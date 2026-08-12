<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';

// Admin user management (create/edit/delete accounts, assign roles) is
// superadmin-only — see cms_require_role() docblock in functions.php for
// the full tier breakdown.
cms_require_role(['superadmin']);

$pageTitle = 'Admin Users';
$currentNav = 'admins';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'Admin Users', 'href' => ''],
];

$alerts = [];
if (isset($_SESSION['cms_flash']) && is_array($_SESSION['cms_flash'])) {
    $alerts[] = $_SESSION['cms_flash'];
    unset($_SESSION['cms_flash']);
}

$currentAdminId = (int) ($_SESSION['cms_admin_id'] ?? 0);
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editAdmin = null;

// Values MUST match the `admins.role` DB enum exactly (lowercase, no
// spaces) — the labels shown to the admin can be friendlier. Previously
// this list held ['Super Admin', 'Editor', 'Admin'] (title case, with a
// space in "Super Admin"), which is not a valid value for that enum column
// at all — creating/editing an admin's role through this form would have
// silently failed to save the intended role. Fixed 15 Jul 2026.
$roleOptions = [
    'superadmin' => 'Super Admin',
    'admin'      => 'Admin',
    'editor'     => 'Editor',
];

$stmt = $pdo->query(
    'SELECT admin_id, name, email, role, is_active, created_at, updated_at
     FROM admins
     ORDER BY created_at DESC'
);
$admins = $stmt->fetchAll();

if ($editId > 0) {
    $editStmt = $pdo->prepare(
        'SELECT admin_id, name, email, role, is_active
         FROM admins
         WHERE admin_id = :admin_id
         LIMIT 1'
    );
    $editStmt->execute(['admin_id' => $editId]);
    $editAdmin = $editStmt->fetch() ?: null;
    if ($editAdmin === null) {
        $alerts[] = ['type' => 'error', 'message' => 'Admin user not found.'];
        $editId = 0;
    }
}

$formatDt = static function (?string $value): string {
    if ($value === null || $value === '') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts !== false ? date('d M Y, H:i', $ts) : $value;
};

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/sidebar.php';
require dirname(__DIR__) . '/includes/navbar.php';
require dirname(__DIR__) . '/includes/breadcrumb.php';
require dirname(__DIR__) . '/includes/alerts.php';
?>
<section class="admin-stack">
    <div class="toolbar">
        <div class="toolbar__left">
            <h2 class="section-title">Admin users</h2>
            <p class="section-lead">Manage CMS login accounts, roles, and active status.</p>
        </div>
    </div>

    <?php if ($editAdmin) : ?>
    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Edit admin</h3>
            <a class="panel__link" href="admins.php">Cancel edit</a>
        </div>
        <form class="form-grid" method="post" action="../actions/admins-update.php">
            <?= cms_csrf_field() ?>
            <input type="hidden" name="admin_id" value="<?= (int) $editAdmin['admin_id'] ?>">
            <label class="field">Name<input type="text" name="name" value="<?= cms_esc($editAdmin['name']) ?>" required></label>
            <label class="field">Email<input type="email" name="email" value="<?= cms_esc($editAdmin['email']) ?>" required></label>
            <label class="field">New password <span class="muted">(leave blank to keep current)</span><input type="password" name="password" autocomplete="new-password" placeholder="••••••••"></label>
            <?php
            $editRoleOptions = $roleOptions;
            if (!array_key_exists((string) $editAdmin['role'], $editRoleOptions)) {
                // Unknown/legacy value already in the DB — show it as-is so
                // saving without touching this field doesn't silently change it.
                $editRoleOptions = [(string) $editAdmin['role'] => (string) $editAdmin['role']] + $editRoleOptions;
            }
            ?>
            <label class="field">Role
                <select name="role" required>
                    <?php foreach ($editRoleOptions as $value => $label) : ?>
                        <option value="<?= cms_esc($value) ?>"<?= ($editAdmin['role'] === $value) ? ' selected' : '' ?>><?= cms_esc($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">Status
                <select name="is_active" required>
                    <option value="1"<?= (int) $editAdmin['is_active'] === 1 ? ' selected' : '' ?>>Active</option>
                    <option value="0"<?= (int) $editAdmin['is_active'] === 0 ? ' selected' : '' ?>>Inactive</option>
                </select>
            </label>
            <div class="form-grid__actions">
                <button type="submit" class="admin-btn admin-btn--primary">Save changes</button>
                <a class="admin-btn admin-btn--secondary" href="admins.php">Cancel</a>
            </div>
        </form>
    </div>
    <?php else : ?>
    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Add admin</h3>
        </div>
        <form class="form-grid" method="post" action="../actions/admins-store.php">
            <?= cms_csrf_field() ?>
            <label class="field">Name<input type="text" name="name" required></label>
            <label class="field">Email<input type="email" name="email" required></label>
            <label class="field">Password<input type="password" name="password" required autocomplete="new-password"></label>
            <label class="field">Role
                <select name="role" required>
                    <?php foreach ($roleOptions as $value => $label) : ?>
                        <option value="<?= cms_esc($value) ?>"<?= $value === 'editor' ? ' selected' : '' ?>><?= cms_esc($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">Status
                <select name="is_active" required>
                    <option value="1" selected>Active</option>
                    <option value="0">Inactive</option>
                </select>
            </label>
            <div class="form-grid__actions">
                <button type="submit" class="admin-btn admin-btn--primary">Create admin</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">All admins</h3>
            <span class="panel__meta"><?= count($admins) ?> user(s)</span>
        </div>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Updated</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($admins === []) : ?>
                        <tr>
                            <td colspan="7" class="muted">No admin users yet.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($admins as $row) : ?>
                        <?php
                        $rowId = (int) $row['admin_id'];
                        $isSelf = $rowId === $currentAdminId;
                        $active = (int) $row['is_active'] === 1;
                        ?>
                        <tr>
                            <td><?= cms_esc($row['name']) ?><?= $isSelf ? ' <span class="pill pill--muted">You</span>' : '' ?></td>
                            <td><?= cms_esc($row['email']) ?></td>
                            <td><span class="pill pill--accent"><?= cms_esc($roleOptions[$row['role']] ?? $row['role']) ?></span></td>
                            <td><span class="pill pill--<?= $active ? 'ok' : 'muted' ?>"><?= $active ? 'Active' : 'Inactive' ?></span></td>
                            <td><?= cms_esc($formatDt($row['created_at'])) ?></td>
                            <td><?= cms_esc($formatDt($row['updated_at'])) ?></td>
                            <td class="table-actions">
                                <a class="admin-btn admin-btn--sm admin-btn--secondary" href="admins.php?edit=<?= $rowId ?>">Edit</a>
                                <?php if (!$isSelf) : ?>
                                <form class="inline-form" method="post" action="../actions/admins-delete.php" onsubmit="return confirm('Delete this admin user?');">
                                    <?= cms_csrf_field() ?>
                                    <input type="hidden" name="admin_id" value="<?= $rowId ?>">
                                    <button type="submit" class="admin-btn admin-btn--sm admin-btn--danger">Delete</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php
require dirname(__DIR__) . '/includes/footer.php';
