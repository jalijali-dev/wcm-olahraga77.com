<?php
declare(strict_types=1);

/**
 * /ad-click.php?id={ad_id} — click-through tracker for wpm_render_ad_slot()
 * (includes/site-bootstrap.php). Increments `advertisements.clicks`, then
 * 302-redirects to the ad's target_url. Falls back to the homepage if the
 * ad is missing/inactive or has no target_url, rather than 404ing on a
 * stale/cached ad link.
 */

require_once __DIR__ . '/includes/site-bootstrap.php';

$id = (int) ($_GET['id'] ?? 0);
$targetUrl = null;

if ($id > 0) {
    try {
        $stmt = $pdo->prepare('SELECT target_url FROM advertisements WHERE id = :id AND is_active = 1 LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row && trim((string) $row['target_url']) !== '') {
            $targetUrl = trim((string) $row['target_url']);
            $pdo->prepare('UPDATE advertisements SET clicks = clicks + 1 WHERE id = :id')->execute(['id' => $id]);
        }
    } catch (Throwable $e) {
        // fall through to homepage redirect below
    }
}

header('Location: ' . ($targetUrl ?? wpm_base_url('/')), true, 302);
exit;
