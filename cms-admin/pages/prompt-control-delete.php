<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';

// Site-wide configuration is admin-tier — see cms_require_role() in
// functions.php for the full tier breakdown.
cms_require_role(['superadmin', 'admin']);

// POST-only action. Reject anything else.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: prompt-control.php', true, 302);
    exit;
}

$id = (int) ($_POST['id'] ?? 0);

if ($id <= 0) {
    $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Invalid prompt ID.'];
    header('Location: prompt-control.php', true, 302);
    exit;
}

// Deleting an "active" prompt (the one currently live in production output)
// is blocked here on purpose — archive/replace it with a new version first,
// so a click can never silently break what the AI agents are generating.
$stmt = $pdo->prepare('SELECT status FROM agent_prompts WHERE id = :id');
$stmt->execute(['id' => $id]);
$status = $stmt->fetchColumn();

if ($status === false) {
    $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Prompt not found.'];
    header('Location: prompt-control.php', true, 302);
    exit;
}

if ($status === 'active') {
    $_SESSION['cms_flash'] = [
        'type'    => 'error',
        'message' => 'Active prompts cannot be deleted directly. Archive it (via a new version) first.',
    ];
    header('Location: prompt-control.php', true, 302);
    exit;
}

$delete = $pdo->prepare('DELETE FROM agent_prompts WHERE id = :id AND status != :active');
$delete->execute(['id' => $id, 'active' => 'active']);

if ($delete->rowCount() < 1) {
    $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Prompt not found or already removed.'];
} else {
    $adminName = $_SESSION['cms_admin_name'] ?? 'admin';
    error_log("[PromptControl] Deleted prompt id={$id} (was {$status}) by {$adminName}");

    $_SESSION['cms_flash'] = ['type' => 'success', 'message' => 'Prompt deleted permanently.'];
}

header('Location: prompt-control.php', true, 302);
exit;
