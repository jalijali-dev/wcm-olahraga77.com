<?php
declare(strict_types=1);

/**
 * includes/site-bootstrap.php — public-frontend bootstrap for olahraga77.com.
 *
 * Separate from cms-admin entirely: the public site is a brand-new
 * frontend (built 12 Agu 2026, see /CLAUDE.md task list). It reuses only
 * cms-admin/config/database.php (the DB connection) and a couple of pure
 * helper functions from cms-admin/includes/functions.php — it does NOT
 * reuse cms-admin's session/auth/admin UI in any way.
 *
 * Focus of this site (per operator, 12 Agu 2026): sepakbola domestik
 * Indonesia (Liga 1, Timnas, Transfer domestik) dengan sedikit bola dunia
 * masuk kategori "Ragam". Nav: TIMNAS / LIGA 1 / TRANSFER / RAGAM.
 */

require_once dirname(__DIR__) . '/cms-admin/config/database.php';
require_once dirname(__DIR__) . '/cms-admin/includes/schema-guard.php';

/** cms_slugify() polyfill — avoids pulling in all of cms-admin/functions.php
 *  (which requires config/app.php + admin session helpers we don't want here). */
if (!function_exists('cms_slugify')) {
    function cms_slugify(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
        return trim($text, '-');
    }
}

// ─── Site identity ──────────────────────────────────────────────────────────
define('WPM_SITE_NAME', 'Olahraga77.com');
define('WPM_SITE_TAGLINE', 'Kabar Sepak Bola Indonesia Terlengkap');

/**
 * Site can be deployed either at the domain root (production, e.g.
 * https://olahraga77.com/) or under a subfolder (local dev, e.g.
 * http://localhost:8008/wcm_olahraga77.com/ — confirmed 12 Agu 2026,
 * operator's docker vhost serves multiple projects from one docroot).
 * Every internal URL (CSS, images, article/category links, search form)
 * MUST go through wpm_base_url() instead of a hardcoded leading "/", or
 * links silently 404 whenever the site isn't at the domain root — this
 * bit us on first local test (12 Agu 2026): CSS didn't load and article
 * links pointed at localhost:8008/artikel/... instead of
 * localhost:8008/wcm_olahraga77.com/artikel/....
 *
 * dirname($_SERVER['SCRIPT_NAME']) is reliable even under the
 * /artikel/{slug} and /kategori/{slug} rewrites in .htaccess, because
 * SCRIPT_NAME always reflects the *physical* PHP file being executed
 * (artikel.php / kategori.php), not the rewritten request URI.
 */
if (!defined('WPM_BASE_PATH')) {
    $wpmScriptDir = dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'));
    $wpmScriptDir = str_replace('\\', '/', $wpmScriptDir);
    define('WPM_BASE_PATH', $wpmScriptDir === '/' ? '' : rtrim($wpmScriptDir, '/'));
}

function wpm_base_url(string $path = ''): string
{
    $path = '/' . ltrim($path, '/');
    return WPM_BASE_PATH . $path;
}

/**
 * The 4 nav categories, in menu order. slug => label. This is the single
 * source of truth for both the header nav and the category migration
 * below — keep in sync if the operator wants to add/rename a menu item.
 */
function wpm_site_nav_categories(): array
{
    return [
        'timnas'   => 'Timnas',
        'liga-1'   => 'Liga 1',
        'transfer' => 'Transfer',
        'ragam'    => 'Ragam',
    ];
}

/**
 * One-time-ish idempotent migration: collapse the old livescore-era
 * category set (Sepak Bola, Liga Champions, Liga Inggris, Business,
 * Sports, Livescore, Apps, Guides, General News — seeded back when this
 * cms-admin was still a sagagoal.com clone) down to the 4 categories this
 * site actually uses. Any article sitting in a category outside the
 * final 4 gets reassigned to "Ragam" before the stale category row is
 * deleted, so no article silently disappears from listings.
 *
 * Safe to run on every page load: it only does work when a stale
 * category still exists (checked first), so after the first real run
 * this is a single cheap SELECT.
 */
function wpm_site_migrate_categories(PDO $pdo): void
{
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;

    $final = wpm_site_nav_categories();

    try {
        // Ensure the 4 target categories exist.
        $ids = [];
        foreach ($final as $slug => $label) {
            $stmt = $pdo->prepare('SELECT id FROM article_categories WHERE slug = :slug');
            $stmt->execute(['slug' => $slug]);
            $id = $stmt->fetchColumn();
            if ($id === false) {
                $ins = $pdo->prepare('INSERT INTO article_categories (name, slug) VALUES (:name, :slug)');
                $ins->execute(['name' => $label, 'slug' => $slug]);
                $id = (int) $pdo->lastInsertId();
            } else {
                // Keep the label in sync with the canonical nav label.
                $upd = $pdo->prepare('UPDATE article_categories SET name = :name WHERE id = :id');
                $upd->execute(['name' => $label, 'id' => (int) $id]);
            }
            $ids[$slug] = (int) $id;
        }

        // Find any category outside the final 4 — these are the
        // livescore-era leftovers (or an old "Timnas"/"Transfer" row with
        // a different slug spelling than our canonical one).
        //
        // BUG (found 12 Agu 2026, live on production): this used to bind
        // array_values($ids) here — but $ids is keyed BY SLUG (see the
        // `$ids[$slug] = (int) $id;` loop above), so array_values($ids)
        // gives the numeric category IDs (e.g. 988, 989...), not their
        // slugs. Comparing `slug NOT IN (988, 989, ...)` is never true for
        // any real slug, so EVERY category — including all 4 correct ones
        // — matched as "stale" and got deleted on every single public page
        // load. This is why categories kept vanishing right after being
        // added, every time the homepage or a /kategori/{slug} page was
        // opened. array_keys($ids) is the actual slug list.
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stale = $pdo->prepare("SELECT id, slug FROM article_categories WHERE slug NOT IN ($placeholders)");
        $stale->execute(array_keys($ids));
        $staleRows = $stale->fetchAll();

        if (!$staleRows) {
            return; // already clean — nothing left to migrate
        }

        $ragamId = $ids['ragam'];
        $staleIds = array_map(static fn($r) => (int) $r['id'], $staleRows);

        // Reassign any article still pointing at a stale category to Ragam.
        $reassignPlaceholders = implode(',', array_fill(0, count($staleIds), '?'));
        $reassign = $pdo->prepare("UPDATE pages SET category_id = ? WHERE category_id IN ($reassignPlaceholders)");
        $reassign->execute(array_merge([$ragamId], $staleIds));

        // Delete the now-unused stale category rows.
        $delete = $pdo->prepare("DELETE FROM article_categories WHERE id IN ($reassignPlaceholders)");
        $delete->execute($staleIds);
    } catch (Throwable $e) {
        // Never fatal the public site over a category cleanup — log and move on.
        error_log('[wpm_site_migrate_categories] ' . $e->getMessage());
    }
}

