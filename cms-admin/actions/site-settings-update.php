<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';

// Same tier as pages/site-settings.php — admin-tier.
cms_require_role(['superadmin', 'admin']);

// See pages/site-settings.php for why these 3 columns/fields exist and why
// telegram_username is VARCHAR(255) (stores a full URL, not a bare username).
cms_ensure_column($pdo, 'site_settings', 'telegram_username', 'VARCHAR(255) NULL AFTER whatsapp_number');
cms_widen_column($pdo, 'site_settings', 'telegram_username', 'VARCHAR(255) NULL AFTER whatsapp_number');
cms_ensure_column($pdo, 'site_settings', 'show_whatsapp_button', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER telegram_username');
cms_ensure_column($pdo, 'site_settings', 'show_telegram_button', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER show_whatsapp_button');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ../pages/site-settings.php', true, 302);
    exit;
}

$projectRoot = CMS_PROJECT_ROOT;
$redirect = '../pages/site-settings.php';

$existingSettings = null;

try {
    $settingsRow = $pdo->query(
        'SELECT id, logo_path, favicon_path, og_image FROM site_settings LIMIT 1'
    )->fetch();
    $existingSettings = is_array($settingsRow) ? $settingsRow : null;
} catch (PDOException) {
    $_SESSION['cms_flash'] = [
        'type' => 'error',
        'message' => 'Could not load current site settings. Please try again.',
    ];
    header('Location: ' . $redirect, true, 302);
    exit;
}

// telegram_username stores a full URL now (see pages/site-settings.php) —
// accept the link with or without a leading "https://" and auto-prefix it
// here so the stored value is always an absolute, clickable URL by the
// time wpm_floating_contact_buttons() renders it verbatim (no more
// "https://t.me/" + username assembly on the consuming side).
$telegramUrl = ltrim(trim((string) ($_POST['telegram_username'] ?? '')), '@');
if ($telegramUrl !== '' && !preg_match('#^https?://#i', $telegramUrl)) {
    $telegramUrl = 'https://' . $telegramUrl;
}
// Soft, non-blocking format check: no strict regex, just flag anything
// that doesn't even resemble a t.me/telegram.me link (e.g. a typo) so the
// admin notices — save proceeds either way (see the flash message below).
$telegramLooksValid = $telegramUrl === '' || (bool) preg_match('#^https?://([^/]*\.)?(t\.me|telegram\.me)/#i', $telegramUrl);

$payload = [
    'site_name' => trim((string) ($_POST['site_name'] ?? '')),
    'site_tagline' => trim((string) ($_POST['site_tagline'] ?? '')),
    'logo_path' => trim((string) ($existingSettings['logo_path'] ?? '')),
    'favicon_path' => trim((string) ($existingSettings['favicon_path'] ?? '')),
    'og_image' => trim((string) ($existingSettings['og_image'] ?? '')),
    'whatsapp_number' => trim((string) ($_POST['whatsapp_number'] ?? '')),
    'telegram_username' => $telegramUrl,
    'show_whatsapp_button' => !empty($_POST['show_whatsapp_button']) ? 1 : 0,
    'show_telegram_button' => !empty($_POST['show_telegram_button']) ? 1 : 0,
    'instagram_url' => trim((string) ($_POST['instagram_url'] ?? '')),
    'email' => trim((string) ($_POST['email'] ?? '')),
    'address' => trim((string) ($_POST['address'] ?? '')),
    'meta_title' => trim((string) ($_POST['meta_title'] ?? '')),
    'meta_description' => trim((string) ($_POST['meta_description'] ?? '')),
    'meta_keywords' => trim((string) ($_POST['meta_keywords'] ?? '')),
    'google_analytics_id' => trim((string) ($_POST['google_analytics_id'] ?? '')),
];

