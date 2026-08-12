<?php
declare(strict_types=1);

/**
 * /sitemap.xml — sitemap index. Delegates the actual URL list to whatever
 * cms-admin/includes/sitemap-service.php has already written into
 * `sitemap_urls` (populated by the admin's article/category/tag save
 * hooks, or the "Regenerate Sitemap" button in Special Pages -> Sitemaps).
 * This file never queries `pages`/`article_categories` directly — it's a
 * pure reader over that table, per the module's own design (see the
 * header comment in sitemap-service.php).
 *
 * Rewritten from /sitemap.xml by .htaccess (this is a .php file so it can
 * run PHP; most hosts won't execute PHP inside a literal .xml file).
 */

require_once __DIR__ . '/cms-admin/config/database.php';
require_once __DIR__ . '/cms-admin/includes/sitemap-service.php';

cms_sitemap_ensure_schema($pdo);

$stmt = $pdo->query(
    "SELECT sitemap_file, MAX(lastmod) AS lastmod, COUNT(*) AS cnt
     FROM sitemap_urls
     WHERE included = 1 AND status = 'published'
     GROUP BY sitemap_file"
);
$files = $stmt->fetchAll();

header('Content-Type: application/xml; charset=UTF-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

foreach ($files as $f) {
    if ((int) $f['cnt'] < 1) {
        continue;
    }
    $loc = cms_sitemap_absolute_url((string) $f['sitemap_file']);
    $lastmod = $f['lastmod'] ? date('c', strtotime((string) $f['lastmod'])) : date('c');
    echo "  <sitemap>\n";
    echo '    <loc>' . htmlspecialchars($loc, ENT_XML1) . "</loc>\n";
    echo '    <lastmod>' . $lastmod . "</lastmod>\n";
    echo "  </sitemap>\n";
}

echo '</sitemapindex>' . "\n";