wpm_site_migrate_categories($pdo);

// ─── URL helpers ────────────────────────────────────────────────────────────

function wpm_article_url(string $slug): string
{
    return wpm_base_url('/artikel/' . rawurlencode($slug));
}

function wpm_category_url(string $slug): string
{
    return wpm_base_url('/kategori/' . rawurlencode($slug));
}

// ─── Data helpers ───────────────────────────────────────────────────────────

/** Published articles, newest first, optionally filtered by category slug. */
function wpm_get_articles(PDO $pdo, int $limit = 10, int $offset = 0, ?string $categorySlug = null): array
{
    $sql = 'SELECT p.page_id, p.title, p.slug, p.excerpt, p.featured_image, p.published_at,
                   p.is_featured, p.is_trending, p.views, c.name AS category_name, c.slug AS category_slug
            FROM pages p
            LEFT JOIN article_categories c ON c.id = p.category_id
            WHERE p.status = "published" AND p.published_at IS NOT NULL AND p.published_at <= NOW()';
    $params = [];
    if ($categorySlug !== null) {
        $sql .= ' AND c.slug = :slug';
        $params['slug'] = $categorySlug;
    }
    $sql .= ' ORDER BY p.published_at DESC LIMIT :limit OFFSET :offset';

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function wpm_count_articles(PDO $pdo, ?string $categorySlug = null): int
{
    $sql = 'SELECT COUNT(*) FROM pages p LEFT JOIN article_categories c ON c.id = p.category_id
            WHERE p.status = "published" AND p.published_at IS NOT NULL AND p.published_at <= NOW()';
    $params = [];
    if ($categorySlug !== null) {
        $sql .= ' AND c.slug = :slug';
        $params['slug'] = $categorySlug;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
}

function wpm_get_article_by_slug(PDO $pdo, string $slug): ?array
{
    $stmt = $pdo->prepare(
        'SELECT p.*, c.name AS category_name, c.slug AS category_slug
         FROM pages p
         LEFT JOIN article_categories c ON c.id = p.category_id
         WHERE p.slug = :slug AND p.status = "published"
         LIMIT 1'
    );
    $stmt->execute(['slug' => $slug]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function wpm_get_related_articles(PDO $pdo, int $categoryId, int $excludePageId, int $limit = 5): array
{
    $stmt = $pdo->prepare(
        'SELECT page_id, title, slug, featured_image, published_at
         FROM pages
         WHERE status = "published" AND category_id = :cat AND page_id != :exclude
         ORDER BY published_at DESC LIMIT :limit'
    );
    $stmt->bindValue('cat', $categoryId, PDO::PARAM_INT);
    $stmt->bindValue('exclude', $excludePageId, PDO::PARAM_INT);
    $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function wpm_get_popular_articles(PDO $pdo, int $limit = 5): array
{
    $stmt = $pdo->prepare(
        'SELECT page_id, title, slug, featured_image
         FROM pages WHERE status = "published"
         ORDER BY views DESC, published_at DESC LIMIT :limit'
    );
    $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function wpm_increment_views(PDO $pdo, int $pageId): void
{
    try {
        $stmt = $pdo->prepare('UPDATE pages SET views = views + 1 WHERE page_id = :id');
        $stmt->execute(['id' => $pageId]);
    } catch (Throwable $e) {
        // non-critical
    }
}

// ─── Display helpers ────────────────────────────────────────────────────────

function wpm_esc(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function wpm_image_url(?string $path): string
{
    $path = trim((string) $path);
    if ($path === '') {
        return wpm_base_url('/assets/img/placeholder.svg');
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    return wpm_base_url($path);
}

function wpm_time_ago(?string $datetime): string
{
    if (!$datetime) {
        return '';
    }
    $ts = strtotime($datetime);
    if ($ts === false) {
        return '';
    }
    $diff = time() - $ts;
    if ($diff < 60) {
        return 'Baru saja';
    }
    if ($diff < 3600) {
        return floor($diff / 60) . ' menit yang lalu';
    }
    if ($diff < 86400) {
        return floor($diff / 3600) . ' jam yang lalu';
    }
    if ($diff < 86400 * 7) {
        return floor($diff / 86400) . ' hari yang lalu';
    }

    return date('d M Y', $ts);
}
