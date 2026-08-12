<?php
declare(strict_types=1);

/**
 * TinyMCE Media Library picker — shared partial (WPM).
 *
 * Include this file just before the <script src="…/tinymce.min.js"> tag on
 * any page that uses tinymce.init(). After including it, add to tinymce.init():
 *
 *   file_picker_types: 'image',
 *   file_picker_callback: window.wpmMlPicker,
 *
 * Requirements:
 *   - $pdo (PDO)  must be defined by the including page.
 *   - app_asset_preview_url() is loaded here if not already available.
 *
 * Outputs: scoped CSS + modal HTML + JS that registers window.wpmMlPicker.
 * Does not modify media_library schema.
 */

// app_asset_preview_url() and CMS_PROJECT_ROOT are available via the chain:
//   auth.php → functions.php → cms-admin/config/app.php
/** @var \PDO $pdo */
try {
    $mceMlImages = $pdo->query(
        'SELECT id, file_name, file_path, alt_text
         FROM media_library
         WHERE is_active = 1
           AND (file_type = \'image\' OR mime_type LIKE \'image/%\')
         ORDER BY id DESC'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Fallback for missing columns (schema migration not yet applied).
    $mceMlImages = $pdo->query(
        'SELECT id, file_name, file_path, alt_text
         FROM media_library
         WHERE file_type = \'image\'
         ORDER BY id DESC'
    )->fetchAll(PDO::FETCH_ASSOC);
}

// Used to resolve disk paths for getimagesize().
// CMS_PROJECT_ROOT is defined in cms-admin/config/app.php.
$mceMlProjectRoot = CMS_PROJECT_ROOT;
?>
<style>
/* ---- TinyMCE Media Library picker modal ---- */
/* Prefix: mce-ml-* — isolated from admin.css and gl-media-* */
#mce-ml-modal {
    position: fixed; inset: 0;
    z-index: 1400;          /* above TinyMCE dialog (~1300) */
    display: flex; align-items: center; justify-content: center;
}
#mce-ml-modal[hidden] { display: none !important; }
#mce-ml-backdrop {
    position: absolute; inset: 0;
    background: var(--modal-overlay);
}
#mce-ml-dialog {
    position: relative;
    background: var(--surface);
    border: 1px solid var(--modal-border);
    border-radius: 18px;
    box-shadow: var(--modal-shadow);
    width: min(820px, 95vw);
    max-height: 84vh;
    display: flex; flex-direction: column;
    overflow: hidden;
}
#mce-ml-head {
    display: flex; align-items: center; gap: 10px;
    padding: 14px 16px 12px;
    border-bottom: 1px solid var(--line-subtle);
    flex-shrink: 0;
}
#mce-ml-head h3 {
    margin: 0; font-size: 15px; font-weight: 700;
    flex-shrink: 0; white-space: nowrap;
    color: var(--text);
}
#mce-ml-search {
    flex: 1; min-width: 0;
    padding: 7px 11px;
    border: 1px solid var(--line);
    border-radius: 8px;
    background: var(--input-bg);
    color: var(--text);
    font-size: 13px;
    font-family: inherit;
}
#mce-ml-close {
    flex-shrink: 0;
    background: transparent;
    border: 1px solid var(--line);
    border-radius: 7px;
    padding: 6px 10px;
    cursor: pointer;
    font-size: 14px;
    color: var(--muted);
    line-height: 1;
}
#mce-ml-close:hover { background: var(--navlink-hover-bg); border-color: var(--navlink-hover-border); }
#mce-ml-body {
    overflow-y: auto; flex: 1;
    padding: 10px 12px 14px;
}
.mce-ml-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
    gap: 10px;
}
.mce-ml-item {
    cursor: pointer;
    border: 1.5px solid var(--line);
    border-radius: 10px;
    overflow: hidden;
    background: var(--surface-soft);
    transition: border-color .14s ease, transform .13s ease, box-shadow .14s ease;
    outline: none;
}
.mce-ml-item:hover,
.mce-ml-item:focus {
    border-color: var(--navlink-active-border);
    transform: translateY(-2px);
    box-shadow: var(--shadow-sm);
}
.mce-ml-item[hidden] { display: none !important; }
.mce-ml-item__img {
    display: block; width: 100%; height: 100px;
    object-fit: cover;
}
.mce-ml-item__name {
    display: block;
    font-size: 11px; color: var(--muted);
    padding: 5px 7px 3px;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    border-top: 1px solid var(--line-subtle);
    background: var(--surface-soft);
}
.mce-ml-item__dims {
    display: block;
    font-size: 10px; color: var(--muted);
    padding: 0 7px 5px;
    text-align: right;
    background: var(--surface-soft);
    letter-spacing: .02em;
}
.mce-ml-empty {
    grid-column: 1 / -1;
    padding: 24px; text-align: center;
    color: var(--muted); font-size: 14px;
}
</style>

