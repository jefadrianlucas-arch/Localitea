<?php
session_start();
date_default_timezone_set('Asia/Manila');

require_once '../includes/db.php';

/* =========================================================
   ADMIN ACCESS
========================================================= */
if (
    !isset($_SESSION['user_id']) ||
    !in_array($_SESSION['user_role'] ?? '', ['admin'], true)
) {
    header('Location: ../auth/login.php');
    exit;
}

/* =========================================================
   MARK CUSTOMER FEEDBACK AS READ
========================================================= */
if (isset($_GET['read_feedback'])) {
    try {
        if ($_GET['read_feedback'] === 'all') {
            $pdo->exec("UPDATE order_feedback SET is_read = 1 WHERE is_read = 0");
        } else {
            $feedbackReadId = (int)$_GET['read_feedback'];

            if ($feedbackReadId > 0) {
                $markFeedbackStmt = $pdo->prepare("
                    UPDATE order_feedback
                    SET is_read = 1
                    WHERE id = ?
                ");
                $markFeedbackStmt->execute([$feedbackReadId]);
            }
        }
    } catch (Throwable $e) {
        error_log('dashboard.php feedback read: ' . $e->getMessage());
    }

    header('Location: dashboard.php#feedbackPanel');
    exit;
}

/* =========================================================
   ARCHIVE / DELETE CUSTOMER FEEDBACK (POST + CSRF)
   Archive column name: change here if different in your SQL.
========================================================= */
$FEEDBACK_ARCHIVE_COL = 'is_archived';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['feedback_action'])
) {
    $postedToken = (string)($_POST['csrf_token'] ?? '');

    if (hash_equals($_SESSION['csrf_token'], $postedToken)) {
        $feedbackActionId = (int)($_POST['feedback_id'] ?? 0);
        $feedbackAction   = (string)$_POST['feedback_action'];

        if ($feedbackActionId > 0) {
            try {
                if ($feedbackAction === 'archive') {
                    $pdo->prepare("
                        UPDATE order_feedback
                        SET {$FEEDBACK_ARCHIVE_COL} = 1, is_read = 1
                        WHERE id = ?
                    ")->execute([$feedbackActionId]);
                }
            } catch (Throwable $e) {
                error_log('dashboard.php feedback action: ' . $e->getMessage());
            }
        }
    }

    header('Location: dashboard.php#feedbackPanel');
    exit;
}

/* =========================================================
   DASHBOARD METRICS
========================================================= */

/* Today's sales: completed orders based on completion time. */
$todaySalesStmt = $pdo->query("
    SELECT COALESCE(SUM(total_amount), 0)
    FROM orders
    WHERE status = 'completed'
      AND DATE(COALESCE(closed_at, created_at)) = CURDATE()
");
$todaySales = (float)$todaySalesStmt->fetchColumn();

/* Completed orders today. */
$completedTodayStmt = $pdo->query("
    SELECT COUNT(*)
    FROM orders
    WHERE status = 'completed'
      AND DATE(COALESCE(closed_at, created_at)) = CURDATE()
");
$completedToday = (int)$completedTodayStmt->fetchColumn();

/* All orders placed today, regardless of current status. */
$todayOrdersStmt = $pdo->query("
    SELECT COUNT(*)
    FROM orders
    WHERE DATE(created_at) = CURDATE()
");
$todayOrders = (int)$todayOrdersStmt->fetchColumn();

/* GCash orders waiting for payment-proof verification. */
$pendingVerificationStmt = $pdo->query("
    SELECT COUNT(*)
    FROM orders
    WHERE status = 'pending_verification'
      AND LOWER(TRIM(COALESCE(payment_method, ''))) = 'gcash'
");
$pendingVerification = (int)$pendingVerificationStmt->fetchColumn();

/* Orders currently moving through the active workflow. */
$activeOrdersStmt = $pdo->query("
    SELECT COUNT(*)
    FROM orders
    WHERE status IN ('confirmed', 'preparing', 'ready')
");
$activeOrders = (int)$activeOrdersStmt->fetchColumn();

/* =========================================================
   ORDER STATUS COUNTS
========================================================= */
$statusStmt = $pdo->query("
    SELECT
        COALESCE(SUM(status = 'confirmed'), 0) AS confirmed_count,
        COALESCE(SUM(status = 'preparing'), 0) AS preparing_count,
        COALESCE(SUM(status = 'ready'), 0) AS ready_count
    FROM orders
");
$statusCounts = $statusStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$confirmedCount = (int)($statusCounts['confirmed_count'] ?? 0);
$preparingCount = (int)($statusCounts['preparing_count'] ?? 0);
$readyCount = (int)($statusCounts['ready_count'] ?? 0);

/* =========================================================
   7-DAY SALES OVERVIEW
========================================================= */
$salesByDay = [];

$salesTrendStmt = $pdo->query("
    SELECT
        DATE(COALESCE(closed_at, created_at)) AS sales_day,
        COALESCE(SUM(total_amount), 0) AS sales_total
    FROM orders
    WHERE status = 'completed'
      AND DATE(COALESCE(closed_at, created_at))
          BETWEEN DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND CURDATE()
    GROUP BY DATE(COALESCE(closed_at, created_at))
    ORDER BY sales_day ASC
");

foreach ($salesTrendStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $salesByDay[$row['sales_day']] = (float)$row['sales_total'];
}

$salesTrend = [];
$weeklySalesTotal = 0.0;
$maxDailySales = 0.0;

for ($i = 6; $i >= 0; $i--) {
    $dateKey = date('Y-m-d', strtotime("-{$i} days"));
    $amount = (float)($salesByDay[$dateKey] ?? 0);

    $salesTrend[] = [
        'date' => $dateKey,
        'label' => date('D', strtotime($dateKey)),
        'short_date' => date('M d', strtotime($dateKey)),
        'amount' => $amount
    ];

    $weeklySalesTotal += $amount;
    $maxDailySales = max($maxDailySales, $amount);
}

/* =========================================================
   TODAY'S TRANSACTIONS
   Completed orders use completion time when available.
   Cancelled orders are counted from today's orders because
   cancelled orders may not have a closed_at timestamp.
========================================================= */
$todayTransactionsStmt = $pdo->query("
    SELECT
        COALESCE(SUM(
            status = 'completed'
            AND DATE(COALESCE(closed_at, created_at)) = CURDATE()
        ), 0) AS completed_count,
        COALESCE(SUM(
            status = 'cancelled'
            AND DATE(created_at) = CURDATE()
        ), 0) AS cancelled_count
    FROM orders
");
$todayTransactions = $todayTransactionsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$completedTransactionsToday = (int)($todayTransactions['completed_count'] ?? 0);
$cancelledTransactionsToday = (int)($todayTransactions['cancelled_count'] ?? 0);

$todayTransactionsStmt = $pdo->query("
    SELECT
        id,
        order_number,
        claim_number,
        customer_name,
        payment_method,
        total_amount,
        status,
        created_at,
        closed_at
    FROM orders
    WHERE
        (
            status = 'completed'
            AND DATE(COALESCE(closed_at, created_at)) = CURDATE()
        )
        OR
        (
            status = 'cancelled'
            AND DATE(created_at) = CURDATE()
        )
    ORDER BY COALESCE(closed_at, created_at) DESC, id DESC
    LIMIT 8
");
$todayTransactionsList = $todayTransactionsStmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================================================
   RECENT ADMIN NOTIFICATIONS
========================================================= */
$recentNotificationsStmt = $pdo->query("
    SELECT
        n.id,
        n.type,
        n.message,
        n.reference_id,
        n.is_read,
        n.created_at,
        o.order_number,
        o.status AS order_status
    FROM notifications n
    LEFT JOIN orders o
        ON o.id = n.reference_id
    WHERE n.recipient_role = 'admin'
    ORDER BY n.is_read ASC, n.created_at DESC, n.id DESC
    LIMIT 5
");
$recentNotifications = $recentNotificationsStmt->fetchAll(PDO::FETCH_ASSOC);

$unreadNotificationsStmt = $pdo->query("
    SELECT COUNT(*)
    FROM notifications
    WHERE recipient_role = 'admin'
      AND is_read = 0
");
$unreadNotifications = (int)$unreadNotificationsStmt->fetchColumn();

/* =========================================================
   CUSTOMER FEEDBACK
========================================================= */
$recentFeedback = [];
$unreadFeedback = 0;

try {
    $recentFeedbackStmt = $pdo->query("
        SELECT
            f.id,
            f.order_id,
            f.customer_id,
            f.message,
            f.is_read,
            f.created_at,
            o.order_number,
            COALESCE(
                NULLIF(TRIM(u.name), ''),
                NULLIF(TRIM(o.customer_name), ''),
                'Guest'
            ) AS display_name
        FROM order_feedback f
        LEFT JOIN orders o
            ON o.id = f.order_id
        LEFT JOIN users u
            ON u.id = f.customer_id
        WHERE f.{$FEEDBACK_ARCHIVE_COL} = 0
        ORDER BY f.is_read ASC, f.created_at DESC, f.id DESC
        LIMIT 30
    ");
    $recentFeedback = $recentFeedbackStmt->fetchAll(PDO::FETCH_ASSOC);

    $unreadFeedback = (int)$pdo->query("
        SELECT COUNT(*)
        FROM order_feedback
        WHERE is_read = 0
          AND {$FEEDBACK_ARCHIVE_COL} = 0
    ")->fetchColumn();
} catch (Throwable $e) {
    /* Keep the dashboard working if the feedback table is not installed yet. */
    $recentFeedback = [];
    $unreadFeedback = 0;
}

/* =========================================================
   DISPLAY HELPERS
========================================================= */
function dashboardStatusLabel(string $status): string
{
    return match ($status) {
        'pending_verification' => 'Pending Verification',
        'confirmed' => 'Confirmed',
        'preparing' => 'Preparing',
        'ready' => 'Ready for Pick-up',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        default => ucwords(str_replace('_', ' ', $status))
    };
}

function dashboardStatusClass(string $status): string
{
    return match ($status) {
        'pending_verification' => 'status-pending',
        'confirmed' => 'status-confirmed',
        'preparing' => 'status-preparing',
        'ready' => 'status-ready',
        'completed' => 'status-completed',
        'cancelled' => 'status-cancelled',
        default => 'status-default'
    };
}

function dashboardNotificationTitle(string $type): string
{
    return match ($type) {
        'new_order' => 'New Order',
        'gcash_pending_verification' => 'GCash Payment Verification',
        'order_confirmed' => 'Order Confirmed',
        'order_preparing' => 'Order Preparing',
        'order_ready' => 'Order Ready',
        'order_completed' => 'Order Completed',
        'customer_cancelled_order' => 'Order Cancelled by Customer',
        default => ucwords(str_replace(['_', '-'], ' ', $type))
    };
}

function dashboardNotificationIcon(string $type): string
{
    return match ($type) {
        'new_order' => 'bi-cart-check',
        'gcash_pending_verification' => 'bi-credit-card',
        'order_confirmed' => 'bi-check-circle',
        'order_preparing' => 'bi-cup-hot',
        'order_ready' => 'bi-bag-check',
        'order_completed' => 'bi-check2-all',
        'customer_cancelled_order' => 'bi-x-circle',
        default => 'bi-bell'
    };
}

$page_title = 'Dashboard';
$page_description = 'Monitor daily sales, orders, queue activity, and notifications.';

require_once '../includes/header.php';
?>

<link
    rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
>

<style>
    /* =====================================================
       ADMIN DASHBOARD - new layout, original system colors
    ====================================================== */
    body {
        background: #F7F5F2;
    }

    .admin-dashboard-page {
        min-height: 100vh;
    }

    .admin-main {
        min-width: 0;
    }

    .admin-content {
        padding: 24px;
    }

    /* ---------- Header ---------- */
    .dashboard-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 14px;
        margin-bottom: 18px;
    }

    .dashboard-title {
        margin: 0;
        color: #4A3525;
        font-size: 1.4rem;
        font-weight: 700;
        letter-spacing: -.02em;
    }

    .dashboard-description {
        margin: 4px 0 0;
        color: #7B6D62;
        font-size: .8rem;
    }

    .dashboard-date {
        padding: 8px 12px;
        border: 2px solid #8B6A55;
        border-radius: 10px;
        background: #FFFFFF;
        color: #6F4E37;
        font-size: .75rem;
        font-weight: 500;
        white-space: nowrap;
    }

    /* ---------- Metric cards ---------- */
    .metric-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px;
        margin-bottom: 14px;
    }

    .metric-card {
        padding: 15px 17px;
        border: 2px solid #6F4E37;
        border-radius: 12px;
        background: #FFFFFF;
        box-shadow: 0 3px 10px rgba(74,53,37,.05);
    }

    .metric-card.sales   { border-left: 5px solid #4A3525; }
    .metric-card.orders  { border-left: 5px solid #4C78A8; }
    .metric-card.pending { border-left: 5px solid #C7922E; }
    .metric-card.active  { border-left: 5px solid #3F8A55; }

    .metric-label {
        color: #7B6D62;
        font-size: .68rem;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: .35px;
    }

    .metric-value {
        margin-top: 6px;
        color: #4A3525;
        font-size: 1.55rem;
        font-weight: 700;
        line-height: 1.1;
    }

    .metric-subtext {
        margin-top: 6px;
        color: #8A7D72;
        font-size: .68rem;
        line-height: 1.35;
    }

    /* ---------- Panels ---------- */
    .dashboard-panel {
        margin-bottom: 14px;
        border: 2px solid #6F4E37;
        border-radius: 12px;
        background: #FFFFFF;
        box-shadow: 0 3px 10px rgba(74,53,37,.04);
        overflow: hidden;
    }

    .dashboard-panel-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 8px 12px;
        padding: 15px 18px;
        border-bottom: 1px solid #B8A08A;
    }

    .panel-heading {
        margin: 0;
        color: #4A3525;
        font-size: .95rem;
        font-weight: 600;
    }

    .panel-subheading {
        margin: 3px 0 0;
        color: #8A7D72;
        font-size: .72rem;
    }

    .panel-total {
        color: #4A3525;
        font-size: .92rem;
        font-weight: 600;
        text-align: right;
        white-space: nowrap;
    }

    .quick-link {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        color: #6F4E37;
        font-size: .72rem;
        font-weight: 500;
        text-decoration: none;
    }

    .quick-link:hover {
        color: #4A3525;
    }

    .dashboard-panel-body {
        padding: 0;
    }

    .dashboard-duo {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 14px;
        margin-bottom: 14px;
    }

    .dashboard-duo > .dashboard-panel {
        margin-bottom: 0;
    }

    /* ---------- Sales overview ---------- */
    .sales-chart {
        padding: 16px 18px 14px;
    }

    .chart-bars {
        display: flex;
        align-items: flex-end;
        gap: 10px;
    }

    .chart-day {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: flex-end;
        gap: 8px;
    }

    .chart-amount,
    .chart-date-label {
        display: none;
    }

    .chart-bar-wrap {
        width: 100%;
        max-width: 56px;
        height: 155px;
        display: flex;
        align-items: flex-end;
        justify-content: center;
    }

    .chart-bar {
        width: 100%;
        min-height: 4px;
        border-radius: 8px 8px 3px 3px;
        background: #6F4E37;
        transition: height .2s ease;
    }

    .chart-day.today .chart-bar {
        background: #4A3525;
    }

    .chart-day-label {
        color: #6B5B50;
        font-size: .7rem;
        font-weight: 500;
    }

    .chart-day.today .chart-day-label {
        color: #4A3525;
        font-weight: 700;
    }

    /* ---------- Order status ---------- */
    .status-panel-body {
        padding: 6px 18px 10px;
    }

    .status-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 12px 0;
        border-bottom: 1px solid #D8C9BD;
    }

    .status-row:last-child {
        border-bottom: 0;
    }

    .status-name {
        display: flex;
        align-items: center;
        gap: 9px;
        color: #5E5148;
        font-size: .78rem;
        font-weight: 500;
    }

    .status-dot {
        width: 10px;
        height: 10px;
        flex: 0 0 10px;
        border-radius: 50%;
    }

    .status-dot.confirmed { background: #4C78A8; }
    .status-dot.preparing { background: #7654A8; }
    .status-dot.ready     { background: #3F8A55; }

    .status-count {
        min-width: 34px;
        padding: 4px 8px;
        border: 1px solid #B8A08A;
        border-radius: 8px;
        background: #FBF9F6;
        color: #4A3525;
        font-size: .74rem;
        font-weight: 600;
        text-align: center;
    }

    /* ---------- Notifications ---------- */
    .notifications-list {
        padding: 6px 18px 12px;
    }

    .dashboard-notification {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        padding: 12px 0;
        border-bottom: 1px solid #D8C9BD;
    }

    .dashboard-notification:last-child {
        border-bottom: 0;
    }

    .notification-icon {
        width: 34px;
        height: 34px;
        min-width: 34px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 9px;
        background: #F3E8D8;
        color: #6B4226;
        font-size: .9rem;
    }

    .notification-main {
        min-width: 0;
        flex: 1;
    }

    .notification-title-row {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 7px;
    }

    .notification-title {
        color: #4B2E1E;
        font-size: .74rem;
        font-weight: 600;
    }

    .notification-new {
        padding: 2px 6px;
        border-radius: 8px;
        background: #5A3825;
        color: #FFFFFF;
        font-size: .55rem;
        font-weight: 500;
    }

    .notification-message {
        margin-top: 3px;
        color: #6B625C;
        font-size: .72rem;
        line-height: 1.4;
    }

    .notification-time {
        margin-top: 4px;
        color: #999999;
        font-size: .62rem;
    }

    .empty-dashboard {
        padding: 22px 18px;
        color: #8A7D72;
        font-size: .74rem;
        text-align: center;
    }

    /* ---------- Customer feedback ---------- */
    .feedback-panel .dashboard-panel-header {
        align-items: center;
        flex-wrap: nowrap;
    }

    .feedback-icon {
        position: relative;
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        border: 1px solid #B8A08A;
        border-radius: 10px;
        background: #FBF7F3;
        color: #6F4E37;
        font-size: 1.05rem;
    }

    .feedback-icon-badge {
        position: absolute;
        top: -7px;
        right: -7px;
        min-width: 18px;
        height: 18px;
        padding: 0 5px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        background: #6F4E37;
        color: #FFFFFF;
        font-size: .62rem;
        font-weight: 700;
        line-height: 1;
    }

    .feedback-list.is-expanded {
        max-height: 440px;
        overflow-y: auto;
    }

    .feedback-item {
        padding: 15px 18px 15px 15px;
        border-left: 3px solid transparent;
        border-bottom: 1px solid #D8C9BD;
    }

    .feedback-item[hidden] {
        display: none;
    }

    .feedback-item:last-child {
        border-bottom: 0;
    }

    .feedback-item.unread {
        border-left-color: #6F4E37;
        background: #FBF7F3;
    }

    .feedback-item-top,
    .feedback-item-bottom {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
    }

    .feedback-name-wrap {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 8px;
        min-width: 0;
    }

    .feedback-name {
        color: #4A3525;
        font-size: .88rem;
        font-weight: 600;
    }

    .feedback-date {
        color: #8A7D72;
        font-size: .72rem;
        white-space: nowrap;
    }

    .feedback-message {
        margin: 8px 0 0;
        color: #4A3525;
        font-size: .86rem;
        line-height: 1.5;
        white-space: pre-line;
        word-break: break-word;
    }

    .feedback-item-bottom {
        margin-top: 8px;
    }

    .feedback-order {
        color: #8A7D72;
        font-size: .72rem;
    }

    .feedback-badge {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 999px;
        font-size: .64rem;
        font-weight: 700;
        letter-spacing: .3px;
    }

    .feedback-badge.new {
        background: #6F4E37;
        color: #FFFFFF;
    }

    .feedback-badge.guest {
        background: #F1E8DF;
        color: #6F4E37;
    }

    .feedback-actions {
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .feedback-actions form {
        margin: 0;
    }

    .feedback-icon-btn {
        width: 30px;
        height: 30px;
        padding: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid #6F4E37;
        border-radius: 8px;
        background: #FFFFFF;
        color: #6F4E37;
        font-size: .86rem;
        line-height: 1;
        text-decoration: none;
        cursor: pointer;
    }

    .feedback-icon-btn:hover {
        background: #6F4E37;
        color: #FFFFFF;
    }

    .feedback-icon-btn.danger {
        border-color: #A33A3A;
        color: #A33A3A;
    }

    .feedback-icon-btn.danger:hover {
        background: #A33A3A;
        color: #FFFFFF;
    }

    .feedback-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 13px 18px;
        border-top: 1px solid #D8C9BD;
    }

    .feedback-view-all {
        padding: 0;
        border: 0;
        background: none;
        color: #6F4E37;
        font-size: .8rem;
        font-weight: 600;
        cursor: pointer;
    }

    .feedback-view-all:hover {
        text-decoration: underline;
    }

    .feedback-markall {
        color: #8A7D72;
        font-size: .74rem;
        text-decoration: none;
    }

    .feedback-markall:hover {
        color: #6F4E37;
        text-decoration: underline;
    }

    /* ---------- Today's transactions ---------- */
    .transaction-summary {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
        padding: 16px;
        border-bottom: 1px solid #D8C9BD;
    }

    .transaction-summary-card {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 13px 14px;
        border: 1px solid #B8A08A;
        border-radius: 10px;
        background: #FBF9F6;
    }

    .transaction-summary-label {
        color: #7B6D62;
        font-size: .68rem;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: .35px;
    }

    .transaction-summary-value {
        margin-top: 3px;
        color: #4A3525;
        font-size: 1.18rem;
        font-weight: 700;
        line-height: 1;
    }

    .transaction-summary-icon {
        width: 34px;
        height: 34px;
        flex: 0 0 34px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 9px;
        background: #F0E6D6;
        color: #6F4E37;
    }

    .transaction-table-wrap {
        overflow-x: auto;
    }

    .transaction-table {
        width: 100%;
        min-width: 690px;
        border-collapse: collapse;
    }

    .transaction-table th {
        padding: 11px 16px;
        border-bottom: 2px solid #8B6A55;
        background: #FBF8F4;
        color: #7B6D62;
        font-size: .66rem;
        font-weight: 500;
        letter-spacing: .35px;
        text-align: left;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .transaction-table td {
        padding: 13px 16px;
        border-bottom: 1px solid #D8C9BD;
        color: #4B4038;
        font-size: .76rem;
        vertical-align: middle;
    }

    .transaction-table tbody tr:last-child td {
        border-bottom: 0;
    }

    .transaction-table tbody tr:hover {
        background: #FCFAF7;
    }

    .order-number {
        color: #4A3525;
        font-weight: 600;
    }

    .claim-number {
        margin-top: 2px;
        color: #8A7D72;
        font-size: .65rem;
    }

    .payment-method {
        font-size: .65rem;
        font-weight: 500;
        letter-spacing: .3px;
        text-transform: uppercase;
    }

    .payment-cash  { color: #5B7B5E; }
    .payment-gcash { color: #286090; }

    .status-badge {
        display: inline-flex;
        align-items: center;
        padding: 5px 8px;
        border-radius: 999px;
        font-size: .63rem;
        font-weight: 500;
        white-space: nowrap;
    }

    .status-pending   { background: #FFF1D6; color: #8A5A00; border: 1px solid #D8B56A; }
    .status-confirmed { background: #E7F1FF; color: #285B9A; border: 1px solid #8DAFD6; }
    .status-preparing { background: #EEE7FF; color: #5B3A9A; border: 1px solid #AA98D0; }
    .status-ready     { background: #E5F6EA; color: #23733D; border: 1px solid #86B998; }
    .status-completed { background: #E8E2DD; color: #4A3525; border: 1px solid #B8A08A; }
    .status-cancelled { background: #FCE3E3; color: #A33A3A; border: 1px solid #D89A9A; }
    .status-default   { background: #F3F0EC; color: #5E5148; border: 1px solid #C9BBAE; }

    .table-view-btn {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 6px 9px;
        border: 1px solid #8B6A55;
        border-radius: 8px;
        background: #FFFFFF;
        color: #6F4E37;
        font-size: .65rem;
        font-weight: 500;
        text-decoration: none;
        white-space: nowrap;
    }

    .table-view-btn:hover {
        border-color: #4A3525;
        background: #F7F1EB;
        color: #4A3525;
    }

    /* ---------- Responsive ---------- */
    @media (max-width: 1000px) {
        .metric-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 768px) {
        .admin-content {
            padding: 16px;
        }

        .dashboard-header {
            flex-direction: column;
        }

        .transaction-summary {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 560px) {
        .admin-content {
            padding: 12px;
        }

        .dashboard-duo {
            grid-template-columns: 1fr;
        }

        .chart-bars {
            gap: 6px;
        }

        .feedback-item-top {
            align-items: flex-start;
        }
    }
</style>

<div class="admin-dashboard-page">

    <?php require_once 'sidebar.php'; ?>

    <?php require_once 'navbar.php'; ?>

    <main class="admin-main">

        <div class="admin-content">

            <div class="dashboard-header">
                <div>
                    <h1 class="dashboard-title">
                        Admin Dashboard
                    </h1>

                    <p class="dashboard-description">
                        Monitor today's sales, orders, queue activity, and notifications.
                    </p>
                </div>

                <div class="dashboard-date">
                    <i class="bi bi-calendar3 me-1"></i>
                    <?= date('F d, Y') ?>
                </div>
            </div>

            <!-- =====================================================
                 METRIC CARDS
            ====================================================== -->
            <div class="metric-grid">

                <div class="metric-card sales">
                    <div class="metric-label">Today's Sales</div>

                    <div class="metric-value">
                        ₱<?= number_format($todaySales, 2) ?>
                    </div>

                    <div class="metric-subtext">
                        <?= number_format($completedToday) ?>
                        completed order<?= $completedToday === 1 ? '' : 's' ?> today
                    </div>
                </div>

                <div class="metric-card orders">
                    <div class="metric-label">Today's Orders</div>

                    <div class="metric-value">
                        <?= number_format($todayOrders) ?>
                    </div>

                    <div class="metric-subtext">
                        All orders placed today
                    </div>
                </div>

                <div class="metric-card pending">
                    <div class="metric-label">Pending Verification</div>

                    <div class="metric-value">
                        <?= number_format($pendingVerification) ?>
                    </div>

                    <div class="metric-subtext">
                        GCash payment proof awaiting review
                    </div>
                </div>

                <div class="metric-card active">
                    <div class="metric-label">Active Orders</div>

                    <div class="metric-value">
                        <?= number_format($activeOrders) ?>
                    </div>

                    <div class="metric-subtext">
                        Confirmed, preparing, and ready orders
                    </div>
                </div>

            </div>

            <!-- =====================================================
                 SALES OVERVIEW
            ====================================================== -->
            <section class="dashboard-panel">

                    <div class="dashboard-panel-header">

                        <div>
                            <h2 class="panel-heading">
                                Sales Overview
                            </h2>

                            <p class="panel-subheading">
                                Completed sales for the last 7 days
                            </p>
                        </div>

                        <div>
                            <div class="panel-total">
                                ₱<?= number_format($weeklySalesTotal, 2) ?>
                            </div>

                            <a class="quick-link" href="sales-reports.php">
                                View Sales Reports
                                <i class="bi bi-arrow-right"></i>
                            </a>
                        </div>

                    </div>

                    <div class="sales-chart">

                        <div class="chart-bars">

                            <?php foreach ($salesTrend as $day): ?>

                                <?php
                                $barHeight = $maxDailySales > 0
                                    ? max(4, ($day['amount'] / $maxDailySales) * 155)
                                    : 4;

                                $isToday = $day['date'] === date('Y-m-d');
                                ?>

                                <div class="chart-day <?= $isToday ? 'today' : '' ?>">

                                    <div class="chart-amount">
                                        ₱<?= number_format($day['amount'], 0) ?>
                                    </div>

                                    <div class="chart-bar-wrap">

                                        <div
                                            class="chart-bar"
                                            style="height: <?= number_format($barHeight, 2, '.', '') ?>px;"
                                            title="<?= htmlspecialchars($day['short_date']) ?>: ₱<?= number_format($day['amount'], 2) ?>"
                                        ></div>

                                    </div>

                                    <div class="chart-day-label">
                                        <?= htmlspecialchars($day['label']) ?>
                                    </div>

                                    <div class="chart-date-label">
                                        <?= htmlspecialchars($day['short_date']) ?>
                                    </div>

                                </div>

                            <?php endforeach; ?>

                        </div>

                    </div>

                </section>

            <!-- =====================================================
                 ORDER STATUS + NOTIFICATIONS
            ====================================================== -->
            <div class="dashboard-duo">

                <section class="dashboard-panel">

                    <div class="dashboard-panel-header">

                        <div>
                            <h2 class="panel-heading">
                                Order Status
                            </h2>

                            <p class="panel-subheading">
                                Current active workflow
                            </p>
                        </div>

                        <a class="quick-link" href="orders.php">
                            Manage Orders
                            <i class="bi bi-arrow-right"></i>
                        </a>

                    </div>

                    <div class="status-panel-body">

                        <div class="status-row">

                            <div class="status-name">
                                <span class="status-dot confirmed"></span>
                                Confirmed
                            </div>

                            <div class="status-count">
                                <?= number_format($confirmedCount) ?>
                            </div>

                        </div>

                        <div class="status-row">

                            <div class="status-name">
                                <span class="status-dot preparing"></span>
                                Preparing
                            </div>

                            <div class="status-count">
                                <?= number_format($preparingCount) ?>
                            </div>

                        </div>

                        <div class="status-row">

                            <div class="status-name">
                                <span class="status-dot ready"></span>
                                Ready for Pick-up
                            </div>

                            <div class="status-count">
                                <?= number_format($readyCount) ?>
                            </div>

                        </div>

                    </div>

                </section>

                <section class="dashboard-panel">

                    <div class="dashboard-panel-header">

                        <div>

                            <h2 class="panel-heading">
                                Recent Notifications
                            </h2>

                            <p class="panel-subheading">

                                <?= number_format($unreadNotifications) ?>
                                unread notification<?= $unreadNotifications === 1 ? '' : 's' ?>

                            </p>

                        </div>

                        <a class="quick-link" href="notifications.php">

                            View All

                            <i class="bi bi-arrow-right"></i>

                        </a>

                    </div>

                    <div class="notifications-list">

                        <?php if (empty($recentNotifications)): ?>

                            <div class="empty-dashboard">
                                No admin notifications yet.
                            </div>

                        <?php else: ?>

                            <?php foreach ($recentNotifications as $notification): ?>

                                <?php
                                $notificationType = (string)($notification['type'] ?? 'notification');

                                $notificationDate = !empty($notification['created_at'])
                                    ? date('M d, Y • h:i A', strtotime($notification['created_at']))
                                    : '';
                                ?>

                                <div class="dashboard-notification">

                                    <div class="notification-icon">

                                        <i class="bi <?= htmlspecialchars(dashboardNotificationIcon($notificationType)) ?>"></i>

                                    </div>

                                    <div class="notification-main">

                                        <div class="notification-title-row">

                                            <span class="notification-title">

                                                <?= htmlspecialchars(dashboardNotificationTitle($notificationType)) ?>

                                            </span>

                                            <?php if ((int)$notification['is_read'] === 0): ?>

                                                <span class="notification-new">
                                                    NEW
                                                </span>

                                            <?php endif; ?>

                                        </div>

                                        <div class="notification-message">

                                            <?= htmlspecialchars((string)($notification['message'] ?? '')) ?>

                                        </div>

                                        <div class="notification-time">

                                            <i class="bi bi-clock me-1"></i>

                                            <?= htmlspecialchars($notificationDate) ?>

                                        </div>

                                    </div>

                                </div>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </div>

                </section>

            </div>

            <!-- =====================================================
                 CUSTOMER FEEDBACK
            ====================================================== -->
            <section class="dashboard-panel feedback-panel" id="feedbackPanel">

                <div class="dashboard-panel-header">

                    <div>
                        <h2 class="panel-heading">
                            Recent Customer Feedback
                        </h2>

                        <p class="panel-subheading">
                            Latest comments from completed orders
                        </p>
                    </div>

                    <div class="feedback-icon" title="<?= (int)$unreadFeedback ?> unread">
                        <i class="bi bi-chat-left-text"></i>

                        <?php if ($unreadFeedback > 0): ?>
                            <span class="feedback-icon-badge">
                                <?= $unreadFeedback > 99 ? '99+' : (int)$unreadFeedback ?>
                            </span>
                        <?php endif; ?>
                    </div>

                </div>

                <div class="feedback-list">

                    <?php if (empty($recentFeedback)): ?>

                        <div class="empty-dashboard">
                            No customer feedback yet.
                        </div>

                    <?php else: ?>

                        <?php foreach ($recentFeedback as $feedbackIndex => $feedback): ?>

                            <?php
                            $feedbackIsUnread = ((int)$feedback['is_read'] === 0);
                            $feedbackTime = !empty($feedback['created_at'])
                                ? strtotime($feedback['created_at'])
                                : false;
                            $feedbackShortDate = $feedbackTime ? date('M d', $feedbackTime) : '';
                            $feedbackFullDate  = $feedbackTime ? date('M d, Y • h:i A', $feedbackTime) : '';
                            $feedbackHidden    = $feedbackIndex >= 3;
                            ?>

                            <div
                                class="feedback-item<?= $feedbackIsUnread ? ' unread' : '' ?><?= $feedbackHidden ? ' feedback-extra' : '' ?>"
                                <?= $feedbackHidden ? 'hidden' : '' ?>
                            >

                                <div class="feedback-item-top">

                                    <div class="feedback-name-wrap">
                                        <span class="feedback-name">
                                            <?= htmlspecialchars((string)$feedback['display_name']) ?>
                                        </span>

                                        <?php if ($feedback['customer_id'] === null): ?>
                                            <span class="feedback-badge guest">Guest</span>
                                        <?php endif; ?>

                                        <?php if ($feedbackIsUnread): ?>
                                            <span class="feedback-badge new">NEW</span>
                                        <?php endif; ?>
                                    </div>

                                    <span
                                        class="feedback-date"
                                        title="<?= htmlspecialchars($feedbackFullDate) ?>"
                                    >
                                        <?= htmlspecialchars($feedbackShortDate) ?>
                                    </span>

                                </div>

                                <p class="feedback-message"><?= htmlspecialchars((string)$feedback['message']) ?></p>

                                <div class="feedback-item-bottom">

                                    <span class="feedback-order">
                                        Order #<?= htmlspecialchars((string)($feedback['order_number'] ?? 'N/A')) ?>
                                    </span>

                                    <div class="feedback-actions">

                                        <form method="post" action="dashboard.php#feedbackPanel">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="feedback_id" value="<?= (int)$feedback['id'] ?>">
                                            <input type="hidden" name="feedback_action" value="archive">
                                            <button
                                                type="submit"
                                                class="feedback-icon-btn danger"
                                                title="Archive"
                                                aria-label="Archive feedback"
                                            >
                                                <i class="bi bi-archive"></i>
                                            </button>
                                        </form>

                                    </div>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>

                <?php if (!empty($recentFeedback)): ?>
                    <div class="feedback-footer">

                        <?php if (count($recentFeedback) > 3): ?>
                            <button
                                type="button"
                                class="feedback-view-all"
                                data-feedback-toggle
                                data-total="<?= (int)count($recentFeedback) ?>"
                            >
                                View All Feedback (<?= (int)count($recentFeedback) ?>) &rarr;
                            </button>
                        <?php else: ?>
                            <span class="feedback-order">
                                Showing all <?= (int)count($recentFeedback) ?> feedback
                            </span>
                        <?php endif; ?>

                        <?php if ($unreadFeedback > 0): ?>
                            <a
                                class="feedback-markall"
                                href="dashboard.php?read_feedback=all#feedbackPanel"
                            >
                                Mark all as read
                            </a>
                        <?php endif; ?>

                    </div>
                <?php endif; ?>

                <script>
                (function () {
                    var panel = document.getElementById('feedbackPanel');
                    if (!panel) return;

                    var button = panel.querySelector('[data-feedback-toggle]');
                    if (!button) return;

                    var list = panel.querySelector('.feedback-list');
                    var extras = panel.querySelectorAll('.feedback-extra');
                    var total = button.getAttribute('data-total');
                    var open = false;

                    button.addEventListener('click', function () {
                        open = !open;

                        extras.forEach(function (item) {
                            item.hidden = !open;
                        });

                        list.classList.toggle('is-expanded', open);

                        button.innerHTML = open
                            ? 'Show less &uarr;'
                            : 'View All Feedback (' + total + ') &rarr;';
                    });
                })();
                </script>

            </section>

            <!-- =====================================================
                 TODAY'S TRANSACTIONS
            ====================================================== -->
            <section class="dashboard-panel">

                    <div class="dashboard-panel-header">

                        <div>
                            <h2 class="panel-heading">
                                Today's Transactions
                            </h2>

                            <p class="panel-subheading">
                                Completed and cancelled orders for today
                            </p>
                        </div>

                    </div>

                    <div class="dashboard-panel-body">

                        <div class="transaction-summary">

                            <div class="transaction-summary-card">

                                <div>
                                    <div class="transaction-summary-label">
                                        Completed Today
                                    </div>

                                    <div class="transaction-summary-value">
                                        <?= number_format($completedTransactionsToday) ?>
                                    </div>
                                </div>

                                <div class="transaction-summary-icon">
                                    <i class="bi bi-check2-circle"></i>
                                </div>

                            </div>

                            <div class="transaction-summary-card">

                                <div>
                                    <div class="transaction-summary-label">
                                        Cancelled Today
                                    </div>

                                    <div class="transaction-summary-value">
                                        <?= number_format($cancelledTransactionsToday) ?>
                                    </div>
                                </div>

                                <div class="transaction-summary-icon">
                                    <i class="bi bi-x-circle"></i>
                                </div>

                            </div>

                        </div>

                        <?php if (empty($todayTransactionsList)): ?>

                            <div class="empty-dashboard">
                                No completed or cancelled transactions today.
                            </div>

                        <?php else: ?>

                            <div class="transaction-table-wrap">

                                <table class="transaction-table">

                                    <thead>
                                        <tr>
                                            <th>Order</th>
                                            <th>Customer</th>
                                            <th>Payment</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>

                                    <tbody>

                                        <?php foreach ($todayTransactionsList as $order): ?>

                                            <tr>

                                                <td>

                                                    <div class="order-number">
                                                        <?= htmlspecialchars($order['order_number'] ?: 'Order #' . (int)$order['id']) ?>
                                                    </div>

                                                    <?php if (!empty($order['claim_number'])): ?>

                                                        <div class="claim-number">
                                                            <?= htmlspecialchars($order['claim_number']) ?>
                                                        </div>

                                                    <?php endif; ?>

                                                </td>

                                                <td>
                                                    <?= htmlspecialchars($order['customer_name'] ?? '') ?>
                                                </td>

                                                <td>

                                                    <span class="payment-method <?= strtolower((string)$order['payment_method']) === 'gcash' ? 'payment-gcash' : 'payment-cash' ?>">

                                                        <?= htmlspecialchars(ucfirst((string)$order['payment_method'])) ?>

                                                    </span>

                                                </td>

                                                <td>

                                                    <strong>
                                                        ₱<?= number_format((float)$order['total_amount'], 2) ?>
                                                    </strong>

                                                </td>

                                                <td>

                                                    <span class="status-badge <?= dashboardStatusClass((string)$order['status']) ?>">

                                                        <?= htmlspecialchars(dashboardStatusLabel((string)$order['status'])) ?>

                                                    </span>

                                                </td>

                                            </tr>

                                        <?php endforeach; ?>

                                    </tbody>

                                </table>

                            </div>

                        <?php endif; ?>

                    </div>

                </section>

        </div>

    </main>

</div>