<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

/**
 * Persist the admin's selected colour theme in the session (called via
 * fetch() from the navbar theme <select>). auth.php already ran
 * cms_session_start() + cms_verify_csrf() before this file executes.
 */

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$theme = (string) ($_POST['theme'] ?? '');

if (!in_array($theme, cms_valid_themes(), true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid theme']);
    exit;
}

$_SESSION['wpm_theme'] = $theme;

echo json_encode(['ok' => true, 'theme' => $theme]);
