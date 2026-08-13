<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/site-bootstrap.php';

$featured = wpm_get_articles($pdo, 1, 0);
$hero = $featured[0] ?? null;
$latest = wpm_get_articles($pdo, 10, $hero ? 1 : 0);
$popular = wpm_get_popular_articles($pdo, 5);

// Re-use already loaded homepage data for visual modules; no extra DB query needed.
$headlinePool = [];
if ($hero) {
    $headlinePool[] = $hero;
}
foreach ($latest as $article) {
    $headlinePool[] = $article;
}
$quickHeadlines = array_slice($latest, 0, 3);
$sidebarUpdates = array_slice($latest, 3, 4);

$pageTitle = WPM_SITE_NAME . ' — ' . WPM_SITE_TAGLINE;
$metaDescription = 'Berita, hasil, dan kabar transfer sepak bola Indonesia terkini: Liga 1, Timnas Indonesia, dan bursa transfer.';
$activeNavSlug = 'home';
require __DIR__ . '/includes/site-header.php';
?>

<?php if ($headlinePool): ?>
<section class="wpm-breaking" aria-label="Berita terbaru">
  <div class="wpm-container wpm-breaking__inner">
    <div class="wpm-breaking__label"><span class="wpm-breaking__dot"></span> Breaking</div>
    <div class="wpm-breaking__viewport">
      <div class="wpm-breaking__track">
        <?php foreach (array_slice($headlinePool, 0, 6) as $item): ?>
          <a href="<?= wpm_esc(wpm_article_url($item['slug'])) ?>" class="wpm-breaking__item">
            <?= wpm_esc($item['title']) ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>

<main class="wpm-main">
  <div class="wpm-main__primary">

    <?php if ($hero): ?>
    <a href="<?= wpm_esc(wpm_article_url($hero['slug'])) ?>" class="wpm-hero">
      <img src="<?= wpm_esc(wpm_image_url($hero['featured_image'])) ?>" alt="<?= wpm_esc($hero['title']) ?>">
      <div class="wpm-hero__overlay">
        <div class="wpm-hero__eyebrow">
          <span class="wpm-headline-pill">Headline</span>
          <?php if ($hero['category_name']): ?><span class="wpm-badge"><?= wpm_esc($hero['category_name']) ?></span><?php endif; ?>
        </div>
        <h1 class="wpm-hero__title"><?= wpm_esc($hero['title']) ?></h1>
        <span class="wpm-hero__meta"><span aria-hidden="true">◷</span> <?= wpm_esc(wpm_time_ago($hero['published_at'])) ?></span>
      </div>
    </a>
    <?php endif; ?>

    <?php if ($quickHeadlines): ?>
    <section class="wpm-quick-grid" aria-label="Headline pilihan">
      <?php foreach ($quickHeadlines as $article): ?>
      <a href="<?= wpm_esc(wpm_article_url($article['slug'])) ?>" class="wpm-quick-card">
        <div class="wpm-quick-card__media">
          <img src="<?= wpm_esc(wpm_image_url($article['featured_image'])) ?>" alt="<?= wpm_esc($article['title']) ?>">
        </div>
        <div class="wpm-quick-card__body">
          <?php if ($article['category_name']): ?><span class="wpm-quick-card__category"><?= wpm_esc($article['category_name']) ?></span><?php endif; ?>
          <h2><?= wpm_esc($article['title']) ?></h2>
          <span class="wpm-quick-card__meta"><?= wpm_esc(wpm_time_ago($article['published_at'])) ?></span>
        </div>
      </a>
      <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <div class="wpm-section-heading">
      <h2 class="wpm-section-title">Berita Terkini</h2>
      <?php if ($latest): ?><span class="wpm-section-heading__hint">Update terbaru Olahraga77</span><?php endif; ?>
    </div>

    <div class="wpm-card-list">
      <?php foreach ($latest as $article): ?>
      <article class="wpm-card">
        <a class="wpm-card__media" href="<?= wpm_esc(wpm_article_url($article['slug'])) ?>">
          <img src="<?= wpm_esc(wpm_image_url($article['featured_image'])) ?>" alt="<?= wpm_esc($article['title']) ?>">
        </a>
        <div class="wpm-card__body">
          <div class="wpm-card__topline">
            <?php if ($article['category_name']): ?><span class="wpm-badge"><?= wpm_esc($article['category_name']) ?></span><?php endif; ?>
            <span class="wpm-card__meta"><span aria-hidden="true">◷</span> <?= wpm_esc(wpm_time_ago($article['published_at'])) ?></span>
          </div>
          <h3 class="wpm-card__title"><a href="<?= wpm_esc(wpm_article_url($article['slug'])) ?>"><?= wpm_esc($article['title']) ?></a></h3>
          <?php if (!empty($article['excerpt'])): ?><p class="wpm-card__excerpt"><?= wpm_esc($article['excerpt']) ?></p><?php endif; ?>
          <a class="wpm-card__read" href="<?= wpm_esc(wpm_article_url($article['slug'])) ?>">Baca selengkapnya <span aria-hidden="true">→</span></a>
        </div>
      </article>
      <?php endforeach; ?>
      <?php if (!$latest && !$hero): ?>
        <div class="wpm-empty-state">
          <span class="wpm-empty-state__icon" aria-hidden="true">⚽</span>
          <h2>Belum ada artikel</h2>
          <p>Artikel yang dipublikasikan akan tampil di halaman ini.</p>
        </div>
      <?php endif; ?>
    </div>

  </div>

  <aside class="wpm-main__sidebar">
    <?= wpm_render_ad_slot($pdo, 'sidebar-left', 'homepage') ?>

    <div class="wpm-sidebar-box wpm-sidebar-box--popular">
      <div class="wpm-sidebar-box__heading">
        <h2 class="wpm-section-title">Terpopuler</h2>
        <span class="wpm-sidebar-box__mini">Paling dibaca</span>
      </div>
      <?php foreach ($popular as $i => $p): ?>
      <div class="wpm-popular-item">
        <span class="wpm-popular-item__rank"><?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
        <span class="wpm-popular-item__title"><a href="<?= wpm_esc(wpm_article_url($p['slug'])) ?>"><?= wpm_esc($p['title']) ?></a></span>
      </div>
      <?php endforeach; ?>
      <?php if (!$popular): ?><p class="wpm-sidebar-empty">Belum ada data.</p><?php endif; ?>
    </div>

    <?= wpm_render_ad_slot($pdo, 'sidebar-right', 'homepage') ?>

    <?php if ($sidebarUpdates): ?>
    <div class="wpm-sidebar-box wpm-sidebar-updates">
      <div class="wpm-sidebar-box__heading">
        <h2 class="wpm-section-title">Update Terbaru</h2>
      </div>
      <?php foreach ($sidebarUpdates as $article): ?>
      <a class="wpm-sidebar-update" href="<?= wpm_esc(wpm_article_url($article['slug'])) ?>">
        <img src="<?= wpm_esc(wpm_image_url($article['featured_image'])) ?>" alt="<?= wpm_esc($article['title']) ?>">
        <span>
          <strong><?= wpm_esc($article['title']) ?></strong>
          <small><?= wpm_esc(wpm_time_ago($article['published_at'])) ?></small>
        </span>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </aside>
</main>
<?php require __DIR__ . '/includes/site-footer.php'; ?>
