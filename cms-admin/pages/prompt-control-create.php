<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';

// Site-wide configuration is admin-tier — see cms_require_role() in
// functions.php for the full tier breakdown.
cms_require_role(['superadmin', 'admin']);

$promptLoaderPath = dirname(__DIR__, 2) . '/services/PromptLoader.php';
if (!file_exists($promptLoaderPath)) {
    die('PromptLoader.php tidak ditemukan. Jalankan installer/migration atau cek folder services.');
}
require_once $promptLoaderPath;

$pageTitle  = 'New Prompt Draft';
$currentNav = 'prompt-control';
$breadcrumbs = [
    ['label' => 'Dashboard',       'href' => cms_dashboard_href()],
    ['label' => 'Prompt Control',  'href' => 'prompt-control.php'],
    ['label' => 'New Draft',       'href' => ''],
];

$errors  = [];
$notices = [];

// ── POST handler ──────────────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {

    $agentKey   = trim((string) ($_POST['agent_key']   ?? ''));
    $promptType = trim((string) ($_POST['prompt_type'] ?? ''));
    $title      = trim((string) ($_POST['title']       ?? ''));
    $content    = trim((string) ($_POST['content']     ?? ''));
    $notes      = trim((string) ($_POST['notes']       ?? ''));
    $createdBy  = trim((string) ($_SESSION['cms_admin_name'] ?? 'admin'));

    // Validate
    // Note: this file builds its own INSERT rather than calling
    // PromptLoader::savePrompt() (which returns null on an invalid
    // agent_key/prompt_type with no error detail the caller could surface)
    // — these two checks run first specifically so a bad value always
    // produces a clear, specific message here instead of ever reaching a
    // silent null-return path. GROWTH_AGENT_V2_PROPOSAL.md § 6 Fase F.0
    // flagged this — messages below made specific (which value, which
    // keys are valid) rather than the previous generic "Invalid agent key."
    if (!in_array($agentKey, PromptLoader::ALLOWED_AGENT_KEYS, true)) {
        $errors[] = 'Invalid agent key: "' . $agentKey . '". Must be one of: ' . implode(', ', PromptLoader::ALLOWED_AGENT_KEYS) . '.';
    }
    if (!in_array($promptType, PromptLoader::ALLOWED_PROMPT_TYPES, true)) {
        $errors[] = 'Invalid prompt type: "' . $promptType . '". Must be one of: ' . implode(', ', PromptLoader::ALLOWED_PROMPT_TYPES) . '.';
    }
    if ($title === '') {
        $errors[] = 'Title is required.';
    }
    if (mb_strlen($title) > 255) {
        $errors[] = 'Title must be 255 characters or fewer.';
    }
    if ($content === '') {
        $errors[] = 'Content is required.';
    }

    if (empty($errors)) {
        // Compute next version for this (agent_key, prompt_type) pair
        $vStmt = $pdo->prepare(
            'SELECT COALESCE(MAX(version), 0) + 1 AS next_ver
               FROM agent_prompts
              WHERE agent_key = :key AND prompt_type = :type'
        );
        $vStmt->execute(['key' => $agentKey, 'type' => $promptType]);
        $nextVer = (int) $vStmt->fetchColumn();

        $ins = $pdo->prepare(
            'INSERT INTO agent_prompts
               (agent_key, prompt_type, title, content, version, status, notes, created_by)
             VALUES
               (:agent_key, :prompt_type, :title, :content, :version, :status, :notes, :created_by)'
        );
        $ins->execute([
            'agent_key'   => $agentKey,
            'prompt_type' => $promptType,
            'title'       => $title,
            'content'     => $content,
            'version'     => $nextVer,
            'status'      => 'draft',
            'notes'       => $notes,
            'created_by'  => $createdBy,
        ]);

        $_SESSION['cms_flash'] = [
            'type'    => 'success',
            'message' => "Draft \"{$title}\" created as v{$nextVer}. Review it and activate when ready.",
        ];
        header('Location: prompt-control.php', true, 302);
        exit;
    }

    // Preserve form values on error
    $form = compact('agentKey', 'promptType', 'title', 'content', 'notes');
} else {
    $form = [
        'agentKey'   => $_GET['agent_key']   ?? '',
        'promptType' => $_GET['prompt_type'] ?? '',
        'title'      => '',
        'content'    => '',
        'notes'      => '',
    ];
}

$alerts = [];
foreach ($errors as $err) {
    $alerts[] = ['type' => 'error', 'message' => $err];
}

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/sidebar.php';
require dirname(__DIR__) . '/includes/navbar.php';
require dirname(__DIR__) . '/includes/breadcrumb.php';
require dirname(__DIR__) . '/includes/alerts.php';
?>

<section class="admin-stack">
    <div class="toolbar">
        <div class="toolbar__left">
            <h2 class="section-title">New Prompt Draft</h2>
            <p class="section-lead">Drafts are saved but not used by agents until you activate them.</p>
        </div>
    </div>

    <div class="panel">
        <form class="form-stack" method="post" action="prompt-control-create.php">
            <?= cms_csrf_field() ?>

            <label class="field">Agent key
                <select id="pc_agent_key" name="agent_key" required>
                    <option value="">— select —</option>
                    <?php foreach (PromptLoader::ALLOWED_AGENT_KEYS as $k): ?>
                        <option value="<?= cms_esc($k) ?>"<?= $form['agentKey'] === $k ? ' selected' : '' ?>><?= cms_esc($k) ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="muted">Use <code>global</code> for layers shared across all agents.</small>
            </label>

            <label class="field">Prompt type
                <select id="pc_prompt_type" name="prompt_type" required>
                    <option value="">— select —</option>
                    <?php foreach (PromptLoader::ALLOWED_PROMPT_TYPES as $t): ?>
                        <option value="<?= cms_esc($t) ?>"<?= $form['promptType'] === $t ? ' selected' : '' ?>><?= cms_esc($t) ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="muted"><code>base</code> brand identity · <code>guardrail</code> safety rules · <code>instruction</code> task instructions · <code>output_schema</code> JSON format</small>
            </label>

            <label class="field">Title
                <input id="pc_title" name="title" type="text" maxlength="255" value="<?= cms_esc($form['title']) ?>" required>
                <small class="muted">Human-readable label for this version. Max 255 characters.</small>
            </label>

            <label class="field">Prompt content
                <textarea id="pc_content" name="content" rows="14" style="font-family:monospace;font-size:13px;" required><?= cms_esc($form['content']) ?></textarea>
                <small class="muted" id="pc_content_count" style="text-align:right;display:block;"></small>
            </label>

            <label class="field">Notes
                <textarea id="pc_notes" name="notes" rows="3"><?= cms_esc($form['notes']) ?></textarea>
                <small class="muted">Optional. Describe what changed in this version.</small>
            </label>

            <div style="display:flex;gap:10px;">
                <button type="submit" class="admin-btn admin-btn--primary">Save Draft</button>
                <a href="prompt-control.php" class="admin-btn admin-btn--secondary">Cancel</a>
            </div>
        </form>
    </div>
</section>

<script>
(function () {
    var ta = document.getElementById('pc_content');
    var counter = document.getElementById('pc_content_count');
    if (!ta || !counter) return;
    function update() {
        counter.textContent = ta.value.length.toLocaleString() + ' characters';
    }
    ta.addEventListener('input', update);
    update();
})();
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
