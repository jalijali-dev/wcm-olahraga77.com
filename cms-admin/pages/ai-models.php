<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';

// Site-wide configuration is admin-tier — see cms_require_role() in
// functions.php for the full tier breakdown.
cms_require_role(['superadmin', 'admin']);

$pageTitle = 'AI Models';
$currentNav = 'ai-models';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'AI Models', 'href' => ''],
];

$selfUrl = 'ai-models.php';
$providers = ['openai' => 'OpenAI', 'anthropic' => 'Anthropic'];

$redirect = static function (string $message, string $type = 'success', ?string $query = null) use ($selfUrl): void {
    $_SESSION['cms_flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . $selfUrl . ($query ? '?' . $query : ''), true, 302);
    exit;
};

$validate = static function (string $provider, string $modelKey, string $label) use ($providers): ?string {
    if (!isset($providers[$provider])) {
        return 'Invalid provider.';
    }
    if ($modelKey === '') {
        return 'Model key is required (e.g. claude-3-5-haiku-20241022, gpt-4o-mini).';
    }
    if ($label === '') {
        return 'Label is required.';
    }
    return null;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete') {
        $deleteId = (int) ($_POST['id'] ?? 0);
        if ($deleteId <= 0) {
            $redirect('Invalid model.', 'error');
        }
        $delete = $pdo->prepare('DELETE FROM ai_models WHERE id = :id');
        $delete->execute(['id' => $deleteId]);
        if ($delete->rowCount() < 1) {
            $redirect('Model not found, already deleted, or still referenced by an agent.', 'error');
        }
        $redirect('Model deleted successfully.');
    }

    $provider = (string) ($_POST['provider'] ?? '');
    $modelKey = trim((string) ($_POST['model_key'] ?? ''));
    $label = trim((string) ($_POST['label'] ?? ''));
    $isDefault = isset($_POST['is_default']) && (int) $_POST['is_default'] === 1 ? 1 : 0;
    $isActive = isset($_POST['is_active']) && (int) $_POST['is_active'] === 1 ? 1 : 0;

    $error = $validate($provider, $modelKey, $label);
    if ($error !== null) {
        $redirect($error, 'error');
    }

    if ($action === 'create') {
        try {
            if ($isDefault === 1) {
                $pdo->prepare('UPDATE ai_models SET is_default = 0 WHERE provider = :provider')->execute(['provider' => $provider]);
            }
            $insert = $pdo->prepare(
                'INSERT INTO ai_models (provider, model_key, label, is_default, is_active, created_at, updated_at)
                 VALUES (:provider, :model_key, :label, :is_default, :is_active, NOW(), NOW())'
            );
            $insert->execute([
                'provider' => $provider,
                'model_key' => $modelKey,
                'label' => $label,
                'is_default' => $isDefault,
                'is_active' => $isActive,
            ]);
        } catch (\PDOException $e) {
            $redirect('Could not save model — it may already exist for this provider.', 'error');
        }
        $redirect('Model saved successfully.');
    }

    if ($action === 'update') {
        $updateId = (int) ($_POST['id'] ?? 0);
        if ($updateId <= 0) {
            $redirect('Invalid model.', 'error');
        }
        try {
            if ($isDefault === 1) {
                $pdo->prepare('UPDATE ai_models SET is_default = 0 WHERE provider = :provider')->execute(['provider' => $provider]);
            }
            $update = $pdo->prepare(
                'UPDATE ai_models
                 SET provider = :provider, model_key = :model_key, label = :label,
                     is_default = :is_default, is_active = :is_active, updated_at = NOW()
                 WHERE id = :id'
            );
            $update->execute([
                'provider' => $provider,
                'model_key' => $modelKey,
                'label' => $label,
                'is_default' => $isDefault,
                'is_active' => $isActive,
                'id' => $updateId,
            ]);
        } catch (\PDOException $e) {
            $redirect('Could not update model — it may conflict with an existing entry.', 'error');
        }
        $redirect('Model updated successfully.');
    }

    $redirect('Unknown action.', 'error');
}

