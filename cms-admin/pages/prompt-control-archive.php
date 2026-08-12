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

// Only draft prompts can be archived from this action.
// Active prompts are archived automatically when a newer draft is activated.
$stmt = $pdo->prepare(
    'UPDATE agent_prompts
        SET status = :status, archived_at = NOW()
      WHERE id = :id AND status = :curr_status'
);
$stmt->execute([
    'status'      => 'archived',
    'id'          => $id,
    'curr_status' => 'draft',
]);

if ($stmt->rowCount() < 1) {
    $_SESSION['cms_flash'] = [
        'type'    => 'error',
        'message' => 'Prompt not found, not a draft, or already archived.',
    ];
} else {
    $adminName = $_SESSION['cms_admin_name'] ?? 'admin';
    error_log("[PromptControl] Archived draft id={$id} by {$adminName}");

    $_SESSION['cms_flash'] = [
        'type'    => 'success',
        'message' => 'Draft archived successfully.',
    ];
}

header('Location: prompt-control.php', true, 302);
exit;
