<?php
declare(strict_types=1);

/**
 * Sitemaps module — central service (14 Jul 2026).
 *
 * Every place in the admin that creates/edits/deletes anything that has a
 * public URL (articles, categories, tags, redirects) calls into this file
 * instead of writing to `sitemap_urls`/`sitemap_changelog` directly. That's
 * the "event/hook/service" mechanism requested by the brief — there is no
 * separate queue/cron: hooks run synchronously, in the same request as the
 * content save, right after the source table's INSERT/UPDATE/DELETE
 * succeeds. The public /sitemap*.xml endpoints (root sitemap.php) then just
 * SELECT from `sitemap_urls` — they never recompute anything themselves, so
 * they stay fast and always reflect whatever this service last wrote.
 *
 * A manual "Regenerate Sitemap" button still exists (cms_sitemap_full_resync())
 * for bootstrapping and for drift-correction (e.g. first install, or a
 * content type whose hook was never wired), but normal day-to-day content
 * edits are expected to keep the sitemap current on their own via the
 * on_*_save()/on_*_delete() functions below — never only on manual click.
 */

require_once __DIR__ . '/schema-guard.php';

/**
 * Creates sitemap_urls / sitemap_changelog / sitemap_settings if missing,
 * and the `pages.noindex` column. Idempotent — safe to call on every page
 * load from any admin page that touches sitemap data (mirrors the
 * cms_ensure_table()/cms_ensure_column() auto-migration pattern used by
 * every other module in this project).
 */
