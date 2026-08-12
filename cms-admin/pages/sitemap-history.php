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

$pageTitle = 'Sitemap Update History';
$currentNav = 'sitemaps';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'SEO Settings', 'href' => cms_nav_href('seo-dashboard.php')],
    ['label' => 'Sitemaps', 'href' => cms_nav_href('sitemaps.php')],
    ['label' => 'Update History', 'href' => ''],
];

$selfUrl = 'sitemap-history.php';

$fAction = (string) ($_GET['action'] ?? '');
$fType = (string) ($_GET['content_type'] ?? '');
$fContentId = (int) ($_GET['content_id'] ?? 0);
$fResult = (string) ($_GET['result'] ?? '');
$fUser = trim((string) ($_GET['triggered_by'] ?? ''));
$fFrom = trim((string) ($_GET['from'] ?? ''));
$fTo = trim((string) ($_GET['to'] ?? ''));
$fPage = max(1, (int) ($_GET['page'] ?? 1));
$fPerPage = 30;

$validActions = ['created', 'updated', 'published', 'unpublished', 'deleted', 'restored', 'slug_changed', 'included', 'excluded', 'redirected', 'sitemap_generated', 'validation_executed'];
$validResults = ['success', 'error'];

$where = [];
$params = [];
if (in_array($fAction, $validActions, true)) {
    $where[] = 'action = :action';
    $params['action'] = $fAction;
}
if ($fType !== '') {
    $where[] = 'content_type = :content_type';
    $params['content_type'] = $fType;
}
if ($fContentId > 0) {
    $where[] = 'content_id = :content_id';
    $params['content_id'] = $fContentId;
}
if (in_array($fResult, $validResults, true)) {
    $where[] = 'result = :result';
    $params['result'] = $fResult;
}
if ($fUser !== '') {
    $where[] = 'triggered_by LIKE :user';
    $params['user'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $fUser) . '%';
}
if ($fFrom !== '') {
    $where[] = 'occurred_at >= :from';
    $params['from'] = $fFrom . ' 00:00:00';
}
if ($fTo !== '') {
    $where[] = 'occurred_at <= :to';
    $params['to'] = $fTo . ' 23:59:59';
}
$whereClause = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM sitemap_changelog' . $whereClause);
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $fPerPage));
if ($fPage > $totalPages) {
    $fPage = $totalPages;
}
$offset = ($fPage - 1) * $fPerPage;

