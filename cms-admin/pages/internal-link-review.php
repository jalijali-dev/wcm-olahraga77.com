<?php
declare(strict_types=1);

/**
 * "Internal Linking Agent" — review/apply page for one
 * job_type='internal_link_suggestion' row created by
 * cms_growth_agent_scan_internal_links() (see includes/growth-agent-service.php).
 *
 * Deliberately a separate page from the generic Approve/Reject buttons on
 * growth-agent.php, for two reasons:
 *   1. Applying here is one of only two actions in the whole Growth Agent
 *      feature that writes back into `pages` (the other being "Apply SEO
 *      Recommendation") — it writes ONLY `content` (via a DOM-safe single
 *      link insertion, never `status`), never anything else.
 *   2. The operator needs to actually SEE the anchor text and the sentence
 *      around it before deciding — the generic Approve/Reject buttons have
 *      no room for that, and approving blind on a content change is not
 *      an acceptable trade for convenience.
 *
 * The insertion itself is re-derived fresh against the article's CURRENT
 * content on Apply (cms_growth_agent_apply_internal_link()), not the
 * scan-time snapshot shown below — if the article was edited since the
 * scan, Apply will say so and refuse rather than insert somewhere
 * different than what was reviewed here.
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
    $redirectBack('Job usulan link internal tidak valid.', 'error');
}

$jobStmt = $pdo->prepare(
    "SELECT j.id, j.status, j.page_id, j.input_brief, j.output_json, j.created_at,
            p.title AS source_title, p.slug AS source_slug
       FROM growth_agent_jobs j
       LEFT JOIN pages p ON p.page_id = j.page_id
      WHERE j.id = :id AND j.job_type = 'internal_link_suggestion'
      LIMIT 1"
);
$jobStmt->execute(['id' => $jobId]);
$job = $jobStmt->fetch();

if (!$job) {
    $redirectBack('Job usulan link internal tidak ditemukan.', 'error');
}

$brief = json_decode((string) ($job['input_brief'] ?? ''), true);
if (!is_array($brief)) {
    $redirectBack('Data usulan (input_brief) rusak.', 'error');
}

$targetPageId = (int) ($brief['target_page_id'] ?? 0);
$targetStmt = $pdo->prepare('SELECT page_id, title, slug, status FROM pages WHERE page_id = :id LIMIT 1');
$targetStmt->execute(['id' => $targetPageId]);
$targetPage = $targetStmt->fetch();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // cms_verify_csrf() already ran for every POST in includes/auth.php.
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'apply') {
        if ($job['status'] !== 'manual_action') {
            $redirectBack('Usulan ini sudah pernah diproses sebelumnya.', 'error');
        }
        $result = cms_growth_agent_apply_internal_link($pdo, $jobId);
        if ($result['ok']) {
            $redirectBack('Link internal berhasil ditambahkan ke artikel — konten lama tersimpan di riwayat job ini kalau perlu dipulihkan manual.');
        }
        $redirectBack('Gagal menerapkan: ' . $result['error'], 'error');
    }

    if ($action === 'reject') {
        if ($job['status'] !== 'manual_action') {
            $redirectBack('Usulan ini sudah pernah diproses sebelumnya.', 'error');
        }
        $currentAdminId = (int) ($_SESSION['cms_admin_id'] ?? 0) ?: null;
        $pdo->prepare(
            'INSERT INTO growth_agent_feedback (job_id, action, reviewed_by, created_at) VALUES (:job_id, :action, :reviewed_by, NOW())'
        )->execute(['job_id' => $jobId, 'action' => 'rejected', 'reviewed_by' => $currentAdminId]);

        $pdo->prepare("UPDATE growth_agent_jobs SET status = 'failed', updated_at = NOW() WHERE id = :id")
            ->execute(['id' => $jobId]);

        $redirectBack('Usulan link ditolak — tidak ada perubahan pada artikel.');
    }

    $redirectBack('Aksi tidak dikenal.', 'error');
}

$pageTitle = 'Review Link Internal';
$currentNav = 'growth-agent';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'AI Management', 'href' => ''],
    ['label' => 'Growth Agent', 'href' => cms_nav_href('growth-agent.php')],
    ['label' => 'Review Link Internal', 'href' => ''],
];

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/sidebar.php';
require dirname(__DIR__) . '/includes/navbar.php';
require dirname(__DIR__) . '/includes/breadcrumb.php';
require dirname(__DIR__) . '/includes/alerts.php';

$anchorText = (string) ($brief['anchor_text'] ?? '');
$context = (string) ($brief['context'] ?? '');
$similarity = (string) ($brief['similarity'] ?? '');

// Highlight the anchor text inside the context snippet for display only —
// this is presentation, NOT the actual insertion logic (that's
// cms_growth_agent_il_insert_link(), which uses DOMDocument on the real
// article content). cms_esc() first, then wrap the already-escaped anchor
// text with a <mark> — safe because both sides of the str_replace are
// escaped the same way, so no raw HTML from the article can leak through.
$contextHtml = cms_esc($context);
if ($anchorText !== '') {
    $escapedAnchor = cms_esc($anchorText);
    $contextHtml = str_replace($escapedAnchor, '<mark>' . $escapedAnchor . '</mark>', $contextHtml);
}
?>
<section class="admin-stack">
    <div class="toolbar">
        <div class="toolbar__left">
            <h2 class="section-title">Review Link Internal</h2>
            <p class="section-lead">Cek anchor text &amp; kalimat sekitarnya, lalu Apply atau Reject. Apply hanya menyisipkan SATU link ke dalam konten artikel sumber — tidak ada bagian lain yang berubah.</p>
        </div>
        <div class="toolbar__right">
            <a class="admin-btn admin-btn--ghost" href="growth-agent.php">&larr; Kembali</a>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Usulan</h3>
        </div>
        <table class="admin-table">
            <tbody>
                <tr><td class="muted" style="width:180px;">Job</td><td>Internal Link Suggestion — job #<?= (int) $job['id'] ?></td></tr>
                <tr>
                    <td class="muted">Artikel sumber (yang akan diedit)</td>
                    <td>
                        <?= $job['source_title'] ? cms_esc((string) $job['source_title']) : '<span class="muted">Artikel tidak ditemukan</span>' ?>
                        <?php if (!empty($job['page_id'])) : ?>
                            &nbsp;<a class="admin-btn admin-btn--sm admin-btn--ghost" href="pages.php?edit=<?= (int) $job['page_id'] ?>" target="_blank" rel="noopener">Buka artikel</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td class="muted">Artikel tujuan (yang akan di-link)</td>
                    <td>
                        <?= $targetPage ? cms_esc((string) $targetPage['title']) : '<span class="muted">Artikel tidak ditemukan — mungkin sudah dihapus.</span>' ?>
                        <?php if ($targetPage) : ?>
                            &nbsp;<a class="admin-btn admin-btn--sm admin-btn--ghost" href="pages.php?edit=<?= (int) $targetPage['page_id'] ?>" target="_blank" rel="noopener">Buka artikel</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr><td class="muted">Anchor text yang diusulkan</td><td><strong><?= cms_esc($anchorText) ?></strong></td></tr>
                <tr><td class="muted">Skor kemiripan topik</td><td class="muted"><?= cms_esc($similarity) ?></td></tr>
                <tr><td class="muted">Dibuat</td><td class="muted"><?= cms_esc((string) $job['created_at']) ?></td></tr>
            </tbody>
        </table>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Konteks (dari artikel sumber, saat scan dijalankan)</h3>
        </div>
        <div style="padding:16px 20px;font-size:14px;line-height:1.7;">
            <?= $contextHtml !== '' ? $contextHtml : '<span class="muted">(tidak ada cuplikan konteks)</span>' ?>
        </div>
        <p class="muted" style="padding:0 20px 16px;font-size:13px;">
            Anchor text yang ditandai <mark>seperti ini</mark> akan dibungkus <code>&lt;a href&gt;</code> menuju artikel tujuan.
            Apply menyisipkan ulang berdasarkan konten artikel yang <strong>terkini</strong> (bukan cuplikan di atas) —
            kalau artikel sudah diedit sejak scan ini dijalankan dan kalimatnya sudah berubah, Apply akan gagal dengan aman
            (tidak ada yang diterapkan) daripada menyisipkan ke tempat yang salah.
        </p>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Keputusan</h3>
        </div>
        <p class="muted" style="padding:0 20px;font-size:13px;">
            Konten lama artikel sumber otomatis disimpan penuh ke riwayat job ini sebelum diubah — CMS ini tidak punya
            sistem revisi artikel, jadi ini satu-satunya jalan pulang kalau hasilnya ternyata tidak diinginkan.
        </p>
        <div style="display:flex;gap:10px;padding:16px 20px;">
            <form method="post" action="internal-link-review.php?job_id=<?= (int) $job['id'] ?>">
                <?= cms_csrf_field() ?>
                <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                <input type="hidden" name="action" value="apply">
                <button type="submit" class="admin-btn admin-btn--primary" <?= ($job['page_id'] && $targetPage) ? '' : 'disabled' ?>>Apply</button>
            </form>
            <form method="post" action="internal-link-review.php?job_id=<?= (int) $job['id'] ?>">
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