function cms_sitemap_ensure_schema(PDO $pdo): void
{
    cms_ensure_table(
        $pdo,
        'sitemap_urls',
        "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
         url VARCHAR(500) NOT NULL,
         content_type ENUM('homepage','article','category','tag','page','custom') NOT NULL,
         content_id INT UNSIGNED DEFAULT NULL,
         content_title VARCHAR(255) DEFAULT NULL,
         status ENUM('published','draft','scheduled','deleted','redirected','excluded','error') NOT NULL DEFAULT 'published',
         included TINYINT(1) NOT NULL DEFAULT 1,
         priority DECIMAL(2,1) NOT NULL DEFAULT 0.5,
         changefreq ENUM('always','hourly','daily','weekly','monthly','yearly','never') NOT NULL DEFAULT 'weekly',
         lastmod DATETIME DEFAULT NULL,
         last_detected_change DATETIME DEFAULT NULL,
         sitemap_file VARCHAR(40) NOT NULL DEFAULT 'sitemap-pages.xml',
         overrides_json TEXT DEFAULT NULL,
         error_message VARCHAR(255) DEFAULT NULL,
         created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
         updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
         UNIQUE KEY uniq_sitemap_content (content_type, content_id),
         KEY idx_sitemap_type (content_type),
         KEY idx_sitemap_included (included),
         KEY idx_sitemap_status (status),
         KEY idx_sitemap_file (sitemap_file)"
    );

    cms_ensure_table(
        $pdo,
        'sitemap_changelog',
        "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
         occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
         action ENUM('created','updated','published','unpublished','deleted','restored','slug_changed','included','excluded','redirected','sitemap_generated','validation_executed') NOT NULL,
         content_type VARCHAR(30) DEFAULT NULL,
         content_id INT UNSIGNED DEFAULT NULL,
         old_url VARCHAR(500) DEFAULT NULL,
         new_url VARCHAR(500) DEFAULT NULL,
         changed_fields TEXT DEFAULT NULL,
         triggered_by VARCHAR(150) NOT NULL DEFAULT 'System',
         result ENUM('success','error') NOT NULL DEFAULT 'success',
         error_message VARCHAR(255) DEFAULT NULL,
         created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
         KEY idx_changelog_occurred (occurred_at),
         KEY idx_changelog_action (action),
         KEY idx_changelog_content_type (content_type),
         KEY idx_changelog_result (result)"
    );

    cms_ensure_table(
        $pdo,
        'sitemap_settings',
        'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
         rules_json TEXT DEFAULT NULL,
         last_generated_at DATETIME DEFAULT NULL,
         last_success_at DATETIME DEFAULT NULL,
         last_error TEXT DEFAULT NULL,
         updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
    );

    $settingsCount = (int) $pdo->query('SELECT COUNT(*) FROM sitemap_settings')->fetchColumn();
    if ($settingsCount === 0) {
        $pdo->prepare('INSERT INTO sitemap_settings (rules_json) VALUES (:rules)')
            ->execute(['rules' => json_encode(cms_sitemap_default_rules())]);
    }

    // Opt-out-of-search-engines flag for articles (checkbox in pages.php).
    cms_ensure_column($pdo, 'pages', 'noindex', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `canonical_url`');
}

/**
 * Per-content-type "auto-rule" defaults — used whenever a URL's
 * priority/changefreq have not been manually pinned by an admin (see
 * overrides_json handling in cms_sitemap_upsert()).
 */
function cms_sitemap_default_rules(): array
{
    return [
        'homepage' => ['included' => 1, 'priority' => 1.0, 'changefreq' => 'daily'],
        'article'  => ['included' => 1, 'priority' => 0.8, 'changefreq' => 'weekly'],
        'category' => ['included' => 1, 'priority' => 0.6, 'changefreq' => 'weekly'],
        'tag'      => ['included' => 1, 'priority' => 0.4, 'changefreq' => 'monthly'],
        'page'     => ['included' => 1, 'priority' => 0.5, 'changefreq' => 'monthly'],
    ];
}

/** Sitemap file a content type's URLs are written into. Tags are folded
 *  into the categories file — both are taxonomy/archive listings and the
 *  brief's own example index only names pages/articles/categories/custom. */
function cms_sitemap_file_for(string $contentType): string
{
    return match ($contentType) {
        'article' => 'sitemap-articles.xml',
        'category', 'tag' => 'sitemap-categories.xml',
        'custom' => 'sitemap-custom.xml',
        default => 'sitemap-pages.xml', // homepage, page
    };
}

function cms_sitemap_settings(PDO $pdo): array
{
    cms_sitemap_ensure_schema($pdo);
    $row = $pdo->query('SELECT * FROM sitemap_settings ORDER BY id ASC LIMIT 1')->fetch();
    if ($row === false) {
        $row = ['rules_json' => json_encode(cms_sitemap_default_rules())];
    }
    $rules = json_decode((string) ($row['rules_json'] ?? ''), true);
    if (!is_array($rules)) {
        $rules = [];
    }
    // Merge over defaults so a partially-saved/older rules_json never loses
    // a content type that was added to the defaults later.
    $row['rules'] = array_replace_recursive(cms_sitemap_default_rules(), $rules);
    return $row;
}

function cms_sitemap_save_rules(PDO $pdo, array $rules): void
{
    cms_sitemap_ensure_schema($pdo);
    $clean = [];
    foreach (cms_sitemap_default_rules() as $type => $defaults) {
        $clean[$type] = [
            'included'   => !empty($rules[$type]['included']) ? 1 : 0,
            'priority'   => cms_sitemap_clamp_priority((float) ($rules[$type]['priority'] ?? $defaults['priority'])),
            'changefreq' => cms_sitemap_valid_changefreq((string) ($rules[$type]['changefreq'] ?? $defaults['changefreq'])),
        ];
    }
    $pdo->prepare('UPDATE sitemap_settings SET rules_json = :rules ORDER BY id ASC LIMIT 1')
        ->execute(['rules' => json_encode($clean)]);
}

function cms_sitemap_clamp_priority(float $priority): float
{
    return round(max(0.0, min(1.0, $priority)), 1);
}

function cms_sitemap_valid_changefreq(string $value): string
{
    $valid = ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'];
    return in_array($value, $valid, true) ? $value : 'weekly';
}

/**
 * Always-absolute URL builder, safe to call from admin context (unlike
 * cms_public_base_prefix(), which intentionally returns a *relative*
 * "../" style prefix on local single-host installs — fine for admin <a>
 * links, wrong for a sitemap <loc>, which must always be a full absolute
 * URL regardless of environment). Mirrors the same "wpm." production
 * subdomain detection cms_public_base_prefix() uses, so both stay
 * consistent about what host the public site actually lives on.
 */
function cms_sitemap_absolute_url(string $relativePath): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $relativePath = ltrim($relativePath, '/');

    if (str_starts_with($host, 'wpm.')) {
        // Production: admin on its own subdomain, public site is a
        // different host entirely at its own root.
        return $scheme . '://' . substr($host, 4) . '/' . $relativePath;
    }

    // Local dev: admin nested under the same host as the public site
    // (e.g. localhost/wpm/cms-admin/pages/x.php) — derive the project
    // root path by cutting everything from "/cms-admin/" onward, so this
    // is correct regardless of how deep the calling script sits.
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $cmsPos = strpos($scriptName, '/cms-admin/');
    $rootPath = $cmsPos !== false ? substr($scriptName, 0, $cmsPos) : '';
    return $scheme . '://' . $host . $rootPath . '/' . $relativePath;
}

