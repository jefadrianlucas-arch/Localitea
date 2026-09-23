<?php

require_once '../includes/db.php';


/* =========================================================
   ADMIN ACCESS
========================================================= */

if (
    !isset($_SESSION['user_role']) ||
    !in_array($_SESSION['user_role'], ['admin'], true)
) {
    header("Location: ../auth/login.php");
    exit;
}

/* =========================================================
   DATE RANGE
========================================================= */

$today = date('Y-m-d');
$firstDayOfMonth = date('Y-m-01');

$start_date = $_GET['start_date'] ?? $firstDayOfMonth;
$end_date   = $_GET['end_date'] ?? $today;

/*
 * Validate date format.
 * If invalid, use the current month's first day / today.
 */
$startObject = DateTime::createFromFormat('Y-m-d', $start_date);
$endObject   = DateTime::createFromFormat('Y-m-d', $end_date);

if (
    !$startObject ||
    $startObject->format('Y-m-d') !== $start_date
) {
    $start_date = $firstDayOfMonth;
}

if (
    !$endObject ||
    $endObject->format('Y-m-d') !== $end_date
) {
    $end_date = $today;
}

/*
 * Prevent future dates.
 */
if ($start_date > $today) {
    $start_date = $today;
}

if ($end_date > $today) {
    $end_date = $today;
}

/*
 * Prevent an invalid range.
 */
if ($start_date > $end_date) {
    $temporaryDate = $start_date;
    $start_date = $end_date;
    $end_date = $temporaryDate;
}

/* =========================================================
   REPORT MODE
========================================================= */
$report_mode = $_GET['mode'] ?? null;
/* =========================================================
   DATE RANGE LENGTH
========================================================= */

$rangeStart = new DateTime($start_date);
$rangeEnd   = new DateTime($end_date);

$rangeDays = $rangeStart->diff($rangeEnd)->days + 1;


/* =========================================================
   LIMIT AVAILABLE REPORT MODES
========================================================= */

if ($rangeDays <= 10) {

    $availableModes = [
        'daily',
        'weekly',
        'monthly'
    ];

    if ($report_mode === null) {
        $report_mode = 'daily';
    }

} elseif ($rangeDays <= 180) {

    $availableModes = [
        'weekly',
        'monthly'
    ];

    if (
        $report_mode === null ||
        !in_array($report_mode, $availableModes, true)
    ) {
        $report_mode = 'weekly';
    }

} else {

    $availableModes = [
        'monthly'
    ];

    if (
        $report_mode === null ||
        !in_array($report_mode, $availableModes, true)
    ) {
        $report_mode = 'monthly';
    }
}


/* =========================================================
   SAFETY CHECK
========================================================= */

if (!in_array($report_mode, $availableModes, true)) {
    $report_mode = $availableModes[0];
}

/* =========================================================
   REPORT GENERATION DETAILS
========================================================= */

/*
 * The report is generated automatically from the currently
 * selected date range and report mode. No separate Generate
 * Report action is required before printing or browsing the
 * report.
 */
$reportGeneratedAt = date('Y-m-d H:i:s');
$reportGeneratedBy = $_SESSION['user_name'] ?? 'Admin User';

/* =========================================================
   SALES REPORT GENERATION TOAST
========================================================= */
$reportToast = null;
$reportToastAction = trim((string)($_GET['report_action'] ?? ''));

$reportToastMap = [
    'daily' => [
        'icon' => 'bi-bar-chart-line',
        'message' => 'Daily sales report generated successfully!'
    ],
    'weekly' => [
        'icon' => 'bi-bar-chart-line',
        'message' => 'Weekly sales report generated successfully!'
    ],
    'monthly' => [
        'icon' => 'bi-bar-chart-line',
        'message' => 'Monthly sales report generated successfully!'
    ]
];

if (
    $reportToastAction === '1' &&
    isset($reportToastMap[$report_mode])
) {
    $reportToast = $reportToastMap[$report_mode];
}

/* =========================================================
   FORMAT ORDER ADD-ONS
========================================================= */

function formatOrderAddons(?string $addons): string
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

    $parts = array_values(
        array_filter(
            array_map('trim', $parts)
        )
    );

    return implode(', ', $parts);
}


/* =========================================================
   GET COMPLETED SALES TRANSACTIONS
========================================================= */

/*
 * Only COMPLETED orders are included in sales reports.
 *
 * closed_at represents when the transaction was completed.
 * COALESCE is used as a fallback for older records where
 * closed_at may not have been populated.
 */
