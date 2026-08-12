<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';

// Site-wide configuration is admin-tier — see cms_require_role() in
// functions.php for the full tier breakdown.
cms_require_role(['superadmin', 'admin']);

$pageTitle = 'Ad Statistics';
$currentNav = 'ad-statistics';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'Advertisements', 'href' => cms_nav_href('ads.php')],
    ['label' => 'Ad Statistics', 'href' => ''],
];

$totals = ['impressions' => 0, 'clicks' => 0];
try {
    $totalsRow = $pdo->query('SELECT COALESCE(SUM(impressions),0) AS impressions, COALESCE(SUM(clicks),0) AS clicks FROM advertisements')->fetch();
    $totals = is_array($totalsRow) ? $totalsRow : $totals;
} catch (Throwable $e) {
    // advertisements table not migrated yet — totals stay 0, page still renders.
}

$activeCount = 0;
try {
    $activeCount = (int) $pdo->query('SELECT COUNT(*) FROM advertisements WHERE is_active = 1')->fetchColumn();
} catch (Throwable $e) {
    $activeCount = 0;
}

$rows = [];
try {
    $rows = $pdo->query(
        'SELECT a.id, a.name, a.ad_type, a.impressions, a.clicks, a.is_active, p.name AS position_name
         FROM advertisements a
         LEFT JOIN ad_positions p ON p.id = a.position_id
         ORDER BY a.clicks DESC, a.impressions DESC'
    )->fetchAll();
} catch (Throwable $e) {
    $rows = [];
}

$overallCtr = (int) $totals['impressions'] > 0
    ? number_format(((int) $totals['clicks'] / (int) $totals['impressions']) * 100, 2)
    : '0.00';

$fmtCtr = static function (array $row): string {
    $imp = (int) ($row['impressions'] ?? 0);
    $clk = (int) ($row['clicks'] ?? 0);
    if ($imp === 0) {
        return '—';
    }
    return number_format(($clk / $imp) * 100, 2) . '%';
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
            <h2 class="section-title">Ad Statistics</h2>
            <p class="section-lead">Impressions, clicks, and CTR per ad. Counters update as ads are served on the public site.</p>
        </div>
        <div class="toolbar__right">
            <a class="admin-btn admin-btn--secondary" href="ads.php">← Back to Ads</a>
        </div>
    </div>

    <div class="admin-grid admin-grid--stats">
        <article class="stat-card">
            <div class="stat-card__label">Active ads</div>
            <div class="stat-card__value"><?= $activeCount ?></div>
            <div class="stat-card__hint">Currently serving</div>
        </article>
        <article class="stat-card">
            <div class="stat-card__label">Total impressions</div>
            <div class="stat-card__value"><?= (int) $totals['impressions'] ?></div>
            <div class="stat-card__hint">All ads combined</div>
        </article>
        <article class="stat-card">
            <div class="stat-card__label">Total clicks</div>
            <div class="stat-card__value"><?= (int) $totals['clicks'] ?></div>
            <div class="stat-card__hint">All ads combined</div>
        </article>
        <article class="stat-card">
            <div class="stat-card__label">Overall CTR</div>
            <div class="stat-card__value"><?= $overallCtr ?>%</div>
            <div class="stat-card__hint">Clicks / impressions</div>
        </article>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Per-ad breakdown</h3>
            <span class="panel__meta"><?= count($rows) ?> ad(s)</span>
        </div>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Ad</th>
                        <th>Type</th>
                        <th>Position</th>
                        <th>Impressions</th>
                        <th>Clicks</th>
                        <th>CTR</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows === []) : ?>
                        <tr><td colspan="7" class="muted">No ad data yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row) : ?>
                        <tr>
                            <td><?= cms_esc((string) $row['name']) ?></td>
                            <td><code><?= cms_esc((string) $row['ad_type']) ?></code></td>
                            <td><?= cms_esc((string) ($row['position_name'] ?? '—')) ?></td>
                            <td><?= (int) $row['impressions'] ?></td>
                            <td><?= (int) $row['clicks'] ?></td>
                            <td><?= $fmtCtr($row) ?></td>
                            <td><span class="pill pill--<?= (int) $row['is_active'] === 1 ? 'ok' : 'muted' ?>"><?= (int) $row['is_active'] === 1 ? 'Active' : 'Inactive' ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
