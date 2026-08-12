<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';
require_once dirname(__DIR__) . '/includes/growth-agent-service.php';
require_once dirname(__DIR__) . '/includes/gsc-api.php';

// Same tier as the rest of AI Management — see cms_require_role() in
// functions.php for the full tier breakdown.
cms_require_role(['superadmin', 'admin']);

cms_growth_agent_ensure_schema($pdo);

// Lazy auto-cleanup — safety net that runs alongside
// cron/growth_agent_maintenance.php (added 4 Aug 2026), not instead of it:
// if the cron hasn't run yet or is misconfigured, opening this page still
// keeps things maintained, same "self-maintaining on request" spirit as
// cms_ensure_table(). Only removes already-resolved jobs (failed, or
// succeeded-and-never-approved) older than 90 days; see
// cms_growth_agent_cleanup_old_jobs() for exactly what's protected from
// deletion. A manual "Bersihkan job lama" button further down runs the
// same function on demand with a chosen window.
cms_growth_agent_cleanup_old_jobs($pdo, 90);

// Lazy GSC fetch — safety net alongside cron/growth_agent_maintenance.php,
// same spirit as the cleanup call above. Re-fetches only if GSC is
// connected AND the last fetch is more than 24h old; a no-op otherwise.
// Never throws.
cms_gsc_fetch_if_stale($pdo, 24);

// Lazy Agent Memory detection (ROADMAP.md gap #3) — same pattern again,
// gated by gsc_settings.last_memory_detection_at vs
// memory_thresholds_json's detection_interval_days. Advisory-only: this
// never creates/approves/executes anything, see
// cms_growth_agent_detect_memory_patterns()'s own guardrail note. Never throws.
cms_growth_agent_detect_memory_if_stale($pdo);

// Lazy Feedback Loop snapshot (ROADMAP.md gap #4) — same pattern again,
// gated by gsc_settings.last_performance_snapshot_at, default 24h. Purely
// a read/aggregate/upsert into growth_agent_performance — never touches
// `pages` or growth_agent_jobs. Never throws.
cms_growth_agent_snapshot_performance_if_stale($pdo, 24);

// Lazy Measurement Loop (GROWTH_AGENT_V2_PROPOSAL.md § Fase C, reprioritized
// 5 Aug 2026 ahead of Fase E). Unlike the *_if_stale() calls above, this one
// isn't gated by a "last run" timestamp — its own WHERE clause
// (measured_at IS NULL AND 28+ days old) is already self-limiting, so
// repeat calls on every page load only ever touch genuinely new rows, same
// spirit as cms_growth_agent_cleanup_old_jobs() above. Bounded per call via
// opportunity_thresholds_json.measurement_loop.batch_size. Never throws.
cms_growth_agent_run_measurement_loop($pdo);

// Lazy Trending Headlines refresh (GROWTH_AGENT_V2_PROPOSAL.md § 5, 6 Aug
// 2026) — same *_if_stale() pattern as the calls above, gated on
// gsc_settings.last_trending_headlines_refresh_at vs
// opportunity_thresholds_json.trending_headlines.refresh_interval_hours
// (default 12h). Fetches external headlines for context only — see
// cms_growth_agent_refresh_trending_headlines()'s own docblock on the
// headline-only, no-full-article storage boundary. Never throws.
cms_growth_agent_refresh_trending_headlines_if_stale($pdo);

$pageTitle = 'Growth Agent';
$currentNav = 'growth-agent';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'AI Management', 'href' => ''],
    ['label' => 'Growth Agent', 'href' => ''],
];

$selfUrl = 'growth-agent.php';

