<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ../pages/banners.php', true, 302);
    exit;
}

$projectRoot = CMS_PROJECT_ROOT;
$deleteId = (int) ($_POST['id'] ?? 0);
$redirect = '../pages/banners.php';
$bannersWebPrefix = '/uploads/banners/';
$bannersDiskDir = $projectRoot . '/uploads/banners';

if ($deleteId <= 0) {
    $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Invalid banner.'];
    header('Location: ' . $redirect, true, 302);
    exit;
}

$imagePaths = [];

try {
    $fetch = $pdo->prepare(
        'SELECT desktop_image, mobile_image FROM banners WHERE id = :id LIMIT 1'
    );
    $fetch->execute(['id' => $deleteId]);
    $row = $fetch->fetch();
    if (is_array($row)) {
        $imagePaths[] = trim((string) ($row['desktop_image'] ?? ''));
        $imagePaths[] = trim((string) ($row['mobile_image'] ?? ''));
    }
} catch (PDOException) {
    $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Could not load banner. Please try again.'];
    header('Location: ' . $redirect, true, 302);
    exit;
}

$delete = $pdo->prepare('DELETE FROM banners WHERE id = :id');
$delete->execute(['id' => $deleteId]);

if ($delete->rowCount() < 1) {
    $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Banner not found or already deleted.'];
    header('Location: ' . $redirect, true, 302);
    exit;
}

$bannersDiskReal = realpath($bannersDiskDir);
if ($bannersDiskReal !== false) {
    foreach ($imagePaths as $webPath) {
        if ($webPath === '' || !str_starts_with($webPath, $bannersWebPrefix)) {
            continue;
        }

        $diskPath = $projectRoot . '/' . ltrim($webPath, '/');
        $diskReal = realpath($diskPath);
        if ($diskReal === false || !is_file($diskReal)) {
            continue;
        }
        if (!str_starts_with($diskReal, $bannersDiskReal . DIRECTORY_SEPARATOR)) {
            continue;
        }

        @unlink($diskReal);
    }
}

$_SESSION['cms_flash'] = ['type' => 'success', 'message' => 'Banner deleted successfully.'];
header('Location: ' . $redirect, true, 302);
exit;