$salesStmt = $pdo->prepare("
    SELECT
        id,
        order_number,
        claim_number,
        customer_name,
        contact_number,
        pickup_date,
        pickup_time,
        payment_method,
        payment_screenshot,
        total_amount,
        status,
        created_at,
        closed_at
    FROM orders
    WHERE status = 'completed'
      AND DATE(COALESCE(closed_at, created_at))
          BETWEEN ? AND ?
    ORDER BY COALESCE(closed_at, created_at) DESC, id DESC
");

$salesStmt->execute([
    $start_date,
    $end_date
]);

$salesTransactions = $salesStmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================================================
   COMPLETED SALES TRANSACTION FILTER + AJAX SUPPORT

   These filters affect ONLY the Completed Sales Transactions
   section. The Sales Report summary and Sales Overview continue
   to use the main Start Date / End Date range above.
========================================================= */
$completed_search = trim((string)($_GET['completed_q'] ?? ''));

if (mb_strlen($completed_search) > 100) {
    $completed_search = mb_substr($completed_search, 0, 100);
}

$completed_period = strtolower(
    trim((string)($_GET['completed_period'] ?? 'today'))
);

$valid_completed_periods = [
    'today',
    'last_week',
    'last_month',
    'specific_month'
];

if (!in_array($completed_period, $valid_completed_periods, true)) {
    $completed_period = 'today';
}

$completed_month = trim(
    (string)($_GET['completed_month'] ?? date('Y-m'))
);

$completedMonthObject = DateTime::createFromFormat(
    '!Y-m',
    $completed_month
);

if (
    $completedMonthObject === false
    || $completedMonthObject->format('Y-m') !== $completed_month
) {
    $completed_month = date('Y-m');
} elseif ($completed_month > date('Y-m')) {
    $completed_month = date('Y-m');
}

$completedPeriodBounds = static function (string $period, string $month): array {
    $now = new DateTime('today');

    switch ($period) {
        case 'last_week':
            $start = clone $now;
            $start->modify('monday this week');
            $start->modify('-7 days');

            $end = clone $start;
            $end->modify('+6 days');
            return [$start->format('Y-m-d'), $end->format('Y-m-d')];

        case 'last_month':
            $start = new DateTime('first day of last month');
            $end = new DateTime('last day of last month');
            return [$start->format('Y-m-d'), $end->format('Y-m-d')];

        case 'specific_month':
            $start = DateTime::createFromFormat('!Y-m-d', $month . '-01');
            if ($start === false) {
                $start = new DateTime('first day of this month');
            }
            $end = clone $start;
            $end->modify('last day of this month');
            return [$start->format('Y-m-d'), $end->format('Y-m-d')];

        case 'today':
        default:
            return [$now->format('Y-m-d'), $now->format('Y-m-d')];
    }
};

[$completedFilterStart, $completedFilterEnd] =
    $completedPeriodBounds($completed_period, $completed_month);

$filteredSalesTransactions = array_values(
    array_filter(
        $salesTransactions,
        static function (array $sale) use (
            $completed_search,
            $completedFilterStart,
            $completedFilterEnd
        ): bool {
            $saleTimestamp = strtotime(
                $sale['closed_at'] ?? $sale['created_at'] ?? ''
            );

            if ($saleTimestamp === false) {
                return false;
            }

            $saleDate = date('Y-m-d', $saleTimestamp);

            if (
                $saleDate < $completedFilterStart ||
                $saleDate > $completedFilterEnd
            ) {
                return false;
            }

            if ($completed_search === '') {
                return true;
            }

            $haystack = mb_strtolower(
                implode(' ', [
                    (string)($sale['order_number'] ?? ''),
                    (string)($sale['claim_number'] ?? ''),
                    (string)($sale['customer_name'] ?? ''),
                    (string)($sale['contact_number'] ?? ''),
                    (string)($sale['id'] ?? '')
                ])
            );

            return mb_strpos(
                $haystack,
                mb_strtolower($completed_search)
            ) !== false;
        }
    )
);

/* =========================================================
   SUMMARY
========================================================= */

$totalSales = 0;
$totalOrders = count($salesTransactions);

foreach ($salesTransactions as $sale) {
    $totalSales += (float)$sale['total_amount'];
}

$averageOrder = $totalOrders > 0
    ? $totalSales / $totalOrders
    : 0;

/* =========================================================
   COMPLETED TRANSACTION PAGINATION
========================================================= */

$transactionPerPage = 10;

$transactionPage = (int)($_GET['transaction_page'] ?? 1);

if ($transactionPage < 1) {
    $transactionPage = 1;
}

$completedTransactionCount = count($filteredSalesTransactions);
$completedTransactionSalesTotal = 0.0;

foreach ($filteredSalesTransactions as $filteredSale) {
    $completedTransactionSalesTotal += (float)($filteredSale['total_amount'] ?? 0);
}

$totalTransactionPages = max(
    1,
    (int)ceil($completedTransactionCount / $transactionPerPage)
);

if ($transactionPage > $totalTransactionPages) {
    $transactionPage = $totalTransactionPages;
}

$transactionOffset =
    ($transactionPage - 1) * $transactionPerPage;

$displayTransactions = array_slice(
    $filteredSalesTransactions,
    $transactionOffset,
    $transactionPerPage
);

/* =========================================================
   GET ORDER ITEMS FOR DISPLAYED TRANSACTIONS
========================================================= */

$transactionOrderItems = [];

if (!empty($displayTransactions)) {

    $displayOrderIds = array_map(
        'intval',
        array_column($displayTransactions, 'id')
    );

    $placeholders = implode(
        ',',
        array_fill(0, count($displayOrderIds), '?')
    );

    $itemsStmt = $pdo->prepare("
        SELECT
            *
        FROM order_items
        WHERE order_id IN ($placeholders)
        ORDER BY id ASC
    ");

    $itemsStmt->execute($displayOrderIds);

    foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {

        $transactionOrderItems[(int)$item['order_id']][] = $item;
    }
}

/* =========================================================
   SALES OVERVIEW DATA
========================================================= */

$salesOverview = [];

/*
 * DAILY
 */
if ($report_mode === 'daily') {

    $periodStart = new DateTime($start_date);
    $periodEnd = new DateTime($end_date);

    while ($periodStart <= $periodEnd) {

        $key = $periodStart->format('Y-m-d');

        $salesOverview[$key] = [
            'label' => $periodStart->format('M d'),
            'sales' => 0,
            'orders' => 0
        ];

        $periodStart->modify('+1 day');
    }

    foreach ($salesTransactions as $sale) {

        $dateKey = date(
            'Y-m-d',
            strtotime($sale['closed_at'] ?? $sale['created_at'])
        );

        if (isset($salesOverview[$dateKey])) {

            $salesOverview[$dateKey]['sales']
                += (float)$sale['total_amount'];

            $salesOverview[$dateKey]['orders']++;
        }
    }
}

/*
 * WEEKLY
 */
if ($report_mode === 'weekly') {

    /*
     * Start from the Monday of the week
     * containing the selected start date.
     */
    $periodStart = clone $rangeStart;
    $periodStart->modify('monday this week');

    /*
     * End at the Sunday of the week
     * containing the selected end date.
     */
    $periodEnd = clone $rangeEnd;
    $periodEnd->modify('sunday this week');

    /*
     * Create every week in the selected range first.
     * This ensures zero-sales weeks are displayed.
     */
    while ($periodStart <= $periodEnd) {

        $year = $periodStart->format('o');
        $week = $periodStart->format('W');

        $key = $year . '-W' . $week;

        $weekEnd = clone $periodStart;
        $weekEnd->modify('+6 days');

        $weekStartLabel = $periodStart->format('M d');
        $weekEndLabel = $weekEnd->format('M d');

        /* Compact weekly label so date ranges do not visually collide. */
        $weeklyLabel = $periodStart->format('M') === $weekEnd->format('M')
            ? $periodStart->format('M d') . '–' . $weekEnd->format('d')
            : $weekStartLabel . '–' . $weekEndLabel;

        $salesOverview[$key] = [
            'label' => $weeklyLabel,
            'sales' => 0,
            'orders' => 0,
            'sort' => $periodStart->format('Y-m-d')
        ];

        $periodStart->modify('+1 week');
    }

    /*
     * Add completed sales to their corresponding week.
     */
    foreach ($salesTransactions as $sale) {

        $saleDate = new DateTime(
            $sale['closed_at'] ?? $sale['created_at']
        );

        $year = $saleDate->format('o');
        $week = $saleDate->format('W');

        $key = $year . '-W' . $week;

        if (isset($salesOverview[$key])) {

            $salesOverview[$key]['sales']
                += (float)$sale['total_amount'];

            $salesOverview[$key]['orders']++;
        }
    }

    uasort(
        $salesOverview,
        function ($a, $b) {
            return strcmp(
                $a['sort'],
                $b['sort']
            );
        }
    );
}

/*
 * MONTHLY
 */
if ($report_mode === 'monthly') {

    $periodStart = new DateTime(
        $rangeStart->format('Y-m-01')
    );

    $periodEnd = new DateTime(
        $rangeEnd->format('Y-m-01')
    );

    /*
     * Create every month in the selected range first.
     * This ensures months with zero sales are still displayed.
     */
    while ($periodStart <= $periodEnd) {

        $key = $periodStart->format('Y-m');

        $salesOverview[$key] = [
            // Compact month/year labels prevent crowding when many months
            // are shown, especially on mobile screens.
            'label' => $periodStart->format('M Y'),
            'sales' => 0,
            'orders' => 0,
            'sort' => $periodStart->format('Y-m')
        ];

        $periodStart->modify('+1 month');
    }

    /*
     * Add completed sales to their corresponding month.
     */
    foreach ($salesTransactions as $sale) {

        $saleDate = new DateTime(
            $sale['closed_at'] ?? $sale['created_at']
        );

        $key = $saleDate->format('Y-m');

        if (isset($salesOverview[$key])) {

            $salesOverview[$key]['sales']
                += (float)$sale['total_amount'];

            $salesOverview[$key]['orders']++;
        }
    }

    ksort($salesOverview);
}

/* =========================================================
   GRAPH SCALE
========================================================= */

$maxSales = 0;

foreach ($salesOverview as $period) {

    if ($period['sales'] > $maxSales) {
        $maxSales = $period['sales'];
    }
}

if ($maxSales <= 0) {
    $maxSales = 1;
}

/* =========================================================
   REPORT TITLE
========================================================= */

$modeLabels = [
    'daily'   => 'Daily',
    'weekly'  => 'Weekly',
    'monthly' => 'Monthly'
];

$reportModeLabel = $modeLabels[$report_mode];

$completedPeriodLabels = [
    'today' => 'Today',
    'last_week' => 'Last week',
    'last_month' => 'Last month',
    'specific_month' => 'Specific month'
];

require_once '../includes/header.php';

?>

<style>

/* =========================================================
   PAGE
========================================================= */

html,
body {
    max-width: 100%;
    overflow-x: hidden;
}

body {
    background: #F7F5F2;
}

.sales-page {
    min-height: calc(100vh - 70px);
    min-width: 0;
    width: 100%;
    margin-left: 0;
    box-sizing: border-box;
}

/*
 * IMPORTANT:
 * responsive.css applies the desktop sidebar offset to .sales-page.
 * This page already applies that offset to .admin-main, so allowing
 * both .sales-page and .admin-main to receive margin-left creates
 * a double-width blank area on Laptop / Laptop L / Desktop.
 *
 * The page wrapper stays full-width. Only .admin-main follows the
 * sidebar. The shared navbar uses the same responsive sidebar width.
 */
@media (min-width: 992px) {
    .sales-page {
        margin-left: 0 !important;
        width: 100% !important;
        max-width: none !important;
    }

    .localitea-admin-navbar {
        margin-left: 260px !important;
        width: calc(100% - 260px) !important;
    }
}

@media (max-width: 991.98px) {
    .sales-page {
        margin-left: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
    }

    .localitea-admin-navbar {
        margin-left: 0 !important;
        width: 100% !important;
    }
}

.sales-content {
    padding: 24px;
    min-width: 0;
    max-width: 100%;
    box-sizing: border-box;
}


/* =========================================================
   PAGE HEADER
========================================================= */

.sales-header {
    margin-bottom: 22px;
}

.sales-header h2 {
    color: #4A3525;
    font-weight: 800;
    margin-bottom: 5px;
}

.sales-header p {
    color: #4A3525;
    margin: 0;
    font-size: 0.88rem;
}


/* =========================================================
   FILTER CARD
========================================================= */

.filter-card {
    background: #ffffff;
    border: 2px solid #6F4E37;
    border-radius: 14px;
    padding: 22px;
    box-shadow: 0 3px 10px rgba(0,0,0,.04);
    margin-bottom: 20px;
}

.filter-title {
    color: #4A3525;
    font-size: 1.05rem;
    font-weight: 700;
    margin-bottom: 15px;
}

.filter-label {
    color: #77706A;
    font-size: 0.84rem;
    font-weight: 700;
    margin-bottom: 6px;
    display: block;
}

.filter-input {
    width: 100%;
    border: 2px solid #6F4E37;
    border-radius: 9px;
    padding: 10px 12px;
    font-size: 0.88rem;
    color: #4A3525;
    background: #fffdf9;
}

.filter-input:focus {
    outline: none;
    border-color: #6f4e37;
}

.filter-actions {
    display: flex;
    gap: 8px;
    align-items: end;
    height: 100%;
}

.btn-generate {
    background: #4A3525;
    color: #ffffff;
    border: 2px solid #6F4E37;
    border-radius: 9px;
    padding: 9px 16px;
    font-size: 0.82rem;
    font-weight: 700;
}

.btn-generate:hover {
    background: #3d2b20;
    color: #ffffff;
}

.btn-print {
    background: #ffffff;
    color: #4A3525;
    border: 2px solid #6F4E37;
    border-radius: 9px;
    padding: 9px 16px;
    font-size: 0.82rem;
    font-weight: 700;
}

.btn-print:hover {
    background: #F7F1E8;
    color: #4A3525;
    border-color: #B8A08A !important;
}


/* =========================================================
   SUMMARY CARDS
========================================================= */

.summary-card {
    background: #ffffff;
    border: 2px solid #6F4E37;
    border-radius: 14px;
    padding: 18px 20px;
    box-shadow: 0 3px 10px rgba(0,0,0,.04);
    height: 100%;
}

.summary-label {
    color: #8a7f75;
    font-size: 0.76rem;
    font-weight: 700;
}

.summary-value {
    color: #4A3525;
    font-size: 1.55rem;
    font-weight: 800;
    margin-top: 5px;
}


/* =========================================================
   REPORT CARD
========================================================= */

.report-card {
    background: #ffffff;
    border: 2px solid #6F4E37;
    border-radius: 14px;
    padding: 20px;
    box-shadow: 0 3px 10px rgba(0,0,0,.04);
    margin-top: 20px;
}

.report-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
    flex-wrap: wrap;
    margin-bottom: 18px;
}

.report-title {
    color: #2c221e;
    font-size: 1.05rem;
    font-weight: 800;
    margin: 0;
}

.report-period {
    color: #000000;
    font-size: 0.78rem;
    margin-top: 4px;
}


/* =========================================================
   REPORT MODE
========================================================= */

.mode-tabs {
    display: flex;
    gap: 7px;
    flex-wrap: wrap;
    position: relative;
    z-index: 10;
}

.mode-tab {
    position: relative;
    z-index: 11;
    display: inline-block;
    text-decoration: none;
    color: #000000;
    background: #ffffff;
    border: 2px solid #6F4E37;
    border-radius: 8px;
    padding: 7px 13px;
    font-size: 0.78rem;
    font-weight: 700;
    cursor: pointer;  
}

.mode-tab:hover {
    color: #4A3525;
    background: #F7F1E8;
}

.mode-tab.active {
    color: #ffffff;
    background: #4A3525;
    border-color: #4A3525;
}

/* =========================================================
   SALES OVERVIEW
========================================================= */

.overview-card {
    border: 2px solid #6F4E37;
    border-radius: 12px;
    background: #fffdf9;
    padding: 18px;
}

.overview-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 18px;
}

.overview-title {
    color: #4A3525;
    font-size: 0.9rem;
    font-weight: 800;
}

.overview-total {
    color: #4A3525;
    font-size: 1rem;
    font-weight: 800;
}

.chart {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: flex-end;
    gap: 3px;
    min-height: 250px;
    padding: 20px 4px 4px;
    width: 100%;
    overflow: hidden;
}

.chart-column {
    min-width: 0;
    flex: 1 1 0;
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    align-items: center;
    gap: 5px;
}

.chart-value {
    color: #000000;
    font-size: 0.84rem;
    font-weight: 800;
    white-space: nowrap;
}

.chart-bar-wrap {
    width: 100%;
    height: 165px;
    display: flex;
    align-items: flex-end;
    justify-content: center;
}

.chart-bar {
    width: 70%;
    max-width: 32px;
    min-height: 3px;
    background: #6f4e37;
    border-radius: 6px 6px 2px 2px;
    transition: height .2s ease;
}

.chart-label {
    color: #000000;
    font-size: 0.78rem;
    font-weight: 700;
    text-align: center;
    white-space: nowrap;
    line-height: 1.15;
}

/* Weekly view: give each period enough room so date ranges stay readable. */
.chart.chart-weekly {
    overflow-x: auto;
    overflow-y: hidden;
    justify-content: flex-start;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
}

.chart.chart-weekly .chart-column {
    flex: 0 0 82px;
    min-width: 82px;
}

.chart.chart-weekly .chart-label {
    white-space: normal;
    min-height: 30px;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    max-width: 82px;
    line-height: 1.15;
}

/* Monthly view: keep one month per column so Jan 2026, Feb 2026, etc.
   never overlap when the selected range spans many months. */
.chart.chart-monthly {
    overflow-x: auto;
    overflow-y: hidden;
    justify-content: flex-start;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
}

.chart.chart-monthly .chart-column {
    flex: 0 0 78px;
    min-width: 78px;
}

.chart.chart-monthly .chart-label {
    white-space: nowrap;
    max-width: 78px;
    text-align: center;
    line-height: 1.15;
}

/* =========================================================
   TRANSACTION TABLE
========================================================= */

.transaction-report-card {
    margin-top: 28px;
}

.transaction-section {
    margin-top: 0;
}

.transaction-title {
    color: #2c221e;
    font-size: 0.95rem;
    font-weight: 800;
    margin-bottom: 12px;
}

/* =========================================================
   COMPLETED SALES TRANSACTION FILTERS
========================================================= */
.completed-transaction-filter-form {
    display: flex;
    align-items: flex-end;
    gap: 8px;
    flex-wrap: wrap;
    margin: 0 0 16px;
    padding: 12px;
    border: 1px solid #B8A08A;
    border-radius: 10px;
    background: #FDF8F2;
}

.completed-transaction-filter-group {
    display: flex;
    flex-direction: column;
    gap: 5px;
    min-width: 0;
}

