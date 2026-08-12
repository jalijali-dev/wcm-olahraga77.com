<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/site-bootstrap.php';

$featured = wpm_get_articles($pdo, 1, 0);
$hero = $featured[0] ?? null;
$latest = wpm_get_articles($pdo, 10, $hero ? 1 : 0);
$popular = wpm_get_popular_articles($pdo, 5);

$pageTitle = WPM_SITE_NAME . ' — ' . WPM_SITE_TAGLINE;
$metaDescription = 'Berita, hasil, dan kabar transfer sepak bola Indonesia terkini: Liga 1, Timnas Indonesia, dan bursa transfer.';
$activeNavSlug = 'home';
require __DIR__ . '/includes/site-header.php';
?>
<main class="wpm-main">
  <div class="wpm-main__primary">

    <?php if ($hero): ?>
    <a href="<?= wpm_esc(wpm_article_url($hero['slug'])) ?>" class="wpm-hero">
      <img src="<?= wpm_esc(wpm_image_url($hero['featured_image'])) ?>" alt="<?= wpm_esc($hero['title']) ?>">
      <div class="wpm-hero__overlay">
        <?php if ($hero['category_name']): ?><span class="wpm-badge"><?= wpm_esc($hero['category_name']) ?></span><?php endif; ?>
        <h1 class="wpm-hero__title"><?= wpm_esc($hero['title']) ?></h1>
        <span class="wpm-hero__meta"><?= wpm_esc(wpm_time_ago($hero['published_at'])) ?></span>
      </div>
    </a>
    <?php endif; ?>

    <h2 class="wpm-section-title">Berita Terkini</h2>
    <div class="wpm-card-list">
      <?php foreach ($latest as $article): ?>
      <article class="wpm-card">
        <a href="<?= wpm_esc(wpm_article_url($article['slug'])) ?>">
          <img src="<?= wpm_esc(wpm_image_url($article['featured_image'])) ?>" alt="<?= wpm_esc($article['title']) ?>">
        </a>
        <div>
          <?php if ($article['category_name']): ?><span class="wpm-badge"><?= wpm_esc($article['category_name']) ?></span><?php endif; ?>
          <h3 class="wpm-card__title"><a href="<?= wpm_esc(wpm_article_url($article['slug'])) ?>"><?= wpm_esc($article['title']) ?></a></h3>
          <p class="wpm-card__excerpt"><?= wpm_esc($article['excerpt']) ?></p>
          <div class="wpm-card__meta"><?= wpm_esc(wpm_time_ago($article['published_at'])) ?></div>
        </div>
      </article>
      <?php endforeach; ?>
      <?php if (!$latest && !$hero): ?>
        <p>Belum ada artikel yang dipublikasikan.</p>
      <?php endif; ?>
    </div>

  </div>

  <aside class="wpm-main__sidebar">
    <?= wpm_render_ad_slot($pdo, 'sidebar-left', 'homepage') ?>
    <div class="wpm-sidebar-box">
      <h2 class="wpm-section-title">Terpopuler</h2>
      <?php foreach ($popular as $i => $p): ?>
      <div class="wpm-popular-item">
        <span class="wpm-popular-item__rank"><?= $i + 1 ?></span>
        <span class="wpm-popular-item__title"><a href="<?= wpm_esc(wpm_article_url($p['slug'])) ?>"><?= wpm_esc($p['title']) ?></a></span>
      </div>
      <?php endforeach; ?>
      <?php if (!$popular): ?><p>Belum ada data.</p><?php endif; ?>
    </div>
    <?= wpm_render_ad_slot($pdo, 'sidebar-right', 'homepage') ?>
  </aside>
</main>
<?php require __DIR__ . '/includes/site-footer.php'; ?>
