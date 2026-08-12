<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';
require_once dirname(__DIR__) . '/includes/sitemap-service.php';

cms_sitemap_ensure_schema($pdo);

/**
 * Auto-migration: article categories/tags support. Idempotent — checks
 * INFORMATION_SCHEMA first, safe to run on every page load. Seeds the
 * default category set on first creation only.
 */
$pg_schemaError = null;
try {
    $pg_categoriesCreated = cms_ensure_table(
        $pdo,
        'article_categories',
        'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
         name VARCHAR(100) NOT NULL,
         slug VARCHAR(120) NOT NULL,
         created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
         updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
         UNIQUE KEY uniq_article_category_slug (slug)'
    );
    cms_ensure_table(
        $pdo,
        'article_tags',
        'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
         name VARCHAR(100) NOT NULL,
         slug VARCHAR(120) NOT NULL,
         created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
         UNIQUE KEY uniq_article_tag_slug (slug)'
    );
    cms_ensure_table(
        $pdo,
        'article_tag_map',
        'page_id INT NOT NULL,
         tag_id INT UNSIGNED NOT NULL,
         PRIMARY KEY (page_id, tag_id),
         KEY idx_article_tag_map_tag (tag_id)'
    );

    cms_ensure_column($pdo, 'pages', 'category_id', 'INT UNSIGNED DEFAULT NULL AFTER `slug`');
    cms_ensure_column($pdo, 'pages', 'league_id', 'INT UNSIGNED DEFAULT NULL AFTER `category_id`');
    // Which sport (sports.key — football/basketball/motorsport) an article
    // is about, independent of league_id (football-only). Powers the
    // homepage's sport filter chips (see wpm_sport_filter_row()). Nullable —
    // most existing articles predate the multi-sport pivot and have none.
    cms_ensure_column($pdo, 'pages', 'sport_key', 'VARCHAR(20) DEFAULT NULL AFTER `league_id`');
    cms_ensure_column($pdo, 'pages', 'author_id', 'INT UNSIGNED DEFAULT NULL AFTER `league_id`');
    cms_ensure_column($pdo, 'pages', 'meta_keywords', 'VARCHAR(255) DEFAULT NULL AFTER `meta_description`');
    cms_ensure_column($pdo, 'pages', 'canonical_url', 'VARCHAR(255) DEFAULT NULL AFTER `meta_keywords`');
    cms_ensure_column($pdo, 'pages', 'og_image', 'VARCHAR(255) DEFAULT NULL AFTER `canonical_url`');
    cms_ensure_column($pdo, 'pages', 'is_featured', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `og_image`');
    cms_ensure_column($pdo, 'pages', 'is_trending', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_featured`');
    cms_ensure_column($pdo, 'pages', 'views', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER `is_trending`');

    if ($pg_categoriesCreated) {
        $seedCats = [
            'Sepak Bola', 'Liga Champions', 'Liga Inggris', 'Transfer', 'Timnas',
            'Business', 'Sports', 'Livescore', 'Apps', 'Guides', 'General News',
        ];
        $seedStmt = $pdo->prepare('INSERT IGNORE INTO article_categories (name, slug) VALUES (:name, :slug)');
        foreach ($seedCats as $catName) {
            $seedStmt->execute(['name' => $catName, 'slug' => cms_slugify($catName)]);
        }
    }
} catch (Throwable $e) {
    $pg_schemaError = $e->getMessage();
}

$pageTitle = 'Pages & Articles';
$currentNav = 'pages';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'Pages & Articles', 'href' => ''],
];

$selfUrl = 'pages.php';

$pg_redirect = static function (string $message, string $type = 'success', ?string $query = null) use ($selfUrl): void {
    $_SESSION['cms_flash'] = ['type' => $type, 'message' => $message];
    $target = $selfUrl . ($query !== null && $query !== '' ? '?' . $query : '');
    header('Location: ' . $target, true, 302);
    exit;
};

// sport_key concept removed entirely 11/12 Agu 2026 (Opsi A, operator
// decision — see CLAUDE.md) — olahraga77.com doesn't integrate any
// livescore API, so there's no `sports` table of API-tracked sports to
// validate against here anymore (that table + its is_active toggle system
// belonged to the livescore feature, deleted from this codebase's
// cms-admin). Articles here are classified by `category_id` only (see
// $categoryId below), same as any other content-only site.

$pg_validate = static function (string $title, string $slug, string $status) : ?string {
    if ($title === '') {
        return 'Title is required.';
    }
    if ($slug === '') {
        return 'Slug is required.';
    }
    if (!in_array($status, ['draft', 'published'], true)) {
        return 'Status must be draft or published.';
    }

    return null;
};

$pg_duplicate_slug = static function (PDO $pdo, string $slug, ?int $excludeId): ?string {
    $sql = 'SELECT COUNT(*) FROM pages WHERE slug = :slug';
    $params = ['slug' => $slug];
    if ($excludeId !== null) {
        $sql .= ' AND page_id != :id';
        $params['id'] = $excludeId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    if ((int) $stmt->fetchColumn() > 0) {
        return 'That slug is already in use.';
    }

    return null;
};

$pg_parse_published_at = static function (string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $ts = strtotime($raw);
    return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete') {
        $deleteId = (int) ($_POST['page_id'] ?? 0);
        if ($deleteId <= 0) {
            $pg_redirect('Invalid article.', 'error');
        }
        $delete = $pdo->prepare('DELETE FROM pages WHERE page_id = :id');
        $delete->execute(['id' => $deleteId]);
        if ($delete->rowCount() < 1) {
            $pg_redirect('Article not found or already deleted.', 'error');
        }
        // Sitemaps module: hard deletes in this codebase have no
        // trash/restore step, so the sitemap entry is simply flagged
        // 'deleted' + excluded (never dropped outright, stays auditable).
        cms_sitemap_on_article_delete($pdo, $deleteId);
        $pg_redirect('Article deleted successfully.');
    }

    $title = trim((string) ($_POST['title'] ?? ''));
    $slug = trim((string) ($_POST['slug'] ?? ''));
    $featuredImage = trim((string) ($_POST['featured_image'] ?? ''));
    $excerpt = trim((string) ($_POST['excerpt'] ?? ''));
    $content = trim((string) ($_POST['content'] ?? ''));
    $metaTitle = trim((string) ($_POST['meta_title'] ?? ''));
    $metaDescription = trim((string) ($_POST['meta_description'] ?? ''));
    $faqJson = trim((string) ($_POST['faq_json'] ?? ''));
    $status = strtolower(trim((string) ($_POST['status'] ?? '')));
    $publishedAt = $pg_parse_published_at((string) ($_POST['published_at'] ?? ''));

    // ── Phase 2 additions: category, author, tags, extra SEO, featured/trending ──
    $categoryId   = (int) ($_POST['category_id'] ?? 0) ?: null;
    $leagueId     = (int) ($_POST['league_id'] ?? 0) ?: null;
    $authorId     = (int) ($_POST['author_id'] ?? 0) ?: (int) ($_SESSION['cms_admin_id'] ?? 0) ?: null;
    $metaKeywords = trim((string) ($_POST['meta_keywords'] ?? ''));
    $canonicalUrl = trim((string) ($_POST['canonical_url'] ?? ''));
    $ogImage      = trim((string) ($_POST['og_image'] ?? ''));
    $isFeatured   = !empty($_POST['is_featured']) ? 1 : 0;
    $isTrending   = !empty($_POST['is_trending']) ? 1 : 0;
    $noindex      = !empty($_POST['noindex']) ? 1 : 0;
    $tagsRaw      = trim((string) ($_POST['tags'] ?? ''));

    // Auto-fill published_at when publishing without a date — keeps draft saves clean.
    // wpm_now_wib(), NOT date() (9 Aug 2026 fix, same class as the Growth
    // Agent auto-publish timezone bug, docs/DECISIONS.md) — every reader
    // of published_at (artikel.php's wpm_format_date()/wpm_time_ago(),
    // JSON-LD datePublished) now explicitly treats it as WIB, so the write
    // side must actually store WIB, not this server's UTC default.
    if ($status === 'published' && $publishedAt === null) {
        require_once dirname(__DIR__) . '/../includes/TimeHelpers.php';
        $publishedAt = wpm_now_wib()->format('Y-m-d H:i:s');
    }

    // Validate faq_json only when provided — empty is always allowed.
    if ($faqJson !== '') {
        $faqDecoded = json_decode($faqJson, true);
        if (!is_array($faqDecoded)) {
            $faqErrorQuery = null;
            if ($action === 'update') {
                $faqFailId = (int) ($_POST['page_id'] ?? 0);
                if ($faqFailId > 0) {
                    $faqErrorQuery = 'edit=' . $faqFailId;
                }
            }
            $pg_redirect('FAQ JSON tidak valid. Periksa kembali atau kosongkan field tersebut.', 'error', $faqErrorQuery);
        }
    }

    $validationError = $pg_validate($title, $slug, $status);
    if ($validationError !== null) {
        $errorQuery = null;
        if ($action === 'update') {
            $failId = (int) ($_POST['page_id'] ?? 0);
            if ($failId > 0) {
                $errorQuery = 'edit=' . $failId;
            }
        }
        $pg_redirect($validationError, 'error', $errorQuery);
    }

    $payload = [
        'title'            => $title,
        'slug'             => $slug,
        'featured_image'   => $featuredImage,
        'excerpt'          => $excerpt,
        'content'          => $content,
        'meta_title'       => $metaTitle,
        'meta_description' => $metaDescription,
        'faq_json'         => $faqJson !== '' ? $faqJson : null,
        'status'           => $status,
        'published_at'     => $publishedAt,
        'category_id'      => $categoryId,
        'league_id'        => $leagueId,
        'author_id'        => $authorId,
        'meta_keywords'    => $metaKeywords !== '' ? $metaKeywords : null,
        'canonical_url'    => $canonicalUrl !== '' ? $canonicalUrl : null,
        'og_image'         => $ogImage !== '' ? $ogImage : null,
        'is_featured'      => $isFeatured,
        'is_trending'      => $isTrending,
        'noindex'          => $noindex,
    ];

    /**
     * Resolve a comma-separated tag string into tag IDs, creating any tag
     * that doesn't exist yet. Returns an array of int IDs (deduped).
     */
    $pg_syncTags = static function (PDO $pdo, int $pageId, string $tagsRaw): void {
        $pdo->prepare('DELETE FROM article_tag_map WHERE page_id = :id')->execute(['id' => $pageId]);

        $names = array_filter(array_map('trim', explode(',', $tagsRaw)));
        if ($names === []) {
            return;
        }

        $findStmt   = $pdo->prepare('SELECT id FROM article_tags WHERE slug = :slug LIMIT 1');
        $insertStmt = $pdo->prepare('INSERT INTO article_tags (name, slug) VALUES (:name, :slug)');
        $mapStmt    = $pdo->prepare('INSERT IGNORE INTO article_tag_map (page_id, tag_id) VALUES (:page_id, :tag_id)');

        foreach ($names as $name) {
            $slug = cms_slugify($name);
            if ($slug === '') {
                continue;
            }
            $findStmt->execute(['slug' => $slug]);
            $tagId = $findStmt->fetchColumn();
            if ($tagId === false) {
                $insertStmt->execute(['name' => $name, 'slug' => $slug]);
                $tagId = (int) $pdo->lastInsertId();
            }
            $mapStmt->execute(['page_id' => $pageId, 'tag_id' => (int) $tagId]);
        }
    };

    if ($action === 'create') {
        $dupError = $pg_duplicate_slug($pdo, $slug, null);
        if ($dupError !== null) {
            $pg_redirect($dupError, 'error');
        }

        $insert = $pdo->prepare(
            'INSERT INTO pages (
                title, slug, featured_image, content, excerpt, meta_title, meta_description,
                faq_json, status, published_at, category_id, league_id, author_id, meta_keywords,
                canonical_url, og_image, is_featured, is_trending, noindex, created_at, updated_at
            ) VALUES (
                :title, :slug, :featured_image, :content, :excerpt, :meta_title, :meta_description,
                :faq_json, :status, :published_at, :category_id, :league_id, :author_id, :meta_keywords,
                :canonical_url, :og_image, :is_featured, :is_trending, :noindex, NOW(), NOW()
            )'
        );
        $insert->execute($payload);
        $newId = (int) $pdo->lastInsertId();
        $pg_syncTags($pdo, $newId, $tagsRaw);
        cms_sitemap_on_article_save($pdo, [], $payload + ['page_id' => $newId]);
        $pg_redirect('Article created successfully.', 'success', 'edit=' . $newId);
    }

    if ($action === 'update') {
        $updateId = (int) ($_POST['page_id'] ?? 0);
        if ($updateId <= 0) {
            $pg_redirect('Invalid article.', 'error');
        }

        $dupError = $pg_duplicate_slug($pdo, $slug, $updateId);
        if ($dupError !== null) {
            $pg_redirect($dupError, 'error', 'edit=' . $updateId);
        }

        // Sitemaps module needs the pre-save row to detect publish/
        // unpublish transitions and slug changes.
        $pg_oldStmt = $pdo->prepare('SELECT * FROM pages WHERE page_id = :id LIMIT 1');
        $pg_oldStmt->execute(['id' => $updateId]);
        $pg_oldRow = $pg_oldStmt->fetch() ?: [];

        $update = $pdo->prepare(
            'UPDATE pages
             SET title = :title,
                 slug = :slug,
                 featured_image = :featured_image,
                 content = :content,
                 excerpt = :excerpt,
                 meta_title = :meta_title,
                 meta_description = :meta_description,
                 faq_json = :faq_json,
                 status = :status,
                 published_at = :published_at,
                 category_id = :category_id,
                 league_id = :league_id,
                 author_id = :author_id,
                 meta_keywords = :meta_keywords,
                 canonical_url = :canonical_url,
                 og_image = :og_image,
                 is_featured = :is_featured,
                 is_trending = :is_trending,
                 noindex = :noindex,
                 updated_at = NOW()
             WHERE page_id = :id'
        );
        $update->execute($payload + ['id' => $updateId]);
        $pg_syncTags($pdo, $updateId, $tagsRaw);
        cms_sitemap_on_article_save($pdo, $pg_oldRow, $payload + ['page_id' => $updateId]);
        if ($update->rowCount() < 1) {
            $exists = $pdo->prepare('SELECT page_id FROM pages WHERE page_id = :id LIMIT 1');
            $exists->execute(['id' => $updateId]);
            if (!$exists->fetch()) {
                $pg_redirect('Article not found.', 'error');
            }
        }
        $pg_redirect('Article updated successfully.', 'success', 'edit=' . $updateId);
    }

    $pg_redirect('Unknown action.', 'error');
}

$alerts = [];
if (isset($_SESSION['cms_flash']) && is_array($_SESSION['cms_flash'])) {
    $alerts[] = $_SESSION['cms_flash'];
    unset($_SESSION['cms_flash']);
}
if ($pg_schemaError !== null) {
    $alerts[] = [
        'type'    => 'error',
        'message' => 'Category/tag setup could not run automatically: ' . $pg_schemaError,
    ];
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = null;

// ---- Article list: search + status filter + pagination ----
$listSearchRaw = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
if (mb_strlen($listSearchRaw, 'UTF-8') > 100) {
    $listSearchRaw = mb_substr($listSearchRaw, 0, 100, 'UTF-8');
}
$listStatus = isset($_GET['status']) ? strtolower(trim((string) $_GET['status'])) : '';
if (!in_array($listStatus, ['published', 'draft'], true)) {
    $listStatus = '';
}
$listCategoryId = (int) ($_GET['category_id'] ?? 0);

$listPerPage  = 20;
$listPage     = max(1, (int) ($_GET['page'] ?? 1));

$listWhere  = [];
$listParams = [];
if ($listSearchRaw !== '') {
    $listEscaped          = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $listSearchRaw);
    $listWhere[]          = '(p.title LIKE :search OR p.slug LIKE :search)';
    $listParams['search'] = '%' . $listEscaped . '%';
}
if ($listStatus !== '') {
    $listWhere[]                 = 'p.status = :status_filter';
    $listParams['status_filter'] = $listStatus;
}
if ($listCategoryId > 0) {
    $listWhere[]              = 'p.category_id = :category_filter';
    $listParams['category_filter'] = $listCategoryId;
}

$listWhereClause = $listWhere !== [] ? ' WHERE ' . implode(' AND ', $listWhere) : '';

// Count matching rows to compute pagination
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM pages p' . $listWhereClause);
$countStmt->execute($listParams);
$listTotalRows  = (int) $countStmt->fetchColumn();
$listTotalPages = max(1, (int) ceil($listTotalRows / $listPerPage));

// Clamp page to valid range after knowing total
if ($listPage > $listTotalPages) {
    $listPage = $listTotalPages;
}
$listOffset = ($listPage - 1) * $listPerPage;

// Fetch one page of results
$listSql  = 'SELECT p.page_id, p.title, p.slug, p.status, p.published_at, p.updated_at,
                    p.is_featured, p.is_trending, p.views, p.featured_image, c.name AS category_name,
                    a.name AS author_name
             FROM pages p
             LEFT JOIN article_categories c ON c.id = p.category_id
             LEFT JOIN admins a ON a.admin_id = p.author_id'
          . $listWhereClause
          . ' ORDER BY p.page_id DESC'
          . ' LIMIT :limit OFFSET :offset';
$listStmt = $pdo->prepare($listSql);
// Bind integer params explicitly — PDO LIMIT/OFFSET requires PDO::PARAM_INT
$listStmt->bindValue(':limit',  $listPerPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $listOffset,  PDO::PARAM_INT);
foreach ($listParams as $key => $val_) {
    $listStmt->bindValue(':' . $key, $val_);
}
$listStmt->execute();
$pagesList = $listStmt->fetchAll();

// ---- Article stats (full table, not filtered) ----
$statsRow = $pdo->query(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) AS published_count,
            SUM(CASE WHEN status = 'draft'     THEN 1 ELSE 0 END) AS draft_count
     FROM pages"
)->fetch();
$articleStats = is_array($statsRow) ? $statsRow : ['total' => 0, 'published_count' => 0, 'draft_count' => 0];

// ---- Pagination URL helper ----
// Preserves search + status params; edit param intentionally excluded.
$paginateUrl = static function (int $targetPage) use ($listSearchRaw, $listStatus, $listCategoryId, $selfUrl): string {
    $q = [];
    if ($listSearchRaw !== '') {
        $q['search'] = $listSearchRaw;
    }
    if ($listStatus !== '') {
        $q['status'] = $listStatus;
    }
    if ($listCategoryId > 0) {
        $q['category_id'] = $listCategoryId;
    }
    $q['page'] = $targetPage;
    return $selfUrl . '?' . http_build_query($q);
};

if ($editId > 0) {
    $editStmt = $pdo->prepare(
        'SELECT page_id, title, slug, featured_image, content, excerpt, meta_title, meta_description,
                faq_json, status, published_at, category_id, league_id, sport_key, author_id, meta_keywords,
                canonical_url, og_image, is_featured, is_trending, noindex, views
         FROM pages
         WHERE page_id = :id
         LIMIT 1'
    );
    $editStmt->execute(['id' => $editId]);
    $editRow = $editStmt->fetch() ?: null;
    if ($editRow === null) {
        $alerts[] = ['type' => 'error', 'message' => 'Article not found.'];
        $editId = 0;
    }
}

// ---- Categories & authors for the form dropdowns ----
$articleCategories = $pdo->query('SELECT id, name FROM article_categories ORDER BY name ASC')->fetchAll();
$articleLeagues = [];
try {
    $articleLeagues = $pdo->query('SELECT id, name FROM leagues WHERE is_active = 1 ORDER BY sort_order ASC, name ASC')->fetchAll();
} catch (Throwable $e) {
    $articleLeagues = [];
}
// $articleSports fetched earlier (near $pg_validate), reused here.
$articleAuthors = $pdo->query('SELECT admin_id, name FROM admins ORDER BY name ASC')->fetchAll();

// ---- Existing tag names, for the <datalist> autocomplete on the Tags field ----
$allTagNames = [];
try {
    $allTagNames = array_column($pdo->query('SELECT name FROM article_tags ORDER BY name ASC')->fetchAll(), 'name');
} catch (Throwable $e) {
    $allTagNames = [];
}

// ---- Current tags for the edit form, as a comma-separated string ----
$editTagsString = '';
if ($editRow !== null) {
    $tagStmt = $pdo->prepare(
        'SELECT t.name FROM article_tags t
         INNER JOIN article_tag_map m ON m.tag_id = t.id
         WHERE m.page_id = :id
         ORDER BY t.name ASC'
    );
    $tagStmt->execute(['id' => (int) $editRow['page_id']]);
    $editTagsString = implode(', ', array_column($tagStmt->fetchAll(), 'name'));
}

$formatDt = static function (?string $value): string {
    if ($value === null || $value === '') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts !== false ? date('d M Y, H:i', $ts) : $value;
};

$toDatetimeLocal = static function (?string $value): string {
    if ($value === null || $value === '') {
        return '';
    }
    $ts = strtotime($value);
    return $ts !== false ? date('Y-m-d\TH:i', $ts) : '';
};

$val = static function (array $row, string $key): string {
    return (string) ($row[$key] ?? '');
};

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/sidebar.php';
require dirname(__DIR__) . '/includes/navbar.php';
require dirname(__DIR__) . '/includes/breadcrumb.php';
require dirname(__DIR__) . '/includes/alerts.php';
?>
<datalist id="pg-tags-datalist">
    <?php foreach ($allTagNames as $tagName) : ?>
        <option value="<?= cms_esc($tagName) ?>"></option>
    <?php endforeach; ?>
</datalist>
<section class="admin-stack">
    <div class="toolbar">
        <div class="toolbar__left">
            <h2 class="section-title">Articles & Content</h2>
            <p class="section-lead">Create and manage articles, guides, tips, and SEO-friendly content for the website.</p>
        </div>
        <div class="toolbar__right">
            <a class="admin-btn admin-btn--primary" href="<?= cms_esc($editRow ? $selfUrl : $selfUrl . '#create-page') ?>">New Article</a>
        </div>
    </div>

    <?php if ($editRow) : ?>
    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Edit Article</h3>
            <span class="panel__meta" style="margin-right:auto;margin-left:12px;"><?= (int) ($editRow['views'] ?? 0) ?> views</span>
            <a class="panel__link" href="preview-article.php?id=<?= (int) $editRow['page_id'] ?>" target="_blank" rel="noopener">Preview</a>
            <a class="panel__link" href="<?= cms_esc($selfUrl) ?>">Cancel edit</a>
        </div>
        <form class="form-grid" method="post" action="<?= cms_esc($selfUrl) ?>">
            <?= cms_csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="page_id" value="<?= (int) $editRow['page_id'] ?>">
            <label class="field">Title
                <input type="text" name="title" value="<?= cms_esc($val($editRow, 'title')) ?>" required>
            </label>
            <label class="field">Slug
                <input type="text" name="slug" value="<?= cms_esc($val($editRow, 'slug')) ?>" required>
            </label>
            <label class="field">Featured image
                <input type="text" name="featured_image" id="pg-feat-img-edit" class="js-pg-img-input"
                       value="<?= cms_esc($val($editRow, 'featured_image')) ?>"
                       placeholder="e.g. /uploads/media/2026/05/file.webp"
                       autocomplete="off">
                <img class="cms-path-upload__preview js-pg-img-preview" alt="Preview featured image"
                     <?= ($fiPrev = app_asset_preview_url($val($editRow, 'featured_image'))) !== '' ? 'src="' . cms_esc($fiPrev) . '"' : 'hidden' ?>>
                <small class="field__hint field__hint--error js-pg-img-error" hidden>Gambar tidak ditemukan.</small>
                <button type="button" class="admin-btn admin-btn--secondary js-pg-img-pick" data-target="featured_image"
                        style="margin-top:6px;align-self:flex-start;">Choose from Media Library</button>
                <small style="font-size:11px;color:var(--muted,#888);display:block;margin-top:6px;">Recommended: 1200 × 630 px. JPG, PNG, atau WEBP. Maks. 5 MB.</small>
            </label>
            <label class="field">Open Graph image
                <input type="text" name="og_image" id="pg-og-img-edit" class="js-pg-img-input"
                       value="<?= cms_esc($val($editRow, 'og_image')) ?>"
                       placeholder="Kosongkan = pakai Featured image"
                       autocomplete="off">
                <img class="cms-path-upload__preview js-pg-img-preview" alt="Preview Open Graph image"
                     <?= ($ogPrev = app_asset_preview_url($val($editRow, 'og_image'))) !== '' ? 'src="' . cms_esc($ogPrev) . '"' : 'hidden' ?>>
                <small class="field__hint field__hint--error js-pg-img-error" hidden>Gambar tidak ditemukan.</small>
                <button type="button" class="admin-btn admin-btn--secondary js-pg-img-pick" data-target="og_image"
                        style="margin-top:6px;align-self:flex-start;">Choose from Media Library</button>
                <small style="font-size:11px;color:var(--muted,#888);display:block;margin-top:6px;">Gambar khusus untuk preview share ke Facebook/Twitter/WhatsApp. 1200 × 630 px.</small>
            </label>
            <label class="field">Category
                <select name="category_id">
                    <option value="">— No category —</option>
                    <?php foreach ($articleCategories as $cat) : ?>
                        <option value="<?= (int) $cat['id'] ?>"<?= (int) ($editRow['category_id'] ?? 0) === (int) $cat['id'] ? ' selected' : '' ?>><?= cms_esc($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <!-- Field Liga disembunyikan sementara (25 Jul 2026) — fungsinya belum jelas
                 terhubung ke frontend, lihat investigasi terpisah. Jangan hapus, tinggal
                 ubah `if (false)` jadi `if (true)` kalau sudah diputuskan dipakai. Kolom
                 league_id di database dan data lama TIDAK disentuh oleh perubahan ini —
                 form tetap submit league_id apa adanya (kosong untuk artikel baru), field
                 ini sebelumnya juga sudah opsional jadi tidak ada validasi yang terpengaruh. -->
            <?php if (false) : ?>
            <label class="field">Liga
                <select name="league_id">
                    <option value="">— Tidak terkait liga —</option>
                    <?php foreach ($articleLeagues as $league) : ?>
                        <option value="<?= (int) $league['id'] ?>"<?= (int) ($editRow['league_id'] ?? 0) === (int) $league['id'] ? ' selected' : '' ?>><?= cms_esc($league['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php endif; ?>
            <?php // "Cabang Sport" (sport_key) dropdown removed 11/12 Agu 2026 —
            // Opsi A, sport_key dihapus total di situs ini (lihat CLAUDE.md).
            // Artikel diklasifikasikan lewat kategori (di bawah) saja. ?>
            <label class="field">Author
                <select name="author_id">
                    <option value="">— Default (current admin) —</option>
                    <?php foreach ($articleAuthors as $author) : ?>
                        <option value="<?= (int) $author['admin_id'] ?>"<?= (int) ($editRow['author_id'] ?? 0) === (int) $author['admin_id'] ? ' selected' : '' ?>><?= cms_esc($author['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field" style="grid-column: 1 / -1;">Tags
                <input type="text" name="tags" class="js-tags-input" value="<?= cms_esc($editTagsString) ?>" placeholder="pisahkan dengan koma, mis: Messi, Liga Inggris, transfer" list="pg-tags-datalist">
            </label>
            <label class="field field--checkbox">
                <input type="checkbox" name="is_featured" value="1"<?= (int) ($editRow['is_featured'] ?? 0) === 1 ? ' checked' : '' ?>>
                <span class="field--checkbox__text">
                    <span class="field--checkbox__title">Artikel unggulan (Featured)</span>
                </span>
            </label>
            <label class="field field--checkbox">
                <input type="checkbox" name="is_trending" value="1"<?= (int) ($editRow['is_trending'] ?? 0) === 1 ? ' checked' : '' ?>>
                <span class="field--checkbox__text">
                    <span class="field--checkbox__title">Artikel trending</span>
                </span>
            </label>
            <?php $editStatus = strtolower($val($editRow, 'status')); ?>
            <label class="field field--status">Status
                <select name="status" required>
                    <option value="draft"<?= $editStatus === 'draft' ? ' selected' : '' ?>>draft</option>
                    <option value="published"<?= $editStatus === 'published' ? ' selected' : '' ?>>published</option>
                </select>
            </label>
            <label class="field">Published at
                <input type="datetime-local" name="published_at" value="<?= cms_esc($toDatetimeLocal($editRow['published_at'] ?? null)) ?>">
            </label>
            <label class="field">Meta title
                <input type="text" name="meta_title" value="<?= cms_esc($val($editRow, 'meta_title')) ?>">
            </label>
            <!-- Meta keywords disembunyikan dari UI (29 Jul 2026) — field ini
                 tersimpan di DB tapi tidak pernah dirender ke <head> halaman
                 publik manapun (Google sendiri sudah mengabaikan tag ini
                 sejak 2009), jadi cuma bikin bingung admin tanpa efek SEO
                 nyata. Tetap dikirim sebagai hidden input supaya nilai lama
                 yang sudah tersimpan tidak ke-null-kan setiap kali artikel
                 ini disave. -->
            <input type="hidden" name="meta_keywords" value="<?= cms_esc($val($editRow, 'meta_keywords')) ?>">
            <label class="field" style="grid-column: 1 / -1;">Canonical URL
                <input type="text" name="canonical_url" value="<?= cms_esc($val($editRow, 'canonical_url')) ?>" placeholder="Kosongkan = pakai URL artikel ini">
            </label>
            <label class="field field--checkbox" style="grid-column: 1 / -1;">
                <input type="checkbox" name="noindex" value="1"<?= (int) ($editRow['noindex'] ?? 0) === 1 ? ' checked' : '' ?>>
                <span class="field--checkbox__text">
                    <span class="field--checkbox__title">Exclude from search engines (noindex)</span>
                    <span class="field--checkbox__desc">Removes this article from the sitemap and adds a noindex tag on the public page.</span>
                </span>
            </label>
            <label class="field" style="grid-column: 1 / -1;">Meta description
                <textarea name="meta_description" rows="3"><?= cms_esc($val($editRow, 'meta_description')) ?></textarea>
            </label>
            <label class="field" style="grid-column: 1 / -1;">Excerpt
                <textarea name="excerpt" rows="3"><?= cms_esc($val($editRow, 'excerpt')) ?></textarea>
            </label>
            <label class="field" style="grid-column: 1 / -1;">Content
                <textarea name="content" id="pg-content" rows="6"><?= cms_esc($val($editRow, 'content')) ?></textarea>
            </label>
            <label class="field" style="grid-column: 1 / -1;">FAQ JSON
                <textarea name="faq_json" rows="8" style="font-family:monospace;font-size:12px;" placeholder="Klik Generate FAQ untuk mengisi otomatis, atau tulis JSON manual."><?= cms_esc($val($editRow, 'faq_json')) ?></textarea>
                <small style="font-size:11px;color:var(--muted,#888);margin-top:3px;">Diisi otomatis oleh AI. Periksa dan edit sebelum menyimpan. Boleh dikosongkan.</small>
            </label>
            <div style="grid-column: 1 / -1;">
                <!-- Notes for Agent SEO — visible by default, no name attr so never submitted to DB -->
                <div style="margin-top:12px;">
                    <label class="field" style="max-width:480px;">Catatan untuk Agent SEO
                        <textarea class="pg-article-notes" rows="4"
                                  placeholder="Target audience description.&#10;Tone and language guidelines.&#10;Mention the site or brand naturally.&#10;Include 3–5 FAQ items.&#10;Add a CTA to the site at the end.&#10;&#10;More examples:&#10;- Focus on a specific region&#10;- Focus on corporate gifting&#10;- Focus on a product category&#10;- Target a specific demographic"></textarea>
                    </label>
                    <small style="font-size:11px;color:var(--muted,#888);display:block;margin-top:3px;">
                        Tips: gunakan catatan untuk memberi instruksi tambahan ke Agent SEO. Catatan ini akan menimpa arahan umum jika ada konflik.
                    </small>
                </div>
                <!-- AI action buttons -->
                <div class="pg-ai-actions">
                    <div class="pg-ai-actions__item">
                        <button type="button" class="admin-btn admin-btn--secondary js-generate-seo">Generate SEO</button>
                        <span class="js-seo-status pg-ai-actions__status"></span>
                    </div>
                    <div class="pg-ai-actions__item">
                        <button type="button" class="admin-btn admin-btn--secondary js-generate-article">Generate Article</button>
                        <span class="js-article-status pg-ai-actions__status"></span>
                    </div>
                    <div class="pg-ai-actions__item">
                        <button type="button" class="admin-btn admin-btn--secondary js-generate-faq">Generate FAQ</button>
                        <span class="js-faq-status pg-ai-actions__status"></span>
                    </div>
                </div>
                <!-- Helper boxes -->
                <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:8px;">
                    <div class="pg-seo-helper">
                        <span class="pg-seo-helper__label">Generate SEO with Agent SEO</span>
                        <ul class="pg-seo-helper__list">
                            <li>Meta Title</li>
                            <li>Meta Description</li>
                        </ul>
                    </div>
                    <div class="pg-seo-helper">
                        <span class="pg-seo-helper__label">Generate Article with Agent SEO</span>
                        <ul class="pg-seo-helper__list">
                            <li>Excerpt</li>
                            <li>Content</li>
                            <li>Meta Title</li>
                            <li>Meta Description</li>
                        </ul>
                    </div>
                    <div class="pg-seo-helper">
                        <span class="pg-seo-helper__label">Generate FAQ with Agent SEO</span>
                        <ul class="pg-seo-helper__list">
                            <li>5 FAQ items</li>
                            <li>Question &amp; Answer</li>
                            <li>Bahasa Indonesia</li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="form-grid__actions">
                <button type="submit" class="admin-btn admin-btn--primary">Save changes</button>
                <a class="admin-btn admin-btn--secondary" href="<?= cms_esc($selfUrl) ?>">Cancel</a>
            </div>
        </form>
    </div>
    <?php else : ?>
    <div class="panel" id="create-page">
        <div class="panel__head">
            <h3 class="panel__title">New Article</h3>
        </div>
        <form class="form-grid" method="post" action="<?= cms_esc($selfUrl) ?>">
            <?= cms_csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <label class="field">Title
                <input type="text" name="title" required>
            </label>
            <label class="field">Slug
                <input type="text" name="slug" required>
            </label>
            <label class="field">Featured image
                <input type="text" name="featured_image" id="pg-feat-img-create" class="js-pg-img-input"
                       placeholder="e.g. /uploads/media/2026/05/file.webp"
                       autocomplete="off">
                <img class="cms-path-upload__preview js-pg-img-preview" alt="Preview featured image" hidden>
                <small class="field__hint field__hint--error js-pg-img-error" hidden>Gambar tidak ditemukan.</small>
                <button type="button" class="admin-btn admin-btn--secondary js-pg-img-pick" data-target="featured_image"
                        style="margin-top:6px;align-self:flex-start;">Choose from Media Library</button>
                <small style="font-size:11px;color:var(--muted,#888);display:block;margin-top:6px;">Recommended: 1200 × 630 px. JPG, PNG, atau WEBP. Maks. 5 MB.</small>
            </label>
            <label class="field">Open Graph image
                <input type="text" name="og_image" id="pg-og-img-create" class="js-pg-img-input"
                       placeholder="Kosongkan = pakai Featured image"
                       autocomplete="off">
                <img class="cms-path-upload__preview js-pg-img-preview" alt="Preview Open Graph image" hidden>
                <small class="field__hint field__hint--error js-pg-img-error" hidden>Gambar tidak ditemukan.</small>
                <button type="button" class="admin-btn admin-btn--secondary js-pg-img-pick" data-target="og_image"
                        style="margin-top:6px;align-self:flex-start;">Choose from Media Library</button>
                <small style="font-size:11px;color:var(--muted,#888);display:block;margin-top:6px;">Gambar khusus untuk preview share ke Facebook/Twitter/WhatsApp. 1200 × 630 px.</small>
            </label>
            <label class="field">Category
                <select name="category_id">
                    <option value="">— No category —</option>
                    <?php foreach ($articleCategories as $cat) : ?>
                        <option value="<?= (int) $cat['id'] ?>"><?= cms_esc($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <!-- Field Liga disembunyikan sementara (25 Jul 2026) — fungsinya belum jelas
                 terhubung ke frontend, lihat investigasi terpisah. Jangan hapus, tinggal
                 ubah `if (false)` jadi `if (true)` kalau sudah diputuskan dipakai. -->
            <?php if (false) : ?>
            <label class="field">Liga
                <select name="league_id">
                    <option value="">— Tidak terkait liga —</option>
                    <?php foreach ($articleLeagues as $league) : ?>
                        <option value="<?= (int) $league['id'] ?>"><?= cms_esc($league['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php endif; ?>
            <?php // "Cabang Sport" (sport_key) dropdown removed 11/12 Agu 2026 —
            // Opsi A, sport_key dihapus total di situs ini (lihat CLAUDE.md). ?>
            <label class="field">Author
                <select name="author_id">
                    <option value="">— Default (current admin) —</option>
                    <?php foreach ($articleAuthors as $author) : ?>
                        <option value="<?= (int) $author['admin_id'] ?>"><?= cms_esc($author['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field" style="grid-column: 1 / -1;">Tags
                <input type="text" name="tags" class="js-tags-input" placeholder="pisahkan dengan koma, mis: Messi, Liga Inggris, transfer" list="pg-tags-datalist">
            </label>
            <label class="field field--checkbox">
                <input type="checkbox" name="is_featured" value="1">
                <span class="field--checkbox__text">
                    <span class="field--checkbox__title">Artikel unggulan (Featured)</span>
                </span>
            </label>
            <label class="field field--checkbox">
                <input type="checkbox" name="is_trending" value="1">
                <span class="field--checkbox__text">
                    <span class="field--checkbox__title">Artikel trending</span>
                </span>
            </label>
            <label class="field field--status">Status
                <select name="status" required>
                    <option value="draft" selected>draft</option>
                    <option value="published">published</option>
                </select>
            </label>
            <label class="field">Published at
                <input type="datetime-local" name="published_at">
            </label>
            <label class="field">Meta title
                <input type="text" name="meta_title">
            </label>
            <!-- Meta keywords disembunyikan dari UI (29 Jul 2026) — lihat
                 catatan di form edit di atas untuk alasannya. -->
            <input type="hidden" name="meta_keywords" value="">
            <label class="field" style="grid-column: 1 / -1;">Canonical URL
                <input type="text" name="canonical_url" placeholder="Kosongkan = pakai URL artikel ini">
            </label>
            <label class="field field--checkbox" style="grid-column: 1 / -1;">
                <input type="checkbox" name="noindex" value="1">
                <span class="field--checkbox__text">
                    <span class="field--checkbox__title">Exclude from search engines (noindex)</span>
                    <span class="field--checkbox__desc">Removes this article from the sitemap and adds a noindex tag on the public page.</span>
                </span>
            </label>
            <label class="field" style="grid-column: 1 / -1;">Meta description
                <textarea name="meta_description" rows="3"></textarea>
            </label>
            <label class="field" style="grid-column: 1 / -1;">Excerpt
                <textarea name="excerpt" rows="3"></textarea>
            </label>
            <label class="field" style="grid-column: 1 / -1;">Content
                <textarea name="content" id="pg-content" rows="6"></textarea>
            </label>
            <label class="field" style="grid-column: 1 / -1;">FAQ JSON
                <textarea name="faq_json" rows="8" style="font-family:monospace;font-size:12px;" placeholder="Klik Generate FAQ untuk mengisi otomatis, atau tulis JSON manual."></textarea>
                <small style="font-size:11px;color:var(--muted,#888);margin-top:3px;">Diisi otomatis oleh AI. Periksa dan edit sebelum menyimpan. Boleh dikosongkan.</small>
            </label>
            <div style="grid-column: 1 / -1;">
                <!-- Notes for Agent SEO — visible by default, no name attr so never submitted to DB -->
                <div style="margin-top:12px;">
                    <label class="field" style="max-width:480px;">Catatan untuk Agent SEO
                        <textarea class="pg-article-notes" rows="4"
                                  placeholder="Target audience description.&#10;Tone and language guidelines.&#10;Mention the site or brand naturally.&#10;Include 3–5 FAQ items.&#10;Add a CTA to the site at the end.&#10;&#10;More examples:&#10;- Focus on a specific region&#10;- Focus on corporate gifting&#10;- Focus on a product category&#10;- Target a specific demographic"></textarea>
                    </label>
                    <small style="font-size:11px;color:var(--muted,#888);display:block;margin-top:3px;">
                        Tips: gunakan catatan untuk memberi instruksi tambahan ke Agent SEO. Catatan ini akan menimpa arahan umum jika ada konflik.
                    </small>
                </div>
                <!-- AI action buttons -->
                <div class="pg-ai-actions">
                    <div class="pg-ai-actions__item">
                        <button type="button" class="admin-btn admin-btn--secondary js-generate-seo">Generate SEO</button>
                        <span class="js-seo-status pg-ai-actions__status"></span>
                    </div>
                    <div class="pg-ai-actions__item">
                        <button type="button" class="admin-btn admin-btn--secondary js-generate-article">Generate Article</button>
                        <span class="js-article-status pg-ai-actions__status"></span>
                    </div>
                    <div class="pg-ai-actions__item">
                        <button type="button" class="admin-btn admin-btn--secondary js-generate-faq">Generate FAQ</button>
                        <span class="js-faq-status pg-ai-actions__status"></span>
                    </div>
                </div>
                <!-- Helper boxes -->
                <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:8px;">
                    <div class="pg-seo-helper">
                        <span class="pg-seo-helper__label">Generate SEO with Agent SEO</span>
                        <ul class="pg-seo-helper__list">
                            <li>Meta Title</li>
                            <li>Meta Description</li>
                        </ul>
                    </div>
                    <div class="pg-seo-helper">
                        <span class="pg-seo-helper__label">Generate Article with Agent SEO</span>
                        <ul class="pg-seo-helper__list">
                            <li>Excerpt</li>
                            <li>Content</li>
                            <li>Meta Title</li>
                            <li>Meta Description</li>
                        </ul>
                    </div>
                    <div class="pg-seo-helper">
                        <span class="pg-seo-helper__label">Generate FAQ with Agent SEO</span>
                        <ul class="pg-seo-helper__list">
                            <li>5 FAQ items</li>
                            <li>Question &amp; Answer</li>
                            <li>Bahasa Indonesia</li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="form-grid__actions">
                <button type="submit" class="admin-btn admin-btn--primary">Create Article</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- Article stats bar -->
    <div class="pg-stats-bar">
        <span>Total Artikel: <strong><?= (int) $articleStats['total'] ?></strong></span>
        <span class="pg-stats-sep">·</span>
        <span>Published: <strong><?= (int) $articleStats['published_count'] ?></strong></span>
        <span class="pg-stats-sep">·</span>
        <span>Draft: <strong><?= (int) $articleStats['draft_count'] ?></strong></span>
    </div>

    <!-- Search + filter form -->
    <form class="pg-list-filter" method="get" action="">
        <input type="text" name="search" class="pg-filter-input"
               placeholder="Cari judul atau slug…"
               value="<?= cms_esc($listSearchRaw) ?>">
        <select name="status" class="pg-filter-select">
            <option value="">Semua Status</option>
            <option value="published" <?= $listStatus === 'published' ? 'selected' : '' ?>>Published</option>
            <option value="draft"     <?= $listStatus === 'draft'     ? 'selected' : '' ?>>Draft</option>
        </select>
        <select name="category_id" class="pg-filter-select">
            <option value="">Semua Kategori</option>
            <?php foreach ($articleCategories as $cat) : ?>
                <option value="<?= (int) $cat['id'] ?>"<?= $listCategoryId === (int) $cat['id'] ? ' selected' : '' ?>><?= cms_esc($cat['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="admin-btn admin-btn--secondary">Filter</button>
        <?php if ($listSearchRaw !== '' || $listStatus !== '' || $listCategoryId > 0): ?>
            <a href="<?= cms_esc($selfUrl) ?>" class="admin-btn admin-btn--secondary">Reset</a>
        <?php endif; ?>
    </form>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">All Articles</h3>
            <?php
                $listFrom = $listTotalRows > 0 ? $listOffset + 1 : 0;
                $listTo   = min($listOffset + $listPerPage, $listTotalRows);
            ?>
            <span class="panel__meta">
                <?= $listFrom ?>–<?= $listTo ?> dari <?= $listTotalRows ?> artikel<?= ($listSearchRaw !== '' || $listStatus !== '') ? ' (filtered)' : '' ?>
            </span>
        </div>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th class="pg-col-id">ID</th>
                        <th class="pg-col-thumb"></th>
                        <th>Title</th>
                        <th>Category</th>
                        <th>Author</th>
                        <th>Status</th>
                        <th>Views</th>
                        <th>Published At</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($pagesList === []) : ?>
                        <tr>
                            <td colspan="9" class="muted">
                                <?= ($listSearchRaw !== '' || $listStatus !== '') ? 'Tidak ada artikel yang cocok dengan filter.' : 'No articles yet.' ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($pagesList as $row) : ?>
                        <?php
                        $rowId     = (int) $row['page_id'];
                        $published = strtolower((string) $row['status']) === 'published';
                        $updatedTs = isset($row['updated_at']) && $row['updated_at'] !== ''
                            ? strtotime((string) $row['updated_at'])
                            : false;
                        $isNew = $updatedTs !== false && (time() - $updatedTs) < 86400;
                        $rowThumb = trim((string) ($row['featured_image'] ?? ''));
                        $rowThumbSrc = $rowThumb !== '' ? app_asset_preview_url($rowThumb) : '';
                        ?>
                        <tr>
                            <td class="pg-col-id pg-id-cell"><?= $rowId ?></td>
                            <td class="pg-thumb-cell">
                                <?php if ($rowThumbSrc !== '') : ?>
                                    <img class="pg-thumb" src="<?= cms_esc($rowThumbSrc) ?>" alt="" loading="lazy" onerror="this.replaceWith(Object.assign(document.createElement('div'),{className:'pg-thumb pg-thumb--ph',textContent:'No-Image'}))">
                                <?php else : ?>
                                    <div class="pg-thumb pg-thumb--ph">No-Image</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= cms_esc($val($row, 'title')) ?>
                                <?php if ($isNew): ?>
                                    <span class="pg-badge-new">NEW</span>
                                <?php endif; ?>
                                <?php if ((int) ($row['is_featured'] ?? 0) === 1): ?>
                                    <span class="pill pill--accent" style="margin-left:4px;">Featured</span>
                                <?php endif; ?>
                                <?php if ((int) ($row['is_trending'] ?? 0) === 1): ?>
                                    <span class="pill pill--accent" style="margin-left:4px;">Trending</span>
                                <?php endif; ?>
                                <br><code style="font-size:11px;"><?= cms_esc($val($row, 'slug')) ?></code>
                            </td>
                            <td><?= cms_esc($val($row, 'category_name') !== '' ? $val($row, 'category_name') : '—') ?></td>
                            <td><?= cms_esc($val($row, 'author_name') !== '' ? $val($row, 'author_name') : '—') ?></td>
                            <td><span class="pill pill--<?= $published ? 'ok' : 'muted' ?>"><?= cms_esc($val($row, 'status')) ?></span></td>
                            <td><?= (int) ($row['views'] ?? 0) ?></td>
                            <td><?= cms_esc($formatDt($row['published_at'] ?? null)) ?></td>
                            <td class="table-actions">
                                <a class="admin-btn admin-btn--sm admin-btn--secondary" href="<?= cms_esc($selfUrl) ?>?edit=<?= $rowId ?>">Edit</a>
                                <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>" onsubmit="return confirm('Delete this article?');">
                                    <?= cms_csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="page_id" value="<?= $rowId ?>">
                                    <button type="submit" class="admin-btn admin-btn--sm admin-btn--danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($listTotalPages > 1): ?>
    <nav class="pg-pagination" aria-label="Navigasi halaman artikel">
        <?php if ($listPage > 1): ?>
            <a class="pg-page-btn" href="<?= cms_esc($paginateUrl($listPage - 1)) ?>">« Prev</a>
        <?php else: ?>
            <span class="pg-page-btn pg-page-btn--disabled">« Prev</span>
        <?php endif; ?>

        <?php
        // Show at most 5 page numbers centered on current page.
        $pgWin  = 2; // pages on each side
        $pgMin  = max(1, $listPage - $pgWin);
        $pgMax  = min($listTotalPages, $listPage + $pgWin);
        // Pad window if near edges
        if ($pgMin === 1) {
            $pgMax = min($listTotalPages, 1 + $pgWin * 2);
        }
        if ($pgMax === $listTotalPages) {
            $pgMin = max(1, $listTotalPages - $pgWin * 2);
        }
        if ($pgMin > 1): ?>
            <a class="pg-page-btn" href="<?= cms_esc($paginateUrl(1)) ?>">1</a>
            <?php if ($pgMin > 2): ?><span class="pg-page-ellipsis">…</span><?php endif; ?>
        <?php endif; ?>

        <?php for ($pgI = $pgMin; $pgI <= $pgMax; $pgI++): ?>
            <?php if ($pgI === $listPage): ?>
                <span class="pg-page-btn pg-page-btn--active"><?= $pgI ?></span>
            <?php else: ?>
                <a class="pg-page-btn" href="<?= cms_esc($paginateUrl($pgI)) ?>"><?= $pgI ?></a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ($pgMax < $listTotalPages): ?>
            <?php if ($pgMax < $listTotalPages - 1): ?><span class="pg-page-ellipsis">…</span><?php endif; ?>
            <a class="pg-page-btn" href="<?= cms_esc($paginateUrl($listTotalPages)) ?>"><?= $listTotalPages ?></a>
        <?php endif; ?>

        <?php if ($listPage < $listTotalPages): ?>
            <a class="pg-page-btn" href="<?= cms_esc($paginateUrl($listPage + 1)) ?>">Next »</a>
        <?php else: ?>
            <span class="pg-page-btn pg-page-btn--disabled">Next »</span>
        <?php endif; ?>
    </nav>
    <?php endif; ?>

</section>
<style>
/* ---- Article stats bar ---- */
.pg-stats-bar {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 16px;
    border-radius: 10px;
    background: var(--surface);
    border: 1px solid var(--line);
    font-size: 13px;
    color: var(--muted);
    margin-bottom: 12px;
}
.pg-stats-bar strong { color: var(--text); }
.pg-stats-sep { color: var(--muted); opacity: .45; }

/* ---- Article list filter form ---- */
.pg-list-filter {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    margin-bottom: 12px;
}
.pg-filter-input {
    flex: 1;
    min-width: 180px;
    max-width: 320px;
    padding: 8px 12px;
    border: 1px solid var(--line);
    border-radius: 8px;
    font-size: 13px;
    background: var(--input-bg);
    color: var(--text);
    font-family: inherit;
}
.pg-filter-input:focus { outline: none; border-color: var(--navlink-active-border); box-shadow: 0 0 0 3px var(--ring); }
.pg-filter-select {
    padding: 8px 28px 8px 10px;
    border: 1px solid var(--line);
    border-radius: 8px;
    font-size: 13px;
    background: var(--input-bg);
    color: var(--text);
    cursor: pointer;
    font-family: inherit;
    -webkit-appearance: none;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%23999'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 10px center;
}
.pg-filter-select:focus { outline: none; border-color: var(--navlink-active-border); }

/* ---- ID column ---- */
.pg-col-id { width: 52px; }
.pg-id-cell {
    font-family: monospace;
    font-size: 12px;
    color: var(--muted);
    white-space: nowrap;
}

/* ---- Thumbnail column ---- */
.pg-col-thumb { width: 56px; }
.pg-thumb-cell { padding-top: 6px; padding-bottom: 6px; }
.pg-thumb {
    display: block;
    width: 48px;
    height: 48px;
    object-fit: cover;
    border-radius: 6px;
    border: 1px solid var(--line);
    flex-shrink: 0;
}
.pg-thumb--ph {
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    background: var(--surface-soft);
    border-style: dashed;
    color: var(--muted);
    font-size: 9px;
    font-weight: 600;
    line-height: 1.2;
    text-transform: uppercase;
    letter-spacing: .02em;
    padding: 2px;
}

/* ---- Pagination ---- */
.pg-pagination {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px;
    margin-top: 14px;
}
.pg-page-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 34px;
    height: 34px;
    padding: 0 10px;
    border-radius: 8px;
    border: 1px solid var(--line);
    background: var(--surface-soft);
    color: var(--text);
    font-size: 13px;
    font-weight: 500;
    text-decoration: none;
    cursor: pointer;
    font-family: inherit;
    transition: background .12s, border-color .12s;
}
.pg-page-btn:hover { background: var(--navlink-hover-bg); border-color: var(--navlink-active-border); }
.pg-page-btn--active {
    background: var(--accent);
    border-color: var(--accent);
    color: var(--accent-text);
    cursor: default;
}
.pg-page-btn--disabled {
    color: var(--muted);
    border-color: var(--line-subtle);
    background: var(--surface-soft);
    cursor: default;
}
.pg-page-ellipsis {
    padding: 0 4px;
    color: var(--muted);
    font-size: 13px;
    line-height: 34px;
}

/* ---- NEW badge ---- */
.pg-badge-new {
    display: inline-block;
    margin-left: 6px;
    padding: 1px 6px;
    border-radius: 4px;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .04em;
    text-transform: uppercase;
    background: var(--badge-new-bg);
    color: var(--badge-new-text);
    vertical-align: middle;
}

/* ---- Status select ---- */
.field--status select {
    min-height: 0;
    height: 52px;
    padding-top: 0;
    padding-bottom: 0;
    display: flex;
    align-items: center;
    line-height: 1.2;
}
.form-grid .field select {
    -webkit-appearance: none;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%23999'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    padding-right: 32px;
    cursor: pointer;
}

/* ---- AI action buttons row (Generate SEO / Article / FAQ) ---- */
/* Each button + its status message is one flex column, so a long status
   text (e.g. an error message) only pushes elements below its own button
   instead of shoving the neighbouring buttons out of line. */
.pg-ai-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    gap: 20px;
    margin-top: 8px;
}
.pg-ai-actions__item {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 150px;
}
.pg-ai-actions__status {
    font-size: .85em;
    color: var(--muted);
    line-height: 1.45;
}

/* ---- Generate SEO helper block ---- */
.pg-seo-helper {
    display: inline-flex;
    flex-direction: column;
    gap: 3px;
    margin-top: 8px;
    padding: 7px 12px;
    border-left: 3px solid var(--seo-helper-border);
    background: var(--seo-helper-bg);
    border-radius: 0 6px 6px 0;
    font-size: 12px;
    color: var(--muted);
    line-height: 1.55;
    max-width: 260px;
}
.pg-seo-helper__label {
    font-weight: 600;
    color: var(--seo-helper-label);
    letter-spacing: .01em;
}
.pg-seo-helper__list {
    margin: 0;
    padding: 0;
    list-style: none;
}
.pg-seo-helper__list li::before {
    content: '• ';
    color: var(--seo-helper-label);
}
@media (max-width: 480px) {
    .pg-seo-helper { max-width: 100%; }
}
</style>
<?php require dirname(__DIR__) . '/includes/tinymce-media-picker.php'; ?>
<script>
(function () {
  document.querySelectorAll('.js-generate-seo').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var form       = btn.closest('form');
      var statusEl   = form.querySelector('.js-seo-status');

      var titleEl    = form.querySelector('[name="title"]');
      var slugEl     = form.querySelector('[name="slug"]');
      var excerptEl  = form.querySelector('[name="excerpt"]');
      var contentEl  = form.querySelector('[name="content"]');
      var metaTitleEl = form.querySelector('[name="meta_title"]');
      var metaDescEl  = form.querySelector('[name="meta_description"]');
      var pageIdEl    = form.querySelector('[name="page_id"]'); // only present on the edit form
      var notesEl     = form.querySelector('.pg-article-notes'); // shared "Catatan untuk Agent SEO" box

      // Get content from TinyMCE if active, otherwise fall back to textarea value
      var contentValue = '';
      if (typeof tinymce !== 'undefined' && tinymce.activeEditor) {
        contentValue = tinymce.activeEditor.getContent({ format: 'text' });
      } else if (contentEl) {
        contentValue = contentEl.value;
      }

      var data = new FormData();
      data.append('type',    'page');
      data.append('title',   titleEl   ? titleEl.value.trim()   : '');
      data.append('slug',    slugEl    ? slugEl.value.trim()    : '');
      data.append('excerpt', excerptEl ? excerptEl.value.trim() : '');
      data.append('content', contentValue.trim());
      data.append('notes',   notesEl   ? notesEl.value.trim()   : '');
      if (pageIdEl && pageIdEl.value) { data.append('page_id', pageIdEl.value); }
      data.append('csrf_token', '<?= cms_csrf_token() ?>');

      btn.disabled   = true;
      statusEl.style.color = '#666';
      statusEl.textContent = 'Generating…';

      var controller = new AbortController();
      var abortTimer = setTimeout(function () { controller.abort(); }, 65000);

      fetch('<?= cms_esc(cms_api_href('seo-generate.php')) ?>', {
        method: 'POST',
        body:   data,
        signal: controller.signal,
      })
        .then(function (r) {
          if (!r.ok) {
            return r.text().then(function (t) {
              throw new Error('HTTP ' + r.status + (t ? ': ' + t.slice(0, 200) : ''));
            });
          }
          return r.json();
        })
        .then(function (json) {
          if (json.success) {
            if (metaTitleEl) { metaTitleEl.value = json.meta_title; }
            if (metaDescEl)  { metaDescEl.value  = json.meta_description; }
            statusEl.style.color = 'green';
            statusEl.textContent = 'Done — review and edit as needed.';
          } else {
            statusEl.style.color = '#c00';
            statusEl.textContent = 'Error: ' + (json.error || 'Unknown error.');
          }
        })
        .catch(function (err) {
          statusEl.style.color = '#c00';
          statusEl.textContent = err.name === 'AbortError'
            ? 'Timeout — request took too long. Please try again.'
            : 'Request failed: ' + err.message;
        })
        .finally(function () {
          clearTimeout(abortTimer);
          btn.disabled = false;
        });
    });
  });
})();
</script>
<script>
(function () {
  // Tags field chip/pill UI — purely presentational. The original
  // <input type="text" name="tags" ...> stays in the DOM (just hidden),
  // still carrying the comma-separated string $pg_syncTags() expects in
  // $_POST['tags'] — nothing server-side changes. Runs once per
  // .js-tags-input on the page (edit form + add-new form both have one).
  document.querySelectorAll('.js-tags-input').forEach(function (original) {
    var datalistId = original.getAttribute('list');
    var tags = original.value.split(',').map(function (s) { return s.trim(); }).filter(Boolean);

    original.style.display = 'none';

    var wrap = document.createElement('div');
    wrap.className = 'pg-tags-chip-wrap';
    wrap.style.display = 'flex';
    wrap.style.flexWrap = 'wrap';
    wrap.style.gap = '6px';
    wrap.style.alignItems = 'center';
    original.parentNode.insertBefore(wrap, original.nextSibling);

    var chipsEl = document.createElement('div');
    chipsEl.style.display = 'contents';
    wrap.appendChild(chipsEl);

    var typer = document.createElement('input');
    typer.type = 'text';
    typer.placeholder = tags.length ? '' : 'Ketik tag lalu Enter atau koma';
    typer.style.flex = '1 1 160px';
    typer.style.minWidth = '160px';
    typer.style.border = 'none';
    typer.style.outline = 'none';
    typer.style.background = 'transparent';
    typer.style.font = 'inherit';
    typer.style.color = 'inherit';
    if (datalistId) {
      typer.setAttribute('list', datalistId);
    }
    wrap.appendChild(typer);

    function syncOriginal() {
      original.value = tags.join(', ');
    }

    function render() {
      chipsEl.innerHTML = '';
      tags.forEach(function (tag, index) {
        var chip = document.createElement('span');
        chip.className = 'pill pill--tag';

        var label = document.createElement('span');
        label.textContent = tag;
        chip.appendChild(label);

        var remove = document.createElement('span');
        remove.className = 'js-tag-remove';
        remove.textContent = '×';
        remove.addEventListener('click', function () {
          tags.splice(index, 1);
          syncOriginal();
          render();
          typer.focus();
        });
        chip.appendChild(remove);

        chipsEl.appendChild(chip);
      });
      syncOriginal();
    }

    function commitTyped() {
      var value = typer.value.trim();
      // Datalist autocomplete always resets against the freshly-emptied
      // typer input below — native <datalist> behavior needs no extra
      // wiring per commit, it just re-triggers because the input is empty
      // again and still carries the same `list` attribute.
      typer.value = '';
      if (value === '') {
        return;
      }
      var exists = tags.some(function (t) { return t.toLowerCase() === value.toLowerCase(); });
      if (!exists) {
        tags.push(value);
        render();
      }
    }

    typer.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ',') {
        e.preventDefault();
        commitTyped();
      } else if (e.key === 'Backspace' && typer.value === '' && tags.length > 0) {
        e.preventDefault();
        tags.pop();
        syncOriginal();
        render();
      }
    });

    typer.addEventListener('blur', function () {
      commitTyped();
    });

    render();
  });
})();
</script>
<script>
// ---- Generate Article with Agent SEO (pages.php only) ----
(function () {
  document.querySelectorAll('.js-generate-article').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var form        = btn.closest('form');
      var statusEl    = form.querySelector('.js-article-status');
      var titleEl     = form.querySelector('[name="title"]');
      var notesEl     = form.querySelector('.pg-article-notes');
      var excerptEl   = form.querySelector('[name="excerpt"]');
      var metaTitleEl = form.querySelector('[name="meta_title"]');
      var metaDescEl  = form.querySelector('[name="meta_description"]');
      var pageIdEl    = form.querySelector('[name="page_id"]'); // only present on the edit form

      var contentEl = form.querySelector('[name="content"]');

      var data = new FormData();
      data.append('title', titleEl ? titleEl.value.trim() : '');
      data.append('notes', notesEl ? notesEl.value.trim() : '');
      if (pageIdEl && pageIdEl.value) { data.append('page_id', pageIdEl.value); }
      data.append('csrf_token', '<?= cms_csrf_token() ?>');

      btn.disabled = true;
      statusEl.style.color = '#666';
      statusEl.textContent = 'Generating…';

      var controller = new AbortController();
      var abortTimer = setTimeout(function () { controller.abort(); }, 65000);

      fetch('<?= cms_esc(cms_api_href('article-generate.php')) ?>', {
        method: 'POST',
        body:   data,
        signal: controller.signal,
      })
        .then(function (r) {
          if (!r.ok) {
            return r.text().then(function (t) {
              throw new Error('HTTP ' + r.status + (t ? ': ' + t.slice(0, 200) : ''));
            });
          }
          return r.json();
        })
        .then(function (json) {
          if (json.success) {
            if (excerptEl)   { excerptEl.value   = json.excerpt; }
            if (metaTitleEl) { metaTitleEl.value = json.meta_title; }
            if (metaDescEl)  { metaDescEl.value  = json.meta_description; }
            // Populate TinyMCE editor — look up fresh at population time,
            // not at click time, so init() is guaranteed to have completed.
            var editor = (typeof tinymce !== 'undefined') ? tinymce.get('pg-content') : null;
            if (editor) {
              editor.setContent(json.content);
              editor.save(); // sync back to hidden textarea for form submit
            } else if (contentEl) {
              contentEl.value = json.content;
            }
            statusEl.style.color = 'green';
            statusEl.textContent = 'Done — review and edit as needed.';
          } else {
            statusEl.style.color = '#c00';
            statusEl.textContent = 'Error: ' + (json.error || 'Unknown error.');
          }
        })
        .catch(function (err) {
          console.error('[Generate Article]', err);
          statusEl.style.color = '#c00';
          statusEl.textContent = err.name === 'AbortError'
            ? 'Timeout — artikel membutuhkan waktu terlalu lama. Coba lagi.'
            : 'Error: ' + (err.message || 'Unknown error.');
        })
        .finally(function () {
          clearTimeout(abortTimer);
          btn.disabled = false;
        });
    });
  });
})();
</script>
<script>
// ---- Generate FAQ with Agent SEO (pages.php only) ----
(function () {
  document.querySelectorAll('.js-generate-faq').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var form      = btn.closest('form');
      var statusEl  = form.querySelector('.js-faq-status');
      var titleEl   = form.querySelector('[name="title"]');
      var excerptEl = form.querySelector('[name="excerpt"]');
      var notesEl   = form.querySelector('.pg-article-notes');
      var faqEl     = form.querySelector('[name="faq_json"]');
      var pageIdEl  = form.querySelector('[name="page_id"]'); // only present on the edit form

      // Read content from TinyMCE if active, otherwise fall back to textarea value
      var contentValue = '';
      if (typeof tinymce !== 'undefined' && tinymce.activeEditor) {
        contentValue = tinymce.activeEditor.getContent({ format: 'text' });
      } else {
        var contentEl = form.querySelector('[name="content"]');
        if (contentEl) { contentValue = contentEl.value; }
      }

      var data = new FormData();
      data.append('title',      titleEl   ? titleEl.value.trim()   : '');
      data.append('content',    contentValue.trim());
      data.append('excerpt',    excerptEl ? excerptEl.value.trim() : '');
      data.append('dev_notes',  notesEl   ? notesEl.value.trim()   : '');
      if (pageIdEl && pageIdEl.value) { data.append('page_id', pageIdEl.value); }
      data.append('csrf_token', '<?= cms_csrf_token() ?>');

      btn.disabled = true;
      statusEl.style.color = '#666';
      statusEl.textContent = 'Generating…';

      var controller = new AbortController();
      var abortTimer = setTimeout(function () { controller.abort(); }, 65000);

      fetch('<?= cms_esc(cms_api_href('faq-generate.php')) ?>', {
        method: 'POST',
        body:   data,
        signal: controller.signal,
      })
        .then(function (r) {
          if (!r.ok) {
            return r.text().then(function (t) {
              throw new Error('HTTP ' + r.status + (t ? ': ' + t.slice(0, 200) : ''));
            });
          }
          return r.json();
        })
        .then(function (json) {
          if (json.success) {
            if (faqEl) { faqEl.value = JSON.stringify(json.faq, null, 2); }
            statusEl.style.color = 'green';
            statusEl.textContent = 'Done — review and edit as needed.';
          } else {
            statusEl.style.color = '#c00';
            statusEl.textContent = 'Error: ' + (json.error || 'Unknown error.');
          }
        })
        .catch(function (err) {
          console.error('[Generate FAQ]', err);
          statusEl.style.color = '#c00';
          statusEl.textContent = err.name === 'AbortError'
            ? 'Timeout — FAQ membutuhkan waktu terlalu lama. Coba lagi.'
            : 'Error: ' + (err.message || 'Unknown error.');
        })
        .finally(function () {
          clearTimeout(abortTimer);
          btn.disabled = false;
        });
    });
  });
})();
</script>
<script>
// ---- Featured image path picker (pages.php only) ----
(function () {
    var modal    = document.getElementById('mce-ml-modal');
    var search   = document.getElementById('mce-ml-search');
    var backdrop = document.getElementById('mce-ml-backdrop');
    var closeBtn = document.getElementById('mce-ml-close');
    if (!modal) { return; }

    // Holds the <input name="featured_image"> that triggered the picker.
    // null means the modal was opened by TinyMCE — leave it alone.
    var _targetInput = null;

    function openPicker(input) {
        _targetInput = input;
        // Reset search so all images are visible (mirrors tinymce-media-picker openModal)
        if (search) {
            search.value = '';
            search.dispatchEvent(new Event('input'));
            search.focus();
        }
        modal.hidden = false;
    }

    function closePicker() {
        _targetInput = null;
        modal.hidden = true;
    }

    // "Choose from Media Library" button click — open modal for path picking
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-pg-img-pick');
        if (!btn) { return; }
        var form       = btn.closest('form');
        var targetName = btn.getAttribute('data-target') || 'featured_image';
        var input      = form ? form.querySelector('[name="' + targetName + '"]') : null;
        if (!input) { return; }
        openPicker(input);
    });

    // Image item click while in path-picker mode — write data-path, close modal
    document.addEventListener('click', function (e) {
        if (!_targetInput) { return; }          // TinyMCE mode or modal not active
        var item = e.target.closest('.mce-ml-item');
        if (!item || modal.hidden) { return; }
        var path = item.getAttribute('data-path') || '';
        if (path) {
            _targetInput.value = path;
            // Setting .value in JS doesn't fire 'input'/'change' on its own —
            // dispatch it so the image-preview wiring below (which only
            // listens for those events) picks up the newly-picked path too,
            // not just manual typing.
            _targetInput.dispatchEvent(new Event('input', { bubbles: true }));
        }
        closePicker();
    });

    // Clear _targetInput if the modal is dismissed via backdrop, close button, or Escape
    function onDismiss() { _targetInput = null; }
    if (backdrop) { backdrop.addEventListener('click', onDismiss); }
    if (closeBtn) { closeBtn.addEventListener('click', onDismiss); }
    document.addEventListener('keydown', function (e) {
        if (!modal.hidden && (e.key === 'Escape' || e.key === 'Esc')) { onDismiss(); }
    });
})();
</script>
<script>
// ---- Featured/OG image live preview (26 Jul 2026) ----
// Wires every .js-pg-img-input to the .cms-path-upload__preview <img> and
// .js-pg-img-error hint that immediately follow it in the DOM. Covers all
// three ways the path can change: page load with an existing value (edit
// mode — src is already set server-side via app_asset_preview_url(), this
// just re-validates it can actually load), manual typing/paste (debounced
// so it doesn't fire a request per keystroke), and the "Choose from Media
// Library" picker (which now dispatches an 'input' event after setting
// .value, see the picker script above — same listener handles both).
(function () {
    // Mirrors app_asset_preview_url()'s rule: absolute http(s) URLs pass
    // through untouched, everything else is resolved against the same
    // public-site base prefix the server used for the initial (edit-mode)
    // preview, so a manually-typed path resolves to the identical URL an
    // edit-mode reload would have produced.
    var BASE_PREFIX = <?= json_encode(function_exists('cms_public_base_prefix') ? cms_public_base_prefix() : '', JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;

    function resolveUrl(path) {
        path = (path || '').trim();
        if (path === '') { return ''; }
        if (/^https?:\/\//i.test(path)) { return path; }
        return BASE_PREFIX + path.replace(/^\/+/, '');
    }

    function wireOne(input) {
        var preview = input.nextElementSibling;
        if (!preview || !preview.classList.contains('js-pg-img-preview')) { return; }
        var errorHint = preview.nextElementSibling;
        if (!errorHint || !errorHint.classList.contains('js-pg-img-error')) { errorHint = null; }

        var debounceTimer = null;
        function update() {
            var url = resolveUrl(input.value);
            if (errorHint) { errorHint.hidden = true; }
            if (url === '') {
                preview.hidden = true;
                preview.removeAttribute('src');
                return;
            }
            preview.hidden = false;
            preview.src = url;
        }

        preview.addEventListener('error', function () {
            if (preview.hidden) { return; }
            preview.hidden = true;
            if (errorHint) { errorHint.hidden = false; }
        });
        preview.addEventListener('load', function () {
            if (errorHint) { errorHint.hidden = true; }
        });

        input.addEventListener('input', function () {
            window.clearTimeout(debounceTimer);
            debounceTimer = window.setTimeout(update, 300);
        });

        // Edit mode renders the preview's src server-side (already loaded
        // or already failed by the time this script runs), so the 'error'/
        // 'load' listeners just attached above may have missed that first
        // load/fail — .complete + naturalWidth catches it retroactively
        // without re-requesting the image.
        if (!preview.hidden && preview.complete && preview.naturalWidth === 0) {
            preview.hidden = true;
            if (errorHint) { errorHint.hidden = false; }
        }
    }

    document.querySelectorAll('.js-pg-img-input').forEach(wireOne);
})();
</script>
<script>
// ---- Auto-generate slug from title (pages.php only) ----
(function () {
    function slugify(str) {
        return str
            .toLowerCase()
            .replace(/[àáâãäå]/g, 'a').replace(/[èéêë]/g, 'e')
            .replace(/[ìíîï]/g, 'i').replace(/[òóôõöø]/g, 'o')
            .replace(/[ùúûü]/g, 'u').replace(/ñ/g, 'n').replace(/ç/g, 'c')
            .replace(/[^a-z0-9\s-]/g, '')
            .trim()
            .replace(/[\s-]+/g, '-');
    }

    document.querySelectorAll('form.form-grid').forEach(function (form) {
        var titleEl = form.querySelector('[name="title"]');
        var slugEl  = form.querySelector('[name="slug"]');
        if (!titleEl || !slugEl) { return; }

        // Start locked when slug already has a value (edit form with existing slug).
        // Start unlocked when slug is empty (create form).
        var locked = slugEl.value.trim() !== '';

        titleEl.addEventListener('input', function () {
            if (locked) { return; }
            slugEl.value = slugify(titleEl.value);
        });

        // First manual keystroke in the slug field locks auto-generation permanently.
        slugEl.addEventListener('input', function () {
            locked = true;
        });
    });
})();
</script>
<script>
// ---- Require "Cabang Sport" before an article can be saved (26 Jul 2026) ----
// Server-side is the real gate (see $pg_validate in this file) — this is
// just the friendlier, immediate version so admins don't have to submit
// first to find out. setCustomValidity() is used instead of relying on the
// browser's default "please select an item" text, so the message actually
// explains WHY the field matters (it drives the homepage sport filter
// chips), not just that it's empty.
(function () {
    var SPORT_KEY_MESSAGE = 'Cabang Sport wajib dipilih agar artikel muncul di filter livescore.';
    document.querySelectorAll('.js-pg-sport-key').forEach(function (select) {
        var check = function () {
            select.setCustomValidity(select.value === '' ? SPORT_KEY_MESSAGE : '');
        };
        check();
        select.addEventListener('change', check);
        select.addEventListener('invalid', check);
    });
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/tinymce@7.6.1/tinymce.min.js" crossorigin="anonymous"></script>
<script>
(function () {
  var contentField = document.querySelector('textarea[name="content"]');
  if (!contentField) {
    return;
  }

  tinymce.init({
    license_key: 'gpl',
    selector: 'textarea[name="content"]',
    height: 360,
    menubar: false,
    branding: false,
    promotion: false,
    readonly: false,
    plugins: 'lists link image table code',
    toolbar:
      'undo redo | blocks | bold italic underline | alignleft aligncenter alignright | bullist numlist | link image table | code',
    block_formats: 'Paragraph=p; Heading 2=h2; Heading 3=h3; Heading 4=h4',
    automatic_uploads: false,
    images_upload_url: false,
    paste_data_images: false,
    image_description: false,
    content_style: window.wpmMlContentStyle || '',
    link_default_target: '_blank',
    link_assume_external_targets: true,
    image_advtab: true,
    image_class_list: [
      { title: 'Center image (default)', value: 'img-center' },
      { title: 'Full width image',       value: 'img-full'   },
      { title: 'Small left image',       value: 'img-left'   },
      { title: 'Small right image',      value: 'img-right'  },
    ],
    file_picker_types: 'image',
    file_picker_callback: window.wpmMlPicker,
    setup: function (editor) {
      if (window.wpmMlSetupEditor) { window.wpmMlSetupEditor(editor); }
      editor.on('change input undo redo', function () {
        editor.save();
      });
    },
  });

  document.querySelectorAll('form.form-grid').forEach(function (form) {
    if (!form.querySelector('textarea[name="content"]')) {
      return;
    }
    form.addEventListener('submit', function () {
      if (typeof tinymce !== 'undefined') {
        tinymce.triggerSave();
      }
    });
  });
})();
</script>
<?php
require dirname(__DIR__) . '/includes/footer.php';
