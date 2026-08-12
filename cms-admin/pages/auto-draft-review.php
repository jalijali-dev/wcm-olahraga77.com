<?php
declare(strict_types=1);

/**
 * "Full Draft Automation" — review page for one job_type='auto_draft_article'
 * row created by cms_growth_agent_generate_auto_draft_article() (see
 * includes/growth-agent-service.php).
 *
 * Deliberately a separate page from the generic Approve/Reject buttons on
 * growth-agent.php, same reasoning as seo-recommendation-review.php /
 * internal-link-review.php / indexing-issue-review.php /
 * cannibalization-review.php: Approve here is the "Content Agent Adapter"
 * execution step (see cms_growth_agent_create_article_draft_from_auto_draft()'s
 * own docblock) that writes a brand-new row into `pages` — the operator
 * needs to actually SEE the generated title/body/cover image first, not
 * approve blind on a generic button with zero preview.
 *
 * Approve logic here is MOVED from growth-agent.php's generic action
 * handler (was inline there before this page existed), not duplicated —
 * growth-agent.php no longer has an auto_draft_article special case.
 *
 * Still only ever produces a DRAFT — publish stays separate and fully
 * manual, exactly like before this page existed.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';
require_once dirname(__DIR__) . '/includes/growth-agent-service.php';

cms_require_role(['superadmin', 'admin']);

cms_growth_agent_ensure_schema($pdo);

$jobId = (int) ($_GET['job_id'] ?? $_POST['job_id'] ?? 0);

$backUrl = 'growth-agent.php';

$redirectBack = static function (string $message, string $type = 'success') use ($backUrl): void {
    $_SESSION['cms_flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . $backUrl, true, 302);
    exit;
};

if ($jobId <= 0) {
    $redirectBack('Job draft otomatis tidak valid.', 'error');
}

$jobStmt = $pdo->prepare(
    "SELECT id, status, page_id, input_brief, output_json, created_at
       FROM growth_agent_jobs
      WHERE id = :id AND job_type = 'auto_draft_article'
      LIMIT 1"
);
$jobStmt->execute(['id' => $jobId]);
$job = $jobStmt->fetch();

if (!$job) {
    $redirectBack('Job draft otomatis tidak ditemukan.', 'error');
}

// Same gate as growth-agent.php's $canReviewAutoDraft — a job that's
// already been approved (page_id set) or isn't in a reviewable state
// (still running, or already rejected/closed) has nothing left to do here.
$canReview = $job['status'] === 'succeeded' && empty($job['page_id']);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // cms_verify_csrf() already ran for every POST in includes/auth.php.
    $action = (string) ($_POST['action'] ?? '');
    $currentAdminId = (int) ($_SESSION['cms_admin_id'] ?? 0) ?: null;

    if (!$canReview) {
        $redirectBack('Job ini sudah pernah diproses sebelumnya.', 'error');
    }

    if ($action === 'approve') {
        // Full Draft Automation (GROWTH_AGENT_V2_PROPOSAL.md § 6, Fase F) —
        // "Approve IS the execution step", same exception as
        // gsc_article_idea/topic_gap_article/keyword_expansion_topic.
        // ALWAYS still lands as pages.status='draft' — publish stays a
        // fully separate, manual action; this never sets 'published'.
        $draftResult = cms_growth_agent_create_article_draft_from_auto_draft($pdo, $job, $currentAdminId);

        if (!$draftResult['ok']) {
            $failUpd = $pdo->prepare("UPDATE growth_agent_jobs SET status = 'failed', error_message = :error, updated_at = NOW() WHERE id = :id");
            $failUpd->execute(['error' => $draftResult['error'], 'id' => $jobId]);
            $redirectBack('Gagal membuat draft artikel: ' . $draftResult['error'], 'error');
        }

        $ins = $pdo->prepare(
            'INSERT INTO growth_agent_feedback (job_id, action, reviewed_by, created_at)
             VALUES (:job_id, :action, :reviewed_by, NOW())'
        );
        $ins->execute(['job_id' => $jobId, 'action' => 'approved_as_is', 'reviewed_by' => $currentAdminId]);

        $upd = $pdo->prepare('UPDATE growth_agent_jobs SET status = :status, page_id = :page_id, updated_at = NOW() WHERE id = :id');
        $upd->execute(['status' => 'succeeded', 'page_id' => $draftResult['page_id'], 'id' => $jobId]);

        $redirectBack('Draft artikel otomatis berhasil dibuat — WAJIB dibaca & diedit sebelum publish, klik "Edit draft" di Job Terbaru.');
    }

    if ($action === 'reject') {
        $currentAdminId = (int) ($_SESSION['cms_admin_id'] ?? 0) ?: null;
        $pdo->prepare(
            'INSERT INTO growth_agent_feedback (job_id, action, reviewed_by, created_at) VALUES (:job_id, :action, :reviewed_by, NOW())'
        )->execute(['job_id' => $jobId, 'action' => 'rejected', 'reviewed_by' => $currentAdminId]);

        $pdo->prepare("UPDATE growth_agent_jobs SET status = 'failed', updated_at = NOW() WHERE id = :id")
            ->execute(['id' => $jobId]);

        $redirectBack('Draft otomatis ditolak — tidak ada artikel yang dibuat.');
    }

    $redirectBack('Aksi tidak dikenal.', 'error');
}

$pageTitle = 'Review Draft Otomatis';
$currentNav = 'growth-agent';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'AI Management', 'href' => ''],
    ['label' => 'Growth Agent', 'href' => cms_nav_href('growth-agent.php')],
    ['label' => 'Review Draft Otomatis', 'href' => ''],
];

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/sidebar.php';
require dirname(__DIR__) . '/includes/navbar.php';
require dirname(__DIR__) . '/includes/breadcrumb.php';
require dirname(__DIR__) . '/includes/alerts.php';

$output = json_decode((string) ($job['output_json'] ?? ''), true);
$output = is_array($output) ? $output : [];
$brief = json_decode((string) ($job['input_brief'] ?? ''), true);
$brief = is_array($brief) ? $brief : [];

$title = (string) ($output['title'] ?? '');
$bodyHtml = (string) ($output['body_html'] ?? '');
$coverImagePath = trim((string) ($output['cover_image_path'] ?? ''));
$coverImageIsFallback = ($output['cover_image_is_fallback'] ?? false) === true;
$coverImageError = (string) ($output['cover_image_error'] ?? '');
$coverImagePreviewUrl = $coverImagePath !== '' ? app_asset_preview_url($coverImagePath) : '';

$sourceHeadline = (string) ($brief['source_headline'] ?? '');
$sourceUrl = (string) ($brief['source_url'] ?? '');

$g0Warnings = is_array($brief['seo_g0_gate']['warnings'] ?? null) ? $brief['seo_g0_gate']['warnings'] : [];
$titleHeadlineFlagged = ($brief['title_vs_headline_check']['flagged'] ?? false) === true;
$titleHeadlineMatches = $titleHeadlineFlagged && is_array($brief['title_vs_headline_check']['matches'] ?? null)
    ? $brief['title_vs_headline_check']['matches']
    : [];
?>
<section class="admin-stack">
    <div class="toolbar">
        <div class="toolbar__left">
            <h2 class="section-title">Review Draft Otomatis</h2>
            <p class="section-lead">Cek judul, isi, gambar cover, dan peringatan di bawah sebelum Approve — draft ini
                dibuat sepenuhnya oleh AI tanpa campur tangan manusia, WAJIB dibaca dulu sebelum jadi draft artikel beneran.</p>
        </div>
        <div class="toolbar__right">
            <a class="admin-btn admin-btn--ghost" href="growth-agent.php">&larr; Kembali</a>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Sumber</h3>
        </div>
        <table class="admin-table">
            <tbody>
                <tr><td class="muted" style="width:180px;">Job</td><td>Full Draft Automation — job #<?= (int) $job['id'] ?></td></tr>
                <tr>
                    <td class="muted">Headline sumber</td>
                    <td>
                        <?= $sourceHeadline !== '' ? cms_esc($sourceHeadline) : '<span class="muted">—</span>' ?>
                        <?php if ($sourceUrl !== '') : ?>
                            &nbsp;<a class="admin-btn admin-btn--sm admin-btn--ghost" href="<?= cms_esc($sourceUrl) ?>" target="_blank" rel="noopener">Buka sumber</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr><td class="muted">Dibuat</td><td class="muted"><?= cms_esc((string) $job['created_at']) ?></td></tr>
            </tbody>
        </table>
    </div>

    <?php if ($g0Warnings !== [] || $titleHeadlineFlagged) : ?>
    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Peringatan</h3>
        </div>
        <div style="padding:16px 20px;">
            <?php if ($g0Warnings !== []) : ?>
                <div style="margin-bottom:10px;">
                    <span class="pill pill--warn" title="SEO-G0 Gate: usulan ini berpotensi tumpang tindih dengan sesuatu yang sudah ada — cek detail di bawah sebelum approve.">
                        ⚠ SEO-G0: <?= count($g0Warnings) ?> peringatan
                    </span>
                    <div class="muted" style="font-size:12px;margin-top:6px;">
                        <?php foreach ($g0Warnings as $g0Warning) : ?>
                            <div style="margin-top:2px;">• <?= cms_esc((string) ($g0Warning['message'] ?? '')) ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($titleHeadlineFlagged) : ?>
                <div>
                    <span class="pill pill--warn" title="Judul yang dibuat AI ini mirip banget sama headline sumber yang dipakai — cek dulu sebelum approve, pastikan bukan sekadar reword tipis.">
                        ⚠ Mirip headline sumber: <?= count($titleHeadlineMatches) ?>
                    </span>
                    <div class="muted" style="font-size:12px;margin-top:6px;">
                        <?php foreach ($titleHeadlineMatches as $flagMatch) : ?>
                            <div style="margin-top:2px;">• "<?= cms_esc((string) ($flagMatch['headline'] ?? '')) ?>" (overlap <?= cms_esc((string) ($flagMatch['coefficient'] ?? '')) ?>)</div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Gambar Cover</h3>
        </div>
        <div style="padding:16px 20px;">
            <?php if ($coverImagePreviewUrl !== '') : ?>
                <?php if ($coverImageIsFallback) : ?>
                    <span class="pill pill--warn" title="image_agent belum dikonfigurasi atau gagal generate — ini logo situs sebagai gambar sementara, bukan hasil AI.">Gambar default (fallback)</span>
                <?php endif; ?>
                <div style="margin-top:8px;max-width:480px;">
                    <img src="<?= cms_esc($coverImagePreviewUrl) ?>" alt="Cover image preview" style="max-width:100%;border-radius:8px;">
                </div>
            <?php else : ?>
                <span class="muted">Tidak ada gambar cover.</span>
            <?php endif; ?>
            <?php if ($coverImageError !== '') : ?>
                <p class="muted" style="font-size:12px;margin-top:8px;">Catatan generate gambar: <?= cms_esc($coverImageError) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Preview Artikel</h3>
        </div>
        <div style="padding:16px 20px;">
            <h2 style="margin-top:0;"><?= cms_esc($title) ?></h2>
            <?php /*
             * body_html rendered directly (NOT cms_esc()'d) — it's real
             * markup meant to be previewed as rendered HTML, not escaped
             * text like internal-link-review.php's plain-text context
             * snippet. Same trust boundary as the rest of this admin: only
             * superadmin/admin (cms_require_role() above) can reach this
             * page, and Approve saves this exact same HTML verbatim into
             * pages.content anyway (cms_growth_agent_create_article_draft_
             * from_auto_draft()) — previewing it here doesn't create a new
             * XSS surface beyond what already exists once it's a live page,
             * it just lets the operator see that surface BEFORE approving
             * instead of only after.
             */ ?>
            <div class="admin-article-preview" style="line-height:1.7;font-size:15px;">
                <?= $bodyHtml !== '' ? $bodyHtml : '<p class="muted">(Tidak ada isi body.)</p>' ?>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Keputusan</h3>
        </div>
        <p class="muted" style="padding:0 20px;font-size:13px;">
            Approve membuat draft artikel baru (status <code>draft</code>, TIDAK langsung publish) — tetap wajib dibaca
            ulang &amp; diedit di halaman edit artikel sebelum publish beneran. Reject menutup job ini tanpa membuat artikel apapun.
        </p>
        <div style="display:flex;gap:10px;padding:16px 20px;">
            <form method="post" action="auto-draft-review.php?job_id=<?= (int) $job['id'] ?>">
                <?= cms_csrf_field() ?>
                <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                <input type="hidden" name="action" value="approve">
                <button type="submit" class="admin-btn admin-btn--primary" <?= ($canReview && $title !== '' && $bodyHtml !== '') ? '' : 'disabled' ?>>Approve</button>
            </form>
            <form method="post" action="auto-draft-review.php?job_id=<?= (int) $job['id'] ?>">
                <?= cms_csrf_field() ?>
                <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                <input type="hidden" name="action" value="reject">
                <button type="submit" class="admin-btn admin-btn--ghost" <?= $canReview ? '' : 'disabled' ?>>Reject</button>
            </form>
        </div>
    </div>
</section>
<?php
require dirname(__DIR__) . '/includes/footer.php';
