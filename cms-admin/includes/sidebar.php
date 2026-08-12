<?php
declare(strict_types=1);

/**
 * ── Role-based sidebar filtering ──────────────────────────────────────────
 * Each link/item may carry a 'roles' key — a list of role names allowed to
 * see it. Omitting 'roles' (or leaving it null) means "visible to any
 * logged-in admin", matching the tiers documented in cms_require_role()
 * (functions.php): editor gets Pages & Articles, Media Library, SEO
 * Dashboard, and Banners only; admin gets everything except Admin Users,
 * AI Credentials, and Livescore API Settings; superadmin is unrestricted. This is
 * display-only — the actual enforcement lives in each page's own
 * cms_require_role() call, so a stale/cached sidebar can never grant real
 * access, only hide/show a link to it.
 */
$ROLES_ADMIN_UP = ['superadmin', 'admin'];
$ROLES_SUPER_ONLY = ['superadmin'];

$articlesNavGroup = [
    'label' => 'Pages & Articles',
    'icon' => 'article',
    'items' => [
        ['id' => 'pages', 'label' => 'All Articles', 'href' => cms_nav_href('pages.php'), 'icon' => 'file'],
        ['id' => 'pages-new', 'label' => 'Add Article', 'href' => cms_nav_href('pages.php') . '#create-page', 'icon' => 'pencil'],
        ['id' => 'article-categories', 'label' => 'Article Categories', 'href' => cms_nav_href('article-categories.php'), 'icon' => 'tag'],
        ['id' => 'article-tags', 'label' => 'Article Tags', 'href' => cms_nav_href('article-tags.php'), 'icon' => 'label'],
        // Links to admins.php, which is superadmin-only — not part of the
        // editor's "content only" tier even though it lives in this group.
        ['id' => 'authors', 'label' => 'Authors', 'href' => cms_nav_href('admins.php'), 'icon' => 'users', 'roles' => $ROLES_SUPER_ONLY],
    ],
];

$adNavGroup = [
    'label' => 'Advertisements',
    'icon' => 'megaphone',
    'items' => [
        ['id' => 'ads', 'label' => 'All Ads', 'href' => cms_nav_href('ads.php'), 'icon' => 'flag', 'roles' => $ROLES_ADMIN_UP],
        ['id' => 'ads-new', 'label' => 'Add New Ad', 'href' => cms_nav_href('ads.php') . '#create-ad', 'icon' => 'pencil', 'roles' => $ROLES_ADMIN_UP],
        ['id' => 'ad-positions', 'label' => 'Ad Positions', 'href' => cms_nav_href('ad-positions.php'), 'icon' => 'layers', 'roles' => $ROLES_ADMIN_UP],
        ['id' => 'ad-settings', 'label' => 'Ad Settings', 'href' => cms_nav_href('ad-settings.php'), 'icon' => 'gear', 'roles' => $ROLES_ADMIN_UP],
        ['id' => 'ad-statistics', 'label' => 'Ad Statistics', 'href' => cms_nav_href('ad-statistics.php'), 'icon' => 'chart', 'roles' => $ROLES_ADMIN_UP],
    ],
];

// $integrationsNavGroup and $sportsDataNavGroup removed 11 Agu 2026 — this
// is olahraga77.com's cms-admin (Tentakel 1 for sagagoal.com, see
// CLAUDE.md), which deliberately does NOT integrate any livescore API
// (football/basketball/F1 fixtures/scores/standings). Both groups only
// ever held livescore-related pages (Livescore API Settings, Football
// Matches, NBA Games, F1 Races/Podium/Standings) — all of those page
// files were deleted from cms-admin/pages+actions in the same pass as
// this sidebar cleanup, so keeping either group here would just be dead
// links. If this codebase's cms-admin is ever re-synced from
// wpm_sagagoal.com (the money-site source), re-check this file — that
// sync would silently reintroduce these groups/links even though the
// underlying pages are gone here.

$seoNavGroup = [
    'label' => 'SEO Settings',
    'icon' => 'search',
    'items' => [
        // Editor tier includes SEO Dashboard (article meta title/description).
        ['id' => 'seo-dashboard', 'label' => 'SEO Dashboard', 'href' => cms_nav_href('seo-dashboard.php'), 'icon' => 'chart'],
        ['id' => 'seo-redirects', 'label' => 'SEO Redirects', 'href' => cms_nav_href('seo-redirects.php'), 'icon' => 'link', 'roles' => $ROLES_ADMIN_UP],
        ['id' => 'sitemaps', 'label' => 'Sitemaps', 'href' => cms_nav_href('sitemaps.php'), 'icon' => 'sitemap', 'roles' => $ROLES_ADMIN_UP],
        ['id' => 'seo-schema', 'label' => 'SEO Schema', 'href' => cms_nav_href('seo-schema.php'), 'icon' => 'code', 'roles' => $ROLES_ADMIN_UP],
    ],
];