.completed-transaction-filter-group label {
    color: #7B6D62;
    font-size: .68rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .3px;
}

.completed-transaction-filter-group input,
.completed-transaction-filter-group select {
    height: 38px;
    border: 1px solid #B8A08A;
    border-radius: 8px;
    padding: 6px 10px;
    font-size: .82rem;
    color: #4A3525;
    background: #FFFFFF;
    outline: none;
    box-sizing: border-box;
}

.completed-transaction-filter-group input:focus,
.completed-transaction-filter-group select:focus {
    border-color: #6F4E37;
    box-shadow: 0 0 0 2px rgba(111,78,55,.10);
}

.completed-transaction-filter-search {
    width: 290px;
    max-width: 100%;
}

.completed-transaction-filter-period {
    width: 170px;
}

.completed-transaction-filter-month {
    width: 155px;
}

.completed-transaction-filter-month-wrap {
    display: none;
}

.completed-transaction-filter-actions {
    display: flex;
    gap: 8px;
}

.completed-transaction-filter-actions .btn {
    height: 38px;
    border-radius: 8px;
    font-size: .82rem;
    font-weight: 700;
    padding: 7px 13px;
}

.completed-transaction-filter-clear {
    background: #FFFFFF;
    border: 1px solid #B8A08A;
    color: #6F4E37;
    text-decoration: none;
}

.completed-transaction-filter-clear:hover {
    background: #F7F1E8;
    color: #4A3525;
}

.completed-transactions-ajax-busy {
    opacity: .58;
    pointer-events: none;
    transition: opacity .12s ease;
}

@media (max-width: 768px) {
    .completed-transaction-filter-form {
        align-items: stretch;
        flex-direction: column;
    }

    .completed-transaction-filter-group,
    .completed-transaction-filter-search,
    .completed-transaction-filter-period,
    .completed-transaction-filter-month,
    .completed-transaction-filter-actions {
        width: 100%;
        max-width: 100%;
    }

    .completed-transaction-filter-actions .btn,
    .completed-transaction-filter-clear {
        flex: 1 1 0;
        text-align: center;
        justify-content: center;
    }
}

.transaction-table {
    margin-bottom: 0;
    width: 100%;
}

.transaction-table .transaction-col-index {
    width: 6%;
}

.transaction-table .transaction-col-order {
    width: 30%;
}

.transaction-table .transaction-col-payment {
    width: 15%;
}

.transaction-table .transaction-col-amount {
    width: 13%;
}

.transaction-table .transaction-col-date {
    width: 18%;
}

.transaction-table .transaction-col-details {
    width: 18%;
}

.transaction-index-cell {
    white-space: nowrap;
}

.transaction-index-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: #F7F1E8;
    color: #6F4E37;
    font-size: 0.68rem;
    font-weight: 800;
    line-height: 1;
}

.transaction-order-cell {
    min-width: 0;
}

.transaction-order-number {
    color: #2C221E;
    font-size: 0.82rem;
    font-weight: 800;
    line-height: 1.15;
    overflow-wrap: anywhere;
}

.transaction-claim-number {
    margin-top: 2px;
    color: #8A7F75;
    font-size: 0.67rem;
    font-weight: 500;
    line-height: 1.1;
}

.transaction-payment-cell .payment-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 62px;
    padding: 6px 10px;
    border-radius: 7px;
    font-size: 0.78rem;
    font-weight: 800;
    line-height: 1;
    white-space: nowrap;
}

.transaction-payment-cell .payment-badge.gcash {
    background: #E7F1FF;
    color: #1677FF;
    border: 1px solid #1677FF;
}

.transaction-payment-cell .payment-badge.cash {
    background: #F7F1E8;
    color: #6F4E37;
    border: 1px solid #D8C4B2;
}

.transaction-amount-cell {
    color: #4A3525;
    font-size: 0.86rem;
    font-weight: 800;
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
}

.transaction-date-cell {
    white-space: nowrap;
}

.transaction-date,
.transaction-time {
    display: block;
    color: #6F665F;
    line-height: 1.2;
}

.transaction-date {
    font-size: 0.75rem;
    font-weight: 600;
}

.transaction-time {
    font-size: 0.66rem;
    margin-top: 2px;
}

.transaction-details-cell .btn-view-transaction {
    white-space: nowrap;
}

.transaction-table th {
    color: #8a7f75;
    font-size: 0.7rem;
    text-transform: uppercase;
    white-space: nowrap;
    border-bottom: 2px solid #6F4E37;
    padding: 11px 10px;
}

.transaction-table td {
    color: #4A3525;
    font-size: 0.8rem;
    vertical-align: middle;
    border-bottom: 2px solid #6F4E37;
    padding: 11px 10px;
}

.transaction-table .amount {
    font-weight: 800;
}

.payment-badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 6px;
    background: #F7F1E8;
    color: #6f4e37;
    font-size: 0.68rem;
    font-weight: 700;
}

.btn-view-transaction {
    border: 2px solid #6F4E37;
    border-radius: 8px;
    padding: 6px 10px;
    background: #ffffff;
    color: #4A3525;
    font-size: 0.72rem;
    font-weight: 700;
    white-space: nowrap;
}

.btn-view-transaction:hover {
    background: #4A3525;
    color: #ffffff;
}

.transaction-pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 6px;
    margin-top: 18px;
    flex-wrap: wrap;
}

.transaction-page-link {
    min-width: 34px;
    height: 34px;
    padding: 0 9px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 2px solid #6F4E37;
    border-radius: 7px;
    background: #ffffff;
    color: #4A3525;
    text-decoration: none;
    font-size: 0.72rem;
    font-weight: 700;
}

.transaction-page-link:hover {
    background: #F7F1E8;
    color: #4A3525;
}

.transaction-page-link.active {
    background: #4A3525;
    border-color: #4A3525;
    color: #ffffff;
}

.transaction-page-link.disabled {
    opacity: 0.45;
    pointer-events: none;
}

.transaction-page-info {
    color: #8a7f75;
    font-size: 0.74rem;
    margin-top: 8px;
    text-align: center;
}

.transaction-detail-item {
    padding: 10px 0;
    border-bottom: 2px dashed #6F4E37;
}

.transaction-detail-item:last-child {
    border-bottom: none;
}

.transaction-detail-item-name {
    color: #4A3525;
    font-size: 0.82rem;
    font-weight: 700;
}

.transaction-detail-item-info {
    color: #8a7f75;
    font-size: 0.72rem;
    margin-top: 3px;
    line-height: 1.5;
}

.transaction-detail-item-price {
    color: #4A3525;
    font-size: 0.8rem;
    font-weight: 800;
    white-space: nowrap;
}

.transaction-detail-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
    margin-bottom: 18px;
}

.transaction-detail-box {
    background: #FDF8F2;
    border: 2px solid #6F4E37;
    border-radius: 9px;
    padding: 11px;
}

.transaction-detail-box small {
    display: block;
    color: #8a7f75;
    font-size: 0.65rem;
    margin-bottom: 4px;
}

.transaction-detail-box strong {
    color: #4A3525;
    font-size: 0.78rem;
}

.transaction-detail-total {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 10px;
    padding-top: 12px;
    border-top: 2px solid #6F4E37;
}

.transaction-detail-total span {
    color: #8a7f75;
    font-size: 0.78rem;
}

.transaction-detail-total strong {
    color: #4A3525;
    font-size: 1rem;
}

.transaction-payment-proof {
    margin-top: 18px;
    padding-top: 14px;
    border-top: 2px solid #6F4E37;
}

.transaction-payment-proof h6 {
    color: #4A3525;
    font-size: 0.8rem;
    font-weight: 800;
    margin-bottom: 9px;
}

.btn-view-payment-proof {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 8px 12px;
    border: 2px solid #6F4E37;
    border-radius: 8px;
    background: #ffffff;
    color: #4A3525;
    font-size: 0.74rem;
    font-weight: 700;
    cursor: pointer;
    transition: background .15s ease, border-color .15s ease;
}

.btn-view-payment-proof:hover,
.btn-view-payment-proof[aria-expanded="true"] {
    background: #F7F1E8;
    color: #4A3525;
    border-color: #4A3525;
}

.gcash-proof-chevron {
    transition: transform .2s ease;
}

.btn-view-payment-proof[aria-expanded="true"] .gcash-proof-chevron {
    transform: rotate(180deg);
}

.gcash-proof-dropdown {
    margin-top: 10px;
    padding: 12px;
    border: 2px solid #6F4E37;
    border-radius: 10px;
    background: #FDF8F2;
}

.gcash-proof-dropdown img {
    display: block;
    width: 100%;
    max-height: 420px;
    object-fit: contain;
    border: 2px solid #6F4E37;
    border-radius: 9px;
    background: #ffffff;
}

@media (max-width: 576px) {
    .transaction-detail-grid {
        grid-template-columns: 1fr;
    }
}

.report-footer-summary {
    display: flex;
    justify-content: flex-end;
    gap: 25px;
    padding-top: 14px;
    margin-top: 5px;
    border-top: 2px solid #6F4E37;
}

.report-footer-summary div {
    color: #8a7f75;
    font-size: 0.76rem;
}

.report-footer-summary strong {
    color: #4A3525;
    font-size: 0.88rem;
}


/* =========================================================
   FIXED SALES REPORT ACTION TOAST
   Matches the existing Orders toast style.
========================================================= */
.sales-report-toast-wrap {
    position: fixed;
    top: 88px;
    right: 24px;
    z-index: 2000;
    width: min(420px, calc(100vw - 32px));
    pointer-events: none;
}

.sales-report-toast {
    position: relative;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 13px 14px;
    background: #ffffff;
    border: 2px solid #6F4E37;
    border-left: 6px solid #4A8B5A;
    border-radius: 12px;
    box-shadow: 0 10px 28px rgba(44,34,30,.18);
    color: #2C221E;
    pointer-events: auto;
    overflow: hidden;
    animation: salesReportToastIn .22s ease-out;
}

.sales-report-toast-icon {
    flex: 0 0 30px;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #EAF6EE;
    color: #2F6E3E;
    font-size: 15px;
    margin-top: 1px;
}

.sales-report-toast-message {
    flex: 1;
    padding-top: 3px;
    font-size: .9rem;
    line-height: 1.45;
    font-weight: 700;
}

