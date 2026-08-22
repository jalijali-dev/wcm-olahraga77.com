<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/site-bootstrap.php';

$slug = trim((string) ($_GET['slug'] ?? ''));
$article = $slug !== '' ? wpm_get_article_by_slug($pdo, $slug) : null;

if (!$article) {
    http_response_code(404);
    $pageTitle = 'Artikel tidak ditemukan — ' . WPM_SITE_NAME;
    require __DIR__ . '/includes/site-header.php';
    echo '<main class="wpm-main"><div class="wpm-main__primary"><h1>Artikel tidak ditemukan</h1><p><a href="' . wpm_esc(wpm_base_url('/')) . '">Kembali ke beranda</a></p></div></main>';
    require __DIR__ . '/includes/site-footer.php';
    exit;
}

wpm_increment_views($pdo, (int) $article['page_id']);
$related = $article['category_id']
    ? wpm_get_related_articles($pdo, (int) $article['category_id'], (int) $article['page_id'], 5)
    : [];
$popular = wpm_get_popular_articles($pdo, 5);

$pageTitle = ($article['meta_title'] ?: $article['title']) . ' — ' . WPM_SITE_NAME;
$metaDescription = $article['meta_description'] ?: $article['excerpt'];
$activeNavSlug = $article['category_slug'] ?? '';
require __DIR__ . '/includes/site-header.php';
?>
<main class="wpm-main">
  <div class="wpm-main__primary">
    <div class="wpm-breadcrumb">
      <a href="<?= wpm_esc(wpm_base_url('/')) ?>">Beranda</a>
      <?php if ($article['category_slug']): ?> / <a href="<?= wpm_esc(wpm_category_url($article['category_slug'])) ?>"><?= wpm_esc($article['category_name']) ?></a><?php endif; ?>
    </div>

    <?php if ($article['category_name']): ?><span class="wpm-article__category"><?= wpm_esc($article['category_name']) ?></span><?php endif; ?>
    <h1 class="wpm-article__title"><?= wpm_esc($article['title']) ?></h1>
    <div class="wpm-article__meta">
      <?php if (!empty($article['author_name'])): ?><span class="wpm-article__byline">Oleh <strong><?= wpm_esc($article['author_name']) ?></strong></span> &middot; <?php endif; ?>
      <?= wpm_esc(wpm_time_ago($article['published_at'])) ?> &middot; <?= (int) $article['views'] ?> views
    </div>

    <?php if (!empty($article['featured_image'])): ?>
    <img class="wpm-article__cover" src="<?= wpm_esc(wpm_image_url($article['featured_image'])) ?>" alt="<?= wpm_esc($article['title']) ?>">
    <?php endif; ?>

    <?= wpm_render_ad_slot($pdo, 'article-before-title', 'article', (int) $article['page_id']) ?>

    <div class="wpm-article__body"><?= $article['content'] ?></div>

    <?php $articleTags = wpm_article_tags($article['meta_keywords'] ?? null); ?>
    <?php if ($articleTags): ?>
    <div class="wpm-article__tags">
      <?php foreach ($articleTags as $tag): ?>
      <a class="wpm-tag" href="<?= wpm_esc(wpm_base_url('/cari.php?q=' . rawurlencode($tag))) ?>">#<?= wpm_esc($tag) ?></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?= wpm_render_ad_slot($pdo, 'article-after-title', 'article', (int) $article['page_id']) ?>
  </div>

  <aside class="wpm-main__sidebar">
    <?= wpm_render_ad_slot($pdo, 'sidebar-left', 'article', (int) $article['page_id']) ?>
    <?php if ($related): ?>
    <div class="wpm-sidebar-box">
      <h2 class="wpm-section-title">Artikel Terkait</h2>
      <?php foreach ($related as $r): ?>
      <div class="wpm-popular-item">
        <span class="wpm-popular-item__title"><a href="<?= wpm_esc(wpm_article_url($r['slug'])) ?>"><?= wpm_esc($r['title']) ?></a></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="wpm-sidebar-box">
      <h2 class="wpm-section-title">Terpopuler</h2>
      <?php foreach ($popular as $i => $p): ?>
      <div class="wpm-popular-item">
        <span class="wpm-popular-item__rank"><?= $i + 1 ?></span>
        <span class="wpm-popular-item__title"><a href="<?= wpm_esc(wpm_article_url($p['slug'])) ?>"><?= wpm_esc($p['title']) ?></a></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?= wpm_render_ad_slot($pdo, 'sidebar-right', 'article', (int) $article['page_id']) ?>
  </aside>
</main>
<?php require __DIR__ . '/includes/site-footer.php'; ?>
