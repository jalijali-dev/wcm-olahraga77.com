<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';

// Site-wide configuration is admin-tier — see cms_require_role() in
// functions.php for the full tier breakdown.
cms_require_role(['superadmin', 'admin']);

// Floating contact buttons (24 Jul 2026, link format revised 27 Jul 2026)
// — telegram_username stores a full t.me link (public username OR private
// invite link, e.g. "https://t.me/sagagoal" or "https://t.me/+uUBp0t7Zt6JmNWM1"),
// not a bare username: private groups/channels only have an invite-link
// form (t.me/+xxxxx), which has no equivalent "@username" to deep-link by,
// so this column now stores whatever the admin pastes and renders it
// as-is (see wpm_floating_contact_buttons() in site-bootstrap.php for the
// consuming side — it must NOT prefix this value with "https://t.me/"
// anymore, since the stored value is already a full URL). Column name
// kept as telegram_username for backward compatibility (avoids another
// migration) even though it no longer holds a bare username. Widened to
// VARCHAR(255) (was VARCHAR(50), sized for a bare username) since a full
// URL needs more room; cms_widen_column() upgrades any pre-existing
// installs still on the old width.
// show_*_button lets an admin turn a button off entirely (not rendered at
// all, not just CSS-hidden) even if the link field still has a value saved.
cms_ensure_column($pdo, 'site_settings', 'telegram_username', 'VARCHAR(255) NULL AFTER whatsapp_number');
cms_widen_column($pdo, 'site_settings', 'telegram_username', 'VARCHAR(255) NULL AFTER whatsapp_number');
cms_ensure_column($pdo, 'site_settings', 'show_whatsapp_button', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER telegram_username');
cms_ensure_column($pdo, 'site_settings', 'show_telegram_button', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER show_whatsapp_button');

$pageTitle = 'Site Settings';
$currentNav = 'site-settings';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'Site Settings', 'href' => ''],
];

$alerts = [];
if (isset($_SESSION['cms_flash']) && is_array($_SESSION['cms_flash'])) {
    $alerts[] = $_SESSION['cms_flash'];
    unset($_SESSION['cms_flash']);
}

$stmt = $pdo->query('SELECT * FROM site_settings LIMIT 1');
$settings = $stmt->fetch() ?: [];

$val = static function (string $key) use ($settings): string {
    return (string) ($settings[$key] ?? '');
};

/**
 * @param list<string> $remarks
 */
