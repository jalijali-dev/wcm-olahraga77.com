<?php
declare(strict_types=1);

/**
 * Public endpoint — click-through redirect for ads. The front-end points
 * an ad's link at this file instead of the raw target URL, so every click
 * is counted before the visitor is forwarded on. No admin auth (visitors
 * trigger this directly by clicking).
 *
 * GET /cms-admin/actions/ad-click.php?id=123
 */

require_once dirname(__DIR__) . '/config/database.php';

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    die('Invalid ad id.');
}

$targetUrl = null;
try {
    $stmt = $pdo->prepare('SELECT target_url FROM advertisements WHERE id = :id AND is_active = 1 LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    if ($row !== false && !empty($row['target_url'])) {
        $targetUrl = (string) $row['target_url'];
        $pdo->prepare('UPDATE advertisements SET clicks = clicks + 1 WHERE id = :id')->execute(['id' => $id]);
    }
} catch (Throwable $e) {
    // Table may not exist yet — fall through to the safe default redirect below.
}

// Only ever redirect to an http(s) URL we read from our own database — never
// to a value taken directly from the request, so this can't be abused as an
// open redirect.
if ($targetUrl !== null && preg_match('#^https?://#i', $targetUrl)) {
    header('Location: ' . $targetUrl, true, 302);
    exit;
}

// No valid target — send the visitor home instead of a dead end.
header('Location: ../../index.php', true, 302);
exit;