.sales-report-toast-close {
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

.sales-report-toast-close:hover {
    background: #F0E6D6;
    color: #2C221E;
}

.sales-report-toast-progress {
    position: absolute;
    left: 0;
    bottom: 0;
    height: 3px;
    width: 100%;
    background: #4A8B5A;
    transform-origin: left center;
    animation: salesReportToastProgress 3.5s linear forwards;
}

.sales-report-toast.is-restored {
    animation: none;
}

.sales-report-toast.is-closing {
    animation: salesReportToastOut .18s ease-in forwards;
}

@keyframes salesReportToastIn {
    from { opacity: 0; transform: translateY(-8px) translateX(8px); }
    to { opacity: 1; transform: translateY(0) translateX(0); }
}

@keyframes salesReportToastOut {
    from { opacity: 1; transform: translateY(0) translateX(0); }
    to { opacity: 0; transform: translateY(-6px) translateX(8px); }
}

@keyframes salesReportToastProgress {
    from { transform: scaleX(1); }
    to { transform: scaleX(0); }
}

/* =========================================================
   EMPTY STATE
========================================================= */

.empty-report {
    text-align: center;
    padding: 40px 20px;
    color: #8a7f75;
}

.empty-report i {
    font-size: 2rem;
    color: #B8A08A;
    margin-bottom: 8px;
}

.empty-report p {
    margin: 0;
    font-size: 0.82rem;
}

/* =========================================================
   MOBILE
========================================================= */

@media (max-width: 768px) {

    .sales-content {
        padding: 15px;
    }

    .filter-card {
        padding: 15px;
    }

    .filter-actions {
        align-items: stretch;
        flex-direction: column;
    }

    .btn-generate,
    .btn-print {
        width: 100%;
    }

    .report-card {
        padding: 15px;
    }

    .chart {
        min-height: 220px;
        padding-left: 2px;
        padding-right: 2px;
    }

    .report-footer-summary {
        justify-content: space-between;
        gap: 10px;
        flex-wrap: wrap;
    }

    .sales-report-toast-wrap {
        top: 74px;
        right: 14px;
        width: min(420px, calc(100vw - 28px));
    }

}

/* =========================================================
   MOBILE LAYOUT (phones)
========================================================= */

@media (max-width: 767.98px) {

    .chart.chart-weekly .chart-column {
        flex-basis: 74px;
        min-width: 74px;
    }

    .chart.chart-weekly .chart-label {
        max-width: 74px;
        font-size: 0.68rem;
    }

    .chart.chart-monthly .chart-column {
        flex-basis: 72px;
        min-width: 72px;
    }

    .chart.chart-monthly .chart-label {
        max-width: 72px;
        font-size: 0.68rem;
    }

    .sales-content {
        padding: 14px 12px 24px;
    }

    .filter-card,
    .report-card {
        padding: 14px 12px;
        border-radius: 16px;
    }

    .overview-card {
        padding: 14px 10px;
        border-radius: 14px;
    }

    /* Summary cards: total sales full width, the other two side by side */
    .summary-card {
        padding: 14px;
    }

    .summary-value {
        font-size: 1.2rem;
    }

    /* Chart scrolls sideways instead of crushing 30 bars together */
    .chart {
        overflow-x: auto;
        overflow-y: hidden;
        justify-content: flex-start;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: thin;
        padding-bottom: 8px;
    }

    .chart-column {
        flex: 1 0 46px;
        min-width: 46px;
    }

    .chart-value {
        font-size: 0.68rem;
    }

    .chart-label {
        font-size: 0.68rem;
    }

    /* ---------------------------------------------------------
       COMPLETED SALES TRANSACTIONS - MOBILE CARDS
       Order/claim stay beside the # column.
       Date/time and View Details stay on the right.
    --------------------------------------------------------- */

    .transaction-report-card .table-responsive {
        overflow: visible !important;
    }

    .transaction-table,
    .transaction-table tbody {
        display: block !important;
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
    }

    .transaction-table thead {
        display: none !important;
    }

    .transaction-table tbody tr {
        display: grid !important;
        grid-template-columns: 30px minmax(0, 1fr) 94px;
        grid-template-rows: auto auto auto auto;
        column-gap: 8px;
        row-gap: 3px;
        align-items: start;
        width: 100% !important;
        margin: 0 0 8px !important;
        padding: 10px !important;
        background: #ffffff;
        border: 1px solid #E8DED3;
        border-radius: 12px;
        box-shadow: 0 2px 6px rgba(57, 39, 27, .04);
        box-sizing: border-box;
    }

    .transaction-table tbody tr:last-child {
        margin-bottom: 0;
    }

    .transaction-table tbody td {
        display: block !important;
        width: auto !important;
        min-width: 0 !important;
        max-width: 100% !important;
        min-height: 0 !important;
        padding: 0 !important;
        margin: 0 !important;
        border: 0 !important;
        text-align: left !important;
        overflow: visible !important;
        overflow-wrap: anywhere;
        word-break: normal;
        line-height: 1.15 !important;
    }

    .transaction-table tbody td::before {
        display: none !important;
        content: none !important;
    }

    .transaction-index-cell {
        grid-column: 1;
        grid-row: 1 / span 4;
        display: flex !important;
        align-items: flex-start !important;
        justify-content: center !important;
        padding-top: 2px !important;
    }

    .transaction-index-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 24px;
        height: 24px;
        border-radius: 50%;
        background: #F7F1E8;
        color: #6F4E37;
        font-size: 0.62rem;
        font-weight: 800;
        line-height: 1;
    }

    .transaction-order-cell {
        grid-column: 2;
        grid-row: 1;
        min-width: 0 !important;
    }

    .transaction-order-number {
        color: #2C221E;
        font-size: 0.80rem;
        font-weight: 800;
        line-height: 1.15;
        overflow-wrap: anywhere;
    }

    .transaction-claim-number {
        margin-top: 2px;
        color: #8A7F75;
        font-size: 0.62rem;
        font-weight: 500;
        line-height: 1.1;
        white-space: nowrap;
    }

    .transaction-amount-cell {
        grid-column: 2;
        grid-row: 2;
        text-align: left !important;
        color: #4A3525;
        font-size: 0.86rem;
        font-weight: 800;
        white-space: nowrap;
        padding-top: 5px !important;
    }

    .transaction-payment-cell {
        grid-column: 2;
        grid-row: 3;
        text-align: left !important;
        white-space: nowrap;
        padding-top: 2px !important;
    }

    .transaction-payment-cell .payment-badge {
        display: inline-flex !important;
        align-items: center;
        justify-content: center;
        min-width: 0 !important;
        padding: 4px 7px !important;
        border-radius: 6px !important;
        font-size: 0.64rem !important;
        font-weight: 800 !important;
        line-height: 1 !important;
        white-space: nowrap !important;
    }

    .transaction-payment-cell .payment-badge.gcash {
        background: #E7F1FF !important;
        color: #1677FF !important;
        border: 1px solid #1677FF !important;
    }

    .transaction-payment-cell .payment-badge.cash {
        background: #F7F1E8 !important;
        color: #6F4E37 !important;
        border: 1px solid #D8C4B2 !important;
    }

    .transaction-date-cell {
        grid-column: 3;
        grid-row: 1 / span 2;
        align-self: start;
        text-align: right !important;
    }

    .transaction-date,
    .transaction-time {
        display: block;
        color: #6F665F;
        white-space: nowrap;
    }

    .transaction-date {
        font-size: 0.64rem;
        font-weight: 600;
        line-height: 1.2;
    }

    .transaction-time {
        font-size: 0.58rem;
        line-height: 1.2;
        margin-top: 1px;
    }

    .transaction-details-cell {
        grid-column: 3;
        grid-row: 3;
        display: flex !important;
        align-items: flex-end !important;
        justify-content: flex-end !important;
        align-self: start;
        padding-top: 4px !important;
    }

    .transaction-details-cell .btn-view-transaction {
        width: auto !important;
        min-width: 0 !important;
        min-height: 30px !important;
        height: 30px !important;
        padding: 4px 8px !important;
        display: inline-flex !important;
        align-items: center;
        justify-content: center;
        gap: 4px;
        border-radius: 7px !important;
        font-size: 0.61rem !important;
        line-height: 1 !important;
        white-space: nowrap !important;
    }

    .transaction-details-cell .btn-view-transaction i {
        margin-right: 0 !important;
        font-size: 0.65rem !important;
    }

    @media (max-width: 379.98px) {
        .transaction-table tbody tr {
            grid-template-columns: 27px minmax(0, 1fr) 88px;
            column-gap: 7px;
            row-gap: 2px;
            padding: 9px !important;
            border-radius: 11px;
        }

        .transaction-index-badge {
            width: 22px;
            height: 22px;
            font-size: 0.58rem;
        }

        .transaction-order-number {
            font-size: 0.75rem;
        }

        .transaction-claim-number {
            font-size: 0.59rem;
        }

        .transaction-amount-cell {
            font-size: 0.82rem;
        }

        .transaction-date {
            font-size: 0.60rem;
        }

        .transaction-time {
            font-size: 0.55rem;
        }

        .transaction-details-cell .btn-view-transaction {
            min-height: 28px !important;
            height: 28px !important;
            padding: 4px 7px !important;
            font-size: 0.58rem !important;
        }
    }

    .transaction-pagination,
    .transaction-page-info {
        justify-content: center;
    }

    .report-footer-summary {
        flex-direction: column;
        align-items: flex-start;
    }
}



@media (max-width: 767.98px) {
    .sales-content .transaction-report-card .table-responsive {
        overflow-x: visible !important;
        overflow-y: visible !important;
    }
}

/* =========================================================
   PRINT
========================================================= */

