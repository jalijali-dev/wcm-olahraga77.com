<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';

// Site-wide configuration is admin-tier — see cms_require_role() in
// functions.php for the full tier breakdown.
cms_require_role(['superadmin', 'admin']);

cms_ensure_table(
    $pdo,
    'ad_positions',
    'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
     name VARCHAR(100) NOT NULL,
     slug VARCHAR(120) NOT NULL,
     created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
     UNIQUE KEY uniq_ad_position_slug (slug)'
);

$pageTitle = 'Ad Positions';
$currentNav = 'ad-positions';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'Advertisements', 'href' => cms_nav_href('ads.php')],
    ['label' => 'Ad Positions', 'href' => ''],
];

$selfUrl = 'ad-positions.php';

$ap_redirect = static function (string $message, string $type = 'success') use ($selfUrl): void {
    $_SESSION['cms_flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . $selfUrl, true, 302);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete') {
        $deleteId = (int) ($_POST['id'] ?? 0);
        if ($deleteId <= 0) {
            $ap_redirect('Invalid position.', 'error');
        }
        $pdo->prepare('UPDATE advertisements SET position_id = NULL WHERE position_id = :id')->execute(['id' => $deleteId]);
        $delete = $pdo->prepare('DELETE FROM ad_positions WHERE id = :id');
        $delete->execute(['id' => $deleteId]);
        $ap_redirect($delete->rowCount() > 0 ? 'Position deleted.' : 'Position not found.', $delete->rowCount() > 0 ? 'success' : 'error');
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') {
        $ap_redirect('Name is required.', 'error');
    }
    $slug = cms_slugify($name);

    if ($action === 'create') {
        $dup = $pdo->prepare('SELECT COUNT(*) FROM ad_positions WHERE slug = :slug');
        $dup->execute(['slug' => $slug]);
        if ((int) $dup->fetchColumn() > 0) {
            $ap_redirect('A position with that name already exists.', 'error');
        }
        $pdo->prepare('INSERT INTO ad_positions (name, slug) VALUES (:name, :slug)')->execute(['name' => $name, 'slug' => $slug]);
        $ap_redirect('Position created.');
    }

    if ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            $ap_redirect('Invalid position.', 'error');
        }
        $dup = $pdo->prepare('SELECT COUNT(*) FROM ad_positions WHERE slug = :slug AND id != :id');
        $dup->execute(['slug' => $slug, 'id' => $id]);
        if ((int) $dup->fetchColumn() > 0) {
            $ap_redirect('A position with that name already exists.', 'error');
        }
        $pdo->prepare('UPDATE ad_positions SET name = :name, slug = :slug WHERE id = :id')->execute(['name' => $name, 'slug' => $slug, 'id' => $id]);
        $ap_redirect('Position updated.');
    }

    $ap_redirect('Unknown action.', 'error');
}

$alerts = [];
if (isset($_SESSION['cms_flash']) && is_array($_SESSION['cms_flash'])) {
    $alerts[] = $_SESSION['cms_flash'];
    unset($_SESSION['cms_flash']);
}

$positions = $pdo->query(
    'SELECT p.id, p.name, p.slug, COUNT(a.id) AS ad_count
     FROM ad_positions p
     LEFT JOIN advertisements a ON a.position_id = p.id
     GROUP BY p.id, p.name, p.slug
     ORDER BY p.name ASC'
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
            <h2 class="section-title">Ad Positions</h2>
            <p class="section-lead">Manage where ads can be placed across the site (Header, Sidebar, Between article cards, etc).</p>
        </div>
        <div class="toolbar__right">
            <a class="admin-btn admin-btn--secondary" href="ads.php">← Back to Ads</a>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Add position</h3>
        </div>
        <form class="form-stack form-stack--row" method="post" action="<?= cms_esc($selfUrl) ?>">
            <?= cms_csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <label class="field">Name
                <input type="text" name="name" required placeholder="e.g. Sticky Bottom Mobile">
            </label>
            <div class="field field--actions">
                <button type="submit" class="admin-btn admin-btn--primary">Add</button>
            </div>
        </form>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">All positions</h3>
            <span class="panel__meta"><?= count($positions) ?> positions</span>
        </div>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr><th>Name</th><th>Slug</th><th>Ads</th><th></th></tr>
                </thead>
                <tbody>
                    <?php if ($positions === []) : ?>
                        <tr><td colspan="4" class="muted">No positions yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($positions as $pos) : ?>
                        <tr>
                            <td>
                                <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>" style="display:flex;gap:6px;">
                                    <?= cms_csrf_field() ?>
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="id" value="<?= (int) $pos['id'] ?>">
                                    <input type="text" name="name" value="<?= cms_esc((string) $pos['name']) ?>" style="max-width:220px;">
                                    <button type="submit" class="admin-btn admin-btn--sm admin-btn--secondary">Save</button>
                                </form>
                            </td>
                            <td><code><?= cms_esc((string) $pos['slug']) ?></code></td>
                            <td><?= (int) $pos['ad_count'] ?></td>
                            <td class="table-actions">
                                <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>" onsubmit="return confirm('Delete this position? Ads using it keep working but show as unassigned.');">
                                    <?= cms_csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $pos['id'] ?>">
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
