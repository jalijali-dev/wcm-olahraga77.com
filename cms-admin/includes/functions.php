<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';

/**
 * Server-side slugify — used when auto-creating categories/tags from admin
 * input (the client-side JS slugify in pages.php only covers the article
 * title field itself).
 */
if (!function_exists('cms_slugify')) {
    function cms_slugify(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
        return trim($text, '-');
    }
}

function cms_session_start(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        // Harden session cookie: HttpOnly + SameSite=Lax always,
        // Secure when the request is served over HTTPS.
        $secure = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

/**
 * Return the current session CSRF token, creating one on first use.
 * One token per session (not rotated per request) so multi-tab admin use
 * keeps working. Token lives in the session only — no schema change.
 */
function cms_csrf_token(): string
{
    cms_session_start();
    if (empty($_SESSION['cms_csrf_token'])) {
        $_SESSION['cms_csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['cms_csrf_token'];
}

/**
 * Hidden input markup for embedding the CSRF token in a POST form.
 */
function cms_csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . cms_esc(cms_csrf_token()) . '">';
}

/**
 * Validate the CSRF token on POST requests. Accepts the token from the
 * csrf_token POST field or the X-CSRF-Token header (for fetch calls).
 * Non-POST requests pass through untouched. On failure: hard 403 + exit.
 */
function cms_verify_csrf(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return;
    }

    $sent  = (string) ($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    $known = (string) ($_SESSION['cms_csrf_token'] ?? '');

    if ($known === '' || $sent === '' || !hash_equals($known, $sent)) {
        http_response_code(403);
        exit('Invalid or expired form token. Please go back, refresh the page, and try again.');
    }
}

/**
 * ── Role-based access control ─────────────────────────────────────────────
 *
 * Three roles, stored verbatim in `admins.role` (DB enum: 'superadmin',
 * 'admin', 'editor' — lowercase, no spaces; the admin-facing form in
 * admins.php shows friendlier labels but always submits one of these three
 * raw values). Session value is set once at login (login.php) and never
 * re-checked against the DB per-request, so a role change only takes effect
 * the next time that admin logs in — same tradeoff the rest of the session
 * already makes for name/email.
 *
 * Access tiers (15 Jul 2026, per explicit user request — previously every
 * logged-in admin had identical access regardless of role):
 *   - editor:     Pages & Articles (+ Categories/Tags), Media Library,
 *                 SEO Dashboard, Banners. Nothing else.
 *   - admin:      Everything editor has, plus everything else EXCEPT
 *                 Admin Users, AI Credentials, and Livescore API Settings
 *                 (all three touch account/API-key secrets).
 *   - superadmin: Everything, no restrictions. Also the only role that can
 *                 create/edit/view "External Ad Code" ads (see ads.php —
 *                 separate, pre-existing restriction, unrelated to the
 *                 tiers above).
 */
function cms_admin_role(): string
{
    return (string) ($_SESSION['cms_admin_role'] ?? '');
}

function cms_is_superadmin(): bool
{
    return cms_admin_role() === 'superadmin';
}

/** True for 'admin' or 'superadmin' — false for 'editor' or anything unset. */
function cms_is_admin_or_above(): bool
{
    return in_array(cms_admin_role(), ['superadmin', 'admin'], true);
}

/**
 * Gate an entire page to a set of roles. Call right after requiring
 * auth.php (auth.php only checks "logged in", not "logged in as which
 * role"). On denial: flash message + redirect to the dashboard — the admin
 * never sees a bare 403, just lands somewhere useful with an explanation.
 *
 * @param list<string> $allowedRoles e.g. ['superadmin', 'admin']
 */
function cms_require_role(array $allowedRoles): void
{
    if (in_array(cms_admin_role(), $allowedRoles, true)) {
        return;
    }
    $_SESSION['cms_flash'] = [
        'type' => 'error',
        'message' => 'Kamu tidak punya akses ke halaman ini. Hubungi Super Admin kalau merasa ini seharusnya diizinkan.',
    ];
    header('Location: ' . cms_dashboard_href(), true, 302);
    exit;
}

function cms_is_demo_authenticated(): bool
{
    return !empty($_SESSION['cms_demo_auth']);
}

function cms_require_demo_auth(): void
{
    cms_session_start();
    if (!cms_is_demo_authenticated()) {
        header('Location: ' . cms_login_href());
        exit;
    }
}

/**
 * True when the current script lives under cms-admin/pages/.
 */
function cms_is_pages_subdirectory(): bool
{
    $path = $_SERVER['SCRIPT_FILENAME'] ?? '';
    return str_contains($path, DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR);
}

function cms_nav_href(string $pageFilename): string
{
    return cms_is_pages_subdirectory() ? $pageFilename : 'pages/' . $pageFilename;
}

/**
 * Prefix needed to reach cms-admin/pages/*.php from the current script.
 * Used client-side (global search) to turn a bare filename returned by
 * actions/search.php into a link that resolves correctly no matter which
 * admin page the search was triggered from.
 */
function cms_pages_prefix(): string
{
    return cms_is_pages_subdirectory() ? '' : 'pages/';
}

function cms_dashboard_href(): string
{
    return cms_is_pages_subdirectory() ? '../dashboard.php' : 'dashboard.php';
}

function cms_login_href(): string
{
    return cms_is_pages_subdirectory() ? '../login.php' : 'login.php';
}

function cms_logout_href(): string
{
    return cms_is_pages_subdirectory() ? '../logout.php' : 'logout.php';
}

function cms_action_href(string $actionFilename): string
{
    return cms_is_pages_subdirectory() ? '../actions/' . $actionFilename : 'actions/' . $actionFilename;
}

/**
 * Prefix to reach cms-admin/api/*.php (AI content-generation endpoints)
 * from the current script. Same topology-agnostic pattern as
 * cms_action_href() — detected from the on-disk script location, so it
 * resolves correctly regardless of hosting topology (nested path under
 * the main domain, e.g. sagagoal.com/cms-admin/ — current production
 * setup since 7 Aug 2026 — or the older split-subdomain setup this
 * project used before that, e.g. wpm.sagagoal.com).
 */
function cms_api_href(string $apiFilename): string
{
    return cms_is_pages_subdirectory() ? '../api/' . $apiFilename : 'api/' . $apiFilename;
}

/**
 * ── Admin colour theme (session-backed) ───────────────────────────────
 * The selected theme is stored server-side in $_SESSION so it follows the
 * admin across every page navigation, not just while JS/localStorage is
 * available. cms-admin/actions/theme-update.php writes to this session key.
 */
function cms_valid_themes(): array
{
    return ['deep-purple', 'dark-modern', 'light-modern'];
}

function cms_current_theme(): string
{
    cms_session_start();
    $theme = (string) ($_SESSION['wpm_theme'] ?? '');
    return in_array($theme, cms_valid_themes(), true) ? $theme : 'deep-purple';
}

function cms_settings_href(): string
{
    return cms_nav_href('site-settings.php');
}

function cms_asset_url(string $relativePath): string
{
    // Relative to the current script (not SCRIPT_NAME string-matching), so this
    // works whether cms-admin/ is served as a nested folder (local dev, e.g.
    // domain.test/cms-admin/pages/x.php, OR production since 7 Aug 2026, e.g.
    // sagagoal.com/cms-admin/pages/x.php) OR as the document root of its own
    // (sub)domain (the older production setup this project used before that,
    // e.g. wpm.sagagoal.com/pages/x.php) — both cases are detected purely
    // from the on-disk script location.
    $relativePath = ltrim($relativePath, '/');
    $prefix = cms_is_pages_subdirectory() ? '../assets/' : 'assets/';
    return $prefix . $relativePath;
}

/**
 * Absolute URL to the cms-admin/ root itself (unlike BASE_URL, which
 * intentionally points to the project root for asset/upload resolution —
 * see config/app.php). Used anywhere we need a link INTO the admin panel
 * from outside a browser session (e.g. Telegram digest, webhook payloads).
 */
function cms_admin_base_url(): string
{
    return rtrim(BASE_URL, '/') . '/cms-admin/';
}

/**
 * Prefix that reaches the public front-end (project root) from wherever the
 * current admin script lives.
 *
 * - Local dev, and production since 7 Aug 2026 (cms-admin/ nested under
 *   the project root / main domain, same host — e.g.
 *   sagagoal.com/cms-admin/pages/x.php): a plain relative "../" (or
 *   "../../" from pages/) is enough.
 * - The older production setup this project used before 7 Aug 2026
 *   (wpm.sagagoal.com's document root pointed straight at cms-admin/,
 *   so the public site was a *different* host entirely — a relative
 *   path could never reach it): build an absolute URL by stripping the
 *   "wpm." prefix off the current host, e.g. wpm.sagagoal.com ->
 *   sagagoal.com. Kept as a fallback branch below in case that DNS/vhost
 *   setup is ever reintroduced — harmless no-op under the current
 *   nested-path topology since HTTP_HOST no longer starts with "wpm.".
 */
function cms_public_base_prefix(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (str_starts_with($host, 'wpm.')) {
        $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            ? 'https' : 'http';
        return $scheme . '://' . substr($host, 4) . '/';
    }
    return cms_is_pages_subdirectory() ? '../../' : '../';
}

function cms_public_site_url(): string
{
    return cms_public_base_prefix() . 'index.php';
}

/**
 * Admin panel browser-tab icon. This is the WPM admin brand (a fixed
 * template asset shipped with the panel), deliberately independent of
 * Site Settings — site_settings.logo_path/favicon_path is the PUBLIC
 * site's brand (e.g. the Sagagoal logo), a completely different
 * identity from the admin panel's own "WPM" mark. An earlier version of
 * this function pulled from site_settings instead, which incorrectly
 * showed the public site's logo as the admin panel's tab icon.
 *
 * cms-admin/assets/img/logo.png already IS the correct WPM mark (black
 * monogram, transparent background) — same asset used for the sidebar
 * brand on the light theme (see includes/sidebar.php) — so the favicon
 * just reuses it via cms_asset_url(), same as every other admin asset.
 */
function cms_favicon_url(): string
{
    return cms_asset_url('img/logo.png');
}

function cms_esc(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Best-effort sanitizer for the Advertisements "Custom HTML" ad type
 * (cms-admin/pages/ads.php, ad_type='html'). Applied at SAVE time, not at
 * render time, so the stored value is already safe and the frontend never
 * needs to re-sanitize on every page view.
 *
 * This is a regex-based blocklist, not a full HTML parser — it strips the
 * dangerous constructs explicitly called out in the brief (script/iframe/
 * object/embed tags, on*="" event handler attributes, javascript: URLs).
 * It is intentionally NOT applied to "External Ad Code" (ad_type =
 * 'external_code'), which is a separate, more trusted field restricted to
 * superadmin — see the role check in ads.php's POST handler — precisely
 * because legitimate ad-network embed codes are usually <script> tags
 * that this sanitizer would otherwise strip.
 */
function cms_sanitize_ad_html(string $html): string
{
    // Whole dangerous elements, tags + their content.
    $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? $html;
    $html = preg_replace('#<iframe\b[^>]*>.*?</iframe>#is', '', $html) ?? $html;
    $html = preg_replace('#<style\b[^>]*>.*?</style>#is', '', $html) ?? $html;
    // Self-closing / no-content-model dangerous tags.
    $html = preg_replace('#<(object|embed|applet|form|link|meta|base)\b[^>]*/?>#is', '', $html) ?? $html;
    // Any on*="..." / on*='...' / on*=bareword event handler attribute.
    $html = preg_replace('#\s+on[a-z]+\s*=\s*"[^"]*"#is', '', $html) ?? $html;
    $html = preg_replace("#\\s+on[a-z]+\\s*=\\s*'[^']*'#is", '', $html) ?? $html;
    $html = preg_replace('#\s+on[a-z]+\s*=\s*[^\s>]+#is', '', $html) ?? $html;
    // javascript:/data: URLs inside href/src attributes.
    $html = preg_replace('#(href|src)(\s*=\s*)(["\'])\s*(javascript|data):[^"\']*\3#is', '$1$2$3#$3', $html) ?? $html;

    return trim($html);
}
