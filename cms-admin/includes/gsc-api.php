<?php
declare(strict_types=1);

/**
 * Google Search Console integration for Growth Agent.
 *
 * Ported 27 Jul 2026 from the sibling SagaCrypto project (wpm.sagacrypto.com,
 * this project's crypto-news predecessor) — that codebase already had a
 * working, tuned version of this exact feature. Logic and thresholds carry
 * over unchanged (none of it is crypto-specific, it's generic Search
 * Console + scoring); only user-facing copy referencing the old site was
 * adapted (see growth-agent-service.php for the generate-function prompts).
 *
 * No cron in this codebase — cms_gsc_fetch_if_stale() is called lazily from
 * pages/growth-agent.php instead, same "self-maintaining on request" spirit
 * as cms_ensure_table(). fetch_lookback_days defaults to 14 (not "just
 * yesterday") specifically to backfill safely across however long a gap
 * between page visits turns out to be.
 *
 * No Google API Client Library / Composer — this hand-rolls the service
 * account JWT bearer flow (openssl_sign(), already a hard dependency via
 * cms_ai_encrypt()/cms_ai_decrypt() in ai-helpers.php) plus plain cURL REST
 * calls, matching this codebase's existing provider-agnostic pattern of
 * "settings in a DB table, no SDK".
 *
 * Credential storage: the full service account JSON is encrypted at rest
 * via cms_ai_encrypt() (reused from ai-helpers.php, same AES-256-CBC
 * already protecting ai_credentials.api_key_enc) — never decrypted back to
 * the UI after saving, same rule as AI Credentials.
 */

require_once __DIR__ . '/schema-guard.php';

if (!function_exists('cms_gsc_ensure_schema')) {
    function cms_gsc_ensure_schema(PDO $pdo): void
    {
        $created = cms_ensure_table(
            $pdo,
            'gsc_settings',
            "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
             service_account_email VARCHAR(255) DEFAULT NULL,
             service_account_json_enc LONGTEXT DEFAULT NULL,
             site_url VARCHAR(255) DEFAULT NULL,
             is_active TINYINT(1) NOT NULL DEFAULT 0,
             fetch_lookback_days INT UNSIGNED NOT NULL DEFAULT 14,
             fetch_window_days INT UNSIGNED NOT NULL DEFAULT 90,
             opportunity_thresholds_json LONGTEXT DEFAULT NULL,
             memory_thresholds_json LONGTEXT DEFAULT NULL,
             last_memory_detection_at TIMESTAMP NULL DEFAULT NULL,
             last_fetch_status VARCHAR(20) DEFAULT NULL,
             last_fetch_message VARCHAR(255) DEFAULT NULL,
             last_fetch_rows INT UNSIGNED DEFAULT NULL,
             last_fetch_at TIMESTAMP NULL DEFAULT NULL,
             created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
             updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
        );
        if ($created) {
            $pdo->exec('INSERT INTO gsc_settings (is_active) VALUES (0)');
        }
        cms_ensure_column($pdo, 'gsc_settings', 'opportunity_thresholds_json', 'LONGTEXT DEFAULT NULL AFTER `fetch_window_days`');
        cms_ensure_column($pdo, 'gsc_settings', 'memory_thresholds_json', 'LONGTEXT DEFAULT NULL AFTER `opportunity_thresholds_json`');
        cms_ensure_column($pdo, 'gsc_settings', 'last_memory_detection_at', 'TIMESTAMP NULL DEFAULT NULL AFTER `memory_thresholds_json`');
        // Feedback Loop (ROADMAP.md gap #4, closed 28 Jul 2026) — lazy-trigger
        // bookkeeping for cms_growth_agent_snapshot_performance_if_stale(),
        // same convention as last_fetch_at/last_memory_detection_at.
        cms_ensure_column($pdo, 'gsc_settings', 'last_performance_snapshot_at', 'TIMESTAMP NULL DEFAULT NULL AFTER `last_memory_detection_at`');
        // Trending Headlines (GROWTH_AGENT_V2_PROPOSAL.md § 5, 6 Aug 2026) —
        // same lazy-trigger bookkeeping convention, for
        // cms_growth_agent_refresh_trending_headlines_if_stale() in
        // growth-agent-service.php.
        cms_ensure_column($pdo, 'gsc_settings', 'last_trending_headlines_refresh_at', 'TIMESTAMP NULL DEFAULT NULL AFTER `last_performance_snapshot_at`');
        // Full Draft Automation scheduler (GROWTH_AGENT_V2_PROPOSAL.md § 6,
        // Fase H, 8 Aug 2026) — records the last MINUTE a scheduled draft
        // generation actually ran, so the cron step (which may itself be
        // invoked more often than the configured schedule) never fires
        // twice for the same matching minute. See
        // cms_growth_agent_maybe_generate_auto_draft() in
        // growth-agent-service.php.
        cms_ensure_column($pdo, 'gsc_settings', 'last_auto_draft_run_at', 'TIMESTAMP NULL DEFAULT NULL AFTER `last_trending_headlines_refresh_at`');

        cms_ensure_table(
            $pdo,
            'gsc_query_data',
            "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
             query VARCHAR(255) NOT NULL,
             page_url VARCHAR(500) NOT NULL,
             matched_page_id INT UNSIGNED DEFAULT NULL,
             clicks INT UNSIGNED NOT NULL DEFAULT 0,
             impressions INT UNSIGNED NOT NULL DEFAULT 0,
             ctr DECIMAL(7,4) DEFAULT NULL,
             position DECIMAL(6,2) DEFAULT NULL,
             data_date DATE NOT NULL,
             dedupe_hash CHAR(32) NOT NULL,
             fetched_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
             UNIQUE KEY uniq_gsc_dedupe_hash (dedupe_hash),
             KEY idx_gsc_page (matched_page_id),
             KEY idx_gsc_date (data_date),
             KEY idx_gsc_query (query(100))"
        );

        cms_ensure_table(
            $pdo,
            'gsc_opportunities',
            "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
             item_type ENUM('page','query') NOT NULL,
             matched_page_id INT UNSIGNED DEFAULT NULL,
             query_text VARCHAR(255) DEFAULT NULL,
             matched_categories VARCHAR(255) NOT NULL DEFAULT '',
             impact_score TINYINT UNSIGNED NOT NULL,
             effort_score TINYINT UNSIGNED NOT NULL,
             priority ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
             recommended_agent VARCHAR(50) NOT NULL,
             recommended_action ENUM('seo_recommendation','gsc_content_optimization','gsc_article_idea') NOT NULL,
             reason TEXT DEFAULT NULL,
             metrics_json TEXT DEFAULT NULL,
             status ENUM('open','actioned') NOT NULL DEFAULT 'open',
             linked_job_id INT UNSIGNED DEFAULT NULL,
             dedupe_key CHAR(32) NOT NULL,
             computed_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
             UNIQUE KEY uniq_gsc_opp_dedupe (dedupe_key),
             KEY idx_gsc_opp_status_priority (status, priority),
             KEY idx_gsc_opp_page (matched_page_id)"
        );

        // Sagagoal's growth_agent_jobs never had the 2-tier ('normal','high')
        // era this column went through in the reference project — a direct
        // 3-tier add is safe here, no widen-migrate-narrow dance needed
        // (see cms_growth_agent_ensure_priority_enum() in
        // growth-agent-service.php for that dance, kept there only for
        // .action's own 2-tier->3-tier-equivalent widen when Close as
        // Legacy is added).
        cms_ensure_column($pdo, 'growth_agent_jobs', 'priority', "ENUM('low','medium','high') NOT NULL DEFAULT 'medium' AFTER `status`");

        // Pre-existing in this codebase as a raw table (only ever written to
        // via wpm_log_api_error() in LivescoreSync.php/FormulaOneSync.php/
        // BasketballSync.php, never created via cms_ensure_table()) — adding
        // the wrapper here makes it self-healing like every other table in
        // this file, and is a safe no-op if the live table already has this
        // shape (cms_ensure_table() only creates when missing).
        cms_ensure_table(
            $pdo,
            'api_error_log',
            'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
             source VARCHAR(30) NOT NULL,
             message TEXT NOT NULL,
             created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
             KEY idx_api_error_source (source)'
        );

        // Indexing Workflow (Phase 5 roadmap, ROADMAP.md gap #2, closed 27
        // Jul 2026) — one row per published page we've inspected via
        // Search Console's URL Inspection API (cms_gsc_inspect_url()
        // below). page_id is UNIQUE so re-inspecting a page always upserts
        // the same row rather than accumulating history; raw_response_json
        // keeps the full indexStatusResult payload for anything the fixed
        // columns don't capture (used when building the deterministic
        // review_indexing_issue checklist — see growth-agent-service.php).
        cms_ensure_table(
            $pdo,
            'gsc_url_inspections',
            "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
             page_id INT UNSIGNED NOT NULL,
             url VARCHAR(500) NOT NULL,
             verdict VARCHAR(30) DEFAULT NULL,
             coverage_state VARCHAR(255) DEFAULT NULL,
             robots_txt_state VARCHAR(30) DEFAULT NULL,
             indexing_state VARCHAR(30) DEFAULT NULL,
             page_fetch_state VARCHAR(30) DEFAULT NULL,
             last_crawl_time DATETIME DEFAULT NULL,
             google_canonical VARCHAR(500) DEFAULT NULL,
             user_canonical VARCHAR(500) DEFAULT NULL,
             sitemap VARCHAR(500) DEFAULT NULL,
             raw_response_json TEXT DEFAULT NULL,
             error_message VARCHAR(255) DEFAULT NULL,
             inspected_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
             UNIQUE KEY uniq_gsc_url_inspection_page (page_id),
             KEY idx_gsc_url_inspection_verdict (verdict)"
        );

        // NOTE: the reference project also creates a `growth_agent_memory`
        // table here for its "Agent Memory" feature (winning_pattern/
        // content_gap insight detection). That feature is out of scope for
        // this port — deliberately not brought over, so no unused table
        // gets created for something nothing writes to.

        // Cannibalization + Content Decay (ROADMAP.md gap #5, closed 28 Jul
        // 2026) — widen-safe ENUM add, same pattern as
        // cms_growth_agent_ensure_legacy_status() in growth-agent-service.php.
        cms_gsc_ensure_cannibalization_action($pdo);
    }
}

if (!function_exists('cms_gsc_ensure_cannibalization_action')) {
    /**
     * Widens gsc_opportunities.recommended_action to add
     * 'cannibalization_review' — cms_ensure_column() can't widen an
     * existing ENUM, only add a missing column, so this checks the live
     * column definition via information_schema first and only ALTERs when
     * the new value isn't already in it. Exact same widen-safe pattern as
     * cms_growth_agent_ensure_legacy_status() in growth-agent-service.php.
     *
     * cannibalization_review is deliberately NOT one of the 3 AI-generation
     * action types (seo_recommendation/gsc_content_optimization/
     * gsc_article_idea) — there is no "Generate" button for it, only
     * "Review" (see cms_growth_agent_log_cannibalization_review() in
     * growth-agent-service.php). Deciding whether to differentiate intent,
     * consolidate, or pick a pillar page is a judgment call this codebase
     * deliberately never lets AI make on its own.
     */
    function cms_gsc_ensure_cannibalization_action(PDO $pdo): void
    {
        $actionType = (string) $pdo->query(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gsc_opportunities' AND COLUMN_NAME = 'recommended_action'"
        )->fetchColumn();
        if ($actionType !== '' && !str_contains($actionType, "'cannibalization_review'")) {
            $pdo->exec("ALTER TABLE `gsc_opportunities` MODIFY COLUMN `recommended_action` ENUM('seo_recommendation','gsc_content_optimization','gsc_article_idea','cannibalization_review') NOT NULL");
        }
    }
}

if (!function_exists('cms_gsc_get_settings')) {
    function cms_gsc_get_settings(PDO $pdo): array
    {
        cms_gsc_ensure_schema($pdo);
        $row = $pdo->query('SELECT * FROM gsc_settings ORDER BY id ASC LIMIT 1')->fetch();
        return $row !== false ? $row : [];
    }
}

