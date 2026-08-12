<?php
declare(strict_types=1);

/**
 * Growth Agent weekly digest — read-only JSON feed for an EXTERNAL n8n
 * workflow (docs/ROADMAP.md § Now → Fase A, "Notifikasi ringkasan
 * mingguan"). n8n relays this straight into a Telegram bot that already
 * exists on the user's side; this endpoint is only the missing data
 * source.
 *
 * No admin session involved — n8n has no session cookie, so this
 * deliberately does NOT require includes/auth.php or cms_require_role().
 * Auth is a single bearer token (GROWTH_AGENT_DIGEST_TOKEN, config/app.php,
 * gitignored — see the pattern CMS_AI_ENC_SECRET already uses in the same
 * file) checked with hash_equals(), same timing-safe compare
 * cms_verify_csrf() uses in includes/functions.php.
 *
 * Every count below reuses the EXACT query logic already shown to the
 * operator elsewhere, so the Telegram digest never disagrees with what's
 * on screen:
 *   - opportunities_open / opportunities_new_7d : gsc_opportunities.status
 *     (see includes/gsc-api.php's cms_gsc_ensure_schema() schema)
 *   - jobs_need_review / jobs_manual_action      : pages/growth-agent.php's
 *     $needReviewCount query + growth_agent_jobs.status='manual_action'
 *   - indexing_issues_open                       : same job_type/status
 *     cms_growth_agent_log_indexing_issue() dedups against
 *     ('review_indexing_issue', 'manual_action')
 *   - cannibalization_open                       : same job_type/status
 *     cms_growth_agent_log_cannibalization_review() dedups against
 *     ('cannibalization_review', 'manual_action')
 *   - content_conflicts_open                     : same status != 'dismissed'
 *     filter as pages/content-conflict-detection.php
 *
 * Read-only: nothing in this file INSERTs/UPDATEs/DELETEs anything —
 * it is a data source for a notification, not an action queue. Response
 * payload deliberately excludes article titles/content (small payload,
 * nothing unpublished leaks into Telegram/n8n) — just counts + a link back
 * to the admin panel.
 */

require_once dirname(__DIR__) . '/includes/functions.php'; // pulls in config/app.php
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';
require_once dirname(__DIR__) . '/includes/growth-agent-service.php';
require_once dirname(__DIR__) . '/includes/gsc-api.php';

header('Content-Type: application/json; charset=utf-8');

