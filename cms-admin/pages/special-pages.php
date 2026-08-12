<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/includes/SpecialPages.php';

// Site-wide configuration is admin-tier — see cms_require_role() in
// functions.php for the full tier breakdown.
cms_require_role(['superadmin', 'admin']);

wpm_ensure_special_pages_table($pdo);

$pageTitle = 'Special Pages';
$currentNav = 'special-pages';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'Special Pages', 'href' => ''],
];

$selfUrl = 'special-pages.php';

// page_key values with their own special-case template in page.php
// (contact => Kontak form, about => About hero + hardcoded feature
// cards) — deleting these would 404/break that template's data lookup,
// so they're never deletable from this list, only editable.
$protectedPageKeys = ['contact', 'about'];

$sp_redirect = static function (string $message, string $type = 'success', ?string $query = null) use ($selfUrl): void {
    $_SESSION['cms_flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . $selfUrl . ($query ? '?' . $query : ''), true, 302);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete') {
        $deleteId = (int) ($_POST['id'] ?? 0);
        if ($deleteId <= 0) {
            $sp_redirect('Invalid page.', 'error');
        }
        $row = wpm_special_page_by_id($pdo, $deleteId);
        if ($row !== null && in_array((string) $row['page_key'], $protectedPageKeys, true)) {
            $sp_redirect('Halaman sistem tidak bisa dihapus (dipakai template khusus di page.php).', 'error');
        }
        $delete = $pdo->prepare('DELETE FROM special_pages WHERE special_page_id = :id');
        $delete->execute(['id' => $deleteId]);
        $sp_redirect($delete->rowCount() > 0 ? 'Page deleted.' : 'Page not found.', $delete->rowCount() > 0 ? 'success' : 'error');
    }

    if ($action === 'create' || $action === 'update') {
        $id = (int) ($_POST['special_page_id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $slug = strtolower(trim((string) ($_POST['slug'] ?? '')));
        $pageKey = strtolower(trim((string) ($_POST['page_key'] ?? '')));
        // content is rendered raw (not escaped) on the public site — see
        // page.php — since it's admin-authored rich text. Sanitized here at
        // save time (same cms_sanitize_ad_html() used for the Advertisements
        // "Custom HTML" field) as defense-in-depth against a compromised or
        // lower-privilege admin account, not because the write path itself
        // is untrusted.
        $content = cms_sanitize_ad_html((string) ($_POST['content'] ?? ''));
        $metaTitle = trim((string) ($_POST['meta_title'] ?? ''));
        $metaDescription = trim((string) ($_POST['meta_description'] ?? ''));
        $showInMenu = !empty($_POST['show_in_menu']) ? 1 : 0;
        $showInFooter = !empty($_POST['show_in_footer']) ? 1 : 0;
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        $status = (string) ($_POST['status'] ?? 'draft');

        if ($title === '') {
            $sp_redirect('Title is required.', 'error');
        }
        if ($slug === '' || preg_match('/^[a-z0-9-]+$/', $slug) !== 1) {
            $sp_redirect('Slug hanya boleh huruf kecil, angka, dan tanda strip.', 'error');
        }
        if (!in_array($status, ['published', 'draft'], true)) {
            $status = 'draft';
        }

        if ($action === 'create') {
            if ($pageKey === '' || preg_match('/^[a-z0-9_]+$/', $pageKey) !== 1) {
                $sp_redirect('Page key hanya boleh huruf kecil, angka, dan underscore.', 'error');
            }
            if (wpm_special_page_slug_is_reserved($slug)) {
                $sp_redirect('Slug ini sudah dipakai sistem, silakan pilih slug lain.', 'error');
            }
            $dup = $pdo->prepare('SELECT COUNT(*) FROM special_pages WHERE slug = :slug OR page_key = :page_key');
            $dup->execute(['slug' => $slug, 'page_key' => $pageKey]);
            if ((int) $dup->fetchColumn() > 0) {
                $sp_redirect('Slug atau page key sudah dipakai.', 'error');
            }

            $pdo->prepare(
                'INSERT INTO special_pages
                    (page_key, title, slug, content, meta_title, meta_description, show_in_menu, show_in_footer, sort_order, status)
                 VALUES
                    (:page_key, :title, :slug, :content, :meta_title, :meta_description, :show_in_menu, :show_in_footer, :sort_order, :status)'
            )->execute([
                'page_key' => $pageKey,
                'title' => $title,
                'slug' => $slug,
                'content' => $content,
                'meta_title' => $metaTitle !== '' ? $metaTitle : null,
                'meta_description' => $metaDescription !== '' ? $metaDescription : null,
                'show_in_menu' => $showInMenu,
                'show_in_footer' => $showInFooter,
                'sort_order' => $sortOrder,
                'status' => $status,
            ]);
            $sp_redirect('Special page created.');
        }

        // update
        if ($id <= 0) {
            $sp_redirect('Invalid page.', 'error');
        }
        $existing = wpm_special_page_by_id($pdo, $id);
        if ($existing === null) {
            $sp_redirect('Page not found.', 'error');
        }
        if (wpm_special_page_slug_is_reserved($slug, (string) $existing['slug'])) {
            $sp_redirect('Slug ini sudah dipakai sistem, silakan pilih slug lain.', 'error');
        }
        $dup = $pdo->prepare('SELECT COUNT(*) FROM special_pages WHERE slug = :slug AND special_page_id != :id');
        $dup->execute(['slug' => $slug, 'id' => $id]);
        if ((int) $dup->fetchColumn() > 0) {
            $sp_redirect('Slug sudah dipakai halaman lain.', 'error');
        }

        // page_key is permanent — never updated from the form, regardless of what was posted.
        $pdo->prepare(
            'UPDATE special_pages SET
                title = :title, slug = :slug, content = :content, meta_title = :meta_title,
                meta_description = :meta_description, show_in_menu = :show_in_menu,
                show_in_footer = :show_in_footer, sort_order = :sort_order, status = :status
             WHERE special_page_id = :id'
        )->execute([
            'title' => $title,
            'slug' => $slug,
            'content' => $content,
            'meta_title' => $metaTitle !== '' ? $metaTitle : null,
            'meta_description' => $metaDescription !== '' ? $metaDescription : null,
            'show_in_menu' => $showInMenu,
            'show_in_footer' => $showInFooter,
            'sort_order' => $sortOrder,
            'status' => $status,
            'id' => $id,
        ]);
        $sp_redirect('Special page updated.');
    }

    $sp_redirect('Unknown action.', 'error');
}

$alerts = [];
if (isset($_SESSION['cms_flash']) && is_array($_SESSION['cms_flash'])) {
    $alerts[] = $_SESSION['cms_flash'];
    unset($_SESSION['cms_flash']);
}

$view = (string) ($_GET['view'] ?? 'list');
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = null;

if ($editId > 0) {
    $editRow = wpm_special_page_by_id($pdo, $editId);
    if ($editRow === null) {
        $alerts[] = ['type' => 'error', 'message' => 'Page not found.'];
        $editId = 0;
    } else {
        $view = 'form';
    }
}
if ($view === 'new') {
    $view = 'form';
}

$pages = wpm_special_pages_all($pdo);

$val = static fn (array $row, string $key, string $default = ''): string => (string) ($row[$key] ?? $default);
$formatDt = static function (?string $value): string {
    if ($value === null || $value === '') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts !== false ? date('d M Y, H:i', $ts) : $value;
};

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/sidebar.php';
require dirname(__DIR__) . '/includes/navbar.php';
require dirname(__DIR__) . '/includes/breadcrumb.php';
require dirname(__DIR__) . '/includes/alerts.php';
?>
<section class="admin-stack">
    <?php if ($view === 'form') : ?>
        <div class="toolbar">
            <div class="toolbar__left">
                <h2 class="section-title"><?= $editRow ? 'Edit Special Page' : 'New Special Page' ?></h2>
                <p class="section-lead">Halaman statis yang dikelola dari admin tanpa perlu bikin file baru — contoh: Kontak, FAQ.</p>
            </div>
            <div class="toolbar__right">
                <a class="admin-btn admin-btn--secondary" href="<?= cms_esc($selfUrl) ?>">← Back to list</a>
            </div>
        </div>

        <div class="panel">
            <form class="form-grid" method="post" action="<?= cms_esc($selfUrl) ?>">
                <?= cms_csrf_field() ?>
                <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'create' ?>">
                <?php if ($editRow) : ?>
                    <input type="hidden" name="special_page_id" value="<?= (int) $editRow['special_page_id'] ?>">
                <?php endif; ?>

                <!-- Left column and right column are each their own independent
                     grid stack, so one column's tall field (Content's rich
                     text editor) never stretches a row in the other column
                     and leaves dead space underneath it. -->
                <div style="display:grid;gap:14px;align-content:start;">
                    <label class="field">Title
                        <input type="text" name="title" required value="<?= cms_esc($editRow ? $val($editRow, 'title') : '') ?>">
                    </label>

                    <label class="field">Slug
                        <input type="text" name="slug" required pattern="[a-z0-9-]+" value="<?= cms_esc($editRow ? $val($editRow, 'slug') : '') ?>">
                        <span class="field__hint">URL publik, contoh: <code>faq</code> → situs.com/faq. Huruf kecil, angka, strip saja.</span>
                    </label>

                    <label class="field">Page Key
                        <input type="text" name="page_key" <?= $editRow ? 'readonly disabled' : 'required pattern="[a-z0-9_]+"' ?> value="<?= cms_esc($editRow ? $val($editRow, 'page_key') : '') ?>">
                        <span class="field__hint">Identitas permanen, tidak bisa diubah setelah dibuat. Contoh: <code>faq</code>, <code>privacy_policy</code>.</span>
                    </label>

                    <label class="field">Content
                        <textarea name="content" id="sp-content" rows="10"><?= cms_esc($editRow ? $val($editRow, 'content') : '') ?></textarea>
                    </label>
                </div>

                <div style="display:grid;gap:14px;align-content:start;">
                    <label class="field">Status
                        <select name="status">
                            <option value="draft" <?= (!$editRow || $val($editRow, 'status') === 'draft') ? 'selected' : '' ?>>Draft</option>
                            <option value="published" <?= ($editRow && $val($editRow, 'status') === 'published') ? 'selected' : '' ?>>Published</option>
                        </select>
                    </label>

                    <label class="field">Meta Title
                        <input type="text" name="meta_title" maxlength="150" value="<?= cms_esc($editRow ? $val($editRow, 'meta_title') : '') ?>">
                    </label>

                    <label class="field">Meta Description
                        <textarea name="meta_description" rows="4" maxlength="300"><?= cms_esc($editRow ? $val($editRow, 'meta_description') : '') ?></textarea>
                    </label>

                    <label class="field--checkbox">
                        <input type="checkbox" name="show_in_menu" value="1" <?= (!$editRow || (int) ($editRow['show_in_menu'] ?? 0) === 1) ? 'checked' : '' ?>>
                        <span class="field--checkbox__text">
                            <span class="field--checkbox__title">Tampilkan di menu header</span>
                        </span>
                    </label>

                    <label class="field--checkbox">
                        <input type="checkbox" name="show_in_footer" value="1" <?= ($editRow && (int) ($editRow['show_in_footer'] ?? 0) === 1) ? 'checked' : '' ?>>
                        <span class="field--checkbox__text">
                            <span class="field--checkbox__title">Tampilkan di footer</span>
                        </span>
                    </label>

                    <label class="field">Sort Order
                        <input type="number" name="sort_order" value="<?= $editRow ? (int) $editRow['sort_order'] : 100 ?>">
                    </label>
                </div>

                <div class="field field--actions" style="grid-column: 1 / -1;">
                    <button type="submit" class="admin-btn admin-btn--primary"><?= $editRow ? 'Save Changes' : 'Create Page' ?></button>
                </div>
            </form>
        </div>
    <?php else : ?>
        <div class="toolbar">
            <div class="toolbar__left">
                <h2 class="section-title">Special Pages</h2>
                <p class="section-lead">Halaman statis (Kontak, FAQ, dll) yang bisa dikelola tanpa dev bikin file baru.</p>
            </div>
            <div class="toolbar__right">
                <a class="admin-btn admin-btn--primary" href="<?= cms_esc($selfUrl) ?>?view=new">+ New special page</a>
            </div>
        </div>

        <div class="panel">
            <div class="panel__head">
                <h3 class="panel__title">All pages</h3>
                <span class="panel__meta"><?= count($pages) ?> page(s)</span>
            </div>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr><th>ID</th><th>Page Key</th><th>Title</th><th>Slug</th><th>Status</th><th>Updated At</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php if ($pages === []) : ?>
                            <tr><td colspan="7" class="muted">Belum ada special page.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($pages as $sp) : ?>
                            <tr>
                                <td><?= (int) $sp['special_page_id'] ?></td>
                                <td><code><?= cms_esc((string) $sp['page_key']) ?></code></td>
                                <td><?= cms_esc((string) $sp['title']) ?></td>
                                <td><code>/<?= cms_esc((string) $sp['slug']) ?></code></td>
                                <td><span class="pill pill--<?= $sp['status'] === 'published' ? 'ok' : 'muted' ?>"><?= cms_esc((string) $sp['status']) ?></span></td>
                                <td><?= cms_esc($formatDt($sp['updated_at'] ?? null)) ?></td>
                                <td class="table-actions">
                                    <a class="admin-btn admin-btn--sm admin-btn--secondary" href="<?= cms_esc($selfUrl) ?>?edit=<?= (int) $sp['special_page_id'] ?>">Edit</a>
                                    <?php if (in_array((string) $sp['page_key'], $protectedPageKeys, true)) : ?>
                                        <button type="button" class="admin-btn admin-btn--sm admin-btn--danger" disabled title="Halaman sistem, tidak bisa dihapus">Delete</button>
                                    <?php else : ?>
                                        <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>" onsubmit="return confirm('Delete this page?');">
                                            <?= cms_csrf_field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int) $sp['special_page_id'] ?>">
                                            <button type="submit" class="admin-btn admin-btn--sm admin-btn--danger">Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</section>
