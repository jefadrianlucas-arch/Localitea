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
   CUSTOMER FEEDBACK: FILTERS + ACTIONS
   The feedback dropdown keeps its own fb_* query values so the
   rest of the dashboard is not affected.
========================================================= */

$FEEDBACK_ARCHIVE_COL = 'is_archived';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$fbView = ($_GET['fb_view'] ?? 'inbox') === 'archived' ? 'archived' : 'inbox';

$fbRead = (string)($_GET['fb_read'] ?? 'all');
if (!in_array($fbRead, ['all', 'unread', 'read'], true)) {
    $fbRead = 'all';
}

$fbSearch = trim((string)($_GET['fb_q'] ?? ''));
if (mb_strlen($fbSearch) > 100) {
    $fbSearch = mb_substr($fbSearch, 0, 100);
}

$fbPage = max(1, (int)($_GET['fb_page'] ?? 1));
$fbPerPage = 5;

function dashboardFeedbackUrl(array $overrides = []): string
{
    $query = array_merge([
        'fb_view' => $GLOBALS['fbView'],
        'fb_read' => $GLOBALS['fbRead'],
        'fb_q' => $GLOBALS['fbSearch'],
        'fb_page' => $GLOBALS['fbPage'],
    ], $overrides);

    $defaults = ['fb_view' => 'inbox', 'fb_read' => 'all', 'fb_q' => '', 'fb_page' => 1];

    foreach ($defaults as $key => $default) {
        if ((string)$query[$key] === (string)$default) {
            unset($query[$key]);
        }
    }

    return 'dashboard.php' . ($query ? '?' . http_build_query($query) : '') . '#feedbackPanel';
}

