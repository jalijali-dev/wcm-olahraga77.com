<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';

$pageTitle  = 'SEO Dashboard';
$currentNav = 'seo-dashboard';
$breadcrumbs = [
    ['label' => 'Dashboard',      'href' => cms_dashboard_href()],
    ['label' => 'SEO Dashboard',  'href' => ''],
];

// ── Summary + audit counts ────────────────────────────────────────────────────
// One query per table returns total + two missing-field counts in a single pass.

$pageStats = $pdo->query(
    'SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN meta_title       IS NULL OR TRIM(meta_title)       = \'\' THEN 1 ELSE 0 END) AS missing_title,
        SUM(CASE WHEN meta_description IS NULL OR TRIM(meta_description) = \'\' THEN 1 ELSE 0 END) AS missing_desc
     FROM pages'
)->fetch();

// ── Detail rows: only records with at least one SEO field missing ─────────────

$pagesWithIssues = $pdo->query(
    'SELECT page_id, title, meta_title, meta_description
     FROM pages
     WHERE (meta_title       IS NULL OR TRIM(meta_title)       = \'\')
        OR (meta_description IS NULL OR TRIM(meta_description) = \'\')
     ORDER BY page_id ASC'
)->fetchAll();

// ── Helpers ───────────────────────────────────────────────────────────────────

/** Return an integer value from a stats row, defaulting to 0. */
$stat = static fn (mixed $row, string $key): int => (int) ($row[$key] ?? 0);

/** Render a pill for a meta field: danger if empty, ok if present. */
$seoPill = static function (?string $value): string {
    $empty = ($value === null || trim($value) === '');
    $cls   = $empty ? 'pill--danger' : 'pill--ok';
    $label = $empty ? 'Missing'      : 'OK';
    return '<span class="pill ' . $cls . '">' . $label . '</span>';
};

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/sidebar.php';
require dirname(__DIR__) . '/includes/navbar.php';
require dirname(__DIR__) . '/includes/breadcrumb.php';
require dirname(__DIR__) . '/includes/alerts.php';
?>
<section class="admin-stack">

    <div class="toolbar">
        <div class="toolbar__left">
            <h2 class="section-title">SEO Dashboard</h2>
            <p class="section-lead">Meta title and description coverage across pages.</p>
        </div>
    </div>

    <style>
    /* Only 3 stat cards on this page — .admin-grid--stats defaults to a
       6-column track on wide desktop screens (sized for pages with more
       cards), which would leave this row half-empty. Force 3 even columns
       above the breakpoint where admin.css's own responsive rules would
       otherwise apply repeat(6, ...); narrower breakpoints are left alone
       since admin.css already collapses to 2/1 columns there. */
    @media (min-width: 1281px) {
        .seo-dash-stats { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    }
    </style>

    <!-- Summary + SEO audit cards — one row, evenly aligned (previously split
         into two separate grid containers, which pushed "Total Pages" onto
         its own row above the other two instead of sitting alongside them). -->
    <div class="admin-grid admin-grid--stats seo-dash-stats">
        <article class="stat-card">
            <div class="stat-card__label">Total Pages</div>
            <div class="stat-card__value"><?= $stat($pageStats, 'total') ?></div>
            <div class="stat-card__hint">Articles &amp; content</div>
        </article>
        <article class="stat-card">
            <div class="stat-card__label">Pages — Missing Meta Title</div>
            <div class="stat-card__value"><?= $stat($pageStats, 'missing_title') ?></div>
            <div class="stat-card__hint">
                <?php if ($stat($pageStats, 'missing_title') > 0) : ?>
                    <a href="#tbl-pages">View below</a>
                <?php else : ?>
                    All covered
                <?php endif; ?>
            </div>
        </article>
        <article class="stat-card">
            <div class="stat-card__label">Pages — Missing Meta Desc</div>
            <div class="stat-card__value"><?= $stat($pageStats, 'missing_desc') ?></div>
            <div class="stat-card__hint">
                <?php if ($stat($pageStats, 'missing_desc') > 0) : ?>
                    <a href="#tbl-pages">View below</a>
                <?php else : ?>
                    All covered
                <?php endif; ?>
            </div>
        </article>
    </div>

    <!-- Section 3b: Pages with SEO issues -->
    <div class="panel" id="tbl-pages">
        <div class="panel__head">
            <h3 class="panel__title">Pages with SEO Issues</h3>
            <span class="panel__meta"><?= count($pagesWithIssues) ?> issue(s)</span>
        </div>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Title</th>
                        <th>Meta Title</th>
                        <th>Meta Description</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($pagesWithIssues === []) : ?>
                        <tr><td colspan="5" class="muted">No SEO issues found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($pagesWithIssues as $row) : ?>
                        <tr>
                            <td><?= (int) $row['page_id'] ?></td>
                            <td><?= cms_esc((string) ($row['title'] ?? '')) ?></td>
                            <td><?= $seoPill((string) ($row['meta_title'] ?? '')) ?></td>
                            <td><?= $seoPill((string) ($row['meta_description'] ?? '')) ?></td>
                            <td class="table-actions">
                                <a class="admin-btn admin-btn--sm admin-btn--secondary"
                                   href="pages.php?edit=<?= (int) $row['page_id'] ?>">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</section>
<?php
require dirname(__DIR__) . '/includes/footer.php';
