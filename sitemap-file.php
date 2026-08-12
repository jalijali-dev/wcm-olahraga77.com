<?php
declare(strict_types=1);

/**
 * /sitemap-{articles|categories|pages|custom}.xml — one <urlset> per
 * sitemap_file bucket in `sitemap_urls`. Reached via the .htaccess rewrite
 * for /sitemap-*.xml -> sitemap-file.php?file=sitemap-*.xml.
 */

require_once __DIR__ . '/cms-admin/config/database.php';
require_once __DIR__ . '/cms-admin/includes/sitemap-service.php';

cms_sitemap_ensure_schema($pdo);

$allowed = ['sitemap-articles.xml', 'sitemap-categories.xml', 'sitemap-pages.xml', 'sitemap-custom.xml'];
$file = (string) ($_GET['file'] ?? '');
if (!in_array($file, $allowed, true)) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'Not found';
    exit;
}

$stmt = $pdo->prepare(
    "SELECT url, lastmod, priority, changefreq FROM sitemap_urls
     WHERE sitemap_file = :file AND included = 1 AND status = 'published'
     ORDER BY priority DESC, lastmod DESC"
);
$stmt->execute(['file' => $file]);
$rows = $stmt->fetchAll();

header('Content-Type: application/xml; charset=UTF-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

foreach ($rows as $row) {
    $lastmod = $row['lastmod'] ? date('c', strtotime((string) $row['lastmod'])) : date('c');
    echo "  <url>\n";
    echo '    <loc>' . htmlspecialchars((string) $row['url'], ENT_XML1) . "</loc>\n";
    echo '    <lastmod>' . $lastmod . "</lastmod>\n";
    echo '    <changefreq>' . htmlspecialchars((string) $row['changefreq'], ENT_XML1) . "</changefreq>\n";
    echo '    <priority>' . htmlspecialchars((string) $row['priority'], ENT_XML1) . "</priority>\n";
    echo "  </url>\n";
}

echo '</urlset>' . "\n";
