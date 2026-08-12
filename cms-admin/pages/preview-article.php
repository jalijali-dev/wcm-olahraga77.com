<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';

/**
 * Read-only article preview — lets an admin see how an article (draft or
 * published) will read before it goes live. Intentionally standalone
 * (no sidebar/navbar chrome) so it approximates a real reading view.
 * Admin-only: still gated by auth.php above, so unpublished drafts are
 * never exposed publicly.
 */

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    die('Invalid article id.');
}

$stmt = $pdo->prepare(
    'SELECT p.*, c.name AS category_name, a.name AS author_name
     FROM pages p
     LEFT JOIN article_categories c ON c.id = p.category_id
     LEFT JOIN admins a ON a.admin_id = p.author_id
     WHERE p.page_id = :id
     LIMIT 1'
);
$stmt->execute(['id' => $id]);
$article = $stmt->fetch();

if ($article === false) {
    http_response_code(404);
    die('Article not found.');
}

$tagStmt = $pdo->prepare(
    'SELECT t.name FROM article_tags t
     INNER JOIN article_tag_map m ON m.tag_id = t.id
     WHERE m.page_id = :id
     ORDER BY t.name ASC'
);
$tagStmt->execute(['id' => $id]);
$tags = array_column($tagStmt->fetchAll(), 'name');

$fmtDt = static function (?string $value): string {
    if ($value === null || $value === '') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts !== false ? date('d M Y, H:i', $ts) : $value;
};
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preview: <?= cms_esc((string) $article['title']) ?></title>
    <style>
        body { margin: 0; padding: 0; background: #0f0a1a; color: #e8e6f0; font-family: 'Inter', system-ui, sans-serif; }
        .preview-bar { background: #7c3aed; color: #fff; padding: 10px 20px; font-size: 13px; font-weight: 600; text-align: center; }
        .preview-bar strong { text-transform: uppercase; letter-spacing: .04em; }
        .wrap { max-width: 760px; margin: 0 auto; padding: 40px 20px 80px; }
        .meta { display: flex; flex-wrap: wrap; gap: 10px; font-size: 13px; color: #a89fc4; margin-bottom: 14px; }
        .meta span::after { content: '·'; margin-left: 10px; color: #5a5170; }
        .meta span:last-child::after { content: none; }
        h1 { font-size: 2rem; line-height: 1.25; margin: 0 0 16px; }
        .featured-img { width: 100%; border-radius: 14px; margin-bottom: 24px; display: block; }
        .excerpt { font-size: 1.05rem; color: #c9c3dc; border-left: 3px solid #7c3aed; padding-left: 14px; margin-bottom: 28px; }
        .content { font-size: 1rem; line-height: 1.8; }
        .content img { max-width: 100%; border-radius: 10px; }
        .tags { margin-top: 32px; display: flex; flex-wrap: wrap; gap: 8px; }
        .tags span { background: #241a3a; border: 1px solid #392a5c; border-radius: 999px; padding: 4px 12px; font-size: 12px; color: #c9c3dc; }
        .seo-box { margin-top: 40px; padding: 16px; border-radius: 12px; background: #170f28; border: 1px solid #2c2148; font-size: 12px; color: #a89fc4; }
        .seo-box h3 { margin: 0 0 8px; font-size: 13px; color: #e8e6f0; }
        .seo-box dl { margin: 0; display: grid; grid-template-columns: 120px 1fr; gap: 4px 10px; }
        .seo-box dt { color: #7c6f9e; }
    </style>
</head>
<body>
    <div class="preview-bar">
        <strong>Preview mode</strong> — status: <?= cms_esc(ucfirst((string) $article['status'])) ?> — bukan halaman publik, hanya admin yang bisa lihat ini.
    </div>
    <div class="wrap">
        <div class="meta">
            <?php if (($article['category_name'] ?? '') !== null && $article['category_name'] !== '') : ?>
                <span><?= cms_esc((string) $article['category_name']) ?></span>
            <?php endif; ?>
            <span>Oleh <?= cms_esc($article['author_name'] !== null ? (string) $article['author_name'] : 'Admin') ?></span>
            <span><?= cms_esc($fmtDt($article['published_at'] ?? null)) ?></span>
            <span><?= (int) ($article['views'] ?? 0) ?> views</span>
        </div>
        <h1><?= cms_esc((string) $article['title']) ?></h1>
        <?php if (!empty($article['featured_image'])) : ?>
            <img class="featured-img" src="<?= cms_esc((string) $article['featured_image']) ?>" alt="">
        <?php endif; ?>
        <?php if (!empty($article['excerpt'])) : ?>
            <p class="excerpt"><?= cms_esc((string) $article['excerpt']) ?></p>
        <?php endif; ?>
        <div class="content"><?= $article['content'] ?? '' ?></div>
        <?php if ($tags !== []) : ?>
            <div class="tags">
                <?php foreach ($tags as $tag) : ?>
                    <span>#<?= cms_esc($tag) ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="seo-box">
            <h3>SEO preview (tidak tampil ke publik)</h3>
            <dl>
                <dt>Meta title</dt><dd><?= cms_esc((string) ($article['meta_title'] ?? '') ?: '—') ?></dd>
                <dt>Meta description</dt><dd><?= cms_esc((string) ($article['meta_description'] ?? '') ?: '—') ?></dd>
                <dt>Meta keywords</dt><dd><?= cms_esc((string) ($article['meta_keywords'] ?? '') ?: '—') ?></dd>
                <dt>Canonical URL</dt><dd><?= cms_esc((string) ($article['canonical_url'] ?? '') ?: '—') ?></dd>
                <dt>Slug</dt><dd>/<?= cms_esc((string) $article['slug']) ?></dd>
            </dl>
        </div>
    </div>
</body>
</html>
