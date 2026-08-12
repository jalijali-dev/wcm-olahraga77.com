<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';
require_once dirname(__DIR__) . '/includes/sitemap-service.php';

// Site-wide configuration is admin-tier — see cms_require_role() in
// functions.php for the full tier breakdown.
cms_require_role(['superadmin', 'admin']);

cms_sitemap_ensure_schema($pdo);

$pageTitle = 'Sitemaps';
$currentNav = 'sitemaps';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'SEO Settings', 'href' => cms_nav_href('seo-dashboard.php')],
    ['label' => 'Sitemaps', 'href' => ''],
];

$selfUrl = 'sitemaps.php';

$sm_redirect = static function (string $message, string $type = 'success', ?string $query = null) use ($selfUrl): void {
    $_SESSION['cms_flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . $selfUrl . ($query ? '?' . $query : ''), true, 302);
    exit;
};

// Bootstrap: first time this page is ever opened, sitemap_urls is empty
// even though real content (articles/categories/tags/homepage) already
// exists — auto-run one resync so the admin never lands on an empty
// table needing to know to click "Regenerate" first.
$smUrlCount = (int) $pdo->query('SELECT COUNT(*) FROM sitemap_urls')->fetchColumn();
if ($smUrlCount === 0) {
    cms_sitemap_full_resync($pdo, 'System');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'regenerate') {
        $stats = cms_sitemap_full_resync($pdo);
        $sm_redirect(sprintf(
            'Sitemap regenerated — %d article(s), %d categor(y/ies), %d tag(s), %d static page(s) synced.',
            $stats['articles'], $stats['categories'], $stats['tags'], $stats['pages']
        ), $stats['errors'] > 0 ? 'error' : 'success');
    }

    if ($action === 'validate') {
        $issues = cms_sitemap_validate($pdo);
        $sm_redirect($issues === [] ? 'Validation passed — no problems found.' : count($issues) . ' URL(s) failed validation. See the table below (Status: Error).', $issues === [] ? 'success' : 'error');
    }

    if ($action === 'save_settings') {
        $rules = [];
        foreach (array_keys(cms_sitemap_default_rules()) as $type) {
            $rules[$type] = [
                'included'   => !empty($_POST['included'][$type]),
                'priority'   => (float) ($_POST['priority'][$type] ?? 0.5),
                'changefreq' => (string) ($_POST['changefreq'][$type] ?? 'weekly'),
            ];
        }
        cms_sitemap_save_rules($pdo, $rules);
        cms_sitemap_log($pdo, ['action' => 'updated', 'content_type' => 'settings', 'changed_fields' => $rules]);
        $sm_redirect('Sitemap settings saved. New auto-rule values apply the next time each URL is touched or on next Regenerate.', 'success', '#sitemap-settings');
    }

    if ($action === 'toggle_included') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT included FROM sitemap_urls WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $current = $stmt->fetchColumn();
        if ($current === false) {
            $sm_redirect('URL not found.', 'error');
        }
        cms_sitemap_set_included($pdo, $id, (int) $current !== 1);
        $sm_redirect('Inclusion updated.');
    }

    if ($action === 'regenerate_entry') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM sitemap_urls WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            $sm_redirect('URL not found.', 'error');
        }
        if ($row['content_type'] === 'article' && $row['content_id']) {
            $a = $pdo->prepare('SELECT page_id, title, slug, status, published_at, canonical_url, noindex FROM pages WHERE page_id = :id');
            $a->execute(['id' => $row['content_id']]);
            $art = $a->fetch();
            if ($art) {
                cms_sitemap_on_article_save($pdo, [], $art);
            } else {
                cms_sitemap_mark_deleted($pdo, 'article', (int) $row['content_id']);
            }
        } elseif ($row['content_type'] === 'category' && $row['content_id']) {
            $c = $pdo->prepare('SELECT id, name, slug FROM article_categories WHERE id = :id');
            $c->execute(['id' => $row['content_id']]);
            $cat = $c->fetch();
            if ($cat) {
                cms_sitemap_on_category_save($pdo, (int) $cat['id'], (string) $cat['name'], (string) $cat['slug']);
            } else {
                cms_sitemap_mark_deleted($pdo, 'category', (int) $row['content_id']);
            }
        } elseif ($row['content_type'] === 'tag' && $row['content_id']) {
            $t = $pdo->prepare('SELECT id, name, slug FROM article_tags WHERE id = :id');
            $t->execute(['id' => $row['content_id']]);
            $tag = $t->fetch();
            if ($tag) {
                cms_sitemap_on_tag_save($pdo, (int) $tag['id'], (string) $tag['name'], (string) $tag['slug']);
            } else {
                cms_sitemap_mark_deleted($pdo, 'tag', (int) $row['content_id']);
            }
        } else {
            cms_sitemap_log($pdo, ['action' => 'updated', 'content_type' => $row['content_type'], 'content_id' => $row['content_id'], 'new_url' => $row['url']]);
        }
        $sm_redirect('Entry regenerated from source content.');
    }

    if ($action === 'add_custom') {
        $path = ltrim(trim((string) ($_POST['path'] ?? '')), '/');
        $title = trim((string) ($_POST['title'] ?? ''));
        $priority = (float) ($_POST['priority'] ?? 0.5);
        $changefreq = (string) ($_POST['changefreq'] ?? 'weekly');
        $included = !empty($_POST['included']);
        if ($path === '' || $title === '') {
            $sm_redirect('URL path and title are required for a custom entry.', 'error');
        }
        cms_sitemap_add_custom($pdo, $path, $title, $priority, $changefreq, $included);
        $sm_redirect('Custom URL added.');
    }

    if ($action === 'update_custom') {
        $id = (int) ($_POST['id'] ?? 0);
        $path = ltrim(trim((string) ($_POST['path'] ?? '')), '/');
        $title = trim((string) ($_POST['title'] ?? ''));
        $priority = (float) ($_POST['priority'] ?? 0.5);
        $changefreq = (string) ($_POST['changefreq'] ?? 'weekly');
        $included = !empty($_POST['included']);
        if ($path === '' || $title === '') {
            $sm_redirect('URL path and title are required.', 'error');
        }
        cms_sitemap_update_custom($pdo, $id, $path, $title, $priority, $changefreq, $included);
        $sm_redirect('Custom URL updated.');
    }

    if ($action === 'delete_custom') {
        $id = (int) ($_POST['id'] ?? 0);
        $ok = cms_sitemap_delete_custom($pdo, $id);
        $sm_redirect($ok ? 'Custom URL deleted.' : 'Custom URL not found (only custom entries can be deleted here).', $ok ? 'success' : 'error');
    }

    $sm_redirect('Unknown action.', 'error');
}