$aiNavGroup = [
    'label' => 'AI Management',
    'icon' => 'ai',
    'items' => [
        ['id' => 'ai-sandbox', 'label' => 'AI Sandbox', 'href' => cms_nav_href('ai-sandbox.php'), 'icon' => 'pencil', 'roles' => $ROLES_ADMIN_UP],
        ['id' => 'prompt-control', 'label' => 'Prompt Control', 'href' => cms_nav_href('prompt-control.php'), 'icon' => 'code', 'roles' => $ROLES_ADMIN_UP],
        // Holds raw AI provider API keys — superadmin-only.
        ['id' => 'ai-credentials', 'label' => 'AI Credentials', 'href' => cms_nav_href('ai-credentials.php'), 'icon' => 'key', 'roles' => $ROLES_SUPER_ONLY],
        ['id' => 'ai-models', 'label' => 'AI Models', 'href' => cms_nav_href('ai-models.php'), 'icon' => 'puzzle', 'roles' => $ROLES_ADMIN_UP],
        ['id' => 'ai-agent-settings', 'label' => 'AI Agent Settings', 'href' => cms_nav_href('ai-agent-settings.php'), 'icon' => 'person', 'roles' => $ROLES_ADMIN_UP],
        ['id' => 'growth-agent', 'label' => 'Growth Agent', 'href' => cms_nav_href('growth-agent.php'), 'icon' => 'chart', 'roles' => $ROLES_ADMIN_UP],
        // Holds a raw Google service-account private key — same
        // superadmin-only tier as AI Credentials above, same reasoning.
        ['id' => 'gsc-settings', 'label' => 'GSC Settings', 'href' => cms_nav_href('gsc-settings.php'), 'icon' => 'key', 'roles' => $ROLES_SUPER_ONLY],
        ['id' => 'seo-intelligence', 'label' => 'SEO Intelligence', 'href' => cms_nav_href('seo-intelligence.php'), 'icon' => 'search', 'roles' => $ROLES_ADMIN_UP],
    ],
];

/**
 * Single ordered render list — mixes standalone links and collapsible
 * groups in one sequence so we're not limited to "all flat links, then
 * all groups" like before. Requested order (updated 14 Jul 2026):
 * Pages & Articles → SEO Settings (Dashboard + Redirects + Schema, all
 * one group) → Integrations → Advertisements → AI Management. This one
 * list drives both the desktop sidebar and the mobile drawer since they
 * share this same markup (mobile just toggles visibility of the whole
 * <aside>).
 */
$sidebarSections = [
    ['type' => 'link', 'id' => 'dashboard', 'label' => 'Dashboard', 'href' => cms_dashboard_href(), 'icon' => 'grid'],
    ['type' => 'link', 'id' => 'site-settings', 'label' => 'Site Settings', 'href' => cms_nav_href('site-settings.php'), 'icon' => 'gear', 'roles' => $ROLES_ADMIN_UP],
    ['type' => 'link', 'id' => 'banners', 'label' => 'Banners', 'href' => cms_nav_href('banners.php'), 'icon' => 'flag'],
    // Admin-managed static pages (Kontak, FAQ, etc.) (Fase 3, Special
    // Pages & Sports Modules spec, 24 Jul 2026) — see includes/SpecialPages.php.
    ['type' => 'link', 'id' => 'special-pages', 'label' => 'Special Pages', 'href' => cms_nav_href('special-pages.php'), 'icon' => 'star', 'roles' => $ROLES_ADMIN_UP],
    ['type' => 'link', 'id' => 'media-library', 'label' => 'Media Library', 'href' => cms_nav_href('media-library.php'), 'icon' => 'folder'],
    ['type' => 'link', 'id' => 'contact-messages', 'label' => 'Contact Messages', 'href' => cms_nav_href('contact-messages.php'), 'icon' => 'mail', 'roles' => $ROLES_ADMIN_UP],
    // Manages other admin accounts/roles — superadmin-only. Moved out of
    // AI Management (17 Jul 2026) to sit right below Contact Messages.
    ['type' => 'link', 'id' => 'admins', 'label' => 'Admin Users', 'href' => cms_nav_href('admins.php'), 'icon' => 'users', 'roles' => $ROLES_SUPER_ONLY],

    // 1. All Articles (+ submenu)
    ['type' => 'group'] + $articlesNavGroup,

    // 2. SEO Settings — Dashboard, Redirects, and Schema all in one group.
    ['type' => 'group'] + $seoNavGroup,

    // 3 & 3b. Sports Data / Integrations groups removed 11 Agu 2026 — see
    // this file's own note above ($integrationsNavGroup/$sportsDataNavGroup
    // removal) — both only ever held now-deleted livescore pages.

    // 4. AI Management
    ['type' => 'group'] + $aiNavGroup,

    // 5. Advertisements
    ['type' => 'group'] + $adNavGroup,
];

$currentNav = $currentNav ?? '';

