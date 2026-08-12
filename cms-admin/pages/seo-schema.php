<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';

// Site-wide configuration is admin-tier — see cms_require_role() in
// functions.php for the full tier breakdown.
cms_require_role(['superadmin', 'admin']);

$pageTitle = 'SEO Schema';
$currentNav = 'seo-schema';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'SEO Schema', 'href' => ''],
];

$selfUrl = 'seo-schema.php';

$ss_redirect = static function (string $message, string $type = 'success', ?string $query = null) use ($selfUrl): void {
    $_SESSION['cms_flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . $selfUrl . ($query ? '?' . $query : ''), true, 302);
    exit;
};

$ss_validate = static function (string $schemaName, string $schemaType, string $schemaJson): ?string {
    if ($schemaName === '') {
        return 'Schema name is required.';
    }
    if ($schemaType === '') {
        return 'Schema type is required.';
    }
    if ($schemaJson === '') {
        return 'Schema JSON is required.';
    }
    json_decode($schemaJson);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return 'Schema JSON must be valid JSON.';
    }

    return null;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete') {
        $deleteId = (int) ($_POST['id'] ?? 0);
        if ($deleteId <= 0) {
            $ss_redirect('Invalid schema.', 'error');
        }
        $delete = $pdo->prepare('DELETE FROM seo_schema WHERE id = :id');
        $delete->execute(['id' => $deleteId]);
        if ($delete->rowCount() < 1) {
            $ss_redirect('Schema not found or already deleted.', 'error');
        }
        $ss_redirect('Schema deleted successfully.');
    }

    $schemaName = trim((string) ($_POST['schema_name'] ?? ''));
    $schemaType = trim((string) ($_POST['schema_type'] ?? ''));
    $schemaJson = trim((string) ($_POST['schema_json'] ?? ''));
    $isActive = (int) ($_POST['is_active'] ?? 0) === 1 ? 1 : 0;

    $validationError = $ss_validate($schemaName, $schemaType, $schemaJson);
    if ($validationError !== null) {
        $errorQuery = ($action === 'update' && (int) ($_POST['id'] ?? 0) > 0)
            ? 'edit=' . (int) $_POST['id'] : null;
        $ss_redirect($validationError, 'error', $errorQuery);
    }

    $payload = [
        'schema_name' => $schemaName,
        'schema_type' => $schemaType,
        'schema_json' => $schemaJson,
        'is_active' => $isActive,
    ];

    if ($action === 'create') {
        $insert = $pdo->prepare(
            'INSERT INTO seo_schema (schema_name, schema_type, schema_json, is_active, created_at, updated_at)
             VALUES (:schema_name, :schema_type, :schema_json, :is_active, NOW(), NOW())'
        );
        $insert->execute($payload);
        $newId = (int) $pdo->lastInsertId();
        $ss_redirect('Schema created successfully.', 'success', 'edit=' . $newId);
    }

    if ($action === 'update') {
        $updateId = (int) ($_POST['id'] ?? 0);
        if ($updateId <= 0) {
            $ss_redirect('Invalid schema.', 'error');
        }
        $update = $pdo->prepare(
            'UPDATE seo_schema
             SET schema_name = :schema_name, schema_type = :schema_type, schema_json = :schema_json,
                 is_active = :is_active, updated_at = NOW()
             WHERE id = :id'
        );
        $update->execute($payload + ['id' => $updateId]);
        $ss_redirect('Schema updated successfully.', 'success', 'edit=' . $updateId);
    }

    $ss_redirect('Unknown action.', 'error');
}

