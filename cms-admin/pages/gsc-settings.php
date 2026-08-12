<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';
require_once dirname(__DIR__) . '/includes/ai-helpers.php';
require_once dirname(__DIR__) . '/includes/gsc-api.php';

// Ported 27 Jul 2026 from the sibling SagaCrypto project's GSC Settings page
// — see gsc-api.php's header comment for the full port rationale.
//
// Holds a raw service account private key — superadmin-only, same tier as
// ai-credentials.php/crypto-api.php (pages that store raw credentials).
// Deliberately its own page rather than folded into growth-agent.php
// (admin+ tier) for the same reason ai-credentials.php is split out from
// ai-sandbox.php.
cms_require_role(['superadmin']);

$gsc_schemaError = null;
try {
    cms_gsc_ensure_schema($pdo);
} catch (Throwable $e) {
    $gsc_schemaError = $e->getMessage();
}

$pageTitle = 'GSC Settings';
$currentNav = 'gsc-settings';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'AI Management', 'href' => ''],
    ['label' => 'GSC Settings', 'href' => ''],
];

$selfUrl = 'gsc-settings.php';

$gsc_redirect = static function (string $message, string $type = 'success') use ($selfUrl): void {
    $_SESSION['cms_flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . $selfUrl, true, 302);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'connect') {
        $rawJson = trim((string) ($_POST['service_account_json'] ?? ''));
        if ($rawJson === '') {
            $gsc_redirect('Paste dulu JSON service account-nya.', 'error');
        }

        $decoded = json_decode($rawJson, true);
        if (!is_array($decoded) || !isset($decoded['client_email'], $decoded['private_key'])) {
            $gsc_redirect('Itu bukan JSON service account yang valid — client_email/private_key tidak ditemukan.', 'error');
        }

        $test = cms_gsc_test_service_account($decoded);
        if (!$test['ok']) {
            $gsc_redirect('Test koneksi gagal: ' . $test['message'], 'error');
        }

        $pdo->prepare(
            'UPDATE gsc_settings
                SET service_account_email = :email, service_account_json_enc = :json_enc, updated_at = NOW()
              ORDER BY id ASC LIMIT 1'
        )->execute([
            'email'    => $test['email'],
            'json_enc' => cms_ai_encrypt($rawJson),
        ]);

        $gsc_redirect('Service account terhubung sebagai ' . $test['email'] . '. Sekarang pilih property di bawah.');
    }

    if ($action === 'select_property') {
        $siteUrl = trim((string) ($_POST['site_url'] ?? ''));
        if ($siteUrl === '') {
            $gsc_redirect('Pilih salah satu property dari daftar.', 'error');
        }

        $pdo->prepare(
            'UPDATE gsc_settings SET site_url = :site_url, is_active = 1, updated_at = NOW() ORDER BY id ASC LIMIT 1'
        )->execute(['site_url' => $siteUrl]);

        $gsc_redirect('Terhubung ke ' . $siteUrl . '. Growth Agent akan mulai menarik data saat pengecekan berikutnya (tiap 24 jam, atau klik "🔄 Fetch GSC Data" di halaman Growth Agent).');
    }

    if ($action === 'disconnect') {
        $pdo->exec(
            'UPDATE gsc_settings
                SET service_account_email = NULL, service_account_json_enc = NULL,
                    site_url = NULL, is_active = 0, updated_at = NOW()'
        );
        $gsc_redirect('Terputus. Data cache yang sudah ada di gsc_query_data tetap disimpan sampai Anda connect ulang dan refresh.');
    }

    $gsc_redirect('Aksi tidak dikenal.', 'error');
}

$alerts = [];
if (isset($_SESSION['cms_flash']) && is_array($_SESSION['cms_flash'])) {
    $alerts[] = $_SESSION['cms_flash'];
    unset($_SESSION['cms_flash']);
}
if ($gsc_schemaError !== null) {
    $alerts[] = ['type' => 'error', 'message' => 'Setup GSC tidak bisa jalan otomatis: ' . $gsc_schemaError];
}

$settings = cms_gsc_get_settings($pdo);
$hasCredential = !empty($settings['service_account_json_enc']);
$hasProperty = !empty($settings['site_url']);

$availableSites = [];
$sitesError = null;
if ($hasCredential && !$hasProperty) {
    $sitesResult = cms_gsc_list_sites($pdo);
    if ($sitesResult['ok']) {
        $availableSites = $sitesResult['sites'];
    } else {
        $sitesError = $sitesResult['error'];
    }
}

