<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';

// Site-wide configuration is admin-tier — see cms_require_role() in
// functions.php for the full tier breakdown. (Note: "External Ad Code"
// within this page is further restricted to superadmin only — see the
// separate role check later in this file.)
cms_require_role(['superadmin', 'admin']);

/**
 * Auto-migration: advertisements + ad_positions. Idempotent, safe on every
 * load. Seeds the default position set on first creation only.
 */
$ad_schemaError = null;
try {
    $ad_positionsCreated = cms_ensure_table(
        $pdo,
        'ad_positions',
        'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
         name VARCHAR(100) NOT NULL,
         slug VARCHAR(120) NOT NULL,
         created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
         UNIQUE KEY uniq_ad_position_slug (slug)'
    );
    cms_ensure_table(
        $pdo,
        'advertisements',
        'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
         name VARCHAR(150) NOT NULL,
         title VARCHAR(150) DEFAULT NULL,
         ad_type ENUM(\'image\',\'html\',\'video\') NOT NULL DEFAULT \'image\',
         banner_image VARCHAR(255) DEFAULT NULL,
         video_url VARCHAR(255) DEFAULT NULL,
         html_code TEXT DEFAULT NULL,
         target_url VARCHAR(255) DEFAULT NULL,
         cta_text VARCHAR(100) DEFAULT NULL,
         position_id INT UNSIGNED DEFAULT NULL,
         placement_scope ENUM(\'global\',\'homepage\',\'category\',\'article\',\'livescore\',\'apps\') NOT NULL DEFAULT \'global\',
         placement_target_id INT DEFAULT NULL,
         device ENUM(\'all\',\'desktop\',\'mobile\') NOT NULL DEFAULT \'all\',
         start_date DATE DEFAULT NULL,
         end_date DATE DEFAULT NULL,
         is_active TINYINT(1) NOT NULL DEFAULT 1,
         sort_order INT NOT NULL DEFAULT 0,
         impressions INT UNSIGNED NOT NULL DEFAULT 0,
         clicks INT UNSIGNED NOT NULL DEFAULT 0,
         created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
         updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
         KEY idx_ads_position (position_id),
         KEY idx_ads_active (is_active)'
    );

    if ($ad_positionsCreated) {
        $seedPositions = [
            'Header', 'Below Main Menu', 'Homepage Hero', 'Sidebar', 'Above Article',
            'Middle of Article', 'Below Article', 'Between Article Cards', 'Footer',
            'Popup', 'Sticky Bottom Mobile',
        ];
        $seedStmt = $pdo->prepare('INSERT IGNORE INTO ad_positions (name, slug) VALUES (:name, :slug)');
        foreach ($seedPositions as $posName) {
            $seedStmt->execute(['name' => $posName, 'slug' => cms_slugify($posName)]);
        }
    }

    /**
     * Multi-format ad system (14 Jul 2026): widen ad_type to support Text
     * Ad / External Ad Code (Image banner / Custom HTML / Video already
     * existed as 'image'/'html'/'video' — kept as-is, nothing renamed at
     * the DB level so old rows never need touching). New columns are all
     * nullable/defaulted so every pre-existing row (all 'image' type,
     * since that's the only type ever offered before today) keeps working
     * unmodified — banner_image/target_url/cta_text/html_code are reused
     * as-is rather than duplicated under new names.
     */
    cms_ensure_column($pdo, 'advertisements', 'advertiser_label', "VARCHAR(50) NOT NULL DEFAULT 'Ad' AFTER `ad_type`");
    cms_ensure_column($pdo, 'advertisements', 'display_domain', 'VARCHAR(100) DEFAULT NULL AFTER `advertiser_label`');
    cms_ensure_column($pdo, 'advertisements', 'headline', 'VARCHAR(90) DEFAULT NULL AFTER `display_domain`');
    cms_ensure_column($pdo, 'advertisements', 'description', 'VARCHAR(180) DEFAULT NULL AFTER `headline`');
    cms_ensure_column($pdo, 'advertisements', 'open_in_new_tab', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER `cta_text`');
    cms_ensure_column($pdo, 'advertisements', 'show_sponsored_label', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER `open_in_new_tab`');
    cms_ensure_column($pdo, 'advertisements', 'image_alt', 'VARCHAR(150) DEFAULT NULL AFTER `banner_image`');
    cms_ensure_column($pdo, 'advertisements', 'video_path', 'VARCHAR(255) DEFAULT NULL AFTER `video_url`');
    cms_ensure_column($pdo, 'advertisements', 'video_poster', 'VARCHAR(255) DEFAULT NULL AFTER `video_path`');
    cms_ensure_column($pdo, 'advertisements', 'video_autoplay', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `video_poster`');
    cms_ensure_column($pdo, 'advertisements', 'video_muted', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER `video_autoplay`');
    cms_ensure_column($pdo, 'advertisements', 'video_loop', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `video_muted`');
    cms_ensure_column($pdo, 'advertisements', 'video_controls', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER `video_loop`');
    cms_ensure_column($pdo, 'advertisements', 'external_code', 'TEXT DEFAULT NULL AFTER `html_code`');

    // MODIFY isn't an "add if missing" operation like cms_ensure_column, but
    // MySQL/MariaDB MODIFY COLUMN is naturally idempotent (safe to re-run
    // with the same definition every page load) so no separate guard needed.
    $pdo->exec("ALTER TABLE `advertisements` MODIFY COLUMN `ad_type` ENUM('image','html','video','text','external_code') NOT NULL DEFAULT 'image'");
    $pdo->exec("ALTER TABLE `advertisements` MODIFY COLUMN `device` ENUM('all','desktop','mobile','tablet') NOT NULL DEFAULT 'all'");
    // 'football'/'basket' added 11 Agu 2026 — operator wants to target ads
    // specifically at football.php/basket.php (their sidebar-right slot
    // already renders with these exact scope strings, see football.php/
    // basket.php's wpm_render_ad_slot() calls), but until now there was no
    // way to actually PICK that scope from the admin dropdown ($AD_SCOPES
    // below) or store it (this ENUM didn't allow it) — every ad aimed at
    // those two pages could only ever be 'global'. 'livescore' (already in
    // the enum from an earlier design, never wired to any page or dropdown
    // option) is left as-is/unused, not removed — no destructive schema
    // changes here, only additive.
    $pdo->exec("ALTER TABLE `advertisements` MODIFY COLUMN `placement_scope` ENUM('global','homepage','category','article','livescore','apps','football','basket') NOT NULL DEFAULT 'global'");

    cms_ensure_column($pdo, 'ad_settings', 'rotation_mode', "ENUM('priority','random','sequential') NOT NULL DEFAULT 'priority' AFTER `show_ad_label`");

    // Seed the new position keys (section 5) without touching/removing the
    // existing ones — 'sidebar' is kept for backward compat (see the
    // one-time reassignment below) even though nothing renders it anymore.
    $newPositions = [
        'Article — Before Title'  => 'article-before-title',
        'Article — After Title'   => 'article-after-title',
        'Sidebar (Left)'          => 'sidebar-left',
        'Sidebar (Right)'         => 'sidebar-right',
    ];
    $seedStmt2 = $pdo->prepare('INSERT IGNORE INTO ad_positions (name, slug) VALUES (:name, :slug)');
    foreach ($newPositions as $posName => $posSlug) {
        $seedStmt2->execute(['name' => $posName, 'slug' => $posSlug]);
    }

    // One-time migration: the old 'sidebar' position used to render TWICE
    // (both article sidebars called the same slug — the duplicate-ad bug
    // from the brief). Any ad still pointed at the old 'sidebar' slot is
    // moved onto the new 'sidebar-left' slot so it doesn't silently stop
    // appearing anywhere after this update.
    $oldSidebar = $pdo->query("SELECT id FROM ad_positions WHERE slug = 'sidebar' LIMIT 1")->fetch();
    $newSidebarLeft = $pdo->query("SELECT id FROM ad_positions WHERE slug = 'sidebar-left' LIMIT 1")->fetch();
    if ($oldSidebar && $newSidebarLeft) {
        $pdo->prepare('UPDATE advertisements SET position_id = :new WHERE position_id = :old')
            ->execute(['new' => (int) $newSidebarLeft['id'], 'old' => (int) $oldSidebar['id']]);
    }
} catch (Throwable $e) {
    $ad_schemaError = $e->getMessage();
}

$pageTitle = 'Advertisements';
$currentNav = 'ads';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'Advertisements', 'href' => ''],
];

$selfUrl = 'ads.php';
$isSuperadmin = (($_SESSION['cms_admin_role'] ?? '') === 'superadmin');

$AD_TYPES = [
    'text'          => 'Text Ad',
    'image'         => 'Image Banner',
    'video'         => 'Video Ad',
    'html'          => 'Custom HTML',
    'external_code' => 'External Ad Code',
];

$AD_SCOPES = [
    'global'    => 'Global (semua halaman)',
    'homepage'  => 'Homepage saja',
    'article'   => 'Artikel — semua, atau pilih satu di bawah',
    'category'  => 'Kategori artikel — semua, atau pilih satu di bawah',
    // 'Halaman Apps' (scope 'apps') removed from the picker 11 Agu 2026 —
    // operator found it confusing next to the other options. The scope
    // value itself, the DB enum entry, and the wiring in
    // wpm_app_promo_section() are all left in place (nothing to migrate,
    // no ad currently uses it) — only hidden from this dropdown so it
    // can't be picked. If wanted back later, just re-add the line here,
    // no other change needed.
    // 11 Agu 2026 — wired to football.php/basket.php's sidebar-right slot
    // (previously those pages could only ever show 'global'-scoped ads
    // since no dropdown option produced the exact scope string their code
    // requested).
    'football'  => 'Sepak Bola (/football)',
    'basket'    => 'Basket (/basket)',
];

$ad_redirect = static function (string $message, string $type = 'success', ?string $query = null) use ($selfUrl): void {
    $_SESSION['cms_flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . $selfUrl . ($query ? '?' . $query : ''), true, 302);
    exit;
};

$ad_parse_date = static function (string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $ts = strtotime($raw);
    return $ts !== false ? date('Y-m-d', $ts) : null;
};

/**
 * Computed lifecycle status (section 13) — purely derived from is_active +
 * start_date/end_date vs "today", never stored. Timezone-aware in the
 * sense that it compares against the server's own current date (same
 * source of truth CURDATE() uses in the frontend SQL in wpm_ad_pick()),
 * so admin-displayed status always agrees with what actually renders.
 */
$ad_status_of = static function (array $ad): array {
    if ((int) ($ad['is_active'] ?? 0) !== 1) {
        return ['label' => 'Inactive', 'tone' => 'muted'];
    }
    $today = date('Y-m-d');
    $start = (string) ($ad['start_date'] ?? '');
    $end   = (string) ($ad['end_date'] ?? '');
    if ($start !== '' && $start > $today) {
        return ['label' => 'Scheduled', 'tone' => 'info'];
    }
    if ($end !== '' && $end < $today) {
        return ['label' => 'Expired', 'tone' => 'warn'];
    }
    return ['label' => 'Active', 'tone' => 'ok'];
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete') {
        $deleteId = (int) ($_POST['id'] ?? 0);
        if ($deleteId <= 0) {
            $ad_redirect('Invalid ad.', 'error');
        }
        $delete = $pdo->prepare('DELETE FROM advertisements WHERE id = :id');
        $delete->execute(['id' => $deleteId]);
        $ad_redirect($delete->rowCount() > 0 ? 'Ad deleted.' : 'Ad not found.', $delete->rowCount() > 0 ? 'success' : 'error');
    }

    if ($action === 'toggle_active') {
        $toggleId = (int) ($_POST['id'] ?? 0);
        if ($toggleId <= 0) {
            $ad_redirect('Invalid ad.', 'error');
        }
        $pdo->prepare('UPDATE advertisements SET is_active = 1 - is_active WHERE id = :id')->execute(['id' => $toggleId]);
        $ad_redirect('Ad status updated.');
    }

    if ($action === 'duplicate') {
        $dupId = (int) ($_POST['id'] ?? 0);
        if ($dupId <= 0) {
            $ad_redirect('Invalid ad.', 'error');
        }
        $srcStmt = $pdo->prepare('SELECT * FROM advertisements WHERE id = :id LIMIT 1');
        $srcStmt->execute(['id' => $dupId]);
        $src = $srcStmt->fetch();
        if (!$src) {
            $ad_redirect('Ad not found.', 'error');
        }
        if ((string) $src['ad_type'] === 'external_code' && !$isSuperadmin) {
            $ad_redirect('External Ad Code ads can only be duplicated by superadmin accounts.', 'error');
        }
        unset($src['id'], $src['created_at'], $src['updated_at']);
        $src['name'] = ((string) $src['name']) . ' (Copy)';
        // Duplicates always start Inactive — an admin must deliberately
        // re-enable the copy, so two live ads never accidentally compete
        // for the same slot right after duplicating.
        $src['is_active']   = 0;
        $src['impressions'] = 0;
        $src['clicks']      = 0;
        $cols = array_keys($src);
        $placeholders = implode(', ', array_map(static fn (string $c): string => ':' . $c, $cols));
        $colList = implode(', ', array_map(static fn (string $c): string => "`$c`", $cols));
        $insert = $pdo->prepare("INSERT INTO advertisements ($colList, created_at, updated_at) VALUES ($placeholders, NOW(), NOW())");
        $insert->execute($src);
        $ad_redirect('Ad duplicated as an inactive copy — review and activate it when ready.', 'success', 'edit=' . $pdo->lastInsertId());
    }

    $name               = trim((string) ($_POST['name'] ?? ''));
    $title              = trim((string) ($_POST['title'] ?? ''));
    $adType             = in_array($_POST['ad_type'] ?? '', array_keys($AD_TYPES), true) ? $_POST['ad_type'] : 'image';
    $advertiserLabel    = trim((string) ($_POST['advertiser_label'] ?? '')) ?: 'Ad';
    $displayDomain      = trim((string) ($_POST['display_domain'] ?? ''));
    $headline           = trim((string) ($_POST['headline'] ?? ''));
    $description        = trim((string) ($_POST['description'] ?? ''));
    $bannerImage        = trim((string) ($_POST['banner_image'] ?? ''));
    $imageAlt           = trim((string) ($_POST['image_alt'] ?? ''));
    $videoUrl           = trim((string) ($_POST['video_url'] ?? ''));
    $videoPath          = trim((string) ($_POST['video_path'] ?? ''));
    $videoPoster        = trim((string) ($_POST['video_poster'] ?? ''));
    $videoMuted         = !empty($_POST['video_muted']) ? 1 : 0;
    $videoAutoplay      = ($videoMuted && !empty($_POST['video_autoplay'])) ? 1 : 0; // autoplay only ever honoured when muted
    $videoLoop          = !empty($_POST['video_loop']) ? 1 : 0;
    $videoControls      = !empty($_POST['video_controls']) ? 1 : 0;
    $htmlCodeRaw        = trim((string) ($_POST['html_code'] ?? ''));
    $externalCodeRaw    = trim((string) ($_POST['external_code'] ?? ''));
    $targetUrl          = trim((string) ($_POST['target_url'] ?? ''));
    $ctaText            = trim((string) ($_POST['cta_text'] ?? ''));
    $openInNewTab       = !empty($_POST['open_in_new_tab']) ? 1 : 0;
    $showSponsoredLabel = !empty($_POST['show_sponsored_label']) ? 1 : 0;
    $positionId         = (int) ($_POST['position_id'] ?? 0) ?: null;
    $scope              = in_array($_POST['placement_scope'] ?? '', array_keys($AD_SCOPES), true) ? $_POST['placement_scope'] : 'global';
    $targetId           = (int) ($_POST['placement_target_id'] ?? 0) ?: null;
    $device             = in_array($_POST['device'] ?? '', ['all', 'desktop', 'mobile', 'tablet'], true) ? $_POST['device'] : 'all';
    $startDate          = $ad_parse_date((string) ($_POST['start_date'] ?? ''));
    $endDate            = $ad_parse_date((string) ($_POST['end_date'] ?? ''));
    $isActive           = !empty($_POST['is_active']) ? 1 : 0;
    $sortOrder          = (int) ($_POST['sort_order'] ?? 0);

    // "category"/"article" scope only makes sense with those target IDs —
    // clear a stale target_id left over from switching scopes client-side.
    if (!in_array($scope, ['article', 'category'], true)) {
        $targetId = null;
    }

    if ($adType === 'external_code' && !$isSuperadmin) {
        $ad_redirect('External Ad Code is restricted to superadmin accounts.', 'error');
    }
    if ($action === 'update') {
        $updateId = (int) ($_POST['id'] ?? 0);
        $existingTypeStmt = $pdo->prepare('SELECT ad_type FROM advertisements WHERE id = :id');
        $existingTypeStmt->execute(['id' => $updateId]);
        $existingType = $existingTypeStmt->fetchColumn();
        if ($existingType === 'external_code' && !$isSuperadmin) {
            $ad_redirect('This ad uses External Ad Code and can only be edited by superadmin accounts.', 'error');
        }
    }

    // ---- Validation (section 19) ----
    $errors = [];
    if ($name === '') {
        $errors[] = 'Ad name is required.';
    }
    if ($positionId === null) {
        $errors[] = 'Position is required.';
    }
    switch ($adType) {
        case 'text':
            if ($headline === '') { $errors[] = 'Headline is required for Text Ad.'; }
            if ($description === '') { $errors[] = 'Description is required for Text Ad.'; }
            if ($targetUrl === '') { $errors[] = 'Target URL is required for Text Ad.'; }
            break;
        case 'image':
            if ($bannerImage === '') { $errors[] = 'Banner image is required for Image Banner.'; }
            if ($targetUrl === '') { $errors[] = 'Target URL is required for Image Banner.'; }
            break;
        case 'video':
            if ($videoPath === '' && $videoUrl === '') { $errors[] = 'A video file (Media Library) or video URL is required.'; }
            break;
        case 'html':
            if ($htmlCodeRaw === '') { $errors[] = 'HTML code is required for Custom HTML.'; }
            break;
        case 'external_code':
            if ($externalCodeRaw === '') { $errors[] = 'External ad code is required.'; }
            break;
    }
    if ($targetUrl !== '' && !preg_match('#^https?://#i', $targetUrl)) {
        $errors[] = 'Target URL must start with http:// or https://.';
    }
    if (mb_strlen($headline) > 90) { $errors[] = 'Headline must be 90 characters or fewer.'; }
    if (mb_strlen($description) > 180) { $errors[] = 'Description must be 180 characters or fewer.'; }
    if (mb_strlen($displayDomain) > 100) { $errors[] = 'Display domain must be 100 characters or fewer.'; }
    if (mb_strlen($ctaText) > 40) { $errors[] = 'CTA text must be 40 characters or fewer.'; }

    if ($errors !== []) {
        $ad_redirect(implode(' ', $errors), 'error');
    }

    // Custom HTML is sanitized at save time (never at render time — the
    // frontend trusts what's already in the DB). External Ad Code is
    // intentionally NOT run through this sanitizer: it's a different,
    // more trusted, superadmin-only field for legitimate ad-network embed
    // scripts that the HTML sanitizer would otherwise strip.
    $htmlCode = $htmlCodeRaw !== '' ? cms_sanitize_ad_html($htmlCodeRaw) : null;
    $externalCode = $externalCodeRaw !== '' ? $externalCodeRaw : null;

    $payload = [
        'name'                 => $name,
        'title'                => $title !== '' ? $title : null,
        'ad_type'              => $adType,
        'advertiser_label'     => $advertiserLabel,
        'display_domain'       => $displayDomain !== '' ? $displayDomain : null,
        'headline'             => $headline !== '' ? $headline : null,
        'description'          => $description !== '' ? $description : null,
        'banner_image'         => $bannerImage !== '' ? $bannerImage : null,
        'image_alt'            => $imageAlt !== '' ? $imageAlt : null,
        'video_url'            => $videoUrl !== '' ? $videoUrl : null,
        'video_path'           => $videoPath !== '' ? $videoPath : null,
        'video_poster'         => $videoPoster !== '' ? $videoPoster : null,
        'video_autoplay'       => $videoAutoplay,
        'video_muted'          => $videoMuted,
        'video_loop'           => $videoLoop,
        'video_controls'       => $videoControls,
        'html_code'            => $htmlCode,
        'external_code'        => $externalCode,
        'target_url'           => $targetUrl !== '' ? $targetUrl : null,
        'cta_text'             => $ctaText !== '' ? $ctaText : null,
        'open_in_new_tab'      => $openInNewTab,
        'show_sponsored_label' => $showSponsoredLabel,
        'position_id'          => $positionId,
        'placement_scope'      => $scope,
        'placement_target_id'  => $targetId,
        'device'               => $device,
        'start_date'           => $startDate,
        'end_date'             => $endDate,
        'is_active'            => $isActive,
        'sort_order'           => $sortOrder,
    ];

    if ($action === 'create') {
        $cols = array_keys($payload);
        $colList = implode(', ', array_map(static fn (string $c): string => "`$c`", $cols));
        $placeholders = implode(', ', array_map(static fn (string $c): string => ':' . $c, $cols));
        $insert = $pdo->prepare("INSERT INTO advertisements ($colList, created_at, updated_at) VALUES ($placeholders, NOW(), NOW())");
        $insert->execute($payload);
        $newId = (int) $pdo->lastInsertId();
        $ad_redirect('Ad created successfully.', 'success', 'edit=' . $newId);
    }

    if ($action === 'update') {
        $updateId = (int) ($_POST['id'] ?? 0);
        if ($updateId <= 0) {
            $ad_redirect('Invalid ad.', 'error');
        }
        $setList = implode(', ', array_map(static fn (string $c): string => "`$c` = :$c", array_keys($payload)));
        $update = $pdo->prepare("UPDATE advertisements SET $setList, updated_at = NOW() WHERE id = :id");
        $update->execute($payload + ['id' => $updateId]);
        $ad_redirect('Ad updated successfully.', 'success', 'edit=' . $updateId);
    }

    $ad_redirect('Unknown action.', 'error');
}

$alerts = [];
if (isset($_SESSION['cms_flash']) && is_array($_SESSION['cms_flash'])) {
    $alerts[] = $_SESSION['cms_flash'];
    unset($_SESSION['cms_flash']);
}
if ($ad_schemaError !== null) {
    $alerts[] = ['type' => 'error', 'message' => 'Ad setup could not run automatically: ' . $ad_schemaError];
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = null;
if ($editId > 0) {
    $editStmt = $pdo->prepare('SELECT * FROM advertisements WHERE id = :id LIMIT 1');
    $editStmt->execute(['id' => $editId]);
    $editRow = $editStmt->fetch() ?: null;
    if ($editRow === null) {
        $alerts[] = ['type' => 'error', 'message' => 'Ad not found.'];
        $editId = 0;
    } elseif ((string) $editRow['ad_type'] === 'external_code' && !$isSuperadmin) {
        $alerts[] = ['type' => 'error', 'message' => 'This ad uses External Ad Code — only superadmin accounts can view or edit it.'];
        $editRow = null;
        $editId = 0;
    }
}

$positions = $pdo->query('SELECT id, name FROM ad_positions ORDER BY name ASC')->fetchAll();

$ads = $pdo->query(
    'SELECT a.*, p.name AS position_name
     FROM advertisements a
     LEFT JOIN ad_positions p ON p.id = a.position_id
     ORDER BY a.sort_order ASC, a.id DESC'
)->fetchAll();

// Searchable placement-target pickers (section 6) — titles, not raw IDs.
$articleOptions  = $pdo->query("SELECT page_id, title FROM pages WHERE status = 'published' ORDER BY title ASC LIMIT 500")->fetchAll();
$categoryOptions = $pdo->query('SELECT id, name FROM article_categories ORDER BY name ASC')->fetchAll();

$currentArticleLabel = '';
$currentCategoryLabel = '';
if ($editRow && (int) ($editRow['placement_target_id'] ?? 0) > 0) {
    $tid = (int) $editRow['placement_target_id'];
    if ((string) $editRow['placement_scope'] === 'article') {
        foreach ($articleOptions as $opt) {
            if ((int) $opt['page_id'] === $tid) { $currentArticleLabel = (string) $opt['title']; break; }
        }
    } elseif ((string) $editRow['placement_scope'] === 'category') {
        foreach ($categoryOptions as $opt) {
            if ((int) $opt['id'] === $tid) { $currentCategoryLabel = (string) $opt['name']; break; }
        }
    }
}

$val = static function (array $row, string $key): string {
    return (string) ($row[$key] ?? '');
};

$fmtCtr = static function (array $row): string {
    $imp = (int) ($row['impressions'] ?? 0);
    $clk = (int) ($row['clicks'] ?? 0);
    if ($imp === 0) {
        return '—';
    }
    return number_format(($clk / $imp) * 100, 2) . '%';
};

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/sidebar.php';
require dirname(__DIR__) . '/includes/navbar.php';
require dirname(__DIR__) . '/includes/breadcrumb.php';
require dirname(__DIR__) . '/includes/alerts.php';
?>
<style>
/* Scoped to this page only — the searchable target pickers, ad-type field
   groups, and the in-admin live preview panel are specific enough to
   Advertisements that they don't belong in the global admin.css. */
.ad-form-section { grid-column: 1 / -1; display: grid; gap: 16px; padding-top: 4px; margin-top: 4px; border-top: 1px dashed var(--border, rgba(255,255,255,.12)); }
.ad-form-section__title { font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; opacity: .7; }
.ad-char-count { font-size: 11.5px; opacity: .6; text-align: right; }
.ad-char-count.is-over { color: #f87171; opacity: 1; }
.ad-security-note { font-size: 12.5px; padding: 10px 12px; border-radius: 8px; background: rgba(251, 191, 36, .1); border: 1px solid rgba(251, 191, 36, .3); }
.ad-preview { border: 1px solid var(--border, rgba(255,255,255,.12)); border-radius: 12px; padding: 16px; background: rgba(0,0,0,.15); }
.ad-preview__modes { display: flex; gap: 6px; margin-bottom: 12px; flex-wrap: wrap; }
.ad-preview__modes button { font-size: 12px; padding: 5px 10px; border-radius: 999px; border: 1px solid var(--border, rgba(255,255,255,.15)); background: transparent; color: inherit; cursor: pointer; opacity: .7; }
.ad-preview__modes button.is-active { opacity: 1; background: rgba(139,92,246,.18); border-color: #8b5cf6; }
.ad-preview__frame { margin: 0 auto; width: 100%; max-width: 640px; transition: max-width .2s ease; }
.ad-preview__frame.mode-mobile { max-width: 340px; }
.ad-preview__frame.mode-sidebar { max-width: 280px; }
.ad-preview__frame.mode-inline { max-width: 620px; }
.ad-preview__unit { border-radius: 10px; overflow: hidden; border: 1px solid rgba(255,255,255,.12); background: rgba(255,255,255,.03); padding: 14px; }
.ad-preview__meta { display: flex; align-items: center; gap: 6px; font-size: 11px; color: rgba(255,255,255,.5); margin-bottom: 4px; }
.ad-preview__badge { font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #22d3ee; border: 1px solid rgba(34,211,238,.35); border-radius: 4px; padding: 1px 6px; }
.ad-preview__headline { font-size: 16px; font-weight: 700; margin-bottom: 4px; }
.ad-preview__desc { font-size: 13px; color: rgba(255,255,255,.65); margin-bottom: 8px; }
.ad-preview__cta { display: inline-block; font-size: 12.5px; font-weight: 700; color: #a855f7; }
.ad-preview__img { max-width: 100%; display: block; border-radius: 8px; }
.ad-preview__placeholder { font-size: 13px; color: rgba(255,255,255,.45); font-style: italic; }
.ad-target-picker { position: relative; }

/* ── Advertisement form grid (14 Jul 2026 layout fix) ──────────────────
   The generic global `.form-grid` (admin.css) has no align-items rule and
   assumes every field is a simple single-row label. The Advertisement
   form mixes short fields, fields with helper text, full-width sections,
   and fields that get shown/hidden by JS (the article/category target
   pickers) — under plain 2-column grid auto-placement that combination
   made Device end up sharing a row with whichever target picker happened
   to be visible, instead of always sitting predictably next to Sort
   order. `.ad-form-grid` fixes this structurally (not with per-field
   margins) and is scoped to this page only, so it never affects the
   many other admin pages that still use `.form-grid`. */
.ad-form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 24px 20px;
    align-items: start;
    padding: 18px 20px;
}
.ad-form-grid .field { min-width: 0; }
/* Full-width rows inside the 2-column grid — anything that shouldn't
   compete for column space with its neighbour. */
.ad-form-grid .ad-form-section,
.ad-form-grid .ad-target-pickers,
.ad-form-grid .field--checkbox,
.ad-form-grid .form-grid__actions {
    grid-column: 1 / -1;
}
/* The two searchable target pickers (article/category) live in their own
   full-width row directly under Placement scope, decoupled entirely from
   the 2-column flow — only one is ever visible at a time (JS toggles
   display per placement_scope), and keeping them out of the main column
   flow means Device (and everything after it) never shifts position
   depending on which one is showing. */
.ad-target-pickers { display: grid; gap: 16px; }
.ad-target-pickers .field { grid-column: 1 / -1; }

@media (max-width: 1024px) {
    .ad-form-grid { grid-template-columns: 1fr; }
}

/* Edit form + Live Preview side-by-side panel — collapses to a single
   column on tablet/mobile instead of squeezing both panels narrow enough
   to visually collide. */
.ad-edit-layout {
    display: grid;
    grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr);
    gap: 20px;
    align-items: start;
}
@media (max-width: 1100px) {
    .ad-edit-layout { grid-template-columns: 1fr; }
}
</style>
<section class="admin-stack">
    <div class="toolbar">
        <div class="toolbar__left">
            <h2 class="section-title">Advertisements</h2>
            <p class="section-lead">Manage ad units (text, image, video, custom HTML, external code), placement, scheduling, and performance.</p>
        </div>
        <div class="toolbar__right">
            <a class="admin-btn admin-btn--primary" href="<?= cms_esc($editRow ? $selfUrl : $selfUrl . '#create-ad') ?>">+ New Ad</a>
            <a class="admin-btn admin-btn--secondary" href="ad-positions.php">Positions</a>
            <a class="admin-btn admin-btn--secondary" href="ad-settings.php">Settings</a>
            <a class="admin-btn admin-btn--secondary" href="ad-statistics.php">Statistics</a>
        </div>
    </div>

    <?php
    $adForm = $editRow;
    $formAction = $editRow ? 'update' : 'create';
    $formTitle = $editRow ? 'Edit Ad' : 'New Ad';
    $curType = $adForm ? $val($adForm, 'ad_type') : 'text';
    $curScope = $adForm ? $val($adForm, 'placement_scope') : 'global';
    ?>
    <div class="ad-edit-layout">
    <div class="panel" id="create-ad">
        <div class="panel__head">
            <h3 class="panel__title"><?= cms_esc($formTitle) ?></h3>
            <?php if ($editRow) : ?><a class="panel__link" href="<?= cms_esc($selfUrl) ?>">Cancel edit</a><?php endif; ?>
        </div>
        <form class="ad-form-grid" method="post" action="<?= cms_esc($selfUrl) ?>" id="ad-form">
            <?= cms_csrf_field() ?>
            <input type="hidden" name="action" value="<?= $formAction ?>">
            <?php if ($editRow) : ?><input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>"><?php endif; ?>

            <label class="field">Ad name (internal)
                <input type="text" name="name" required value="<?= $adForm ? cms_esc($val($adForm, 'name')) : '' ?>" placeholder="e.g. Homepage Hero — Exchange XYZ">
            </label>
            <label class="field">Display title (optional, internal)
                <input type="text" name="title" value="<?= $adForm ? cms_esc($val($adForm, 'title')) : '' ?>">
            </label>

            <label class="field">Ad type
                <select name="ad_type" id="ad-type-select">
                    <?php foreach ($AD_TYPES as $tVal => $tLabel) :
                        $disabled = ($tVal === 'external_code' && !$isSuperadmin); ?>
                        <option value="<?= $tVal ?>"<?= $curType === $tVal ? ' selected' : '' ?><?= $disabled ? ' disabled' : '' ?>><?= cms_esc($tLabel) ?><?= $disabled ? ' (superadmin only)' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">Position
                <select name="position_id" required>
                    <option value="">— Choose a position —</option>
                    <?php foreach ($positions as $pos) : ?>
                        <option value="<?= (int) $pos['id'] ?>"<?= $adForm && (int) ($adForm['position_id'] ?? 0) === (int) $pos['id'] ? ' selected' : '' ?>><?= cms_esc($pos['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <!-- ── Text Ad fields ─────────────────────────────────────── -->
            <div class="ad-form-section ad-field-text">
                <div class="ad-form-section__title">Text Ad</div>
                <label class="field">Advertiser label
                    <input type="text" name="advertiser_label" maxlength="50" value="<?= $adForm ? cms_esc($val($adForm, 'advertiser_label')) : 'Ad' ?>" placeholder="Ad">
                </label>
                <label class="field">Display domain <span class="field__hint">(shown next to the label, e.g. exchange.com)</span>
                    <input type="text" name="display_domain" maxlength="100" data-counter="ad-count-domain" value="<?= $adForm ? cms_esc($val($adForm, 'display_domain')) : '' ?>" placeholder="exchange.com">
                    <span class="ad-char-count" id="ad-count-domain">0/100</span>
                </label>
                <label class="field">Headline
                    <input type="text" name="headline" maxlength="90" data-counter="ad-count-headline" value="<?= $adForm ? cms_esc($val($adForm, 'headline')) : '' ?>" placeholder="e.g. Watch Live Scores in Real Time">
                    <span class="ad-char-count" id="ad-count-headline">0/90</span>
                </label>
                <label class="field">Description
                    <textarea name="description" rows="2" maxlength="180" data-counter="ad-count-desc" placeholder="Short supporting line under the headline."><?= $adForm ? cms_esc($val($adForm, 'description')) : '' ?></textarea>
                    <span class="ad-char-count" id="ad-count-desc">0/180</span>
                </label>
                <label class="field field--checkbox">
                    <input type="checkbox" name="open_in_new_tab" value="1"<?= (!$adForm || (int) ($adForm['open_in_new_tab'] ?? 1) === 1) ? ' checked' : '' ?>>
                    <span class="field--checkbox__text">
                        <span class="field--checkbox__title">Open link in a new tab</span>
                        <span class="field--checkbox__desc">When off, the ad opens in the same tab. New-tab links always get rel="sponsored nofollow noopener".</span>
                    </span>
                </label>
                <label class="field field--checkbox">
                    <input type="checkbox" name="show_sponsored_label" value="1"<?= (!$adForm || (int) ($adForm['show_sponsored_label'] ?? 1) === 1) ? ' checked' : '' ?>>
                    <span class="field--checkbox__text">
                        <span class="field--checkbox__title">Show "Ad" / sponsored label</span>
                        <span class="field--checkbox__desc">Displays the advertiser label + domain disclosure above the headline.</span>
                    </span>
                </label>
            </div>

            <!-- ── Image Banner fields ────────────────────────────────── -->
            <div class="ad-form-section ad-field-image">
                <div class="ad-form-section__title">Image Banner</div>
                <label class="field">Banner image path
                    <input type="text" name="banner_image" id="ad-banner-input" value="<?= $adForm ? cms_esc($val($adForm, 'banner_image')) : '' ?>" placeholder="e.g. /uploads/media/2026/07/banner.webp" autocomplete="off">
                    <button type="button" class="admin-btn admin-btn--secondary js-ad-img-pick" style="margin-top:6px;align-self:flex-start;">Choose from Media Library</button>
                </label>
                <label class="field">Image alt text
                    <input type="text" name="image_alt" maxlength="150" value="<?= $adForm ? cms_esc($val($adForm, 'image_alt')) : '' ?>" placeholder="Describes the banner for accessibility/SEO">
                </label>
            </div>

            <!-- ── Video Ad fields ────────────────────────────────────── -->
            <div class="ad-form-section ad-field-video">
                <div class="ad-form-section__title">Video Ad</div>
                <label class="field">Video file path (Media Library)
                    <input type="text" name="video_path" id="ad-video-input" value="<?= $adForm ? cms_esc($val($adForm, 'video_path')) : '' ?>" placeholder="e.g. /uploads/media/2026/07/ad.mp4" autocomplete="off">
                    <button type="button" class="admin-btn admin-btn--secondary js-ad-video-pick" style="margin-top:6px;align-self:flex-start;">Choose from Media Library</button>
                </label>
                <label class="field">…or Video URL <span class="field__hint">(used if no file above)</span>
                    <input type="text" name="video_url" value="<?= $adForm ? cms_esc($val($adForm, 'video_url')) : '' ?>" placeholder="https://...">
                </label>
                <label class="field">Poster image path
                    <input type="text" name="video_poster" id="ad-poster-input" value="<?= $adForm ? cms_esc($val($adForm, 'video_poster')) : '' ?>" placeholder="e.g. /uploads/media/2026/07/ad-poster.jpg">
                    <button type="button" class="admin-btn admin-btn--secondary js-ad-poster-pick" style="margin-top:6px;align-self:flex-start;">Choose from Media Library</button>
                </label>
                <label class="field field--checkbox">
                    <input type="checkbox" name="video_muted" id="ad-video-muted" value="1"<?= (!$adForm || (int) ($adForm['video_muted'] ?? 1) === 1) ? ' checked' : '' ?>>
                    <span class="field--checkbox__text">
                        <span class="field--checkbox__title">Muted</span>
                        <span class="field--checkbox__desc">Required for autoplay to work — browsers block unmuted autoplay.</span>
                    </span>
                </label>
                <label class="field field--checkbox">
                    <input type="checkbox" name="video_autoplay" id="ad-video-autoplay" value="1"<?= ($adForm && (int) ($adForm['video_autoplay'] ?? 0) === 1) ? ' checked' : '' ?>>
                    <span class="field--checkbox__text">
                        <span class="field--checkbox__title">Autoplay</span>
                        <span class="field--checkbox__desc">Only ever plays while the ad is scrolled into view, and only if Muted is also on.</span>
                    </span>
                </label>
                <label class="field field--checkbox">
                    <input type="checkbox" name="video_loop" value="1"<?= ($adForm && (int) ($adForm['video_loop'] ?? 0) === 1) ? ' checked' : '' ?>>
                    <span class="field--checkbox__text">
                        <span class="field--checkbox__title">Loop</span>
                    </span>
                </label>
                <label class="field field--checkbox">
                    <input type="checkbox" name="video_controls" value="1"<?= (!$adForm || (int) ($adForm['video_controls'] ?? 1) === 1) ? ' checked' : '' ?>>
                    <span class="field--checkbox__text">
                        <span class="field--checkbox__title">Show player controls</span>
                    </span>
                </label>
            </div>

            <!-- ── Custom HTML fields ─────────────────────────────────── -->
            <div class="ad-form-section ad-field-html">
                <div class="ad-form-section__title">Custom HTML</div>
                <div class="ad-security-note">Sanitized automatically on save — &lt;script&gt;, &lt;iframe&gt;, event-handler attributes, and javascript:/data: URLs are stripped. For trusted ad-network embed scripts, use External Ad Code instead.</div>
                <label class="field">HTML code
                    <textarea name="html_code" rows="6" style="font-family:monospace;font-size:12px;" placeholder="&lt;div&gt;...&lt;/div&gt;"><?= $adForm ? cms_esc($val($adForm, 'html_code')) : '' ?></textarea>
                </label>
            </div>

            <!-- ── External Ad Code fields ────────────────────────────── -->
            <div class="ad-form-section ad-field-external_code">
                <div class="ad-form-section__title">External Ad Code</div>
                <?php if ($isSuperadmin) : ?>
                    <div class="ad-security-note">Superadmin only. Rendered exactly as entered (NOT sanitized) — only paste code from ad networks you trust. Never rendered in the All Ads list preview.</div>
                    <label class="field">Ad network embed code
                        <textarea name="external_code" rows="6" style="font-family:monospace;font-size:12px;" placeholder="Full &lt;script&gt;/&lt;ins&gt; embed code from your ad network"><?= $adForm ? cms_esc($val($adForm, 'external_code')) : '' ?></textarea>
                    </label>
                <?php else : ?>
                    <div class="ad-security-note">External Ad Code is restricted to superadmin accounts. Ask a superadmin to create or edit this ad type.</div>
                <?php endif; ?>
            </div>

            <label class="field">Target URL (click-through)
                <input type="text" name="target_url" value="<?= $adForm ? cms_esc($val($adForm, 'target_url')) : '' ?>" placeholder="https://...">
            </label>
            <label class="field">CTA text <span class="field__hint">(≤40 chars)</span>
                <input type="text" name="cta_text" maxlength="40" value="<?= $adForm ? cms_esc($val($adForm, 'cta_text')) : '' ?>" placeholder="e.g. Daftar Sekarang">
            </label>

            <label class="field">Placement scope
                <select name="placement_scope" id="ad-scope-select">
                    <?php foreach ($AD_SCOPES as $sVal => $sLabel) : ?>
                        <option value="<?= $sVal ?>"<?= $curScope === $sVal ? ' selected' : '' ?>><?= cms_esc($sLabel) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="field__hint">"Mobile only"/"Desktop only" is controlled by the Device field, not here.</span>
            </label>

            <!-- Full-width row, decoupled from the 2-column flow on purpose:
                 only one of these two pickers is ever visible at a time (JS
                 toggles them by placement_scope), so keeping them out of the
                 normal column pairing means Device below always lines up
                 predictably regardless of which picker is showing. -->
            <div class="ad-target-pickers">
                <input type="hidden" name="placement_target_id" id="ad-target-id" value="<?= $adForm && $adForm['placement_target_id'] !== null ? (int) $adForm['placement_target_id'] : '' ?>">
                <label class="field ad-target-picker ad-target-article-wrap">Specific article <span class="field__hint">(leave blank = all articles)</span>
                    <input type="text" id="ad-target-article" list="ad-articles-list" placeholder="Start typing an article title…" value="<?= cms_esc($currentArticleLabel) ?>" autocomplete="off">
                    <datalist id="ad-articles-list">
                        <?php foreach ($articleOptions as $opt) : ?>
                            <option value="<?= cms_esc((string) $opt['title']) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </label>
                <label class="field ad-target-picker ad-target-category-wrap">Specific category <span class="field__hint">(leave blank = all categories)</span>
                    <input type="text" id="ad-target-category" list="ad-categories-list" placeholder="Start typing a category name…" value="<?= cms_esc($currentCategoryLabel) ?>" autocomplete="off">
                    <datalist id="ad-categories-list">
                        <?php foreach ($categoryOptions as $opt) : ?>
                            <option value="<?= cms_esc((string) $opt['name']) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </label>
            </div>

            <label class="field">Device
                <select name="device">
                    <?php $curDevice = $adForm ? $val($adForm, 'device') : 'all'; ?>
                    <option value="all"<?= $curDevice === 'all' ? ' selected' : '' ?>>All devices</option>
                    <option value="desktop"<?= $curDevice === 'desktop' ? ' selected' : '' ?>>Desktop only</option>
                    <option value="mobile"<?= $curDevice === 'mobile' ? ' selected' : '' ?>>Mobile only</option>
                    <option value="tablet"<?= $curDevice === 'tablet' ? ' selected' : '' ?>>Tablet only</option>
                </select>
            </label>
            <label class="field">Sort order <span class="field__hint">(lower = higher priority when several ads share a position)</span>
                <input type="number" name="sort_order" value="<?= $adForm ? (int) $adForm['sort_order'] : 0 ?>">
            </label>

            <label class="field">Start date
                <input type="date" name="start_date" value="<?= $adForm ? cms_esc($val($adForm, 'start_date')) : '' ?>">
            </label>
            <label class="field">End date
                <input type="date" name="end_date" value="<?= $adForm ? cms_esc($val($adForm, 'end_date')) : '' ?>">
            </label>

            <label class="field field--checkbox">
                <input type="checkbox" name="is_active" value="1"<?= (!$adForm || (int) ($adForm['is_active'] ?? 1) === 1) ? ' checked' : '' ?>>
                <span class="field--checkbox__text">
                    <span class="field--checkbox__title">Active</span>
                    <span class="field--checkbox__desc">Turn off to hide this ad everywhere without deleting it.</span>
                </span>
            </label>

            <div class="form-grid__actions">
                <button type="submit" class="admin-btn admin-btn--primary"><?= $editRow ? 'Save changes' : 'Create Ad' ?></button>
                <?php if ($editRow) : ?><a class="admin-btn admin-btn--secondary" href="<?= cms_esc($selfUrl) ?>">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Live Preview</h3>
        </div>
        <div class="ad-preview">
            <div class="ad-preview__modes">
                <button type="button" data-mode="desktop" class="is-active">Desktop</button>
                <button type="button" data-mode="mobile">Mobile</button>
                <button type="button" data-mode="sidebar">Sidebar</button>
                <button type="button" data-mode="inline">Inside article</button>
            </div>
            <div class="ad-preview__frame" id="ad-preview-frame">
                <div class="ad-preview__unit" id="ad-preview-unit"></div>
            </div>
        </div>
    </div>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">All Ads</h3>
            <span class="panel__meta"><?= count($ads) ?> ad(s)</span>
        </div>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Position</th>
                        <th>Scope</th>
                        <th>Device</th>
                        <th>Impressions</th>
                        <th>Clicks</th>
                        <th>CTR</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($ads === []) : ?>
                        <tr><td colspan="10" class="muted">No ads yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($ads as $ad) :
                        if ((string) $ad['ad_type'] === 'external_code' && !$isSuperadmin) {
                            continue; // never surfaced to non-superadmin, per section 10.
                        }
                        $status = $ad_status_of($ad);
                    ?>
                        <tr>
                            <td><?= cms_esc((string) $ad['name']) ?><?php if (!empty($ad['title'])) : ?><br><small class="muted"><?= cms_esc((string) $ad['title']) ?></small><?php endif; ?></td>
                            <td><span class="pill pill--muted"><?= cms_esc($AD_TYPES[$ad['ad_type']] ?? (string) $ad['ad_type']) ?></span></td>
                            <td><?= cms_esc((string) ($ad['position_name'] ?? '—')) ?></td>
                            <td><?= cms_esc((string) $ad['placement_scope']) ?></td>
                            <td><?= cms_esc((string) $ad['device']) ?></td>
                            <td><?= (int) $ad['impressions'] ?></td>
                            <td><?= (int) $ad['clicks'] ?></td>
                            <td><?= $fmtCtr($ad) ?></td>
                            <td>
                                <span class="pill pill--<?= cms_esc($status['tone']) ?>"><?= cms_esc($status['label']) ?></span>
                                <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>" style="margin-top:4px;">
                                    <?= cms_csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="id" value="<?= (int) $ad['id'] ?>">
                                    <button type="submit" class="admin-btn admin-btn--sm admin-btn--secondary"><?= (int) $ad['is_active'] === 1 ? 'Disable' : 'Enable' ?></button>
                                </form>
                            </td>
                            <td class="table-actions">
                                <a class="admin-btn admin-btn--sm admin-btn--secondary" href="<?= cms_esc($selfUrl) ?>?edit=<?= (int) $ad['id'] ?>">Edit</a>
                                <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>">
                                    <?= cms_csrf_field() ?>
                                    <input type="hidden" name="action" value="duplicate">
                                    <input type="hidden" name="id" value="<?= (int) $ad['id'] ?>">
                                    <button type="submit" class="admin-btn admin-btn--sm admin-btn--secondary">Duplicate</button>
                                </form>
                                <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>" onsubmit="return confirm('Delete this ad?');">
                                    <?= cms_csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $ad['id'] ?>">
                                    <button type="submit" class="admin-btn admin-btn--sm admin-btn--danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php require dirname(__DIR__) . '/includes/tinymce-media-picker.php'; ?>
<script>
(function () {
    var PUBLIC_PREFIX = <?= json_encode(cms_public_base_prefix(), JSON_HEX_TAG) ?>;
    var form = document.getElementById('ad-form');
    var typeSelect = document.getElementById('ad-type-select');
    var fieldGroups = {
        text: document.querySelector('.ad-field-text'),
        image: document.querySelector('.ad-field-image'),
        video: document.querySelector('.ad-field-video'),
        html: document.querySelector('.ad-field-html'),
        external_code: document.querySelector('.ad-field-external_code')
    };

    function syncType() {
        var t = typeSelect.value;
        Object.keys(fieldGroups).forEach(function (key) {
            if (fieldGroups[key]) { fieldGroups[key].style.display = (key === t) ? '' : 'none'; }
        });
        updatePreview();
    }
    if (typeSelect) {
        typeSelect.addEventListener('change', syncType);
        syncType();
    }

    // Placement scope → show/hide the matching searchable target picker.
    var scopeSelect = document.getElementById('ad-scope-select');
    var articleWrap = document.querySelector('.ad-target-article-wrap');
    var categoryWrap = document.querySelector('.ad-target-category-wrap');
    var articleInput = document.getElementById('ad-target-article');
    var categoryInput = document.getElementById('ad-target-category');
    var targetIdInput = document.getElementById('ad-target-id');

    <?php // JSON_HEX_TAG: article titles/category names come from user content
    // (editor role can set article titles) and are printed straight into this
    // <script> block — without it, a title containing "</script>" would close
    // the block early and run as JavaScript in whichever admin has this page
    // open (privilege escalation, not just self-XSS — see docs/DECISIONS.md
    // 2026-07-15 on the 3-tier RBAC). \uXXXX-escaped angle brackets decode
    // back to "<"/">" when the browser parses this object literal, so the
    // articleTitleToId/categoryNameToId lookups below are unaffected. ?>
    <?php echo 'var articleTitleToId = ' . json_encode(array_column($articleOptions, 'page_id', 'title'), JSON_HEX_TAG) . ';'; ?>
    <?php echo 'var categoryNameToId = ' . json_encode(array_column($categoryOptions, 'id', 'name'), JSON_HEX_TAG) . ';'; ?>

    function syncScope() {
        var s = scopeSelect.value;
        if (articleWrap) { articleWrap.style.display = (s === 'article') ? '' : 'none'; }
        if (categoryWrap) { categoryWrap.style.display = (s === 'category') ? '' : 'none'; }
        if (s !== 'article' && articleInput) { articleInput.value = ''; }
        if (s !== 'category' && categoryInput) { categoryInput.value = ''; }
        if (s !== 'article' && s !== 'category' && targetIdInput) { targetIdInput.value = ''; }
    }
    if (scopeSelect) {
        scopeSelect.addEventListener('change', syncScope);
        syncScope();
    }
    if (articleInput) {
        articleInput.addEventListener('input', function () {
            var id = articleTitleToId[articleInput.value];
            targetIdInput.value = id ? id : '';
        });
    }
    if (categoryInput) {
        categoryInput.addEventListener('input', function () {
            var id = categoryNameToId[categoryInput.value];
            targetIdInput.value = id ? id : '';
        });
    }

    // Autoplay only ever allowed while Muted is checked.
    var mutedBox = document.getElementById('ad-video-muted');
    var autoplayBox = document.getElementById('ad-video-autoplay');
    function syncAutoplay() {
        if (!mutedBox || !autoplayBox) { return; }
        if (!mutedBox.checked) { autoplayBox.checked = false; autoplayBox.disabled = true; }
        else { autoplayBox.disabled = false; }
    }
    if (mutedBox) { mutedBox.addEventListener('change', syncAutoplay); syncAutoplay(); }

    // Live character counters.
    document.querySelectorAll('[data-counter]').forEach(function (input) {
        var counter = document.getElementById(input.getAttribute('data-counter'));
        if (!counter) { return; }
        var max = parseInt(input.getAttribute('maxlength'), 10) || 0;
        function sync() {
            var len = input.value.length;
            counter.textContent = len + '/' + max;
            counter.classList.toggle('is-over', len > max);
        }
        input.addEventListener('input', sync);
        sync();
    });

    // ---- Live Preview (in-admin only, never fetches the real frontend) ----
    var previewFrame = document.getElementById('ad-preview-frame');
    var previewUnit = document.getElementById('ad-preview-unit');
    document.querySelectorAll('.ad-preview__modes button').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.ad-preview__modes button').forEach(function (b) { b.classList.remove('is-active'); });
            btn.classList.add('is-active');
            previewFrame.className = 'ad-preview__frame mode-' + btn.getAttribute('data-mode');
        });
    });

    function fieldVal(name) {
        var el = form.querySelector('[name="' + name + '"]');
        return el ? el.value : '';
    }
    function fieldChecked(name) {
        var el = form.querySelector('[name="' + name + '"]');
        return el ? el.checked : false;
    }
    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }
    function publicUrl(path) {
        if (!path) { return ''; }
        return PUBLIC_PREFIX + path.replace(/^\//, '');
    }

    function updatePreview() {
        if (!previewUnit) { return; }
        var t = typeSelect ? typeSelect.value : 'text';
        var html = '';

        if (t === 'text') {
            var showLabel = fieldChecked('show_sponsored_label');
            var meta = '';
            if (showLabel) {
                meta = '<div class="ad-preview__meta"><span class="ad-preview__badge">' + esc(fieldVal('advertiser_label') || 'Ad') + '</span>';
                if (fieldVal('display_domain')) { meta += '<span>&middot; ' + esc(fieldVal('display_domain')) + '</span>'; }
                meta += '</div>';
            }
            html = meta
                + '<div class="ad-preview__headline">' + (esc(fieldVal('headline')) || '<span class="ad-preview__placeholder">Headline will appear here</span>') + '</div>'
                + (fieldVal('description') ? '<div class="ad-preview__desc">' + esc(fieldVal('description')) + '</div>' : '')
                + (fieldVal('cta_text') ? '<div class="ad-preview__cta">' + esc(fieldVal('cta_text')) + ' &rarr;</div>' : '');
        } else if (t === 'image') {
            var imgPath = fieldVal('banner_image');
            html = imgPath
                ? '<img class="ad-preview__img" src="' + publicUrl(imgPath) + '" alt="' + esc(fieldVal('image_alt')) + '">'
                : '<div class="ad-preview__placeholder">Choose a banner image to preview it here.</div>';
            if (fieldVal('cta_text')) { html += '<div class="ad-preview__cta" style="margin-top:8px;">' + esc(fieldVal('cta_text')) + '</div>'; }
        } else if (t === 'video') {
            var vPath = fieldVal('video_path') || fieldVal('video_url');
            var poster = fieldVal('video_poster');
            if (vPath) {
                html = '<video class="ad-preview__img" style="width:100%;" ' + (poster ? 'poster="' + publicUrl(poster) + '" ' : '') + 'controls muted></video>';
                html += '<script>document.currentScript.previousElementSibling.src = ' + JSON.stringify(vPath.indexOf('http') === 0 ? vPath : publicUrl(vPath)) + ';<\/script>';
            } else {
                html = '<div class="ad-preview__placeholder">Choose a video file or paste a video URL to preview it here.</div>';
            }
        } else if (t === 'html') {
            html = '<div class="ad-preview__placeholder">Custom HTML preview is intentionally not rendered live here for safety — check the frontend after saving.</div>';
        } else if (t === 'external_code') {
            html = '<div class="ad-preview__placeholder">External Ad Code is never rendered in preview or in the admin list — it will run on the live frontend once saved.</div>';
        }

        previewUnit.innerHTML = html;
    }
    if (form) {
        form.addEventListener('input', updatePreview);
        form.addEventListener('change', updatePreview);
    }
    updatePreview();

    // Reuse the media-library picker modal (shared with pages.php's include)
    // for banner image, video file, and poster image fields.
    var modal    = document.getElementById('mce-ml-modal');
    var search   = document.getElementById('mce-ml-search');
    var backdrop = document.getElementById('mce-ml-backdrop');
    var closeBtn = document.getElementById('mce-ml-close');
    if (!modal) { return; }

    var _targetInput = null;
    function openPicker(input) {
        _targetInput = input;
        if (search) { search.value = ''; search.dispatchEvent(new Event('input')); search.focus(); }
        modal.hidden = false;
    }
    function closePicker() { _targetInput = null; modal.hidden = true; }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-ad-img-pick, .js-ad-video-pick, .js-ad-poster-pick');
        if (!btn) { return; }
        var inputId = btn.classList.contains('js-ad-img-pick') ? 'ad-banner-input'
            : (btn.classList.contains('js-ad-video-pick') ? 'ad-video-input' : 'ad-poster-input');
        var input = document.getElementById(inputId);
        if (!input) { return; }
        openPicker(input);
    });
    document.addEventListener('click', function (e) {
        if (!_targetInput) { return; }
        var item = e.target.closest('.mce-ml-item');
        if (!item || modal.hidden) { return; }
        var path = item.getAttribute('data-path') || '';
        if (path) { _targetInput.value = path; _targetInput.dispatchEvent(new Event('input')); }
        closePicker();
    });
    function onDismiss() { _targetInput = null; }
    if (backdrop) { backdrop.addEventListener('click', onDismiss); }
    if (closeBtn) { closeBtn.addEventListener('click', onDismiss); }
    document.addEventListener('keydown', function (e) {
        if (!modal.hidden && (e.key === 'Escape' || e.key === 'Esc')) { onDismiss(); }
    });
})();
</script>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
