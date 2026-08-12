<?php
declare(strict_types=1);

if (!isset($cmsDashboardFragment)) {
    header('Location: ../dashboard.php', true, 302);
    exit;
}

// ── Stats queries — one fetch per table, safe fallback to [] / 0 on any error ──
// Every stat here matches the Phase 8 dashboard-widget spec: articles,
// categories, views, trending, and ads. Each query is wrapped defensively
// since some tables are created lazily by their own admin pages on first
// visit and may not exist yet on a fresh install.

$safeScalar = static function (PDO $pdo, string $sql): int {
    try {
        $row = $pdo->query($sql)->fetch();
        return (int) ($row[0] ?? array_values($row ?: [0])[0] ?? 0);
    } catch (\Throwable $e) {
        return 0;
    }
};

$messageStats = $pdo->query(
    'SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread
     FROM contact_messages'
)->fetch() ?: [];

$pageStats = $pdo->query(
    'SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = \'published\' THEN 1 ELSE 0 END) AS published,
        SUM(CASE WHEN status = \'draft\' THEN 1 ELSE 0 END) AS draft,
        SUM(CASE WHEN is_trending = 1 THEN 1 ELSE 0 END) AS trending,
        SUM(views) AS total_views
     FROM pages'
)->fetch() ?: [];

$totalCategories = $safeScalar($pdo, 'SELECT COUNT(*) FROM article_categories');

$redirectStats = $pdo->query(
    'SELECT COUNT(*) AS total FROM seo_redirects'
)->fetch() ?: [];

// media_library may not be present in all environments yet — fail silently.
$mediaTotal = 0;
try {
    $mediaRow = $pdo->query('SELECT COUNT(*) AS total FROM media_library')->fetch();
    $mediaTotal = (int) ($mediaRow['total'] ?? 0);
} catch (\Throwable $e) {
    $mediaTotal = 0;
}

// Build stats cards array — same structure the template iterates.
$statsCards = [
    [
        'label' => 'Total articles',
        'value' => (string) (int) ($pageStats['total'] ?? 0),
        'hint'  => (int) ($pageStats['published'] ?? 0) . ' published',
    ],
    [
        'label' => 'Published articles',
        'value' => (string) (int) ($pageStats['published'] ?? 0),
        'hint'  => 'Live on the site',
    ],
    [
        'label' => 'Draft articles',
        'value' => (string) (int) ($pageStats['draft'] ?? 0),
        'hint'  => 'Awaiting publish',
    ],
    [
        'label' => 'Total categories',
        'value' => (string) $totalCategories,
        'hint'  => 'Article categories',
    ],
    [
        'label' => 'Total views',
        'value' => (string) (int) ($pageStats['total_views'] ?? 0),
        'hint'  => 'All-time article views',
    ],
    [
        'label' => 'Trending articles',
        'value' => (string) (int) ($pageStats['trending'] ?? 0),
        'hint'  => 'Flagged as trending',
    ],
    [
        'label' => 'Media files',
        'value' => (string) $mediaTotal,
        'hint'  => 'Library uploads',
    ],
];

// ── Recent pages & articles ───────────────────────────────────────────────────

$recentPages = $pdo->query(
    'SELECT page_id, title, slug, status, updated_at
     FROM pages
     ORDER BY updated_at DESC, page_id DESC
     LIMIT 5'
)->fetchAll();

// ── Latest contact messages ───────────────────────────────────────────────────

$latestMessages = $pdo->query(
    'SELECT id, full_name, email, subject, is_read, created_at
     FROM contact_messages
     ORDER BY created_at DESC, id DESC
     LIMIT 5'
)->fetchAll();

// ── Top 5 articles by views — replaces the old static "Traffic snapshot"
// placeholder bars with real data. Published only (a draft's view count
// isn't meaningful traffic yet).
$topViewedArticles = $pdo->query(
    'SELECT page_id, title, views
       FROM pages
      WHERE status = \'published\'
      ORDER BY views DESC, page_id DESC
      LIMIT 5'
)->fetchAll();

// ── Date formatter ────────────────────────────────────────────────────────────

$fmtDt = static function (?string $value): string {
    if ($value === null || $value === '') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts !== false ? date('d M Y, H:i', $ts) : $value;
};
?>
<section class="admin-stack">
    <div class="admin-grid admin-grid--stats">
        <?php foreach ($statsCards as $card) : ?>
            <article class="stat-card">
                <div class="stat-card__label"><?= cms_esc($card['label']) ?></div>
                <div class="stat-card__value"><?= cms_esc($card['value']) ?></div>
                <div class="stat-card__hint"><?= cms_esc($card['hint']) ?></div>
            </article>
        <?php endforeach; ?>
    </div>

    <div class="admin-grid admin-grid--2">
        <section class="panel">
            <div class="panel__head">
                <h2 class="panel__title">Recent pages &amp; articles</h2>
                <a class="panel__link" href="<?= cms_esc(cms_nav_href('pages.php')) ?>">View all</a>
            </div>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Slug</th>
                            <th>Status</th>
                            <th>Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($recentPages === []) : ?>
                            <tr><td colspan="4" class="muted">No pages or articles yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($recentPages as $row) : ?>
                            <?php $published = ($row['status'] ?? '') === 'published'; ?>
                            <tr>
                                <td><?= cms_esc((string) ($row['title'] ?? '')) ?></td>
                                <td><code><?= cms_esc((string) ($row['slug'] ?? '')) ?></code></td>
                                <td>
                                    <span class="pill pill--<?= $published ? 'ok' : 'muted' ?>">
                                        <?= cms_esc(ucfirst((string) ($row['status'] ?? 'draft'))) ?>
                                    </span>
                                </td>
                                <td><?= cms_esc($fmtDt($row['updated_at'] ?? null)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <div class="panel__head">
                <h2 class="panel__title">Quick actions</h2>
            </div>
            <?php
            // Only link to pages the current role can actually open — see
            // cms_require_role() in functions.php for the tier breakdown.
            $qaIsAdminUp = cms_is_admin_or_above();
            $qaIsSuper = cms_is_superadmin();
            ?>
            <div class="quick-actions">
                <?php // Trimmed to 5 primary shortcuts 24 Jul 2026 — the rest (Contact
                // messages, Manage ads, SEO redirects, SEO schema) stay reachable via
                // the sidebar, just no longer duplicated here. ?>
                <a class="quick-actions__btn" href="<?= cms_esc(cms_nav_href('pages.php')) ?>">Manage pages &amp; articles</a>
                <a class="quick-actions__btn" href="<?= cms_esc(cms_nav_href('media-library.php')) ?>">Media library</a>
                <?php // Livescore API Settings quick action removed 11 Agu 2026 — this
                // site (olahraga77.com) doesn't integrate any livescore API, see
                // CLAUDE.md. ?>
                <a class="quick-actions__btn" href="<?= cms_esc(cms_nav_href('seo-dashboard.php')) ?>">SEO Dashboard</a>
                <?php if ($qaIsAdminUp) : ?>
                <a class="quick-actions__btn" href="<?= cms_esc(cms_nav_href('special-pages.php')) ?>">Edit Special Pages</a>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <div class="admin-grid admin-grid--2">
        <section class="panel">
            <div class="panel__head">
                <h2 class="panel__title">Artikel Terpopuler</h2>
                <span class="panel__meta">Top 5 by views</span>
            </div>
            <div class="views-chart">
                <?php if ($topViewedArticles === []) : ?>
                    <p class="muted">Belum ada artikel published untuk ditampilkan.</p>
                <?php else : ?>
                    <?php $viewsMax = max(1, (int) $topViewedArticles[0]['views']); ?>
                    <?php foreach ($topViewedArticles as $viewsIdx => $viewsRow) : ?>
                        <?php
                        $viewsCount = (int) ($viewsRow['views'] ?? 0);
                        // Minimum 4% so a 0-view (or far-behind-#1) article still
                        // shows a sliver of bar instead of visually vanishing.
                        $viewsPct = max(4, (int) round(($viewsCount / $viewsMax) * 100));
                        ?>
                        <a class="views-chart__row" href="<?= cms_esc(cms_nav_href('pages.php')) ?>?edit=<?= (int) $viewsRow['page_id'] ?>">
                            <div class="views-chart__label">
                                <span class="views-chart__rank">#<?= $viewsIdx + 1 ?></span>
                                <span class="views-chart__title"><?= cms_esc((string) $viewsRow['title']) ?></span>
                            </div>
                            <div class="views-chart__track">
                                <div class="views-chart__fill" style="width: <?= $viewsPct ?>%;"></div>
                            </div>
                            <span class="views-chart__value"><?= number_format($viewsCount, 0, ',', '.') ?> views</span>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <section class="panel">
            <div class="panel__head">
                <h2 class="panel__title">Latest contact messages</h2>
                <a class="panel__link" href="<?= cms_esc(cms_nav_href('contact-messages.php')) ?>">Open inbox</a>
            </div>
            <ul class="message-list">
                <?php if ($latestMessages === []) : ?>
                    <li class="message-list__item muted">No messages yet.</li>
                <?php endif; ?>
                <?php foreach ($latestMessages as $msg) : ?>
                    <?php $isUnread = (int) ($msg['is_read'] ?? 1) === 0; ?>
                    <li class="message-list__item">
                        <div class="message-list__top">
                            <strong><?= cms_esc((string) ($msg['full_name'] ?? '')) ?></strong>
                            <?php if ($isUnread) : ?>
                                <span class="pill pill--accent">New</span>
                            <?php endif; ?>
                        </div>
                        <div class="message-list__sub">
                            <?= cms_esc((string) ($msg['email'] ?? '')) ?>
                            · <?= cms_esc($fmtDt($msg['created_at'] ?? null)) ?>
                        </div>
                        <div class="message-list__subject"><?= cms_esc((string) ($msg['subject'] ?? '')) ?></div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    </div>
</section>