$alerts = [];
if (isset($_SESSION['cms_flash']) && is_array($_SESSION['cms_flash'])) {
    $alerts[] = $_SESSION['cms_flash'];
    unset($_SESSION['cms_flash']);
}

/* ── Summary cards ────────────────────────────────────────────────── */
$summary = $pdo->query(
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) AS published,
        SUM(CASE WHEN included = 0 OR status = 'excluded' THEN 1 ELSE 0 END) AS excluded_count,
        SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) AS error_count
     FROM sitemap_urls"
)->fetch();
$fileCount = (int) $pdo->query("SELECT COUNT(DISTINCT sitemap_file) FROM sitemap_urls WHERE included = 1 AND status != 'deleted'")->fetchColumn();
$settings = cms_sitemap_settings($pdo);

$fmtDate = static function (?string $raw): string {
    if ($raw === null || $raw === '') {
        return '—';
    }
    $ts = strtotime($raw);
    return $ts !== false ? date('d M Y, H:i', $ts) : '—';
};

/* ── URL table: filters + search + sort + pagination ────────────────── */
$fSearch = trim((string) ($_GET['search'] ?? ''));
$fType   = (string) ($_GET['type'] ?? '');
$fStatus = (string) ($_GET['status'] ?? '');
$fIncluded = (string) ($_GET['included'] ?? '');
$fFile   = (string) ($_GET['file'] ?? '');
$fSort   = (string) ($_GET['sort'] ?? 'updated_desc');
$fPage   = max(1, (int) ($_GET['page'] ?? 1));
$fPerPage = 20;

