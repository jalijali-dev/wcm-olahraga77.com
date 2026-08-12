<?php
declare(strict_types=1);

/**
 * Growth Agent — Fase 2 instrumentation schema + logging helper.
 *
 * Four tables, self-created on first use via cms_ensure_table() (same lazy
 * pattern as sitemap-service.php's cms_sitemap_ensure_schema()):
 *
 *   growth_agent_jobs        one row per generation attempt (manual click
 *                            today, scheduled Growth Agent runs later).
 *                            Statuses drive the stat cards on
 *                            pages/growth-agent.php: ready, running,
 *                            succeeded, failed, manual_action.
 *   growth_agent_feedback    human approve/edit/reject signal against a
 *                            job — this is what lets a past job be reused
 *                            as a few-shot example (see
 *                            services/GrowthAgentPromptBuilder.php).
 *   growth_agent_style_rules living style guide, manually curated for now
 *                            (source='auto_extracted' is reserved for a
 *                            later phase — nothing writes it yet).
 *   growth_agent_performance traffic/ranking signal per page. Schema only
 *                            — nothing ingests into it yet, since there's
 *                            no GA/Search Console integration in this repo.
 *                            Kept here so the column shape is settled
 *                            ahead of that follow-up work.
 *
 * No FK constraints, matching this codebase's existing convention
 * (article_tag_map etc. use plain indexed columns, not CONSTRAINT ...
 * FOREIGN KEY) — app-level integrity, not DB-enforced.
 */