if (!function_exists('cms_gsc_log_error')) {
    function cms_gsc_log_error(PDO $pdo, string $message): void
    {
        try {
            cms_ensure_table(
                $pdo,
                'api_error_log',
                'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                 source VARCHAR(30) NOT NULL,
                 message TEXT NOT NULL,
                 created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                 KEY idx_api_error_source (source)'
            );
            $pdo->prepare('INSERT INTO api_error_log (source, message, created_at) VALUES (\'gsc\', :message, NOW())')
                ->execute(['message' => mb_substr($message, 0, 2000)]);
        } catch (Throwable $e) {
            // Logging must never break the caller.
        }
    }
}

if (!function_exists('cms_gsc_upsert_url_inspection')) {
    /**
     * SELECT-then-INSERT/UPDATE upsert keyed on page_id, matching this
     * codebase's existing upsert convention (see cms_sitemap_upsert() in
     * sitemap-service.php) rather than an ON DUPLICATE KEY UPDATE query.
     */
    function cms_gsc_upsert_url_inspection(PDO $pdo, array $data): void
    {
        $existing = $pdo->prepare('SELECT id FROM gsc_url_inspections WHERE page_id = :page_id LIMIT 1');
        $existing->execute(['page_id' => $data['page_id']]);
        $existingId = $existing->fetchColumn();

        $params = [
            'page_id'           => $data['page_id'],
            'url'               => $data['url'],
            'verdict'           => $data['verdict'],
            'coverage_state'    => $data['coverage_state'],
            'robots_txt_state'  => $data['robots_txt_state'],
            'indexing_state'    => $data['indexing_state'],
            'page_fetch_state'  => $data['page_fetch_state'],
            'last_crawl_time'   => $data['last_crawl_time'],
            'google_canonical'  => $data['google_canonical'],
            'user_canonical'    => $data['user_canonical'],
            'sitemap'           => $data['sitemap'],
            'raw_response_json' => $data['raw_response_json'],
            'error_message'     => $data['error_message'],
        ];

        if ($existingId !== false) {
            // page_id is intentionally NOT in this statement's param list —
            // the row is being updated (not re-keyed), the WHERE clause
            // targets it by :id instead. PDO::ATTR_EMULATE_PREPARES is off
            // (see config/database.php), so real prepared statements
            // reject an execute() array containing a key the query never
            // references — passing $params as-is (which still carries
            // 'page_id') would throw "Invalid parameter number".
            $updateParams = $params;
            unset($updateParams['page_id']);
            $updateParams['id'] = $existingId;
            $pdo->prepare(
                'UPDATE gsc_url_inspections
                    SET url = :url, verdict = :verdict, coverage_state = :coverage_state,
                        robots_txt_state = :robots_txt_state, indexing_state = :indexing_state,
                        page_fetch_state = :page_fetch_state, last_crawl_time = :last_crawl_time,
                        google_canonical = :google_canonical, user_canonical = :user_canonical,
                        sitemap = :sitemap, raw_response_json = :raw_response_json,
                        error_message = :error_message, inspected_at = NOW()
                  WHERE id = :id'
            )->execute($updateParams);
        } else {
            $pdo->prepare(
                'INSERT INTO gsc_url_inspections
                    (page_id, url, verdict, coverage_state, robots_txt_state, indexing_state, page_fetch_state,
                     last_crawl_time, google_canonical, user_canonical, sitemap, raw_response_json, error_message, inspected_at)
                 VALUES
                    (:page_id, :url, :verdict, :coverage_state, :robots_txt_state, :indexing_state, :page_fetch_state,
                     :last_crawl_time, :google_canonical, :user_canonical, :sitemap, :raw_response_json, :error_message, NOW())'
            )->execute($params);
        }
    }
}

if (!function_exists('cms_gsc_inspect_url')) {
    /**
     * Indexing Workflow (Phase 5 roadmap, ROADMAP.md gap #2, closed 27 Jul
     * 2026) — calls Search Console's URL Inspection API
     * (urlInspection.index:inspect) for ONE URL and upserts the result
     * into gsc_url_inspections. Read-only against Search Console; reuses
     * the exact same service-account credential/token flow as the GSC
     * Collector (cms_gsc_get_access_token()) — the webmasters.readonly
     * scope already granted is sufficient for this endpoint too, so no new
     * credential or scope is requested.
     *
     * Deliberately does NOT use the separate Google Indexing API
     * (indexing.googleapis.com) — per GROWTH_AGENT_SEO_ROADMAP.md's own
     * guardrail, that API is restricted to JobPosting/livestream content
     * and must never be used for regular articles.
     *
     * Never throws — any failure (no credential, HTTP error, malformed
     * response) is logged via cms_gsc_log_error() and returned as
     * ok:false, matching every other function in this file. On failure
     * with a known $pageId, a row is still upserted with error_message set
     * (and verdict/etc. left null) so the UI can show "last inspection
     * failed" instead of silently showing stale or no data.
     *
     * @return array{ok:bool, data:array, error:string}
     */
    function cms_gsc_inspect_url(PDO $pdo, string $url, ?int $pageId = null): array
    {
        try {
            cms_gsc_ensure_schema($pdo);

            $tokenResult = cms_gsc_get_access_token($pdo);
            if (!$tokenResult['ok']) {
                cms_gsc_log_error($pdo, 'URL Inspection failed (auth) for ' . $url . ': ' . $tokenResult['error']);
                if ($pageId !== null) {
                    cms_gsc_upsert_url_inspection($pdo, [
                        'page_id' => $pageId, 'url' => $url, 'verdict' => null, 'coverage_state' => null,
                        'robots_txt_state' => null, 'indexing_state' => null, 'page_fetch_state' => null,
                        'last_crawl_time' => null, 'google_canonical' => null, 'user_canonical' => null,
                        'sitemap' => null, 'raw_response_json' => null,
                        'error_message' => mb_substr($tokenResult['error'], 0, 255),
                    ]);
                }
                return ['ok' => false, 'data' => [], 'error' => $tokenResult['error']];
            }

            $settings = cms_gsc_get_settings($pdo);
            $siteUrl = (string) ($settings['site_url'] ?? '');
            if ($siteUrl === '') {
                $error = 'GSC property (site_url) belum di-set — lihat GSC Settings.';
                cms_gsc_log_error($pdo, 'URL Inspection failed: ' . $error);
                return ['ok' => false, 'data' => [], 'error' => $error];
            }

            $body = json_encode(['inspectionUrl' => $url, 'siteUrl' => $siteUrl], JSON_UNESCAPED_SLASHES);

            $result = cms_gsc_http_request(
                'POST',
                'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect',
                $body,
                ['Authorization: Bearer ' . $tokenResult['token'], 'Content-Type: application/json']
            );

            if (!$result['ok']) {
                $decoded = json_decode($result['body'], true);
                $detail = is_array($decoded) ? ($decoded['error']['message'] ?? null) : null;
                $error = $detail ?? ($result['error'] ?? 'URL Inspection request failed');
                cms_gsc_log_error($pdo, 'URL Inspection failed for ' . $url . ': ' . $error);

                if ($pageId !== null) {
                    cms_gsc_upsert_url_inspection($pdo, [
                        'page_id' => $pageId, 'url' => $url, 'verdict' => null, 'coverage_state' => null,
                        'robots_txt_state' => null, 'indexing_state' => null, 'page_fetch_state' => null,
                        'last_crawl_time' => null, 'google_canonical' => null, 'user_canonical' => null,
                        'sitemap' => null, 'raw_response_json' => null,
                        'error_message' => mb_substr((string) $error, 0, 255),
                    ]);
                }

                return ['ok' => false, 'data' => [], 'error' => (string) $error];
            }

            $decoded = json_decode($result['body'], true);
            $indexStatus = is_array($decoded['inspectionResult']['indexStatusResult'] ?? null)
                ? $decoded['inspectionResult']['indexStatusResult']
                : [];

            $sitemapList = is_array($indexStatus['sitemap'] ?? null) ? $indexStatus['sitemap'] : [];
            $lastCrawlTime = !empty($indexStatus['lastCrawlTime'])
                ? date('Y-m-d H:i:s', strtotime((string) $indexStatus['lastCrawlTime']))
                : null;

            $data = [
                'verdict'          => (string) ($indexStatus['verdict'] ?? 'VERDICT_UNSPECIFIED'),
                'coverage_state'   => (string) ($indexStatus['coverageState'] ?? ''),
                'robots_txt_state' => (string) ($indexStatus['robotsTxtState'] ?? ''),
                'indexing_state'   => (string) ($indexStatus['indexingState'] ?? ''),
                'page_fetch_state' => (string) ($indexStatus['pageFetchState'] ?? ''),
                'last_crawl_time'  => $lastCrawlTime,
                'google_canonical' => (string) ($indexStatus['googleCanonical'] ?? ''),
                'user_canonical'   => (string) ($indexStatus['userCanonical'] ?? ''),
                'sitemap'          => $sitemapList !== [] ? implode(', ', $sitemapList) : null,
            ];

            if ($pageId !== null) {
                cms_gsc_upsert_url_inspection($pdo, [
                    'page_id'           => $pageId,
                    'url'               => $url,
                    'verdict'           => $data['verdict'],
                    'coverage_state'    => $data['coverage_state'] !== '' ? $data['coverage_state'] : null,
                    'robots_txt_state'  => $data['robots_txt_state'] !== '' ? $data['robots_txt_state'] : null,
                    'indexing_state'    => $data['indexing_state'] !== '' ? $data['indexing_state'] : null,
                    'page_fetch_state'  => $data['page_fetch_state'] !== '' ? $data['page_fetch_state'] : null,
                    'last_crawl_time'   => $data['last_crawl_time'],
                    'google_canonical'  => $data['google_canonical'] !== '' ? $data['google_canonical'] : null,
                    'user_canonical'    => $data['user_canonical'] !== '' ? $data['user_canonical'] : null,
                    'sitemap'           => $data['sitemap'],
                    'raw_response_json' => json_encode($indexStatus, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'error_message'     => null,
                ]);
            }

            return ['ok' => true, 'data' => $data, 'error' => ''];
        } catch (Throwable $e) {
            cms_gsc_log_error($pdo, 'URL Inspection exception for ' . $url . ': ' . $e->getMessage());
            return ['ok' => false, 'data' => [], 'error' => $e->getMessage()];
        }
    }
}

// ── Service account JWT bearer flow ─────────────────────────────────────

if (!function_exists('cms_gsc_base64url_encode')) {
    function cms_gsc_base64url_encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

if (!function_exists('cms_gsc_generate_jwt')) {
    /**
     * Signs a JWT bearer assertion for the given service account, per
     * Google's OAuth2 service-account flow (RFC 7523). Read-only scope —
     * this integration never writes to Search Console.
     *
     * @param array{client_email:string, private_key:string, token_uri?:string} $serviceAccount
     * @throws RuntimeException if signing fails (bad/malformed private key).
     */
    function cms_gsc_generate_jwt(array $serviceAccount, string $scope = 'https://www.googleapis.com/auth/webmasters.readonly'): string
    {
        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss'   => $serviceAccount['client_email'],
            'scope' => $scope,
            'aud'   => $serviceAccount['token_uri'] ?? 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];

        $unsigned = cms_gsc_base64url_encode(json_encode($header, JSON_UNESCAPED_SLASHES))
            . '.' . cms_gsc_base64url_encode(json_encode($claims, JSON_UNESCAPED_SLASHES));

        $privateKey = openssl_pkey_get_private($serviceAccount['private_key']);
        if ($privateKey === false) {
            throw new RuntimeException('Invalid service account private key.');
        }

        $signature = '';
        $signed = openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (!$signed) {
            throw new RuntimeException('Failed to sign JWT — invalid private key.');
        }

        return $unsigned . '.' . cms_gsc_base64url_encode($signature);
    }
}

if (!function_exists('cms_gsc_http_request')) {
    /**
     * Minimal cURL wrapper shared by every GSC call in this file — form
     * POST (token exchange), JSON POST (searchAnalytics query), and GET
     * (sites.list) all funnel through here.
     *
     * @param 'GET'|'POST' $method
     * @param int $timeoutSeconds Default 20s matches every existing caller's
     *            actual need (token exchange, searchAnalytics query,
     *            sites.list) exactly — kept as the default so none of them
     *            need to change. The Technical SEO Auditor's PageSpeed
     *            Insights call (4 Agu 2026) is the first caller that needs
     *            longer, since a single PSI run can legitimately take up to
     *            ~30s — it passes its own value explicitly rather than this
     *            default being raised for everyone.
     * @return array{ok:bool,status:int,body:string,error:?string}
     */
    function cms_gsc_http_request(string $method, string $url, ?string $body = null, array $headers = [], int $timeoutSeconds = 20): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'cURL extension is not available on this server.'];
        }

        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'WPM-GrowthAgent-GSC/1.0',
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $body ?? '';
        }
        curl_setopt_array($ch, $opts);

        $responseBody = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch) ?: null;
        curl_close($ch);

        if ($responseBody === false || $error !== null) {
            return ['ok' => false, 'status' => $status, 'body' => '', 'error' => $error ?? 'Unknown cURL error'];
        }
        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'status' => $status, 'body' => (string) $responseBody, 'error' => 'HTTP ' . $status];
        }
        return ['ok' => true, 'status' => $status, 'body' => (string) $responseBody, 'error' => null];
    }
}