/**
 * Relative path builders — MUST stay in sync with wpm_url_*() in
 * includes/site-bootstrap.php and the RewriteRules in the root
 * .htaccess. Duplicated here (rather than requiring the frontend
 * bootstrap file from admin pages) to keep the wpm_ and cms_ function
 * namespace separation this project already follows everywhere else.
 */
function cms_sitemap_path_for(string $contentType, ?string $slug = null): string
{
    // MUST stay in sync with wpm_article_url()/wpm_category_url() in
    // includes/site-bootstrap.php and the RewriteRules in the root
    // .htaccess — this previously pointed at 'berita/kategori/{slug}'
    // (a leftover from the sagagoal-era URL scheme this cms-admin was
    // cloned from), which doesn't exist on this site and would have
    // put broken URLs in the sitemap. Fixed 12 Agu 2026.
    return match ($contentType) {
        'homepage' => '',
        'article'  => 'artikel/' . rawurlencode((string) $slug),
        'category' => $slug !== null && $slug !== '' ? 'kategori/' . rawurlencode($slug) : 'kategori',
        'tag'      => 'tag/' . rawurlencode((string) $slug),
        default    => (string) $slug,
    };
}

function cms_sitemap_actor(): string
{
    $name = trim((string) ($_SESSION['cms_admin_name'] ?? ''));
    return $name !== '' ? $name : 'System';
}

/**
 * Append one row to sitemap_changelog. Never throws — logging failures
 * must never break the content save that triggered them.
 */
function cms_sitemap_log(PDO $pdo, array $entry): void
{
    try {
        cms_sitemap_ensure_schema($pdo);
        $pdo->prepare(
            'INSERT INTO sitemap_changelog
                (occurred_at, action, content_type, content_id, old_url, new_url, changed_fields, triggered_by, result, error_message, created_at)
             VALUES
                (NOW(), :action, :content_type, :content_id, :old_url, :new_url, :changed_fields, :triggered_by, :result, :error_message, NOW())'
        )->execute([
            'action'         => $entry['action'],
            'content_type'   => $entry['content_type'] ?? null,
            'content_id'     => $entry['content_id'] ?? null,
            'old_url'        => $entry['old_url'] ?? null,
            'new_url'        => $entry['new_url'] ?? null,
            'changed_fields' => isset($entry['changed_fields']) ? json_encode($entry['changed_fields']) : null,
            'triggered_by'   => $entry['triggered_by'] ?? cms_sitemap_actor(),
            'result'         => $entry['result'] ?? 'success',
            'error_message'  => $entry['error_message'] ?? null,
        ]);
    } catch (Throwable $e) {
        // Best-effort — a broken changelog write must never block content saves.
    }
}

/**
 * Core upsert: one row per (content_type, content_id). Always refreshes
 * url/title/status/lastmod/sitemap_file/last_detected_change (these
 * reflect real content truth, never "rules"). priority/changefreq/
 * included only get overwritten from the auto-rule defaults when the
 * admin hasn't manually pinned them (overrides_json) — this is the
 * "changefreq/priority jika menggunakan auto-rule" behaviour from the
 * brief. Logs an appropriate changelog action and returns the row id.
 */
