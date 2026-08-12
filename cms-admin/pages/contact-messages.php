<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';

// Site-wide configuration is admin-tier — see cms_require_role() in
// functions.php for the full tier breakdown.
cms_require_role(['superadmin', 'admin']);

$pageTitle = 'Contact Messages';
$currentNav = 'contact-messages';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'Contact Messages', 'href' => ''],
];

$selfUrl = 'contact-messages.php';

$cm_redirect = static function (string $message, string $type = 'success', ?string $query = null) use ($selfUrl): void {
    $_SESSION['cms_flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . $selfUrl . ($query ? '?' . $query : ''), true, 302);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete') {
        $deleteId = (int) ($_POST['id'] ?? 0);
        if ($deleteId <= 0) {
            $cm_redirect('Invalid message.', 'error');
        }
        $delete = $pdo->prepare('DELETE FROM contact_messages WHERE id = :id');
        $delete->execute(['id' => $deleteId]);
        if ($delete->rowCount() < 1) {
            $cm_redirect('Message not found or already deleted.', 'error');
        }
        $cm_redirect('Message deleted successfully.');
    }

    if ($action === 'toggle_read') {
        $msgId = (int) ($_POST['id'] ?? 0);
        $isRead = (int) ($_POST['is_read'] ?? 0) === 1 ? 1 : 0;
        if ($msgId <= 0) {
            $cm_redirect('Invalid message.', 'error');
        }
        $update = $pdo->prepare('UPDATE contact_messages SET is_read = :is_read WHERE id = :id');
        $update->execute(['is_read' => $isRead, 'id' => $msgId]);
        $cm_redirect($isRead === 1 ? 'Message marked as read.' : 'Message marked as unread.');
    }

    if ($action === 'mark_all_read') {
        $pdo->exec('UPDATE contact_messages SET is_read = 1');
        $cm_redirect('All messages marked as read.');
    }

    $cm_redirect('Unknown action.', 'error');
}

$alerts = [];
if (isset($_SESSION['cms_flash']) && is_array($_SESSION['cms_flash'])) {
    $alerts[] = $_SESSION['cms_flash'];
    unset($_SESSION['cms_flash']);
}

$viewId = isset($_GET['view']) ? (int) $_GET['view'] : 0;
$viewRow = null;

$messages = $pdo->query(
    'SELECT id, full_name, email, phone, subject, is_read, created_at,
            LEFT(message, 60) AS message_preview
     FROM contact_messages
     ORDER BY created_at DESC, id DESC'
)->fetchAll();

$unreadCount = 0;
foreach ($messages as $m) {
    if ((int) ($m['is_read'] ?? 0) === 0) {
        $unreadCount++;
    }
}

if ($viewId > 0) {
    $viewStmt = $pdo->prepare(
        'SELECT id, full_name, email, phone, subject, message, is_read, created_at
         FROM contact_messages WHERE id = :id LIMIT 1'
    );
    $viewStmt->execute(['id' => $viewId]);
    $viewRow = $viewStmt->fetch() ?: null;
    if ($viewRow === null) {
        $alerts[] = ['type' => 'error', 'message' => 'Message not found.'];
        $viewId = 0;
    }
}

$formatDt = static function (?string $value): string {
    if ($value === null || $value === '') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts !== false ? date('d M Y, H:i', $ts) : $value;
};

