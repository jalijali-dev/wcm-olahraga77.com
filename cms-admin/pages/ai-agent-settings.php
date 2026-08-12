<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';

// Site-wide configuration is admin-tier — see cms_require_role() in
// functions.php for the full tier breakdown.
cms_require_role(['superadmin', 'admin']);

$pageTitle = 'AI Agent Settings';
$currentNav = 'ai-agent-settings';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'AI Agent Settings', 'href' => ''],
];

$selfUrl = 'ai-agent-settings.php';

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
            $redirect('Invalid agent.', 'error');
        }
        $delete = $pdo->prepare('DELETE FROM ai_agent_settings WHERE id = :id');
        $delete->execute(['id' => $deleteId]);
        if ($delete->rowCount() < 1) {
            $redirect('Agent not found or already deleted.', 'error');
        }
        $redirect('Agent removed successfully.');
    }

    $agentKey = trim((string) ($_POST['agent_key'] ?? ''));
    $label = trim((string) ($_POST['label'] ?? ''));
    $modelId = (int) ($_POST['model_id'] ?? 0);
    $temperature = (float) ($_POST['temperature'] ?? 0.7);
    $maxTokens = (int) ($_POST['max_tokens'] ?? 512);
    $systemPrompt = trim((string) ($_POST['system_prompt'] ?? ''));
    $isActive = isset($_POST['is_active']) && (int) $_POST['is_active'] === 1 ? 1 : 0;

    if ($agentKey === '' || !preg_match('/^[a-z0-9_]+$/', $agentKey)) {
        $redirect('Agent key is required (lowercase letters, numbers, underscores only).', 'error');
    }
    if ($label === '') {
        $redirect('Label is required.', 'error');
    }
    $temperature = max(0.0, min(2.0, $temperature));
    $maxTokens = max(16, min(8192, $maxTokens));
    $modelIdValue = $modelId > 0 ? $modelId : null;

    $payload = [
        'agent_key' => $agentKey,
        'label' => $label,
        'model_id' => $modelIdValue,
        'temperature' => $temperature,
        'max_tokens' => $maxTokens,
        'system_prompt' => $systemPrompt !== '' ? $systemPrompt : null,
        'is_active' => $isActive,
    ];

    if ($action === 'create') {
        try {
            $insert = $pdo->prepare(
                'INSERT INTO ai_agent_settings (agent_key, label, model_id, temperature, max_tokens, system_prompt, is_active, created_at, updated_at)
                 VALUES (:agent_key, :label, :model_id, :temperature, :max_tokens, :system_prompt, :is_active, NOW(), NOW())'
            );
            $insert->execute($payload);
        } catch (\PDOException $e) {
            $redirect('Could not save — an agent with this key may already exist.', 'error');
        }
        $redirect('Agent settings saved successfully.');
    }

    if ($action === 'update') {
        $updateId = (int) ($_POST['id'] ?? 0);
        if ($updateId <= 0) {
            $redirect('Invalid agent.', 'error');
        }
        try {
            $update = $pdo->prepare(
                'UPDATE ai_agent_settings
                 SET agent_key = :agent_key, label = :label, model_id = :model_id,
                     temperature = :temperature, max_tokens = :max_tokens,
                     system_prompt = :system_prompt, is_active = :is_active, updated_at = NOW()
                 WHERE id = :id'
            );
            $update->execute($payload + ['id' => $updateId]);
        } catch (\PDOException $e) {
            $redirect('Could not update — agent key may conflict with an existing entry.', 'error');
        }
        $redirect('Agent settings updated successfully.');
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

$modelsStmt = $pdo->query("SELECT id, provider, label, model_key FROM ai_models WHERE is_active = 1 ORDER BY provider, label");
$models = $modelsStmt->fetchAll();

$listStmt = $pdo->query(
    'SELECT a.id, a.agent_key, a.label, a.temperature, a.max_tokens, a.is_active,
            m.label AS model_label, m.provider AS model_provider
     FROM ai_agent_settings a
     LEFT JOIN ai_models m ON m.id = a.model_id
     ORDER BY a.agent_key'
);
$agents = $listStmt->fetchAll();

if ($editId > 0) {
    $editStmt = $pdo->prepare('SELECT id, agent_key, label, model_id, temperature, max_tokens, system_prompt, is_active FROM ai_agent_settings WHERE id = :id LIMIT 1');
    $editStmt->execute(['id' => $editId]);
    $editRow = $editStmt->fetch() ?: null;
    if ($editRow === null) {
        $alerts[] = ['type' => 'error', 'message' => 'Agent not found.'];
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
            <h2 class="section-title">AI Agent Settings</h2>
            <p class="section-lead">Model, temperature, and system prompt per AI agent (SEO generator, Account Recovery Bot, etc).</p>
        </div>
        <div class="toolbar__right">
            <a class="admin-btn admin-btn--primary" href="<?= cms_esc($editRow ? $selfUrl : $selfUrl . '#agent-form') ?>">Add agent</a>
        </div>
    </div>

    <div class="admin-grid admin-grid--2">
        <div class="panel">
            <div class="panel__head">
                <h3 class="panel__title">Agents</h3>
                <span class="panel__meta"><?= count($agents) ?> item(s)</span>
            </div>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Agent</th>
                            <th>Model</th>
                            <th>Temp</th>
                            <th>Max tokens</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($agents === []) : ?>
                            <tr><td colspan="6" class="muted">No agents configured yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($agents as $row) : ?>
                            <?php $rowId = (int) $row['id']; ?>
                            <tr>
                                <td>
                                    <strong><?= cms_esc($val($row, 'label')) ?></strong><br>
                                    <span class="muted">agent_key: <code><?= cms_esc($val($row, 'agent_key')) ?></code></span>
                                </td>
                                <td><?= $row['model_label'] ? cms_esc($val($row, 'model_label')) : '<span class="muted">—</span>' ?></td>
                                <td><?= cms_esc($val($row, 'temperature')) ?></td>
                                <td><?= cms_esc($val($row, 'max_tokens')) ?></td>
                                <td>
                                    <?php if ((int) ($row['is_active'] ?? 0) === 1) : ?>
                                        <span class="pill pill--accent">Active</span>
                                    <?php else : ?>
                                        <span class="pill pill--muted">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="table-actions">
                                    <a class="admin-btn admin-btn--sm admin-btn--secondary" href="<?= cms_esc($selfUrl) ?>?edit=<?= $rowId ?>">Edit</a>
                                    <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>" onsubmit="return confirm('Remove this agent config?');">
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

        <div class="panel" id="agent-form">
            <div class="panel__head">
                <h3 class="panel__title"><?= $editRow ? 'Edit agent' : 'New agent' ?></h3>
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
                <label class="field">Agent key
                    <input type="text" name="agent_key" value="<?= cms_esc($editRow ? $val($editRow, 'agent_key') : '') ?>" placeholder="e.g. account_recovery" pattern="[a-z0-9_]+" required>
                </label>
                <label class="field">Label
                    <input type="text" name="label" value="<?= cms_esc($editRow ? $val($editRow, 'label') : '') ?>" placeholder="e.g. Account Recovery Bot" required>
                </label>
                <label class="field">Model
                    <select name="model_id">
                        <option value="0">— No model selected —</option>
                        <?php foreach ($models as $m) : ?>
                            <?php $selected = $editRow && (int) ($editRow['model_id'] ?? 0) === (int) $m['id']; ?>
                            <option value="<?= (int) $m['id'] ?>"<?= $selected ? ' selected' : '' ?>><?= cms_esc($m['label']) ?> (<?= cms_esc($m['provider']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field">Temperature (0.0 – 2.0)
                    <input type="number" name="temperature" min="0" max="2" step="0.1" value="<?= cms_esc($editRow ? $val($editRow, 'temperature') : '0.7') ?>">
                </label>
                <label class="field">Max tokens
                    <input type="number" name="max_tokens" min="16" max="8192" value="<?= cms_esc($editRow ? $val($editRow, 'max_tokens') : '512') ?>">
                </label>
                <label class="field">System prompt
                    <textarea name="system_prompt" rows="5" placeholder="Instructions the agent always follows."><?= cms_esc($editRow ? $val($editRow, 'system_prompt') : '') ?></textarea>
                </label>
                <label class="field">Active
                    <select name="is_active">
                        <option value="1"<?= !$editRow || (int) ($editRow['is_active'] ?? 1) === 1 ? ' selected' : '' ?>>Yes</option>
                        <option value="0"<?= $editRow && (int) ($editRow['is_active'] ?? 1) === 0 ? ' selected' : '' ?>>No</option>
                    </select>
                </label>
                <button type="submit" class="admin-btn admin-btn--primary"><?= $editRow ? 'Save changes' : 'Create agent' ?></button>
            </form>
        </div>
    </div>
</section>
<?php
require dirname(__DIR__) . '/includes/footer.php';