function cms_sitemap_upsert(PDO $pdo, array $data): int
{
    cms_sitemap_ensure_schema($pdo);

    $contentType = (string) $data['content_type'];
    $contentId   = $data['content_id'] ?? null;
    $url         = (string) $data['url'];
    $title       = $data['content_title'] ?? null;
    $status      = (string) ($data['status'] ?? 'published');
    $triggeredBy = $data['triggered_by'] ?? cms_sitemap_actor();
    $forceIncluded = array_key_exists('force_included', $data) ? (int) (bool) $data['force_included'] : null;

    $existingStmt = $pdo->prepare(
        'SELECT * FROM sitemap_urls WHERE content_type = :type AND (content_id <=> :id) LIMIT 1'
    );
    $existingStmt->execute(['type' => $contentType, 'id' => $contentId]);
    $existing = $existingStmt->fetch() ?: null;

    $overrides = [];
    if ($existing && !empty($existing['overrides_json'])) {
        $decoded = json_decode((string) $existing['overrides_json'], true);
        $overrides = is_array($decoded) ? $decoded : [];
    }

    $rules = cms_sitemap_settings($pdo)['rules'][$contentType] ?? ['included' => 1, 'priority' => 0.5, 'changefreq' => 'weekly'];

    $priority = (!empty($overrides['priority']) && $existing) ? (float) $existing['priority'] : (float) $rules['priority'];
    $changefreq = (!empty($overrides['changefreq']) && $existing) ? (string) $existing['changefreq'] : (string) $rules['changefreq'];

    if ($forceIncluded !== null) {
        $included = $forceIncluded;
    } elseif (!empty($overrides['included']) && $existing) {
        $included = (int) $existing['included'];
    } else {
        $included = ($status === 'published' && !empty($rules['included'])) ? 1 : 0;
    }

    $sitemapFile = cms_sitemap_file_for($contentType);
    $now = date('Y-m-d H:i:s');

    $slugChanged = $existing && $existing['url'] !== $url;
    $wasDeleted = $existing && $existing['status'] === 'deleted';

    if ($existing) {
        // Note: :lastmod and :lastdetected are bound to the same $now value
        // under two different placeholder names on purpose — this project's
        // PDO connection runs with ATTR_EMULATE_PREPARES => false, and the
        // native MySQL driver rejects a named placeholder that appears more
        // than once in the same query ("SQLSTATE[HY093]: Invalid parameter
        // number"), so every column needs its own unique :name even when
        // the value is identical.
        $pdo->prepare(
            'UPDATE sitemap_urls SET
                url = :url, content_title = :title, status = :status, included = :included,
                priority = :priority, changefreq = :changefreq, lastmod = :lastmod,
                last_detected_change = :lastdetected, sitemap_file = :file, error_message = NULL,
                overrides_json = :overrides, updated_at = NOW()
             WHERE id = :id'
        )->execute([
            'url' => $url, 'title' => $title, 'status' => $status, 'included' => $included,
            'priority' => $priority, 'changefreq' => $changefreq, 'lastmod' => $now, 'lastdetected' => $now,
            'file' => $sitemapFile, 'overrides' => json_encode($overrides), 'id' => $existing['id'],
        ]);
        $rowId = (int) $existing['id'];

        if ($slugChanged) {
            cms_sitemap_log($pdo, [
                'action' => 'slug_changed', 'content_type' => $contentType, 'content_id' => $contentId,
                'old_url' => $existing['url'], 'new_url' => $url, 'triggered_by' => $triggeredBy,
            ]);
        } elseif ($wasDeleted && $status !== 'deleted') {
            cms_sitemap_log($pdo, [
                'action' => 'restored', 'content_type' => $contentType, 'content_id' => $contentId,
                'new_url' => $url, 'triggered_by' => $triggeredBy,
            ]);
        } else {
            cms_sitemap_log($pdo, [
                'action' => 'updated', 'content_type' => $contentType, 'content_id' => $contentId,
                'new_url' => $url, 'triggered_by' => $triggeredBy,
                'changed_fields' => ['status' => $status, 'included' => $included],
            ]);
        }
    } else {
        $pdo->prepare(
            'INSERT INTO sitemap_urls
                (url, content_type, content_id, content_title, status, included, priority, changefreq,
                 lastmod, last_detected_change, sitemap_file, overrides_json, created_at, updated_at)
             VALUES
                (:url, :type, :id, :title, :status, :included, :priority, :changefreq,
                 :lastmod, :lastdetected, :file, :overrides, NOW(), NOW())'
        )->execute([
            'url' => $url, 'type' => $contentType, 'id' => $contentId, 'title' => $title,
            'status' => $status, 'included' => $included, 'priority' => $priority, 'changefreq' => $changefreq,
            'lastmod' => $now, 'lastdetected' => $now, 'file' => $sitemapFile, 'overrides' => json_encode($overrides),
        ]);
        $rowId = (int) $pdo->lastInsertId();

        cms_sitemap_log($pdo, [
            'action' => 'created', 'content_type' => $contentType, 'content_id' => $contentId,
            'new_url' => $url, 'triggered_by' => $triggeredBy,
        ]);
    }

    return $rowId;
}

/** Marks a sitemap_urls row as deleted (hard content deletes never remove
 *  the sitemap row outright — a 'deleted' row simply stops rendering in
 *  the XML via included=0, while staying visible/auditable in the admin
 *  URL list and changelog). */
function cms_sitemap_mark_deleted(PDO $pdo, string $contentType, ?int $contentId, ?string $triggeredBy = null): void
{
    cms_sitemap_ensure_schema($pdo);
    $triggeredBy = $triggeredBy ?? cms_sitemap_actor();

    $stmt = $pdo->prepare('SELECT id, url FROM sitemap_urls WHERE content_type = :type AND (content_id <=> :id) LIMIT 1');
    $stmt->execute(['type' => $contentType, 'id' => $contentId]);
    $row = $stmt->fetch();
    if (!$row) {
        return;
    }

    $pdo->prepare("UPDATE sitemap_urls SET status = 'deleted', included = 0, last_detected_change = NOW(), updated_at = NOW() WHERE id = :id")
        ->execute(['id' => $row['id']]);

    cms_sitemap_log($pdo, [
        'action' => 'deleted', 'content_type' => $contentType, 'content_id' => $contentId,
        'old_url' => $row['url'], 'triggered_by' => $triggeredBy,
    ]);
}