$listStmt = $pdo->prepare('SELECT * FROM sitemap_changelog' . $whereClause . ' ORDER BY occurred_at DESC, id DESC LIMIT :limit OFFSET :offset');
foreach ($params as $k => $v) {
    $listStmt->bindValue(':' . $k, $v);
}
$listStmt->bindValue(':limit', $fPerPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$entries = $listStmt->fetchAll();

$paginateUrl = static function (int $targetPage) use ($fAction, $fType, $fContentId, $fResult, $fUser, $fFrom, $fTo, $selfUrl): string {
    $q = array_filter([
        'action' => $fAction, 'content_type' => $fType, 'content_id' => $fContentId ?: '',
        'result' => $fResult, 'triggered_by' => $fUser, 'from' => $fFrom, 'to' => $fTo, 'page' => $targetPage,
    ], static fn ($v) => $v !== '' && $v !== null && $v !== 0);
    return $selfUrl . '?' . http_build_query($q);
};

$actionLabels = [
    'created' => 'Created', 'updated' => 'Updated', 'published' => 'Published', 'unpublished' => 'Unpublished',
    'deleted' => 'Deleted', 'restored' => 'Restored', 'slug_changed' => 'Slug changed', 'included' => 'Included',
    'excluded' => 'Excluded', 'redirected' => 'Redirected', 'sitemap_generated' => 'Sitemap generated',
    'validation_executed' => 'Validation executed',
];

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/sidebar.php';
require dirname(__DIR__) . '/includes/navbar.php';
require dirname(__DIR__) . '/includes/breadcrumb.php';
require dirname(__DIR__) . '/includes/alerts.php';
?>
<style>
.sh-filters { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; align-items: end; padding: 14px 20px 0; }
.sh-detail-row { display: none; background: var(--surface-soft); }
.sh-detail-row.is-open { display: table-row; }
.sh-detail-row td { padding: 12px 20px; font-size: 12.5px; }
.sh-detail-row pre { white-space: pre-wrap; word-break: break-word; margin: 4px 0 0; }
.sh-page-btn { padding: 6px 12px; border-radius: 8px; border: 1px solid var(--line); font-size: 13px; color: inherit; text-decoration: none; }
.sh-page-btn--active { background: var(--accent-soft); border-color: var(--navlink-active-border); font-weight: 700; }
.sh-page-btn--disabled { opacity: .4; pointer-events: none; }
</style>
<section class="admin-stack">
    <div class="toolbar">
        <div class="toolbar__left">
            <h2 class="section-title">Sitemap Update History</h2>
            <p class="section-lead">Every change ever detected or applied by the Sitemaps module.</p>
        </div>
        <div class="toolbar__right">
            <a class="admin-btn admin-btn--secondary" href="sitemaps.php">&larr; Back to Sitemaps</a>
        </div>
    </div>

    <div class="panel">
        <form method="get" action="<?= cms_esc($selfUrl) ?>">
            <div class="sh-filters">
                <label class="field">Action
                    <select name="action">
                        <option value="">All actions</option>
                        <?php foreach ($actionLabels as $aVal => $aLabel) : ?>
                            <option value="<?= $aVal ?>"<?= $fAction === $aVal ? ' selected' : '' ?>><?= cms_esc($aLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field">Content type
                    <select name="content_type">
                        <option value="">All types</option>
                        <?php foreach (['homepage', 'article', 'category', 'tag', 'page', 'custom', 'redirect', 'settings'] as $tVal) : ?>
                            <option value="<?= $tVal ?>"<?= $fType === $tVal ? ' selected' : '' ?>><?= ucfirst($tVal) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field">Result
                    <select name="result">
                        <option value="">All results</option>
                        <option value="success"<?= $fResult === 'success' ? ' selected' : '' ?>>Success</option>
                        <option value="error"<?= $fResult === 'error' ? ' selected' : '' ?>>Error</option>
                    </select>
                </label>
                <label class="field">Admin / user
                    <input type="text" name="triggered_by" value="<?= cms_esc($fUser) ?>" placeholder="Name or System">
                </label>
                <label class="field">From
                    <input type="date" name="from" value="<?= cms_esc($fFrom) ?>">
                </label>
                <label class="field">To
                    <input type="date" name="to" value="<?= cms_esc($fTo) ?>">
                </label>
                <?php if ($fContentId > 0) : ?><input type="hidden" name="content_id" value="<?= $fContentId ?>"><?php endif; ?>
                <div class="field field--actions">
                    <button type="submit" class="admin-btn admin-btn--primary">Apply</button>
                    <a class="admin-btn admin-btn--secondary" href="<?= cms_esc($selfUrl) ?>">Reset</a>
                </div>
            </div>
        </form>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Change log</h3>
            <span class="panel__meta"><?= $totalRows ?> entr(y/ies)</span>
        </div>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Date &amp; time</th>
                        <th>Action</th>
                        <th>Content type</th>
                        <th>Content ID</th>
                        <th>Triggered by</th>
                        <th>Result</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($entries === []) : ?>
                        <tr><td colspan="7" class="muted">No history entries match these filters.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($entries as $i => $entry) : ?>
                        <tr>
                            <td><?= cms_esc(date('d M Y, H:i:s', strtotime((string) $entry['occurred_at']))) ?></td>
                            <td><span class="pill pill--muted"><?= cms_esc($actionLabels[$entry['action']] ?? $entry['action']) ?></span></td>
                            <td><?= cms_esc((string) ($entry['content_type'] ?? '—')) ?></td>
                            <td><?= $entry['content_id'] !== null ? (int) $entry['content_id'] : '—' ?></td>
                            <td><?= cms_esc((string) $entry['triggered_by']) ?></td>
                            <td><span class="pill pill--<?= $entry['result'] === 'success' ? 'ok' : 'warn' ?>"><?= cms_esc(ucfirst((string) $entry['result'])) ?></span></td>
                            <td><button type="button" class="admin-btn admin-btn--sm admin-btn--secondary js-sh-toggle" data-target="sh-detail-<?= $i ?>">Details</button></td>
                        </tr>
                        <tr class="sh-detail-row" id="sh-detail-<?= $i ?>">
                            <td colspan="7">
                                <div><strong>Old URL:</strong> <?= cms_esc((string) ($entry['old_url'] ?? '—')) ?></div>
                                <div><strong>New URL:</strong> <?= cms_esc((string) ($entry['new_url'] ?? '—')) ?></div>
                                <?php if (!empty($entry['error_message'])) : ?>
                                    <div><strong>Error:</strong> <?= cms_esc((string) $entry['error_message']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($entry['changed_fields'])) : ?>
                                    <div><strong>Changed fields:</strong>
                                        <pre><?= cms_esc((string) $entry['changed_fields']) ?></pre>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1) : ?>
        <nav class="sh-pagination" aria-label="History pagination" style="display:flex;gap:6px;flex-wrap:wrap;padding:14px 20px;">
            <?php if ($fPage > 1) : ?>
                <a class="sh-page-btn" href="<?= cms_esc($paginateUrl($fPage - 1)) ?>">&laquo; Prev</a>
            <?php else : ?>
                <span class="sh-page-btn sh-page-btn--disabled">&laquo; Prev</span>
            <?php endif; ?>
            <?php for ($i = max(1, $fPage - 2); $i <= min($totalPages, $fPage + 2); $i++) : ?>
                <?php if ($i === $fPage) : ?>
                    <span class="sh-page-btn sh-page-btn--active"><?= $i ?></span>
                <?php else : ?>
                    <a class="sh-page-btn" href="<?= cms_esc($paginateUrl($i)) ?>"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor; ?>
            <?php if ($fPage < $totalPages) : ?>
                <a class="sh-page-btn" href="<?= cms_esc($paginateUrl($fPage + 1)) ?>">Next &raquo;</a>
            <?php else : ?>
                <span class="sh-page-btn sh-page-btn--disabled">Next &raquo;</span>
            <?php endif; ?>
        </nav>
        <?php endif; ?>
    </div>
</section>
<script>
document.addEventListener('click', function (e) {
    var btn = e.target.closest('.js-sh-toggle');
    if (!btn) { return; }
    var target = document.getElementById(btn.getAttribute('data-target'));
    if (target) { target.classList.toggle('is-open'); }
});
</script>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