<?php if ($view === 'form') : ?>
<script>
(function () {
    function slugify(str) {
        return str.toLowerCase()
            .replace(/[àáâãäå]/g, 'a').replace(/[èéêë]/g, 'e')
            .replace(/[ìíîï]/g, 'i').replace(/[òóôõöø]/g, 'o')
            .replace(/[ùúûü]/g, 'u').replace(/ñ/g, 'n').replace(/ç/g, 'c')
            .replace(/[^a-z0-9\s-]/g, '')
            .trim()
            .replace(/[\s-]+/g, '-');
    }
    var form = document.querySelector('form.form-grid');
    if (!form) { return; }
    var titleEl = form.querySelector('[name="title"]');
    var slugEl = form.querySelector('[name="slug"]');
    var pageKeyEl = form.querySelector('[name="page_key"]');
    if (!titleEl || !slugEl) { return; }
    var slugLocked = slugEl.value.trim() !== '';
    var keyLocked = !pageKeyEl || pageKeyEl.value.trim() !== '' || pageKeyEl.disabled;
    titleEl.addEventListener('input', function () {
        if (!slugLocked) { slugEl.value = slugify(titleEl.value); }
        if (!keyLocked) { pageKeyEl.value = slugify(titleEl.value).replace(/-/g, '_'); }
    });
    slugEl.addEventListener('input', function () { slugLocked = true; });
    if (pageKeyEl) { pageKeyEl.addEventListener('input', function () { keyLocked = true; }); }
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/tinymce@7.6.1/tinymce.min.js" crossorigin="anonymous"></script>
<script>
(function () {
    var contentField = document.querySelector('textarea[name="content"]');
    if (!contentField) { return; }
    tinymce.init({
        license_key: 'gpl',
        selector: 'textarea[name="content"]',
        height: 360,
        menubar: false,
        branding: false,
        promotion: false,
        plugins: 'lists link table code',
        toolbar: 'undo redo | blocks | bold italic underline | alignleft aligncenter alignright | bullist numlist | link table | code',
        block_formats: 'Paragraph=p; Heading 2=h2; Heading 3=h3; Heading 4=h4',
        link_default_target: '_blank',
        link_assume_external_targets: true,
    });
})();
</script>
<?php endif; ?>
<?php
require dirname(__DIR__) . '/includes/footer.php';