/**
 * Toggle a row's inclusion from the admin URL list ("Include/Exclude"
 * action) — pins `included` in overrides_json so future content saves
 * don't silently flip it back via the auto-rule.
 */
function cms_sitemap_set_included(PDO $pdo, int $rowId, bool $included, ?string $triggeredBy = null): void
{
    cms_sitemap_ensure_schema($pdo);
    $triggeredBy = $triggeredBy ?? cms_sitemap_actor();

    $stmt = $pdo->prepare('SELECT * FROM sitemap_urls WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $rowId]);
    $row = $stmt->fetch();
    if (!$row) {
        return;
    }

    $overrides = json_decode((string) ($row['overrides_json'] ?? ''), true);
    $overrides = is_array($overrides) ? $overrides : [];
    $overrides['included'] = true;

    $newStatus = $row['status'];
    if (!$included && $newStatus === 'published') {
        $newStatus = 'excluded';
    } elseif ($included && $newStatus === 'excluded') {
        $newStatus = 'published';
    }

    $pdo->prepare('UPDATE sitemap_urls SET included = :included, status = :status, overrides_json = :overrides, updated_at = NOW() WHERE id = :id')
        ->execute(['included' => $included ? 1 : 0, 'status' => $newStatus, 'overrides' => json_encode($overrides), 'id' => $rowId]);

    cms_sitemap_log($pdo, [
        'action' => $included ? 'included' : 'excluded', 'content_type' => $row['content_type'], 'content_id' => $row['content_id'],
        'new_url' => $row['url'], 'triggered_by' => $triggeredBy,
    ]);
}

/**
 * Custom URLs (content_type='custom', content_id always NULL) are handled
 * outside cms_sitemap_upsert() on purpose: that function finds "the
 * existing row" via `content_id <=> :id`, which for NULL would match
 * *any* custom row (NULL <=> NULL is true for every one of them) and
 * silently clobber a different custom entry. Custom entries get their own
 * small, explicit CRUD instead — always by primary key.
 */
function cms_sitemap_add_custom(PDO $pdo, string $relativePath, string $title, float $priority, string $changefreq, bool $included): int
{
    cms_sitemap_ensure_schema($pdo);
    $url = cms_sitemap_absolute_url($relativePath);
    $priority = cms_sitemap_clamp_priority($priority);
    $changefreq = cms_sitemap_valid_changefreq($changefreq);

    $pdo->prepare(
        "INSERT INTO sitemap_urls
            (url, content_type, content_id, content_title, status, included, priority, changefreq,
             lastmod, last_detected_change, sitemap_file, overrides_json, created_at, updated_at)
         VALUES
            (:url, 'custom', NULL, :title, 'published', :included, :priority, :changefreq,
             NOW(), NOW(), 'sitemap-custom.xml', :overrides, NOW(), NOW())"
    )->execute([
        'url' => $url, 'title' => $title, 'included' => $included ? 1 : 0,
        'priority' => $priority, 'changefreq' => $changefreq,
        'overrides' => json_encode(['priority' => true, 'changefreq' => true, 'included' => true]),
    ]);
    $rowId = (int) $pdo->lastInsertId();

    cms_sitemap_log($pdo, ['action' => 'created', 'content_type' => 'custom', 'content_id' => $rowId, 'new_url' => $url]);

    return $rowId;
}

function cms_sitemap_update_custom(PDO $pdo, int $rowId, string $relativePath, string $title, float $priority, string $changefreq, bool $included): void
{
    cms_sitemap_ensure_schema($pdo);
    $stmt = $pdo->prepare("SELECT * FROM sitemap_urls WHERE id = :id AND content_type = 'custom' LIMIT 1");
    $stmt->execute(['id' => $rowId]);
    $row = $stmt->fetch();
    if (!$row) {
        return;
    }

    $url = cms_sitemap_absolute_url($relativePath);
    $pdo->prepare(
        "UPDATE sitemap_urls SET url = :url, content_title = :title, priority = :priority,
            changefreq = :changefreq, included = :included, status = 'published',
            last_detected_change = NOW(), updated_at = NOW()
         WHERE id = :id"
    )->execute([
        'url' => $url, 'title' => $title, 'priority' => cms_sitemap_clamp_priority($priority),
        'changefreq' => cms_sitemap_valid_changefreq($changefreq), 'included' => $included ? 1 : 0, 'id' => $rowId,
    ]);

    cms_sitemap_log($pdo, [
        'action' => $row['url'] !== $url ? 'slug_changed' : 'updated',
        'content_type' => 'custom', 'content_id' => $rowId, 'old_url' => $row['url'], 'new_url' => $url,
    ]);
}

function cms_sitemap_delete_custom(PDO $pdo, int $rowId): bool
{
    cms_sitemap_ensure_schema($pdo);
    $stmt = $pdo->prepare("SELECT url FROM sitemap_urls WHERE id = :id AND content_type = 'custom' LIMIT 1");
    $stmt->execute(['id' => $rowId]);
    $row = $stmt->fetch();
    if (!$row) {
        return false;
    }

    $pdo->prepare('DELETE FROM sitemap_urls WHERE id = :id')->execute(['id' => $rowId]);
    cms_sitemap_log($pdo, ['action' => 'deleted', 'content_type' => 'custom', 'content_id' => $rowId, 'old_url' => $row['url']]);

    return true;
}

/* ── Content-specific hooks ─────────────────────────────────────────── */

/**
 * Call after every successful INSERT/UPDATE in pages.php (articles).
 * $old is the pre-save row (empty array on create), $new is the payload
 * actually written (must include page_id merged in as 'page_id').
 */
function cms_sitemap_on_article_save(PDO $pdo, array $old, array $new): void
{
    $pageId = (int) $new['page_id'];
    $slug = (string) $new['slug'];
    $status = (string) $new['status']; // draft|published
    $noindex = !empty($new['noindex']);
    $canonical = trim((string) ($new['canonical_url'] ?? ''));

    $ownUrl = cms_sitemap_absolute_url(cms_sitemap_path_for('article', $slug));

    // A canonical pointing somewhere else is a strong "don't index this
    // copy" signal — sitemaps should never list non-canonical URLs.
    $canonicalDiffers = $canonical !== '' && rtrim($canonical, '/') !== rtrim($ownUrl, '/');

    if ($noindex || $canonicalDiffers) {
        $sitemapStatus = 'excluded';
    } elseif ($status === 'published') {
        $sitemapStatus = 'published';
    } else {
        // Scheduling isn't automated in this codebase (no cron flips
        // draft->published at a future date) — "scheduled" here is purely
        // an informational label for a draft with a future published_at,
        // still excluded from the sitemap like any other draft.
        $futureDate = !empty($new['published_at']) && strtotime((string) $new['published_at']) > time();
        $sitemapStatus = $futureDate ? 'scheduled' : 'draft';
    }

    cms_sitemap_upsert($pdo, [
        'content_type'  => 'article',
        'content_id'    => $pageId,
        'url'           => $ownUrl,
        'content_title' => (string) $new['title'],
        'status'        => $sitemapStatus,
    ]);

    // Publish/unpublish transitions get their own explicit changelog
    // entries in addition to the generic 'updated'/'created' one already
    // logged by cms_sitemap_upsert(), since the brief calls these out as
    // their own distinct trackable actions.
    $oldStatus = (string) ($old['status'] ?? '');
    if ($oldStatus !== '' && $oldStatus !== $status) {
        cms_sitemap_log($pdo, [
            'action' => $status === 'published' ? 'published' : 'unpublished',
            'content_type' => 'article', 'content_id' => $pageId, 'new_url' => $ownUrl,
        ]);
    }
}

function cms_sitemap_on_article_delete(PDO $pdo, int $pageId): void
{
    cms_sitemap_mark_deleted($pdo, 'article', $pageId);
}

function cms_sitemap_on_category_save(PDO $pdo, int $categoryId, string $name, string $slug): void
{
    cms_sitemap_upsert($pdo, [
        'content_type'  => 'category',
        'content_id'    => $categoryId,
        'url'           => cms_sitemap_absolute_url(cms_sitemap_path_for('category', $slug)),
        'content_title' => $name,
        'status'        => 'published',
    ]);
}

function cms_sitemap_on_category_delete(PDO $pdo, int $categoryId): void
{
    cms_sitemap_mark_deleted($pdo, 'category', $categoryId);
}

function cms_sitemap_on_tag_save(PDO $pdo, int $tagId, string $name, string $slug): void
{
    cms_sitemap_upsert($pdo, [
        'content_type'  => 'tag',
        'content_id'    => $tagId,
        'url'           => cms_sitemap_absolute_url(cms_sitemap_path_for('tag', $slug)),
        'content_title' => $name,
        'status'        => 'published',
    ]);
}

function cms_sitemap_on_tag_delete(PDO $pdo, int $tagId): void
{
    cms_sitemap_mark_deleted($pdo, 'tag', $tagId);
}

/**
 * A redirect being created/edited doesn't have its own sitemap_urls row
 * (redirects aren't a public "page"), but if its old_url matches a URL
 * we're already tracking, that URL should stop being listed in the
 * sitemap (a sitemap should never contain a URL that just 302/301s
 * elsewhere) — flip it to status='redirected'. Always logs a changelog
 * entry either way, per the brief's "Redirect dibuat atau diubah" /
 * "URL lama dialihkan" requirements.
 */
function cms_sitemap_on_redirect_save(PDO $pdo, string $oldUrl, string $newUrl, bool $isCreate): void
{
    cms_sitemap_ensure_schema($pdo);
    $triggeredBy = cms_sitemap_actor();

    $normalizedOld = '/' . ltrim($oldUrl, '/');
    $stmt = $pdo->prepare("SELECT id, url, content_type, content_id FROM sitemap_urls WHERE url LIKE :pattern LIMIT 1");
    $stmt->execute(['pattern' => '%' . $normalizedOld]);
    $match = $stmt->fetch();

    if ($match) {
        $pdo->prepare("UPDATE sitemap_urls SET status = 'redirected', included = 0, last_detected_change = NOW(), updated_at = NOW() WHERE id = :id")
            ->execute(['id' => $match['id']]);
        cms_sitemap_log($pdo, [
            'action' => 'redirected', 'content_type' => $match['content_type'], 'content_id' => $match['content_id'],
            'old_url' => $oldUrl, 'new_url' => $newUrl, 'triggered_by' => $triggeredBy,
        ]);
    } else {
        cms_sitemap_log($pdo, [
            'action' => $isCreate ? 'created' : 'updated', 'content_type' => 'redirect',
            'old_url' => $oldUrl, 'new_url' => $newUrl, 'triggered_by' => $triggeredBy,
        ]);
    }
}

/**
 * Full resync — walks every real content source and upserts it into
 * sitemap_urls, then marks as 'deleted' any tracked row whose source no
 * longer exists. This is what "Regenerate Sitemap" runs, and is also
 * what bootstraps the table the very first time the module is opened
 * (so existing pre-existing content isn't invisible just because it
 * predates this feature). Returns summary counters for the flash message.
 */
function cms_sitemap_full_resync(PDO $pdo, ?string $triggeredBy = null): array
{
    cms_sitemap_ensure_schema($pdo);
    $triggeredBy = $triggeredBy ?? cms_sitemap_actor();
    $stats = ['articles' => 0, 'categories' => 0, 'tags' => 0, 'pages' => 0, 'errors' => 0];

    try {
        // Homepage — always exactly one row.
        cms_sitemap_upsert($pdo, [
            'content_type' => 'homepage', 'content_id' => null,
            'url' => cms_sitemap_absolute_url(''), 'content_title' => 'Homepage', 'status' => 'published',
        ]);
        $stats['pages']++;

        // Static hub/section pages — deliberately empty right now. This
        // used to list a "Semua Berita" entry at cms_sitemap_path_for('category')
        // (no slug), but kategori.php 404s without a ?slug= — there is no
        // "all categories" index route on this frontend, so that would have
        // put a broken URL straight into the sitemap. Fixed 12 Agu 2026.
        // Add entries here once page.php/Special Pages routes actually exist.
        $staticPages = [];
        foreach ($staticPages as $sp) {
            cms_sitemap_upsert($pdo, [
                'content_type' => 'page', 'content_id' => crc32($sp['path']) % 2000000000,
                'url' => cms_sitemap_absolute_url($sp['path']), 'content_title' => $sp['title'], 'status' => 'published',
            ]);
            $stats['pages']++;
        }

        // Articles.
        $trackedArticleIds = [];
        $articles = $pdo->query('SELECT page_id, title, slug, status, published_at, canonical_url, noindex FROM pages')->fetchAll();
        foreach ($articles as $a) {
            cms_sitemap_on_article_save($pdo, [], [
                'page_id' => $a['page_id'], 'title' => $a['title'], 'slug' => $a['slug'],
                'status' => $a['status'], 'published_at' => $a['published_at'],
                'canonical_url' => $a['canonical_url'], 'noindex' => $a['noindex'],
            ]);
            $trackedArticleIds[] = (int) $a['page_id'];
            $stats['articles']++;
        }
        cms_sitemap_prune($pdo, 'article', $trackedArticleIds);

        // Categories.
        $trackedCatIds = [];
        $cats = $pdo->query('SELECT id, name, slug FROM article_categories')->fetchAll();
        foreach ($cats as $c) {
            cms_sitemap_on_category_save($pdo, (int) $c['id'], (string) $c['name'], (string) $c['slug']);
            $trackedCatIds[] = (int) $c['id'];
            $stats['categories']++;
        }
        cms_sitemap_prune($pdo, 'category', $trackedCatIds);

        // Tags.
        $trackedTagIds = [];
        $tags = $pdo->query('SELECT id, name, slug FROM article_tags')->fetchAll();
        foreach ($tags as $t) {
            cms_sitemap_on_tag_save($pdo, (int) $t['id'], (string) $t['name'], (string) $t['slug']);
            $trackedTagIds[] = (int) $t['id'];
            $stats['tags']++;
        }
        cms_sitemap_prune($pdo, 'tag', $trackedTagIds);

        $pdo->prepare('UPDATE sitemap_settings SET last_generated_at = NOW(), last_success_at = NOW(), last_error = NULL ORDER BY id ASC LIMIT 1')->execute();
        cms_sitemap_log($pdo, ['action' => 'sitemap_generated', 'triggered_by' => $triggeredBy, 'result' => 'success']);
    } catch (Throwable $e) {
        $stats['errors']++;
        $pdo->prepare('UPDATE sitemap_settings SET last_generated_at = NOW(), last_error = :err ORDER BY id ASC LIMIT 1')
            ->execute(['err' => $e->getMessage()]);
        cms_sitemap_log($pdo, ['action' => 'sitemap_generated', 'triggered_by' => $triggeredBy, 'result' => 'error', 'error_message' => $e->getMessage()]);
    }

    return $stats;
}

/** Marks any tracked row of a given content type NOT in $liveIds as deleted. */
function cms_sitemap_prune(PDO $pdo, string $contentType, array $liveIds): void
{
    $sql = 'SELECT content_id FROM sitemap_urls WHERE content_type = :type AND status != \'deleted\'';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['type' => $contentType]);
    $tracked = array_map('intval', array_column($stmt->fetchAll(), 'content_id'));

    foreach (array_diff($tracked, array_map('intval', $liveIds)) as $goneId) {
        cms_sitemap_mark_deleted($pdo, $contentType, $goneId, 'System');
    }
}

