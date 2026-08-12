<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';

// Same tier as pages/admins.php — deleting accounts is superadmin-only.
cms_require_role(['superadmin']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ../pages/admins.php', true, 302);
    exit;
}

$adminId = (int) ($_POST['admin_id'] ?? 0);
$currentId = (int) ($_SESSION['cms_admin_id'] ?? 0);

if ($adminId <= 0) {
    $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Invalid admin user.'];
    header('Location: ../pages/admins.php', true, 302);
    exit;
}

if ($adminId === $currentId) {
    $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'You cannot delete your own account while logged in.'];
    header('Location: ../pages/admins.php', true, 302);
    exit;
}

$delete = $pdo->prepare('DELETE FROM admins WHERE admin_id = :admin_id');
$delete->execute(['admin_id' => $adminId]);

if ($delete->rowCount() < 1) {
    $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Admin user not found or already deleted.'];
    header('Location: ../pages/admins.php', true, 302);
    exit;
}

$_SESSION['cms_flash'] = ['type' => 'success', 'message' => 'Admin user deleted successfully.'];
header('Location: ../pages/admins.php', true, 302);
exit;