/* Old links (dashboard.php?read_feedback=...) still work. */
if (isset($_GET['read_feedback'])) {
    try {
        if ($_GET['read_feedback'] === 'all') {
            $pdo->exec("UPDATE order_feedback SET is_read = 1 WHERE is_read = 0");
        } elseif ((int)$_GET['read_feedback'] > 0) {
            $pdo->prepare("UPDATE order_feedback SET is_read = 1 WHERE id = ?")
                ->execute([(int)$_GET['read_feedback']]);
        }
    } catch (Throwable $e) {
        error_log('dashboard.php feedback read: ' . $e->getMessage());
    }

    header('Location: dashboard.php#feedbackPanel');
    exit;
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['feedback_action'])
) {
    $fbReturn = (string)($_POST['fb_return'] ?? '');

    /* Only ever return to the dashboard. */
    if (!preg_match('/^dashboard\.php(\?[^\r\n#]*)?#feedbackPanel$/', $fbReturn)) {
        $fbReturn = 'dashboard.php#feedbackPanel';
    }

    if (hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
        $feedbackAction = (string)$_POST['feedback_action'];
        $feedbackActionId = (int)($_POST['feedback_id'] ?? 0);

        $feedbackUpdates = [
            'read' => 'is_read = 1',
            'unread' => 'is_read = 0',
            'archive' => "{$FEEDBACK_ARCHIVE_COL} = 1, is_read = 1",
            'restore' => "{$FEEDBACK_ARCHIVE_COL} = 0",
        ];

        try {
            if ($feedbackAction === 'read_all') {
                $pdo->exec("UPDATE order_feedback SET is_read = 1 WHERE is_read = 0 AND {$FEEDBACK_ARCHIVE_COL} = 0");
            } elseif (isset($feedbackUpdates[$feedbackAction]) && $feedbackActionId > 0) {
                $pdo->prepare("UPDATE order_feedback SET {$feedbackUpdates[$feedbackAction]} WHERE id = ?")
                    ->execute([$feedbackActionId]);
            }
        } catch (Throwable $e) {
            error_log('dashboard.php feedback action: ' . $e->getMessage());
        }
    }

    header('Location: ' . $fbReturn);
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
$ordersByDay = [];

$salesTrendStmt = $pdo->query("
    SELECT
        DATE(COALESCE(closed_at, created_at)) AS sales_day,
        COALESCE(SUM(total_amount), 0) AS sales_total,
        COUNT(*) AS order_count
    FROM orders
    WHERE status = 'completed'
      AND DATE(COALESCE(closed_at, created_at))
          BETWEEN DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND CURDATE()
    GROUP BY DATE(COALESCE(closed_at, created_at))
    ORDER BY sales_day ASC
");

foreach ($salesTrendStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $salesByDay[$row['sales_day']] = (float)$row['sales_total'];
    $ordersByDay[$row['sales_day']] = (int)$row['order_count'];
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
        'amount' => $amount,
        'orders' => (int)($ordersByDay[$dateKey] ?? 0)
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

/* Completed / Cancelled buttons filter the list (?tx_status=...). */
$txStatus = (string)($_GET['tx_status'] ?? 'all');
if (!in_array($txStatus, ['all', 'completed', 'cancelled'], true)) {
    $txStatus = 'all';
}

$txCompletedSql = "(status = 'completed' AND DATE(COALESCE(closed_at, created_at)) = CURDATE())";
$txCancelledSql = "(status = 'cancelled' AND DATE(created_at) = CURDATE())";
$txWhereSql = [
    'all' => "{$txCompletedSql} OR {$txCancelledSql}",
    'completed' => $txCompletedSql,
    'cancelled' => $txCancelledSql,
][$txStatus];

function dashboardTransactionsUrl(string $status): string
{
    $query = $_GET;
    unset($query['tx_status']);

    if ($status !== 'all') {
        $query['tx_status'] = $status;
    }

    return 'dashboard.php' . ($query ? '?' . http_build_query($query) : '') . '#transactionsPanel';
}

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
    WHERE {$txWhereSql}
    ORDER BY COALESCE(closed_at, created_at) DESC, id DESC
    LIMIT 50
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
   CUSTOMER FEEDBACK LIST
========================================================= */
$recentFeedback = [];
$unreadFeedback = 0;
$fbInboxCount = 0;
$fbArchivedCount = 0;
$fbTotal = 0;
$fbPages = 1;

try {
    $fbWhere = ["f.{$FEEDBACK_ARCHIVE_COL} = ?"];
    $fbParams = [$fbView === 'archived' ? 1 : 0];

    if ($fbRead === 'unread') {
        $fbWhere[] = 'f.is_read = 0';
    } elseif ($fbRead === 'read') {
        $fbWhere[] = 'f.is_read = 1';
    }

    if ($fbSearch !== '') {
        $fbWhere[] = '(o.order_number LIKE ? OR o.claim_number LIKE ? OR c.full_name LIKE ? OR o.customer_name LIKE ? OR f.message LIKE ?)';
        $fbLike = '%' . $fbSearch . '%';
        array_push($fbParams, $fbLike, $fbLike, $fbLike, $fbLike, $fbLike);
    }

    $fbFromWhere = "
        FROM order_feedback f
        LEFT JOIN orders o ON o.id = f.order_id
        LEFT JOIN customers c ON c.id = f.customer_id
        WHERE " . implode(' AND ', $fbWhere);

    $fbCountStmt = $pdo->prepare("SELECT COUNT(*) {$fbFromWhere}");
    $fbCountStmt->execute($fbParams);
    $fbTotal = (int)$fbCountStmt->fetchColumn();

    $fbPages = max(1, (int)ceil($fbTotal / $fbPerPage));
    $fbPage = min($fbPage, $fbPages);
    $fbOffset = ($fbPage - 1) * $fbPerPage;

    $recentFeedbackStmt = $pdo->prepare("
        SELECT
            f.id,
            f.customer_id,
            f.message,
            f.is_read,
            f.created_at,
            o.order_number,
            o.claim_number,
            o.total_amount,
            o.closed_at,
            c.email AS customer_email,
            COALESCE(NULLIF(TRIM(c.contact_number), ''), NULLIF(TRIM(o.contact_number), '')) AS customer_phone,
            COALESCE(
                NULLIF(TRIM(c.full_name), ''),
                NULLIF(TRIM(o.customer_name), ''),
                'Guest'
            ) AS display_name
        {$fbFromWhere}
        ORDER BY f.is_read ASC, f.created_at DESC, f.id DESC
        LIMIT {$fbPerPage} OFFSET {$fbOffset}
    ");
    $recentFeedbackStmt->execute($fbParams);
    $recentFeedback = $recentFeedbackStmt->fetchAll(PDO::FETCH_ASSOC);

    $fbCounts = $pdo->query("
        SELECT
            COALESCE(SUM({$FEEDBACK_ARCHIVE_COL} = 0), 0) AS inbox_count,
            COALESCE(SUM({$FEEDBACK_ARCHIVE_COL} = 1), 0) AS archived_count,
            COALESCE(SUM({$FEEDBACK_ARCHIVE_COL} = 0 AND is_read = 0), 0) AS unread_count
        FROM order_feedback
    ")->fetch(PDO::FETCH_ASSOC) ?: [];

    $fbInboxCount = (int)($fbCounts['inbox_count'] ?? 0);
    $fbArchivedCount = (int)($fbCounts['archived_count'] ?? 0);
    $unreadFeedback = (int)($fbCounts['unread_count'] ?? 0);
} catch (Throwable $e) {
    /* Keep the dashboard working if the feedback table is not installed yet. */
    error_log('dashboard.php feedback list: ' . $e->getMessage());
    $recentFeedback = [];
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

    /* Today's Transactions sets the row height; Recent Notifications
       matches it and scrolls its list instead of adding space. */
    @media (min-width: 561px) {
        .dashboard-duo-notify > .dashboard-panel:first-child {
            display: flex;
            flex-direction: column;
            contain: size;
            min-height: 320px;
        }

        .dashboard-duo-notify > .dashboard-panel:first-child .notifications-list {
            flex: 1 1 0;
            min-height: 0;
            overflow-y: auto;
        }
    }

    /* ---------- Sales overview ---------- */
    .sales-chart {
        padding: 14px 18px 12px;
    }

    .panel-total-caption {
        color: #8A7968;
        font-size: .68rem;
        font-weight: 500;
        text-align: right;
        margin-bottom: 2px;
    }

    .chart-bars {
        --chart-h: 120px;
        position: relative;
        display: flex;
        align-items: flex-end;
        gap: 14px;
    }

    .chart-day {
        position: relative;
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: flex-end;
        gap: 6px;
    }

    .chart-date-label {
        display: none;
    }

    /* Value above each bar */
    .chart-amount {
        color: #6F4E37;
        font-size: .68rem;
        font-weight: 600;
        line-height: 1;
        white-space: nowrap;
    }

    .chart-day.today .chart-amount {
        color: #4A3525;
    }

    .chart-day.empty .chart-amount {
        visibility: hidden;
    }

    /* Bar area with faint gridlines (25% / 50% / 75% / 100%) */
    .chart-bar-wrap {
        width: 100%;
        height: var(--chart-h);
        display: flex;
        align-items: flex-end;
        justify-content: center;
        background-image: repeating-linear-gradient(
            to top,
            transparent 0,
            transparent calc(25% - 1px),
            #EFE8E0 calc(25% - 1px),
            #EFE8E0 25%
        );
    }

    .chart-bar {
        width: 62%;
        max-width: 44px;
        min-height: 4px;
        border-radius: 7px 7px 2px 2px;
        background: linear-gradient(180deg, #7D5A41 0%, #6F4E37 100%);
        transition: height .2s ease, filter .15s ease, transform .15s ease;
    }

    .chart-day:hover .chart-bar {
        filter: brightness(1.12);
        transform: translateY(-2px);
    }

    .chart-day.today .chart-bar {
        background: linear-gradient(180deg, #5C402C 0%, #4A3525 100%);
    }

    /* Zero-sales days read as "no sales", not "broken" */
    .chart-day.empty .chart-bar {
        background: #E8DFD6;
        min-height: 4px;
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

    /* Hover tooltip */
    .chart-tooltip {
        position: absolute;
        bottom: calc(100% + 4px);
        left: 50%;
        transform: translateX(-50%) translateY(4px);
        background: #3A2A1E;
        color: #fff;
        padding: 6px 9px;
        border-radius: 8px;
        font-size: .68rem;
        line-height: 1.35;
        text-align: center;
        white-space: nowrap;
        pointer-events: none;
        opacity: 0;
        z-index: 5;
        transition: opacity .15s ease, transform .15s ease;
    }

    .chart-day:hover .chart-tooltip {
        opacity: 1;
        transform: translateX(-50%) translateY(0);
    }

    .chart-tooltip strong {
        display: block;
        font-size: .74rem;
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
        padding: 9px 0;
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

    /* ---------- Customer feedback row: full width, cards in 2 columns ---------- */
    .dashboard-duo.dashboard-duo-bottom {
        grid-template-columns: 1fr;
        align-items: start;
    }

    .feedback-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .feedback-grid > .feedback-item {
        display: flex;
        flex-direction: column;
    }

    /* Keep the buttons lined up at the bottom of each card */
    .feedback-grid > .feedback-item .feedback-item-bottom {
        margin-top: auto;
        padding-top: 9px;
    }

    .feedback-grid > .feedback-item:nth-child(odd) {
        border-right: 1px solid #D8C9BD;
    }

    @media (max-width: 900px) {
        .feedback-grid {
            grid-template-columns: 1fr;
        }

        .feedback-grid > .feedback-item:nth-child(odd) {
            border-right: 0;
        }
    }

    /* ---------- Action toasts (copied from orders.php) ---------- */
    .orders-toast-stack {
        position: fixed;
        top: 88px;
        right: 24px;
        z-index: 2001;
        display: flex;
        flex-direction: column;
        align-items: stretch;
        gap: 10px;
        width: min(420px, calc(100vw - 32px));
        max-height: calc(100vh - 108px);
        overflow-y: auto;
        padding: 2px;
        pointer-events: none;
    }

    .orders-toast-stack .orders-toast {
        width: 100%;
        flex: 0 0 auto;
        pointer-events: auto;
    }

    .orders-toast {
        position: relative;
        display: flex;
        align-items: flex-start;
        gap: 12px;
        padding: 13px 14px;
        background: #ffffff;
        border: 2px solid #6F4E37;
        border-radius: 12px;
        box-shadow: 0 10px 28px rgba(44,34,30,.18);
        color: #2C221E;
        pointer-events: auto;
        overflow: hidden;
        animation: ordersToastIn .22s ease-out;
    }

    .orders-toast-success { border-left: 6px solid #4A8B5A; }
    .orders-toast-cancel { border-left: 6px solid #A33A3A; }

    .orders-toast-icon {
        flex: 0 0 30px;
        width: 30px;
        height: 30px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: #F3EADF;
        color: #4A3525;
        font-size: 15px;
        margin-top: 1px;
    }

    .orders-toast-success .orders-toast-icon {
        color: #2F6E3E;
        background: #EAF6EE;
    }

    .orders-toast-cancel .orders-toast-icon {
        color: #8E2F2F;
        background: #FCE3E3;
    }

    .orders-toast-copy {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 2px;
    }

    .orders-toast-title {
        color: #2F6E3E;
        font-size: .94rem;
        line-height: 1.3;
        font-weight: 700;
    }

    .orders-toast-message {
        flex: 1;
        padding-top: 1px;
        font-size: .86rem;
        line-height: 1.45;
        font-weight: 500;
    }

    .orders-toast-close {
        flex: 0 0 auto;
        border: 0;
        background: transparent;
        color: #6F4E37;
        width: 30px;
        height: 30px;
        border-radius: 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
    }

    .orders-toast-close:hover {
        background: #F0E6D6;
        color: #2C221E;
    }

    .orders-toast-progress {
        position: absolute;
        left: 0;
        bottom: 0;
        height: 3px;
        width: 100%;
        background: #6F4E37;
        transform-origin: left center;
        animation: ordersToastProgress 3.5s linear forwards;
    }

    .orders-toast-cancel .orders-toast-title { color: #8E2F2F; }
    .orders-toast-success .orders-toast-progress { background: #4A8B5A; }
    .orders-toast-cancel .orders-toast-progress { background: #A33A3A; }
    .orders-toast.is-restored { animation: none; }
    .orders-toast.is-closing { animation: ordersToastOut .18s ease-in forwards; }

    @keyframes ordersToastIn {
        from { opacity: 0; transform: translateY(-8px) translateX(8px); }
        to { opacity: 1; transform: translateY(0) translateX(0); }
    }

    @keyframes ordersToastOut {
        from { opacity: 1; transform: translateY(0) translateX(0); }
        to { opacity: 0; transform: translateY(-6px) translateX(8px); }
    }

    @keyframes ordersToastProgress {
        from { transform: scaleX(1); }
        to { transform: scaleX(0); }
    }

    @media (max-width: 768px) {
        .orders-toast-stack {
            top: 74px;
            right: 14px;
            width: min(420px, calc(100vw - 28px));
            max-height: calc(100vh - 90px);
        }
    }

    /* ---------- Customer feedback ---------- */
    .feedback-summary {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 13px 18px;
        border-bottom: 1px solid #B8A08A;
    }

    .feedback-summary-icon {
        position: relative;
        width: 36px;
        height: 36px;
        flex: 0 0 36px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 10px;
        background: #F0E6D6;
        color: #6F4E37;
    }

    .feedback-summary-badge {
        position: absolute;
        top: -6px;
        right: -6px;
        min-width: 18px;
        height: 18px;
        padding: 0 5px;
        border-radius: 999px;
        background: #B3261E;
        color: #FFFFFF;
        font-size: .6rem;
        font-weight: 700;
        line-height: 18px;
        text-align: center;
    }

    .feedback-summary-text {
        flex: 1;
        min-width: 0;
    }

    .feedback-toolbar {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 8px 10px;
        padding: 12px 18px;
        border-bottom: 1px solid #D8C9BD;
        background: #FBF8F4;
    }

    .feedback-tab {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 12px;
        border: 1px solid #B8A08A;
        border-radius: 8px;
        background: #FFFFFF;
        color: #6F4E37;
        font-size: .74rem;
        font-weight: 600;
        text-decoration: none;
    }

    .feedback-tab:hover {
        background: #F7F1EA;
        color: #4A3525;
    }

    .feedback-tab.active {
        border-color: #6F4E37;
        background: #6F4E37;
        color: #FFFFFF;
    }

    .feedback-tab-count {
        padding: 0 6px;
        border-radius: 999px;
        background: rgba(111, 78, 55, .12);
        font-size: .64rem;
    }

    .feedback-tab.active .feedback-tab-count {
        background: rgba(255, 255, 255, .22);
    }

    .feedback-filter {
        display: flex;
        flex: 0 1 auto;
        gap: 8px;
        margin: 0;
    }

    .feedback-filter select,
    .feedback-filter input {
        min-height: 34px;
        padding: 5px 10px;
        border: 1px solid #C9B3A2;
        border-radius: 8px;
        background: #FFFFFF;
        color: #4A3525;
        font-size: .74rem;
    }

    .feedback-filter input {
        width: 200px;
        min-width: 0;
    }

    /* Mark all read sits at the far right. */
    .feedback-toolbar > form[method="POST"] {
        margin-left: auto;
    }

    #feedbackContent {
        transition: opacity .15s ease;
    }

    .feedback-panel.is-loading #feedbackContent {
        opacity: .55;
        pointer-events: none;
    }

    .feedback-filter select:focus,
    .feedback-filter input:focus {
        outline: none;
        border-color: #6F4E37;
        box-shadow: 0 0 0 3px rgba(111, 78, 55, .12);
    }

    .feedback-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
        min-height: 34px;
        padding: 5px 11px;
        border: 1px solid #6F4E37;
        border-radius: 8px;
        background: #6F4E37;
        color: #FFFFFF;
        font-size: .72rem;
        font-weight: 600;
        text-decoration: none;
        white-space: nowrap;
        cursor: pointer;
    }

    .feedback-btn:hover {
        background: #4A3525;
        color: #FFFFFF;
    }

    .feedback-btn.outline {
        background: #FFFFFF;
        color: #6F4E37;
    }

    .feedback-btn.outline:hover {
        background: #F7F1EA;
        color: #4A3525;
    }
    .feedback-btn.danger-solid {
        border-color: #A33A3A;
        background: #A33A3A;
        color: #FFFFFF;
    }
    .feedback-btn.danger-solid:hover {
        background: #8E2F2F;
        color: #FFFFFF;
    }

    /* ---------- Delete confirmation ---------- */
    .fb-confirm-overlay {
        position: fixed;
        inset: 0;
        z-index: 2100;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 16px;
        background: rgba(44, 34, 30, .45);
    }

    .fb-confirm-box {
        width: min(400px, 100%);
        padding: 22px 22px 18px;
        background: #FFFFFF;
        border: 2px solid #6F4E37;
        border-radius: 14px;
        box-shadow: 0 16px 40px rgba(44, 34, 30, .25);
        color: #2C221E;
    }

    .fb-confirm-icon {
        width: 42px;
        height: 42px;
        margin-bottom: 12px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        background: #FCE3E3;
        color: #8E2F2F;
        font-size: 19px;
    }

    .fb-confirm-title {
        margin: 0 0 6px;
        font-size: 1rem;
        font-weight: 700;
        color: #4A3525;
    }

    .fb-confirm-text {
        margin: 0 0 18px;
        font-size: .84rem;
        line-height: 1.5;
        color: #6B5B50;
    }

    .fb-confirm-actions {
        display: flex;
        justify-content: flex-end;
        gap: 8px;
    }

    .feedback-btn.outline.danger {
        border-color: #A33A3A;
        color: #A33A3A;
    }
    .feedback-btn.outline.danger:hover {
        background: #FCE3E3;
        color: #8E2F2F;
    }

    .feedback-toolbar form {
        margin: 0;
    }

    .feedback-item {
        padding: 13px 18px 13px 15px;
        border-left: 3px solid transparent;
        border-bottom: 1px solid #D8C9BD;
    }

    .feedback-item.unread {
        border-left-color: #6F4E37;
        background: #FBF7F3;
    }

    .feedback-item-top,
    .feedback-item-bottom {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 8px 12px;
    }

    .feedback-item-bottom {
        flex-wrap: wrap;
        align-items: center;
        margin-top: 9px;
    }

    .feedback-name-wrap {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 4px 8px;
        min-width: 0;
    }

    .feedback-name {
        color: #4A3525;
        font-size: .82rem;
        font-weight: 700;
        overflow-wrap: anywhere;
    }

    .feedback-contact,
    .feedback-date,
    .feedback-order {
        color: #8A7D72;
        font-size: .7rem;
        overflow-wrap: anywhere;
    }

    .feedback-date {
        white-space: nowrap;
    }

    .feedback-message {
        margin: 7px 0 0;
        color: #2C221E;
        font-size: .8rem;
        line-height: 1.5;
        white-space: pre-line;
        overflow-wrap: anywhere;
    }

    .feedback-order {
        display: flex;
        flex-wrap: wrap;
        gap: 2px 10px;
    }

    .feedback-order strong {
        color: #4A3525;
    }

    .feedback-order a {
        color: #6F4E37;
        font-weight: 600;
        text-decoration: none;
    }

    .feedback-order a:hover {
        text-decoration: underline;
    }

    .feedback-badge {
        padding: 1px 7px;
        border-radius: 999px;
        font-size: .58rem;
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
        flex-wrap: wrap;
        gap: 6px;
    }

    .feedback-actions form {
        margin: 0;
    }

    .feedback-footer {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        padding: 10px 18px;
        color: #8A7D72;
        font-size: .72rem;
    }

    .feedback-pages {
        display: flex;
        flex-wrap: wrap;
        gap: 4px;
    }

    .feedback-page {
        min-width: 32px;
        padding: 5px 9px;
        border: 1px solid #D8C9BD;
        border-radius: 7px;
        color: #4A3525;
        font-size: .72rem;
        font-weight: 600;
        text-align: center;
        text-decoration: none;
    }

    .feedback-page:hover {
        background: #F7F1EA;
        color: #4A3525;
    }

    .feedback-page.active {
        border-color: #6F4E37;
        background: #6F4E37;
        color: #FFFFFF;
    }

    .feedback-page.disabled {
        color: #B8A08A;
        pointer-events: none;
    }

    @media (max-width: 560px) {
        .feedback-filter {
            flex-wrap: wrap;
        }

        .feedback-filter {
            flex: 1 1 100%;
            flex-wrap: nowrap;
        }

        .feedback-filter input {
            flex: 1;
            width: auto;
        }

        .feedback-item-top {
            flex-direction: column;
            gap: 2px;
        }

        .feedback-actions,
        .feedback-actions form {
            flex: 1 1 auto;
        }

        .feedback-actions .feedback-btn {
            width: 100%;
        }
    }

    /* ---------- Today's transactions ---------- */
    .transaction-summary {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }

    .transaction-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 5px 10px;
        border: 1px solid #B8A08A;
        border-radius: 999px;
        background: #FBF9F6;
        color: #7B6D62;
        font-size: .7rem;
        white-space: nowrap;
    }

    .transaction-pill strong {
        color: #4A3525;
        font-size: .8rem;
    }

    .transaction-pill.completed i { color: #2E7D4F; }
    .transaction-pill.cancelled i { color: #B3261E; }

    a.transaction-pill {
        text-decoration: none;
        cursor: pointer;
        transition: background .15s ease, border-color .15s ease;
    }

    a.transaction-pill:hover {
        border-color: #6F4E37;
        background: #F3EBE3;
        color: #4A3525;
    }

    a.transaction-pill.active {
        border-color: #6F4E37;
        background: #6F4E37;
        color: #FFFFFF;
    }

    a.transaction-pill.active strong,
    a.transaction-pill.active i {
        color: #FFFFFF;
    }

    .transactions-panel.is-loading {
        opacity: .6;
        pointer-events: none;
    }

    /* About 8 rows visible, the rest scrolls inside the panel. */
    .transaction-table-wrap {
        max-height: 340px;
        overflow: auto;
    }

    .transaction-table {
        width: 100%;
        min-width: 440px;
        border-collapse: collapse;
    }

    .transaction-table th {
        position: sticky;
        top: 0;
        z-index: 1;
        padding: 8px 10px;
        border-bottom: 2px solid #8B6A55;
        background: #FBF8F4;
        color: #7B6D62;
        font-size: .64rem;
        font-weight: 500;
        letter-spacing: .35px;
        text-align: left;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .transaction-table td {
        padding: 6px 10px;
        border-bottom: 1px solid #E6DAD0;
        color: #4B4038;
        font-size: .76rem;
        vertical-align: middle;
    }

    .transaction-table .order-number,
    .transaction-table .status-badge,
    .transaction-table strong {
        white-space: nowrap;
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
        display: block;
        margin-top: 1px;
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
    }

    @media (max-width: 560px) {
        .admin-content {
            padding: 12px;
        }

        .dashboard-duo {
            grid-template-columns: 1fr;
        }

        .chart-bars {
            --chart-h: 100px;
            gap: 6px;
        }

        .chart-amount {
            font-size: .6rem;
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
                 SALES OVERVIEW + ORDER STATUS (side by side)
            ====================================================== -->
            <div class="dashboard-duo">

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
                            <div class="panel-total-caption">Total · last 7 days</div>

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
                                    ? max(4, ($day['amount'] / $maxDailySales) * 120)
                                    : 4;

                                $isToday = $day['date'] === date('Y-m-d');
                                ?>

                                <div class="chart-day <?= $isToday ? 'today' : '' ?> <?= $day['amount'] <= 0 ? 'empty' : '' ?>">

                                    <div class="chart-tooltip">
                                        <strong>₱<?= number_format($day['amount'], 2) ?></strong>
                                        <?= htmlspecialchars($day['short_date']) ?> ·
                                        <?= (int)$day['orders'] ?> order<?= $day['orders'] === 1 ? '' : 's' ?>
                                    </div>

                                    <div class="chart-amount">
                                        ₱<?= number_format($day['amount'], 0) ?>
                                    </div>

                                    <div class="chart-bar-wrap">

                                        <div
                                            class="chart-bar"
                                            style="height: <?= number_format($barHeight, 2, '.', '') ?>px;"
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
                 RECENT NOTIFICATIONS + TODAY'S TRANSACTIONS (side by side)
            ====================================================== -->
            <div class="dashboard-duo dashboard-duo-notify">

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

            <!-- =====================================================
                 TODAY'S TRANSACTIONS
            ====================================================== -->
            <section class="dashboard-panel transactions-panel" id="transactionsPanel">

                    <div class="dashboard-panel-header">

                        <div>
                            <h2 class="panel-heading">
                                Today's Transactions
                            </h2>

                            <p class="panel-subheading">
                                Completed and cancelled orders for today
                            </p>
                        </div>

                        <div class="transaction-summary">
                            <?php /* Click to show only that status; click again to show all. */ ?>
                            <a
                                class="transaction-pill completed <?= $txStatus === 'completed' ? 'active' : '' ?>"
                                href="<?= htmlspecialchars(dashboardTransactionsUrl($txStatus === 'completed' ? 'all' : 'completed')) ?>"
                                aria-pressed="<?= $txStatus === 'completed' ? 'true' : 'false' ?>"
                                title="<?= $txStatus === 'completed' ? 'Show all' : 'Show completed only' ?>"
                            >
                                <i class="bi bi-check2-circle"></i>
                                Completed <strong><?= number_format($completedTransactionsToday) ?></strong>
                            </a>

                            <a
                                class="transaction-pill cancelled <?= $txStatus === 'cancelled' ? 'active' : '' ?>"
                                href="<?= htmlspecialchars(dashboardTransactionsUrl($txStatus === 'cancelled' ? 'all' : 'cancelled')) ?>"
                                aria-pressed="<?= $txStatus === 'cancelled' ? 'true' : 'false' ?>"
                                title="<?= $txStatus === 'cancelled' ? 'Show all' : 'Show cancelled only' ?>"
                            >
                                <i class="bi bi-x-circle"></i>
                                Cancelled <strong><?= number_format($cancelledTransactionsToday) ?></strong>
                            </a>
                        </div>

                    </div>

                    <div class="dashboard-panel-body">

                        <?php if (empty($todayTransactionsList)): ?>

                            <div class="empty-dashboard">
                                <?php if ($txStatus === 'completed'): ?>
                                    No completed orders today.
                                <?php elseif ($txStatus === 'cancelled'): ?>
                                    No cancelled orders today.
                                <?php else: ?>
                                    No completed or cancelled transactions today.
                                <?php endif; ?>
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

                                                    <span class="order-number"><?= htmlspecialchars($order['order_number'] ?: 'Order #' . (int)$order['id']) ?></span>

                                                    <?php if (!empty($order['claim_number'])): ?>
                                                        <span class="claim-number"><?= htmlspecialchars($order['claim_number']) ?></span>
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

                <script>
                /* Completed / Cancelled buttons update the list without a page refresh. */
                (function () {
                    var panel = document.getElementById('transactionsPanel');

                    if (!panel || !window.fetch || !window.DOMParser) {
                        return;
                    }

                    panel.addEventListener('click', function (event) {
                        var pill = event.target.closest('a.transaction-pill');

                        if (!pill) {
                            return;
                        }

                        event.preventDefault();
                        panel.classList.add('is-loading');

                        fetch(pill.href, { credentials: 'same-origin' })
                            .then(function (response) {
                                if (!response.ok) {
                                    throw new Error('Request failed');
                                }

                                return response.text();
                            })
                            .then(function (html) {
                                var fresh = new DOMParser()
                                    .parseFromString(html, 'text/html')
                                    .getElementById('transactionsPanel');

                                if (!fresh) {
                                    throw new Error('Transactions not found');
                                }

                                panel.innerHTML = fresh.innerHTML;
                                history.replaceState(null, '', pill.href.split('#')[0] + '#transactionsPanel');
                            })
                            .catch(function () {
                                window.location.href = pill.href;
                            })
                            .finally(function () {
                                panel.classList.remove('is-loading');
                            });
                    });
                })();
                </script>

            </div>

            <!-- =====================================================
                 CUSTOMER FEEDBACK (full width, 2-column cards, bottom)
            ====================================================== -->
            <div class="dashboard-duo dashboard-duo-bottom">

            <section class="dashboard-panel feedback-panel" id="feedbackPanel">

                <div class="feedback-dropdown">

                    <div class="feedback-summary">
                        <span class="feedback-summary-icon">
                            <i class="bi bi-chat-left-text"></i>

                            <?php if ($unreadFeedback > 0): ?>
                                <span class="feedback-summary-badge">
                                    <?= $unreadFeedback > 99 ? '99+' : (int)$unreadFeedback ?>
                                </span>
                            <?php endif; ?>
                        </span>

                        <span class="feedback-summary-text">
                            <span class="panel-heading d-block">Customer Feedback</span>
                            <span class="panel-subheading d-block">
                                <?= number_format($unreadFeedback) ?> unread
                                &middot;
                                <?= number_format($fbInboxCount) ?> in inbox
                            </span>
                        </span>

                    </div>

                    <?php $fbCurrentUrl = dashboardFeedbackUrl(); ?>

                    <div id="feedbackContent">

                    <div class="feedback-toolbar">

                        <a class="feedback-tab <?= $fbView === 'inbox' ? 'active' : '' ?>" href="<?= htmlspecialchars(dashboardFeedbackUrl(['fb_view' => 'inbox', 'fb_page' => 1])) ?>">
                            Inbox <span class="feedback-tab-count"><?= number_format($fbInboxCount) ?></span>
                        </a>

                        <a class="feedback-tab <?= $fbView === 'archived' ? 'active' : '' ?>" href="<?= htmlspecialchars(dashboardFeedbackUrl(['fb_view' => 'archived', 'fb_page' => 1])) ?>">
                            Deleted <span class="feedback-tab-count"><?= number_format($fbArchivedCount) ?></span>
                        </a>

                        <form method="GET" action="dashboard.php#feedbackPanel" class="feedback-filter">
                            <input type="hidden" name="fb_view" value="<?= htmlspecialchars($fbView) ?>">

                            <select name="fb_read" aria-label="Read status">
                                <option value="all" <?= $fbRead === 'all' ? 'selected' : '' ?>>All</option>
                                <option value="unread" <?= $fbRead === 'unread' ? 'selected' : '' ?>>Unread</option>
                                <option value="read" <?= $fbRead === 'read' ? 'selected' : '' ?>>Read</option>
                            </select>

                            <input
                                type="text"
                                name="fb_q"
                                enterkeyhint="search"
                                value="<?= htmlspecialchars($fbSearch) ?>"
                                placeholder="Search feedback..."
                                aria-label="Search feedback"
                                autocomplete="off"
                            >

                        </form>

                        <?php if ($fbView === 'inbox' && $unreadFeedback > 0): ?>
                            <form method="POST" action="dashboard.php">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="fb_return" value="<?= htmlspecialchars($fbCurrentUrl) ?>">
                                <input type="hidden" name="feedback_action" value="read_all">
                                <button type="submit" class="feedback-btn outline">
                                    <i class="bi bi-check2-all"></i> Mark all read
                                </button>
                            </form>
                        <?php endif; ?>

                    </div>

                    <?php if (empty($recentFeedback)): ?>

                        <div class="empty-dashboard">
                            <?php if ($fbSearch !== '' || $fbRead !== 'all'): ?>
                                No feedback matched your search.
                            <?php elseif ($fbView === 'archived'): ?>
                                No deleted feedback.
                            <?php else: ?>
                                No customer feedback yet.
                            <?php endif; ?>
                        </div>

                    <?php else: ?>

                        <div class="feedback-grid">

                        <?php foreach ($recentFeedback as $feedback): ?>
                            <?php
                            $feedbackId = (int)$feedback['id'];
                            $feedbackIsUnread = (int)$feedback['is_read'] === 0;
                            $feedbackTime = !empty($feedback['created_at']) ? strtotime((string)$feedback['created_at']) : false;
                            $feedbackOrderNumber = (string)($feedback['order_number'] ?? '');
                            /* Opens the order in Orders > Order History. */
                            $feedbackOrderUrl = $feedbackOrderNumber !== ''
                                ? 'orders.php?' . http_build_query([
                                    'cancelled_open' => '1',
                                    'history_status' => 'completed',
                                    'cancelled_period' => 'specific_month',
                                    'cancelled_month' => !empty($feedback['closed_at']) ? date('Y-m', strtotime((string)$feedback['closed_at'])) : date('Y-m'),
                                    'cancelled_q' => $feedbackOrderNumber,
                                ])
                                : '';
                            $feedbackButtons = $fbView === 'archived'
                                ? [['restore', 'bi-arrow-counterclockwise', 'Restore']]
                                : [
                                    $feedbackIsUnread
                                        ? ['read', 'bi-envelope-open', 'Mark read']
                                        : ['unread', 'bi-envelope', 'Mark unread'],
                                    ['archive', 'bi-trash', 'Delete'],
                                ];
                            ?>

                            <article class="feedback-item<?= $feedbackIsUnread ? ' unread' : '' ?>">

                                <div class="feedback-item-top">
                                    <div>
                                        <div class="feedback-name-wrap">
                                            <span class="feedback-name"><?= htmlspecialchars((string)$feedback['display_name']) ?></span>

                                            <?php if ($feedback['customer_id'] === null): ?>
                                                <span class="feedback-badge guest">Guest</span>
                                            <?php endif; ?>

                                            <?php if ($feedbackIsUnread): ?>
                                                <span class="feedback-badge new">NEW</span>
                                            <?php endif; ?>
                                        </div>

                                        <?php if (!empty($feedback['customer_email']) || !empty($feedback['customer_phone'])): ?>
                                            <div class="feedback-contact">
                                                <?= htmlspecialchars(implode(' · ', array_filter([
                                                    (string)($feedback['customer_email'] ?? ''),
                                                    (string)($feedback['customer_phone'] ?? ''),
                                                ]))) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <span class="feedback-date">
                                        <?= $feedbackTime ? htmlspecialchars(date('M d, Y · h:i A', $feedbackTime)) : '' ?>
                                    </span>
                                </div>

                                <p class="feedback-message"><?= htmlspecialchars((string)$feedback['message']) ?></p>

                                <div class="feedback-item-bottom">

                                    <div class="feedback-order">
                                        <span>Order <strong><?= htmlspecialchars($feedbackOrderNumber !== '' ? $feedbackOrderNumber : 'N/A') ?></strong></span>

                                        <?php if (!empty($feedback['claim_number'])): ?>
                                            <span>Claim <?= htmlspecialchars((string)$feedback['claim_number']) ?></span>
                                        <?php endif; ?>

                                        <?php if ($feedback['total_amount'] !== null): ?>
                                            <span>₱<?= number_format((float)$feedback['total_amount'], 2) ?></span>
                                        <?php endif; ?>

                                        <?php if ($feedbackOrderUrl !== ''): ?>
                                            <a href="<?= htmlspecialchars($feedbackOrderUrl) ?>">View order <i class="bi bi-arrow-right"></i></a>
                                        <?php endif; ?>
                                    </div>

                                    <div class="feedback-actions">
                                        <?php foreach ($feedbackButtons as [$fbAction, $fbIcon, $fbLabel]): ?>
                                            <form method="POST" action="dashboard.php">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                <input type="hidden" name="fb_return" value="<?= htmlspecialchars($fbCurrentUrl) ?>">
                                                <input type="hidden" name="feedback_id" value="<?= $feedbackId ?>">
                                                <input type="hidden" name="feedback_action" value="<?= $fbAction ?>">
                                                <button type="submit" class="feedback-btn outline<?= $fbAction === 'archive' ? ' danger' : '' ?>" data-feedback-action="<?= $fbAction ?>">
                                                    <i class="bi <?= $fbIcon ?>"></i> <?= $fbLabel ?>
                                                </button>
                                            </form>
                                        <?php endforeach; ?>
                                    </div>

                                </div>

                            </article>

                        <?php endforeach; ?>

                        </div>

                    <?php endif; ?>

                    <?php if ($fbTotal > 0): ?>
                        <div class="feedback-footer">
                            <span>
                                Showing <?= (($fbPage - 1) * $fbPerPage) + 1 ?>–<?= min($fbPage * $fbPerPage, $fbTotal) ?>
                                of <?= number_format($fbTotal) ?>
                            </span>

                            <?php if ($fbPages > 1): ?>
                                <nav class="feedback-pages" aria-label="Feedback pages">
                                    <a class="feedback-page <?= $fbPage <= 1 ? 'disabled' : '' ?>" href="<?= htmlspecialchars(dashboardFeedbackUrl(['fb_page' => max(1, $fbPage - 1)])) ?>" aria-label="Previous">&lsaquo;</a>

                                    <?php for ($fbP = max(1, $fbPage - 2); $fbP <= min($fbPages, $fbPage + 2); $fbP++): ?>
                                        <a class="feedback-page <?= $fbP === $fbPage ? 'active' : '' ?>" href="<?= htmlspecialchars(dashboardFeedbackUrl(['fb_page' => $fbP])) ?>"><?= $fbP ?></a>
                                    <?php endfor; ?>

                                    <a class="feedback-page <?= $fbPage >= $fbPages ? 'disabled' : '' ?>" href="<?= htmlspecialchars(dashboardFeedbackUrl(['fb_page' => min($fbPages, $fbPage + 1)])) ?>" aria-label="Next">&rsaquo;</a>
                                </nav>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    </div>

                </div>

                <script>
                /* Feedback tabs, filter, search, pages and buttons load
                   without refreshing the whole dashboard. */
                (function () {
                    var panel = document.getElementById('feedbackPanel');

                    if (!panel || !window.fetch || !window.DOMParser) {
                        return;
                    }

                    /* =====================================================
                       ACTION TOAST (same toast used on orders.php)
                    ===================================================== */
                    var feedbackToastMessages = {
                        read:    { title: 'Marked as read',    message: 'The feedback was marked as read.',          icon: 'bi-check-circle' },
                        unread:  { title: 'Marked as unread',  message: 'The feedback was marked as unread.',        icon: 'bi-check-circle' },
                        archive: { title: 'Feedback deleted',  message: 'The feedback was moved to Deleted.',        icon: 'bi-trash', type: 'cancel' },
                        restore: { title: 'Feedback restored', message: 'The feedback was moved back to the inbox.', icon: 'bi-check-circle' },
                        read_all:{ title: 'All marked as read',message: 'All unread feedback was marked as read.',   icon: 'bi-check-circle' }
                    };

                    function getToastStack() {
                        var stack = document.getElementById('adminAjaxToastStack');

                        if (!stack) {
                            stack = document.createElement('div');
                            stack.id = 'adminAjaxToastStack';
                            stack.className = 'orders-toast-stack';
                            stack.setAttribute('aria-live', 'polite');
                            stack.setAttribute('aria-relevant', 'additions');
                            document.body.appendChild(stack);
                        }

                        return stack;
                    }

                    function escapeToastText(value) {
                        return String(value == null ? '' : value).replace(/[&<>"']/g, function (character) {
                            return {
                                '&': '&amp;',
                                '<': '&lt;',
                                '>': '&gt;',
                                '"': '&quot;',
                                "'": '&#039;'
                            }[character];
                        });
                    }

                    function showFeedbackToast(info) {
                        if (!info) {
                            return;
                        }

                        var stack = getToastStack();
                        var toast = document.createElement('div');

                        toast.className = 'orders-toast ' + (info.type === 'cancel' ? 'orders-toast-cancel' : 'orders-toast-success');
                        toast.setAttribute('role', 'status');
                        toast.innerHTML =
                            '<span class="orders-toast-icon">' +
                                '<i class="bi ' + escapeToastText(info.icon || 'bi-check-circle') + '"></i>' +
                            '</span>' +
                            '<span class="orders-toast-copy">' +
                                '<strong class="orders-toast-title">' + escapeToastText(info.title) + '</strong>' +
                                '<span class="orders-toast-message">' + escapeToastText(info.message) + '</span>' +
                            '</span>' +
                            '<button type="button" class="orders-toast-close" aria-label="Close notification">' +
                                '<i class="bi bi-x-lg"></i>' +
                            '</button>' +
                            '<span class="orders-toast-progress" aria-hidden="true"></span>';

                        stack.appendChild(toast);

                        var isClosing = false;
                        var closeTimer = null;

                        function closeThisToast() {
                            if (isClosing) {
                                return;
                            }
                            isClosing = true;

                            if (closeTimer !== null) {
                                window.clearTimeout(closeTimer);
                            }

                            toast.classList.add('is-closing');
                            window.setTimeout(function () {
                                toast.remove();
                                var currentStack = document.getElementById('adminAjaxToastStack');
                                if (currentStack && currentStack.childElementCount === 0) {
                                    currentStack.remove();
                                }
                            }, 190);
                        }

                        var closeButton = toast.querySelector('.orders-toast-close');
                        if (closeButton) {
                            closeButton.addEventListener('click', closeThisToast);
                        }

                        closeTimer = window.setTimeout(closeThisToast, 3500);
                    }

                    /* =====================================================
                       DELETE CONFIRMATION
                    ===================================================== */
                    function confirmDelete(onConfirm) {
                        var previousFocus = document.activeElement;
                        var overlay = document.createElement('div');

                        overlay.className = 'fb-confirm-overlay';
                        overlay.innerHTML =
                            '<div class="fb-confirm-box" role="alertdialog" aria-modal="true" ' +
                                'aria-labelledby="fbConfirmTitle" aria-describedby="fbConfirmText">' +
                                '<span class="fb-confirm-icon"><i class="bi bi-trash"></i></span>' +
                                '<h3 class="fb-confirm-title" id="fbConfirmTitle">Delete this message?</h3>' +
                                '<p class="fb-confirm-text" id="fbConfirmText">' +
                                    'Are you sure you want to delete this message? ' +
                                    'It will be moved to the Deleted tab, where you can restore it.' +
                                '</p>' +
                                '<div class="fb-confirm-actions">' +
                                    '<button type="button" class="feedback-btn outline" data-confirm="cancel">Cancel</button>' +
                                    '<button type="button" class="feedback-btn danger-solid" data-confirm="yes">' +
                                        '<i class="bi bi-trash"></i> Delete' +
                                    '</button>' +
                                '</div>' +
                            '</div>';

                        function closeConfirm() {
                            document.removeEventListener('keydown', onKey);
                            overlay.remove();

                            if (previousFocus && previousFocus.focus) {
                                previousFocus.focus();
                            }
                        }

                        function onKey(event) {
                            if (event.key === 'Escape') {
                                closeConfirm();
                            }
                        }

                        overlay.addEventListener('click', function (event) {
                            if (event.target === overlay || event.target.closest('[data-confirm="cancel"]')) {
                                closeConfirm();
                                return;
                            }

                            if (event.target.closest('[data-confirm="yes"]')) {
                                closeConfirm();
                                onConfirm();
                            }
                        });

                        document.addEventListener('keydown', onKey);
                        document.body.appendChild(overlay);
                        overlay.querySelector('[data-confirm="cancel"]').focus();
                    }

                    var controller = null;
                    var searchTimer = null;

                    function load(url, options, toastInfo) {
                        if (controller) {
                            controller.abort();
                        }

                        controller = new AbortController();
                        panel.classList.add('is-loading');

                        var request = Object.assign({
                            credentials: 'same-origin',
                            signal: controller.signal
                        }, options || {});

                        fetch(url, request)
                            .then(function (response) {
                                if (!response.ok) {
                                    throw new Error('Request failed');
                                }

                                return response.text().then(function (html) {
                                    return { html: html, url: response.url };
                                });
                            })
                            .then(function (result) {
                                var doc = new DOMParser().parseFromString(result.html, 'text/html');
                                var freshSummary = doc.querySelector('#feedbackPanel .feedback-summary');
                                var freshContent = doc.getElementById('feedbackContent');

                                if (!freshSummary || !freshContent) {
                                    throw new Error('Feedback not found');
                                }

                                /* Keep typing in the search box while results update. */
                                var search = panel.querySelector('input[name="fb_q"]');
                                var keepFocus = search && document.activeElement === search;
                                var caret = keepFocus ? search.selectionStart : 0;

                                panel.querySelector('.feedback-summary').innerHTML = freshSummary.innerHTML;
                                document.getElementById('feedbackContent').innerHTML = freshContent.innerHTML;

                                if (keepFocus) {
                                    var newSearch = panel.querySelector('input[name="fb_q"]');
                                    newSearch.focus();
                                    newSearch.setSelectionRange(caret, caret);
                                }

                                history.replaceState(null, '', result.url.split('#')[0] + '#feedbackPanel');

                                /* Mark read / unread / delete / restore succeeded. */
                                showFeedbackToast(toastInfo);
                            })
                            .catch(function (error) {
                                if (error.name !== 'AbortError') {
                                    window.location.href = 'dashboard.php#feedbackPanel';
                                }
                            })
                            .finally(function () {
                                panel.classList.remove('is-loading');
                            });
                    }

                    function loadFilter(form) {
                        var query = new URLSearchParams(new FormData(form)).toString();
                        load('dashboard.php?' + query);
                    }

                    panel.addEventListener('click', function (event) {
                        var link = event.target.closest('a.feedback-tab, a.feedback-page');

                        if (!link) {
                            return;
                        }

                        event.preventDefault();

                        if (!link.classList.contains('disabled') && !link.classList.contains('active')) {
                            load(link.href);
                        }
                    });

                    panel.addEventListener('submit', function (event) {
                        var form = event.target;

                        if (!form.closest('#feedbackContent')) {
                            return;
                        }

                        event.preventDefault();
                        clearTimeout(searchTimer);

                        if (form.method.toLowerCase() === 'post') {
                            var actionField = form.elements['feedback_action'];

                            var toastInfo = actionField ? feedbackToastMessages[actionField.value] : null;

                            var formData = new FormData(form);
                            var sendAction = function () {
                                load('dashboard.php', { method: 'POST', body: formData }, toastInfo);
                            };

                            /* Deleting asks first; everything else goes straight through. */
                            if (actionField && actionField.value === 'archive') {
                                confirmDelete(sendAction);
                            } else {
                                sendAction();
                            }
                        } else {
                            loadFilter(form);
                        }
                    });

                    panel.addEventListener('change', function (event) {
                        if (event.target.name === 'fb_read') {
                            clearTimeout(searchTimer);
                            loadFilter(event.target.form);
                        }
                    });

                })();
                </script>

            </section>

            </div>

        </div>

    </main>

</div>