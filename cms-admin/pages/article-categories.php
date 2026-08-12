<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';
require_once dirname(__DIR__) . '/includes/sitemap-service.php';

cms_sitemap_ensure_schema($pdo);

cms_ensure_table(
    $pdo,
    'article_categories',
    'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
     name VARCHAR(100) NOT NULL,
     slug VARCHAR(120) NOT NULL,
     created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
     updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
     UNIQUE KEY uniq_article_category_slug (slug)'
);

$pageTitle = 'Article Categories';
$currentNav = 'article-categories';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'Pages & Articles', 'href' => cms_nav_href('pages.php')],
    ['label' => 'Article Categories', 'href' => ''],
];

$selfUrl = 'article-categories.php';

$ac_redirect = static function (string $message, string $type = 'success') use ($selfUrl): void {
    $_SESSION['cms_flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . $selfUrl, true, 302);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete') {
        $deleteId = (int) ($_POST['id'] ?? 0);
        if ($deleteId <= 0) {
            $ac_redirect('Invalid category.', 'error');
        }
        // Detach from any articles first — do not delete articles themselves.
        $pdo->prepare('UPDATE pages SET category_id = NULL WHERE category_id = :id')->execute(['id' => $deleteId]);
        $delete = $pdo->prepare('DELETE FROM article_categories WHERE id = :id');
        $delete->execute(['id' => $deleteId]);
        if ($delete->rowCount() > 0) {
            cms_sitemap_on_category_delete($pdo, $deleteId);
        }
        $ac_redirect($delete->rowCount() > 0 ? 'Category deleted.' : 'Category not found.', $delete->rowCount() > 0 ? 'success' : 'error');
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') {
        $ac_redirect('Name is required.', 'error');
    }
    $slug = cms_slugify($name);

    if ($action === 'create') {
        $dup = $pdo->prepare('SELECT COUNT(*) FROM article_categories WHERE slug = :slug');
        $dup->execute(['slug' => $slug]);
        if ((int) $dup->fetchColumn() > 0) {
            $ac_redirect('A category with that name already exists.', 'error');
        }
        $pdo->prepare('INSERT INTO article_categories (name, slug) VALUES (:name, :slug)')
            ->execute(['name' => $name, 'slug' => $slug]);
        cms_sitemap_on_category_save($pdo, (int) $pdo->lastInsertId(), $name, $slug);
        $ac_redirect('Category created.');
    }

    if ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            $ac_redirect('Invalid category.', 'error');
        }
        $dup = $pdo->prepare('SELECT COUNT(*) FROM article_categories WHERE slug = :slug AND id != :id');
        $dup->execute(['slug' => $slug, 'id' => $id]);
        if ((int) $dup->fetchColumn() > 0) {
            $ac_redirect('A category with that name already exists.', 'error');
        }
        $pdo->prepare('UPDATE article_categories SET name = :name, slug = :slug WHERE id = :id')
            ->execute(['name' => $name, 'slug' => $slug, 'id' => $id]);
        cms_sitemap_on_category_save($pdo, $id, $name, $slug);
        $ac_redirect('Category updated.');
    }

    $ac_redirect('Unknown action.', 'error');
}

$alerts = [];
if (isset($_SESSION['cms_flash']) && is_array($_SESSION['cms_flash'])) {
    $alerts[] = $_SESSION['cms_flash'];
    unset($_SESSION['cms_flash']);
}

$categories = $pdo->query(
    'SELECT c.id, c.name, c.slug, COUNT(p.page_id) AS article_count
     FROM article_categories c
     LEFT JOIN pages p ON p.category_id = c.id
     GROUP BY c.id, c.name, c.slug
     ORDER BY c.name ASC'
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
            <h2 class="section-title">Article Categories</h2>
            <p class="section-lead">Manage the categories articles can be filed under.</p>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Add category</h3>
        </div>
        <form class="form-stack form-stack--row" method="post" action="<?= cms_esc($selfUrl) ?>">
            <?= cms_csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <label class="field">Name
                <input type="text" name="name" required placeholder="e.g. Bitcoin">
            </label>
            <div class="field field--actions">
                <button type="submit" class="admin-btn admin-btn--primary">Add</button>
            </div>
        </form>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">All categories</h3>
            <span class="panel__meta"><?= count($categories) ?> categories</span>
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
                    <?php if ($categories === []) : ?>
                        <tr><td colspan="4" class="muted">No categories yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($categories as $cat) : ?>
                        <tr>
                            <td>
                                <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>" style="display:flex;gap:6px;">
                                    <?= cms_csrf_field() ?>
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="id" value="<?= (int) $cat['id'] ?>">
                                    <input type="text" name="name" value="<?= cms_esc((string) $cat['name']) ?>" style="max-width:220px;">
                                    <button type="submit" class="admin-btn admin-btn--sm admin-btn--secondary">Save</button>
                                </form>
                            </td>
                            <td><code><?= cms_esc((string) $cat['slug']) ?></code></td>
                            <td><?= (int) $cat['article_count'] ?></td>
                            <td class="table-actions">
                                <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>" onsubmit="return confirm('Delete this category? Articles using it will be set to no category.');">
                                    <?= cms_csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $cat['id'] ?>">
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
