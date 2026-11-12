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


/**
 * Same data as formatOrderAddons(), but returned as separate pieces so the
 * details modal can show each add-on / bundle cup on its own.
 */
function orderAddonParts(?string $addons): array
{
    $text = formatOrderAddons($addons);

    if ($text === '') {
        return [];
    }

    return array_values(
        array_filter(
            array_map('trim', explode(', ', $text)),
            static fn($part) => $part !== ''
        )
    );
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
        subtotal,
        discount_type,
        discount_rate,
        discount_amount,
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

/*
 * SALES OVERVIEW CHART SCALE ONLY
 * Creates clean, rounded Y-axis values like ₱800, ₱1.6K, ₱2.4K, etc.
 */
$chartStep = max(100, (int)(ceil(($maxSales / 4) / 100) * 100));
$chartMax = $chartStep * 4;

/* =========================================================
   SALES OVERVIEW DETAILS
========================================================= */
$overviewTotalOrders = 0;
$activePeriods = 0;
$bestPeriod = null;

foreach ($salesOverview as $p) {
    $overviewTotalOrders += (int)$p['orders'];

    if ($p['sales'] > 0) {
        $activePeriods++;
    }

    if ($bestPeriod === null || $p['sales'] > $bestPeriod['sales']) {
        $bestPeriod = $p;
    }
}

$averagePerPeriod = count($salesOverview) > 0
    ? $totalSales / count($salesOverview)
    : 0;

$periodUnitLabel = $report_mode === 'daily'
    ? 'Day'
    : ($report_mode === 'weekly' ? 'Week' : 'Month');

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
    font-weight: 600;
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
    font-weight: 500;
    margin-bottom: 15px;
}

.filter-label {
    color: #77706A;
    font-size: 0.84rem;
    font-weight: 500;
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
    font-weight: 500;
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
    font-weight: 500;
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
    font-weight: 500;
}

.summary-value {
    color: #4A3525;
    font-size: 1.55rem;
    font-weight: 600;
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
    font-weight: 600;
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
    font-weight: 500;
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
   GRAPH ONLY — clean/plain presentation
========================================================= */

.overview-card {
    border: 0;
    border-radius: 0;
    background: #ffffff;
    padding: 4px 0 0;
}

.overview-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    margin-bottom: 16px;
}

.overview-title {
    color: #4A3525;
    font-size: 0.95rem;
    font-weight: 600;
}

.overview-total {
    color: #4A3525;
    font-size: 1rem;
    font-weight: 600;
}

/* Tabs remain part of Sales Overview but stay simple. */
.mode-tabs {
    margin-bottom: 12px !important;
}

/* Main chart area */
.sales-chart-area {
    display: flex;
    width: 100%;
    min-width: 0;
    height: 315px;
    gap: 10px;
}

.sales-chart-yaxis {
    flex: 0 0 52px;
    height: 250px;
    margin-top: 24px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    align-items: flex-end;
    padding: 0 2px 0 0;
    box-sizing: border-box;
}

.sales-chart-yaxis span {
    color: #77706A;
    font-size: 0.68rem;
    font-weight: 500;
    line-height: 1;
    white-space: nowrap;
}

.sales-chart-viewport {
    position: relative;
    flex: 1 1 auto;
    min-width: 0;
    overflow: hidden;
    scrollbar-width: none;
    -webkit-overflow-scrolling: auto;
}

.sales-chart-plot {
    position: relative;
    min-width: 100%;
    height: 315px;
    box-sizing: border-box;
}

.sales-chart-grid {
    position: absolute;
    left: 0;
    right: 0;
    top: 24px;
    height: 250px;
    pointer-events: none;
    z-index: 0;
    background:
        repeating-linear-gradient(
            to bottom,
            transparent 0,
            transparent calc(25% - 1px),
            #E7E0D9 calc(25% - 1px),
            #E7E0D9 25%
        );
    border-bottom: 1px solid #E7E0D9;
}

.chart {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: flex-end;
    gap: 12px;
    min-height: 0;
    height: 315px;
    padding: 0 8px 4px;
    width: 100%;
    box-sizing: border-box;
    overflow: hidden;
}

.chart-column {
    min-width: 58px;
    flex: 1 1 0;
    height: 315px;
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    align-items: center;
    gap: 6px;
    position: relative;
}

.chart-value {
    color: #4A3525;
    font-size: 0.68rem;
    font-weight: 600;
    white-space: nowrap;
    line-height: 1;
    min-height: 12px;
}

.chart-bar-wrap {
    width: 100%;
    height: 250px;
    display: flex;
    align-items: flex-end;
    justify-content: center;
}

.chart-bar {
    position: relative;
    width: min(48px, 72%);
    max-width: 48px;
    min-height: 3px;
    background: #6F4E37;
    border-radius: 9px 9px 3px 3px;
    transition: height .2s ease, transform .15s ease;
    cursor: default;
}

.chart-bar:hover {
    transform: translateY(-2px);
}

.chart-bar.is-best {
    background: #4A3525;
}

.chart-bar::after {
    content: attr(data-tooltip);
    position: absolute;
    left: 50%;
    bottom: calc(100% + 9px);
    transform: translateX(-50%) translateY(4px);
    min-width: max-content;
    padding: 8px 11px;
    border-radius: 8px;
    background: #2C221E;
    color: #ffffff;
    font-size: 0.72rem;
    font-weight: 500;
    line-height: 1.25;
    white-space: nowrap;
    opacity: 0;
    pointer-events: none;
    transition: opacity .15s ease, transform .15s ease;
    z-index: 50;
    box-shadow: 0 5px 16px rgba(44,34,30,.16);
}

.chart-bar:hover::after {
    opacity: 1;
    transform: translateX(-50%) translateY(0);
}

.chart-label {
    color: #6F665F;
    font-size: 0.68rem;
    font-weight: 500;
    text-align: center;
    white-space: nowrap;
    line-height: 1.15;
    min-height: 13px;
}

.chart-orders {
    display: none;
}

/* Weekly view */
.chart.chart-weekly {
    overflow: visible;
    justify-content: flex-start;
}

.chart.chart-weekly .chart-column {
    flex: 0 0 82px;
    min-width: 82px;
}

.chart.chart-weekly .chart-label {
    white-space: normal;
    min-height: 28px;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    max-width: 82px;
    line-height: 1.15;
}