/** @return never */
function cms_growth_digest_respond(array $payload, int $status = 200)
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    cms_growth_digest_respond(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

// Header takes priority over the query param when both are present (an
// n8n HTTP Request node can use either). Apache/PHP-FPM setups commonly
// don't populate $_SERVER['HTTP_AUTHORIZATION'] at all unless explicitly
// configured to forward it (mod_php strips it by default; mod_rewrite
// setups often surface it as REDIRECT_HTTP_AUTHORIZATION instead) — so
// this checks every place the header value can actually land rather than
// assuming one.
$authHeader = $_SERVER['HTTP_AUTHORIZATION']
    ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
    ?? '';
if ($authHeader === '' && function_exists('apache_request_headers')) {
    $apacheHeaders = apache_request_headers();
    $authHeader = $apacheHeaders['Authorization'] ?? $apacheHeaders['authorization'] ?? '';
}

$providedToken = '';
if ($authHeader !== '' && stripos($authHeader, 'Bearer ') === 0) {
    $providedToken = trim(substr($authHeader, 7));
} elseif (isset($_GET['token'])) {
    $providedToken = (string) $_GET['token'];
}

if (!defined('GROWTH_AGENT_DIGEST_TOKEN') || $providedToken === '' || !hash_equals(GROWTH_AGENT_DIGEST_TOKEN, $providedToken)) {
    cms_growth_digest_respond(['ok' => false, 'error' => 'Unauthorized.'], 401);
}

// Same $safeCount() pattern as pages/growth-agent.php — one query failing
// (e.g. a table that's never been lazily created yet) must not 500 the
// whole endpoint, it just reports 0 for that field.
$safeCount = static function (PDO $pdo, string $sql): int {
    try {
        $row = $pdo->query($sql)->fetch();
        return (int) ($row['cnt'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
};

$safeScalar = static function (PDO $pdo, string $sql, string $column): ?string {
    try {
        $row = $pdo->query($sql)->fetch();
        $value = $row[$column] ?? null;
        return $value !== null ? (string) $value : null;
    } catch (Throwable $e) {
        return null;
    }
};

try {
    cms_growth_agent_ensure_schema($pdo);
} catch (Throwable $e) {
    // Ignore — individual $safeCount()/$safeScalar() calls below still
    // guard themselves, this endpoint must never hard-fail just because
    // lazy schema creation hit a snag.
}
try {
    cms_gsc_ensure_schema($pdo);
} catch (Throwable $e) {
    // Ignore, same reasoning as above.
}

// ── gsc_opportunities — same status='open' definition the Prioritized
// Opportunities panel on growth-agent.php uses.
$opportunitiesOpen = $safeCount($pdo, "SELECT COUNT(*) AS cnt FROM gsc_opportunities WHERE status = 'open'");
$opportunitiesNew7d = $safeCount($pdo, "SELECT COUNT(*) AS cnt FROM gsc_opportunities WHERE status = 'open' AND computed_at >= (NOW() - INTERVAL 7 DAY)");

// ── growth_agent_jobs — identical WHERE clause to $needReviewCount in
// pages/growth-agent.php (see the comment there: single source of truth
// for the "Need Review" bucket, shared by the stat cards and the tabs).
$jobsNeedReview = $safeCount($pdo, "
    SELECT COUNT(*) AS cnt FROM growth_agent_jobs j
     WHERE (SELECT COUNT(*) FROM growth_agent_feedback f WHERE f.job_id = j.id) = 0
       AND (
             (j.job_type <> 'seo_recommendation' AND j.status IN ('succeeded', 'failed', 'manual_action'))
          OR (j.job_type = 'seo_recommendation' AND j.status = 'manual_action')
           )
");
$jobsManualAction = $safeCount($pdo, "SELECT COUNT(*) AS cnt FROM growth_agent_jobs WHERE status = 'manual_action'");

// ── review_indexing_issue — same (job_type, status) pair
// cms_growth_agent_log_indexing_issue() dedups new jobs against.
$indexingIssuesOpen = $safeCount($pdo, "SELECT COUNT(*) AS cnt FROM growth_agent_jobs WHERE job_type = 'review_indexing_issue' AND status = 'manual_action'");

// ── cannibalization_review — same (job_type, status) pair
// cms_growth_agent_log_cannibalization_review() dedups new jobs against.
$cannibalizationOpen = $safeCount($pdo, "SELECT COUNT(*) AS cnt FROM growth_agent_jobs WHERE job_type = 'cannibalization_review' AND status = 'manual_action'");

// ── growth_agent_content_conflicts — same status != 'dismissed' filter as
// pages/content-conflict-detection.php's own listing query.
$contentConflictsOpen = $safeCount($pdo, "SELECT COUNT(*) AS cnt FROM growth_agent_content_conflicts WHERE status != 'dismissed'");

// ── last analysis — identical query to $lastAnalysisAt on growth-agent.php.
$lastAnalysisAt = $safeScalar($pdo, 'SELECT MAX(created_at) AS m FROM growth_agent_jobs', 'm');

$adminUrl = cms_admin_base_url() . 'pages/growth-agent.php';

$summaryLines = [
    '📊 Digest Growth Agent Olahraga77',
    $opportunitiesOpen . ' opportunity terbuka (' . $opportunitiesNew7d . ' baru minggu ini)',
    $jobsNeedReview . ' job nunggu review, ' . $jobsManualAction . ' perlu aksi manual',
    $indexingIssuesOpen . ' masalah index, ' . $contentConflictsOpen . ' potensi konflik konten',
    'Terakhir dianalisis: ' . ($lastAnalysisAt !== null && $lastAnalysisAt !== '' ? $lastAnalysisAt : '—'),
];

cms_growth_digest_respond([
    'ok' => true,
    'generated_at' => date('Y-m-d H:i:s'),
    'opportunities_open' => $opportunitiesOpen,
    'opportunities_new_7d' => $opportunitiesNew7d,
    'jobs_need_review' => $jobsNeedReview,
    'jobs_manual_action' => $jobsManualAction,
    'indexing_issues_open' => $indexingIssuesOpen,
    'cannibalization_open' => $cannibalizationOpen,
    'content_conflicts_open' => $contentConflictsOpen,
    'last_analysis_at' => $lastAnalysisAt !== null && $lastAnalysisAt !== '' ? $lastAnalysisAt : null,
    'admin_url' => $adminUrl,
    'summary_text' => implode("\n", $summaryLines),
]);
