<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/ai-helpers.php';

// Site-wide configuration is admin-tier — see cms_require_role() in
// functions.php for the full tier breakdown.
cms_require_role(['superadmin', 'admin']);

$pageTitle = 'AI Sandbox';
$currentNav = 'ai-sandbox';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'AI Sandbox', 'href' => ''],
];

$selfUrl = 'ai-sandbox.php';
$providers = ['openai' => 'OpenAI', 'anthropic' => 'Anthropic'];

$alerts = [];
$result = null;

$credStmt = $pdo->query("SELECT id, provider, label, key_last4 FROM ai_credentials WHERE is_active = 1 ORDER BY provider, label");
$credentials = $credStmt->fetchAll();

$modelStmt = $pdo->query("SELECT id, provider, model_key, label FROM ai_models WHERE is_active = 1 ORDER BY provider, label");
$models = $modelStmt->fetchAll();

$selectedCredentialId = (int) ($_POST['credential_id'] ?? 0);
$selectedModelId = (int) ($_POST['model_id'] ?? 0);
$systemPrompt = trim((string) ($_POST['system_prompt'] ?? ''));
$userPrompt = trim((string) ($_POST['user_prompt'] ?? ''));
$temperature = isset($_POST['temperature']) ? (float) $_POST['temperature'] : 0.7;
$maxTokens = isset($_POST['max_tokens']) ? (int) $_POST['max_tokens'] : 512;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if ($selectedCredentialId <= 0) {
        $alerts[] = ['type' => 'error', 'message' => 'Select a credential first.'];
    } elseif ($selectedModelId <= 0) {
        $alerts[] = ['type' => 'error', 'message' => 'Select a model first.'];
    } elseif ($userPrompt === '') {
        $alerts[] = ['type' => 'error', 'message' => 'Enter a test prompt.'];
    } else {
        $credStmt = $pdo->prepare('SELECT id, provider, api_key_enc FROM ai_credentials WHERE id = :id AND is_active = 1 LIMIT 1');
        $credStmt->execute(['id' => $selectedCredentialId]);
        $cred = $credStmt->fetch();

        $modelRowStmt = $pdo->prepare('SELECT id, provider, model_key FROM ai_models WHERE id = :id AND is_active = 1 LIMIT 1');
        $modelRowStmt->execute(['id' => $selectedModelId]);
        $modelRow = $modelRowStmt->fetch();

        if (!$cred) {
            $alerts[] = ['type' => 'error', 'message' => 'Credential not found or inactive.'];
        } elseif (!$modelRow) {
            $alerts[] = ['type' => 'error', 'message' => 'Model not found or inactive.'];
        } elseif ($cred['provider'] !== $modelRow['provider']) {
            $alerts[] = ['type' => 'error', 'message' => 'Credential and model must be for the same provider.'];
        } else {
            cms_ai_rate_limit(10, 60);
            session_write_close();
            set_time_limit(45);

            $apiKey = cms_ai_decrypt((string) $cred['api_key_enc']);
            $temperature = max(0.0, min(2.0, $temperature));
            $maxTokens = max(16, min(4096, $maxTokens));

            $result = cms_ai_call_provider(
                (string) $cred['provider'],
                $apiKey,
                (string) $modelRow['model_key'],
                $userPrompt,
                $systemPrompt,
                $maxTokens,
                $temperature
            );

            cms_ai_log(
                'ai-sandbox',
                (string) $cred['provider'],
                $result['success'],
                (string) $modelRow['model_key'],
                $result['http_status'],
                $result['latency_ms'],
                mb_strlen($userPrompt),
                $result['success'] ? '' : $result['error']
            );

            if (!$result['success']) {
                $alerts[] = ['type' => 'error', 'message' => 'AI call failed: ' . $result['error']];
            }
        }
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
            <h2 class="section-title">AI Sandbox</h2>
            <p class="section-lead">Send a test prompt straight to a configured provider/model using a stored credential.</p>
        </div>
    </div>

    <div class="admin-grid admin-grid--2">
        <div class="panel">
            <div class="panel__head">
                <h3 class="panel__title">Test prompt</h3>
            </div>

            <?php if ($credentials === []) : ?>
                <p class="muted" style="padding:8px 0;">No active credentials yet. Add one in <a href="<?= cms_esc(cms_nav_href('ai-credentials.php')) ?>">AI Credentials</a>.</p>
            <?php elseif ($models === []) : ?>
                <p class="muted" style="padding:8px 0;">No active models yet. Add one in <a href="<?= cms_esc(cms_nav_href('ai-models.php')) ?>">AI Models</a>.</p>
            <?php else : ?>
                <form class="form-stack" method="post" action="<?= cms_esc($selfUrl) ?>">
                    <?= cms_csrf_field() ?>
                    <label class="field">Credential
                        <select name="credential_id" required>
                            <option value="0">— Select credential —</option>
                            <?php foreach ($credentials as $c) : ?>
                                <option value="<?= (int) $c['id'] ?>" data-provider="<?= cms_esc($c['provider']) ?>"<?= $selectedCredentialId === (int) $c['id'] ? ' selected' : '' ?>>
                                    <?= cms_esc($c['label']) ?> (<?= cms_esc($providers[$c['provider']] ?? $c['provider']) ?>, ••••<?= cms_esc((string) $c['key_last4']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="field">Model
                        <select name="model_id" required>
                            <option value="0">— Select model —</option>
                            <?php foreach ($models as $m) : ?>
                                <option value="<?= (int) $m['id'] ?>" data-provider="<?= cms_esc($m['provider']) ?>"<?= $selectedModelId === (int) $m['id'] ? ' selected' : '' ?>>
                                    <?= cms_esc($m['label']) ?> (<?= cms_esc($providers[$m['provider']] ?? $m['provider']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="field">System prompt (optional)
                        <textarea name="system_prompt" rows="3" placeholder="e.g. You are a helpful assistant."><?= cms_esc($systemPrompt) ?></textarea>
                    </label>
                    <label class="field">Test prompt
                        <textarea name="user_prompt" rows="5" required placeholder="Type a prompt to test..."><?= cms_esc($userPrompt) ?></textarea>
                    </label>
                    <label class="field">Temperature
                        <input type="number" name="temperature" min="0" max="2" step="0.1" value="<?= cms_esc((string) $temperature) ?>">
                    </label>
                    <label class="field">Max tokens
                        <input type="number" name="max_tokens" min="16" max="4096" value="<?= cms_esc((string) $maxTokens) ?>">
                    </label>
                    <button type="submit" class="admin-btn admin-btn--primary">Send test prompt</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="panel">
            <div class="panel__head">
                <h3 class="panel__title">Response</h3>
                <?php if ($result !== null) : ?>
                    <span class="panel__meta"><?= (int) $result['latency_ms'] ?> ms · HTTP <?= (int) $result['http_status'] ?></span>
                <?php endif; ?>
            </div>
            <?php if ($result !== null && $result['success']) : ?>
                <div class="form-stack">
                    <label class="field">Output
                        <textarea rows="14" readonly><?= cms_esc($result['text']) ?></textarea>
                    </label>
                </div>
            <?php elseif ($result !== null) : ?>
                <p class="muted" style="padding:16px 0;">Call failed — see error above.</p>
            <?php else : ?>
                <p class="muted" style="padding:16px 0;">Send a prompt to see the model's response here.</p>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php
require dirname(__DIR__) . '/includes/footer.php';