$redirect = static function (string $message, string $type = 'success') use ($selfUrl): void {
    $_SESSION['cms_flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . $selfUrl, true, 302);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $currentAdminId = (int) ($_SESSION['cms_admin_id'] ?? 0) ?: null;

    // ── Job review — feeds the Fase 3 few-shot pool ──────────────────────
    // Approving a job as-is is what makes it eligible as a future
    // GrowthAgentPromptBuilder example (see services/GrowthAgentPromptBuilder.php).
    if ($action === 'approve' || $action === 'reject') {
        $jobId = (int) ($_POST['job_id'] ?? 0);
        if ($jobId <= 0) {
            $redirect('Job tidak valid.', 'error');
        }

        // Content Agent Adapter (ROADMAP.md gap #1, closed 27 Jul 2026):
        // for job_type='gsc_article_idea', Approve IS the execution step —
        // see cms_growth_agent_create_article_draft_from_idea()'s own
        // docblock for why this one job type is a deliberate exception to
        // "approve isn't execute". Every other job type falls through to
        // the generic feedback+status-flip logic below, unchanged.
        $jobRowStmt = $pdo->prepare('SELECT id, job_type, output_json, page_id FROM growth_agent_jobs WHERE id = :id LIMIT 1');
        $jobRowStmt->execute(['id' => $jobId]);
        $jobRow = $jobRowStmt->fetch();
        if (!$jobRow) {
            $redirect('Job tidak ditemukan.', 'error');
        }

        if ($action === 'approve' && $jobRow['job_type'] === 'gsc_article_idea' && empty($jobRow['page_id'])) {
            $draftResult = cms_growth_agent_create_article_draft_from_idea($pdo, $jobRow, $currentAdminId);

            if (!$draftResult['ok']) {
                // Never silent-fail: the job stays visible as 'failed'
                // with the real error, instead of quietly looking
                // "approved" with no draft to show for it.
                $failUpd = $pdo->prepare("UPDATE growth_agent_jobs SET status = 'failed', error_message = :error, updated_at = NOW() WHERE id = :id");
                $failUpd->execute(['error' => $draftResult['error'], 'id' => $jobId]);
                $redirect('Gagal membuat draft artikel: ' . $draftResult['error'], 'error');
            }

            $ins = $pdo->prepare(
                'INSERT INTO growth_agent_feedback (job_id, action, reviewed_by, created_at)
                 VALUES (:job_id, :action, :reviewed_by, NOW())'
            );
            $ins->execute(['job_id' => $jobId, 'action' => 'approved_as_is', 'reviewed_by' => $currentAdminId]);

            $upd = $pdo->prepare('UPDATE growth_agent_jobs SET status = :status, page_id = :page_id, updated_at = NOW() WHERE id = :id');
            $upd->execute(['status' => 'succeeded', 'page_id' => $draftResult['page_id'], 'id' => $jobId]);

            $redirect('Draft artikel berhasil dibuat — klik "Edit draft" di Job Terbaru untuk melengkapi & publish.');
        }

        // SEO Intelligence Content Agent Adapter (topic_gap_article) — same
        // "Approve IS the execution step" exception as gsc_article_idea
        // above, see cms_growth_agent_create_article_draft_from_topic_gap().
        if ($action === 'approve' && $jobRow['job_type'] === 'topic_gap_article' && empty($jobRow['page_id'])) {
            $draftResult = cms_growth_agent_create_article_draft_from_topic_gap($pdo, $jobRow, $currentAdminId);

            if (!$draftResult['ok']) {
                $failUpd = $pdo->prepare("UPDATE growth_agent_jobs SET status = 'failed', error_message = :error, updated_at = NOW() WHERE id = :id");
                $failUpd->execute(['error' => $draftResult['error'], 'id' => $jobId]);
                $redirect('Gagal membuat draft artikel: ' . $draftResult['error'], 'error');
            }

            $ins = $pdo->prepare(
                'INSERT INTO growth_agent_feedback (job_id, action, reviewed_by, created_at)
                 VALUES (:job_id, :action, :reviewed_by, NOW())'
            );
            $ins->execute(['job_id' => $jobId, 'action' => 'approved_as_is', 'reviewed_by' => $currentAdminId]);

            $upd = $pdo->prepare('UPDATE growth_agent_jobs SET status = :status, page_id = :page_id, updated_at = NOW() WHERE id = :id');
            $upd->execute(['status' => 'succeeded', 'page_id' => $draftResult['page_id'], 'id' => $jobId]);

            $redirect('Draft artikel berhasil dibuat — klik "Edit draft" di Job Terbaru untuk melengkapi & publish.');
        }

        // Keyword Expansion Agent (Fase B item 2, 4 Agu 2026) —
        // 'keyword_expansion_topic' reuses
        // cms_growth_agent_create_article_draft_from_topic_gap() UNCHANGED:
        // its input_brief stores the topic under 'missing_topic' (aliased
        // alongside this agent's own clearer 'topic' key specifically so
        // this reuse works) — see
        // cms_growth_agent_keyword_expansion_process_topics(). Same
        // "Approve IS the execution step" exception as gsc_article_idea/
        // topic_gap_article above.
        if ($action === 'approve' && $jobRow['job_type'] === 'keyword_expansion_topic' && empty($jobRow['page_id'])) {
            $draftResult = cms_growth_agent_create_article_draft_from_topic_gap($pdo, $jobRow, $currentAdminId);

            if (!$draftResult['ok']) {
                $failUpd = $pdo->prepare("UPDATE growth_agent_jobs SET status = 'failed', error_message = :error, updated_at = NOW() WHERE id = :id");
                $failUpd->execute(['error' => $draftResult['error'], 'id' => $jobId]);
                $redirect('Gagal membuat draft artikel: ' . $draftResult['error'], 'error');
            }

            $ins = $pdo->prepare(
                'INSERT INTO growth_agent_feedback (job_id, action, reviewed_by, created_at)
                 VALUES (:job_id, :action, :reviewed_by, NOW())'
            );
            $ins->execute(['job_id' => $jobId, 'action' => 'approved_as_is', 'reviewed_by' => $currentAdminId]);

            $upd = $pdo->prepare('UPDATE growth_agent_jobs SET status = :status, page_id = :page_id, updated_at = NOW() WHERE id = :id');
            $upd->execute(['status' => 'succeeded', 'page_id' => $draftResult['page_id'], 'id' => $jobId]);

            $redirect('Draft artikel berhasil dibuat — klik "Edit draft" di Job Terbaru untuk melengkapi & publish.');
        }

        // Full Draft Automation (GROWTH_AGENT_V2_PROPOSAL.md § 6, Fase F) —
        // MOVED to auto-draft-review.php (8 Aug 2026): same "Approve IS the
        // execution step" exception as gsc_article_idea/topic_gap_article/
        // keyword_expansion_topic above, but this one needs an actual
        // preview (title/body/cover image) before the operator decides —
        // the generic Approve/Reject buttons have no room for that. See
        // $canReviewAutoDraft below, which routes these jobs to that page
        // instead of ever reaching this generic handler.

        // SEO Intelligence — content_conflict_proposal: approve is NEVER an
        // execution step here, "Recommendation only" guardrail — this just
        // falls through to the generic feedback+status-flip logic below,
        // exactly like cannibalization_review/review_indexing_issue. No
        // special-case block needed; noted here so the guardrail is
        // explicit at the point where it would be easy to "accidentally"
        // wire up real article changes later.

        $feedbackAction = $action === 'approve' ? 'approved_as_is' : 'rejected';
        $newStatus = $action === 'approve' ? 'succeeded' : 'failed';

        $ins = $pdo->prepare(
            'INSERT INTO growth_agent_feedback (job_id, action, reviewed_by, created_at)
             VALUES (:job_id, :action, :reviewed_by, NOW())'
        );
        $ins->execute(['job_id' => $jobId, 'action' => $feedbackAction, 'reviewed_by' => $currentAdminId]);

        $upd = $pdo->prepare('UPDATE growth_agent_jobs SET status = :status, updated_at = NOW() WHERE id = :id');
        $upd->execute(['status' => $newStatus, 'id' => $jobId]);

        $redirect($action === 'approve' ? 'Job di-approve — sekarang bisa dipakai sebagai contoh di masa depan.' : 'Job ditolak.');
    }

    // ── "Close as Legacy" — a third review outcome distinct from
    // reject/approve: the underlying signal (e.g. stale GSC data, an
    // outdated recommendation) just isn't relevant anymore, not a
    // judgment that the job itself was bad. Never counts as an active
    // reject (see GrowthAgentPromptBuilder — few-shot examples only ever
    // come from 'approved_as_is', so this can't accidentally pollute that
    // pool either way) and is purged on the same retention schedule as
    // 'failed' jobs (see cms_growth_agent_cleanup_old_jobs()).
    if ($action === 'close_as_legacy') {
        $jobId = (int) ($_POST['job_id'] ?? 0);
        if ($jobId <= 0) {
            $redirect('Job tidak valid.', 'error');
        }

        cms_growth_agent_ensure_legacy_status($pdo);

        $ins = $pdo->prepare(
            'INSERT INTO growth_agent_feedback (job_id, action, reviewed_by, created_at)
             VALUES (:job_id, :action, :reviewed_by, NOW())'
        );
        $ins->execute(['job_id' => $jobId, 'action' => 'closed_as_legacy', 'reviewed_by' => $currentAdminId]);

        $upd = $pdo->prepare("UPDATE growth_agent_jobs SET status = 'closed_as_legacy', updated_at = NOW() WHERE id = :id");
        $upd->execute(['id' => $jobId]);

        $redirect('Job ditandai sebagai legacy — tidak dihitung sebagai reject, cuma sudah tidak relevan lagi.');
    }

    if ($action === 'style_rule_create') {
        $ruleText = trim((string) ($_POST['rule_text'] ?? ''));
        if ($ruleText === '') {
            $redirect('Teks style rule wajib diisi.', 'error');
        }
        $ins = $pdo->prepare(
            'INSERT INTO growth_agent_style_rules (rule_text, source, is_active, created_by, created_at)
             VALUES (:rule_text, :source, 1, :created_by, NOW())'
        );
        $ins->execute(['rule_text' => $ruleText, 'source' => 'manual', 'created_by' => $currentAdminId]);
        $redirect('Style rule berhasil ditambahkan.');
    }

    if ($action === 'style_rule_toggle') {
        $ruleId = (int) ($_POST['id'] ?? 0);
        if ($ruleId <= 0) {
            $redirect('Rule tidak valid.', 'error');
        }
        $pdo->prepare('UPDATE growth_agent_style_rules SET is_active = 1 - is_active WHERE id = :id')
            ->execute(['id' => $ruleId]);
        $redirect('Style rule berhasil diperbarui.');
    }

    if ($action === 'style_rule_delete') {
        $ruleId = (int) ($_POST['id'] ?? 0);
        if ($ruleId <= 0) {
            $redirect('Rule tidak valid.', 'error');
        }
        $pdo->prepare('DELETE FROM growth_agent_style_rules WHERE id = :id')->execute(['id' => $ruleId]);
        $redirect('Style rule berhasil dihapus.');
    }

    // ── "Apply SEO Recommendation" — manual scan trigger ─────────────────
    // Deliberately manual (a button click), not scheduled/automatic — see
    // the approved flow: Scan -> Resolve Target -> SEO child action ->
    // Review & Apply. Each scanned article becomes one manual_action job,
    // reviewed on seo-recommendation-review.php (not the generic
    // Approve/Reject buttons below), since "apply" here writes directly
    // into pages.meta_title / pages.meta_description.
    if ($action === 'scan_seo') {
        $scanStats = cms_growth_agent_scan_seo_recommendations($pdo, 5);
        if ($scanStats['created'] > 0) {
            $redirect($scanStats['created'] . ' rekomendasi SEO baru dibuat dari ' . $scanStats['scanned'] . ' artikel yang di-scan. Cek di tabel "Job Terbaru" di bawah.');
        }
        if ($scanStats['scanned'] === 0) {
            $redirect('Tidak ada artikel baru untuk di-scan — semua artikel published sudah pernah di-scan.', 'error');
        }
        $redirect('Scan selesai (' . $scanStats['scanned'] . ' artikel) tapi tidak ada rekomendasi yang berhasil dibuat. Coba lagi nanti.', 'error');
    }

    // ── Internal Linking Agent — manual scan trigger ─────────────────────
    // Lives here (not seo-intelligence.php) because it's the same
    // "deterministic content scan across published articles, bounded batch
    // per click" shape as the SEO recommendation scan right above — no AI,
    // pure token-overlap + DOM text search (see
    // cms_growth_agent_scan_internal_links() in growth-agent-service.php).
    // seo-intelligence.php is specifically the AI-driven Topic Cluster
    // surface; this doesn't belong there. Each proposed pair becomes one
    // manual_action job, reviewed on internal-link-review.php (not the
    // generic Approve/Reject buttons) since "Apply" here writes directly
    // into pages.content.
    if ($action === 'scan_internal_linking') {
        $ilStats = cms_growth_agent_scan_internal_links($pdo);
        if ($ilStats['created'] > 0) {
            $redirect(
                $ilStats['created'] . ' usulan link internal baru dari ' . $ilStats['scanned'] . ' artikel yang di-scan.'
                . ($ilStats['auto_applied'] > 0 ? ' ' . $ilStats['auto_applied'] . ' di antaranya diterapkan OTOMATIS (Mode Otonom aktif).' : '')
                . ' Cek di tabel "Job Terbaru" di bawah.'
            );
        }
        if ($ilStats['scanned'] === 0) {
            $redirect('Tidak cukup artikel published untuk di-scan (butuh minimal 2).', 'error');
        }
        $redirect('Scan selesai (' . $ilStats['scanned'] . ' artikel) tapi tidak ada usulan link baru yang ditemukan — kemungkinan besar semua pasangan relevan sudah pernah diusulkan/di-link, atau memang belum ada topik yang cukup tumpang tindih.', 'error');
    }

    // ── Autonomous Mode (GROWTH_AGENT_V2_PROPOSAL.md § Fase E, 6 Aug 2026)
    // — one on/off switch, presented to the operator as ONE checkbox even
    // though the underlying config technically supports per-job_type
    // granularity (only internal_link_suggestion exists as a pilot
    // job_type today — see cms_gsc_default_opportunity_thresholds()). Both
    // 'enabled' and job_types.internal_link_suggestion are flipped together
    // so there is exactly one switch to reason about, matching the devs
    // brief's own "kill switch tunggal" wording.
    if ($action === 'autonomous_toggle') {
        $turnOn = !empty($_POST['enabled']);
        $saved = cms_gsc_set_opportunity_threshold_key($pdo, 'autonomous_mode', [
            'enabled' => $turnOn,
            'job_types' => ['internal_link_suggestion' => $turnOn],
            'weekly_limit' => (int) (cms_gsc_get_opportunity_thresholds($pdo)['autonomous_mode']['weekly_limit'] ?? 3),
        ]);
        if (!$saved) {
            $redirect('Gagal menyimpan pengaturan Mode Otonom.', 'error');
        }
        $redirect($turnOn
            ? 'Mode Otonom Internal Linking DINYALAKAN — usulan link internal baru akan otomatis diterapkan (sampai batas mingguan) mulai scan berikutnya.'
            : 'Mode Otonom Internal Linking DIMATIKAN — semua usulan baru kembali butuh review manual.');
    }

    if ($action === 'revert_auto_applied_link') {
        $revertJobId = (int) ($_POST['job_id'] ?? 0);
        if ($revertJobId <= 0) {
            $redirect('Job tidak valid.', 'error');
        }
        $revertResult = cms_growth_agent_revert_auto_applied_link($pdo, $revertJobId);
        $redirect(
            $revertResult['ok'] ? 'Link internal yang di-auto-apply berhasil direvert — konten artikel dikembalikan seperti sebelum perubahan.' : 'Gagal revert: ' . $revertResult['error'],
            $revertResult['ok'] ? 'success' : 'error'
        );
    }

    // Full Draft Automation scheduler (GROWTH_AGENT_V2_PROPOSAL.md § 6,
    // Fase H, 8 Aug 2026) — one save button for all three settings
    // (enabled, schedule, source URLs), same "one form, one save" pattern
    // as every other settings page in this codebase (e.g. site-settings.php)
    // rather than three separate POST actions for three related fields.
    if ($action === 'auto_draft_automation_save') {
        $turnOn = !empty($_POST['enabled']);

        // Hour checkboxes (0-23) translated into a cron expression —
        // operators pick times of day, not raw cron syntax. Minute fixed
        // at 0 (run on the hour), day-of-month/month/day-of-week always
        // "*" — this UI only ever needs to express "which hours of every
        // day", matching the default schedule's own shape (0 6,12,18 * * *).
        $hours = array_values(array_unique(array_map('intval', (array) ($_POST['hours'] ?? []))));
        $hours = array_values(array_filter($hours, static fn (int $h): bool => $h >= 0 && $h <= 23));
        sort($hours);
        $scheduleCron = $hours !== [] ? ('0 ' . implode(',', $hours) . ' * * *') : '0 6,12,18 * * *';

        // Source URLs — one per line from the textarea, same
        // filter_var(FILTER_VALIDATE_URL) check
        // cms_growth_agent_fetch_trending_source() itself relies on
        // implicitly (a malformed URL just fails to fetch cleanly there),
        // applied here instead so the operator gets immediate feedback
        // rather than a silent "0 headlines" later.
        $rawUrls = preg_split('/\r\n|\r|\n/', (string) ($_POST['source_urls'] ?? '')) ?: [];
        $sourceUrls = [];
        $invalidUrls = [];
        foreach ($rawUrls as $rawUrl) {
            $rawUrl = trim($rawUrl);
            if ($rawUrl === '') {
                continue;
            }
            if (filter_var($rawUrl, FILTER_VALIDATE_URL) === false) {
                $invalidUrls[] = $rawUrl;
                continue;
            }
            $sourceUrls[] = $rawUrl;
        }
        if ($invalidUrls !== []) {
            $redirect('URL tidak valid, tidak disimpan: ' . implode(', ', $invalidUrls) . '. Perbaiki dan simpan ulang.', 'error');
        }
        if ($sourceUrls === []) {
            $redirect('Minimal satu URL sumber diperlukan.', 'error');
        }

        // Daily draft cap (8 Aug 2026) — plain text input (not <input
        // type="number">), so range validation MUST happen server-side,
        // never trusted from the browser alone. Empty/non-numeric input
        // casts to 0 via (int), which the clamp below then floors at 0
        // anyway — same "0 = no cap" meaning as an explicit 0, so there's
        // no separate "fall back to 3" branch needed here.
        $maxDraftsPerDay = (int) ($_POST['max_drafts_per_day'] ?? 3);
        $maxDraftsPerDay = max(0, min(1000, $maxDraftsPerDay));

        // Fase G (9 Aug 2026, docs/DECISIONS.md) — operator-approved
        // exception, see opportunity_thresholds_json's own note on this
        // key in gsc-api.php's defaults. Separate checkbox from the
        // "Nyalakan Full Draft Automation" one above (that one gates
        // GENERATION, this one gates PUBLISH) — an operator can run
        // generation without auto-publish, but never the reverse.
        $autoPublish = !empty($_POST['auto_publish']);

        $saved = cms_gsc_set_opportunity_threshold_key($pdo, 'auto_draft_automation', [
            'enabled' => $turnOn,
            'schedule_cron' => $scheduleCron,
            'source_urls' => $sourceUrls,
            'max_drafts_per_day' => $maxDraftsPerDay,
            'auto_publish' => $autoPublish,
        ]);
        if (!$saved) {
            $redirect('Gagal menyimpan pengaturan Full Draft Automation.', 'error');
        }
        $redirect($turnOn
            ? 'Full Draft Automation DINYALAKAN — draft artikel otomatis akan dibuat sesuai jadwal (belum publish, tetap butuh review manual).'
            : 'Full Draft Automation DIMATIKAN — cron tidak akan generate draft baru.');
    }

    // ── Technical SEO Auditor (Fase B item 3, 5 Agu 2026) — pure REPORT,
    // no job/approve involved (see this feature's own top note in
    // growth-agent-service.php on why that's still compliant with § 1b).
    // Alt-text + schema checks are bundled into one button since both are
    // cheap (no network / fast same-server fetch); Core Web Vitals (PSI)
    // is its own separate action because a single PSI call can take up to
    // ~30s — bundling it with the others risks a PHP timeout.
    if ($action === 'tsa_check_content') {
        $contentStats = cms_growth_agent_tsa_run_content_checks($pdo);
        $schemaStats = cms_growth_agent_tsa_run_schema_checks($pdo);
        $redirect(
            'Cek konten selesai — ' . $contentStats['checked'] . ' artikel dicek alt text, '
            . $schemaStats['checked'] . ' artikel dicek schema markup'
            . (($contentStats['errors'] + $schemaStats['errors']) > 0 ? ' (' . ($contentStats['errors'] + $schemaStats['errors']) . ' gagal, lihat detail di tabel).' : '.')
        );
    }

    if ($action === 'tsa_check_psi') {
        $psiStats = cms_growth_agent_tsa_run_psi($pdo);
        if ($psiStats['checked'] === 0 && $psiStats['errors'] === 0) {
            $redirect('Tidak ada artikel untuk dicek Core Web Vitals.', 'error');
        }
        $redirect('Core Web Vitals dicek untuk ' . ($psiStats['checked'] + $psiStats['errors']) . ' artikel (' . $psiStats['checked'] . ' berhasil, ' . $psiStats['errors'] . ' gagal).');
    }

    if ($action === 'tsa_save_psi_key') {
        $psiKeyInput = trim((string) ($_POST['psi_api_key'] ?? ''));
        if ($psiKeyInput === '') {
            $redirect('API key kosong — tidak ada yang disimpan. Gunakan "Hapus API Key" kalau memang ingin menghapus key yang sudah tersimpan.', 'error');
        }
        $saved = cms_growth_agent_tsa_save_psi_api_key($pdo, $psiKeyInput);
        $redirect($saved ? 'API key PageSpeed Insights berhasil disimpan (terenkripsi).' : 'Gagal menyimpan API key.', $saved ? 'success' : 'error');
    }

    if ($action === 'tsa_clear_psi_key') {
        $cleared = cms_growth_agent_tsa_save_psi_api_key($pdo, '');
        $redirect($cleared ? 'API key PageSpeed Insights dihapus — PSI akan dipanggil tanpa key (limit publik per-IP berlaku).' : 'Gagal menghapus API key.', $cleared ? 'success' : 'error');
    }

    // ── Manual cleanup — same rules as the lazy auto-cleanup above, just
    // on-demand with a chosen retention window. Never removes 'ready',
    // 'running', or 'manual_action' jobs, and never removes a 'succeeded'
    // job a human approved as-is (the Fase 3 few-shot pool).
    if ($action === 'cleanup_jobs') {
        $days = (int) ($_POST['days'] ?? 90);
        $deleted = cms_growth_agent_cleanup_old_jobs($pdo, $days);
        $redirect($deleted > 0
            ? $deleted . ' job lama (lebih dari ' . max(7, min(365, $days)) . ' hari) berhasil dihapus.'
            : 'Tidak ada job yang perlu dibersihkan untuk jendela waktu itu.');
    }

    // ── Recompute Prioritized Opportunities on demand — same pure SQL/
    // scoring pass that already runs automatically after every GSC fetch
    // (cms_gsc_fetch_and_cache() -> cms_gsc_compute_opportunities()); this
    // button exists for refreshing scores without waiting for the next
    // fetch (e.g. after tuning thresholds).
    if ($action === 'recompute_opportunities') {
        $result = cms_gsc_compute_opportunities($pdo);
        $redirect($result['ok']
            ? $result['count'] . ' opportunity dihitung ulang.'
            : 'Gagal recompute: ' . $result['error'], $result['ok'] ? 'success' : 'error');
    }

    // ── Generate on-demand from one Prioritized Opportunities row ────────
    // The opportunity table itself is pure scoring (no AI, computed by
    // cms_gsc_compute_opportunities()) — AI is only ever called here, for
    // the ONE row the operator picked. Dispatches by recommended_action,
    // reusing the exact same generate engines as the "Scan for SEO
    // improvements" button (single-item calls, not bulk).
    if ($action === 'generate_from_opportunity') {
        $oppId = (int) ($_POST['opportunity_id'] ?? 0);
        if ($oppId <= 0) {
            $redirect('Opportunity tidak valid.', 'error');
        }

        $oppStmt = $pdo->prepare("SELECT * FROM gsc_opportunities WHERE id = :id AND status = 'open' LIMIT 1");
        $oppStmt->execute(['id' => $oppId]);
        $opp = $oppStmt->fetch();
        if (!$opp) {
            $redirect('Opportunity tidak ditemukan atau sudah pernah di-generate.', 'error');
        }

        $metrics = json_decode((string) ($opp['metrics_json'] ?? ''), true);
        $metrics = is_array($metrics) ? $metrics : [];
        $priority = (string) $opp['priority'];
        $jobId = 0;
        $ok = false;
        $genError = 'Aksi tidak dikenal untuk opportunity ini.';

        if ($opp['recommended_action'] === 'seo_recommendation') {
            $pageStmt = $pdo->prepare(
                'SELECT page_id, title, slug, excerpt, content, meta_title, meta_description FROM pages WHERE page_id = :id LIMIT 1'
            );
            $pageStmt->execute(['id' => (int) $opp['matched_page_id']]);
            $page = $pageStmt->fetch();
            if (!$page) {
                $redirect('Artikel sumber tidak ditemukan — mungkin sudah dihapus.', 'error');
            }
            $result = cms_growth_agent_run_seo_recommendation_scan($pdo, [$page], [(int) $page['page_id'] => $priority]);
            $ok = $result['created'] > 0;
            $jobId = (int) ($result['job_ids'][0] ?? 0);
            $genError = $ok ? '' : 'AI request gagal atau hasil tidak dalam format yang diharapkan.';
        } elseif ($opp['recommended_action'] === 'gsc_content_optimization') {
            $pageStmt = $pdo->prepare('SELECT page_id, title, slug, excerpt, content FROM pages WHERE page_id = :id LIMIT 1');
            $pageStmt->execute(['id' => (int) $opp['matched_page_id']]);
            $page = $pageStmt->fetch();
            if (!$page) {
                $redirect('Artikel sumber tidak ditemukan — mungkin sudah dihapus.', 'error');
            }
            $page['avg_position'] = $metrics['position'] ?? 0;
            $page['impressions'] = $metrics['impressions'] ?? 0;
            $page['top_queries'] = $metrics['top_queries'] ?? '';
            // Content Decay (ROADMAP.md gap #5) — when this opportunity's
            // primary category is a decline rather than "hasn't broken
            // into page one yet", pass the trend evidence through so
            // cms_growth_agent_generate_content_optimization() switches to
            // its decay-specific prompt instead of the striking-distance one.
            if (str_contains((string) $opp['matched_categories'], 'Content Decay') && isset($metrics['pct_change_clicks'])) {
                $page['is_decay'] = true;
                $page['prev_clicks'] = $metrics['prev_clicks'] ?? 0;
                $page['cur_clicks'] = $metrics['cur_clicks'] ?? 0;
                $page['prev_impressions'] = $metrics['prev_impressions'] ?? 0;
                $page['cur_impressions'] = $metrics['cur_impressions'] ?? 0;
                $page['pct_change_clicks'] = $metrics['pct_change_clicks'];
                $page['comparison_window_days'] = $metrics['comparison_window_days'] ?? 28;
            }
            $result = cms_growth_agent_generate_content_optimization($pdo, $page, $priority);
            $ok = $result['ok'];
            $jobId = $result['job_id'];
            $genError = $result['error'];
        } elseif ($opp['recommended_action'] === 'gsc_article_idea') {
            $queryData = [
                'query' => (string) $opp['query_text'],
                'impressions' => (int) ($metrics['impressions'] ?? 0),
                'avg_position' => (float) ($metrics['position'] ?? 0),
            ];
            $result = cms_growth_agent_generate_article_idea($pdo, $queryData, $priority);
            $ok = $result['ok'];
            $jobId = $result['job_id'];
            $genError = $result['error'];
        } elseif ($opp['recommended_action'] === 'cannibalization_review') {
            // No AI here at all — this deterministically surfaces the
            // query + competing pages/shares as a manual_action job for
            // the operator to review on cannibalization-review.php.
            // ROADMAP.md gap #5 — deciding intent-split/consolidate/pillar
            // is a judgment call this codebase never routes to AI.
            $competingPages = is_array($metrics['competing_pages'] ?? null) ? $metrics['competing_pages'] : [];
            $jobId = cms_growth_agent_log_cannibalization_review(
                $pdo, (string) $opp['query_text'], $competingPages,
                (int) ($metrics['total_clicks'] ?? 0), (int) ($metrics['total_impressions'] ?? 0), $priority
            );
            $ok = $jobId > 0;
            $genError = $ok ? '' : 'Gagal membuat job review cannibalization.';
        }

        if ($jobId > 0) {
            $pdo->prepare("UPDATE gsc_opportunities SET status = 'actioned', linked_job_id = :job_id WHERE id = :id")
                ->execute(['job_id' => $jobId, 'id' => $oppId]);
        }

        $redirect($ok
            ? 'Rekomendasi berhasil digenerate — cek tabel "Job Terbaru" di bawah untuk review.'
            : 'Generate gagal: ' . $genError, $ok ? 'success' : 'error');
    }

    // ── Indexing Workflow (Phase 5 roadmap, ROADMAP.md gap #2) ────────────
    // Inspect ONE article's index status on demand — never writes to the
    // article itself, only upserts gsc_url_inspections and, if the verdict
    // looks problematic, logs a manual_action 'review_indexing_issue' job.
    if ($action === 'inspect_single_url') {
        $inspectPageId = (int) ($_POST['page_id'] ?? 0);
        if ($inspectPageId <= 0) {
            $redirect('Artikel tidak valid.', 'error');
        }

        require_once dirname(__DIR__) . '/includes/sitemap-service.php';
        $inspectPageStmt = $pdo->prepare("SELECT page_id, slug, canonical_url FROM pages WHERE page_id = :id AND status = 'published' LIMIT 1");
        $inspectPageStmt->execute(['id' => $inspectPageId]);
        $inspectPage = $inspectPageStmt->fetch();
        if (!$inspectPage) {
            $redirect('Artikel tidak ditemukan atau belum published.', 'error');
        }

        $inspectCanonical = trim((string) ($inspectPage['canonical_url'] ?? ''));
        $inspectUrl = $inspectCanonical !== ''
            ? $inspectCanonical
            : cms_sitemap_absolute_url(cms_sitemap_path_for('article', (string) $inspectPage['slug']));

        $inspectResult = cms_gsc_inspect_url($pdo, $inspectUrl, $inspectPageId);
        if (!$inspectResult['ok']) {
            $redirect('Inspect URL gagal: ' . $inspectResult['error'], 'error');
        }

        if (cms_growth_agent_indexing_issue_needs_review($inspectResult['data'])) {
            cms_growth_agent_log_indexing_issue($pdo, $inspectPageId, $inspectUrl, $inspectResult['data']);
            $redirect('Inspect selesai — verdict: ' . $inspectResult['data']['verdict'] . '. Job "review_indexing_issue" dibuat, cek Job Terbaru.', 'error');
        }

        $redirect('Inspect selesai — verdict: ' . $inspectResult['data']['verdict'] . ', tidak ada masalah terdeteksi.');
    }

    // Batch version of the same inspection — default 10, same clamp
    // pattern as cms_growth_agent_scan_seo_recommendations()'s $limit.
    if ($action === 'inspect_priority_urls') {
        $inspectLimit = (int) ($_POST['limit'] ?? 10);
        $inspectStats = cms_growth_agent_inspect_priority_urls($pdo, $inspectLimit);
        if ($inspectStats['inspected'] === 0) {
            $redirect('Tidak ada artikel published untuk diinspeksi.', 'error');
        }
        $redirect(
            $inspectStats['inspected'] . ' URL diinspeksi, ' . $inspectStats['issues_found'] . ' masalah terdeteksi'
            . ($inspectStats['errors'] > 0 ? ', ' . $inspectStats['errors'] . ' gagal' : '') . '.'
        );
    }

    // ── Agent Memory (ROADMAP.md gap #3) ──────────────────────────────────
    // The ONLY manual action Agent Memory has — deliberately not "approve"
    // or "execute" (memory is not an action queue, see the guardrail note
    // on cms_growth_agent_detect_memory_patterns()). Lets an operator turn
    // off a pattern they judge no longer relevant; never deletes the row.
    if ($action === 'mark_memory_stale') {
        $memoryId = (int) ($_POST['memory_id'] ?? 0);
        if ($memoryId <= 0) {
            $redirect('Pattern tidak valid.', 'error');
        }
        $marked = cms_growth_agent_mark_memory_stale($pdo, $memoryId);
        $redirect($marked ? 'Pattern ditandai stale.' : 'Pattern tidak ditemukan.', $marked ? 'success' : 'error');
    }

    $redirect('Aksi tidak dikenal.', 'error');
}

$alerts = [];
if (isset($_SESSION['cms_flash']) && is_array($_SESSION['cms_flash'])) {
    $alerts[] = $_SESSION['cms_flash'];
    unset($_SESSION['cms_flash']);
}

// ── Stats ─────────────────────────────────────────────────────────────
// NOTE: this connection is opened with PDO::ATTR_DEFAULT_FETCH_MODE =>
// PDO::FETCH_ASSOC (see config/database.php) — a plain fetch() here never
// has a numeric index [0], so the previous "$row[0] ?? 0" always silently
// fell through to 0 regardless of the real count. Fixed by aliasing the
// column and reading it by name.
$safeCount = static function (PDO $pdo, string $sql): int {
    try {
        $row = $pdo->query($sql)->fetch();
        return (int) ($row['cnt'] ?? 0);
    } catch (\Throwable $e) {
        return 0;
    }
};

// ── Artikel Terpopuler (by total views) — NOT gated by $gscConnected, this
// reads pages.views directly (already populated by wpm_increment_views()
// in artikel.php), not GSC data at all. Read-only, no action buttons.
$topViewedArticles = [];
try {
    $topViewedStmt = $pdo->query(
        "SELECT page_id, title, slug, views
           FROM pages
          WHERE status = 'published'
          ORDER BY views DESC
          LIMIT 10"
    );
    $topViewedArticles = $topViewedStmt->fetchAll();
} catch (Throwable $e) {
    $topViewedArticles = [];
}

$statsCards = [
    ['label' => 'Disetujui / Siap', 'value' => $safeCount($pdo, "SELECT COUNT(*) AS cnt FROM growth_agent_jobs WHERE status = 'ready'"), 'hint' => 'Menunggu eksekusi'],
    ['label' => 'Berjalan', 'value' => $safeCount($pdo, "SELECT COUNT(*) AS cnt FROM growth_agent_jobs WHERE status = 'running'"), 'hint' => 'Sedang berjalan'],
    ['label' => 'Berhasil', 'value' => $safeCount($pdo, "SELECT COUNT(*) AS cnt FROM growth_agent_jobs WHERE status = 'succeeded'"), 'hint' => 'Draft berhasil dibuat'],
    ['label' => 'Gagal', 'value' => $safeCount($pdo, "SELECT COUNT(*) AS cnt FROM growth_agent_jobs WHERE status = 'failed'"), 'hint' => 'Bisa dicoba lagi'],
    ['label' => 'Aksi Manual', 'value' => $safeCount($pdo, "SELECT COUNT(*) AS cnt FROM growth_agent_jobs WHERE status = 'manual_action'"), 'hint' => 'Perlu eksekusi manual operator'],
];

// ── "Need Review" / "Ready to Run" / "Completed" — same 3-way split used
// both for the Recent Jobs tabs below and these two extra summary cards,
// so the numbers on the cards always agree with what's actually in each
// tab (single source of truth: the same WHERE logic, just expressed once
// in SQL here and once per-row in PHP further down for the table itself).
// "Need Review" mirrors $canReviewGeneric/$canReviewSeo exactly. Completed
// is derived as total-minus-the-other-two rather than its own query,
// since the 3 buckets are exhaustive and mutually exclusive by
// construction (every job is in exactly one).
$needReviewCount = $safeCount($pdo, "
    SELECT COUNT(*) AS cnt FROM growth_agent_jobs j
     WHERE (SELECT COUNT(*) FROM growth_agent_feedback f WHERE f.job_id = j.id) = 0
       AND (
             (j.job_type <> 'seo_recommendation' AND j.status IN ('succeeded', 'failed', 'manual_action'))
          OR (j.job_type = 'seo_recommendation' AND j.status = 'manual_action')
           )
");
$readyToRunCount = $safeCount($pdo, "SELECT COUNT(*) AS cnt FROM growth_agent_jobs WHERE status IN ('ready', 'running')");
$totalJobsCount = $safeCount($pdo, 'SELECT COUNT(*) AS cnt FROM growth_agent_jobs');
$completedCount = max(0, $totalJobsCount - $needReviewCount - $readyToRunCount);
$lastAnalysisAt = (string) ($pdo->query('SELECT MAX(created_at) AS m FROM growth_agent_jobs')->fetch()['m'] ?? '');

$summaryCards = [
    ['label' => 'Tertunda', 'value' => $needReviewCount, 'hint' => 'Menunggu di-review'],
    ['label' => 'Selesai', 'value' => $completedCount, 'hint' => 'Sudah di-approve/reject/legacy'],
    ['label' => 'Analisis Terakhir', 'value' => $lastAnalysisAt !== '' ? $lastAnalysisAt : '—', 'hint' => 'Job terakhir dibuat'],
];

// ── Recent jobs — with page title (if linked) and whether feedback already exists ──
$jobsStmt = $pdo->query(
    "SELECT j.id, j.job_type, j.agent_key, j.page_id, j.status, j.priority, j.model_used, j.latency_ms,
            j.error_message, j.created_at, j.input_brief, p.title AS page_title,
            p.status AS page_status, p.slug AS page_slug,
            (SELECT COUNT(*) FROM growth_agent_feedback f WHERE f.job_id = j.id) AS feedback_count
       FROM growth_agent_jobs j
       LEFT JOIN pages p ON p.page_id = j.page_id
      ORDER BY j.created_at DESC
      LIMIT 25"
);
$jobs = $jobsStmt->fetchAll();

// SEO-G0 Gate (GROWTH_AGENT_V2_PROPOSAL.md Fase A item 3) — warnings ride
// along in input_brief.seo_g0_gate for 'gsc_article_idea'/'topic_gap_article'/
// 'keyword_expansion_topic' jobs (see cms_growth_agent_seo_g0_gate() in
// growth-agent-service.php). Decoded once here, not per-row, and only for
// the job types that can carry it — every other job type's input_brief is
// left completely alone.
// Title-vs-headline "aturan keras" (GROWTH_AGENT_V2_PROPOSAL.md § 5, 6 Aug
// 2026) — same ride-along-in-input_brief shape as seo_g0_gate above, only
// ever present for 'gsc_article_idea' (the only job type Trending
// Headlines context feeds today — see
// cms_growth_agent_check_title_vs_headlines() in growth-agent-service.php).
foreach ($jobs as &$jobForGate) {
    $jobForGate['_g0_warnings'] = [];
    $jobForGate['_title_headline_flag'] = null;
    if (!in_array($jobForGate['job_type'], ['gsc_article_idea', 'topic_gap_article', 'keyword_expansion_topic'], true)) {
        continue;
    }
    $briefDecoded = json_decode((string) $jobForGate['input_brief'], true);
    if (is_array($briefDecoded) && is_array($briefDecoded['seo_g0_gate']['warnings'] ?? null)) {
        $jobForGate['_g0_warnings'] = $briefDecoded['seo_g0_gate']['warnings'];
    }
    if (is_array($briefDecoded) && ($briefDecoded['title_vs_headline_check']['flagged'] ?? false) === true) {
        $jobForGate['_title_headline_flag'] = $briefDecoded['title_vs_headline_check']['matches'] ?? [];
    }
}
unset($jobForGate);

// ── Style rules ──────────────────────────────────────────────────────
$rulesStmt = $pdo->query(
    'SELECT id, rule_text, source, is_active, created_at FROM growth_agent_style_rules ORDER BY created_at DESC'
);
$styleRules = $rulesStmt->fetchAll();

$statusPill = [
    'ready' => 'muted',
    'running' => 'accent',
    'succeeded' => 'ok',
    'failed' => 'warn',
    'manual_action' => 'info',
    'closed_as_legacy' => 'muted',
];

$gscSettings = cms_gsc_get_settings($pdo);
$gscConnected = !empty($gscSettings['is_active']) && !empty($gscSettings['site_url']);

// ── GSC aggregate stats + Top Queries + Top Pages ──
$gscAggregate = null;
$gscTopQueries = [];
$gscTopPages = [];
if ($gscConnected) {
    try {
        $aggRow = $pdo->query(
            'SELECT SUM(clicks) AS total_clicks, SUM(impressions) AS total_impressions,
                    AVG(position) AS avg_position, MIN(data_date) AS min_date, MAX(data_date) AS max_date
               FROM gsc_query_data'
        )->fetch();
        if ($aggRow && (int) ($aggRow['total_impressions'] ?? 0) > 0) {
            $impressions = (int) $aggRow['total_impressions'];
            $gscAggregate = [
                'clicks' => (int) $aggRow['total_clicks'],
                'impressions' => $impressions,
                'ctr' => round(((int) $aggRow['total_clicks'] / $impressions) * 100, 2),
                'avg_position' => round((float) $aggRow['avg_position'], 1),
                'min_date' => (string) $aggRow['min_date'],
                'max_date' => (string) $aggRow['max_date'],
            ];
        }

        $topStmt = $pdo->query(
            'SELECT query, SUM(clicks) AS clicks, SUM(impressions) AS impressions, AVG(position) AS position
               FROM gsc_query_data
              GROUP BY query
              ORDER BY impressions DESC
              LIMIT 10'
        );
        $gscTopQueries = $topStmt->fetchAll();

        $topPagesStmt = $pdo->query(
            "SELECT g.page_url, g.matched_page_id, p.title AS page_title,
                    SUM(g.clicks) AS clicks, SUM(g.impressions) AS impressions,
                    AVG(g.position) AS position
               FROM gsc_query_data g
               LEFT JOIN pages p ON p.page_id = g.matched_page_id
              GROUP BY g.page_url, g.matched_page_id, p.title
              ORDER BY impressions DESC
              LIMIT 10"
        );
        $gscTopPages = $topPagesStmt->fetchAll();
    } catch (Throwable $e) {
        $gscAggregate = null;
        $gscTopQueries = [];
        $gscTopPages = [];
    }
}

// ── Prioritized Opportunities ──
$opportunities = [];
if ($gscConnected) {
    try {
        $oppStmt = $pdo->query(
            "SELECT o.*, p.title AS page_title, p.slug AS page_slug
               FROM gsc_opportunities o
               LEFT JOIN pages p ON p.page_id = o.matched_page_id
              WHERE o.status = 'open'
              ORDER BY FIELD(o.priority, 'high', 'medium', 'low'), o.impact_score DESC
              LIMIT 30"
        );
        $opportunities = $oppStmt->fetchAll();
    } catch (Throwable $e) {
        $opportunities = [];
    }
}
$priorityPill = ['high' => 'warn', 'medium' => 'accent', 'low' => 'muted'];

// ── Index Status (Phase 5 roadmap, ROADMAP.md gap #2) ──
// Never-inspected pages first, then oldest-inspected — same "round-robin
// coverage over time" ordering as cms_growth_agent_inspect_priority_urls()
// itself, so what the operator SEES here matches what the batch button
// would pick next if clicked. Read-only listing; the actual inspect calls
// only ever happen from the inspect_single_url/inspect_priority_urls POST
// actions above, never on a plain page load.
$indexInspections = [];
if ($gscConnected) {
    try {
        $indexStmt = $pdo->query(
            "SELECT p.page_id, p.title, p.slug,
                    i.verdict, i.coverage_state, i.last_crawl_time, i.inspected_at, i.error_message
               FROM pages p
               LEFT JOIN gsc_url_inspections i ON i.page_id = p.page_id
              WHERE p.status = 'published'
              ORDER BY (i.inspected_at IS NULL) DESC, i.inspected_at ASC
              LIMIT 15"
        );
        $indexInspections = $indexStmt->fetchAll();
    } catch (Throwable $e) {
        $indexInspections = [];
    }
}
$indexVerdictPill = ['PASS' => 'ok', 'PARTIAL' => 'warn', 'FAIL' => 'warn', 'NEUTRAL' => 'muted', 'VERDICT_UNSPECIFIED' => 'muted'];

// ── Agent Memory (ROADMAP.md gap #3) ──
// Read-only listing — no approve/execute here on purpose (memory is not
// an action queue, see cms_growth_agent_detect_memory_patterns()'s own
// guardrail note). 'stale' rows are still shown (not hidden) so an
// operator can see detection history, not just what's currently active.
$memoryPatterns = [];
try {
    $memoryStmt = $pdo->query(
        "SELECT m.*, p.title AS page_title
           FROM growth_agent_memory m
           LEFT JOIN pages p ON p.page_id = m.matched_page_id
          ORDER BY FIELD(m.status, 'active', 'pending_review', 'stale'), m.last_confirmed_at DESC
          LIMIT 30"
    );
    $memoryPatterns = $memoryStmt->fetchAll();
} catch (Throwable $e) {
    $memoryPatterns = [];
}
$memoryStatusPill = ['active' => 'ok', 'pending_review' => 'info', 'stale' => 'muted'];
$memoryPatternLabel = ['winning_pattern' => 'Pola Sukses', 'content_gap' => 'Kesenjangan Konten'];

// ── Daftar Artikel Berpotensi Tinggi (GROWTH_AGENT_V2_PROPOSAL.md § Fase
// D, renamed 6 Aug 2026 from "Backlink Monitor" — see
// cms_growth_agent_get_high_potential_articles()'s own docblock for why).
// Gated on $gscConnected same as Feedback Loop below — this reads
// gsc_query_data, so without GSC connected there's nothing to rank by.
// Live-computed on every page load (no persistence, unlike Technical SEO
// Auditor) — cheap SQL aggregate, never throws.
$highPotentialArticles = [];
if ($gscConnected) {
    try {
        $highPotentialArticles = cms_growth_agent_get_high_potential_articles($pdo);
    } catch (Throwable $e) {
        $highPotentialArticles = [];
    }
}

// ── Feedback Loop / Before-After (ROADMAP.md gap #4) ──
// Read-only reporting — no approve/execute here (see
// cms_growth_agent_get_feedback_report()'s own guardrail note on why
// gsc_content_optimization is deliberately excluded).
$feedbackReport = [];
if ($gscConnected) {
    try {
        $feedbackReport = cms_growth_agent_get_feedback_report($pdo, 20, 28);
    } catch (Throwable $e) {
        $feedbackReport = [];
    }
}
$feedbackActionLabel = [
    'internal_link_suggestion' => 'Internal Link (Diterapkan)',
    'seo_recommendation' => 'Rekomendasi SEO (Diterapkan)',
    'gsc_article_idea' => 'Ide Artikel (Terbit)',
];

// ── Technical SEO Auditor report (Fase B item 3) — pure read, joined
// against every published article so articles never-yet-audited still
// show up. THREE overall states, not two (fixed 5 Agu 2026 — the previous
// two-state version silently counted "never audited at all" as "clean",
// which is actively misleading: a report claiming an unaudited article is
// fine is worse than no report at all):
//   'issue'     — checked in at least one dimension, and either a real
//                 problem was found (missing alt, schema confirmed
//                 missing, poor PSI score) OR a check that WAS attempted
//                 failed to complete (schema/PSI fetch error) — a failed
//                 check is itself something worth following up on, not
//                 nothing.
//   'unchecked' — ALL THREE dimensions (content/schema/PSI) have never
//                 been run at all.
//   'clean'     — checked in at least one dimension, nothing wrong and no
//                 check failures found in whatever WAS checked. Per-cell
//                 pills below still show "Belum diperiksa" for any
//                 individual dimension that hasn't run yet on this
//                 article — bucketing the row as 'clean' overall never
//                 hides that per-dimension gap, it only means "nothing
//                 checked so far found a problem".
// Both 'issue' and 'unchecked' rows are shown in the table (only 'clean'
// rows are hidden) — "never audited" is exactly as actionable as "found a
// problem", so it must not disappear into a clean-looking summary either.
$tsaThresholds = cms_growth_agent_tsa_thresholds($pdo);
$tsaPoorScore = (int) ($tsaThresholds['psi_poor_score_threshold'] ?? 50);
$tsaRows = [];
try {
    cms_growth_agent_tsa_ensure_schema($pdo);
    $tsaStmt = $pdo->prepare(
        "SELECT p.page_id, p.title,
                a.total_image_count, a.missing_alt_count, a.content_checked_at,
                a.has_news_article_schema, a.has_breadcrumb_schema, a.schema_check_error, a.schema_checked_at,
                a.psi_mobile_score, a.psi_lcp_ms, a.psi_cls, a.psi_error, a.psi_checked_at
           FROM pages p
           LEFT JOIN growth_agent_technical_audits a ON a.page_id = p.page_id
          WHERE p.status = 'published'"
    );
    $tsaStmt->execute();
    $tsaAll = $tsaStmt->fetchAll();

    $tsaClassify = static function (array $row) use ($tsaPoorScore): string {
        $anyChecked = $row['content_checked_at'] !== null || $row['schema_checked_at'] !== null || $row['psi_checked_at'] !== null;
        if (!$anyChecked) {
            return 'unchecked';
        }
        // Real content problems.
        if ((int) ($row['missing_alt_count'] ?? 0) > 0) {
            return 'issue';
        }
        if ($row['has_news_article_schema'] !== null && (int) $row['has_news_article_schema'] === 0) {
            return 'issue';
        }
        if ($row['has_breadcrumb_schema'] !== null && (int) $row['has_breadcrumb_schema'] === 0) {
            return 'issue';
        }
        if ($row['psi_mobile_score'] !== null && (int) $row['psi_mobile_score'] <= $tsaPoorScore) {
            return 'issue';
        }
        // A check that was attempted but failed (schema/PSI fetch error —
        // content-check has no such state, it only ever writes
        // content_checked_at on success) is surfaced as 'issue' too, not
        // silently folded into 'clean' — an operator needs to know a
        // check didn't actually complete.
        if ($row['schema_checked_at'] !== null && $row['has_news_article_schema'] === null) {
            return 'issue';
        }
        if ($row['psi_checked_at'] !== null && $row['psi_mobile_score'] === null) {
            return 'issue';
        }
        return 'clean';
    };

    $tsaUnchecked = 0;
    $tsaCleanCount = 0;
    foreach ($tsaAll as $tsaRow) {
        $state = $tsaClassify($tsaRow);
        if ($state === 'unchecked') {
            $tsaUnchecked++;
            $tsaRows[] = $tsaRow;
        } elseif ($state === 'issue') {
            $tsaRows[] = $tsaRow;
        } else {
            $tsaCleanCount++;
        }
    }
} catch (Throwable $e) {
    $tsaAll = [];
    $tsaCleanCount = 0;
    $tsaUnchecked = 0;
}

$tsaPsiKeyConfigured = cms_growth_agent_tsa_get_psi_api_key($pdo) !== '';

// Summary cards first (higher-level "how are things going" numbers), then
// the existing granular per-status breakdown — same .admin-grid--stats
// grid, just more cards in it.
$allStatsCards = array_merge($summaryCards, $statsCards);

// ── AI Health Indicator — reuses two signals already computed elsewhere
// on this exact page load rather than tracking a new "N failures in a
// row" counter (nothing in this codebase tracks consecutive failures
// anywhere today): cms_growth_agent_notifications() (the same count that
// already drives the navbar bell — failed/manual_action jobs) OR GSC's
// own last_fetch_status being 'failed'. Either signal alone is enough to
// flip the badge red. Deliberately action_needed_count, NOT the combined
// 'count' (9 Aug 2026) — a pile of newly auto-published articles is good
// news, it must never flip this "needs attention" badge red on its own.
$healthNotifCount = cms_growth_agent_notifications($pdo)['action_needed_count'];
$healthGscFailed = $gscConnected && ($gscSettings['last_fetch_status'] ?? '') === 'failed';
$healthNeedsAttention = $healthNotifCount > 0 || $healthGscFailed;


// ── Page-level tab badges (Fase B UI reorg) — reuse data already
// computed above, no new queries. Hidden entirely when 0 (see tab strip
// markup below).
$tabActionCount = count(array_filter($jobs, static fn (array $j): bool => in_array($j['status'], ['manual_action', 'ready'], true)));
$tabTechIssueCount = count($tsaRows) - $tsaUnchecked;
// The panel-lead spacing fix this class used to scope (.page-growth-agent
// .panel > .section-lead) was generalized to plain `.panel > .section-lead`
// in admin.css 24 Jul 2026 (same bug turned up on other pages) — this class
// is now unused by any CSS rule, kept only in case a future page-specific
// override is needed here again.
$bodyClass = 'page-growth-agent';

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/sidebar.php';
require dirname(__DIR__) . '/includes/navbar.php';
require dirname(__DIR__) . '/includes/breadcrumb.php';
require dirname(__DIR__) . '/includes/alerts.php';
?>
<section class="admin-stack">
    <div class="toolbar">
        <div class="toolbar__left">
            <h2 class="section-title">
                Growth Agent
                <?php if ($healthNeedsAttention) : ?>
                    <span class="pill pill--warn" style="vertical-align:middle;margin-left:8px;" title="<?= $healthGscFailed ? 'GSC fetch terakhir gagal' : $healthNotifCount . ' job butuh review (failed/manual action)' ?>">🔴 Perlu Perhatian</span>
                <?php else : ?>
                    <span class="pill pill--ok" style="vertical-align:middle;margin-left:8px;">🟢 Sehat</span>
                <?php endif; ?>
            </h2>
            <p class="section-lead">Pipeline SEO &amp; konten — bikin draft, jalanin proses, lalu kembalikan ke operator buat di-approve.</p>
        </div>
    </div>
    <div class="ga-scan-actions">
        <form method="post" action="<?= cms_esc($selfUrl) ?>" data-ga-page-tab="action">
            <?= cms_csrf_field() ?>
            <input type="hidden" name="action" value="scan_seo">
            <button type="submit" class="admin-btn admin-btn--primary">Scan untuk perbaikan SEO</button>
        </form>
        <form method="post" action="<?= cms_esc($selfUrl) ?>" data-ga-page-tab="action">
            <?= cms_csrf_field() ?>
            <input type="hidden" name="action" value="scan_internal_linking">
            <button type="submit" class="admin-btn admin-btn--secondary">Scan Internal Linking</button>
        </form>
        <form method="post" action="<?= cms_esc($selfUrl) ?>" data-ga-page-tab="health">
            <?= cms_csrf_field() ?>
            <input type="hidden" name="action" value="tsa_check_content">
            <button type="submit" class="admin-btn admin-btn--secondary">Cek Konten (Alt Text &amp; Schema)</button>
        </form>
    </div>
    <p class="section-lead">Scan mengecek artikel published yang belum pernah di-scan (maks. 5 per klik) dan mengusulkan meta title/description yang lebih baik untuk masing-masing — tidak ada yang berubah sampai Anda review dan apply.</p>
    <p class="section-lead section-lead--tight">Scan Internal Linking mencari pasangan artikel yang topiknya relevan tapi belum saling link (maks. 10 artikel sumber per klik, maks. 3 usulan per artikel) — murni pencocokan teks, tanpa AI. Tidak ada yang berubah sampai Anda review dan apply di halaman Review Link Internal.</p>
    <p class="section-lead section-lead--tight">Cek Konten memeriksa alt text gambar &amp; schema markup (murni laporan, TIDAK PERNAH mengubah artikel) — hasilnya di panel "Technical SEO Auditor" di bawah. Core Web Vitals (PageSpeed Insights) dicek terpisah karena jauh lebih lambat, lihat tombolnya sendiri di panel itu.</p>

    <div class="ga-page-tabs" role="tablist">
        <button type="button" class="admin-btn admin-btn--sm ga-page-tab-btn" data-ga-page-tab="action">Perlu Tindakan<?php if ($tabActionCount > 0) : ?> <span class="pill pill--warn"><?= $tabActionCount ?></span><?php endif; ?></button>
        <button type="button" class="admin-btn admin-btn--sm ga-page-tab-btn" data-ga-page-tab="health">Kesehatan Teknis<?php if ($tabTechIssueCount > 0) : ?> <span class="pill pill--warn"><?= $tabTechIssueCount ?></span><?php endif; ?></button>
        <button type="button" class="admin-btn admin-btn--sm ga-page-tab-btn" data-ga-page-tab="data">Data &amp; Performa</button>
        <button type="button" class="admin-btn admin-btn--sm ga-page-tab-btn" data-ga-page-tab="settings">Agent &amp; Setelan</button>
        <button type="button" class="admin-btn admin-btn--sm ga-page-tab-btn" data-ga-page-tab="automation">Otomatisasi</button>
    </div>

    <div class="ga-page-tab-panel" data-ga-page-tab="action">
    <div class="admin-grid admin-grid--stats">
        <?php foreach ($allStatsCards as $card) : ?>
            <article class="stat-card">
                <div class="stat-card__label"><?= cms_esc($card['label']) ?></div>
                <div class="stat-card__value"<?= !is_numeric($card['value']) ? ' style="font-size:16px;"' : '' ?>><?= cms_esc((string) $card['value']) ?></div>
                <div class="stat-card__hint"><?= cms_esc($card['hint']) ?></div>
            </article>
        <?php endforeach; ?>
    </div>

    <?php
    // ── Need Review / Ready to Run / Completed — same 3-way split as the
    // summary cards above, computed once here per-row so the tabs and
    // those cards can never disagree (cards use a full-table SQL COUNT,
    // this loop only sees the 25 most recent — see each tab's own "shown"
    // count in its header for that distinction, same "X shown" wording
    // the panel already used before tabs existed).
    $jobsByTab = ['need-review' => [], 'ready-to-run' => [], 'completed' => []];
    foreach ($jobs as $job) {
        $isSeoRecommendation = $job['job_type'] === 'seo_recommendation';
        $isIndexingIssue = $job['job_type'] === 'review_indexing_issue';
        $isCannibalization = $job['job_type'] === 'cannibalization_review';
        $isInternalLink = $job['job_type'] === 'internal_link_suggestion';
        $isAutoDraft = $job['job_type'] === 'auto_draft_article';
        // seo_recommendation jobs get their own review page (Apply writes
        // straight into pages.meta_title/meta_description), so they never
        // use the generic Approve/Reject buttons — Close as Legacy is
        // still available to both paths (see the 'gated by the same
        // conditions' note on the close_as_legacy action above).
        // review_indexing_issue jobs get the same treatment (own review
        // page, indexing-issue-review.php) for a different reason: there's
        // nothing to "approve"/"reject" in a diagnostic checklist — the
        // dedicated page frames the 2 real actions as "Tandai Sudah
        // Ditinjau" / "Tutup sebagai Legacy" instead. cannibalization_review
        // (ROADMAP.md gap #5) gets the same treatment for the same reason —
        // cannibalization-review.php. internal_link_suggestion (Fase B item
        // 1) gets the same treatment too — its own review page
        // (internal-link-review.php), because Apply here writes directly
        // into pages.content and the operator needs to see the anchor text
        // + surrounding sentence before deciding, which the generic
        // Approve/Reject buttons have no room to show. auto_draft_article
        // (Fase F, 8 Aug 2026) gets the same treatment for the same
        // reason — its own review page (auto-draft-review.php), because
        // Approve here creates a brand-new `pages` row from a fully
        // AI-written title+body+cover image, and the operator needs to
        // preview all of that before deciding.
        $canReviewGeneric = !$isSeoRecommendation && !$isIndexingIssue && !$isCannibalization && !$isInternalLink && !$isAutoDraft && (int) $job['feedback_count'] === 0 && in_array($job['status'], ['succeeded', 'failed', 'manual_action'], true);
        $canReviewSeo = $isSeoRecommendation && $job['status'] === 'manual_action';
        $canReviewIndexing = $isIndexingIssue && $job['status'] === 'manual_action';
        $canReviewCannibalization = $isCannibalization && $job['status'] === 'manual_action';
        $canReviewInternalLink = $isInternalLink && $job['status'] === 'manual_action';
        $canReviewAutoDraft = $isAutoDraft && $job['status'] === 'succeeded' && empty($job['page_id']);
        $job['_can_review_generic'] = $canReviewGeneric;
        $job['_can_review_seo'] = $canReviewSeo;
        $job['_can_review_indexing'] = $canReviewIndexing;
        $job['_can_review_cannibalization'] = $canReviewCannibalization;
        $job['_can_review_internal_link'] = $canReviewInternalLink;
        $job['_can_review_auto_draft'] = $canReviewAutoDraft;

        if ($canReviewGeneric || $canReviewSeo || $canReviewIndexing || $canReviewCannibalization || $canReviewInternalLink || $canReviewAutoDraft) {
            $jobsByTab['need-review'][] = $job;
        } elseif (in_array($job['status'], ['ready', 'running'], true)) {
            $jobsByTab['ready-to-run'][] = $job;
        } else {
            $jobsByTab['completed'][] = $job;
        }
    }

    /** Renders one Recent Jobs <tr> (+ its optional preview-toggle row is intentionally omitted here — this page never fetched output_json, unlike the review pages). Shared by all 3 tabs so the row markup only exists once. */
    $renderJobRow = static function (array $job) use ($statusPill, $priorityPill, $selfUrl): void {
        $pill = $statusPill[$job['status']] ?? 'muted';
        $canReviewGeneric = $job['_can_review_generic'];
        $canReviewSeo = $job['_can_review_seo'];
        $canReviewIndexing = $job['_can_review_indexing'];
        $canReviewCannibalization = $job['_can_review_cannibalization'];
        $canReviewInternalLink = $job['_can_review_internal_link'];
        $canReviewAutoDraft = $job['_can_review_auto_draft'];
        ?>
        <?php $g0Warnings = $job['_g0_warnings'] ?? []; ?>
        <?php $titleHeadlineFlag = $job['_title_headline_flag'] ?? null; ?>
        <tr>
            <td class="jobs-table__col-job">
                <strong><?= cms_esc((string) $job['job_type']) ?></strong><br>
                <span class="muted">agent: <code><?= cms_esc((string) $job['agent_key']) ?></code></span>
                <?php if ($g0Warnings !== []) : ?>
                    <div style="margin-top:6px;">
                        <span class="pill pill--warn" title="SEO-G0 Gate: usulan ini berpotensi tumpang tindih dengan sesuatu yang sudah ada — cek detail di bawah sebelum approve.">
                            ⚠ SEO-G0: <?= count($g0Warnings) ?> peringatan
                        </span>
                        <div class="muted" style="font-size:11px;margin-top:4px;">
                            <?php foreach ($g0Warnings as $g0Warning) : ?>
                                <div style="margin-top:2px;">• <?= cms_esc((string) ($g0Warning['message'] ?? '')) ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($titleHeadlineFlag !== null) : ?>
                    <div style="margin-top:6px;">
                        <span class="pill pill--warn" title="Judul usulan AI ini mirip banget sama salah satu headline sumber tren yang dikasih ke prompt — cek dulu sebelum approve, pastikan bukan sekadar reword tipis.">
                            ⚠ Mirip headline sumber: <?= count($titleHeadlineFlag) ?>
                        </span>
                        <div class="muted" style="font-size:11px;margin-top:4px;">
                            <?php foreach ($titleHeadlineFlag as $flagMatch) : ?>
                                <div style="margin-top:2px;">• "<?= cms_esc((string) ($flagMatch['headline'] ?? '')) ?>" (overlap <?= cms_esc((string) ($flagMatch['coefficient'] ?? '')) ?>)</div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </td>
            <td class="jobs-table__col-article">
                <?php if ($job['page_title']) : ?>
                    <span class="jobs-table__truncate" title="<?= cms_esc((string) $job['page_title']) ?>"><?= cms_esc((string) $job['page_title']) ?></span>
                <?php else : ?>
                    <span class="muted">—</span>
                <?php endif; ?>
            </td>
            <td class="jobs-table__col-status">
                <span class="pill pill--<?= $pill ?>"><?= cms_esc((string) $job['status']) ?></span>
                <?php $jobPriority = (string) ($job['priority'] ?? 'medium'); ?>
                <?php if ($jobPriority === 'high') : ?>
                    <span class="pill pill--<?= $priorityPill['high'] ?>" title="Prioritas tinggi">HIGH</span>
                <?php elseif ($jobPriority === 'low') : ?>
                    <span class="pill pill--<?= $priorityPill['low'] ?>" title="Prioritas rendah">LOW</span>
                <?php endif; ?>
                <?php if ($job['status'] === 'failed' && $job['error_message']) : ?>
                    <div class="muted" style="font-size:11px;margin-top:4px;"><?= cms_esc(mb_substr((string) $job['error_message'], 0, 140)) ?></div>
                <?php endif; ?>
            </td>
            <td class="jobs-table__col-model">
                <?php if ($job['model_used'] || $job['latency_ms'] !== null) : ?>
                    <?= $job['model_used'] ? cms_esc((string) $job['model_used']) : '<span class="muted">—</span>' ?>
                    <?php if ($job['latency_ms'] !== null) : ?>
                        <span class="muted" style="font-size:11px;"><?= $job['model_used'] ? ' · ' : '' ?><?= cms_esc((string) $job['latency_ms']) ?> ms</span>
                    <?php endif; ?>
                <?php else : ?>
                    <span class="muted">—</span>
                <?php endif; ?>
            </td>
            <td class="jobs-table__col-time muted"><?= cms_esc((string) $job['created_at']) ?></td>
            <td class="jobs-table__col-actions table-actions">
                <?php if ($job['job_type'] === 'auto_draft_article' && !empty($job['page_id']) && $job['page_status'] === 'published') : ?>
                    <?php // Fase G auto-publish result — nothing left to approve/reject/edit-as-draft,
                          // the article is already live. Link straight to the public page instead. ?>
                    <a class="admin-btn admin-btn--sm admin-btn--secondary" href="<?= cms_esc((function_exists('cms_public_base_prefix') ? cms_public_base_prefix() : '../') . 'artikel.php?slug=' . urlencode((string) $job['page_slug'])) ?>" target="_blank" rel="noopener">Lihat artikel</a>
                    <a class="admin-btn admin-btn--sm admin-btn--ghost" href="pages.php?edit=<?= (int) $job['page_id'] ?>">Edit</a>
                <?php elseif (in_array($job['job_type'], ['gsc_article_idea', 'auto_draft_article'], true) && !empty($job['page_id'])) : ?>
                    <a class="admin-btn admin-btn--sm admin-btn--secondary" href="pages.php?edit=<?= (int) $job['page_id'] ?>">Edit draft</a>
                <?php elseif ($canReviewSeo) : ?>
                    <a class="admin-btn admin-btn--sm admin-btn--primary" href="seo-recommendation-review.php?job_id=<?= (int) $job['id'] ?>">Review</a>
                <?php elseif ($canReviewIndexing) : ?>
                    <a class="admin-btn admin-btn--sm admin-btn--primary" href="indexing-issue-review.php?job_id=<?= (int) $job['id'] ?>">Review</a>
                <?php elseif ($canReviewCannibalization) : ?>
                    <a class="admin-btn admin-btn--sm admin-btn--primary" href="cannibalization-review.php?job_id=<?= (int) $job['id'] ?>">Review</a>
                <?php elseif ($canReviewInternalLink) : ?>
                    <a class="admin-btn admin-btn--sm admin-btn--primary" href="internal-link-review.php?job_id=<?= (int) $job['id'] ?>">Review</a>
                <?php elseif ($canReviewAutoDraft) : ?>
                    <a class="admin-btn admin-btn--sm admin-btn--primary" href="auto-draft-review.php?job_id=<?= (int) $job['id'] ?>">Review</a>
                <?php elseif ($canReviewGeneric) : ?>
                    <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>">
                        <?= cms_csrf_field() ?>
                        <input type="hidden" name="action" value="approve">
                        <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                        <button type="submit" class="admin-btn admin-btn--sm admin-btn--secondary">Approve</button>
                    </form>
                    <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>">
                        <?= cms_csrf_field() ?>
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                        <button type="submit" class="admin-btn admin-btn--sm admin-btn--ghost">Reject</button>
                    </form>
                <?php else : ?>
                    <span class="muted">—</span>
                <?php endif; ?>
                <?php if ($canReviewGeneric || $canReviewSeo || $canReviewIndexing || $canReviewCannibalization || $canReviewInternalLink || $canReviewAutoDraft) : ?>
                    <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>" onsubmit="return confirm('Tandai job ini sebagai legacy? Ini BUKAN reject — cuma menandai sudah tidak relevan lagi (mis. data GSC-nya sudah basi), tidak dihitung sebagai penolakan aktif.');">
                        <?= cms_csrf_field() ?>
                        <input type="hidden" name="action" value="close_as_legacy">
                        <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                        <button type="submit" class="admin-btn admin-btn--sm admin-btn--ghost" title="Sudah tidak relevan lagi — beda dari Reject (yang berarti 'ditolak karena tidak bagus')">Tutup sebagai Legacy</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    };

    $tabDefs = [
        ['key' => 'need-review', 'label' => 'Perlu Direview', 'pillTone' => 'warn'],
        ['key' => 'ready-to-run', 'label' => 'Siap Dijalankan', 'pillTone' => 'muted'],
        ['key' => 'completed', 'label' => 'Selesai', 'pillTone' => 'ok'],
    ];
    ?>
    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Job Terbaru</h3>
            <span class="panel__meta"><?= count($jobs) ?> ditampilkan</span>
        </div>
        <div class="js-ga-tabs" style="display:flex;gap:8px;padding:0 20px 16px;flex-wrap:wrap;">
            <?php foreach ($tabDefs as $i => $tab) : ?>
                <button type="button"
                        class="admin-btn admin-btn--sm js-ga-tab-btn <?= $i === 0 ? 'admin-btn--secondary is-active' : 'admin-btn--ghost' ?>"
                        data-tab-target="<?= $tab['key'] ?>">
                    <?= $tab['label'] ?> <span class="pill pill--<?= $tab['pillTone'] ?>"><?= count($jobsByTab[$tab['key']]) ?></span>
                </button>
            <?php endforeach; ?>
        </div>
        <?php foreach ($tabDefs as $i => $tab) : ?>
            <div class="table-wrap js-ga-tab-panel" data-tab-panel="<?= $tab['key'] ?>"<?= $i === 0 ? '' : ' hidden' ?>>
                <table class="admin-table jobs-table">
                    <thead>
                        <tr>
                            <th class="jobs-table__col-job">Job</th>
                            <th class="jobs-table__col-article">Artikel</th>
                            <th class="jobs-table__col-status">Status</th>
                            <th class="jobs-table__col-model">Model / Latensi</th>
                            <th class="jobs-table__col-time">Waktu</th>
                            <th class="jobs-table__col-actions"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($jobsByTab[$tab['key']] === []) : ?>
                            <tr><td colspan="6" class="muted">
                                <?= $tab['key'] === 'need-review'
                                    ? 'Tidak ada job yang perlu direview saat ini.'
                                    : ($tab['key'] === 'ready-to-run'
                                        ? 'Tidak ada job yang sedang antre/berjalan.'
                                        : 'Belum ada job yang selesai di-review.') ?>
                            </td></tr>
                        <?php endif; ?>
                        <?php foreach ($jobsByTab[$tab['key']] as $job) : $renderJobRow($job); ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    </div>
    <script>
    // ---- Recent Jobs tabs (Growth Agent) ----
    (function () {
        document.querySelectorAll('.js-ga-tabs').forEach(function (tabs) {
            tabs.addEventListener('click', function (e) {
                var btn = e.target.closest('.js-ga-tab-btn');
                if (!btn) { return; }
                var target = btn.getAttribute('data-tab-target');
                var panel = tabs.closest('.panel');
                tabs.querySelectorAll('.js-ga-tab-btn').forEach(function (b) {
                    b.classList.toggle('is-active', b === btn);
                    b.classList.toggle('admin-btn--secondary', b === btn);
                    b.classList.toggle('admin-btn--ghost', b !== btn);
                });
                panel.querySelectorAll('.js-ga-tab-panel').forEach(function (p) {
                    p.hidden = p.getAttribute('data-tab-panel') !== target;
                });
            });
        });
    })();
    </script>

    <?php if ($gscConnected) : ?>
    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Peluang Terprioritas</h3>
            <span class="panel__meta"><?= count($opportunities) ?> terbuka</span>
        </div>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Prioritas</th>
                        <th>Item</th>
                        <th>Kategori Cocok</th>
                        <th>Dampak</th>
                        <th>Upaya</th>
                        <th>Agent Rekomendasi</th>
                        <th>Alasan</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($opportunities === []) : ?>
                        <tr><td colspan="8" class="muted">Belum ada opportunity — akan muncul otomatis setelah data GSC di-fetch (atau klik "Hitung Ulang Opportunities" di atas).</td></tr>
                    <?php endif; ?>
                    <?php foreach ($opportunities as $opp) : ?>
                        <tr>
                            <td><span class="pill pill--<?= $priorityPill[$opp['priority']] ?? 'muted' ?>"><?= strtoupper(cms_esc((string) $opp['priority'])) ?></span></td>
                            <td>
                                <?php if ($opp['item_type'] === 'page') : ?>
                                    <?= $opp['page_title'] ? cms_esc((string) $opp['page_title']) : '<span class="muted">Artikel #' . (int) $opp['matched_page_id'] . '</span>' ?>
                                    <span class="muted" style="font-size:11px;">(artikel)</span>
                                <?php else : ?>
                                    <?= cms_esc((string) $opp['query_text']) ?>
                                    <span class="muted" style="font-size:11px;">(query)</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php foreach (array_filter(array_map('trim', explode(',', (string) $opp['matched_categories']))) as $cat) : ?>
                                    <span class="pill pill--muted" style="margin:0 3px 3px 0;"><?= cms_esc($cat) ?></span>
                                <?php endforeach; ?>
                            </td>
                            <td><?= (int) $opp['impact_score'] ?>/10</td>
                            <td><?= (int) $opp['effort_score'] ?>/10</td>
                            <td><code><?= cms_esc((string) $opp['recommended_agent']) ?></code></td>
                            <td class="muted" style="font-size:12px;max-width:320px;"><?= cms_esc((string) $opp['reason']) ?></td>
                            <td class="table-actions">
                                <form method="post" action="<?= cms_esc($selfUrl) ?>">
                                    <?= cms_csrf_field() ?>
                                    <input type="hidden" name="action" value="generate_from_opportunity">
                                    <input type="hidden" name="opportunity_id" value="<?= (int) $opp['id'] ?>">
                                    <?php if ($opp['recommended_action'] === 'cannibalization_review') : ?>
                                        <button type="submit" class="admin-btn admin-btn--sm admin-btn--secondary" title="Tidak ada AI di sini — cuma menampilkan data query + halaman yang bentrok buat ditinjau manual">Review</button>
                                    <?php else : ?>
                                        <button type="submit" class="admin-btn admin-btn--sm admin-btn--primary">Generate</button>
                                    <?php endif; ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    </div>

    <div class="ga-page-tab-panel" data-ga-page-tab="health">
    <?php if ($gscConnected) : ?>
    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Status Index</h3>
            <span class="panel__meta"><?= count($indexInspections) ?> artikel published ditampilkan</span>
        </div>
        <div class="toolbar" style="padding:0 20px 16px;">
            <div class="toolbar__left">
                <p class="muted" style="margin:0;font-size:13px;">
                    Baca status index via Search Console URL Inspection API — read-only, tidak pernah menulis/mengubah artikel.
                    Kalau verdict bermasalah, job "review_indexing_issue" otomatis dibuat di Job Terbaru (checklist deterministik, bukan AI).
                </p>
            </div>
            <div class="toolbar__right">
                <form method="post" action="<?= cms_esc($selfUrl) ?>" class="inline-form" style="display:flex;gap:8px;align-items:center;">
                    <?= cms_csrf_field() ?>
                    <input type="hidden" name="action" value="inspect_priority_urls">
                    <input type="number" name="limit" value="10" min="1" max="50" style="width:70px;" title="Jumlah URL maksimum per batch">
                    <button type="submit" class="admin-btn admin-btn--primary">Inspect prioritas</button>
                </form>
            </div>
        </div>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Artikel</th>
                        <th>Verdict</th>
                        <th>Crawl Terakhir</th>
                        <th>Terakhir Diinspeksi</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($indexInspections === []) : ?>
                        <tr><td colspan="5" class="muted">Belum ada artikel published untuk diinspeksi.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($indexInspections as $insp) : ?>
                        <tr>
                            <td><?= cms_esc((string) $insp['title']) ?></td>
                            <td>
                                <?php if ($insp['verdict']) : ?>
                                    <span class="pill pill--<?= $indexVerdictPill[$insp['verdict']] ?? 'muted' ?>"><?= cms_esc((string) $insp['verdict']) ?></span>
                                <?php elseif ($insp['error_message']) : ?>
                                    <span class="pill pill--warn" title="<?= cms_esc((string) $insp['error_message']) ?>">Gagal</span>
                                <?php else : ?>
                                    <span class="muted">Belum diinspeksi</span>
                                <?php endif; ?>
                            </td>
                            <td class="muted"><?= $insp['last_crawl_time'] ? cms_esc((string) $insp['last_crawl_time']) : '—' ?></td>
                            <td class="muted"><?= $insp['inspected_at'] ? cms_esc((string) $insp['inspected_at']) : '—' ?></td>
                            <td class="table-actions">
                                <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>">
                                    <?= cms_csrf_field() ?>
                                    <input type="hidden" name="action" value="inspect_single_url">
                                    <input type="hidden" name="page_id" value="<?= (int) $insp['page_id'] ?>">
                                    <button type="submit" class="admin-btn admin-btn--sm admin-btn--secondary">Inspect URL</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Technical SEO Auditor</h3>
            <span class="panel__meta"><?= count($tsaRows) - $tsaUnchecked ?> bermasalah &middot; <?= $tsaCleanCount ?> bersih &middot; <?= $tsaUnchecked ?> belum diperiksa</span>
        </div>
        <p class="muted" style="margin:0;padding:0 20px 16px;font-size:13px;">
            Laporan read-only: alt text gambar, schema markup (NewsArticle/BreadcrumbList), dan Core Web Vitals
            (PageSpeed Insights). Tidak ada approve/execute di sini — agent ini TIDAK PERNAH mengubah artikel,
            perbaiki sendiri lewat editor artikel. Hanya artikel yang BERMASALAH atau BELUM PERNAH diperiksa yang
            ditampilkan di tabel — yang sudah diperiksa dan bersih disembunyikan supaya tidak menenggelamkan yang
            perlu perhatian ("belum diperiksa" TIDAK dihitung sebagai bersih).
        </p>
        <div class="toolbar" style="padding:0 20px 16px;">
            <div class="toolbar__left">
                <p class="muted" style="margin:0;font-size:13px;">
                    PageSpeed Insights API Key: <span class="pill pill--<?= $tsaPsiKeyConfigured ? 'ok' : 'muted' ?>"><?= $tsaPsiKeyConfigured ? 'Terpasang' : 'Tidak terpasang (pakai limit publik per-IP)' ?></span>
                </p>
            </div>
            <div class="toolbar__right" style="gap:8px;">
                <form method="post" action="<?= cms_esc($selfUrl) ?>" style="display:flex;gap:6px;align-items:center;">
                    <?= cms_csrf_field() ?>
                    <input type="hidden" name="action" value="tsa_save_psi_key">
                    <input type="password" name="psi_api_key" placeholder="PSI API key (opsional)" autocomplete="off" style="width:220px;">
                    <button type="submit" class="admin-btn admin-btn--sm admin-btn--ghost">Simpan</button>
                </form>
                <?php if ($tsaPsiKeyConfigured) : ?>
                <form method="post" action="<?= cms_esc($selfUrl) ?>" onsubmit="return confirm('Hapus API key PSI? PSI akan dipanggil tanpa key setelah ini.');">
                    <?= cms_csrf_field() ?>
                    <input type="hidden" name="action" value="tsa_clear_psi_key">
                    <button type="submit" class="admin-btn admin-btn--sm admin-btn--ghost">Hapus API Key</button>
                </form>
                <?php endif; ?>
                <form method="post" action="<?= cms_esc($selfUrl) ?>">
                    <?= cms_csrf_field() ?>
                    <input type="hidden" name="action" value="tsa_check_psi">
                    <button type="submit" class="admin-btn admin-btn--secondary" title="Lambat — bisa sampai 30 detik per artikel, dibatasi beberapa artikel per klik.">Cek Core Web Vitals (maks. beberapa artikel/klik)</button>
                </form>
            </div>
        </div>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Artikel</th>
                        <th>Alt Text</th>
                        <th>Schema Markup</th>
                        <th>Core Web Vitals</th>
                        <th>Terakhir Diperiksa</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($tsaRows === []) : ?>
                        <tr><td colspan="5" class="muted">Semua artikel yang sudah diperiksa dalam kondisi bersih, dan tidak ada artikel yang belum diperiksa.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($tsaRows as $tsaRow) : ?>
                        <tr>
                            <td><a href="pages.php?edit=<?= (int) $tsaRow['page_id'] ?>"><?= cms_esc((string) $tsaRow['title']) ?></a></td>
                            <td>
                                <?php if ($tsaRow['content_checked_at'] === null) : ?>
                                    <span class="pill pill--muted">Belum diperiksa</span>
                                <?php elseif ((int) $tsaRow['missing_alt_count'] > 0) : ?>
                                    <span class="pill pill--warn"><?= (int) $tsaRow['missing_alt_count'] ?> dari <?= (int) $tsaRow['total_image_count'] ?> gambar tanpa alt</span>
                                <?php else : ?>
                                    <span class="pill pill--ok">Lengkap (<?= (int) $tsaRow['total_image_count'] ?> gambar)</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($tsaRow['schema_checked_at'] === null) : ?>
                                    <span class="pill pill--muted">Belum diperiksa</span>
                                <?php elseif ($tsaRow['has_news_article_schema'] === null) : ?>
                                    <span class="pill pill--muted" title="<?= cms_esc((string) $tsaRow['schema_check_error']) ?>">Gagal diperiksa</span>
                                <?php elseif (!(int) $tsaRow['has_news_article_schema'] || !(int) $tsaRow['has_breadcrumb_schema']) : ?>
                                    <span class="pill pill--warn">
                                        <?= !(int) $tsaRow['has_news_article_schema'] ? 'NewsArticle hilang' : '' ?>
                                        <?= (!(int) $tsaRow['has_news_article_schema'] && !(int) $tsaRow['has_breadcrumb_schema']) ? ' &amp; ' : '' ?>
                                        <?= !(int) $tsaRow['has_breadcrumb_schema'] ? 'BreadcrumbList hilang' : '' ?>
                                    </span>
                                <?php else : ?>
                                    <span class="pill pill--ok">Lengkap</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($tsaRow['psi_checked_at'] === null) : ?>
                                    <span class="pill pill--muted">Belum diperiksa</span>
                                <?php elseif ($tsaRow['psi_mobile_score'] === null) : ?>
                                    <span class="pill pill--muted" title="<?= cms_esc((string) $tsaRow['psi_error']) ?>">Gagal diperiksa</span>
                                <?php else : ?>
                                    <span class="pill pill--<?= (int) $tsaRow['psi_mobile_score'] <= $tsaPoorScore ? 'warn' : 'ok' ?>">Skor Mobile: <?= (int) $tsaRow['psi_mobile_score'] ?></span>
                                    <?php if ($tsaRow['psi_lcp_ms'] !== null) : ?>
                                        <span class="muted" style="font-size:11px;display:block;">LCP <?= round((int) $tsaRow['psi_lcp_ms'] / 1000, 1) ?>s &middot; CLS <?= $tsaRow['psi_cls'] !== null ? rtrim(rtrim(number_format((float) $tsaRow['psi_cls'], 3), '0'), '.') : '—' ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="muted" style="font-size:12px;">
                                <?php
                                $lastChecks = array_filter([$tsaRow['content_checked_at'], $tsaRow['schema_checked_at'], $tsaRow['psi_checked_at']]);
                                echo $lastChecks !== [] ? cms_esc((string) max($lastChecks)) : '—';
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    </div>

    <div class="ga-page-tab-panel" data-ga-page-tab="data">
    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Google Search Console</h3>
            <span class="pill pill--<?= $gscConnected ? 'ok' : 'muted' ?>"><?= $gscConnected ? 'Terhubung' : 'Tidak terhubung' ?></span>
        </div>
        <div class="toolbar" style="padding:16px 20px 20px;">
            <div class="toolbar__left">
                <?php if ($gscConnected) : ?>
                    <p class="muted" style="margin:0;font-size:13px;">
                        Properti: <code><?= cms_esc((string) $gscSettings['site_url']) ?></code><br>
                        Fetch terakhir:
                        <?php if (!empty($gscSettings['last_fetch_at'])) : ?>
                            <span class="pill pill--<?= $gscSettings['last_fetch_status'] === 'success' ? 'ok' : 'warn' ?>" style="margin-left:4px;"><?= cms_esc((string) $gscSettings['last_fetch_status']) ?></span>
                            <?= (int) $gscSettings['last_fetch_rows'] ?> baris — <?= cms_esc((string) $gscSettings['last_fetch_at']) ?>
                        <?php else : ?>
                            <span class="muted">belum pernah — akan otomatis jalan begitu halaman ini dibuka (atau klik Fetch GSC Data).</span>
                        <?php endif; ?>
                    </p>
                <?php else : ?>
                    <p class="muted" style="margin:0;font-size:13px;">
                        Belum tersambung ke Google Search Console — rekomendasi berbasis data GSC (tabel "Peluang Terprioritas" di bawah) belum bisa jalan.
                        <a class="panel__link" href="<?= cms_esc(cms_nav_href('gsc-settings.php')) ?>">Hubungkan sekarang &rarr;</a>
                    </p>
                <?php endif; ?>
            </div>
            <div class="toolbar__right" style="gap:8px;">
                <?php if ($gscConnected) : ?>
                    <form method="post" action="<?= cms_esc(cms_action_href('gsc-refresh.php')) ?>">
                        <?= cms_csrf_field() ?>
                        <button type="submit" class="admin-btn admin-btn--secondary">🔄 Fetch GSC Data</button>
                    </form>
                    <form method="post" action="<?= cms_esc($selfUrl) ?>">
                        <?= cms_csrf_field() ?>
                        <input type="hidden" name="action" value="recompute_opportunities">
                        <button type="submit" class="admin-btn admin-btn--ghost">Hitung Ulang Opportunities</button>
                    </form>
                <?php endif; ?>
                <a class="admin-btn admin-btn--ghost" href="<?= cms_esc(cms_nav_href('gsc-settings.php')) ?>">GSC Settings</a>
            </div>
        </div>

        <?php if ($gscAggregate !== null) : ?>
            <div class="table-wrap" style="padding:0 20px 4px;">
                <p class="muted" style="font-size:12px;margin:0 0 12px;">
                    Rentang data: <?= cms_esc($gscAggregate['min_date']) ?> &ndash; <?= cms_esc($gscAggregate['max_date']) ?>
                    (<?= (int) ($gscSettings['fetch_lookback_days'] ?? 14) ?> hari lookback) — Search Console punya delay
                    &sim;3 hari, jadi beberapa hari paling baru belum tentu lengkap.
                </p>
            </div>
            <div class="admin-grid admin-grid--stats" style="padding:0 20px 20px;">
                <article class="stat-card">
                    <div class="stat-card__label">Klik</div>
                    <div class="stat-card__value"><?= number_format($gscAggregate['clicks']) ?></div>
                </article>
                <article class="stat-card">
                    <div class="stat-card__label">Impresi</div>
                    <div class="stat-card__value"><?= number_format($gscAggregate['impressions']) ?></div>
                </article>
                <article class="stat-card">
                    <div class="stat-card__label">CTR</div>
                    <div class="stat-card__value"><?= cms_esc((string) $gscAggregate['ctr']) ?>%</div>
                </article>
                <article class="stat-card">
                    <div class="stat-card__label">Rata-rata Posisi</div>
                    <div class="stat-card__value"><?= cms_esc((string) $gscAggregate['avg_position']) ?></div>
                </article>
            </div>

            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr><th>Query</th><th>Klik</th><th>Impresi</th><th>CTR</th><th>Posisi</th></tr>
                    </thead>
                    <tbody>
                        <?php if ($gscTopQueries === []) : ?>
                            <tr><td colspan="5" class="muted">Belum ada data.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($gscTopQueries as $q) : ?>
                            <?php $qImpressions = (int) $q['impressions']; $qCtr = $qImpressions > 0 ? round(((int) $q['clicks'] / $qImpressions) * 100, 2) : 0.0; ?>
                            <tr>
                                <td><?= cms_esc((string) $q['query']) ?></td>
                                <td><?= number_format((int) $q['clicks']) ?></td>
                                <td><?= number_format($qImpressions) ?></td>
                                <td><?= cms_esc((string) $qCtr) ?>%</td>
                                <td><?= cms_esc((string) round((float) $q['position'], 1)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <h4 style="margin:20px 0 8px;padding:0 20px;">Halaman Teratas</h4>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr><th>Artikel</th><th>Klik</th><th>Impresi</th><th>CTR</th><th>Posisi</th></tr>
                    </thead>
                    <tbody>
                        <?php if ($gscTopPages === []) : ?>
                            <tr><td colspan="5" class="muted">Belum ada data.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($gscTopPages as $pg) : ?>
                            <?php $pImpressions = (int) $pg['impressions']; $pCtr = $pImpressions > 0 ? round(((int) $pg['clicks'] / $pImpressions) * 100, 2) : 0.0; ?>
                            <tr>
                                <td><?= $pg['page_title'] ? cms_esc((string) $pg['page_title']) : cms_esc((string) $pg['page_url']) ?></td>
                                <td><?= number_format((int) $pg['clicks']) ?></td>
                                <td><?= number_format($pImpressions) ?></td>
                                <td><?= cms_esc((string) $pCtr) ?>%</td>
                                <td><?= cms_esc((string) round((float) $pg['position'], 1)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Artikel Terpopuler</h3>
            <span class="panel__meta">Total views, top 10</span>
        </div>
        <p class="muted" style="margin:0;padding:0 20px 16px;font-size:13px;">
            Total views sepanjang waktu (bukan data 28 hari terakhir) — jangan disalahartikan sebagai angka real-time terbaru.
        </p>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr><th>Artikel</th><th>Views</th></tr>
                </thead>
                <tbody>
                    <?php if ($topViewedArticles === []) : ?>
                        <tr><td colspan="2" class="muted">Belum ada data.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($topViewedArticles as $art) : ?>
                        <tr>
                            <td><?= cms_esc((string) $art['title']) ?></td>
                            <td><?= number_format((int) $art['views']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($gscConnected) : ?>
    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Daftar Artikel Berpotensi Tinggi</h3>
            <span class="panel__meta"><?= count($highPotentialArticles) ?> artikel</span>
        </div>
        <p class="muted" style="margin:0;padding:0 20px 16px;font-size:13px;">
            Artikel published diranking berdasarkan traffic/impression GSC 28 hari terakhir — target konkret buat
            dipromosikan/di-push manual (share ulang, internal link, dsb). Murni laporan read-only, tidak ada
            approve/execute di sini. <em>Dulu direncanakan sebagai "Backlink Monitor" — dibatalkan 6 Agu 2026 setelah
            ditemukan Search Console API tidak punya endpoint data backlink sama sekali, gratis maupun berbayar.</em>
        </p>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr><th>Artikel</th><th>Impressions</th><th>Klik</th><th>CTR</th><th>Posisi Rata-rata</th></tr>
                </thead>
                <tbody>
                    <?php if ($highPotentialArticles === []) : ?>
                        <tr><td colspan="5" class="muted">Belum ada artikel dengan impression yang cukup di window ini.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($highPotentialArticles as $hpa) : ?>
                        <tr>
                            <td><a href="pages.php?edit=<?= (int) $hpa['page_id'] ?>"><?= cms_esc((string) $hpa['title']) ?></a></td>
                            <td><?= number_format((int) $hpa['impressions']) ?></td>
                            <td><?= number_format((int) $hpa['clicks']) ?></td>
                            <td><?= number_format(((float) $hpa['ctr']) * 100, 2) ?>%</td>
                            <td><?= $hpa['avg_position'] !== null ? number_format((float) $hpa['avg_position'], 1) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($gscConnected) : ?>
    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Feedback / Sebelum-Sesudah</h3>
            <span class="panel__meta"><?= count($feedbackReport) ?> artikel</span>
        </div>
        <p class="muted" style="margin:0;padding:0 20px 16px;font-size:13px;">
            Laporan read-only: artikel yang pernah kena action Growth Agent (SEO Recommendation yang sudah di-Apply,
            atau Article Idea yang draft-nya sudah dipublish), dibandingkan performa GSC 28 hari sebelum vs sesudah
            perubahan. Tidak ada approve/execute di sini — cuma laporan. Data yang belum cukup (minimal 7 hari di tiap
            sisi) ditandai "Data belum cukup", bukan dipaksakan jadi kesimpulan.
        </p>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Artikel</th>
                        <th>Aksi</th>
                        <th>Tanggal Perubahan</th>
                        <th>Klik</th>
                        <th>Impresi</th>
                        <th>CTR</th>
                        <th>Posisi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($feedbackReport === []) : ?>
                        <tr><td colspan="7" class="muted">Belum ada artikel yang memenuhi syarat (SEO Recommendation ter-Apply, atau Article Idea yang sudah dipublish).</td></tr>
                    <?php endif; ?>
                    <?php foreach ($feedbackReport as $fb) : ?>
                        <?php $cmp = $fb['comparison']; ?>
                        <tr>
                            <td><?= cms_esc((string) $fb['page_title']) ?></td>
                            <td><?= cms_esc($feedbackActionLabel[$fb['action_type']] ?? (string) $fb['action_type']) ?></td>
                            <td class="muted"><?= cms_esc((string) $fb['change_date']) ?></td>
                            <?php if (($cmp['status'] ?? '') === 'ok') : ?>
                                <td>
                                    <?= (int) $cmp['before']['clicks'] ?> &rarr; <?= (int) $cmp['after']['clicks'] ?>
                                    <span class="muted" style="font-size:11px;">(<?= $cmp['delta']['clicks'] >= 0 ? '+' : '' ?><?= (int) $cmp['delta']['clicks'] ?>)</span>
                                </td>
                                <td>
                                    <?= (int) $cmp['before']['impressions'] ?> &rarr; <?= (int) $cmp['after']['impressions'] ?>
                                    <span class="muted" style="font-size:11px;">(<?= $cmp['delta']['impressions'] >= 0 ? '+' : '' ?><?= (int) $cmp['delta']['impressions'] ?>)</span>
                                </td>
                                <td>
                                    <?= round($cmp['before']['ctr'] * 100, 2) ?>% &rarr; <?= round($cmp['after']['ctr'] * 100, 2) ?>%
                                    <span class="muted" style="font-size:11px;">(<?= $cmp['delta']['ctr'] >= 0 ? '+' : '' ?><?= round($cmp['delta']['ctr'] * 100, 2) ?>%)</span>
                                </td>
                                <td>
                                    <?php if ($cmp['before']['avg_position'] !== null && $cmp['after']['avg_position'] !== null) : ?>
                                        <?= $cmp['before']['avg_position'] ?> &rarr; <?= $cmp['after']['avg_position'] ?>
                                        <span class="muted" style="font-size:11px;">(<?= $cmp['delta']['avg_position'] >= 0 ? '+' : '' ?><?= $cmp['delta']['avg_position'] ?>)</span>
                                    <?php else : ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                            <?php else : ?>
                                <td colspan="4"><span class="pill pill--muted" title="Minimal 7 hari data di kedua sisi (sebelum/sesudah) diperlukan untuk perbandingan yang valid.">Data belum cukup</span></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    </div>

    <div class="ga-page-tab-panel" data-ga-page-tab="settings">
    <?php if ($gscConnected) : ?>
    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Memori Agent</h3>
            <span class="panel__meta"><?= count($memoryPatterns) ?> pattern</span>
        </div>
        <p class="muted" style="margin:0;padding:0 20px 16px;font-size:13px;">
            Pola historis dari data GSC (deteksi deterministik, bukan AI) — cuma jadi konteks tambahan buat prompt Growth Agent,
            bukan action queue. Tidak ada approve/execute di sini; satu-satunya aksi manual adalah menonaktifkan pattern yang sudah tidak relevan.
        </p>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Tipe</th>
                        <th>Target</th>
                        <th>Status</th>
                        <th>Bukti</th>
                        <th>Minggu Terdeteksi</th>
                        <th>Terakhir Dikonfirmasi</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($memoryPatterns === []) : ?>
                        <tr><td colspan="7" class="muted">Belum ada pattern terdeteksi — akan muncul otomatis setelah cukup data historis GSC terkumpul (minimal <?= (int) cms_gsc_get_memory_thresholds($pdo)['min_distinct_weeks'] ?> minggu berbeda).</td></tr>
                    <?php endif; ?>
                    <?php foreach ($memoryPatterns as $mem) : ?>
                        <?php $memEvidence = json_decode((string) ($mem['evidence_json'] ?? ''), true); $memEvidence = is_array($memEvidence) ? $memEvidence : []; ?>
                        <tr>
                            <td><?= cms_esc($memoryPatternLabel[$mem['pattern_type']] ?? (string) $mem['pattern_type']) ?></td>
                            <td>
                                <?php if ($mem['scope_type'] === 'page') : ?>
                                    <?= $mem['page_title'] ? cms_esc((string) $mem['page_title']) : '<span class="muted">Artikel #' . (int) $mem['matched_page_id'] . '</span>' ?>
                                    <span class="muted" style="font-size:11px;">(page)</span>
                                <?php else : ?>
                                    <?= cms_esc((string) $mem['query_text']) ?>
                                    <span class="muted" style="font-size:11px;">(query)</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="pill pill--<?= $memoryStatusPill[$mem['status']] ?? 'muted' ?>"><?= cms_esc((string) $mem['status']) ?></span></td>
                            <td class="muted" style="font-size:12px;">
                                <?php if (isset($memEvidence['avg_ctr'])) : ?>
                                    CTR <?= round(((float) $memEvidence['avg_ctr']) * 100, 2) ?>%, posisi <?= round((float) ($memEvidence['avg_position'] ?? 0), 1) ?>,
                                <?php endif; ?>
                                <?= (int) ($memEvidence['total_impressions'] ?? 0) ?> impressions
                            </td>
                            <td><?= (int) $mem['distinct_weeks_seen'] ?></td>
                            <td class="muted"><?= cms_esc((string) $mem['last_confirmed_at']) ?></td>
                            <td class="table-actions">
                                <?php if ($mem['status'] !== 'stale') : ?>
                                    <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>" onsubmit="return confirm('Tandai pattern ini sebagai stale? Ini cuma menonaktifkan dari context prompt, tidak menghapus histori.');">
                                        <?= cms_csrf_field() ?>
                                        <input type="hidden" name="action" value="mark_memory_stale">
                                        <input type="hidden" name="memory_id" value="<?= (int) $mem['id'] ?>">
                                        <button type="submit" class="admin-btn admin-btn--sm admin-btn--ghost">Tandai stale</button>
                                    </form>
                                <?php else : ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Aturan Gaya</h3>
            <span class="panel__meta"><?= count($styleRules) ?> item</span>
        </div>
        <p class="section-lead">Rule yang aktif otomatis dimasukkan ke setiap pemanggilan generate — lihat Fase 3 di dokumen arsitektur GrowthAgent.</p>

        <form method="post" action="<?= cms_esc($selfUrl) ?>" style="display:flex;gap:8px;align-items:flex-start;margin-bottom:16px;">
            <?= cms_csrf_field() ?>
            <input type="hidden" name="action" value="style_rule_create">
            <textarea name="rule_text" rows="2" placeholder="misal: Selalu tulis meta_title dalam Bahasa Indonesia, jangan pakai judul clickbait." style="flex:1;" required></textarea>
            <button type="submit" class="admin-btn admin-btn--primary">Tambah rule</button>
        </form>

        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Rule</th>
                        <th>Sumber</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($styleRules === []) : ?>
                        <tr><td colspan="4" class="muted">Belum ada style rule.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($styleRules as $rule) : ?>
                        <tr>
                            <td><?= cms_esc((string) $rule['rule_text']) ?></td>
                            <td><span class="muted"><?= cms_esc((string) $rule['source']) ?></span></td>
                            <td>
                                <?php if ((int) $rule['is_active'] === 1) : ?>
                                    <span class="pill pill--ok">Aktif</span>
                                <?php else : ?>
                                    <span class="pill pill--muted">Nonaktif</span>
                                <?php endif; ?>
                            </td>
                            <td class="table-actions">
                                <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>">
                                    <?= cms_csrf_field() ?>
                                    <input type="hidden" name="action" value="style_rule_toggle">
                                    <input type="hidden" name="id" value="<?= (int) $rule['id'] ?>">
                                    <button type="submit" class="admin-btn admin-btn--sm admin-btn--secondary"><?= (int) $rule['is_active'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?></button>
                                </form>
                                <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>" onsubmit="return confirm('Hapus style rule ini?');">
                                    <?= cms_csrf_field() ?>
                                    <input type="hidden" name="action" value="style_rule_delete">
                                    <input type="hidden" name="id" value="<?= (int) $rule['id'] ?>">
                                    <button type="submit" class="admin-btn admin-btn--sm admin-btn--ghost">Hapus</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Pemeliharaan</h3>
        </div>
        <p class="section-lead">
            Job lama otomatis dibersihkan setiap halaman ini dibuka (job yang sudah selesai &amp; berumur
            &gt; 90 hari). Job berstatus <strong>Aksi Manual</strong> (belum di-review) dan job yang sudah
            di-Approve sebagai contoh (few-shot) tidak pernah dihapus otomatis maupun manual.
        </p>
        <form method="post" action="<?= cms_esc($selfUrl) ?>" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;" onsubmit="return confirm('Hapus job selesai/gagal yang lebih tua dari jumlah hari ini? Job yang masih menunggu review tidak akan terhapus.');">
            <?= cms_csrf_field() ?>
            <input type="hidden" name="action" value="cleanup_jobs">
            <label class="muted" style="font-size:13px;">Hapus job selesai/gagal yang lebih tua dari</label>
            <input type="number" name="days" value="30" min="7" max="365" style="width:80px;">
            <span class="muted" style="font-size:13px;">hari</span>
            <button type="submit" class="admin-btn admin-btn--ghost">Bersihkan sekarang</button>
        </form>
    </div>
    </div>

    <div class="ga-page-tab-panel" data-ga-page-tab="automation">
    <?php
    // ── Autonomous Mode (GROWTH_AGENT_V2_PROPOSAL.md § Fase E, 6 Aug 2026)
    // — read-only-until-submitted panel state. Presented as ONE switch
    // (see the autonomous_toggle handler above for why enabled +
    // job_types.internal_link_suggestion are always written together).
    // Ships OFF — the oldest internal_link_suggestion job in this install
    // is nowhere near the Measurement Loop's 28-day window yet, so there is
    // no before/after evidence to justify turning this on. That decision
    // belongs to an operator, later, not to this deploy.
    $autonomousConfig = cms_gsc_get_opportunity_thresholds($pdo)['autonomous_mode'] ?? [];
    $autonomousEnabled = ($autonomousConfig['enabled'] ?? false) === true
        && (($autonomousConfig['job_types'] ?? [])['internal_link_suggestion'] ?? false) === true;
    $autonomousWeeklyLimit = max(0, (int) ($autonomousConfig['weekly_limit'] ?? 3));
    $autonomousWeeklyUsed = cms_growth_agent_autonomous_weekly_used($pdo);
    $autonomousRecentLinks = cms_growth_agent_get_recent_auto_applied_links($pdo, 10);
    ?>
    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Mode Otonom — Internal Linking</h3>
            <span class="pill pill--<?= $autonomousEnabled ? 'ok' : 'muted' ?>"><?= $autonomousEnabled ? 'AKTIF' : 'NONAKTIF' ?></span>
        </div>
        <p class="muted" style="margin:0;padding:0 20px 16px;font-size:13px;">
            Kalau dinyalakan, usulan link internal baru (job_type <code>internal_link_suggestion</code>) langsung
            diterapkan otomatis lewat fungsi yang sama persis dengan tombol "Apply" manual — tanpa menunggu review.
            Rekomendasi meta SEO (<code>seo_recommendation</code>) TIDAK PERNAH ikut, itu tetap manual selamanya.
            Dibatasi <?= $autonomousWeeklyLimit ?> auto-apply per minggu, dan tiap perubahan bisa direvert di bawah
            kalau hasilnya keliru.
        </p>
        <div class="toolbar" style="padding:0 20px 16px;">
            <div class="toolbar__left">
                <p class="muted" style="margin:0;font-size:13px;">
                    Dipakai minggu ini: <strong><?= $autonomousWeeklyUsed ?> / <?= $autonomousWeeklyLimit ?></strong>
                </p>
            </div>
            <div class="toolbar__right">
                <form method="post" action="<?= cms_esc($selfUrl) ?>" onsubmit="return confirm('<?= $autonomousEnabled ? 'Matikan' : 'Nyalakan' ?> Mode Otonom Internal Linking?');">
                    <?= cms_csrf_field() ?>
                    <input type="hidden" name="action" value="autonomous_toggle">
                    <input type="hidden" name="enabled" value="<?= $autonomousEnabled ? '0' : '1' ?>">
                    <button type="submit" class="admin-btn admin-btn--sm <?= $autonomousEnabled ? 'admin-btn--ghost' : 'admin-btn--primary' ?>">
                        <?= $autonomousEnabled ? 'Matikan' : 'Nyalakan' ?>
                    </button>
                </form>
            </div>
        </div>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Artikel</th>
                        <th>Link Ditambahkan</th>
                        <th>Diterapkan</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($autonomousRecentLinks === []) : ?>
                        <tr><td colspan="4" class="muted">Belum ada link yang diterapkan otomatis.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($autonomousRecentLinks as $autoLink) : ?>
                        <tr>
                            <td><?= cms_esc($autoLink['page_title']) ?></td>
                            <td>
                                <span class="muted">"<?= cms_esc($autoLink['anchor_text']) ?>"</span>
                                &rarr; <?= cms_esc($autoLink['target_title']) ?>
                            </td>
                            <td class="muted"><?= cms_esc($autoLink['applied_at']) ?></td>
                            <td class="table-actions">
                                <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>" onsubmit="return confirm('Revert link ini? Konten artikel akan dikembalikan seperti sebelum link ditambahkan.');">
                                    <?= cms_csrf_field() ?>
                                    <input type="hidden" name="action" value="revert_auto_applied_link">
                                    <input type="hidden" name="job_id" value="<?= $autoLink['job_id'] ?>">
                                    <button type="submit" class="admin-btn admin-btn--sm admin-btn--ghost">Revert</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php
    // ── Full Draft Automation scheduler (GROWTH_AGENT_V2_PROPOSAL.md § 6,
    // Fase H, 8 Aug 2026) — read-only-until-submitted panel state. Ships
    // OFF (see cron/growth_agent_maintenance.php's own step 8 note) — an
    // operator turns this on only once Fase F's draft quality has been
    // manually reviewed for a while.
    $autoDraftConfig = cms_gsc_get_opportunity_thresholds($pdo)['auto_draft_automation'] ?? [];
    $autoDraftEnabled = ($autoDraftConfig['enabled'] ?? false) === true;
    $autoDraftScheduleCron = (string) ($autoDraftConfig['schedule_cron'] ?? '0 6,12,18 * * *');
    $autoDraftSelectedHours = [];
    if (preg_match('/^\S+\s+(\S+)\s/', $autoDraftScheduleCron, $cronMatch)) {
        $autoDraftSelectedHours = array_map('intval', explode(',', $cronMatch[1]));
    }
    $autoDraftSourceUrls = is_array($autoDraftConfig['source_urls'] ?? null) ? $autoDraftConfig['source_urls'] : [];
    $autoDraftMaxPerDay = (int) ($autoDraftConfig['max_drafts_per_day'] ?? 3);
    $autoDraftAutoPublish = ($autoDraftConfig['auto_publish'] ?? false) === true;
    ?>
    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Full Draft Automation — Jadwal &amp; Sumber</h3>
            <span class="pill pill--<?= $autoDraftEnabled ? 'ok' : 'muted' ?>"><?= $autoDraftEnabled ? 'AKTIF' : 'NONAKTIF' ?></span>
        </div>
        <p class="muted" style="margin:0;padding:0 20px 16px;font-size:13px;">
            Kalau dinyalakan, sesuai jadwal di bawah, sistem otomatis ambil headline tren, generate draft artikel
            lengkap (judul + isi + gambar cover) lewat AI, dan masukkan ke "Job Terbaru" sebagai
            <code>auto_draft_article</code> — status "draft siap review" di tab Perlu Tindakan, menunggu Approve
            manual di <code>auto-draft-review.php</code>. <strong>KECUALI</strong> toggle "Mode Otonom —
            Auto-Publish Draft" di bawah dinyalakan juga — lihat peringatannya sendiri sebelum menyalakan itu.
        </p>
        <form id="auto-draft-automation-form" method="post" action="<?= cms_esc($selfUrl) ?>" class="form-stack" style="padding:0 20px 20px;">
            <?= cms_csrf_field() ?>
            <input type="hidden" name="action" value="auto_draft_automation_save">

            <label class="field" style="display:flex;align-items:center;gap:8px;flex-direction:row;">
                <input type="checkbox" name="enabled" value="1" <?= $autoDraftEnabled ? 'checked' : '' ?>>
                <span>Nyalakan Full Draft Automation</span>
            </label>

            <div class="field">
                <span>Jadwal (jam berapa saja per hari, WIB/server time)</span>
                <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px;">
                    <?php for ($h = 0; $h < 24; $h++) : ?>
                        <label style="display:flex;align-items:center;gap:4px;font-size:12px;border:1px solid var(--line);border-radius:8px;padding:4px 8px;">
                            <input type="checkbox" name="hours[]" value="<?= $h ?>" <?= in_array($h, $autoDraftSelectedHours, true) ? 'checked' : '' ?>>
                            <?= sprintf('%02d', $h) ?>
                        </label>
                    <?php endfor; ?>
                </div>
                <small class="muted">Cron aktif saat ini: <code><?= cms_esc($autoDraftScheduleCron) ?></code></small>
            </div>

            <label class="field">
                <span>Batas maksimal draft per hari</span>
                <input type="text" inputmode="numeric" pattern="[0-9]*" name="max_drafts_per_day"
                       value="<?= (int) $autoDraftMaxPerDay ?>" style="width:120px;">
                <small class="muted">
                    Default 3/hari. Isi angka 0–1000 (0 = tidak dibatasi, tidak direkomendasikan sampai kualitas
                    draft AI sudah divalidasi beberapa minggu). Sisa jadwal hari itu otomatis di-skip begitu
                    batas tercapai, terlepas dari berapa banyak jam yang dicentang di atas.
                </small>
            </label>

            <label class="field">
                <span>Daftar URL sumber (satu per baris)</span>
                <textarea name="source_urls" rows="4" style="width:100%;font-family:monospace;font-size:12px;"><?= cms_esc(implode("\n", $autoDraftSourceUrls)) ?></textarea>
                <small class="muted">Sama seperti Trending Headlines — sistem coba RSS dulu (<code>/rss</code>, <code>/feed</code>), fallback scraping HTML generik kalau tidak ada.</small>
            </label>

            <div class="toolbar__right">
                <button type="submit" class="admin-btn admin-btn--primary">Simpan Pengaturan</button>
            </div>
        </form>
    </div>

    <?php
    // Fase G (9 Aug 2026, docs/DECISIONS.md) — deliberately its own visually
    // distinct block, not folded quietly into the panel above, so an
    // operator can't miss what they're turning on. Saves through the exact
    // same form/action as the panel above (auto_draft_automation_save) —
    // this checkbox just rides along as one more field in that same POST,
    // NOT a separate save action, so it can never desync from the rest of
    // this config block.
    ?>
    <div class="panel" style="border:1px solid var(--danger, #c0392b);">
        <div class="panel__head">
            <h3 class="panel__title">⚠️ Mode Otonom — Auto-Publish Draft</h3>
            <span class="pill pill--<?= $autoDraftAutoPublish ? 'warn' : 'muted' ?>"><?= $autoDraftAutoPublish ? 'AKTIF' : 'NONAKTIF' ?></span>
        </div>
        <p style="margin:0;padding:0 20px 16px;font-size:13px;">
            <strong>Kalau nyala, artikel hasil scrape + AI dari Full Draft Automation di atas LANGSUNG TAYANG ke
            publik tanpa direview manusia dulu.</strong> Tidak ada persetujuan, tidak ada jeda — begitu AI selesai
            generate, artikel langsung <code>published</code>. SEO-G0 gate dan cek judul-mirip-headline-sumber
            TETAP jalan dan tetap tercatat di job (buat audit), tapi hasilnya <strong>TIDAK memblokir publish</strong>
            — kalau ada peringatan, artikel tetap tayang. <code>max_drafts_per_day</code> di atas tetap membatasi
            berapa kali AI generate per hari, tapi itu bukan pengaman publish. Ini keputusan operator yang sudah
            disetujui secara sadar (lihat <code>docs/DECISIONS.md</code>, 9 Agustus 2026) — kalau nanti ada
            artikel auto-publish yang salah fakta, judulnya kurang pas, atau bermasalah lainnya, itu konsekuensi
            yang sudah diterima, bukan bug.
        </p>
        <div style="padding:0 20px 20px;">
            <label class="field" style="display:flex;align-items:center;gap:8px;flex-direction:row;">
                <input type="checkbox" name="auto_publish" value="1" form="auto-draft-automation-form" <?= $autoDraftAutoPublish ? 'checked' : '' ?>>
                <span>Ya, saya paham risikonya — nyalakan auto-publish tanpa review manusia</span>
            </label>
        </div>
    </div>
    </div>

<script>
// ---- Page-level tabs (Growth Agent) — deliberately separate class
// names/attributes (ga-page-tab-*) from Job Terbaru's own internal tabs
// (js-ga-tab-*) above, so the two never interfere with each other.
//
// No-JS degradation: server-rendered HTML has NO `hidden` on ANY of the
// 4 tab panels — all 4 tabs' content is fully present and visible by
// default (a long scroll, same as the page before this reorg). This
// script is what hides the non-active 3 at runtime (via p.hidden below).
// If JS is blocked/fails, nothing gets hidden and every panel stays
// reachable — the tab buttons just become inert decoration instead of
// making 3/4 of the page inaccessible.
//
// Tab persistence across POST+redirect: this page's $redirect() helper
// only ever redirects back to growth-agent.php with no hash/query, so
// persistence is handled entirely client-side via sessionStorage — on
// any form submit, we record which tab that form belongs to (via the
// closest .ga-page-tab-panel, or an explicit data-ga-page-tab attribute
// on the 3 header forms that live outside any tab panel), then restore
// it on the next load. This required zero changes to $redirect() or any
// PHP action handler.
(function () {
    var STORAGE_KEY = 'wpm_ga_active_tab';
    var tabsWrap = document.querySelector('.ga-page-tabs');
    if (!tabsWrap) { return; }
    var panels = document.querySelectorAll('.ga-page-tab-panel');
    var buttons = tabsWrap.querySelectorAll('.ga-page-tab-btn');

    function activate(tabKey) {
        buttons.forEach(function (b) {
            var isActive = b.getAttribute('data-ga-page-tab') === tabKey;
            b.classList.toggle('is-active', isActive);
            b.classList.toggle('admin-btn--secondary', isActive);
            b.classList.toggle('admin-btn--ghost', !isActive);
        });
        panels.forEach(function (p) {
            p.hidden = p.getAttribute('data-ga-page-tab') !== tabKey;
        });
        try { sessionStorage.setItem(STORAGE_KEY, tabKey); } catch (e) {}
    }

    buttons.forEach(function (b) {
        b.addEventListener('click', function () {
            activate(b.getAttribute('data-ga-page-tab'));
        });
    });

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!(form instanceof HTMLFormElement)) { return; }
        var panel = form.closest('.ga-page-tab-panel');
        var tabKey = panel ? panel.getAttribute('data-ga-page-tab') : form.getAttribute('data-ga-page-tab');
        if (tabKey) {
            try { sessionStorage.setItem(STORAGE_KEY, tabKey); } catch (err) {}
        }
    }, true);

    var validKeys = Array.prototype.map.call(buttons, function (b) { return b.getAttribute('data-ga-page-tab'); });
    var initialTab = null;
    try { initialTab = sessionStorage.getItem(STORAGE_KEY); } catch (e) {}
    activate(validKeys.indexOf(initialTab) !== -1 ? initialTab : 'action');
})();
</script>
</section>
<?php
require dirname(__DIR__) . '/includes/footer.php';