/* Monthly view */
.chart.chart-monthly {
    overflow: visible;
    justify-content: flex-start;
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

/* Keep the existing overview highlights, but make them quieter. */
.overview-highlights {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
    margin-top: 14px;
}

.overview-highlight {
    background: #FCF9F6;
    border: 1px solid #E5DAD0;
    border-radius: 8px;
    padding: 10px 12px;
    min-width: 0;
}

.overview-highlight small {
    display: block;
    color: #8A7F75;
    font-size: 0.64rem;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: .25px;
    margin-bottom: 3px;
}

.overview-highlight strong {
    color: #2C221E;
    font-size: 0.84rem;
    font-weight: 500;
    overflow-wrap: anywhere;
}

.overview-detail-table {
    margin-bottom: 0;
    width: 100%;
}

.overview-detail-table th {
    color: #8a7f75;
    font-size: 0.7rem;
    font-weight: 500;
    text-transform: uppercase;
    white-space: nowrap;
    border-bottom: 2px solid #6F4E37;
    padding: 9px 10px;
}

.overview-detail-table td {
    color: #4A3525;
    font-size: 0.8rem;
    font-weight: 400;
    border-bottom: 1px solid #E5DAD0;
    padding: 9px 10px;
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
}

.overview-detail-table tr.is-empty td {
    color: #B0A79E;
}

.overview-detail-table tfoot td {
    font-weight: 600;
    border-top: 2px solid #6F4E37;
    border-bottom: 0;
    background: #F7F1E8;
}

@media (max-width: 767.98px) {

    .overview-card {
        padding-left: 0;
        padding-right: 0;
    }

    .sales-chart-area {
        height: 285px;
        gap: 7px;
    }

    .sales-chart-yaxis {
        flex-basis: 45px;
        height: 225px;
        margin-top: 22px;
    }

    .sales-chart-yaxis span {
        font-size: 0.62rem;
    }

    .sales-chart-plot {
        height: 285px;
    }

    .sales-chart-grid {
        top: 22px;
        height: 225px;
    }

    .chart {
        height: 285px;
        padding-left: 4px;
        padding-right: 4px;
        gap: 8px;
    }

    .chart-column {
        height: 285px;
        min-width: 52px;
    }

    .chart-bar-wrap {
        height: 225px;
    }

    .chart-bar {
        width: min(42px, 70%);
    }

    .chart-value {
        font-size: 0.62rem;
    }

    .chart-label {
        font-size: 0.62rem;
    }

    .chart.chart-weekly .chart-column {
        flex-basis: 74px;
        min-width: 74px;
    }

    .chart.chart-monthly .chart-column {
        flex-basis: 70px;
        min-width: 70px;
    }

    .overview-highlights {
        grid-template-columns: 1fr;
    }
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
    font-weight: 600;
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
    font-weight: 600;
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

.completed-transaction-search-wrap {
    position: relative;
    width: 290px;
    max-width: 100%;
}

.completed-transaction-filter-search {
    width: 100%;
    max-width: 100%;
    padding-right: 40px !important;
}

.completed-transaction-search-clear {
    position: absolute;
    top: 50%;
    right: 7px;
    transform: translateY(-50%);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    padding: 0;
    border: 0;
    border-radius: 50%;
    background: transparent;
    color: #7B6D62;
    font-size: 21px;
    line-height: 1;
    cursor: pointer;
    transition: background .15s ease, color .15s ease;
}

.completed-transaction-search-clear:hover {
    background: #F1E8DF;
    color: #4A3525;
}

.completed-transaction-search-clear:focus-visible {
    outline: 2px solid #6F4E37;
    outline-offset: 1px;
}

.completed-transaction-search-clear[hidden] {
    display: none !important;
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
    .completed-transaction-search-wrap,
    .completed-transaction-filter-search,
    .completed-transaction-filter-period,
    .completed-transaction-filter-month {
        width: 100%;
        max-width: 100%;
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
    font-weight: 600;
    line-height: 1;
}

.transaction-order-cell {
    min-width: 0;
}

.transaction-order-number {
    color: #2C221E;
    font-size: 0.82rem;
    font-weight: 600;
    line-height: 1.15;
    overflow-wrap: anywhere;
}

.transaction-claim-number {
    margin-top: 2px;
    color: #8A7F75;
    font-size: 0.67rem;
    font-weight: 400;
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
    font-weight: 600;
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
    font-weight: 600;
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
    font-weight: 500;
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
    font-weight: 500;
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
    font-weight: 600;
}

.payment-badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 6px;
    background: #F7F1E8;
    color: #6f4e37;
    font-size: 0.68rem;
    font-weight: 500;
}

.btn-view-transaction {
    border: 2px solid #6F4E37;
    border-radius: 8px;
    padding: 6px 10px;
    background: #ffffff;
    color: #4A3525;
    font-size: 0.72rem;
    font-weight: 500;
    white-space: nowrap;
}

.btn-view-transaction:hover {
    background: #4A3525;
    color: #ffffff;
}

.transaction-pagination {
    display: flex;
    justify-content: center;
    margin: 24px 0;
}

.transaction-pagination .pagination {
    margin-bottom: 0;
}

/* Match the current Admin Orders pagination appearance. */
.transaction-pagination .page-link {
    color: #6F4E37;
    border-color: #8B6F5A;
    font-size: .8rem;
    white-space: nowrap;
}

.transaction-pagination .page-item.active .page-link {
    background: #6F4E37;
    border-color: #5A3D2B;
    color: #FFFFFF;
}

.transaction-pagination .page-item.disabled .page-link,
.transaction-pagination .page-link.disabled {
    color: #A79B91;
    border-color: #B8A08A;
    background: #F7F5F2;
    pointer-events: none;
}

.transaction-page-info {
    color: #8a7f75;
    font-size: 0.74rem;
    margin-top: 8px;
    text-align: center;
}

@media (max-width: 768px) {
    .transaction-pagination {
        overflow-x: auto;
        justify-content: flex-start;
        padding: 0 2px 3px;
        -webkit-overflow-scrolling: touch;
    }

    .transaction-pagination nav {
        margin: 0 auto;
    }

    .transaction-pagination .pagination {
        flex-wrap: nowrap;
        white-space: nowrap;
    }
}

.transaction-detail-section {
    margin-bottom: 20px;
}

.transaction-detail-section:last-child {
    margin-bottom: 0;
}

.transaction-detail-section-title {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0 0 10px;
    color: #4A3525;
    font-size: 0.78rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .35px;
}

.transaction-detail-section-title::after {
    content: '';
    flex: 1 1 auto;
    height: 1px;
    background: #E5DAD0;
}

.transaction-detail-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
    margin-bottom: 0;
}

.transaction-detail-box {
    min-width: 0;
    background: #FCF9F6;
    border: 1px solid #DCCFC4;
    border-radius: 10px;
    padding: 12px 13px;
    box-shadow: 0 1px 2px rgba(67, 48, 36, .03);
}

.transaction-detail-box small {
    display: block;
    margin: 0 0 5px;
    color: #8A7F75;
    font-size: 0.64rem;
    font-weight: 600;
    line-height: 1.2;
    text-transform: uppercase;
    letter-spacing: .25px;
}

.transaction-detail-box strong {
    display: block;
    max-width: 100%;
    color: #2C221E;
    font-size: 0.86rem;
    font-weight: 500;
    line-height: 1.35;
    overflow-wrap: anywhere;
    word-break: break-word;
}

.transaction-detail-box.highlight {
    background: #F7F1E8;
    border-color: #CDB9A8;
}

.transaction-status-completed {
    display: inline-flex !important;
    align-items: center;
    width: fit-content;
    padding: 5px 9px;
    border-radius: 999px;
    background: #EAF6EE;
    border: 1px solid #B9DCC4;
    color: #2F6E3E !important;
    font-size: 0.72rem !important;
    font-weight: 600 !important;
    line-height: 1 !important;
    white-space: nowrap;
}

.transaction-payment-value {
    display: inline-flex !important;
    align-items: center;
    width: fit-content;
    padding: 5px 9px;
    border-radius: 7px;
    background: #F7F1E8;
    border: 1px solid #D9C5B4;
    color: #6F4E37 !important;
    font-size: 0.74rem !important;
    font-weight: 600 !important;
    line-height: 1 !important;
    white-space: nowrap;
}

.transaction-detail-item {
    padding: 12px 0;
    border-bottom: 1px solid #E5DAD0;
}

.transaction-detail-item:last-child {
    border-bottom: none;
}

.transaction-detail-item-name {
    color: #2C221E;
    font-size: 0.84rem;
    font-weight: 500;
    line-height: 1.35;
}

.transaction-detail-item-info {
    color: #7B7068;
    font-size: 0.73rem;
    margin-top: 4px;
    line-height: 1.55;
}

.transaction-detail-item-price {
    flex: 0 0 auto;
    color: #4A3525;
    font-size: 0.82rem;
    font-weight: 600;
    white-space: nowrap;
}

.transaction-items-card {
    padding: 2px 14px;
    background: #FFFDFC;
    border: 1px solid #E1D6CC;
    border-radius: 11px;
}

.transaction-items-empty {
    padding: 14px 0;
    color: #8A7F75;
    font-size: 0.78rem;
}

.transaction-detail-total {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    margin-top: 16px;
    padding: 13px 14px;
    border: 1px solid #D7C3B1;
    border-radius: 10px;
    background: #F7F1E8;
}

.transaction-detail-total span {
    color: #6F6259;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .25px;
}

.transaction-detail-total strong {
    color: #4A3525;
    font-size: 1.15rem;
    font-weight: 600;
    line-height: 1.1;
    white-space: nowrap;
}

.transaction-payment-proof {
    margin-top: 18px;
    padding: 15px;
    border: 1px solid #E1D6CC;
    border-radius: 11px;
    background: #FCF9F6;
}

.transaction-payment-proof h6 {
    color: #4A3525;
    font-size: 0.8rem;
    font-weight: 600;
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
    font-weight: 500;
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
    font-weight: 600;
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
    font-weight: 500;
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
        font-weight: 600;
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
        font-weight: 600;
        line-height: 1.15;
        overflow-wrap: anywhere;
    }

    .transaction-claim-number {
        margin-top: 2px;
        color: #8A7F75;
        font-size: 0.62rem;
        font-weight: 400;
        line-height: 1.1;
        white-space: nowrap;
    }

    .transaction-amount-cell {
        grid-column: 2;
        grid-row: 2;
        text-align: left !important;
        color: #4A3525;
        font-size: 0.86rem;
        font-weight: 600;
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
        font-weight: 600 !important;
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
        font-weight: 500;
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

        font-weight: 600 !important;

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

        font-weight: 600 !important;

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


/* =========================================================
   COMPLETED TRANSACTION DETAILS MODAL
   Cleaner hierarchy and lighter field treatment.
========================================================= */

.transaction-details-dialog {
    max-width: 760px;
}

.transaction-detail-modal {
    border: 0;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 18px 55px rgba(44, 34, 30, .24);
    background: #FFFFFF;
}

.transaction-detail-modal .modal-header {
    padding: 15px 18px;
    border-bottom: 1px solid #E5DAD0;
    background: #FFFCF9;
}

.transaction-detail-modal .modal-title {
    display: flex;
    align-items: center;
    gap: 3px;
    color: #4A3525;
    font-size: 1rem;
    font-weight: 600;
}

.transaction-detail-modal .modal-title i {
    font-size: 0.95rem;
}

.transaction-detail-modal .modal-body {
    padding: 18px;
    background: #FFFFFF;
}

.transaction-detail-modal .modal-footer {
    padding: 12px 18px;
    border-top: 1px solid #E5DAD0;
    background: #FFFCF9;
}

.transaction-detail-modal .modal-footer .btn-secondary {
    min-width: 76px;
    border: 1px solid #BFAE9F;
    border-radius: 8px;
    background: #FFFFFF;
    color: #4A3525;
    font-size: 0.78rem;
    font-weight: 500;
}

.transaction-detail-modal .modal-footer .btn-secondary:hover {
    background: #F7F1E8;
    border-color: #8B6A55;
    color: #4A3525;
}

.transaction-detail-modal .btn-close {
    opacity: .55;
}

.transaction-detail-modal .btn-close:hover {
    opacity: .9;
}

.transaction-detail-modal .transaction-payment-proof h6 {
    margin: 0 0 9px;
    color: #4A3525;
    font-size: 0.76rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .25px;
}

.transaction-detail-modal .btn-view-payment-proof {
    border: 1px solid #CBB7A7;
    border-radius: 8px;
    padding: 8px 11px;
    background: #FFFFFF;
    color: #4A3525;
    font-size: 0.73rem;
    font-weight: 500;
}

.transaction-detail-modal .btn-view-payment-proof:hover,
.transaction-detail-modal .btn-view-payment-proof[aria-expanded="true"] {
    background: #F7F1E8;
    border-color: #8B6A55;
    color: #4A3525;
}

.transaction-detail-modal .gcash-proof-dropdown {
    margin-top: 10px;
    padding: 10px;
    border: 1px solid #DCCFC4;
    border-radius: 10px;
    background: #FFFFFF;
}

.transaction-detail-modal .gcash-proof-dropdown img {
    border: 1px solid #DCCFC4;
    border-radius: 8px;
}

@media (max-width: 576px) {
    .transaction-detail-modal .modal-body {
        padding: 14px;
    }

    .transaction-detail-grid {
        gap: 8px;
    }

    .transaction-detail-box {
        padding: 10px 11px;
    }

    .transaction-detail-box strong {
        font-size: 0.82rem;
    }

    .transaction-detail-total {
        padding: 12px;
    }

    .transaction-detail-total strong {
        font-size: 1.02rem;
    }

    .transaction-items-card {
        padding: 2px 11px;
    }
}


/* =========================================================
   GCASH PAYMENT PROOF INDICATOR IN COMPLETED TRANSACTIONS
   Shows at a glance when a completed GCash transaction has
   an uploaded payment screenshot. The full proof remains
   accessible inside View Details.
========================================================= */
.transaction-payment-stack {
    display: inline-flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 4px;
    max-width: 100%;
}

.transaction-proof-indicator {
    display: inline-flex;
    align-items: center;
    color: #6F4E37;
    font-size: 0.62rem;
    font-weight: 500;
    line-height: 1;
    white-space: nowrap;
}

@media (max-width: 991.98px) {
    .transaction-proof-indicator {
        font-size: 0.60rem;
    }
}

@media (max-width: 767.98px) {
    .transaction-payment-stack {
        gap: 3px;
        max-width: 100%;
    }

    .transaction-proof-indicator {
        font-size: 0.58rem;
    }
}


/* =========================================================
   REDESIGN LAYER
   Appended last so it refines the rules above without
   touching the JS hooks (.transaction-report-card,
   .overview-card, .mode-tab, .btn-view-transaction, modal ids).
========================================================= */

.sales-content {
    --sr-ink: #2C221E;
    --sr-brown: #4A3525;
    --sr-accent: #6F4E37;
    --sr-line: #E8DED4;
    --sr-soft: #FBF8F4;
    --sr-cream: #F7F1E8;
    --sr-muted: #8A7F75;
    --sr-green: #2F6E3E;
    --sr-blue: #1677FF;
    --sr-shadow: 0 1px 2px rgba(44, 34, 30, .05), 0 10px 28px rgba(44, 34, 30, .06);
}

.sr-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 11px;
    border: 1px solid var(--sr-line);
    border-radius: 999px;
    background: var(--sr-soft);
    color: #6F665F;
    font-size: .72rem;
    font-weight: 500;
    white-space: nowrap;
}

.sr-chip i {
    color: var(--sr-accent);
}

/* ---------- Completed transactions ---------- */
.transaction-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 14px;
}