if (!function_exists('cms_gsc_exchange_jwt_for_token')) {
    /**
     * @param array{client_email:string,private_key:string,token_uri?:string} $serviceAccount
     * @return array{ok:bool,token:string,error:?string}
     */
    function cms_gsc_exchange_jwt_for_token(array $serviceAccount): array
    {
        try {
            $jwt = cms_gsc_generate_jwt($serviceAccount);
        } catch (Throwable $e) {
            return ['ok' => false, 'token' => '', 'error' => $e->getMessage()];
        }

        $tokenUri = $serviceAccount['token_uri'] ?? 'https://oauth2.googleapis.com/token';
        $body = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]);

        $result = cms_gsc_http_request('POST', $tokenUri, $body, ['Content-Type: application/x-www-form-urlencoded']);
        if (!$result['ok']) {
            $decoded = json_decode($result['body'], true);
            $detail = is_array($decoded) ? ($decoded['error_description'] ?? $decoded['error'] ?? null) : null;
            return ['ok' => false, 'token' => '', 'error' => $detail ?? ($result['error'] ?? 'Token exchange failed')];
        }

        $decoded = json_decode($result['body'], true);
        $accessToken = is_array($decoded) ? (string) ($decoded['access_token'] ?? '') : '';
        if ($accessToken === '') {
            return ['ok' => false, 'token' => '', 'error' => 'Token response did not include an access_token.'];
        }

        return ['ok' => true, 'token' => $accessToken, 'error' => null];
    }
}

if (!function_exists('cms_gsc_decrypt_service_account')) {
    /**
     * @return array{ok:bool,data:array,error:?string}
     */
    function cms_gsc_decrypt_service_account(string $encrypted): array
    {
        require_once __DIR__ . '/ai-helpers.php';

        $json = cms_ai_decrypt($encrypted);
        if ($json === '') {
            return ['ok' => false, 'data' => [], 'error' => 'Could not decrypt stored service account credential.'];
        }

        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['client_email'], $data['private_key'])) {
            return ['ok' => false, 'data' => [], 'error' => 'Stored service account JSON is malformed.'];
        }

        return ['ok' => true, 'data' => $data, 'error' => null];
    }
}

if (!function_exists('cms_gsc_get_access_token')) {
    /**
     * @return array{ok:bool,token:string,error:string}
     */
    function cms_gsc_get_access_token(PDO $pdo): array
    {
        $settings = cms_gsc_get_settings($pdo);
        $encrypted = (string) ($settings['service_account_json_enc'] ?? '');
        if ($encrypted === '') {
            return ['ok' => false, 'token' => '', 'error' => 'No service account connected yet — see GSC Settings.'];
        }

        $decrypted = cms_gsc_decrypt_service_account($encrypted);
        if (!$decrypted['ok']) {
            return ['ok' => false, 'token' => '', 'error' => (string) $decrypted['error']];
        }

        $exchanged = cms_gsc_exchange_jwt_for_token($decrypted['data']);
        return ['ok' => $exchanged['ok'], 'token' => $exchanged['token'], 'error' => (string) ($exchanged['error'] ?? '')];
    }
}

if (!function_exists('cms_gsc_test_service_account')) {
    /**
     * Used by the "Test Connection" step on pages/gsc-settings.php —
     * validates a freshly-pasted JSON (not yet saved) by attempting a real
     * token exchange, without touching gsc_settings.
     *
     * @return array{ok:bool,message:string,email:string}
     */
    function cms_gsc_test_service_account(array $serviceAccount): array
    {
        if (!isset($serviceAccount['client_email'], $serviceAccount['private_key'])) {
            return ['ok' => false, 'message' => 'JSON is missing client_email or private_key.', 'email' => ''];
        }

        $exchanged = cms_gsc_exchange_jwt_for_token($serviceAccount);
        if (!$exchanged['ok']) {
            return ['ok' => false, 'message' => (string) $exchanged['error'], 'email' => ''];
        }

        return ['ok' => true, 'message' => 'Service account authenticated successfully.', 'email' => (string) $serviceAccount['client_email']];
    }
}

if (!function_exists('cms_gsc_list_sites')) {
    /**
     * @return array{ok:bool,sites:list<string>,error:string}
     */
    function cms_gsc_list_sites(PDO $pdo): array
    {
        $tokenResult = cms_gsc_get_access_token($pdo);
        if (!$tokenResult['ok']) {
            return ['ok' => false, 'sites' => [], 'error' => $tokenResult['error']];
        }

        $result = cms_gsc_http_request(
            'GET',
            'https://www.googleapis.com/webmasters/v3/sites',
            null,
            ['Authorization: Bearer ' . $tokenResult['token']]
        );
        if (!$result['ok']) {
            return ['ok' => false, 'sites' => [], 'error' => $result['error'] ?? 'Failed to list properties.'];
        }

        $decoded = json_decode($result['body'], true);
        $entries = is_array($decoded['siteEntry'] ?? null) ? $decoded['siteEntry'] : [];
        $sites = array_values(array_filter(array_map(
            static fn (array $entry): string => (string) ($entry['siteUrl'] ?? ''),
            $entries
        )));

        return ['ok' => true, 'sites' => $sites, 'error' => ''];
    }
}

if (!function_exists('cms_gsc_site_url_label')) {
    /**
     * Google's Search Console has two distinct property types that can BOTH
     * exist for the same site and BOTH show up in sites.list() — picking
     * the wrong one is a common cause of "connected fine, but always 0
     * rows" (the other property variant has the actual tracked history,
     * this one doesn't). Surfaced in the GSC Settings picker so the
     * operator can tell them apart instead of guessing.
     */
    function cms_gsc_site_url_label(string $siteUrl): string
    {
        return str_starts_with($siteUrl, 'sc-domain:')
            ? 'Domain property — mencakup semua varian (http/https, www/non-www, subdomain)'
            : 'URL-prefix property — cuma exact match URL ini persis';
    }
}

// ── Fetch pipeline ───────────────────────────────────────────────────────

if (!function_exists('cms_gsc_page_url_index')) {
    /**
     * Builds [normalizedUrl => page_id] for every published article, so
     * matching GSC's page_url against our own content is one in-memory
     * lookup per row instead of a query per row. Prefers canonical_url when
     * the admin set one; falls back to the default clean-URL pattern
     * otherwise. Reuses cms_sitemap_absolute_url()/cms_sitemap_path_for()
     * from sitemap-service.php rather than re-deriving the absolute-URL
     * logic a third time.
     *
     * @return array<string, int>
     */
    function cms_gsc_page_url_index(PDO $pdo): array
    {
        require_once __DIR__ . '/sitemap-service.php';

        $index = [];
        $stmt = $pdo->query("SELECT page_id, slug, canonical_url FROM pages WHERE status = 'published'");
        foreach ($stmt->fetchAll() as $row) {
            $canonical = trim((string) ($row['canonical_url'] ?? ''));
            $url = $canonical !== ''
                ? $canonical
                : cms_sitemap_absolute_url(cms_sitemap_path_for('article', (string) $row['slug']));
            $index[cms_gsc_normalize_url($url)] = (int) $row['page_id'];
        }

        return $index;
    }
}

if (!function_exists('cms_gsc_normalize_url')) {
    function cms_gsc_normalize_url(string $url): string
    {
        $url = strtolower(trim($url));
        $url = preg_replace('#^https?://#', '', $url) ?? $url;
        return rtrim($url, '/');
    }
}

if (!function_exists('cms_gsc_fetch_and_cache')) {
    /**
     * Main entry point — pulls searchAnalytics data for the connected
     * property, upserts it into gsc_query_data (resolving matched_page_id
     * along the way), prunes rows older than fetch_window_days, and updates
     * gsc_settings.last_fetch_*. Never throws — a broken/unconfigured
     * connection must never break the Growth Agent page.
     *
     * @return array{ok:bool,rows_written:int,error:string}
     */
    function cms_gsc_fetch_and_cache(PDO $pdo, bool $forceRefresh = false): array
    {
        try {
            cms_gsc_ensure_schema($pdo);
            $settings = cms_gsc_get_settings($pdo);
        } catch (Throwable $e) {
            return ['ok' => false, 'rows_written' => 0, 'error' => $e->getMessage()];
        }

        if ((int) ($settings['is_active'] ?? 0) !== 1 || empty($settings['site_url'])) {
            $error = 'GSC is not connected — see GSC Settings.';
            if ($forceRefresh) {
                cms_gsc_log_error($pdo, $error);
            }
            return ['ok' => false, 'rows_written' => 0, 'error' => $error];
        }

        $tokenResult = cms_gsc_get_access_token($pdo);
        if (!$tokenResult['ok']) {
            cms_gsc_log_error($pdo, 'Token exchange failed: ' . $tokenResult['error']);
            cms_gsc_record_fetch_result($pdo, false, $tokenResult['error'], 0);
            return ['ok' => false, 'rows_written' => 0, 'error' => $tokenResult['error']];
        }

        $lookbackDays = max(1, (int) ($settings['fetch_lookback_days'] ?? 14));
        $endDate = date('Y-m-d', strtotime('-2 days')); // GSC data lags ~2-3 days
        $startDate = date('Y-m-d', strtotime('-' . ($lookbackDays + 2) . ' days'));

        $queryUrl = 'https://searchconsole.googleapis.com/webmasters/v3/sites/'
            . rawurlencode((string) $settings['site_url']) . '/searchAnalytics/query';

        $requestBody = json_encode([
            'startDate'  => $startDate,
            'endDate'    => $endDate,
            'dimensions' => ['query', 'page', 'date'],
            'rowLimit'   => 5000, // known limitation: no pagination yet, fine for this site's current volume
        ]);

        $result = cms_gsc_http_request('POST', $queryUrl, $requestBody, [
            'Authorization: Bearer ' . $tokenResult['token'],
            'Content-Type: application/json',
        ]);

        if (!$result['ok']) {
            $decoded = json_decode($result['body'], true);
            $detail = is_array($decoded) ? ($decoded['error']['message'] ?? null) : null;
            $message = $detail ?? ($result['error'] ?? 'Search Console query failed');
            cms_gsc_log_error($pdo, $message);
            cms_gsc_record_fetch_result($pdo, false, $message, 0);
            return ['ok' => false, 'rows_written' => 0, 'error' => $message];
        }

        $decoded = json_decode($result['body'], true);
        $rows = is_array($decoded['rows'] ?? null) ? $decoded['rows'] : [];

        if ($rows === []) {
            // Request succeeded (HTTP 2xx) but Google returned no rows at
            // all — NOT necessarily a bug. Common causes: (1) a brand-new
            // property GSC hasn't finished accumulating queryable data for
            // yet; (2) the wrong property variant was picked in GSC
            // Settings — see cms_gsc_site_url_label(). Logged as a
            // diagnostic (not cms_gsc_log_error's usual failure path) with
            // the exact request/response so this is debuggable without
            // server log access.
            cms_gsc_log_error($pdo, sprintf(
                "Fetch OK (HTTP %d) but 0 rows returned.\nsite_url: %s\nRequest URL: %s\nRequest body: %s\nRaw response: %s",
                $result['status'],
                (string) $settings['site_url'],
                $queryUrl,
                $requestBody,
                mb_substr($result['body'], 0, 1500)
            ));
        }

        $pageIndex = cms_gsc_page_url_index($pdo);

        $upsert = $pdo->prepare(
            'INSERT INTO gsc_query_data
                (query, page_url, matched_page_id, clicks, impressions, ctr, `position`, data_date, dedupe_hash, fetched_at)
             VALUES
                (:query, :page_url, :matched_page_id, :clicks, :impressions, :ctr, :position, :data_date, :dedupe_hash, NOW())
             ON DUPLICATE KEY UPDATE
                matched_page_id = VALUES(matched_page_id),
                clicks = VALUES(clicks),
                impressions = VALUES(impressions),
                ctr = VALUES(ctr),
                `position` = VALUES(`position`),
                fetched_at = NOW()'
        );

        $written = 0;
        foreach ($rows as $row) {
            $keys = is_array($row['keys'] ?? null) ? $row['keys'] : [];
            $query = (string) ($keys[0] ?? '');
            $pageUrl = (string) ($keys[1] ?? '');
            $dataDate = (string) ($keys[2] ?? '');
            if ($query === '' || $pageUrl === '' || $dataDate === '') {
                continue;
            }

            $dedupeHash = md5($query . '|' . $pageUrl . '|' . $dataDate);
            $matchedPageId = $pageIndex[cms_gsc_normalize_url($pageUrl)] ?? null;

            $upsert->execute([
                'query'           => mb_substr($query, 0, 255),
                'page_url'        => mb_substr($pageUrl, 0, 500),
                'matched_page_id' => $matchedPageId,
                'clicks'          => (int) ($row['clicks'] ?? 0),
                'impressions'     => (int) ($row['impressions'] ?? 0),
                'ctr'             => isset($row['ctr']) ? (float) $row['ctr'] : null,
                'position'        => isset($row['position']) ? (float) $row['position'] : null,
                'data_date'       => $dataDate,
                'dedupe_hash'     => $dedupeHash,
            ]);
            $written++;
        }

        $windowDays = max(1, (int) ($settings['fetch_window_days'] ?? 90));
        $pdo->prepare('DELETE FROM gsc_query_data WHERE data_date < (CURDATE() - INTERVAL :days DAY)')
            ->execute(['days' => $windowDays]);

        $fetchMessage = $written > 0
            ? ''
            : "0 rows untuk {$startDate}..{$endDate} (site: {$settings['site_url']}) — cek panel \"Recent Diagnostics\" di GSC Settings untuk detail request/response mentah.";
        cms_gsc_record_fetch_result($pdo, true, $fetchMessage, $written);

        // Recompute Prioritized Opportunities right after every successful
        // fetch — pure SQL/scoring, no AI call, so this is cheap even
        // though it runs on every fetch. Never allowed to make the fetch
        // itself look like it failed.
        try {
            cms_gsc_compute_opportunities($pdo);
        } catch (Throwable $e) {
            cms_gsc_log_error($pdo, 'Opportunity recompute failed after fetch: ' . $e->getMessage());
        }

        return ['ok' => true, 'rows_written' => $written, 'error' => ''];
    }
}