@media print {

    body {
        background: #ffffff !important;
    }

    .no-print,
    .transaction-pagination,
    .transaction-page-info,
    .sidebar,
    .filter-card,
    .mode-tabs {
        display: none !important;
    }

    .sales-content {
        padding: 0;
    }

    .report-card {
        border: none !important;
        box-shadow: none !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    .overview-card {
        border: 2px solid #6F4E37 !important;
        page-break-inside: avoid;
    }

    .summary-card {
        box-shadow: none !important;
    }

    .transaction-table {
        width: 100%;
    }
}
/* =========================================================
   FINAL RESPONSIVE TRANSACTION CARD
   Mobile S / M / L
========================================================= */

@media (max-width: 767.98px) {

    .transaction-table tbody tr {
        display: grid !important;

        grid-template-columns:
            27px
            minmax(0, 1fr)
            88px;

        /*
         * ROW 1:
         * Order + Claim
         *
         * ROW 2:
         * Empty vertical space + Amount at bottom
         *
         * ROW 3:
         * Payment + View Details
         */
        grid-template-rows:
            auto
            46px
            30px;

        column-gap: 7px;
        row-gap: 5px;

        width: 100% !important;
        min-width: 0 !important;

        padding: 10px !important;

        box-sizing: border-box;
    }


    /* =========================
       NUMBER
    ========================= */

    .transaction-index-cell {
        grid-column: 1 !important;
        grid-row: 1 / span 3 !important;

        display: flex !important;

        align-items: flex-start !important;
        justify-content: center !important;

        padding-top: 2px !important;
        margin: 0 !important;
    }


    /* =========================
       ORDER + CLAIM
    ========================= */

    .transaction-order-cell {
        grid-column: 2 !important;
        grid-row: 1 !important;

        min-width: 0 !important;

        align-self: start !important;

        padding: 0 !important;
        margin: 0 !important;
    }


    .transaction-order-number {
        font-size: clamp(
            0.75rem,
            2.8vw,
            0.80rem
        ) !important;

        font-weight: 800 !important;

        line-height: 1.15 !important;

        overflow-wrap: anywhere !important;
    }


    .transaction-claim-number {
        margin-top: 3px !important;

        font-size: clamp(
            0.59rem,
            2.2vw,
            0.62rem
        ) !important;

        line-height: 1.1 !important;

        white-space: nowrap !important;
    }


    /* =========================
       AMOUNT
       MOVED DOWN
    ========================= */

    .transaction-amount-cell {
        grid-column: 2 !important;
        grid-row: 2 !important;

        align-self: end !important;
        justify-self: start !important;

        text-align: left !important;

        font-size: clamp(
            0.82rem,
            3vw,
            0.86rem
        ) !important;

        font-weight: 800 !important;

        line-height: 1.1 !important;

        white-space: nowrap !important;

        padding: 0 !important;
        margin: 0 !important;
    }


    /* =========================
       PAYMENT
       BELOW AMOUNT
    ========================= */

    .transaction-payment-cell {
        grid-column: 2 !important;
        grid-row: 3 !important;

        align-self: start !important;
        justify-self: start !important;

        text-align: left !important;

        padding: 0 !important;
        margin: 0 !important;

        white-space: nowrap !important;
    }


    .transaction-payment-cell .payment-badge {
        display: inline-flex !important;

        align-items: center !important;
        justify-content: center !important;

        min-width: 0 !important;

        padding: 4px 7px !important;

        border-radius: 6px !important;

        font-size: clamp(
            0.60rem,
            2.4vw,
            0.64rem
        ) !important;

        line-height: 1 !important;

        white-space: nowrap !important;
    }


    /* =========================
       DATE + TIME
       RIGHT SIDE
    ========================= */

    .transaction-date-cell {
        grid-column: 3 !important;
        grid-row: 1 / span 2 !important;

        align-self: start !important;
        justify-self: end !important;

        text-align: right !important;

        padding: 0 !important;
        margin: 0 !important;

        white-space: nowrap !important;
    }


    .transaction-date,
    .transaction-time {
        display: block !important;

        white-space: nowrap !important;
    }


    .transaction-date {
        font-size: clamp(
            0.60rem,
            2.5vw,
            0.64rem
        ) !important;

        line-height: 1.2 !important;
    }


    .transaction-time {
        font-size: clamp(
            0.55rem,
            2.2vw,
            0.58rem
        ) !important;

        line-height: 1.2 !important;

        margin-top: 1px !important;
    }


    /* =========================
       VIEW DETAILS
       RIGHT SIDE / BOTTOM
    ========================= */

    .transaction-details-cell {
        grid-column: 3 !important;
        grid-row: 3 !important;

        display: flex !important;

        align-items: center !important;
        justify-content: flex-end !important;

        align-self: center !important;

        padding: 0 !important;
        margin: 0 !important;
    }


    .transaction-details-cell .btn-view-transaction {
        display: inline-flex !important;

        align-items: center !important;
        justify-content: center !important;

        width: auto !important;
        min-width: 0 !important;

        height: 30px !important;
        min-height: 30px !important;

        padding: 4px 8px !important;

        gap: 4px;

        border-radius: 7px !important;

        font-size: clamp(
            0.58rem,
            2.3vw,
            0.61rem
        ) !important;

        line-height: 1 !important;

        white-space: nowrap !important;
    }
}


/* =========================================================
   MOBILE S
   320px - 379.98px
========================================================= */

@media (max-width: 379.98px) {

    .chart.chart-monthly .chart-column {
        flex-basis: 68px;
        min-width: 68px;
    }

    .chart.chart-monthly .chart-label {
        max-width: 68px;
        font-size: 0.64rem;
    }

    .transaction-table tbody tr {
        grid-template-columns:
            27px
            minmax(0, 1fr)
            88px;

        grid-template-rows:
            auto
            40px
            28px;

        column-gap: 7px;
        row-gap: 5px;

        padding: 9px !important;
    }


    .transaction-index-badge {
        width: 22px !important;
        height: 22px !important;

        font-size: 0.58rem !important;
    }


    .transaction-order-number {
        font-size: 0.75rem !important;
    }


    .transaction-claim-number {
        font-size: 0.59rem !important;

        margin-top: 3px !important;
    }


    .transaction-amount-cell {
        font-size: 0.82rem !important;
    }


    .transaction-payment-cell .payment-badge {
        padding: 4px 6px !important;

        font-size: 0.60rem !important;
    }


    .transaction-date {
        font-size: 0.60rem !important;
    }


    .transaction-time {
        font-size: 0.55rem !important;
    }


    .transaction-details-cell .btn-view-transaction {
        height: 28px !important;
        min-height: 28px !important;

        padding: 4px 7px !important;

        font-size: 0.58rem !important;
    }
}

</style>


<div class="sales-page">

    <!-- SIDEBAR -->
    <?php require_once 'sidebar.php'; ?>

    <!-- SHARED ADMIN NAVBAR -->
    <?php require_once 'navbar.php'; ?>

    <!-- CONTENT -->
    <main class="admin-main sales-content">

            <!-- FILTERS -->
            <div class="filter-card no-print">

                <div class="filter-title">
                    Sales Report Filters
                </div>

                <form
                    method="GET"
                    action="sales-reports.php"
                    id="salesReportFilterForm"
                >

                    <input type="hidden" name="mode" value="<?= htmlspecialchars($report_mode) ?>">
                    <input type="hidden" name="report_action" value="1">

                    <div class="row g-3 align-items-end">

                        <div class="col-md-4">

                            <label class="filter-label">
                                Start Date
                            </label>

                            <input
                                type="date"
                                name="start_date"
                                class="filter-input"
                                value="<?= htmlspecialchars($start_date) ?>"
                                max="<?= htmlspecialchars($today) ?>"
                                required
                            >

                        </div>


                        <div class="col-md-4">

                            <label class="filter-label">
                                End Date
                            </label>

                            <input
                                type="date"
                                name="end_date"
                                class="filter-input"
                                value="<?= htmlspecialchars($end_date) ?>"
                                min="<?= htmlspecialchars($start_date) ?>"
                                required
                            >

                        </div>


                        <div class="col-md-4">

                            <div class="filter-actions">

                                <button
                                    type="submit"
                                    class="btn btn-generate"
                                >
                                    <i class="bi bi-bar-chart-line me-1"></i>
                                    Generate Sales Overview
                                </button>

                                <a
                                    href="print-sales-report.php?start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>"
                                    target="_blank"
                                    class="btn btn-print"
                                    id="printSalesReportBtn"
                                >
                                    <i class="bi bi-printer me-1"></i>
                                    Print Report
                                </a>

                            </div>

                        </div>

                    </div>

                </form>

            </div>


            <!-- PRINTABLE REPORT -->
            <section class="report-card" id="printableReport">


                <!-- REPORT HEADER -->
                <div class="report-card-header">

                    <div>

                        <h3 class="report-title">
                            Local Milktea House
                        </h3>

                        <div class="report-period">
                            Sales Report:
                            <?= date('F d, Y', strtotime($start_date)) ?>
                            –
                            <?= date('F d, Y', strtotime($end_date)) ?>
                        </div>

                    </div>
                </div>


                <!-- SUMMARY -->
                <div class="row g-3">

                    <div class="col-12 col-md-4">

                        <div class="summary-card">

                            <div class="summary-label">
                                Total Sales
                            </div>

                            <div class="summary-value">
                                ₱<?= number_format($totalSales, 2) ?>
                            </div>

                        </div>

                    </div>


                    <div class="col-6 col-md-4">

                        <div class="summary-card">

                            <div class="summary-label">
                                Total Completed Orders
                            </div>

                            <div class="summary-value">
                                <?= number_format($totalOrders) ?>
                            </div>

                        </div>

                    </div>


                    <div class="col-6 col-md-4">

                        <div class="summary-card">

                            <div class="summary-label">
                                Average Order
                            </div>

                            <div class="summary-value">
                                ₱<?= number_format($averageOrder, 2) ?>
                            </div>

                        </div>

                    </div>

                </div>


                <!-- SALES OVERVIEW -->
                <div class="overview-card mt-4">

                    <div class="overview-head">

                        <div class="overview-title">
                            Sales Overview
                        </div>

                        <div class="overview-total">
                            ₱<?= number_format($totalSales, 2) ?>
                        </div>

                    </div>


                    <!-- MODE TABS -->
                    <div class="mode-tabs mb-3 no-print">

                    <?php foreach ($availableModes as $modeOption): ?>

                        <a
                            href="<?= htmlspecialchars(
                                'sales-reports.php?' . http_build_query(array_filter([
                                    'start_date' => $start_date,
                                    'end_date' => $end_date,
                                    'mode' => $modeOption,
                                    'completed_q' => $completed_search,
                                    'completed_period' => $completed_period,
                                    'completed_month' => $completed_period === 'specific_month'
                                        ? $completed_month
                                        : '',
                                    'transaction_page' => $transactionPage
                                ], static fn($value) => $value !== '' && $value !== null))
                            ) ?>"
                            class="mode-tab <?= $report_mode === $modeOption ? 'active' : '' ?>"
                            data-preserve-scroll="1"
                        >
                            <?= ucfirst($modeOption) ?>
                        </a>

                    <?php endforeach; ?>

                </div>


                    <?php if (empty($salesOverview)): ?>

                        <div class="empty-report">

                            <i class="bi bi-bar-chart"></i>

                            <p>
                                No completed sales transactions were recorded
                                within the selected period.
                            </p>

                        </div>

                    <?php else: ?>

                        <div class="chart <?= $report_mode === 'weekly' ? 'chart-weekly' : ($report_mode === 'monthly' ? 'chart-monthly' : '') ?>">

                            <?php foreach ($salesOverview as $period): ?>

                                <?php

                                $heightPercentage =
                                    ($period['sales'] / $maxSales) * 100;

                                if (
                                    $period['sales'] > 0 &&
                                    $heightPercentage < 5
                                ) {
                                    $heightPercentage = 5;
                                }

                                ?>

                                <div class="chart-column">

                                    <div class="chart-value">
                                        ₱<?= number_format($period['sales'], 0) ?>
                                    </div>

                                    <div class="chart-bar-wrap">

                                        <div
                                            class="chart-bar"
                                            style="height: <?= $heightPercentage ?>%;"
                                        ></div>

                                    </div>

                                    <div class="chart-label">
                                        <?= htmlspecialchars($period['label']) ?>
                                    </div>

                                </div>

                            <?php endforeach; ?>

                        </div>

                    <?php endif; ?>

                </div>


            </section>


            <!-- COMPLETED SALES TRANSACTIONS -->
            <section class="report-card transaction-report-card">

                <div class="transaction-section">

                    <div class="transaction-title">
                        Completed Sales Transactions
                    </div>

                    <form
                        method="GET"
                        action="sales-reports.php"
                        class="completed-transaction-filter-form no-print"
                        data-completed-transaction-filter-form
                    >
                        <div class="completed-transaction-filter-group">
                            <label for="completedTransactionSearch">
                                Search
                            </label>

                            <input
                                type="search"
                                id="completedTransactionSearch"
                                class="completed-transaction-filter-search"
                                data-completed-transaction-search
                                name="completed_q"
                                value="<?= htmlspecialchars($completed_search) ?>"
                                maxlength="100"
                                placeholder="Order, claim, customer, phone..."
                                autocomplete="off"
                            >
                        </div>

                        <div class="completed-transaction-filter-group">
                            <label for="completedTransactionPeriod">
                                Date
                            </label>

                            <select
                                id="completedTransactionPeriod"
                                class="completed-transaction-filter-period"
                                data-completed-transaction-period
                                name="completed_period"
                            >
                                <?php foreach ($completedPeriodLabels as $periodKey => $periodLabel): ?>
                                    <option
                                        value="<?= htmlspecialchars($periodKey) ?>"
                                        <?= $completed_period === $periodKey ? 'selected' : '' ?>
                                    >
                                        <?= htmlspecialchars($periodLabel) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div
                            class="completed-transaction-filter-group completed-transaction-filter-month-wrap"
                            data-completed-transaction-month-wrap
                        >
                            <label for="completedTransactionMonth">
                                Month
                            </label>

                            <input
                                type="month"
                                id="completedTransactionMonth"
                                class="completed-transaction-filter-month"
                                data-completed-transaction-month
                                name="completed_month"
                                value="<?= htmlspecialchars($completed_month) ?>"
                                max="<?= htmlspecialchars(date('Y-m')) ?>"
                            >
                        </div>

                        <div class="completed-transaction-filter-actions">
                            <button
                                type="submit"
                                class="btn btn-dark"
                            >
                                <i class="bi bi-search me-1"></i>
                                Search
                            </button>

                            <a
                                href="<?= htmlspecialchars(
                                    'sales-reports.php?' . http_build_query([
                                        'start_date' => $start_date,
                                        'end_date' => $end_date,
                                        'mode' => $report_mode,
                                        'transaction_page' => 1
                                    ])
                                ) ?>"
                                class="btn completed-transaction-filter-clear"
                                data-completed-transaction-clear
                            >
                                Clear
                            </a>
                        </div>
                    </form>

                    <?php if (empty($filteredSalesTransactions)): ?>

                        <div class="empty-report">

                            <i class="bi bi-receipt"></i>

                            <p>
                                No completed sales transactions match the current
                                search and date filters.
                            </p>

                        </div>

                    <?php else: ?>

                        <div class="table-responsive">

                            <table class="table transaction-table">

                                <thead>

                                    <tr>

                                        <th class="transaction-col-index">#</th>

                                        <th class="transaction-col-order">Order / Claim</th>

                                        <th class="transaction-col-payment">Payment</th>

                                        <th class="transaction-col-amount text-end">Amount</th>

                                        <th class="transaction-col-date">Date &amp; Time</th>

                                        <th class="transaction-col-details text-end">Details</th>

                                    </tr>

                                </thead>


                                <tbody>

                                    <?php foreach ($displayTransactions as $index => $sale): ?>

                                        <?php

                                        $saleDate = $sale['closed_at']
                                            ?? $sale['created_at'];

                                        $payment = ucfirst(
                                            strtolower(
                                                $sale['payment_method']
                                                ?? 'N/A'
                                            )
                                        );

                                        $paymentClass =
                                            strtolower($payment) === 'gcash'
                                                ? 'gcash'
                                                : 'cash';

                                        $saleOrderId = (int)$sale['id'];

                                        $transactionNumber =
                                            $transactionOffset + $index + 1;

                                        $saleItems =
                                            $transactionOrderItems[$saleOrderId]
                                            ?? [];

                                        $saleCreatedDate = !empty($sale['created_at'])
                                            ? date(
                                                'M d, Y • h:i A',
                                                strtotime($sale['created_at'])
                                            )
                                            : 'N/A';

                                        $saleClosedDate = !empty($sale['closed_at'])
                                            ? date(
                                                'M d, Y • h:i A',
                                                strtotime($sale['closed_at'])
                                            )
                                            : 'N/A';

                                        $salePickupDate = $sale['pickup_date'] ?? 'N/A';

                                        $salePickupTime = '';

                                        if (!empty($sale['pickup_time'])) {

                                            $pickupTimestamp =
                                                strtotime($sale['pickup_time']);

                                            if ($pickupTimestamp !== false) {

                                                $salePickupTime =
                                                    date(
                                                        'h:i A',
                                                        $pickupTimestamp
                                                    );
                                            }
                                        }

                                        if ($salePickupTime === '') {
                                            $salePickupTime = 'N/A';
                                        }

                                        ?>

                                        <tr>

                                            <td
                                                class="transaction-index-cell"
                                                data-label="#"
                                            >
                                                <span class="transaction-index-badge">
                                                    <?= $transactionNumber ?>
                                                </span>
                                            </td>

                                            <td
                                                class="transaction-order-cell"
                                                data-label="Order / Claim"
                                            >
                                                <div class="transaction-order-number">
                                                    <?= htmlspecialchars(
                                                        $sale['order_number'] ?? 'N/A'
                                                    ) ?>
                                                </div>
                                                <div class="transaction-claim-number">
                                                    <?= htmlspecialchars(
                                                        $sale['claim_number'] ?? 'N/A'
                                                    ) ?>
                                                </div>
                                            </td>

                                            <td
                                                class="transaction-payment-cell"
                                                data-label="Payment"
                                            >
                                                <span class="payment-badge <?= $paymentClass ?>">
                                                    <?= htmlspecialchars($payment) ?>
                                                </span>
                                            </td>

                                            <td
                                                class="text-end amount transaction-amount-cell"
                                                data-label="Amount"
                                            >
                                                ₱<?= number_format(
                                                    (float)$sale['total_amount'],
                                                    2
                                                ) ?>
                                            </td>

                                            <td
                                                class="transaction-date-cell"
                                                data-label="Date &amp; Time"
                                            >
                                                <span class="transaction-date">
                                                    <?= date(
                                                        'M d, Y',
                                                        strtotime($saleDate)
                                                    ) ?>
                                                </span>
                                                <span class="transaction-time">
                                                    <?= date(
                                                        'h:i A',
                                                        strtotime($saleDate)
                                                    ) ?>
                                                </span>
                                            </td>

                                            <td
                                                class="text-end transaction-details-cell"
                                                data-label="Details"
                                            >
                                                <button
                                                    type="button"
                                                    class="btn-view-transaction"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#transactionDetailsModal<?= $saleOrderId ?>"
                                                >
                                                    <i class="bi bi-eye"></i>
                                                    View Details
                                                </button>
                                            </td>

                                        </tr>


                                        <!-- TRANSACTION DETAILS MODAL -->
                                        <div
                                            class="modal fade no-print"
                                            id="transactionDetailsModal<?= $saleOrderId ?>"
                                            tabindex="-1"
                                            aria-labelledby="transactionDetailsLabel<?= $saleOrderId ?>"
                                            aria-hidden="true"
                                        >

                                            <div class="modal-dialog modal-dialog-centered modal-lg">

                                                <div class="modal-content">

                                                    <div class="modal-header">

                                                        <h5
                                                            class="modal-title"
                                                            id="transactionDetailsLabel<?= $saleOrderId ?>"
                                                        >
                                                            <i class="bi bi-receipt me-2"></i>
                                                            Transaction Details
                                                        </h5>

                                                        <button
                                                            type="button"
                                                            class="btn-close"
                                                            data-bs-dismiss="modal"
                                                            aria-label="Close"
                                                        ></button>

                                                    </div>


                                                    <div class="modal-body">

                                                        <div class="transaction-detail-grid">

                                                            <div class="transaction-detail-box">
                                                                <small>Order Number</small>
                                                                <strong>
                                                                    <?= htmlspecialchars(
                                                                        $sale['order_number'] ?? 'N/A'
                                                                    ) ?>
                                                                </strong>
                                                            </div>

                                                            <div class="transaction-detail-box">
                                                                <small>Claim Number</small>
                                                                <strong>
                                                                    <?= htmlspecialchars(
                                                                        $sale['claim_number'] ?? 'N/A'
                                                                    ) ?>
                                                                </strong>
                                                            </div>

                                                            <div class="transaction-detail-box">
                                                                <small>Customer</small>
                                                                <strong>
                                                                    <?= htmlspecialchars(
                                                                        $sale['customer_name'] ?? 'N/A'
                                                                    ) ?>
                                                                </strong>
                                                            </div>

                                                            <div class="transaction-detail-box">
                                                                <small>Contact Number</small>
                                                                <strong>
                                                                    <?= htmlspecialchars(
                                                                        $sale['contact_number'] ?? 'N/A'
                                                                    ) ?>
                                                                </strong>
                                                            </div>

                                                            <div class="transaction-detail-box">
                                                                <small>Pick-up Date</small>
                                                                <strong>
                                                                    <?= htmlspecialchars($salePickupDate) ?>
                                                                </strong>
                                                            </div>

                                                            <div class="transaction-detail-box">
                                                                <small>Pick-up Time</small>
                                                                <strong>
                                                                    <?= htmlspecialchars($salePickupTime) ?>
                                                                </strong>
                                                            </div>

                                                            <div class="transaction-detail-box">
                                                                <small>Payment Method</small>
                                                                <strong>
                                                                    <?= htmlspecialchars($payment) ?>
                                                                </strong>
                                                            </div>

                                                            <div class="transaction-detail-box">
                                                                <small>Status</small>
                                                                <strong>Completed</strong>
                                                            </div>

                                                            <div class="transaction-detail-box">
                                                                <small>Order Date</small>
                                                                <strong>
                                                                    <?= htmlspecialchars($saleCreatedDate) ?>
                                                                </strong>
                                                            </div>

                                                            <div class="transaction-detail-box">
                                                                <small>Completed At</small>
                                                                <strong>
                                                                    <?= htmlspecialchars($saleClosedDate) ?>
                                                                </strong>
                                                            </div>

                                                        </div>


                                                        <div class="fw-bold mb-2" style="color:#4A3525; font-size:0.85rem;">
                                                            Order Items
                                                        </div>

                                                        <?php if (empty($saleItems)): ?>

                                                            <div class="text-muted small">
                                                                No order items found.
                                                            </div>

                                                        <?php else: ?>

                                                            <?php foreach ($saleItems as $item): ?>

                                                                <?php

                                                                $itemInfoParts = [];

                                                                if (!empty($item['size'])) {
                                                                    $itemInfoParts[] =
                                                                        'Size: ' .
                                                                        $item['size'];
                                                                }

                                                                if (!empty($item['sugar_level'])) {
                                                                    $itemInfoParts[] =
                                                                        'Sugar: ' .
                                                                        $item['sugar_level'];
                                                                }

                                                                $addonText = formatOrderAddons(
                                                                    $item['addons'] ?? null
                                                                );

                                                                if ($addonText !== '') {

                                                                    $itemInfoParts[] =
                                                                        'Add-ons: ' .
                                                                        $addonText;
                                                                }

                                                                ?>

                                                                <div class="transaction-detail-item">

                                                                    <div class="d-flex justify-content-between align-items-start gap-3">

                                                                        <div>

                                                                            <div class="transaction-detail-item-name">

                                                                                <?= htmlspecialchars(
                                                                                    $item['quantity']
                                                                                ) ?>
                                                                                ×
                                                                                <?= htmlspecialchars(
                                                                                    $item['product_name']
                                                                                ) ?>

                                                                            </div>

                                                                            <div class="transaction-detail-item-info">

                                                                                ₱<?= number_format(
                                                                                    (float)$item['unit_price'],
                                                                                    2
                                                                                ) ?>
                                                                                each

                                                                                <?php if (!empty($itemInfoParts)): ?>
                                                                                    <br>
                                                                                    <?= htmlspecialchars(
                                                                                        implode(
                                                                                            ' • ',
                                                                                            $itemInfoParts
                                                                                        )
                                                                                    ) ?>
                                                                                <?php endif; ?>

                                                                            </div>

                                                                        </div>


                                                                        <div class="transaction-detail-item-price">

                                                                            ₱<?= number_format(
                                                                                (float)$item['subtotal'],
                                                                                2
                                                                            ) ?>

                                                                        </div>

                                                                    </div>

                                                                </div>

                                                            <?php endforeach; ?>

                                                        <?php endif; ?>


                                                        <div class="transaction-detail-total">

                                                            <span>
                                                                Total Amount
                                                            </span>

                                                            <strong>
                                                                ₱<?= number_format(
                                                                    (float)$sale['total_amount'],
                                                                    2
                                                                ) ?>
                                                            </strong>

                                                        </div>


                                                        <?php if (
                                                            strtolower(
                                                                $sale['payment_method']
                                                                ?? ''
                                                            ) === 'gcash'
                                                            &&
                                                            !empty(
                                                                $sale['payment_screenshot']
                                                            )
                                                        ): ?>

                                                            <div class="transaction-payment-proof">

                                                                <h6>
                                                                    <i class="bi bi-image me-1"></i>
                                                                    GCash Payment Proof
                                                                </h6>

                                                                <button
                                                                    type="button"
                                                                    class="btn-view-payment-proof"
                                                                    data-bs-toggle="collapse"
                                                                    data-bs-target="#gcashProofDropdown<?= $saleOrderId ?>"
                                                                    aria-expanded="false"
                                                                    aria-controls="gcashProofDropdown<?= $saleOrderId ?>"
                                                                >
                                                                    <i class="bi bi-eye me-1"></i>
                                                                    View GCash Payment Proof
                                                                    <i class="bi bi-chevron-down gcash-proof-chevron"></i>
                                                                </button>

                                                                <div
                                                                    class="collapse"
                                                                    id="gcashProofDropdown<?= $saleOrderId ?>"
                                                                >
                                                                    <div class="gcash-proof-dropdown">
                                                                        <img
                                                                            src="../<?= htmlspecialchars(
                                                                                $sale['payment_screenshot']
                                                                            ) ?>"
                                                                            alt="GCash Payment Proof"
                                                                        >
                                                                    </div>
                                                                </div>

                                                            </div>
                                                        <?php endif; ?>

                                                    </div>


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

                                </tbody>

                            </table>

                        </div>


                        <!-- TRANSACTION PAGINATION -->
                        <?php if ($totalTransactionPages > 1): ?>

                            <?php

                            $paginationParams = [
                                'start_date' => $start_date,
                                'end_date'   => $end_date,
                                'mode'       => $report_mode,
                                'completed_q' => $completed_search,
                                'completed_period' => $completed_period,
                                'completed_month' => $completed_period === 'specific_month'
                                    ? $completed_month
                                    : '',
                                'transaction_page' => $transactionPage
                            ];

                            ?>

                            <div class="transaction-pagination">

                                <a
                                    href="?<?= http_build_query(
                                        array_merge(
                                            $paginationParams,
                                            [
                                                'transaction_page' =>
                                                    $transactionPage - 1
                                            ]
                                        )
                                    ) ?>"
                                    class="transaction-page-link <?= $transactionPage <= 1 ? 'disabled' : '' ?>"
                                    data-preserve-scroll="1"
                                >
                                    <i class="bi bi-chevron-left"></i>
                                </a>


                                <?php

                                /*
                                 * Keep page 1 visible at all times. For longer
                                 * result sets, show nearby pages plus ellipses
                                 * and the final page.
                                 */
                                if ($totalTransactionPages <= 7) {

                                    $visibleTransactionPages = range(
                                        1,
                                        $totalTransactionPages
                                    );

                                } else {

                                    $visibleTransactionPages = [1];

                                    if ($transactionPage <= 4) {
                                        $visibleTransactionPages = [
                                            1,
                                            2,
                                            3,
                                            4,
                                            5,
                                            $totalTransactionPages
                                        ];

                                    } elseif (
                                        $transactionPage >= $totalTransactionPages - 3
                                    ) {
                                        $visibleTransactionPages = [
                                            1,
                                            $totalTransactionPages - 4,
                                            $totalTransactionPages - 3,
                                            $totalTransactionPages - 2,
                                            $totalTransactionPages - 1,
                                            $totalTransactionPages
                                        ];

                                    } else {
                                        $visibleTransactionPages = [
                                            1,
                                            $transactionPage - 1,
                                            $transactionPage,
                                            $transactionPage + 1
                                        ];
                                    }

                                    $visibleTransactionPages = array_values(
                                        array_unique($visibleTransactionPages)
                                    );
                                }

                                $previousPage = null;

                                foreach ($visibleTransactionPages as $paginationPage):

                                    if (
                                        $previousPage !== null &&
                                        $paginationPage > $previousPage + 1
                                    ):
                                    ?>

                                        <span
                                            class="transaction-page-link disabled"
                                            aria-hidden="true"
                                        >
                                            …
                                        </span>

                                    <?php endif; ?>

                                    <a
                                        href="?<?= http_build_query(
                                            array_merge(
                                                $paginationParams,
                                                [
                                                    'transaction_page' =>
                                                        $paginationPage
                                                ]
                                            )
                                        ) ?>"
                                        class="transaction-page-link <?= $paginationPage === $transactionPage ? 'active' : '' ?>"
                                        data-preserve-scroll="1"
                                    >
                                        <?= $paginationPage ?>
                                    </a>

                                    <?php
                                    $previousPage = $paginationPage;

                                endforeach;
                                ?>


                                <a
                                    href="?<?= http_build_query(
                                        array_merge(
                                            $paginationParams,
                                            [
                                                'transaction_page' =>
                                                    $transactionPage + 1
                                            ]
                                        )
                                    ) ?>"
                                    class="transaction-page-link <?= $transactionPage >= $totalTransactionPages ? 'disabled' : '' ?>"
                                    data-preserve-scroll="1"
                                >
                                    <i class="bi bi-chevron-right"></i>
                                </a>

                            </div>

                        <?php endif; ?>

                        <div class="transaction-page-info">
                            Showing
                            <?= count($displayTransactions) ?>
                            of
                            <?= number_format($completedTransactionCount) ?>
                            completed transaction<?= $completedTransactionCount !== 1 ? 's' : '' ?>
                            <?php if ($totalTransactionPages > 1): ?>
                                • Page
                                <?= $transactionPage ?>
                                of
                                <?= $totalTransactionPages ?>
                            <?php endif; ?>
                        </div>


                        <!-- REPORT TOTAL -->
                        <div class="report-footer-summary">

                            <div>
                                Completed Orders:
                                <strong>
                                    <?= number_format($completedTransactionCount) ?>
                                </strong>
                            </div>

                            <div>
                                Total Sales:
                                <strong>
                                    ₱<?= number_format($completedTransactionSalesTotal, 2) ?>
                                </strong>
                            </div>

                        </div>

                    <?php endif; ?>

                </div>


            </section>

        </main>