$renderSiteSettingsImageField = static function (
    string $label,
    string $fieldName,
    string $fileInputName,
    string $uploadDestination,
    string $accept,
    array $remarks
) use ($val): void {
    $currentPath = $val($fieldName);
    $previewUrl = app_asset_preview_url($currentPath);
    $hasPreview = $previewUrl !== '';
    ?>
    <div class="cms-path-upload" data-accept="<?= cms_esc($accept); ?>">
        <span class="field cms-path-upload__label"><?= cms_esc($label); ?></span>
        <p class="cms-path-upload__hint">Upload destination: <code><?= cms_esc($uploadDestination); ?></code></p>
        <div class="cms-path-upload__box">
            <img
                class="cms-path-upload__preview"
                alt="<?= cms_esc($label . ' preview'); ?>"
                <?= $hasPreview ? 'src="' . cms_esc($previewUrl) . '"' : 'hidden'; ?>
            >
            <input type="file" name="<?= cms_esc($fileInputName); ?>" class="cms-path-upload__file" accept="<?= cms_esc($accept); ?>">
        </div>
        <label class="field"><?= cms_esc($label); ?> path
            <input
                type="text"
                name="<?= cms_esc($fieldName); ?>"
                class="cms-path-upload__input"
                value="<?= cms_esc($currentPath); ?>"
                readonly
            >
        </label>
        <div class="cms-field-hint" role="note">
            <ul>
                <?php foreach ($remarks as $remark): ?>
                    <li><?= cms_esc($remark); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php
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
            <h2 class="section-title">Site settings</h2>
            <p class="section-lead">Global branding, contact channels, and default SEO — not persisted.</p>
        </div>
    </div>
    <?php require dirname(__DIR__) . '/includes/module-note.php'; ?>
    <style>
/* preview height override — admin.css default is 120px */
.cms-path-upload__preview { max-height: 140px; }
</style>
    <form method="post" action="../actions/site-settings-update.php" enctype="multipart/form-data">
        <?= cms_csrf_field() ?>
        <div class="admin-grid admin-grid--2">
            <div class="panel">
                <div class="panel__head">
                    <h3 class="panel__title">General &amp; contact</h3>
                </div>
                <div class="form-stack">
                    <label class="field">Site name
                        <input type="text" name="site_name" value="<?= cms_esc($val('site_name')) ?>">
                    </label>
                    <label class="field">Site tagline
                        <input type="text" name="site_tagline" value="<?= cms_esc($val('site_tagline')) ?>">
                    </label>
                    <?php $renderSiteSettingsImageField(
                        'Logo',
                        'logo_path',
                        'logo_file',
                        '/uploads/site/logo/',
                        'image/jpeg,image/png,image/svg+xml,image/webp,.jpg,.jpeg,.png,.svg,.webp',
                        [
                            'Allowed: JPG, PNG, SVG, WEBP',
                            'Recommended: 300×100 px',
                            'Max size: 5 MB',
                        ]
                    ); ?>
                    <?php $renderSiteSettingsImageField(
                        'Favicon',
                        'favicon_path',
                        'favicon_file',
                        '/uploads/site/favicon/',
                        'image/png,image/x-icon,.ico,.png',
                        [
                            'Allowed: ICO, PNG',
                            'Recommended: 32×32 px',
                            'Max size: 1 MB',
                        ]
                    ); ?>
                    <label class="field">WhatsApp number
                        <input type="text" name="whatsapp_number" value="<?= cms_esc($val('whatsapp_number')) ?>">
                        <span class="field__hint">Format: kode negara tanpa "+" atau "0" di depan, contoh <code>62857xxxxxxx</code>.</span>
                    </label>
                    <label class="field--checkbox">
                        <input type="checkbox" name="show_whatsapp_button" value="1" <?= ($settings === [] || (int) ($settings['show_whatsapp_button'] ?? 1) === 1) ? 'checked' : '' ?>>
                        <span class="field--checkbox__text">
                            <span class="field--checkbox__title">Tampilkan tombol floating WhatsApp</span>
                            <span class="field--checkbox__desc">Kalau dimatikan, tombolnya tidak dirender sama sekali di frontend.</span>
                        </span>
                    </label>
                    <label class="field">Link Telegram (grup/channel/akun)
                        <input type="text" name="telegram_username" placeholder="https://t.me/+uUBp0t7Zt6JmNWM1" value="<?= cms_esc($val('telegram_username')) ?>">
                        <span class="field__hint">Tempel link Telegram lengkap — bisa link invite grup/channel privat (<code>t.me/+xxxxxxx</code>) atau link username publik (<code>t.me/namaAkun</code>). Kalau cuma menempel <code>t.me/...</code> tanpa <code>https://</code>, akan otomatis dilengkapi jadi <code>https://t.me/...</code>.</span>
                    </label>
                    <label class="field--checkbox">
                        <input type="checkbox" name="show_telegram_button" value="1" <?= (int) ($settings['show_telegram_button'] ?? 0) === 1 ? 'checked' : '' ?>>
                        <span class="field--checkbox__text">
                            <span class="field--checkbox__title">Tampilkan tombol floating Telegram</span>
                            <span class="field--checkbox__desc">Kalau dimatikan, tombolnya tidak dirender sama sekali di frontend.</span>
                        </span>
                    </label>
                    <label class="field">Instagram URL
                        <input type="text" name="instagram_url" value="<?= cms_esc($val('instagram_url')) ?>">
                    </label>
                    <label class="field">Email
                        <input type="email" name="email" value="<?= cms_esc($val('email')) ?>">
                    </label>
                    <label class="field">Address
                        <textarea name="address" rows="4"><?= cms_esc($val('address')) ?></textarea>
                    </label>
                    <button type="submit" class="admin-btn admin-btn--primary">Save changes</button>
                </div>
            </div>
            <div class="panel">
                <div class="panel__head">
                    <h3 class="panel__title">SEO defaults</h3>
                </div>
                <div class="form-stack">
                    <label class="field">Meta title
                        <input type="text" name="meta_title" value="<?= cms_esc($val('meta_title')) ?>">
                    </label>
                    <label class="field">Meta description
                        <textarea name="meta_description" rows="4"><?= cms_esc($val('meta_description')) ?></textarea>
                    </label>
                    <label class="field">Meta keywords
                        <textarea name="meta_keywords" rows="3"><?= cms_esc($val('meta_keywords')) ?></textarea>
                    </label>
                    <?php $renderSiteSettingsImageField(
                        'OG image',
                        'og_image',
                        'og_image_file',
                        '/uploads/site/seo/',
                        'image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp',
                        [
                            'Allowed: JPG, PNG, WEBP',
                            'Recommended: 1200×630 px',
                            'Max size: 5 MB',
                        ]
                    ); ?>
                    <label class="field">Google Analytics ID
                        <input type="text" name="google_analytics_id" value="<?= cms_esc($val('google_analytics_id')) ?>">
                    </label>
                    <button type="button" class="admin-btn admin-btn--secondary" disabled>Preview metadata</button>
                </div>
            </div>
        </div>
    </form>
</section>
<script>
(function () {
  // Same base the server used to render the initial <img src> via
  // app_asset_preview_url() (cms_public_base_prefix() when available) —
  // must match exactly, otherwise this script overwrites a correct
  // server-rendered preview with a stale BASE_URL-based one that 404s
  // under the older split-subdomain topology this project used before
  // 7 Aug 2026 (wpm.sagagoal.com admin vs sagagoal.com frontend — admin
  // now lives at sagagoal.com/cms-admin/, same host as frontend), which
  // is what silently hid the logo/favicon preview in production while
  // local dev (single-domain) looked fine.
  var cmsBaseUrl = <?= json_encode(function_exists('cms_public_base_prefix') ? cms_public_base_prefix() : BASE_URL, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
  function previewUrl(path) {
    path = (path || '').trim();
    if (!path) return '';
    if (/^https?:\/\//i.test(path)) return path;
    return cmsBaseUrl + path.replace(/^\/+/, '');
  }
  document.querySelectorAll('.cms-path-upload').forEach(function (wrap) {
    var pathInput = wrap.querySelector('.cms-path-upload__input');
    var preview = wrap.querySelector('.cms-path-upload__preview');
    var fileInput = wrap.querySelector('.cms-path-upload__file');
    if (!pathInput || !preview) return;
    var objectUrl = '';
    function showPreview(url) {
      if (!url) {
        preview.hidden = true;
        preview.removeAttribute('src');
        return;
      }
      preview.src = url;
      preview.hidden = false;
      preview.onerror = function () {
        preview.hidden = true;
      };
    }
    function syncFromPath() {
      if (objectUrl) return;
      showPreview(previewUrl(pathInput.value));
    }
    if (fileInput) {
      fileInput.addEventListener('change', function () {
        if (objectUrl) {
          URL.revokeObjectURL(objectUrl);
          objectUrl = '';
        }
        if (!fileInput.files || !fileInput.files[0]) {
          syncFromPath();
          return;
        }
        objectUrl = URL.createObjectURL(fileInput.files[0]);
        showPreview(objectUrl);
      });
    }
    syncFromPath();
  });
})();
</script>
<?php
require dirname(__DIR__) . '/includes/footer.php';