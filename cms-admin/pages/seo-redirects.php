<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';
require_once dirname(__DIR__) . '/includes/sitemap-service.php';

// Site-wide configuration is admin-tier — see cms_require_role() in
// functions.php for the full tier breakdown.
cms_require_role(['superadmin', 'admin']);

cms_sitemap_ensure_schema($pdo);

$pageTitle = 'SEO Redirects';
$currentNav = 'seo-redirects';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'SEO Redirects', 'href' => ''],
];

$selfUrl = 'seo-redirects.php';

$sr_redirect = static function (string $message, string $type = 'success', ?string $query = null) use ($selfUrl): void {
    $_SESSION['cms_flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . $selfUrl . ($query ? '?' . $query : ''), true, 302);
    exit;
};

$sr_validate = static function (string $oldUrl, string $newUrl): ?string {
    if ($oldUrl === '') {
        return 'Old URL is required.';
    }
    if ($newUrl === '') {
        return 'New URL is required.';
    }

    return null;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete') {
        $deleteId = (int) ($_POST['id'] ?? 0);
        if ($deleteId <= 0) {
            $sr_redirect('Invalid redirect.', 'error');
        }
        $delete = $pdo->prepare('DELETE FROM seo_redirects WHERE id = :id');
        $delete->execute(['id' => $deleteId]);
        if ($delete->rowCount() < 1) {
            $sr_redirect('Redirect not found or already deleted.', 'error');
        }
        $sr_redirect('Redirect deleted successfully.');
    }

    $oldUrl = trim((string) ($_POST['old_url'] ?? ''));
    $newUrl = trim((string) ($_POST['new_url'] ?? ''));
    $redirectType = trim((string) ($_POST['redirect_type'] ?? '301'));
    $isActive = (int) ($_POST['is_active'] ?? 0) === 1 ? 1 : 0;

    $validationError = $sr_validate($oldUrl, $newUrl);
    if ($validationError !== null) {
        $errorQuery = ($action === 'update' && (int) ($_POST['id'] ?? 0) > 0)
            ? 'edit=' . (int) $_POST['id'] : null;
        $sr_redirect($validationError, 'error', $errorQuery);
    }

    $payload = [
        'old_url' => $oldUrl,
        'new_url' => $newUrl,
        'redirect_type' => $redirectType,
        'is_active' => $isActive,
    ];

    if ($action === 'create') {
        $insert = $pdo->prepare(
            'INSERT INTO seo_redirects (old_url, new_url, redirect_type, is_active, created_at, updated_at)
             VALUES (:old_url, :new_url, :redirect_type, :is_active, NOW(), NOW())'
        );
        $insert->execute($payload);
        $newId = (int) $pdo->lastInsertId();
        cms_sitemap_on_redirect_save($pdo, $oldUrl, $newUrl, true);
        $sr_redirect('Redirect created successfully.', 'success', 'edit=' . $newId);
    }

    if ($action === 'update') {
        $updateId = (int) ($_POST['id'] ?? 0);
        if ($updateId <= 0) {
            $sr_redirect('Invalid redirect.', 'error');
        }
        $update = $pdo->prepare(
            'UPDATE seo_redirects
             SET old_url = :old_url, new_url = :new_url, redirect_type = :redirect_type,
                 is_active = :is_active, updated_at = NOW()
             WHERE id = :id'
        );
        $update->execute($payload + ['id' => $updateId]);
        cms_sitemap_on_redirect_save($pdo, $oldUrl, $newUrl, false);
        $sr_redirect('Redirect updated successfully.', 'success', 'edit=' . $updateId);
    }

    $sr_redirect('Unknown action.', 'error');
}