if (!function_exists('cms_gsc_record_fetch_result')) {
    function cms_gsc_record_fetch_result(PDO $pdo, bool $ok, string $message, int $rows): void
    {
        try {
            $pdo->prepare(
                'UPDATE gsc_settings
                    SET last_fetch_status = :status, last_fetch_message = :message,
                        last_fetch_rows = :rows, last_fetch_at = NOW()
                  ORDER BY id ASC LIMIT 1'
            )->execute([
                'status'  => $ok ? 'success' : 'failed',
                'message' => mb_substr($message, 0, 255),
                'rows'    => $rows,
            ]);
        } catch (Throwable $e) {
            // Non-fatal — the fetch itself already succeeded or failed independently of this bookkeeping.
        }
    }
}

if (!function_exists('cms_gsc_fetch_if_stale')) {
    /**
     * Lazy trigger — no cron in this codebase. Called from
     * pages/growth-agent.php on every page load; only actually fetches when
     * GSC is connected AND the last fetch is older than $maxAgeHours (or
     * has never run). Never throws.
     */
    function cms_gsc_fetch_if_stale(PDO $pdo, int $maxAgeHours = 24): void
    {
        try {
            $settings = cms_gsc_get_settings($pdo);
            if ((int) ($settings['is_active'] ?? 0) !== 1 || empty($settings['site_url'])) {
                return;
            }

            $lastFetchAt = $settings['last_fetch_at'] ?? null;
            $isStale = $lastFetchAt === null
                || (time() - strtotime((string) $lastFetchAt)) >= ($maxAgeHours * 3600);

            if ($isStale) {
                cms_gsc_fetch_and_cache($pdo, false);
            }
        } catch (Throwable $e) {
            // A lazy background refresh must never break the page it's attached to.
        }
    }
}

// ── Prioritized Opportunities ────────────────────────────────────────────
//
// Opportunities are scored purely from gsc_query_data — no AI call at
// compute time. AI is only invoked later, on demand, when the operator
// clicks "Generate" on one specific row (dispatched from
// pages/growth-agent.php into the cms_growth_agent_generate_*() functions
// in growth-agent-service.php).
//
// Every scoring threshold lives in ONE place — gsc_settings.
// opportunity_thresholds_json — instead of scattered PHP constants, so it
// can be tuned later without touching code.

