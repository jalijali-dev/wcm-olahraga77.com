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

$pageTitle  = 'New Prompt Version';
$currentNav = 'prompt-control';
$breadcrumbs = [
    ['label' => 'Dashboard',      'href' => cms_dashboard_href()],
    ['label' => 'Prompt Control', 'href' => 'prompt-control.php'],
    ['label' => 'New Version',    'href' => ''],
];

// ── Load source row ───────────────────────────────────────────────────────────
$sourceId = (int) ($_GET['id'] ?? $_POST['source_id'] ?? 0);

if ($sourceId <= 0) {
    $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Invalid prompt ID.'];
    header('Location: prompt-control.php', true, 302);
    exit;
}

$sourceStmt = $pdo->prepare(
    'SELECT id, agent_key, prompt_type, title, content, version, status, notes
       FROM agent_prompts
      WHERE id = :id'
);
$sourceStmt->execute(['id' => $sourceId]);
$source = $sourceStmt->fetch(PDO::FETCH_ASSOC);

if (!$source) {
    $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Source prompt not found.'];
    header('Location: prompt-control.php', true, 302);
    exit;
}

// agent_key and prompt_type are always taken from the DB row — never from POST.
$agentKey   = (string) $source['agent_key'];
$promptType = (string) $source['prompt_type'];

// ── POST handler ──────────────────────────────────────────────────────────────
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {

    $title     = trim((string) ($_POST['title']   ?? ''));
    $content   = trim((string) ($_POST['content'] ?? ''));
    $notes     = trim((string) ($_POST['notes']   ?? ''));
    $createdBy = trim((string) ($_SESSION['cms_admin_name'] ?? 'Olahraga77 Admin'));

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
        // Next version for this (agent_key, prompt_type) pair
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
            'message' => "New draft created: \"{$title}\" (v{$nextVer}). Review and activate when ready.",
        ];
        header('Location: prompt-control.php', true, 302);
        exit;
    }

    // Preserve form values on validation error
    $form = compact('title', 'content', 'notes');
} else {
    // Pre-fill from source row
    $form = [
        'title'   => (string) $source['title'],
        'content' => (string) $source['content'],
        'notes'   => '',
    ];
}

$alerts = [];
foreach ($errors as $err) {
    $alerts[] = ['type' => 'error', 'message' => $err];
}

// Next version number for display only (informational)
$vPreviewStmt = $pdo->prepare(
    'SELECT COALESCE(MAX(version), 0) + 1 AS next_ver
       FROM agent_prompts
      WHERE agent_key = :key AND prompt_type = :type'
);
$vPreviewStmt->execute(['key' => $agentKey, 'type' => $promptType]);
$nextVerPreview = (int) $vPreviewStmt->fetchColumn();

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/sidebar.php';
require dirname(__DIR__) . '/includes/navbar.php';
require dirname(__DIR__) . '/includes/breadcrumb.php';
require dirname(__DIR__) . '/includes/alerts.php';
?>

<section class="admin-stack">
    <div class="toolbar">
        <div class="toolbar__left">
            <h2 class="section-title">New Version from Existing Prompt</h2>
            <p class="section-lead">
                Based on: <strong><?= cms_esc($agentKey) ?> / <?= cms_esc($promptType) ?></strong>
                v<?= (int) $source['version'] ?> (<?= cms_esc($source['status']) ?>).
                Saving creates a new <strong>draft</strong> at v<?= $nextVerPreview ?>. The original row is not modified.
            </p>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Source</h3>
        </div>
        <div style="display:flex;gap:32px;font-size:14px;padding:0 20px 16px;flex-wrap:wrap;">
            <div>
                <span class="muted">Agent</span><br>
                <code><?= cms_esc($agentKey) ?></code>
            </div>
            <div>
                <span class="muted">Type</span><br>
                <code><?= cms_esc($promptType) ?></code>
            </div>
            <div>
                <span class="muted">Source version</span><br>
                v<?= (int) $source['version'] ?> (<?= cms_esc($source['status']) ?>)
            </div>
            <div>
                <span class="muted">New draft version</span><br>
                <strong>v<?= $nextVerPreview ?></strong>
            </div>
        </div>
    </div>

    <div class="panel">
        <form class="form-stack" method="post" action="prompt-control-edit.php">
            <?= cms_csrf_field() ?>
            <input type="hidden" name="source_id" value="<?= $sourceId ?>">

            <label class="field">Title
                <input id="pce_title" name="title" type="text" maxlength="255" value="<?= cms_esc($form['title']) ?>" required>
                <small class="muted">Describe this version. Max 255 characters.</small>
            </label>

            <label class="field">Prompt content
                <textarea id="pce_content" name="content" rows="16" style="font-family:monospace;font-size:13px;" required><?= cms_esc($form['content']) ?></textarea>
                <small class="muted" id="pce_content_count" style="text-align:right;display:block;"></small>
            </label>

            <label class="field">Notes
                <textarea id="pce_notes" name="notes" rows="3"><?= cms_esc($form['notes']) ?></textarea>
                <small class="muted">Describe what changed in this version relative to v<?= (int) $source['version'] ?>.</small>
            </label>

            <div style="display:flex;gap:10px;">
                <button type="submit" class="admin-btn admin-btn--primary">Save as Draft v<?= $nextVerPreview ?></button>
                <a href="prompt-control.php" class="admin-btn admin-btn--secondary">Cancel</a>
            </div>
        </form>
    </div>
</section>

<script>
(function () {
    var ta = document.getElementById('pce_content');
    var counter = document.getElementById('pce_content_count');
    if (!ta || !counter) return;
    function update() {
        counter.textContent = ta.value.length.toLocaleString() + ' characters';
    }
    ta.addEventListener('input', update);
    update();
})();
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
