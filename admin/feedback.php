<?php
session_start();
date_default_timezone_set('Asia/Manila');

require_once '../includes/db.php';

/* =========================================================
   ADMIN ACCESS
========================================================= */
if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['user_role'] ?? '') !== 'admin'
) {
    header('Location: ../auth/login.php');
    exit;
}

/* Sign out Admin accounts that were archived while logged in. */
$activeAdminStmt = $pdo->prepare("
    SELECT id
    FROM admins
    WHERE id = ?
      AND is_archived = 0
    LIMIT 1
");
$activeAdminStmt->execute([(int)$_SESSION['user_id']]);

if (!$activeAdminStmt->fetch()) {
    $_SESSION = [];
    session_destroy();
    header('Location: ../auth/login.php');
    exit;
}

/* Same token the Admin dashboard uses for its feedback buttons. */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/* =========================================================
   FILTERS (GET)
========================================================= */
$feedbackView = ($_GET['view'] ?? 'inbox') === 'archived' ? 'archived' : 'inbox';

$feedbackRead = (string)($_GET['read'] ?? 'all');
if (!in_array($feedbackRead, ['all', 'unread', 'read'], true)) {
    $feedbackRead = 'all';
}

$feedbackPeriod = (string)($_GET['period'] ?? 'all');
if (!in_array($feedbackPeriod, ['all', 'today', 'week', 'month'], true)) {
    $feedbackPeriod = 'all';
}

$feedbackSearch = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($feedbackSearch) > 100) {
    $feedbackSearch = mb_substr($feedbackSearch, 0, 100);
}

$feedbackPage = max(1, (int)($_GET['page'] ?? 1));
$feedbackPerPage = 10;

function feedbackUrl(array $overrides = []): string
{
    $query = array_merge([
        'view' => $GLOBALS['feedbackView'],
        'read' => $GLOBALS['feedbackRead'],
        'period' => $GLOBALS['feedbackPeriod'],
        'q' => $GLOBALS['feedbackSearch'],
        'page' => $GLOBALS['feedbackPage'],
    ], $overrides);

    /* Leave defaults out of the address bar. */
    $defaults = ['view' => 'inbox', 'read' => 'all', 'period' => 'all', 'q' => '', 'page' => 1];

    foreach ($defaults as $key => $default) {
        if ((string)$query[$key] === (string)$default) {
            unset($query[$key]);
        }
    }

    return 'feedback.php' . ($query ? '?' . http_build_query($query) : '');
}

/* =========================================================
   ACTIONS (POST + CSRF), then back to the same filtered list
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $returnUrl = (string)($_POST['return'] ?? 'feedback.php');

    /* Only ever return to this page. */
    if (!preg_match('/^feedback\.php(\?[^\r\n]*)?$/', $returnUrl)) {
        $returnUrl = 'feedback.php';
    }

    if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        $_SESSION['feedback_flash'] = ['type' => 'danger', 'message' => 'Your session has expired. Please try again.'];
        header('Location: ' . $returnUrl);
        exit;
    }

    $action = (string)($_POST['feedback_action'] ?? '');
    $feedbackId = (int)($_POST['feedback_id'] ?? 0);

    $updates = [
        'read' => 'is_read = 1',
        'unread' => 'is_read = 0',
        'archive' => 'is_archived = 1, is_read = 1',
        'restore' => 'is_archived = 0',
    ];

    try {
        if ($action === 'read_all') {
            $pdo->exec("UPDATE order_feedback SET is_read = 1 WHERE is_read = 0 AND is_archived = 0");
            $_SESSION['feedback_flash'] = ['type' => 'success', 'message' => 'All feedback marked as read.'];
        } elseif (isset($updates[$action]) && $feedbackId > 0) {
            $pdo->prepare("UPDATE order_feedback SET {$updates[$action]} WHERE id = ?")
                ->execute([$feedbackId]);

            $_SESSION['feedback_flash'] = [
                'type' => 'success',
                'message' => [
                    'read' => 'Marked as read.',
                    'unread' => 'Marked as unread.',
                    'archive' => 'Feedback archived.',
                    'restore' => 'Feedback moved back to the inbox.',
                ][$action],
            ];
        }
    } catch (Throwable $e) {
        error_log('feedback.php action: ' . $e->getMessage());
        $_SESSION['feedback_flash'] = ['type' => 'danger', 'message' => 'The feedback could not be updated.'];
    }

    header('Location: ' . $returnUrl);
    exit;
}