// "Connected but always 0 rows" is most often caused by picking the wrong
// property variant (see cms_gsc_site_url_label()) — flag it here too if
// BOTH a Domain and a URL-prefix property showed up for the same
// underlying site, not just at pick-time.
$hasDomainAndPrefixVariant = false;
if (count($availableSites) > 1) {
    $hasDomain = false;
    $hasPrefix = false;
    foreach ($availableSites as $site) {
        if (str_starts_with($site, 'sc-domain:')) {
            $hasDomain = true;
        } else {
            $hasPrefix = true;
        }
    }
    $hasDomainAndPrefixVariant = $hasDomain && $hasPrefix;
}

// Recent diagnostics — includes both actual request failures and the
// "fetch OK but 0 rows" case logged by cms_gsc_fetch_and_cache(), so this
// is the one place to check when data isn't showing up.
$recentDiagnostics = [];
if ($hasCredential) {
    try {
        $diagStmt = $pdo->prepare("SELECT message, created_at FROM api_error_log WHERE source = 'gsc' ORDER BY id DESC LIMIT 10");
        $diagStmt->execute();
        $recentDiagnostics = $diagStmt->fetchAll();
    } catch (Throwable $e) {
        $recentDiagnostics = [];
    }
}

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/sidebar.php';
require dirname(__DIR__) . '/includes/navbar.php';
require dirname(__DIR__) . '/includes/breadcrumb.php';
require dirname(__DIR__) . '/includes/alerts.php';
?>
<section class="admin-stack">
    <div class="toolbar">
        <div class="toolbar__left">
            <h2 class="section-title">GSC Settings</h2>
            <p class="section-lead">Hubungkan property Google Search Console lewat service account, supaya Growth Agent bisa menarik data performa pencarian yang sungguhan.</p>
        </div>
        <div class="toolbar__right">
            <a class="admin-btn admin-btn--secondary" href="<?= cms_esc(cms_nav_href('growth-agent.php')) ?>">&larr; Growth Agent</a>
        </div>
    </div>

    <?php if (!$hasCredential) : ?>
        <div class="panel">
            <div class="panel__head">
                <h3 class="panel__title">Langkah 1 — Hubungkan service account</h3>
            </div>
            <form class="form-stack" method="post" action="<?= cms_esc($selfUrl) ?>">
                <?= cms_csrf_field() ?>
                <input type="hidden" name="action" value="connect">
                <label class="field">Service account JSON
                    <textarea name="service_account_json" rows="10" placeholder='{"type": "service_account", "client_email": "...", "private_key": "-----BEGIN PRIVATE KEY-----...", ...}' required style="font-family:monospace;font-size:12px;"></textarea>
                    <small class="field__hint">
                        Buat service account di Google Cloud Console, aktifkan Search Console API, download key JSON-nya,
                        lalu tambahkan email service account itu sebagai <strong>User</strong> (cukup akses read/Restricted)
                        di properti Search Console yang mau dihubungkan — tanpa langkah ini, koneksi akan gagal /
                        daftar propertinya kosong. Paste seluruh isi file JSON di sini.
                    </small>
                </label>
                <button type="submit" class="admin-btn admin-btn--primary">Test &amp; Connect</button>
            </form>
        </div>
    <?php elseif (!$hasProperty) : ?>
        <div class="panel">
            <div class="panel__head">
                <h3 class="panel__title">Langkah 2 — Pilih property</h3>
                <span class="panel__meta">Terhubung sebagai <?= cms_esc((string) $settings['service_account_email']) ?></span>
            </div>
            <?php if ($sitesError !== null) : ?>
                <div class="admin-alert admin-alert--error" style="margin:0 0 16px;">
                    Tidak bisa memuat daftar property: <?= cms_esc($sitesError) ?>
                    — pastikan service account sudah ditambahkan sebagai User di property Search Console yang dimaksud.
                </div>
            <?php elseif ($availableSites === []) : ?>
                <p class="muted" style="margin:0 0 16px;">
                    Tidak ada property yang bisa diakses service account ini. Tambahkan
                    <code><?= cms_esc((string) $settings['service_account_email']) ?></code>
                    sebagai User di Search Console property Anda, lalu reload halaman ini.
                </p>
            <?php else : ?>
                <?php if ($hasDomainAndPrefixVariant) : ?>
                    <div class="admin-alert admin-alert--info" style="margin:0 0 16px;">
                        Ada lebih dari satu tipe property terdaftar untuk service account ini — <strong>Domain property</strong>
                        (<code>sc-domain:...</code>) dan <strong>URL-prefix property</strong> (<code>https://...</code>) itu dua
                        entri terpisah di Search Console, dan cuma salah satunya yang biasanya benar-benar punya riwayat data
                        ter-track. Kalau ragu, pilih yang <strong>Domain property</strong> — itu otomatis mencakup semua varian
                        (http/https, www/non-www, subdomain) jadi lebih kecil kemungkinan salah pilih dan hasilnya 0 rows.
                    </div>
                <?php endif; ?>
                <form class="form-stack" method="post" action="<?= cms_esc($selfUrl) ?>">
                    <?= cms_csrf_field() ?>
                    <input type="hidden" name="action" value="select_property">
                    <label class="field">Property
                        <select name="site_url" required>
                            <option value="">— pilih property —</option>
                            <?php foreach ($availableSites as $site) : ?>
                                <option value="<?= cms_esc($site) ?>"><?= cms_esc($site) ?> — <?= cms_esc(cms_gsc_site_url_label($site)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="field__hint">Kalau tampilan pilihan kepanjangan, cek isi lengkapnya di kode sumber URL — yang penting bagian sebelum " — " itu value persis yang tersimpan.</small>
                    </label>
                    <button type="submit" class="admin-btn admin-btn--primary">Connect property</button>
                </form>
            <?php endif; ?>
            <form method="post" action="<?= cms_esc($selfUrl) ?>" style="margin-top:16px;" onsubmit="return confirm('Putuskan service account ini? Anda perlu paste ulang JSON-nya kalau mau connect lagi.');">
                <?= cms_csrf_field() ?>
                <input type="hidden" name="action" value="disconnect">
                <button type="submit" class="admin-btn admin-btn--ghost">Disconnect service account</button>
            </form>
        </div>
    <?php else : ?>
        <div class="panel">
            <div class="panel__head">
                <h3 class="panel__title">Terhubung</h3>
                <span class="pill pill--ok">Aktif</span>
            </div>
            <table class="admin-table">
                <tbody>
                    <tr><td class="muted" style="width:180px;">Service account</td><td><?= cms_esc((string) $settings['service_account_email']) ?></td></tr>
                    <tr><td class="muted">Property</td><td><code><?= cms_esc((string) $settings['site_url']) ?></code></td></tr>
                    <tr><td class="muted">Fetch Terakhir</td>
                        <td>
                            <?php if (!empty($settings['last_fetch_at'])) : ?>
                                <span class="pill pill--<?= $settings['last_fetch_status'] === 'success' ? 'ok' : 'warn' ?>"><?= cms_esc((string) $settings['last_fetch_status']) ?></span>
                                <?= cms_esc((string) $settings['last_fetch_message']) ?>
                                (<?= (int) $settings['last_fetch_rows'] ?> baris, <?= cms_esc((string) $settings['last_fetch_at']) ?>)
                            <?php else : ?>
                                <span class="muted">Belum pernah fetch — akan otomatis jalan saat halaman Growth Agent dibuka.</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr><td class="muted">Rentang Lookback</td><td><?= (int) $settings['fetch_lookback_days'] ?> hari per fetch</td></tr>
                </tbody>
            </table>
            <form method="post" action="<?= cms_esc($selfUrl) ?>" style="margin-top:16px;" onsubmit="return confirm('Putuskan koneksi GSC? Data cache yang sudah ada tetap disimpan, tapi tidak akan ada fetch baru sampai disambungkan lagi.');">
                <?= cms_csrf_field() ?>
                <input type="hidden" name="action" value="disconnect">
                <button type="submit" class="admin-btn admin-btn--ghost">Disconnect</button>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($hasCredential) : ?>
        <div class="panel">
            <div class="panel__head">
                <h3 class="panel__title">Diagnostik Terbaru</h3>
                <span class="panel__meta"><?= count($recentDiagnostics) ?> tercatat</span>
            </div>
            <p class="section-lead" style="padding:0 20px;">
                Termasuk permintaan yang benar-benar gagal DAN kasus "fetch sukses tapi 0 rows" (bukan error, tapi tetap
                dicatat di sini lengkap dengan request/response mentahnya) — cek di sini dulu kalau data tidak muncul-muncul.
            </p>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Pesan</th><th style="width:160px;">Waktu</th></tr></thead>
                    <tbody>
                        <?php if ($recentDiagnostics === []) : ?>
                            <tr><td colspan="2" class="muted">Belum ada diagnostik tercatat.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($recentDiagnostics as $diag) : ?>
                            <tr>
                                <td><pre style="white-space:pre-wrap;word-break:break-word;margin:0;font-size:12px;font-family:monospace;"><?= cms_esc((string) $diag['message']) ?></pre></td>
                                <td class="muted"><code><?= cms_esc((string) $diag['created_at']) ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</section>
<?php
require dirname(__DIR__) . '/includes/footer.php';
