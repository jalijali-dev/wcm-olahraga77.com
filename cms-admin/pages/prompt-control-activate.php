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

$pageTitle  = 'Activate Prompt';
$currentNav = 'prompt-control';
$breadcrumbs = [
    ['label' => 'Dashboard',      'href' => cms_dashboard_href()],
    ['label' => 'Prompt Control', 'href' => 'prompt-control.php'],
    ['label' => 'Activate',       'href' => ''],
];

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

if ($id <= 0) {
    $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Invalid prompt ID.'];
    header('Location: prompt-control.php', true, 302);
    exit;
}

// Load the draft being activated
$draftStmt = $pdo->prepare(
    'SELECT id, agent_key, prompt_type, title, content, version, status, notes
       FROM agent_prompts
      WHERE id = :id'
);
$draftStmt->execute(['id' => $id]);
$draft = $draftStmt->fetch(PDO::FETCH_ASSOC);

if (!$draft) {
    $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Prompt not found.'];
    header('Location: prompt-control.php', true, 302);
    exit;
}
if ($draft['status'] !== 'draft') {
    $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Only draft prompts can be activated.'];
    header('Location: prompt-control.php', true, 302);
    exit;
}

// Load current active prompt for same (agent_key, prompt_type) — may be null
$activeStmt = $pdo->prepare(
    'SELECT id, title, version, activated_at
       FROM agent_prompts
      WHERE agent_key = :key AND prompt_type = :type AND status = :status
      ORDER BY version DESC
      LIMIT 1'
);
$activeStmt->execute([
    'key'    => $draft['agent_key'],
    'type'   => $draft['prompt_type'],
    'status' => 'active',
]);
$currentActive = $activeStmt->fetch(PDO::FETCH_ASSOC) ?: null;

// ── POST — Confirm and activate ───────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $confirmNotes = trim((string) ($_POST['activation_notes'] ?? ''));

    if ($confirmNotes === '') {
        $alerts = [['type' => 'error', 'message' => 'Activation notes are required. Describe why you are activating this version.']];
    } else {
        $checksum = hash('sha256', (string) $draft['content']);

        try {
            $pdo->beginTransaction();

            // 1. Archive the currently active prompt (if any)
            if ($currentActive) {
                $archiveStmt = $pdo->prepare(
                    'UPDATE agent_prompts
                        SET status = :status, archived_at = NOW()
                      WHERE id = :id AND status = :curr_status'
                );
                $archiveStmt->execute([
                    'status'      => 'archived',
                    'id'          => (int) $currentActive['id'],
                    'curr_status' => 'active',
                ]);
            }

            // 2. Activate the draft
            $activateStmt = $pdo->prepare(
                'UPDATE agent_prompts
                    SET status = :status, activated_at = NOW(), checksum = :checksum,
                        notes = CONCAT(COALESCE(notes, \'\'), :extra_notes)
                  WHERE id = :id AND status = :curr_status'
            );
            $activateStmt->execute([
                'status'      => 'active',
                'checksum'    => $checksum,
                'extra_notes' => $confirmNotes !== '' ? "\n[Activation] " . $confirmNotes : '',
                'id'          => $id,
                'curr_status' => 'draft',
            ]);

            if ($activateStmt->rowCount() < 1) {
                throw new RuntimeException('Prompt was not in draft status when activation ran. No changes made.');
            }

            $pdo->commit();

            // Clear PromptLoader cache so this request and next immediate requests see the change
            PromptLoader::clearCache();

            $adminName = $_SESSION['cms_admin_name'] ?? 'admin';
            error_log("[PromptControl] Activated {$draft['agent_key']}/{$draft['prompt_type']} v{$draft['version']} by {$adminName}. Checksum: {$checksum}");

            $_SESSION['cms_flash'] = [
                'type'    => 'success',
                'message' => "Prompt \"{$draft['title']}\" is now active. Production API calls will use this prompt.",
            ];
            header('Location: prompt-control.php', true, 302);
            exit;

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $alerts = [['type' => 'error', 'message' => 'Activation failed: ' . $e->getMessage()]];
        }
    }
}

$alerts = $alerts ?? [];

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/sidebar.php';
require dirname(__DIR__) . '/includes/navbar.php';
require dirname(__DIR__) . '/includes/breadcrumb.php';
require dirname(__DIR__) . '/includes/alerts.php';
?>

<section class="admin-stack">
    <div class="toolbar">
        <div class="toolbar__left">
            <h2 class="section-title">Activate Prompt</h2>
            <p class="section-lead">Review the draft carefully before activating. Activation affects live API calls immediately.</p>
        </div>
    </div>

    <?php if ($currentActive): ?>
    <div class="panel" style="border-left:3px solid var(--warning-border, #f59e0b);">
        <div class="panel__head">
            <h3 class="panel__title">Currently active prompt will be archived</h3>
        </div>
        <p class="muted" style="padding:0 0 16px;">
            <strong><?= cms_esc($currentActive['title']) ?></strong> (v<?= (int) $currentActive['version'] ?>)
            — activated <?= cms_esc(substr((string) ($currentActive['activated_at'] ?? 'unknown'), 0, 10)) ?>
            — will move to <em>archived</em> status.
        </p>
    </div>
    <?php endif; ?>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Draft to activate</h3>
        </div>
        <div class="table-wrap">
            <table class="admin-table">
                <tr><th style="width:120px;">Agent</th><td><code><?= cms_esc($draft['agent_key']) ?></code></td></tr>
                <tr><th>Type</th><td><code><?= cms_esc($draft['prompt_type']) ?></code></td></tr>
                <tr><th>Title</th><td><?= cms_esc($draft['title']) ?></td></tr>
                <tr><th>Version</th><td><?= (int) $draft['version'] ?></td></tr>
                <?php if ($draft['notes'] !== ''): ?>
                <tr><th>Notes</th><td><?= nl2br(cms_esc($draft['notes'])) ?></td></tr>
                <?php endif; ?>
            </table>
        </div>

        <label class="field" style="padding:16px 20px 20px;">Prompt content preview
            <textarea readonly rows="12" style="font-family:monospace;font-size:12px;"><?= cms_esc($draft['content']) ?></textarea>
        </label>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Confirm activation</h3>
        </div>
        <form class="form-stack" method="post" action="prompt-control-activate.php">
            <?= cms_csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">

            <label class="field">Why are you activating this version?
                <textarea id="pc_activation_notes" name="activation_notes" rows="3" required
                          placeholder="e.g. Added hashtag count limit after testing showed overflow in captions."><?= cms_esc($_POST['activation_notes'] ?? '') ?></textarea>
            </label>

            <div style="display:flex;gap:10px;">
                <button type="submit" class="admin-btn admin-btn--primary">Activate Prompt</button>
                <a href="prompt-control.php" class="admin-btn admin-btn--secondary">Cancel</a>
            </div>
        </form>
    </div>
</section>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
