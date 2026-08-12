<?php
declare(strict_types=1);

/**
 * "SEO Intelligence" — Topic Cluster dashboard. Full-recompute AI analysis
 * over the 50 most recent published articles (see
 * cms_growth_agent_generate_topic_clusters() in
 * includes/growth-agent-service.php) — manual trigger only, no cron/lazy
 * refresh. Clicking "Generate Saran Artikel" on a missing topic never
 * calls the AI itself, it only queues a 'topic_gap_article' job
 * (growth_agent_jobs, status='manual_action') reviewed via the generic
 * Approve/Reject buttons on growth-agent.php — approving it is what
 * actually creates the draft article
 * (cms_growth_agent_create_article_draft_from_topic_gap()).
 *
 * Content Conflict Detection lives on its own page
 * (content-conflict-detection.php), linked from here — same "no sidebar
 * entry of its own" pattern as indexing-issue-review.php/
 * cannibalization-review.php.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';
require_once dirname(__DIR__) . '/includes/growth-agent-service.php';

cms_require_role(['superadmin', 'admin']);

cms_growth_agent_ensure_schema($pdo);
cms_growth_agent_seo_intel_ensure_schema($pdo);

$selfUrl = 'seo-intelligence.php';

$redirect = static function (string $message, string $type = 'success') use ($selfUrl): void {
    $_SESSION['cms_flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . $selfUrl, true, 302);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // cms_verify_csrf() already ran for every POST in includes/auth.php.
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'generate_clusters') {
        $result = cms_growth_agent_generate_topic_clusters($pdo);
        if ($result['ok']) {
            $redirect($result['clusters_created'] . ' topic cluster berhasil dibuat dari 50 artikel published terbaru.');
        }
        $redirect('Gagal generate topic cluster: ' . $result['error'], 'error');
    }

    if ($action === 'request_topic_gap_article') {
        $clusterId = (int) ($_POST['cluster_id'] ?? 0);
        $missingTopic = trim((string) ($_POST['missing_topic'] ?? ''));
        if ($clusterId <= 0 || $missingTopic === '') {
            $redirect('Cluster atau topik tidak valid.', 'error');
        }
        $jobId = cms_growth_agent_request_topic_gap_article($pdo, $clusterId, $missingTopic);
        if ($jobId > 0) {
            $redirect('Saran artikel untuk topik "' . $missingTopic . '" berhasil diajukan — cek di "Job Terbaru" pada Growth Agent untuk approve.');
        }
        $redirect('Gagal mengajukan saran artikel.', 'error');
    }

    // Keyword Expansion Agent (GROWTH_AGENT_V2_PROPOSAL.md Fase B item 2,
    // 4 Agu 2026) — lives on THIS page, not growth-agent.php, because it's
    // the same shape as "Generate Cluster" right above: one AI call over
    // the site's recently-published articles, manual trigger only. This
    // is the one AI-driven discovery surface on this page that ISN'T tied
    // to an existing topic cluster — it proposes brand-new topics the site
    // hasn't touched at all, using GSC history for nothing (unlike the
    // Opportunity Engine on growth-agent.php, which only ever sees queries
    // the site already has impressions for). Each proposed topic becomes
    // one 'keyword_expansion_topic' manual_action job (see
    // cms_growth_agent_scan_keyword_expansion() in growth-agent-service.php),
    // reviewed via the generic Approve/Reject buttons on growth-agent.php —
    // approving one creates a draft article, exactly like topic_gap_article.
    if ($action === 'generate_keyword_expansion') {
        $result = cms_growth_agent_scan_keyword_expansion($pdo);
        if ($result['ok']) {
            $redirect($result['topics_proposed'] . ' topik artikel baru diusulkan — cek di "Job Terbaru" pada Growth Agent untuk approve.');
        }
        $redirect('Gagal generate keyword expansion: ' . $result['error'], 'error');
    }

    $redirect('Aksi tidak dikenal.', 'error');
}

$clustersStmt = $pdo->query(
    "SELECT c.id, c.cluster_name, c.pillar_page_id, c.supporting_page_ids, c.status,
            c.missing_content_json, c.generated_at, c.model_used,
            p.title AS pillar_title, p.slug AS pillar_slug
       FROM growth_agent_topic_clusters c
       LEFT JOIN pages p ON p.page_id = c.pillar_page_id
      ORDER BY c.status DESC, c.cluster_name ASC"
);
$clusters = $clustersStmt->fetchAll();

// Resolve every supporting_page_ids JSON array in one batch query instead
// of N+1 per cluster.
$allSupportingIds = [];
foreach ($clusters as $cluster) {
    $ids = json_decode((string) $cluster['supporting_page_ids'], true);
    if (is_array($ids)) {
        foreach ($ids as $id) {
            $allSupportingIds[(int) $id] = true;
        }
    }
}
$supportingPagesById = [];
if ($allSupportingIds !== []) {
    $ids = array_keys($allSupportingIds);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $supStmt = $pdo->prepare("SELECT page_id, title, slug FROM pages WHERE page_id IN ({$placeholders})");
    $supStmt->execute($ids);
    foreach ($supStmt->fetchAll() as $row) {
        $supportingPagesById[(int) $row['page_id']] = $row;
    }
}

$pageTitle = 'SEO Intelligence';
$currentNav = 'seo-intelligence';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'AI Management', 'href' => ''],
    ['label' => 'SEO Intelligence', 'href' => ''],
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
            <h2 class="section-title">SEO Intelligence</h2>
            <p class="section-lead">Topic cluster dari 50 artikel published terbaru — trigger manual, tidak ada refresh otomatis.</p>
        </div>
        <div class="toolbar__right">
            <a class="admin-btn admin-btn--ghost" href="content-conflict-detection.php">Content Conflict Detection &rarr;</a>
            <form method="post" action="seo-intelligence.php">
                <?= cms_csrf_field() ?>
                <input type="hidden" name="action" value="generate_keyword_expansion">
                <button type="submit" class="admin-btn admin-btn--secondary">Keyword Expansion</button>
            </form>
            <form method="post" action="seo-intelligence.php" onsubmit="return confirm('Generate ulang topic cluster? Hasil generate sebelumnya akan diganti seluruhnya.');">
                <?= cms_csrf_field() ?>
                <input type="hidden" name="action" value="generate_clusters">
                <button type="submit" class="admin-btn admin-btn--primary">Generate Cluster</button>
            </form>
        </div>
    </div>
    <p class="section-lead" style="margin-top:-8px;">Keyword Expansion mengusulkan topik artikel BARU yang belum pernah ditulis situs ini (maks. 5 topik per klik, satu panggilan AI) — beda dari Topic Cluster yang menganalisis cakupan artikel yang sudah ada. Tiap topik lewat SEO-G0 Gate dulu sebelum jadi usulan, dan tidak ada yang dibuat/dipublish sampai Anda approve di Growth Agent.</p>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Topic Cluster</h3>
            <span class="panel__meta"><?= count($clusters) ?> cluster</span>
        </div>

        <?php if ($clusters === []) : ?>
            <p class="muted" style="padding:0 20px 20px;">Belum ada topic cluster — klik "Generate Cluster" untuk analisis pertama.</p>
        <?php endif; ?>

        <?php foreach ($clusters as $cluster) : ?>
            <?php
            $supportingIds = json_decode((string) $cluster['supporting_page_ids'], true);
            $supportingIds = is_array($supportingIds) ? $supportingIds : [];
            $missingTopics = json_decode((string) $cluster['missing_content_json'], true);
            $missingTopics = is_array($missingTopics) ? $missingTopics : [];
            $isNeedsMore = $cluster['status'] === 'needs_more_content';
            ?>
            <div style="margin:0 20px 20px;padding:16px;border:1px solid var(--border-color, #2a2f3a);border-radius:8px;">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
                    <strong><?= cms_esc((string) $cluster['cluster_name']) ?></strong>
                    <?php if ($isNeedsMore) : ?>
                        <span class="pill pill--warn">Perlu Konten Tambahan</span>
                    <?php else : ?>
                        <span class="pill pill--ok">Coverage Baik</span>
                    <?php endif; ?>
                </div>

                <p style="margin:10px 0 4px;font-size:13px;" class="muted">Pillar:</p>
                <?php if ($cluster['pillar_page_id']) : ?>
                    <a href="pages.php?edit=<?= (int) $cluster['pillar_page_id'] ?>"><?= cms_esc((string) ($cluster['pillar_title'] ?? $cluster['pillar_slug'])) ?></a>
                <?php else : ?>
                    <span class="muted">Tidak ditemukan</span>
                <?php endif; ?>

                <p style="margin:14px 0 4px;font-size:13px;" class="muted">Artikel Terkait:</p>
                <?php if ($supportingIds === []) : ?>
                    <span class="muted">Belum ada artikel pendukung.</span>
                <?php else : ?>
                    <ul style="margin:0;padding-left:20px;line-height:1.7;">
                        <?php foreach ($supportingIds as $supId) : ?>
                            <?php $supPage = $supportingPagesById[(int) $supId] ?? null; ?>
                            <li>
                                <?php if ($supPage) : ?>
                                    <a href="pages.php?edit=<?= (int) $supPage['page_id'] ?>"><?= cms_esc((string) $supPage['title']) ?></a>
                                <?php else : ?>
                                    <span class="muted">Artikel #<?= (int) $supId ?> (tidak ditemukan)</span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if ($isNeedsMore && $missingTopics !== []) : ?>
                    <p style="margin:14px 0 4px;font-size:13px;" class="muted">Konten yang Kurang:</p>
                    <ul style="margin:0;padding-left:0;list-style:none;">
                        <?php foreach ($missingTopics as $mt) : ?>
                            <?php $topic = (string) ($mt['topic'] ?? ''); ?>
                            <?php if ($topic === '') continue; ?>
                            <li style="display:flex;justify-content:space-between;align-items:center;gap:12px;padding:8px 0;border-top:1px solid var(--border-color, #2a2f3a);">
                                <span><?= cms_esc($topic) ?></span>
                                <form method="post" action="seo-intelligence.php" style="margin:0;">
                                    <?= cms_csrf_field() ?>
                                    <input type="hidden" name="action" value="request_topic_gap_article">
                                    <input type="hidden" name="cluster_id" value="<?= (int) $cluster['id'] ?>">
                                    <input type="hidden" name="missing_topic" value="<?= cms_esc($topic) ?>">
                                    <button type="submit" class="admin-btn admin-btn--ghost admin-btn--sm">Generate Saran Artikel</button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <p class="muted" style="margin:0;padding:0 20px 20px;font-size:13px;">
            "Generate Saran Artikel" hanya mengajukan job review — draft artikel baru dibuat setelah job itu di-approve di halaman Growth Agent.
        </p>
    </div>
</section>
<?php
require dirname(__DIR__) . '/includes/footer.php';
