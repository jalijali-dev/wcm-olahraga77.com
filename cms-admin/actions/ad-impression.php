<?php
declare(strict_types=1);

/**
 * Public endpoint — increments an ad's impression counter.
 * Called from the front-end (fetch/beacon) whenever an ad unit renders.
 * Intentionally does NOT require admin auth (visitors trigger this), but
 * is read-light and rate-impact-limited to a single UPDATE per call.
 */

require_once dirname(__DIR__) . '/config/database.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid ad id']);
    exit;
}

try {
    $stmt = $pdo->prepare('UPDATE advertisements SET impressions = impressions + 1 WHERE id = :id AND is_active = 1');
    $stmt->execute(['id' => $id]);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    // Table may not exist yet on an older deploy — fail quietly, never break the page.
    echo json_encode(['ok' => false]);
}
