<?php
declare(strict_types=1);

/**
 * "Review Cannibalization" — read-only detail page for one
 * job_type='cannibalization_review' row created by
 * cms_growth_agent_log_cannibalization_review() (see
 * includes/growth-agent-service.php), itself triggered by a "Review"
 * click on a Cannibalization row in growth-agent.php's Prioritized
 * Opportunities panel (ROADMAP.md gap #5).
 *
 * Deliberately a separate page from the generic Approve/Reject buttons —
 * same reasoning as indexing-issue-review.php: there is NOTHING to apply
 * here, and no AI is involved anywhere in this feature at all (unlike
 * seo_recommendation/gsc_content_optimization/gsc_article_idea, which all
 * have a "Generate" AI step). The query + competing pages/shares are pure
 * SQL aggregation (cms_gsc_compute_opportunities() in gsc-api.php).
 * Deciding whether to differentiate intent, consolidate content, or pick
 * one page as the pillar for this query is a judgment call this codebase
 * deliberately never routes to AI — this page only ever closes out the
 * job record itself (mark reviewed / close as legacy), never touches
 * `pages` at all.
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
    $redirectBack('Job kanibalisasi tidak valid.', 'error');
}

$jobStmt = $pdo->prepare(
    "SELECT id, status, priority, input_brief, output_json, created_at
       FROM growth_agent_jobs
      WHERE id = :id AND job_type = 'cannibalization_review'
      LIMIT 1"
);
$jobStmt->execute(['id' => $jobId]);
$job = $jobStmt->fetch();

if (!$job) {
    $redirectBack('Job kanibalisasi tidak ditemukan.', 'error');
}

if ($job['status'] !== 'manual_action') {
    $redirectBack('Job ini sudah pernah diproses sebelumnya.', 'error');
}

$inputBrief = json_decode((string) ($job['input_brief'] ?? ''), true);
$inputBrief = is_array($inputBrief) ? $inputBrief : [];
$queryText = (string) ($inputBrief['query'] ?? '');
$totalClicks = (int) ($inputBrief['total_clicks'] ?? 0);
$totalImpressions = (int) ($inputBrief['total_impressions'] ?? 0);

$output = json_decode((string) ($job['output_json'] ?? ''), true);
$competingPages = is_array($output) && is_array($output['competing_pages'] ?? null) ? $output['competing_pages'] : [];

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

        $redirectBack('Kanibalisasi ditandai sudah ditinjau.');
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

$pageTitle = 'Review Kanibalisasi';
$currentNav = 'growth-agent';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'AI Management', 'href' => ''],
    ['label' => 'Growth Agent', 'href' => cms_nav_href('growth-agent.php')],
    ['label' => 'Review Kanibalisasi', 'href' => ''],
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
            <h2 class="section-title">Review Kanibalisasi</h2>
            <p class="section-lead">Satu query yang klik/impression-nya terbagi ke beberapa artikel — deteksi murni SQL, bukan AI, dan tidak ada yang otomatis ditulis ke artikel.</p>
        </div>
        <div class="toolbar__right">
            <a class="admin-btn admin-btn--ghost" href="growth-agent.php">&larr; Kembali</a>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Sumber Query</h3>
        </div>
        <table class="admin-table">
            <tbody>
                <tr><td class="muted" style="width:180px;">Job</td><td>Review Kanibalisasi — job #<?= (int) $job['id'] ?></td></tr>
                <tr><td class="muted">Query</td><td><strong><?= cms_esc($queryText) ?></strong></td></tr>
                <tr><td class="muted">Total Klik</td><td><?= (int) $totalClicks ?></td></tr>
                <tr><td class="muted">Total Impresi</td><td><?= (int) $totalImpressions ?></td></tr>
                <tr><td class="muted">Dibuat</td><td class="muted"><?= cms_esc((string) $job['created_at']) ?></td></tr>
            </tbody>
        </table>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Artikel yang bentrok untuk query ini</h3>
        </div>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Artikel</th>
                        <th>Klik</th>
                        <th>Impresi</th>
                        <th>Share</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($competingPages === []) : ?>
                        <tr><td colspan="4" class="muted">Tidak ada data artikel tersimpan untuk job ini.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($competingPages as $cp) : ?>
                        <tr>
                            <td>
                                <?php if (!empty($cp['page_id'])) : ?>
                                    <a href="pages.php?edit=<?= (int) $cp['page_id'] ?>"><?= cms_esc((string) ($cp['title'] ?? ('Artikel #' . (int) $cp['page_id']))) ?></a>
                                <?php else : ?>
                                    <?= cms_esc((string) ($cp['title'] ?? '—')) ?>
                                <?php endif; ?>
                            </td>
                            <td><?= (int) ($cp['clicks'] ?? 0) ?></td>
                            <td><?= (int) ($cp['impressions'] ?? 0) ?></td>
                            <td><span class="pill pill--muted"><?= round(((float) ($cp['share'] ?? 0)) * 100, 1) ?>%</span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p class="muted" style="margin-top:16px;font-size:13px;">
            Keputusan (bedakan intent, konsolidasi konten, atau tentukan satu pillar page) sepenuhnya manual —
            halaman ini tidak pernah menulis apa pun ke artikel.
        </p>

        <div style="display:flex;gap:10px;margin-top:16px;">
            <form method="post" action="cannibalization-review.php?job_id=<?= (int) $job['id'] ?>">
                <?= cms_csrf_field() ?>
                <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                <input type="hidden" name="action" value="mark_reviewed">
                <button type="submit" class="admin-btn admin-btn--primary">Tandai Sudah Ditinjau</button>
            </form>
            <form method="post" action="cannibalization-review.php?job_id=<?= (int) $job['id'] ?>" onsubmit="return confirm('Tandai job ini sebagai legacy? Ini BUKAN reject — cuma menandai sudah tidak relevan lagi, tidak dihitung sebagai penolakan aktif.');">
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