/**
 * Validate every currently-included URL: well-formed absolute http(s)
 * URL, priority in range, valid changefreq, no duplicate <loc> across the
 * whole set. Flags failing rows as status='error' with a message and
 * logs a single 'validation_executed' summary changelog entry. Returns
 * the list of problems found (empty = all clear).
 */
function cms_sitemap_validate(PDO $pdo): array
{
    cms_sitemap_ensure_schema($pdo);
    $rows = $pdo->query("SELECT * FROM sitemap_urls WHERE included = 1 AND status != 'deleted'")->fetchAll();

    $issues = [];
    $seenUrls = [];
    $errorStmt = $pdo->prepare("UPDATE sitemap_urls SET status = 'error', error_message = :msg, updated_at = NOW() WHERE id = :id");

    foreach ($rows as $row) {
        $problems = [];
        if (!preg_match('#^https?://#i', (string) $row['url'])) {
            $problems[] = 'URL is not absolute (missing http(s)://)';
        }
        if ((float) $row['priority'] < 0 || (float) $row['priority'] > 1) {
            $problems[] = 'Priority out of 0.0–1.0 range';
        }
        if (!in_array($row['changefreq'], ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'], true)) {
            $problems[] = 'Invalid changefreq value';
        }
        if (isset($seenUrls[$row['url']])) {
            $problems[] = 'Duplicate URL also used by row #' . $seenUrls[$row['url']];
        } else {
            $seenUrls[$row['url']] = $row['id'];
        }

        if ($problems !== []) {
            $msg = implode('; ', $problems);
            $errorStmt->execute(['msg' => $msg, 'id' => $row['id']]);
            $issues[] = ['id' => $row['id'], 'url' => $row['url'], 'message' => $msg];
        }
    }

    cms_sitemap_log($pdo, [
        'action' => 'validation_executed',
        'result' => $issues === [] ? 'success' : 'error',
        'error_message' => $issues === [] ? null : (count($issues) . ' URL(s) failed validation'),
    ]);

    return $issues;
}