$feedbackFlash = $_SESSION['feedback_flash'] ?? null;
unset($_SESSION['feedback_flash']);

/* =========================================================
   QUERY
========================================================= */
$where = ['f.is_archived = ?'];
$params = [$feedbackView === 'archived' ? 1 : 0];

if ($feedbackRead === 'unread') {
    $where[] = 'f.is_read = 0';
} elseif ($feedbackRead === 'read') {
    $where[] = 'f.is_read = 1';
}

switch ($feedbackPeriod) {
    case 'today':
        $where[] = 'DATE(f.created_at) = CURDATE()';
        break;
    case 'week':
        $where[] = 'f.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)';
        break;
    case 'month':
        $where[] = "f.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')";
        break;
}

if ($feedbackSearch !== '') {
    $where[] = "(
        o.order_number LIKE ?
        OR o.claim_number LIKE ?
        OR c.full_name LIKE ?
        OR o.customer_name LIKE ?
        OR f.message LIKE ?
    )";
    $like = '%' . $feedbackSearch . '%';
    array_push($params, $like, $like, $like, $like, $like);
}

$whereSql = 'WHERE ' . implode(' AND ', $where);
$fromSql = "
    FROM order_feedback f
    LEFT JOIN orders o ON o.id = f.order_id
    LEFT JOIN customers c ON c.id = f.customer_id
";

$feedbackRows = [];
$feedbackTotal = 0;
$tableMissing = false;
$inboxCount = 0;
$archivedCount = 0;
$unreadCount = 0;

