<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';
require_once dirname(__DIR__) . '/includes/sitemap-service.php';

cms_sitemap_ensure_schema($pdo);

cms_ensure_table(
    $pdo,
    'article_tags',
    'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
     name VARCHAR(100) NOT NULL,
     slug VARCHAR(120) NOT NULL,
     created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
     UNIQUE KEY uniq_article_tag_slug (slug)'
);
cms_ensure_table(
    $pdo,
    'article_tag_map',
    'page_id INT NOT NULL,
     tag_id INT UNSIGNED NOT NULL,
     PRIMARY KEY (page_id, tag_id),
     KEY idx_article_tag_map_tag (tag_id)'
);

$pageTitle = 'Article Tags';
$currentNav = 'article-tags';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'Pages & Articles', 'href' => cms_nav_href('pages.php')],
    ['label' => 'Article Tags', 'href' => ''],
];

$selfUrl = 'article-tags.php';

$at_redirect = static function (string $message, string $type = 'success') use ($selfUrl): void {
    $_SESSION['cms_flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . $selfUrl, true, 302);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete') {
        $deleteId = (int) ($_POST['id'] ?? 0);
        if ($deleteId <= 0) {
            $at_redirect('Invalid tag.', 'error');
        }
        $pdo->prepare('DELETE FROM article_tag_map WHERE tag_id = :id')->execute(['id' => $deleteId]);
        $delete = $pdo->prepare('DELETE FROM article_tags WHERE id = :id');
        $delete->execute(['id' => $deleteId]);
        if ($delete->rowCount() > 0) {
            cms_sitemap_on_tag_delete($pdo, $deleteId);
        }
        $at_redirect($delete->rowCount() > 0 ? 'Tag deleted.' : 'Tag not found.', $delete->rowCount() > 0 ? 'success' : 'error');
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') {
        $at_redirect('Name is required.', 'error');
    }
    $slug = cms_slugify($name);

    if ($action === 'create') {
        $dup = $pdo->prepare('SELECT COUNT(*) FROM article_tags WHERE slug = :slug');
        $dup->execute(['slug' => $slug]);
        if ((int) $dup->fetchColumn() > 0) {
            $at_redirect('A tag with that name already exists.', 'error');
        }
        $pdo->prepare('INSERT INTO article_tags (name, slug) VALUES (:name, :slug)')
            ->execute(['name' => $name, 'slug' => $slug]);
        cms_sitemap_on_tag_save($pdo, (int) $pdo->lastInsertId(), $name, $slug);
        $at_redirect('Tag created.');
    }

    if ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            $at_redirect('Invalid tag.', 'error');
        }
        $dup = $pdo->prepare('SELECT COUNT(*) FROM article_tags WHERE slug = :slug AND id != :id');
        $dup->execute(['slug' => $slug, 'id' => $id]);
        if ((int) $dup->fetchColumn() > 0) {
            $at_redirect('A tag with that name already exists.', 'error');
        }
        $pdo->prepare('UPDATE article_tags SET name = :name, slug = :slug WHERE id = :id')
            ->execute(['name' => $name, 'slug' => $slug, 'id' => $id]);
        cms_sitemap_on_tag_save($pdo, $id, $name, $slug);
        $at_redirect('Tag updated.');
    }

    $at_redirect('Unknown action.', 'error');
}

$alerts = [];
if (isset($_SESSION['cms_flash']) && is_array($_SESSION['cms_flash'])) {
    $alerts[] = $_SESSION['cms_flash'];
    unset($_SESSION['cms_flash']);
}

$tags = $pdo->query(
    'SELECT t.id, t.name, t.slug, COUNT(m.page_id) AS article_count
     FROM article_tags t
     LEFT JOIN article_tag_map m ON m.tag_id = t.id
     GROUP BY t.id, t.name, t.slug
     ORDER BY t.name ASC'
)->fetchAll();

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/sidebar.php';
require dirname(__DIR__) . '/includes/navbar.php';
require dirname(__DIR__) . '/includes/breadcrumb.php';
require dirname(__DIR__) . '/includes/alerts.php';
?>
<section class="admin-stack">
    <div class="toolbar">
        <div class="toolbar__left">
            <h2 class="section-title">Article Tags</h2>
            <p class="section-lead">Manage tags used to label articles. Tags are also created automatically when typed into an article's Tags field.</p>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Add tag</h3>
        </div>
        <form class="form-stack form-stack--row" method="post" action="<?= cms_esc($selfUrl) ?>">
            <?= cms_csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <label class="field">Name
                <input type="text" name="name" required placeholder="e.g. Regulasi">
            </label>
            <div class="field field--actions">
                <button type="submit" class="admin-btn admin-btn--primary">Add</button>
            </div>
        </form>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">All tags</h3>
            <span class="panel__meta"><?= count($tags) ?> tags</span>
        </div>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Slug</th>
                        <th>Articles</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($tags === []) : ?>
                        <tr><td colspan="4" class="muted">No tags yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($tags as $tag) : ?>
                        <tr>
                            <td>
                                <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>" style="display:flex;gap:6px;">
                                    <?= cms_csrf_field() ?>
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="id" value="<?= (int) $tag['id'] ?>">
                                    <input type="text" name="name" value="<?= cms_esc((string) $tag['name']) ?>" style="max-width:220px;">
                                    <button type="submit" class="admin-btn admin-btn--sm admin-btn--secondary">Save</button>
                                </form>
                            </td>
                            <td><code><?= cms_esc((string) $tag['slug']) ?></code></td>
                            <td><?= (int) $tag['article_count'] ?></td>
                            <td class="table-actions">
                                <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>" onsubmit="return confirm('Delete this tag?');">
                                    <?= cms_csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $tag['id'] ?>">
                                    <button type="submit" class="admin-btn admin-btn--sm admin-btn--danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
