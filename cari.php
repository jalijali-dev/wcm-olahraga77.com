<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/site-bootstrap.php';

$q = trim((string) ($_GET['q'] ?? ''));
$results = [];
if ($q !== '') {
    $stmt = $pdo->prepare(
        'SELECT p.page_id, p.title, p.slug, p.excerpt, p.featured_image, p.published_at,
                c.name AS category_name
         FROM pages p
         LEFT JOIN article_categories c ON c.id = p.category_id
         WHERE p.status = "published" AND (p.title LIKE :q OR p.excerpt LIKE :q OR p.meta_keywords LIKE :q)
         ORDER BY p.published_at DESC LIMIT 20'
    );
    $stmt->execute(['q' => '%' . $q . '%']);
    $results = $stmt->fetchAll();
}

$pageTitle = ($q !== '' ? 'Hasil pencarian: ' . $q : 'Cari') . ' — ' . WPM_SITE_NAME;
require __DIR__ . '/includes/site-header.php';
?>
<main class="wpm-main">
  <div class="wpm-main__primary">
    <h1 class="wpm-section-title"><?= $q !== '' ? 'Hasil untuk "' . wpm_esc($q) . '"' : 'Cari Berita' ?></h1>
    <div class="wpm-card-list">
      <?php foreach ($results as $article): ?>
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
      <?php if ($q !== '' && !$results): ?><p>Tidak ada hasil untuk "<?= wpm_esc($q) ?>".</p><?php endif; ?>
    </div>
  </div>
  <aside class="wpm-main__sidebar"></aside>
</main>
<?php require __DIR__ . '/includes/site-footer.php'; ?>
