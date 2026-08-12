<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/site-bootstrap.php';

$navCategories = wpm_site_nav_categories();
$slug = trim((string) ($_GET['slug'] ?? ''));

if ($slug === '' || !isset($navCategories[$slug])) {
    http_response_code(404);
    $pageTitle = 'Kategori tidak ditemukan — ' . WPM_SITE_NAME;
    require __DIR__ . '/includes/site-header.php';
    echo '<main class="wpm-main"><div class="wpm-main__primary"><h1>Kategori tidak ditemukan</h1><p><a href="' . wpm_esc(wpm_base_url('/')) . '">Kembali ke beranda</a></p></div></main>';
    require __DIR__ . '/includes/site-footer.php';
    exit;
}

$label = $navCategories[$slug];
$perPage = 12;
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$articles = wpm_get_articles($pdo, $perPage, $offset, $slug);
$total = wpm_count_articles($pdo, $slug);
$totalPages = (int) max(1, ceil($total / $perPage));
$popular = wpm_get_popular_articles($pdo, 5);

$pageTitle = $label . ' — ' . WPM_SITE_NAME;
$metaDescription = 'Kumpulan berita ' . $label . ' terbaru seputar sepak bola Indonesia di ' . WPM_SITE_NAME . '.';
$activeNavSlug = $slug;
require __DIR__ . '/includes/site-header.php';
?>
<main class="wpm-main">
  <div class="wpm-main__primary">
    <div class="wpm-breadcrumb"><a href="<?= wpm_esc(wpm_base_url('/')) ?>">Beranda</a> / <?= wpm_esc($label) ?></div>
    <h1 class="wpm-section-title"><?= wpm_esc($label) ?></h1>

    <div class="wpm-card-list">
      <?php foreach ($articles as $article): ?>
      <article class="wpm-card">
        <a href="<?= wpm_esc(wpm_article_url($article['slug'])) ?>">
          <img src="<?= wpm_esc(wpm_image_url($article['featured_image'])) ?>" alt="<?= wpm_esc($article['title']) ?>">
        </a>
        <div>
          <h3 class="wpm-card__title"><a href="<?= wpm_esc(wpm_article_url($article['slug'])) ?>"><?= wpm_esc($article['title']) ?></a></h3>
          <p class="wpm-card__excerpt"><?= wpm_esc($article['excerpt']) ?></p>
          <div class="wpm-card__meta"><?= wpm_esc(wpm_time_ago($article['published_at'])) ?></div>
        </div>
      </article>
      <?php endforeach; ?>
      <?php if (!$articles): ?>
        <p>Belum ada artikel di kategori ini.</p>
      <?php endif; ?>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="wpm-pagination">
      <?php for ($p = 1; $p <= $totalPages; $p++): ?>
        <?php if ($p === $page): ?>
          <span class="is-active"><?= $p ?></span>
        <?php else: ?>
          <a href="<?= wpm_esc(wpm_category_url($slug)) ?>?page=<?= $p ?>"><?= $p ?></a>
        <?php endif; ?>
      <?php endfor; ?>
    </div>
    <?php endif; ?>
  </div>

  <aside class="wpm-main__sidebar">
    <div class="wpm-sidebar-box">
      <h2 class="wpm-section-title">Terpopuler</h2>
      <?php foreach ($popular as $i => $p): ?>
      <div class="wpm-popular-item">
        <span class="wpm-popular-item__rank"><?= $i + 1 ?></span>
        <span class="wpm-popular-item__title"><a href="<?= wpm_esc(wpm_article_url($p['slug'])) ?>"><?= wpm_esc($p['title']) ?></a></span>
      </div>
      <?php endforeach; ?>
    </div>
  </aside>
</main>
<?php require __DIR__ . '/includes/site-footer.php'; ?>