.transaction-head .transaction-title {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0;
    font-size: 1rem;
    font-weight: 700;
}

.transaction-head .transaction-title i {
    color: var(--sr-accent);
}

.completed-transaction-filter-form {
    border: 1px solid var(--sr-line);
    border-radius: 12px;
    background: var(--sr-soft);
}

.transaction-customer {
    display: flex;
    align-items: center;
    gap: 5px;
    margin-top: 4px;
    color: #6F665F;
    font-size: .72rem;
    line-height: 1.2;
}

.transaction-customer i {
    color: var(--sr-accent);
    font-size: .78rem;
}

.btn-view-transaction {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    border: 1px solid #CDB9A8;
    border-radius: 9px;
    padding: 7px 12px;
    font-weight: 600;
    transition: background-color .15s ease, border-color .15s ease, color .15s ease;
}

.btn-view-transaction:hover {
    background: var(--sr-brown);
    border-color: var(--sr-brown);
    color: #FFFFFF;
}

/* Desktop / tablet table only; phones keep their card layout. */
@media (min-width: 768px) {

    .transaction-table {
        border-collapse: separate;
        border-spacing: 0;
    }

    .transaction-table th {
        background: var(--sr-soft);
        border-bottom: 1px solid var(--sr-line);
        font-weight: 600;
        letter-spacing: .3px;
    }

    .transaction-table th:first-child {
        border-top-left-radius: 10px;
    }

    .transaction-table th:last-child {
        border-top-right-radius: 10px;
    }

    .transaction-table td {
        border-bottom: 1px solid #F0E8E0;
        padding: 13px 10px;
        transition: background-color .12s ease;
    }

    .transaction-table tbody tr:hover td {
        background: #FDFAF6;
    }

    .transaction-table tbody tr:last-child td {
        border-bottom: 1px solid var(--sr-line);
    }
}

.transaction-pagination .page-link {
    border-radius: 8px;
    margin: 0 2px;
    border-color: var(--sr-line);
}