$alerts = [];
if (isset($_SESSION['cms_flash']) && is_array($_SESSION['cms_flash'])) {
    $alerts[] = $_SESSION['cms_flash'];
    unset($_SESSION['cms_flash']);
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = null;

$redirects = $pdo->query(
    'SELECT id, old_url, new_url, redirect_type, is_active, updated_at
     FROM seo_redirects
     ORDER BY id DESC'
)->fetchAll();

if ($editId > 0) {
    $editStmt = $pdo->prepare(
        'SELECT id, old_url, new_url, redirect_type, is_active
         FROM seo_redirects WHERE id = :id LIMIT 1'
    );
    $editStmt->execute(['id' => $editId]);
    $editRow = $editStmt->fetch() ?: null;
    if ($editRow === null) {
        $alerts[] = ['type' => 'error', 'message' => 'Redirect not found.'];
        $editId = 0;
    }
}

$val = static fn (array $row, string $key): string => (string) ($row[$key] ?? '');

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/sidebar.php';
require dirname(__DIR__) . '/includes/navbar.php';
require dirname(__DIR__) . '/includes/breadcrumb.php';
require dirname(__DIR__) . '/includes/alerts.php';
?>
<section class="admin-stack">
    <div class="toolbar">
        <div class="toolbar__left">
            <h2 class="section-title">SEO redirects</h2>
            <p class="section-lead">301/302 rules for legacy URLs and campaign paths.</p>
        </div>
        <div class="toolbar__right">
            <a class="admin-btn admin-btn--primary" href="<?= cms_esc($editRow ? $selfUrl : $selfUrl . '#redirect-form') ?>">Add redirect</a>
        </div>
    </div>

    <div class="admin-grid admin-grid--2">
        <div class="panel">
            <div class="panel__head">
                <h3 class="panel__title">Redirect rules</h3>
                <span class="panel__meta"><?= count($redirects) ?> rule(s)</span>
            </div>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>From</th>
                            <th>To</th>
                            <th>Type</th>
                            <th>Active</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($redirects === []) : ?>
                            <tr><td colspan="5" class="muted">No redirects yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($redirects as $row) : ?>
                            <?php $rowId = (int) $row['id']; ?>
                            <tr>
                                <td><code class="cell-clip"><?= cms_esc($val($row, 'old_url')) ?></code></td>
                                <td><code class="cell-clip"><?= cms_esc($val($row, 'new_url')) ?></code></td>
                                <td><?= cms_esc($val($row, 'redirect_type')) ?></td>
                                <td>
                                    <span class="pill pill--<?= (int) ($row['is_active'] ?? 0) === 1 ? 'ok' : 'muted' ?>">
                                        <?= (int) ($row['is_active'] ?? 0) === 1 ? 'Yes' : 'No' ?>
                                    </span>
                                </td>
                                <td class="table-actions">
                                    <a class="admin-btn admin-btn--sm admin-btn--secondary" href="<?= cms_esc($selfUrl) ?>?edit=<?= $rowId ?>">Edit</a>
                                    <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>" onsubmit="return confirm('Delete this redirect?');">
                                        <?= cms_csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $rowId ?>">
                                        <button type="submit" class="admin-btn admin-btn--sm admin-btn--danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="panel" id="redirect-form">
            <div class="panel__head">
                <h3 class="panel__title"><?= $editRow ? 'Edit redirect' : 'New redirect' ?></h3>
                <?php if ($editRow) : ?>
                    <a class="panel__link" href="<?= cms_esc($selfUrl) ?>">Cancel edit</a>
                <?php endif; ?>
            </div>
            <form class="form-stack" method="post" action="<?= cms_esc($selfUrl) ?>">
                <?= cms_csrf_field() ?>
                <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'create' ?>">
                <?php if ($editRow) : ?>
                    <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
                <?php endif; ?>
                <label class="field">Old URL
                    <input type="text" name="old_url" value="<?= cms_esc($editRow ? $val($editRow, 'old_url') : '') ?>" required placeholder="/old-url">
                </label>
                <label class="field">New URL
                    <input type="text" name="new_url" value="<?= cms_esc($editRow ? $val($editRow, 'new_url') : '') ?>" required placeholder="/new-url">
                </label>
                <label class="field">Redirect type
                    <select name="redirect_type">
                        <option value="301"<?= !$editRow || $val($editRow, 'redirect_type') === '301' ? ' selected' : '' ?>>301</option>
                        <option value="302"<?= $editRow && $val($editRow, 'redirect_type') === '302' ? ' selected' : '' ?>>302</option>
                    </select>
                </label>
                <label class="field">Active
                    <select name="is_active" required>
                        <option value="1"<?= !$editRow || (int) ($editRow['is_active'] ?? 0) === 1 ? ' selected' : '' ?>>Yes</option>
                        <option value="0"<?= $editRow && (int) ($editRow['is_active'] ?? 0) === 0 ? ' selected' : '' ?>>No</option>
                    </select>
                </label>
                <button type="submit" class="admin-btn admin-btn--primary"><?= $editRow ? 'Save changes' : 'Create redirect' ?></button>
            </form>
        </div>
    </div>
</section>
<?php
require dirname(__DIR__) . '/includes/footer.php';
