<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';

$pageTitle = 'Banners';
$currentNav = 'banners';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'Banners', 'href' => ''],
];

$selfUrl = 'banners.php';

$bn_schedule_label = static function (?string $start, ?string $end): string {
    $start = $start !== null && $start !== '' ? $start : null;
    $end = $end !== null && $end !== '' ? $end : null;
    if ($start === null && $end === null) {
        return 'Always on';
    }
    if ($start !== null && $end !== null) {
        return $start . ' → ' . $end;
    }
    if ($start !== null) {
        return 'From ' . $start;
    }

    return 'Until ' . (string) $end;
};

/**
 * Convert MySQL DATETIME (YYYY-MM-DD HH:MM:SS) to datetime-local (YYYY-MM-DDTHH:MM).
 */
$bn_mysql_to_datetime_local = static function (?string $value): string {
    $value = $value !== null ? trim($value) : '';
    if ($value === '') {
        return '';
    }
    $value = str_replace(' ', 'T', $value);
    return substr($value, 0, 16);
};

/**
 * @param list<string> $remarks
 */
$renderBannerImageField = static function (
    string $label,
    string $fieldName,
    string $fileInputName,
    array $remarks,
    ?array $editRow,
    callable $val
): void {
    $currentPath = $editRow ? $val($editRow, $fieldName) : '';
    $previewUrl = app_asset_preview_url($currentPath);
    $hasPreview = $previewUrl !== '';
    ?>
    <div class="cms-path-upload">
        <span class="field cms-path-upload__label"><?= cms_esc($label); ?></span>
        <p class="cms-path-upload__hint">Upload destination: <code>/uploads/banners/</code></p>
        <div class="cms-path-upload__box">
            <img
                class="cms-path-upload__preview"
                alt="<?= cms_esc($label . ' preview'); ?>"
                <?= $hasPreview ? 'src="' . cms_esc($previewUrl) . '"' : 'hidden'; ?>
            >
            <input
                type="file"
                name="<?= cms_esc($fileInputName); ?>"
                class="cms-path-upload__file"
                accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp"
            >
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

$alerts = [];
if (isset($_SESSION['cms_flash']) && is_array($_SESSION['cms_flash'])) {
    $alerts[] = $_SESSION['cms_flash'];
    unset($_SESSION['cms_flash']);
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = null;

$listStmt = $pdo->query(
    'SELECT id, title, placement, sort_order, is_active, start_date, end_date, button_url
     FROM banners
     ORDER BY sort_order ASC, id DESC'
);
$banners = $listStmt->fetchAll();

if ($editId > 0) {
    $editStmt = $pdo->prepare(
        'SELECT id, title, subtitle, button_text, button_url, desktop_image, mobile_image,
                placement, sort_order, is_active, start_date, end_date
         FROM banners
         WHERE id = :id
         LIMIT 1'
    );
    $editStmt->execute(['id' => $editId]);
    $editRow = $editStmt->fetch() ?: null;
    if ($editRow === null) {
        $alerts[] = ['type' => 'error', 'message' => 'Banner not found.'];
        $editId = 0;
    }
}

$val = static function (array $row, string $key): string {
    return (string) ($row[$key] ?? '');
};

$desktopImageRemarks = [
    'Allowed: JPG, PNG, WEBP',
    'Recommended desktop: 1920×900 px',
    'Max size: 5 MB',
];

$mobileImageRemarks = [
    'Allowed: JPG, PNG, WEBP',
    'Recommended mobile: 900×1200 px',
    'Max size: 5 MB',
];

$isAlwaysOnChecked = true;
$startDateValue = '';
$endDateValue = '';
if ($editRow) {
    $startRaw = $val($editRow, 'start_date');
    $endRaw = $val($editRow, 'end_date');
    $startDateValue = $bn_mysql_to_datetime_local($startRaw !== '' ? $startRaw : null);
    $endDateValue = $bn_mysql_to_datetime_local($endRaw !== '' ? $endRaw : null);
    $isAlwaysOnChecked = $startDateValue === '' && $endDateValue === '';
}

$formAction = $editRow ? '../actions/banners-update.php' : '../actions/banners-store.php';

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/sidebar.php';
require dirname(__DIR__) . '/includes/navbar.php';
require dirname(__DIR__) . '/includes/breadcrumb.php';
require dirname(__DIR__) . '/includes/alerts.php';
?>
<style>
.cms-schedule.is-disabled { opacity: .55; }
</style>
<section class="admin-stack">
    <div class="toolbar">
        <div class="toolbar__left">
            <h2 class="section-title">Banners</h2>
            <p class="section-lead">Slots, scheduling, and placement rules — image paths as text for now.</p>
        </div>
        <div class="toolbar__right">
            <button type="button" class="admin-btn admin-btn--secondary" disabled>Reorder</button>
            <a class="admin-btn admin-btn--primary" href="<?= cms_esc($editRow ? $selfUrl : $selfUrl . '#banner-form') ?>">New banner</a>
        </div>
    </div>

    <div class="admin-grid admin-grid--2">
        <div class="panel">
            <div class="panel__head">
                <h3 class="panel__title">Active &amp; scheduled</h3>
                <span class="panel__meta"><?= count($banners) ?> banner(s)</span>
            </div>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Placement</th>
                            <th>Sort</th>
                            <th>Schedule</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($banners === []) : ?>
                            <tr>
                                <td colspan="6" class="muted">No banners yet.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($banners as $row) : ?>
                            <?php $rowId = (int) $row['id']; ?>
                            <tr>
                                <td><?= cms_esc($val($row, 'title')) ?></td>
                                <td><code><?= cms_esc($val($row, 'placement')) ?></code></td>
                                <td><?= cms_esc($val($row, 'sort_order')) ?></td>
                                <td><?= cms_esc($bn_schedule_label($row['start_date'] ?? null, $row['end_date'] ?? null)) ?></td>
                                <td>
                                    <span class="pill pill--<?= (int) ($row['is_active'] ?? 0) === 1 ? 'ok' : 'muted' ?>">
                                        <?= (int) ($row['is_active'] ?? 0) === 1 ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                                <td class="table-actions">
                                    <a class="admin-btn admin-btn--sm admin-btn--secondary" href="<?= cms_esc($selfUrl) ?>?edit=<?= $rowId ?>">Edit</a>
                                    <form class="inline-form" method="post" action="../actions/banners-delete.php" onsubmit="return confirm('Delete this banner?');">
                                        <?= cms_csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= $rowId ?>">
                                        <button type="submit" class="admin-btn admin-btn--sm admin-btn--danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="panel" id="banner-form">
            <div class="panel__head">
                <h3 class="panel__title"><?= $editRow ? 'Edit banner' : 'New banner' ?></h3>
                <?php if ($editRow) : ?>
                    <a class="panel__link" href="<?= cms_esc($selfUrl) ?>">Cancel edit</a>
                <?php endif; ?>
            </div>
            <form class="form-stack" method="post" action="<?= cms_esc($formAction) ?>" enctype="multipart/form-data">
                <?= cms_csrf_field() ?>
                <?php if ($editRow) : ?>
                    <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
                <?php endif; ?>
                <label class="field">Title
                    <input type="text" name="title" value="<?= cms_esc($editRow ? $val($editRow, 'title') : '') ?>" required>
                </label>
                <label class="field">Subtitle
                    <input type="text" name="subtitle" value="<?= cms_esc($editRow ? $val($editRow, 'subtitle') : '') ?>">
                </label>
                <label class="field">Button text
                    <input type="text" name="button_text" value="<?= cms_esc($editRow ? $val($editRow, 'button_text') : '') ?>">
                </label>
                <label class="field">Button URL
                    <input type="text" name="button_url" value="<?= cms_esc($editRow ? $val($editRow, 'button_url') : '') ?>" placeholder="https://">
                </label>
                <div class="cms-schedule<?= $isAlwaysOnChecked ? ' is-disabled' : '' ?>" data-schedule>
                    <label class="field field--checkbox">
                        <input type="checkbox" name="is_always_on" value="1"<?= $isAlwaysOnChecked ? ' checked' : '' ?> data-always-on>
                        <span class="field--checkbox__text">
                            <span class="field--checkbox__title">Always on</span>
                            <span class="field--checkbox__desc">Banner tampil terus tanpa jadwal — kosongkan Start/End date di bawah.</span>
                        </span>
                    </label>
                    <label class="field">Start date
                        <input type="datetime-local" name="start_date" value="<?= cms_esc($startDateValue) ?>"<?= $isAlwaysOnChecked ? ' disabled' : '' ?> data-start-date>
                    </label>
                    <label class="field">End date
                        <input type="datetime-local" name="end_date" value="<?= cms_esc($endDateValue) ?>"<?= $isAlwaysOnChecked ? ' disabled' : '' ?> data-end-date>
                    </label>
                </div>
                <?php $renderBannerImageField(
                    'Desktop image',
                    'desktop_image',
                    'desktop_image_file',
                    $desktopImageRemarks,
                    $editRow,
                    $val
                ); ?>
                <?php $renderBannerImageField(
                    'Mobile image',
                    'mobile_image',
                    'mobile_image_file',
                    $mobileImageRemarks,
                    $editRow,
                    $val
                ); ?>
                <label class="field">Placement
                    <input type="text" name="placement" value="<?= cms_esc($editRow ? $val($editRow, 'placement') : '') ?>" required placeholder="e.g. home_hero">
                </label>
                <label class="field">Sort order
                    <input type="number" name="sort_order" min="0" step="1" value="<?= cms_esc($editRow ? $val($editRow, 'sort_order') : '0') ?>" required>
                </label>
                <label class="field">Status
                    <select name="is_active" required>
                        <option value="1"<?= $editRow && (int) ($editRow['is_active'] ?? 0) === 1 ? ' selected' : (!$editRow ? ' selected' : '') ?>>Active</option>
                        <option value="0"<?= $editRow && (int) ($editRow['is_active'] ?? 0) === 0 ? ' selected' : '' ?>>Inactive</option>
                    </select>
                </label>
                <button type="submit" class="admin-btn admin-btn--primary"><?= $editRow ? 'Save changes' : 'Create banner' ?></button>
            </form>
        </div>
    </div>
</section>
<script>
(function () {
  // Must match the base app_asset_preview_url() used server-side for the
  // initial <img src> (cms_public_base_prefix() when available) — otherwise
  // this script clobbers a correct preview with a stale BASE_URL-based one
  // that 404s under the older split-subdomain topology this project used
  // before 7 Aug 2026 (wpm.sagagoal.com admin vs sagagoal.com frontend —
  // admin now lives at sagagoal.com/cms-admin/, same host as frontend).
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

  document.querySelectorAll('[data-schedule]').forEach(function (wrap) {
    var alwaysOn = wrap.querySelector('[data-always-on]');
    var start = wrap.querySelector('[data-start-date]');
    var end = wrap.querySelector('[data-end-date]');
    if (!alwaysOn || !start || !end) return;
    function sync() {
      var checked = !!alwaysOn.checked;
      start.disabled = checked;
      end.disabled = checked;
      wrap.classList.toggle('is-disabled', checked);
    }
    alwaysOn.addEventListener('change', sync);
    sync();
  });
})();
</script>
<?php
require dirname(__DIR__) . '/includes/footer.php';
