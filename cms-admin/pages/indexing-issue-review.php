<?php
declare(strict_types=1);

/**
 * "Review Indexing Issue" — read-only detail page for one
 * job_type='review_indexing_issue' row created by
 * cms_growth_agent_log_indexing_issue() (see includes/growth-agent-service.php),
 * itself triggered by an "Inspect URL"/"Inspect prioritas" click on
 * growth-agent.php (Indexing Workflow, Phase 5 roadmap, ROADMAP.md gap #2).
 *
 * Deliberately a separate page from the generic Approve/Reject buttons on
 * growth-agent.php, same reasoning as seo-recommendation-review.php but for
 * the opposite reason: there is NOTHING to apply here. The checklist +
 * raw verdict data are purely diagnostic (deterministic pattern-matching
 * against Search Console's own verdict fields — see
 * cms_growth_agent_build_indexing_checklist()), never a suggestion to
 * rewrite or republish the article. This page never writes to `pages` at
 * all — the only two actions available just close out the job record
 * itself (mark reviewed / close as legacy), matching
 * GROWTH_AGENT_SEO_ROADMAP.md Phase 5's guardrail that fixing canonical/
 * redirect/content issues stays entirely the operator's manual call,
 * carried out outside this system.
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
    $redirectBack('Job masalah index tidak valid.', 'error');
}

$jobStmt = $pdo->prepare(
    "SELECT j.id, j.status, j.page_id, j.priority, j.input_brief, j.output_json, j.created_at,
            p.title AS page_title, p.slug AS page_slug
       FROM growth_agent_jobs j
       LEFT JOIN pages p ON p.page_id = j.page_id
      WHERE j.id = :id AND j.job_type = 'review_indexing_issue'
      LIMIT 1"
);
$jobStmt->execute(['id' => $jobId]);
$job = $jobStmt->fetch();

if (!$job) {
    $redirectBack('Job masalah index tidak ditemukan.', 'error');
}

if ($job['status'] !== 'manual_action') {
    $redirectBack('Job ini sudah pernah diproses sebelumnya.', 'error');
}

$output = json_decode((string) ($job['output_json'] ?? ''), true);
$checklist = is_array($output) && is_array($output['checklist'] ?? null) ? $output['checklist'] : [];
$inspection = is_array($output) && is_array($output['inspection'] ?? null) ? $output['inspection'] : [];
$inputBrief = json_decode((string) ($job['input_brief'] ?? ''), true);
$inputBrief = is_array($inputBrief) ? $inputBrief : [];
$inspectedUrl = (string) ($inputBrief['url'] ?? '');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // cms_verify_csrf() already ran for every POST in includes/auth.php.
    $action = (string) ($_POST['action'] ?? '');
    $currentAdminId = (int) ($_SESSION['cms_admin_id'] ?? 0) ?: null;

    if ($action === 'mark_reviewed') {
        $ins = $pdo->prepare(
            'INSERT INTO growth_agent_feedback (job_id, action, reviewed_by, created_at)
             VALUES (:job_id, :action, :reviewed_by, NOW())'
        );
        $ins->execute(['job_id' => $jobId, 'action' => 'approved_as_is', 'reviewed_by' => $currentAdminId]);

        $pdo->prepare("UPDATE growth_agent_jobs SET status = 'succeeded', updated_at = NOW() WHERE id = :id")
            ->execute(['id' => $jobId]);

        $redirectBack('Indexing issue ditandai sudah ditinjau.');
    }

    if ($action === 'close_as_legacy') {
        cms_growth_agent_ensure_legacy_status($pdo);

        $ins = $pdo->prepare(
            'INSERT INTO growth_agent_feedback (job_id, action, reviewed_by, created_at)
             VALUES (:job_id, :action, :reviewed_by, NOW())'
        );
        $ins->execute(['job_id' => $jobId, 'action' => 'closed_as_legacy', 'reviewed_by' => $currentAdminId]);

        $pdo->prepare("UPDATE growth_agent_jobs SET status = 'closed_as_legacy', updated_at = NOW() WHERE id = :id")
            ->execute(['id' => $jobId]);

        $redirectBack('Job ditandai sebagai legacy — tidak dihitung sebagai reject, cuma sudah tidak relevan lagi.');
    }

    $redirectBack('Aksi tidak dikenal.', 'error');
}

$pageTitle = 'Review Masalah Index';
$currentNav = 'growth-agent';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'AI Management', 'href' => ''],
    ['label' => 'Growth Agent', 'href' => cms_nav_href('growth-agent.php')],
    ['label' => 'Review Masalah Index', 'href' => ''],
];

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/sidebar.php';
require dirname(__DIR__) . '/includes/navbar.php';
require dirname(__DIR__) . '/includes/breadcrumb.php';
require dirname(__DIR__) . '/includes/alerts.php';
?>
<section class="admin-stack">
    <div class="toolbar">
        <div class="toolbar__left">
            <h2 class="section-title">Review Masalah Index</h2>
            <p class="section-lead">Checklist deterministik + data verdict mentah dari Search Console URL Inspection — bukan rekomendasi AI, dan tidak ada yang otomatis ditulis ke artikel.</p>
        </div>
        <div class="toolbar__right">
            <a class="admin-btn admin-btn--ghost" href="growth-agent.php">&larr; Kembali</a>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Sumber Inspeksi</h3>
        </div>
        <table class="admin-table">
            <tbody>
                <tr><td class="muted" style="width:180px;">Job</td><td>Review Masalah Index — job #<?= (int) $job['id'] ?></td></tr>
                <tr><td class="muted">Artikel</td><td><?= $job['page_title'] ? cms_esc((string) $job['page_title']) : '<span class="muted">Artikel tidak ditemukan</span>' ?></td></tr>
                <tr><td class="muted">URL</td><td><?php if ($inspectedUrl !== '') : ?><a href="<?= cms_esc($inspectedUrl) ?>" target="_blank" rel="noopener"><?= cms_esc($inspectedUrl) ?></a><?php else : ?><span class="muted">—</span><?php endif; ?></td></tr>
                <tr><td class="muted">Dibuat</td><td class="muted"><?= cms_esc((string) $job['created_at']) ?></td></tr>
            </tbody>
        </table>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Checklist kemungkinan penyebab</h3>
        </div>
        <div style="padding:0 20px 20px;">
            <?php if ($checklist === []) : ?>
                <p class="muted">Tidak ada checklist tersimpan untuk job ini.</p>
            <?php else : ?>
                <ul style="margin:0;padding-left:20px;line-height:1.7;">
                    <?php foreach ($checklist as $point) : ?>
                        <li><?= cms_esc((string) $point) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Data verdict mentah</h3>
        </div>
        <div class="table-wrap">
            <table class="admin-table">
                <tbody>
                    <tr><td class="muted" style="width:200px;">Verdict</td><td><?= cms_esc((string) ($inspection['verdict'] ?? '—')) ?></td></tr>
                    <tr><td class="muted">Coverage State</td><td><?= cms_esc((string) ($inspection['coverage_state'] ?? '—')) ?></td></tr>
                    <tr><td class="muted">Robots.txt State</td><td><?= cms_esc((string) ($inspection['robots_txt_state'] ?? '—')) ?></td></tr>
                    <tr><td class="muted">Indexing State</td><td><?= cms_esc((string) ($inspection['indexing_state'] ?? '—')) ?></td></tr>
                    <tr><td class="muted">Page Fetch State</td><td><?= cms_esc((string) ($inspection['page_fetch_state'] ?? '—')) ?></td></tr>
                    <tr><td class="muted">Waktu Crawl Terakhir</td><td><?= cms_esc((string) ($inspection['last_crawl_time'] ?? '—')) ?></td></tr>
                    <tr><td class="muted">Google Canonical</td><td><?= cms_esc((string) ($inspection['google_canonical'] ?? '—')) ?></td></tr>
                    <tr><td class="muted">User Canonical</td><td><?= cms_esc((string) ($inspection['user_canonical'] ?? '—')) ?></td></tr>
                    <tr><td class="muted">Sitemap</td><td><?= cms_esc((string) ($inspection['sitemap'] ?? '—')) ?></td></tr>
                </tbody>
            </table>
        </div>

        <p class="muted" style="margin-top:16px;font-size:13px;">
            Perbaikan (canonical, redirect, konten, dsb.) tetap sepenuhnya keputusan &amp; tindakan manual —
            halaman ini tidak pernah menulis apa pun ke artikel.
        </p>

        <div style="display:flex;gap:10px;margin-top:16px;">
            <form method="post" action="indexing-issue-review.php?job_id=<?= (int) $job['id'] ?>">
                <?= cms_csrf_field() ?>
                <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                <input type="hidden" name="action" value="mark_reviewed">
                <button type="submit" class="admin-btn admin-btn--primary">Tandai Sudah Ditinjau</button>
            </form>
            <form method="post" action="indexing-issue-review.php?job_id=<?= (int) $job['id'] ?>" onsubmit="return confirm('Tandai job ini sebagai legacy? Ini BUKAN reject — cuma menandai sudah tidak relevan lagi, tidak dihitung sebagai penolakan aktif.');">
                <?= cms_csrf_field() ?>
                <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                <input type="hidden" name="action" value="close_as_legacy">
                <button type="submit" class="admin-btn admin-btn--ghost">Tutup sebagai Legacy</button>
            </form>
        </div>
    </div>
</section>
<?php
require dirname(__DIR__) . '/includes/footer.php';