$val = static fn (array $row, string $key): string => (string) ($row[$key] ?? '');

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/sidebar.php';
require dirname(__DIR__) . '/includes/navbar.php';
require dirname(__DIR__) . '/includes/breadcrumb.php';
require dirname(__DIR__) . '/includes/alerts.php';
?>
<section class="admin-stack">
    <div class="toolbar">
        <div class="toolbar__left">
            <h2 class="section-title">Contact messages</h2>
            <p class="section-lead">Inbound form submissions — <?= (int) $unreadCount ?> unread.</p>
        </div>
        <div class="toolbar__right">
            <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>">
                <?= cms_csrf_field() ?>
                <input type="hidden" name="action" value="mark_all_read">
                <button type="submit" class="admin-btn admin-btn--secondary">Mark all read</button>
            </form>
        </div>
    </div>

    <div class="admin-grid admin-grid--2">
        <div class="panel">
            <div class="panel__head">
                <h3 class="panel__title">Inbox</h3>
                <span class="panel__meta"><?= count($messages) ?> message(s)</span>
            </div>
            <ul class="message-list message-list--spacious">
                <?php if ($messages === []) : ?>
                    <li class="message-list__item muted">No messages yet.</li>
                <?php endif; ?>
                <?php foreach ($messages as $msg) : ?>
                    <?php
                    $msgId = (int) $msg['id'];
                    $isUnread = (int) ($msg['is_read'] ?? 0) === 0;
                    $isActive = $viewId === $msgId;
                    ?>
                    <li class="message-list__item"<?= $isActive ? ' style="background: var(--row-active-bg);"' : '' ?>>
                        <div class="message-list__top">
                            <strong><?= cms_esc($val($msg, 'full_name')) ?></strong>
                            <?php if ($isUnread) : ?>
                                <span class="pill pill--accent">New</span>
                            <?php endif; ?>
                        </div>
                        <div class="message-list__sub"><?= cms_esc($val($msg, 'email')) ?> · <?= cms_esc($formatDt($msg['created_at'] ?? null)) ?></div>
                        <div class="message-list__subject"><?= cms_esc($val($msg, 'subject')) ?></div>
                        <p class="cell-clip muted" style="margin:6px 0 0;font-size:13px;"><?= cms_esc($val($msg, 'message_preview')) ?></p>
                        <div class="message-list__actions">
                            <a class="admin-btn admin-btn--sm admin-btn--secondary" href="<?= cms_esc($selfUrl) ?>?view=<?= $msgId ?>">View</a>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="panel" id="message-detail">
            <?php if ($viewRow) : ?>
                <div class="panel__head">
                    <h3 class="panel__title">Message detail</h3>
                    <a class="panel__link" href="<?= cms_esc($selfUrl) ?>">Close</a>
                </div>
                <div class="form-stack">
                    <div class="field">
                        <span class="info-label">From</span>
                        <p class="info-value"><?= cms_esc($val($viewRow, 'full_name')) ?></p>
                    </div>
                    <div class="field">
                        <span class="info-label">Email</span>
                        <p class="info-value"><?= cms_esc($val($viewRow, 'email')) ?></p>
                    </div>
                    <div class="field">
                        <span class="info-label">Phone</span>
                        <p class="info-value"><?= cms_esc($val($viewRow, 'phone') !== '' ? $val($viewRow, 'phone') : '—') ?></p>
                    </div>
                    <div class="field">
                        <span class="info-label">Subject</span>
                        <p class="info-value"><?= cms_esc($val($viewRow, 'subject') !== '' ? $val($viewRow, 'subject') : '—') ?></p>
                    </div>
                    <div class="field">
                        <span class="info-label">Received</span>
                        <p class="info-value"><?= cms_esc($formatDt($viewRow['created_at'] ?? null)) ?></p>
                    </div>
                    <div class="field">
                        <span class="info-label">Status</span>
                        <p class="info-value">
                            <span class="pill pill--<?= (int) ($viewRow['is_read'] ?? 0) === 1 ? 'ok' : 'accent' ?>">
                                <?= (int) ($viewRow['is_read'] ?? 0) === 1 ? 'Read' : 'Unread' ?>
                            </span>
                        </p>
                    </div>
                    <label class="field">Message
                        <textarea rows="8" readonly><?= cms_esc($val($viewRow, 'message')) ?></textarea>
                    </label>
                    <div class="form-grid__actions">
                        <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>?view=<?= (int) $viewRow['id'] ?>">
                            <?= cms_csrf_field() ?>
                            <input type="hidden" name="action" value="toggle_read">
                            <input type="hidden" name="id" value="<?= (int) $viewRow['id'] ?>">
                            <input type="hidden" name="is_read" value="<?= (int) ($viewRow['is_read'] ?? 0) === 1 ? '0' : '1' ?>">
                            <button type="submit" class="admin-btn admin-btn--secondary">
                                <?= (int) ($viewRow['is_read'] ?? 0) === 1 ? 'Mark unread' : 'Mark read' ?>
                            </button>
                        </form>
                        <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>" onsubmit="return confirm('Delete this message?');">
                            <?= cms_csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $viewRow['id'] ?>">
                            <button type="submit" class="admin-btn admin-btn--danger">Delete</button>
                        </form>
                    </div>
                </div>
            <?php else : ?>
                <div class="panel__head">
                    <h3 class="panel__title">Message detail</h3>
                </div>
                <p class="muted panel-intro">Select a message from the inbox to view details.</p>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php
require dirname(__DIR__) . '/includes/footer.php';
