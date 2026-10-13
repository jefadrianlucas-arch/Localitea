<?php
require_once '../includes/db.php';


if (
    ($_GET['ajax'] ?? '') === 'status' &&
    isset($_SESSION['user_id']) &&
    ($_SESSION['user_role'] ?? '') === 'customer'
) {
    header('Content-Type: application/json; charset=utf-8');

    $statusStmt = $pdo->prepare("
        SELECT id, status
        FROM orders
        WHERE customer_id = ?
          AND status NOT IN ('completed', 'cancelled')
        ORDER BY created_at DESC, id DESC
    ");
    $statusStmt->execute([(int)$_SESSION['user_id']]);

    echo json_encode([
        'success' => true,
        'orders' => $statusStmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
    exit;
}


/* Mark customer notification as read */
if (isset($_GET['read_notification'])) {

    $notification_id = (int)$_GET['read_notification'];

    if ($notification_id > 0 && isset($_SESSION['user_id'])) {

        $stmt = $pdo->prepare("
            UPDATE notifications
            SET is_read = 1
            WHERE id = ?
              AND recipient_role = 'customer'
              AND recipient_id = ?
        ");

        $stmt->execute([
            $notification_id,
            (int)$_SESSION['user_id']
        ]);
    }

    header("Location: dashboard.php");
    exit;
}
$user_id = $_SESSION['user_id'];

/* =========================================================
   GET ACTIVE ORDERS
========================================================= */

$activeStmt = $pdo->prepare("
    SELECT *
    FROM orders
    WHERE customer_id = ?
      AND status NOT IN ('completed', 'cancelled')
    ORDER BY created_at DESC, id DESC
");

$activeStmt->execute([$user_id]);

$activeOrders = $activeStmt->fetchAll(PDO::FETCH_ASSOC);


/* =========================================================
   GET ORDER HISTORY
========================================================= */

$historyStmt = $pdo->prepare("
    SELECT *
    FROM orders
    WHERE customer_id = ?
      AND status IN ('completed', 'cancelled')
    ORDER BY closed_at DESC, created_at DESC, id DESC
");

$historyStmt->execute([$user_id]);

$orderHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);


/* =========================================================
   ORDER HISTORY PAGINATION
   10 orders per page.
   Page 1 is always kept visible in the pagination controls.
========================================================= */

$historyPerPage = 10;

$historyPage = isset($_GET['history_page'])
    ? (int)$_GET['history_page']
    : 1;

$historyTotal = count($orderHistory);

$historyTotalPages = max(
    1,
    (int)ceil($historyTotal / $historyPerPage)
);

$historyPage = max(
    1,
    min($historyPage, $historyTotalPages)
);

$historyOffset =
    ($historyPage - 1) * $historyPerPage;

$paginatedOrderHistory = array_slice(
    $orderHistory,
    $historyOffset,
    $historyPerPage
);

$historyStart =
    $historyTotal > 0
        ? $historyOffset + 1
        : 0;

$historyEnd =
    min(
        $historyOffset + $historyPerPage,
        $historyTotal
    );


/* =========================================================
   GET ITEMS FOR CURRENT ORDER
========================================================= */

$activeOrderItems = [];

foreach ($activeOrders as $activeOrder) {

    $itemStmt = $pdo->prepare("
        SELECT *
        FROM order_items
        WHERE order_id = ?
        ORDER BY id ASC
    ");

    $itemStmt->execute([
        $activeOrder['id']
    ]);

    $activeOrderItems[$activeOrder['id']] =
        $itemStmt->fetchAll(PDO::FETCH_ASSOC);
}


/* =========================================================
   GET ITEMS FOR ORDER HISTORY
========================================================= */

$historyOrderItems = [];

foreach ($orderHistory as $historyOrder) {

    $historyItemStmt = $pdo->prepare("
        SELECT *
        FROM order_items
        WHERE order_id = ?
        ORDER BY id ASC
    ");

    $historyItemStmt->execute([
        $historyOrder['id']
    ]);

    $historyOrderItems[$historyOrder['id']] =
        $historyItemStmt->fetchAll(PDO::FETCH_ASSOC);
}


/* =========================================================
   GET FEEDBACK FOR ORDER HISTORY
========================================================= */

$feedbackActionUrl = 'submit-feedback.php';
$orderFeedbackMap = [];

try {
    $feedbackStmt = $pdo->prepare("
        SELECT order_id, message, created_at
        FROM order_feedback
        WHERE customer_id = ?
    ");
    $feedbackStmt->execute([$user_id]);

    foreach ($feedbackStmt->fetchAll(PDO::FETCH_ASSOC) as $feedbackRow) {
        $orderFeedbackMap[(int)$feedbackRow['order_id']] = $feedbackRow;
    }
} catch (Throwable $e) {
    /* Keep the dashboard working even if the feedback table is missing. */
    $orderFeedbackMap = [];
}


/* =========================================================
   STATUS HELPERS
========================================================= */

$statusLabels = [
    'pending_verification' => 'Pending Verification',
    'confirmed'             => 'Order Confirmed',
    'preparing'             => 'Preparing',
    'ready'                 => 'Ready for Pick-up',
    'completed'             => 'Completed',
    'cancelled'             => 'Cancelled'
];

$statusDescriptions = [
    'pending_verification' => 'Your order has been received and is waiting for verification.',
    'confirmed'             => 'Your order has been confirmed by the store.',
    'preparing'             => 'Your order is currently being prepared.',
    'ready'                 => 'Your order is ready for pick-up.',
    'completed'             => 'Your order has been completed. Thank you!',
    'cancelled'             => 'This order has been cancelled.'
];

function statusLabel($status, $statusLabels) {
    return $statusLabels[$status] ?? ucwords(str_replace('_', ' ', $status));
}

function statusDescription($status, $statusDescriptions) {
    return $statusDescriptions[$status]
        ?? 'Your order status has been updated.';
}

function orderAddonTotal(PDO $pdo, $productId, $addons): float
{
    if ($addons === null || $addons === '') {
        return 0.0;
    }

    $decoded = json_decode((string)$addons, true);

    if (!is_array($decoded)) {
        return 0.0;
    }

    $total = 0.0;
    $addonNames = [];

    foreach ($decoded as $key => $addon) {
        if (is_array($addon)) {
            $price = $addon['price'] ?? $addon['addon_price'] ?? null;
            if ($price !== null && $price !== '' && is_numeric($price)) {
                $total += (float)$price;
                continue;
            }

            $name = $addon['name']
                ?? $addon['addon_name']
                ?? $addon['title']
                ?? null;

            if ($name !== null && trim((string)$name) !== '') {
                $addonNames[] = trim((string)$name);
            }
        } elseif (!is_int($key) && is_numeric($addon)) {
            $total += (float)$addon;
        } elseif (is_scalar($addon)) {
            $name = trim((string)$addon);
            if ($name !== '') {
                $addonNames[] = $name;
            }
        }
    }

    $addonNames = array_values(array_unique($addonNames));

    if ($productId > 0 && $addonNames) {
        $placeholders = implode(',', array_fill(0, count($addonNames), '?'));
        $stmt = $pdo->prepare("
            SELECT DISTINCT a.name, a.price
            FROM addons a
            INNER JOIN product_addons pa
                ON pa.addon_id = a.id
            WHERE pa.product_id = ?
              AND a.name IN ($placeholders)
        ");
        $stmt->execute(array_merge([(int)$productId], $addonNames));

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $addonRow) {
            $total += (float)($addonRow['price'] ?? 0);
        }
    }

    return round($total, 2);
}

function formatOrderAddons($addons)
{
    if ($addons === null || $addons === '') {
        return '';
    }

    $decoded = json_decode((string)$addons, true);

    if (!is_array($decoded)) {
        return trim((string)$addons);
    }

    $parts = [];

    foreach ($decoded as $key => $addon) {

        // Common format: {"name":"Pearls","price":10}
        if (is_array($addon)) {

            $name = $addon['name']
                ?? $addon['addon_name']
                ?? $addon['title']
                ?? null;

            $price = $addon['price']
                ?? $addon['addon_price']
                ?? null;

            if ($name !== null) {
                $part = (string)$name;

                if ($price !== null && $price !== '' && is_numeric($price)) {
                    $part .= ' (+₱' . number_format((float)$price, 2) . ')';
                }

                $parts[] = $part;
            } else {
                $parts[] = implode(': ', array_map(
                    'strval',
                    array_filter($addon, static function ($value) {
                        return $value !== null && $value !== '';
                    })
                ));
            }

        // Associative format: {"Pearls":10,"Cheese Foam":15}
        } elseif (!is_int($key) && is_numeric($addon)) {
            $parts[] = (string)$key .
                ' (+₱' . number_format((float)$addon, 2) . ')';

        // Simple format: ["Pearls","Cheese Foam"]
        } elseif (is_scalar($addon)) {
            $parts[] = (string)$addon;
        }
    }

    $parts = array_values(array_filter(array_map('trim', $parts)));

    return implode(', ', $parts);
}

/* Timeline only uses the statuses the staff can actually move through. */
$timelineStatuses = [
    'pending_verification',
    'confirmed',
    'preparing',
    'ready',
    'completed'
];

/* =========================================================
   DASHBOARD VIEW
========================================================= */

$dashboardView = $_GET['view'] ?? 'active';

if (!in_array($dashboardView, ['active', 'history'], true)) {
    $dashboardView = 'active';
}
$isHistoryAjax = (
    ($_GET['ajax'] ?? '') === 'history'
    && ($dashboardView === 'history')
    && isset($_SESSION['user_id'])
    && (($_SESSION['user_role'] ?? '') === 'customer')
);

if ($isHistoryAjax) {
    ob_start();
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
?>

<style>
    body {
        background: #F7F5F2;
    }

    .dashboard-page {
        min-height: calc(100vh - 80px);
    }

    .page-title {
        color: #4A3525;
        font-weight: 800;
        margin-bottom: 4px;
    }

    .page-subtitle {
        color: #8a7f75;
        font-size: .9rem;
        margin-bottom: 25px;
    }

    .card-custom {
        background: #ffffff;
        border: 1px solid #B8A08A;
        border-radius: 15px;
        box-shadow: 0 4px 15px rgba(0,0,0,.05);
    }

    /* =====================================================
       CURRENT ORDER
    ===================================================== */

    .current-order-card {
        overflow: hidden;
    }

    .current-order-header {
        background: #FDF8F2;
        border-bottom: 1px solid #E6DEC9;
        padding: 18px 22px;
    }

    .current-order-title {
        color: #4A3525;
        font-weight: 750;
        margin: 0;
    }

    .order-number {
        color: #8a7f75;
        font-size: .82rem;
        margin-top: 3px;
    }

    .current-status-box {
        background: #F7F1E8;
        border: 1px solid #B8A08A;
        border-radius: 12px;
        padding: 16px;
    }

    .current-status-label {
        color: #8a7f75;
        font-size: .75rem;
        text-transform: uppercase;
        letter-spacing: .5px;
        font-weight: 700;
    }

    .current-status {
        color: #4A3525;
        font-size: 1.2rem;
        font-weight: 800;
        margin-top: 3px;
    }

    .status-description {
        color: #6f655d;
        font-size: .84rem;
        margin: 5px 0 0;
    }

    /* =====================================================
       ORDER TIMELINE
    ===================================================== */

    .order-timeline {
        padding: 22px;
    }

    .timeline-title {
        color: #4A3525;
        font-size: 1rem;
        font-weight: 750;
        margin-bottom: 20px;
    }

    .timeline {
        display: flex;
        align-items: flex-start;
        position: relative;
        gap: 0;
    }

    .timeline-step {
        flex: 1;
        position: relative;
        text-align: center;
    }

    .timeline-step:not(:last-child)::after {
        content: "";
        position: absolute;
        top: 15px;
        left: 50%;
        width: 100%;
        height: 3px;
        background: #E6DEC9;
        z-index: 0;
    }

    .timeline-step.done:not(:last-child)::after {
        background: #6f4e37;
    }

    .timeline-circle {
        width: 32px;
        height: 32px;
        margin: 0 auto 8px;
        border-radius: 50%;
        background: #ffffff;
        border: 2px solid #B8A08A;
        color: #8a7f75;
        display: flex;
        align-items: center;
        justify-content: center;
        position: relative;
        z-index: 1;
        font-size: .8rem;
    }

    .timeline-step.done .timeline-circle {
        background: #6f4e37;
        border-color: #6f4e37;
        color: #ffffff;
    }

    .timeline-step.current .timeline-circle {
        box-shadow: 0 0 0 5px rgba(111,78,55,.12);
    }

    .timeline-name {
        color: #8a7f75;
        font-size: .72rem;
        font-weight: 650;
        line-height: 1.25;
    }

    .timeline-step.done .timeline-name,
    .timeline-step.current .timeline-name {
        color: #4A3525;
    }

    /* =====================================================
       ORDER INFORMATION
    ===================================================== */

    .info-label {
        color: #8a7f75;
        font-size: .72rem;
        text-transform: uppercase;
        letter-spacing: .4px;
        font-weight: 700;
    }

    .info-value {
        color: #4A3525;
        font-size: .88rem;
        font-weight: 650;
        margin-top: 2px;
    }

    .order-items-table th {
        color: #8a7f75;
        font-size: .72rem;
        text-transform: uppercase;
        border-bottom: 1px solid #E6DEC9;
    }

    .order-items-table td {
        font-size: .84rem;
        border-bottom: 1px solid #F0EAE0;
    }

    .order-item-name {
        color: #4A3525;
        font-weight: 700;
    }

    .order-item-customization {
        margin-top: 3px;
        color: #8a7f75;
        font-size: .72rem;
        line-height: 1.5;
    }

    .order-item-customization span {
        display: block;
    }

    .free-addon-price {
        margin-top: 2px;
        color: #8A7F75;
        font-size: .68rem;
        line-height: 1.3;
        white-space: nowrap;
    }


    .order-total {
        color: #4A3525;
        font-size: 1rem;
        font-weight: 800;
    }

    /* =====================================================
       ORDER HISTORY
    ===================================================== */

    .history-title {
        color: #4A3525;
        font-weight: 750;
    }

    /* =====================================================
       DASHBOARD VIEW TABS
    ===================================================== */

    .dashboard-view-tabs {
        display: flex;
        gap: 10px;
        margin-bottom: 25px;
    }

    .dashboard-view-tab {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        padding: 10px 18px;
        border: 1px solid #B8A08A;
        border-radius: 9px;
        background: #ffffff;
        color: #6f4e37;
        text-decoration: none;
        font-size: .86rem;
        font-weight: 700;
        transition: all .2s ease;
    }

    .dashboard-view-tab:hover {
        color: #4A3525;
        background: #F7F1E8;
        border-color: #8f735b;
    }

    .dashboard-view-tab.active {
        background: #6f4e37;
        border-color: #6f4e37;
        color: #ffffff;
    }

    @media (max-width: 576px) {
        .dashboard-view-tabs {
            gap: 8px;
        }

        .dashboard-view-tab {
            flex: 1;
            padding: 10px 12px;
            font-size: .8rem;
        }
    }


    .history-table th {
        color: #8a7f75;
        font-size: .72rem;
        text-transform: uppercase;
        border-bottom: 1px solid #E6DEC9;
        white-space: nowrap;
    }

    .history-table td {
        font-size: .83rem;
        vertical-align: middle;
        border-bottom: 1px solid #F0EAE0;
    }

    /* =====================================================
       ORDER HISTORY PAGINATION
    ===================================================== */

    .history-pagination {
        margin-top: 18px;
    }

    .history-pagination .pagination {
        margin-bottom: 0;
        flex-wrap: wrap;
        gap: 4px;
    }

    .history-pagination .page-link {
        color: #4A3525;
        border: 1px solid #E6DEC9;
        background: #ffffff;
        border-radius: 8px !important;
        min-width: 38px;
        text-align: center;
        font-size: .82rem;
        font-weight: 650;
        padding: 7px 10px;
    }

    .history-pagination .page-link:hover {
        color: #4A3525;
        background: #F7F1E8;
        border-color: #B8A08A;
    }

    .history-pagination .page-item.active .page-link {
        color: #ffffff;
        background: #6f4e37;
        border-color: #6f4e37;
    }

    .history-pagination .page-item.disabled .page-link {
        color: #B8A08A;
        background: #F9F7F4;
        border-color: #E6DEC9;
        cursor: not-allowed;
    }

    .history-pagination .page-link.pagination-ellipsis {
        border-color: transparent;
        background: transparent;
        min-width: 30px;
        pointer-events: none;
    }

    .history-pagination-summary {
        color: #8a7f75;
        font-size: .78rem;
        text-align: center;
        margin-top: 10px;
    }

    #historyAjaxRegion {
        position: relative;
    }

    #historyAjaxRegion.history-ajax-loading {
        opacity: .62;
        pointer-events: none;
        transition: opacity .15s ease;
    }

    .status-badge {
        display: inline-block;
        padding: 5px 9px;
        border-radius: 20px;
        font-size: .7rem;
        font-weight: 700;
    }

    .status-pending {
        background: #FFF1D6;
        color: #8a5a00;
    }

    .status-confirmed {
        background: #E7F1FF;
        color: #285B9A;
    }

    .status-preparing {
        background: #EEE7FF;
        color: #5B3A9A;
    }

    .status-ready {
        background: #E5F6EA;
        color: #23733D;
    }

    .status-completed {
        background: #E5F6EA;
        color: #23733D;
    }

    .status-cancelled {
        background: #FBE5E5;
        color: #B02A37;
    }

    .empty-state {
        padding: 45px 20px;
        text-align: center;
        color: #8a7f75;
    }

    .empty-state i {
        font-size: 2.5rem;
        color: #B8A08A;
        margin-bottom: 10px;
    }

    .btn-brown {
        background: #6f4e37;
        border: 1px solid #6f4e37;
        color: #ffffff;
        border-radius: 8px;
        font-size: .8rem;
        font-weight: 650;
        padding: 7px 12px;
    }

    .btn-brown:hover {
        background: #5b3d2e;
        border-color: #5b3d2e;
        color: #ffffff;
    }
    /* =====================================================
       MOBILE
    ===================================================== */

    @media (max-width: 768px) {
        .timeline {
            display: block;
        }

        .timeline-step {
            display: flex;
            align-items: center;
            text-align: left;
            min-height: 52px;
        }

        .timeline-step:not(:last-child)::after {
            top: 32px;
            left: 15px;
            width: 3px;
            height: 52px;
        }

        .timeline-circle {
            margin: 0 12px 0 0;
            flex-shrink: 0;
        }

        .timeline-name {
            font-size: .78rem;
        }

        .current-order-header {
            padding: 16px;
        }

        .order-timeline {
            padding: 16px;
        }
    }
    .order-cancel-action {
    padding-top: 4px;
}

.order-cancel-action .btn {
    min-width: 150px;
    font-weight: 600;
}
/* =====================================================
   MOBILE ORDER HISTORY
===================================================== */

@media (max-width: 768px) {

    .history-table thead {
        display: none;
    }

    .history-table,
    .history-table tbody,
    .history-table tr,
    .history-table td {
        display: block;
        width: 100%;
    }

    .history-table tr {
        background: #fffdf9;
        border: 1px solid #E6DEC9;
        border-radius: 12px;
        padding: 14px;
        margin-bottom: 12px;
    }

    .history-table td {
        border: none !important;
        padding: 7px 0;
        text-align: left !important;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 15px;
    }

    /* Labels */
    .history-table td:nth-child(1)::before {
        content: "Order #";
    }

    .history-table td:nth-child(2)::before {
        content: "Pickup";
    }

    .history-table td:nth-child(3)::before {
        content: "Total";
    }

    .history-table td:nth-child(4)::before {
        content: "Payment";
    }

    .history-table td:nth-child(5)::before {
        content: "Status";
    }

    .history-table td:nth-child(6)::before {
        content: "";
    }

    .history-table td::before {
        color: #8a7f75;
        font-size: .72rem;
        font-weight: 700;
        flex-shrink: 0;
    }

    /* Order number */
    .history-table td:first-child {
        padding-top: 0;
        display: block;
    }

    .history-table td:first-child::before {
        display: block;
        margin-bottom: 4px;
    }

    /* Claim number */
    .history-table td:first-child .claim-history-number {
        display: block;
        margin-top: 4px;
        color: #6f4e37 !important;
        font-size: .78rem;
        font-weight: 700;
    }

    /* Pickup */
    .history-table td:nth-child(2) {
        align-items: flex-start;
    }

    /* Status */
    .history-table td:nth-child(5) {
        justify-content: space-between;
    }

    /* View Details */
    .history-table td:last-child {
        padding-top: 12px;
        margin-top: 5px;
        border-top: 1px solid #E6DEC9 !important;
    }

    .history-table td:last-child .btn {
        width: 100%;
        display: block;
    }
}
/* =====================================================
   RESPONSIVE ORDER DETAILS MODAL
===================================================== */

.historyOrderModal .modal-content > hr,
.historyOrderModal .modal-content > h6,
.historyOrderModal .modal-content > .table-responsive {
    margin-left: 20px;
    margin-right: 20px;
}
@media (max-width: 768px) {

    .modal-dialog.modal-lg {
        width: auto;
        max-width: none;
        margin: 10px;
    }

    .modal-content {
        border-radius: 12px;
    }

    .modal-header {
        padding: 14px 16px;
    }

    .modal-title {
        font-size: 1rem;
    }

    .modal-body {
        padding: 16px;
        overflow-x: hidden;
    }

    /* Order number and claim number */
    .modal-body h5 {
        font-size: 1rem;
        line-height: 1.4;
        word-break: break-word;
    }

    .modal-body .order-timeline {
        padding: 12px 0;
    }

    /* Order information becomes one column */
    .modal-body .row.g-3 > [class*="col-md-3"] {
        width: 100%;
    }

    .info-label {
        font-size: .68rem;
    }

    .info-value {
        font-size: .9rem;
        word-break: break-word;
    }

    /* Make order items readable on small screens */
    .order-items-table {
        min-width: 500px;
    }

    .modal-body .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    /* Cancellation reason */
    .modal-body .alert {
        font-size: .9rem;
        line-height: 1.5;
    }

    /* Modal buttons */
    .modal-footer {
        padding: 12px 16px;
        gap: 8px;
    }

    .modal-footer .btn {
        flex: 1;
        min-width: 0;
    }
}
.claim-number {
    color: #8a7f75;
    font-size: .8rem;
    margin-top: 3px;
}

.claim-number strong {
    color: #4A3525;
    font-size: .9rem;
    font-weight: 700;
}
.claim-history-number {
    display: block;
    margin-top: 3px;
    color: #6f4e37 !important;
    font-size: .78rem;
    font-weight: 700;
}
</style>


<style>
    /* =====================================================
       ORDER HISTORY - ENHANCED UI
    ===================================================== */

    #orderHistory .table-responsive {
        border: 1px solid #E6DEC9;
        border-radius: 12px;
    }

    .history-table {
        margin-bottom: 0;
        --bs-table-bg: transparent;
    }

    .history-table thead th {
        background: #FBF8F4;
        padding: 12px 16px;
        font-size: .68rem;
        letter-spacing: .5px;
        border-bottom: 1px solid #E6DEC9;
    }

    .history-table tbody td {
        padding: 14px 16px;
    }

    .history-table tbody tr:last-child td {
        border-bottom: 0;
    }

    .history-table tbody tr {
        transition: background .15s ease;
    }

    .history-table tbody tr:hover {
        background: #FCFAF7;
    }

    .history-pay {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 999px;
        border: 1px solid #E6DEC9;
        background: #FBF8F4;
        color: #6f4e37;
        font-size: .72rem;
        font-weight: 700;
    }

    .history-pay-gcash {
        background: #E7F1FF;
        border-color: #BBD3F0;
        color: #285B9A;
    }

    .history-pay-cash {
        background: #EAF5EC;
        border-color: #C5DFCB;
        color: #3F7A4D;
    }

    .history-table .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .history-table .status-badge::before {
        content: "";
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: currentColor;
    }

    .history-view-btn {
        position: relative;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        padding: 7px 13px;
        border: 1px solid #6f4e37;
        border-radius: 9px;
        background: #ffffff;
        color: #6f4e37;
        font-size: .78rem;
        font-weight: 700;
        transition: all .15s ease;
    }

    .history-view-btn:hover {
        background: #6f4e37;
        border-color: #6f4e37;
        color: #ffffff;
    }

    .history-feedback-dot {
        position: absolute;
        top: -4px;
        right: -4px;
        width: 10px;
        height: 10px;
        border-radius: 50%;
        background: #C7922E;
        border: 2px solid #ffffff;
    }

    /* =====================================================
       ORDER HISTORY - COMPACT DETAILS MODAL
    ===================================================== */

    .history-modal-dialog {
        max-width: 520px;
    }

    .historyOrderModal .modal-content {
        border: 0;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 18px 50px rgba(74,53,37,.22);
    }

    .historyOrderModal .modal-header {
        padding: 14px 20px;
        background: #FDF8F2;
        border-bottom: 1px solid #E6DEC9;
    }

    .historyOrderModal .modal-title {
        color: #4A3525;
        font-size: 1rem;
        font-weight: 750;
    }

    .historyOrderModal .modal-body {
        padding: 18px 20px;
        overflow-x: hidden;
    }

    .historyOrderModal .modal-body > .d-flex.mb-4 {
        margin-bottom: .75rem !important;
    }

    .historyOrderModal .modal-body h5 {
        font-size: 1rem;
        margin-bottom: 2px !important;
    }

    .historyOrderModal .modal-footer {
        padding: 10px 20px;
        border-top: 1px solid #E6DEC9;
        background: #FDF8F2;
    }

    /* Timeline stays horizontal and small, even on phones */
    .historyOrderModal .order-timeline {
        padding: 10px 0 4px;
    }

    .historyOrderModal .timeline-title {
        display: none;
    }

    .historyOrderModal .timeline {
        display: flex;
    }

    .historyOrderModal .timeline-step {
        display: block;
        min-height: 0;
        text-align: center;
    }

    .historyOrderModal .timeline-step:not(:last-child)::after {
        top: 12px;
        left: 50%;
        width: 100%;
        height: 2px;
    }

    .historyOrderModal .timeline-circle {
        width: 26px;
        height: 26px;
        margin: 0 auto 6px;
        font-size: .72rem;
    }

    .historyOrderModal .timeline-name {
        font-size: .62rem;
    }

    /* Order info as a tidy 2x2 grid */
    .historyOrderModal hr {
        display: none;
    }

    .historyOrderModal .row.g-3 {
        margin: 12px 0 0;
        padding: 12px;
        background: #FBF8F4;
        border: 1px solid #E6DEC9;
        border-radius: 12px;
        --bs-gutter-x: .5rem;
        --bs-gutter-y: .6rem;
    }

    .historyOrderModal .row.g-3 > .col-md-3 {
        width: 50%;
        flex: 0 0 auto;
    }

    .historyOrderModal .info-label {
        font-size: .64rem;
    }

    .historyOrderModal .info-value {
        font-size: .84rem;
    }

    .historyOrderModal .alert-danger {
        margin-top: 12px !important;
        padding: 9px 12px;
        font-size: .8rem;
        border-radius: 10px;
    }

    .history-section-title {
        display: flex;
        align-items: center;
        gap: 7px;
        margin: 18px 0 8px;
        color: #4A3525;
        font-size: .82rem;
        font-weight: 750;
    }

    .history-section-title i {
        color: #6f4e37;
    }

    .historyOrderModal .order-items-table {
        min-width: 0;
    }

    .historyOrderModal .table-responsive {
        border: 1px solid #E6DEC9;
        border-radius: 12px;
        margin: 0;
    }

    .historyOrderModal .order-items-table th {
        padding: 8px 10px;
        background: #FBF8F4;
        font-size: .64rem;
    }

    .historyOrderModal .order-items-table td {
        padding: 9px 10px;
        font-size: .78rem;
    }

    .historyOrderModal .order-items-table tbody tr:last-child td {
        border-bottom: 0;
    }

    /* Feedback inside the modal */
    .history-feedback-box {
        margin-top: 4px;
        padding: 0;
        border: 0 !important;
    }

    .history-feedback-box .history-section-title {
        margin-top: 16px;
    }

    .history-feedback-box textarea {
        border-color: #D8C9BD;
        border-radius: 10px;
        font-size: .84rem;
        resize: none;
    }

    .history-feedback-box textarea:focus {
        border-color: #6f4e37;
        box-shadow: 0 0 0 3px rgba(111,78,55,.12);
    }

    .history-feedback-box .alert-success {
        padding: 10px 12px;
        font-size: .82rem;
        border-radius: 10px;
    }

    @media (max-width: 768px) {
        .history-modal-dialog {
            margin: 10px auto;
            max-width: calc(100% - 20px);
        }

        .historyOrderModal .modal-body {
            padding: 14px 16px;
        }

        .historyOrderModal .modal-footer .btn {
            flex: 0 0 auto;
        }

        .history-table .history-view-btn {
            width: 100%;
        }
    }
</style>


<style>
    /* =====================================================
       ACTIVE ORDERS - ENHANCED UI
    ===================================================== */

    .active-order-card {
        border: 1px solid #E0D3C4;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 6px 22px rgba(74,53,37,.07);
        transition: box-shadow .2s ease, transform .2s ease;
    }

    .active-order-card:hover {
        box-shadow: 0 10px 28px rgba(74,53,37,.11);
    }

    .active-order-card .current-order-header {
        padding: 16px 22px;
        background: linear-gradient(135deg, #FDF8F2 0%, #F6EBDD 100%);
        border-bottom: 1px solid #E6DEC9;
    }

    .active-order-card .current-order-header h5 {
        font-size: 1.08rem;
        letter-spacing: -.01em;
    }

    .active-order-card .claim-history-number {
        display: inline-block;
        margin-top: 4px;
        padding: 3px 10px;
        border-radius: 999px;
        background: #ffffff;
        border: 1px solid #E0D3C4;
        font-size: .72rem;
    }

    .active-order-card .current-order-header .status-badge {
        padding: 7px 14px;
        font-size: .76rem;
        box-shadow: 0 1px 2px rgba(0,0,0,.05);
    }

    .active-order-card > .p-4 {
        padding: 20px 22px !important;
    }

    /* Current status */
    .active-order-card .current-status-box {
        position: relative;
        padding: 16px 18px 16px 22px;
        margin-bottom: 14px !important;
        border: 1px solid #E0D3C4;
        border-radius: 14px;
        background: linear-gradient(135deg, #FBF6EE 0%, #F5EBDD 100%);
        overflow: hidden;
    }

    .active-order-card .current-status-box::before {
        content: "";
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: 5px;
        background: #6f4e37;
    }

    .active-order-card .current-status-label {
        display: flex;
        align-items: center;
        gap: 7px;
        font-size: .68rem;
    }

    .live-dot {
        position: relative;
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #3F8A55;
    }

    .live-dot::after {
        content: "";
        position: absolute;
        inset: 0;
        border-radius: 50%;
        background: #3F8A55;
        animation: livePulse 1.8s ease-out infinite;
    }

    @keyframes livePulse {
        0%   { transform: scale(1);   opacity: .6; }
        100% { transform: scale(3);   opacity: 0;  }
    }

    @media (prefers-reduced-motion: reduce) {
        .live-dot::after { animation: none; }
        .active-order-card { transition: none; }
    }

    .active-order-card .current-status {
        font-size: 1.25rem;
    }

    .active-order-card .status-description {
        font-size: .82rem;
    }

    /* Timeline */
    .active-order-card hr {
        display: none;
    }

    .active-order-card .order-timeline {
        padding: 14px 6px 8px;
    }

    .active-order-card .timeline-title {
        margin-bottom: 18px;
        font-size: .74rem;
        font-weight: 750;
        text-transform: uppercase;
        letter-spacing: .6px;
        color: #8a7f75;
    }

    @media (min-width: 769px) {
        .active-order-card .timeline-step:not(:last-child)::after {
            height: 4px;
            top: 14px;
            border-radius: 4px;
        }
    }

    .active-order-card .timeline-circle {
        width: 32px;
        height: 32px;
        border-width: 2px;
        transition: all .2s ease;
    }

    .active-order-card .timeline-step.current .timeline-circle {
        box-shadow: 0 0 0 6px rgba(111,78,55,.14);
        transform: scale(1.08);
    }

    .active-order-card .timeline-step.current .timeline-name {
        font-weight: 800;
    }

    /* Info tiles */
    .active-order-card .row.g-4 {
        --bs-gutter-x: .75rem;
        --bs-gutter-y: .75rem;
        margin-top: 8px;
        margin-bottom: 18px !important;
    }

    .active-order-card .row.g-4 > .col-md-3 {
        width: 25%;
    }

    .active-order-card .row.g-4 > .col-md-3 > div:first-child,
    .active-order-card .row.g-4 > .col-md-3 {
        min-width: 0;
    }

    .active-order-card .row.g-4 > .col-md-3 {
        position: relative;
    }

    .active-order-card .row.g-4 > .col-md-3::before {
        content: "";
        position: absolute;
        inset: 0 calc(var(--bs-gutter-x) * .5);
        background: #FBF8F4;
        border: 1px solid #E6DEC9;
        border-radius: 12px;
        z-index: 0;
    }

    .active-order-card .row.g-4 > .col-md-3 > * {
        position: relative;
        z-index: 1;
        padding-left: 14px;
        padding-right: 8px;
    }

    .active-order-card .row.g-4 > .col-md-3 > .info-label {
        padding-top: 11px;
    }

    .active-order-card .row.g-4 > .col-md-3 > .info-value {
        padding-bottom: 11px;
        font-size: .92rem;
    }

    /* Items */
    .active-order-card h6.fw-bold {
        display: flex;
        align-items: center;
        gap: 7px;
        margin-bottom: 10px !important;
        font-size: .82rem;
        text-transform: uppercase;
        letter-spacing: .5px;
        color: #8a7f75 !important;
    }

    .active-order-card .table-responsive {
        border: 1px solid #E6DEC9;
        border-radius: 12px;
        margin-bottom: 16px !important;
    }

    .active-order-card .order-items-table th {
        padding: 9px 14px;
        background: #FBF8F4;
        font-size: .66rem;
    }

    .active-order-card .order-items-table td {
        padding: 11px 14px;
    }

    .active-order-card .order-items-table tbody tr:last-child td {
        border-bottom: 0;
    }

    /* Footer: total + cancel */
    .active-order-card > .p-4 > .d-flex:last-child {
        padding-top: 14px;
        border-top: 1px dashed #D8C9BD;
    }

    .active-order-card > .p-4 > .d-flex:last-child > div:first-child {
        font-size: 1.1rem;
        font-weight: 800;
        color: #4A3525;
    }

    .active-order-card > .p-4 > .d-flex:last-child > div:first-child strong {
        font-size: .8rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .4px;
        color: #8a7f75 !important;
        margin-right: 4px;
    }

    .active-order-card .btn-outline-danger {
        padding: 8px 16px;
        border-radius: 10px;
        font-size: .82rem;
        font-weight: 700;
    }

    /* Cancel modals */
    .modal .modal-content {
        border-radius: 14px;
    }

    @media (max-width: 768px) {
        .active-order-card .current-order-header {
            padding: 14px 16px;
        }

        .active-order-card > .p-4 {
            padding: 16px !important;
        }

        .active-order-card .row.g-4 > .col-md-3 {
            width: 50%;
        }

        .active-order-card .order-items-table {
            min-width: 440px;
        }

        .active-order-card > .p-4 > .d-flex:last-child .btn {
            width: 100%;
        }
    }
</style>

<div class="dashboard-page">
    <div class="container py-5">

        <h2 class="page-title">Active Orders & Order History</h2>
        <p class="page-subtitle">
            Monitor your current order status and view your previous orders.
        </p> 

        

            
        <!-- =====================================================
             ORDER VIEW TABS
        ====================================================== -->

        <div class="dashboard-view-tabs" aria-label="Order view selection">

            <a
                href="dashboard.php?view=active"
                class="dashboard-view-tab <?= $dashboardView === 'active' ? 'active' : '' ?>"
            >
                <i class="bi bi-hourglass-split"></i>
                Active Orders
            </a>

            <a
                href="dashboard.php?view=history&history_page=1"
                class="dashboard-view-tab <?= $dashboardView === 'history' ? 'active' : '' ?>"
             >
                <i class="bi bi-clock-history"></i>
                Order History
            </a>

        </div>


        <?php if ($dashboardView === 'active'): ?>


<!-- =====================================================
     ACTIVE ORDERS
====================================================== -->

<div class="card-custom p-4 mb-4">

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">

        <h4 class="history-title mb-0">
            Active Orders
        </h4>

        <span class="text-muted small">
            <?= count($activeOrders) ?> active order(s)
        </span>

    </div>

    <?php if (empty($activeOrders)): ?>

        <div class="empty-state">
            <i class="bi bi-receipt"></i>
            <p class="mb-0">
                You don't have any active orders.
            </p>
        </div>

    <?php else: ?>

        <?php foreach ($activeOrders as $activeOrder): ?>

            <?php
            $activeStatus = $activeOrder['status'];

            $activeItems =
                $activeOrderItems[$activeOrder['id']] ?? [];

            $activeTimelineStatuses = [
                'pending_verification',
                'confirmed',
                'preparing',
                'ready',
                'completed'
            ];

            $activeStatusIndex = array_search(
                $activeStatus,
                $activeTimelineStatuses,
                true
            );

            if ($activeStatusIndex === false) {
                $activeStatusIndex = -1;
            }
            ?>

            <div class="card-custom active-order-card mb-4">

                <div class="current-order-header">

                    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">

                        <div>

                           <h5 class="fw-bold mb-1" style="color:#4A3525;">
                            Order #
                            <?= htmlspecialchars($activeOrder['order_number'] ?? 'N/A') ?>
                        </h5>

                        <small class="claim-history-number">
                            Claim No. <?= htmlspecialchars(
                                $activeOrder['claim_number'] ?? 'N/A'
                            ) ?>
                        </small>
                        </div>     
                          

                        <span class="status-badge status-<?= htmlspecialchars($activeStatus) ?>" data-active-order-status="<?= (int)$activeOrder['id'] ?>">
                            <?= htmlspecialchars(
                                statusLabel(
                                    $activeStatus,
                                    $statusLabels
                                )
                            ) ?>
                        </span>

                    </div>

                </div>

                <div class="p-4">

                    <!-- CURRENT STATUS -->

                    <div class="current-status-box mb-4">

                        <div class="current-status-label">
                            <span class="live-dot"></span>
                            Current Order Status
                        </div>

                        <div class="current-status">
                            <span data-active-order-current="<?= (int)$activeOrder['id'] ?>">
                                <?= htmlspecialchars(
                                    statusLabel(
                                        $activeStatus,
                                        $statusLabels
                                    )
                                ) ?>
                            </span>
                        </div>

                        <p class="status-description">
                            <?= htmlspecialchars(
                                statusDescription(
                                    $activeStatus,
                                    $statusDescriptions
                                )
                            ) ?>
                        </p>

                    </div>


                    <!-- ORDER PROCESS -->

                    <div class="order-timeline">

                        <div class="timeline-title">
                            Order Process
                        </div>

                        <div class="timeline">

                            <?php foreach (
                                $activeTimelineStatuses
                                as $index => $timelineStatus
                            ): ?>

                                <?php
                                $isDone =
                                    $activeStatusIndex >= $index;

                                $isCurrent =
                                    $activeStatus === $timelineStatus;
                                ?>

                                <div class="timeline-step
                                    <?= $isDone ? 'done' : '' ?>
                                    <?= $isCurrent ? 'current' : '' ?>">

                                    <div class="timeline-circle">

                                        <?php if ($isDone): ?>

                                            <i class="bi bi-check"></i>

                                        <?php else: ?>

                                            <i class="bi bi-circle"></i>

                                        <?php endif; ?>

                                    </div>

                                    <div class="timeline-name">

                                        <?= htmlspecialchars(
                                            statusLabel(
                                                $timelineStatus,
                                                $statusLabels
                                            )
                                        ) ?>

                                    </div>

                                </div>

                            <?php endforeach; ?>

                        </div>

                    </div>


                    <hr>


                    <!-- ORDER INFORMATION -->

                    <div class="row g-4 mb-4">

                        <div class="col-md-3">

                            <div class="info-label">
                                Pickup Date
                            </div>

                            <div class="info-value">
                                <?= htmlspecialchars(
                                    $activeOrder['pickup_date']
                                ) ?>
                            </div>

                        </div>


                        <div class="col-md-3">

                            <div class="info-label">
                                Pickup Time
                            </div>

                            <div class="info-value">
                                <?= htmlspecialchars(
                                    date(
                                        'g:i A',
                                        strtotime(
                                            $activeOrder['pickup_time']
                                        )
                                    )
                                ) ?>
                            </div>

                        </div>


                        <div class="col-md-3">

                            <div class="info-label">
                                Payment
                            </div>

                            <div class="info-value">
                                <?= htmlspecialchars(
                                    ucfirst(
                                        $activeOrder['payment_method']
                                    )
                                ) ?>
                            </div>

                        </div>


                        <div class="col-md-3">

                            <div class="info-label">
                                Total
                            </div>

                            <div class="info-value">
                                ₱<?= number_format(
                                    $activeOrder['total_amount'],
                                    2
                                ) ?>
                            </div>

                        </div>

                    </div>


                    <!-- ORDER DETAILS -->

                    <?php if ($activeItems): ?>

                        <h6 class="fw-bold mb-3" style="color:#4A3525;">
                            Order Details
                        </h6>

                        <div class="table-responsive mb-4">

                            <table class="table order-items-table mb-0">

                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th class="text-center">Qty</th>
                                        <th class="text-end">Price</th>
                                        <th class="text-end">Subtotal</th>
                                    </tr>
                                </thead>

                                <tbody>

                                    <?php foreach ($activeItems as $item): ?>

                                        <?php
                                        $customizations = [];

                                        if (!empty($item['size'])) {
                                            $customizations[] =
                                                'Size: ' . (string)$item['size'];
                                        }

                                        if (!empty($item['sugar_level'])) {
                                            $customizations[] =
                                                'Sugar: ' . (string)$item['sugar_level'];
                                        }

                                        $addonText = formatOrderAddons(
                                            $item['addons'] ?? null
                                        );

                                        if ($addonText !== '') {
                                            $customizations[] =
                                                'Add-ons: ' . $addonText;
                                        }
                                        ?>

                                        <tr>

                                            <td>
                                                <div class="order-item-name">
                                                    <?= htmlspecialchars(
                                                        $item['product_name']
                                                    ) ?>
                                                </div>

                                                <?php if ($customizations): ?>
                                                    <div class="order-item-customization">
                                                        <?php foreach ($customizations as $customization): ?>
                                                            <span>
                                                                <?= htmlspecialchars($customization) ?>
                                                            </span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>

                                            <td class="text-center">
                                                <?= (int)$item['quantity'] ?>
                                            </td>

                                            <?php
                                            $isFreeOrderItem = str_ends_with(
                                                trim((string)($item['product_name'] ?? '')),
                                                ' (FREE)'
                                            );
                                            $itemQuantity = max(1, (int)($item['quantity'] ?? 1));
                                            $itemAddonTotal = $isFreeOrderItem
                                                ? orderAddonTotal(
                                                    $pdo,
                                                    (int)($item['product_id'] ?? 0),
                                                    $item['addons'] ?? null
                                                )
                                                : 0.0;
                                            $freeItemAddonSubtotal = round(
                                                $itemAddonTotal * $itemQuantity,
                                                2
                                            );
                                            ?>

                                            <td class="text-end">
                                                <?php if ($isFreeOrderItem): ?>
                                                    <div class="fw-semibold text-success">FREE</div>
                                                    <?php if ($itemAddonTotal > 0): ?>
                                                        <div class="free-addon-price">
                                                            + ₱<?= number_format($itemAddonTotal, 2) ?> add-ons
                                                        </div>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    ₱<?= number_format(
                                                        (float)$item['unit_price'],
                                                        2
                                                    ) ?>
                                                <?php endif; ?>
                                            </td>

                                            <td class="text-end">
                                                <?php if ($isFreeOrderItem): ?>
                                                    <?php if ($freeItemAddonSubtotal > 0): ?>
                                                        <span class="fw-semibold">
                                                            ₱<?= number_format($freeItemAddonSubtotal, 2) ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="fw-semibold text-success">FREE</span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    ₱<?= number_format(
                                                        (float)$item['subtotal'],
                                                        2
                                                    ) ?>
                                                <?php endif; ?>
                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    <?php endif; ?>


                    <!-- TOTAL + CANCEL -->

                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">

                        <div>

                            <strong style="color:#4A3525;">
                                Total:
                            </strong>

                            ₱<?= number_format(
                                $activeOrder['total_amount'],
                                2
                            ) ?>

                        </div>


                        <?php if (
                            in_array(
                                $activeStatus,
                                ['pending_verification', 'confirmed'],
                                true
                            )
                        ): ?>

                            <button
                                type="button"
                                class="btn btn-outline-danger"
                                data-bs-toggle="modal"
                                data-bs-target="#cancelOrderModal<?= (int)$activeOrder['id'] ?>"
                            >
                                <i class="bi bi-x-circle me-1"></i>
                                Cancel Order
                            </button>

                        <?php elseif (
                            in_array(
                                $activeStatus,
                                ['preparing', 'ready'],
                                true
                            )
                        ): ?>

                            <button
                                type="button"
                                class="btn btn-outline-danger"
                                data-bs-toggle="modal"
                                data-bs-target="#cannotCancelModal"
                            >
                                <i class="bi bi-x-circle me-1"></i>
                                Cancel Order
                            </button>

                        <?php endif; ?>

                    </div>

                </div>

            </div>


            <!-- =================================================
                 CANCEL MODAL FOR THIS ORDER
            ================================================== -->

            <?php if (
                in_array(
                    $activeStatus,
                    ['pending_verification', 'confirmed'],
                    true
                )
            ): ?>

                <div
                    class="modal fade"
                    id="cancelOrderModal<?= (int)$activeOrder['id'] ?>"
                    tabindex="-1"
                    aria-hidden="true"
                >

                    <div class="modal-dialog modal-dialog-centered">

                        <div class="modal-content">

                            <div class="modal-header">

                                <h5 class="modal-title">
                                    Cancel Order
                                </h5>

                                <button
                                    type="button"
                                    class="btn-close"
                                    data-bs-dismiss="modal"
                                ></button>

                            </div>


                            <form
                                method="POST"
                                action="cancel-order.php" data-ajax-form="true" data-ajax-loading-text="Cancelling order..."
                            >

                                <div class="modal-body">

                                    <p>
                                        Are you sure you want to cancel this order?
                                    </p>

                                    <label class="form-label fw-semibold">
                                        Reason for cancellation
                                    </label>

                                    <select
                                        name="cancellation_reason"
                                        class="form-select"
                                        required
                                    >

                                        <option value="">
                                            Select a reason
                                        </option>

                                        <option value="Changed my mind">
                                            Changed my mind
                                        </option>

                                        <option value="Ordered by mistake">
                                            Ordered by mistake
                                        </option>

                                        <option value="Pickup time is no longer convenient">
                                            Pickup time is no longer convenient
                                        </option>

                                        <option value="Wrong order details">
                                            Wrong order details
                                        </option>

                                        <option value="Other">
                                            Other
                                        </option>

                                    </select>

                                    <input
                                        type="hidden"
                                        name="order_id"
                                        value="<?= (int)$activeOrder['id'] ?>"
                                    >

                                </div>


                                <div class="modal-footer">

                                    <button
                                        type="button"
                                        class="btn btn-secondary"
                                        data-bs-dismiss="modal"
                                    >
                                        Keep Order
                                    </button>

                                    <button
                                        type="submit"
                                        class="btn btn-danger"
                                    >
                                        Confirm Cancellation
                                    </button>

                                </div>

                            </form>

                        </div>

                    </div>

                </div>

            <?php endif; ?>

        <?php endforeach; ?>

    <?php endif; ?>

</div>


        <?php endif; ?>


<!-- =====================================================
     ORDER HISTORY
===================================================== -->

        <?php if ($dashboardView === 'history'): ?>

<!-- AJAX_HISTORY_START -->
<div id="historyAjaxRegion">

<div class="card-custom p-4" id="orderHistory">

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">

        <h4 class="history-title mb-0">
            Order History
        </h4>

        <span class="text-muted small">
            <?= count($orderHistory) ?> order(s)
        </span>

    </div>


    <?php if (empty($orderHistory)): ?>

        <div class="empty-state">

            <i class="bi bi-receipt"></i>

            <p class="mb-0">
                You don't have any completed or cancelled orders yet.
            </p>

        </div>

    <?php else: ?>

        <div class="table-responsive">

            <table class="table history-table align-middle mb-0">

                <thead>

                    <tr>

                        <th>Order #</th>

                        <th>Pickup</th>

                        <th>Total</th>

                        <th>Payment</th>

                        <th>Status</th>

                        <th class="text-end">
                            Action
                        </th>

                    </tr>

                </thead>


                <!-- =================================================
                     HISTORY TABLE ROWS ONLY
                     DO NOT PUT MODALS INSIDE TBODY
                ================================================== -->

                <tbody>

    <?php foreach ($paginatedOrderHistory as $ord): ?>

        <tr>

            <td>
                <div
                    class="fw-bold"
                    style="color:#4A3525;"
                >
                    <?= htmlspecialchars(
                        $ord['order_number'] ?? 'N/A'
                    ) ?>
                </div>

                <small class="text-muted">
                    <?= htmlspecialchars(
                        $ord['claim_number'] ?? 'N/A'
                    ) ?>
                </small>
            </td>

            <td>
                <?= htmlspecialchars(
                    $ord['pickup_date']
                ) ?>

                <br>

                <small class="text-muted">
                    <?= htmlspecialchars(
                        date(
                            'g:i A',
                            strtotime(
                                $ord['pickup_time']
                            )
                        )
                    ) ?>
                </small>
            </td>

            <td>
                ₱<?= number_format(
                    $ord['total_amount'],
                    2
                ) ?>
            </td>

            <td>
                <span class="history-pay <?= strtolower((string)$ord['payment_method']) === 'gcash' ? 'history-pay-gcash' : 'history-pay-cash' ?>">
                    <?= htmlspecialchars(
                        ucfirst(
                            $ord['payment_method']
                        )
                    ) ?>
                </span>
            </td>

            <td>
                <span
                    class="status-badge status-<?= htmlspecialchars(
                        $ord['status']
                    ) ?>"
                >
                    <?= htmlspecialchars(
                        statusLabel(
                            $ord['status'],
                            $statusLabels
                        )
                    ) ?>
                </span>
            </td>

            <td class="text-end">

                <button
                    type="button"
                    class="btn btn-sm history-view-btn"
                    data-bs-toggle="modal"
                    data-bs-target="#historyOrderModal<?= (int)$ord['id'] ?>"
                >
                    <i class="bi bi-eye"></i>
                    View Details

                    <?php if (
                        ($ord['status'] ?? '') === 'completed'
                        && !isset($orderFeedbackMap[(int)$ord['id']])
                    ): ?>
                        <span
                            class="history-feedback-dot"
                            data-feedback-btn="<?= (int)$ord['id'] ?>"
                            title="Feedback pending"
                        ></span>
                    <?php endif; ?>
                </button>

            </td>

        </tr>

    <?php endforeach; ?>

</tbody>
</table>

        </div>


        <?php if ($historyTotal > 0 && $historyTotalPages > 1): ?>

            <!-- =================================================
                 ORDER HISTORY PAGINATION
                 Page 1 is always visible.
            ================================================== -->

            <nav
                class="history-pagination"
                aria-label="Order history pagination"
            >

                <ul class="pagination justify-content-center">

                    <?php
                    $pageUrl = function ($page) {
                        return 'dashboard.php?view=history&history_page=' .
                            (int)$page;
                            
                    };
                    ?>

                    <!-- FIRST -->
                    <li class="page-item <?= $historyPage === 1 ? 'disabled' : '' ?>">
                        <a
                            class="page-link"
                            href="<?= htmlspecialchars($pageUrl(1)) ?>"
                            aria-label="First page"
                        >
                            <i class="bi bi-chevron-double-left"></i>
                        </a>
                    </li>

                    <!-- PREVIOUS -->
                    <li class="page-item <?= $historyPage === 1 ? 'disabled' : '' ?>">
                        <a
                            class="page-link"
                            href="<?= htmlspecialchars(
                                $pageUrl(max(1, $historyPage - 1))
                            ) ?>"
                            aria-label="Previous page"
                        >
                            <i class="bi bi-chevron-left"></i>
                        </a>
                    </li>


                    <?php
                    /*
                     * Always show page 1.
                     * Then show the current page and its nearest neighbors.
                     * Ellipses are used when there is a larger gap.
                     */

                    $visiblePages = [1];

                    $rangeStart = max(
                        2,
                        $historyPage - 1
                    );

                    $rangeEnd = min(
                        $historyTotalPages - 1,
                        $historyPage + 1
                    );

                    for ($page = $rangeStart; $page <= $rangeEnd; $page++) {
                        $visiblePages[] = $page;
                    }

                    if ($historyTotalPages > 1) {
                        $visiblePages[] = $historyTotalPages;
                    }

                    $visiblePages = array_values(
                        array_unique($visiblePages)
                    );
                    ?>

                    <?php
                    $previousDisplayedPage = null;

                    foreach ($visiblePages as $page):
                    ?>

                        <?php if (
                            $previousDisplayedPage !== null &&
                            $page > $previousDisplayedPage + 1
                        ): ?>

                            <li class="page-item">
                                <span class="page-link pagination-ellipsis">
                                    …
                                </span>
                            </li>

                        <?php endif; ?>


                        <li class="page-item <?= $page === $historyPage ? 'active' : '' ?>">
                            <a
                                class="page-link"
                                href="<?= htmlspecialchars($pageUrl($page)) ?>"
                                <?= $page === $historyPage
                                    ? 'aria-current="page"'
                                    : '' ?>
                            >
                                <?= $page ?>
                            </a>
                        </li>

                        <?php
                        $previousDisplayedPage = $page;
                        ?>

                    <?php endforeach; ?>


                    <!-- NEXT -->
                    <li class="page-item <?= $historyPage >= $historyTotalPages ? 'disabled' : '' ?>">
                        <a
                            class="page-link"
                            href="<?= htmlspecialchars(
                                $pageUrl(min(
                                    $historyTotalPages,
                                    $historyPage + 1
                                ))
                            ) ?>"
                            aria-label="Next page"
                        >
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </li>

                    <!-- LAST -->
                    <li class="page-item <?= $historyPage >= $historyTotalPages ? 'disabled' : '' ?>">
                        <a
                            class="page-link"
                            href="<?= htmlspecialchars(
                                $pageUrl($historyTotalPages)
                            ) ?>"
                            aria-label="Last page"
                        >
                            <i class="bi bi-chevron-double-right"></i>
                        </a>
                    </li>

                </ul>

                <div class="history-pagination-summary">
                    Showing
                    <?= $historyStart ?>–<?= $historyEnd ?>
                    of
                    <?= $historyTotal ?>
                    order(s)
                </div>

            </nav>

        <?php endif; ?>


        <!-- =================================================
             HISTORY MODALS
             IMPORTANT:
             These are OUTSIDE the table.
        ================================================== -->

        <?php foreach ($orderHistory as $ord): ?>

            <?php

            $historyStatusIndex = array_search(
                $ord['status'],
                $timelineStatuses,
                true
            );

            if ($historyStatusIndex === false) {
                $historyStatusIndex = -1;
            }

            ?>


            <div class="modal fade historyOrderModal"
            id="historyOrderModal<?= (int)$ord['id'] ?>"
                tabindex="-1"
                aria-hidden="true"
            >

                <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable history-modal-dialog">

                    <div class="modal-content">


                        <!-- MODAL HEADER -->

                        <div class="modal-header">

                            <h5 class="modal-title">
                                Order Details
                            </h5>

                            <button
                                type="button"
                                class="btn-close"
                                data-bs-dismiss="modal"
                                aria-label="Close"
                            ></button>

                        </div>


                        <!-- MODAL BODY -->

                        <div class="modal-body">


                            <!-- ORDER HEADER -->

                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">

                                <div>

                                    <h5
                                        class="fw-bold mb-1"
                                        style="color:#4A3525;"
                                    >

                                        Order #

                                        <?= htmlspecialchars(
                                            $ord['order_number'] ?? 'N/A'
                                        ) ?>

                                    </h5>


                                    <small class="text-muted">

                                        Claim #

                                        <?= htmlspecialchars(
                                            $ord['claim_number'] ?? 'N/A'
                                        ) ?>

                                    </small>

                                </div>


                                <span
                                    class="status-badge status-<?= htmlspecialchars(
                                        $ord['status']
                                    ) ?>"
                                >

                                    <?= htmlspecialchars(
                                        statusLabel(
                                            $ord['status'],
                                            $statusLabels
                                        )
                                    ) ?>

                                </span>

                            </div>


                            <!-- =================================================
                                 ORDER PROCESS
                            ================================================== -->

                            <div class="order-timeline">

                                <div class="timeline-title">
                                    Order Process
                                </div>


                                <div class="timeline">


                                    <?php if ($ord['status'] === 'cancelled'): ?>


                                        <?php

                                        $cancelledTimeline = [
                                            'pending_verification',
                                            'confirmed',
                                            'cancelled'
                                        ];

                                        ?>


                                        <?php foreach (
                                            $cancelledTimeline
                                            as $index => $timelineStatus
                                        ): ?>


                                            <?php

                                            if ($timelineStatus === 'cancelled') {

                                                $isDone = true;
                                                $isCurrent = true;

                                            } else {

                                                $isDone = true;
                                                $isCurrent = false;

                                            }

                                            ?>


                                            <div
                                                class="timeline-step
                                                <?= $isDone ? 'done' : '' ?>
                                                <?= $isCurrent ? 'current' : '' ?>"
                                            >

                                                <div class="timeline-circle">

                                                    <?php if (
                                                        $timelineStatus === 'cancelled'
                                                    ): ?>

                                                        <i class="bi bi-x"></i>

                                                    <?php else: ?>

                                                        <i class="bi bi-check"></i>

                                                    <?php endif; ?>

                                                </div>


                                                <div class="timeline-name">

                                                    <?= htmlspecialchars(
                                                        statusLabel(
                                                            $timelineStatus,
                                                            $statusLabels
                                                        )
                                                    ) ?>

                                                </div>

                                            </div>


                                        <?php endforeach; ?>


                                    <?php else: ?>


                                        <?php foreach (
                                            $timelineStatuses
                                            as $index => $timelineStatus
                                        ): ?>


                                            <?php

                                            $isDone =
                                                $historyStatusIndex >= $index;

                                            $isCurrent =
                                                $ord['status'] === $timelineStatus;

                                            ?>


                                            <div
                                                class="timeline-step
                                                <?= $isDone ? 'done' : '' ?>
                                                <?= $isCurrent ? 'current' : '' ?>"
                                            >

                                                <div class="timeline-circle">

                                                    <?php if ($isDone): ?>

                                                        <i class="bi bi-check"></i>

                                                    <?php else: ?>

                                                        <i class="bi bi-circle"></i>

                                                    <?php endif; ?>

                                                </div>


                                                <div class="timeline-name">

                                                    <?= htmlspecialchars(
                                                        statusLabel(
                                                            $timelineStatus,
                                                            $statusLabels
                                                        )
                                                    ) ?>

                                                </div>

                                            </div>


                                        <?php endforeach; ?>


                                    <?php endif; ?>


                                </div>

                            </div>


                            <!-- =================================================
                                 CANCELLATION REASON
                            ================================================== -->

                            <?php if (
                                $ord['status'] === 'cancelled'
                            ): ?>

                                <div class="alert alert-danger mt-4 mb-0">

                                    <strong>
                                        Cancellation Reason:
                                    </strong>

                                    <?= !empty(
                                        $ord['cancellation_reason']
                                    )
                                        ? htmlspecialchars(
                                            $ord['cancellation_reason']
                                        )
                                        : 'Not provided'
                                    ?>

                                </div>

                            <?php endif; ?>


                            <hr>


                            <!-- =================================================
                                 ORDER INFORMATION
                            ================================================== -->

                            <div class="row g-3">


                                <div class="col-md-3">

                                    <div class="info-label">
                                        Pickup Date
                                    </div>

                                    <div class="info-value">

                                        <?= htmlspecialchars(
                                            $ord['pickup_date']
                                        ) ?>

                                    </div>

                                </div>


                                <div class="col-md-3">

                                    <div class="info-label">
                                        Pickup Time
                                    </div>

                                    <div class="info-value">

                                        <?= htmlspecialchars(
                                            date(
                                                'g:i A',
                                                strtotime(
                                                    $ord['pickup_time']
                                                )
                                            )
                                        ) ?>

                                    </div>

                                </div>


                                <div class="col-md-3">

                                    <div class="info-label">
                                        Payment
                                    </div>

                                    <div class="info-value">

                                        <?= htmlspecialchars(
                                            ucfirst(
                                                $ord['payment_method']
                                            )
                                        ) ?>

                                    </div>

                                </div>


                                <div class="col-md-3">

                                    <div class="info-label">
                                        Total
                                    </div>

                                    <div class="info-value">

                                        ₱<?= number_format(
                                            $ord['total_amount'],
                                            2
                                        ) ?>

                                    </div>

                                </div>


                            </div>


                        <!-- =================================================
                             ORDER DETAILS
                        ================================================== -->

                        <?php
                        $historyItems =
                            $historyOrderItems[$ord['id']] ?? [];
                        ?>

                        <div class="history-section-title">
                            <i class="bi bi-bag"></i> Items Ordered
                        </div>

                        <?php if ($historyItems): ?>

                            <div class="table-responsive">

                                <table class="table order-items-table mb-0">

                                    <thead>
                                        <tr>
                                            <th>Item</th>
                                            <th class="text-center">Qty</th>
                                            <th class="text-end">Price</th>
                                            <th class="text-end">Subtotal</th>
                                        </tr>
                                    </thead>

                                    <tbody>

                                        <?php foreach ($historyItems as $historyItem): ?>

                                            <?php
                                            $historyCustomizations = [];

                                            if (!empty($historyItem['size'])) {
                                                $historyCustomizations[] =
                                                    'Size: ' .
                                                    (string)$historyItem['size'];
                                            }

                                            if (!empty($historyItem['sugar_level'])) {
                                                $historyCustomizations[] =
                                                    'Sugar: ' .
                                                    (string)$historyItem['sugar_level'];
                                            }

                                            $historyAddonText =
                                                formatOrderAddons(
                                                    $historyItem['addons'] ?? null
                                                );

                                            if ($historyAddonText !== '') {
                                                $historyCustomizations[] =
                                                    'Add-ons: ' .
                                                    $historyAddonText;
                                            }
                                            ?>

                                            <tr>

                                                <td>

                                                    <div class="order-item-name">
                                                        <?= htmlspecialchars(
                                                            $historyItem['product_name']
                                                        ) ?>
                                                    </div>

                                                    <?php if ($historyCustomizations): ?>

                                                        <div class="order-item-customization">

                                                            <?php foreach (
                                                                $historyCustomizations
                                                                as $historyCustomization
                                                            ): ?>

                                                                <span>
                                                                    <?= htmlspecialchars(
                                                                        $historyCustomization
                                                                    ) ?>
                                                                </span>

                                                            <?php endforeach; ?>

                                                        </div>

                                                    <?php endif; ?>

                                                </td>

                                                <td class="text-center">
                                                    <?= (int)$historyItem['quantity'] ?>
                                                </td>

                                                <?php
                                                $isFreeHistoryItem = str_ends_with(
                                                    trim((string)($historyItem['product_name'] ?? '')),
                                                    ' (FREE)'
                                                );
                                                $historyItemQuantity = max(1, (int)($historyItem['quantity'] ?? 1));
                                                $historyAddonTotal = $isFreeHistoryItem
                                                    ? orderAddonTotal(
                                                        $pdo,
                                                        (int)($historyItem['product_id'] ?? 0),
                                                        $historyItem['addons'] ?? null
                                                    )
                                                    : 0.0;
                                                $freeHistoryAddonSubtotal = round(
                                                    $historyAddonTotal * $historyItemQuantity,
                                                    2
                                                );
                                                ?>

                                                <td class="text-end">
                                                    <?php if ($isFreeHistoryItem): ?>
                                                        <div class="fw-semibold text-success">FREE</div>
                                                        <?php if ($historyAddonTotal > 0): ?>
                                                            <div class="free-addon-price">
                                                                + ₱<?= number_format($historyAddonTotal, 2) ?> add-ons
                                                            </div>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        ₱<?= number_format(
                                                            (float)$historyItem['unit_price'],
                                                            2
                                                        ) ?>
                                                    <?php endif; ?>
                                                </td>

                                                <td class="text-end">
                                                    <?php if ($isFreeHistoryItem): ?>
                                                        <?php if ($freeHistoryAddonSubtotal > 0): ?>
                                                            <span class="fw-semibold">
                                                                ₱<?= number_format($freeHistoryAddonSubtotal, 2) ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="fw-semibold text-success">FREE</span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        ₱<?= number_format(
                                                            (float)$historyItem['subtotal'],
                                                            2
                                                        ) ?>
                                                    <?php endif; ?>
                                                </td>

                                            </tr>

                                        <?php endforeach; ?>

                                    </tbody>

                                </table>

                            </div>

                        <?php else: ?>

                            <div class="text-muted small">
                                Order item details are not available for this order.
                            </div>

                        <?php endif; ?>

                        <?php if (($ord['status'] ?? '') === 'completed'): ?>
                            <?php $ordFeedback = $orderFeedbackMap[(int)$ord['id']] ?? null; ?>

    <div class="order-feedback-box history-feedback-box" data-feedback-box>
        <div class="history-section-title">
            <i class="bi bi-chat-left-heart"></i> Your Feedback
        </div>

        <div data-feedback-sent class="<?= $ordFeedback ? '' : 'd-none' ?>">
            <div class="alert alert-success border-0 mb-0">
                <div class="small fw-semibold mb-1">Thank you for your feedback!</div>
                <div data-feedback-text style="white-space:pre-line;"><?= $ordFeedback ? htmlspecialchars((string)$ordFeedback['message']) : '' ?></div>
                <div class="small text-muted mt-1" data-feedback-date><?= $ordFeedback ? htmlspecialchars(date('M j, Y g:i A', strtotime((string)$ordFeedback['created_at']))) : '' ?></div>
            </div>
        </div>

        <form
            class="order-feedback-form <?= $ordFeedback ? 'd-none' : '' ?>"
            method="POST"
            action="<?= htmlspecialchars($feedbackActionUrl) ?>"
            novalidate
        >
            <input type="hidden" name="order_id" value="<?= (int)$ord['id'] ?>">
            <label class="form-label small text-muted mb-1">
                How was your order? Tell us what you liked or what we can improve.
            </label>
            <textarea
                name="message"
                class="form-control"
                rows="3"
                maxlength="500"
                placeholder="Write your feedback here..."
                required
            ></textarea>
            <div class="d-flex justify-content-between align-items-center mt-2">
                <small class="text-muted"><span data-feedback-count>0</span>/500</small>
                <button type="submit" class="btn btn-sm text-white" style="background:#4A3525;">Send Feedback</button>
            </div>
            <div class="small text-danger mt-2 d-none" data-feedback-error></div>
        </form>
    </div>
                        <?php endif; ?>

                        </div><!-- /.modal-body -->


                        <!-- MODAL FOOTER -->

                        <div class="modal-footer">

                            <button
                                type="button"
                                class="btn btn-brown"
                                data-bs-dismiss="modal"
                            >
                                Close
                            </button>

                        </div>


                    </div>

                </div>

            </div>


        <?php endforeach; ?>


    <?php endif; ?>

</div>


        <?php endif; ?>


</div>
<!-- AJAX_HISTORY_END -->

    </div><!-- /.container -->
</div><!-- /.dashboard-page -->



<!-- =====================================================
     CANNOT CANCEL MODAL
====================================================== -->

<div
    class="modal fade"
    id="cannotCancelModal"
    tabindex="-1"
    aria-labelledby="cannotCancelModalLabel"
    aria-hidden="true"
>

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <div class="modal-header">

                <h5
                    class="modal-title"
                    id="cannotCancelModalLabel"
                >
                    Order Cannot Be Cancelled
                </h5>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                    aria-label="Close"
                ></button>

            </div>


            <div class="modal-body">
                Your order can no longer be cancelled because it is already being prepared or is ready for pick-up.
            </div>


            <div class="modal-footer">

                <button
                    type="button"
                    class="btn btn-primary"
                    data-bs-dismiss="modal"
                >
                    Okay
                </button>

            </div>

        </div>

    </div>

</div>


<?php
if ($isHistoryAjax) {
    $ajaxPageOutput = ob_get_clean();

    $ajaxStartMarker = '<!-- AJAX_HISTORY_START -->';
    $ajaxEndMarker = '<!-- AJAX_HISTORY_END -->';
    $ajaxStart = strpos($ajaxPageOutput, $ajaxStartMarker);
    $ajaxEnd = strpos($ajaxPageOutput, $ajaxEndMarker);

    header('Content-Type: text/html; charset=utf-8');

    if ($ajaxStart !== false && $ajaxEnd !== false && $ajaxEnd > $ajaxStart) {
        $ajaxFragmentStart = $ajaxStart + strlen($ajaxStartMarker);
        echo trim(substr(
            $ajaxPageOutput,
            $ajaxFragmentStart,
            $ajaxEnd - $ajaxFragmentStart
        ));
    } else {
        echo '<div id="historyAjaxRegion">';
        echo '<div class="card-custom p-4"><div class="empty-state">Unable to load order history.</div></div>';
        echo '</div>';
    }

    exit;
}
?>

<script>
(function () {
    const statusLabels = {
        pending_verification: 'Pending Verification',
        confirmed: 'Order Confirmed',
        preparing: 'Preparing',
        ready: 'Ready for Pick-up'
    };

    function refreshDashboardStatuses() {
        fetch('dashboard.php?ajax=status', {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Accept': 'application/json' }
        })
        .then(function (response) {
            if (!response.ok) throw new Error('Status request failed');
            return response.json();
        })
        .then(function (data) {
            if (!data.success || !Array.isArray(data.orders)) return;

            const statusById = {};
            data.orders.forEach(function (order) {
                statusById[String(order.id)] = order.status;
            });

            document.querySelectorAll('[data-active-order-status]').forEach(function (badge) {
                const id = badge.getAttribute('data-active-order-status');
                const status = statusById[id];
                if (!status) {
                    window.location.reload();
                    return;
                }

                Object.keys(statusLabels).forEach(function (knownStatus) {
                    badge.classList.remove('status-' + knownStatus);
                });
                badge.classList.add('status-' + status);
                badge.textContent = statusLabels[status] || status.replaceAll('_', ' ');

                const current = document.querySelector('[data-active-order-current="' + id + '"]');
                if (current) {
                    current.textContent = statusLabels[status] || status.replaceAll('_', ' ');
                }
            });
        })
        .catch(function () {
            /* Status refresh is optional; keep the page usable. */
        });
    }

    window.addEventListener('load', function () {
        window.setInterval(refreshDashboardStatuses, 8000);
    });
})();
</script>

<script>
(function () {
    if (!document.getElementById('historyAjaxRegion')) return;

    let requestController = null;
    let requestSequence = 0;

    function loadingState(loading) {
        const region = document.getElementById('historyAjaxRegion');
        if (!region) return;
        region.classList.toggle('history-ajax-loading', loading);
        region.setAttribute('aria-busy', loading ? 'true' : 'false');
    }

    function requestUrl(href) {
        const url = new URL(href, window.location.href);
        url.searchParams.set('view', 'history');
        url.searchParams.set('ajax', 'history');
        return url;
    }

    function browserUrl(href) {
        const url = new URL(href, window.location.href);
        url.searchParams.delete('ajax');
        return url;
    }

    async function loadHistory(href, pushState) {
        const requestId = ++requestSequence;

        if (requestController) requestController.abort();
        requestController = new AbortController();

        const fetchUrl = requestUrl(href);
        const cleanUrl = browserUrl(href);
        loadingState(true);

        try {
            const response = await fetch(fetchUrl.toString(), {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                signal: requestController.signal,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'text/html'
                }
            });

            if (!response.ok) {
                throw new Error('Order history request failed.');
            }

            const html = await response.text();
            if (requestId !== requestSequence) return;

            const template = document.createElement('template');
            template.innerHTML = html.trim();
            const nextRegion = template.content.querySelector('#historyAjaxRegion');

            if (!nextRegion) {
                throw new Error('Invalid order history response.');
            }

            const currentRegion = document.getElementById('historyAjaxRegion');
            if (!currentRegion) return;

            currentRegion.replaceWith(nextRegion);

            if (pushState) {
                window.history.pushState(
                    { dashboardHistoryPage: true },
                    '',
                    cleanUrl.toString()
                );
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                console.error(error);
                window.location.href = cleanUrl.toString();
                return;
            }
        } finally {
            loadingState(false);
        }
    }

    document.addEventListener('click', function (event) {
        const link = event.target.closest('#historyAjaxRegion .history-pagination a');
        if (!link) return;

        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;

        const pageItem = link.closest('.page-item');
        if (pageItem && pageItem.classList.contains('disabled')) return;

        event.preventDefault();
        loadHistory(link.href, true);
    });

    window.addEventListener('popstate', function () {
        const url = new URL(window.location.href);
        if (url.searchParams.get('view') !== 'history') return;
        loadHistory(url.toString(), false);
    });
})();
</script>

<script>
/* Order feedback: works for dynamically loaded modals too (event delegation). */
(function () {
    document.addEventListener('input', function (event) {
        var textarea = event.target.closest ? event.target.closest('.order-feedback-form textarea') : null;
        if (!textarea) return;

        var counter = textarea.form.querySelector('[data-feedback-count]');
        if (counter) counter.textContent = textarea.value.length;
    });

    document.addEventListener('submit', function (event) {
        var form = event.target.closest ? event.target.closest('.order-feedback-form') : null;
        if (!form) return;

        event.preventDefault();
        if (form.dataset.sending === '1') return;

        var box = form.closest('[data-feedback-box]');
        var sent = box.querySelector('[data-feedback-sent]');
        var errorBox = form.querySelector('[data-feedback-error]');
        var button = form.querySelector('button[type="submit"]');
        var textarea = form.querySelector('textarea[name="message"]');
        var oldLabel = button.textContent;

        function showError(message) {
            errorBox.textContent = message;
            errorBox.classList.remove('d-none');
        }

        errorBox.classList.add('d-none');

        if (!textarea.value.trim()) {
            showError('Please write your feedback first.');
            return;
        }

        form.dataset.sending = '1';
        button.disabled = true;
        button.textContent = 'Sending...';

        fetch(form.getAttribute('action'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
            body: new FormData(form)
        })
        .then(function (response) {
            return response.json().catch(function () {
                return { success: false, message: 'Unexpected response from the server.' };
            });
        })
        .then(function (data) {
            if (data.success && data.feedback) {
                sent.querySelector('[data-feedback-text]').textContent = data.feedback.message;
                sent.querySelector('[data-feedback-date]').textContent = data.feedback.created_at;
                sent.classList.remove('d-none');
                form.classList.add('d-none');

                /* Hide any "Give Feedback" shortcut buttons for this order. */
                var orderId = form.querySelector('input[name="order_id"]').value;
                document.querySelectorAll('[data-feedback-btn="' + orderId + '"]').forEach(function (btn) {
                    btn.remove();
                });
            } else {
                showError(data.message || 'Could not send your feedback. Please try again.');
            }
        })
        .catch(function () {
            showError('Network error. Please try again.');
        })
        .then(function () {
            form.dataset.sending = '0';
            button.disabled = false;
            button.textContent = oldLabel;
        });
    });
})();
</script>

<?php require_once '../includes/footer.php'; ?>