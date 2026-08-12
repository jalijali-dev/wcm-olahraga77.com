<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';

// Site-wide configuration is admin-tier — see cms_require_role() in
// functions.php for the full tier breakdown.
cms_require_role(['superadmin', 'admin']);

$promptLoaderPath = dirname(__DIR__, 2) . '/services/PromptLoader.php';
if (!file_exists($promptLoaderPath)) {
    die('PromptLoader.php tidak ditemukan. Jalankan installer/migration atau cek folder services.');
}
require_once $promptLoaderPath;

$pageTitle  = 'Prompt Control';
$currentNav = 'prompt-control';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'Prompt Control', 'href' => ''],
];

// ── Filters ──────────────────────────────────────────────────────────────────
$filterKey    = in_array($_GET['agent_key'] ?? '', PromptLoader::ALLOWED_AGENT_KEYS, true)
    ? (string) $_GET['agent_key']
    : '';
$filterStatus = in_array($_GET['status'] ?? '', ['draft', 'active', 'archived'], true)
    ? (string) $_GET['status']
    : '';

// ── Load rows ─────────────────────────────────────────────────────────────────
$where  = [];
$params = [];

if ($filterKey !== '') {
    $where[]           = 'agent_key = :agent_key';
    $params['agent_key'] = $filterKey;
}
if ($filterStatus !== '') {
    $where[]           = 'status = :status';
    $params['status']  = $filterStatus;
}

$sql = 'SELECT id, agent_key, prompt_type, title, version, status, created_by,
               activated_at, archived_at, created_at, updated_at
          FROM agent_prompts'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY agent_key ASC, prompt_type ASC, version DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Flash ─────────────────────────────────────────────────────────────────────
$alerts = [];
if (isset($_SESSION['cms_flash']) && is_array($_SESSION['cms_flash'])) {
    $alerts[] = $_SESSION['cms_flash'];
    unset($_SESSION['cms_flash']);
}

// ── Status pill styling ────────────────────────────────────────────────────────
$pillClass = [
    'draft'    => 'pill--accent',
    'active'   => 'pill--ok',
    'archived' => 'pill--muted',
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
            <h2 class="section-title">Prompt Control</h2>
            <p class="section-lead">Manage AI prompt content stored in the database. Active prompts override PHP fallbacks.</p>
        </div>
        <div class="toolbar__right">
            <a class="admin-btn admin-btn--primary" href="prompt-control-create.php">+ New Prompt</a>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Filter</h3>
        </div>
        <form method="get" action="prompt-control.php" class="form-stack form-stack--row">
            <label class="field">Agent
                <select name="agent_key">
                    <option value="">All Agents</option>
                    <?php foreach (PromptLoader::ALLOWED_AGENT_KEYS as $key): ?>
                        <option value="<?= cms_esc($key) ?>"<?= $filterKey === $key ? ' selected' : '' ?>><?= cms_esc($key) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">Status
                <select name="status">
                    <option value="">All Statuses</option>
                    <?php foreach (['draft', 'active', 'archived'] as $s): ?>
                        <option value="<?= cms_esc($s) ?>"<?= $filterStatus === $s ? ' selected' : '' ?>><?= cms_esc(ucfirst($s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="field field--actions">
                <button type="submit" class="admin-btn admin-btn--secondary">Filter</button>
                <?php if ($filterKey !== '' || $filterStatus !== ''): ?>
                    <a href="prompt-control.php" class="admin-btn admin-btn--ghost">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Prompts</h3>
            <span class="panel__meta"><?= count($rows) ?> row(s)<?= ($filterKey !== '' || $filterStatus !== '') ? ' · filtered' : '' ?></span>
        </div>

        <?php if (empty($rows)): ?>
            <p class="muted" style="padding:16px 0;">No prompts found. <a href="prompt-control-create.php">Create the first one.</a></p>
        <?php else: ?>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Agent</th>
                        <th>Type</th>
                        <th>Title</th>
                        <th>Ver</th>
                        <th>Status</th>
                        <th>Created by</th>
                        <th>Updated</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><code><?= cms_esc($row['agent_key']) ?></code></td>
                        <td><code><?= cms_esc($row['prompt_type']) ?></code></td>
                        <td><?= cms_esc($row['title']) ?></td>
                        <td><?= (int) $row['version'] ?></td>
                        <td>
                            <span class="pill <?= cms_esc($pillClass[$row['status']] ?? 'pill--muted') ?>">
                                <?= cms_esc(ucfirst($row['status'])) ?>
                            </span>
                        </td>
                        <td><?= cms_esc($row['created_by']) ?></td>
                        <td><?= cms_esc(substr((string) $row['updated_at'], 0, 10)) ?></td>
                        <td class="table-actions">
                            <?php if ($row['status'] === 'draft'): ?>
                                <a href="prompt-control-activate.php?id=<?= (int) $row['id'] ?>"
                                   class="admin-btn admin-btn--sm admin-btn--primary">Activate</a>
                                <a href="prompt-control-edit.php?id=<?= (int) $row['id'] ?>"
                                   class="admin-btn admin-btn--sm admin-btn--secondary">New Version</a>
                                <form class="inline-form" method="post" action="prompt-control-archive.php"
                                      onsubmit="return confirm('Archive this draft?');">
                                    <?= cms_csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                    <button type="submit" class="admin-btn admin-btn--sm admin-btn--danger">Archive</button>
                                </form>
                                <form class="inline-form" method="post" action="prompt-control-delete.php"
                                      onsubmit="return confirm('Delete this draft permanently? This cannot be undone.');">
                                    <?= cms_csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                    <button type="submit" class="admin-btn admin-btn--sm admin-btn--danger">Delete</button>
                                </form>
                            <?php elseif ($row['status'] === 'active'): ?>
                                <a href="prompt-control-edit.php?id=<?= (int) $row['id'] ?>"
                                   class="admin-btn admin-btn--sm admin-btn--secondary">New Version</a>
                                <span class="muted" style="font-size:12px;" title="Active prompts cannot be edited directly. Use New Version to create a draft.">Live</span>
                            <?php else: ?>
                                <a href="prompt-control-edit.php?id=<?= (int) $row['id'] ?>"
                                   class="admin-btn admin-btn--sm admin-btn--secondary">Clone</a>
                                <form class="inline-form" method="post" action="prompt-control-delete.php"
                                      onsubmit="return confirm('Delete this archived prompt permanently? This cannot be undone.');">
                                    <?= cms_csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                    <button type="submit" class="admin-btn admin-btn--sm admin-btn--danger">Delete</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Merge order (reference)</h3>
        </div>
        <p class="muted" style="padding:0 0 16px;">
            For each agent: <code>global/base</code> → <code>global/guardrail</code> →
            <code>{agent}/guardrail</code> → <code>{agent}/instruction</code> →
            <code>{agent}/output_schema</code> → runtime context (CMS article data).<br>
            Missing layers are skipped. If no DB <code>instruction</code> exists for an agent,
            the PHP fallback prompt file is used and production behavior is unchanged.
        </p>
    </div>
</section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