if (!function_exists('cms_gsc_default_opportunity_thresholds')) {
    /**
     * @return array<string, mixed>
     */
    function cms_gsc_default_opportunity_thresholds(): array
    {
        return [
            'volume_buckets' => [
                ['min' => 5000, 'score' => 10],
                ['min' => 2000, 'score' => 8],
                ['min' => 1000, 'score' => 6],
                ['min' => 500, 'score' => 4],
                ['min' => 200, 'score' => 2],
                ['min' => 0, 'score' => 1],
            ],
            'ctr_gap_buckets' => [
                ['min' => 0.05, 'score' => 10],
                ['min' => 0.03, 'score' => 7],
                ['min' => 0.015, 'score' => 5],
                ['min' => 0.005, 'score' => 3],
                ['min' => 0.0, 'score' => 1],
            ],
            'page_one_buckets' => [
                ['max_position' => 13, 'score' => 10],
                ['max_position' => 16, 'score' => 7],
                ['max_position' => 20, 'score' => 4],
            ],
            'zero_click_score' => 9,
            'zero_click_ctr_threshold' => 0.01,
            'page_one_min_position' => 11,
            'page_one_max_position' => 20,
            'effort' => [
                'low_ctr' => 2,
                'zero_click_page' => 4,
                'page_one' => 6,
                'no_article' => 9,
                'content_decay' => 6,
                'cannibalization' => 7,
            ],
            'priority' => [
                'high_impact_min' => 7,
                'high_effort_max' => 5,
                'high_impact_override' => 9,
                'low_impact_max' => 3,
                'low_impact_mid' => 5,
                'low_effort_min' => 8,
            ],
            'min_impressions_page' => 100,
            'min_impressions_query' => 200,

            // Cannibalization + Content Decay (ROADMAP.md gap #5, closed 28
            // Jul 2026) — period-over-period comparison thresholds. Both
            // percentages are deliberately conservative defaults (confirmed
            // with the user before implementing, since these are exactly the
            // kind of "no objectively correct number" judgment calls that
            // shouldn't be guessed silently) — tune down here later once
            // real traffic patterns on this site are observed, no code
            // change needed.
            'comparison_window_days' => 28, // length of each of the two compared windows
            'comparison_min_days' => 7, // minimum distinct days of data required on EACH side before comparing at all — below this, the page/query is silently skipped (not flagged either way), same "don't dress up thin data" principle as the Feedback Loop's insufficient_data
            'decay_min_pct_decline' => 0.30, // >=30% clicks decline current-vs-previous window to count as real decay, not normal week-to-week noise
            'decay_min_previous_impressions' => 100, // previous window must have had at least this many impressions — a page that only had a handful of impressions before isn't a meaningful decay signal even at 30%+ decline
            'decay_pct_buckets' => [
                ['min' => 0.70, 'score' => 10],
                ['min' => 0.50, 'score' => 7],
                ['min' => 0.30, 'score' => 4],
            ],
            'cannibalization_min_share' => 0.20, // each competing page must hold >=20% of the query's total clicks (or impressions, if the query has zero clicks) — a page getting only a few % isn't real cannibalization, just an incidental secondary match
            'cannibalization_min_impressions' => 200, // total impressions for the query across all matched pages combined

            // SEO-G0 Gate (GROWTH_AGENT_V2_PROPOSAL.md Fase A item 3, 4 Agu
            // 2026) — deterministic topic-overlap pre-check run before a new
            // article proposal (gsc_article_idea/topic_gap_article) is
            // logged. See cms_growth_agent_seo_g0_gate() in
            // growth-agent-service.php for how these are used. Nested here
            // (not a new gsc_settings column) — same array_replace_recursive
            // override pattern as 'effort'/'priority' above, tunable from
            // the DB without a migration.
            'seo_g0_gate' => [
                // Overlap coefficient (|intersection| / min(|A|,|B|) of the
                // two token sets, see cms_growth_agent_g0_overlap()) at or
                // above which two topics count as "similar enough to warn
                // about". 1.0 = the shorter side's meaningful words are
                // entirely contained in the longer side.
                'similarity_threshold' => 0.6,
                // Minimum number of overlapping meaningful tokens required
                // — guards against a single coincidental shared word (e.g.
                // one shared team name) tripping the threshold by itself
                // when one side only has 1-2 tokens after stopword removal.
                'min_overlap_tokens' => 2,
            ],

            // Article Idea — proactive collision avoidance (GROWTH_AGENT_V2_
            // PROPOSAL.md § 5, 6 Aug 2026). Reuses the SEO-G0 Gate's own
            // cms_growth_agent_g0_tokenize()/cms_growth_agent_g0_overlap()
            // (no new tokenizer) to find published articles most similar to
            // the GSC query being turned into a proposal, BEFORE the AI
            // prompt is built — see
            // cms_growth_agent_generate_article_idea() in
            // growth-agent-service.php. Distinct from 'seo_g0_gate' above:
            // that one is a POST-hoc advisory warning against the AI's
            // eventual title; this one actively shapes what the AI is told
            // before it writes anything.
            'article_idea' => [
                // Overlap coefficient threshold — same metric/scale as
                // seo_g0_gate.similarity_threshold, but deliberately its own
                // separate key (not shared) so the two can be tuned
                // independently: this one decides "worth mentioning as
                // context", the gate's decides "worth warning the operator
                // about" — different purposes, no reason they must move
                // together.
                'min_overlap_threshold' => 0.5,
                // How many top-matching published articles get sent into
                // the prompt as context, at most. Deliberately far smaller
                // than Keyword Expansion's context_articles_limit (default
                // 50) — that scan sends its 50 most-RECENT articles to
                // survey a whole niche; this sends only the handful most
                // TOPICALLY SIMILAR to one specific query, so a small
                // number is both cheaper and more relevant.
                'context_articles_limit' => 8,
            ],

            // Trending Headlines (GROWTH_AGENT_V2_PROPOSAL.md § 5, 6 Aug
            // 2026) — external headline+link+time signal folded into the
            // Article Idea prompt as inspiration/context, never as content
            // to copy. See cms_growth_agent_refresh_trending_headlines_if_stale()
            // and cms_growth_agent_get_trending_headlines_for_prompt() in
            // growth-agent-service.php.
            'trending_headlines' => [
                // Deliberately an array of URLs, not one hardcoded site —
                // user's explicit instruction: admin must be able to
                // add/remove sources without a code change. Each entry is a
                // site's homepage/section URL (e.g. a sports section), not
                // a feed URL directly — the fetcher tries well-known feed
                // paths per source first (see
                // cms_growth_agent_fetch_trending_source()). Both entries
                // below verified LIVE 7 Aug 2026 by actually fetching
                // {source}/rss and confirming a real RSS 2.0 response
                // (title/link/pubDate items) — NOT the doc's original
                // assumption that detik.com needed HTML scraping; that
                // assumption turned out to be wrong; detik's /rss path
                // works cleanly, so the HTML-scrape fallback is exercised
                // by neither of these two defaults today, only by whatever
                // a future source without an /rss or /feed path needs. This
                // is NOT a claim that any arbitrary URL added here will
                // parse correctly — structure varies per site and the
                // scrape fallback is a best effort, not a guarantee.
                'sources' => [
                    'https://sport.detik.com',
                    'https://www.cnnindonesia.com/olahraga',
                ],
                // How old the LAST successful refresh can be before
                // cms_growth_agent_refresh_trending_headlines_if_stale()
                // fetches again — same "*_if_stale()" convention as GSC
                // fetch/memory detection/performance snapshot. Headlines are
                // context/inspiration, not time-critical breaking news, so a
                // slower cadence than GSC's own 24h is fine.
                'refresh_interval_hours' => 12,
                // Hard cap on how many headline rows one source can
                // contribute per fetch — same "bounded batch" reasoning as
                // every other scan in this file, and keeps one noisy source
                // from crowding out the others in the stored table.
                'max_headlines_per_source' => 15,
                // How many headlines actually get sent into the Article
                // Idea prompt per generate call (after the overlap filter
                // below removes ones already covered by a published
                // article) — kept small on purpose, this is meant to be
                // "recent context", not a news digest.
                'headlines_in_prompt' => 5,
                // Overlap threshold for filtering OUT headlines whose topic
                // is already covered by a published article, before they
                // ever reach the prompt — reuses the exact same
                // g0_overlap() metric as 'article_idea.min_overlap_threshold'
                // above, kept as its own separate tunable for the same
                // independent-purposes reasoning.
                'published_overlap_threshold' => 0.5,
                // The "aturan keras" check — AFTER the AI returns a title,
                // its overlap against the SOURCE HEADLINES actually shown in
                // that prompt is measured with the same g0_overlap()
                // function again (third independent use of it in this
                // file). At or above this, the title is flagged in
                // input_brief rather than silently passed through — see
                // cms_growth_agent_check_title_vs_headlines() in
                // growth-agent-service.php. Deliberately HIGHER than
                // article_idea.min_overlap_threshold (0.5) — that threshold
                // decides "similar enough to mention as inspiration"; this
                // one decides "similar enough to be a near-duplicate title",
                // a much stricter bar since a false positive here would
                // wrongly flag a legitimately-reworded title.
                'title_vs_headline_max_overlap' => 0.75,
            ],

            // Internal Linking Agent (GROWTH_AGENT_V2_PROPOSAL.md Fase B
            // item 1, 4 Agu 2026) — deterministic topic-overlap threshold
            // for proposing A -> B internal links. See
            // cms_growth_agent_scan_internal_links() in
            // growth-agent-service.php. Deliberately a LOWER
            // similarity_threshold than seo_g0_gate's 0.6: that gate wants
            // near-DUPLICATE topics (a reason to warn), this wants
            // topically-RELATED-but-distinct articles (a reason to link) —
            // a much looser bar is correct here, not a bug/inconsistency.
            'internal_linking' => [
                'similarity_threshold' => 0.5,
                'min_overlap_tokens' => 2,
                // Hard cap per source article per scan — more than this
                // reads as link-spamming, not genuine internal linking.
                'max_suggestions_per_article' => 3,
                // Same "bounded batch per click" pattern as
                // cms_growth_agent_scan_seo_recommendations()'s $limit (5)
                // and Topic Cluster's 50-article cap — kept separately
                // tunable since this scan is O(articles²) in the worst
                // case (every source compared against every other
                // published article), costlier per article scanned than
                // the SEO recommendation scan.
                'articles_scanned_per_run' => 10,

                // Single-word anchor guardrails (added 4 Agu 2026 after a
                // real production incident: the anchor "paling" — a bare
                // Indonesian intensifier with zero topical meaning — was
                // proposed and briefly applied live). A manual stopword
                // list alone can never keep up with every generic
                // Indonesian adverb/connector, so single-word anchors are
                // instead gated on two independently-computed signals — see
                // cms_growth_agent_il_candidate_phrases() in
                // growth-agent-service.php:
                //   1. Corpus document frequency: how large a fraction of
                //      ALL published articles the word appears in. A word
                //      that shows up across most of the site's articles is
                //      generic BY DEFINITION, no matter what it is — this
                //      self-adjusts as the article corpus grows, unlike a
                //      fixed word list.
                //   2. Mid-sentence capitalization in the SOURCE article's
                //      own body text — a much stronger proper-noun signal
                //      than title casing (most titles on this site are
                //      Title Case, so capitalization THERE means nothing).
                // A single word must pass BOTH to be eligible at all.
                'single_word_max_df_ratio' => 0.2,
                // Below this many published articles, document-frequency
                // ratios are too noisy to trust (one article swings the
                // percentage too much) — single-word anchors are disabled
                // entirely rather than guessed at with unreliable stats.
                'min_corpus_size_for_single_word' => 10,
            ],

            // Keyword Expansion Agent (GROWTH_AGENT_V2_PROPOSAL.md Fase B
            // item 2, 4 Agu 2026) — this is the first agent in this new
            // wave that calls AI, so unlike the deterministic gates above,
            // its knobs are about bounding COST per click rather than
            // matching precision. See
            // cms_growth_agent_scan_keyword_expansion() in
            // growth-agent-service.php.
            'keyword_expansion' => [
                // Hard cap on new-topic jobs created per click. Kept
                // deliberately small — these are genuinely new article
                // ideas (each one becomes a full draft an editor has to
                // write from scratch if approved), not small metadata
                // tweaks, so reviewing more than a handful at once isn't
                // realistic. Same "5 per click" order of magnitude as
                // cms_growth_agent_scan_seo_recommendations()'s $limit.
                'max_topics_per_run' => 5,
                // How many recent published articles are given to the AI
                // as "already covered" context, so it doesn't re-propose
                // an existing topic. Same limit/ordering as
                // cms_growth_agent_generate_topic_clusters()'s own context
                // window — kept separately tunable since the two prompts
                // serve different purposes even though the default matches.
                'context_articles_limit' => 50,
            ],

            // Technical SEO Auditor (GROWTH_AGENT_V2_PROPOSAL.md Fase B
            // item 3, 5 Agu 2026) — a read-only REPORT, not a proposal
            // queue (see cms_growth_agent_tsa_run_content_checks()/
            // cms_growth_agent_tsa_run_psi() in growth-agent-service.php).
            // Its three checks have very different cost profiles, so each
            // gets its own bound:
            'technical_seo' => [
                // Alt-text check: pure DB read (pages.content) + DOMDocument
                // parse, no network at all — cheap enough to cover every
                // published article in one click. 50 as a defensive
                // ceiling, same order of magnitude as other "whole corpus"
                // limits in this codebase (e.g. Topic Cluster's context).
                'content_check_articles_per_run' => 50,
                // Schema-markup check: fetches the article's OWN live URL
                // over HTTP to confirm the NewsArticle/BreadcrumbList
                // JSON-LD actually renders — fast per request (same
                // server) but still a real network round trip 24+ times,
                // so it's bounded too, just far more generously than PSI.
                'schema_check_articles_per_run' => 24,
                // Core Web Vitals via PageSpeed Insights: BY FAR the
                // costliest check — a single PSI call can legitimately
                // take up to ~30s, so this MUST stay small or PHP's
                // execution time limit will kill the request before it
                // finishes. Same "small per click" reasoning as
                // cms_growth_agent_inspect_priority_urls()'s $limit
                // (default 10) for the GSC URL Inspection API, just an
                // even smaller default given PSI's much higher per-call
                // cost (seconds vs tens of seconds).
                'psi_urls_per_run' => 3,
                // PSI's own responses can take up to ~30s to compute
                // server-side — cms_gsc_http_request()'s normal 20s
                // default (tuned for fast GSC endpoints) would cut that
                // off mid-request. Passed explicitly by the PSI caller
                // only; every other cms_gsc_http_request() caller keeps
                // using the unchanged 20s default.
                'psi_timeout_seconds' => 35,
                // PSI performance score (0-100) at or below which a page
                // counts as a "problem" worth surfacing in the report —
                // matches Google's own public "poor" bucket boundary
                // (0-49 poor, 50-89 needs improvement, 90-100 good), kept
                // tunable rather than hardcoded in the report-filtering
                // logic.
                'psi_poor_score_threshold' => 50,
            ],

            // Measurement Loop (GROWTH_AGENT_V2_PROPOSAL.md § Fase C,
            // reprioritized 5 Aug 2026 to run BEFORE Fase E, not after — see
            // that section's own note: without before/after evidence,
            // deciding which job_type is "safe enough to automate" in Fase E
            // is a guess, not a fact). See
            // cms_growth_agent_run_measurement_loop() in
            // growth-agent-service.php.
            'measurement_loop' => [
                // Same before/after window and minimum-data guardrail as
                // cms_growth_agent_compare_before_after()'s own defaults —
                // kept here (not hardcoded in the loop function) so both can
                // be tuned together without a code change, same
                // "everything lives in gsc_settings" philosophy as every
                // other block in this array.
                'window_days' => 28,
                'min_days' => 7,
                // internal_link_suggestion is included here (not just
                // seo_recommendation/gsc_article_idea, which the Feedback
                // report already covered) — it is in fact the primary
                // motivating case: this is the exact data Fase E's pilot
                // ('internal_link_suggestion' only, see that section) needs
                // before autonomous mode can be trusted for it.
                'eligible_job_types' => ['internal_link_suggestion', 'seo_recommendation', 'gsc_article_idea'],
                // Bounded per run — cms_growth_agent_compare_before_after()
                // does 2 aggregate queries per row, so an unbounded batch on
                // first rollout (when many already-old succeeded jobs are
                // suddenly all eligible at once) could make one page load or
                // cron run noticeably slow. Same order of magnitude as
                // cms_growth_agent_get_feedback_report()'s own default
                // $limit (20) — this is the loop that feeds it, so eventual
                // throughput matters more than clearing a backlog instantly.
                'batch_size' => 20,
            ],

            // Daftar Artikel Berpotensi Tinggi (GROWTH_AGENT_V2_PROPOSAL.md
            // § Fase D — renamed 6 Aug 2026 from "Backlink Monitor" after
            // investigation found the GSC API has no Links/backlink report
            // endpoint at all, free or paid; that half of the original
            // scope was dropped entirely, see the doc's own correction).
            // What survives: published articles ranked by existing GSC
            // traffic/impression signal, so an operator has concrete
            // promotion/outreach targets. See
            // cms_growth_agent_get_high_potential_articles() in
            // growth-agent-service.php — pure live SQL aggregate, no new
            // table/column (unlike Technical SEO Auditor, nothing here is
            // expensive enough to need persisting between page loads).
            'high_potential_articles' => [
                // Same order of magnitude as most other "recent window"
                // knobs in this file (comparison_window_days, Measurement
                // Loop's own window_days) — 28 days is this codebase's
                // default lookback everywhere GSC trend data is involved.
                'window_days' => 28,
                // Below this many impressions in the window, a page isn't
                // a meaningful "high potential" candidate — just noise.
                // Deliberately low: this list is meant to surface anything
                // worth a human's attention, not just the top handful.
                'min_impressions' => 50,
                // How many articles the panel actually displays.
                'articles_per_report' => 10,
                // Pass 1 (bulk GROUP BY ranking, see that function's own
                // docblock for the two-pass design) casts a wider net than
                // articles_per_report before pass 2 re-aggregates each
                // candidate via cms_growth_agent_aggregate_page_window() and
                // re-sorts by the properly-sourced numbers — a buffer so a
                // page whose properly-sourced total differs slightly from
                // pass 1's raw sum doesn't fall out of the top
                // articles_per_report incorrectly.
                'candidate_pool_size' => 30,
            ],

            // Autonomous Mode (GROWTH_AGENT_V2_PROPOSAL.md § Fase E, 6 Aug
            // 2026) — the ONLY place this whole feature reads its config
            // from; there is no separate settings row anywhere else. See
            // cms_growth_agent_autonomous_maybe_apply_internal_link() in
            // growth-agent-service.php.
            //
            // 'enabled' AND 'job_types' BOTH gate every auto-apply attempt
            // (see that function) — this default ships OFF/empty
            // deliberately: the oldest internal_link_suggestion job in this
            // install is ~2 days old, nowhere near the Measurement Loop's
            // 28-day window, so there is zero before/after evidence yet to
            // justify trusting autonomous mode for ANY job_type. Do not
            // flip 'enabled' to true as part of a deploy — that's an
            // operator decision, made later, once real data exists.
            'autonomous_mode' => [
                'enabled' => false,
                // Deliberately an explicit per-job_type allowlist, not a
                // single flag — a job_type simply absent from this array
                // must be treated as OFF (see the calling function's own
                // strict `=== true` check), never as "on by default because
                // the master switch is on". internal_link_suggestion is the
                // only Fase E pilot candidate (see that section's own
                // table): seo_recommendation was explicitly cut from the
                // pilot 5 Aug 2026 and stays permanently manual, so it is
                // intentionally NOT a key here at all, not even `=> false`.
                'job_types' => [
                    'internal_link_suggestion' => false,
                ],
                // Weekly, not daily (this section's own devs brief) — a
                // human should still be able to sanity-check what
                // autonomous mode did at a pace slower than "every scan
                // click", not just cap total volume. Counted from
                // growth_agent_feedback.action='auto_applied' rows in the
                // trailing 7 days — see
                // cms_growth_agent_autonomous_maybe_apply_internal_link().
                'weekly_limit' => 3,
            ],

            // Full Draft Automation scheduler (GROWTH_AGENT_V2_PROPOSAL.md
            // § 6, Fase H, 8 Aug 2026) — gates
            // cms_growth_agent_generate_auto_draft_article() (Fase F) being
            // called at all from cron/growth_agent_maintenance.php. Ships
            // OFF (same reasoning as autonomous_mode above: no track record
            // yet to justify running unattended) and with zero configured
            // schedule until an operator sets one via the Agent & Setelan
            // panel — see that panel's own POST handler in growth-agent.php.
            'auto_draft_automation' => [
                'enabled' => false,
                // Standard 5-field cron expression, evaluated by the cron
                // step itself (not a real system crontab entry — this repo
                // already runs everything through ONE actual cron trigger,
                // cron/growth_agent_maintenance.php, same as every other
                // *_if_stale() feature; this string just decides which of
                // that script's invocations are eligible to actually
                // generate a draft). Default: 3x/day (06:00, 12:00, 18:00).
                'schedule_cron' => '0 6,12,18 * * *',
                // Same reasoning as trending_headlines.sources above — a
                // configurable list, not one hardcoded site. Deliberately a
                // SEPARATE list from trending_headlines.sources (not
                // reused as-is) — an operator may want draft-automation to
                // pull from a narrower/different set of sources than the
                // general trending-headlines context feed, even though
                // both go through the same fetcher
                // (cms_growth_agent_fetch_trending_source()).
                'source_urls' => [
                    'https://www.detik.com/tag/sepak-bola',
                    'https://www.cnnindonesia.com/olahraga',
                ],
                // Hard daily cap, INDEPENDENT of how many hours are checked
                // in schedule_cron (8 Aug 2026 — requested by project owner:
                // budget isn't the concern, flexible control over daily
                // review-queue volume is). Same field the proposal doc
                // already reserved for Fase G's own rate limit (§ 6,
                // 'rate_limit_per_day' under autonomous_mode.job_types.
                // auto_draft_article) — deliberately a DIFFERENT key name
                // here (max_drafts_per_day) so this Fase F draft-only cap
                // and Fase G's future auto-PUBLISH cap can never be
                // confused for the same setting once Fase G exists.
                // Default 3, not 0/unlimited — this is the first time this
                // codebase has ever generated unattended draft content, and
                // there's no track record yet for how many drafts/day an
                // editor can actually review. 3 matches the old default
                // schedule's own hour count (06,12,18), so upgrading
                // existing installs doesn't silently change behavior. An
                // operator can raise/lower this, or explicitly set 0 to
                // remove the cap, once draft quality has been reviewed for
                // a while and 3/day proves too conservative.
                'max_drafts_per_day' => 3,
                // Fase G (9 Aug 2026, docs/DECISIONS.md) — operator-approved
                // exception to this whole project's default "Action Queue,
                // human approval required" architecture, ONLY for this one
                // job_type. Default false: a draft still just sits waiting
                // for manual Approve, same as every install before this
                // field existed. When an operator flips this true, a
                // successfully generated auto_draft_article job publishes
                // straight to the public site with ZERO human review — see
                // cms_growth_agent_auto_publish_draft() in
                // growth-agent-service.php. SEO-G0 gate and the title-vs-
                // headline check still run and still get recorded either
                // way, they just don't block publish once this is on.
                // max_drafts_per_day above still applies regardless — it
                // caps GENERATION (AI cost), not publish, so it's not a
                // safety net against this setting, just an independent knob.
                'auto_publish' => false,
            ],
        ];
    }
}

