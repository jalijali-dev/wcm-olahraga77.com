<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/banners-store.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ../pages/banners.php', true, 302);
    exit;
}

$projectRoot = CMS_PROJECT_ROOT;
$redirect = '../pages/banners.php';

$updateId = (int) ($_POST['id'] ?? 0);
if ($updateId <= 0) {
    $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Invalid banner.'];
    header('Location: ' . $redirect, true, 302);
    exit;
}

$title = trim((string) ($_POST['title'] ?? ''));
$subtitle = trim((string) ($_POST['subtitle'] ?? ''));
$buttonText = trim((string) ($_POST['button_text'] ?? ''));
$buttonUrl = trim((string) ($_POST['button_url'] ?? ''));
$placement = trim((string) ($_POST['placement'] ?? ''));
$sortOrderRaw = trim((string) ($_POST['sort_order'] ?? '0'));
$isActive = (int) ($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
$isAlwaysOn = (int) ($_POST['is_always_on'] ?? 0) === 1;
$startDateRaw = trim((string) ($_POST['start_date'] ?? ''));
$endDateRaw = trim((string) ($_POST['end_date'] ?? ''));

$errorQuery = 'edit=' . $updateId;

if ($title === '') {
    $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Title is required.'];
    header('Location: ' . $redirect . '?' . $errorQuery, true, 302);
    exit;
}
if ($placement === '') {
    $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Placement is required.'];
    header('Location: ' . $redirect . '?' . $errorQuery, true, 302);
    exit;
}
if ($sortOrderRaw === '' || !is_numeric($sortOrderRaw)) {
    $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Sort order must be a number.'];
    header('Location: ' . $redirect . '?' . $errorQuery, true, 302);
    exit;
}
if (!banners_is_valid_button_url($buttonUrl)) {
    $_SESSION['cms_flash'] = [
        'type' => 'error',
        'message' => 'Button URL must be empty, a valid http(s) URL, or an internal relative path.',
    ];
    header('Location: ' . $redirect . '?' . $errorQuery, true, 302);
    exit;
}

$startDate = null;
$endDate = null;
if (!$isAlwaysOn) {
    $startDate = $startDateRaw !== '' ? banners_datetime_local_to_mysql($startDateRaw) : null;
    $endDate = $endDateRaw !== '' ? banners_datetime_local_to_mysql($endDateRaw) : null;

    if ($startDateRaw !== '' && $startDate === null) {
        $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Start date format is invalid.'];
        header('Location: ' . $redirect . '?' . $errorQuery, true, 302);
        exit;
    }
    if ($endDateRaw !== '' && $endDate === null) {
        $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'End date format is invalid.'];
        header('Location: ' . $redirect . '?' . $errorQuery, true, 302);
        exit;
    }
    if ($startDate !== null && $endDate !== null && strtotime($endDate) < strtotime($startDate)) {
        $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'End date must be later than start date.'];
        header('Location: ' . $redirect . '?' . $errorQuery, true, 302);
        exit;
    }
}

try {
    $existing = $pdo->prepare(
        'SELECT id, desktop_image, mobile_image FROM banners WHERE id = :id LIMIT 1'
    );
    $existing->execute(['id' => $updateId]);
    $existingRow = $existing->fetch();
} catch (PDOException) {
    $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Could not load banner. Please try again.'];
    header('Location: ' . $redirect, true, 302);
    exit;
}

if (!$existingRow) {
    $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Banner not found.'];
    header('Location: ' . $redirect, true, 302);
    exit;
}

$imagePaths = [
    'desktop_image' => trim((string) ($existingRow['desktop_image'] ?? '')),
    'mobile_image' => trim((string) ($existingRow['mobile_image'] ?? '')),
];

$uploadResult = banners_process_image_uploads($imagePaths, $projectRoot);
$imagePaths = $uploadResult['paths'];

if ($uploadResult['errors'] !== []) {
    foreach ($uploadResult['new_files'] as $uploadedPath) {
        if (is_file($uploadedPath)) {
            @unlink($uploadedPath);
        }
    }
    $_SESSION['cms_flash'] = [
        'type' => 'error',
        'message' => implode(' ', $uploadResult['errors']),
    ];
    header('Location: ' . $redirect . '?' . $errorQuery, true, 302);
    exit;
}

try {
    $update = $pdo->prepare(
        'UPDATE banners
         SET title = :title,
             subtitle = :subtitle,
             button_text = :button_text,
             button_url = :button_url,
             desktop_image = :desktop_image,
             mobile_image = :mobile_image,
             placement = :placement,
             sort_order = :sort_order,
             is_active = :is_active,
             start_date = :start_date,
             end_date = :end_date
         WHERE id = :id'
    );
    $update->execute([
        'title' => $title,
        'subtitle' => $subtitle,
        'button_text' => $buttonText,
        'button_url' => $buttonUrl,
        'desktop_image' => $imagePaths['desktop_image'],
        'mobile_image' => $imagePaths['mobile_image'],
        'placement' => $placement,
        'sort_order' => (int) $sortOrderRaw,
        'is_active' => $isActive,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'id' => $updateId,
    ]);

    foreach (array_unique($uploadResult['delete_after']) as $oldPath) {
        if (is_file($oldPath)) {
            @unlink($oldPath);
        }
    }

    $_SESSION['cms_flash'] = ['type' => 'success', 'message' => 'Banner updated successfully.'];
    header('Location: ' . $redirect . '?' . $errorQuery, true, 302);
    exit;
} catch (PDOException) {
    foreach ($uploadResult['new_files'] as $uploadedPath) {
        if (is_file($uploadedPath)) {
            @unlink($uploadedPath);
        }
    }
    $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Could not update banner. Please try again.'];
    header('Location: ' . $redirect . '?' . $errorQuery, true, 302);
    exit;
}
