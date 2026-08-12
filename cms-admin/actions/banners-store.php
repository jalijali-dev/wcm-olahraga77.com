<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/upload.php';

function banners_is_valid_button_url(string $url): bool
{
    if ($url === '') {
        return true;
    }

    if (preg_match('#^https?://#i', $url) === 1) {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) === 1 || str_starts_with($url, '//')) {
        return false;
    }

    if (preg_match('%^#[a-zA-Z0-9._~-]+$%', $url) === 1) {
        return true;
    }

    if (preg_match('%^/#[a-zA-Z0-9._~-]+$%', $url) === 1) {
        return true;
    }

    if (preg_match('#^/[a-zA-Z0-9._~%/-]*$#', $url) === 1) {
        return true;
    }

    return preg_match('#^[a-zA-Z0-9][a-zA-Z0-9._~%/-]*$#', $url) === 1;
}

function banners_datetime_local_to_mysql(?string $value): ?string
{
    $value = $value !== null ? trim($value) : '';
    if ($value === '') {
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $value);
    if (!$dt) {
        return null;
    }
    return $dt->format('Y-m-d H:i:00');
}

/**
 * @param array{desktop_image: string, mobile_image: string} $paths
 * @return array{
 *   paths: array{desktop_image: string, mobile_image: string},
 *   errors: list<string>,
 *   new_files: list<string>,
 *   delete_after: list<string>
 * }
 */
function banners_process_image_uploads(array $paths, string $projectRoot): array
{
    $specs = [
        'desktop_image_file' => [
            'path_field' => 'desktop_image',
            'label'      => 'Desktop image',
            'disk_dir'   => 'uploads/banners',
            'web_prefix' => '/uploads/banners/',
            'max_bytes'  => 5 * 1024 * 1024,
            'extensions' => ['jpg', 'jpeg', 'png', 'webp'],
            'mimes'      => ['image/jpeg', 'image/png', 'image/webp'],
        ],
        'mobile_image_file' => [
            'path_field' => 'mobile_image',
            'label'      => 'Mobile image',
            'disk_dir'   => 'uploads/banners',
            'web_prefix' => '/uploads/banners/',
            'max_bytes'  => 5 * 1024 * 1024,
            'extensions' => ['jpg', 'jpeg', 'png', 'webp'],
            'mimes'      => ['image/jpeg', 'image/png', 'image/webp'],
        ],
    ];

    return cms_process_file_uploads($specs, $paths, $projectRoot);
}

if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== 'banners-store.php') {
    return;
}

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ../pages/banners.php', true, 302);
    exit;
}

$projectRoot = CMS_PROJECT_ROOT;
$redirect = '../pages/banners.php';

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

if ($title === '') {
    $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Title is required.'];
    header('Location: ' . $redirect, true, 302);
    exit;
}
if ($placement === '') {
    $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Placement is required.'];
    header('Location: ' . $redirect, true, 302);
    exit;
}
if ($sortOrderRaw === '' || !is_numeric($sortOrderRaw)) {
    $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Sort order must be a number.'];
    header('Location: ' . $redirect, true, 302);
    exit;
}
if (!banners_is_valid_button_url($buttonUrl)) {
    $_SESSION['cms_flash'] = [
        'type' => 'error',
        'message' => 'Button URL must be empty, a valid http(s) URL, or an internal relative path.',
    ];
    header('Location: ' . $redirect, true, 302);
    exit;
}

$startDate = null;
$endDate = null;
if (!$isAlwaysOn) {
    $startDate = $startDateRaw !== '' ? banners_datetime_local_to_mysql($startDateRaw) : null;
    $endDate = $endDateRaw !== '' ? banners_datetime_local_to_mysql($endDateRaw) : null;

    if ($startDateRaw !== '' && $startDate === null) {
        $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Start date format is invalid.'];
        header('Location: ' . $redirect, true, 302);
        exit;
    }
    if ($endDateRaw !== '' && $endDate === null) {
        $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'End date format is invalid.'];
        header('Location: ' . $redirect, true, 302);
        exit;
    }
    if ($startDate !== null && $endDate !== null && strtotime($endDate) < strtotime($startDate)) {
        $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'End date must be later than start date.'];
        header('Location: ' . $redirect, true, 302);
        exit;
    }
}

$successQuery = null;

$imagePaths = [
    'desktop_image' => '',
    'mobile_image' => '',
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
    header('Location: ' . $redirect, true, 302);
    exit;
}

try {
    $insert = $pdo->prepare(
        'INSERT INTO banners (
            title, subtitle, button_text, button_url, desktop_image, mobile_image,
            placement, sort_order, is_active, start_date, end_date
        ) VALUES (
            :title, :subtitle, :button_text, :button_url, :desktop_image, :mobile_image,
            :placement, :sort_order, :is_active, :start_date, :end_date
        )'
    );
    $insert->execute([
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
    ]);

    $successQuery = 'edit=' . (int) $pdo->lastInsertId();
    $_SESSION['cms_flash'] = ['type' => 'success', 'message' => 'Banner created successfully.'];
} catch (PDOException) {
    foreach ($uploadResult['new_files'] as $uploadedPath) {
        if (is_file($uploadedPath)) {
            @unlink($uploadedPath);
        }
    }
    $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Could not create banner. Please try again.'];
}

header('Location: ' . $redirect . ($successQuery !== null ? '?' . $successQuery : ''), true, 302);
exit;
