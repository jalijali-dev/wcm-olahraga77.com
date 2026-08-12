<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/ai-helpers.php';

// Holds raw AI provider API keys — superadmin-only. See cms_require_role()
// in functions.php for the full tier breakdown.
cms_require_role(['superadmin']);

$pageTitle = 'AI Credentials';
$currentNav = 'ai-credentials';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'AI Credentials', 'href' => ''],
];

$selfUrl = 'ai-credentials.php';
$providers = ['openai' => 'OpenAI', 'anthropic' => 'Anthropic'];

$redirect = static function (string $message, string $type = 'success', ?string $query = null) use ($selfUrl): void {
    $_SESSION['cms_flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . $selfUrl . ($query ? '?' . $query : ''), true, 302);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete') {
        $deleteId = (int) ($_POST['id'] ?? 0);
        if ($deleteId <= 0) {
            $redirect('Invalid credential.', 'error');
        }
        $delete = $pdo->prepare('DELETE FROM ai_credentials WHERE id = :id');
        $delete->execute(['id' => $deleteId]);
        if ($delete->rowCount() < 1) {
            $redirect('Credential not found or already deleted.', 'error');
        }
        $redirect('Credential deleted successfully.');
    }

    $provider = (string) ($_POST['provider'] ?? '');
    $label = trim((string) ($_POST['label'] ?? ''));
    $apiKey = trim((string) ($_POST['api_key'] ?? ''));
    $isActive = isset($_POST['is_active']) && (int) $_POST['is_active'] === 1 ? 1 : 0;

    if (!isset($providers[$provider])) {
        $redirect('Invalid provider.', 'error');
    }
    if ($label === '') {
        $redirect('Label is required.', 'error');
    }

    if ($action === 'create') {
        if ($apiKey === '') {
            $redirect('API key is required.', 'error');
        }
        $insert = $pdo->prepare(
            'INSERT INTO ai_credentials (provider, label, api_key_enc, key_last4, is_active, created_at, updated_at)
             VALUES (:provider, :label, :api_key_enc, :key_last4, :is_active, NOW(), NOW())'
        );
        $insert->execute([
            'provider' => $provider,
            'label' => $label,
            'api_key_enc' => cms_ai_encrypt($apiKey),
            'key_last4' => mb_substr($apiKey, -4),
            'is_active' => $isActive,
        ]);
        $redirect('Credential saved successfully.');
    }

    if ($action === 'update') {
        $updateId = (int) ($_POST['id'] ?? 0);
        if ($updateId <= 0) {
            $redirect('Invalid credential.', 'error');
        }

        if ($apiKey !== '') {
            $update = $pdo->prepare(
                'UPDATE ai_credentials
                 SET provider = :provider, label = :label, api_key_enc = :api_key_enc,
                     key_last4 = :key_last4, is_active = :is_active, updated_at = NOW()
                 WHERE id = :id'
            );
            $update->execute([
                'provider' => $provider,
                'label' => $label,
                'api_key_enc' => cms_ai_encrypt($apiKey),
                'key_last4' => mb_substr($apiKey, -4),
                'is_active' => $isActive,
                'id' => $updateId,
            ]);
        } else {
            $update = $pdo->prepare(
                'UPDATE ai_credentials
                 SET provider = :provider, label = :label, is_active = :is_active, updated_at = NOW()
                 WHERE id = :id'
            );
            $update->execute([
                'provider' => $provider,
                'label' => $label,
                'is_active' => $isActive,
                'id' => $updateId,
            ]);
        }
        $redirect('Credential updated successfully.');
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

$listStmt = $pdo->query(
    'SELECT id, provider, label, key_last4, is_active, updated_at FROM ai_credentials ORDER BY id DESC'
);
$credentials = $listStmt->fetchAll();

if ($editId > 0) {
    $editStmt = $pdo->prepare('SELECT id, provider, label, key_last4, is_active FROM ai_credentials WHERE id = :id LIMIT 1');
    $editStmt->execute(['id' => $editId]);
    $editRow = $editStmt->fetch() ?: null;
    if ($editRow === null) {
        $alerts[] = ['type' => 'error', 'message' => 'Credential not found.'];
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
            <h2 class="section-title">AI Credentials</h2>
            <p class="section-lead">API keys for OpenAI &amp; Anthropic, stored encrypted. Keys are never displayed again after saving.</p>
        </div>
        <div class="toolbar__right">
            <a class="admin-btn admin-btn--primary" href="<?= cms_esc($editRow ? $selfUrl : $selfUrl . '#credential-form') ?>">Add credential</a>
        </div>
    </div>

    <div class="admin-grid admin-grid--2">
        <div class="panel">
            <div class="panel__head">
                <h3 class="panel__title">Credentials</h3>
                <span class="panel__meta"><?= count($credentials) ?> item(s)</span>
            </div>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Label</th>
                            <th>Provider</th>
                            <th>Key</th>
                            <th>Status</th>
                            <th>Updated</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($credentials === []) : ?>
                            <tr><td colspan="6" class="muted">No credentials yet. Add your OpenAI or Anthropic API key.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($credentials as $row) : ?>
                            <?php $rowId = (int) $row['id']; ?>
                            <tr>
                                <td><?= cms_esc($val($row, 'label')) ?></td>
                                <td><span class="pill pill--muted"><?= cms_esc($providers[$row['provider']] ?? $row['provider']) ?></span></td>
                                <td>••••<?= cms_esc($val($row, 'key_last4')) ?></td>
                                <td>
                                    <?php if ((int) ($row['is_active'] ?? 0) === 1) : ?>
                                        <span class="pill pill--accent">Active</span>
                                    <?php else : ?>
                                        <span class="pill pill--muted">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= cms_esc($val($row, 'updated_at')) ?></td>
                                <td class="table-actions">
                                    <a class="admin-btn admin-btn--sm admin-btn--secondary" href="<?= cms_esc($selfUrl) ?>?edit=<?= $rowId ?>">Edit</a>
                                    <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>" onsubmit="return confirm('Delete this credential?');">
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

        <div class="panel" id="credential-form">
            <div class="panel__head">
                <h3 class="panel__title"><?= $editRow ? 'Edit credential' : 'New credential' ?></h3>
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
                <label class="field">Label
                    <input type="text" name="label" value="<?= cms_esc($editRow ? $val($editRow, 'label') : '') ?>" placeholder="e.g. Production key" required>
                </label>
                <label class="field">API key<?= $editRow ? ' (leave blank to keep current key)' : '' ?>
                    <input type="password" name="api_key" placeholder="<?= $editRow ? '••••' . cms_esc($val($editRow, 'key_last4')) : 'sk-...' ?>" autocomplete="off">
                </label>
                <label class="field">Active
                    <select name="is_active">
                        <option value="1"<?= !$editRow || (int) ($editRow['is_active'] ?? 1) === 1 ? ' selected' : '' ?>>Yes</option>
                        <option value="0"<?= $editRow && (int) ($editRow['is_active'] ?? 1) === 0 ? ' selected' : '' ?>>No</option>
                    </select>
                </label>
                <button type="submit" class="admin-btn admin-btn--primary"><?= $editRow ? 'Save changes' : 'Save credential' ?></button>
            </form>
        </div>
    </div>
</section>
<?php
require dirname(__DIR__) . '/includes/footer.php';