if (!function_exists('cms_gsc_get_opportunity_thresholds')) {
    /**
     * @return array<string, mixed>
     */
    function cms_gsc_get_opportunity_thresholds(PDO $pdo): array
    {
        $defaults = cms_gsc_default_opportunity_thresholds();

        try {
            $settings = cms_gsc_get_settings($pdo);
            $raw = (string) ($settings['opportunity_thresholds_json'] ?? '');
            if ($raw === '') {
                return $defaults;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return $defaults;
            }
            return array_replace_recursive($defaults, $decoded);
        } catch (Throwable $e) {
            return $defaults;
        }
    }
}

if (!function_exists('cms_gsc_set_opportunity_threshold_key')) {
    /**
     * Writes ONE top-level key into gsc_settings.opportunity_thresholds_json,
     * leaving every other key exactly as it was stored (or entirely absent,
     * if it was never explicitly set). The first — and, as of Autonomous
     * Mode's toggle UI (Fase E, 6 Aug 2026), only — write path into this
     * JSON blob; every other consumer only ever reads it via
     * cms_gsc_get_opportunity_thresholds()'s defaults-merged view.
     *
     * Deliberately reads the RAW stored JSON here, not the defaults-merged
     * result cms_gsc_get_opportunity_thresholds() returns — merging in
     * defaults before writing back would permanently persist today's code
     * defaults for every OTHER feature's config too (measurement_loop,
     * technical_seo, etc.), silently freezing them against future default
     * changes even though nobody asked to configure them. This function
     * only ever touches the one key it's told to.
     *
     * Never throws — returns false on any failure, same convention as
     * cms_ensure_column().
     */
    function cms_gsc_set_opportunity_threshold_key(PDO $pdo, string $key, $value): bool
    {
        try {
            $settings = cms_gsc_get_settings($pdo);
            $raw = (string) ($settings['opportunity_thresholds_json'] ?? '');
            $stored = $raw !== '' ? json_decode($raw, true) : [];
            if (!is_array($stored)) {
                $stored = [];
            }

            $stored[$key] = $value;

            $pdo->prepare('UPDATE gsc_settings SET opportunity_thresholds_json = :json ORDER BY id ASC LIMIT 1')
                ->execute(['json' => json_encode($stored, JSON_UNESCAPED_UNICODE)]);

            return true;
        } catch (Throwable $e) {
            error_log('[cms_gsc_set_opportunity_threshold_key] key=' . $key . ': ' . $e->getMessage());
            return false;
        }
    }
}

// ── Agent Memory thresholds ───────────────────────────────────────────────

if (!function_exists('cms_gsc_default_memory_thresholds')) {
    /**
     * @return array<string, mixed>
     */
    function cms_gsc_default_memory_thresholds(): array
    {
        return [
            'min_distinct_weeks' => 3,
            'min_impressions' => 300,
            'winning_ctr_threshold' => 0.03,
            'winning_position_threshold' => 10.0,
            'pending_review_stale_days' => 30,
            'active_stale_days' => 90,
            'detection_interval_days' => 7,
        ];
    }
}

if (!function_exists('cms_gsc_get_memory_thresholds')) {
    /**
     * @return array<string, mixed>
     */
    function cms_gsc_get_memory_thresholds(PDO $pdo): array
    {
        $defaults = cms_gsc_default_memory_thresholds();

        try {
            $settings = cms_gsc_get_settings($pdo);
            $raw = (string) ($settings['memory_thresholds_json'] ?? '');
            if ($raw === '') {
                return $defaults;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return $defaults;
            }
            return array_replace_recursive($defaults, $decoded);
        } catch (Throwable $e) {
            return $defaults;
        }
    }
}

if (!function_exists('cms_gsc_bucket_score_desc')) {
    /**
     * @param list<array{min: float|int, score: int}> $buckets Sorted descending by 'min'.
     */
    function cms_gsc_bucket_score_desc(float $value, array $buckets): int
    {
        foreach ($buckets as $bucket) {
            if ($value >= (float) $bucket['min']) {
                return (int) $bucket['score'];
            }
        }
        return 1;
    }
}

if (!function_exists('cms_gsc_bucket_score_asc_max')) {
    /**
     * @param list<array{max_position: float|int, score: int}> $buckets Sorted ascending by 'max_position'.
     */
    function cms_gsc_bucket_score_asc_max(float $value, array $buckets): int
    {
        foreach ($buckets as $bucket) {
            if ($value <= (float) $bucket['max_position']) {
                return (int) $bucket['score'];
            }
        }
        return 1;
    }
}

if (!function_exists('cms_gsc_position_bucket_label')) {
    function cms_gsc_position_bucket_label(float $position): string
    {
        if ($position <= 3) {
            return '1-3';
        }
        if ($position <= 10) {
            return '4-10';
        }
        if ($position <= 20) {
            return '11-20';
        }
        return '21+';
    }
}

if (!function_exists('cms_gsc_site_ctr_by_position_bucket')) {
    /**
     * Average CTR per position bucket, computed fresh from the current
     * gsc_query_data window — used as the fair baseline for "Low CTR"
     * (comparing a position-15 query's CTR against a position-2 query's
     * CTR would always look artificially bad, so this compares within the
     * same bucket instead).
     *
     * @return array<string, float> bucket label => average CTR (0.0-1.0)
     */
    function cms_gsc_site_ctr_by_position_bucket(PDO $pdo): array
    {
        $rows = $pdo->query(
            "SELECT
                CASE
                    WHEN position <= 3 THEN '1-3'
                    WHEN position <= 10 THEN '4-10'
                    WHEN position <= 20 THEN '11-20'
                    ELSE '21+'
                END AS bucket,
                SUM(clicks) AS total_clicks,
                SUM(impressions) AS total_impressions
             FROM gsc_query_data
             WHERE position IS NOT NULL
             GROUP BY bucket"
        )->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $impressions = (int) $row['total_impressions'];
            $result[(string) $row['bucket']] = $impressions > 0 ? ((int) $row['total_clicks'] / $impressions) : 0.0;
        }
        return $result;
    }
}

if (!function_exists('cms_gsc_derive_priority')) {
    function cms_gsc_derive_priority(int $impact, int $effort, array $thresholds): string
    {
        $p = $thresholds['priority'];
        if ($impact >= (int) $p['high_impact_override']) {
            return 'high';
        }
        if ($impact >= (int) $p['high_impact_min'] && $effort <= (int) $p['high_effort_max']) {
            return 'high';
        }
        if ($impact <= (int) $p['low_impact_max']) {
            return 'low';
        }
        if ($impact <= (int) $p['low_impact_mid'] && $effort >= (int) $p['low_effort_min']) {
            return 'low';
        }
        return 'medium';
    }
}

