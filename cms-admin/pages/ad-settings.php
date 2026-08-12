<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';

// Site-wide configuration is admin-tier — see cms_require_role() in
// functions.php for the full tier breakdown.
cms_require_role(['superadmin', 'admin']);

cms_ensure_table(
    $pdo,
    'ad_settings',
    'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
     ads_enabled TINYINT(1) NOT NULL DEFAULT 1,
     popup_frequency_hours INT UNSIGNED NOT NULL DEFAULT 24,
     sticky_mobile_enabled TINYINT(1) NOT NULL DEFAULT 1,
     show_ad_label TINYINT(1) NOT NULL DEFAULT 1,
     global_header_script TEXT DEFAULT NULL,
     global_footer_script TEXT DEFAULT NULL,
     updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
);
// Multi-format Advertisements (14 Jul 2026) — rotation mode used when
// several active ads tie for the same position/priority (wpm_ad_rotate()
// in includes/site-bootstrap.php). Also added via the auto-migration in
// ads.php; guarded again here since Ad Settings can be opened first.
cms_ensure_column($pdo, 'ad_settings', 'rotation_mode', "ENUM('priority','random','sequential') NOT NULL DEFAULT 'priority' AFTER `show_ad_label`");

// Ensure exactly one settings row exists (singleton pattern, same as site_settings).
$rowCount = (int) $pdo->query('SELECT COUNT(*) FROM ad_settings')->fetchColumn();
if ($rowCount === 0) {
    $pdo->exec('INSERT INTO ad_settings (ads_enabled, popup_frequency_hours, sticky_mobile_enabled, show_ad_label) VALUES (1, 24, 1, 1)');
}

$pageTitle = 'Ad Settings';
$currentNav = 'ad-settings';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'Advertisements', 'href' => cms_nav_href('ads.php')],
    ['label' => 'Ad Settings', 'href' => ''],
];

$selfUrl = 'ad-settings.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $adsEnabled    = !empty($_POST['ads_enabled']) ? 1 : 0;
    $popupHours    = max(1, (int) ($_POST['popup_frequency_hours'] ?? 24));
    $stickyMobile  = !empty($_POST['sticky_mobile_enabled']) ? 1 : 0;
    $showLabel     = !empty($_POST['show_ad_label']) ? 1 : 0;
    $headerScript  = trim((string) ($_POST['global_header_script'] ?? ''));
    $footerScript  = trim((string) ($_POST['global_footer_script'] ?? ''));
    $rotationMode  = in_array($_POST['rotation_mode'] ?? '', ['priority', 'random', 'sequential'], true) ? $_POST['rotation_mode'] : 'priority';

    $update = $pdo->prepare(
        'UPDATE ad_settings SET
            ads_enabled = :ads_enabled,
            popup_frequency_hours = :popup_frequency_hours,
            sticky_mobile_enabled = :sticky_mobile_enabled,
            show_ad_label = :show_ad_label,
            rotation_mode = :rotation_mode,
            global_header_script = :global_header_script,
            global_footer_script = :global_footer_script
         ORDER BY id ASC LIMIT 1'
    );
    $update->execute([
        'ads_enabled'            => $adsEnabled,
        'popup_frequency_hours'  => $popupHours,
        'sticky_mobile_enabled'  => $stickyMobile,
        'show_ad_label'          => $showLabel,
        'rotation_mode'          => $rotationMode,
        'global_header_script'   => $headerScript !== '' ? $headerScript : null,
        'global_footer_script'   => $footerScript !== '' ? $footerScript : null,
    ]);

    $_SESSION['cms_flash'] = ['type' => 'success', 'message' => 'Ad settings saved.'];
    header('Location: ' . $selfUrl, true, 302);
    exit;
}

$alerts = [];
if (isset($_SESSION['cms_flash']) && is_array($_SESSION['cms_flash'])) {
    $alerts[] = $_SESSION['cms_flash'];
    unset($_SESSION['cms_flash']);
}

$settings = $pdo->query('SELECT * FROM ad_settings ORDER BY id ASC LIMIT 1')->fetch() ?: [];
$val = static function (string $key) use ($settings): string {
    return (string) ($settings[$key] ?? '');
};

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/sidebar.php';
require dirname(__DIR__) . '/includes/navbar.php';
require dirname(__DIR__) . '/includes/breadcrumb.php';
require dirname(__DIR__) . '/includes/alerts.php';
?>
<section class="admin-stack">
    <div class="toolbar">
        <div class="toolbar__left">
            <h2 class="section-title">Ad Settings</h2>
            <p class="section-lead">Global ad behavior — applies across all ad units.</p>
        </div>
        <div class="toolbar__right">
            <a class="admin-btn admin-btn--secondary" href="ads.php">← Back to Ads</a>
        </div>
    </div>

    <div class="panel">
        <form class="form-grid" method="post" action="<?= cms_esc($selfUrl) ?>">
            <?= cms_csrf_field() ?>
            <label class="field field--checkbox">
                <input type="checkbox" name="ads_enabled" value="1"<?= (int) ($settings['ads_enabled'] ?? 1) === 1 ? ' checked' : '' ?>>
                <span class="field--checkbox__text">
                    <span class="field--checkbox__title">Ads enabled site-wide</span>
                </span>
            </label>
            <label class="field field--checkbox">
                <input type="checkbox" name="show_ad_label" value="1"<?= (int) ($settings['show_ad_label'] ?? 1) === 1 ? ' checked' : '' ?>>
                <span class="field--checkbox__text">
                    <span class="field--checkbox__title">Show "Iklan/Sponsored" label on ad units</span>
                </span>
            </label>
            <label class="field field--checkbox">
                <input type="checkbox" name="sticky_mobile_enabled" value="1"<?= (int) ($settings['sticky_mobile_enabled'] ?? 1) === 1 ? ' checked' : '' ?>>
                <span class="field--checkbox__text">
                    <span class="field--checkbox__title">Sticky bottom mobile ad enabled</span>
                </span>
            </label>
            <label class="field">Popup frequency (hours between showing the same visitor a popup ad)
                <input type="number" name="popup_frequency_hours" min="1" value="<?= (int) ($settings['popup_frequency_hours'] ?? 24) ?>">
            </label>
            <label class="field">Ad rotation mode <small style="opacity:.7;">(used when multiple active ads share the same position and priority/sort order)</small>
                <?php $curRotation = $val('rotation_mode') ?: 'priority'; ?>
                <select name="rotation_mode">
                    <option value="priority"<?= $curRotation === 'priority' ? ' selected' : '' ?>>Priority (lowest sort order wins, ties broken by newest)</option>
                    <option value="random"<?= $curRotation === 'random' ? ' selected' : '' ?>>Random (tied ads shown in random order)</option>
                    <option value="sequential"<?= $curRotation === 'sequential' ? ' selected' : '' ?>>Sequential (tied ads rotate based on impression count)</option>
                </select>
            </label>
            <label class="field" style="grid-column: 1 / -1;">Global header script <small style="opacity:.7;">(e.g. Google AdSense verification/head tag — injected on every page)</small>
                <textarea name="global_header_script" rows="4" style="font-family:monospace;font-size:12px;"><?= cms_esc($val('global_header_script')) ?></textarea>
            </label>
            <label class="field" style="grid-column: 1 / -1;">Global footer script
                <textarea name="global_footer_script" rows="4" style="font-family:monospace;font-size:12px;"><?= cms_esc($val('global_footer_script')) ?></textarea>
            </label>
            <div class="form-grid__actions">
                <button type="submit" class="admin-btn admin-btn--primary">Save settings</button>
            </div>
        </form>
    </div>
</section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
