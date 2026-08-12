<?php
declare(strict_types=1);

/**
 * "Apply SEO Recommendation" — review/apply page for one
 * job_type='seo_recommendation' row created by
 * cms_growth_agent_scan_seo_recommendations() (see includes/growth-agent-service.php).
 *
 * This is deliberately a separate page from the generic Approve/Reject
 * buttons on growth-agent.php: applying a recommendation here is the ONLY
 * action in the whole Growth Agent feature that writes back into the
 * `pages` table, and it writes ONLY meta_title + meta_description —
 * nothing else on the page is ever touched. Everything else (article
 * generation, FAQ generation) only ever produces a draft the human still
 * has to paste in manually.
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
    $redirectBack('Job rekomendasi SEO tidak valid.', 'error');
}

$jobStmt = $pdo->prepare(
    "SELECT j.id, j.status, j.page_id, j.input_brief, j.output_json, j.created_at,
            p.title AS page_title, p.slug AS page_slug,
            p.meta_title AS page_meta_title, p.meta_description AS page_meta_description
       FROM growth_agent_jobs j
       LEFT JOIN pages p ON p.page_id = j.page_id
      WHERE j.id = :id AND j.job_type = 'seo_recommendation'
      LIMIT 1"
);
$jobStmt->execute(['id' => $jobId]);
$job = $jobStmt->fetch();

if (!$job) {
    $redirectBack('Job rekomendasi SEO tidak ditemukan.', 'error');
}

if ($job['status'] !== 'manual_action') {
    $redirectBack('Rekomendasi ini sudah pernah diproses sebelumnya.', 'error');
}

$output = json_decode((string) ($job['output_json'] ?? ''), true);
if (!is_array($output) || !isset($output['recommended_meta_title'], $output['recommended_meta_description'])) {
    $redirectBack('Data rekomendasi rusak/tidak lengkap.', 'error');
}

// Always compare against the page's CURRENT live values, not the snapshot
// taken at scan time — the admin may have already changed them by hand.
$currentMetaTitle = (string) ($job['page_meta_title'] ?? '');
$currentMetaDescription = (string) ($job['page_meta_description'] ?? '');
$recommendedMetaTitle = (string) $output['recommended_meta_title'];
$recommendedMetaDescription = (string) $output['recommended_meta_description'];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // cms_verify_csrf() already ran for every POST in includes/auth.php.
    $action = (string) ($_POST['action'] ?? '');
    $currentAdminId = (int) ($_SESSION['cms_admin_id'] ?? 0) ?: null;

    if ($action === 'apply') {
        if (!$job['page_id']) {
            $redirectBack('Artikel tujuan tidak ditemukan — mungkin sudah dihapus.', 'error');
        }

        $pdo->beginTransaction();
        try {
            // Scoped to exactly these two columns — nothing else on the
            // page is ever touched by this action.
            $upd = $pdo->prepare(
                'UPDATE pages SET meta_title = :meta_title, meta_description = :meta_description, updated_at = NOW()
                  WHERE page_id = :page_id'
            );
            $upd->execute([
                'meta_title' => mb_substr($recommendedMetaTitle, 0, 255),
                'meta_description' => mb_substr($recommendedMetaDescription, 0, 255),
                'page_id' => (int) $job['page_id'],
            ]);

            $ins = $pdo->prepare(
                'INSERT INTO growth_agent_feedback (job_id, action, reviewed_by, created_at)
                 VALUES (:job_id, :action, :reviewed_by, NOW())'
            );
            $ins->execute(['job_id' => $jobId, 'action' => 'approved_as_is', 'reviewed_by' => $currentAdminId]);

            $pdo->prepare("UPDATE growth_agent_jobs SET status = 'succeeded', updated_at = NOW() WHERE id = :id")
                ->execute(['id' => $jobId]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $redirectBack('Gagal menerapkan rekomendasi: ' . $e->getMessage(), 'error');
        }

        $redirectBack('Rekomendasi diterapkan — meta title & meta description artikel sudah diperbarui.');
    }

    if ($action === 'reject') {
        $ins = $pdo->prepare(
            'INSERT INTO growth_agent_feedback (job_id, action, reviewed_by, created_at)
             VALUES (:job_id, :action, :reviewed_by, NOW())'
        );
        $ins->execute(['job_id' => $jobId, 'action' => 'rejected', 'reviewed_by' => $currentAdminId]);

        $pdo->prepare("UPDATE growth_agent_jobs SET status = 'failed', updated_at = NOW() WHERE id = :id")
            ->execute(['id' => $jobId]);

        $redirectBack('Rekomendasi ditolak — tidak ada perubahan pada artikel.');
    }

    $redirectBack('Aksi tidak dikenal.', 'error');
}

$pageTitle = 'Review Rekomendasi SEO';
$currentNav = 'growth-agent';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'AI Management', 'href' => ''],
    ['label' => 'Growth Agent', 'href' => cms_nav_href('growth-agent.php')],
    ['label' => 'Review Rekomendasi', 'href' => ''],
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
            <h2 class="section-title">Review Rekomendasi SEO</h2>
            <p class="section-lead">Bandingkan meta title/description saat ini dengan usulan Growth Agent, lalu Apply atau Reject.</p>
        </div>
        <div class="toolbar__right">
            <a class="admin-btn admin-btn--ghost" href="growth-agent.php">&larr; Kembali</a>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Sumber Aksi</h3>
        </div>
        <table class="admin-table">
            <tbody>
                <tr><td class="muted" style="width:180px;">Job</td><td>SEO Recommendation — job #<?= (int) $job['id'] ?></td></tr>
                <tr><td class="muted">Artikel</td><td><?= $job['page_title'] ? cms_esc((string) $job['page_title']) : '<span class="muted">Artikel tidak ditemukan</span>' ?></td></tr>
                <tr><td class="muted">Dibuat</td><td class="muted"><?= cms_esc((string) $job['created_at']) ?></td></tr>
            </tbody>
        </table>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Saat Ini vs Rekomendasi</h3>
        </div>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th style="width:140px;"></th>
                        <th>Saat Ini</th>
                        <th>Rekomendasi</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="muted">Meta Title</td>
                        <td><?= $currentMetaTitle !== '' ? cms_esc($currentMetaTitle) : '<span class="muted">(kosong)</span>' ?></td>
                        <td><strong><?= cms_esc($recommendedMetaTitle) ?></strong></td>
                    </tr>
                    <tr>
                        <td class="muted">Meta Description</td>
                        <td><?= $currentMetaDescription !== '' ? cms_esc($currentMetaDescription) : '<span class="muted">(kosong)</span>' ?></td>
                        <td><strong><?= cms_esc($recommendedMetaDescription) ?></strong></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="muted" style="margin-top:16px;font-size:13px;">
            Apply hanya akan memperbarui <code>meta_title</code> dan <code>meta_description</code> pada artikel ini.
            Tidak ada bagian lain dari artikel yang berubah.
        </p>

        <div style="display:flex;gap:10px;margin-top:16px;">
            <form method="post" action="seo-recommendation-review.php?job_id=<?= (int) $job['id'] ?>">
                <?= cms_csrf_field() ?>
                <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                <input type="hidden" name="action" value="apply">
                <button type="submit" class="admin-btn admin-btn--primary" <?= $job['page_id'] ? '' : 'disabled' ?>>Apply</button>
            </form>
            <form method="post" action="seo-recommendation-review.php?job_id=<?= (int) $job['id'] ?>">
                <?= cms_csrf_field() ?>
                <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                <input type="hidden" name="action" value="reject">
                <button type="submit" class="admin-btn admin-btn--ghost">Reject</button>
            </form>
        </div>
    </div>
</section>
<?php
require dirname(__DIR__) . '/includes/footer.php';
