<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';

// Same tier as pages/admins.php — editing accounts/roles is superadmin-only.
cms_require_role(['superadmin']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ../pages/admins.php', true, 302);
    exit;
}

$adminId = (int) ($_POST['admin_id'] ?? 0);
$name = trim((string) ($_POST['name'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$role = trim((string) ($_POST['role'] ?? ''));
$isActive = (int) ($_POST['is_active'] ?? 1);

$redirectEdit = '../pages/admins.php' . ($adminId > 0 ? '?edit=' . $adminId : '');

if ($adminId <= 0 || $name === '' || $email === '' || $role === '') {
    $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Name, email, and role are required.'];
    header('Location: ' . $redirectEdit, true, 302);
    exit;
}

if (!in_array($role, ['superadmin', 'admin', 'editor'], true)) {
    $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Invalid role.'];
    header('Location: ' . $redirectEdit, true, 302);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Please enter a valid email address.'];
    header('Location: ' . $redirectEdit, true, 302);
    exit;
}

if (!in_array($isActive, [0, 1], true)) {
    $isActive = 1;
}

$exists = $pdo->prepare('SELECT admin_id FROM admins WHERE admin_id = :admin_id LIMIT 1');
$exists->execute(['admin_id' => $adminId]);
if (!$exists->fetch()) {
    $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Admin user not found.'];
    header('Location: ../pages/admins.php', true, 302);
    exit;
}

$check = $pdo->prepare('SELECT COUNT(*) FROM admins WHERE email = :email AND admin_id != :admin_id');
$check->execute(['email' => $email, 'admin_id' => $adminId]);
if ((int) $check->fetchColumn() > 0) {
    $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'That email is already in use.'];
    header('Location: ' . $redirectEdit, true, 302);
    exit;
}

if ($password !== '') {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $update = $pdo->prepare(
        'UPDATE admins
         SET name = :name, email = :email, password_hash = :password_hash, role = :role, is_active = :is_active, updated_at = NOW()
         WHERE admin_id = :admin_id'
    );
    $update->execute([
        'name' => $name,
        'email' => $email,
        'password_hash' => $hash,
        'role' => $role,
        'is_active' => $isActive,
        'admin_id' => $adminId,
    ]);
} else {
    $update = $pdo->prepare(
        'UPDATE admins
         SET name = :name, email = :email, role = :role, is_active = :is_active, updated_at = NOW()
         WHERE admin_id = :admin_id'
    );
    $update->execute([
        'name' => $name,
        'email' => $email,
        'role' => $role,
        'is_active' => $isActive,
        'admin_id' => $adminId,
    ]);
}

$_SESSION['cms_flash'] = ['type' => 'success', 'message' => 'Admin user updated successfully.'];
header('Location: ../pages/admins.php', true, 302);
exit;
