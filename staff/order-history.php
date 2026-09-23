<?php

require_once '../includes/db.php';


/* =========================================================
   STAFF ACCESS
========================================================= */

if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['user_role'] ?? '') !== 'staff'
) {
    header("Location: ../auth/login.php");
    exit;
}


/* =========================================================
   FILTERS
========================================================= */

/*
 * Default history view:
 * Completed + Cancelled
 *
 * Available:
 * all
 * completed
 * cancelled
 */

$history_view = $_GET['view'] ?? 'recent';

$allowed_history_views = [
    'recent',
    'archive'
];

if (!in_array($history_view, $allowed_history_views, true)) {
    $history_view = 'recent';
}


$status_filter = $_GET['status'] ?? 'history';

$allowed_status_filters = [
    'history',
    'all',
    'completed',
    'cancelled'
];

if (!in_array($status_filter, $allowed_status_filters, true)) {
    $status_filter = 'history';
}


$search = trim($_GET['search'] ?? '');

$date_filter = trim($_GET['date'] ?? '');


/* =========================================================
   PAGINATION
========================================================= */

$per_page = 10;

$page = (int)($_GET['page'] ?? 1);

if ($page < 1) {
    $page = 1;
}

$offset = ($page - 1) * $per_page;


/* =========================================================
   MAINTAIN RECENT / ARCHIVE HISTORY
========================================================= */

/*
 * Only completed and cancelled orders belong in Order History.
 *
 * The newest 100 historical orders remain in Recent Orders.
 * Older completed/cancelled orders are automatically archived.
 *
 * closed_at is used because it records when the order was
 * actually completed or cancelled.
 */