.transaction-pagination .page-item.active .page-link {
    border-color: var(--sr-brown);
}

.report-footer-summary {
    border-top: 1px solid var(--sr-line);
    padding-top: 14px;
}



/* =========================================================
   TRANSACTION DETAILS MODAL (redesigned)
========================================================= */

.transaction-details-dialog {
    --bs-modal-width: 920px;
    max-width: 920px;
}

.transaction-detail-modal {
    border-radius: 18px;
}

.txd-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px 20px;
    border-bottom: 1px solid var(--sr-line, #E8DED4);
    background: #FFFCF9;
}

.txd-header .btn-close {
    margin: 0 0 0 4px;
}

.txd-header-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    width: 40px;
    height: 40px;
    border-radius: 12px;
    background: #4A3525;
    color: #FFFFFF;
    font-size: 1.05rem;
}

.txd-header-text {
    flex: 1 1 auto;
    min-width: 0;
}

.transaction-detail-modal .txd-header .modal-title {
    display: block;
    margin: 0;
    font-size: 1.02rem;
    font-weight: 700;
    line-height: 1.2;
}

.txd-header-sub {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 3px;
    color: #8A7F75;
    font-size: .76rem;
    font-weight: 500;
}

.txd-sep {
    display: inline-block;
    width: 3px;
    height: 3px;
    border-radius: 50%;
    background: #C9BBAE;
}

.txd-status {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    flex: 0 0 auto;
    padding: 6px 11px;
    border: 1px solid #B9DCC4;
    border-radius: 999px;
    background: #EAF6EE;
    color: #2F6E3E;
    font-size: .72rem;
    font-weight: 700;
    line-height: 1;
}

.txd-body {
    padding: 18px 20px 20px;
    background: #FAF7F3;
}

/* Amount band */
.txd-hero {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 16px;
    padding: 16px 20px;
    border-radius: 14px;
    background: #4A3525;
    color: #FFFFFF;
}

.txd-hero-label {
    color: rgba(255, 255, 255, .7);
    font-size: .7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .4px;
}

.txd-hero-amount {
    margin-top: 2px;
    font-size: 1.85rem;
    font-weight: 700;
    letter-spacing: -.4px;
    line-height: 1.1;
    font-variant-numeric: tabular-nums;
}

.txd-hero-side {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 6px;
}

.txd-hero-meta {
    color: rgba(255, 255, 255, .72);
    font-size: .74rem;
}

.txd-pay {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 999px;
    font-size: .76rem;
    font-weight: 700;
    line-height: 1;
}

.txd-pay.is-cash {
    background: #F7F1E8;
    color: #6F4E37;
}

.txd-pay.is-gcash {
    background: #E7F1FF;
    color: #1677FF;
}

/* Two columns */
.txd-columns {
    display: grid;
    grid-template-columns: minmax(0, 5fr) minmax(0, 7fr);
    gap: 14px;
    align-items: start;
}

.txd-col {
    display: flex;
    flex-direction: column;
    gap: 14px;
    min-width: 0;
}

.txd-card {
    padding: 14px 16px;
    border: 1px solid #E8DED4;
    border-radius: 14px;
    background: #FFFFFF;
}

.txd-card-title {
    display: flex;
    align-items: center;
    gap: 7px;
    margin: 0 0 12px;
    color: #4A3525;
    font-size: .72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .4px;
}

.txd-card-title i {
    color: #6F4E37;
    font-size: .9rem;
}

.txd-count {
    margin-left: auto;
    min-width: 22px;
    padding: 2px 7px;
    border-radius: 999px;
    background: #F7F1E8;
    color: #6F4E37;
    font-size: .68rem;
    text-align: center;
    letter-spacing: 0;
}

.txd-muted {
    color: #8A7F75;
    font-size: .8rem;
}

/* Customer */
.txd-customer {
    display: flex;
    align-items: center;
    gap: 12px;
}

.txd-avatar {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    width: 42px;
    height: 42px;
    border-radius: 50%;
    background: #F7F1E8;
    border: 1px solid #D9C5B4;
    color: #6F4E37;
    font-size: 1rem;
    font-weight: 700;
}

.txd-customer-text {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}

.txd-customer-text strong {
    color: #2C221E;
    font-size: .92rem;
    font-weight: 600;
    overflow-wrap: anywhere;
}

.txd-customer-text a {
    color: #6F4E37;
    font-size: .8rem;
    text-decoration: none;
}

.txd-customer-text a:hover {
    text-decoration: underline;
}

/* Key / value pairs */
.txd-kv {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    margin: 0;
}

.txd-kv dt {
    color: #8A7F75;
    font-size: .68rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .3px;
}

.txd-kv dd {
    margin: 2px 0 0;
    color: #2C221E;
    font-size: .86rem;
    font-weight: 600;
}

/* Timeline */
.txd-timeline {
    position: relative;
    margin: 0;
    padding: 0;
    list-style: none;
}

.txd-timeline li {
    position: relative;
    display: flex;
    gap: 12px;
    padding-bottom: 14px;
}

.txd-timeline li:last-child {
    padding-bottom: 0;
}

.txd-timeline li::before {
    content: "";
    position: absolute;
    left: 5px;
    top: 14px;
    bottom: -2px;
    width: 2px;
    background: #E8DED4;
}

.txd-timeline li:last-child::before {
    display: none;
}

.txd-tl-dot {
    position: relative;
    z-index: 1;
    flex: 0 0 auto;
    width: 12px;
    height: 12px;
    margin-top: 3px;
    border-radius: 50%;
    border: 2px solid #CDB9A8;
    background: #FFFFFF;
}

.txd-timeline li.is-done .txd-tl-dot {
    border-color: #2F6E3E;
    background: #2F6E3E;
}

.txd-timeline strong {
    display: block;
    color: #2C221E;
    font-size: .82rem;
    font-weight: 600;
    line-height: 1.2;
}

.txd-timeline small {
    display: block;
    margin-top: 2px;
    color: #8A7F75;
    font-size: .74rem;
}

.txd-duration {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-top: 12px;
    padding: 5px 10px;
    border-radius: 999px;
    background: #F7F1E8;
    color: #6F4E37;
    font-size: .72rem;
    font-weight: 600;
}

/* Items */
.txd-items {
    margin: 0;
    padding: 0;
    list-style: none;
}

.txd-item {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto;
    gap: 12px;
    padding: 12px 0;
    border-bottom: 1px dashed #E8DED4;
}

.txd-item:first-child {
    padding-top: 0;
}

.txd-item:last-child {
    padding-bottom: 0;
    border-bottom: 0;
}

.txd-item-qty {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 34px;
    height: 26px;
    padding: 0 7px;
    border-radius: 8px;
    background: #F7F1E8;
    color: #6F4E37;
    font-size: .78rem;
    font-weight: 700;
}

.txd-item-name {
    color: #2C221E;
    font-size: .9rem;
    font-weight: 600;
    line-height: 1.3;
    overflow-wrap: anywhere;
}

.txd-free {
    display: inline-block;
    margin-left: 6px;
    padding: 2px 7px;
    border-radius: 999px;
    background: #EAF6EE;
    color: #2F6E3E;
    font-size: .6rem;
    font-weight: 700;
    letter-spacing: .4px;
    vertical-align: middle;
}

.txd-item-unit {
    margin-top: 2px;
    color: #8A7F75;
    font-size: .74rem;
}

.txd-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    margin-top: 8px;
}

.txd-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 9px;
    border: 1px solid #E8DED4;
    border-radius: 999px;
    background: #FBF8F4;
    color: #6F4E37;
    font-size: .7rem;
    font-weight: 500;
    line-height: 1.3;
}

.txd-chip i {
    font-size: .7rem;
}

.txd-chip-addon {
    background: #FFF7E8;
    border-color: #F0DDB8;
    color: #8A5A12;
}

.txd-cups {
    margin: 8px 0 0;
    padding: 8px 10px;
    list-style: none;
    border-radius: 8px;
    background: #FBF8F4;
    color: #6F4E37;
    font-size: .74rem;
}

.txd-cups li + li {
    margin-top: 3px;
}