$alerts = [];
if (isset($_SESSION['cms_flash']) && is_array($_SESSION['cms_flash'])) {
    $alerts[] = $_SESSION['cms_flash'];
    unset($_SESSION['cms_flash']);
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = null;

$schemas = $pdo->query(
    'SELECT id, schema_name, schema_type, is_active, updated_at
     FROM seo_schema
     ORDER BY id DESC'
)->fetchAll();

if ($editId > 0) {
    $editStmt = $pdo->prepare(
        'SELECT id, schema_name, schema_type, schema_json, is_active
         FROM seo_schema WHERE id = :id LIMIT 1'
    );
    $editStmt->execute(['id' => $editId]);
    $editRow = $editStmt->fetch() ?: null;
    if ($editRow === null) {
        $alerts[] = ['type' => 'error', 'message' => 'Schema not found.'];
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
    <div class="admin-notice admin-notice--info">
        <strong>Catatan:</strong> Schema records di halaman ini belum dirender otomatis ke frontend. Saat ini frontend memakai schema bawaan dari halaman publik, seperti Bakery schema di homepage dan Article/FAQ schema di detail artikel.
    </div>
    <div class="toolbar">
        <div class="toolbar__left">
            <h2 class="section-title">SEO schema</h2>
            <p class="section-lead">JSON-LD templates per page type.</p>
        </div>
        <div class="toolbar__right">
            <a class="admin-btn admin-btn--primary" href="<?= cms_esc($editRow ? $selfUrl : $selfUrl . '#schema-form') ?>">Add schema</a>
        </div>
    </div>

    <div class="admin-grid admin-grid--2">
        <div class="panel">
            <div class="panel__head">
                <h3 class="panel__title">Schema records</h3>
                <span class="panel__meta"><?= count($schemas) ?> record(s)</span>
            </div>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Active</th>
                            <th>Updated</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($schemas === []) : ?>
                            <tr><td colspan="5" class="muted">No schema records yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($schemas as $row) : ?>
                            <?php $rowId = (int) $row['id']; ?>
                            <tr>
                                <td><?= cms_esc($val($row, 'schema_name')) ?></td>
                                <td><?= cms_esc($val($row, 'schema_type')) ?></td>
                                <td>
                                    <span class="pill pill--<?= (int) ($row['is_active'] ?? 0) === 1 ? 'ok' : 'muted' ?>">
                                        <?= (int) ($row['is_active'] ?? 0) === 1 ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                                <td><?= cms_esc($val($row, 'updated_at')) ?></td>
                                <td class="table-actions">
                                    <a class="admin-btn admin-btn--sm admin-btn--secondary" href="<?= cms_esc($selfUrl) ?>?edit=<?= $rowId ?>">Edit</a>
                                    <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>" onsubmit="return confirm('Delete this schema?');">
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

        <div class="panel" id="schema-form">
            <div class="panel__head">
                <h3 class="panel__title"><?= $editRow ? 'Edit schema' : 'New schema' ?></h3>
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
                <label class="field">Schema name
                    <input type="text" name="schema_name" value="<?= cms_esc($editRow ? $val($editRow, 'schema_name') : '') ?>" required>
                </label>
                <label class="field">Schema type
                    <input type="text" name="schema_type" value="<?= cms_esc($editRow ? $val($editRow, 'schema_type') : '') ?>" required placeholder="e.g. LocalBusiness">
                </label>
                <label class="field">Schema JSON
                    <textarea name="schema_json" rows="12" required placeholder='{"@context":"https://schema.org",...}'><?= cms_esc($editRow ? $val($editRow, 'schema_json') : '') ?></textarea>
                </label>
                <label class="field">Active
                    <select name="is_active" required>
                        <option value="1"<?= !$editRow || (int) ($editRow['is_active'] ?? 0) === 1 ? ' selected' : '' ?>>Active</option>
                        <option value="0"<?= $editRow && (int) ($editRow['is_active'] ?? 0) === 0 ? ' selected' : '' ?>>Inactive</option>
                    </select>
                </label>
                <button type="submit" class="admin-btn admin-btn--primary"><?= $editRow ? 'Save changes' : 'Create schema' ?></button>
            </form>
        </div>
    </div>
</section>
<?php
require dirname(__DIR__) . '/includes/footer.php';