/* First archive all historical orders. */
$pdo->exec("
    UPDATE orders
    SET is_archived = 1
    WHERE status IN ('completed', 'cancelled')
      AND closed_at IS NOT NULL
");

/* Then restore the newest 100 historical orders to Recent. */
$pdo->exec("
    UPDATE orders
    SET is_archived = 0
    WHERE id IN (
        SELECT id
        FROM (
            SELECT id
            FROM orders
            WHERE status IN ('completed', 'cancelled')
              AND closed_at IS NOT NULL
            ORDER BY closed_at DESC, id DESC
            LIMIT 100
        ) AS recent_orders
    )
");


/* =========================================================
   BUILD FILTER CONDITIONS
========================================================= */

$where = [];
$params = [];


/*
 * RECENT / ARCHIVE VIEW
 */

if ($history_view === 'recent') {

    $where[] = "
        o.status IN ('completed', 'cancelled')
        AND o.is_archived = 0
    ";

} else {

    $where[] = "
        o.status IN ('completed', 'cancelled')
        AND o.is_archived = 1
    ";
}


/*
 * STATUS
 */

if ($status_filter === 'history') {

    $where[] = "
        o.status IN ('completed', 'cancelled')
    ";

} elseif ($status_filter === 'completed') {

    $where[] = "
        o.status = 'completed'
    ";

} elseif ($status_filter === 'cancelled') {

    $where[] = "
        o.status = 'cancelled'
    ";
}


/*
 * SEARCH
 */

if ($search !== '') {

    $where[] = "
        (
            o.order_number LIKE ?
            OR o.claim_number LIKE ?
            OR o.customer_name LIKE ?
            OR o.contact_number LIKE ?
        )
    ";

    $searchTerm = '%' . $search . '%';

    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}


/*
 * DATE
 */

if ($date_filter !== '') {

    $dateObject = DateTime::createFromFormat(
        'Y-m-d',
        $date_filter
    );

    if (
        $dateObject &&
        $dateObject->format('Y-m-d') === $date_filter
    ) {

        $where[] = "
            DATE(o.closed_at) = ?
        ";

        $params[] = $date_filter;
    }
}


/* =========================================================
   WHERE CLAUSE
========================================================= */

$whereSql = '';

if (!empty($where)) {

    $whereSql = 'WHERE ' . implode(' AND ', $where);
}


/* =========================================================
   GET TOTAL RECORDS
========================================================= */

$stmtCount = $pdo->prepare("
    SELECT COUNT(*)
    FROM orders o
    $whereSql
");

$stmtCount->execute($params);

$total_orders = (int)$stmtCount->fetchColumn();


/* =========================================================
   TOTAL PAGES
========================================================= */

$total_pages = max(
    1,
    (int)ceil($total_orders / $per_page)
);

if ($page > $total_pages) {
    $page = $total_pages;

    $offset = ($page - 1) * $per_page;
}


/* =========================================================
   GET ORDERS
========================================================= */

$stmtOrders = $pdo->prepare("
    SELECT
        o.*
    FROM orders o

    $whereSql

    ORDER BY
        o.closed_at DESC,
        o.id DESC

    LIMIT $per_page
    OFFSET $offset
");

$stmtOrders->execute($params);

$orders = $stmtOrders->fetchAll(PDO::FETCH_ASSOC);


/* =========================================================
   GET ORDER ITEMS
========================================================= */

$orderItems = [];

if (!empty($orders)) {

    $orderIds = array_map(
        'intval',
        array_column($orders, 'id')
    );

    $placeholders = implode(
        ',',
        array_fill(
            0,
            count($orderIds),
            '?'
        )
    );

    $stmtItems = $pdo->prepare("
        SELECT
            *
        FROM order_items
        WHERE order_id IN ($placeholders)
        ORDER BY id ASC
    ");

    $stmtItems->execute($orderIds);

    foreach (
        $stmtItems->fetchAll(PDO::FETCH_ASSOC)
        as $item
    ) {

        $orderItems[(int)$item['order_id']][] = $item;
    }
}





/* =========================================================
   PAGE INFORMATION
========================================================= */

$page_title = 'Order History';

$page_description =
    'View and search previously processed customer orders.';


/* =========================================================
   HEADER
========================================================= */

require_once '../includes/header.php';

?>


<!-- =========================================================
     BOOTSTRAP ICONS
========================================================= -->

<link
    rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
>


<style>

/* =========================================================
   MAIN STAFF AREA
========================================================= */

.staff-dashboard {

    background: #f8f5ef;

    min-height: 100vh;

}

@media (min-width: 992px) {
    .staff-dashboard {
        width: calc(100% - 260px);
        margin-left: 260px;
    }
}

.staff-content {

    padding: 30px;
}


/* =========================================================
   PAGE HEADER
========================================================= */

.history-header {

    display: flex;

    justify-content: space-between;

    align-items: flex-start;

    gap: 20px;

    margin-bottom: 25px;
}

.history-title h2 {

    margin: 0;

    color: #4b2e1e;

    font-weight: 700;
}

.history-title p {

    margin: 5px 0 0;

    color: #777;

    font-size: 14px;
}


/* =========================================================
   SUMMARY CARDS
========================================================= */

.summary-grid {

    display: grid;

    grid-template-columns:
        repeat(3, minmax(0, 1fr));

    gap: 15px;

    margin-bottom: 25px;
}

.summary-card {

    background: #ffffff;

    border: 1px solid #B8A08A;

    border-radius: 14px;

    padding: 18px;

    box-shadow:
        0 3px 12px
        rgba(0, 0, 0, 0.04);

}

.summary-label {

    color: #777;

    font-size: 12px;

    margin-bottom: 5px;
}

.summary-value {

    color: #4b2e1e;

    font-size: 24px;

    font-weight: 700;
}


/* =========================================================
   HISTORY TABS
========================================================= */

.history-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 18px;
    flex-wrap: wrap;
}

.history-tab {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    min-height: 42px;
    padding: 8px 15px;
    border: 1px solid #B8A08A;
    border-radius: 9px;
    background: #ffffff;
    color: #4b2e1e;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
    transition: 0.2s ease;
}

.history-tab:hover {
    background: #f6eee5;
    color: #4b2e1e;
}

.history-tab.active {
    background: #4b2e1e;
    color: #ffffff;
    border-color: #4b2e1e;
}

.history-tab-count {
    min-width: 21px;
    height: 21px;
    padding: 0 5px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 20px;
    background: #f0e5da;
    color: #4b2e1e;
    font-size: 10px;
    font-weight: 700;
}

.history-tab.active .history-tab-count {
    background: #ffffff;
    color: #4b2e1e;
}


/* =========================================================
   FILTER SECTION
========================================================= */

.filter-section {

    background: #ffffff;

    border: 1px solid #B8A08A;

    border-radius: 16px;

    padding: 20px;

    margin-bottom: 25px;

    box-shadow:
        0 3px 12px
        rgba(0, 0, 0, 0.04);
}

.filter-row {

    display: grid;

    grid-template-columns:
        minmax(0, 1fr)
        180px
        180px
        auto;

    gap: 12px;

    align-items: end;
}

.filter-group {

    min-width: 0;
}

.filter-label {

    display: block;

    color: #6b4226;

    font-size: 12px;

    font-weight: 600;

    margin-bottom: 6px;
}

.filter-control {

    width: 100%;

    height: 42px;

    border: 1px solid #B8A08A;

    border-radius: 9px;

    padding: 8px 12px;

    background: #ffffff;

    color: #4b2e1e;

    font-size: 13px;

    outline: none;

}

.filter-control:focus {

    border-color: #4b2e1e;

    box-shadow:
        0 0 0 2px
        rgba(75, 46, 30, 0.10);
}

.btn-filter {

    height: 42px;

    border: none;

    border-radius: 9px;

    padding: 8px 16px;

    background: #4b2e1e;

    color: #ffffff;

    font-size: 13px;

    font-weight: 600;

    text-decoration: none;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    gap: 6px;

    cursor: pointer;
}

.btn-filter:hover {

    background: #351f14;

    color: #ffffff;
}

.btn-clear {

    height: 42px;

    border: 1px solid #B8A08A;

    border-radius: 9px;

    padding: 8px 14px;

    background: #ffffff;

    color: #4b2e1e;

    font-size: 13px;

    font-weight: 600;

    text-decoration: none;

    display: inline-flex;

    align-items: center;

    justify-content: center;
}

.btn-clear:hover {

    background: #f6eee5;

    color: #4b2e1e;
}


/* =========================================================
   HISTORY SECTION
========================================================= */

.history-section {

    background: #ffffff;

    border: 1px solid #B8A08A;

    border-radius: 16px;

    padding: 25px;

    box-shadow:
        0 3px 12px
        rgba(0, 0, 0, 0.04);
}

.history-section-header {

    display: flex;

    justify-content: space-between;

    align-items: center;

    gap: 15px;

    margin-bottom: 20px;
}

.history-section-header h4 {

    margin: 0;

    color: #4b2e1e;

    font-weight: 700;

    font-size: 18px;
}

.history-count {

    color: #777;

    font-size: 12px;
}


/* =========================================================
   ORDER ROW
========================================================= */

.history-row {

    display: grid;

    grid-template-columns:
        minmax(180px, 1.2fr)
        minmax(150px, 1fr)
        minmax(130px, 0.8fr)
        minmax(110px, 0.7fr)
        auto;

    gap: 15px;

    align-items: center;

    background: #fffdf9;

    border: 1px solid #B8A08A;

    border-radius: 12px;

    padding: 16px;

    margin-bottom: 10px;

    transition:
        background 0.2s ease,
        border-color 0.2s ease;
}

.history-row:last-child {

    margin-bottom: 0;
}

.history-row:hover {

    background: #fffaf4;

    border-color: #8B6F5A;
}


/* =========================================================
   ORDER IDENTIFIERS
========================================================= */

.history-order-number {

    color: #4b2e1e;

    font-size: 14px;

    font-weight: 700;

    margin-bottom: 3px;
}

.history-claim {

    color: #777;

    font-size: 11px;
}


/* =========================================================
   CUSTOMER
========================================================= */

.history-customer strong {

    display: block;

    color: #4b2e1e;

    font-size: 13px;

    margin-bottom: 2px;
}

.history-customer span {

    color: #888;

    font-size: 11px;
}


/* =========================================================
   DATE
========================================================= */

.history-date {

    color: #555;

    font-size: 12px;

    line-height: 1.5;
}

.history-date small {
    display: block;
    margin-top: 2px;
    color: #888;
    font-size: 10px;
}
.history-cancel-reason {
    color: #555;
    font-size: 15px;
    line-height: 1.4;
}

.history-cancel-reason small {
    display: block;
    margin-bottom: 4px;
    color: #888;
    font-size: 12px;
}

.history-cancel-reason strong {
    display: block;
    color: #4b2e1e;
    font-size: 15px;
    font-weight: 600;
}


/* =========================================================
   AMOUNT
========================================================= */

.history-amount {

    color: #4b2e1e;

    font-size: 14px;

    font-weight: 700;
}


/* =========================================================
   STATUS
========================================================= */

.history-status {

    display: inline-flex;

    align-items: center;

    justify-content: center;

    padding: 6px 10px;

    border-radius: 20px;

    font-size: 10px;

    font-weight: 700;

    white-space: nowrap;
}

.status-completed {

    background: #e4f7e8;

    color: #28763b;
}

.status-cancelled {

    background: #fce3e3;

    color: #a33a3a;
}

.status-pending {

    background: #fff1cc;

    color: #956c00;
}

.status-confirmed {

    background: #e6f1ff;

    color: #286090;
}

.status-preparing {

    background: #f1e7ff;

    color: #7040a0;
}

.status-ready {

    background: #e4f7e8;

    color: #28763b;
}


/* =========================================================
   VIEW BUTTON
========================================================= */

.btn-view-history {

    display: inline-flex;

    align-items: center;

    justify-content: center;

    gap: 5px;

    border: 1px solid #4b2e1e;

    border-radius: 8px;

    padding: 8px 12px;

    background: #ffffff;

    color: #4b2e1e;

    text-decoration: none;

    font-size: 12px;

    font-weight: 600;

    white-space: nowrap;

    cursor: pointer;
}

.btn-view-history:hover {

    background: #4b2e1e;

    color: #ffffff;
}


/* =========================================================
   EMPTY STATE
========================================================= */

.empty-history {

    text-align: center;

    padding: 65px 20px;

    color: #888;
}

.empty-history i {

    display: block;

    font-size: 50px;

    color: #B8A08A;

    margin-bottom: 15px;
}

.empty-history h5 {

    color: #6b4226;

    margin-bottom: 5px;

    font-weight: 700;
}

.empty-history p {

    margin: 0;

    font-size: 13px;
}


/* =========================================================
   PAGINATION
========================================================= */

.pagination-wrapper {

    display: flex;

    justify-content: center;

    align-items: center;

    gap: 5px;

    margin-top: 25px;

    flex-wrap: wrap;
}

.page-link-history {

    min-width: 36px;

    height: 36px;

    padding: 0 10px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    border: 1px solid #B8A08A;

    border-radius: 7px;

    background: #ffffff;

    color: #4b2e1e;

    text-decoration: none;

    font-size: 12px;

    font-weight: 600;
}

.page-link-history:hover {

    background: #f6eee5;

    color: #4b2e1e;
}

.page-link-history.active {

    background: #4b2e1e;

    color: #ffffff;

    border-color: #4b2e1e;
}

.page-link-history.disabled {

    opacity: 0.45;

    pointer-events: none;
}


/* =========================================================
   ORDER MODAL
========================================================= */

.modal-content {

    border: 1px solid #B8A08A;

    border-radius: 14px;

    overflow: hidden;
}

.modal-header {

    background: #FDF8F2;

    border-bottom: 1px solid #B8A08A;

    color: #4b2e1e;
}

.modal-title {

    font-weight: 700;
}

.modal-body {

    background: #ffffff;
}

.modal-footer {

    border-top: 1px solid #B8A08A;

    background: #FDF8F2;
}


/* =========================================================
   MODAL DETAILS
========================================================= */

.detail-grid {

    display: grid;

    grid-template-columns:
        repeat(2, minmax(0, 1fr));

    gap: 12px;

    margin-bottom: 20px;
}

.detail-box {

    background: #fdf8f2;

    border: 1px solid #B8A08A;

    border-radius: 10px;

    padding: 12px;
}

.detail-box small {

    display: block;

    color: #888;

    font-size: 10px;

    margin-bottom: 4px;
}

.detail-box strong {

    color: #4b2e1e;

    font-size: 13px;
}


/* =========================================================
   ITEMS IN MODAL
========================================================= */

.detail-items-title {

    color: #4b2e1e;

    font-size: 14px;

    font-weight: 700;

    margin-bottom: 8px;
}

.detail-item {

    display: flex;

    justify-content: space-between;

    align-items: flex-start;

    gap: 15px;

    padding: 9px 0;

    border-bottom: 1px dashed #B8A08A;
}

.detail-item:last-child {

    border-bottom: none;
}

.detail-item-name {

    color: #4b2e1e;

    font-size: 13px;

    font-weight: 600;
}

.detail-item-info {

    color: #888;

    font-size: 11px;

    margin-top: 2px;
}

.detail-item-price {

    color: #4b2e1e;

    font-size: 13px;

    font-weight: 600;

    white-space: nowrap;
}

.detail-total {

    display: flex;

    justify-content: space-between;

    align-items: center;

    margin-top: 10px;

    padding-top: 12px;

    border-top: 1px solid #B8A08A;
}

.detail-total span {

    color: #777;

    font-size: 13px;
}

.detail-total strong {

    color: #4b2e1e;

    font-size: 18px;
}


/* =========================================================
   GCASH PROOF
========================================================= */

.history-payment-proof {

    margin-top: 20px;

    padding-top: 15px;

    border-top: 1px solid #B8A08A;
}

.history-payment-proof h6 {

    color: #4b2e1e;

    font-size: 13px;

    font-weight: 700;

    margin-bottom: 10px;
}

.history-payment-proof img {

    display: block;

    width: 100%;

    max-height: 420px;

    object-fit: contain;

    border: 1px solid #B8A08A;

    border-radius: 10px;

    background: #fdf8f2;
}


/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 1100px) {

    .filter-row {

        grid-template-columns:
            minmax(0, 1fr)
            160px
            160px;
    }

    .filter-actions {

        grid-column: 1 / -1;

        display: flex;

        gap: 8px;
    }

    .history-row {

        grid-template-columns:
            minmax(170px, 1fr)
            minmax(140px, 1fr)
            minmax(110px, 0.8fr)
            auto;
    }

    .history-row .history-date {

        display: none;
    }

}


@media (max-width: 768px) {

    .staff-dashboard {



        min-width: 0;
    }

    .staff-content {

        width: 100%;

        padding: 15px;

        box-sizing: border-box;
    }


    /* HEADER */

    .history-header {

        display: block;
    }

    .history-title h2 {

        font-size: 21px;
    }


    /* SUMMARY */

    .summary-grid {

        grid-template-columns: 1fr;

        gap: 10px;
    }

    .summary-card {

        padding: 14px;
    }


    /* HISTORY TABS */

    .history-tabs {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }

    .history-tab {
        width: 100%;
        padding: 8px 10px;
        font-size: 12px;
    }


    /* FILTER */

    .filter-section {

        padding: 14px;
    }

    .filter-row {

        display: grid;

        grid-template-columns: 1fr;

        gap: 10px;
    }

    .filter-actions {

        grid-column: auto;

        display: grid;

        grid-template-columns: 1fr 1fr;

        gap: 8px;
    }


    /* HISTORY */

    .history-section {

        padding: 12px;
    }

    .history-section-header {

        display: block;
    }

    .history-count {

        display: block;

        margin-top: 4px;
    }


    /* HISTORY CARD */

    .history-row {

        display: flex;

        flex-direction: column;

        align-items: stretch;

        gap: 10px;

        padding: 13px;

        margin-bottom: 10px;
    }

    .history-date {

        display: block !important;

        color: #777;

        font-size: 11px;
    }

    .history-status {

        align-self: flex-start;
    }

    .btn-view-history {

        width: 100%;
    }


    /* MODAL */

    .detail-grid {

        grid-template-columns: 1fr;
    }

}


@media (max-width: 480px) {

    .staff-content {

        padding: 10px;
        padding-top: 64px;
    }

    .history-title h2 {

        font-size: 19px;
    }

    .history-title p {

        font-size: 12px;
    }

    .summary-value {

        font-size: 21px;
    }

    .filter-actions {

        grid-template-columns: 1fr;
    }

    .history-section {

        padding: 10px;
    }

    .history-row {

        padding: 12px;
    }

    .history-order-number {

        font-size: 13px;
    }

    .modal-body {

        padding: 15px;
    }


}

</style>


<!-- =========================================================
     STAFF LAYOUT
========================================================= -->

<div>

    <!-- SIDEBAR -->

    <?php require_once 'sidebar.php'; ?>


    <!-- MAIN -->

    <main class="staff-dashboard flex-grow-1">

        <div class="staff-content">


            <!-- =================================================
                 PAGE HEADER
            ================================================= -->

            <div class="history-header">

                <div class="history-title">

                    <h2>

                        <i class="bi bi-clock-history me-2"></i>

                        Order History

                    </h2>

                    <p>
                        View and search previously processed customer orders.
                    </p>

                </div>

            </div>

            <!-- =================================================
                 HISTORY TABS
            ================================================= -->

            <div class="history-tabs">

                <a
                    href="order-history.php?view=recent"
                    class="history-tab <?= $history_view === 'recent' ? 'active' : '' ?>"
                >
                    <i class="bi bi-clock-history"></i>
                    Recent Orders
                    <span class="history-tab-count">
                        <?php
                        $stmtRecentCount = $pdo->query("
                            SELECT COUNT(*)
                            FROM orders
                            WHERE status IN ('completed', 'cancelled')
                              AND is_archived = 0
                        ");
                        echo (int)$stmtRecentCount->fetchColumn();
                        ?>
                    </span>
                </a>

                <a
                    href="order-history.php?view=archive"
                    class="history-tab <?= $history_view === 'archive' ? 'active' : '' ?>"
                >
                    <i class="bi bi-archive"></i>
                    Archive
                    <span class="history-tab-count">
                        <?php
                        $stmtArchiveCount = $pdo->query("
                            SELECT COUNT(*)
                            FROM orders
                            WHERE status IN ('completed', 'cancelled')
                              AND is_archived = 1
                        ");
                        echo (int)$stmtArchiveCount->fetchColumn();
                        ?>
                    </span>
                </a>

            </div>


            <!-- =================================================
                 FILTERS
            ================================================= -->

            <div class="filter-section">

                <form method="GET">

                    <div class="filter-row">


                        <!-- SEARCH -->

                        <div class="filter-group">

                            <label class="filter-label">

                                Search Orders

                            </label>

                            <input
                                type="text"
                                name="search"
                                value="<?= htmlspecialchars($search) ?>"
                                class="filter-control"
                                placeholder="Order number, claim number, customer..."
                            >

                        </div>


                        <!-- STATUS -->

                        <div class="filter-group">

                            <label class="filter-label">

                                Status

                            </label>

                            <select
                                name="status"
                                class="filter-control"
                            >

                                <option
                                    value="history"
                                    <?= $status_filter === 'history'
                                        ? 'selected'
                                        : '' ?>
                                >
                                    Completed & Cancelled
                                </option>

                                <option
                                    value="all"
                                    <?= $status_filter === 'all'
                                        ? 'selected'
                                        : '' ?>
                                >
                                    All Orders
                                </option>

                                <option
                                    value="completed"
                                    <?= $status_filter === 'completed'
                                        ? 'selected'
                                        : '' ?>
                                >
                                    Completed
                                </option>

                                <option
                                    value="cancelled"
                                    <?= $status_filter === 'cancelled'
                                        ? 'selected'
                                        : '' ?>
                                >
                                    Cancelled
                                </option>

                            </select>

                        </div>


                        <!-- DATE -->

                        <div class="filter-group">

                            <label class="filter-label">

                                Date

                            </label>

                            <input
                                type="date"
                                name="date"
                                value="<?= htmlspecialchars($date_filter) ?>"
                                class="filter-control"
                            >

                        </div>


                        <!-- ACTIONS -->

                        <div class="filter-actions">

                            <button
                                type="submit"
                                class="btn-filter"
                            >

                                <i class="bi bi-search"></i>

                                Search

                            </button>

                            <a
                                href="order-history.php?view=<?= urlencode($history_view) ?>"
                                class="btn-clear"
                            >

                                <i class="bi bi-x-circle me-1"></i>

                                Clear

                            </a>

                        </div>


                    </div>

                </form>

            </div>


            <!-- =================================================
                 HISTORY
            ================================================= -->

            <div class="history-section">


                <div class="history-section-header">

                    <div>

                        <h4>
                            <?= $history_view === 'recent'
                                ? 'Recent Orders'
                                : 'Archived Orders' ?>
                        </h4>

                        <span class="history-count">

                            Showing
                            <?= count($orders) ?>
                            of
                            <?= $total_orders ?>
                            order<?= $total_orders !== 1 ? 's' : '' ?>

                        </span>

                    </div>

                </div>


                <?php if (empty($orders)): ?>


                    <!-- =================================================
                         EMPTY STATE
                    ================================================= -->

                    <div class="empty-history">

                        <i class="bi bi-clock-history"></i>

                        <h5>
                            No Order History Found
                        </h5>

                        <p>
                            No orders match the current search or filter.
                        </p>

                    </div>


                <?php else: ?>


                    <!-- =================================================
                         HISTORY LIST
                    ================================================= -->

                    <?php foreach ($orders as $order): ?>


                        <?php

                        $orderId = (int)$order['id'];

                        $status = $order['status'];


                        /*
                         * Status label
                         */

                        switch ($status) {

                            case 'completed':
                                $statusText = 'Completed';
                                $statusClass = 'status-completed';
                                break;

                            case 'cancelled':
                                $statusText = 'Cancelled';
                                $statusClass = 'status-cancelled';
                                break;

                            case 'confirmed':
                                $statusText = 'Confirmed';
                                $statusClass = 'status-confirmed';
                                break;

                            case 'preparing':
                                $statusText = 'Preparing';
                                $statusClass = 'status-preparing';
                                break;

                            case 'ready':
                                $statusText = 'Ready for Pickup';
                                $statusClass = 'status-ready';
                                break;

                            case 'pending_verification':
                                $statusText = 'Pending Verification';
                                $statusClass = 'status-pending';
                                break;

                            default:
                                $statusText = ucfirst(
                                    str_replace(
                                        '_',
                                        ' ',
                                        $status
                                    )
                                );

                                $statusClass = 'status-pending';
                        }


                        /*
                         * Order date
                         */

                        $createdDate = '';

                        if (!empty($order['created_at'])) {

                            $createdDate = date(
                                'M d, Y • h:i A',
                                strtotime(
                                    $order['created_at']
                                )
                            );
                        }

                        /*
                         * Closed date
                         * This is when the order was completed
                         * or cancelled.
                         */

                        $closedDate = '';

                        if (!empty($order['closed_at'])) {

                            $closedDate = date(
                                'M d, Y • h:i A',
                                strtotime(
                                    $order['closed_at']
                                )
                            );
                        }


                        /*
                         * Items
                         */

                        $items =
                            $orderItems[$orderId]
                            ?? [];

                        ?>


                        <!-- =================================================
                             HISTORY ROW
                        ================================================= -->

                        <div class="history-row">


                            <!-- ORDER -->

                            <div>

                                <div class="history-order-number">

                                    <?= htmlspecialchars(
                                        $order['order_number']
                                        ?? 'ORD-' . $orderId
                                    ) ?>

                                </div>

                                <div class="history-claim">

                                    Claim No:

                                    <?= htmlspecialchars(
                                        $order['claim_number']
                                        ?? ''
                                    ) ?>

                                </div>

                            </div>


                            <!-- CUSTOMER -->

                            <div class="history-customer">

                                <strong>

                                    <?= htmlspecialchars(
                                        $order['customer_name']
                                        ?? ''
                                    ) ?>

                                </strong>

                                <span>

                                    <?= htmlspecialchars(
                                        $order['contact_number']
                                        ?? ''
                                    ) ?>

                                </span>

                            </div>


                            <!-- DATE / CANCELLATION REASON -->

                            <div>

                                <?php if ($status === 'cancelled'): ?>

                                    <div class="history-cancel-reason">
                                        <small>Cancellation Reason</small>

                                        <strong>
                                            <?= !empty($order['cancellation_reason'])
                                                ? htmlspecialchars($order['cancellation_reason'])
                                                : 'Not provided' ?>
                                        </strong>
                                    </div>

                                <?php else: ?>

                                    <div class="history-date">

                                        <div>
                                            <?= htmlspecialchars(
                                                $closedDate !== ''
                                                    ? $closedDate
                                                    : $createdDate
                                            ) ?>
                                        </div>

                                        <small>
                                            Completed At
                                        </small>

                                    </div>

                                <?php endif; ?>

                            </div>


                            <!-- TOTAL + STATUS -->

                            <div>

                                <div class="history-amount">

                                    ₱<?= number_format(
                                        (float)$order['total_amount'],
                                        2
                                    ) ?>

                                </div>

                                <span
                                    class="history-status <?= $statusClass ?>"
                                >

                                    <?= htmlspecialchars(
                                        $statusText
                                    ) ?>

                                </span>

                            </div>


                            <!-- VIEW -->

                            <div>

                                <button
                                    type="button"
                                    class="btn-view-history"
                                    data-bs-toggle="modal"
                                    data-bs-target="#orderDetailsModal<?= $orderId ?>"
                                >

                                    <i class="bi bi-eye"></i>

                                    View Details

                                </button>

                            </div>


                        </div>


                        <!-- =================================================
                             ORDER DETAILS MODAL
                        ================================================= -->

                        <div
                            class="modal fade"
                            id="orderDetailsModal<?= $orderId ?>"
                            tabindex="-1"
                            aria-labelledby="orderDetailsLabel<?= $orderId ?>"
                            aria-hidden="true"
                        >

                            <div
                                class="modal-dialog modal-dialog-centered modal-lg"
                            >

                                <div class="modal-content">


                                    <!-- HEADER -->

                                    <div class="modal-header">

                                        <h5
                                            class="modal-title"
                                            id="orderDetailsLabel<?= $orderId ?>"
                                        >

                                            <i class="bi bi-receipt me-2"></i>

                                            Order Details

                                        </h5>


                                        <button
                                            type="button"
                                            class="btn-close"
                                            data-bs-dismiss="modal"
                                            aria-label="Close"
                                        ></button>

                                    </div>


                                    <!-- BODY -->

                                    <div class="modal-body">


                                        <!-- ORDER INFORMATION -->

                                        <div class="detail-grid">


                                            <div class="detail-box">

                                                <small>
                                                    Order Number
                                                </small>

                                                <strong>

                                                    <?= htmlspecialchars(
                                                        $order['order_number']
                                                        ?? 'ORD-' . $orderId
                                                    ) ?>

                                                </strong>

                                            </div>


                                            <div class="detail-box">

                                                <small>
                                                    Claim Number
                                                </small>

                                                <strong>

                                                    <?= htmlspecialchars(
                                                        $order['claim_number']
                                                        ?? ''
                                                    ) ?>

                                                </strong>

                                            </div>


                                            <div class="detail-box">

                                                <small>
                                                    Customer
                                                </small>

                                                <strong>

                                                    <?= htmlspecialchars(
                                                        $order['customer_name']
                                                        ?? ''
                                                    ) ?>

                                                </strong>

                                            </div>


                                            <div class="detail-box">

                                                <small>
                                                    Contact Number
                                                </small>

                                                <strong>

                                                    <?= htmlspecialchars(
                                                        $order['contact_number']
                                                        ?? ''
                                                    ) ?>

                                                </strong>

                                            </div>


                                            <div class="detail-box">

                                                <small>
                                                    Pick-up Date
                                                </small>

                                                <strong>

                                                    <?= htmlspecialchars(
                                                        $order['pickup_date']
                                                        ?? ''
                                                    ) ?>

                                                </strong>

                                            </div>


                                            <div class="detail-box">

                                                <small>
                                                    Pick-up Time
                                                </small>

                                                <strong>

                                                    <?php

                                                    $pickupTime = '';

                                                    if (
                                                        !empty(
                                                            $order['pickup_time']
                                                        )
                                                    ) {

                                                        $pickupTimestamp =
                                                            strtotime(
                                                                $order['pickup_time']
                                                            );

                                                        if (
                                                            $pickupTimestamp !== false
                                                        ) {

                                                            $pickupTime =
                                                                date(
                                                                    'h:i A',
                                                                    $pickupTimestamp
                                                                );
                                                        }
                                                    }

                                                    ?>

                                                    <?= htmlspecialchars(
                                                        $pickupTime
                                                    ) ?>

                                                </strong>

                                            </div>


                                            <div class="detail-box">

                                                <small>
                                                    Payment Method
                                                </small>

                                                <strong>

                                                    <?= ucfirst(
                                                        htmlspecialchars(
                                                            $order['payment_method']
                                                            ?? ''
                                                        )
                                                    ) ?>

                                                </strong>

                                            </div>


                                            <div class="detail-box">

                                                <small>
                                                    Order Status
                                                </small>

                                                <strong>

                                                    <?= htmlspecialchars(
                                                        $statusText
                                                    ) ?>

                                                </strong>

                                            </div>


                                            <div class="detail-box">

                                                <small>
                                                    Order Date
                                                </small>

                                                <strong>

                                                    <?= htmlspecialchars(
                                                        $createdDate
                                                    ) ?>

                                                </strong>

                                            </div>


                                            <div class="detail-box">

                                                <small>
                                                    <?= $status === 'completed'
                                                        ? 'Completed At'
                                                        : 'Cancelled At' ?>
                                                </small>

                                                <strong>

                                                    <?= htmlspecialchars(
                                                        $closedDate !== ''
                                                            ? $closedDate
                                                            : 'Not recorded'
                                                    ) ?>

                                                </strong>

                                            </div>


                                        </div>


                                        <!-- =================================================
                                             ORDER ITEMS
                                        ================================================= -->

                                        <div class="detail-items-title">

                                            Order Items

                                        </div>


                                        <?php if (empty($items)): ?>


                                            <div class="text-muted small">

                                                No order items found.

                                            </div>


                                        <?php else: ?>


                                            <?php foreach ($items as $item): ?>


                                                <div class="detail-item">


                                                    <div>

                                                        <div class="detail-item-name">

                                                            <?= htmlspecialchars(
                                                                $item['quantity']
                                                            ) ?>

                                                            ×

                                                            <?= htmlspecialchars(
                                                                $item['product_name']
                                                            ) ?>

                                                        </div>

                                                        <div class="detail-item-info">

                                                            ₱<?= number_format(
                                                                (float)$item['unit_price'],
                                                                2
                                                            ) ?>

                                                            each

                                                        </div>

                                                    </div>


                                                    <div class="detail-item-price">

                                                        ₱<?= number_format(
                                                            (float)$item['subtotal'],
                                                            2
                                                        ) ?>

                                                    </div>


                                                </div>


                                            <?php endforeach; ?>


                                        <?php endif; ?>


                                        <!-- TOTAL -->

                                        <div class="detail-total">

                                            <span>
                                                Total Amount
                                            </span>

                                            <strong>

                                                ₱<?= number_format(
                                                    (float)$order['total_amount'],
                                                    2
                                                ) ?>

                                            </strong>

                                        </div>


                                        <!-- =================================================
                                             GCASH PROOF
                                        ================================================= -->

                                        <?php if (
                                            strtolower(
                                                $order['payment_method']
                                                ?? ''
                                            ) === 'gcash'
                                            &&
                                            !empty(
                                                $order['payment_screenshot']
                                            )
                                        ): ?>

                                            <div
                                                class="history-payment-proof"
                                            >

                                                <h6>

                                                    <i class="bi bi-image me-1"></i>

                                                    GCash Payment Proof

                                                </h6>


                                                <img
                                                    src="../<?= htmlspecialchars(
                                                        $order['payment_screenshot']
                                                    ) ?>"
                                                    alt="GCash Payment Proof"
                                                >

                                            </div>

                                        <?php endif; ?>


                                    </div>


                                    <!-- FOOTER -->

                                    <div class="modal-footer">

                                        <button
                                            type="button"
                                            class="btn btn-secondary"
                                            data-bs-dismiss="modal"
                                        >

                                            Close

                                        </button>

                                    </div>


                                </div>

                            </div>

                        </div>


                    <?php endforeach; ?>


                    <!-- =================================================
                         PAGINATION
                    ================================================= -->

                    <?php if ($total_pages > 1): ?>


                        <div class="pagination-wrapper">


                            <?php

                            /*
                             * Preserve filters when changing pages.
                             */

                            $paginationParams = [
                                'view' => $history_view,
                                'status' => $status_filter
                            ];

                            if ($search !== '') {

                                $paginationParams['search'] =
                                    $search;
                            }

                            if ($date_filter !== '') {

                                $paginationParams['date'] =
                                    $date_filter;
                            }

                            ?>


                            <!-- PREVIOUS -->

                            <a
                                href="?<?= http_build_query(
                                    array_merge(
                                        $paginationParams,
                                        [
                                            'page' =>
                                                $page - 1
                                        ]
                                    )
                                ) ?>"
                                class="page-link-history <?= $page <= 1 ? 'disabled' : '' ?>"
                            >

                                <i class="bi bi-chevron-left"></i>

                            </a>


                            <?php

                            $startPage =
                                max(1, $page - 2);

                            $endPage =
                                min(
                                    $total_pages,
                                    $page + 2
                                );

                            for (
                                $paginationPage = $startPage;
                                $paginationPage <= $endPage;
                                $paginationPage++
                            ):

                            ?>


                                <a
                                    href="?<?= http_build_query(
                                        array_merge(
                                            $paginationParams,
                                            [
                                                'page' =>
                                                    $paginationPage
                                            ]
                                        )
                                    ) ?>"
                                    class="page-link-history <?= $paginationPage === $page ? 'active' : '' ?>"
                                >

                                    <?= $paginationPage ?>

                                </a>


                            <?php endfor; ?>


                            <!-- NEXT -->

                            <a
                                href="?<?= http_build_query(
                                    array_merge(
                                        $paginationParams,
                                        [
                                            'page' =>
                                                $page + 1
                                        ]
                                    )
                                ) ?>"
                                class="page-link-history <?= $page >= $total_pages ? 'disabled' : '' ?>"
                            >

                                <i class="bi bi-chevron-right"></i>

                            </a>


                        </div>


                    <?php endif; ?>


                <?php endif; ?>


            </div>


        </div>

    </main>

</div>


<?php

require_once '../includes/footer.php';

?>