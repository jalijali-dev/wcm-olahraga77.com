<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';

// Same tier as pages/admins.php — creating accounts is superadmin-only.
cms_require_role(['superadmin']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ../pages/admins.php', true, 302);
    exit;
}

$name = trim((string) ($_POST['name'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$role = trim((string) ($_POST['role'] ?? ''));
$isActive = (int) ($_POST['is_active'] ?? 1);

if ($name === '' || $email === '' || $password === '' || $role === '') {
    $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Name, email, password, and role are required.'];
    header('Location: ../pages/admins.php', true, 302);
    exit;
}

// Defense in depth — must match the `admins.role` DB enum exactly, even if
// the request didn't come through the admins.php <select> (e.g. a replayed
// or hand-crafted POST).
if (!in_array($role, ['superadmin', 'admin', 'editor'], true)) {
    $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Invalid role.'];
    header('Location: ../pages/admins.php', true, 302);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Please enter a valid email address.'];
    header('Location: ../pages/admins.php', true, 302);
    exit;
}

if (!in_array($isActive, [0, 1], true)) {
    $isActive = 1;
}

$check = $pdo->prepare('SELECT COUNT(*) FROM admins WHERE email = :email');
$check->execute(['email' => $email]);
if ((int) $check->fetchColumn() > 0) {
    $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'That email is already in use.'];
    header('Location: ../pages/admins.php', true, 302);
    exit;
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$insert = $pdo->prepare(
    'INSERT INTO admins (name, email, password_hash, role, is_active, created_at, updated_at)
     VALUES (:name, :email, :password_hash, :role, :is_active, NOW(), NOW())'
);
$insert->execute([
    'name' => $name,
    'email' => $email,
    'password_hash' => $hash,
    'role' => $role,
    'is_active' => $isActive,
]);

$_SESSION['cms_flash'] = ['type' => 'success', 'message' => 'Admin user created successfully.'];
header('Location: ../pages/admins.php', true, 302);
exit;
