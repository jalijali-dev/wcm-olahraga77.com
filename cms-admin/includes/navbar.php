<?php
declare(strict_types=1);

$pageTitle = $pageTitle ?? 'Dashboard';

// Notification bell — was a disabled "coming soon" placeholder, now backed
// by real data: failed generations + SEO recommendations awaiting review.
// navbar.php is required on every admin page, so growth-agent-service.php
// is loaded defensively here rather than assuming a specific page already
// pulled it in.
require_once dirname(__DIR__) . '/includes/growth-agent-service.php';
$cmsGrowthNotif = (isset($pdo) && $pdo instanceof PDO)
    ? cms_growth_agent_notifications($pdo, 8)
    : ['count' => 0, 'action_needed_count' => 0, 'new_article_count' => 0, 'items' => []];
?>
<div class="admin-main">
    <header class="admin-navbar">
        <div class="admin-navbar__left">
            <button type="button" class="admin-navbar__toggle" id="admin-sidebar-toggle" aria-label="Open menu" aria-expanded="false" aria-controls="admin-sidebar">
                <span class="admin-navbar__toggle-bars" aria-hidden="true"></span>
            </button>
            <h1 class="admin-navbar__title"><?= cms_esc($pageTitle) ?></h1>
        </div>
        <div class="admin-navbar__center">
            <label class="admin-search" data-pages-prefix="<?= cms_esc(cms_pages_prefix()) ?>">
                <span class="visually-hidden">Search</span>
                <input type="search"
                       id="admin-search-input"
                       class="admin-search__input"
                       placeholder="Search pages, articles, messages…"
                       autocomplete="off"
                       data-search-action="<?= cms_esc(cms_action_href('search.php')) ?>">
                <div class="admin-search__results" id="admin-search-results" hidden></div>
            </label>
        </div>
        <div class="admin-navbar__right">
            <div class="admin-notif" id="admin-notif">
                <button type="button"
                        class="admin-bell"
                        id="admin-notif-toggle"
                        title="Notifications"
                        aria-label="Notifications"
                        aria-haspopup="true"
                        aria-expanded="false"
                        aria-controls="admin-notif-panel">
                    <span class="admin-bell__icon" aria-hidden="true">🔔</span>
                    <?php if ($cmsGrowthNotif['count'] > 0) : ?>
                        <span class="admin-bell__dot" aria-hidden="true"></span>
                    <?php endif; ?>
                </button>
                <div class="admin-notif__panel" id="admin-notif-panel" hidden>
                    <div class="admin-notif__head">
                        <span>Growth Agent</span>
                        <?php if ($cmsGrowthNotif['action_needed_count'] > 0) : ?>
                            <span class="pill pill--warn"><?= (int) $cmsGrowthNotif['action_needed_count'] ?> perlu perhatian</span>
                        <?php endif; ?>
                        <?php if ($cmsGrowthNotif['new_article_count'] > 0) : ?>
                            <span class="pill pill--ok"><?= (int) $cmsGrowthNotif['new_article_count'] ?> artikel baru</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($cmsGrowthNotif['items'] === []) : ?>
                        <div class="admin-notif__empty">Tidak ada job yang gagal, menunggu review, atau artikel baru dalam 24 jam terakhir.</div>
                    <?php else : ?>
                        <?php foreach ($cmsGrowthNotif['items'] as $notifJob) : ?>
                            <?php
                            $notifIsNewArticle = $notifJob['notif_type'] === 'new_article';
                            $notifIsSeoRec = $notifJob['job_type'] === 'seo_recommendation' && $notifJob['status'] === 'manual_action';
                            $notifHref = $notifIsNewArticle
                                ? cms_nav_href('pages.php') . '?edit=' . (int) $notifJob['page_id']
                                : ($notifIsSeoRec
                                    ? cms_nav_href('seo-recommendation-review.php') . '?job_id=' . (int) $notifJob['id']
                                    : cms_nav_href('growth-agent.php'));
                            $notifPill = $notifIsNewArticle ? 'ok' : ($notifJob['status'] === 'failed' ? 'warn' : 'info');
                            $notifLabel = $notifIsNewArticle ? '🆕 Baru' : ($notifJob['status'] === 'failed' ? 'Gagal' : 'Perlu direview');
                            $notifTitle = $notifJob['page_title'] ? cms_esc((string) $notifJob['page_title']) : cms_esc((string) $notifJob['job_type']);
                            ?>
                            <a class="admin-notif__item<?= $notifIsNewArticle ? ' admin-notif__item--new' : '' ?>" href="<?= cms_esc($notifHref) ?>">
                                <span class="admin-notif__item-title">
                                    <?= $notifIsNewArticle ? 'Artikel baru dipublikasikan: ' . $notifTitle : $notifTitle ?>
                                </span>
                                <span class="admin-notif__item-meta">
                                    <span class="pill pill--<?= $notifPill ?>"><?= cms_esc($notifLabel) ?></span>
                                    <span class="muted"><?= cms_esc((string) $notifJob['job_type']) ?></span>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <a class="admin-notif__viewall" href="<?= cms_esc(cms_nav_href('growth-agent.php')) ?>">Lihat semua di Growth Agent &rarr;</a>
                </div>
            </div>
            <label class="theme-switcher" for="theme-switcher">
                <span class="theme-switcher__icon" aria-hidden="true">🎨</span>
                <select class="theme-switcher__select"
                        id="theme-switcher"
                        aria-label="Select colour theme"
                        data-theme-action="<?= cms_esc(cms_action_href('theme-update.php')) ?>"
                        data-csrf-token="<?= cms_esc(cms_csrf_token()) ?>">
                    <option value="deep-purple">Purple</option>
                    <option value="dark-modern">Dark</option>
                    <option value="light-modern">Light</option>
                </select>
            </label>
            <a class="admin-btn admin-btn--ghost" href="<?= cms_esc(cms_public_site_url()) ?>" target="_blank" rel="noopener">View Site</a>
        </div>
    </header>
