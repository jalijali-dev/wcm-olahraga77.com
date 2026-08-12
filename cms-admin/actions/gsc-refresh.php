<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/gsc-api.php';

// "Refresh Data" button on pages/growth-agent.php's Google Search Console
// panel — same tier as growth-agent.php itself (not gsc-settings.php's
// superadmin-only tier, since this doesn't touch the stored credential,
// just triggers a fetch with it).
cms_require_role(['superadmin', 'admin']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ../pages/growth-agent.php', true, 302);
    exit;
}

// force=true — an admin clicking "Refresh Data" wants a fresh pull now,
// not to be told to wait for the 24h staleness window (see
// cms_gsc_fetch_if_stale()).
$result = cms_gsc_fetch_and_cache($pdo, true);

$_SESSION['cms_flash'] = $result['ok']
    ? ['type' => 'success', 'message' => 'GSC data refreshed — ' . $result['rows_written'] . ' rows written.']
    : ['type' => 'error', 'message' => 'Refresh gagal: ' . $result['error']];

header('Location: ../pages/growth-agent.php', true, 302);
exit;