try {
    $totalStmt = $pdo->prepare("SELECT COUNT(*) {$fromSql} {$whereSql}");
    $totalStmt->execute($params);
    $feedbackTotal = (int)$totalStmt->fetchColumn();

    $feedbackPages = max(1, (int)ceil($feedbackTotal / $feedbackPerPage));
    $feedbackPage = min($feedbackPage, $feedbackPages);
    $offset = ($feedbackPage - 1) * $feedbackPerPage;

    $listStmt = $pdo->prepare("
        SELECT
            f.id,
            f.order_id,
            f.customer_id,
            f.message,
            f.is_read,
            f.is_archived,
            f.created_at,
            o.order_number,
            o.claim_number,
            o.order_source,
            o.total_amount,
            o.closed_at,
            c.email AS customer_email,
            COALESCE(NULLIF(TRIM(c.contact_number), ''), NULLIF(TRIM(o.contact_number), '')) AS customer_phone,
            COALESCE(
                NULLIF(TRIM(c.full_name), ''),
                NULLIF(TRIM(o.customer_name), ''),
                'Guest'
            ) AS display_name
        {$fromSql}
        {$whereSql}
        ORDER BY f.is_read ASC, f.created_at DESC, f.id DESC
        LIMIT {$feedbackPerPage} OFFSET {$offset}
    ");
    $listStmt->execute($params);
    $feedbackRows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    $counts = $pdo->query("
        SELECT
            COALESCE(SUM(is_archived = 0), 0) AS inbox_count,
            COALESCE(SUM(is_archived = 1), 0) AS archived_count,
            COALESCE(SUM(is_archived = 0 AND is_read = 0), 0) AS unread_count
        FROM order_feedback
    ")->fetch(PDO::FETCH_ASSOC) ?: [];

    $inboxCount = (int)($counts['inbox_count'] ?? 0);
    $archivedCount = (int)($counts['archived_count'] ?? 0);
    $unreadCount = (int)($counts['unread_count'] ?? 0);
} catch (Throwable $e) {
    error_log('feedback.php list: ' . $e->getMessage());
    $tableMissing = true;
    $feedbackPages = 1;
}

$feedbackStart = $feedbackTotal > 0 ? (($feedbackPage - 1) * $feedbackPerPage) + 1 : 0;
$feedbackEnd = min($feedbackPage * $feedbackPerPage, $feedbackTotal);
$currentUrl = feedbackUrl();

$page_title = 'Customer Feedback';
$page_description = 'Read and manage feedback customers left on their completed orders.';

require_once '../includes/header.php';
?>

<link
    rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
>

<style>
    body {
        background: #F7F5F2;
    }

    .admin-feedback-page {
        min-height: 100vh;
    }

    .admin-main {
        min-width: 0;
    }

    .admin-content {
        padding: 24px;
    }

    .fb-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 14px;
        margin-bottom: 18px;
    }

    .fb-title {
        margin: 0;
        color: #4A3525;
        font-size: 1.4rem;
        font-weight: 700;
        letter-spacing: -.02em;
    }

    .fb-description {
        margin: 4px 0 0;
        color: #7B6D62;
        font-size: .8rem;
    }

    .fb-unread-pill {
        padding: 8px 12px;
        border: 2px solid #8B6A55;
        border-radius: 10px;
        background: #FFFFFF;
        color: #6F4E37;
        font-size: .78rem;
        font-weight: 600;
        white-space: nowrap;
    }

    /* Tabs */
    .fb-tabs {
        display: flex;
        gap: 8px;
        margin-bottom: 14px;
    }

    .fb-tab {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 9px 16px;
        border: 1px solid #B8A08A;
        border-radius: 10px;
        background: #FFFFFF;
        color: #6F4E37;
        font-size: .82rem;
        font-weight: 600;
        text-decoration: none;
    }

    .fb-tab:hover {
        background: #F7F1EA;
        color: #4A3525;
    }

    .fb-tab.active {
        border-color: #6F4E37;
        background: #6F4E37;
        color: #FFFFFF;
    }

    .fb-tab-count {
        min-width: 20px;
        padding: 1px 6px;
        border-radius: 999px;
        background: rgba(111, 78, 55, .12);
        font-size: .7rem;
        text-align: center;
    }

    .fb-tab.active .fb-tab-count {
        background: rgba(255, 255, 255, .22);
    }

    /* Panel */
    .fb-panel {
        border: 2px solid #6F4E37;
        border-radius: 12px;
        background: #FFFFFF;
        box-shadow: 0 3px 10px rgba(74, 53, 37, .04);
        overflow: hidden;
    }

    .fb-filters {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-end;
        gap: 10px 12px;
        padding: 14px 18px;
        border-bottom: 1px solid #D8C9BD;
        background: #FBF8F4;
    }

    .fb-filter {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .fb-filter label {
        color: #7B6D62;
        font-size: .66rem;
        font-weight: 600;
        letter-spacing: .35px;
        text-transform: uppercase;
    }

    .fb-filter select,
    .fb-filter input {
        min-height: 40px;
        padding: 7px 11px;
        border: 1px solid #C9B3A2;
        border-radius: 9px;
        background: #FFFFFF;
        color: #4A3525;
        font-size: .82rem;
    }

    .fb-filter select:focus,
    .fb-filter input:focus {
        outline: none;
        border-color: #6F4E37;
        box-shadow: 0 0 0 3px rgba(111, 78, 55, .12);
    }

    .fb-filter-search {
        flex: 1 1 240px;
    }

    .fb-filter-search input {
        width: 100%;
    }

    .fb-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        min-height: 40px;
        padding: 7px 14px;
        border: 1px solid #6F4E37;
        border-radius: 9px;
        background: #6F4E37;
        color: #FFFFFF;
        font-size: .8rem;
        font-weight: 600;
        text-decoration: none;
        cursor: pointer;
        white-space: nowrap;
    }

    .fb-btn:hover {
        border-color: #4A3525;
        background: #4A3525;
        color: #FFFFFF;
    }

    .fb-btn.outline {
        background: #FFFFFF;
        color: #6F4E37;
    }

    .fb-btn.outline:hover {
        background: #F7F1EA;
        color: #4A3525;
    }

    .fb-btn.small {
        min-height: 34px;
        padding: 5px 11px;
        font-size: .74rem;
    }

    .fb-list-bar {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 8px 12px;
        padding: 11px 18px;
        border-bottom: 1px solid #D8C9BD;
        color: #8A7D72;
        font-size: .76rem;
    }

    .fb-list-bar form {
        margin: 0;
    }

    /* Feedback items */
    .fb-item {
        padding: 16px 18px 16px 15px;
        border-left: 3px solid transparent;
        border-bottom: 1px solid #D8C9BD;
    }

    .fb-item:last-child {
        border-bottom: 0;
    }

    .fb-item.unread {
        border-left-color: #6F4E37;
        background: #FBF7F3;
    }

    .fb-item-top {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
    }

    .fb-name-wrap {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 6px 8px;
        min-width: 0;
    }

    .fb-name {
        color: #4A3525;
        font-size: .92rem;
        font-weight: 700;
        overflow-wrap: anywhere;
    }

    .fb-badge {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 999px;
        font-size: .62rem;
        font-weight: 700;
        letter-spacing: .3px;
    }

    .fb-badge.new {
        background: #6F4E37;
        color: #FFFFFF;
    }

    .fb-badge.guest {
        background: #F1E8DF;
        color: #6F4E37;
    }

    .fb-date {
        color: #8A7D72;
        font-size: .74rem;
        white-space: nowrap;
    }

    .fb-contact {
        margin-top: 3px;
        color: #8A7D72;
        font-size: .74rem;
        overflow-wrap: anywhere;
    }

    .fb-message {
        margin: 10px 0 0;
        color: #2C221E;
        font-size: .9rem;
        line-height: 1.55;
        white-space: pre-line;
        overflow-wrap: anywhere;
    }

    .fb-item-bottom {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 10px 12px;
        margin-top: 12px;
    }

    .fb-order {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 4px 10px;
        color: #7B6D62;
        font-size: .76rem;
    }

    .fb-order strong {
        color: #4A3525;
    }

    .fb-order a {
        color: #6F4E37;
        font-weight: 600;
        text-decoration: none;
    }

    .fb-order a:hover {
        text-decoration: underline;
    }

    .fb-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }

    .fb-actions form {
        margin: 0;
    }

    .fb-empty {
        padding: 40px 18px;
        color: #8A7D72;
        font-size: .82rem;
        text-align: center;
    }

    .fb-empty i {
        display: block;
        margin-bottom: 8px;
        color: #B8A08A;
        font-size: 2rem;
    }

    .fb-pagination {
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        gap: 4px;
        padding: 14px 18px;
        border-top: 1px solid #D8C9BD;
    }

    .fb-page {
        min-width: 38px;
        padding: 7px 10px;
        border: 1px solid #D8C9BD;
        border-radius: 8px;
        background: #FFFFFF;
        color: #4A3525;
        font-size: .8rem;
        font-weight: 600;
        text-align: center;
        text-decoration: none;
    }

    .fb-page:hover {
        background: #F7F1EA;
        color: #4A3525;
    }

    .fb-page.active {
        border-color: #6F4E37;
        background: #6F4E37;
        color: #FFFFFF;
    }

    .fb-page.disabled {
        color: #B8A08A;
        pointer-events: none;
    }

    .fb-flash {
        margin-bottom: 14px;
        padding: 10px 14px;
        border-radius: 10px;
        font-size: .82rem;
    }

    .fb-flash.success {
        border: 1px solid #86B998;
        background: #E5F6EA;
        color: #23733D;
    }

    .fb-flash.danger {
        border: 1px solid #D89A9A;
        background: #FCE3E3;
        color: #A33A3A;
    }

    /* Room for the floating sidebar menu button on tablets/phones. */
    @media (max-width: 991.98px) {
        body:has(.admin-menu-toggle-floating) .fb-header {
            padding-left: 52px;
        }
    }

    @media (max-width: 768px) {
        .admin-content {
            padding: 16px;
        }

        .fb-header {
            flex-direction: column;
        }
    }

    @media (max-width: 560px) {
        .admin-content {
            padding: 12px;
        }

        .fb-tabs {
            overflow-x: auto;
        }

        .fb-tab {
            flex: 0 0 auto;
        }

        .fb-filter {
            flex: 1 1 calc(50% - 6px);
        }

        .fb-filter select {
            width: 100%;
        }

        .fb-filter-search {
            flex-basis: 100%;
        }

        .fb-filters .fb-btn {
            flex: 1 1 100%;
        }

        .fb-item-top {
            flex-direction: column;
            gap: 4px;
        }

        .fb-actions {
            width: 100%;
        }

        .fb-actions form {
            flex: 1 1 0;
        }

        .fb-actions .fb-btn {
            width: 100%;
        }
    }