function cms_growth_agent_ensure_schema(PDO $pdo): void
{
    cms_ensure_table(
        $pdo,
        'growth_agent_jobs',
        "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
         job_type VARCHAR(50) NOT NULL COMMENT 'e.g. seo_meta, article_draft',
         agent_key VARCHAR(50) NOT NULL COMMENT 'matches ai_agent_settings.agent_key',
         page_id INT UNSIGNED DEFAULT NULL COMMENT 'pages.page_id — null if not saved yet',
         status ENUM('ready','running','succeeded','failed','manual_action') NOT NULL DEFAULT 'running',
         input_brief TEXT DEFAULT NULL COMMENT 'JSON snapshot of what was sent to the agent',
         output_json TEXT DEFAULT NULL COMMENT 'JSON snapshot of the parsed result',
         model_used VARCHAR(100) DEFAULT NULL,
         tokens_in INT UNSIGNED DEFAULT NULL,
         tokens_out INT UNSIGNED DEFAULT NULL,
         latency_ms INT UNSIGNED DEFAULT NULL,
         error_message TEXT DEFAULT NULL,
         created_by INT UNSIGNED DEFAULT NULL COMMENT 'admins.admin_id, null = system',
         created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
         updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
         KEY idx_gaj_status (status),
         KEY idx_gaj_page (page_id),
         KEY idx_gaj_agent_key (agent_key)"
    );

    cms_ensure_table(
        $pdo,
        'growth_agent_feedback',
        "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
         job_id INT UNSIGNED NOT NULL,
         action ENUM('approved_as_is','approved_with_edits','rejected') NOT NULL,
         notes TEXT DEFAULT NULL,
         reviewed_by INT UNSIGNED DEFAULT NULL COMMENT 'admins.admin_id',
         created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
         KEY idx_gaf_job (job_id)"
    );

    cms_ensure_table(
        $pdo,
        'growth_agent_style_rules',
        "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
         rule_text TEXT NOT NULL,
         source ENUM('manual','auto_extracted') NOT NULL DEFAULT 'manual',
         is_active TINYINT(1) NOT NULL DEFAULT 1,
         created_by INT UNSIGNED DEFAULT NULL,
         created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
         KEY idx_gasr_active (is_active)"
    );

    cms_ensure_table(
        $pdo,
        'growth_agent_performance',
        "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
         page_id INT UNSIGNED NOT NULL,
         metric_date DATE NOT NULL,
         pageviews INT UNSIGNED NOT NULL DEFAULT 0,
         impressions INT UNSIGNED NOT NULL DEFAULT 0,
         avg_ranking_position DECIMAL(6,2) DEFAULT NULL,
         clicks INT UNSIGNED NOT NULL DEFAULT 0,
         ctr DECIMAL(6,4) DEFAULT NULL,
         created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
         UNIQUE KEY uniq_gap_page_date (page_id, metric_date)"
    );
    // impressions (28 Jul 2026, Feedback Loop gap #4) — the original
    // schema-only table predates any real writer and never had impressions,
    // needed both to compute CTR properly here and to weight avg_ranking_position
    // by impressions when combining multiple queries for the same page/day
    // (see cms_growth_agent_snapshot_performance()). Additive column, safe
    // on any pre-existing installs of this table.
    cms_ensure_column($pdo, 'growth_agent_performance', 'impressions', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER `pageviews`');

    // Agent Memory (ROADMAP.md gap #3, GROWTH_AGENT_SEO_ROADMAP.md §
    // Growth memory, closed 28 Jul 2026) — completes the half-finished
    // port noted in gsc-api.php ("out of scope for this port"):
    // gsc_settings.memory_thresholds_json/last_memory_detection_at and
    // cms_gsc_default_memory_thresholds()/cms_gsc_get_memory_thresholds()
    // already existed unused; this table is what they were always meant
    // to feed. dedupe_key (same md5-hash convention as
    // gsc_opportunities.dedupe_key) rather than a UNIQUE key across the
    // nullable matched_page_id/query_text columns directly — MySQL never
    // enforces uniqueness across a combination where a column is NULL in
    // both rows being compared, which would silently defeat dedup for the
    // query-scope rows (matched_page_id always NULL there).
    // ADVISORY ONLY: nothing in this table is ever read by anything that
    // creates/approves/executes a growth_agent_jobs row — only
    // GrowthAgentPromptBuilder::buildMemoryContext() reads 'active' rows,
    // and only to add plain text to a prompt. See
    // cms_growth_agent_detect_memory_patterns() for the (deterministic,
    // no AI) detection logic and cms_growth_agent_mark_memory_stale() for
    // the one manual action this feature has (there is no approve/execute
    // — memory is not an action queue).
    cms_ensure_table(
        $pdo,
        'growth_agent_memory',
        "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
         pattern_type ENUM('winning_pattern','content_gap') NOT NULL,
         scope_type ENUM('page','query') NOT NULL,
         matched_page_id INT UNSIGNED DEFAULT NULL,
         query_text VARCHAR(255) DEFAULT NULL,
         status ENUM('pending_review','active','stale') NOT NULL DEFAULT 'pending_review',
         evidence_json TEXT DEFAULT NULL,
         distinct_weeks_seen INT UNSIGNED NOT NULL DEFAULT 0,
         dedupe_key CHAR(32) NOT NULL,
         first_detected_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
         last_confirmed_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
         created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
         updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
         UNIQUE KEY uniq_gam_dedupe (dedupe_key),
         KEY idx_gam_status (status),
         KEY idx_gam_page (matched_page_id)"
    );

    cms_growth_agent_ensure_legacy_status($pdo);

    // Measurement Loop (GROWTH_AGENT_V2_PROPOSAL.md § Fase C, reprioritized
    // 5 Aug 2026 ahead of Fase E — see cms_growth_agent_run_measurement_loop()
    // below for the feature itself). Additive column, same precedent as
    // `priority` (gsc-api.php's cms_gsc_ensure_schema()): a plain indexed
    // TIMESTAMP, not a flag buried in output_json, because "which succeeded
    // jobs are 28+ days old and still unmeasured" needs to be a cheap
    // indexed WHERE, not a per-row JSON scan. NULL = not yet measured (or
    // not eligible); a non-NULL value only ever means "a measurement
    // attempt ran for this job" — it does not by itself mean the result was
    // a usable comparison (see that function's own note on
    // status='insufficient_data' still counting as measured).
    cms_ensure_column($pdo, 'growth_agent_jobs', 'measured_at', 'TIMESTAMP NULL DEFAULT NULL AFTER `updated_at`');

    // Autonomous Mode (GROWTH_AGENT_V2_PROPOSAL.md § Fase E, 6 Aug 2026) —
    // see cms_growth_agent_ensure_autonomous_schema()'s own docblock.
    cms_growth_agent_ensure_autonomous_schema($pdo);

    // Trending Headlines (GROWTH_AGENT_V2_PROPOSAL.md § 5, 6 Aug 2026) —
    // headline + link + publish time ONLY, one row per external article,
    // fed into the Article Idea prompt as inspiration/context (see
    // cms_growth_agent_get_trending_headlines_for_prompt()). Deliberately
    // NOT storing full article body anywhere — copyright risk the proposal
    // doc is explicit about; the fetcher itself
    // (cms_growth_agent_fetch_trending_source()) never even parses full
    // article content, only feed/listing-page metadata.
    //
    // dedupe_hash = md5(url) — a source's RSS/listing page is re-fetched on
    // every refresh, so the same headline would otherwise be inserted again
    // each time; the URL is the one field guaranteed both present and
    // stable per external article. UNIQUE + upsert-by-hash (see that
    // fetcher's own INSERT ... ON DUPLICATE KEY UPDATE) means a
    // re-fetched headline just refreshes fetched_at, never duplicates.
    cms_ensure_table(
        $pdo,
        'growth_agent_trending_headlines',
        "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
         headline VARCHAR(500) NOT NULL,
         source VARCHAR(255) NOT NULL COMMENT 'the configured source URL this came from, e.g. https://sport.detik.com',
         url VARCHAR(500) NOT NULL,
         published_at DATETIME DEFAULT NULL COMMENT 'NULL if the source did not expose a parseable timestamp',
         fetched_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
         dedupe_hash CHAR(32) NOT NULL,
         UNIQUE KEY uniq_gath_dedupe (dedupe_hash),
         KEY idx_gath_fetched (fetched_at)"
    );
}

/**
 * Widens growth_agent_jobs.status and growth_agent_feedback.action to add
 * 'closed_as_legacy' (27 Jul 2026, "Close as Legacy" review action) —
 * distinct from 'rejected'/'failed' (which mean "this was bad") and from
 * 'approved_as_is'/'succeeded' (which mean "this was good"): legacy means
 * neither judgment applies, the underlying signal (e.g. stale GSC data) is
 * just no longer relevant. cms_ensure_column() can't widen an existing
 * ENUM, only add a missing column — so this checks the live column
 * definition via information_schema first and only ALTERs when the new
 * value isn't already in it, mirroring the exact same widen-safe pattern
 * this project's sibling codebase used for its own 2-tier->3-tier
 * priority migration.
 */
function cms_growth_agent_ensure_legacy_status(PDO $pdo): void
{
    $statusType = (string) $pdo->query(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'growth_agent_jobs' AND COLUMN_NAME = 'status'"
    )->fetchColumn();
    if ($statusType !== '' && !str_contains($statusType, "'closed_as_legacy'")) {
        $pdo->exec("ALTER TABLE `growth_agent_jobs` MODIFY COLUMN `status` ENUM('ready','running','succeeded','failed','manual_action','closed_as_legacy') NOT NULL DEFAULT 'running'");
    }

    $actionType = (string) $pdo->query(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'growth_agent_feedback' AND COLUMN_NAME = 'action'"
    )->fetchColumn();
    if ($actionType !== '' && !str_contains($actionType, "'closed_as_legacy'")) {
        $pdo->exec("ALTER TABLE `growth_agent_feedback` MODIFY COLUMN `action` ENUM('approved_as_is','approved_with_edits','rejected','closed_as_legacy') NOT NULL");
    }
}

/**
 * Widens growth_agent_jobs.status (+'reverted') and growth_agent_feedback.action
 * (+'auto_applied') for Autonomous Mode (GROWTH_AGENT_V2_PROPOSAL.md § Fase
 * E, 6 Aug 2026) — exact same widen-safe pattern as
 * cms_growth_agent_ensure_legacy_status() right above, kept as a separate
 * function per feature rather than folded into that one, same reasoning
 * cms_gsc_ensure_cannibalization_action() is its own function too.
 *
 * 'auto_applied' (feedback.action): distinct from 'approved_as_is' so a
 * human-approved internal link and an autonomous one are queryable
 * separately — this is what cms_growth_agent_autonomous_maybe_apply_internal_link()
 * retags the row to right after calling the exact same
 * cms_growth_agent_apply_internal_link() the manual "Apply" button uses (it
 * always inserts 'approved_as_is' first; retagging after the fact means
 * zero changes to that shared function). Also what the weekly rate-limit
 * count and the Revert UI's eligibility check key off of.
 *
 * 'reverted' (jobs.status): deliberately NOT reusing 'closed_as_legacy' —
 * their meanings differ ("no longer relevant" vs. "an operator undid a
 * live content change") — and deliberately NOT staying 'succeeded' after a
 * revert, because 'succeeded' is exactly what
 * cms_growth_agent_get_feedback_report() and
 * cms_growth_agent_run_measurement_loop() filter on: once reverted, the
 * link is no longer even in the article, so it must stop being measured
 * and stop appearing in the Feedback panel. Changing status away from
 * 'succeeded' achieves that for free, with no extra filtering logic needed
 * in either of those two functions.
 */
function cms_growth_agent_ensure_autonomous_schema(PDO $pdo): void
{
    $statusType = (string) $pdo->query(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'growth_agent_jobs' AND COLUMN_NAME = 'status'"
    )->fetchColumn();
    if ($statusType !== '' && !str_contains($statusType, "'reverted'")) {
        $pdo->exec("ALTER TABLE `growth_agent_jobs` MODIFY COLUMN `status` ENUM('ready','running','succeeded','failed','manual_action','closed_as_legacy','reverted') NOT NULL DEFAULT 'running'");
    }

    $actionType = (string) $pdo->query(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'growth_agent_feedback' AND COLUMN_NAME = 'action'"
    )->fetchColumn();
    if ($actionType !== '' && !str_contains($actionType, "'auto_applied'")) {
        $pdo->exec("ALTER TABLE `growth_agent_feedback` MODIFY COLUMN `action` ENUM('approved_as_is','approved_with_edits','rejected','closed_as_legacy','auto_applied') NOT NULL");
    }
}

/**
 * SEO Intelligence (Topic Cluster + Content Conflict Detection) — separate
 * lazy schema from cms_growth_agent_ensure_schema(), same
 * cms_ensure_table() pattern, called explicitly from
 * pages/seo-intelligence.php and pages/content-conflict-detection.php
 * rather than folded into the main schema function, since this feature is
 * its own self-contained addition.
 *
 * Both tables are full-recompute, not incremental: every "Generate" click
 * deletes all existing rows for that table and inserts a fresh batch (see
 * cms_growth_agent_generate_topic_clusters() /
 * cms_growth_agent_generate_content_conflicts()) — same spirit as the
 * existing "Hitung Ulang Opportunities" recompute.
 */
function cms_growth_agent_seo_intel_ensure_schema(PDO $pdo): void
{
    cms_ensure_table(
        $pdo,
        'growth_agent_topic_clusters',
        "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
         cluster_name VARCHAR(255) NOT NULL,
         pillar_page_id INT UNSIGNED DEFAULT NULL COMMENT 'pages.page_id, app-level FK',
         supporting_page_ids TEXT NOT NULL COMMENT 'JSON array of page_id',
         status ENUM('needs_more_content','good_coverage') NOT NULL DEFAULT 'needs_more_content',
         missing_content_json TEXT DEFAULT NULL COMMENT 'JSON array of {topic: string}',
         generated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
         model_used VARCHAR(100) DEFAULT NULL,
         KEY idx_gatc_status (status)"
    );

    cms_ensure_table(
        $pdo,
        'growth_agent_content_conflicts',
        "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
         page_a_id INT UNSIGNED NOT NULL,
         page_b_id INT UNSIGNED NOT NULL,
         risk ENUM('low','medium','high') NOT NULL DEFAULT 'low',
         issue_text TEXT NOT NULL,
         recommendation_text TEXT NOT NULL,
         status ENUM('open','proposal_requested','dismissed') NOT NULL DEFAULT 'open',
         generated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
         model_used VARCHAR(100) DEFAULT NULL,
         KEY idx_gacc_status (status)"
    );
}

/**
 * Resolves a list of AI-provided slugs back to page_id, using ONLY the
 * slug=>page_id map built from the exact candidate list sent in the prompt
 * — never trusts a page_id the AI might return directly, since that's an
 * easy hallucination surface. Unknown slugs are silently dropped.
 *
 * @param array<string, int> $slugToPageId
 * @param list<mixed> $slugs
 * @return list<int>
 */
function cms_growth_agent_seo_intel_resolve_slugs(array $slugToPageId, array $slugs): array
{
    $resolved = [];
    foreach ($slugs as $slug) {
        $slug = trim((string) $slug);
        if ($slug !== '' && isset($slugToPageId[$slug])) {
            $resolved[] = $slugToPageId[$slug];
        }
    }
    return $resolved;
}

/**
 * Topic Cluster generation — full recompute triggered by the "Generate
 * Cluster" button on pages/seo-intelligence.php. Sends title + slug +
 * meta_description (falling back to excerpt) for the 50 most recent
 * published articles — NOT full content, too expensive for 50 articles in
 * one call — and asks the AI to group them into topic clusters, pick a
 * pillar per cluster, flag clusters that need more supporting content, and
 * suggest missing subtopics.
 *
 * Same "generate + log" pattern as cms_growth_agent_generate_content_optimization()
 * (cms_ai_resolve_agent + cms_ai_call_provider + cms_ai_extract_json), just
 * writing into growth_agent_topic_clusters instead of growth_agent_jobs —
 * this is a data table, not an action queue, since clustering itself isn't
 * something to approve/reject.
 *
 * On parse/AI failure, existing rows are left untouched so the UI keeps
 * showing the last successful generate instead of going blank.
 *
 * @return array{ok:bool, clusters_created:int, error:string}
 */
function cms_growth_agent_generate_topic_clusters(PDO $pdo): array
{
    try {
        cms_growth_agent_seo_intel_ensure_schema($pdo);
        require_once __DIR__ . '/ai-helpers.php';
    } catch (Throwable $e) {
        return ['ok' => false, 'clusters_created' => 0, 'error' => $e->getMessage()];
    }

    try {
        $stmt = $pdo->query(
            "SELECT page_id, title, slug, meta_description, excerpt
               FROM pages
              WHERE status = 'published'
              ORDER BY created_at DESC
              LIMIT 50"
        );
        $pages = $stmt->fetchAll();
    } catch (Throwable $e) {
        return ['ok' => false, 'clusters_created' => 0, 'error' => $e->getMessage()];
    }

    if ($pages === []) {
        return ['ok' => false, 'clusters_created' => 0, 'error' => 'Tidak ada artikel published untuk dianalisis.'];
    }

    $slugToPageId = [];
    $promptLines = [];
    foreach ($pages as $page) {
        $slug = (string) $page['slug'];
        $slugToPageId[$slug] = (int) $page['page_id'];
        $desc = trim((string) ($page['meta_description'] ?? ''));
        if ($desc === '') {
            $desc = trim((string) ($page['excerpt'] ?? ''));
        }
        $promptLines[] = "- slug: {$slug} | title: {$page['title']} | description: {$desc}";
    }

    $defaultSystemPrompt =
        'You are the Growth Agent SEO strategist for an Indonesian-language sports news website. ' .
        'You are given a list of published articles (slug, title, short description). Group them into ' .
        'topic clusters based on topical similarity / shared search intent. For each cluster, pick the ' .
        'single most comprehensive/representative article as the "pillar" and the rest as "supporting". ' .
        'Mark a cluster status "needs_more_content" if it has fewer than 3 supporting articles, otherwise ' .
        '"good_coverage". For every "needs_more_content" cluster, suggest 3-5 specific subtopics not yet ' .
        'covered by any article in that cluster. Only reference slugs from the given list — never invent a ' .
        'slug. Respond with ONLY a raw JSON object, no markdown, no code fences, no commentary, in exactly ' .
        'this shape: {"clusters": [{"cluster_name": "...", "pillar_slug": "...", ' .
        '"supporting_slugs": ["...", "..."], "status": "needs_more_content", "missing_topics": ["...", "..."]}]}';

    $agent = cms_ai_resolve_agent($pdo, 'growth_agent', $defaultSystemPrompt);
    if (!$agent['ok']) {
        return ['ok' => false, 'clusters_created' => 0, 'error' => $agent['error']];
    }

    $userPrompt = "Articles (max 50, most recent published first):\n" . implode("\n", $promptLines);

    try {
        $result = cms_ai_call_provider(
            $agent['provider'], $agent['api_key'], $agent['model'],
            $userPrompt, $agent['system_prompt'], max($agent['max_tokens'], 1500), $agent['temperature']
        );
    } catch (Throwable $e) {
        return ['ok' => false, 'clusters_created' => 0, 'error' => $e->getMessage()];
    }

    $parsed = $result['success'] ? cms_ai_extract_json($result['text']) : null;
    if (!$result['success'] || !is_array($parsed) || !is_array($parsed['clusters'] ?? null)) {
        $errorMessage = $result['success'] ? 'AI response was not in the expected format' : ('AI request failed: ' . $result['error']);
        return ['ok' => false, 'clusters_created' => 0, 'error' => $errorMessage];
    }

    $rows = [];
    foreach ($parsed['clusters'] as $cluster) {
        if (!is_array($cluster)) {
            continue;
        }
        $clusterName = trim((string) ($cluster['cluster_name'] ?? ''));
        if ($clusterName === '') {
            continue;
        }
        $pillarSlug = trim((string) ($cluster['pillar_slug'] ?? ''));
        $pillarPageId = $pillarSlug !== '' && isset($slugToPageId[$pillarSlug]) ? $slugToPageId[$pillarSlug] : null;
        $supportingIds = cms_growth_agent_seo_intel_resolve_slugs($slugToPageId, is_array($cluster['supporting_slugs'] ?? null) ? $cluster['supporting_slugs'] : []);
        $status = (string) ($cluster['status'] ?? 'needs_more_content');
        $status = in_array($status, ['needs_more_content', 'good_coverage'], true) ? $status : 'needs_more_content';
        $missingTopics = [];
        foreach (is_array($cluster['missing_topics'] ?? null) ? $cluster['missing_topics'] : [] as $topic) {
            $topic = trim((string) $topic);
            if ($topic !== '') {
                $missingTopics[] = ['topic' => $topic];
            }
        }

        $rows[] = [
            'cluster_name' => $clusterName,
            'pillar_page_id' => $pillarPageId,
            'supporting_page_ids' => json_encode($supportingIds, JSON_UNESCAPED_UNICODE),
            'status' => $status,
            'missing_content_json' => $missingTopics !== [] ? json_encode($missingTopics, JSON_UNESCAPED_UNICODE) : null,
            'model_used' => $agent['model'],
        ];
    }

    if ($rows === []) {
        return ['ok' => false, 'clusters_created' => 0, 'error' => 'AI tidak menghasilkan cluster yang valid.'];
    }

    try {
        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM growth_agent_topic_clusters');
        $ins = $pdo->prepare(
            'INSERT INTO growth_agent_topic_clusters
                (cluster_name, pillar_page_id, supporting_page_ids, status, missing_content_json, generated_at, model_used)
             VALUES
                (:cluster_name, :pillar_page_id, :supporting_page_ids, :status, :missing_content_json, NOW(), :model_used)'
        );
        foreach ($rows as $row) {
            $ins->execute($row);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'clusters_created' => 0, 'error' => $e->getMessage()];
    }

    return ['ok' => true, 'clusters_created' => count($rows), 'error' => ''];
}

/**
 * Content Conflict Detection — full recompute triggered by the "Generate"
 * button on pages/content-conflict-detection.php. Same 50-article
 * title+description candidate set as
 * cms_growth_agent_generate_topic_clusters() (kept identical on purpose so
 * both features are analyzing the same snapshot), asks the AI to find
 * PAIRS of articles whose search intent is too similar / at risk of
 * cannibalizing each other.
 *
 * This is a distinct, AI-driven sibling to
 * cms_growth_agent_log_cannibalization_review() — that one is pure SQL
 * against real GSC click/impression data (a query IS already splitting
 * traffic across pages), this one is a content-similarity heuristic over
 * article metadata (a query MIGHT end up splitting traffic once both
 * articles rank). Different evidence, different table, both still land on
 * the same "Recommendation only" guardrail: neither ever merges/redirects
 * anything automatically.
 *
 * @return array{ok:bool, conflicts_created:int, error:string}
 */
function cms_growth_agent_generate_content_conflicts(PDO $pdo): array
{
    try {
        cms_growth_agent_seo_intel_ensure_schema($pdo);
        require_once __DIR__ . '/ai-helpers.php';
    } catch (Throwable $e) {
        return ['ok' => false, 'conflicts_created' => 0, 'error' => $e->getMessage()];
    }

    try {
        $stmt = $pdo->query(
            "SELECT page_id, title, slug, meta_description, excerpt
               FROM pages
              WHERE status = 'published'
              ORDER BY created_at DESC
              LIMIT 50"
        );
        $pages = $stmt->fetchAll();
    } catch (Throwable $e) {
        return ['ok' => false, 'conflicts_created' => 0, 'error' => $e->getMessage()];
    }

    if ($pages === []) {
        return ['ok' => false, 'conflicts_created' => 0, 'error' => 'Tidak ada artikel published untuk dianalisis.'];
    }

    $slugToPageId = [];
    $promptLines = [];
    foreach ($pages as $page) {
        $slug = (string) $page['slug'];
        $slugToPageId[$slug] = (int) $page['page_id'];
        $desc = trim((string) ($page['meta_description'] ?? ''));
        if ($desc === '') {
            $desc = trim((string) ($page['excerpt'] ?? ''));
        }
        $promptLines[] = "- slug: {$slug} | title: {$page['title']} | description: {$desc}";
    }

    $defaultSystemPrompt =
        'You are the Growth Agent SEO strategist for an Indonesian-language sports news website. ' .
        'You are given a list of published articles (slug, title, short description). Find PAIRS of ' .
        'articles whose search intent is too similar and at risk of cannibalizing each other in Google ' .
        'Search (competing for the same queries). For each pair, give a risk level (low/medium/high), a ' .
        'short issue description, and a free-text recommendation (e.g. differentiate intent, merge ' .
        'candidate, distinguish angle). Only reference slugs from the given list — never invent a slug. ' .
        'Only report pairs with a real, specific overlap — do not pad the list. Respond with ONLY a raw ' .
        'JSON object, no markdown, no code fences, no commentary, in exactly this shape: ' .
        '{"conflicts": [{"slug_a": "...", "slug_b": "...", "risk": "low", "issue": "...", "recommendation": "..."}]}';

    $agent = cms_ai_resolve_agent($pdo, 'growth_agent', $defaultSystemPrompt);
    if (!$agent['ok']) {
        return ['ok' => false, 'conflicts_created' => 0, 'error' => $agent['error']];
    }

    $userPrompt = "Articles (max 50, most recent published first):\n" . implode("\n", $promptLines);

    try {
        $result = cms_ai_call_provider(
            $agent['provider'], $agent['api_key'], $agent['model'],
            $userPrompt, $agent['system_prompt'], max($agent['max_tokens'], 1500), $agent['temperature']
        );
    } catch (Throwable $e) {
        return ['ok' => false, 'conflicts_created' => 0, 'error' => $e->getMessage()];
    }

    $parsed = $result['success'] ? cms_ai_extract_json($result['text']) : null;
    if (!$result['success'] || !is_array($parsed) || !is_array($parsed['conflicts'] ?? null)) {
        $errorMessage = $result['success'] ? 'AI response was not in the expected format' : ('AI request failed: ' . $result['error']);
        return ['ok' => false, 'conflicts_created' => 0, 'error' => $errorMessage];
    }

    $rows = [];
    foreach ($parsed['conflicts'] as $conflict) {
        if (!is_array($conflict)) {
            continue;
        }
        $slugA = trim((string) ($conflict['slug_a'] ?? ''));
        $slugB = trim((string) ($conflict['slug_b'] ?? ''));
        if ($slugA === '' || $slugB === '' || !isset($slugToPageId[$slugA], $slugToPageId[$slugB])) {
            continue;
        }
        $pageAId = $slugToPageId[$slugA];
        $pageBId = $slugToPageId[$slugB];
        if ($pageAId === $pageBId) {
            continue;
        }
        $issue = trim((string) ($conflict['issue'] ?? ''));
        $recommendation = trim((string) ($conflict['recommendation'] ?? ''));
        if ($issue === '' || $recommendation === '') {
            continue;
        }
        $risk = (string) ($conflict['risk'] ?? 'low');
        $risk = in_array($risk, ['low', 'medium', 'high'], true) ? $risk : 'low';

        $rows[] = [
            'page_a_id' => $pageAId,
            'page_b_id' => $pageBId,
            'risk' => $risk,
            'issue_text' => $issue,
            'recommendation_text' => $recommendation,
            'model_used' => $agent['model'],
        ];
    }

    if ($rows === []) {
        return ['ok' => false, 'conflicts_created' => 0, 'error' => 'AI tidak menemukan konflik konten yang valid.'];
    }

    try {
        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM growth_agent_content_conflicts');
        $ins = $pdo->prepare(
            'INSERT INTO growth_agent_content_conflicts
                (page_a_id, page_b_id, risk, issue_text, recommendation_text, status, generated_at, model_used)
             VALUES
                (:page_a_id, :page_b_id, :risk, :issue_text, :recommendation_text, \'open\', NOW(), :model_used)'
        );
        foreach ($rows as $row) {
            $ins->execute($row);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'conflicts_created' => 0, 'error' => $e->getMessage()];
    }

    return ['ok' => true, 'conflicts_created' => count($rows), 'error' => ''];
}

/**
 * ── SEO-G0 Gate (GROWTH_AGENT_V2_PROPOSAL.md Fase A item 3, 4 Agu 2026) ──
 *
 * Deterministic topic-overlap pre-check run before either of the two
 * new-article proposal paths logs its job: cms_growth_agent_generate_
 * article_idea() (job_type 'gsc_article_idea', triggered from the
 * "Peluang Terprioritas" panel on growth-agent.php) and
 * cms_growth_agent_request_topic_gap_article() (job_type
 * 'topic_gap_article', triggered from seo-intelligence.php). Both call
 * cms_growth_agent_seo_g0_gate() below and merge its result into their own
 * input_brief under the 'seo_g0_gate' key — no new table/column, per the
 * doc's § 1b "Action Queue is the only queue" rule.
 *
 * Design decisions locked in by the proposal doc, not to be changed here:
 *   - Advisory only, never blocking. The proposal is ALWAYS created
 *     regardless of what the gate finds; a warning just rides along on
 *     the same row for the operator to see during their normal
 *     approve/reject review. No separate override control — there is
 *     nothing to override since nothing is blocked.
 *   - Deterministic only. All three checks are plain SQL + string/token
 *     comparison, never an AI call — same "must be consistent and
 *     auditable, not vary run to run" reasoning as the Opportunity Engine.
 *   - Never throws. A gate failure must never prevent the proposal it's
 *     attached to from being created (see cms_growth_agent_seo_g0_gate()'s
 *     own docblock for how each sub-check degrades independently).
 */

/**
 * Tokenizer + stopword filter for the gate's topic-similarity checks — not
 * a general NLP tool, tuned specifically for short Indonesian sports-news
 * phrases (GSC queries, article titles, topic-gap labels). Strips
 * punctuation, lowercases, and removes both standard Indonesian function
 * words AND terms that are generic on THIS site specifically ("jadwal",
 * "hasil", "live", "streaming", "vs", "skor", "berita", ...). The
 * sports-generic half matters as much as the stopword half: without it,
 * almost every headline on a livescore site shares 3-4 of those words
 * regardless of actual topic, which would flag nearly any two articles as
 * "similar" and train operators to ignore the warning entirely.
 *
 * @return string[] unique, order-independent token set (empty array if
 *                   nothing meaningful survives filtering)
 */
function cms_growth_agent_g0_tokenize(string $text): array
{
    static $stopwords = null;
    if ($stopwords === null) {
        $stopwords = array_flip([
            // Indonesian function/structural words.
            'yang', 'dan', 'di', 'ke', 'dari', 'untuk', 'dengan', 'pada', 'itu', 'ini', 'akan', 'atau',
            'juga', 'saja', 'adalah', 'sudah', 'belum', 'tidak', 'ada', 'dalam', 'oleh', 'para', 'bisa',
            'dapat', 'tersebut', 'seperti', 'karena', 'jika', 'kalau', 'saat', 'ketika', 'setelah',
            'sebelum', 'terhadap', 'antara', 'hingga', 'sampai', 'lebih', 'sangat', 'banyak', 'semua',
            'satu', 'dua', 'tiga', 'empat', 'lima', 'apa', 'siapa', 'kapan', 'dimana', 'mengapa',
            'bagaimana', 'tanpa', 'atas', 'bawah', 'antar', 'per', 'nya', 'pun', 'tak', 'gak', 'ga',
            'yg', 'dgn', 'utk', 'dr', 'pd', 'si', 'sang', 'tentang', 'soal', 'seputar', 'mengenai',
            'bagi', 'agar', 'supaya', 'maupun', 'namun', 'tetapi', 'serta', 'usai',
            // Generic on a livescore/sports-news site — present in most
            // headlines regardless of topic, so keeping them would make
            // nearly any two articles register as "similar".
            'jadwal', 'hasil', 'live', 'streaming', 'vs', 'skor', 'score', 'berita', 'terbaru', 'update',
            'video', 'highlight', 'highlights', 'prediksi', 'link', 'nonton', 'gratis', 'malam', 'hari',
            'wib', 'babak', 'pertandingan', 'main', 'bermain', 'laga', 'duel', 'partai', 'leg',
            'matchday', 'preview', 'recap', 'ringkasan', 'terkini', 'terkait', 'klasemen', 'statistik',
            'info', 'cara', 'h2h',
            // Generic transfer-window/match-report vocabulary (added after
            // Internal Linking Agent testing surfaced it, 4 Agu 2026) — words
            // like "transfer"/"musim"/"panas"/"trofi"/"juara" recur across
            // almost every transfer or match-result article regardless of
            // which players/clubs/tournament are actually involved, so
            // without these two problems showed up: (1) topic-overlap
            // matches on nothing but this generic vocabulary between
            // otherwise-unrelated articles, (2) anchor phrases built from
            // title fragments that happened to include one of these words
            // at the edge, reading as awkward/ungrammatical link text (e.g.
            // "pulang tanpa trofi di" — a real anchor this produced before
            // 'trofi'/'pulang' were added here).
            'bursa', 'transfer', 'musim', 'panas', 'dingin', 'resmi', 'rp', 'triliun', 'juta', 'banderol',
            'nilai', 'kontrak', 'gelar', 'trofi', 'turnamen', 'juara', 'pulang', 'datang', 'tampil',
            'sebagai', 'menjadi', 'menjadikannya', 'terbesar', 'jendela', 'winger', 'striker', 'gelandang',
            'bek', 'kiper', 'pemain', 'klub',
            // Common Indonesian intensifiers/adverbs (added 4 Agu 2026
            // after "paling" — a bare intensifier with zero topical
            // meaning — was proposed and briefly applied as a live anchor
            // in production). NOTE: this list is NOT the actual fix for
            // that class of bug — see cms_growth_agent_il_candidate_
            // phrases()'s corpus-document-frequency + mid-sentence-
            // capitalization gating for the real, self-adjusting defense.
            // This is just incidental cleanup so today's known offenders
            // don't even reach that machinery.
            'paling', 'sangat', 'lebih', 'sekali', 'bakal', 'makin', 'banget', 'cukup', 'agak',
            'terlalu', 'amat', 'begitu', 'terus', 'masih', 'selalu', 'kembali', 'kian',
        ]);
    }

    $normalized = mb_strtolower($text);
    $normalized = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalized) ?? '';
    $rawTokens = preg_split('/\s+/', trim($normalized)) ?: [];

    $tokens = [];
    foreach ($rawTokens as $token) {
        if ($token === '' || mb_strlen($token) < 3 || isset($stopwords[$token])) {
            continue;
        }
        $tokens[$token] = true;
    }

    return array_keys($tokens);
}

/**
 * Overlap coefficient (|A∩B| / min(|A|,|B|)) between two token sets — used
 * instead of Jaccard (|A∩B| / |A∪B|) because the two sides being compared
 * are usually very different lengths: a GSC query or missing-topic label
 * is typically 2-5 meaningful words, an article title 6-12. Jaccard would
 * get dragged down by the longer side's unrelated words even when every
 * meaningful word of the SHORT side is fully contained in the long one —
 * exactly the "this query is already covered by that article" case this
 * gate exists to catch. Overlap coefficient measures "how much of the
 * smaller set is contained in the larger one", which matches that intent
 * directly.
 *
 * @param string[] $a
 * @param string[] $b
 * @return array{coefficient: float, intersection: string[]}
 */
function cms_growth_agent_g0_overlap(array $a, array $b): array
{
    if ($a === [] || $b === []) {
        return ['coefficient' => 0.0, 'intersection' => []];
    }
    $intersection = array_values(array_intersect($a, $b));
    $minSize = min(count($a), count($b));

    return [
        'coefficient' => $minSize > 0 ? count($intersection) / $minSize : 0.0,
        'intersection' => $intersection,
    ];
}

/**
 * Reads the gate's similarity_threshold/min_overlap_tokens, nested under
 * opportunity_thresholds_json's 'seo_g0_gate' key (see
 * cms_gsc_default_opportunity_thresholds() in gsc-api.php) — same
 * array_replace_recursive-over-defaults pattern as every other threshold
 * getter in this codebase, so an admin can retune it from the DB without a
 * migration. Never throws.
 *
 * @return array{similarity_threshold: float, min_overlap_tokens: int}
 */
function cms_growth_agent_g0_gate_thresholds(PDO $pdo): array
{
    try {
        require_once __DIR__ . '/gsc-api.php';
        $defaults = cms_gsc_default_opportunity_thresholds()['seo_g0_gate'];
        $configured = cms_gsc_get_opportunity_thresholds($pdo)['seo_g0_gate'] ?? [];

        return array_replace_recursive($defaults, is_array($configured) ? $configured : []);
    } catch (Throwable $e) {
        return ['similarity_threshold' => 0.6, 'min_overlap_tokens' => 2];
    }
}

/**
 * The gate itself. Runs three independent, deterministic checks against
 * $topicText (the raw GSC query for 'gsc_article_idea', or the raw
 * missing-topic label for 'topic_gap_article') and returns every match as
 * a warning — never blocks, never throws (each sub-check is wrapped
 * separately so one failing SQL query still lets the other two run, and a
 * top-level catch guarantees an empty result rather than a fatal error no
 * matter what goes wrong).
 *
 *   A. Another pending proposal (growth_agent_jobs, job_type IN
 *      ('gsc_article_idea','topic_gap_article'), status IN
 *      ('manual_action','ready','running')) already covers a similar
 *      topic — compared against that job's OWN input_brief.query /
 *      .missing_topic (the two job types store the proposed topic under
 *      different keys, see cms_growth_agent_generate_article_idea() and
 *      cms_growth_agent_request_topic_gap_article()).
 *   B. A published article's title already covers a similar topic.
 *   C. The topic overlaps an OPEN growth_agent_content_conflicts row, or
 *      an OPEN gsc_opportunities row with recommended_action =
 *      'cannibalization_review'.
 *
 * Each warning carries enough for an operator to act without re-deriving
 * anything: which check, what it matched (type + id + a human label), and
 * the computed similarity score.
 *
 * @return array{warnings: array<int, array<string, mixed>>}
 */
function cms_growth_agent_seo_g0_gate(PDO $pdo, string $jobType, string $topicText): array
{
    $warnings = [];

    try {
        $thresholds = cms_growth_agent_g0_gate_thresholds($pdo);
        $simThreshold = (float) $thresholds['similarity_threshold'];
        $minOverlap = (int) $thresholds['min_overlap_tokens'];

        $topicTokens = cms_growth_agent_g0_tokenize($topicText);
        if ($topicTokens === []) {
            return ['warnings' => []];
        }

        $isMatch = static function (array $candidateTokens) use ($topicTokens, $simThreshold, $minOverlap): ?array {
            $overlap = cms_growth_agent_g0_overlap($topicTokens, $candidateTokens);
            if ($overlap['coefficient'] >= $simThreshold && count($overlap['intersection']) >= $minOverlap) {
                return $overlap;
            }
            return null;
        };

        // ── A. Duplicate pending proposal ───────────────────────────────
        try {
            // 'keyword_expansion_topic' (Fase B item 2, 4 Agu 2026) added to
            // this list so it's cross-deduped against the other two
            // new-article proposal types too — it stores its topic under
            // the same 'missing_topic' key as 'topic_gap_article' (see
            // cms_growth_agent_keyword_expansion_process_topics()), so the
            // existing ternary below already reads it correctly with no
            // further change needed.
            // 'auto_draft_article' (Fase F, 8 Aug 2026) added the same way —
            // cms_growth_agent_generate_auto_draft_article() also stores the
            // source headline under 'missing_topic', same reasoning.
            $pendingStmt = $pdo->query(
                "SELECT id, job_type, status, input_brief FROM growth_agent_jobs
                  WHERE job_type IN ('gsc_article_idea', 'topic_gap_article', 'keyword_expansion_topic', 'auto_draft_article')
                    AND status IN ('manual_action', 'ready', 'running')"
            );
            foreach ($pendingStmt->fetchAll() as $row) {
                $brief = json_decode((string) $row['input_brief'], true);
                if (!is_array($brief)) {
                    continue;
                }
                $candidateText = $row['job_type'] === 'gsc_article_idea'
                    ? (string) ($brief['query'] ?? '')
                    : (string) ($brief['missing_topic'] ?? '');
                if ($candidateText === '') {
                    continue;
                }
                $match = $isMatch(cms_growth_agent_g0_tokenize($candidateText));
                if ($match !== null) {
                    $warnings[] = [
                        'check' => 'duplicate_pending',
                        'similarity' => round($match['coefficient'], 2),
                        'ref_type' => 'job',
                        'ref_id' => (int) $row['id'],
                        'ref_label' => $candidateText,
                        'message' => 'Sudah ada usulan lain (job #' . (int) $row['id'] . ', status ' . $row['status']
                            . ') dengan topik serupa: "' . $candidateText . '".',
                    ];
                }
            }
        } catch (Throwable $e) {
            // Check A degrades independently — B/C still run.
        }

        // ── B. Already covered by a published article ───────────────────
        try {
            $pagesStmt = $pdo->query("SELECT page_id, title FROM pages WHERE status = 'published'");
            foreach ($pagesStmt->fetchAll() as $row) {
                $match = $isMatch(cms_growth_agent_g0_tokenize((string) $row['title']));
                if ($match !== null) {
                    $warnings[] = [
                        'check' => 'published_coverage',
                        'similarity' => round($match['coefficient'], 2),
                        'ref_type' => 'page',
                        'ref_id' => (int) $row['page_id'],
                        'ref_label' => (string) $row['title'],
                        'message' => 'Sudah ada artikel published yang mirip: "' . $row['title']
                            . '" (page_id ' . (int) $row['page_id'] . ').',
                    ];
                }
            }
        } catch (Throwable $e) {
            // Check B degrades independently.
        }

        // ── C1. Open content conflicts ───────────────────────────────────
        try {
            $conflictStmt = $pdo->query(
                "SELECT c.id, c.issue_text, a.page_id AS page_a_id, a.title AS page_a_title,
                        b.page_id AS page_b_id, b.title AS page_b_title
                   FROM growth_agent_content_conflicts c
                   JOIN pages a ON a.page_id = c.page_a_id
                   JOIN pages b ON b.page_id = c.page_b_id
                  WHERE c.status = 'open'"
            );
            foreach ($conflictStmt->fetchAll() as $row) {
                foreach (['page_a_title', 'page_b_title'] as $titleKey) {
                    $match = $isMatch(cms_growth_agent_g0_tokenize((string) $row[$titleKey]));
                    if ($match !== null) {
                        $warnings[] = [
                            'check' => 'conflict_flagged',
                            'similarity' => round($match['coefficient'], 2),
                            'ref_type' => 'conflict',
                            'ref_id' => (int) $row['id'],
                            'ref_label' => (string) $row[$titleKey],
                            'message' => 'Topik ini bersinggungan dengan content conflict #' . (int) $row['id']
                                . ' yang masih terbuka (melibatkan "' . $row[$titleKey] . '"): ' . $row['issue_text'],
                        ];
                        break; // one warning per conflict row is enough
                    }
                }
            }
        } catch (Throwable $e) {
            // Check C1 degrades independently.
        }

        // ── C2. Open cannibalization-review opportunities ────────────────
        try {
            $oppStmt = $pdo->query(
                "SELECT id, query_text FROM gsc_opportunities
                  WHERE recommended_action = 'cannibalization_review' AND status = 'open'"
            );
            foreach ($oppStmt->fetchAll() as $row) {
                $queryText = (string) ($row['query_text'] ?? '');
                if ($queryText === '') {
                    continue;
                }
                $match = $isMatch(cms_growth_agent_g0_tokenize($queryText));
                if ($match !== null) {
                    $warnings[] = [
                        'check' => 'conflict_flagged',
                        'similarity' => round($match['coefficient'], 2),
                        'ref_type' => 'opportunity',
                        'ref_id' => (int) $row['id'],
                        'ref_label' => $queryText,
                        'message' => 'Topik ini bersinggungan dengan opportunity cannibalization-review #'
                            . (int) $row['id'] . ' yang masih terbuka (query: "' . $queryText . '").',
                    ];
                }
            }
        } catch (Throwable $e) {
            // Check C2 degrades independently.
        }
    } catch (Throwable $e) {
        return ['warnings' => []];
    }

    return ['warnings' => $warnings];
}

/**
 * Article Idea — proactive collision avoidance (GROWTH_AGENT_V2_PROPOSAL.md
 * § 5, 6 Aug 2026). Finds the published articles most topically similar to
 * $topicText, reusing the exact same cms_growth_agent_g0_tokenize()/
 * cms_growth_agent_g0_overlap() the SEO-G0 Gate uses — no new tokenizer, no
 * new similarity metric. Used by cms_growth_agent_generate_article_idea()
 * to decide what (if anything) to tell the AI about already-published
 * coverage BEFORE it writes a title, unlike the gate above which only
 * checks AFTER.
 *
 * Full-table scan over `pages` + PHP-side scoring — identical approach to
 * cms_growth_agent_seo_g0_gate()'s own check B, not a smarter DB-level
 * pre-filter, for the same reason: token overlap can't be expressed as a
 * SQL WHERE clause without duplicating the tokenizer in SQL.
 *
 * Never throws — returns [] on any failure, so a broken query never blocks
 * article-idea generation, it just proceeds with no context (same as if
 * every article scored below threshold).
 *
 * @return list<array{page_id:int,title:string,meta_description:string,excerpt:string,coefficient:float}>
 *         ordered by coefficient descending, capped at $limit.
 */
function cms_growth_agent_find_similar_published_articles(PDO $pdo, string $topicText, float $minOverlap, int $limit): array
{
    try {
        $topicTokens = cms_growth_agent_g0_tokenize($topicText);
        if ($topicTokens === []) {
            return [];
        }

        $pages = $pdo->query("SELECT page_id, title, meta_description, excerpt FROM pages WHERE status = 'published'")->fetchAll();

        $scored = [];
        foreach ($pages as $page) {
            $overlap = cms_growth_agent_g0_overlap($topicTokens, cms_growth_agent_g0_tokenize((string) $page['title']));
            if ($overlap['coefficient'] >= $minOverlap) {
                $scored[] = [
                    'page_id' => (int) $page['page_id'],
                    'title' => (string) $page['title'],
                    'meta_description' => (string) ($page['meta_description'] ?? ''),
                    'excerpt' => (string) ($page['excerpt'] ?? ''),
                    'coefficient' => $overlap['coefficient'],
                ];
            }
        }

        usort($scored, static fn (array $a, array $b): int => $b['coefficient'] <=> $a['coefficient']);

        return array_slice($scored, 0, max(0, $limit));
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * ── Trending Headlines (GROWTH_AGENT_V2_PROPOSAL.md § 5, 6 Aug 2026) ──
 *
 * External sports-news headlines folded into the Article Idea prompt as
 * inspiration/context — NEVER content to copy. Legal boundary, enforced by
 * what this section physically stores: headline text + link + publish
 * time only (growth_agent_trending_headlines — see
 * cms_growth_agent_ensure_schema()). No function anywhere in this section
 * reads or persists a source's full article body; the RSS parser only
 * touches <title>/<link>/<pubDate>, and the HTML-scrape fallback only
 * touches anchor text + href, never page body content.
 *
 * Two-tier fetch per configured source (opportunity_thresholds_json.
 * trending_headlines.sources):
 *   1. RSS first — tries "{source}/rss" then "{source}/feed" via
 *      SimpleXML. Preferred: a real feed's <item> shape is stable and
 *      standard, unlike a site's HTML markup which can change on any
 *      redesign.
 *   2. Generic HTML scrape fallback (DOMDocument) — only reached if
 *      neither RSS path returns a parseable feed. Heuristic, not a
 *      guarantee: looks for anchor tags with reasonably long link text
 *      outside <nav>/<header>/<footer>, since navigation/UI links are
 *      short ("Home", "Login") while headline links are not. This WILL
 *      miss headlines or pick up noise on sites whose markup doesn't fit
 *      that shape — verified manually per source before being added to
 *      the default list (see 'sources' config's own note on which two are
 *      verified today), not something that works for "any URL" by
 *      construction.
 *
 * Both defaults (sport.detik.com, cnnindonesia.com/olahraga) were verified
 * 7 Aug 2026 to have a working "{source}/rss" feed, so in practice neither
 * exercises the scrape fallback today — it exists for sources an admin
 * adds later that don't publish RSS.
 */

/**
 * Fetches and parses ONE trending source — tries RSS first, falls back to
 * HTML scraping. Never throws (every failure path returns []) so one bad/
 * down/restructured source can never break the batch refresh that calls
 * this per-source in a loop.
 *
 * @return list<array{headline:string,url:string,published_at:?string}>
 */
function cms_growth_agent_fetch_trending_source(string $sourceUrl, int $maxHeadlines): array
{
    try {
        require_once __DIR__ . '/gsc-api.php';
        $sourceUrl = rtrim($sourceUrl, '/');

        foreach (['/rss', '/feed'] as $feedPath) {
            $rss = cms_growth_agent_parse_rss_feed($sourceUrl . $feedPath, $maxHeadlines);
            if ($rss !== []) {
                return $rss;
            }
        }

        return cms_growth_agent_scrape_headlines_generic($sourceUrl, $maxHeadlines);
    } catch (Throwable $e) {
        error_log('[cms_growth_agent_fetch_trending_source] ' . $sourceUrl . ': ' . $e->getMessage());
        return [];
    }
}

/**
 * RSS 2.0 parser via SimpleXML — chosen over a hand-rolled regex/DOM parse
 * specifically because RSS is a standard, well-formed XML format; SimpleXML
 * is more robust against the minor structural variation between different
 * sites' feeds (namespaced elements, optional fields) than string matching
 * would be. Only ever reads <title>, <link> (or <guid> as fallback — some
 * feeds' <link> is relative/empty while <guid> holds the real absolute
 * URL), and <pubDate>. Never reads <description>/<content:encoded> at
 * all — those often contain a short summary or even a snippet of body
 * HTML, and this function has no legitimate use for them (see this
 * section's top note on what's stored).
 *
 * Returns [] (not a partial/garbage result) on anything from "URL doesn't
 * exist" to "response isn't valid RSS" to "libxml choked" — the caller
 * treats an empty return as "try the next fallback", so this must never
 * throw or return junk that looks like real headlines.
 */
function cms_growth_agent_parse_rss_feed(string $feedUrl, int $maxHeadlines): array
{
    try {
        $response = cms_gsc_http_request('GET', $feedUrl, null, [], 12);
        if (!$response['ok'] || trim($response['body']) === '') {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response['body']);
        libxml_use_internal_errors($previous);

        if ($xml === false || !isset($xml->channel->item)) {
            return [];
        }

        $headlines = [];
        foreach ($xml->channel->item as $item) {
            if (count($headlines) >= $maxHeadlines) {
                break;
            }

            $title = trim((string) $item->title);
            $link = trim((string) $item->link);
            if ($link === '') {
                $link = trim((string) $item->guid);
            }
            if ($title === '' || $link === '' || !filter_var($link, FILTER_VALIDATE_URL)) {
                continue;
            }

            $pubDateRaw = trim((string) $item->pubDate);
            $pubDateTs = $pubDateRaw !== '' ? strtotime($pubDateRaw) : false;

            $headlines[] = [
                'headline' => $title,
                'url' => $link,
                'published_at' => $pubDateTs !== false ? date('Y-m-d H:i:s', $pubDateTs) : null,
            ];
        }

        return $headlines;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Generic HTML-scrape fallback — DOMDocument (same UTF-8-safe "<?xml
 * encoding=...>" prefix trick as cms_growth_agent_tsa_check_schema_markup()
 * above, not a coincidence, same trap applies here). Heuristic only:
 * collects <a> tags whose visible text is long enough to plausibly be a
 * headline (>= 25 characters — filters out nav items like "Home"/"Login"/
 * "Olahraga" without needing a per-site selector), skips any anchor whose
 * ancestor chain includes <nav>/<header>/<footer>/<aside> (typical
 * chrome/ad-rail placement), and resolves relative hrefs against the
 * source's own origin.
 *
 * No publish-time extraction here — unlike RSS's structured <pubDate>,
 * there is no generic, reliable way to find a timestamp in arbitrary HTML,
 * so published_at is always null for scraped results (the DB column is
 * nullable specifically for this).
 *
 * Never throws. This is explicitly a best-effort fallback, not a promise
 * that any given site's markup will yield clean results — see this
 * section's own top note.
 */
function cms_growth_agent_scrape_headlines_generic(string $pageUrl, int $maxHeadlines): array
{
    try {
        $response = cms_gsc_http_request('GET', $pageUrl, null, [], 12);
        if (!$response['ok'] || trim($response['body']) === '') {
            return [];
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $response['body']);
        libxml_use_internal_errors($previous);

        $parsedSource = parse_url($pageUrl);
        $origin = ($parsedSource['scheme'] ?? 'https') . '://' . ($parsedSource['host'] ?? '');

        $excludedAncestorTags = ['nav', 'header', 'footer', 'aside'];
        // Common non-article listing-page path segments across Indonesian
        // news sites generally (tag/category/topic index pages, not a
        // single headline) — a mild, generic exclusion, not per-site
        // special-casing. Verified necessary during testing: without this,
        // a site's own "Piala Dunia 2026 tag page" promo link (long text,
        // outside nav/footer) was indistinguishable from a real headline.
        $excludedPathSegments = ['/tag/', '/topic/', '/kategori/', '/category/'];
        $seen = [];
        $headlines = [];

        foreach ($dom->getElementsByTagName('a') as $anchor) {
            if (count($headlines) >= $maxHeadlines) {
                break;
            }

            // Joins each direct text/element child with a single space
            // rather than $anchor->textContent (which concatenates
            // adjacent nodes with NO separator at all) — sites commonly
            // nest a timestamp <span> directly beside the headline text
            // with no whitespace text node between them in the source
            // HTML, which produced results like "07 Agu 2026
            // 07:51Manchester United..." (a timestamp glued onto the
            // headline) before this fix.
            $text = cms_growth_agent_dom_node_text($anchor);
            if (mb_strlen($text) < 25) {
                continue;
            }

            $inExcludedAncestor = false;
            for ($ancestor = $anchor->parentNode; $ancestor !== null; $ancestor = $ancestor->parentNode) {
                if ($ancestor instanceof DOMElement && in_array(strtolower($ancestor->tagName), $excludedAncestorTags, true)) {
                    $inExcludedAncestor = true;
                    break;
                }
            }
            if ($inExcludedAncestor) {
                continue;
            }

            $href = trim((string) $anchor->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'javascript:')) {
                continue;
            }
            foreach ($excludedPathSegments as $segment) {
                if (str_contains($href, $segment)) {
                    continue 2;
                }
            }
            $absoluteUrl = str_starts_with($href, 'http') ? $href : ($origin . '/' . ltrim($href, '/'));
            if (!filter_var($absoluteUrl, FILTER_VALIDATE_URL) || isset($seen[$absoluteUrl])) {
                continue;
            }
            $seen[$absoluteUrl] = true;

            $headlines[] = ['headline' => $text, 'url' => $absoluteUrl, 'published_at' => null];
        }

        return $headlines;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Extracts an element's visible text with a space inserted BETWEEN each
 * direct child node — unlike DOMNode::$textContent, which concatenates
 * every descendant's text with no separator, so two adjacent elements with
 * no whitespace text node between them in the source HTML (e.g. a
 * timestamp <span> immediately followed by the headline text) come out
 * glued together with no boundary. Used only by
 * cms_growth_agent_scrape_headlines_generic() above.
 */
function cms_growth_agent_dom_node_text(DOMNode $node): string
{
    $parts = [];
    foreach ($node->childNodes as $child) {
        $parts[] = $child->nodeType === XML_TEXT_NODE ? $child->textContent : cms_growth_agent_dom_node_text($child);
    }

    return trim(preg_replace('/\s+/u', ' ', implode(' ', $parts)) ?? '');
}

/**
 * Fetches every configured trending source and upserts results into
 * growth_agent_trending_headlines. Per-source isolation: one source
 * throwing/timing out/returning garbage must never stop the others from
 * being fetched — matches every other multi-item scan in this file (Internal
 * Linking Agent, Technical SEO Auditor). Never throws overall either.
 *
 * Upsert-by-dedupe_hash (md5 of the URL — see that column's own comment in
 * cms_growth_agent_ensure_schema()): a source's feed/listing page is
 * re-fetched on every refresh and will naturally re-list the same recent
 * headlines, so this must refresh fetched_at on an existing row rather
 * than insert a duplicate.
 *
 * @return array{sources_ok:int,sources_failed:int,headlines_upserted:int}
 */
function cms_growth_agent_refresh_trending_headlines(PDO $pdo): array
{
    $stats = ['sources_ok' => 0, 'sources_failed' => 0, 'headlines_upserted' => 0];

    try {
        cms_growth_agent_ensure_schema($pdo);
        require_once __DIR__ . '/gsc-api.php';
        $config = cms_gsc_get_opportunity_thresholds($pdo)['trending_headlines'] ?? [];

        // Source priority (9 Aug 2026 CRITICAL fix): auto_draft_automation.
        // source_urls — the "Daftar URL sumber" textarea an operator
        // actually edits in the Full Draft Automation panel — used to be
        // written but NEVER read anywhere. This function (the ONLY real
        // scraper) always read trending_headlines.sources instead, a
        // completely separate config key nothing ever synced it with.
        // Result: an operator narrowing sources to specific sport tag
        // pages had zero effect — every draft still scraped from the old
        // sport.detik.com/cnnindonesia.com/olahraga defaults, full topic
        // range included. Operator-configured source_urls now wins
        // whenever non-empty; trending_headlines.sources is kept only as
        // the fallback for installs that never touched the newer panel.
        $opportunityThresholds = cms_gsc_get_opportunity_thresholds($pdo);
        $autoDraftSources = $opportunityThresholds['auto_draft_automation']['source_urls'] ?? [];
        $legacySources = $config['sources'] ?? [];
        $sources = is_array($autoDraftSources) && $autoDraftSources !== []
            ? array_values(array_filter($autoDraftSources, 'is_string'))
            : (is_array($legacySources) ? array_values(array_filter($legacySources, 'is_string')) : []);

        $maxPerSource = max(1, min(50, (int) ($config['max_headlines_per_source'] ?? 15)));
    } catch (Throwable $e) {
        error_log('[cms_growth_agent_refresh_trending_headlines] setup failed: ' . $e->getMessage());
        return $stats;
    }

    $upsertStmt = $pdo->prepare(
        'INSERT INTO growth_agent_trending_headlines (headline, source, url, published_at, dedupe_hash, fetched_at)
         VALUES (:headline, :source, :url, :published_at, :dedupe_hash, NOW())
         ON DUPLICATE KEY UPDATE fetched_at = NOW()'
    );

    foreach ($sources as $sourceUrl) {
        try {
            $headlines = cms_growth_agent_fetch_trending_source($sourceUrl, $maxPerSource);
            if ($headlines === []) {
                $stats['sources_failed']++;
                continue;
            }

            foreach ($headlines as $headline) {
                try {
                    $upsertStmt->execute([
                        'headline' => mb_substr($headline['headline'], 0, 500),
                        'source' => mb_substr($sourceUrl, 0, 255),
                        'url' => mb_substr($headline['url'], 0, 500),
                        'published_at' => $headline['published_at'],
                        'dedupe_hash' => md5($headline['url']),
                    ]);
                    $stats['headlines_upserted']++;
                } catch (Throwable $e) {
                    // One bad row must not stop the rest of this source's headlines.
                }
            }
            $stats['sources_ok']++;
        } catch (Throwable $e) {
            error_log('[cms_growth_agent_refresh_trending_headlines] source failed: ' . $sourceUrl . ': ' . $e->getMessage());
            $stats['sources_failed']++;
        }
    }

    return $stats;
}

/**
 * Lazy trigger for cms_growth_agent_refresh_trending_headlines() — no cron
 * guaranteed to run in this codebase, mirrors cms_gsc_fetch_if_stale()/
 * cms_growth_agent_snapshot_performance_if_stale()'s "check last-run
 * timestamp, run only if past the configured interval" pattern exactly,
 * keyed off gsc_settings.last_trending_headlines_refresh_at and
 * opportunity_thresholds_json.trending_headlines.refresh_interval_hours
 * (default 12). Called from growth-agent.php's page load. Never throws.
 */
function cms_growth_agent_refresh_trending_headlines_if_stale(PDO $pdo): void
{
    try {
        require_once __DIR__ . '/gsc-api.php';
        $settings = cms_gsc_get_settings($pdo);
        $config = cms_gsc_get_opportunity_thresholds($pdo)['trending_headlines'] ?? [];
        $maxAgeHours = max(1, (int) ($config['refresh_interval_hours'] ?? 12));

        $lastRun = $settings['last_trending_headlines_refresh_at'] ?? null;
        $isStale = $lastRun === null
            || (time() - strtotime((string) $lastRun)) >= ($maxAgeHours * 3600);

        if (!$isStale) {
            return;
        }

        cms_growth_agent_refresh_trending_headlines($pdo);

        $pdo->prepare('UPDATE gsc_settings SET last_trending_headlines_refresh_at = NOW() ORDER BY id ASC LIMIT 1')->execute();
    } catch (Throwable $e) {
        // A lazy background refresh must never break the page it's attached to.
    }
}

/**
 * Selects trending headlines fit to send into the Article Idea prompt —
 * most recent first, EXCLUDING any whose topic already overlaps a
 * published article (reuses cms_growth_agent_g0_tokenize()/
 * cms_growth_agent_g0_overlap() a third time in this file, same metric as
 * the SEO-G0 Gate and cms_growth_agent_find_similar_published_articles()
 * above — no new similarity logic). Without this filter, the prompt could
 * suggest "here's a trending story" for something Sagagoal already covered
 * days ago, which defeats the whole point of Bagian 1's collision
 * avoidance for the SAME generate call.
 *
 * Pulls a pool of the most recent stored headlines (4x the final limit, so
 * filtering out covered ones still usually leaves enough) and checks each
 * against every published article's title — same O(headlines × articles)
 * full-scan approach as cms_growth_agent_seo_g0_gate()'s own check B, at
 * the same small site scale that's already proven fine for.
 *
 * Never throws — returns [] on any failure (including "table doesn't have
 * any rows yet because the fetch hasn't run"), so the prompt just proceeds
 * without a trending-headlines section, same graceful degradation as
 * "topic is genuinely new" in the collision-avoidance context above.
 *
 * @return list<array{headline:string,url:string,source:string}>
 */
function cms_growth_agent_get_trending_headlines_for_prompt(PDO $pdo): array
{
    try {
        require_once __DIR__ . '/gsc-api.php';
        $config = cms_gsc_get_opportunity_thresholds($pdo)['trending_headlines'] ?? [];
        $overlapThreshold = (float) ($config['published_overlap_threshold'] ?? 0.5);
        $limit = max(1, min(20, (int) ($config['headlines_in_prompt'] ?? 5)));

        $headlineRows = $pdo->query(
            "SELECT headline, url, source FROM growth_agent_trending_headlines
              ORDER BY fetched_at DESC, published_at DESC
              LIMIT " . ($limit * 4)
        )->fetchAll();
        if ($headlineRows === []) {
            return [];
        }

        $publishedTitles = $pdo->query("SELECT title FROM pages WHERE status = 'published'")->fetchAll(PDO::FETCH_COLUMN, 0);
        $publishedTokenSets = array_map('cms_growth_agent_g0_tokenize', $publishedTitles);

        $selected = [];
        foreach ($headlineRows as $row) {
            if (count($selected) >= $limit) {
                break;
            }

            $headlineTokens = cms_growth_agent_g0_tokenize((string) $row['headline']);
            if ($headlineTokens === []) {
                continue;
            }

            $coveredByPublished = false;
            foreach ($publishedTokenSets as $pubTokens) {
                $overlap = cms_growth_agent_g0_overlap($headlineTokens, $pubTokens);
                if ($overlap['coefficient'] >= $overlapThreshold) {
                    $coveredByPublished = true;
                    break;
                }
            }
            if ($coveredByPublished) {
                continue;
            }

            $selected[] = [
                'headline' => (string) $row['headline'],
                'url' => (string) $row['url'],
                'source' => (string) $row['source'],
            ];
        }

        return $selected;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * "Aturan keras" — checks the AI-generated title against the SOURCE
 * HEADLINES actually shown to it in this same prompt (user explicit
 * requirement, GROWTH_AGENT_V2_PROPOSAL.md § 5: a near-duplicate of a
 * source headline is a copyright/originality risk in its own right, not
 * just copying the article body would be). Fourth use of
 * cms_growth_agent_g0_tokenize()/cms_growth_agent_g0_overlap() in this
 * file — still no new similarity metric.
 *
 * Deliberately compares against ONLY $headlines (the specific ones this
 * job's prompt contained), not the whole growth_agent_trending_headlines
 * table — a title can only "copy" what it was actually shown.
 *
 * Same "advisory, never blocking" posture as cms_growth_agent_seo_g0_gate()
 * (the devs brief explicitly says "mirip pola SEO-G0 Gate") — the job is
 * still created either way; this only decides whether it carries a
 * visible flag for the operator, mirrored in growth-agent.php's Job
 * Terbaru row the same way _g0_warnings already is. Never throws.
 *
 * @param list<array{headline:string,url:string,source:string}> $headlines
 * @return array{flagged:bool,matches:array<int,array{headline:string,url:string,coefficient:float}>}
 */
function cms_growth_agent_check_title_vs_headlines(PDO $pdo, string $title, array $headlines): array
{
    try {
        if ($headlines === []) {
            return ['flagged' => false, 'matches' => []];
        }

        require_once __DIR__ . '/gsc-api.php';
        $threshold = (float) (cms_gsc_get_opportunity_thresholds($pdo)['trending_headlines']['title_vs_headline_max_overlap'] ?? 0.75);

        $titleTokens = cms_growth_agent_g0_tokenize($title);
        if ($titleTokens === []) {
            return ['flagged' => false, 'matches' => []];
        }

        $matches = [];
        foreach ($headlines as $headline) {
            $overlap = cms_growth_agent_g0_overlap($titleTokens, cms_growth_agent_g0_tokenize($headline['headline']));
            if ($overlap['coefficient'] >= $threshold) {
                $matches[] = [
                    'headline' => $headline['headline'],
                    'url' => $headline['url'],
                    'coefficient' => round($overlap['coefficient'], 2),
                ];
            }
        }

        return ['flagged' => $matches !== [], 'matches' => $matches];
    } catch (Throwable $e) {
        return ['flagged' => false, 'matches' => []];
    }
}

/**
 * ── Internal Linking Agent (GROWTH_AGENT_V2_PROPOSAL.md Fase B item 1,
 *    4 Agu 2026) ──
 *
 * Scans published articles for pairs (A -> B) that are topically related
 * (reusing the SEO-G0 Gate's own tokenizer/overlap metric —
 * cms_growth_agent_g0_tokenize()/cms_growth_agent_g0_overlap() — same
 * reasoning applies: needs Indonesian stopword + site-generic-term
 * filtering or nearly every article pair would register as "related")
 * where A's content doesn't yet link to B, and proposes adding one link.
 *
 * Detection is 100% deterministic (plain token-overlap + DOM text search),
 * same "must be consistent/auditable, no AI, no per-run cost" reasoning as
 * the Opportunity Engine and the SEO-G0 Gate.
 *
 * Logs one job_type='internal_link_suggestion' row per proposed pair,
 * status='manual_action' — same Action Queue as everything else (§ 1b).
 * The scan itself NEVER touches `pages.content`; only approving the
 * resulting job on internal-link-review.php does, and even then only
 * after re-deriving the insertion fresh against the article's CURRENT
 * content (not a stale scan-time snapshot) and taking a full snapshot of
 * the old content into that same job's output_json first — this CMS has
 * no article revision history at all, so that snapshot is the only way
 * back if a link insertion goes wrong.
 */

/**
 * Reads similarity_threshold/min_overlap_tokens/max_suggestions_per_article/
 * articles_scanned_per_run, nested under opportunity_thresholds_json's
 * 'internal_linking' key (see cms_gsc_default_opportunity_thresholds() in
 * gsc-api.php) — same array_replace_recursive-over-defaults pattern as
 * cms_growth_agent_g0_gate_thresholds(). Never throws.
 *
 * @return array{similarity_threshold: float, min_overlap_tokens: int, max_suggestions_per_article: int, articles_scanned_per_run: int}
 */
function cms_growth_agent_il_thresholds(PDO $pdo): array
{
    $fallback = [
        'similarity_threshold' => 0.5, 'min_overlap_tokens' => 2,
        'max_suggestions_per_article' => 3, 'articles_scanned_per_run' => 10,
        'single_word_max_df_ratio' => 0.2, 'min_corpus_size_for_single_word' => 10,
    ];
    try {
        require_once __DIR__ . '/gsc-api.php';
        $defaults = cms_gsc_default_opportunity_thresholds()['internal_linking'] ?? $fallback;
        $configured = cms_gsc_get_opportunity_thresholds($pdo)['internal_linking'] ?? [];

        return array_replace_recursive($defaults, is_array($configured) ? $configured : []);
    } catch (Throwable $e) {
        return $fallback;
    }
}

/**
 * Corpus-wide token document-frequency, computed across every published
 * article's title + plain-text content — this is the structural fix (not
 * the stopword list) for single-word anchors like "paling" slipping
 * through: a word that appears in a large fraction of ALL published
 * articles is generic BY DEFINITION regardless of what the word actually
 * is, and this self-adjusts as the site's article corpus grows, unlike a
 * fixed manual list that can never enumerate every generic Indonesian
 * adverb/connector in advance. See cms_growth_agent_il_candidate_phrases()
 * for how this is used (gated behind a minimum corpus size — see that
 * function's own note on why).
 *
 * Never throws. Computed fresh per scan/apply call (not cached/persisted —
 * no new table, and cheap at this site's current article volume; revisit
 * if the corpus grows into the thousands).
 *
 * @return array{size: int, df: array<string, int>}
 */
function cms_growth_agent_il_corpus_stats(PDO $pdo): array
{
    try {
        $rows = $pdo->query("SELECT title, content FROM pages WHERE status = 'published'")->fetchAll();
    } catch (Throwable $e) {
        return ['size' => 0, 'df' => []];
    }

    $df = [];
    foreach ($rows as $row) {
        try {
            $plainText = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $row['content'])) ?? '');
            $tokens = array_unique(array_merge(
                cms_growth_agent_g0_tokenize($plainText),
                cms_growth_agent_g0_tokenize((string) $row['title'])
            ));
        } catch (Throwable $e) {
            continue;
        }
        foreach ($tokens as $token) {
            $df[$token] = ($df[$token] ?? 0) + 1;
        }
    }

    return ['size' => count($rows), 'df' => $df];
}

/**
 * Whether $word shows up ANYWHERE in $sourcePlainText capitalized AND in
 * the middle of a sentence — a much stronger proper-noun signal than
 * capitalization in a title, since most article titles on this site are
 * Title Case (every word capitalized), so title casing alone says nothing
 * about whether a specific word is actually a proper noun. A word
 * appearing capitalized mid-sentence in real body prose (where only
 * proper nouns and sentence-starts are normally capitalized in Indonesian)
 * is a real, independent signal.
 *
 * "Mid-sentence" here means: the nearest non-whitespace character before
 * the match, after trimming trailing whitespace, exists and is not a
 * sentence-terminating '.', '!', or '?' — i.e. not the first word of the
 * text and not the first word after a previous sentence ended.
 *
 * Never throws.
 */
function cms_growth_agent_il_is_proper_noun_candidate(string $word, string $sourcePlainText): bool
{
    $word = trim($word);
    if ($word === '' || $sourcePlainText === '') {
        return false;
    }

    try {
        $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($word, '/') . '(?![\p{L}\p{N}])/u';
        if (!preg_match_all($pattern, $sourcePlainText, $matches, PREG_OFFSET_CAPTURE)) {
            return false;
        }

        foreach ($matches[0] as [$matchText, $offset]) {
            if ($matchText === '' || preg_match('/^\p{Lu}/u', $matchText) !== 1) {
                continue; // this particular occurrence isn't capitalized
            }
            $before = rtrim(substr($sourcePlainText, 0, (int) $offset));
            if ($before === '') {
                continue; // very first word of the text — not a signal
            }
            $prevChar = mb_substr($before, -1);
            if (in_array($prevChar, ['.', '!', '?'], true)) {
                continue; // first word of a new sentence — not a signal
            }
            return true; // genuine mid-sentence capitalized occurrence
        }
        return false;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Generates candidate anchor phrases from a target article's title, longest
 * first (up to 6 words) — every multi-word candidate is guaranteed to
 * contain at least 2 non-stopword/non-generic tokens (reuses
 * cms_growth_agent_g0_tokenize() as the filter, trimmed off both edges
 * first), so a phrase built mostly of filler words is never proposed.
 * Longest-first means the most specific/natural phrase wins if multiple
 * candidates would match — "Piala Dunia 2026" over just "Piala".
 *
 * Single-word candidates are the historically riskier case (a real
 * production incident: the bare intensifier "paling" was proposed and
 * briefly applied as an anchor — see this file's top note on the Internal
 * Linking Agent). A single word is only ever offered as a candidate if it
 * passes BOTH:
 *   1. Corpus document frequency at or below 'single_word_max_df_ratio' —
 *      see cms_growth_agent_il_corpus_stats(). Skipped entirely (no
 *      single-word candidates at all) when the corpus is smaller than
 *      'min_corpus_size_for_single_word', since document-frequency ratios
 *      from a handful of articles are too noisy to trust.
 *   2. Mid-sentence capitalized evidence in the SOURCE article's own body
 *      text — see cms_growth_agent_il_is_proper_noun_candidate().
 *
 * @param array{size: int, df: array<string, int>} $corpusStats
 * @param array<string, mixed> $thresholds
 * @return string[] ordered longest (most words) first
 */
function cms_growth_agent_il_candidate_phrases(string $title, array $corpusStats, string $sourcePlainText, array $thresholds): array
{
    $words = preg_split('/\s+/u', trim($title)) ?: [];
    $words = array_values(array_filter($words, static fn ($w): bool => $w !== ''));
    $n = count($words);
    if ($n === 0) {
        return [];
    }

    // A window can contain a meaningful word in the middle but a stopword
    // at either edge (e.g. a 4-word window ending in "di" or "trofi") —
    // that reads as an awkward, ungrammatical anchor even though the
    // phrase as a whole "has a meaningful token". Trim stopword words off
    // both ends before accepting a candidate, so anchors always start and
    // end on a real word.
    $isStopword = static fn (string $word): bool => cms_growth_agent_g0_tokenize($word) === [];
    // Titles carry their own punctuation attached to words ("2026,",
    // "Dimulai!", "Leste:") — trimming only whole stopword words off the
    // edges still leaves an edge word like "2026," dangling into the
    // anchor as literal punctuation once matched against source text that
    // also happens to have a comma there (a real anchor this produced:
    // "Piala Dunia 2026," with a trailing comma). Strip leading/trailing
    // punctuation from the edge words themselves, on top of the stopword
    // trim, so an anchor never starts or ends on stray punctuation.
    $stripEdgePunct = static fn (string $word): string => trim($word, "\"'“”‘’()[]{}«»,.;:!?…-");

    $phrases = [];
    $seen = [];
    $maxWords = min($n, 6);
    for ($len = $maxWords; $len >= 2; $len--) {
        for ($start = 0; $start + $len <= $n; $start++) {
            $slice = array_slice($words, $start, $len);
            while ($slice !== []) {
                $clean = $stripEdgePunct($slice[0]);
                if ($clean === '' || $isStopword($clean)) {
                    array_shift($slice);
                    continue;
                }
                $slice[0] = $clean;
                break;
            }
            while ($slice !== []) {
                $lastIdx = count($slice) - 1;
                $clean = $stripEdgePunct($slice[$lastIdx]);
                if ($clean === '' || $isStopword($clean)) {
                    array_pop($slice);
                    continue;
                }
                $slice[$lastIdx] = $clean;
                break;
            }
            if (count($slice) < 2) {
                continue;
            }
            $phrase = implode(' ', $slice);
            // Requires >=2 MEANINGFUL tokens, not just >=2 words — a
            // 2-word phrase like "yang penting" (if "yang" survived
            // mid-phrase rather than at an edge) still only carries one
            // real topical token and reads as vague, not identifying.
            if (isset($seen[$phrase]) || count(cms_growth_agent_g0_tokenize($phrase)) < 2) {
                continue;
            }
            $seen[$phrase] = true;
            $phrases[] = $phrase;
        }
    }

    $corpusSize = (int) ($corpusStats['size'] ?? 0);
    $minCorpusSize = (int) ($thresholds['min_corpus_size_for_single_word'] ?? 10);
    $maxDfRatio = (float) ($thresholds['single_word_max_df_ratio'] ?? 0.2);
    if ($corpusSize >= $minCorpusSize) {
        foreach ($words as $rawWord) {
            $word = $stripEdgePunct($rawWord);
            if (mb_strlen($word) < 5 || $isStopword($word) || isset($seen[$word])) {
                continue;
            }
            $tokenized = cms_growth_agent_g0_tokenize($word);
            if ($tokenized === []) {
                continue;
            }
            $df = (int) ($corpusStats['df'][$tokenized[0]] ?? 0);
            if (($df / $corpusSize) > $maxDfRatio) {
                continue; // too generic across the corpus — the "paling" case
            }
            if (!cms_growth_agent_il_is_proper_noun_candidate($word, $sourcePlainText)) {
                continue; // no independent evidence this reads as a proper noun
            }
            $seen[$word] = true;
            $phrases[] = $word;
        }
    }

    // Stopword/punctuation trimming means how many REAL words survive a
    // window varies unpredictably by starting position — a 6-word window
    // that trims down to 3 words can end up earlier in $phrases than a
    // different 6-word window (a few positions later) that trims down to
    // 4, simply because it was generated first. Sort by actual surviving
    // word count (descending) so "longest first" is genuinely true of the
    // final list, not just of the raw windows that produced it — usort()
    // is stable since PHP 8.0, so candidates with equal word counts keep
    // their original relative order. Single-word candidates (0 spaces)
    // naturally sort last without special-casing.
    usort($phrases, static fn (string $a, string $b): int => substr_count($b, ' ') <=> substr_count($a, ' '));

    return $phrases;
}

/**
 * Whether $html already contains a link to the article at $targetSlug —
 * checked via DOMDocument (not a raw string search) so an href that merely
 * happens to contain the slug as a text coincidence elsewhere doesn't
 * false-positive... though in practice this is a straightforward
 * substring check against real <a href> values, which is safe precisely
 * because DOMDocument guarantees we're only ever looking at genuine href
 * attribute values, never arbitrary text. Never throws — a parse failure
 * is treated as "not linked" (the insertion step re-parses the same HTML
 * anyway and will itself abort safely if the HTML can't be trusted).
 */
function cms_growth_agent_il_already_linked(string $html, string $targetSlug): bool
{
    if (trim($html) === '' || $targetSlug === '') {
        return false;
    }
    try {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8"><div id="wpm-il-check">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        if (!$loaded) {
            return false;
        }
        $needle = 'artikel/' . rawurlencode($targetSlug);
        $xpath = new DOMXPath($dom);
        foreach ($xpath->query('//a[@href]') as $anchor) {
            $href = $anchor->getAttribute('href');
            if ($href !== '' && (str_contains($href, $needle) || str_contains($href, $targetSlug))) {
                return true;
            }
        }
        return false;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Re-parses $newHtml and confirms it's safe to persist: parses without a
 * FATAL libxml error, has exactly $expectedAnchorCount total <a> tags (the
 * original count + 1 — never more, never fewer), and has zero nested
 * anchors (<a> inside another <a>). Belt-and-suspenders on top of
 * cms_growth_agent_il_insert_link()'s own careful DOM surgery — if this
 * returns false, the caller must discard the result entirely rather than
 * save it, per the "abort rather than risk a broken article" rule.
 */
function cms_growth_agent_il_verify_safe(string $newHtml, int $expectedAnchorCount): bool
{
    try {
        libxml_use_internal_errors(true);
        $check = new DOMDocument('1.0', 'UTF-8');
        $loaded = $check->loadHTML(
            '<?xml encoding="UTF-8"><div id="wpm-il-verify">' . $newHtml . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        $errors = libxml_get_errors();
        libxml_clear_errors();
        if (!$loaded) {
            return false;
        }
        foreach ($errors as $error) {
            if ($error->level === LIBXML_ERR_FATAL) {
                return false;
            }
        }
        $xpath = new DOMXPath($check);
        if ($xpath->query('//a//a')->length > 0) {
            return false;
        }
        return $xpath->query('//a')->length === $expectedAnchorCount;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * The DOM-safe link insertion itself — the core of this whole feature's
 * safety story. Never uses str_replace()/raw regex-on-HTML (which could
 * insert into an attribute value, inside <script>/<style>, or nest inside
 * an existing <a>). Instead:
 *
 *   1. Parses $html with DOMDocument (UTF-8 explicitly declared via the
 *      "<?xml encoding=...>" prefix trick — WITHOUT this, DOMDocument
 *      silently mis-decodes UTF-8 as Latin-1 and mangles every non-ASCII
 *      character, the classic PHP DOMDocument/UTF-8 trap).
 *   2. Selects ONLY text() nodes that are NOT already inside <a>, <script>,
 *      or <style> (an XPath ancestor:: check — attribute VALUES are never
 *      even visible to this query, since DOMAttr nodes aren't text()
 *      nodes, so "inside an attribute" is structurally impossible to hit
 *      here at all, not just filtered out).
 *   3. Tries each candidate anchor phrase (longest first, see
 *      cms_growth_agent_il_candidate_phrases()), and within a phrase,
 *      each eligible text node in document order — stops at the FIRST
 *      whole-phrase (word-boundary-safe, Unicode-aware) match found,
 *      never inserts more than once.
 *   4. Splits that one text node into before/anchor/after DOM nodes
 *      (byte-offset split from PREG_OFFSET_CAPTURE with the 'u' modifier
 *      is safe here — those offsets always land on UTF-8 character
 *      boundaries) and inserts a real <a> element via createElement/
 *      createTextNode, which handles HTML-entity escaping correctly on
 *      its own.
 *   5. Re-serializes ONLY the wrapper's children (never the wrapper
 *      itself) via saveHTML(), then hands the result to
 *      cms_growth_agent_il_verify_safe() as a final safety re-check.
 *
 * Returns null (never partially applies) if: the HTML can't be parsed
 * safely, no candidate phrase has any safe occurrence, or the post-
 * insertion safety re-check fails for any reason.
 *
 * @return array{html: string, anchor_text: string, context: string}|null
 */
function cms_growth_agent_il_insert_link(string $html, string $targetTitle, string $targetHref, array $corpusStats, array $thresholds): ?array
{
    if (trim($html) === '' || trim($targetTitle) === '' || trim($targetHref) === '') {
        return null;
    }

    $sourcePlainText = trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '');
    $phrases = cms_growth_agent_il_candidate_phrases($targetTitle, $corpusStats, $sourcePlainText, $thresholds);
    if ($phrases === []) {
        return null;
    }

    try {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8"><div id="wpm-il-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        $parseErrors = libxml_get_errors();
        libxml_clear_errors();
        if (!$loaded) {
            return null;
        }
        foreach ($parseErrors as $error) {
            if ($error->level === LIBXML_ERR_FATAL) {
                return null; // source HTML too broken to safely round-trip
            }
        }

        $root = $dom->getElementById('wpm-il-root');
        if ($root === null) {
            return null;
        }

        $xpath = new DOMXPath($dom);
        $originalAnchorCount = $xpath->query('//a')->length;

        foreach ($phrases as $phrase) {
            $pattern = '/(?<![\p{L}\p{N}])(' . preg_quote($phrase, '/') . ')(?![\p{L}\p{N}])/ui';
            $textNodes = $xpath->query('.//text()[not(ancestor::a) and not(ancestor::script) and not(ancestor::style)]', $root);

            foreach ($textNodes as $node) {
                $nodeText = $node->nodeValue;
                if ($nodeText === null || trim($nodeText) === '') {
                    continue;
                }
                if (!preg_match($pattern, $nodeText, $m, PREG_OFFSET_CAPTURE)) {
                    continue;
                }

                $matchText = $m[1][0];
                $offset = $m[1][1];
                $before = substr($nodeText, 0, $offset);
                $after = substr($nodeText, $offset + strlen($matchText));

                $parent = $node->parentNode;
                if ($parent === null) {
                    continue;
                }

                $anchor = $dom->createElement('a');
                $anchor->setAttribute('href', $targetHref);
                $anchor->appendChild($dom->createTextNode($matchText));

                if ($before !== '') {
                    $parent->insertBefore($dom->createTextNode($before), $node);
                }
                $parent->insertBefore($anchor, $node);
                if ($after !== '') {
                    $parent->insertBefore($dom->createTextNode($after), $node);
                }
                $parent->removeChild($node);

                $context = trim(preg_replace('/\s+/u', ' ', (string) $parent->textContent) ?? '');
                if (mb_strlen($context) > 220) {
                    $pos = mb_stripos($context, $matchText);
                    $start = max(0, ($pos === false ? 0 : $pos) - 80);
                    $context = ($start > 0 ? '…' : '') . mb_substr($context, $start, 220) . '…';
                }

                $newHtml = '';
                foreach ($root->childNodes as $child) {
                    $newHtml .= $dom->saveHTML($child);
                }

                if (!cms_growth_agent_il_verify_safe($newHtml, $originalAnchorCount + 1)) {
                    return null; // abort entirely rather than risk a broken save
                }

                return ['html' => $newHtml, 'anchor_text' => $matchText, 'context' => $context];
            }
        }

        return null; // no safe occurrence found for any candidate phrase
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * The scan itself — triggered manually (button on growth-agent.php, see
 * that page's own comment on why it lives there and not
 * seo-intelligence.php). For up to $articlesLimit published articles
 * (least-recently-updated first, same convention as
 * cms_growth_agent_scan_seo_recommendations()), compares against every
 * OTHER published article by topic-token overlap, and for each relevant,
 * not-yet-linked, not-yet-proposed pair where a safe anchor insertion
 * point actually exists, logs one manual_action job. Caps proposals per
 * source article (max_suggestions_per_article) so one heavily-connected
 * article doesn't dominate a scan with a wall of suggestions.
 *
 * Never modifies `pages.content` — that only happens in
 * cms_growth_agent_apply_internal_link(), on explicit operator approval.
 * Never throws.
 *
 * @return array{scanned: int, created: int, errors: int}
 */
function cms_growth_agent_scan_internal_links(PDO $pdo): array
{
    $stats = ['scanned' => 0, 'created' => 0, 'errors' => 0, 'auto_applied' => 0];

    try {
        cms_growth_agent_ensure_schema($pdo);
        $thresholds = cms_growth_agent_il_thresholds($pdo);
        $simThreshold = (float) $thresholds['similarity_threshold'];
        $minOverlap = (int) $thresholds['min_overlap_tokens'];
        $maxPerArticle = max(1, (int) $thresholds['max_suggestions_per_article']);
        $articlesLimit = max(1, min(30, (int) $thresholds['articles_scanned_per_run']));

        $allPages = $pdo->query("SELECT page_id, title, slug, content FROM pages WHERE status = 'published'")->fetchAll();
        if (count($allPages) < 2) {
            return $stats;
        }

        // Computed once per scan (not per candidate pair) — corpus size at
        // this site's current volume makes recomputing per-pair wasteful.
        $corpusStats = cms_growth_agent_il_corpus_stats($pdo);

        $sourceStmt = $pdo->prepare(
            "SELECT page_id, title, slug, content FROM pages WHERE status = 'published' ORDER BY updated_at ASC LIMIT " . $articlesLimit
        );
        $sourceStmt->execute();
        $sources = $sourceStmt->fetchAll();

        // Existing pending/applied pairs — decoded in PHP rather than a
        // JSON_EXTRACT() SQL condition, same convention as the SEO-G0
        // Gate's duplicate-pending check (this codebase's established
        // pattern for scanning growth_agent_jobs.input_brief).
        $existingPairs = [];
        $jobRows = $pdo->query(
            "SELECT input_brief FROM growth_agent_jobs
              WHERE job_type = 'internal_link_suggestion' AND status IN ('manual_action', 'succeeded')"
        )->fetchAll();
        foreach ($jobRows as $row) {
            $brief = json_decode((string) $row['input_brief'], true);
            if (is_array($brief) && isset($brief['source_page_id'], $brief['target_page_id'])) {
                $existingPairs[$brief['source_page_id'] . ':' . $brief['target_page_id']] = true;
            }
        }
    } catch (Throwable $e) {
        return $stats;
    }

    foreach ($sources as $source) {
        $stats['scanned']++;
        $sourceId = (int) $source['page_id'];

        try {
            $plainText = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $source['content'])) ?? '');
            $sourceTokens = cms_growth_agent_g0_tokenize($plainText);
        } catch (Throwable $e) {
            continue;
        }
        if ($sourceTokens === []) {
            continue;
        }

        $suggestionsForThisArticle = 0;
        foreach ($allPages as $target) {
            if ($suggestionsForThisArticle >= $maxPerArticle) {
                break;
            }
            $targetId = (int) $target['page_id'];
            if ($targetId === $sourceId) {
                continue;
            }
            if (isset($existingPairs[$sourceId . ':' . $targetId])) {
                continue;
            }

            try {
                $targetTokens = cms_growth_agent_g0_tokenize((string) $target['title']);
                if ($targetTokens === []) {
                    continue;
                }
                $overlap = cms_growth_agent_g0_overlap($targetTokens, $sourceTokens);
                if ($overlap['coefficient'] < $simThreshold || count($overlap['intersection']) < $minOverlap) {
                    continue;
                }

                if (cms_growth_agent_il_already_linked((string) $source['content'], (string) $target['slug'])) {
                    continue;
                }

                $targetHref = 'artikel/' . rawurlencode((string) $target['slug']);
                $insertResult = cms_growth_agent_il_insert_link((string) $source['content'], (string) $target['title'], $targetHref, $corpusStats, $thresholds);
                if ($insertResult === null) {
                    continue; // no safe anchor point — skip, do not force it
                }

                $inputBrief = [
                    'source_page_id' => $sourceId,
                    'source_title' => (string) $source['title'],
                    'target_page_id' => $targetId,
                    'target_title' => (string) $target['title'],
                    'target_slug' => (string) $target['slug'],
                    'anchor_text' => $insertResult['anchor_text'],
                    'context' => $insertResult['context'],
                    'similarity' => round($overlap['coefficient'], 2),
                ];

                $jobId = cms_growth_agent_log_job(
                    $pdo, 'internal_link_suggestion', 'growth_agent', $sourceId, 'manual_action',
                    $inputBrief, null, null, null, null, null, '', 'medium'
                );
                if ($jobId > 0) {
                    $stats['created']++;
                    $suggestionsForThisArticle++;
                    $existingPairs[$sourceId . ':' . $targetId] = true;

                    // Autonomous Mode (Fase E) — no-op unless an operator
                    // has explicitly turned it on for this job_type; see
                    // that function's own docblock. Deliberately checked
                    // per-job, right after creation, not as a separate
                    // batch pass afterward — keeps the rate-limit count
                    // (which this call itself consumes from) accurate
                    // between successive suggestions in the same scan run.
                    $autoResult = cms_growth_agent_autonomous_maybe_apply_internal_link($pdo, $jobId);
                    if ($autoResult['applied']) {
                        $stats['auto_applied']++;
                    }
                } else {
                    $stats['errors']++;
                }
            } catch (Throwable $e) {
                $stats['errors']++;
            }
        }
    }

    return $stats;
}

/**
 * Approve half of the Internal Linking Agent flow — the ONLY place
 * `pages.content` is ever written by this feature, called exclusively
 * from internal-link-review.php's "Apply" action (never generic
 * Approve/Reject: writing to `pages` needs the dedicated snapshot-first
 * handling below, same reasoning as why 'seo_recommendation' has its own
 * review page instead of the generic buttons).
 *
 * Re-derives the insertion fresh against the article's CURRENT content
 * (never trusts the scan-time input_brief.context as still accurate — the
 * article may have been edited since) via
 * cms_growth_agent_il_insert_link(), and if (and only if) that succeeds:
 * snapshots the OLD content in full into this job's own output_json
 * (mandatory — see this file's own top note on there being no revision
 * history at all), then overwrites `pages.content` (and ONLY content —
 * `pages.status` is never touched, published stays published).
 *
 * Never throws. Returns ['ok' => bool, 'error' => string].
 */
function cms_growth_agent_apply_internal_link(PDO $pdo, int $jobId): array
{
    try {
        $jobStmt = $pdo->prepare(
            "SELECT id, status, page_id, input_brief FROM growth_agent_jobs
              WHERE id = :id AND job_type = 'internal_link_suggestion' LIMIT 1"
        );
        $jobStmt->execute(['id' => $jobId]);
        $job = $jobStmt->fetch();
        if (!$job) {
            return ['ok' => false, 'error' => 'Job usulan link tidak ditemukan.'];
        }
        if ($job['status'] !== 'manual_action') {
            return ['ok' => false, 'error' => 'Usulan ini sudah pernah diproses sebelumnya.'];
        }

        $brief = json_decode((string) $job['input_brief'], true);
        if (!is_array($brief)) {
            return ['ok' => false, 'error' => 'Data usulan (input_brief) rusak.'];
        }
        $sourceId = (int) ($brief['source_page_id'] ?? 0);
        $targetSlug = trim((string) ($brief['target_slug'] ?? ''));
        $targetTitle = trim((string) ($brief['target_title'] ?? ''));
        if ($sourceId <= 0 || $targetSlug === '' || $targetTitle === '') {
            return ['ok' => false, 'error' => 'Data usulan tidak lengkap.'];
        }

        $pageStmt = $pdo->prepare('SELECT page_id, content FROM pages WHERE page_id = :id LIMIT 1');
        $pageStmt->execute(['id' => $sourceId]);
        $page = $pageStmt->fetch();
        if (!$page) {
            return ['ok' => false, 'error' => 'Artikel sumber tidak ditemukan — mungkin sudah dihapus.'];
        }

        $currentContent = (string) $page['content'];
        if (cms_growth_agent_il_already_linked($currentContent, $targetSlug)) {
            return ['ok' => false, 'error' => 'Artikel ini sudah punya link ke artikel tujuan — tidak ada perubahan yang diterapkan.'];
        }

        $targetHref = 'artikel/' . rawurlencode($targetSlug);
        $corpusStats = cms_growth_agent_il_corpus_stats($pdo);
        $thresholds = cms_growth_agent_il_thresholds($pdo);
        $result = cms_growth_agent_il_insert_link($currentContent, $targetTitle, $targetHref, $corpusStats, $thresholds);
        if ($result === null) {
            return ['ok' => false, 'error' => 'Tidak ditemukan tempat penyisipan yang aman di konten SAAT INI — kemungkinan artikel sudah diedit sejak usulan ini dibuat. Tidak ada perubahan diterapkan.'];
        }

        $currentAdminId = (int) ($_SESSION['cms_admin_id'] ?? 0) ?: null;

        $pdo->beginTransaction();
        try {
            // Mandatory content snapshot — see this section's own top note:
            // this CMS has no revision history, so this is the only way an
            // operator can recover the previous wording if this insertion
            // turns out to be wrong after the fact.
            $snapshot = [
                'page_id' => $sourceId,
                'previous_content' => $currentContent,
                'previous_content_length' => mb_strlen($currentContent),
                'applied_at' => date(DATE_ATOM),
                'anchor_text' => $result['anchor_text'],
                'target_page_id' => (int) ($brief['target_page_id'] ?? 0),
                'target_href' => $targetHref,
            ];

            $pdo->prepare('UPDATE pages SET content = :content, updated_at = NOW() WHERE page_id = :id')
                ->execute(['content' => $result['html'], 'id' => $sourceId]);

            $pdo->prepare(
                'INSERT INTO growth_agent_feedback (job_id, action, reviewed_by, created_at) VALUES (:job_id, :action, :reviewed_by, NOW())'
            )->execute(['job_id' => $jobId, 'action' => 'approved_as_is', 'reviewed_by' => $currentAdminId]);

            $pdo->prepare(
                "UPDATE growth_agent_jobs SET status = 'succeeded', output_json = :output, updated_at = NOW() WHERE id = :id"
            )->execute(['output' => json_encode($snapshot, JSON_UNESCAPED_UNICODE), 'id' => $jobId]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return ['ok' => true, 'error' => ''];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Autonomous Mode (GROWTH_AGENT_V2_PROPOSAL.md § Fase E, 6 Aug 2026) — the
 * ONLY entry point that auto-applies an internal_link_suggestion job
 * without a human clicking "Apply". Called once, right after a brand-new
 * job is created, from cms_growth_agent_scan_internal_links()'s own loop —
 * this feature has no lazy/cron trigger of its own, it rides the same
 * "Scan Internal Linking" click that already creates the job.
 *
 * Gates, in order (first failure short-circuits, job is left exactly as
 * cms_growth_agent_scan_internal_links() created it — status='manual_action',
 * unchanged — so it just falls back to the normal human-review queue):
 *   1. opportunity_thresholds_json.autonomous_mode.enabled === true (the
 *      single kill switch — see cms_gsc_default_opportunity_thresholds()).
 *      Ships false. DO NOT flip true as part of any deploy — see that
 *      config block's own note on why (no before/after evidence yet).
 *   2. ...job_types['internal_link_suggestion'] === true — strict
 *      identity check, not a truthy check: a job_type simply absent from
 *      the array (e.g. some future job_type nobody added yet) must never
 *      be treated as enabled.
 *   3. Weekly rate limit (weekly_limit, default 3) — counts
 *      growth_agent_feedback rows with action='auto_applied' created in
 *      the trailing 7 days. Reaching the limit is not an error, just a
 *      normal "wait for next week" outcome — the job stays manual_action
 *      for a human to pick up in the meantime.
 *
 * If all three pass: calls cms_growth_agent_apply_internal_link() —
 * literally the exact same function internal-link-review.php's manual
 * "Apply" button calls, completely unmodified, so every guard already in
 * there (content-changed-since-scan check, transaction, previous_content
 * snapshot) applies identically here. The only difference from a manual
 * Apply is what happens AFTER it succeeds: the 'approved_as_is' feedback
 * row that function always inserts gets retagged to 'auto_applied' (one
 * UPDATE, not a duplicate INSERT — see cms_growth_agent_ensure_autonomous_schema()
 * for why that ENUM value exists) so this event is distinguishable from a
 * human-approved one for rate-limit counting and the Revert UI, and a
 * best-effort notification fires (cms_growth_agent_autonomous_notify()).
 *
 * Never throws — a failure anywhere in this gate must not break the scan
 * it's attached to; the job simply stays manual_action, same as if
 * autonomous mode didn't exist at all.
 *
 * @return array{applied:bool,reason:string}
 */
function cms_growth_agent_autonomous_maybe_apply_internal_link(PDO $pdo, int $jobId): array
{
    try {
        require_once __DIR__ . '/gsc-api.php';
        $config = cms_gsc_get_opportunity_thresholds($pdo)['autonomous_mode'] ?? [];

        if (($config['enabled'] ?? false) !== true) {
            return ['applied' => false, 'reason' => 'autonomous_mode.enabled is not true'];
        }
        if ((($config['job_types'] ?? [])['internal_link_suggestion'] ?? false) !== true) {
            return ['applied' => false, 'reason' => 'internal_link_suggestion not in job_types allowlist'];
        }

        $weeklyLimit = max(0, (int) ($config['weekly_limit'] ?? 3));
        $usedThisWeek = (int) $pdo->query(
            "SELECT COUNT(*) FROM growth_agent_feedback WHERE action = 'auto_applied' AND created_at >= (NOW() - INTERVAL 7 DAY)"
        )->fetchColumn();
        if ($usedThisWeek >= $weeklyLimit) {
            return ['applied' => false, 'reason' => "weekly rate limit reached ({$usedThisWeek}/{$weeklyLimit})"];
        }

        $result = cms_growth_agent_apply_internal_link($pdo, $jobId);
        if (!$result['ok']) {
            return ['applied' => false, 'reason' => 'apply failed: ' . $result['error']];
        }

        // Retag the feedback row cms_growth_agent_apply_internal_link() just
        // inserted (action='approved_as_is', reviewed_by=NULL — that
        // function reads $_SESSION['cms_admin_id'], which is simply absent
        // here since this path has no logged-in admin, same as any other
        // system-initiated write in this codebase). A job can only ever be
        // approved once (that function itself guards status='manual_action'
        // before proceeding), so this WHERE matches exactly one row; ORDER
        // BY + LIMIT kept anyway as a defensive habit, not a requirement.
        $pdo->prepare(
            "UPDATE growth_agent_feedback SET action = 'auto_applied'
              WHERE job_id = :job_id AND action = 'approved_as_is'
              ORDER BY id DESC LIMIT 1"
        )->execute(['job_id' => $jobId]);

        cms_growth_agent_autonomous_notify($pdo, $jobId);

        return ['applied' => true, 'reason' => 'ok'];
    } catch (Throwable $e) {
        error_log('[cms_growth_agent_autonomous_maybe_apply_internal_link] job_id=' . $jobId . ': ' . $e->getMessage());
        return ['applied' => false, 'reason' => 'exception: ' . $e->getMessage()];
    }
}

/**
 * Best-effort Telegram notification for one auto-applied internal link.
 *
 * IMPORTANT — there is no pre-existing outbound n8n webhook in this
 * codebase to "reuse" (checked before assuming otherwise): the only n8n
 * integration that exists is api/growth-agent-digest.php, which is PULL —
 * n8n calls THIS server on a schedule for a weekly summary. This function
 * is the first PUSH integration (this server calls OUT to n8n once per
 * auto-apply event) — a genuinely new webhook, not a reuse of the digest
 * one, since the two move data in opposite directions and the digest
 * endpoint has no mechanism to originate an unsolicited per-event call.
 *
 * GROWTH_AGENT_AUTONOMOUS_WEBHOOK_URL (config/app.php, gitignored, same
 * precedent as GROWTH_AGENT_DIGEST_TOKEN there) defaults to '' — notifying
 * is entirely optional; auto-apply itself works identically whether or not
 * this is configured. Never throws — a failed/unconfigured notification
 * must never undo or block an apply that already succeeded.
 */
function cms_growth_agent_autonomous_notify(PDO $pdo, int $jobId): void
{
    try {
        if (!defined('GROWTH_AGENT_AUTONOMOUS_WEBHOOK_URL') || GROWTH_AGENT_AUTONOMOUS_WEBHOOK_URL === '') {
            return;
        }

        require_once __DIR__ . '/gsc-api.php';

        $stmt = $pdo->prepare(
            "SELECT j.id, j.page_id, j.input_brief, p.title, p.slug
               FROM growth_agent_jobs j
               LEFT JOIN pages p ON p.page_id = j.page_id
              WHERE j.id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $jobId]);
        $job = $stmt->fetch();
        if (!$job) {
            return;
        }

        $brief = json_decode((string) ($job['input_brief'] ?? ''), true);
        $targetTitle = is_array($brief) ? (string) ($brief['target_title'] ?? '') : '';
        $anchorText = is_array($brief) ? (string) ($brief['anchor_text'] ?? '') : '';

        $adminUrl = cms_admin_base_url() . 'pages/growth-agent.php';
        $text = "🤖 Internal link diterapkan otomatis\n"
            . 'Artikel: ' . (string) $job['title'] . "\n"
            . 'Anchor: "' . $anchorText . '" → ' . $targetTitle . "\n"
            . 'Detail: ' . $adminUrl;

        cms_gsc_http_request(
            'POST',
            GROWTH_AGENT_AUTONOMOUS_WEBHOOK_URL,
            json_encode(['job_id' => $jobId, 'page_id' => (int) $job['page_id'], 'text' => $text], JSON_UNESCAPED_UNICODE),
            ['Content-Type: application/json'],
            10
        );
    } catch (Throwable $e) {
        error_log('[cms_growth_agent_autonomous_notify] job_id=' . $jobId . ': ' . $e->getMessage());
    }
}

/**
 * Reverts one auto-applied internal link — restores pages.content from the
 * previous_content snapshot cms_growth_agent_apply_internal_link() took at
 * apply time (stored in growth_agent_jobs.output_json, same field the
 * manual-apply flow has always written; nothing new to read from). This is
 * the ONLY path back: this CMS has no article revision history at all, so
 * once a human notices an autonomous change was wrong, this snapshot is
 * the sole way to undo it (see this section's own devs brief note).
 *
 * Eligibility, checked directly in the WHERE clause rather than as
 * separate PHP guards: the job must be job_type='internal_link_suggestion',
 * status='succeeded' (a job that's already 'reverted' can't be reverted
 * again — no matching row, clean "not found" error), AND have a
 * growth_agent_feedback row with action='auto_applied' — deliberately
 * scoped to AUTO-applied links only, not every manually-applied one too
 * (the devs brief's own wording: "buat internal link yang di-auto-apply").
 * A manually-applied link has no equivalent Revert button anywhere in this
 * codebase and this function does not add one — reverting a human's own
 * deliberate action is a different, larger conversation than undoing
 * something the agent did unsupervised.
 *
 * On success: pages.content restored, growth_agent_jobs.status set to
 * 'reverted' (NOT back to 'succeeded' or 'manual_action' — see
 * cms_growth_agent_ensure_autonomous_schema()'s docblock for why this
 * specific status value exists and what it deliberately excludes the job
 * from), and a NEW growth_agent_feedback row is inserted with
 * action='rejected' — the original 'auto_applied' row is left untouched as
 * an honest historical record ("this WAS auto-applied on this date"); the
 * revert is recorded as its own later, separate event, same
 * append-only-log spirit as every other multi-event job in this table.
 *
 * @return array{ok:bool,error:string}
 */
function cms_growth_agent_revert_auto_applied_link(PDO $pdo, int $jobId): array
{
    try {
        $stmt = $pdo->prepare(
            "SELECT j.id, j.page_id, j.output_json
               FROM growth_agent_jobs j
              WHERE j.id = :id
                AND j.job_type = 'internal_link_suggestion'
                AND j.status = 'succeeded'
                AND EXISTS (
                    SELECT 1 FROM growth_agent_feedback f
                     WHERE f.job_id = j.id AND f.action = 'auto_applied'
                )
              LIMIT 1"
        );
        $stmt->execute(['id' => $jobId]);
        $job = $stmt->fetch();
        if (!$job) {
            return ['ok' => false, 'error' => 'Job auto-applied tidak ditemukan — mungkin sudah direvert sebelumnya, atau link ini diterapkan manual (bukan otomatis).'];
        }

        $output = json_decode((string) $job['output_json'], true);
        if (!is_array($output) || !isset($output['previous_content'])) {
            return ['ok' => false, 'error' => 'Snapshot konten sebelum perubahan tidak ditemukan di job ini — tidak bisa direvert dengan aman.'];
        }

        $pageId = (int) $job['page_id'];
        $currentAdminId = (int) ($_SESSION['cms_admin_id'] ?? 0) ?: null;

        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE pages SET content = :content, updated_at = NOW() WHERE page_id = :id')
                ->execute(['content' => (string) $output['previous_content'], 'id' => $pageId]);

            $pdo->prepare(
                "UPDATE growth_agent_jobs SET status = 'reverted', updated_at = NOW() WHERE id = :id"
            )->execute(['id' => $jobId]);

            $pdo->prepare(
                'INSERT INTO growth_agent_feedback (job_id, action, notes, reviewed_by, created_at)
                 VALUES (:job_id, :action, :notes, :reviewed_by, NOW())'
            )->execute([
                'job_id' => $jobId,
                'action' => 'rejected',
                'notes' => 'Auto-applied internal link direvert manual oleh operator — konten dikembalikan ke sebelum perubahan.',
                'reviewed_by' => $currentAdminId,
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return ['ok' => true, 'error' => ''];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * How many auto-applies have counted against this week's rate limit so
 * far — same COUNT() cms_growth_agent_autonomous_maybe_apply_internal_link()
 * itself checks before applying, exposed separately so the Autonomous Mode
 * panel can show "X / weekly_limit used this week" without duplicating the
 * query inline in growth-agent.php. Never throws (returns 0 on failure —
 * same "don't block the page over a display number" reasoning as every
 * other $safeCount()-style helper in this codebase).
 */
function cms_growth_agent_autonomous_weekly_used(PDO $pdo): int
{
    try {
        return (int) $pdo->query(
            "SELECT COUNT(*) FROM growth_agent_feedback WHERE action = 'auto_applied' AND created_at >= (NOW() - INTERVAL 7 DAY)"
        )->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Recent auto-applied internal links, newest first — feeds the Autonomous
 * Mode panel's Revert list. Only rows still eligible for Revert (status=
 * 'succeeded', i.e. not already reverted) are returned — an already-
 * reverted job simply drops off this list, since
 * cms_growth_agent_revert_auto_applied_link() itself would refuse it
 * anyway (see that function's WHERE clause). Never throws.
 *
 * @return list<array{job_id:int,page_id:int,page_title:string,target_title:string,anchor_text:string,applied_at:string}>
 */
function cms_growth_agent_get_recent_auto_applied_links(PDO $pdo, int $limit = 10): array
{
    $limit = max(1, min(50, $limit));
    try {
        $stmt = $pdo->prepare(
            "SELECT j.id AS job_id, j.page_id, p.title AS page_title, j.input_brief, f.created_at AS applied_at
               FROM growth_agent_jobs j
               INNER JOIN growth_agent_feedback f ON f.job_id = j.id AND f.action = 'auto_applied'
               LEFT JOIN pages p ON p.page_id = j.page_id
              WHERE j.job_type = 'internal_link_suggestion' AND j.status = 'succeeded'
              ORDER BY f.created_at DESC
              LIMIT " . $limit
        );
        $stmt->execute();
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $brief = json_decode((string) ($row['input_brief'] ?? ''), true);
            $rows[] = [
                'job_id' => (int) $row['job_id'],
                'page_id' => (int) $row['page_id'],
                'page_title' => (string) ($row['page_title'] ?? ''),
                'target_title' => is_array($brief) ? (string) ($brief['target_title'] ?? '') : '',
                'anchor_text' => is_array($brief) ? (string) ($brief['anchor_text'] ?? '') : '',
                'applied_at' => (string) $row['applied_at'],
            ];
        }
        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * ── Keyword Expansion Agent (GROWTH_AGENT_V2_PROPOSAL.md Fase B item 2,
 *    4 Agu 2026) ──
 *
 * Fills the gap the Opportunity Engine structurally can't: that engine only
 * ever sees queries the site ALREADY has GSC impressions for, so it can
 * optimize existing reach but never discover a topic the site has never
 * touched at all. This agent instead looks at what the site HAS published
 * (title + short description of the 50 most recent articles, same context
 * shape as cms_growth_agent_generate_topic_clusters()) and asks AI for new,
 * niche-appropriate topic ideas not yet covered.
 *
 * NOTE on GROWTH_AGENT_V2_PROPOSAL.md § 2: that section's original text
 * ("hasilnya masuk sebagai draft topic baru di Topic Cluster yang sudah
 * ada") pre-dates § 1b and is superseded by it — confirmed with the user
 * before implementing. This agent writes ONLY to growth_agent_jobs
 * (job_type 'keyword_expansion_topic'), never directly into
 * growth_agent_topic_clusters. No new table, no new column.
 *
 * Cost control: this is the first agent in the new Fase B/C wave that
 * calls a paid AI API, so unlike the deterministic SEO-G0 Gate/Internal
 * Linking Agent, it needs an explicit spend bound — see
 * 'keyword_expansion' in cms_gsc_default_opportunity_thresholds()
 * (gsc-api.php): AI is called EXACTLY ONCE per click (one prompt, one
 * response containing up to N topics), never once per topic, and the
 * result is hard-capped to max_topics_per_run regardless of how many the
 * model returns.
 *
 * Split into two functions specifically so the parsing/validation/gate/
 * job-creation logic (cms_growth_agent_keyword_expansion_process_topics())
 * can be exercised directly with a hand-built response array in tests —
 * without an AI credential configured or any network call — the same way
 * a real malformed AI response would be handled. This function
 * (cms_growth_agent_scan_keyword_expansion()) is the thin wrapper that
 * actually resolves the agent and calls AI.
 */

/**
 * Validates/persists an (already-parsed) AI response for keyword
 * expansion — the testable half, see this section's top note. Never
 * assumes $parsed came from a real AI call; a caller can pass any shape
 * here, malformed or not, and it degrades the same way either way.
 *
 * For every topic that survives shape validation (non-empty string after
 * trim, capped to max_topics_per_run even if the model returned more):
 * runs the SEO-G0 Gate against it (same pattern as
 * cms_growth_agent_generate_article_idea()/
 * cms_growth_agent_request_topic_gap_article() — see
 * cms_growth_agent_seo_g0_gate()'s own docblock) and logs one
 * 'keyword_expansion_topic' manual_action job. Advisory only: a gate
 * warning never prevents the job from being logged, it just rides along
 * in input_brief.seo_g0_gate for the operator to see during review.
 *
 * input_brief stores the topic under BOTH 'topic' (this agent's own,
 * clearer field name) AND 'missing_topic' (so
 * cms_growth_agent_create_article_draft_from_topic_gap() — written for
 * 'topic_gap_article' — can be reused UNCHANGED for this job type's
 * Approve action too; see growth-agent.php's approve handler).
 *
 * Never throws. Returns ['ok' => bool, 'topics_proposed' => int, 'error' => string].
 */
function cms_growth_agent_keyword_expansion_process_topics(PDO $pdo, ?array $parsed, ?string $modelUsed): array
{
    try {
        if (!is_array($parsed) || !is_array($parsed['topics'] ?? null)) {
            return ['ok' => false, 'topics_proposed' => 0, 'error' => 'AI response was not in the expected format'];
        }

        $maxTopics = max(1, (int) (cms_growth_agent_keyword_expansion_thresholds($pdo)['max_topics_per_run'] ?? 5));

        $created = 0;
        foreach ($parsed['topics'] as $entry) {
            if ($created >= $maxTopics) {
                break; // hard cap even if the model returned more than asked
            }
            $topic = is_array($entry) ? trim((string) ($entry['topic'] ?? '')) : trim((string) $entry);
            if ($topic === '') {
                continue;
            }
            $rationale = is_array($entry) ? trim((string) ($entry['rationale'] ?? '')) : '';

            $gateResult = cms_growth_agent_seo_g0_gate($pdo, 'keyword_expansion_topic', $topic);

            $inputBrief = [
                'topic' => $topic,
                'missing_topic' => $topic, // alias — lets the Approve handler reuse cms_growth_agent_create_article_draft_from_topic_gap() unchanged
                'rationale' => $rationale,
                'model_used' => $modelUsed,
                'seo_g0_gate' => $gateResult,
            ];

            $jobId = cms_growth_agent_log_job(
                $pdo, 'keyword_expansion_topic', 'growth_agent', null, 'manual_action', $inputBrief, null,
                $modelUsed, null, null, null, '', 'medium'
            );
            if ($jobId > 0) {
                $created++;
            }
        }

        if ($created === 0) {
            return ['ok' => false, 'topics_proposed' => 0, 'error' => 'AI tidak menghasilkan topik baru yang valid (semua kosong, atau sudah pernah diusulkan).'];
        }

        return ['ok' => true, 'topics_proposed' => $created, 'error' => ''];
    } catch (Throwable $e) {
        return ['ok' => false, 'topics_proposed' => 0, 'error' => $e->getMessage()];
    }
}

/**
 * Reads 'keyword_expansion' thresholds from opportunity_thresholds_json —
 * same array_replace_recursive-over-defaults pattern as
 * cms_growth_agent_il_thresholds()/cms_growth_agent_g0_gate_thresholds().
 * Never throws.
 *
 * @return array{max_topics_per_run: int, context_articles_limit: int}
 */
function cms_growth_agent_keyword_expansion_thresholds(PDO $pdo): array
{
    $fallback = ['max_topics_per_run' => 5, 'context_articles_limit' => 50];
    try {
        require_once __DIR__ . '/gsc-api.php';
        $defaults = cms_gsc_default_opportunity_thresholds()['keyword_expansion'] ?? $fallback;
        $configured = cms_gsc_get_opportunity_thresholds($pdo)['keyword_expansion'] ?? [];

        return array_replace_recursive($defaults, is_array($configured) ? $configured : []);
    } catch (Throwable $e) {
        return $fallback;
    }
}

/**
 * The AI-calling half — thin on purpose, see this section's top note.
 * Builds the "already covered" context (same shape/limit convention as
 * cms_growth_agent_generate_topic_clusters()'s 50-article prompt), calls
 * AI exactly once, and hands the parsed (or unparseable) result to
 * cms_growth_agent_keyword_expansion_process_topics() to validate and log.
 *
 * Never throws. Returns ['ok' => bool, 'topics_proposed' => int, 'error' => string].
 */
function cms_growth_agent_scan_keyword_expansion(PDO $pdo): array
{
    try {
        cms_growth_agent_ensure_schema($pdo);
        require_once __DIR__ . '/ai-helpers.php';
    } catch (Throwable $e) {
        return ['ok' => false, 'topics_proposed' => 0, 'error' => $e->getMessage()];
    }

    $contextLimit = max(10, min(100, (int) (cms_growth_agent_keyword_expansion_thresholds($pdo)['context_articles_limit'] ?? 50)));

    try {
        $stmt = $pdo->prepare(
            "SELECT title, meta_description, excerpt
               FROM pages
              WHERE status = 'published'
              ORDER BY created_at DESC
              LIMIT " . $contextLimit
        );
        $stmt->execute();
        $pages = $stmt->fetchAll();
    } catch (Throwable $e) {
        return ['ok' => false, 'topics_proposed' => 0, 'error' => $e->getMessage()];
    }

    if ($pages === []) {
        return ['ok' => false, 'topics_proposed' => 0, 'error' => 'Tidak ada artikel published untuk dijadikan konteks.'];
    }

    $promptLines = [];
    foreach ($pages as $page) {
        $desc = trim((string) ($page['meta_description'] ?? ''));
        if ($desc === '') {
            $desc = trim((string) ($page['excerpt'] ?? ''));
        }
        $promptLines[] = '- ' . $page['title'] . ($desc !== '' ? ' | ' . $desc : '');
    }

    $maxTopics = max(1, (int) (cms_growth_agent_keyword_expansion_thresholds($pdo)['max_topics_per_run'] ?? 5));

    $defaultSystemPrompt =
        'You are the Growth Agent content strategist for an Indonesian-language sports news website ' .
        'covering football (Timnas Indonesia, Liga 1, major European leagues) and other popular sports. ' .
        'You are given a list of articles the site has ALREADY published. ' .
        'Propose ' . $maxTopics . ' NEW article topic ideas that are realistic for this specific site\'s ' .
        'niche and audience, and that are NOT already covered (even partially) by anything in the given ' .
        'list. Do not propose generic listicles or topics outside sports news. Each topic must ' .
        'be phrased as a concrete, specific article title in Bahasa Indonesia (the site\'s language), not a ' .
        'vague keyword. Respond with ONLY a raw JSON object, no markdown, no code fences, no commentary, ' .
        'in exactly this shape: {"topics": [{"topic": "...", "rationale": "..."}]}';

    $agent = cms_ai_resolve_agent($pdo, 'growth_agent', $defaultSystemPrompt);
    if (!$agent['ok']) {
        return ['ok' => false, 'topics_proposed' => 0, 'error' => $agent['error']];
    }

    $userPrompt = "Articles already published (most recent first, max {$contextLimit}):\n" . implode("\n", $promptLines);

    try {
        // Exactly ONE AI call per click, regardless of how many topics come
        // back — see this section's top note on cost control.
        $result = cms_ai_call_provider(
            $agent['provider'], $agent['api_key'], $agent['model'],
            $userPrompt, $agent['system_prompt'], max($agent['max_tokens'], 800), $agent['temperature']
        );
    } catch (Throwable $e) {
        return ['ok' => false, 'topics_proposed' => 0, 'error' => $e->getMessage()];
    }

    if (!$result['success']) {
        return ['ok' => false, 'topics_proposed' => 0, 'error' => 'AI request failed: ' . $result['error']];
    }

    $parsed = cms_ai_extract_json($result['text']);

    return cms_growth_agent_keyword_expansion_process_topics($pdo, $parsed, $agent['model']);
}

/**
 * ── Technical SEO Auditor (GROWTH_AGENT_V2_PROPOSAL.md Fase B item 3,
 *    5 Agu 2026) ──
 *
 * DIFFERENT IN KIND from every other agent in this file: this is a
 * read-only REPORT, not a proposal that needs approve/reject. It never
 * writes to growth_agent_jobs and never touches `pages` — confirmed
 * precedent for this shape is the existing Feedback Loop / Before-After
 * panel on growth-agent.php (cms_growth_agent_get_feedback_report()),
 * which is also pure reporting with no approve/execute step. § 1b's "every
 * proposal needs a job row" rule applies to things a human must DECIDE;
 * there is no decision here, only information an operator acts on
 * themselves in the article editor.
 *
 * Results persist in ONE new table, `growth_agent_technical_audits` —
 * within § 1b's explicit allowance for "supporting tables that hold raw
 * scan data" (as opposed to a second proposal queue, which is what's
 * actually forbidden). One row per published article, upserted on every
 * (re-)audit so an operator can see improvement after fixing something —
 * same upsert-by-key shape as gsc_url_inspections.
 *
 * That same table ALSO holds exactly one extra row, keyed by page_id IS
 * NULL, reserved for the optional PageSpeed Insights API key (encrypted
 * via cms_ai_encrypt()/cms_ai_decrypt() — those two functions are
 * generic AES-256-CBC helpers despite the "ai_" name, not intrinsically
 * tied to ai_credentials). This is deliberate: the task budget was "at
 * most ONE new table", and a second table just for one optional secret
 * value isn't worth spending that budget on. page_id is NULLable and NOT
 * part of a UNIQUE constraint specifically so this sentinel row can
 * coexist with real per-article rows; single-settings-row enforcement
 * happens in cms_growth_agent_tsa_save_psi_api_key() itself (check-then-
 * write), not at the schema level.
 *
 * Three checks, deliberately very different in cost and therefore
 * triggered/batched differently:
 *   A. Alt text — cms_growth_agent_tsa_check_images(). Pure DB read +
 *      DOMDocument parse, no network. Cheap enough to run on every
 *      published article in one click.
 *   B. Schema markup — cms_growth_agent_tsa_check_schema_markup(). Split
 *      into a pure parser (testable with mock HTML, no network) and an
 *      orchestrator that fetches the article's own live URL. See that
 *      orchestrator's own docblock for why a real HTTP fetch is used
 *      instead of a DB-only heuristic, and the real risk that decision
 *      carries.
 *   C. Core Web Vitals — cms_growth_agent_tsa_run_psi(). PageSpeed
 *      Insights, 10-30s PER url. Hard-capped small
 *      ('psi_urls_per_run', default 3) and run as its own separate
 *      action, never bundled with A/B.
 *
 * Every check degrades independently and never throws — a PSI outage
 * must never stop the alt-text/schema checks from running, and a broken
 * article must never abort the whole batch.
 */

/**
 * Creates growth_agent_technical_audits if missing — lazy self-healing via
 * cms_ensure_table(), same convention as every other table in this file.
 * See this section's top note for the page_id-NULL settings-row design.
 */
function cms_growth_agent_tsa_ensure_schema(PDO $pdo): void
{
    cms_ensure_table(
        $pdo,
        'growth_agent_technical_audits',
        "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
         page_id INT UNSIGNED DEFAULT NULL COMMENT 'NULL = reserved settings row holding psi_api_key_enc only',
         url VARCHAR(500) DEFAULT NULL,
         total_image_count INT UNSIGNED DEFAULT NULL,
         missing_alt_count INT UNSIGNED DEFAULT NULL,
         content_checked_at TIMESTAMP NULL DEFAULT NULL,
         has_news_article_schema TINYINT(1) DEFAULT NULL COMMENT 'NULL = not yet verified (fetch never attempted or failed), not the same as 0/false',
         has_breadcrumb_schema TINYINT(1) DEFAULT NULL COMMENT 'NULL = not yet verified, see has_news_article_schema',
         schema_check_error VARCHAR(255) DEFAULT NULL,
         schema_checked_at TIMESTAMP NULL DEFAULT NULL,
         psi_mobile_score TINYINT UNSIGNED DEFAULT NULL,
         psi_lcp_ms INT UNSIGNED DEFAULT NULL,
         psi_cls DECIMAL(4,3) DEFAULT NULL,
         psi_error VARCHAR(255) DEFAULT NULL,
         psi_checked_at TIMESTAMP NULL DEFAULT NULL,
         psi_api_key_enc TEXT DEFAULT NULL COMMENT 'Only ever set on the page_id IS NULL settings row',
         created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
         updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
         KEY idx_gata_page (page_id)"
    );
}

/**
 * Reads 'technical_seo' thresholds from opportunity_thresholds_json — same
 * array_replace_recursive-over-defaults pattern as every other threshold
 * getter in this file. Never throws.
 */
function cms_growth_agent_tsa_thresholds(PDO $pdo): array
{
    $fallback = [
        'content_check_articles_per_run' => 50, 'schema_check_articles_per_run' => 24,
        'psi_urls_per_run' => 3, 'psi_timeout_seconds' => 35, 'psi_poor_score_threshold' => 50,
    ];
    try {
        require_once __DIR__ . '/gsc-api.php';
        $defaults = cms_gsc_default_opportunity_thresholds()['technical_seo'] ?? $fallback;
        $configured = cms_gsc_get_opportunity_thresholds($pdo)['technical_seo'] ?? [];

        return array_replace_recursive($defaults, is_array($configured) ? $configured : []);
    } catch (Throwable $e) {
        return $fallback;
    }
}

/**
 * Upsert-by-page_id into growth_agent_technical_audits — SELECT-then-
 * INSERT/UPDATE, same convention as cms_gsc_upsert_url_inspection().
 * $pageId === null targets the one reserved settings row (see this
 * section's top note); any other value targets/creates that article's row.
 * $fields is merged over the existing row's values (only keys present in
 * $fields are touched) so the alt-text pass and the schema-check pass
 * (which may run separately) never clobber each other's columns.
 *
 * Never throws — a failed audit write must not break the batch it's part
 * of.
 */
function cms_growth_agent_tsa_upsert(PDO $pdo, ?int $pageId, array $fields): void
{
    try {
        cms_growth_agent_tsa_ensure_schema($pdo);

        if ($pageId === null) {
            $existing = $pdo->query('SELECT id FROM growth_agent_technical_audits WHERE page_id IS NULL LIMIT 1')->fetchColumn();
        } else {
            $stmt = $pdo->prepare('SELECT id FROM growth_agent_technical_audits WHERE page_id = :page_id LIMIT 1');
            $stmt->execute(['page_id' => $pageId]);
            $existing = $stmt->fetchColumn();
        }

        $allowedColumns = [
            'url', 'total_image_count', 'missing_alt_count', 'content_checked_at',
            'has_news_article_schema', 'has_breadcrumb_schema', 'schema_check_error', 'schema_checked_at',
            'psi_mobile_score', 'psi_lcp_ms', 'psi_cls', 'psi_error', 'psi_checked_at', 'psi_api_key_enc',
        ];
        $fields = array_intersect_key($fields, array_flip($allowedColumns));
        if ($fields === []) {
            return;
        }

        if ($existing !== false) {
            $setSql = implode(', ', array_map(static fn (string $col): string => "{$col} = :{$col}", array_keys($fields)));
            $fields['id'] = $existing;
            $pdo->prepare("UPDATE growth_agent_technical_audits SET {$setSql}, updated_at = NOW() WHERE id = :id")->execute($fields);
        } else {
            $fields['page_id'] = $pageId;
            $cols = implode(', ', array_keys($fields));
            $placeholders = implode(', ', array_map(static fn (string $col): string => ":{$col}", array_keys($fields)));
            $pdo->prepare("INSERT INTO growth_agent_technical_audits ({$cols}, created_at) VALUES ({$placeholders}, NOW())")->execute($fields);
        }
    } catch (Throwable $e) {
        // A failed audit-row write must not break the batch it's part of.
    }
}

/**
 * Check A — alt text. Parses $html with DOMDocument (the SAME UTF-8-safe
 * loadHTML pattern as cms_growth_agent_il_insert_link() — the
 * "<?xml encoding=...>" prefix trick, without which non-ASCII characters
 * get silently mangled) and counts <img> tags with a missing or
 * whitespace-only alt attribute. DOMDocument (not regex) means an <img>
 * that happens to appear inside a <script>/<style> block, or text that
 * merely LOOKS like an img tag inside an attribute value, can never be
 * miscounted — only real element nodes are ever inspected.
 *
 * Never throws. 'ok' is false only when the HTML couldn't be parsed at
 * all (distinct from 'ok' true + total_images 0, a genuinely image-free
 * article).
 *
 * @return array{ok: bool, total_images: int, missing_alt: int}
 */
function cms_growth_agent_tsa_check_images(string $html): array
{
    if (trim($html) === '') {
        return ['ok' => true, 'total_images' => 0, 'missing_alt' => 0];
    }

    try {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8"><div id="wpm-tsa-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        if (!$loaded) {
            return ['ok' => false, 'total_images' => 0, 'missing_alt' => 0];
        }

        $root = $dom->getElementById('wpm-tsa-root');
        if ($root === null) {
            return ['ok' => false, 'total_images' => 0, 'missing_alt' => 0];
        }

        $images = (new DOMXPath($dom))->query('.//img', $root);
        $total = $images->length;
        $missing = 0;
        foreach ($images as $img) {
            $alt = trim((string) $img->getAttribute('alt'));
            if ($alt === '') {
                $missing++;
            }
        }

        return ['ok' => true, 'total_images' => $total, 'missing_alt' => $missing];
    } catch (Throwable $e) {
        return ['ok' => false, 'total_images' => 0, 'missing_alt' => 0];
    }
}

/**
 * Runs Check A across up to 'content_check_articles_per_run' published
 * articles (least-recently content-checked first) and upserts the result.
 * Pure DB read + DOM parse — no network, so no per-article failure mode
 * beyond "couldn't parse this one article's HTML", which is handled by
 * cms_growth_agent_tsa_check_images()'s own 'ok' flag and simply skips
 * writing that row rather than writing a false "0 missing" result.
 *
 * Never throws. Returns ['checked' => int, 'errors' => int].
 */
function cms_growth_agent_tsa_run_content_checks(PDO $pdo): array
{
    $stats = ['checked' => 0, 'errors' => 0];

    try {
        cms_growth_agent_tsa_ensure_schema($pdo);
        $limit = max(1, min(200, (int) (cms_growth_agent_tsa_thresholds($pdo)['content_check_articles_per_run'] ?? 50)));

        $stmt = $pdo->prepare(
            "SELECT p.page_id, p.content
               FROM pages p
               LEFT JOIN growth_agent_technical_audits a ON a.page_id = p.page_id
              WHERE p.status = 'published'
              ORDER BY (a.content_checked_at IS NULL) DESC, a.content_checked_at ASC
              LIMIT " . $limit
        );
        $stmt->execute();
        $pages = $stmt->fetchAll();
    } catch (Throwable $e) {
        return $stats;
    }

    foreach ($pages as $page) {
        try {
            $result = cms_growth_agent_tsa_check_images((string) $page['content']);
            if (!$result['ok']) {
                $stats['errors']++;
                continue;
            }
            cms_growth_agent_tsa_upsert($pdo, (int) $page['page_id'], [
                'total_image_count' => $result['total_images'],
                'missing_alt_count' => $result['missing_alt'],
                'content_checked_at' => date('Y-m-d H:i:s'),
            ]);
            $stats['checked']++;
        } catch (Throwable $e) {
            $stats['errors']++;
        }
    }

    return $stats;
}

/**
 * Check B, pure parser half — given ALREADY-FETCHED page HTML, looks for
 * <script type="application/ld+json"> blocks and checks whether any of
 * them decode to a NewsArticle / BreadcrumbList (or an array containing
 * one — @graph-style multi-type blocks are not used by this codebase, but
 * a bare array of objects is tolerated defensively). No network, no DB —
 * deliberately separated from cms_growth_agent_tsa_check_schema_markup()
 * so it can be unit-tested with hand-written HTML strings, malformed or
 * not, without ever touching the network.
 *
 * Never throws.
 *
 * @return array{has_news_article: bool, has_breadcrumb: bool}
 */
function cms_growth_agent_tsa_parse_schema_markup(string $html): array
{
    $result = ['has_news_article' => false, 'has_breadcrumb' => false];
    if (trim($html) === '') {
        return $result;
    }

    try {
        if (!preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches)) {
            return $result;
        }
        foreach ($matches[1] as $jsonText) {
            $decoded = json_decode(trim($jsonText), true);
            if (!is_array($decoded)) {
                continue;
            }
            // Normalize to a flat list of "objects with an @type" so both
            // a single {"@type":"..."} block and an array of such blocks
            // are handled the same way.
            $candidates = array_is_list($decoded) ? $decoded : [$decoded];
            foreach ($candidates as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }
                $type = (string) ($candidate['@type'] ?? '');
                if ($type === 'NewsArticle') {
                    $result['has_news_article'] = true;
                }
                if ($type === 'BreadcrumbList') {
                    $result['has_breadcrumb'] = true;
                }
            }
        }
        return $result;
    } catch (Throwable $e) {
        return $result;
    }
}

/**
 * Check B, fetch orchestrator. Deliberately fetches the article's REAL
 * public URL over HTTP rather than inferring schema presence from DB
 * state alone — a DB-only check (e.g. "this is a published article, and
 * artikel.php unconditionally emits the JSON-LD block, so it must be
 * fine") would be circular: it would just re-confirm routing, never catch
 * an actual regression (a future conditional that suppresses the block
 * for some articles, a PHP error specific to one article's data shape,
 * caching serving stale pre-schema HTML, etc.) — which is exactly the
 * "verify it's actually working, not just spot-checked" value this check
 * exists for.
 *
 * This is a real, accepted trade-off: the server ends up requesting its
 * own public URL over the network. Mitigated three ways:
 *   1. Small, explicit per-run cap ('schema_check_articles_per_run') —
 *      unlike PSI this is fast per-request (same-server), but still a
 *      real round trip repeated per article, so it stays bounded.
 *   2. A fetch FAILURE is recorded as "not yet verified" (NULL in
 *      has_news_article_schema/has_breadcrumb_schema), never as "schema
 *      missing" — a network hiccup or a host firewall blocking loopback
 *      self-requests must never be misreported as a content defect.
 *   3. Same public-URL construction already trusted elsewhere in this
 *      file (cms_sitemap_absolute_url(cms_sitemap_path_for(...)) — see
 *      cms_growth_agent_inspect_priority_urls()) rather than inventing a
 *      new one.
 *
 * Never throws. Returns ['checked' => int, 'errors' => int].
 */
function cms_growth_agent_tsa_run_schema_checks(PDO $pdo): array
{
    $stats = ['checked' => 0, 'errors' => 0];

    try {
        cms_growth_agent_tsa_ensure_schema($pdo);
        require_once __DIR__ . '/gsc-api.php';
        require_once __DIR__ . '/sitemap-service.php';
        $limit = max(1, min(50, (int) (cms_growth_agent_tsa_thresholds($pdo)['schema_check_articles_per_run'] ?? 24)));

        $stmt = $pdo->prepare(
            "SELECT p.page_id, p.slug, p.canonical_url
               FROM pages p
               LEFT JOIN growth_agent_technical_audits a ON a.page_id = p.page_id
              WHERE p.status = 'published'
              ORDER BY (a.schema_checked_at IS NULL) DESC, a.schema_checked_at ASC
              LIMIT " . $limit
        );
        $stmt->execute();
        $pages = $stmt->fetchAll();
    } catch (Throwable $e) {
        return $stats;
    }

    foreach ($pages as $page) {
        try {
            $canonical = trim((string) ($page['canonical_url'] ?? ''));
            $url = $canonical !== '' ? $canonical : cms_sitemap_absolute_url(cms_sitemap_path_for('article', (string) $page['slug']));

            $response = cms_gsc_http_request('GET', $url);
            if (!$response['ok']) {
                cms_growth_agent_tsa_upsert($pdo, (int) $page['page_id'], [
                    'url' => $url,
                    'has_news_article_schema' => null,
                    'has_breadcrumb_schema' => null,
                    'schema_check_error' => 'Gagal mengambil halaman: ' . ($response['error'] ?? ('HTTP ' . $response['status'])),
                    'schema_checked_at' => date('Y-m-d H:i:s'),
                ]);
                $stats['errors']++;
                continue;
            }

            $parsed = cms_growth_agent_tsa_parse_schema_markup($response['body']);
            cms_growth_agent_tsa_upsert($pdo, (int) $page['page_id'], [
                'url' => $url,
                'has_news_article_schema' => $parsed['has_news_article'] ? 1 : 0,
                'has_breadcrumb_schema' => $parsed['has_breadcrumb'] ? 1 : 0,
                'schema_check_error' => null,
                'schema_checked_at' => date('Y-m-d H:i:s'),
            ]);
            $stats['checked']++;
        } catch (Throwable $e) {
            $stats['errors']++;
        }
    }

    return $stats;
}

/**
 * Check C, pure parser half — given an ALREADY-json_decode()'d PageSpeed
 * Insights response (or null, simulating unparseable JSON), extracts the
 * performance score (0-100, PSI reports 0-1), Largest Contentful Paint
 * (ms), and Cumulative Layout Shift. Deliberately separated from
 * cms_growth_agent_tsa_run_psi() so this can be tested against hand-built
 * response shapes — including every malformed variant — without ever
 * calling the real API.
 *
 * Never throws.
 *
 * @return array{ok: bool, mobile_score: ?int, lcp_ms: ?int, cls: ?float, error: string}
 */
function cms_growth_agent_tsa_parse_psi_response(?array $decoded): array
{
    $fail = static fn (string $message): array => ['ok' => false, 'mobile_score' => null, 'lcp_ms' => null, 'cls' => null, 'error' => $message];

    if (!is_array($decoded)) {
        return $fail('Respons PSI bukan JSON yang valid.');
    }

    try {
        $lighthouse = $decoded['lighthouseResult'] ?? null;
        if (!is_array($lighthouse)) {
            return $fail('Respons PSI tidak berisi lighthouseResult — kemungkinan URL gagal dianalisis PSI.');
        }

        $scoreRaw = $lighthouse['categories']['performance']['score'] ?? null;
        $mobileScore = is_numeric($scoreRaw) ? (int) round(((float) $scoreRaw) * 100) : null;

        $lcpRaw = $lighthouse['audits']['largest-contentful-paint']['numericValue'] ?? null;
        $lcpMs = is_numeric($lcpRaw) ? (int) round((float) $lcpRaw) : null;

        $clsRaw = $lighthouse['audits']['cumulative-layout-shift']['numericValue'] ?? null;
        $cls = is_numeric($clsRaw) ? round((float) $clsRaw, 3) : null;

        if ($mobileScore === null && $lcpMs === null && $cls === null) {
            return $fail('Respons PSI tidak berisi metrik yang diharapkan (score/LCP/CLS semuanya kosong).');
        }

        return ['ok' => true, 'mobile_score' => $mobileScore, 'lcp_ms' => $lcpMs, 'cls' => $cls, 'error' => ''];
    } catch (Throwable $e) {
        return $fail($e->getMessage());
    }
}

/**
 * Check C, API-calling orchestrator. Calls PageSpeed Insights
 * (pagespeedonline/v5/runPagespeed, strategy=mobile — mobile-first
 * indexing makes this the metric that actually matters for ranking) for
 * up to 'psi_urls_per_run' published articles (least-recently PSI-checked
 * first), via cms_gsc_http_request() reused as-is (no new cURL wrapper),
 * with an explicit longer timeout ('psi_timeout_seconds', default 35s —
 * PSI itself can take up to ~30s to compute). The API key is optional
 * (PSI works keyless, just under a shared-IP rate limit that matters on
 * shared hosting) — see cms_growth_agent_tsa_get_psi_api_key().
 *
 * Every per-URL failure (HTTP error, timeout, invalid JSON, unexpected
 * shape) is handled by cms_growth_agent_tsa_parse_psi_response() and
 * recorded as psi_error on that article's row rather than thrown —
 * Check A/B must be able to run independently of whether PSI is
 * reachable at all.
 *
 * Never throws. Returns ['checked' => int, 'errors' => int].
 */
function cms_growth_agent_tsa_run_psi(PDO $pdo): array
{
    $stats = ['checked' => 0, 'errors' => 0];

    try {
        cms_growth_agent_tsa_ensure_schema($pdo);
        require_once __DIR__ . '/gsc-api.php';
        require_once __DIR__ . '/sitemap-service.php';
        $thresholds = cms_growth_agent_tsa_thresholds($pdo);
        $limit = max(1, min(10, (int) ($thresholds['psi_urls_per_run'] ?? 3)));
        $timeout = max(20, min(60, (int) ($thresholds['psi_timeout_seconds'] ?? 35)));
        $apiKey = cms_growth_agent_tsa_get_psi_api_key($pdo);

        // Best-effort: some shared hosts cap max_execution_time low enough
        // that even a small PSI batch could get killed mid-request.
        // set_time_limit() is a no-op (silently ignored) when the host
        // disables runtime overrides, so this is purely additive safety,
        // never relied on as the sole mitigation — the small $limit above
        // is the real bound.
        @set_time_limit(max(60, $limit * ((int) $timeout + 5)));

        $stmt = $pdo->prepare(
            "SELECT p.page_id, p.slug, p.canonical_url
               FROM pages p
               LEFT JOIN growth_agent_technical_audits a ON a.page_id = p.page_id
              WHERE p.status = 'published'
              ORDER BY (a.psi_checked_at IS NULL) DESC, a.psi_checked_at ASC
              LIMIT " . $limit
        );
        $stmt->execute();
        $pages = $stmt->fetchAll();
    } catch (Throwable $e) {
        return $stats;
    }

    foreach ($pages as $page) {
        try {
            $canonical = trim((string) ($page['canonical_url'] ?? ''));
            $url = $canonical !== '' ? $canonical : cms_sitemap_absolute_url(cms_sitemap_path_for('article', (string) $page['slug']));

            $psiUrl = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?' . http_build_query(array_filter([
                'url' => $url,
                'strategy' => 'mobile',
                'category' => 'performance',
                'key' => $apiKey !== '' ? $apiKey : null,
            ]));

            $response = cms_gsc_http_request('GET', $psiUrl, null, [], $timeout);
            $decoded = $response['ok'] ? json_decode($response['body'], true) : null;
            $parsed = cms_growth_agent_tsa_parse_psi_response(is_array($decoded) ? $decoded : null);

            if (!$parsed['ok']) {
                $errorMessage = !$response['ok'] ? ('Gagal memanggil PSI: ' . ($response['error'] ?? ('HTTP ' . $response['status']))) : $parsed['error'];
                cms_growth_agent_tsa_upsert($pdo, (int) $page['page_id'], [
                    'psi_mobile_score' => null, 'psi_lcp_ms' => null, 'psi_cls' => null,
                    'psi_error' => mb_substr($errorMessage, 0, 255), 'psi_checked_at' => date('Y-m-d H:i:s'),
                ]);
                $stats['errors']++;
                continue;
            }

            cms_growth_agent_tsa_upsert($pdo, (int) $page['page_id'], [
                'psi_mobile_score' => $parsed['mobile_score'], 'psi_lcp_ms' => $parsed['lcp_ms'], 'psi_cls' => $parsed['cls'],
                'psi_error' => null, 'psi_checked_at' => date('Y-m-d H:i:s'),
            ]);
            $stats['checked']++;
        } catch (Throwable $e) {
            $stats['errors']++;
        }
    }

    return $stats;
}

/**
 * Reads the optional PSI API key from the reserved settings row
 * (page_id IS NULL), decrypting via cms_ai_decrypt(). Returns '' if none
 * is set or on any failure — callers treat '' as "call PSI keyless".
 * Never throws.
 */
function cms_growth_agent_tsa_get_psi_api_key(PDO $pdo): string
{
    try {
        require_once __DIR__ . '/ai-helpers.php';
        cms_growth_agent_tsa_ensure_schema($pdo);
        $enc = $pdo->query('SELECT psi_api_key_enc FROM growth_agent_technical_audits WHERE page_id IS NULL LIMIT 1')->fetchColumn();
        if (!is_string($enc) || $enc === '') {
            return '';
        }
        return cms_ai_decrypt($enc);
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Sets (or, given an empty string, clears) the optional PSI API key on
 * the reserved settings row — encrypted via cms_ai_encrypt(), same
 * encrypted-at-rest convention as ai_credentials.api_key_enc. Never
 * throws. Returns true on success.
 */
function cms_growth_agent_tsa_save_psi_api_key(PDO $pdo, string $apiKey): bool
{
    try {
        require_once __DIR__ . '/ai-helpers.php';
        cms_growth_agent_tsa_upsert($pdo, null, [
            'psi_api_key_enc' => $apiKey !== '' ? cms_ai_encrypt($apiKey) : null,
        ]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Logs a 'topic_gap_article' manual_action job — clicking "Generate Saran
 * Artikel" on a missing topic never calls the AI itself, it only queues a
 * review row (same "click just surfaces a job, approve does the real
 * work" split as cms_growth_agent_log_cannibalization_review()). The
 * actual draft is only created if/when this job gets approved — see
 * cms_growth_agent_create_article_draft_from_topic_gap().
 *
 * Runs the SEO-G0 Gate against $missingTopic before logging — see the
 * gate's own docblock above cms_growth_agent_seo_g0_gate(). Advisory only:
 * the job is logged unconditionally, warnings (if any) just ride along in
 * input_brief.seo_g0_gate for the operator to see during review.
 */
function cms_growth_agent_request_topic_gap_article(PDO $pdo, int $clusterId, string $missingTopic): int
{
    $gateResult = cms_growth_agent_seo_g0_gate($pdo, 'topic_gap_article', $missingTopic);

    $inputBrief = [
        'cluster_id' => $clusterId,
        'missing_topic' => $missingTopic,
        'seo_g0_gate' => $gateResult,
    ];

    return cms_growth_agent_log_job(
        $pdo, 'topic_gap_article', 'growth_agent', null, 'manual_action', $inputBrief, null,
        null, null, null, null, '', 'medium'
    );
}

/**
 * Logs a 'content_conflict_proposal' manual_action job for one
 * growth_agent_content_conflicts row, and flips that row's status to
 * 'proposal_requested' so content-conflict-detection.php can grey out the
 * button instead of letting it be queued twice. No AI call here either —
 * approving this job never merges/redirects anything (guardrail:
 * "Recommendation only" — see cms_growth_agent_ensure... note in
 * gsc-api.php for the same principle applied to cannibalization), it only
 * marks the conflict as human-reviewed.
 */
function cms_growth_agent_request_conflict_proposal(PDO $pdo, int $conflictId): int
{
    $inputBrief = [
        'conflict_id' => $conflictId,
    ];

    $jobId = cms_growth_agent_log_job(
        $pdo, 'content_conflict_proposal', 'growth_agent', null, 'manual_action', $inputBrief, null,
        null, null, null, null, '', 'medium'
    );

    if ($jobId > 0) {
        try {
            $pdo->prepare("UPDATE growth_agent_content_conflicts SET status = 'proposal_requested' WHERE id = :id")
                ->execute(['id' => $conflictId]);
        } catch (Throwable $e) {
            // Best-effort — the job itself is already logged either way.
        }
    }

    return $jobId;
}

/**
 * Content Agent Adapter for 'topic_gap_article' — approving this job type
 * creates a draft article from a topic-cluster's missing subtopic, exactly
 * the same "Approve IS the execution step" exception as
 * cms_growth_agent_create_article_draft_from_idea() (gsc_article_idea).
 * Title is the missing topic itself, content is a single placeholder
 * paragraph the operator fleshes out manually — there's no outline to
 * build from here (unlike the GSC article-idea flow), just one topic
 * string. Always produces a 'draft', never 'published'.
 *
 * Never throws — matches this file's own convention.
 */
function cms_growth_agent_create_article_draft_from_topic_gap(PDO $pdo, array $job, ?int $authorId): array
{
    try {
        $inputBrief = json_decode((string) ($job['input_brief'] ?? ''), true);
        $title = is_array($inputBrief) ? trim((string) ($inputBrief['missing_topic'] ?? '')) : '';
        if ($title === '') {
            return ['ok' => false, 'page_id' => 0, 'error' => 'Job input tidak berisi missing_topic yang valid.'];
        }

        require_once __DIR__ . '/functions.php';
        require_once __DIR__ . '/sitemap-service.php';

        $slugBase = cms_slugify($title);
        if ($slugBase === '') {
            $slugBase = 'topic-gap-' . (int) $job['id'];
        }
        $slug = $slugBase;
        $dupCheck = $pdo->prepare('SELECT COUNT(*) FROM pages WHERE slug = :slug');
        for ($suffix = 2; ; $suffix++) {
            $dupCheck->execute(['slug' => $slug]);
            if ((int) $dupCheck->fetchColumn() === 0) {
                break;
            }
            $slug = $slugBase . '-' . $suffix;
        }

        $contentHtml = '<p><em>Draft dibuat otomatis oleh Growth Agent dari topik yang belum tercover di sebuah topic cluster — lengkapi konten di bawah sebelum publish.</em></p><p>[Tulis konten untuk topik ini]</p>';

        $payload = [
            'title'     => $title,
            'slug'      => $slug,
            'content'   => $contentHtml,
            'status'    => 'draft',
            'author_id' => $authorId,
        ];

        $insert = $pdo->prepare(
            'INSERT INTO pages (title, slug, content, status, author_id, created_at, updated_at)
             VALUES (:title, :slug, :content, :status, :author_id, NOW(), NOW())'
        );
        $insert->execute($payload);
        $pageId = (int) $pdo->lastInsertId();

        try {
            cms_sitemap_ensure_schema($pdo);
            cms_sitemap_on_article_save($pdo, [], $payload + [
                'page_id'       => $pageId,
                'noindex'       => 0,
                'canonical_url' => null,
                'published_at'  => null,
            ]);
        } catch (Throwable $e) {
            error_log('[cms_growth_agent_create_article_draft_from_topic_gap] Sitemap upsert failed: ' . $e->getMessage());
        }

        return ['ok' => true, 'page_id' => $pageId, 'error' => ''];
    } catch (Throwable $e) {
        return ['ok' => false, 'page_id' => 0, 'error' => $e->getMessage()];
    }
}

/**
 * Insert one growth_agent_jobs row. Never throws — a logging failure must
 * never break the actual generate response, matching cms_ai_log()'s own
 * philosophy in ai-helpers.php. Returns the new job id, or 0 on failure.
 *
 * $priority (added 27 Jul 2026, ported alongside the GSC/Prioritized
 * Opportunities feature — see gsc-api.php) defaults to 'medium' so EVERY
 * job type always carries a value, never null/skipped — a job spawned from
 * a scored opportunity passes that opportunity's derived priority through;
 * a job with nothing to score (e.g. a plain "Scan for SEO improvements"
 * click) just gets the neutral default. Invalid values silently fall back
 * to 'medium' rather than rejecting the whole log call.
 *
 * @param array<string, mixed>      $inputBrief  JSON-encoded verbatim.
 * @param array<string, mixed>|null $outputData  JSON-encoded verbatim, null if the job failed before producing output.
 */
function cms_growth_agent_log_job(
    PDO $pdo,
    string $jobType,
    string $agentKey,
    ?int $pageId,
    string $status,
    array $inputBrief,
    ?array $outputData,
    ?string $modelUsed,
    ?int $tokensIn,
    ?int $tokensOut,
    ?int $latencyMs,
    string $errorMessage = '',
    string $priority = 'medium'
): int {
    try {
        cms_growth_agent_ensure_schema($pdo);

        $stmt = $pdo->prepare(
            'INSERT INTO growth_agent_jobs
                (job_type, agent_key, page_id, status, priority, input_brief, output_json, model_used, tokens_in, tokens_out, latency_ms, error_message, created_by, created_at, updated_at)
             VALUES
                (:job_type, :agent_key, :page_id, :status, :priority, :input_brief, :output_json, :model_used, :tokens_in, :tokens_out, :latency_ms, :error_message, :created_by, NOW(), NOW())'
        );
        $stmt->execute([
            'job_type'      => $jobType,
            'agent_key'     => $agentKey,
            'page_id'       => $pageId,
            'status'        => $status,
            'priority'      => in_array($priority, ['low', 'medium', 'high'], true) ? $priority : 'medium',
            'input_brief'   => json_encode($inputBrief, JSON_UNESCAPED_UNICODE),
            'output_json'   => $outputData !== null ? json_encode($outputData, JSON_UNESCAPED_UNICODE) : null,
            'model_used'    => $modelUsed,
            'tokens_in'     => $tokensIn,
            'tokens_out'    => $tokensOut,
            'latency_ms'    => $latencyMs,
            'error_message' => $errorMessage !== '' ? $errorMessage : null,
            'created_by'    => (int) ($_SESSION['cms_admin_id'] ?? 0) ?: null,
        ]);

        return (int) $pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('[cms_growth_agent_log_job] Failed logging job: ' . $e->getMessage());
        return 0;
    }
}

/**
 * "Apply SEO Recommendation" — the scan half of the review/apply flow.
 *
 * Triggered by the manual "Scan for SEO improvements" button on
 * pages/growth-agent.php (not automatic/scheduled — see the flow diagram
 * the operator approved: Scan -> Resolve Target -> SEO child action ->
 * Review & Apply). For up to $limit published articles that have never
 * been scanned (or already scanned+actioned) before, asks the seo_agent
 * to review the CURRENT meta_title/meta_description and suggest an
 * improvement, then logs one growth_agent_jobs row per article with
 * status='manual_action' — job_type='seo_recommendation' reuses the exact
 * same jobs table as seo_meta/article_draft/faq, it just has a distinct
 * review UI (pages/seo-recommendation-review.php) instead of the generic
 * Approve/Reject buttons, because "approve" here must actually write the
 * new values into the pages table, not just mark a job succeeded.
 *
 * Never throws — a scan failure must not break the Growth Agent page.
 *
 * @return array{scanned:int, created:int, errors:int}
 */
function cms_growth_agent_scan_seo_recommendations(PDO $pdo, int $limit = 5): array
{
    try {
        cms_growth_agent_ensure_schema($pdo);
        $limit = max(1, min(20, $limit));

        $stmt = $pdo->prepare(
            "SELECT page_id, title, slug, excerpt, content, meta_title, meta_description
               FROM pages
              WHERE status = 'published'
                AND page_id NOT IN (
                    SELECT page_id FROM growth_agent_jobs
                     WHERE job_type = 'seo_recommendation' AND page_id IS NOT NULL
                       AND status IN ('manual_action', 'succeeded')
                )
              ORDER BY updated_at ASC
              LIMIT " . $limit
        );
        $stmt->execute();
        $pages = $stmt->fetchAll();
    } catch (Throwable $e) {
        return ['scanned' => 0, 'created' => 0, 'errors' => 0];
    }

    return cms_growth_agent_run_seo_recommendation_scan($pdo, $pages);
}

/**
 * Shared engine behind cms_growth_agent_scan_seo_recommendations() (date-
 * based candidate selection) and the on-demand "Generate" dispatch from a
 * Prioritized Opportunity row (see gsc-api.php / pages/growth-agent.php's
 * `generate_from_opportunity` action) — both just select candidate pages
 * differently, then hand them to this function to actually call the AI,
 * parse the result, and log one job_type='seo_recommendation' row per
 * page. Extracted (27 Jul 2026, ported alongside GSC/Prioritized
 * Opportunities) so the two candidate-selection strategies never drift out
 * of sync on the generate/parse/log logic itself.
 *
 * @param list<array<string, mixed>> $pages
 * @param array<int, string> $priorityMap page_id => 'low'|'medium'|'high', defaults to 'medium' when a page isn't in the map
 * @return array{scanned:int, created:int, errors:int, job_ids:list<int>}
 */
function cms_growth_agent_run_seo_recommendation_scan(PDO $pdo, array $pages, array $priorityMap = []): array
{
    // job_ids: every job actually logged (success or failure), in order —
    // the on-demand opportunity dispatch (called with a single-page array)
    // needs the new job's id to link gsc_opportunities.linked_job_id. The
    // original bulk/date-based caller ignores this key.
    $stats = ['scanned' => 0, 'created' => 0, 'errors' => 0, 'job_ids' => []];

    if ($pages === []) {
        return $stats;
    }

    try {
        require_once __DIR__ . '/ai-helpers.php';
    } catch (Throwable $e) {
        return $stats;
    }

    $defaultSystemPrompt =
        'You are "Agent SEO" reviewing the EXISTING meta_title and meta_description of a published ' .
        'article on an Indonesian-language sports news site. Given the article title, slug, excerpt, ' .
        'content, and its current meta_title/meta_description, suggest an improved meta_title (max 60 ' .
        'characters) and meta_description (max 155 characters) that is more compelling and better ' .
        'optimized for search, in the same language as the content (default Bahasa Indonesia). If the ' .
        'current metadata is already strong, a small refinement is fine — do not change things just to ' .
        'change them. Respond with ONLY a raw JSON object, no markdown, no code fences, no commentary, ' .
        'in exactly this shape: {"recommended_meta_title": "...", "recommended_meta_description": "..."}';

    $agent = cms_ai_resolve_agent($pdo, 'seo_agent', $defaultSystemPrompt);
    if (!$agent['ok']) {
        return $stats;
    }

    $growthContext = '';
    try {
        require_once dirname(__DIR__, 2) . '/services/GrowthAgentPromptBuilder.php';
        $growthContext = trim((new GrowthAgentPromptBuilder($pdo))->buildContext('seo_agent', 'seo_recommendation'));
    } catch (Throwable $e) {
        // Ignore — scan proceeds on the agent's own system prompt.
    }
    $systemPrompt = $growthContext !== ''
        ? trim($agent['system_prompt'] . "\n\n" . $growthContext)
        : $agent['system_prompt'];

    foreach ($pages as $page) {
        $stats['scanned']++;
        $pageId = (int) $page['page_id'];
        $priority = $priorityMap[$pageId] ?? 'medium';

        $currentMetaTitle = (string) ($page['meta_title'] ?? '');
        $currentMetaDescription = (string) ($page['meta_description'] ?? '');

        $userPrompt = "Title: {$page['title']}\nSlug: {$page['slug']}\nExcerpt: {$page['excerpt']}\n" .
            "Current meta_title: {$currentMetaTitle}\nCurrent meta_description: {$currentMetaDescription}\n" .
            "Content:\n" . mb_substr((string) $page['content'], 0, 6000);

        $inputBrief = [
            'title' => (string) $page['title'],
            'slug' => (string) $page['slug'],
            'current_meta_title' => $currentMetaTitle,
            'current_meta_description' => $currentMetaDescription,
        ];
        if (isset($page['total_impressions'])) {
            $inputBrief['gsc_impressions'] = (int) $page['total_impressions'];
            $inputBrief['gsc_clicks'] = (int) ($page['total_clicks'] ?? 0);
        }

        try {
            $result = cms_ai_call_provider(
                $agent['provider'], $agent['api_key'], $agent['model'],
                $userPrompt, $systemPrompt, max($agent['max_tokens'], 300), $agent['temperature']
            );
        } catch (Throwable $e) {
            $stats['errors']++;
            continue;
        }

        $parsed = $result['success'] ? cms_ai_extract_json($result['text']) : null;
        $retried = false;

        if ($result['success'] && (!is_array($parsed) || !isset($parsed['recommended_meta_title'], $parsed['recommended_meta_description']))) {
            $retried = true;
            $correctivePrompt = $userPrompt .
                "\n\n---\nYour previous reply could not be parsed. Reply with ONLY a raw JSON object, " .
                'no markdown, no code fences, no commentary, in exactly this shape: ' .
                '{"recommended_meta_title": "...", "recommended_meta_description": "..."}';
            $result = cms_ai_call_provider(
                $agent['provider'], $agent['api_key'], $agent['model'],
                $correctivePrompt, $systemPrompt, max($agent['max_tokens'], 300), $agent['temperature']
            );
            $parsed = $result['success'] ? cms_ai_extract_json($result['text']) : null;
        }

        $usage = is_array($result['raw'] ?? null) ? ($result['raw']['usage'] ?? []) : [];
        $tokensIn  = $agent['provider'] === 'openai' ? (int) ($usage['prompt_tokens'] ?? 0) : (int) ($usage['input_tokens'] ?? 0);
        $tokensOut = $agent['provider'] === 'openai' ? (int) ($usage['completion_tokens'] ?? 0) : (int) ($usage['output_tokens'] ?? 0);

        if (!$result['success'] || !is_array($parsed) || !isset($parsed['recommended_meta_title'], $parsed['recommended_meta_description'])) {
            $stats['errors']++;
            $stats['job_ids'][] = cms_growth_agent_log_job(
                $pdo, 'seo_recommendation', 'seo_agent', $pageId, 'failed', $inputBrief, null,
                $agent['model'], $tokensIn ?: null, $tokensOut ?: null, $result['latency_ms'] ?? null,
                ($result['success'] ? 'AI response was not in the expected format' : ('AI request failed: ' . $result['error']))
                    . ($retried ? ' (after 1 retry)' : ''),
                $priority
            );
            continue;
        }

        $recommendedMetaTitle = mb_substr(trim((string) $parsed['recommended_meta_title']), 0, 255);
        $recommendedMetaDescription = mb_substr(trim((string) $parsed['recommended_meta_description']), 0, 255);

        if ($recommendedMetaTitle === '' || $recommendedMetaDescription === '') {
            $stats['errors']++;
            $stats['job_ids'][] = cms_growth_agent_log_job(
                $pdo, 'seo_recommendation', 'seo_agent', $pageId, 'failed', $inputBrief, null,
                $agent['model'], $tokensIn ?: null, $tokensOut ?: null, $result['latency_ms'] ?? null,
                'AI returned an empty recommendation' . ($retried ? ' (after 1 retry)' : ''),
                $priority
            );
            continue;
        }

        $output = [
            'current_meta_title' => $currentMetaTitle,
            'current_meta_description' => $currentMetaDescription,
            'recommended_meta_title' => $recommendedMetaTitle,
            'recommended_meta_description' => $recommendedMetaDescription,
        ];

        $stats['job_ids'][] = cms_growth_agent_log_job(
            $pdo, 'seo_recommendation', 'seo_agent', $pageId, 'manual_action', $inputBrief, $output,
            $agent['model'], $tokensIn ?: null, $tokensOut ?: null, $result['latency_ms'] ?? null,
            '', $priority
        );
        $stats['created']++;
    }

    return $stats;
}

/**
 * Type 2 ("Page-one"/striking-distance category, and — since ROADMAP.md
 * gap #5, 28 Jul 2026 — "Content Decay" too) — existing article, content
 * optimization candidate. Generates ONE job for ONE page, called on-demand
 * when the operator clicks "Generate" on a Prioritized Opportunities row —
 * candidate selection/scoring already happened in
 * cms_gsc_compute_opportunities() (gsc-api.php), this function only does
 * the AI call + log. Does NOT write anywhere itself — produces suggested
 * content additions the human copy/pastes in manually, so it uses the
 * generic Approve/Reject flow on pages/growth-agent.php (status goes
 * straight to succeeded/failed, no 'manual_action' apply step, same as
 * article_draft/faq generation).
 *
 * $page['is_decay'] (+ prev_clicks/cur_clicks/prev_impressions/
 * cur_impressions/pct_change_clicks/comparison_window_days) switches to a
 * distinct system prompt: a declining article needs "what changed / is
 * this stale" framing (refresh existing content, check outdated info),
 * not "hasn't broken into page one yet" framing (add more depth) — same
 * evidence-based reason as gsc-api.php's cms_gsc_build_opportunity_reason()
 * distinguishing the two categories' wording.
 *
 * @param array{page_id:int|string, title:string, slug:string, excerpt:string, content:string, avg_position:float|int, impressions:int, top_queries:string, is_decay?:bool, prev_clicks?:int, cur_clicks?:int, prev_impressions?:int, cur_impressions?:int, pct_change_clicks?:float, comparison_window_days?:int} $page
 * @return array{ok:bool, job_id:int, error:string}
 */
function cms_growth_agent_generate_content_optimization(PDO $pdo, array $page, string $priority = 'medium'): array
{
    try {
        require_once __DIR__ . '/ai-helpers.php';
    } catch (Throwable $e) {
        return ['ok' => false, 'job_id' => 0, 'error' => $e->getMessage()];
    }

    $pageId = (int) $page['page_id'];
    $impressions = (int) ($page['impressions'] ?? 0);
    $avgPosition = (float) ($page['avg_position'] ?? 0);
    $topQueries = (string) ($page['top_queries'] ?? '');
    $isDecay = !empty($page['is_decay']);

    $defaultSystemPrompt = $isDecay
        ? ('You are the Growth Agent content strategist for an Indonesian-language sports news website. ' .
            'You are given an existing PUBLISHED article that USED ' .
            'TO perform well in Google Search but has recently DECLINED — clicks/impressions dropped ' .
            'significantly versus a comparable earlier period. This is NOT the same situation as an article ' .
            'that simply never broke into page one — this one already worked before, so the priority is ' .
            'figuring out what might have gone stale, not just adding more depth. Given the article title, ' .
            'slug, excerpt, content, and the decline evidence (previous vs current clicks/impressions), ' .
            'suggest concrete refresh actions — content/statistics that may be outdated and need updating, ' .
            'whether the article still matches current search intent for its queries, sections that may ' .
            'need rewriting for freshness (e.g. "musim panas 2026" dates, transfer rumors, standings that ' .
            'have since changed). Do not suggest changing the meta title/description (a separate tool ' .
            'handles that). Respond in the same language as the article content (default Bahasa Indonesia). ' .
            'Respond with ONLY a raw JSON object, no markdown, no code fences, no commentary, in exactly ' .
            'this shape: {"suggested_sections": ["...", "..."], "summary": "..."}')
        : ('You are the Growth Agent content strategist for an Indonesian-language sports news website. ' .
            'You are given an existing PUBLISHED article that already ' .
            'ranks close to page one for certain search queries but has not broken into the top 10 yet ' .
            '("striking distance"). Given the article title, slug, excerpt, content, its average ranking ' .
            'position, and the queries it ranks for, suggest concrete content improvements — additional ' .
            'sections or subheadings to add, points to expand, related sub-topics to cover — that would ' .
            'plausibly help it rank higher for those specific queries. Do not suggest changing the meta ' .
            'title/description (a separate tool handles that). Respond in the same language as the article ' .
            'content (default Bahasa Indonesia). Respond with ONLY a raw JSON object, no markdown, no code ' .
            'fences, no commentary, in exactly this shape: {"suggested_sections": ["...", "..."], "summary": "..."}');

    $agent = cms_ai_resolve_agent($pdo, 'growth_agent', $defaultSystemPrompt);
    if (!$agent['ok']) {
        return ['ok' => false, 'job_id' => 0, 'error' => $agent['error']];
    }

    $growthContext = '';
    try {
        require_once dirname(__DIR__, 2) . '/services/GrowthAgentPromptBuilder.php';
        $growthContext = trim((new GrowthAgentPromptBuilder($pdo))->buildContext('growth_agent', 'gsc_content_optimization'));
    } catch (Throwable $e) {
        // Ignore — generation proceeds on the agent's own system prompt.
    }
    $systemPrompt = $growthContext !== ''
        ? trim($agent['system_prompt'] . "\n\n" . $growthContext)
        : $agent['system_prompt'];

    $inputBrief = [
        'title' => (string) $page['title'],
        'slug' => (string) $page['slug'],
        'avg_position' => round($avgPosition, 1),
        'gsc_impressions' => $impressions,
        'top_queries' => $topQueries,
    ];

    if ($isDecay) {
        $prevClicks = (int) ($page['prev_clicks'] ?? 0);
        $curClicks = (int) ($page['cur_clicks'] ?? 0);
        $prevImpressions = (int) ($page['prev_impressions'] ?? 0);
        $curImpressions = (int) ($page['cur_impressions'] ?? 0);
        $declinePct = round(abs((float) ($page['pct_change_clicks'] ?? 0)) * 100, 1);
        $windowDays = (int) ($page['comparison_window_days'] ?? 28);

        $userPrompt = "Title: {$page['title']}\nSlug: {$page['slug']}\nExcerpt: {$page['excerpt']}\n" .
            "Performance decline over the last {$windowDays} days vs the {$windowDays} days before that:\n" .
            "  Clicks: {$prevClicks} -> {$curClicks} ({$declinePct}% decline)\n" .
            "  Impressions: {$prevImpressions} -> {$curImpressions}\n" .
            "Ranks for these queries: {$topQueries}\n" .
            "Content:\n" . mb_substr((string) $page['content'], 0, 6000);

        $inputBrief['decay_prev_clicks'] = $prevClicks;
        $inputBrief['decay_cur_clicks'] = $curClicks;
        $inputBrief['decay_prev_impressions'] = $prevImpressions;
        $inputBrief['decay_cur_impressions'] = $curImpressions;
        $inputBrief['decay_pct_change_clicks'] = round((float) ($page['pct_change_clicks'] ?? 0), 4);
        $inputBrief['comparison_window_days'] = $windowDays;
    } else {
        $userPrompt = "Title: {$page['title']}\nSlug: {$page['slug']}\nExcerpt: {$page['excerpt']}\n" .
            "Average ranking position: " . round($avgPosition, 1) . "\n" .
            "Total impressions (recent window): {$impressions}\n" .
            "Ranks for these queries: {$topQueries}\n" .
            "Content:\n" . mb_substr((string) $page['content'], 0, 6000);
    }

    try {
        $result = cms_ai_call_provider(
            $agent['provider'], $agent['api_key'], $agent['model'],
            $userPrompt, $systemPrompt, max($agent['max_tokens'], 400), $agent['temperature']
        );
    } catch (Throwable $e) {
        return ['ok' => false, 'job_id' => 0, 'error' => $e->getMessage()];
    }

    $parsed = $result['success'] ? cms_ai_extract_json($result['text']) : null;

    if (!$result['success'] || !is_array($parsed) || !isset($parsed['suggested_sections'])) {
        $errorMessage = $result['success'] ? 'AI response was not in the expected format' : ('AI request failed: ' . $result['error']);
        $jobId = cms_growth_agent_log_job(
            $pdo, 'gsc_content_optimization', 'growth_agent', $pageId, 'failed', $inputBrief, null,
            $agent['model'], null, null, $result['latency_ms'] ?? null, $errorMessage, $priority
        );
        return ['ok' => false, 'job_id' => $jobId, 'error' => $errorMessage];
    }

    $jobId = cms_growth_agent_log_job(
        $pdo, 'gsc_content_optimization', 'growth_agent', $pageId, 'succeeded', $inputBrief, $parsed,
        $agent['model'], null, null, $result['latency_ms'] ?? null, '', $priority
    );

    return ['ok' => true, 'job_id' => $jobId, 'error' => ''];
}

/**
 * Type 3 ("No article" category) — new article idea candidate. Generates
 * ONE job for ONE query, called on-demand when the operator clicks
 * "Generate" on a Prioritized Opportunities row — candidate selection/
 * scoring (including the "already suggested this query before" exclusion)
 * already happened in cms_gsc_compute_opportunities() (gsc-api.php), this
 * function only does the AI call + log. Also flows through the generic
 * Approve/Reject queue on pages/growth-agent.php, same as Type 2.
 *
 * @param array{query:string, impressions:int, avg_position:float|int} $queryData
 * @return array{ok:bool, job_id:int, error:string}
 */
function cms_growth_agent_generate_article_idea(PDO $pdo, array $queryData, string $priority = 'medium'): array
{
    try {
        require_once __DIR__ . '/ai-helpers.php';
    } catch (Throwable $e) {
        return ['ok' => false, 'job_id' => 0, 'error' => $e->getMessage()];
    }

    $query = (string) $queryData['query'];
    $impressions = (int) ($queryData['impressions'] ?? 0);
    $avgPosition = (float) ($queryData['avg_position'] ?? 0);

    $defaultSystemPrompt =
        'You are the Growth Agent content strategist for an Indonesian-language sports news website. ' .
        'You are given a search query that gets meaningful search ' .
        'impressions but has NO existing article on the site addressing it. Propose a new article idea: a ' .
        'compelling title, and a short outline (3-6 bullet points) covering what the article should ' .
        'include. Keep it realistic for a sports news site — not a generic listicle. Respond in ' .
        'the same language as the query (default Bahasa Indonesia). If the prompt includes a section listing ' .
        'similar existing articles on this site, propose a DIFFERENT angle or framing, not a near-duplicate ' .
        'of any of them. If the prompt includes a section of recent external headlines, treat them ONLY as ' .
        'inspiration/context for what is currently happening — never copy or closely paraphrase a headline\'s ' .
        'exact wording as your title; write a fully original title in your own words. Respond with ONLY a ' .
        'raw JSON object, no markdown, no code fences, no commentary, in exactly this shape: ' .
        '{"title": "...", "outline": ["...", "..."]}';

    $agent = cms_ai_resolve_agent($pdo, 'growth_agent', $defaultSystemPrompt);
    if (!$agent['ok']) {
        return ['ok' => false, 'job_id' => 0, 'error' => $agent['error']];
    }

    $growthContext = '';
    try {
        require_once dirname(__DIR__, 2) . '/services/GrowthAgentPromptBuilder.php';
        $growthContext = trim((new GrowthAgentPromptBuilder($pdo))->buildContext('growth_agent', 'gsc_article_idea'));
    } catch (Throwable $e) {
        // Ignore — generation proceeds on the agent's own system prompt.
    }
    $systemPrompt = $growthContext !== ''
        ? trim($agent['system_prompt'] . "\n\n" . $growthContext)
        : $agent['system_prompt'];

    $userPromptParts = [
        "Search query: {$query}\n" .
        "Total impressions (recent window): {$impressions}\n" .
        "Average position: " . round($avgPosition, 1),
    ];

    // Proactive collision avoidance (GROWTH_AGENT_V2_PROPOSAL.md § 5, 6 Aug
    // 2026) — reuses the SEO-G0 Gate's own tokenizer/overlap, scoped to
    // THIS query specifically (small top-K), unlike Keyword Expansion's
    // "50 most recent articles" (surveys a whole niche, different purpose).
    // If nothing scores above threshold, no section is added at all — the
    // prompt stays exactly as light as it was before this feature existed.
    $articleIdeaThresholds = [];
    try {
        require_once __DIR__ . '/gsc-api.php';
        $articleIdeaThresholds = cms_gsc_get_opportunity_thresholds($pdo)['article_idea'] ?? [];
    } catch (Throwable $e) {
        // Ignore — falls through to the defaults below.
    }
    $minOverlap = (float) ($articleIdeaThresholds['min_overlap_threshold'] ?? 0.5);
    $contextLimit = max(1, min(30, (int) ($articleIdeaThresholds['context_articles_limit'] ?? 8)));

    $similarArticles = cms_growth_agent_find_similar_published_articles($pdo, $query, $minOverlap, $contextLimit);
    if ($similarArticles !== []) {
        $similarLines = [];
        foreach ($similarArticles as $similar) {
            $desc = $similar['meta_description'] !== '' ? $similar['meta_description'] : $similar['excerpt'];
            $similarLines[] = '- ' . $similar['title'] . ($desc !== '' ? ' | ' . $desc : '');
        }
        $userPromptParts[] =
            "Existing published articles on this site already close to this topic (propose a DIFFERENT angle, not a duplicate):\n"
            . implode("\n", $similarLines);
    }

    // Trending Headlines (GROWTH_AGENT_V2_PROPOSAL.md § 5, 6 Aug 2026) —
    // recent external headlines, already filtered to exclude anything
    // overlapping a published article (see
    // cms_growth_agent_get_trending_headlines_for_prompt()). Inspiration/
    // context only — the system prompt above already instructs the AI to
    // never copy or closely paraphrase these; $usedHeadlines is also kept
    // here so the post-generation title-vs-headline overlap check below
    // can compare the AI's title against the EXACT headlines this specific
    // call showed it, not the whole table.
    $usedHeadlines = cms_growth_agent_get_trending_headlines_for_prompt($pdo);
    if ($usedHeadlines !== []) {
        $headlineLines = [];
        foreach ($usedHeadlines as $headline) {
            $headlineLines[] = '- ' . $headline['headline'];
        }
        $userPromptParts[] =
            "Recent sports news headlines (context/inspiration only — do NOT copy or closely paraphrase any of these as your title, write something fully original in your own words):\n"
            . implode("\n", $headlineLines);
    }

    $userPrompt = implode("\n\n", $userPromptParts);

    // SEO-G0 Gate (GROWTH_AGENT_V2_PROPOSAL.md Fase A item 3) — run against
    // the raw query BEFORE the AI call, since the gate exists to catch
    // overlap in the underlying topic itself, not in whatever title the AI
    // happens to phrase. Advisory only: never affects whether generation
    // proceeds, just rides along in input_brief for the operator's review.
    // UNCHANGED by the collision-avoidance context above — still the exact
    // same second-safety-net check it always was, in case the AI still
    // slips despite now being shown this context.
    $gateResult = cms_growth_agent_seo_g0_gate($pdo, 'gsc_article_idea', $query);

    $inputBrief = [
        'query' => $query,
        'gsc_impressions' => $impressions,
        'avg_position' => round($avgPosition, 1),
        'seo_g0_gate' => $gateResult,
        // Recorded for operator transparency (why did the AI pick this
        // angle?) and reused by the post-generation title-vs-headline
        // overlap check below — NOT a second gate, just an audit trail of
        // what the prompt actually contained.
        'similar_published_articles' => array_map(
            static fn (array $a): array => ['page_id' => $a['page_id'], 'title' => $a['title'], 'coefficient' => round($a['coefficient'], 2)],
            $similarArticles
        ),
        // Same audit-trail reasoning as similar_published_articles above —
        // exactly which trending headlines this call's prompt contained,
        // reused a few lines below for the title-vs-headline overlap check.
        'trending_headlines_used' => $usedHeadlines,
    ];

    try {
        $result = cms_ai_call_provider(
            $agent['provider'], $agent['api_key'], $agent['model'],
            $userPrompt, $systemPrompt, max($agent['max_tokens'], 400), $agent['temperature']
        );
    } catch (Throwable $e) {
        return ['ok' => false, 'job_id' => 0, 'error' => $e->getMessage()];
    }

    $parsed = $result['success'] ? cms_ai_extract_json($result['text']) : null;

    if (!$result['success'] || !is_array($parsed) || !isset($parsed['title'], $parsed['outline'])) {
        $errorMessage = $result['success'] ? 'AI response was not in the expected format' : ('AI request failed: ' . $result['error']);
        $jobId = cms_growth_agent_log_job(
            $pdo, 'gsc_article_idea', 'growth_agent', null, 'failed', $inputBrief, null,
            $agent['model'], null, null, $result['latency_ms'] ?? null, $errorMessage, $priority
        );
        return ['ok' => false, 'job_id' => $jobId, 'error' => $errorMessage];
    }

    // "Aturan keras" — the AI's actual title, checked against the exact
    // headlines this prompt showed it. Advisory only (same posture as
    // seo_g0_gate above) — added to input_brief AFTER the fact rather than
    // computed earlier, since there's nothing to check until the AI has
    // actually returned a title. See cms_growth_agent_check_title_vs_headlines()'s
    // own docblock.
    $inputBrief['title_vs_headline_check'] = cms_growth_agent_check_title_vs_headlines($pdo, (string) $parsed['title'], $usedHeadlines);

    $jobId = cms_growth_agent_log_job(
        $pdo, 'gsc_article_idea', 'growth_agent', null, 'succeeded', $inputBrief, $parsed,
        $agent['model'], null, null, $result['latency_ms'] ?? null, '', $priority
    );

    return ['ok' => true, 'job_id' => $jobId, 'error' => ''];
}

/**
 * Content Agent Adapter for `gsc_article_idea` (GROWTH_AGENT_SEO_ROADMAP.md
 * MVP item #5 / ROADMAP.md gap #1, closed 27 Jul 2026). Turns an *approved*
 * gsc_article_idea job's {"title", "outline"} output into a real draft row
 * in `pages` — before this, Approve only flipped the job to 'succeeded'
 * with no article ever created, leaving the operator to copy-paste the
 * idea into Content Agent (article-generate.php) by hand.
 *
 * For every OTHER job type, "approve" is deliberately NOT "execute" (see
 * the roadmap's own guardrail: "Approve tidak sama dengan Execute"). This
 * job type is the one deliberate exception: there is nothing else an
 * approved article idea can become except a draft, so approve IS the
 * execution step here — but it still only ever produces a `draft`, never
 * `published` ("artikel baru selalu draft" — same guardrail, still
 * enforced). Full-article generation stays a manual, separate step: the
 * operator opens the resulting draft and runs the existing Content Agent
 * (article-generate.php) on it if they want AI to flesh out the body —
 * this function only writes a placeholder outline, not prose.
 *
 * Never throws, matching this file's own convention (e.g.
 * cms_growth_agent_log_job()) — the caller (growth-agent.php's approve
 * handler) is responsible for reflecting a failure as the job's `failed`
 * status + error_message rather than silently leaving it looking approved
 * with no draft to show for it.
 */
function cms_growth_agent_create_article_draft_from_idea(PDO $pdo, array $job, ?int $authorId): array
{
    try {
        $output = json_decode((string) ($job['output_json'] ?? ''), true);
        $title = is_array($output) ? trim((string) ($output['title'] ?? '')) : '';
        if ($title === '') {
            return ['ok' => false, 'page_id' => 0, 'error' => 'Job output tidak berisi title yang valid.'];
        }
        $outline = is_array($output['outline'] ?? null) ? $output['outline'] : [];

        require_once __DIR__ . '/functions.php';
        require_once __DIR__ . '/sitemap-service.php';

        // Same slugify + dedupe-by-suffix approach as pages.php's own
        // duplicate-slug check, just applied proactively here instead of
        // rejecting — there's no form round-trip to show a "slug already
        // in use" error to, so this picks the next free "-2"/"-3" suffix.
        $slugBase = cms_slugify($title);
        if ($slugBase === '') {
            $slugBase = 'ide-artikel-' . (int) $job['id'];
        }
        $slug = $slugBase;
        $dupCheck = $pdo->prepare('SELECT COUNT(*) FROM pages WHERE slug = :slug');
        for ($suffix = 2; ; $suffix++) {
            $dupCheck->execute(['slug' => $slug]);
            if ((int) $dupCheck->fetchColumn() === 0) {
                break;
            }
            $slug = $slugBase . '-' . $suffix;
        }

        // Placeholder outline, not a full article — one <h2> per bullet
        // with a stub <p> underneath, using only the tag set
        // article-generate.php's own Content Agent already restricts
        // itself to (<p>, <h2>, <h3>, <ul>, <li>, <strong>, <em>), so this
        // reads consistently whether or not the operator later re-runs
        // Content Agent on top of it.
        $contentHtml = '<p><em>Draft dibuat otomatis oleh Growth Agent dari ide artikel berbasis GSC search query — lengkapi tiap bagian di bawah sebelum publish.</em></p>';
        foreach ($outline as $point) {
            $point = trim((string) $point);
            if ($point === '') {
                continue;
            }
            $contentHtml .= '<h2>' . htmlspecialchars($point, ENT_QUOTES, 'UTF-8') . '</h2><p>[Tulis konten untuk bagian ini]</p>';
        }

        // category_id/league_id deliberately left null — this job type has
        // no known category association (it comes from a raw search
        // query, not an existing article), and guessing one would violate
        // the roadmap's "Growth Agent hanya mereferensikan evidence yang
        // diberikan" principle. The operator sets these during their
        // required review-before-publish pass.
        $payload = [
            'title'     => $title,
            'slug'      => $slug,
            'content'   => $contentHtml,
            'status'    => 'draft',
            'author_id' => $authorId,
        ];

        $insert = $pdo->prepare(
            'INSERT INTO pages (title, slug, content, status, author_id, created_at, updated_at)
             VALUES (:title, :slug, :content, :status, :author_id, NOW(), NOW())'
        );
        $insert->execute($payload);
        $pageId = (int) $pdo->lastInsertId();

        try {
            cms_sitemap_ensure_schema($pdo);
            cms_sitemap_on_article_save($pdo, [], $payload + [
                'page_id'       => $pageId,
                'noindex'       => 0,
                'canonical_url' => null,
                'published_at'  => null,
            ]);
        } catch (Throwable $e) {
            // Sitemap bookkeeping is best-effort here — a failure in it must
            // never undo an already-created draft (cms_sitemap_upsert()
            // failing shouldn't make the operator lose their new article).
            error_log('[cms_growth_agent_create_article_draft_from_idea] Sitemap upsert failed: ' . $e->getMessage());
        }

        return ['ok' => true, 'page_id' => $pageId, 'error' => ''];
    } catch (Throwable $e) {
        return ['ok' => false, 'page_id' => 0, 'error' => $e->getMessage()];
    }
}

/**
 * Indexing Workflow (Phase 5 roadmap, ROADMAP.md gap #2, closed 27 Jul
 * 2026) — pure pattern-matching against Search Console's own verdict
 * fields, NOT an LLM call. Same "deterministic code, not AI" principle as
 * cms_gsc_compute_opportunities() (gsc-api.php): the checklist only ever
 * lists causes the API's own enums already imply, it never invents a
 * diagnosis. Matches the checklist categories named in
 * GROWTH_AGENT_SEO_ROADMAP.md Phase 5: robots, noindex, canonical,
 * redirect, soft 404, orphan page, thin/duplicate content.
 *
 * @param array{verdict?:string,coverage_state?:string,robots_txt_state?:string,indexing_state?:string,page_fetch_state?:string,google_canonical?:string,user_canonical?:string,sitemap?:?string} $inspection
 * @return list<string>
 */
function cms_growth_agent_build_indexing_checklist(array $inspection): array
{
    $checklist = [];

    if (strtoupper((string) ($inspection['robots_txt_state'] ?? '')) === 'DISALLOWED') {
        $checklist[] = 'Diblokir oleh robots.txt — cek aturan disallow untuk path ini.';
    }

    $indexingState = strtoupper((string) ($inspection['indexing_state'] ?? ''));
    if ($indexingState !== '' && $indexingState !== 'INDEXING_ALLOWED') {
        $checklist[] = 'Halaman diblokir dari indexing (kemungkinan noindex meta tag/header) — indexingState: ' . $inspection['indexing_state'];
    }

    $pageFetchState = strtoupper((string) ($inspection['page_fetch_state'] ?? ''));
    if (str_contains($pageFetchState, 'SOFT_404') || str_contains($pageFetchState, 'NOT_FOUND')) {
        $checklist[] = 'Terindikasi soft 404 / halaman tidak ditemukan saat crawl — cek konten & status HTTP.';
    } elseif ($pageFetchState !== '' && $pageFetchState !== 'SUCCESSFUL') {
        $checklist[] = 'Google gagal fetch halaman ini (pageFetchState: ' . $inspection['page_fetch_state'] . ') — cek redirect/error server.';
    }

    $googleCanonical = trim((string) ($inspection['google_canonical'] ?? ''));
    $userCanonical = trim((string) ($inspection['user_canonical'] ?? ''));
    if ($googleCanonical !== '' && $userCanonical !== '' && rtrim($googleCanonical, '/') !== rtrim($userCanonical, '/')) {
        $checklist[] = 'Google memilih canonical berbeda dari yang di-declare (kemungkinan thin/duplicate content) — Google: ' . $googleCanonical . ', Declared: ' . $userCanonical;
    }

    $coverageState = strtolower((string) ($inspection['coverage_state'] ?? ''));
    if (str_contains($coverageState, 'duplicate')) {
        $checklist[] = 'Coverage state menyebutkan duplicate content: "' . $inspection['coverage_state'] . '".';
    }
    if (str_contains($coverageState, 'redirect')) {
        $checklist[] = 'Coverage state menyebutkan redirect: "' . $inspection['coverage_state'] . '" — pastikan ini redirect yang disengaja.';
    }
    if (str_contains($coverageState, 'crawled') && str_contains($coverageState, 'not indexed')) {
        $checklist[] = 'Sudah di-crawl tapi belum diindeks — kualitas/relevansi konten mungkin jadi faktor.';
    }

    if (empty($inspection['sitemap'])) {
        $checklist[] = 'URL tidak ditemukan lewat sitemap manapun menurut Google — cek apakah halaman ini orphan (tidak ada internal link yang crawlable).';
    }

    if ($checklist === []) {
        $checklist[] = 'Verdict: ' . ($inspection['verdict'] ?? 'UNKNOWN') . ' — tidak ada pola penyebab spesifik yang terdeteksi dari data verdict, cek detail lengkap secara manual.';
    }

    return $checklist;
}

/**
 * Whether an inspection result is worth surfacing as a
 * 'review_indexing_issue' job — anything short of a clean PASS verdict,
 * plus a few coverage_state substrings that can still hide a problem
 * under a technically-passing verdict.
 */
function cms_growth_agent_indexing_issue_needs_review(array $inspection): bool
{
    $verdict = strtoupper((string) ($inspection['verdict'] ?? ''));
    if ($verdict !== '' && $verdict !== 'PASS') {
        return true;
    }

    $coverageState = strtolower((string) ($inspection['coverage_state'] ?? ''));
    foreach (['duplicate', 'not indexed', 'redirect', 'not found', 'excluded'] as $needle) {
        if (str_contains($coverageState, $needle)) {
            return true;
        }
    }

    return false;
}

/** Deterministic priority from verdict severity — FAIL is worse than NEUTRAL/PARTIAL. */
function cms_growth_agent_indexing_issue_priority(array $inspection): string
{
    $verdict = strtoupper((string) ($inspection['verdict'] ?? ''));
    if ($verdict === 'FAIL') {
        return 'high';
    }
    if ($verdict === 'NEUTRAL' || $verdict === 'PARTIAL') {
        return 'medium';
    }

    return 'low';
}

/**
 * Logs a deterministic 'review_indexing_issue' job for one page, given an
 * already-fetched inspection result (cms_gsc_inspect_url()'s $data).
 * agent_key is 'gsc_indexing' rather than a real ai_agent_settings key —
 * that column is display-only in this codebase (rendered as plain
 * <code>text</code> on growth-agent.php, never looked up), and there is no
 * AI call behind this job type to attribute to a real agent.
 *
 * output_json is ONLY the checklist + raw verdict fields — never a
 * suggestion to rewrite or republish the article. Per
 * GROWTH_AGENT_SEO_ROADMAP.md Phase 5's own guardrail, deciding what (if
 * anything) to fix is entirely the operator's manual call.
 *
 * Dedup: skips creating a new job if there's already an unresolved
 * (status='manual_action') review_indexing_issue job for this exact
 * page_id — otherwise every re-inspection of a still-broken URL would
 * spam a fresh job every time the batch button is clicked. Returns the
 * existing job's id in that case instead of 0, so callers can still treat
 * it as "there is a job to look at" without double-counting it as new.
 *
 * Never throws.
 */
function cms_growth_agent_log_indexing_issue(PDO $pdo, int $pageId, string $url, array $inspection): int
{
    try {
        $existing = $pdo->prepare(
            "SELECT id FROM growth_agent_jobs WHERE job_type = 'review_indexing_issue' AND page_id = :page_id AND status = 'manual_action' LIMIT 1"
        );
        $existing->execute(['page_id' => $pageId]);
        $existingId = $existing->fetchColumn();
        if ($existingId !== false) {
            return (int) $existingId;
        }

        $checklist = cms_growth_agent_build_indexing_checklist($inspection);
        $priority = cms_growth_agent_indexing_issue_priority($inspection);

        $inputBrief = [
            'url' => $url,
            'verdict' => (string) ($inspection['verdict'] ?? ''),
            'coverage_state' => (string) ($inspection['coverage_state'] ?? ''),
        ];
        $output = ['checklist' => $checklist, 'inspection' => $inspection];

        return cms_growth_agent_log_job(
            $pdo, 'review_indexing_issue', 'gsc_indexing', $pageId, 'manual_action', $inputBrief, $output,
            null, null, null, null, '', $priority
        );
    } catch (Throwable $e) {
        error_log('[cms_growth_agent_log_indexing_issue] Failed: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Logs a deterministic 'cannibalization_review' job — surfaces one
 * cannibalized query + its competing pages/shares for an operator to
 * review. ROADMAP.md gap #5, closed 28 Jul 2026. No AI involved anywhere
 * in this function: deciding whether to differentiate intent,
 * consolidate content, or pick a pillar page is a judgment call this
 * codebase deliberately never routes to AI (see
 * cms_gsc_ensure_cannibalization_action()'s own note in gsc-api.php).
 * agent_key is 'manual_review' — same "display-only, not a real
 * ai_agent_settings key" convention as review_indexing_issue's
 * 'gsc_indexing'.
 *
 * page_id is intentionally NULL — a cannibalization opportunity spans
 * 2+ pages, so there's no single page to attribute the job to; the full
 * list lives in output_json instead.
 *
 * Dedup: growth_agent_jobs has no dedicated "query" column to index on
 * (unlike cms_growth_agent_log_indexing_issue()'s page_id), so this reads
 * the small set of currently-unresolved cannibalization_review jobs and
 * compares input_brief's decoded 'query' field in PHP rather than
 * string-matching raw JSON in SQL — correct regardless of how the JSON
 * happens to be escaped, and cheap since there should only ever be a
 * handful of open cannibalization reviews at a time.
 *
 * Never throws.
 *
 * @param list<array{page_id:int,title:string,clicks:int,impressions:int,share:float}> $competingPages
 */
function cms_growth_agent_log_cannibalization_review(PDO $pdo, string $queryText, array $competingPages, int $totalClicks, int $totalImpressions, string $priority = 'medium'): int
{
    try {
        $existingStmt = $pdo->query(
            "SELECT id, input_brief FROM growth_agent_jobs WHERE job_type = 'cannibalization_review' AND status = 'manual_action'"
        );
        foreach ($existingStmt->fetchAll() as $existingRow) {
            $existingBrief = json_decode((string) ($existingRow['input_brief'] ?? ''), true);
            if (is_array($existingBrief) && ($existingBrief['query'] ?? null) === $queryText) {
                return (int) $existingRow['id'];
            }
        }

        $inputBrief = [
            'query' => $queryText,
            'total_clicks' => $totalClicks,
            'total_impressions' => $totalImpressions,
            'page_count' => count($competingPages),
        ];
        $output = ['competing_pages' => $competingPages];

        return cms_growth_agent_log_job(
            $pdo, 'cannibalization_review', 'manual_review', null, 'manual_action', $inputBrief, $output,
            null, null, null, null, '', $priority
        );
    } catch (Throwable $e) {
        error_log('[cms_growth_agent_log_cannibalization_review] Failed: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Manual batch trigger — "Inspect prioritas" button on growth-agent.php.
 * No cron in this codebase (see cms_growth_agent_cleanup_old_jobs()'s own
 * note on that), so URL Inspection only ever runs when an operator clicks
 * a button — either this batch entry point or a per-article single
 * inspect action on growth-agent.php.
 *
 * Candidate selection (both sources feed the same $limit, combined and
 * deduped — matches the "belum pernah diinspeksi ATAU terkait opportunity
 * open+high" wording in ROADMAP.md gap #2):
 *   - published pages linked to an OPEN, HIGH-priority gsc_opportunities
 *     row (already flagged as worth attention elsewhere in Growth Agent);
 *   - published pages never inspected yet, or with the oldest
 *     inspected_at (round-robin coverage over time so nothing gets stuck
 *     unchecked forever).
 *
 * Never throws — one URL's inspection failing (bad token, network) does
 * not stop the rest of the batch; cms_gsc_inspect_url() itself never
 * throws either, so this is mostly defensive.
 *
 * @return array{inspected:int, issues_found:int, errors:int}
 */
function cms_growth_agent_inspect_priority_urls(PDO $pdo, int $limit = 10): array
{
    $stats = ['inspected' => 0, 'issues_found' => 0, 'errors' => 0];
    $limit = max(1, min(50, $limit));

    try {
        require_once __DIR__ . '/gsc-api.php';
        cms_gsc_ensure_schema($pdo);

        $pageIds = [];

        $oppStmt = $pdo->query(
            "SELECT DISTINCT o.matched_page_id AS page_id
               FROM gsc_opportunities o
               JOIN pages p ON p.page_id = o.matched_page_id
              WHERE o.status = 'open' AND o.priority = 'high' AND p.status = 'published'
              ORDER BY o.computed_at DESC
              LIMIT " . $limit
        );
        foreach ($oppStmt->fetchAll() as $row) {
            $pageIds[] = (int) $row['page_id'];
        }

        if (count($pageIds) < $limit) {
            $remaining = $limit - count($pageIds);
            $placeholders = $pageIds !== [] ? implode(',', array_fill(0, count($pageIds), '?')) : null;
            $sql = "SELECT p.page_id
                      FROM pages p
                      LEFT JOIN gsc_url_inspections i ON i.page_id = p.page_id
                     WHERE p.status = 'published'"
                 . ($placeholders !== null ? " AND p.page_id NOT IN ({$placeholders})" : '')
                 . ' ORDER BY (i.inspected_at IS NULL) DESC, i.inspected_at ASC
                     LIMIT ' . $remaining;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($pageIds);
            foreach ($stmt->fetchAll() as $row) {
                $pageIds[] = (int) $row['page_id'];
            }
        }

        $pageIds = array_slice(array_values(array_unique($pageIds)), 0, $limit);
    } catch (Throwable $e) {
        return $stats;
    }

    if ($pageIds === []) {
        return $stats;
    }

    try {
        require_once __DIR__ . '/sitemap-service.php';
    } catch (Throwable $e) {
        return $stats;
    }

    foreach ($pageIds as $pageId) {
        try {
            $pageStmt = $pdo->prepare('SELECT page_id, slug, canonical_url FROM pages WHERE page_id = :id LIMIT 1');
            $pageStmt->execute(['id' => $pageId]);
            $page = $pageStmt->fetch();
            if (!$page) {
                continue;
            }
            $canonical = trim((string) ($page['canonical_url'] ?? ''));
            $url = $canonical !== '' ? $canonical : cms_sitemap_absolute_url(cms_sitemap_path_for('article', (string) $page['slug']));

            $result = cms_gsc_inspect_url($pdo, $url, $pageId);
            $stats['inspected']++;
            if (!$result['ok']) {
                $stats['errors']++;
                continue;
            }

            if (cms_growth_agent_indexing_issue_needs_review($result['data'])) {
                $jobId = cms_growth_agent_log_indexing_issue($pdo, $pageId, $url, $result['data']);
                if ($jobId > 0) {
                    $stats['issues_found']++;
                }
            }
        } catch (Throwable $e) {
            $stats['errors']++;
        }
    }

    return $stats;
}

/**
 * Agent Memory (ROADMAP.md gap #3, GROWTH_AGENT_SEO_ROADMAP.md § Growth
 * memory, closed 28 Jul 2026) — deterministic (NOT AI) detection of
 * historical patterns from gsc_query_data, upserted into
 * growth_agent_memory as ADVISORY context only. Per the roadmap's own
 * explicit guardrail — "memory hanya menjadi advisory context bagi Growth
 * Agent; memory tidak boleh membuat, approve, atau execute action
 * sendiri" — this function (and everything downstream of it,
 * GrowthAgentPromptBuilder::buildMemoryContext()) never creates a
 * growth_agent_jobs row, never touches gsc_opportunities, and never
 * writes to `pages`. The only table this function writes to is
 * growth_agent_memory itself.
 *
 * Two pattern types (both use cms_gsc_get_memory_thresholds() — no new
 * thresholds invented for this):
 *   - winning_pattern (scope 'page' OR 'query'): >= min_distinct_weeks
 *     distinct ISO weeks with avg CTR >= winning_ctr_threshold AND avg
 *     position <= winning_position_threshold AND total impressions >=
 *     min_impressions. 'page' scope only considers rows already matched
 *     to a published article; 'query' scope aggregates a query across ALL
 *     its rows regardless of match, so a query can independently be both
 *     a winning_pattern AND a content_gap — that's not a contradiction,
 *     it means the topic performs well in search even without a
 *     dedicated page yet.
 *   - content_gap (scope 'query' only): a query recurring across
 *     >= min_distinct_weeks distinct weeks with total impressions >=
 *     min_impressions but NEVER matched to any page. Deliberately
 *     different from gsc_opportunities' one-off "No article" category
 *     (cms_gsc_compute_opportunities(), reacts to the current fetch
 *     window only) — this only fires once the SAME gap has been observed
 *     persistently over multiple detection runs.
 *
 * Promotion (ON DUPLICATE KEY UPDATE on dedupe_key, same md5-hash upsert
 * convention as gsc_opportunities):
 *   - not seen before → inserted as 'pending_review'.
 *   - already 'pending_review' → promoted to 'active' (redetecting the
 *     same dedupe_key means it's still consistent, since this whole
 *     function only ever runs once per detection_interval_days via
 *     cms_growth_agent_detect_memory_if_stale()).
 *   - already 'active' → stays 'active', evidence/last_confirmed_at refreshed.
 *   - already 'stale' → drops back to 'pending_review' rather than
 *     jumping straight to 'active' — a lapsed pattern has to re-earn
 *     confirmation, not resume where it left off.
 *
 * Housekeeping (runs every time this function runs, not a separate lazy
 * gate): 'active' rows not reconfirmed within active_stale_days, or
 * 'pending_review' rows not reconfirmed within pending_review_stale_days,
 * flip to 'stale' — never deleted, same "keep as history" convention as
 * closed_as_legacy elsewhere in this file.
 *
 * Never throws.
 *
 * @return array{winning_patterns:int, content_gaps:int, staled:int}
 */
function cms_growth_agent_detect_memory_patterns(PDO $pdo): array
{
    $stats = ['winning_patterns' => 0, 'content_gaps' => 0, 'staled' => 0];

    try {
        cms_growth_agent_ensure_schema($pdo);
        require_once __DIR__ . '/gsc-api.php';
        $thresholds = cms_gsc_get_memory_thresholds($pdo);
    } catch (Throwable $e) {
        error_log('[cms_growth_agent_detect_memory_patterns] Setup failed: ' . $e->getMessage());
        return $stats;
    }

    $minWeeks = max(1, (int) $thresholds['min_distinct_weeks']);
    $minImpressions = max(0, (int) $thresholds['min_impressions']);
    $winningCtr = (float) $thresholds['winning_ctr_threshold'];
    $winningPosition = (float) $thresholds['winning_position_threshold'];

    $upsert = $pdo->prepare(
        "INSERT INTO growth_agent_memory
            (pattern_type, scope_type, matched_page_id, query_text, status, evidence_json, distinct_weeks_seen,
             dedupe_key, first_detected_at, last_confirmed_at, created_at, updated_at)
         VALUES
            (:pattern_type, :scope_type, :matched_page_id, :query_text, 'pending_review', :evidence_json, :distinct_weeks_seen,
             :dedupe_key, NOW(), NOW(), NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            evidence_json = VALUES(evidence_json),
            distinct_weeks_seen = VALUES(distinct_weeks_seen),
            last_confirmed_at = NOW(),
            status = CASE
                        WHEN status = 'pending_review' THEN 'active'
                        WHEN status = 'stale' THEN 'pending_review'
                        ELSE status
                     END,
            updated_at = NOW()"
    );

    // ── winning_pattern / scope=page ──────────────────────────────────
    try {
        $pageStmt = $pdo->prepare(
            "SELECT g.matched_page_id AS page_id,
                    COUNT(DISTINCT YEARWEEK(g.data_date, 3)) AS distinct_weeks,
                    SUM(g.impressions) AS total_impressions,
                    SUM(g.clicks) AS total_clicks,
                    AVG(g.position) AS avg_position
               FROM gsc_query_data g
               INNER JOIN pages p ON p.page_id = g.matched_page_id
              WHERE g.matched_page_id IS NOT NULL AND p.status = 'published'
              GROUP BY g.matched_page_id
             HAVING distinct_weeks >= :min_weeks AND total_impressions >= :min_impressions"
        );
        $pageStmt->execute(['min_weeks' => $minWeeks, 'min_impressions' => $minImpressions]);
        $pageRows = $pageStmt->fetchAll();
    } catch (Throwable $e) {
        $pageRows = [];
    }

    foreach ($pageRows as $row) {
        $impressions = (int) $row['total_impressions'];
        $ctr = $impressions > 0 ? ((int) $row['total_clicks'] / $impressions) : 0.0;
        $position = (float) $row['avg_position'];
        if ($ctr < $winningCtr || $position > $winningPosition) {
            continue;
        }

        $pageId = (int) $row['page_id'];
        $weeksSeen = (int) $row['distinct_weeks'];
        $evidence = [
            'avg_ctr' => round($ctr, 4),
            'avg_position' => round($position, 1),
            'total_impressions' => $impressions,
            'distinct_weeks_seen' => $weeksSeen,
        ];

        $upsert->execute([
            'pattern_type' => 'winning_pattern',
            'scope_type' => 'page',
            'matched_page_id' => $pageId,
            'query_text' => null,
            'evidence_json' => json_encode($evidence, JSON_UNESCAPED_UNICODE),
            'distinct_weeks_seen' => $weeksSeen,
            'dedupe_key' => md5('winning_pattern|page|' . $pageId),
        ]);
        $stats['winning_patterns']++;
    }

    // ── winning_pattern / scope=query ──────────────────────────────────
    try {
        $queryStmt = $pdo->prepare(
            "SELECT query,
                    COUNT(DISTINCT YEARWEEK(data_date, 3)) AS distinct_weeks,
                    SUM(impressions) AS total_impressions,
                    SUM(clicks) AS total_clicks,
                    AVG(position) AS avg_position
               FROM gsc_query_data
              GROUP BY query
             HAVING distinct_weeks >= :min_weeks AND total_impressions >= :min_impressions"
        );
        $queryStmt->execute(['min_weeks' => $minWeeks, 'min_impressions' => $minImpressions]);
        $queryRows = $queryStmt->fetchAll();
    } catch (Throwable $e) {
        $queryRows = [];
    }

    foreach ($queryRows as $row) {
        $impressions = (int) $row['total_impressions'];
        $ctr = $impressions > 0 ? ((int) $row['total_clicks'] / $impressions) : 0.0;
        $position = (float) $row['avg_position'];
        if ($ctr < $winningCtr || $position > $winningPosition) {
            continue;
        }

        $queryText = mb_substr((string) $row['query'], 0, 255);
        $weeksSeen = (int) $row['distinct_weeks'];
        $evidence = [
            'avg_ctr' => round($ctr, 4),
            'avg_position' => round($position, 1),
            'total_impressions' => $impressions,
            'distinct_weeks_seen' => $weeksSeen,
        ];

        $upsert->execute([
            'pattern_type' => 'winning_pattern',
            'scope_type' => 'query',
            'matched_page_id' => null,
            'query_text' => $queryText,
            'evidence_json' => json_encode($evidence, JSON_UNESCAPED_UNICODE),
            'distinct_weeks_seen' => $weeksSeen,
            'dedupe_key' => md5('winning_pattern|query|' . $queryText),
        ]);
        $stats['winning_patterns']++;
    }

    // ── content_gap / scope=query (persistent, unlike gsc_opportunities'
    // one-off "No article" category) ───────────────────────────────────
    try {
        $gapStmt = $pdo->prepare(
            "SELECT query,
                    COUNT(DISTINCT YEARWEEK(data_date, 3)) AS distinct_weeks,
                    SUM(impressions) AS total_impressions
               FROM gsc_query_data
              GROUP BY query
             HAVING SUM(CASE WHEN matched_page_id IS NOT NULL THEN 1 ELSE 0 END) = 0
                AND distinct_weeks >= :min_weeks AND total_impressions >= :min_impressions"
        );
        $gapStmt->execute(['min_weeks' => $minWeeks, 'min_impressions' => $minImpressions]);
        $gapRows = $gapStmt->fetchAll();
    } catch (Throwable $e) {
        $gapRows = [];
    }

    foreach ($gapRows as $row) {
        $queryText = mb_substr((string) $row['query'], 0, 255);
        $weeksSeen = (int) $row['distinct_weeks'];
        $evidence = [
            'total_impressions' => (int) $row['total_impressions'],
            'distinct_weeks_seen' => $weeksSeen,
        ];

        $upsert->execute([
            'pattern_type' => 'content_gap',
            'scope_type' => 'query',
            'matched_page_id' => null,
            'query_text' => $queryText,
            'evidence_json' => json_encode($evidence, JSON_UNESCAPED_UNICODE),
            'distinct_weeks_seen' => $weeksSeen,
            'dedupe_key' => md5('content_gap|query|' . $queryText),
        ]);
        $stats['content_gaps']++;
    }

    // ── Housekeeping: age out unconfirmed rows to 'stale' ──────────────
    try {
        $staleActive = $pdo->prepare(
            "UPDATE growth_agent_memory
                SET status = 'stale', updated_at = NOW()
              WHERE status = 'active' AND last_confirmed_at < (NOW() - INTERVAL :days DAY)"
        );
        $staleActive->execute(['days' => max(1, (int) $thresholds['active_stale_days'])]);
        $stats['staled'] += $staleActive->rowCount();

        $stalePending = $pdo->prepare(
            "UPDATE growth_agent_memory
                SET status = 'stale', updated_at = NOW()
              WHERE status = 'pending_review' AND last_confirmed_at < (NOW() - INTERVAL :days DAY)"
        );
        $stalePending->execute(['days' => max(1, (int) $thresholds['pending_review_stale_days'])]);
        $stats['staled'] += $stalePending->rowCount();
    } catch (Throwable $e) {
        // Non-fatal — worst case some stale rows linger as active/pending a bit longer.
    }

    return $stats;
}

/**
 * Lazy trigger for cms_growth_agent_detect_memory_patterns() — no cron in
 * this codebase (see cms_growth_agent_cleanup_old_jobs()'s own note on
 * that), mirrors cms_gsc_fetch_if_stale()'s "check last-run timestamp, run
 * only if past the configured interval" pattern exactly, just keyed off
 * gsc_settings.last_memory_detection_at / memory_thresholds_json's
 * detection_interval_days instead of last_fetch_at/the fetch interval.
 * Called from growth-agent.php's page load, right alongside the existing
 * cms_gsc_fetch_if_stale() call.
 *
 * Never throws.
 */
function cms_growth_agent_detect_memory_if_stale(PDO $pdo): void
{
    try {
        require_once __DIR__ . '/gsc-api.php';
        $settings = cms_gsc_get_settings($pdo);
        $thresholds = cms_gsc_get_memory_thresholds($pdo);
        $intervalDays = max(1, (int) $thresholds['detection_interval_days']);

        $lastRun = $settings['last_memory_detection_at'] ?? null;
        $isStale = $lastRun === null
            || (time() - strtotime((string) $lastRun)) >= ($intervalDays * 86400);

        if (!$isStale) {
            return;
        }

        cms_growth_agent_detect_memory_patterns($pdo);

        $pdo->prepare('UPDATE gsc_settings SET last_memory_detection_at = NOW() ORDER BY id ASC LIMIT 1')->execute();
    } catch (Throwable $e) {
        // A lazy background detection must never break the page it's attached to.
    }
}

/**
 * The one manual action Agent Memory has — deliberately NOT "approve" or
 * "execute" (memory is not an action queue, see the guardrail on
 * cms_growth_agent_detect_memory_patterns()). Lets an operator turn off a
 * pattern they judge no longer relevant, same semantics as
 * 'closed_as_legacy' elsewhere: a manual override, not a judgment that the
 * detection was wrong. Never deletes the row (kept as history).
 *
 * Never throws. Returns true if a row was actually updated.
 */
function cms_growth_agent_mark_memory_stale(PDO $pdo, int $memoryId): bool
{
    try {
        $stmt = $pdo->prepare("UPDATE growth_agent_memory SET status = 'stale', updated_at = NOW() WHERE id = :id");
        $stmt->execute(['id' => $memoryId]);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        error_log('[cms_growth_agent_mark_memory_stale] Failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Feedback Loop (ROADMAP.md gap #4, GROWTH_AGENT_SEO_ROADMAP.md § Phase 6
 * "Feedback and Measurement", closed 28 Jul 2026) — daily per-page snapshot
 * of gsc_query_data into growth_agent_performance. This is what turns the
 * schema-only table (noted in this file's own top docblock as "nothing
 * ingests into it yet") into a durable historical record: unlike
 * gsc_query_data, which cms_gsc_fetch_and_cache() prunes to
 * fetch_window_days, growth_agent_performance is never pruned — so a
 * before/after comparison spanning further back than the current GSC
 * retention window still has data here, as long as a snapshot ran while
 * gsc_query_data still held it.
 *
 * avg_ranking_position is impressions-weighted (SUM(position*impressions)
 * / SUM(impressions)) when combining multiple queries for the same
 * page+day — a plain AVG() would let a low-impression query's position
 * skew the page's real, traffic-weighted ranking.
 *
 * Upsert by (page_id, metric_date) per the table's own UNIQUE key —
 * ON DUPLICATE KEY UPDATE, same convention as gsc_opportunities/
 * growth_agent_memory. pageviews is intentionally left untouched (stays
 * whatever it already was, default 0) — this repo has no GA/analytics
 * integration, so nothing populates that column; it exists for a future
 * integration this function doesn't attempt.
 *
 * Never throws.
 *
 * @return array{rows_upserted:int}
 */
function cms_growth_agent_snapshot_performance(PDO $pdo): array
{
    $stats = ['rows_upserted' => 0];

    try {
        cms_growth_agent_ensure_schema($pdo);

        $rows = $pdo->query(
            "SELECT matched_page_id AS page_id, data_date AS metric_date,
                    SUM(clicks) AS total_clicks, SUM(impressions) AS total_impressions,
                    SUM(position * impressions) AS weighted_position_sum
               FROM gsc_query_data
              WHERE matched_page_id IS NOT NULL
              GROUP BY matched_page_id, data_date"
        )->fetchAll();
    } catch (Throwable $e) {
        error_log('[cms_growth_agent_snapshot_performance] Query failed: ' . $e->getMessage());
        return $stats;
    }

    if ($rows === []) {
        return $stats;
    }

    try {
        $upsert = $pdo->prepare(
            'INSERT INTO growth_agent_performance (page_id, metric_date, impressions, clicks, ctr, avg_ranking_position, created_at)
             VALUES (:page_id, :metric_date, :impressions, :clicks, :ctr, :avg_ranking_position, NOW())
             ON DUPLICATE KEY UPDATE
                impressions = VALUES(impressions),
                clicks = VALUES(clicks),
                ctr = VALUES(ctr),
                avg_ranking_position = VALUES(avg_ranking_position)'
        );

        foreach ($rows as $row) {
            $impressions = (int) $row['total_impressions'];
            $clicks = (int) $row['total_clicks'];
            $ctr = $impressions > 0 ? round($clicks / $impressions, 4) : 0.0;
            $avgPosition = $impressions > 0 ? round(((float) $row['weighted_position_sum']) / $impressions, 2) : null;

            $upsert->execute([
                'page_id' => (int) $row['page_id'],
                'metric_date' => (string) $row['metric_date'],
                'impressions' => $impressions,
                'clicks' => $clicks,
                'ctr' => $ctr,
                'avg_ranking_position' => $avgPosition,
            ]);
            $stats['rows_upserted']++;
        }
    } catch (Throwable $e) {
        error_log('[cms_growth_agent_snapshot_performance] Upsert failed: ' . $e->getMessage());
    }

    return $stats;
}

/**
 * Lazy trigger for cms_growth_agent_snapshot_performance() — no cron in
 * this codebase, mirrors cms_gsc_fetch_if_stale()/
 * cms_growth_agent_detect_memory_if_stale()'s "check last-run timestamp,
 * run only if past the configured interval" pattern exactly, keyed off
 * gsc_settings.last_performance_snapshot_at. Called from growth-agent.php's
 * page load. Never throws.
 */
function cms_growth_agent_snapshot_performance_if_stale(PDO $pdo, int $maxAgeHours = 24): void
{
    try {
        require_once __DIR__ . '/gsc-api.php';
        $settings = cms_gsc_get_settings($pdo);

        $lastRun = $settings['last_performance_snapshot_at'] ?? null;
        $isStale = $lastRun === null
            || (time() - strtotime((string) $lastRun)) >= (max(1, $maxAgeHours) * 3600);

        if (!$isStale) {
            return;
        }

        cms_growth_agent_snapshot_performance($pdo);

        $pdo->prepare('UPDATE gsc_settings SET last_performance_snapshot_at = NOW() ORDER BY id ASC LIMIT 1')->execute();
    } catch (Throwable $e) {
        // A lazy background snapshot must never break the page it's attached to.
    }
}

/**
 * Aggregates page performance over one date range (inclusive), preferring
 * growth_agent_performance (the durable snapshot) and falling back to
 * gsc_query_data directly if the snapshot doesn't have enough distinct
 * days for this page/range yet (e.g. snapshotting hasn't run recently, or
 * this range predates when snapshotting started) — gsc_query_data is the
 * same underlying source, just not yet materialized/durable.
 *
 * @return array{start:string,end:string,distinct_days:int,clicks:int,impressions:int,ctr:float,avg_position:?float,source:string}
 */
function cms_growth_agent_aggregate_page_window(PDO $pdo, int $pageId, string $start, string $end): array
{
    $empty = static fn (string $source): array => [
        'start' => $start, 'end' => $end, 'distinct_days' => 0,
        'clicks' => 0, 'impressions' => 0, 'ctr' => 0.0, 'avg_position' => null, 'source' => $source,
    ];

    try {
        $snapRow = $pdo->prepare(
            'SELECT COUNT(*) AS distinct_days, SUM(clicks) AS total_clicks, SUM(impressions) AS total_impressions,
                    SUM(avg_ranking_position * impressions) AS weighted_position_sum
               FROM growth_agent_performance
              WHERE page_id = :page_id AND metric_date BETWEEN :start AND :end'
        );
        $snapRow->execute(['page_id' => $pageId, 'start' => $start, 'end' => $end]);
        $snap = $snapRow->fetch();
    } catch (Throwable $e) {
        $snap = false;
    }

    $snapDays = $snap !== false ? (int) ($snap['distinct_days'] ?? 0) : 0;

    try {
        $rawRow = $pdo->prepare(
            "SELECT COUNT(DISTINCT data_date) AS distinct_days, SUM(clicks) AS total_clicks, SUM(impressions) AS total_impressions,
                    SUM(position * impressions) AS weighted_position_sum
               FROM gsc_query_data
              WHERE matched_page_id = :page_id AND data_date BETWEEN :start AND :end"
        );
        $rawRow->execute(['page_id' => $pageId, 'start' => $start, 'end' => $end]);
        $raw = $rawRow->fetch();
    } catch (Throwable $e) {
        $raw = false;
    }

    $rawDays = $raw !== false ? (int) ($raw['distinct_days'] ?? 0) : 0;

    // Whichever source has more day-coverage for this exact range wins —
    // the snapshot is preferred as the durable record, but a fresher/more
    // complete raw window (e.g. snapshot lag) should not be discarded.
    $row = $snapDays >= $rawDays ? $snap : $raw;
    $days = max($snapDays, $rawDays);
    $source = $snapDays >= $rawDays ? 'growth_agent_performance' : 'gsc_query_data';

    if ($row === false || $days === 0) {
        return $empty($source);
    }

    $impressions = (int) ($row['total_impressions'] ?? 0);
    $clicks = (int) ($row['total_clicks'] ?? 0);
    $ctr = $impressions > 0 ? round($clicks / $impressions, 4) : 0.0;
    $avgPosition = $impressions > 0 ? round(((float) ($row['weighted_position_sum'] ?? 0)) / $impressions, 2) : null;

    return [
        'start' => $start, 'end' => $end, 'distinct_days' => $days,
        'clicks' => $clicks, 'impressions' => $impressions, 'ctr' => $ctr,
        'avg_position' => $avgPosition, 'source' => $source,
    ];
}

/**
 * Before/after comparison for one page around one change date — the core
 * of the Feedback Loop. Deliberately READ-ONLY reporting: this function
 * (and everything that calls it) never creates, approves, or executes a
 * growth_agent_jobs row, never writes to gsc_opportunities, never touches
 * `pages` — same "advisory/reporting only, not an action queue" posture
 * as Agent Memory and Indexing Workflow before it.
 *
 * "Before" is the $windowDays ending the day before $changeDate; "after"
 * starts ON $changeDate and runs $windowDays forward — matches "ukur
 * per page/query... bandingkan window yang setara" from
 * GROWTH_AGENT_SEO_ROADMAP.md § Phase 6.
 *
 * Guardrail: if either side has fewer than $minDays (default 7) distinct
 * days of data, returns status='insufficient_data' with NO delta computed
 * — per the roadmap's own explicit rule, a thin window must never be
 * dressed up as a real before/after result. This also means: actions
 * approved before this feature existed have no valid "before" baseline captured
 * at the time and may legitimately never clear this bar — that's a real
 * limitation of retroactive measurement, not a bug to work around.
 *
 * Never throws.
 *
 * @return array{status:string,page_id:int,change_date:string,window_days:int,before:array,after:array,delta?:array,error?:string}
 */
function cms_growth_agent_compare_before_after(PDO $pdo, int $pageId, string $changeDate, int $windowDays = 28, int $minDays = 7): array
{
    $windowDays = max(1, min(180, $windowDays));
    $minDays = max(1, min($windowDays, $minDays));

    $changeTs = strtotime($changeDate);
    if ($changeTs === false) {
        return [
            'status' => 'insufficient_data', 'page_id' => $pageId, 'change_date' => $changeDate,
            'window_days' => $windowDays, 'before' => [], 'after' => [], 'error' => 'Invalid change_date',
        ];
    }

    try {
        $beforeStart = date('Y-m-d', strtotime('-' . $windowDays . ' days', $changeTs));
        $beforeEnd = date('Y-m-d', strtotime('-1 day', $changeTs));
        $afterStart = date('Y-m-d', $changeTs);
        $afterEnd = date('Y-m-d', strtotime('+' . $windowDays . ' days', $changeTs));

        $before = cms_growth_agent_aggregate_page_window($pdo, $pageId, $beforeStart, $beforeEnd);
        $after = cms_growth_agent_aggregate_page_window($pdo, $pageId, $afterStart, $afterEnd);
    } catch (Throwable $e) {
        error_log('[cms_growth_agent_compare_before_after] Failed: ' . $e->getMessage());
        return [
            'status' => 'insufficient_data', 'page_id' => $pageId, 'change_date' => $changeDate,
            'window_days' => $windowDays, 'before' => [], 'after' => [], 'error' => $e->getMessage(),
        ];
    }

    $result = [
        'status' => 'ok', 'page_id' => $pageId, 'change_date' => $changeDate,
        'window_days' => $windowDays, 'before' => $before, 'after' => $after,
    ];

    if ($before['distinct_days'] < $minDays || $after['distinct_days'] < $minDays) {
        $result['status'] = 'insufficient_data';
        return $result;
    }

    $result['delta'] = [
        'clicks' => $after['clicks'] - $before['clicks'],
        'impressions' => $after['impressions'] - $before['impressions'],
        'ctr' => round($after['ctr'] - $before['ctr'], 4),
        'avg_position' => ($before['avg_position'] !== null && $after['avg_position'] !== null)
            ? round($after['avg_position'] - $before['avg_position'], 2)
            : null,
    ];

    return $result;
}

/**
 * Builds the "Feedback / Before-After" report growth-agent.php renders —
 * one row per page that had a REAL, verifiable applied change:
 *   - internal_link_suggestion: only jobs that actually went through Apply
 *     (job_type='internal_link_suggestion', status='succeeded', page_id
 *     set — succeeded only ever happens via
 *     cms_growth_agent_apply_internal_link(), the one thing that writes
 *     pages.content for this job_type). change_date = job.updated_at (set
 *     at Apply time). Added 5 Aug 2026 as part of the Measurement Loop
 *     (GROWTH_AGENT_V2_PROPOSAL.md § Fase C) — this job_type is also the
 *     sole Fase E pilot candidate, so its before/after evidence matters
 *     most here.
 *   - seo_recommendation: only jobs that actually went through Apply
 *     (job_type='seo_recommendation', status='succeeded', page_id set —
 *     succeeded only ever happens via seo-recommendation-review.php's
 *     Apply action, which is the one thing that writes meta_title/
 *     meta_description). change_date = job.updated_at (set at Apply time).
 *   - gsc_article_idea: only jobs whose draft actually got published
 *     (job_type='gsc_article_idea', page_id set, joined pages.status =
 *     'published'). change_date = pages.published_at (falls back to
 *     pages.updated_at if published_at is somehow null).
 *
 * gsc_content_optimization is deliberately EXCLUDED — traced 28 Jul 2026:
 * that job type has no "applied to the page" event anywhere in this
 * codebase. cms_growth_agent_generate_content_optimization() logs it as
 * 'succeeded' immediately at generation time (it's a proposal the human
 * copies in manually, per GROWTH_AGENT_SEO_ROADMAP.md's own "jangan
 * langsung menulis ke production" rule for this job type), and the
 * generic Approve/Reject buttons on growth-agent.php only ever write
 * growth_agent_feedback + flip job status — never `pages`. There is no
 * reliable timestamp for "when was this suggestion actually applied, if
 * ever", so including it would mean measuring before/after around a date
 * that may have no relation to any real edit. Confirmed with the user
 * before implementing rather than guessing a proxy date.
 *
 * Never throws.
 *
 * @return list<array{page_id:int,page_title:string,action_type:string,change_date:string,comparison:array}>
 */
function cms_growth_agent_get_feedback_report(PDO $pdo, int $limit = 20, int $windowDays = 28): array
{
    $limit = max(1, min(100, $limit));
    $candidates = [];

    try {
        $ilStmt = $pdo->prepare(
            "SELECT j.page_id, p.title, j.updated_at AS change_date
               FROM growth_agent_jobs j
               INNER JOIN pages p ON p.page_id = j.page_id
              WHERE j.job_type = 'internal_link_suggestion' AND j.status = 'succeeded' AND j.page_id IS NOT NULL
              ORDER BY j.updated_at DESC
              LIMIT " . $limit
        );
        $ilStmt->execute();
        foreach ($ilStmt->fetchAll() as $row) {
            $candidates[] = [
                'page_id' => (int) $row['page_id'],
                'page_title' => (string) $row['title'],
                'action_type' => 'internal_link_suggestion',
                'change_date' => (string) $row['change_date'],
            ];
        }
    } catch (Throwable $e) {
        error_log('[cms_growth_agent_get_feedback_report] internal_link_suggestion query failed: ' . $e->getMessage());
    }

    try {
        $seoStmt = $pdo->prepare(
            "SELECT j.page_id, p.title, j.updated_at AS change_date
               FROM growth_agent_jobs j
               INNER JOIN pages p ON p.page_id = j.page_id
              WHERE j.job_type = 'seo_recommendation' AND j.status = 'succeeded' AND j.page_id IS NOT NULL
              ORDER BY j.updated_at DESC
              LIMIT " . $limit
        );
        $seoStmt->execute();
        foreach ($seoStmt->fetchAll() as $row) {
            $candidates[] = [
                'page_id' => (int) $row['page_id'],
                'page_title' => (string) $row['title'],
                'action_type' => 'seo_recommendation',
                'change_date' => (string) $row['change_date'],
            ];
        }
    } catch (Throwable $e) {
        error_log('[cms_growth_agent_get_feedback_report] seo_recommendation query failed: ' . $e->getMessage());
    }

    try {
        $ideaStmt = $pdo->prepare(
            "SELECT j.page_id, p.title, COALESCE(p.published_at, p.updated_at) AS change_date
               FROM growth_agent_jobs j
               INNER JOIN pages p ON p.page_id = j.page_id
              WHERE j.job_type = 'gsc_article_idea' AND j.page_id IS NOT NULL AND p.status = 'published'
              ORDER BY COALESCE(p.published_at, p.updated_at) DESC
              LIMIT " . $limit
        );
        $ideaStmt->execute();
        foreach ($ideaStmt->fetchAll() as $row) {
            $candidates[] = [
                'page_id' => (int) $row['page_id'],
                'page_title' => (string) $row['title'],
                'action_type' => 'gsc_article_idea',
                'change_date' => (string) $row['change_date'],
            ];
        }
    } catch (Throwable $e) {
        error_log('[cms_growth_agent_get_feedback_report] gsc_article_idea query failed: ' . $e->getMessage());
    }

    // Most recent change first overall, capped at $limit total (not per type).
    usort($candidates, static fn (array $a, array $b): int => strtotime((string) $b['change_date']) <=> strtotime((string) $a['change_date']));
    $candidates = array_slice($candidates, 0, $limit);

    $report = [];
    foreach ($candidates as $candidate) {
        try {
            $comparison = cms_growth_agent_compare_before_after($pdo, $candidate['page_id'], $candidate['change_date'], $windowDays);
        } catch (Throwable $e) {
            $comparison = ['status' => 'insufficient_data', 'error' => $e->getMessage()];
        }

        $report[] = $candidate + ['comparison' => $comparison];
    }

    return $report;
}

/**
 * Measurement Loop (GROWTH_AGENT_V2_PROPOSAL.md § Fase C, reprioritized 5
 * Aug 2026 to run BEFORE Fase E — see that section's note on why: Fase E's
 * "which job_type is safe enough to trust autonomously" decision needs real
 * before/after evidence, not a guess). Finds succeeded jobs whose applied
 * change is old enough ($windowDays, default 28 — see
 * cms_gsc_default_opportunity_thresholds()['measurement_loop']) to have a
 * full "after" window, runs cms_growth_agent_compare_before_after() once per
 * row, and marks measured_at so the same row is never re-processed.
 *
 * This does NOT persist the comparison result anywhere new — no column or
 * output_json write for it. Overwriting output_json was considered and
 * rejected: for seo_recommendation it holds the original recommended_meta_*
 * pair that seo-recommendation-review.php re-reads on every load (even
 * after Apply), and for internal_link_suggestion it holds the
 * previous_content revert snapshot (see cms_growth_agent_apply_internal_link()
 * above) — clobbering either would break existing pages. The result is only
 * ever needed live, on demand, by cms_growth_agent_get_feedback_report()
 * (which already recomputes the same comparison itself when rendering the
 * Feedback panel) — so measured_at's only job is to mark "already checked,
 * don't check again", not to cache the number itself.
 *
 * change_date per job_type — deliberately NOT a single uniform
 * `updated_at` across all three, even though that would be a simpler query:
 *   - internal_link_suggestion / seo_recommendation: job.updated_at IS the
 *     Apply timestamp (no separate publish step), same as
 *     cms_growth_agent_get_feedback_report()'s identical treatment of
 *     seo_recommendation.
 *   - gsc_article_idea: job.updated_at only reflects when the DRAFT was
 *     created (cms_growth_agent_create_article_draft_from_idea(), called
 *     from growth-agent.php's approve handler) — the article may then sit
 *     as an unpublished draft for days/weeks before an editor actually
 *     publishes it. Measuring GSC performance from draft-creation time
 *     would be measuring the wrong event entirely (no public page existed
 *     yet to accrue impressions). Uses pages.published_at (falling back to
 *     pages.updated_at), gated on pages.status='published' — the exact same
 *     logic cms_growth_agent_get_feedback_report() already uses for this
 *     job_type, for the exact same reason.
 *
 * "Never throws" per-row, not just overall: a genuine exception on one row
 * (DB hiccup mid-loop) is caught, logged, and leaves that row's measured_at
 * untouched so it's retried on the next run — but compare_before_after()
 * returning status='insufficient_data' is NOT an error, it's a normal
 * final outcome (this function's own docblock: some old jobs may
 * legitimately never clear the min-days bar), so measured_at IS set for
 * those too. Matches the feature's own framing — "schedule ONE check 28
 * days later", not "poll forever until data looks good".
 *
 * @return array{checked:int,measured:int,insufficient_data:int,errors:int}
 */
function cms_growth_agent_run_measurement_loop(PDO $pdo): array
{
    $stats = ['checked' => 0, 'measured' => 0, 'insufficient_data' => 0, 'errors' => 0];

    try {
        cms_growth_agent_ensure_schema($pdo);
        require_once __DIR__ . '/gsc-api.php';

        $config = cms_gsc_get_opportunity_thresholds($pdo)['measurement_loop'] ?? [];
        $windowDays = max(1, min(180, (int) ($config['window_days'] ?? 28)));
        $minDays = max(1, min($windowDays, (int) ($config['min_days'] ?? 7)));
        $eligibleTypes = is_array($config['eligible_job_types'] ?? null) && $config['eligible_job_types'] !== []
            ? array_values(array_filter($config['eligible_job_types'], 'is_string'))
            : ['internal_link_suggestion', 'seo_recommendation', 'gsc_article_idea'];
        $batchSize = max(1, min(100, (int) ($config['batch_size'] ?? 20)));

        $candidates = [];

        $directTypes = array_values(array_intersect(['internal_link_suggestion', 'seo_recommendation'], $eligibleTypes));
        if ($directTypes !== []) {
            $placeholders = implode(',', array_fill(0, count($directTypes), '?'));
            $stmt = $pdo->prepare(
                "SELECT id, page_id, updated_at AS change_date
                   FROM growth_agent_jobs
                  WHERE status = 'succeeded'
                    AND job_type IN ($placeholders)
                    AND page_id IS NOT NULL
                    AND measured_at IS NULL
                    AND DATEDIFF(NOW(), updated_at) >= ?
                  ORDER BY updated_at ASC
                  LIMIT " . $batchSize
            );
            $stmt->execute([...$directTypes, $windowDays]);
            foreach ($stmt->fetchAll() as $row) {
                $candidates[] = [
                    'id' => (int) $row['id'],
                    'page_id' => (int) $row['page_id'],
                    'change_date' => (string) $row['change_date'],
                ];
            }
        }

        if (in_array('gsc_article_idea', $eligibleTypes, true)) {
            $stmt = $pdo->prepare(
                "SELECT j.id, j.page_id, COALESCE(p.published_at, p.updated_at) AS change_date
                   FROM growth_agent_jobs j
                   INNER JOIN pages p ON p.page_id = j.page_id
                  WHERE j.status = 'succeeded'
                    AND j.job_type = 'gsc_article_idea'
                    AND j.page_id IS NOT NULL
                    AND j.measured_at IS NULL
                    AND p.status = 'published'
                    AND DATEDIFF(NOW(), COALESCE(p.published_at, p.updated_at)) >= :window_days
                  ORDER BY COALESCE(p.published_at, p.updated_at) ASC
                  LIMIT " . $batchSize
            );
            $stmt->execute(['window_days' => $windowDays]);
            foreach ($stmt->fetchAll() as $row) {
                $candidates[] = [
                    'id' => (int) $row['id'],
                    'page_id' => (int) $row['page_id'],
                    'change_date' => (string) $row['change_date'],
                ];
            }
        }

        $candidates = array_slice($candidates, 0, $batchSize);

        foreach ($candidates as $candidate) {
            $stats['checked']++;
            try {
                $comparison = cms_growth_agent_compare_before_after(
                    $pdo, $candidate['page_id'], $candidate['change_date'], $windowDays, $minDays
                );

                // `updated_at` explicitly preserved in the SET list —
                // growth_agent_jobs.updated_at is ON UPDATE CURRENT_TIMESTAMP,
                // so leaving it out of an UPDATE to a DIFFERENT column still
                // silently bumps it to NOW() (confirmed the hard way while
                // testing this function). That would corrupt the very
                // change_date pivot this whole measurement is built around —
                // a second run next month would then measure from "today",
                // not from the real Apply date.
                $pdo->prepare('UPDATE growth_agent_jobs SET measured_at = NOW(), updated_at = updated_at WHERE id = :id')
                    ->execute(['id' => $candidate['id']]);

                $stats['measured']++;
                if (($comparison['status'] ?? '') === 'insufficient_data') {
                    $stats['insufficient_data']++;
                }
            } catch (Throwable $e) {
                error_log('[cms_growth_agent_run_measurement_loop] Row failed (job_id=' . $candidate['id'] . '): ' . $e->getMessage());
                $stats['errors']++;
                // measured_at left NULL on purpose — retried next run.
            }
        }
    } catch (Throwable $e) {
        error_log('[cms_growth_agent_run_measurement_loop] Failed: ' . $e->getMessage());
    }

    return $stats;
}

/**
 * "Daftar Artikel Berpotensi Tinggi" (GROWTH_AGENT_V2_PROPOSAL.md § Fase D
 * — renamed 6 Aug 2026 from "Backlink Monitor" after investigation found
 * Google's Search Console API has no Links/backlink report endpoint at
 * all, free or paid — that half of the original scope was dropped
 * entirely; see the doc's own correction). What survives: published
 * articles ranked by existing GSC traffic/impression signal, so an
 * operator has concrete promotion/outreach targets to work from manually
 * — no "zero backlink" filter (that data source is gone along with the
 * monitoring half it would have come from).
 *
 * Pure read + aggregate, same "read-only report, no growth_agent_jobs row"
 * shape as the Technical SEO Auditor (see that feature's own docblock
 * above for why this is still § 1b-compliant — there's no decision here,
 * only information an operator acts on manually). UNLIKE TSA, this
 * persists NOTHING: no new table, no new column. TSA persists because a
 * PageSpeed Insights call is genuinely expensive (10-30s/URL) and must
 * not re-run every page view; this is a cheap SQL aggregate over data
 * that's already fetched (gsc_query_data), so it's simply recomputed live
 * on every page load — nothing to keep in sync, nothing that can go
 * stale.
 *
 * Two-pass query, not one — deliberately reuses
 * cms_growth_agent_aggregate_page_window() (the SAME function
 * cms_growth_agent_compare_before_after() uses, so this panel's numbers
 * are consistent with every other panel that shows page-level GSC
 * metrics) rather than a fresh ad-hoc SUM():
 *   1. One cheap bulk GROUP BY over gsc_query_data ranks ALL published
 *      pages by impressions in a single query — this is what makes the
 *      feature scale to any article count without one query per article.
 *   2. Only the top 'candidate_pool_size' candidates from pass 1 (wider
 *      than the final 'articles_per_report' — see
 *      cms_gsc_default_opportunity_thresholds()['high_potential_articles']
 *      for why) get a real cms_growth_agent_aggregate_page_window() call,
 *      for properly-sourced final numbers (it prefers the durable
 *      growth_agent_performance snapshot over raw gsc_query_data when the
 *      snapshot has better day-coverage — pass 1's raw SUM alone doesn't
 *      know to do that). Results are re-sorted by THESE numbers before
 *      slicing to articles_per_report, not by pass 1's order, so a
 *      candidate whose properly-sourced total differs from its raw sum
 *      still lands in the correct final position.
 *
 * Never throws.
 *
 * @return list<array{page_id:int,title:string,slug:string,clicks:int,impressions:int,ctr:float,avg_position:?float}>
 */
function cms_growth_agent_get_high_potential_articles(PDO $pdo): array
{
    try {
        require_once __DIR__ . '/gsc-api.php';
        $config = cms_gsc_get_opportunity_thresholds($pdo)['high_potential_articles'] ?? [];
        $windowDays = max(1, min(180, (int) ($config['window_days'] ?? 28)));
        $minImpressions = max(0, (int) ($config['min_impressions'] ?? 50));
        $limit = max(1, min(50, (int) ($config['articles_per_report'] ?? 10)));
        $candidatePoolSize = max($limit, min(200, (int) ($config['candidate_pool_size'] ?? 30)));

        $start = date('Y-m-d', strtotime('-' . $windowDays . ' days'));
        $end = date('Y-m-d');

        // Pass 1 — cheap bulk ranking, one query for every published page
        // at once (see this function's own docblock for why this can't
        // just be N calls to aggregate_page_window() directly).
        $candidateStmt = $pdo->prepare(
            "SELECT matched_page_id AS page_id, SUM(impressions) AS total_impressions
               FROM gsc_query_data
              WHERE matched_page_id IS NOT NULL AND data_date BETWEEN :start AND :end
              GROUP BY matched_page_id
             HAVING SUM(impressions) >= :min_impressions
              ORDER BY total_impressions DESC
              LIMIT " . $candidatePoolSize
        );
        $candidateStmt->execute(['start' => $start, 'end' => $end, 'min_impressions' => $minImpressions]);
        $candidatePageIds = array_map('intval', array_column($candidateStmt->fetchAll(), 'page_id'));

        if ($candidatePageIds === []) {
            return [];
        }

        // Only published articles are actionable promotion targets —
        // drafts can't be pushed to anyone yet.
        $placeholders = implode(',', array_fill(0, count($candidatePageIds), '?'));
        $pagesStmt = $pdo->prepare(
            "SELECT page_id, title, slug FROM pages WHERE page_id IN ($placeholders) AND status = 'published'"
        );
        $pagesStmt->execute($candidatePageIds);
        $pageMeta = [];
        foreach ($pagesStmt->fetchAll() as $row) {
            $pageMeta[(int) $row['page_id']] = ['title' => (string) $row['title'], 'slug' => (string) $row['slug']];
        }

        // Pass 2 — re-aggregate each surviving candidate through the
        // shared, properly-sourced function.
        $candidates = [];
        foreach ($candidatePageIds as $pageId) {
            if (!isset($pageMeta[$pageId])) {
                continue; // not a published page (draft/deleted since the GSC data was fetched) — skip
            }
            try {
                $agg = cms_growth_agent_aggregate_page_window($pdo, $pageId, $start, $end);
            } catch (Throwable $e) {
                continue;
            }
            $candidates[] = [
                'page_id' => $pageId,
                'title' => $pageMeta[$pageId]['title'],
                'slug' => $pageMeta[$pageId]['slug'],
                'clicks' => $agg['clicks'],
                'impressions' => $agg['impressions'],
                'ctr' => $agg['ctr'],
                'avg_position' => $agg['avg_position'],
            ];
        }

        usort($candidates, static fn (array $a, array $b): int => $b['impressions'] <=> $a['impressions']);

        return array_slice($candidates, 0, $limit);
    } catch (Throwable $e) {
        error_log('[cms_growth_agent_get_high_potential_articles] Failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Delete old, already-resolved growth_agent_jobs rows so the table doesn't
 * grow forever (there's no cron in this codebase — see sitemap-service.php's
 * own note that everything here runs synchronously on request, not on a
 * schedule — so this is invoked both lazily on every growth-agent.php page
 * load, matching the cms_ensure_table() "self-maintaining on request"
 * pattern, and via an explicit "Bersihkan job lama" button for on-demand use).
 *
 * Deliberately conservative about what it deletes:
 *   - 'ready' / 'running' / 'manual_action' jobs are NEVER touched, no
 *     matter how old — 'manual_action' still needs a human decision
 *     (e.g. an un-reviewed SEO recommendation or indexing issue), and
 *     'ready'/'running' are still in flight.
 *   - 'failed' jobs older than the retention window are deleted — a
 *     failed generation has no future use once it's old.
 *   - 'closed_as_legacy' jobs older than the window are deleted too, same
 *     reasoning as 'failed' — explicitly marked "no longer useful" by a
 *     human, so there's no reason to keep it around as a few-shot example.
 *   - 'succeeded' jobs older than the window are deleted UNLESS a human
 *     explicitly approved it as-is (growth_agent_feedback.action =
 *     'approved_as_is') — those are the Fase 3 few-shot example pool
 *     (see GrowthAgentPromptBuilder::approvedExamples()) and must survive
 *     cleanup indefinitely, or future generations quietly lose their
 *     reference examples.
 *
 * Never throws. Returns the number of jobs deleted (0 on any failure).
 */
function cms_growth_agent_cleanup_old_jobs(PDO $pdo, int $retentionDays = 90): int
{
    try {
        cms_growth_agent_ensure_schema($pdo);
        $days = max(7, min(365, $retentionDays));

        $idStmt = $pdo->query(
            "SELECT id FROM growth_agent_jobs
              WHERE created_at < (NOW() - INTERVAL {$days} DAY)
                AND (
                    status IN ('failed', 'closed_as_legacy')
                    OR (
                        status = 'succeeded'
                        AND id NOT IN (SELECT job_id FROM growth_agent_feedback WHERE action = 'approved_as_is')
                    )
                )"
        );
        $ids = $idStmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];

        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("DELETE FROM growth_agent_feedback WHERE job_id IN ($placeholders)")->execute($ids);
        $pdo->prepare("DELETE FROM growth_agent_jobs WHERE id IN ($placeholders)")->execute($ids);

        return count($ids);
    } catch (Throwable $e) {
        error_log('[cms_growth_agent_cleanup_old_jobs] Failed: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Feeds the notification bell in includes/navbar.php (shown on every admin
 * page). "Needs attention" means: a generation that failed (retryable), or
 * a manual_action job awaiting a human decision (currently only
 * seo_recommendation jobs use that status — see
 * cms_growth_agent_scan_seo_recommendations()). 'ready'/'running' jobs are
 * excluded on purpose — they're not problems, just in-flight/queued work.
 *
 * Never throws — a notification lookup failing must never break every
 * single admin page. Returns ['count' => int, 'items' => array] with
 * count reflecting the TOTAL number needing attention (not capped by
 * $limit — $limit only bounds how many are listed in the dropdown).
 *
 * @return array{count:int, items:array<int, array<string, mixed>>}
 */
/**
 * Fase G follow-up (9 Aug 2026) — a second, DELIBERATELY DIFFERENT-toned
 * notification category alongside the original failed/manual_action one:
 * articles that auto-published (or were manually approved) via
 * job_type='auto_draft_article' in the last 24h. That window is the whole
 * "expiry" mechanism — no read/unread state, a notification just stops
 * qualifying once its job is a day old, same trade-off already accepted
 * elsewhere in this codebase for "good news, not a queue to clear".
 *
 * 'new_article' items require p.status = 'published' (not just page_id
 * being set) — a manually-approved auto_draft_article job also gets a
 * page_id immediately, but that page is still sitting as an unpublished
 * DRAFT until an editor separately publishes it. Notifying "Artikel baru
 * dipublikasikan" for something that isn't actually live yet would be a
 * false positive, so this only fires for genuinely published articles
 * (which today means Fase G auto-publish, but stays correct if a manual
 * approve+immediate-publish path is ever added later).
 *
 * $result shape: 'count' (combined, drives the bell's dot), 'action_
 * needed_count' / 'new_article_count' (the two panel-head pills — kept
 * separate so a pile of good-news items can never make the "perlu
 * perhatian" pill look more urgent than it is, or vice versa), 'items'
 * (merged, ORDER BY created_at DESC, capped at $limit) — each item
 * carries 'notif_type' ('action_needed' | 'new_article') so navbar.php
 * can style/word them differently.
 */
function cms_growth_agent_notifications(PDO $pdo, int $limit = 8): array
{
    $result = ['count' => 0, 'action_needed_count' => 0, 'new_article_count' => 0, 'items' => []];

    try {
        cms_growth_agent_ensure_schema($pdo);

        $countRow = $pdo->query(
            "SELECT
                (SELECT COUNT(*) FROM growth_agent_jobs WHERE status IN ('failed', 'manual_action')) AS action_needed_count,
                (SELECT COUNT(*)
                   FROM growth_agent_jobs j
                   JOIN pages p ON p.page_id = j.page_id
                  WHERE j.job_type = 'auto_draft_article'
                    AND j.page_id IS NOT NULL
                    AND p.status = 'published'
                    AND j.created_at >= NOW() - INTERVAL 1 DAY) AS new_article_count"
        )->fetch();
        $result['action_needed_count'] = (int) ($countRow['action_needed_count'] ?? 0);
        $result['new_article_count'] = (int) ($countRow['new_article_count'] ?? 0);
        $result['count'] = $result['action_needed_count'] + $result['new_article_count'];

        if ($result['count'] === 0) {
            return $result;
        }

        $stmt = $pdo->prepare(
            "(SELECT j.id, j.job_type, j.status, j.created_at, p.title AS page_title, j.page_id,
                     'action_needed' AS notif_type
                FROM growth_agent_jobs j
                LEFT JOIN pages p ON p.page_id = j.page_id
               WHERE j.status IN ('failed', 'manual_action'))
             UNION ALL
             (SELECT j.id, j.job_type, j.status, j.created_at, p.title AS page_title, j.page_id,
                     'new_article' AS notif_type
                FROM growth_agent_jobs j
                JOIN pages p ON p.page_id = j.page_id
               WHERE j.job_type = 'auto_draft_article'
                 AND j.page_id IS NOT NULL
                 AND p.status = 'published'
                 AND j.created_at >= NOW() - INTERVAL 1 DAY)
             ORDER BY created_at DESC
             LIMIT " . max(1, $limit)
        );
        $stmt->execute();
        $result['items'] = $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        error_log('[cms_growth_agent_notifications] Failed: ' . $e->getMessage());
    }

    return $result;
}

/**
 * ── Full Draft Automation (GROWTH_AGENT_V2_PROPOSAL.md § 6, Fase F,
 *    8 Aug 2026) ──
 *
 * Extends the same "scraper gives AI a headline for context, AI writes
 * 100% original content" pattern already proven by
 * cms_growth_agent_generate_article_idea() (§ 5) — the only thing new here
 * is the AI now writes a full body (not just a title+outline) and a cover
 * image gets generated too. Copyright boundary is identical and NOT
 * relaxed: only headline text ever reaches a prompt, never scraped body
 * content (see cms_growth_agent_refresh_trending_headlines()'s own
 * docblock — that constraint was already enforced at the point headlines
 * are stored, so it's inherited here for free by reading from the same
 * growth_agent_trending_headlines table rather than re-scraping).
 *
 * job_type = 'auto_draft_article', logged status='manual_action' — same
 * Action Queue as everything else (§ 1b). Approve creates a `pages` DRAFT
 * row (never published) — same "Approve IS execute" exception as
 * gsc_article_idea, for the same reason: there is nothing else an
 * approved full-draft proposal can become except a draft article.
 * Publish stays a separate, fully manual action exactly like every other
 * article on this site. Fase G (auto-publish) is explicitly NOT part of
 * this — no code path anywhere in this section ever sets pages.status.
 */

/**
 * Converts PNG bytes (what gpt-image-1-mini always returns — see
 * cms_ai_call_openai_image()'s own docblock, no url/JPEG response_format
 * option exists for this model family) to compressed JPEG, targeting
 * $maxBytes (9 Aug 2026 fix — operator flagged 1.96/2.19 MB PNGs as too
 * large; the site's own featured-image size hint is 1200x630, this brings
 * the on-disk file down to match that expectation).
 *
 * Returns null if GD isn't available or decoding fails — caller MUST
 * treat that as "fall back to the original PNG bytes", never as a hard
 * failure; a missing PHP extension on some hosting environment must not
 * be able to kill an otherwise-successful image generation.
 *
 * Quality steps down from 82 in increments of 8 (to a floor of 40) until
 * the JPEG fits $maxBytes — if even quality 40 doesn't fit, that lowest-
 * quality attempt is still returned rather than looping forever; a
 * slightly-over-budget JPEG beats no image at all.
 */
function cms_growth_agent_convert_to_compressed_jpeg(string $pngBytes, int $maxBytes = 900000): ?string
{
    if (!extension_loaded('gd')) {
        return null;
    }
    $img = @imagecreatefromstring($pngBytes);
    if ($img === false) {
        return null;
    }

    // Flatten transparency onto a white background first — JPEG has no
    // alpha channel, and gpt-image-1-mini output can carry one.
    $w = imagesx($img);
    $h = imagesy($img);
    $flat = imagecreatetruecolor($w, $h);
    $white = imagecolorallocate($flat, 255, 255, 255);
    imagefill($flat, 0, 0, $white);
    imagecopy($flat, $img, 0, 0, 0, 0, $w, $h);
    imagedestroy($img);

    $jpegBytes = null;
    for ($quality = 82; $quality >= 40; $quality -= 8) {
        ob_start();
        imagejpeg($flat, null, $quality);
        $jpegBytes = ob_get_clean();
        if ($jpegBytes !== false && strlen($jpegBytes) <= $maxBytes) {
            imagedestroy($flat);
            return $jpegBytes;
        }
    }
    imagedestroy($flat);
    // Still over budget even at the quality floor — return it anyway
    // (better than no image), unless imagejpeg() itself never produced
    // anything usable.
    return $jpegBytes !== false ? $jpegBytes : null;
}

/**
 * Saves AI-generated image bytes to disk — same uploads/media/{Y}/{m}/
 * directory layout, guard-file (index.php 403), and safe-filename
 * convention (sanitized base + random hex suffix) as
 * pages/media-library.php's own upload handler, so a generated cover image
 * is indistinguishable on disk from a manually uploaded one. Also inserts
 * a matching row into `media_library` (9 Aug 2026 fix) — a PREVIOUS
 * version of this docblock claimed the file "shows up in Media Library
 * normally", which was false: that page lists rows from the
 * `media_library` TABLE, not a filesystem scan, and this function used to
 * only write bytes to disk, never insert a row. The image worked fine as
 * a `pages.featured_image` regardless (that column just stores a path),
 * it just never appeared in the catalog UI. Deliberately NOT reusing
 * cms_process_file_uploads() itself — that helper is built around
 * $_FILES/move_uploaded_file() for real HTTP uploads; this writes raw
 * decoded bytes instead, so only the directory/naming conventions are
 * shared, not the file-transfer mechanism.
 *
 * The media_library INSERT is best-effort and never fails the caller —
 * see the try/catch around it below. A generated image that saved fine to
 * disk must still work as a cover image even if the catalog insert fails
 * for some reason (e.g. a future required column this function doesn't
 * know about yet); the article should never lose its cover over a
 * bookkeeping row.
 *
 * Never throws — returns null on any failure (bad base64, disk full,
 * permission error), so a save failure degrades to "draft without a
 * cover image" rather than aborting the whole draft.
 *
 * @param string $altTextSource Optional text (e.g. the article title) to
 *        use as the media_library row's alt_text — falls back to a
 *        generic label if empty.
 * @return string|null leading-slash relative path (e.g.
 *         "/uploads/media/2026/08/xxxx.png"), or null on failure.
 */
function cms_growth_agent_save_generated_image(PDO $pdo, string $base64Data, string $altTextSource = ''): ?string
{
    try {
        $bytes = base64_decode($base64Data, true);
        if ($bytes === false || $bytes === '') {
            return null;
        }

        $projectRoot = CMS_PROJECT_ROOT;
        $yr = date('Y');
        $mo = date('m');
        $relBase = 'uploads/media';
        $relYear = $relBase . '/' . $yr;
        $relDir = $relYear . '/' . $mo;
        $diskDir = $projectRoot . '/' . $relDir;

        if (!is_dir($diskDir) && !mkdir($diskDir, 0755, true) && !is_dir($diskDir)) {
            return null;
        }

        // Same 403-on-direct-browse guard as media-library.php's own
        // upload handler — identical content, kept inline here (not a
        // shared constant) since that file doesn't expose one either.
        $guardContent = "<?php\nhttp_response_code(403);\nexit('Forbidden');\n";
        foreach ([$relBase, $relYear, $relDir] as $guardLevel) {
            $guardFile = $projectRoot . '/' . $guardLevel . '/index.php';
            if (!file_exists($guardFile)) {
                file_put_contents($guardFile, $guardContent);
                @chmod($guardFile, 0644);
            }
        }

        // JPEG conversion (9 Aug 2026 fix) — falls back to the original
        // PNG bytes whenever GD is unavailable or conversion fails, never
        // a hard error; see cms_growth_agent_convert_to_compressed_jpeg()'s
        // own docblock.
        $jpegBytes = cms_growth_agent_convert_to_compressed_jpeg($bytes, 900000);
        $finalBytes = $jpegBytes ?? $bytes;
        $extension = $jpegBytes !== null ? 'jpg' : 'png';
        $mimeType = $jpegBytes !== null ? 'image/jpeg' : 'image/png';

        // 'cover', not 'ai-cover' (9 Aug 2026 — operator didn't want the
        // AI origin visible in the public file name/URL).
        $base = 'cover';
        do {
            $safeFilename = $base . '-' . bin2hex(random_bytes(8)) . '.' . $extension;
            $targetPath = $diskDir . '/' . $safeFilename;
        } while (file_exists($targetPath));

        if (file_put_contents($targetPath, $finalBytes) === false) {
            return null;
        }
        @chmod($targetPath, 0644);

        $relPath = '/' . $relDir . '/' . $safeFilename;

        try {
            $insert = $pdo->prepare(
                'INSERT INTO media_library (
                    file_name, file_path, file_type, mime_type, file_size_kb,
                    alt_text, caption, is_active, created_at, updated_at
                ) VALUES (
                    :file_name, :file_path, :file_type, :mime_type, :file_size_kb,
                    :alt_text, :caption, :is_active, NOW(), NOW()
                )'
            );
            $insert->execute([
                'file_name' => $safeFilename,
                'file_path' => $relPath,
                'file_type' => 'image',
                'mime_type' => $mimeType,
                'file_size_kb' => (int) round(strlen($finalBytes) / 1024),
                'alt_text' => $altTextSource !== '' ? mb_substr($altTextSource, 0, 255) : 'Cover artikel (AI-generated)',
                'caption' => 'Digenerate otomatis oleh Growth Agent (Full Draft Automation)',
                'is_active' => 1,
            ]);
        } catch (Throwable $e) {
            // Never lets a catalog bookkeeping failure take down the cover
            // image itself — see this function's own docblock.
            error_log('[cms_growth_agent_save_generated_image] Gagal insert media_library: ' . $e->getMessage());
        }

        return $relPath;
    } catch (Throwable $e) {
        error_log('[cms_growth_agent_save_generated_image] ' . $e->getMessage());
        return null;
    }
}

/**
 * Extracts likely proper-noun "entities" (player/team/competition names)
 * from an article title — plain regex/keyword matching, NOT an AI call
 * (devs brief explicit requirement: no second AI call just to "think up" an
 * image prompt, that would double token cost for no real benefit here).
 *
 * Relies on this site's own title-casing convention (confirmed against
 * real stored titles): connector words ("ke", "untuk", "dan", "di", "yang",
 * ...) stay lowercase even inside an otherwise Title Case headline, so a
 * capitalized word that ISN'T one of those connectors is a reasonable
 * proper-noun signal — e.g. "Salah ke Trabzonspor untuk Jadi Juara" ->
 * ["Salah", "Trabzonspor", "Jadi", "Juara"] before the generic-word filter
 * below removes "Jadi"/"Juara" (not proper nouns, just happen to be
 * capitalized here since they start a clause). Not perfect — a heuristic,
 * same "best effort" framing as the Trending Headlines HTML scraper.
 *
 * @return string[] up to $limit entity strings, in title order.
 */
function cms_growth_agent_extract_title_entities(string $title, int $limit = 4): array
{
    $connectors = [
        'ke', 'untuk', 'dan', 'di', 'dari', 'yang', 'pada', 'ini', 'itu', 'akan', 'atau', 'jadi',
        'juara', 'menang', 'kalah', 'cedera', 'gagal', 'sukses', 'resmi', 'usai', 'demi', 'saat',
        'gara', 'karena', 'setelah', 'sebelum', 'lawan', 'vs', 'para', 'sang', 'tanpa', 'bersama',
        'dunia', 'pulang', 'tinggalkan', 'gabung', 'pindah', 'kembali', 'siap', 'jelang', 'hadapi',
        'punya', 'jadi',
    ];

    // Sports-news verbs/adverbs (9 Aug 2026 fix) — this site's headlines
    // are Title Case (EVERY word capitalized, not just proper nouns), so
    // capitalization alone can't distinguish "STY" (a real entity) from
    // "Ungkap"/"Masih"/"Optimal" (ordinary verbs/adverbs that only look
    // capitalized because of the title style). Left uncaught, these words
    // rode along in the entity list into the cover-image prompt, and GPT
    // Image tried to render them as literal text on jerseys/objects (a
    // real observed case: "MASIH" and garbled text on jersey graphics).
    // Not a final/complete list — expand as new false positives turn up.
    $nonEntityWords = [
        'ungkap', 'masih', 'belum', 'optimal', 'jelang', 'siap', 'resmi',
        'tegaskan', 'akui', 'sebut', 'klaim', 'nilai', 'sindir', 'bantah',
        'pastikan', 'buka', 'tutup', 'mulai', 'dimulai', 'berlanjut',
        'lanjutkan', 'incar', 'kejar', 'raih', 'catat', 'cetak', 'pecahkan',
        'perbaiki', 'perkuat', 'perpanjang', 'tunda', 'batal', 'gagal',
        'sukses', 'berhasil', 'terancam', 'terkendala', 'siapkan', 'persiapan',
        'jalani', 'hadapi', 'lawan', 'kalahkan', 'taklukkan', 'unggul',
        'tertinggal', 'terpuruk', 'terpaksa', 'diwajibkan', 'diminta',
        'meminta', 'berharap', 'yakin', 'optimis', 'khawatir', 'waspada',
        // Generic competition-result nouns (9 Aug 2026 fix, round 2) —
        // "Jawa Barat Raih Gelar Juara Umum Indonesia Muay Thai
        // Championship" let "Gelar"/"Juara"/"Umum"/"Championship" through
        // as pseudo-entities (same Title Case problem as above, just
        // nouns instead of verbs this time — "Umum" especially is vague
        // enough to send the image model in an unrelated direction).
        'gelar', 'juara', 'umum', 'championship', 'kejuaraan', 'kontingen',
        'delegasi', 'cabang', 'nomor', 'kategori',
    ];
    $nonEntityWords = array_merge($connectors, $nonEntityWords);

    $words = preg_split('/\s+/u', trim($title)) ?: [];
    $entities = [];
    foreach ($words as $word) {
        $clean = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', '', $word), ' ');
        if ($clean === '' || is_numeric($clean)) {
            continue;
        }
        $firstChar = mb_substr($clean, 0, 1);
        if ($firstChar !== mb_strtoupper($firstChar, 'UTF-8')) {
            continue; // not capitalized — skip
        }
        if (in_array(mb_strtolower($clean, 'UTF-8'), $nonEntityWords, true)) {
            continue; // capitalized only because of Title Case, not a proper noun
        }
        if (!in_array($clean, $entities, true)) {
            $entities[] = $clean;
        }
        if (count($entities) >= $limit) {
            break;
        }
    }

    return $entities;
}

/**
 * Maps a title's dominant theme to a short English descriptive phrase for
 * the image prompt — plain keyword lookup against Indonesian sports-news
 * vocabulary, not AI. English output regardless of the (Indonesian) title
 * language: image models are documented to follow English prompts more
 * reliably, and this is a fixed short phrase, not a translation of
 * anything copyrighted.
 */
function cms_growth_agent_extract_title_context(string $title): string
{
    $lower = mb_strtolower($title, 'UTF-8');
    $themes = [
        'juara' => 'championship celebration',
        'menang' => 'victory celebration',
        'kalah' => 'defeat, dejected mood',
        'cedera' => 'injury, medical concern on the field',
        'transfer' => 'transfer signing, new club unveiling',
        'gagal' => 'setback, disappointment',
        'rekor' => 'record-breaking achievement',
        'debut' => 'debut appearance',
        'comeback' => 'dramatic comeback',
        'gol' => 'goal-scoring moment',
        'final' => 'championship final match atmosphere',
        'pensiun' => 'retirement, farewell moment',
        'skandal' => 'controversy, tense atmosphere',
        'gabung' => 'transfer signing, new club unveiling',
        'pindah' => 'transfer signing, new club unveiling',
    ];

    // Deliberately str_contains(), NOT word-boundary regex (9 Aug 2026 —
    // tried \b here, reverted after testing): Indonesian is agglutinative
    // — "menang" (win) legitimately appears with zero word boundary
    // inside "kemenangan" (victory, ke-...-an), "dimenangkan" (won,
    // di-...-kan), "memenangkan" (to win, me-...-kan), etc. \b treats
    // those as non-matches (tested: "Timnas Raih Kemenangan Besar" fell
    // through to the generic fallback instead of "victory celebration"),
    // which is a real regression, not a fix, for this language.
    foreach ($themes as $keyword => $phrase) {
        if (str_contains($lower, $keyword)) {
            return $phrase;
        }
    }

    return 'sports news moment';
}

/**
 * Builds the final image-generation prompt for one article title —
 * template text comes from Prompt Control (image_agent/instruction, same
 * PromptLoader mechanism every other agent uses — see
 * services/PromptLoader.php), entity/context/sport-visual extraction is
 * pure PHP. Falls back to a hardcoded default template if nothing is
 * configured yet in Prompt Control, same "never break because the DB is
 * empty" convention as cms_ai_resolve_agent()'s own fallback.
 *
 * Template placeholders: {entities}, {context}, {sport_visual} — replaced
 * via str_replace, no AI call anywhere in this function.
 *
 * Sport-visual signal (sport_key concept removed entirely 12 Aug 2026 per
 * Opsi A — this site has no livescore/sports API integration, see
 * CLAUDE.md). This function now always runs the keyword-matching path
 * below (previously only reached when sport_key === 'general'): it scans
 * the title text against $offSiteSportKeywords, falling back to a generic
 * multi-sport visual when nothing matches. Before the original 9 Aug 2026
 * fix, the prompt had NO sport signal at all — only mood/theme keywords
 * (e.g. "victory celebration") plus capitalized entities pulled from the
 * title, which let the image model misread ambiguous words: job 203's
 * title contained "Sprint Race" (MotoGP terminology) and the model
 * rendered literal track-and-field sprinting, because nothing in the
 * prompt said "motorsport" anywhere.
 */
function cms_growth_agent_build_cover_image_prompt(PDO $pdo, string $title): string
{
    // "no text overlay, no watermark" alone (pre-9-Aug-2026) reads to the
    // model as "no caption/logo stamped ON TOP of the image" — it does
    // NOT stop the model rendering text INSIDE the scene itself (jersey
    // numbers, sponsor names on kits, signage), which is what actually
    // happened: entity words leaking into the prompt (see the entity-
    // extraction fix above) got rendered as literal jersey text. Kept
    // explicit even after that extraction fix, since language always has
    // new false positives that fix won't catch — this is the backstop.
    // "candid documentary...avoid overly smooth/airbrushed/CGI look" etc
    // (9 Aug 2026 fix) — operator feedback that generated covers look
    // "too AI": the usual tells (glossy/too-smooth skin, perfect studio
    // lighting, perfect symmetry, oversaturated color). Not a technical
    // bug, just prompt wording that never asked for the opposite.
    // Logo/brand-mark ban widened (10 Aug 2026) — the jersey/kit-scoped
    // clause below doesn't stop logos elsewhere in frame (race cars,
    // motorcycles, products, background signage outside "jerseys/
    // clothing/signage/banners"), so a separate blanket clause covers it.
    $defaultTemplate = 'Editorial sports photo, {sport_visual}, {entities}, {context}, realistic photojournalism style, candid documentary sports photography, natural skin texture, natural uneven stadium/venue lighting, slight motion blur on fast movement, avoid overly smooth or airbrushed look, avoid glossy CGI/render look, avoid perfect symmetry, film-grain texture, no text overlay, no watermark, no readable text, letters, numbers, or logos on jerseys, clothing, signage, or banners — plain/blank jerseys and kits only, no visible brand logos, sponsor marks, or trademarks anywhere in the image, 16:9';

    $template = $defaultTemplate;
    // Guardrail layer (10 Aug 2026 fix, structural) — this function used
    // to read ONLY the 'instruction' layer via getPrompt(), completely
    // ignoring 'guardrail' (both global/guardrail and image_agent/
    // guardrail) even though Prompt Control's UI lets an operator fill
    // that tab in for image_agent same as any other agent. Any guardrail
    // text an operator saved there was a silent no-op — never merged into
    // what actually got sent to GPT Image. NOT using PromptLoader::
    // buildMergedPrompt() here (that joins layers with "\n\n" for a chat
    // completion system prompt) — an image prompt needs one dense comma-
    // separated string, so guardrail layers are fetched individually and
    // appended after the {placeholder}-filled instruction below instead.
    $guardrailParts = [];
    try {
        require_once dirname(__DIR__, 2) . '/services/PromptLoader.php';
        $loader = new PromptLoader($pdo);
        $configured = trim((string) ($loader->getPrompt('image_agent', 'instruction') ?? ''));
        if ($configured !== '') {
            $template = $configured;
        }

        $globalGuardrail = trim((string) ($loader->getPrompt('global', 'guardrail') ?? ''));
        if ($globalGuardrail !== '') {
            $guardrailParts[] = $globalGuardrail;
        }
        $imageGuardrail = trim((string) ($loader->getPrompt('image_agent', 'guardrail') ?? ''));
        if ($imageGuardrail !== '') {
            $guardrailParts[] = $imageGuardrail;
        }
    } catch (Throwable $e) {
        // Ignore — falls through to the hardcoded default above; guardrail
        // is additive and must never block image generation on its own.
    }

    // Off-site sport keyword dictionary (9 Aug 2026 fix, job 205) — this
    // site tracks no sport via a DB table at all (sport_key removed 12
    // Aug 2026 per Opsi A), so this keyword match now runs against every
    // title unconditionally. A bare "general sports scene" phrase gave
    // GPT Image nothing to go on — it defaulted to football/soccer
    // imagery for a boxing article (job 205), the exact same "model
    // guesses when the prompt is vague" failure job 203 already proved
    // (there it was a missing sport signal entirely, here it's a signal
    // too vague to be useful). NOT exhaustive by design —
    // add entries as new off-site sports actually turn up in testing,
    // don't pre-build hundreds of entries for sports that never appear.
    // Deliberately plain keyword matching, no AI call, same "cheap and
    // fast" principle as cms_growth_agent_extract_title_context().
    $offSiteSportKeywords = [
        'tinju' => 'boxing match scene, boxing ring, gloves',
        'petinju' => 'boxing match scene, boxing ring, gloves',
        'pugilisme' => 'boxing match scene, boxing ring, gloves',
        'renang' => 'swimming competition scene, pool, swimmer',
        'atletik' => 'athletics track and field scene, running track',
        'lari' => 'running/track athletics scene, running track',
        'angkat besi' => 'weightlifting competition scene, barbell',
        'bulu tangkis' => 'badminton match scene, indoor court, shuttlecock',
        'bulutangkis' => 'badminton match scene, indoor court, shuttlecock',
        'voli' => 'volleyball match scene, indoor court, net',
        'tenis' => 'tennis match scene, tennis court',
        'panahan' => 'archery competition scene, bow and target',
        'e-sports' => 'esports competitive gaming scene, gaming setup',
        'esports' => 'esports competitive gaming scene, gaming setup',
        'golf' => 'golf tournament scene, golf course',
        'bola basket' => 'basketball game scene, indoor court, arena',
        // Combat sports (9 Aug 2026 fix, round 2) — a Muay Thai article
        // fell all the way through to the generic 'multi-sport' fallback
        // below because none of these existed yet.
        'muay thai' => 'muay thai/kickboxing match, ring, two fighters in combat stance',
        'muaythai' => 'muay thai/kickboxing match, ring, two fighters in combat stance',
        'pencak silat' => 'pencak silat martial arts match, mat, two competitors in combat stance',
        'karate' => 'karate martial arts match, dojo or mat, competitors in combat stance',
        'taekwondo' => 'taekwondo martial arts match, mat, competitors in combat stance',
        // MotoGP / motorsport in general — without an entry here it fell
        // all the way to the generic multi-sport fallback, no motorsport
        // anchor at all — real observed case (on sagagoal.com, before
        // this site existed): a MotoGP standings article generated a
        // picture of a wallet.
        'motogp' => 'motorsport racing scene, racetrack, motorcycle racing, motorcycle rider in racing gear',
        'moto gp' => 'motorsport racing scene, racetrack, motorcycle racing, motorcycle rider in racing gear',
        'f1' => 'motorsport racing scene, racetrack, formula 1 race car',
        'formula 1' => 'motorsport racing scene, racetrack, formula 1 race car',
        'sepak bola' => 'football/soccer match scene, stadium, pitch',
        'bola' => 'football/soccer match scene, stadium, pitch',
    ];

    $sportVisual = null;
    $lowerTitle = mb_strtolower($title, 'UTF-8');
    foreach ($offSiteSportKeywords as $keyword => $phrase) {
        if (str_contains($lowerTitle, $keyword)) {
            $sportVisual = $phrase;
            break;
        }
    }
    if ($sportVisual === null) {
        // Last-resort fallback — reached when no keyword matched at all.
        // Explicitly rules out football/soccer rather than saying
        // "general sports scene" — we have two confirmed cases (job 203,
        // job 205, both on sagagoal.com) of GPT Image defaulting to
        // football imagery whenever the prompt doesn't rule it out.
        $sportVisual = 'multi-sport athletic competition scene, diverse sports arena, athletes in action (explicitly NOT a football/soccer match)';
    }

    $entities = cms_growth_agent_extract_title_entities($title);
    $entitiesText = $entities !== [] ? implode(', ', $entities) : 'sports scene';
    $context = cms_growth_agent_extract_title_context($title);

    $finalPrompt = str_replace(
        ['{sport_visual}', '{entities}', '{context}'],
        [$sportVisual, $entitiesText, $context],
        $template
    );

    if ($guardrailParts !== []) {
        $finalPrompt .= ', ' . implode(', ', $guardrailParts);
    }

    return $finalPrompt;
}

/**
 * Main entry point for Fase F — generates ONE full draft article proposal
 * (title + body + optional cover image) from a trending headline, logged
 * as job_type='auto_draft_article'. Called from the Bagian 2 scheduler
 * (cron/growth_agent_maintenance.php, gated on
 * opportunity_thresholds_json.auto_draft_automation.enabled) — never called
 * unconditionally, that config gate lives in the caller, not here.
 *
 * Pipeline, each step degrading independently (never throws):
 *   1. Pick the next trending headline not yet used for this job_type AND
 *      not already overlapping a published article — reuses
 *      cms_growth_agent_get_trending_headlines_for_prompt()'s published-
 *      overlap filter unchanged, only adds the "not already used" check on
 *      top (scanning existing auto_draft_article jobs' input_brief.source_url
 *      — same in-PHP dedupe-scan pattern as cms_growth_agent_scan_internal_links()'s
 *      $existingPairs).
 *   2. SEO-G0 Gate (unchanged function, unchanged behavior for every
 *      existing job_type) run against the raw headline, advisory only.
 *   3. growth_agent AI writes a full ORIGINAL article — the prompt gives
 *      it only the headline text (never scraped body content, see this
 *      section's own top note), same copyright boundary as
 *      cms_growth_agent_generate_article_idea().
 *   4. Title-vs-headline "aturan keras" check (unchanged function) against
 *      the ONE headline actually used.
 *   5. Cover image via image_agent — wrapped in its own try/catch,
 *      completely independent of steps 1-4 succeeding: an image failure
 *      (rate limit, API error, unconfigured agent) never blocks the draft
 *      itself, it just ships without one and the failure reason rides
 *      along in output_json.cover_image_error for the operator to see.
 *
 * Logged status='succeeded' (not 'manual_action') — same convention as
 * cms_growth_agent_generate_article_idea(): this is a proposal awaiting
 * human review, not something requiring the manual_action tier's own
 * distinct pattern (which internal_link_suggestion uses because that
 * approve path needs a dedicated review page, not a generic list). Shows
 * up in Perlu Tindakan through the existing generic "succeeded, zero
 * feedback rows" query — no new UI query needed.
 *
 * Fase G (9 Aug 2026, docs/DECISIONS.md) — AFTER the job above is logged
 * 'succeeded', if opportunity_thresholds_json.auto_draft_automation.
 * auto_publish is true, cms_growth_agent_auto_publish_draft() below runs
 * and MAY flip the resulting pages row straight to status='published'
 * with zero human review. This is a deliberate, operator-approved
 * exception ONLY for this job_type — every other job_type in this
 * codebase still requires human approval via the Action Queue, and
 * auto_publish defaults to false (old draft-and-wait-for-approval
 * behavior unchanged unless an operator opts in).
 *
 * @return array{ok:bool,job_id:int,error:string}
 */
function cms_growth_agent_generate_auto_draft_article(PDO $pdo): array
{
    try {
        cms_growth_agent_ensure_schema($pdo);
        require_once __DIR__ . '/ai-helpers.php';

        $candidates = cms_growth_agent_get_trending_headlines_for_prompt($pdo);
        if ($candidates === []) {
            return ['ok' => false, 'job_id' => 0, 'error' => 'Tidak ada headline tren yang tersedia (belum di-fetch, atau semua sudah tumpang tindih artikel published).'];
        }

        $usedUrls = [];
        try {
            $usedRows = $pdo->query("SELECT input_brief FROM growth_agent_jobs WHERE job_type = 'auto_draft_article'")->fetchAll();
            foreach ($usedRows as $row) {
                $brief = json_decode((string) $row['input_brief'], true);
                if (is_array($brief) && !empty($brief['source_url'])) {
                    $usedUrls[(string) $brief['source_url']] = true;
                }
            }
        } catch (Throwable $e) {
            // If this scan fails, worst case is re-proposing an already-used
            // headline once — not worth aborting generation over.
        }

        $selected = null;
        foreach ($candidates as $candidate) {
            if (!isset($usedUrls[$candidate['url']])) {
                $selected = $candidate;
                break;
            }
        }
        if ($selected === null) {
            return ['ok' => false, 'job_id' => 0, 'error' => 'Semua headline tren yang tersedia sudah pernah dipakai untuk draft otomatis.'];
        }

        $headline = $selected['headline'];

        $defaultSystemPrompt =
            'You are the Growth Agent content strategist for an Indonesian-language sports news portal. You are ' .
            'given ONE recent news headline as context/inspiration only — you do NOT have the source article\'s ' .
            'body text, and must NEVER attempt to reconstruct or guess its specific quotes/details. Write a ' .
            'COMPLETE, ORIGINAL news or analysis article in Bahasa Indonesia inspired by the headline\'s general ' .
            'topic — a real title, and a full body (return as clean HTML using only <p>, <h2>, <h3>, <ul>, <li>, ' .
            '<strong>, <em> tags, no inline styles, no markdown). If the prompt includes a section listing ' .
            'similar existing articles on this site, take a DIFFERENT angle — do not duplicate. Your title must ' .
            'NOT be identical or near-identical to the source headline — reword/reframe it as your own. Also ' .
            'write: an excerpt (1-2 sentences summarizing the article), a meta_title (max 60 characters, ' .
            'SEO-friendly), a meta_description (max 155 characters, SEO-friendly), and a category_slug. ' .
            'category_slug MUST be chosen based on what the article you just wrote is ACTUALLY ABOUT — never ' .
            'pick a "safe" generic option just because it is always technically valid. Pick the SINGLE best ' .
            'match from the "Valid category_slug options" list given in the prompt, using its exact slug value, ' .
            'never invent one — e.g. transfer rumors/news go to a transfer-themed category, tactical/opinion ' .
            'pieces go to an analysis-themed category, match-result recaps go to a results-themed category; if ' .
            'genuinely nothing fits, use an empty string. Respond with ONLY a raw JSON object, no markdown, no ' .
            'code fences, no commentary, in exactly this shape: {"title": "...", "body_html": "...", "excerpt": ' .
            '"...", "meta_title": "...", "meta_description": "...", "category_slug": "..."}';

        $agent = cms_ai_resolve_agent($pdo, 'growth_agent', $defaultSystemPrompt);
        if (!$agent['ok']) {
            return ['ok' => false, 'job_id' => 0, 'error' => $agent['error']];
        }

        $growthContext = '';
        try {
            require_once dirname(__DIR__, 2) . '/services/GrowthAgentPromptBuilder.php';
            $growthContext = trim((new GrowthAgentPromptBuilder($pdo))->buildContext('growth_agent', 'auto_draft_article'));
        } catch (Throwable $e) {
            // Ignore — proceeds on the agent's own system prompt.
        }
        $systemPrompt = $growthContext !== ''
            ? trim($agent['system_prompt'] . "\n\n" . $growthContext)
            : $agent['system_prompt'];

        $userPromptParts = [
            "Source headline (context/inspiration only — do NOT copy or reconstruct its content, you were not given its body text):\n" . $headline,
        ];

        // Same proactive collision-avoidance context as generate_article_idea()
        // (§ 5) — reuses the exact same helper, same threshold config key.
        $articleIdeaThresholds = [];
        try {
            require_once __DIR__ . '/gsc-api.php';
            $articleIdeaThresholds = cms_gsc_get_opportunity_thresholds($pdo)['article_idea'] ?? [];
        } catch (Throwable $e) {
            // Ignore — falls through to the defaults below.
        }
        $minOverlap = (float) ($articleIdeaThresholds['min_overlap_threshold'] ?? 0.5);
        $contextLimit = max(1, min(30, (int) ($articleIdeaThresholds['context_articles_limit'] ?? 8)));
        $similarArticles = cms_growth_agent_find_similar_published_articles($pdo, $headline, $minOverlap, $contextLimit);
        if ($similarArticles !== []) {
            $similarLines = [];
            foreach ($similarArticles as $similar) {
                $desc = $similar['meta_description'] !== '' ? $similar['meta_description'] : $similar['excerpt'];
                $similarLines[] = '- ' . $similar['title'] . ($desc !== '' ? ' | ' . $desc : '');
            }
            $userPromptParts[] =
                "Existing published articles on this site already close to this topic (take a DIFFERENT angle):\n"
                . implode("\n", $similarLines);
        }

        // category_slug picklist (9 Aug 2026, fix: auto-draft articles were
        // shipping with empty category_id, hiding them from category
        // pages entirely; sport_key concept removed entirely 12 Aug 2026
        // per Opsi A — this site has no livescore/sports API integration,
        // see CLAUDE.md). Queried FRESH on every call, same table/columns/
        // ordering pages.php itself uses for its own dropdown — never
        // hardcoded, so an operator adding or renaming a category takes
        // effect on the very next generate with no code change.
        // category_slug is deliberately NOT re-validated here —
        // cms_growth_agent_create_article_draft_from_auto_draft()
        // re-queries and resolves it at Approve time instead, since a
        // category an operator deletes between generation and approval
        // must not silently resurrect it.
        $categoryOptionLines = [];
        try {
            $categoryRows = $pdo->query('SELECT id, name, slug FROM article_categories ORDER BY name ASC')->fetchAll();
        } catch (Throwable $e) {
            $categoryRows = [];
        }
        foreach ($categoryRows as $categoryRow) {
            $categoryOptionLines[] = '- ' . $categoryRow['slug'] . ' | ' . $categoryRow['name'];
        }

        $userPromptParts[] = $categoryOptionLines !== []
            ? "Valid category_slug options (format: slug | name):\n" . implode("\n", $categoryOptionLines)
            : "Valid category_slug options: none configured yet — use an empty string for category_slug.";

        $userPrompt = implode("\n\n", $userPromptParts);

        // SEO-G0 Gate — unchanged function/behavior, run against the raw
        // headline (same "check the topic itself, not the AI's eventual
        // title" reasoning as generate_article_idea()).
        $gateResult = cms_growth_agent_seo_g0_gate($pdo, 'auto_draft_article', $headline);

        $inputBrief = [
            'source_headline' => $headline,
            'source_url' => $selected['url'],
            'source' => $selected['source'],
            // Same key cms_growth_agent_seo_g0_gate()'s own cross-dedup
            // check reads for every non-gsc_article_idea job_type — see
            // that function's updated job_type IN() list above.
            'missing_topic' => $headline,
            'seo_g0_gate' => $gateResult,
            'similar_published_articles' => array_map(
                static fn (array $a): array => ['page_id' => $a['page_id'], 'title' => $a['title'], 'coefficient' => round($a['coefficient'], 2)],
                $similarArticles
            ),
        ];

        try {
            $result = cms_ai_call_provider(
                $agent['provider'], $agent['api_key'], $agent['model'],
                $userPrompt, $systemPrompt, max($agent['max_tokens'], 2000), $agent['temperature']
            );
        } catch (Throwable $e) {
            return ['ok' => false, 'job_id' => 0, 'error' => $e->getMessage()];
        }

        $parsed = $result['success'] ? cms_ai_extract_json($result['text']) : null;

        if (!$result['success'] || !is_array($parsed) || !isset($parsed['title'], $parsed['body_html'])) {
            $errorMessage = $result['success'] ? 'AI response was not in the expected format' : ('AI request failed: ' . $result['error']);
            $jobId = cms_growth_agent_log_job(
                $pdo, 'auto_draft_article', 'growth_agent', null, 'failed', $inputBrief, null,
                $agent['model'], null, null, $result['latency_ms'] ?? null, $errorMessage, 'medium'
            );
            return ['ok' => false, 'job_id' => $jobId, 'error' => $errorMessage];
        }

        $title = (string) $parsed['title'];
        $bodyHtml = (string) $parsed['body_html'];

        // excerpt/meta_title/meta_description/category_slug (9 Aug 2026
        // fix; sport_key removed entirely 12 Aug 2026 per Opsi A) — all
        // optional in the AI response (unlike title/body_html above),
        // cms_growth_agent_create_article_draft_from_auto_draft() applies
        // its own fallbacks for whichever of these come back empty.
        $excerpt = trim((string) ($parsed['excerpt'] ?? ''));
        $metaTitle = trim((string) ($parsed['meta_title'] ?? ''));
        $metaDescription = trim((string) ($parsed['meta_description'] ?? ''));
        $categorySlug = trim((string) ($parsed['category_slug'] ?? ''));

        // "Aturan keras" — checked against the ONE headline this prompt
        // actually used, unchanged function.
        $inputBrief['title_vs_headline_check'] = cms_growth_agent_check_title_vs_headlines($pdo, $title, [$selected]);

        // Cover image — completely independent failure domain. Never lets
        // an image problem block or fail the draft itself.
        $coverImagePath = null;
        $coverImageError = null;
        try {
            $imageAgent = cms_ai_resolve_agent($pdo, 'image_agent', '');
            if (!$imageAgent['ok']) {
                $coverImageError = $imageAgent['error'];
            } else {
                $imagePrompt = cms_growth_agent_build_cover_image_prompt($pdo, $title);
                // Model/quality per GROWTH_AGENT_V2_PROPOSAL.md § 6: Mini,
                // medium quality by default — flagship is far more
                // expensive for a first-pass cover image an editor may
                // still replace before publish.
                $imageResult = cms_ai_call_openai_image($imageAgent['api_key'], $imageAgent['model'], $imagePrompt, 'medium');
                if (!$imageResult['success']) {
                    $coverImageError = $imageResult['error'];
                } else {
                    $coverImagePath = cms_growth_agent_save_generated_image($pdo, $imageResult['b64_data'], $title);
                    if ($coverImagePath === null) {
                        $coverImageError = 'Gambar berhasil digenerate tapi gagal disimpan ke disk.';
                    }
                }
            }
        } catch (Throwable $e) {
            $coverImageError = $e->getMessage();
        }

        // Fallback sementara (8 Aug 2026) — operator mau draft yang
        // kepublish tetap punya cover, bukan kosong, selama image_agent
        // belum dikonfigurasi API key-nya. cover_image_error TETAP disimpan
        // apa adanya di bawah, jadi UI masih bisa bedain "gambar AI asli"
        // vs "fallback logo situs" via cover_image_is_fallback.
        $coverImageIsFallback = $coverImagePath === null;
        if ($coverImageIsFallback) {
            $coverImagePath = '/assets/img/logo.png';
        }

        $output = [
            'title' => $title,
            'body_html' => $bodyHtml,
            'excerpt' => $excerpt,
            'meta_title' => $metaTitle,
            'meta_description' => $metaDescription,
            'category_slug' => $categorySlug,
            'cover_image_path' => $coverImagePath,
            'cover_image_error' => $coverImageError,
            'cover_image_is_fallback' => $coverImageIsFallback,
        ];

        $jobId = cms_growth_agent_log_job(
            $pdo, 'auto_draft_article', 'growth_agent', null, 'succeeded', $inputBrief, $output,
            $agent['model'], null, null, $result['latency_ms'] ?? null, '', 'medium'
        );

        // Fase G — see this function's own docblock note above. Runs AFTER
        // the job is already logged 'succeeded', so a publish failure here
        // never loses the generated draft — cms_growth_agent_auto_publish_draft()
        // never throws, it records auto_publish_error in output_json instead.
        require_once __DIR__ . '/gsc-api.php';
        $autoPublish = (bool) (cms_gsc_get_opportunity_thresholds($pdo)['auto_draft_automation']['auto_publish'] ?? false);
        if ($autoPublish) {
            cms_growth_agent_auto_publish_draft($pdo, $jobId, $output);
        }

        return ['ok' => true, 'job_id' => $jobId, 'error' => ''];
    } catch (Throwable $e) {
        error_log('[cms_growth_agent_generate_auto_draft_article] ' . $e->getMessage());
        return ['ok' => false, 'job_id' => 0, 'error' => $e->getMessage()];
    }
}

/**
 * Fase G (9 Aug 2026, docs/DECISIONS.md) — full auto-publish for
 * `auto_draft_article`, gated entirely on opportunity_thresholds_json.
 * auto_draft_automation.auto_publish (default false). Called ONLY from
 * cms_growth_agent_generate_auto_draft_article(), AFTER the job is already
 * logged 'succeeded' — so any failure in here (bad title/body, DB error,
 * whatever) never loses the generated draft: it stays a normal succeeded
 * job the operator can still Approve manually from auto-draft-review.php,
 * same as if auto_publish were off. The failure reason rides along in
 * output_json.auto_publish_error for the operator to see why the toggle
 * didn't do what it says on the tin this one time.
 *
 * Reuses cms_growth_agent_create_article_draft_from_auto_draft() (same
 * function the manual Approve button calls) to actually create the
 * `pages` row, passing $forAutoPublish=true (9 Aug 2026 fix — see that
 * function's own docblock) so the reviewer-facing disclaimer paragraph
 * never ships as permanent public-facing text on an article nobody is
 * going to review. This function's only remaining job is (1) picking an
 * author for a run that has no admin session to pull one from, and (2)
 * flipping the resulting row from 'draft' to 'published' plus re-running
 * the sitemap upsert so it reflects 'published' status (the create-draft
 * function itself always upserts as 'draft', by design — see its own
 * docblock).
 *
 * Author fallback: no admin session exists on this code path (cron-
 * triggered, nobody clicked anything), so this picks the lowest admin_id
 * active superadmin as the "system" author — deterministic, no new config
 * field needed. If that changes, add a dedicated
 * auto_draft_automation.publish_author_id config key instead of guessing
 * further down this list.
 *
 * Never throws — every failure path is caught and recorded, never
 * propagated to the caller (which has already returned success for the
 * generation itself by the time this runs).
 */
function cms_growth_agent_auto_publish_draft(PDO $pdo, int $jobId, array $output): void
{
    $recordError = static function (string $message) use ($pdo, $jobId): void {
        try {
            $pdo->prepare(
                "UPDATE growth_agent_jobs SET output_json = JSON_SET(output_json, '$.auto_publish_error', :msg), updated_at = NOW() WHERE id = :id"
            )->execute(['msg' => $message, 'id' => $jobId]);
        } catch (Throwable $e) {
            error_log('[cms_growth_agent_auto_publish_draft] Failed to record auto_publish_error: ' . $e->getMessage());
        }
    };

    try {
        $authorStmt = $pdo->query(
            "SELECT admin_id FROM admins WHERE role = 'superadmin' AND is_active = 1 ORDER BY admin_id ASC LIMIT 1"
        );
        $authorId = (int) $authorStmt->fetchColumn() ?: null;

        $draftResult = cms_growth_agent_create_article_draft_from_auto_draft(
            $pdo, ['id' => $jobId, 'output_json' => json_encode($output)], $authorId, true
        );

        if (!$draftResult['ok']) {
            $recordError('Gagal membuat draft: ' . $draftResult['error']);
            return;
        }

        $pageId = $draftResult['page_id'];
        // wpm_now_wib(), NOT date() — same UTC-vs-WIB bug already fixed
        // once in cms_growth_agent_cron_matches() (commit 3f23a35, docs/
        // DECISIONS.md): this server's PHP CLI default timezone is UTC,
        // and published_at is a value an operator actually reads in
        // pages.php, not just an internal comparison — date() without an
        // explicit timezone silently stored a WIB-minus-7h timestamp.
        require_once dirname(__DIR__, 2) . '/includes/TimeHelpers.php';
        $publishedAt = wpm_now_wib()->format('Y-m-d H:i:s');
        $pdo->prepare("UPDATE pages SET status = 'published', published_at = :published_at, updated_at = NOW() WHERE page_id = :id")
            ->execute(['published_at' => $publishedAt, 'id' => $pageId]);

        // Re-upsert into the sitemap as 'published' — the create-draft call
        // above already upserted it once as 'draft' (its own hardcoded
        // status), which would otherwise leave the sitemap saying "draft"
        // for an article that's actually live.
        try {
            $pageStmt = $pdo->prepare('SELECT title, slug, featured_image FROM pages WHERE page_id = :id LIMIT 1');
            $pageStmt->execute(['id' => $pageId]);
            $pageRow = $pageStmt->fetch();
            if ($pageRow) {
                cms_sitemap_on_article_save($pdo, ['status' => 'draft'], [
                    'page_id' => $pageId,
                    'title' => $pageRow['title'],
                    'slug' => $pageRow['slug'],
                    'featured_image' => $pageRow['featured_image'],
                    'status' => 'published',
                    'published_at' => $publishedAt,
                    'noindex' => 0,
                    'canonical_url' => null,
                ]);
            }
        } catch (Throwable $e) {
            error_log('[cms_growth_agent_auto_publish_draft] Sitemap re-upsert failed: ' . $e->getMessage());
        }

        $pdo->prepare('UPDATE growth_agent_jobs SET page_id = :page_id, updated_at = NOW() WHERE id = :id')
            ->execute(['page_id' => $pageId, 'id' => $jobId]);

        $currentAdminId = $authorId; // same id used as author — see docblock's "system author" note.
        $pdo->prepare(
            'INSERT INTO growth_agent_feedback (job_id, action, reviewed_by, created_at) VALUES (:job_id, :action, :reviewed_by, NOW())'
        )->execute(['job_id' => $jobId, 'action' => 'auto_applied', 'reviewed_by' => $currentAdminId]);
    } catch (Throwable $e) {
        error_log('[cms_growth_agent_auto_publish_draft] ' . $e->getMessage());
        $recordError('Exception: ' . $e->getMessage());
    }
}

/**
 * Content Agent Adapter for `auto_draft_article` — same "Approve IS
 * execute" deliberate exception as
 * cms_growth_agent_create_article_draft_from_idea() (see that function's
 * own docblock for why), extended for a job type that already carries a
 * full body + optional cover image rather than just a title+outline stub.
 * Still only ever produces a DRAFT — publish stays separate and fully
 * manual when called from the manual Approve path
 * (growth-agent.php/auto-draft-review.php); cms_growth_agent_auto_publish_draft()
 * above is the ONLY caller allowed to flip the result to 'published'
 * afterward, and only when auto_draft_automation.auto_publish is on.
 *
 * $forAutoPublish (9 Aug 2026 fix, default false) controls ONLY whether
 * the "WAJIB dibaca dan diedit sebelum publish..." disclaimer paragraph
 * gets prepended to content — that disclaimer is written for a human
 * about to review this draft, so it must never be attached when the
 * caller is going to flip straight to published with no review
 * (cms_growth_agent_auto_publish_draft() is the only caller that passes
 * true). Every manual-approve caller (growth-agent.php's generic handler,
 * auto-draft-review.php) leaves this at its default false, unchanged
 * behavior.
 *
 * @return array{ok:bool,page_id:int,error:string}
 */
function cms_growth_agent_create_article_draft_from_auto_draft(PDO $pdo, array $job, ?int $authorId, bool $forAutoPublish = false): array
{
    try {
        $output = json_decode((string) ($job['output_json'] ?? ''), true);
        $title = is_array($output) ? trim((string) ($output['title'] ?? '')) : '';
        $bodyHtml = is_array($output) ? trim((string) ($output['body_html'] ?? '')) : '';
        if ($title === '' || $bodyHtml === '') {
            return ['ok' => false, 'page_id' => 0, 'error' => 'Job output tidak berisi title/body yang valid.'];
        }
        $featuredImage = is_array($output) ? trim((string) ($output['cover_image_path'] ?? '')) : '';

        // excerpt/meta_title/meta_description/category_slug (9 Aug 2026
        // fix; sport_key removed entirely 12 Aug 2026 per Opsi A) —
        // output_json from a job created before this fix (or one where
        // the AI just omitted a field) won't have some/all of these, so
        // every one of them gets a real fallback here rather than landing
        // NULL/empty in `pages` — this function is the single place that
        // actually builds the INSERT, so it's the right spot to guarantee
        // that regardless of what generated the job.
        $excerpt = is_array($output) ? trim((string) ($output['excerpt'] ?? '')) : '';
        $metaTitle = is_array($output) ? trim((string) ($output['meta_title'] ?? '')) : '';
        $metaDescription = is_array($output) ? trim((string) ($output['meta_description'] ?? '')) : '';
        $categorySlug = is_array($output) ? trim((string) ($output['category_slug'] ?? '')) : '';

        require_once __DIR__ . '/functions.php';
        require_once __DIR__ . '/sitemap-service.php';

        $plainTextFallback = trim(preg_replace('/\s+/u', ' ', strip_tags($bodyHtml)) ?? '');
        if ($excerpt === '') {
            $excerpt = mb_substr($plainTextFallback, 0, 160);
        }
        if ($metaTitle === '') {
            $metaTitle = mb_substr($title, 0, 60);
        }
        if ($metaDescription === '') {
            $metaDescription = mb_substr($plainTextFallback, 0, 160);
        }

        // category_slug -> category_id — resolved fresh HERE (Approve/
        // publish time), not reused from whatever was true at generation
        // time, specifically so a category deleted by the operator in
        // between can't silently get resurrected or mis-assigned. No
        // auto-create: an unmatched slug (AI hallucination, or a category
        // that existed at generation time but was deleted since) always
        // falls back to NULL ("No category"), never a crash.
        $categoryId = null;
        if ($categorySlug !== '') {
            try {
                $catStmt = $pdo->prepare('SELECT id FROM article_categories WHERE slug = :slug LIMIT 1');
                $catStmt->execute(['slug' => $categorySlug]);
                $categoryId = (int) $catStmt->fetchColumn() ?: null;
            } catch (Throwable $e) {
                $categoryId = null;
            }
        }

        $slugBase = cms_slugify($title);
        if ($slugBase === '') {
            $slugBase = 'draft-otomatis-' . (int) $job['id'];
        }
        $slug = $slugBase;
        $dupCheck = $pdo->prepare('SELECT COUNT(*) FROM pages WHERE slug = :slug');
        for ($suffix = 2; ; $suffix++) {
            $dupCheck->execute(['slug' => $slug]);
            if ((int) $dupCheck->fetchColumn() === 0) {
                break;
            }
            $slug = $slugBase . '-' . $suffix;
        }

        // Disclaimer is for a human REVIEWER about to click Approve on
        // auto-draft-review.php, not for the public — never attach it when
        // this call is going to flip straight to published with zero
        // review (Fase G, cms_growth_agent_auto_publish_draft()), or it
        // ships as permanent, publicly-readable text on the live article
        // (see docs/DECISIONS.md, 9 Aug 2026 — job 199 leaked this exact
        // paragraph to sagagoal.com before this fix).
        $contentHtml = $forAutoPublish
            ? $bodyHtml
            : '<p><em>Draft dibuat otomatis oleh Growth Agent (Full Draft Automation, Fase F) — WAJIB dibaca dan diedit sebelum publish, jangan asumsikan semua fakta akurat (resiko halusinasi AI).</em></p>'
                . $bodyHtml;

        $payload = [
            'title' => $title,
            'slug' => $slug,
            'content' => $contentHtml,
            'excerpt' => $excerpt,
            'meta_title' => $metaTitle,
            'meta_description' => $metaDescription,
            'category_id' => $categoryId,
            'featured_image' => $featuredImage !== '' ? $featuredImage : null,
            'status' => 'draft',
            'author_id' => $authorId,
        ];

        $insert = $pdo->prepare(
            'INSERT INTO pages (title, slug, content, excerpt, meta_title, meta_description, category_id, featured_image, status, author_id, created_at, updated_at)
             VALUES (:title, :slug, :content, :excerpt, :meta_title, :meta_description, :category_id, :featured_image, :status, :author_id, NOW(), NOW())'
        );
        $insert->execute($payload);
        $pageId = (int) $pdo->lastInsertId();

        try {
            cms_sitemap_ensure_schema($pdo);
            cms_sitemap_on_article_save($pdo, [], $payload + [
                'page_id' => $pageId,
                'noindex' => 0,
                'canonical_url' => null,
                'published_at' => null,
            ]);
        } catch (Throwable $e) {
            error_log('[cms_growth_agent_create_article_draft_from_auto_draft] Sitemap upsert failed: ' . $e->getMessage());
        }

        return ['ok' => true, 'page_id' => $pageId, 'error' => ''];
    } catch (Throwable $e) {
        return ['ok' => false, 'page_id' => 0, 'error' => $e->getMessage()];
    }
}

/**
 * Minimal 5-field cron expression matcher (minute hour day-of-month month
 * day-of-week) — supports `*` and comma-separated lists only (e.g.
 * "0 6,12,18 * * *"), deliberately NOT ranges ("1-5") or step values (every N units):
 * the one default schedule this feature ships with only needs comma-lists,
 * and adding syntax nothing currently uses is unjustified complexity. If a
 * future schedule genuinely needs ranges/steps, extend this function then.
 *
 * This does NOT run as a real OS crontab entry — cron/growth_agent_maintenance.php
 * is the actual (and only) system cron trigger for this whole feature set,
 * same as every other *_if_stale() step in it; this function just decides
 * whether THIS particular invocation's current minute matches the
 * configured schedule closely enough to be eligible to fire.
 *
 * Never throws — a malformed expression (wrong field count, non-numeric
 * value) returns false rather than crashing the cron script.
 */
function cms_growth_agent_cron_matches(string $cronExpr, ?DateTimeImmutable $now = null): bool
{
    try {
        require_once __DIR__ . '/../../includes/TimeHelpers.php';
        $now = $now ?? DateTimeImmutable::createFromMutable(wpm_now_wib());
        $fields = preg_split('/\s+/', trim($cronExpr)) ?: [];
        if (count($fields) !== 5) {
            return false;
        }

        [$minuteExpr, $hourExpr, $domExpr, $monthExpr, $dowExpr] = $fields;

        $matches = static function (string $expr, int $value): bool {
            if ($expr === '*') {
                return true;
            }
            $allowed = array_map('intval', explode(',', $expr));
            return in_array($value, $allowed, true);
        };

        return $matches($minuteExpr, (int) $now->format('i'))
            && $matches($hourExpr, (int) $now->format('G'))
            && $matches($domExpr, (int) $now->format('j'))
            && $matches($monthExpr, (int) $now->format('n'))
            && $matches($dowExpr, (int) $now->format('w'));
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Fase H scheduler gate for Fase F — the ONLY place
 * cms_growth_agent_generate_auto_draft_article() is called from. Checked
 * in this order, first failure short-circuits with no generation attempt
 * (cheapest checks first, same convention as every other multi-condition
 * gate in this file):
 *   1. opportunity_thresholds_json.auto_draft_automation.enabled === true
 *      (ships false — see that config block's own note).
 *   2. schedule_cron matches the current minute (cms_growth_agent_cron_matches()).
 *   3. max_drafts_per_day (8 Aug 2026, requested by project owner) — a hard
 *      daily cap INDEPENDENT of how many hours are checked in
 *      schedule_cron, so an operator can schedule many hourly attempts
 *      while still bounding how many drafts land in the review queue per
 *      day. Counts every 'auto_draft_article' job created today
 *      (MySQL server-local CURDATE(), which this host runs in UTC — NOT
 *      the same "today" boundary as cms_growth_agent_cron_matches(), which
 *      as of 8 Aug 2026 uses wpm_now_wib()/Asia-Jakarta instead of PHP's
 *      UTC default tz. So this cap's day resets at UTC midnight, i.e.
 *      07:00 WIB, ~7h after the WIB schedule itself rolls to a new day.
 *      Known gap, not yet reconciled), regardless of status — a failed generation still
 *      consumed one AI call attempt, so it still counts against the cap.
 *      0 = no cap (an operator's explicit choice, not the shipped default
 *      — see that config key's own note on why the default is 3, not 0).
 *   4. This exact minute hasn't already triggered a run (gsc_settings.
 *      last_auto_draft_run_at) — guards against the underlying system cron
 *      invoking this script more often than the configured schedule.
 *
 * Deliberately NOT wired into growth-agent.php's page load like the
 * lighter *_if_stale() features (Trending Headlines refresh, Measurement
 * Loop) — those are cheap reads/aggregates, this triggers a real paid AI
 * call plus an image-generation call. Only cron/growth_agent_maintenance.php
 * calls this, so opening the admin dashboard can never accidentally
 * trigger billable generation.
 *
 * Never throws.
 *
 * @return array{ran:bool,reason:string,job_id:int}
 */
function cms_growth_agent_maybe_generate_auto_draft(PDO $pdo): array
{
    try {
        require_once __DIR__ . '/gsc-api.php';
        cms_gsc_ensure_schema($pdo);
        $config = cms_gsc_get_opportunity_thresholds($pdo)['auto_draft_automation'] ?? [];

        if (($config['enabled'] ?? false) !== true) {
            return ['ran' => false, 'reason' => 'auto_draft_automation.enabled is not true', 'job_id' => 0];
        }

        $cronExpr = trim((string) ($config['schedule_cron'] ?? ''));
        if ($cronExpr === '' || !cms_growth_agent_cron_matches($cronExpr)) {
            return ['ran' => false, 'reason' => 'current time does not match schedule_cron', 'job_id' => 0];
        }

        $maxPerDay = (int) ($config['max_drafts_per_day'] ?? 0);
        if ($maxPerDay > 0) {
            $todayCount = (int) $pdo->query(
                "SELECT COUNT(*) FROM growth_agent_jobs
                  WHERE job_type = 'auto_draft_article' AND DATE(created_at) = CURDATE()"
            )->fetchColumn();
            if ($todayCount >= $maxPerDay) {
                return ['ran' => false, 'reason' => "daily limit reached ({$todayCount}/{$maxPerDay})", 'job_id' => 0];
            }
        }

        $settings = cms_gsc_get_settings($pdo);
        $lastRun = $settings['last_auto_draft_run_at'] ?? null;
        $nowMinute = (new DateTimeImmutable('now'))->format('Y-m-d H:i');
        if ($lastRun !== null && date('Y-m-d H:i', strtotime((string) $lastRun)) === $nowMinute) {
            return ['ran' => false, 'reason' => 'already ran for this exact minute', 'job_id' => 0];
        }

        $pdo->prepare('UPDATE gsc_settings SET last_auto_draft_run_at = NOW() ORDER BY id ASC LIMIT 1')->execute();

        $result = cms_growth_agent_generate_auto_draft_article($pdo);

        return ['ran' => true, 'reason' => $result['ok'] ? 'generated' : ('generation failed: ' . $result['error']), 'job_id' => $result['job_id']];
    } catch (Throwable $e) {
        error_log('[cms_growth_agent_maybe_generate_auto_draft] ' . $e->getMessage());
        return ['ran' => false, 'reason' => 'exception: ' . $e->getMessage(), 'job_id' => 0];
    }
}
