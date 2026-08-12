<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

cms_session_start();

if (empty($_SESSION['cms_admin_id'])) {
    header('Location: ' . cms_login_href());
    exit;
}

// H-1: CSRF enforcement. Placed after the auth check so unauthenticated POSTs
// are redirected to login rather than rejected, and only authenticated
// state-changing requests are validated. Non-POST requests pass through.
cms_verify_csrf();