$validTypes = ['homepage', 'article', 'category', 'tag', 'page', 'custom'];
$validStatuses = ['published', 'draft', 'scheduled', 'deleted', 'redirected', 'excluded', 'error'];
$validFiles = ['sitemap-pages.xml', 'sitemap-articles.xml', 'sitemap-categories.xml', 'sitemap-custom.xml'];

$where = [];
$params = [];
if ($fSearch !== '') {
    $esc = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $fSearch);
    $where[] = '(url LIKE :search OR content_title LIKE :search)';
    $params['search'] = '%' . $esc . '%';
}
if (in_array($fType, $validTypes, true)) {
    $where[] = 'content_type = :type';
    $params['type'] = $fType;
}
if (in_array($fStatus, $validStatuses, true)) {
    $where[] = 'status = :status';
    $params['status'] = $fStatus;
}
if ($fIncluded === 'included') {
    $where[] = 'included = 1';
} elseif ($fIncluded === 'excluded') {
    $where[] = 'included = 0';
}
if (in_array($fFile, $validFiles, true)) {
    $where[] = 'sitemap_file = :file';
    $params['file'] = $fFile;
}
$whereClause = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

$sortMap = [
    'updated_desc' => 'updated_at DESC',
    'updated_asc'  => 'updated_at ASC',
    'priority_desc' => 'priority DESC',
    'priority_asc'  => 'priority ASC',
    'url_asc'      => 'url ASC',
    'type_asc'     => 'content_type ASC',
];
$orderBy = $sortMap[$fSort] ?? $sortMap['updated_desc'];

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM sitemap_urls' . $whereClause);
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $fPerPage));
if ($fPage > $totalPages) {
    $fPage = $totalPages;
}
$offset = ($fPage - 1) * $fPerPage;