$specs = [
    'logo_file' => [
        'path_field' => 'logo_path',
        'label'      => 'Logo',
        'disk_dir'   => 'uploads/site/logo',
        'web_prefix' => '/uploads/site/logo/',
        'max_bytes'  => 5 * 1024 * 1024,
        'extensions' => ['jpg', 'jpeg', 'png', 'svg', 'webp'],
        'mimes'      => ['image/jpeg', 'image/png', 'image/svg+xml', 'image/webp'],
    ],
    'favicon_file' => [
        'path_field'  => 'favicon_path',
        'label'       => 'Favicon',
        'disk_dir'    => 'uploads/site/favicon',
        'web_prefix'  => '/uploads/site/favicon/',
        'max_bytes'   => 1024 * 1024,
        'extensions'  => ['ico', 'png'],
        'mimes'       => ['image/png', 'image/x-icon', 'image/vnd.microsoft.icon'],
        'extra_mimes' => ['application/octet-stream'], // some finfo builds report .ico as this
    ],
    'og_image_file' => [
        'path_field' => 'og_image',
        'label'      => 'OG image',
        'disk_dir'   => 'uploads/site/seo',
        'web_prefix' => '/uploads/site/seo/',
        'max_bytes'  => 5 * 1024 * 1024,
        'extensions' => ['jpg', 'jpeg', 'png', 'webp'],
        'mimes'      => ['image/jpeg', 'image/png', 'image/webp'],
    ],
];

$currentPaths = [
    'logo_path'    => $payload['logo_path'],
    'favicon_path' => $payload['favicon_path'],
    'og_image'     => $payload['og_image'],
];

$uploadResult = cms_process_file_uploads($specs, $currentPaths, $projectRoot);

// Map result back onto the variable names used by the unchanged code below.
$uploadErrors                = $uploadResult['errors'];
$newlyUploadedDiskPaths      = $uploadResult['new_files'];
$pathsToDeleteAfterDbSuccess = $uploadResult['delete_after'];

// Push updated image paths back into the DB payload.
foreach (['logo_path', 'favicon_path', 'og_image'] as $field) {
    $payload[$field] = $uploadResult['paths'][$field];
}

if ($uploadErrors !== []) {
    foreach ($newlyUploadedDiskPaths as $uploadedPath) {
        if (is_file($uploadedPath)) {
            @unlink($uploadedPath);
        }
    }

    $_SESSION['cms_flash'] = [
        'type' => 'error',
        'message' => implode(' ', $uploadErrors),
    ];
    header('Location: ' . $redirect, true, 302);
    exit;
}

try {
    if ($existingSettings !== null) {
        $update = $pdo->prepare(
            'UPDATE site_settings
             SET site_name = :site_name,
                 site_tagline = :site_tagline,
                 logo_path = :logo_path,
                 favicon_path = :favicon_path,
                 og_image = :og_image,
                 whatsapp_number = :whatsapp_number,
                 telegram_username = :telegram_username,
                 show_whatsapp_button = :show_whatsapp_button,
                 show_telegram_button = :show_telegram_button,
                 instagram_url = :instagram_url,
                 email = :email,
                 address = :address,
                 meta_title = :meta_title,
                 meta_description = :meta_description,
                 meta_keywords = :meta_keywords,
                 google_analytics_id = :google_analytics_id,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $update->execute($payload + ['id' => $existingSettings['id']]);
    } else {
        $insert = $pdo->prepare(
            'INSERT INTO site_settings (
                site_name, site_tagline, logo_path, favicon_path, og_image, whatsapp_number,
                telegram_username, show_whatsapp_button, show_telegram_button, instagram_url,
                email, address, meta_title, meta_description, meta_keywords, google_analytics_id,
                created_at, updated_at
            ) VALUES (
                :site_name, :site_tagline, :logo_path, :favicon_path, :og_image, :whatsapp_number,
                :telegram_username, :show_whatsapp_button, :show_telegram_button, :instagram_url,
                :email, :address, :meta_title, :meta_description, :meta_keywords, :google_analytics_id,
                NOW(), NOW()
            )'
        );
        $insert->execute($payload);
    }

    foreach (array_unique($pathsToDeleteAfterDbSuccess) as $oldPath) {
        if (is_file($oldPath)) {
            @unlink($oldPath);
        }
    }

    $_SESSION['cms_flash'] = $telegramLooksValid
        ? ['type' => 'success', 'message' => 'Site settings saved successfully.']
        : [
            'type' => 'info',
            'message' => 'Site settings saved. Catatan: link Telegram ("' . $telegramUrl . '") tidak terlihat seperti format t.me/telegram.me — dicek lagi kalau ini bukan yang dimaksud.',
        ];
} catch (PDOException) {
    foreach ($newlyUploadedDiskPaths as $uploadedPath) {
        if (is_file($uploadedPath)) {
            @unlink($uploadedPath);
        }
    }

    $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Could not save site settings. Please try again.'];
}

header('Location: ' . $redirect, true, 302);
exit;
