<?php
declare(strict_types=1);

$pageTitle = $pageTitle ?? 'Dashboard';
$bodyClass = trim('admin-body ' . ($bodyClass ?? ''));

// Theme is stored server-side in the session (see actions/theme-update.php),
// so it's rendered here directly — no FOUC, and it follows the admin across
// every page navigation regardless of localStorage/JS availability.
$cmsCurrentTheme = cms_current_theme();
$cmsCssPath      = dirname(__DIR__) . '/assets/css/admin.css';
$cmsCssVersion   = @filemtime($cmsCssPath) ?: 1;
?>
<!DOCTYPE html>
<html lang="id" data-theme="<?= cms_esc($cmsCurrentTheme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= cms_esc($pageTitle) ?> · <?= cms_esc(CMS_ADMIN_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600&amp;family=Poppins:wght@400;500;600;700&amp;display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= cms_esc(cms_asset_url('css/admin.css')) ?>?v=<?= (int) $cmsCssVersion ?>">
    <link rel="icon" href="<?= cms_esc(cms_favicon_url()) ?>" type="image/png">
</head>
<body class="<?= cms_esc($bodyClass) ?>">
<div class="admin-app" id="admin-app">