if (!function_exists('cms_gsc_build_opportunity_reason')) {
    /**
     * Parametrized narrative — NOT AI-generated (deliberately, so listing
     * opportunities costs zero tokens). "Primary category" picks which
     * template to use when an item matched more than one category — same
     * precedence as cms_gsc_primary_action() (cheapest fix first).
     *
     * @param array<string, mixed> $metrics
     */
    function cms_gsc_build_opportunity_reason(string $primaryCategory, array $metrics): string
    {
        $impressions = (int) ($metrics['impressions'] ?? 0);
        $clicks = (int) ($metrics['clicks'] ?? 0);
        $ctrPct = round(((float) ($metrics['ctr'] ?? 0)) * 100, 2);
        $position = round((float) ($metrics['position'] ?? 0), 1);
        $bucketAvgCtrPct = round(((float) ($metrics['bucket_avg_ctr'] ?? 0)) * 100, 2);
        $positionBucketLabel = (string) ($metrics['position_bucket_label'] ?? '');
        $lookbackDays = (int) ($metrics['lookback_days'] ?? 14);
        $query = (string) ($metrics['query'] ?? '');
        $topQuery = (string) ($metrics['top_query'] ?? $query);

        // Content Decay (ROADMAP.md gap #5) — period-over-period fields,
        // only ever populated when this category actually fired (see
        // cms_gsc_compute_opportunities()'s decay block).
        $decayPrevClicks = (int) ($metrics['prev_clicks'] ?? 0);
        $decayCurClicks = (int) ($metrics['cur_clicks'] ?? $clicks);
        $decayDeclinePct = round(abs((float) ($metrics['pct_change_clicks'] ?? 0)) * 100, 1);
        $decayWindowDays = (int) ($metrics['comparison_window_days'] ?? 28);

        // Cannibalization (ROADMAP.md gap #5) — competing_pages is only
        // ever populated for this category (see the standalone
        // cannibalization block in cms_gsc_compute_opportunities()).
        $competingPages = is_array($metrics['competing_pages'] ?? null) ? $metrics['competing_pages'] : [];
        $competingPagesSummary = implode(', ', array_map(
            static fn (array $p): string => (string) $p['title'] . ' (' . round(((float) $p['share']) * 100, 1) . '%)',
            $competingPages
        ));

        return match ($primaryCategory) {
            'Low CTR' => "Artikel ini dapat {$impressions} impressions dalam {$lookbackDays} hari terakhir tapi CTR cuma {$ctrPct}% — di bawah rata-rata {$bucketAvgCtrPct}% untuk artikel di posisi {$positionBucketLabel}. Saran: tulis ulang title yang lebih spesifik & meta description dengan angka/CTA yang jelas.",
            'Zero-click' => "Query \"{$query}\" muncul {$impressions} kali dalam {$lookbackDays} hari terakhir tapi cuma dapat {$clicks} klik, di posisi rata-rata {$position}. Saran: cek relevansi title terhadap query ini, pertimbangkan tambah section yang eksplisit menjawabnya.",
            'Page-one' => "Berada di posisi rata-rata {$position} untuk query \"{$topQuery}\" ({$impressions} impressions dalam {$lookbackDays} hari) — dekat masuk halaman satu. Saran: perdalam bagian yang relevan dengan query ini, tambah subheading yang eksplisit menyebut kata kuncinya.",
            'No article' => "Query \"{$query}\" mendapat {$impressions} impressions dalam {$lookbackDays} hari terakhir tapi situs belum punya artikel yang membahasnya sama sekali. Saran: buat artikel baru menargetkan kata kunci ini.",
            'Content Decay' => "Performa artikel ini menurun: clicks turun dari {$decayPrevClicks} ke {$decayCurClicks} ({$decayDeclinePct}% turun) dibanding {$decayWindowDays} hari sebelumnya. Saran: refresh konten yang sudah ada — cek apakah data/statistik sudah usang dan relevansinya terhadap query saat ini, bukan cuma menambah section baru seperti artikel yang belum pernah menembus page one.",
            'Cannibalization' => "Query \"{$query}\" terbagi signifikan ke " . count($competingPages) . " artikel: {$competingPagesSummary}. Saran: tinjau manual — bedakan intent tiap artikel, konsolidasikan konten yang tumpang tindih, atau tentukan satu sebagai pillar page untuk query ini.",
            default => "Impressions: {$impressions}, CTR: {$ctrPct}%, posisi rata-rata: {$position}.",
        };
    }
}

if (!function_exists('cms_gsc_primary_action')) {
    /**
     * When an item matches more than one category, this decides which one
     * drives recommended_action/recommended_agent/reason — cheapest fix
     * wins (same ordering as the effort lookup: meta fix < content
     * expansion < brand new article). Content Decay sits right after Low
     * CTR — a declining article is a more specific, more urgent diagnosis
     * than "hasn't broken into page one yet" (Page-one), so it takes
     * precedence when a page happens to match both. Cannibalization never
     * reaches this function at all — it's query-level with 2+ pages, a
     * structurally separate detection pass with no other categories to
     * compete with (see cms_gsc_compute_opportunities()).
     *
     * @param list<string> $categories
     * @return array{category: string, action: string, agent: string}
     */
    function cms_gsc_primary_action(array $categories): array
    {
        if (in_array('Low CTR', $categories, true)) {
            return ['category' => 'Low CTR', 'action' => 'seo_recommendation', 'agent' => 'seo_agent'];
        }
        if (in_array('Content Decay', $categories, true)) {
            return ['category' => 'Content Decay', 'action' => 'gsc_content_optimization', 'agent' => 'growth_agent'];
        }
        if (in_array('Page-one', $categories, true)) {
            return ['category' => 'Page-one', 'action' => 'gsc_content_optimization', 'agent' => 'growth_agent'];
        }
        if (in_array('No article', $categories, true)) {
            return ['category' => 'No article', 'action' => 'gsc_article_idea', 'agent' => 'growth_agent'];
        }
        return ['category' => 'Zero-click', 'action' => 'seo_recommendation', 'agent' => 'seo_agent'];
    }
}

