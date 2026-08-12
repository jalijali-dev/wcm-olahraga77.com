<?php
declare(strict_types=1);

/**
 * "Content Conflict Detection" — sibling page to seo-intelligence.php,
 * deliberately without its own sidebar entry (opened via a link from
 * SEO Intelligence, same pattern as indexing-issue-review.php /
 * cannibalization-review.php).
 *
 * Full-recompute AI analysis over the same 50-most-recent-published
 * candidate set as cms_growth_agent_generate_topic_clusters() — see
 * cms_growth_agent_generate_content_conflicts() in
 * includes/growth-agent-service.php. "Buat Proposal Konflik" never merges/
 * redirects anything automatically ("Recommendation only" guardrail): it
 * only queues a 'content_conflict_proposal' job, reviewed via the generic
 * Approve/Reject buttons on growth-agent.php — approving it just marks the
 * recommendation as human-reviewed, no article is ever touched.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';
require_once dirname(__DIR__) . '/includes/growth-agent-service.php';

cms_require_role(['superadmin', 'admin']);

cms_growth_agent_ensure_schema($pdo);
cms_growth_agent_seo_intel_ensure_schema($pdo);

$selfUrl = 'content-conflict-detection.php';

$redirect = static function (string $message, string $type = 'success') use ($selfUrl): void {
    $_SESSION['cms_flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . $selfUrl, true, 302);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // cms_verify_csrf() already ran for every POST in includes/auth.php.
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'generate_conflicts') {
        $result = cms_growth_agent_generate_content_conflicts($pdo);
        if ($result['ok']) {
            $redirect($result['conflicts_created'] . ' potensi konflik konten berhasil dibuat dari 50 artikel published terbaru.');
        }
        $redirect('Gagal generate content conflict: ' . $result['error'], 'error');
    }

    if ($action === 'request_conflict_proposal') {
        $conflictId = (int) ($_POST['conflict_id'] ?? 0);
        if ($conflictId <= 0) {
            $redirect('Konflik tidak valid.', 'error');
        }
        $jobId = cms_growth_agent_request_conflict_proposal($pdo, $conflictId);
        if ($jobId > 0) {
            $redirect('Proposal konflik berhasil diajukan — cek di "Job Terbaru" pada Growth Agent untuk ditinjau.');
        }
        $redirect('Gagal mengajukan proposal konflik.', 'error');
    }

    $redirect('Aksi tidak dikenal.', 'error');
}

$conflictsStmt = $pdo->query(
    "SELECT c.id, c.page_a_id, c.page_b_id, c.risk, c.issue_text, c.recommendation_text,
            c.status, c.generated_at,
            pa.title AS page_a_title, pa.slug AS page_a_slug,
            pb.title AS page_b_title, pb.slug AS page_b_slug
       FROM growth_agent_content_conflicts c
       LEFT JOIN pages pa ON pa.page_id = c.page_a_id
       LEFT JOIN pages pb ON pb.page_id = c.page_b_id
      WHERE c.status != 'dismissed'
      ORDER BY FIELD(c.risk, 'high', 'medium', 'low'), c.generated_at DESC"
);
$conflicts = $conflictsStmt->fetchAll();

$pageTitle = 'Content Conflict Detection';
$currentNav = 'seo-intelligence';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'AI Management', 'href' => ''],
    ['label' => 'SEO Intelligence', 'href' => cms_nav_href('seo-intelligence.php')],
    ['label' => 'Content Conflict Detection', 'href' => ''],
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
            <h2 class="section-title">Content Conflict Detection</h2>
            <p class="section-lead">Rekomendasi saja — tidak pernah merge/redirect otomatis.</p>
        </div>
        <div class="toolbar__right">
            <a class="admin-btn admin-btn--ghost" href="seo-intelligence.php">&larr; SEO Intelligence</a>
            <form method="post" action="content-conflict-detection.php" onsubmit="return confirm('Generate ulang deteksi konflik konten? Hasil generate sebelumnya akan diganti seluruhnya.');">
                <?= cms_csrf_field() ?>
                <input type="hidden" name="action" value="generate_conflicts">
                <button type="submit" class="admin-btn admin-btn--primary">Generate</button>
            </form>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Potensi Konflik Konten</h3>
            <span class="panel__meta"><?= count($conflicts) ?> ditemukan</span>
        </div>

        <?php if ($conflicts === []) : ?>
            <p class="muted" style="padding:0 20px 20px;">Belum ada konflik konten terdeteksi — klik "Generate" untuk analisis pertama.</p>
        <?php endif; ?>

        <?php foreach ($conflicts as $conflict) : ?>
            <?php
            $riskLabel = ['low' => 'Rendah', 'medium' => 'Sedang', 'high' => 'Tinggi'][$conflict['risk']] ?? $conflict['risk'];
            $riskPillClass = ['low' => 'pill--muted', 'medium' => 'pill--accent', 'high' => 'pill--warn'][$conflict['risk']] ?? 'pill--muted';
            $isRequested = $conflict['status'] === 'proposal_requested';
            ?>
            <div style="margin:0 20px 20px;padding:16px;border:1px solid var(--border-color, #2a2f3a);border-radius:8px;">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
                    <div>
                        <?php if ($conflict['page_a_id']) : ?>
                            <a href="pages.php?edit=<?= (int) $conflict['page_a_id'] ?>"><?= cms_esc((string) ($conflict['page_a_title'] ?? $conflict['page_a_slug'])) ?></a>
                        <?php else : ?>
                            <span class="muted">Artikel A tidak ditemukan</span>
                        <?php endif; ?>
                        <span class="muted"> vs </span>
                        <?php if ($conflict['page_b_id']) : ?>
                            <a href="pages.php?edit=<?= (int) $conflict['page_b_id'] ?>"><?= cms_esc((string) ($conflict['page_b_title'] ?? $conflict['page_b_slug'])) ?></a>
                        <?php else : ?>
                            <span class="muted">Artikel B tidak ditemukan</span>
                        <?php endif; ?>
                    </div>
                    <span class="pill <?= cms_esc($riskPillClass) ?>">Risiko <?= cms_esc($riskLabel) ?></span>
                </div>

                <p style="margin:12px 0 4px;font-size:13px;" class="muted">Masalah:</p>
                <p style="margin:0;"><?= cms_esc((string) $conflict['issue_text']) ?></p>

                <p style="margin:12px 0 4px;font-size:13px;" class="muted">Rekomendasi:</p>
                <p style="margin:0;"><?= cms_esc((string) $conflict['recommendation_text']) ?></p>

                <div style="display:flex;gap:10px;margin-top:16px;">
                    <?php if ($conflict['page_a_id']) : ?>
                        <a class="admin-btn admin-btn--ghost admin-btn--sm" href="pages.php?edit=<?= (int) $conflict['page_a_id'] ?>">Buka Artikel A</a>
                    <?php endif; ?>
                    <?php if ($conflict['page_b_id']) : ?>
                        <a class="admin-btn admin-btn--ghost admin-btn--sm" href="pages.php?edit=<?= (int) $conflict['page_b_id'] ?>">Buka Artikel B</a>
                    <?php endif; ?>
                    <form method="post" action="content-conflict-detection.php" style="margin:0;">
                        <?= cms_csrf_field() ?>
                        <input type="hidden" name="action" value="request_conflict_proposal">
                        <input type="hidden" name="conflict_id" value="<?= (int) $conflict['id'] ?>">
                        <button type="submit" class="admin-btn admin-btn--primary admin-btn--sm" <?= $isRequested ? 'disabled' : '' ?>>
                            <?= $isRequested ? 'Proposal Sudah Diajukan' : 'Buat Proposal Konflik' ?>
                        </button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php
require dirname(__DIR__) . '/includes/footer.php';