.txd-item-price {
    color: #2C221E;
    font-size: .9rem;
    font-weight: 700;
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
}

.txd-item-price.is-free {
    color: #A39A91;
    text-decoration: line-through;
    font-weight: 500;
}

/* Totals */
.txd-summary {
    background: #FFFCF9;
}

.txd-line {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    padding: 5px 0;
    color: #6F665F;
    font-size: .82rem;
    font-variant-numeric: tabular-nums;
}

.txd-line.is-discount {
    color: #2F6E3E;
}

.txd-line.txd-total {
    margin-top: 6px;
    padding-top: 12px;
    border-top: 1px solid #E8DED4;
    color: #2C221E;
    font-size: .9rem;
    font-weight: 600;
}

.txd-line.txd-total strong {
    color: #4A3525;
    font-size: 1.2rem;
    font-weight: 700;
}

/* Proof */
.txd-proof {
    position: relative;
    display: block;
    overflow: hidden;
    border: 1px solid #E8DED4;
    border-radius: 12px;
    background: #FBF8F4;
    text-align: center;
}

.txd-proof img {
    display: block;
    max-width: 100%;
    max-height: 320px;
    margin: 0 auto;
    object-fit: contain;
}

.txd-proof-hint {
    position: absolute;
    right: 8px;
    bottom: 8px;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 9px;
    border-radius: 999px;
    background: rgba(44, 34, 30, .78);
    color: #FFFFFF;
    font-size: .68rem;
    opacity: 0;
    transition: opacity .15s ease;
}

.txd-proof:hover .txd-proof-hint,
.txd-proof:focus-visible .txd-proof-hint {
    opacity: 1;
}

/* Modal footer */
.transaction-detail-modal .modal-footer {
    justify-content: flex-end;
}

/* Modal: stack on tablets and phones */
@media (max-width: 767.98px) {
    .txd-columns {
        grid-template-columns: 1fr;
    }

    .txd-header {
        flex-wrap: wrap;
        padding: 14px 16px;
    }

    .txd-status {
        order: 3;
    }

    .txd-body {
        padding: 14px;
    }

    .txd-hero {
        padding: 14px 16px;
    }

    .txd-hero-amount {
        font-size: 1.55rem;
    }

    .txd-hero-side {
        align-items: flex-start;
    }
}