</div>


<?php if ($reportToast): ?>
    <div class="sales-report-toast-wrap" aria-live="polite" aria-atomic="true">
        <div
            class="sales-report-toast"
            id="salesReportActionToast"
            role="status"
            data-toast-key="<?= htmlspecialchars($report_mode . '|' . $start_date . '|' . $end_date) ?>"
        >
            <span class="sales-report-toast-icon">
                <i class="bi <?= htmlspecialchars($reportToast['icon']) ?>"></i>
            </span>

            <span class="sales-report-toast-message">
                <?= htmlspecialchars($reportToast['message']) ?>
            </span>

            <button
                type="button"
                class="sales-report-toast-close"
                aria-label="Close notification"
            >
                <i class="bi bi-x-lg"></i>
            </button>

            <span class="sales-report-toast-progress" aria-hidden="true"></span>
        </div>
    </div>
<?php endif; ?>

<script>

/* =========================================================
   SALES REPORT ACTION TOAST

   Uses the same fixed toast behavior as Orders. Repeatedly
   clicking the SAME report mode/date range while the toast is
   still alive does not restart the 3.5-second countdown.
========================================================= */
(function () {
    var STORAGE_KEY = 'adminSalesReportActionToast';
    var DURATION = 3500;

    function readState() {
        try {
            return JSON.parse(sessionStorage.getItem(STORAGE_KEY) || 'null');
        } catch (e) { return null; }
    }

    function saveState(state) {
        try { sessionStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch (e) {}
    }

    function clearState() {
        try { sessionStorage.removeItem(STORAGE_KEY); } catch (e) {}
    }

    var now = Date.now();
    var toast = document.getElementById('salesReportActionToast');
    var state = readState();

    if (toast) {
        var currentKey = toast.getAttribute('data-toast-key') || '';
        var existingAge = state && state.createdAt
            ? now - Number(state.createdAt)
            : Infinity;

        /* Keep the original timestamp for repeated identical actions. */
        if (
            !state ||
            state.key !== currentKey ||
            !(existingAge >= 0) ||
            existingAge >= DURATION
        ) {
            state = {
                html: toast.outerHTML,
                key: currentKey,
                createdAt: now
            };
            saveState(state);
        } else {
            state.html = toast.outerHTML;
            state.key = currentKey;
            saveState(state);
        }

        /* Prevent refresh from generating the same toast again. */
        try {
            var url = new URL(window.location.href);
            url.searchParams.delete('report_action');
            window.history.replaceState(
                {},
                document.title,
                url.pathname +
                (url.searchParams.toString() ? '?' + url.searchParams.toString() : '') +
                url.hash
            );
        } catch (e) {}

    } else {
        /* Carry a live toast across report mode page reloads. */
        if (!state || !state.html || !state.createdAt || !state.key) {
            clearState();
            return;
        }

        var carriedAge = now - Number(state.createdAt);
        if (!(carriedAge >= 0) || carriedAge >= DURATION) {
            clearState();
            return;
        }

        var wrap = document.createElement('div');
        wrap.className = 'sales-report-toast-wrap';
        wrap.setAttribute('aria-live', 'polite');
        wrap.setAttribute('aria-atomic', 'true');
        wrap.innerHTML = state.html;
        document.body.appendChild(wrap);

        toast = wrap.querySelector('#salesReportActionToast');
        if (!toast) {
            clearState();
            return;
        }

        toast.classList.add('is-restored');
    }

    var age = now - Number(state.createdAt);
    var visibleFor = DURATION - age;

    if (visibleFor <= 0) {
        clearState();
        toast.remove();
        return;
    }

    var progress = toast.querySelector('.sales-report-toast-progress');
    if (progress) {
        progress.style.animationDuration = DURATION + 'ms';
        progress.style.animationDelay = (-age) + 'ms';
    }

    var closeTimer = null;

    function closeToast() {
        if (!toast || toast.classList.contains('is-closing')) {
            return;
        }

        if (closeTimer !== null) {
            clearTimeout(closeTimer);
            closeTimer = null;
        }

        clearState();
        toast.classList.add('is-closing');
        setTimeout(function () {
            if (toast) toast.remove();
        }, 190);
    }

    var closeButton = toast.querySelector('.sales-report-toast-close');
    if (closeButton) {
        closeButton.addEventListener('click', closeToast);
    }

    closeTimer = setTimeout(closeToast, visibleFor);

    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            clearState();
            if (toast) toast.remove();
        }
    });
})();


