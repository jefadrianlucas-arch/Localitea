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
        padding: 28px;
    }

    .dashboard-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 18px;
        margin-bottom: 24px;
    }

    .dashboard-title {
        margin: 0;
        color: #4A3525;
        font-size: 1.65rem;
        font-weight: 900;
        letter-spacing: -.02em;
    }

    .dashboard-description {
        margin: 6px 0 0;
        color: #7B6D62;
        font-size: .84rem;
    }

    .dashboard-date {
        padding: 9px 13px;
        border: 2px solid #8B6A55;
        border-radius: 10px;
        background: #FFFFFF;
        color: #6F4E37;
        font-size: .78rem;
        font-weight: 800;
        white-space: nowrap;
    }

    .metric-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 14px;
        margin-bottom: 20px;
    }

    .metric-card {
        background: #FFFFFF;
        border: 2px solid #6F4E37;
        border-radius: 14px;
        padding: 18px;
        box-shadow: 0 3px 10px rgba(74,53,37,.05);
        min-height: 126px;
    }

    .metric-card.sales {
        border-left: 5px solid #4A3525;
    }

    .metric-card.orders {
        border-left: 5px solid #4C78A8;
    }

    .metric-card.pending {
        border-left: 5px solid #C7922E;
    }

    .metric-card.active {
        border-left: 5px solid #3F8A55;
    }

    .metric-label {
        color: #7B6D62;
        font-size: .72rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .35px;
    }

    .metric-value {
        margin-top: 8px;
        color: #4A3525;
        font-size: 1.8rem;
        font-weight: 900;
        line-height: 1.05;
    }

    .metric-card.sales .metric-value {
        font-size: 1.65rem;
    }

    .metric-subtext {
        margin-top: 8px;
        color: #8A7D72;
        font-size: .73rem;
    }

    .dashboard-two-column {
        display: grid;
        grid-template-columns: minmax(0, 1.7fr) minmax(300px, .9fr);
        gap: 18px;
        margin-bottom: 20px;
    }

    .dashboard-panel {
        background: #FFFFFF;
        border: 2px solid #6F4E37;
        border-radius: 14px;
        box-shadow: 0 3px 10px rgba(74,53,37,.04);
        overflow: hidden;
    }

    .dashboard-panel-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
        padding: 18px 20px;
        border-bottom: 1px solid #B8A08A;
    }

    .panel-heading {
        margin: 0;
        color: #4A3525;
        font-size: .98rem;
        font-weight: 900;
    }

    .panel-subheading {
        margin: 4px 0 0;
        color: #8A7D72;
        font-size: .72rem;
    }

    .panel-total {
        color: #4A3525;
        font-size: .95rem;
        font-weight: 900;
        white-space: nowrap;
    }

    .sales-chart {
        padding: 18px 20px 20px;
    }

    .chart-bars {
        display: flex;
        align-items: flex-end;
        gap: 12px;
        min-height: 210px;
    }

    .chart-day {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: flex-end;
        gap: 8px;
        height: 210px;
    }

    .chart-amount {
        color: #6F4E37;
        font-size: .65rem;
        font-weight: 800;
        text-align: center;
        white-space: nowrap;
    }

    .chart-bar-wrap {
        width: 100%;
        max-width: 48px;
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
        font-size: .68rem;
        font-weight: 800;
    }

    .chart-date-label {
        color: #9A8D83;
        font-size: .62rem;
    }

    .status-panel-body {
        padding: 18px 20px;
    }

    .status-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 13px 0;
        border-bottom: 1px solid #D8C9BD;
    }

    .status-row:first-child {
        padding-top: 0;
    }

    .status-row:last-child {
        border-bottom: 0;
        padding-bottom: 0;
    }

    .status-name {
        display: flex;
        align-items: center;
        gap: 9px;
        color: #5E5148;
        font-size: .78rem;
        font-weight: 700;
    }

    .status-dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        flex: 0 0 10px;
    }

    .status-dot.confirmed {
        background: #4C78A8;
    }

    .status-dot.preparing {
        background: #7654A8;
    }

    .status-dot.ready {
        background: #3F8A55;
    }

    .status-count {
        min-width: 34px;
        padding: 5px 8px;
        border: 1px solid #B8A08A;
        border-radius: 8px;
        background: #FBF9F6;
        color: #4A3525;
        font-size: .74rem;
        font-weight: 900;
        text-align: center;
    }

    .quick-link {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        color: #6F4E37;
        text-decoration: none;
        font-size: .72rem;
        font-weight: 800;
    }

    .quick-link:hover {
        color: #4A3525;
    }

    .dashboard-panel-body {
        padding: 0;
    }

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
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: .35px;
    }

    .transaction-summary-value {
        margin-top: 3px;
        color: #4A3525;
        font-size: 1.18rem;
        font-weight: 900;
        line-height: 1;
    }

    .transaction-summary-icon {
        width: 34px;
        height: 34px;
        border-radius: 9px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #F0E6D6;
        color: #6F4E37;
        flex: 0 0 34px;
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
        background: #FBF8F4;
        border-bottom: 2px solid #8B6A55;
        color: #7B6D62;
        font-size: .66rem;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: .35px;
        text-align: left;
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
        font-weight: 900;
    }

    .claim-number {
        margin-top: 2px;
        color: #8A7D72;
        font-size: .65rem;
    }

    .payment-method {
        text-transform: uppercase;
        font-size: .65rem;
        font-weight: 900;
        letter-spacing: .3px;
    }

    .payment-cash {
        color: #5B7B5E;
    }

    .payment-gcash {
        color: #286090;
    }

    .status-badge {
        display: inline-flex;
        align-items: center;
        padding: 5px 8px;
        border-radius: 999px;
        font-size: .63rem;
        font-weight: 900;
        white-space: nowrap;
    }

    .status-pending {
        background: #FFF1D6;
        color: #8A5A00;
        border: 1px solid #D8B56A;
    }

    .status-confirmed {
        background: #E7F1FF;
        color: #285B9A;
        border: 1px solid #8DAFD6;
    }

    .status-preparing {
        background: #EEE7FF;
        color: #5B3A9A;
        border: 1px solid #AA98D0;
    }

    .status-ready {
        background: #E5F6EA;
        color: #23733D;
        border: 1px solid #86B998;
    }

    .status-completed {
        background: #E8E2DD;
        color: #4A3525;
        border: 1px solid #B8A08A;
    }

    .status-cancelled {
        background: #FCE3E3;
        color: #A33A3A;
        border: 1px solid #D89A9A;
    }

    .status-default {
        background: #F3F0EC;
        color: #5E5148;
        border: 1px solid #C9BBAE;
    }

    .table-view-btn {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 6px 9px;
        border: 1px solid #8B6A55;
        border-radius: 8px;
        background: #FFFFFF;
        color: #6F4E37;
        text-decoration: none;
        font-size: .65rem;
        font-weight: 800;
        white-space: nowrap;
    }

    .table-view-btn:hover {
        border-color: #4A3525;
        background: #F7F1EB;
        color: #4A3525;
    }

    .notifications-list {
        padding: 12px 16px 16px;
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
        border-radius: 9px;
        background: #F3E8D8;
        color: #6B4226;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: .9rem;
    }

    .notification-main {
        min-width: 0;
        flex: 1;
    }

    .notification-title-row {
        display: flex;
        align-items: center;
        gap: 7px;
        flex-wrap: wrap;
    }

    .notification-title {
        color: #4B2E1E;
        font-size: .72rem;
        font-weight: 900;
    }

    .notification-new {
        padding: 2px 6px;
        border-radius: 8px;
        background: #5A3825;
        color: #FFFFFF;
        font-size: .55rem;
        font-weight: 900;
    }

    .notification-message {
        margin-top: 3px;
        color: #6B625C;
        font-size: .7rem;
        line-height: 1.4;
    }

    .notification-time {
        margin-top: 4px;
        color: #999;
        font-size: .61rem;
    }

    .empty-dashboard {
        padding: 24px 18px;
        text-align: center;
        color: #8A7D72;
        font-size: .74rem;
    }

    .dashboard-panels-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.4fr) minmax(320px, .9fr);
        gap: 18px;
    }

    @media (max-width: 1100px) {
        .metric-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .dashboard-two-column,
        .dashboard-panels-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 768px) {

        .admin-content {
            padding: 16px;
        }

        .dashboard-header {
            display: block;
        }

        .dashboard-date {
            display: inline-flex;
            margin-top: 12px;
        }

        /* Four key numbers as a compact 2 x 2 grid */
        .metric-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .metric-card {
            min-height: 0;
            padding: 14px 12px 14px 14px;
        }

        .metric-label {
            font-size: .68rem;
        }

        .metric-value {
            font-size: 1.55rem;
        }

        .metric-card.sales .metric-value {
            font-size: 1.35rem;
        }

        .metric-subtext {
            font-size: .72rem;
            line-height: 1.35;
        }

        .chart-bars {
            gap: 7px;
        }

        .chart-amount {
            font-size: .56rem;
        }
    }

    @media (max-width: 480px) {

        .admin-content {
            padding: 10px;
        }

        .dashboard-title {
            font-size: 1.35rem;
        }

        .dashboard-panel-header {
            padding: 15px;
        }

        .sales-chart,
        .status-panel-body {
            padding: 15px;
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
                 SALES + ORDER STATUS
            ====================================================== -->
            <div class="dashboard-two-column">

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

            </div>

            <!-- =====================================================
                 TODAY'S TRANSACTIONS + NOTIFICATIONS
            ====================================================== -->
            <div class="dashboard-panels-grid">

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

        </div>

    </main>

</div>

<?php require_once '../includes/footer.php'; ?>