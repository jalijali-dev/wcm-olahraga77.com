<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

$pageTitle = 'Dashboard';
$currentNav = 'dashboard';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => ''],
];

require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar.php';
require __DIR__ . '/includes/navbar.php';
require __DIR__ . '/includes/breadcrumb.php';
require __DIR__ . '/includes/alerts.php';

$cmsDashboardFragment = true;
require __DIR__ . '/pages/dashboard.php';

require __DIR__ . '/includes/footer.php';