@media (max-width: 575.98px) {
    .txd-item {
        grid-template-columns: auto minmax(0, 1fr);
    }

    .txd-item-price {
        grid-column: 2;
        justify-self: start;
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

                        <div class="sales-chart-area">

                            <!-- Y AXIS -->
                            <div class="sales-chart-yaxis" aria-hidden="true">
                                <span>₱<?= number_format($chartMax, 0) ?></span>
                                <span>₱<?= number_format($chartStep * 3, 0) ?></span>
                                <span>₱<?= number_format($chartStep * 2, 0) ?></span>
                                <span>₱<?= number_format($chartStep, 0) ?></span>
                                <span>₱0</span>
                            </div>

                            <!-- GRAPH -->
                            <div class="sales-chart-viewport">
                                <div class="sales-chart-plot">

                                    <div class="sales-chart-grid" aria-hidden="true"></div>

                                    <div class="chart <?= $report_mode === 'weekly' ? 'chart-weekly' : ($report_mode === 'monthly' ? 'chart-monthly' : '') ?>">

                                        <?php foreach ($salesOverview as $period): ?>

                                            <?php
                                            $heightPercentage =
                                                ($period['sales'] / $chartMax) * 100;

                                            if (
                                                $period['sales'] > 0 &&
                                                $heightPercentage < 3
                                            ) {
                                                $heightPercentage = 3;
                                            }

                                            $isBest = $bestPeriod
                                                && $period['sales'] > 0
                                                && $period['sales'] === $bestPeriod['sales'];

                                            $tooltipText =
                                                $period['label']
                                                . ' • Sales: ₱'
                                                . number_format($period['sales'], 2)
                                                . ' • '
                                                . (int)$period['orders']
                                                . ' order'
                                                . ((int)$period['orders'] === 1 ? '' : 's');
                                            ?>

                                            <div class="chart-column">

                                                <div class="chart-value">
                                                    ₱<?= number_format($period['sales'], 0) ?>
                                                </div>

                                                <div class="chart-bar-wrap">

                                                    <div
                                                        class="chart-bar <?= $isBest ? 'is-best' : '' ?>"
                                                        style="height: <?= $heightPercentage ?>%;"
                                                        data-tooltip="<?= htmlspecialchars($tooltipText, ENT_QUOTES, 'UTF-8') ?>"
                                                        aria-label="<?= htmlspecialchars($tooltipText, ENT_QUOTES, 'UTF-8') ?>"
                                                    ></div>

                                                </div>

                                                <div class="chart-label">
                                                    <?= htmlspecialchars($period['label']) ?>
                                                </div>

                                                <div class="chart-orders">
                                                    <?= (int)$period['orders'] ?> order<?= (int)$period['orders'] === 1 ? '' : 's' ?>
                                                </div>

                                            </div>

                                        <?php endforeach; ?>

                                    </div>

                                </div>
                            </div>

                        </div>

                    <?php endif; ?>

                </div>


            </section>


            <!-- COMPLETED SALES TRANSACTIONS -->
            <section class="report-card transaction-report-card">

                <div class="transaction-section">

                    <div class="transaction-head">
                        <div class="transaction-title">
                            <i class="bi bi-receipt"></i>
                            Completed Sales Transactions
                        </div>
                        <span class="sr-chip">
                            <?= number_format($completedTransactionCount) ?>
                            transaction<?= $completedTransactionCount !== 1 ? 's' : '' ?>
                            • ₱<?= number_format($completedTransactionSalesTotal, 2) ?>
                        </span>
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

                            <div class="completed-transaction-search-wrap">
                                <input
                                    type="text"
                                    id="completedTransactionSearch"
                                    class="completed-transaction-filter-search"
                                    data-completed-transaction-search
                                    name="completed_q"
                                    value="<?= htmlspecialchars($completed_search) ?>"
                                    maxlength="100"
                                    placeholder="Order, claim, customer, phone..."
                                    autocomplete="off"
                                >
                                <button
                                    type="button"
                                    class="completed-transaction-search-clear"
                                    data-completed-transaction-search-clear
                                    aria-label="Clear search text"
                                    title="Clear search text"
                                    <?= $completed_search !== '' ? '' : 'hidden' ?>
                                >
                                    &times;
                                </button>
                            </div>
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
                                                <?php if (trim((string)($sale['customer_name'] ?? '')) !== ''): ?>
                                                    <div class="transaction-customer">
                                                        <i class="bi bi-person"></i>
                                                        <?= htmlspecialchars((string)$sale['customer_name']) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>

                                            <td
                                                class="transaction-payment-cell"
                                                data-label="Payment"
                                            >
                                                <div class="transaction-payment-stack">
                                                    <span class="payment-badge <?= $paymentClass ?>">
                                                        <?= htmlspecialchars($payment) ?>
                                                    </span>

                                                    <?php if (
                                                        strtolower((string)($sale['payment_method'] ?? '')) === 'gcash'
                                                        && !empty($sale['payment_screenshot'])
                                                    ): ?>
                                                        <span class="transaction-proof-indicator">
                                                            <i class="bi bi-image me-1"></i>
                                                            Proof Available
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
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
                                        <?php
                                        /* ---------- values used only by this modal ---------- */
                                        $txdTotal = (float)$sale['total_amount'];
                                        $txdSubtotal = ($sale['subtotal'] ?? null) !== null
                                            ? (float)$sale['subtotal']
                                            : null;
                                        $txdDiscountAmount = (float)($sale['discount_amount'] ?? 0);
                                        $txdDiscountType = strtolower(trim((string)($sale['discount_type'] ?? 'none')));
                                        $txdDiscountRate = (float)($sale['discount_rate'] ?? 0);

                                        $txdDiscountLabel = $txdDiscountType === 'pwd'
                                            ? 'PWD discount'
                                            : ($txdDiscountType === 'senior' ? 'Senior Citizen discount' : 'Discount');
                                        if ($txdDiscountRate > 0) {
                                            $txdDiscountLabel .= ' (' . rtrim(rtrim(number_format($txdDiscountRate, 2), '0'), '.') . '%)';
                                        }

                                        /* Promotion savings are not stored on the order row, so derive them. */
                                        $txdPromoDiscount = $txdSubtotal !== null
                                            ? max(0.0, round($txdSubtotal - $txdDiscountAmount - $txdTotal, 2))
                                            : 0.0;

                                        $txdItemCount = 0;
                                        foreach ($saleItems as $txdCountItem) {
                                            $txdItemCount += (int)($txdCountItem['quantity'] ?? 0);
                                        }

                                        $txdPickupTs = ($salePickupDate !== 'N/A') ? strtotime($salePickupDate) : false;
                                        $txdPickupDateLabel = $txdPickupTs !== false
                                            ? date('D, M d, Y', $txdPickupTs)
                                            : 'N/A';

                                        $txdCreatedTs = !empty($sale['created_at']) ? strtotime($sale['created_at']) : false;
                                        $txdClosedTs = !empty($sale['closed_at']) ? strtotime($sale['closed_at']) : false;

                                        $txdDuration = '';
                                        if ($txdCreatedTs !== false && $txdClosedTs !== false && $txdClosedTs >= $txdCreatedTs) {
                                            $txdMinutes = (int)round(($txdClosedTs - $txdCreatedTs) / 60);
                                            if ($txdMinutes < 60) {
                                                $txdDuration = $txdMinutes . ' min';
                                            } elseif ($txdMinutes < 1440) {
                                                $txdDuration = floor($txdMinutes / 60) . ' hr ' . ($txdMinutes % 60) . ' min';
                                            } else {
                                                $txdDuration = floor($txdMinutes / 1440) . ' day(s)';
                                            }
                                        }

                                        $txdIsGcash = strtolower((string)($sale['payment_method'] ?? '')) === 'gcash';
                                        $txdHasProof = $txdIsGcash && !empty($sale['payment_screenshot']);

                                        $txdCustomerName = trim((string)($sale['customer_name'] ?? ''));
                                        $txdInitial = $txdCustomerName !== ''
                                            ? mb_strtoupper(mb_substr($txdCustomerName, 0, 1))
                                            : '?';
                                        $txdPhone = trim((string)($sale['contact_number'] ?? ''));
                                        $txdTel = preg_replace('/[^0-9+]/', '', $txdPhone);
                                        ?>
                                        <div
                                            class="modal fade no-print"
                                            id="transactionDetailsModal<?= $saleOrderId ?>"
                                            tabindex="-1"
                                            aria-labelledby="transactionDetailsLabel<?= $saleOrderId ?>"
                                            aria-hidden="true"
                                        >

                                            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg transaction-details-dialog">

                                                <div class="modal-content transaction-detail-modal">

                                                    <!-- HEADER -->
                                                    <div class="modal-header txd-header">

                                                        <span class="txd-header-icon" aria-hidden="true">
                                                            <i class="bi bi-receipt"></i>
                                                        </span>

                                                        <div class="txd-header-text">
                                                            <h5
                                                                class="modal-title"
                                                                id="transactionDetailsLabel<?= $saleOrderId ?>"
                                                            >
                                                                Transaction Details
                                                            </h5>
                                                            <div class="txd-header-sub">
                                                                <span><?= htmlspecialchars($sale['order_number'] ?? 'N/A') ?></span>
                                                                <i class="txd-sep"></i>
                                                                <span><?= htmlspecialchars($sale['claim_number'] ?? 'N/A') ?></span>
                                                            </div>
                                                        </div>

                                                        <span class="txd-status">
                                                            <i class="bi bi-check-circle-fill"></i>
                                                            Completed
                                                        </span>

                                                        <button
                                                            type="button"
                                                            class="btn-close"
                                                            data-bs-dismiss="modal"
                                                            aria-label="Close"
                                                        ></button>

                                                    </div>


                                                    <div class="modal-body txd-body">

                                                        <!-- AMOUNT BAND -->
                                                        <div class="txd-hero">

                                                            <div>
                                                                <div class="txd-hero-label">Total Amount</div>
                                                                <div class="txd-hero-amount">
                                                                    ₱<?= number_format($txdTotal, 2) ?>
                                                                </div>
                                                            </div>

                                                            <div class="txd-hero-side">
                                                                <span class="txd-pay <?= $txdIsGcash ? 'is-gcash' : 'is-cash' ?>">
                                                                    <i class="bi <?= $txdIsGcash ? 'bi-phone' : 'bi-cash-coin' ?>"></i>
                                                                    <?= htmlspecialchars($payment) ?>
                                                                </span>
                                                                <span class="txd-hero-meta">
                                                                    <?= $txdItemCount ?> item<?= $txdItemCount === 1 ? '' : 's' ?>
                                                                    <?php if ($txdClosedTs !== false): ?>
                                                                        • <?= date('M d, Y', $txdClosedTs) ?>
                                                                    <?php endif; ?>
                                                                </span>
                                                            </div>

                                                        </div>


                                                        <div class="txd-columns">

                                                            <!-- LEFT: who / when -->
                                                            <div class="txd-col">

                                                                <section class="txd-card">
                                                                    <h6 class="txd-card-title">
                                                                        <i class="bi bi-person"></i> Customer
                                                                    </h6>

                                                                    <div class="txd-customer">
                                                                        <span class="txd-avatar" aria-hidden="true">
                                                                            <?= htmlspecialchars($txdInitial) ?>
                                                                        </span>
                                                                        <div class="txd-customer-text">
                                                                            <strong>
                                                                                <?= $txdCustomerName !== '' ? htmlspecialchars($txdCustomerName) : 'N/A' ?>
                                                                            </strong>
                                                                            <?php if ($txdPhone !== ''): ?>
                                                                                <a href="tel:<?= htmlspecialchars($txdTel) ?>">
                                                                                    <i class="bi bi-telephone"></i>
                                                                                    <?= htmlspecialchars($txdPhone) ?>
                                                                                </a>
                                                                            <?php else: ?>
                                                                                <span class="txd-muted">No contact number</span>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    </div>
                                                                </section>


                                                                <section class="txd-card">
                                                                    <h6 class="txd-card-title">
                                                                        <i class="bi bi-bag-check"></i> Pick-up
                                                                    </h6>

                                                                    <dl class="txd-kv">
                                                                        <div>
                                                                            <dt>Date</dt>
                                                                            <dd><?= htmlspecialchars($txdPickupDateLabel) ?></dd>
                                                                        </div>
                                                                        <div>
                                                                            <dt>Time</dt>
                                                                            <dd><?= htmlspecialchars($salePickupTime) ?></dd>
                                                                        </div>
                                                                    </dl>
                                                                </section>


                                                                <section class="txd-card">
                                                                    <h6 class="txd-card-title">
                                                                        <i class="bi bi-clock-history"></i> Timeline
                                                                    </h6>

                                                                    <ol class="txd-timeline">
                                                                        <li>
                                                                            <span class="txd-tl-dot"></span>
                                                                            <div>
                                                                                <strong>Order placed</strong>
                                                                                <small><?= htmlspecialchars($saleCreatedDate) ?></small>
                                                                            </div>
                                                                        </li>
                                                                        <li class="is-done">
                                                                            <span class="txd-tl-dot"></span>
                                                                            <div>
                                                                                <strong>Completed</strong>
                                                                                <small><?= htmlspecialchars($saleClosedDate) ?></small>
                                                                            </div>
                                                                        </li>
                                                                    </ol>

                                                                    <?php if ($txdDuration !== ''): ?>
                                                                        <div class="txd-duration">
                                                                            <i class="bi bi-stopwatch"></i>
                                                                            Fulfilled in <?= htmlspecialchars($txdDuration) ?>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                </section>

                                                            </div>


                                                            <!-- RIGHT: what was bought -->
                                                            <div class="txd-col">

                                                                <section class="txd-card">
                                                                    <h6 class="txd-card-title">
                                                                        <i class="bi bi-cup-hot"></i> Order Items
                                                                        <span class="txd-count"><?= $txdItemCount ?></span>
                                                                    </h6>

                                                                    <?php if (empty($saleItems)): ?>

                                                                        <div class="txd-muted">No order items found.</div>

                                                                    <?php else: ?>

                                                                        <ul class="txd-items">

                                                                            <?php foreach ($saleItems as $item): ?>

                                                                                <?php
                                                                                $itemName = trim((string)($item['product_name'] ?? ''));
                                                                                $itemIsFree = false;

                                                                                if (preg_match('/\s*\(FREE\)\s*$/i', $itemName)) {
                                                                                    $itemIsFree = true;
                                                                                    $itemName = trim(preg_replace('/\s*\(FREE\)\s*$/i', '', $itemName));
                                                                                }

                                                                                $itemQty = (int)($item['quantity'] ?? 0);

                                                                                $itemCups = [];
                                                                                $itemAddonChips = [];

                                                                                foreach (orderAddonParts($item['addons'] ?? null) as $addonPart) {
                                                                                    if (preg_match('/^Cup\s+\d+\s*:/i', $addonPart)) {
                                                                                        $itemCups[] = $addonPart;
                                                                                    } else {
                                                                                        $itemAddonChips[] = $addonPart;
                                                                                    }
                                                                                }
                                                                                ?>

                                                                                <li class="txd-item">

                                                                                    <span class="txd-item-qty"><?= $itemQty ?>×</span>

                                                                                    <div class="txd-item-main">

                                                                                        <div class="txd-item-name">
                                                                                            <?= htmlspecialchars($itemName) ?>
                                                                                            <?php if ($itemIsFree): ?>
                                                                                                <span class="txd-free">FREE</span>
                                                                                            <?php endif; ?>
                                                                                        </div>

                                                                                        <div class="txd-item-unit">
                                                                                            ₱<?= number_format((float)$item['unit_price'], 2) ?> each
                                                                                        </div>

                                                                                        <?php if (!empty($item['size']) || !empty($item['sugar_level']) || $itemAddonChips): ?>
                                                                                            <div class="txd-chips">
                                                                                                <?php if (!empty($item['size'])): ?>
                                                                                                    <span class="txd-chip"><i class="bi bi-cup"></i> <?= htmlspecialchars($item['size']) ?></span>
                                                                                                <?php endif; ?>
                                                                                                <?php if (!empty($item['sugar_level'])): ?>
                                                                                                    <span class="txd-chip"><i class="bi bi-droplet"></i> <?= htmlspecialchars($item['sugar_level']) ?> sugar</span>
                                                                                                <?php endif; ?>
                                                                                                <?php foreach ($itemAddonChips as $addonChip): ?>
                                                                                                    <span class="txd-chip txd-chip-addon"><i class="bi bi-plus-lg"></i> <?= htmlspecialchars($addonChip) ?></span>
                                                                                                <?php endforeach; ?>
                                                                                            </div>
                                                                                        <?php endif; ?>

                                                                                        <?php if ($itemCups): ?>
                                                                                            <ul class="txd-cups">
                                                                                                <?php foreach ($itemCups as $cupLine): ?>
                                                                                                    <li><?= htmlspecialchars($cupLine) ?></li>
                                                                                                <?php endforeach; ?>
                                                                                            </ul>
                                                                                        <?php endif; ?>

                                                                                    </div>

                                                                                    <div class="txd-item-price <?= $itemIsFree ? 'is-free' : '' ?>">
                                                                                        ₱<?= number_format((float)$item['subtotal'], 2) ?>
                                                                                    </div>

                                                                                </li>

                                                                            <?php endforeach; ?>

                                                                        </ul>

                                                                    <?php endif; ?>
                                                                </section>


                                                                <section class="txd-card txd-summary">

                                                                    <?php if ($txdSubtotal !== null && ($txdPromoDiscount > 0 || $txdDiscountAmount > 0)): ?>
                                                                        <div class="txd-line">
                                                                            <span>Subtotal</span>
                                                                            <span>₱<?= number_format($txdSubtotal, 2) ?></span>
                                                                        </div>
                                                                    <?php endif; ?>

                                                                    <?php if ($txdPromoDiscount > 0): ?>
                                                                        <div class="txd-line is-discount">
                                                                            <span>Promotion</span>
                                                                            <span>−₱<?= number_format($txdPromoDiscount, 2) ?></span>
                                                                        </div>
                                                                    <?php endif; ?>

                                                                    <?php if ($txdDiscountAmount > 0): ?>
                                                                        <div class="txd-line is-discount">
                                                                            <span><?= htmlspecialchars($txdDiscountLabel) ?></span>
                                                                            <span>−₱<?= number_format($txdDiscountAmount, 2) ?></span>
                                                                        </div>
                                                                    <?php endif; ?>

                                                                    <div class="txd-line txd-total">
                                                                        <span>Total Amount</span>
                                                                        <strong>₱<?= number_format($txdTotal, 2) ?></strong>
                                                                    </div>

                                                                </section>


                                                                <?php if ($txdHasProof): ?>

                                                                    <section class="txd-card transaction-payment-proof">
                                                                        <h6 class="txd-card-title">
                                                                            <i class="bi bi-image"></i> GCash Payment Proof
                                                                        </h6>

                                                                        <a
                                                                            class="txd-proof"
                                                                            href="../<?= htmlspecialchars($sale['payment_screenshot']) ?>"
                                                                            target="_blank"
                                                                            rel="noopener"
                                                                            title="Open full image in a new tab"
                                                                        >
                                                                            <img
                                                                                src="../<?= htmlspecialchars($sale['payment_screenshot']) ?>"
                                                                                alt="GCash Payment Proof for order <?= htmlspecialchars(
                                                                                    $sale['order_number'] ?? (string)$saleOrderId,
                                                                                    ENT_QUOTES,
                                                                                    'UTF-8'
                                                                                ) ?>"
                                                                                loading="lazy"
                                                                            >
                                                                            <span class="txd-proof-hint">
                                                                                <i class="bi bi-arrows-fullscreen"></i> View full size
                                                                            </span>
                                                                        </a>
                                                                    </section>

                                                                <?php endif; ?>

                                                            </div>

                                                        </div>

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


                        <!-- TRANSACTION PAGINATION: follows the current Admin Orders pagination style -->
                        <?php if ($totalTransactionPages > 1): ?>

                            <?php
                            $paginationParams = [
                                'start_date' => $start_date,
                                'end_date' => $end_date,
                                'mode' => $report_mode,
                                'completed_q' => $completed_search,
                                'completed_period' => $completed_period,
                                'completed_month' => $completed_period === 'specific_month'
                                    ? $completed_month
                                    : ''
                            ];

                            $transactionPaginationUrl = static function (int $targetPage) use ($paginationParams): string {
                                $params = $paginationParams;
                                $params['transaction_page'] = $targetPage;
                                return '?' . http_build_query($params);
                            };

                            /*
                             * Keep page 1 and the last page visible, with a
                             * small window around the current page. Gaps use
                             * an ellipsis, matching Admin Orders.
                             */
                            $visibleTransactionPages = [1];

                            for (
                                $paginationCandidate = max(2, $transactionPage - 2);
                                $paginationCandidate <= min(
                                    $totalTransactionPages - 1,
                                    $transactionPage + 2
                                );
                                $paginationCandidate++
                            ) {
                                $visibleTransactionPages[] = $paginationCandidate;
                            }

                            $visibleTransactionPages[] = $totalTransactionPages;
                            $visibleTransactionPages = array_values(
                                array_unique($visibleTransactionPages)
                            );

                            $previousTransactionPage = 0;
                            ?>

                            <div class="transaction-pagination pagination-wrap">

                                <nav aria-label="Completed sales transaction pagination">

                                    <ul class="pagination mb-0">

                                        <li class="page-item <?= $transactionPage <= 1 ? 'disabled' : '' ?>">
                                            <a
                                                class="page-link transaction-page-link <?= $transactionPage <= 1 ? 'disabled' : '' ?>"
                                                href="<?= htmlspecialchars($transactionPaginationUrl(max(1, $transactionPage - 1)), ENT_QUOTES, 'UTF-8') ?>"
                                                data-preserve-scroll="1"
                                                <?= $transactionPage <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>
                                            >
                                                Previous
                                            </a>
                                        </li>

                                        <?php foreach ($visibleTransactionPages as $paginationPage): ?>

                                            <?php if (
                                                $previousTransactionPage > 0
                                                && $paginationPage > $previousTransactionPage + 1
                                            ): ?>
                                                <li class="page-item disabled" aria-hidden="true">
                                                    <span class="page-link">...</span>
                                                </li>
                                            <?php endif; ?>

                                            <li class="page-item <?= $paginationPage === $transactionPage ? 'active' : '' ?>">
                                                <a
                                                    class="page-link transaction-page-link"
                                                    href="<?= htmlspecialchars($transactionPaginationUrl($paginationPage), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-preserve-scroll="1"
                                                    <?= $paginationPage === $transactionPage ? 'aria-current="page"' : '' ?>
                                                >
                                                    <?= $paginationPage ?>
                                                </a>
                                            </li>

                                            <?php $previousTransactionPage = $paginationPage; ?>

                                        <?php endforeach; ?>

                                        <li class="page-item <?= $transactionPage >= $totalTransactionPages ? 'disabled' : '' ?>">
                                            <a
                                                class="page-link transaction-page-link <?= $transactionPage >= $totalTransactionPages ? 'disabled' : '' ?>"
                                                href="<?= htmlspecialchars($transactionPaginationUrl(min($totalTransactionPages, $transactionPage + 1)), ENT_QUOTES, 'UTF-8') ?>"
                                                data-preserve-scroll="1"
                                                <?= $transactionPage >= $totalTransactionPages ? 'aria-disabled="true" tabindex="-1"' : '' ?>
                                            >
                                                Next
                                            </a>
                                        </li>

                                    </ul>

                                </nav>

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

    /*
     * =============================================================
     * TRANSACTION DETAILS MODAL FIX
     * =============================================================
     * The transaction rows are refreshed through AJAX. Their
     * Bootstrap modal buttons therefore need delegated handling.
     *
     * The PHP markup also places each modal beside its <tr> inside
     * the transaction table body. Browsers may relocate those
     * <div> elements differently when parsing/replacing the
     * fragment. Move them to <body> so Bootstrap always receives
     * a valid modal container.
     *
     * This changes no visual layout.
     * =============================================================
     */
    function normalizeTransactionModals(root = document) {
        const modals = root.querySelectorAll(
            '[id^="transactionDetailsModal"]'
        );

        modals.forEach(function (modal) {
            if (modal.parentElement !== document.body) {
                document.body.appendChild(modal);
            }
        });
    }

    function cleanupSalesModalState() {
        document.querySelectorAll('.modal-backdrop').forEach(function (backdrop) {
            backdrop.remove();
        });

        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('padding-right');
        document.body.style.removeProperty('overflow');
    }

    function showTransactionDetailsModal(modal) {
        if (!modal) {
            return;
        }

        if (window.bootstrap && bootstrap.Modal) {
            const instance =
                bootstrap.Modal.getInstance(modal) ||
                bootstrap.Modal.getOrCreateInstance(modal, {
                    backdrop: true,
                    keyboard: true,
                    focus: true
                });

            instance.show();
            return;
        }

        /* Fallback in case Bootstrap JS is unavailable. */
        document.querySelectorAll('.modal.show').forEach(function (openModal) {
            if (openModal !== modal) {
                openModal.classList.remove('show');
                openModal.style.display = 'none';
                openModal.setAttribute('aria-hidden', 'true');
            }
        });

        cleanupSalesModalState();

        modal.classList.add('show');
        modal.style.display = 'block';
        modal.removeAttribute('aria-hidden');
        modal.setAttribute('aria-modal', 'true');
        document.body.classList.add('modal-open');

        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop fade show';
        backdrop.setAttribute('data-sales-report-modal-backdrop', 'true');
        document.body.appendChild(backdrop);
    }

    function hideTransactionDetailsModal(modal) {
        if (!modal) {
            return;
        }

        if (window.bootstrap && bootstrap.Modal) {
            const instance =
                bootstrap.Modal.getInstance(modal) ||
                bootstrap.Modal.getOrCreateInstance(modal);

            instance.hide();
            return;
        }

        modal.classList.remove('show');
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
        modal.removeAttribute('aria-modal');
        cleanupSalesModalState();
    }

    /* Normalize existing modals immediately. */
    normalizeTransactionModals();

    /*
     * Delegated handlers keep working even after the Completed
     * Transactions section is replaced by AJAX.
     */
    document.addEventListener('click', function (event) {
        const viewButton = event.target.closest('.btn-view-transaction');

        if (viewButton) {
            event.preventDefault();
            event.stopPropagation();

            const targetSelector =
                viewButton.getAttribute('data-bs-target');

            if (!targetSelector) {
                return;
            }

            const modal = document.querySelector(targetSelector);

            if (!modal) {
                console.error(
                    'Transaction details modal not found:',
                    targetSelector
                );
                return;
            }

            normalizeTransactionModals();
            showTransactionDetailsModal(modal);
            return;
        }

        const dismissButton = event.target.closest(
            '[data-bs-dismiss="modal"]'
        );

        if (dismissButton) {
            const modal = dismissButton.closest('.modal');

            if (modal && modal.id.indexOf('transactionDetailsModal') === 0) {
                event.preventDefault();
                event.stopPropagation();
                hideTransactionDetailsModal(modal);
            }
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }

        const openTransactionModal = document.querySelector(
            '.modal.show[id^="transactionDetailsModal"]'
        );

        if (openTransactionModal) {
            hideTransactionDetailsModal(openTransactionModal);
        }
    });

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

    function updateCompletedSearchClearButton(searchInput) {
        if (!searchInput) {
            return;
        }

        const clearButton = searchInput
            .closest('.completed-transaction-search-wrap')
            ?.querySelector('[data-completed-transaction-search-clear]');

        if (clearButton) {
            clearButton.hidden = searchInput.value.length === 0;
        }
    }

    updateCompletedSearchClearButton(
        document.querySelector('[data-completed-transaction-search]')
    );

    /* The X clears only the text. It does not search until Enter is pressed. */
    document.addEventListener('input', function (event) {
        const searchInput = event.target.closest(
            '[data-completed-transaction-search]'
        );

        if (searchInput) {
            updateCompletedSearchClearButton(searchInput);
        }
    });

    document.addEventListener('click', function (event) {
        const clearButton = event.target.closest(
            '[data-completed-transaction-search-clear]'
        );

        if (!clearButton) {
            return;
        }

        event.preventDefault();

        const searchInput = clearButton
            .closest('.completed-transaction-search-wrap')
            ?.querySelector('[data-completed-transaction-search]');

        if (!searchInput) {
            return;
        }

        searchInput.value = '';
        updateCompletedSearchClearButton(searchInput);
        searchInput.focus();
    });

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

        /* Use only the last submitted search term. Text currently being
           typed is not applied until the Admin presses Enter. */
        const search =
            overrides.completed_q ??
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

            /*
             * The transaction section was rebuilt by AJAX, so move
             * its newly-created modals back to <body> and let the
             * delegated click handler manage the new buttons.
             */
            normalizeTransactionModals();

            updateCompletedMonthVisibility(
                newSection
            );

            updateCompletedSearchClearButton(
                newSection.querySelector('[data-completed-transaction-search]')
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

    /* Search is manual: typing does nothing until Enter is pressed. */
    document.addEventListener(
        'keydown',
        function (event) {
            if (event.key !== 'Enter' || event.isComposing) {
                return;
            }

            const searchInput = event.target.closest(
                '[data-completed-transaction-search]'
            );

            if (!searchInput) {
                return;
            }

            event.preventDefault();

            const form = searchInput.closest(
                '[data-completed-transaction-filter-form]'
            );

            if (form && typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            }
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