<!-- TinyMCE Media Library picker modal -->
<div id="mce-ml-modal" hidden role="dialog" aria-modal="true" aria-labelledby="mce-ml-title">
    <div id="mce-ml-backdrop"></div>
    <div id="mce-ml-dialog">

        <div id="mce-ml-head">
            <h3 id="mce-ml-title">Select from Media Library</h3>
            <input type="search" id="mce-ml-search"
                   placeholder="Search images…"
                   autocomplete="off">
            <button type="button" id="mce-ml-close" aria-label="Close">✕</button>
        </div>

        <div id="mce-ml-body">
            <div class="mce-ml-grid">
                <?php if ($mceMlImages === []) : ?>
                    <p class="mce-ml-empty">No images found in the Media Library.</p>
                <?php endif; ?>
                <?php foreach ($mceMlImages as $mceImg) : ?>
                    <?php
                    $mceId   = (int)    $mceImg['id'];
                    $mceName = (string) $mceImg['file_name'];
                    $mcePath = (string) $mceImg['file_path'];
                    $mceAlt  = (string) ($mceImg['alt_text'] ?? '');
                    $mceSrc  = app_asset_preview_url($mcePath);

                    // Detect real image dimensions from disk.
                    // Skips external URLs and files that cannot be resolved.
                    // getimagesize() supports JPEG, PNG, GIF, WebP (PHP 7.1+).
                    $mceW = 0;
                    $mceH = 0;
                    // H-3: only read from disk when the stored path safely resolves
                    // inside the uploads directory (blocks traversal, incl. legacy rows).
                    $diskPath = app_safe_media_disk_path($mcePath, $mceMlProjectRoot);
                    if ($diskPath !== null && is_file($diskPath)) {
                        $dimResult = @getimagesize($diskPath);
                        if (is_array($dimResult) && $dimResult[0] > 0 && $dimResult[1] > 0) {
                            $mceW = (int) $dimResult[0];
                            $mceH = (int) $dimResult[1];
                        }
                    }
                    ?>
                    <div class="mce-ml-item"
                         role="button"
                         tabindex="0"
                         data-src="<?=  htmlspecialchars($mceSrc,             ENT_QUOTES, 'UTF-8') ?>"
                         data-path="<?= htmlspecialchars($mcePath,            ENT_QUOTES, 'UTF-8') ?>"
                         data-alt="<?=  htmlspecialchars($mceAlt,             ENT_QUOTES, 'UTF-8') ?>"
                         data-name="<?= htmlspecialchars(strtolower($mceName), ENT_QUOTES, 'UTF-8') ?>"
                         data-width="<?= $mceW ?>"
                         data-height="<?= $mceH ?>"
                         title="<?= htmlspecialchars($mceName, ENT_QUOTES, 'UTF-8') ?>">
                        <img class="mce-ml-item__img"
                             src="<?= htmlspecialchars($mceSrc, ENT_QUOTES, 'UTF-8') ?>"
                             alt="<?= htmlspecialchars($mceAlt ?: $mceName, ENT_QUOTES, 'UTF-8') ?>"
                             loading="lazy"
                             onerror="this.style.display='none'">
                        <span class="mce-ml-item__name"><?= htmlspecialchars($mceName, ENT_QUOTES, 'UTF-8') ?></span>
                        <?php if ($mceW > 0 && $mceH > 0) : ?>
                            <span class="mce-ml-item__dims"><?= $mceW ?> × <?= $mceH ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

    </div>
</div>