if (!function_exists('cms_gsc_compute_opportunities')) {
    /**
     * Rebuilds the Prioritized Opportunities list. Pure SQL + scoring, no
     * AI call — safe/cheap to run on every fetch (see
     * cms_gsc_fetch_and_cache()) plus a manual "Recompute Opportunities"
     * button. Never throws. Rows already status='actioned' are left
     * untouched (frozen history of what was actually generated) — upserts
     * only ever touch 'open' rows via dedupe_key.
     *
     * @return array{ok: bool, count: int, error: string}
     */
    function cms_gsc_compute_opportunities(PDO $pdo): array
    {
        try {
            cms_gsc_ensure_schema($pdo);
            $thresholds = cms_gsc_get_opportunity_thresholds($pdo);
            $settings = cms_gsc_get_settings($pdo);
            $lookbackDays = (int) ($settings['fetch_lookback_days'] ?? 14);
            $ctrByBucket = cms_gsc_site_ctr_by_position_bucket($pdo);
        } catch (Throwable $e) {
            return ['ok' => false, 'count' => 0, 'error' => $e->getMessage()];
        }

        $zeroClickThreshold = (float) $thresholds['zero_click_ctr_threshold'];
        $pageOneMin = (float) $thresholds['page_one_min_position'];
        $pageOneMax = (float) $thresholds['page_one_max_position'];

        // ── Content Decay precompute (ROADMAP.md gap #5) — period-over-
        // period per page, current window vs the equally-long window right
        // before it. Computed once up front (keyed by page_id) so the
        // per-page loop below can fold 'Content Decay' into the SAME
        // multi-category merge as Low CTR/Zero-click/Page-one, rather than
        // a separate upsert pass that would overwrite matched_categories.
        // Dates are computed server-side (never user input) so inlining
        // them into the SQL string is safe — this codebase's own
        // ATTR_EMULATE_PREPARES=false would reject reusing one named
        // placeholder multiple times in one query anyway (see the
        // Indexing Workflow bugfix, ROADMAP.md 28 Jul 2026 entry).
        $comparisonWindowDays = max(1, (int) $thresholds['comparison_window_days']);
        $minComparisonDays = max(1, (int) $thresholds['comparison_min_days']);
        $curStart = date('Y-m-d', strtotime('-' . $comparisonWindowDays . ' days'));
        $prevStart = date('Y-m-d', strtotime('-' . (2 * $comparisonWindowDays) . ' days'));

        $decayByPage = [];
        try {
            $decayRows = $pdo->query(
                "SELECT matched_page_id AS page_id,
                        SUM(CASE WHEN data_date >= '{$curStart}' THEN clicks ELSE 0 END) AS cur_clicks,
                        SUM(CASE WHEN data_date >= '{$curStart}' THEN impressions ELSE 0 END) AS cur_impressions,
                        SUM(CASE WHEN data_date >= '{$curStart}' THEN position * impressions ELSE 0 END) AS cur_weighted_position,
                        SUM(CASE WHEN data_date < '{$curStart}' AND data_date >= '{$prevStart}' THEN clicks ELSE 0 END) AS prev_clicks,
                        SUM(CASE WHEN data_date < '{$curStart}' AND data_date >= '{$prevStart}' THEN impressions ELSE 0 END) AS prev_impressions,
                        COUNT(DISTINCT CASE WHEN data_date >= '{$curStart}' THEN data_date END) AS cur_days,
                        COUNT(DISTINCT CASE WHEN data_date < '{$curStart}' AND data_date >= '{$prevStart}' THEN data_date END) AS prev_days
                   FROM gsc_query_data
                  WHERE matched_page_id IS NOT NULL
                  GROUP BY matched_page_id"
            )->fetchAll();
            foreach ($decayRows as $decayRow) {
                $decayByPage[(int) $decayRow['page_id']] = $decayRow;
            }
        } catch (Throwable $e) {
            $decayByPage = [];
        }

        $upsert = $pdo->prepare(
            'INSERT INTO gsc_opportunities
                (item_type, matched_page_id, query_text, matched_categories, impact_score, effort_score,
                 priority, recommended_agent, recommended_action, reason, metrics_json, dedupe_key, computed_at)
             VALUES
                (:item_type, :matched_page_id, :query_text, :matched_categories, :impact_score, :effort_score,
                 :priority, :recommended_agent, :recommended_action, :reason, :metrics_json, :dedupe_key, NOW())
             ON DUPLICATE KEY UPDATE
                matched_categories = VALUES(matched_categories),
                impact_score = VALUES(impact_score),
                effort_score = VALUES(effort_score),
                priority = VALUES(priority),
                recommended_agent = VALUES(recommended_agent),
                recommended_action = VALUES(recommended_action),
                reason = VALUES(reason),
                metrics_json = VALUES(metrics_json),
                computed_at = NOW()'
        );

        $count = 0;

        // ── Page items ───────────────────────────────────────────────
        try {
            $pageStmt = $pdo->prepare(
                "SELECT p.page_id, SUM(g.impressions) AS total_impressions, SUM(g.clicks) AS total_clicks,
                        AVG(g.position) AS avg_position,
                        GROUP_CONCAT(DISTINCT g.query ORDER BY g.impressions DESC SEPARATOR ', ') AS top_queries,
                        SUBSTRING_INDEX(GROUP_CONCAT(g.query ORDER BY g.impressions DESC SEPARATOR '|'), '|', 1) AS top_query
                   FROM gsc_query_data g
                   INNER JOIN pages p ON p.page_id = g.matched_page_id
                  WHERE g.matched_page_id IS NOT NULL AND p.status = 'published'
                    AND p.page_id NOT IN (
                        SELECT matched_page_id FROM gsc_opportunities
                         WHERE matched_page_id IS NOT NULL AND status = 'actioned'
                    )
                  GROUP BY p.page_id
                 HAVING total_impressions >= :min_impressions"
            );
            $pageStmt->execute(['min_impressions' => (int) $thresholds['min_impressions_page']]);
            $pageRows = $pageStmt->fetchAll();
        } catch (Throwable $e) {
            $pageRows = [];
        }

        foreach ($pageRows as $row) {
            $impressions = (int) $row['total_impressions'];
            $clicks = (int) $row['total_clicks'];
            $ctr = $impressions > 0 ? ($clicks / $impressions) : 0.0;
            $position = (float) $row['avg_position'];
            $bucketLabel = cms_gsc_position_bucket_label($position);
            $bucketAvgCtr = $ctrByBucket[$bucketLabel] ?? $ctr;

            $categories = [];
            $signalScores = [];

            $gap = $bucketAvgCtr - $ctr;
            if ($gap >= (float) end($thresholds['ctr_gap_buckets'])['min']) {
                $categories[] = 'Low CTR';
                $signalScores[] = cms_gsc_bucket_score_desc($gap, $thresholds['ctr_gap_buckets']);
            }
            if ($impressions > 0 && $ctr <= $zeroClickThreshold) {
                $categories[] = 'Zero-click';
                $signalScores[] = (int) $thresholds['zero_click_score'];
            }
            if ($position >= $pageOneMin && $position <= $pageOneMax) {
                $categories[] = 'Page-one';
                $signalScores[] = cms_gsc_bucket_score_asc_max($position, $thresholds['page_one_buckets']);
            }

            // Content Decay (ROADMAP.md gap #5) — folded into the same
            // multi-category check as the 3 above, not a separate upsert
            // pass, so a page that's BOTH declining AND e.g. Low CTR keeps
            // both categories in one row instead of the second pass
            // overwriting the first. Silently skipped (not flagged either
            // way) when either side of the comparison has fewer than
            // comparison_min_days distinct days — same "don't dress up
            // thin data" principle as the Feedback Loop's insufficient_data,
            // just expressed as "no opportunity created" here since
            // gsc_opportunities only ever lists real, actionable items,
            // not a full audit of every page's data-sufficiency status.
            $decayMetrics = null;
            $decayRow = $decayByPage[(int) $row['page_id']] ?? null;
            if ($decayRow !== null) {
                $curDays = (int) $decayRow['cur_days'];
                $prevDays = (int) $decayRow['prev_days'];
                $prevClicksD = (int) $decayRow['prev_clicks'];
                $prevImpressionsD = (int) $decayRow['prev_impressions'];
                if ($curDays >= $minComparisonDays && $prevDays >= $minComparisonDays
                    && $prevClicksD > 0 && $prevImpressionsD >= (int) $thresholds['decay_min_previous_impressions']
                ) {
                    $curClicksD = (int) $decayRow['cur_clicks'];
                    $curImpressionsD = (int) $decayRow['cur_impressions'];
                    $pctChangeClicks = ($curClicksD - $prevClicksD) / $prevClicksD;
                    if ($pctChangeClicks <= -((float) $thresholds['decay_min_pct_decline'])) {
                        $categories[] = 'Content Decay';
                        $signalScores[] = cms_gsc_bucket_score_desc(abs($pctChangeClicks), $thresholds['decay_pct_buckets']);
                        $pctChangeImpressions = $prevImpressionsD > 0 ? ($curImpressionsD - $prevImpressionsD) / $prevImpressionsD : null;
                        $decayMetrics = [
                            'cur_clicks' => $curClicksD,
                            'cur_impressions' => $curImpressionsD,
                            'prev_clicks' => $prevClicksD,
                            'prev_impressions' => $prevImpressionsD,
                            'pct_change_clicks' => round($pctChangeClicks, 4),
                            'pct_change_impressions' => $pctChangeImpressions !== null ? round($pctChangeImpressions, 4) : null,
                            'comparison_window_days' => $comparisonWindowDays,
                        ];
                    }
                }
            }

            if ($categories === []) {
                continue; // not an opportunity — no category signal fired
            }

            $volumeScore = cms_gsc_bucket_score_desc((float) $impressions, $thresholds['volume_buckets']);
            $signalScore = max($signalScores);
            $impact = (int) round(($volumeScore + $signalScore) / 2);
            $impact = max(1, min(10, $impact));

            $effortMap = $thresholds['effort'];
            $effort = 1;
            if (in_array('Low CTR', $categories, true)) {
                $effort = max($effort, (int) $effortMap['low_ctr']);
            }
            if (in_array('Zero-click', $categories, true)) {
                $effort = max($effort, (int) $effortMap['zero_click_page']);
            }
            if (in_array('Page-one', $categories, true)) {
                $effort = max($effort, (int) $effortMap['page_one']);
            }
            if (in_array('Content Decay', $categories, true)) {
                $effort = max($effort, (int) $effortMap['content_decay']);
            }

            $priority = cms_gsc_derive_priority($impact, $effort, $thresholds);
            $primary = cms_gsc_primary_action($categories);

            $metrics = [
                'impressions' => $impressions,
                'clicks' => $clicks,
                'ctr' => $ctr,
                'position' => $position,
                'bucket_avg_ctr' => $bucketAvgCtr,
                'position_bucket_label' => $bucketLabel,
                'lookback_days' => $lookbackDays,
                'top_query' => (string) ($row['top_query'] ?? ''),
                'top_queries' => (string) ($row['top_queries'] ?? ''),
            ];
            if ($decayMetrics !== null) {
                $metrics = array_merge($metrics, $decayMetrics);
            }

            $pageId = (int) $row['page_id'];
            $upsert->execute([
                'item_type' => 'page',
                'matched_page_id' => $pageId,
                'query_text' => null,
                'matched_categories' => implode(', ', $categories),
                'impact_score' => $impact,
                'effort_score' => $effort,
                'priority' => $priority,
                'recommended_agent' => $primary['agent'],
                'recommended_action' => $primary['action'],
                'reason' => cms_gsc_build_opportunity_reason($primary['category'], $metrics),
                'metrics_json' => json_encode($metrics, JSON_UNESCAPED_UNICODE),
                'dedupe_key' => md5('page|' . $pageId),
            ]);
            $count++;
        }

        // ── Query items (no matching article at all) ────────────────
        try {
            $queryStmt = $pdo->prepare(
                "SELECT query, SUM(impressions) AS total_impressions, SUM(clicks) AS total_clicks,
                        AVG(position) AS avg_position
                   FROM gsc_query_data
                  GROUP BY query
                 HAVING SUM(CASE WHEN matched_page_id IS NOT NULL THEN 1 ELSE 0 END) = 0
                    AND total_impressions >= :min_impressions"
            );
            $queryStmt->execute(['min_impressions' => (int) $thresholds['min_impressions_query']]);
            $queryRows = $queryStmt->fetchAll();
        } catch (Throwable $e) {
            $queryRows = [];
        }

        $actionedQueries = [];
        try {
            $stmt = $pdo->query("SELECT query_text FROM gsc_opportunities WHERE item_type = 'query' AND status = 'actioned'");
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN, 0) as $q) {
                $actionedQueries[(string) $q] = true;
            }
        } catch (Throwable $e) {
            // Non-fatal — worst case a query gets re-listed after already being actioned once.
        }

        foreach ($queryRows as $row) {
            $queryText = (string) $row['query'];
            if (isset($actionedQueries[$queryText])) {
                continue;
            }

            $impressions = (int) $row['total_impressions'];
            $clicks = (int) $row['total_clicks'];
            $ctr = $impressions > 0 ? ($clicks / $impressions) : 0.0;
            $position = (float) $row['avg_position'];

            $categories = ['No article'];
            if ($ctr <= $zeroClickThreshold) {
                $categories[] = 'Zero-click';
            }
            if ($position >= $pageOneMin && $position <= $pageOneMax) {
                $categories[] = 'Page-one';
            }

            $impact = cms_gsc_bucket_score_desc((float) $impressions, $thresholds['volume_buckets']);
            $effort = (int) $thresholds['effort']['no_article'];
            $priority = cms_gsc_derive_priority($impact, $effort, $thresholds);

            $metrics = [
                'impressions' => $impressions,
                'clicks' => $clicks,
                'ctr' => $ctr,
                'position' => $position,
                'lookback_days' => $lookbackDays,
                'query' => $queryText,
            ];

            $upsert->execute([
                'item_type' => 'query',
                'matched_page_id' => null,
                'query_text' => mb_substr($queryText, 0, 255),
                'matched_categories' => implode(', ', $categories),
                'impact_score' => $impact,
                'effort_score' => $effort,
                'priority' => $priority,
                'recommended_agent' => 'growth_agent',
                'recommended_action' => 'gsc_article_idea',
                'reason' => cms_gsc_build_opportunity_reason('No article', $metrics),
                'metrics_json' => json_encode($metrics, JSON_UNESCAPED_UNICODE),
                'dedupe_key' => md5('query|' . $queryText),
            ]);
            $count++;
        }

        // ── Cannibalization (query with 2+ matched published pages, each
        // holding a significant share) — ROADMAP.md gap #5. Deliberately
        // NOT period-over-period: unlike Content Decay, this is a snapshot
        // question ("is this query currently split across pages right
        // now"), not a trend question, so it uses the same single-window
        // aggregate (whatever's currently retained in gsc_query_data) as
        // the other 3 original categories above — no comparison_min_days
        // gate applies here. recommended_agent is 'manual_review' (not a
        // real ai_agent_settings key, same "display-only, no AI attached"
        // convention as review_indexing_issue's 'gsc_indexing') because
        // there genuinely is no AI in this loop — deciding whether to
        // differentiate intent, consolidate, or pick a pillar page is a
        // judgment call this codebase deliberately never routes to AI.
        try {
            $cannibalRows = $pdo->query(
                "SELECT g.query, g.matched_page_id AS page_id, p.title,
                        SUM(g.clicks) AS page_clicks, SUM(g.impressions) AS page_impressions
                   FROM gsc_query_data g
                   INNER JOIN pages p ON p.page_id = g.matched_page_id
                  WHERE g.matched_page_id IS NOT NULL AND p.status = 'published'
                  GROUP BY g.query, g.matched_page_id"
            )->fetchAll();
        } catch (Throwable $e) {
            $cannibalRows = [];
        }

        $cannibalByQuery = [];
        foreach ($cannibalRows as $cannibalRow) {
            $cannibalByQuery[(string) $cannibalRow['query']][] = $cannibalRow;
        }

        $cannibalMinShare = (float) $thresholds['cannibalization_min_share'];
        $cannibalMinImpressions = (int) $thresholds['cannibalization_min_impressions'];

        foreach ($cannibalByQuery as $cannibalQueryText => $pagesForQuery) {
            if (count($pagesForQuery) < 2) {
                continue; // only one matched page — not cannibalization by definition
            }

            $totalClicks = (int) array_sum(array_column($pagesForQuery, 'page_clicks'));
            $totalImpressions = (int) array_sum(array_column($pagesForQuery, 'page_impressions'));
            if ($totalImpressions < $cannibalMinImpressions) {
                continue;
            }

            // Share by clicks normally; fall back to impressions share when
            // the query has zero clicks at all across every matched page
            // (division by zero otherwise, and a click-share is meaningless
            // with no clicks to divide up).
            $shareBasis = $totalClicks > 0 ? 'clicks' : 'impressions';
            $shareTotal = $totalClicks > 0 ? $totalClicks : $totalImpressions;

            $competingPages = [];
            foreach ($pagesForQuery as $pageRow) {
                $pageMetric = $shareBasis === 'clicks' ? (int) $pageRow['page_clicks'] : (int) $pageRow['page_impressions'];
                $share = $shareTotal > 0 ? ($pageMetric / $shareTotal) : 0.0;
                if ($share >= $cannibalMinShare) {
                    $competingPages[] = [
                        'page_id' => (int) $pageRow['page_id'],
                        'title' => (string) $pageRow['title'],
                        'clicks' => (int) $pageRow['page_clicks'],
                        'impressions' => (int) $pageRow['page_impressions'],
                        'share' => round($share, 4),
                    ];
                }
            }

            if (count($competingPages) < 2) {
                continue; // fewer than 2 pages actually clear the significance bar
            }

            $impact = cms_gsc_bucket_score_desc((float) $totalImpressions, $thresholds['volume_buckets']);
            $effort = (int) $thresholds['effort']['cannibalization'];
            $priority = cms_gsc_derive_priority($impact, $effort, $thresholds);

            $metrics = [
                'query' => $cannibalQueryText,
                'total_clicks' => $totalClicks,
                'total_impressions' => $totalImpressions,
                'share_basis' => $shareBasis,
                'competing_pages' => $competingPages,
                'lookback_days' => $lookbackDays,
            ];

            $upsert->execute([
                'item_type' => 'query',
                'matched_page_id' => null,
                'query_text' => mb_substr($cannibalQueryText, 0, 255),
                'matched_categories' => 'Cannibalization',
                'impact_score' => $impact,
                'effort_score' => $effort,
                'priority' => $priority,
                'recommended_agent' => 'manual_review',
                'recommended_action' => 'cannibalization_review',
                'reason' => cms_gsc_build_opportunity_reason('Cannibalization', $metrics),
                'metrics_json' => json_encode($metrics, JSON_UNESCAPED_UNICODE),
                'dedupe_key' => md5('cannibalization|query|' . $cannibalQueryText),
            ]);
            $count++;
        }

        return ['ok' => true, 'count' => $count, 'error' => ''];
    }
}