$listStmt = $pdo->prepare('SELECT * FROM sitemap_urls' . $whereClause . ' ORDER BY ' . $orderBy . ' LIMIT :limit OFFSET :offset');
foreach ($params as $k => $v) {
    $listStmt->bindValue(':' . $k, $v);
}
$listStmt->bindValue(':limit', $fPerPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$urls = $listStmt->fetchAll();

$paginateUrl = static function (int $targetPage) use ($fSearch, $fType, $fStatus, $fIncluded, $fFile, $fSort, $selfUrl): string {
    $q = array_filter([
        'search' => $fSearch, 'type' => $fType, 'status' => $fStatus,
        'included' => $fIncluded, 'file' => $fFile, 'sort' => $fSort, 'page' => $targetPage,
    ], static fn ($v) => $v !== '' && $v !== null);
    return $selfUrl . '?' . http_build_query($q);
};

$typeLabels = [
    'homepage' => 'Homepage', 'article' => 'Article', 'category' => 'Category',
    'tag' => 'Tag', 'page' => 'Static Page', 'custom' => 'Custom URL',
];
$statusTone = [
    'published' => 'ok', 'draft' => 'muted', 'scheduled' => 'info', 'deleted' => 'warn',
    'redirected' => 'warn', 'excluded' => 'muted', 'error' => 'warn',
];

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/sidebar.php';
require dirname(__DIR__) . '/includes/navbar.php';
require dirname(__DIR__) . '/includes/breadcrumb.php';
require dirname(__DIR__) . '/includes/alerts.php';
?>
<style>
.sm-endpoints { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 10px; }
.sm-endpoints code { background: var(--surface-soft); border: 1px solid var(--line); border-radius: 8px; padding: 6px 10px; font-size: 12.5px; }
.sm-rules-table { width: 100%; border-collapse: collapse; }
.sm-rules-table th, .sm-rules-table td { padding: 8px 10px; text-align: left; border-bottom: 1px solid var(--line); font-size: 13px; }
.sm-rules-table input[type="number"] { width: 80px; }
.sm-filters { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px 16px; align-items: end; margin-bottom: 16px; }
/* Grid items default to min-width:auto, which refuses to shrink below the
   content's natural size — a long option like "Last modified (newest)"
   was overflowing its column and visually colliding with the next field
   (and its own dropdown arrow). min-width:0 lets each field actually
   respect the grid track width, so the select truncates/ellipsizes
   instead of overflowing. */
.sm-filters .field { min-width: 0; }
.sm-filters .field select,
.sm-filters .field input { width: 100%; max-width: 100%; text-overflow: ellipsis; }
.sm-pagination { display: flex; gap: 6px; flex-wrap: wrap; padding: 14px 20px; }
.sm-page-btn { padding: 6px 12px; border-radius: 8px; border: 1px solid var(--line); font-size: 13px; color: inherit; text-decoration: none; }
.sm-page-btn--active { background: var(--accent-soft); border-color: var(--navlink-active-border); font-weight: 700; }
.sm-page-btn--disabled { opacity: .4; pointer-events: none; }
.sm-actions-cell { display: flex; flex-wrap: wrap; gap: 6px; }
@media (max-width: 640px) { .sm-endpoints { flex-direction: column; } }

/* ---- URL list table ----
   Was using table-layout:auto (browser default) with an unconstrained
   overflow-wrap:anywhere URL cell — on a long unbroken URL string, the
   browser's auto column-width algorithm sized that column down to almost
   nothing (since overflow-wrap lets it break between ANY two characters),
   which forced the URL onto one character per line and blew up the row
   height, throwing off the whole table. Fixed layout + explicit per-column
   percentages + single-line ellipsis truncation (full URL still available
   via the native title tooltip) keeps every row a normal, readable height. */
.sm-table { table-layout: fixed; min-width: 1180px; }
.sm-col-url { width: 20%; }
.sm-col-type { width: 6%; }
.sm-col-title { width: 15%; }
.sm-col-status { width: 7%; }
.sm-col-included { width: 6%; }
.sm-col-priority { width: 6%; }
.sm-col-changefreq { width: 7%; }
.sm-col-lastmod { width: 8%; }
.sm-col-detected { width: 8%; }
.sm-col-file { width: 8%; }
.sm-col-actions { width: 13%; }
.sm-url-cell code,
.sm-file-cell code {
    display: block;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
}
</style>
<section class="admin-stack">
    <div class="toolbar">
        <div class="toolbar__left">
            <h2 class="section-title">Sitemaps</h2>
            <p class="section-lead">Every indexable URL on the site, kept in sync automatically as content changes.</p>
        </div>
        <div class="toolbar__right">
            <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>">
                <?= cms_csrf_field() ?>
                <input type="hidden" name="action" value="regenerate">
                <button type="submit" class="admin-btn admin-btn--primary">Regenerate Sitemap</button>
            </form>
            <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>">
                <?= cms_csrf_field() ?>
                <input type="hidden" name="action" value="validate">
                <button type="submit" class="admin-btn admin-btn--secondary">Validate Sitemap</button>
            </form>
            <a class="admin-btn admin-btn--secondary" href="<?= cms_esc(cms_public_base_prefix() . 'sitemap.xml') ?>" target="_blank" rel="noopener">Open Sitemap XML</a>
            <a class="admin-btn admin-btn--secondary" href="#sitemap-settings">Sitemap Settings</a>
            <a class="admin-btn admin-btn--secondary" href="sitemap-history.php">View Update History</a>
        </div>
    </div>

    <div class="admin-grid admin-grid--stats">
        <article class="stat-card">
            <div class="stat-card__label">Total URLs</div>
            <div class="stat-card__value"><?= (int) ($summary['total'] ?? 0) ?></div>
        </article>
        <article class="stat-card">
            <div class="stat-card__label">Published URLs</div>
            <div class="stat-card__value"><?= (int) ($summary['published'] ?? 0) ?></div>
        </article>
        <article class="stat-card">
            <div class="stat-card__label">Excluded URLs</div>
            <div class="stat-card__value"><?= (int) ($summary['excluded_count'] ?? 0) ?></div>
        </article>
        <article class="stat-card">
            <div class="stat-card__label">Sitemap Files</div>
            <div class="stat-card__value"><?= $fileCount ?></div>
            <div class="stat-card__hint">Active sub-sitemaps</div>
        </article>
        <article class="stat-card">
            <div class="stat-card__label">Last Generated</div>
            <div class="stat-card__value" style="font-size:16px;"><?= cms_esc($fmtDate($settings['last_generated_at'] ?? null)) ?></div>
        </article>
        <article class="stat-card">
            <div class="stat-card__label">Last Successful Update</div>
            <div class="stat-card__value" style="font-size:16px;"><?= cms_esc($fmtDate($settings['last_success_at'] ?? null)) ?></div>
        </article>
        <article class="stat-card">
            <div class="stat-card__label">Errors / Invalid URLs</div>
            <div class="stat-card__value"><?= (int) ($summary['error_count'] ?? 0) ?></div>
        </article>
    </div>

    <div class="panel">
        <div class="panel__head"><h3 class="panel__title">Frontend endpoints</h3></div>
        <div class="sm-endpoints">
            <code><?= cms_esc(cms_public_base_prefix() . 'sitemap.xml') ?></code>
            <code><?= cms_esc(cms_public_base_prefix() . 'sitemap-index.xml') ?></code>
            <code><?= cms_esc(cms_public_base_prefix() . 'sitemap-pages.xml') ?></code>
            <code><?= cms_esc(cms_public_base_prefix() . 'sitemap-articles.xml') ?></code>
            <code><?= cms_esc(cms_public_base_prefix() . 'sitemap-categories.xml') ?></code>
            <code><?= cms_esc(cms_public_base_prefix() . 'sitemap-custom.xml') ?></code>
        </div>
        <p class="field__hint" style="margin-top:10px;">/sitemap.xml and /sitemap-index.xml both serve the same sitemap index, which points search engines at the 4 per-type files above.</p>
    </div>

    <div class="panel" style="border-color: rgba(234, 179, 8, 0.35);">
        <div class="panel__head"><h3 class="panel__title">⚠️ Generate Sitemap Statis (Darurat)</h3></div>
        <p class="section-lead">
            Hanya untuk kondisi darurat — kalau sitemap dinamis di atas sedang bermasalah (error database, dsb)
            dan Googlebot butuh /sitemap.xml yang tetap bisa diakses selagi masalahnya diperbaiki. Tool ini
            menulis snapshot <strong>beku</strong> ke file <code>sitemap.xml</code> di server; selama file itu ada,
            /sitemap.xml berhenti mengikuti perubahan konten terbaru sampai file itu dihapus lagi (ada tombolnya
            di halaman tersebut). <strong>Jangan dipakai untuk kondisi normal sehari-hari</strong> — "Regenerate
            Sitemap" di atas sudah cukup dan selalu live.
        </p>
        <a class="admin-btn admin-btn--secondary" href="<?= cms_esc(cms_public_base_prefix() . 'generate-sitemap-static.php') ?>" target="_blank" rel="noopener">Buka Generator Darurat</a>
    </div>

    <div class="panel" id="sitemap-settings">
        <div class="panel__head"><h3 class="panel__title">Sitemap Settings — auto-rules per content type</h3></div>
        <p class="section-lead">Defaults used whenever a URL's priority/changefreq hasn't been manually pinned via the table below. "Author" pages aren't listed — this site has no public author archive route yet, so there's nothing to include.</p>
        <form method="post" action="<?= cms_esc($selfUrl) ?>" style="padding: 12px 20px 20px;">
            <?= cms_csrf_field() ?>
            <input type="hidden" name="action" value="save_settings">
            <div class="table-wrap">
                <table class="sm-rules-table">
                    <thead>
                        <tr><th>Content type</th><th>Included by default</th><th>Priority</th><th>Change frequency</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($settings['rules'] as $type => $rule) : ?>
                            <tr>
                                <td><?= cms_esc($typeLabels[$type] ?? ucfirst($type)) ?></td>
                                <td><input type="checkbox" name="included[<?= cms_esc($type) ?>]" value="1"<?= !empty($rule['included']) ? ' checked' : '' ?>></td>
                                <td><input type="number" step="0.1" min="0" max="1" name="priority[<?= cms_esc($type) ?>]" value="<?= cms_esc((string) $rule['priority']) ?>"></td>
                                <td>
                                    <select name="changefreq[<?= cms_esc($type) ?>]">
                                        <?php foreach (['always','hourly','daily','weekly','monthly','yearly','never'] as $cf) : ?>
                                            <option value="<?= $cf ?>"<?= $rule['changefreq'] === $cf ? ' selected' : '' ?>><?= ucfirst($cf) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="form-grid__actions" style="margin-top:14px;">
                <button type="submit" class="admin-btn admin-btn--primary">Save settings</button>
            </div>
        </form>
    </div>

    <div class="panel" id="add-custom-url">
        <div class="panel__head"><h3 class="panel__title">Add custom URL</h3></div>
        <form class="form-grid" method="post" action="<?= cms_esc($selfUrl) ?>" style="padding: 12px 20px 20px;">
            <?= cms_csrf_field() ?>
            <input type="hidden" name="action" value="add_custom">
            <label class="field">URL path <span class="field__hint">(relative, e.g. promo/campaign)</span>
                <input type="text" name="path" placeholder="promo/campaign" required>
            </label>
            <label class="field">Title
                <input type="text" name="title" placeholder="Internal label" required>
            </label>
            <label class="field">Priority
                <input type="number" step="0.1" min="0" max="1" name="priority" value="0.5">
            </label>
            <label class="field">Change frequency
                <select name="changefreq">
                    <?php foreach (['always','hourly','daily','weekly','monthly','yearly','never'] as $cf) : ?>
                        <option value="<?= $cf ?>"<?= $cf === 'monthly' ? ' selected' : '' ?>><?= ucfirst($cf) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field field--checkbox">
                <input type="checkbox" name="included" value="1" checked>
                <span class="field--checkbox__text">
                    <span class="field--checkbox__title">Included in sitemap</span>
                </span>
            </label>
            <div class="form-grid__actions">
                <button type="submit" class="admin-btn admin-btn--primary">Add URL</button>
            </div>
        </form>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">All Sitemap URLs</h3>
            <span class="panel__meta"><?= $totalRows ?> URL(s)</span>
        </div>
        <form method="get" action="<?= cms_esc($selfUrl) ?>" style="padding: 14px 20px 0;">
            <div class="sm-filters">
                <label class="field">Search
                    <input type="text" name="search" value="<?= cms_esc($fSearch) ?>" placeholder="URL or title…">
                </label>
                <label class="field">Content type
                    <select name="type">
                        <option value="">All types</option>
                        <?php foreach ($typeLabels as $tVal => $tLabel) : ?>
                            <option value="<?= $tVal ?>"<?= $fType === $tVal ? ' selected' : '' ?>><?= cms_esc($tLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field">Status
                    <select name="status">
                        <option value="">All statuses</option>
                        <?php foreach ($validStatuses as $sVal) : ?>
                            <option value="<?= $sVal ?>"<?= $fStatus === $sVal ? ' selected' : '' ?>><?= ucfirst($sVal) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field">Inclusion
                    <select name="included">
                        <option value="">All</option>
                        <option value="included"<?= $fIncluded === 'included' ? ' selected' : '' ?>>Included</option>
                        <option value="excluded"<?= $fIncluded === 'excluded' ? ' selected' : '' ?>>Excluded</option>
                    </select>
                </label>
                <label class="field">Sitemap file
                    <select name="file">
                        <option value="">All files</option>
                        <?php foreach ($validFiles as $fVal) : ?>
                            <option value="<?= $fVal ?>"<?= $fFile === $fVal ? ' selected' : '' ?>><?= $fVal ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field">Sort by
                    <select name="sort">
                        <option value="updated_desc"<?= $fSort === 'updated_desc' ? ' selected' : '' ?>>Last modified (newest)</option>
                        <option value="updated_asc"<?= $fSort === 'updated_asc' ? ' selected' : '' ?>>Last modified (oldest)</option>
                        <option value="priority_desc"<?= $fSort === 'priority_desc' ? ' selected' : '' ?>>Priority (high→low)</option>
                        <option value="priority_asc"<?= $fSort === 'priority_asc' ? ' selected' : '' ?>>Priority (low→high)</option>
                        <option value="url_asc"<?= $fSort === 'url_asc' ? ' selected' : '' ?>>URL (A→Z)</option>
                        <option value="type_asc"<?= $fSort === 'type_asc' ? ' selected' : '' ?>>Content type</option>
                    </select>
                </label>
                <div class="field field--actions">
                    <button type="submit" class="admin-btn admin-btn--primary">Apply</button>
                    <a class="admin-btn admin-btn--secondary" href="<?= cms_esc($selfUrl) ?>">Reset</a>
                </div>
            </div>
        </form>
        <div class="table-wrap">
            <table class="admin-table sm-table">
                <thead>
                    <tr>
                        <th class="sm-col-url">URL</th>
                        <th class="sm-col-type">Type</th>
                        <th class="sm-col-title">Title</th>
                        <th class="sm-col-status">Status</th>
                        <th class="sm-col-included">Included</th>
                        <th class="sm-col-priority">Priority</th>
                        <th class="sm-col-changefreq">Changefreq</th>
                        <th class="sm-col-lastmod">Last modified</th>
                        <th class="sm-col-detected">Last detected change</th>
                        <th class="sm-col-file">Sitemap file</th>
                        <th class="sm-col-actions"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($urls === []) : ?>
                        <tr><td colspan="11" class="muted">No URLs match these filters.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($urls as $row) :
                        $editHref = null;
                        if ($row['content_type'] === 'article' && $row['content_id']) {
                            $editHref = cms_nav_href('pages.php') . '?edit=' . (int) $row['content_id'];
                        } elseif ($row['content_type'] === 'category') {
                            $editHref = cms_nav_href('article-categories.php');
                        } elseif ($row['content_type'] === 'tag') {
                            $editHref = cms_nav_href('article-tags.php');
                        }
                    ?>
                        <tr>
                            <td class="sm-url-cell" title="<?= cms_esc((string) $row['url']) ?>"><code><?= cms_esc((string) $row['url']) ?></code></td>
                            <td><span class="pill pill--muted"><?= cms_esc($typeLabels[$row['content_type']] ?? $row['content_type']) ?></span></td>
                            <td><?= cms_esc((string) ($row['content_title'] ?? '—')) ?></td>
                            <td><span class="pill pill--<?= cms_esc($statusTone[$row['status']] ?? 'muted') ?>"><?= cms_esc(ucfirst((string) $row['status'])) ?></span></td>
                            <td>
                                <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>">
                                    <?= cms_csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_included">
                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                    <button type="submit" class="pill pill--<?= (int) $row['included'] === 1 ? 'ok' : 'muted' ?>" style="border:none;cursor:pointer;">
                                        <?= (int) $row['included'] === 1 ? 'Yes' : 'No' ?>
                                    </button>
                                </form>
                            </td>
                            <td><?= cms_esc((string) $row['priority']) ?></td>
                            <td><?= cms_esc((string) $row['changefreq']) ?></td>
                            <td><?= cms_esc($fmtDate($row['lastmod'])) ?></td>
                            <td><?= cms_esc($fmtDate($row['last_detected_change'])) ?></td>
                            <td class="sm-file-cell" title="<?= cms_esc((string) $row['sitemap_file']) ?>"><code><?= cms_esc((string) $row['sitemap_file']) ?></code></td>
                            <td class="sm-actions-cell">
                                <a class="admin-btn admin-btn--sm admin-btn--secondary" href="<?= cms_esc((string) $row['url']) ?>" target="_blank" rel="noopener">View</a>
                                <?php if ($editHref) : ?>
                                    <a class="admin-btn admin-btn--sm admin-btn--secondary" href="<?= cms_esc($editHref) ?>">Edit SEO</a>
                                <?php elseif ($row['content_type'] === 'custom') : ?>
                                    <button type="button" class="admin-btn admin-btn--sm admin-btn--secondary js-sm-edit-custom"
                                        data-id="<?= (int) $row['id'] ?>"
                                        data-path="<?= cms_esc(parse_url((string) $row['url'], PHP_URL_PATH) ?: '') ?>"
                                        data-title="<?= cms_esc((string) $row['content_title']) ?>"
                                        data-priority="<?= cms_esc((string) $row['priority']) ?>"
                                        data-changefreq="<?= cms_esc((string) $row['changefreq']) ?>"
                                        data-included="<?= (int) $row['included'] ?>">Edit SEO</button>
                                <?php endif; ?>
                                <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>">
                                    <?= cms_csrf_field() ?>
                                    <input type="hidden" name="action" value="regenerate_entry">
                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                    <button type="submit" class="admin-btn admin-btn--sm admin-btn--secondary">Regenerate</button>
                                </form>
                                <a class="admin-btn admin-btn--sm admin-btn--secondary" href="sitemap-history.php?content_type=<?= cms_esc((string) $row['content_type']) ?>&content_id=<?= (int) ($row['content_id'] ?? 0) ?>">Inspect</a>
                                <?php if ($row['content_type'] === 'custom') : ?>
                                    <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>" onsubmit="return confirm('Delete this custom URL?');">
                                        <?= cms_csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_custom">
                                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                        <button type="submit" class="admin-btn admin-btn--sm admin-btn--danger">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1) : ?>
        <nav class="sm-pagination" aria-label="Sitemap URL pagination">
            <?php if ($fPage > 1) : ?>
                <a class="sm-page-btn" href="<?= cms_esc($paginateUrl($fPage - 1)) ?>">&laquo; Prev</a>
            <?php else : ?>
                <span class="sm-page-btn sm-page-btn--disabled">&laquo; Prev</span>
            <?php endif; ?>
            <?php for ($i = max(1, $fPage - 2); $i <= min($totalPages, $fPage + 2); $i++) : ?>
                <?php if ($i === $fPage) : ?>
                    <span class="sm-page-btn sm-page-btn--active"><?= $i ?></span>
                <?php else : ?>
                    <a class="sm-page-btn" href="<?= cms_esc($paginateUrl($i)) ?>"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor; ?>
            <?php if ($fPage < $totalPages) : ?>
                <a class="sm-page-btn" href="<?= cms_esc($paginateUrl($fPage + 1)) ?>">Next &raquo;</a>
            <?php else : ?>
                <span class="sm-page-btn sm-page-btn--disabled">Next &raquo;</span>
            <?php endif; ?>
        </nav>
        <?php endif; ?>
    </div>
</section>
<script>
(function () {
    var form = document.querySelector('#add-custom-url form');
    if (!form) { return; }
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-sm-edit-custom');
        if (!btn) { return; }
        form.querySelector('[name="action"]').value = 'update_custom';
        var idInput = form.querySelector('[name="id"]');
        if (!idInput) {
            idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'id';
            form.appendChild(idInput);
        }
        idInput.value = btn.getAttribute('data-id');
        form.querySelector('[name="path"]').value = (btn.getAttribute('data-path') || '').replace(/^\//, '');
        form.querySelector('[name="title"]').value = btn.getAttribute('data-title') || '';
        form.querySelector('[name="priority"]').value = btn.getAttribute('data-priority') || '0.5';
        form.querySelector('[name="changefreq"]').value = btn.getAttribute('data-changefreq') || 'monthly';
        form.querySelector('[name="included"]').checked = btn.getAttribute('data-included') === '1';
        document.getElementById('add-custom-url').scrollIntoView({ behavior: 'smooth' });
    });
})();
</script>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