<script>
(function () {
    var modal    = document.getElementById('mce-ml-modal');
    var backdrop = document.getElementById('mce-ml-backdrop');
    var search   = document.getElementById('mce-ml-search');
    var closeBtn = document.getElementById('mce-ml-close');
    var items    = document.querySelectorAll('.mce-ml-item');

    if (!modal) return;

    var currentCallback = null;

    /* ---- open / close ---- */
    function openModal() {
        modal.hidden = false;
        if (search) { search.value = ''; filterItems(''); search.focus(); }
    }
    function closeModal() {
        modal.hidden = true;
        currentCallback = null;
    }

    /* ---- search filter ---- */
    function filterItems(q) {
        q = q.toLowerCase().trim();
        items.forEach(function (item) {
            if (!q) { item.hidden = false; return; }
            var name = (item.getAttribute('data-name') || '').toLowerCase();
            item.hidden = name.indexOf(q) === -1;
        });
    }

    /* ---- select item → fill TinyMCE Source, Alt, Width, Height fields ---- */
    function selectItem(item) {
        if (!currentCallback) return;

        // data-src  = browser-valid URL (app_asset_preview_url output).
        // data-path = stored DB path — kept for reference but NOT passed to TinyMCE,
        //             because TinyMCE uses the URL to preview the image in the dialog.
        var src  = item.getAttribute('data-src')    || item.getAttribute('data-path') || '';
        var alt  = item.getAttribute('data-alt')    || '';
        var w    = parseInt(item.getAttribute('data-width')  || '0', 10);
        var h    = parseInt(item.getAttribute('data-height') || '0', 10);

        // Cap display width at 600 px, preserve aspect ratio.
        var MAX_W = 600;
        if (w > MAX_W) {
            h = h > 0 ? Math.round(h * MAX_W / w) : 0;
            w = MAX_W;
        }

        // Build the meta object TinyMCE uses to pre-fill dialog fields.
        // Width/height omitted when 0 so TinyMCE can infer them from the loaded image.
        var imgMeta = { title: alt };
        if (w > 0) { imgMeta.width  = String(w); }
        if (h > 0) { imgMeta.height = String(h); }

        currentCallback(src, imgMeta);
        closeModal();
    }

    /* ---- event wires ---- */
    if (closeBtn)  { closeBtn.addEventListener('click', closeModal); }
    if (backdrop)  { backdrop.addEventListener('click', closeModal); }

    document.addEventListener('keydown', function (e) {
        if (!modal.hidden && (e.key === 'Escape' || e.key === 'Esc')) { closeModal(); }
    });

    if (search) {
        search.addEventListener('input', function () { filterItems(search.value); });
    }

    items.forEach(function (item) {
        // Mouse click
        item.addEventListener('click', function () { selectItem(item); });
        // Keyboard: Enter or Space for accessibility (role="button" + tabindex)
        item.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                selectItem(item);
            }
        });
    });

    /**
     * TinyMCE file_picker_callback.
     * Register as: file_picker_callback: window.wpmMlPicker
     *
     * @param {Function} callback  Call with (url, meta) to fill the Source field.
     * @param {string}   value     Current value of the Source field.
     * @param {Object}   meta      { filetype: 'image'|'media'|'file' }
     */
    window.wpmMlPicker = function (callback, value, meta) {
        if (!meta || meta.filetype !== 'image') return;
        currentCallback = callback;
        openModal();
    };
})();

// ---- Shared TinyMCE content styles — consumed by each page's tinymce.init() ----
// These styles apply inside the TinyMCE iframe so images look correct while editing.
window.wpmMlContentStyle =
    'body { display: flow-root; }' +
    // Base: unclassified images get centred-ish appearance in the editor
    'img { display:block; max-width:600px; width:auto; height:auto; border-radius:12px; margin:24px auto; }' +
    // Alignment classes — must mirror assets/css/style.css exactly
    '.img-center { display:block; float:none; width:auto; max-width:700px; height:auto; margin:24px auto; border-radius:12px; }' +
    '.img-full   { display:block; float:none; width:100%; max-width:900px; height:auto; margin:24px auto; border-radius:12px; }' +
    '.img-left   { float:left;  width:280px; max-width:40%; height:auto; margin:0 24px 16px 0; border-radius:12px; }' +
    '.img-right  { float:right; width:280px; max-width:40%; height:auto; margin:0 0 16px 24px; border-radius:12px; }' +
    '.img-left~.img-left,.img-left~.img-right,.img-right~.img-left,.img-right~.img-right{clear:both}' +
    '@media (max-width:640px){' +
    '  .img-center,.img-full,.img-left,.img-right{float:none;display:block;width:100%;max-width:100%;margin:20px 0;}' +
    '}';

// ---- Editor setup hook — add to tinymce.init() setup function ----
// Adds an inline style attribute to newly inserted/selected images that do not
// already have one, so the styled appearance is preserved in the saved HTML.
window.wpmMlSetupEditor = function (editor) {
    // Only applied to images that carry NO alignment class (fallback insurance).
    // Images with img-center/img-full/img-left/img-right get their appearance
    // entirely from the CSS class — adding inline styles would override the class.
    var BASE_STYLE = 'height:auto;border-radius:12px';
    var ALIGN_RE   = /\bimg-(center|full|left|right)\b/;
    var ready = false;

    editor.on('init', function () { ready = true; });

    editor.on('NodeChange', function (e) {
        if (!ready) return;
        var el = e.element;
        if (el && el.nodeName === 'IMG' && !el.getAttribute('style')) {
            if (!ALIGN_RE.test(el.getAttribute('class') || '')) {
                editor.dom.setAttrib(el, 'style', BASE_STYLE);
            }
        }
    });
};
</script>