// Filter sections/items down to what the current session's role can see.
// See the "Role-based sidebar filtering" note above.
$sidebarRoleAllowed = static function (?array $roles): bool {
    return $roles === null || in_array(cms_admin_role(), $roles, true);
};
$sidebarSections = array_values(array_filter(array_map(
    static function (array $section) use ($sidebarRoleAllowed): ?array {
        if ($section['type'] === 'link') {
            return $sidebarRoleAllowed($section['roles'] ?? null) ? $section : null;
        }
        $section['items'] = array_values(array_filter(
            $section['items'],
            static fn (array $item): bool => $sidebarRoleAllowed($item['roles'] ?? null)
        ));
        return $section['items'] === [] ? null : $section;
    },
    $sidebarSections
)));

?>
<aside class="admin-sidebar" id="admin-sidebar" aria-label="Primary">
    <div class="admin-sidebar__brand">
        <a class="admin-sidebar__logo" href="<?= cms_esc(cms_dashboard_href()) ?>">
            <!-- img/logo.png (black) + img/logo-white.png (white) are the
                 actual WPM monogram, already theme-aware via the
                 --black/--white CSS classes below (admin.css toggles which
                 one is visible based on the active admin theme — dark/purple
                 shows white, light shows black). This is admin-panel-only
                 branding, independent of Site Settings (that's the public
                 site's own logo, a different identity). -->
            <img class="admin-sidebar__logo-badge admin-sidebar__logo-badge--black"
                 src="<?= cms_esc(cms_asset_url('img/logo.png')) ?>" alt="<?= cms_esc(CMS_ADMIN_NAME) ?> logo">
            <img class="admin-sidebar__logo-badge admin-sidebar__logo-badge--white"
                 src="<?= cms_esc(cms_asset_url('img/logo-white.png')) ?>" alt="<?= cms_esc(CMS_ADMIN_NAME) ?> logo">
            <span class="admin-sidebar__titles">
                <span class="admin-sidebar__name"><?= cms_esc(CMS_ADMIN_NAME) ?></span>
                <span class="admin-sidebar__tag"><?= cms_esc(CMS_ADMIN_TAGLINE) ?></span>
            </span>
        </a>
        <button type="button" class="admin-sidebar__close" id="admin-sidebar-close" aria-label="Close menu">&times;</button>
    </div>
    <nav class="admin-sidebar__nav" aria-label="CMS sections">
        <?php foreach ($sidebarSections as $section) : ?>
            <?php if ($section['type'] === 'link') :
                $active = $currentNav === $section['id'];
                ?>
                <a class="admin-navlink<?= $active ? ' is-active' : '' ?>" href="<?= cms_esc($section['href']) ?>" data-icon="<?= cms_esc($section['icon']) ?>">
                    <span class="admin-navlink__icon" aria-hidden="true"></span>
                    <span class="admin-navlink__label"><?= cms_esc($section['label']) ?></span>
                </a>
            <?php else : ?>
                <div class="admin-sidebar__group">
                    <div class="admin-sidebar__group-label">
                        <span class="admin-sidebar__group-icon" data-icon="<?= cms_esc($section['icon']) ?>" aria-hidden="true"></span>
                        <span><?= cms_esc($section['label']) ?></span>
                    </div>
                    <?php foreach ($section['items'] as $item) :
                        $active = $currentNav === $item['id'];
                        ?>
                        <a class="admin-navlink<?= $active ? ' is-active' : '' ?>" href="<?= cms_esc($item['href']) ?>" data-icon="<?= cms_esc($item['icon']) ?>">
                            <span class="admin-navlink__icon" aria-hidden="true"></span>
                            <span class="admin-navlink__label"><?= cms_esc($item['label']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <div class="admin-sidebar__account">
        <div class="admin-account">
            <?php
            $sidebarRoleLabels = ['superadmin' => 'Super Admin', 'admin' => 'Admin', 'editor' => 'Editor'];
            $sidebarAdminName = $_SESSION['cms_admin_name'] ?? 'Admin';
            $sidebarAdminRole = $sidebarRoleLabels[cms_admin_role()] ?? (cms_admin_role() ?: 'Super Admin');
            $sidebarAdminInitial = mb_strtoupper(mb_substr(trim($sidebarAdminName) !== '' ? trim($sidebarAdminName) : 'A', 0, 1, 'UTF-8'), 'UTF-8');
            ?>
            <div class="admin-account__avatar" aria-hidden="true"><?= cms_esc($sidebarAdminInitial) ?></div>
            <div class="admin-account__meta">
                <div class="admin-account__name"><?= cms_esc($sidebarAdminName) ?></div>
                <div class="admin-account__role"><?= cms_esc($sidebarAdminRole) ?></div>
            </div>
            <div class="admin-account__actions">
                <?php if ($sidebarRoleAllowed($ROLES_ADMIN_UP)) : ?>
                <a class="admin-iconbtn" href="<?= cms_esc(cms_nav_href('site-settings.php')) ?>" title="Site settings" aria-label="Site settings">⚙</a>
                <?php endif; ?>
                <a class="admin-iconbtn" href="<?= cms_esc(cms_logout_href()) ?>" title="Logout" aria-label="Logout">⎋</a>
            </div>
        </div>
    </div>
</aside>