$alerts = [];
if (isset($_SESSION['cms_flash']) && is_array($_SESSION['cms_flash'])) {
    $alerts[] = $_SESSION['cms_flash'];
    unset($_SESSION['cms_flash']);
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = null;

$listStmt = $pdo->query('SELECT id, provider, model_key, label, is_default, is_active FROM ai_models ORDER BY provider, is_default DESC, label');
$models = $listStmt->fetchAll();

if ($editId > 0) {
    $editStmt = $pdo->prepare('SELECT id, provider, model_key, label, is_default, is_active FROM ai_models WHERE id = :id LIMIT 1');
    $editStmt->execute(['id' => $editId]);
    $editRow = $editStmt->fetch() ?: null;
    if ($editRow === null) {
        $alerts[] = ['type' => 'error', 'message' => 'Model not found.'];
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
            <h2 class="section-title">AI Models</h2>
            <p class="section-lead">Model catalogue available to agents. Focus: Claude Haiku &amp; GPT mini for fast, low-cost calls.</p>
        </div>
        <div class="toolbar__right">
            <a class="admin-btn admin-btn--primary" href="<?= cms_esc($editRow ? $selfUrl : $selfUrl . '#model-form') ?>">Add model</a>
        </div>
    </div>

    <div class="admin-grid admin-grid--2">
        <div class="panel">
            <div class="panel__head">
                <h3 class="panel__title">Models</h3>
                <span class="panel__meta"><?= count($models) ?> item(s)</span>
            </div>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Label</th>
                            <th>Provider</th>
                            <th>Model key</th>
                            <th>Default</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($models === []) : ?>
                            <tr><td colspan="6" class="muted">No models yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($models as $row) : ?>
                            <?php $rowId = (int) $row['id']; ?>
                            <tr>
                                <td><?= cms_esc($val($row, 'label')) ?></td>
                                <td><span class="pill pill--muted"><?= cms_esc($providers[$row['provider']] ?? $row['provider']) ?></span></td>
                                <td><code><?= cms_esc($val($row, 'model_key')) ?></code></td>
                                <td>
                                    <?php if ((int) ($row['is_default'] ?? 0) === 1) : ?>
                                        <span class="pill pill--accent">Default</span>
                                    <?php else : ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ((int) ($row['is_active'] ?? 0) === 1) : ?>
                                        <span class="pill pill--accent">Active</span>
                                    <?php else : ?>
                                        <span class="pill pill--muted">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="table-actions">
                                    <a class="admin-btn admin-btn--sm admin-btn--secondary" href="<?= cms_esc($selfUrl) ?>?edit=<?= $rowId ?>">Edit</a>
                                    <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>" onsubmit="return confirm('Delete this model?');">
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

        <div class="panel" id="model-form">
            <div class="panel__head">
                <h3 class="panel__title"><?= $editRow ? 'Edit model' : 'New model' ?></h3>
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
                <label class="field">Provider
                    <select name="provider" required>
                        <?php foreach ($providers as $key => $providerLabel) : ?>
                            <option value="<?= cms_esc($key) ?>"<?= $editRow && $val($editRow, 'provider') === $key ? ' selected' : '' ?>><?= cms_esc($providerLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field">Model key
                    <input type="text" name="model_key" value="<?= cms_esc($editRow ? $val($editRow, 'model_key') : '') ?>" placeholder="e.g. claude-3-5-haiku-20241022" required>
                </label>
                <label class="field">Label
                    <input type="text" name="label" value="<?= cms_esc($editRow ? $val($editRow, 'label') : '') ?>" placeholder="e.g. Claude 3.5 Haiku" required>
                </label>
                <label class="field">Default for provider
                    <select name="is_default">
                        <option value="0"<?= !$editRow || (int) ($editRow['is_default'] ?? 0) === 0 ? ' selected' : '' ?>>No</option>
                        <option value="1"<?= $editRow && (int) ($editRow['is_default'] ?? 0) === 1 ? ' selected' : '' ?>>Yes</option>
                    </select>
                </label>
                <label class="field">Active
                    <select name="is_active">
                        <option value="1"<?= !$editRow || (int) ($editRow['is_active'] ?? 1) === 1 ? ' selected' : '' ?>>Yes</option>
                        <option value="0"<?= $editRow && (int) ($editRow['is_active'] ?? 1) === 0 ? ' selected' : '' ?>>No</option>
                    </select>
                </label>
                <button type="submit" class="admin-btn admin-btn--primary"><?= $editRow ? 'Save changes' : 'Create model' ?></button>
            </form>
        </div>
    </div>
</section>
<?php
require dirname(__DIR__) . '/includes/footer.php';