</style>

<div class="admin-feedback-page">

    <?php require_once 'sidebar.php'; ?>

    <?php require_once 'navbar.php'; ?>

    <main class="admin-main">

        <div class="admin-content">

            <div class="fb-header">
                <div>
                    <h1 class="fb-title">Customer Feedback</h1>
                    <p class="fb-description">
                        Feedback customers left on their completed orders.
                    </p>
                </div>

                <div class="fb-unread-pill">
                    <i class="bi bi-envelope me-1"></i>
                    <?= number_format($unreadCount) ?> unread
                </div>
            </div>

            <?php if ($feedbackFlash): ?>
                <div class="fb-flash <?= $feedbackFlash['type'] === 'success' ? 'success' : 'danger' ?>" role="status">
                    <?= htmlspecialchars((string)$feedbackFlash['message']) ?>
                </div>
            <?php endif; ?>

            <nav class="fb-tabs" aria-label="Feedback folders">
                <a
                    class="fb-tab <?= $feedbackView === 'inbox' ? 'active' : '' ?>"
                    href="<?= htmlspecialchars(feedbackUrl(['view' => 'inbox', 'page' => 1])) ?>"
                >
                    <i class="bi bi-inbox"></i> Inbox
                    <span class="fb-tab-count"><?= number_format($inboxCount) ?></span>
                </a>

                <a
                    class="fb-tab <?= $feedbackView === 'archived' ? 'active' : '' ?>"
                    href="<?= htmlspecialchars(feedbackUrl(['view' => 'archived', 'page' => 1])) ?>"
                >
                    <i class="bi bi-archive"></i> Archived
                    <span class="fb-tab-count"><?= number_format($archivedCount) ?></span>
                </a>
            </nav>

            <section class="fb-panel">

                <form method="GET" class="fb-filters">
                    <input type="hidden" name="view" value="<?= htmlspecialchars($feedbackView) ?>">

                    <div class="fb-filter">
                        <label for="fbRead">Status</label>
                        <select id="fbRead" name="read" onchange="this.form.submit()">
                            <option value="all" <?= $feedbackRead === 'all' ? 'selected' : '' ?>>All</option>
                            <option value="unread" <?= $feedbackRead === 'unread' ? 'selected' : '' ?>>Unread</option>
                            <option value="read" <?= $feedbackRead === 'read' ? 'selected' : '' ?>>Read</option>
                        </select>
                    </div>

                    <div class="fb-filter">
                        <label for="fbPeriod">Date</label>
                        <select id="fbPeriod" name="period" onchange="this.form.submit()">
                            <option value="all" <?= $feedbackPeriod === 'all' ? 'selected' : '' ?>>All time</option>
                            <option value="today" <?= $feedbackPeriod === 'today' ? 'selected' : '' ?>>Today</option>
                            <option value="week" <?= $feedbackPeriod === 'week' ? 'selected' : '' ?>>Last 7 days</option>
                            <option value="month" <?= $feedbackPeriod === 'month' ? 'selected' : '' ?>>This month</option>
                        </select>
                    </div>

                    <div class="fb-filter fb-filter-search">
                        <label for="fbSearch">Search</label>
                        <input
                            type="search"
                            id="fbSearch"
                            name="q"
                            value="<?= htmlspecialchars($feedbackSearch) ?>"
                            placeholder="Customer, order no., claim no., or words in the feedback"
                            autocomplete="off"
                        >
                    </div>

                    <button type="submit" class="fb-btn">
                        <i class="bi bi-search"></i> Search
                    </button>

                    <?php if ($feedbackSearch !== '' || $feedbackRead !== 'all' || $feedbackPeriod !== 'all'): ?>
                        <a class="fb-btn outline" href="<?= htmlspecialchars(feedbackUrl(['read' => 'all', 'period' => 'all', 'q' => '', 'page' => 1])) ?>">
                            Reset
                        </a>
                    <?php endif; ?>
                </form>

                <?php if ($tableMissing): ?>

                    <div class="fb-empty">
                        <i class="bi bi-exclamation-triangle"></i>
                        The feedback table was not found. Run <strong>sql/add_order_feedback.sql</strong> in phpMyAdmin.
                    </div>

                <?php else: ?>

                    <div class="fb-list-bar">
                        <span>
                            <?php if ($feedbackTotal > 0): ?>
                                Showing <?= $feedbackStart ?>–<?= $feedbackEnd ?> of <?= number_format($feedbackTotal) ?> feedback
                            <?php else: ?>
                                No feedback to show
                            <?php endif; ?>
                        </span>

                        <?php if ($feedbackView === 'inbox' && $unreadCount > 0): ?>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="return" value="<?= htmlspecialchars($currentUrl) ?>">
                                <input type="hidden" name="feedback_action" value="read_all">
                                <button type="submit" class="fb-btn outline small">
                                    <i class="bi bi-check2-all"></i> Mark all as read
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <?php if (empty($feedbackRows)): ?>

                        <div class="fb-empty">
                            <i class="bi bi-chat-left-text"></i>
                            <?php if ($feedbackSearch !== '' || $feedbackRead !== 'all' || $feedbackPeriod !== 'all'): ?>
                                No feedback matched the selected filters.
                            <?php elseif ($feedbackView === 'archived'): ?>
                                No archived feedback.
                            <?php else: ?>
                                No customer feedback yet.
                            <?php endif; ?>
                        </div>

                    <?php else: ?>

                        <?php foreach ($feedbackRows as $fb): ?>
                            <?php
                            $fbId = (int)$fb['id'];
                            $fbUnread = (int)$fb['is_read'] === 0;
                            $fbTime = !empty($fb['created_at']) ? strtotime((string)$fb['created_at']) : false;
                            $fbOrderNumber = (string)($fb['order_number'] ?? '');
                            /* Opens the order in Admin Orders > Order History. */
                            $fbOrderUrl = $fbOrderNumber !== ''
                                ? 'orders.php?' . http_build_query([
                                    'cancelled_open' => '1',
                                    'history_status' => 'completed',
                                    'cancelled_period' => 'specific_month',
                                    'cancelled_month' => !empty($fb['closed_at']) ? date('Y-m', strtotime((string)$fb['closed_at'])) : date('Y-m'),
                                    'cancelled_q' => $fbOrderNumber,
                                ])
                                : '';
                            ?>

                            <article class="fb-item <?= $fbUnread ? 'unread' : '' ?>" id="feedback-<?= $fbId ?>">

                                <div class="fb-item-top">
                                    <div>
                                        <div class="fb-name-wrap">
                                            <span class="fb-name"><?= htmlspecialchars((string)$fb['display_name']) ?></span>

                                            <?php if ($fb['customer_id'] === null): ?>
                                                <span class="fb-badge guest">Guest</span>
                                            <?php endif; ?>

                                            <?php if ($fbUnread): ?>
                                                <span class="fb-badge new">NEW</span>
                                            <?php endif; ?>
                                        </div>

                                        <?php if (!empty($fb['customer_email']) || !empty($fb['customer_phone'])): ?>
                                            <div class="fb-contact">
                                                <?= htmlspecialchars(implode(' · ', array_filter([
                                                    (string)($fb['customer_email'] ?? ''),
                                                    (string)($fb['customer_phone'] ?? ''),
                                                ]))) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <span class="fb-date">
                                        <?= $fbTime ? htmlspecialchars(date('M d, Y · h:i A', $fbTime)) : '' ?>
                                    </span>
                                </div>

                                <p class="fb-message"><?= htmlspecialchars((string)$fb['message']) ?></p>

                                <div class="fb-item-bottom">

                                    <div class="fb-order">
                                        <span>Order <strong><?= htmlspecialchars($fbOrderNumber !== '' ? $fbOrderNumber : 'N/A') ?></strong></span>

                                        <?php if (!empty($fb['claim_number'])): ?>
                                            <span>Claim <?= htmlspecialchars((string)$fb['claim_number']) ?></span>
                                        <?php endif; ?>

                                        <?php if ($fb['total_amount'] !== null): ?>
                                            <span>₱<?= number_format((float)$fb['total_amount'], 2) ?></span>
                                        <?php endif; ?>

                                        <?php if ($fbOrderUrl !== ''): ?>
                                            <a href="<?= htmlspecialchars($fbOrderUrl) ?>">
                                                View order <i class="bi bi-arrow-right"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>

                                    <div class="fb-actions">
                                        <?php
                                        $fbButtons = $feedbackView === 'archived'
                                            ? [['restore', 'bi-arrow-counterclockwise', 'Move to inbox']]
                                            : [
                                                $fbUnread
                                                    ? ['read', 'bi-envelope-open', 'Mark as read']
                                                    : ['unread', 'bi-envelope', 'Mark as unread'],
                                                ['archive', 'bi-archive', 'Archive'],
                                            ];
                                        ?>

                                        <?php foreach ($fbButtons as [$fbAction, $fbIcon, $fbLabel]): ?>
                                            <form method="POST">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                                <input type="hidden" name="return" value="<?= htmlspecialchars($currentUrl) ?>">
                                                <input type="hidden" name="feedback_id" value="<?= $fbId ?>">
                                                <input type="hidden" name="feedback_action" value="<?= $fbAction ?>">
                                                <button type="submit" class="fb-btn outline small" data-feedback-action="<?= $fbAction ?>">
                                                    <i class="bi <?= $fbIcon ?>"></i> <?= $fbLabel ?>
                                                </button>
                                            </form>
                                        <?php endforeach; ?>
                                    </div>

                                </div>

                            </article>

                        <?php endforeach; ?>

                        <?php if ($feedbackPages > 1): ?>
                            <nav class="fb-pagination" aria-label="Feedback pages">
                                <a class="fb-page <?= $feedbackPage <= 1 ? 'disabled' : '' ?>" href="<?= htmlspecialchars(feedbackUrl(['page' => max(1, $feedbackPage - 1)])) ?>">Previous</a>

                                <?php for ($p = max(1, $feedbackPage - 2); $p <= min($feedbackPages, $feedbackPage + 2); $p++): ?>
                                    <a
                                        class="fb-page <?= $p === $feedbackPage ? 'active' : '' ?>"
                                        href="<?= htmlspecialchars(feedbackUrl(['page' => $p])) ?>"
                                        <?= $p === $feedbackPage ? 'aria-current="page"' : '' ?>
                                    ><?= $p ?></a>
                                <?php endfor; ?>

                                <a class="fb-page <?= $feedbackPage >= $feedbackPages ? 'disabled' : '' ?>" href="<?= htmlspecialchars(feedbackUrl(['page' => min($feedbackPages, $feedbackPage + 1)])) ?>">Next</a>
                            </nav>
                        <?php endif; ?>

                    <?php endif; ?>

                <?php endif; ?>

            </section>

        </div>

    </main>

</div>