/* =========================================================
   SALES REPORT NAVIGATION / PRINT / AJAX SECTIONS

   - Completed transaction search/date/pagination replace ONLY
     the Completed Sales Transactions section.
   - Sales Overview Daily / Weekly / Monthly replaces ONLY
     the Sales Overview card.
   - Browser URL is kept in sync without a full-page reload.
========================================================= */
(function () {

    const scrollStorageKey = 'salesReportsScrollY';
    const filterForm = document.getElementById('salesReportFilterForm');
    const printButton = document.getElementById('printSalesReportBtn');

    if ('scrollRestoration' in history) {
        history.scrollRestoration = 'manual';
    }

    function navigateUrlWithoutReload(url, replace = false) {
        const cleanUrl = new URL(url, window.location.href);
        cleanUrl.searchParams.delete('report_action');

        const state = {
            localiteaSalesReportsAjax: true
        };

        const href =
            cleanUrl.pathname +
            (cleanUrl.search ? cleanUrl.search : '') +
            cleanUrl.hash;

        if (replace) {
            window.history.replaceState(state, '', href);
        } else {
            window.history.pushState(state, '', href);
        }

        return cleanUrl;
    }

    function setSectionBusy(section, busy) {
        if (!section) {
            return;
        }

        section.classList.toggle(
            'completed-transactions-ajax-busy',
            busy
        );

        section.setAttribute(
            'aria-busy',
            busy ? 'true' : 'false'
        );
    }

    async function fetchPageFragment(url, selector) {
        const requestUrl = new URL(url, window.location.href);
        requestUrl.searchParams.delete('report_action');
        requestUrl.searchParams.set('_ajax_view', '1');
        requestUrl.searchParams.set('_', String(Date.now()));

        const response = await fetch(
            requestUrl.toString(),
            {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'text/html'
                },
                cache: 'no-store'
            }
        );

        if (!response.ok) {
            throw new Error(
                'Server returned HTTP ' + response.status
            );
        }

        const html = await response.text();
        const parsedDocument = new DOMParser().parseFromString(
            html,
            'text/html'
        );

        const fragment = parsedDocument.querySelector(selector);

        if (!fragment) {
            throw new Error(
                'The requested sales report section was not found.'
            );
        }

        return fragment;
    }

    /* =====================================================
       COMPLETED TRANSACTION FILTER UI
    ===================================================== */
    function updateCompletedMonthVisibility(root = document) {
        const period = root.querySelector(
            '[data-completed-transaction-period]'
        );
        const monthWrap = root.querySelector(
            '[data-completed-transaction-month-wrap]'
        );

        if (!period || !monthWrap) {
            return;
        }

        monthWrap.style.display =
            period.value === 'specific_month'
                ? 'flex'
                : 'none';
    }

    function buildCompletedTransactionUrl(overrides = {}) {
        const url = new URL(window.location.href);

        const form = document.querySelector(
            '[data-completed-transaction-filter-form]'
        );

        const searchInput = form?.querySelector(
            '[data-completed-transaction-search]'
        );

        const periodSelect = form?.querySelector(
            '[data-completed-transaction-period]'
        );

        const monthInput = form?.querySelector(
            '[data-completed-transaction-month]'
        );

        const search =
            overrides.completed_q ??
            searchInput?.value ??
            url.searchParams.get('completed_q') ??
            '';

        const period =
            overrides.completed_period ??
            periodSelect?.value ??
            url.searchParams.get('completed_period') ??
            'today';

        const month =
            overrides.completed_month ??
            monthInput?.value ??
            url.searchParams.get('completed_month') ??
            '';

        const page =
            overrides.transaction_page ??
            url.searchParams.get('transaction_page') ??
            '1';

        if (search.trim() !== '') {
            url.searchParams.set(
                'completed_q',
                search.trim()
            );
        } else {
            url.searchParams.delete('completed_q');
        }

        url.searchParams.set(
            'completed_period',
            period || 'today'
        );

        if (
            (period || 'today') === 'specific_month' &&
            month
        ) {
            url.searchParams.set(
                'completed_month',
                month
            );
        } else {
            url.searchParams.delete('completed_month');
        }

        url.searchParams.set(
            'transaction_page',
            String(page || '1')
        );

        url.searchParams.delete('report_action');

        return url;
    }

    let completedSearchTimer = null;
    let completedRequestId = 0;

    async function loadCompletedTransactions(
        targetUrl,
        pushHistory = true
    ) {
        const currentSection = document.querySelector(
            '.transaction-report-card'
        );

        if (!currentSection) {
            return;
        }

        const requestNumber = ++completedRequestId;

        setSectionBusy(currentSection, true);

        try {
            const newSection = await fetchPageFragment(
                targetUrl,
                '.transaction-report-card'
            );

            if (requestNumber !== completedRequestId) {
                return;
            }

            currentSection.replaceWith(newSection);

            updateCompletedMonthVisibility(
                newSection
            );

            if (pushHistory) {
                navigateUrlWithoutReload(targetUrl);
            } else {
                navigateUrlWithoutReload(
                    targetUrl,
                    true
                );
            }

        } catch (error) {
            console.error(
                'Completed Sales Transactions AJAX error:',
                error
            );

            window.alert(
                error.message ||
                'Unable to load completed sales transactions.'
            );

        } finally {
            const restoredSection = document.querySelector(
                '.transaction-report-card'
            );

            setSectionBusy(
                restoredSection,
                false
            );
        }
    }

    document.addEventListener(
        'change',
        function (event) {
            const periodSelect = event.target.closest(
                '[data-completed-transaction-period]'
            );

            if (periodSelect) {
                const period =
                    periodSelect.value || 'today';

                updateCompletedMonthVisibility(
                    document
                );

                const monthInput = document.querySelector(
                    '[data-completed-transaction-month]'
                );

                if (
                    period === 'specific_month' &&
                    (!monthInput || !monthInput.value)
                ) {
                    if (monthInput) {
                        monthInput.focus();
                    }
                    return;
                }

                loadCompletedTransactions(
                    buildCompletedTransactionUrl({
                        completed_period: period,
                        completed_month: monthInput?.value || '',
                        transaction_page: 1
                    }),
                    true
                );

                return;
            }

            const monthInput = event.target.closest(
                '[data-completed-transaction-month]'
            );

            if (monthInput) {
                const periodSelect = document.querySelector(
                    '[data-completed-transaction-period]'
                );

                if (
                    periodSelect?.value === 'specific_month' &&
                    monthInput.value
                ) {
                    loadCompletedTransactions(
                        buildCompletedTransactionUrl({
                            completed_period: 'specific_month',
                            completed_month: monthInput.value,
                            transaction_page: 1
                        }),
                        true
                    );
                }
            }
        }
    );

    document.addEventListener(
        'input',
        function (event) {
            const searchInput = event.target.closest(
                '[data-completed-transaction-search]'
            );

            if (!searchInput) {
                return;
            }

            clearTimeout(completedSearchTimer);

            completedSearchTimer = setTimeout(
                function () {
                    const periodSelect = document.querySelector(
                        '[data-completed-transaction-period]'
                    );

                    const monthInput = document.querySelector(
                        '[data-completed-transaction-month]'
                    );

                    const targetUrl =
                        buildCompletedTransactionUrl({
                            completed_period:
                                periodSelect?.value || 'today',
                            completed_month:
                                monthInput?.value || '',
                            completed_q:
                                searchInput.value,
                            transaction_page: 1
                        });

                    /* Live search replaces the URL state without
                       creating one history entry per keystroke. */
                    navigateUrlWithoutReload(
                        targetUrl,
                        true
                    );

                    loadCompletedTransactions(
                        targetUrl,
                        false
                    );
                },
                300
            );
        }
    );

    document.addEventListener(
        'submit',
        function (event) {
            const form = event.target.closest(
                '[data-completed-transaction-filter-form]'
            );

            if (!form) {
                return;
            }

            event.preventDefault();

            const period = form.querySelector(
                '[data-completed-transaction-period]'
            );
            const month = form.querySelector(
                '[data-completed-transaction-month]'
            );
            const search = form.querySelector(
                '[data-completed-transaction-search]'
            );

            if (
                period?.value === 'specific_month' &&
                !month?.value
            ) {
                month?.focus();
                return;
            }

            loadCompletedTransactions(
                buildCompletedTransactionUrl({
                    completed_period:
                        period?.value || 'today',
                    completed_month:
                        month?.value || '',
                    completed_q:
                        search?.value || '',
                    transaction_page: 1
                }),
                true
            );
        }
    );

    document.addEventListener(
        'click',
        function (event) {
            const clearLink = event.target.closest(
                '[data-completed-transaction-clear]'
            );

            if (clearLink) {
                if (
                    event.ctrlKey ||
                    event.metaKey ||
                    event.shiftKey ||
                    event.altKey ||
                    clearLink.target === '_blank'
                ) {
                    return;
                }

                event.preventDefault();

                const url = new URL(
                    clearLink.href,
                    window.location.href
                );

                url.searchParams.delete('completed_q');
                url.searchParams.set(
                    'completed_period',
                    'today'
                );
                url.searchParams.delete('completed_month');
                url.searchParams.set(
                    'transaction_page',
                    '1'
                );

                loadCompletedTransactions(
                    url,
                    true
                );
                return;
            }

            const paginationLink = event.target.closest(
                '.transaction-page-link[href]'
            );

            if (!paginationLink) {
                return;
            }

            if (
                event.ctrlKey ||
                event.metaKey ||
                event.shiftKey ||
                event.altKey ||
                paginationLink.target === '_blank' ||
                paginationLink.classList.contains('disabled')
            ) {
                return;
            }

            event.preventDefault();

            loadCompletedTransactions(
                paginationLink.href,
                true
            );
        }
    );

    /* =====================================================
       SALES OVERVIEW MODE AJAX
    ===================================================== */
    let overviewRequestId = 0;

    async function loadSalesOverview(
        targetUrl,
        pushHistory = true
    ) {
        const currentOverview = document.querySelector(
            '.overview-card'
        );

        if (!currentOverview) {
            return;
        }

        const requestNumber = ++overviewRequestId;

        currentOverview.setAttribute(
            'aria-busy',
            'true'
        );
        currentOverview.style.opacity = '.58';
        currentOverview.style.pointerEvents = 'none';

        try {
            const newOverview = await fetchPageFragment(
                targetUrl,
                '.overview-card'
            );

            if (requestNumber !== overviewRequestId) {
                return;
            }

            currentOverview.replaceWith(newOverview);

            if (pushHistory) {
                navigateUrlWithoutReload(
                    targetUrl
                );
            } else {
                navigateUrlWithoutReload(
                    targetUrl,
                    true
                );
            }

        } catch (error) {
            console.error(
                'Sales Overview AJAX error:',
                error
            );

            window.alert(
                error.message ||
                'Unable to load the Sales Overview.'
            );

        } finally {
            const restoredOverview = document.querySelector(
                '.overview-card'
            );

            if (restoredOverview) {
                restoredOverview.removeAttribute(
                    'aria-busy'
                );
                restoredOverview.style.opacity = '';
                restoredOverview.style.pointerEvents = '';
            }
        }
    }

    document.addEventListener(
        'click',
        function (event) {
            const modeLink = event.target.closest(
                '.mode-tab[data-preserve-scroll="1"]'
            );

            if (!modeLink) {
                return;
            }

            if (
                event.ctrlKey ||
                event.metaKey ||
                event.shiftKey ||
                event.altKey ||
                modeLink.target === '_blank'
            ) {
                return;
            }

            event.preventDefault();

            sessionStorage.setItem(
                scrollStorageKey,
                String(window.scrollY)
            );

            const modeUrl = new URL(
                modeLink.href,
                window.location.href
            );

            modeUrl.searchParams.delete('report_action');

            loadSalesOverview(
                modeUrl,
                true
            );
        }
    );

    /* Browser Back / Forward: update whichever AJAX section
       is represented by the URL without reloading the page. */
    window.addEventListener(
        'popstate',
        function () {
            loadSalesOverview(
                window.location.href,
                false
            );

            loadCompletedTransactions(
                window.location.href,
                false
            );
        }
    );

    /* -----------------------------------------------------
       Initial Specific Month visibility.
    ----------------------------------------------------- */
    updateCompletedMonthVisibility(
        document
    );

    /* -----------------------------------------------------
       PRINT LINK
    ----------------------------------------------------- */
    if (printButton && filterForm) {

        const startInput =
            filterForm.querySelector('[name="start_date"]');

        const endInput =
            filterForm.querySelector('[name="end_date"]');

        function updatePrintLink() {

            if (!startInput || !endInput) {
                return;
            }

            const startDate = startInput.value;
            const endDate = endInput.value;

            if (!startDate || !endDate) {
                return;
            }

            const printUrl = new URL(
                'print-sales-report.php',
                window.location.href
            );

            printUrl.searchParams.set(
                'start_date',
                startDate
            );

            printUrl.searchParams.set(
                'end_date',
                endDate
            );

            /* Do NOT pass the Sales Overview mode. */
            printButton.href = printUrl.toString();
        }

        startInput.addEventListener(
            'change',
            updatePrintLink
        );

        endInput.addEventListener(
            'change',
            updatePrintLink
        );

        updatePrintLink();
    }

    /* -----------------------------------------------------
       Scroll restoration for normal full-page navigations.
       AJAX actions do not move the page to the top.
    ----------------------------------------------------- */
    function restoreSalesReportScroll() {
        const savedScroll =
            sessionStorage.getItem(scrollStorageKey);

        if (savedScroll === null) {
            return;
        }

        const scrollY = Math.max(
            0,
            parseInt(savedScroll, 10) || 0
        );

        sessionStorage.removeItem(scrollStorageKey);

        window.scrollTo(0, scrollY);

        requestAnimationFrame(function () {
            window.scrollTo(0, scrollY);
        });

        setTimeout(function () {
            window.scrollTo(0, scrollY);
        }, 80);

        setTimeout(function () {
            window.scrollTo(0, scrollY);
        }, 250);
    }

    window.addEventListener(
        'load',
        restoreSalesReportScroll
    );

})();

</script>


<?php require_once '../includes/footer.php'; ?> 
