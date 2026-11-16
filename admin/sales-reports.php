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

$startObject = DateTime::createFromFormat('Y-m-d', $start_date);
$endObject   = DateTime::createFromFormat('Y-m-d', $end_date);

if (!$startObject || $startObject->format('Y-m-d') !== $start_date) {
    $start_date = $firstDayOfMonth;
}
if (!$endObject || $endObject->format('Y-m-d') !== $end_date) {
    $end_date = $today;
}
if ($start_date > $today) { $start_date = $today; }
if ($end_date > $today)   { $end_date = $today; }

if ($start_date > $end_date) {
    $temporaryDate = $start_date;
    $start_date = $end_date;
    $end_date = $temporaryDate;
}

/* =========================================================
   REPORT MODE
========================================================= */
$report_mode = $_GET['mode'] ?? null;

$rangeStart = new DateTime($start_date);
$rangeEnd   = new DateTime($end_date);
$rangeDays  = $rangeStart->diff($rangeEnd)->days + 1;

if ($rangeDays <= 10) {
    $availableModes = ['daily', 'weekly', 'monthly'];
    if ($report_mode === null) {
        $report_mode = 'daily';
    }
} elseif ($rangeDays <= 180) {
    $availableModes = ['weekly', 'monthly'];
    if ($report_mode === null || !in_array($report_mode, $availableModes, true)) {
        $report_mode = 'weekly';
    }
} else {
    $availableModes = ['monthly'];
    if ($report_mode === null || !in_array($report_mode, $availableModes, true)) {
        $report_mode = 'monthly';
    }
}

if (!in_array($report_mode, $availableModes, true)) {
    $report_mode = $availableModes[0];
}

$reportGeneratedAt = date('Y-m-d H:i:s');
$reportGeneratedBy = $_SESSION['user_name'] ?? 'Admin User';

/* =========================================================
   TOAST
========================================================= */
$reportToast = null;
$reportToastAction = trim((string)($_GET['report_action'] ?? ''));

$reportToastMap = [
    'daily'   => ['icon' => 'bi-bar-chart-line', 'message' => 'Daily sales report generated successfully!'],
    'weekly'  => ['icon' => 'bi-bar-chart-line', 'message' => 'Weekly sales report generated successfully!'],
    'monthly' => ['icon' => 'bi-bar-chart-line', 'message' => 'Monthly sales report generated successfully!']
];

if ($reportToastAction === '1' && isset($reportToastMap[$report_mode])) {
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

        if (is_array($addon)) {

            $name  = $addon['name'] ?? $addon['addon_name'] ?? $addon['title'] ?? null;
            $price = $addon['price'] ?? $addon['addon_price'] ?? null;

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

        } elseif (!is_int($key) && is_numeric($addon)) {

            $parts[] = (string)$key . ' (+₱' . number_format((float)$addon, 2) . ')';

        } elseif (is_scalar($addon)) {

            $parts[] = (string)$addon;
        }
    }

    $parts = array_values(array_filter(array_map('trim', $parts)));

    return implode(', ', $parts);
}

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
   COMPLETED SALES TRANSACTIONS
========================================================= */
$salesStmt = $pdo->prepare("
    SELECT
        id, order_number, claim_number, customer_name, contact_number,
        pickup_date, pickup_time, payment_method, payment_screenshot,
        subtotal, discount_type, discount_rate, discount_amount,
        total_amount, status, created_at, closed_at
    FROM orders
    WHERE status = 'completed'
      AND DATE(COALESCE(closed_at, created_at)) BETWEEN ? AND ?
    ORDER BY COALESCE(closed_at, created_at) DESC, id DESC
");

$salesStmt->execute([$start_date, $end_date]);
$salesTransactions = $salesStmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================================================
   COMPLETED TRANSACTION FILTERS
========================================================= */
$completed_search = trim((string)($_GET['completed_q'] ?? ''));

if (mb_strlen($completed_search) > 100) {
    $completed_search = mb_substr($completed_search, 0, 100);
}

$completed_period = strtolower(trim((string)($_GET['completed_period'] ?? 'today')));

$valid_completed_periods = ['today', 'last_week', 'last_month', 'specific_month'];

if (!in_array($completed_period, $valid_completed_periods, true)) {
    $completed_period = 'today';
}

$completed_month = trim((string)($_GET['completed_month'] ?? date('Y-m')));

$completedMonthObject = DateTime::createFromFormat('!Y-m', $completed_month);

if ($completedMonthObject === false || $completedMonthObject->format('Y-m') !== $completed_month) {
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
        static function (array $sale) use ($completed_search, $completedFilterStart, $completedFilterEnd): bool {
            $saleTimestamp = strtotime($sale['closed_at'] ?? $sale['created_at'] ?? '');

            if ($saleTimestamp === false) {
                return false;
            }

            $saleDate = date('Y-m-d', $saleTimestamp);

            if ($saleDate < $completedFilterStart || $saleDate > $completedFilterEnd) {
                return false;
            }

            if ($completed_search === '') {
                return true;
            }

            $haystack = mb_strtolower(implode(' ', [
                (string)($sale['order_number'] ?? ''),
                (string)($sale['claim_number'] ?? ''),
                (string)($sale['customer_name'] ?? ''),
                (string)($sale['contact_number'] ?? ''),
                (string)($sale['id'] ?? '')
            ]));

            return mb_strpos($haystack, mb_strtolower($completed_search)) !== false;
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

$averageOrder = $totalOrders > 0 ? $totalSales / $totalOrders : 0;

/* =========================================================
   ANALYTICS
========================================================= */
require_once '../includes/sales-analytics.php';
$analytics = salesAnalytics($pdo, $start_date, $end_date);

/* =========================================================
   PAGINATION
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

$totalTransactionPages = max(1, (int)ceil($completedTransactionCount / $transactionPerPage));

if ($transactionPage > $totalTransactionPages) {
    $transactionPage = $totalTransactionPages;
}

$transactionOffset = ($transactionPage - 1) * $transactionPerPage;
$displayTransactions = array_slice($filteredSalesTransactions, $transactionOffset, $transactionPerPage);

/* =========================================================
   ORDER ITEMS FOR DISPLAYED TRANSACTIONS
========================================================= */
$transactionOrderItems = [];

if (!empty($displayTransactions)) {

    $displayOrderIds = array_map('intval', array_column($displayTransactions, 'id'));
    $placeholders = implode(',', array_fill(0, count($displayOrderIds), '?'));

    $itemsStmt = $pdo->prepare("
        SELECT * FROM order_items
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

if ($report_mode === 'daily') {

    $periodStart = new DateTime($start_date);
    $periodEnd = new DateTime($end_date);

    while ($periodStart <= $periodEnd) {
        $key = $periodStart->format('Y-m-d');
        $salesOverview[$key] = ['label' => $periodStart->format('M d'), 'sales' => 0, 'orders' => 0];
        $periodStart->modify('+1 day');
    }

    foreach ($salesTransactions as $sale) {
        $dateKey = date('Y-m-d', strtotime($sale['closed_at'] ?? $sale['created_at']));

        if (isset($salesOverview[$dateKey])) {
            $salesOverview[$dateKey]['sales'] += (float)$sale['total_amount'];
            $salesOverview[$dateKey]['orders']++;
        }
    }
}

if ($report_mode === 'weekly') {

    $periodStart = clone $rangeStart;
    $periodStart->modify('monday this week');

    $periodEnd = clone $rangeEnd;
    $periodEnd->modify('sunday this week');

    while ($periodStart <= $periodEnd) {

        $key = $periodStart->format('o') . '-W' . $periodStart->format('W');

        $weekEnd = clone $periodStart;
        $weekEnd->modify('+6 days');

        $weeklyLabel = $periodStart->format('M') === $weekEnd->format('M')
            ? $periodStart->format('M d') . '–' . $weekEnd->format('d')
            : $periodStart->format('M d') . '–' . $weekEnd->format('M d');

        $salesOverview[$key] = [
            'label' => $weeklyLabel,
            'sales' => 0,
            'orders' => 0,
            'sort' => $periodStart->format('Y-m-d')
        ];

        $periodStart->modify('+1 week');
    }

    foreach ($salesTransactions as $sale) {
        $saleDt = new DateTime($sale['closed_at'] ?? $sale['created_at']);
        $key = $saleDt->format('o') . '-W' . $saleDt->format('W');

        if (isset($salesOverview[$key])) {
            $salesOverview[$key]['sales'] += (float)$sale['total_amount'];
            $salesOverview[$key]['orders']++;
        }
    }

    uasort($salesOverview, function ($a, $b) {
        return strcmp($a['sort'], $b['sort']);
    });
}

if ($report_mode === 'monthly') {

    $periodStart = new DateTime($rangeStart->format('Y-m-01'));
    $periodEnd = new DateTime($rangeEnd->format('Y-m-01'));

    while ($periodStart <= $periodEnd) {
        $key = $periodStart->format('Y-m');
        $salesOverview[$key] = [
            'label' => $periodStart->format('M Y'),
            'sales' => 0,
            'orders' => 0,
            'sort' => $periodStart->format('Y-m')
        ];
        $periodStart->modify('+1 month');
    }

    foreach ($salesTransactions as $sale) {
        $key = (new DateTime($sale['closed_at'] ?? $sale['created_at']))->format('Y-m');

        if (isset($salesOverview[$key])) {
            $salesOverview[$key]['sales'] += (float)$sale['total_amount'];
            $salesOverview[$key]['orders']++;
        }
    }

    ksort($salesOverview);
}

/* =========================================================
   CHART SCALE + HIGHLIGHTS
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

$chartStep = max(100, (int)(ceil(($maxSales / 4) / 100) * 100));
$chartMax = $chartStep * 4;

$activePeriods = 0;
$bestPeriod = null;

foreach ($salesOverview as $p) {
    if ($p['sales'] > 0) {
        $activePeriods++;
    }
    if ($bestPeriod === null || $p['sales'] > $bestPeriod['sales']) {
        $bestPeriod = $p;
    }
}

$averagePerPeriod = count($salesOverview) > 0 ? $totalSales / count($salesOverview) : 0;
$periodUnitLabel = $report_mode === 'daily' ? 'day' : ($report_mode === 'weekly' ? 'week' : 'month');

/* Many bars: hide the value above each bar (tooltip still shows it). */
$chartIsDense = count($salesOverview) > 10;

$modeLabels = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'];
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
   TOKENS
========================================================= */
:root {
    --sr-ink: #2C221E;
    --sr-brown: #4A3525;
    --sr-accent: #6F4E37;
    --sr-line: #E8DED4;
    --sr-soft: #FBF8F4;
    --sr-cream: #F7F1E8;
    --sr-muted: #8A7F75;
    --sr-green: #2F6E3E;
    --sr-red: #B3261E;
    --sr-blue: #1677FF;
    --sr-radius: 14px;
    --sr-shadow: 0 1px 2px rgba(44,34,30,.05), 0 8px 24px rgba(44,34,30,.05);
}

/* =========================================================
   PAGE SHELL
========================================================= */
html, body { max-width: 100%; overflow-x: hidden; }
body { background: #F7F5F2; }

.sales-page {
    min-height: calc(100vh - 70px);
    min-width: 0;
    width: 100%;
    margin-left: 0;
    box-sizing: border-box;
}

@media (min-width: 992px) {
    .sales-page { margin-left: 0 !important; width: 100% !important; max-width: none !important; }
    .localitea-admin-navbar { margin-left: 260px !important; width: calc(100% - 260px) !important; }
}
@media (max-width: 991.98px) {
    .sales-page { margin-left: 0 !important; width: 100% !important; max-width: 100% !important; }
    .localitea-admin-navbar { margin-left: 0 !important; width: 100% !important; }
}

.sales-content {
    padding: 24px;
    min-width: 0;
    max-width: 100%;
    box-sizing: border-box;
    color: var(--sr-ink);
}

.sr-wrap { max-width: 1240px; margin: 0 auto; }
.sr-stack > * + * { margin-top: 20px; }

/* =========================================================
   PAGE HEADER
========================================================= */
.sr-header {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 18px;
}
.sr-header h2 { margin: 0; color: var(--sr-brown); font-size: 1.5rem; font-weight: 700; }
.sr-header p { margin: 3px 0 0; color: var(--sr-muted); font-size: .85rem; }

/* =========================================================
   GENERIC CARD / SECTION
========================================================= */
.sr-card {
    background: #fff;
    border: 1px solid var(--sr-line);
    border-radius: var(--sr-radius);
    box-shadow: var(--sr-shadow);
    padding: 20px;
    min-width: 0;
}
.sr-card-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 16px;
}
.sr-card-title {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0;
    color: var(--sr-ink);
    font-size: 1rem;
    font-weight: 700;
}
.sr-card-title i { color: var(--sr-accent); }
.sr-sub { color: var(--sr-muted); font-size: .76rem; }

.sr-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 11px;
    border: 1px solid var(--sr-line);
    border-radius: 999px;
    background: var(--sr-soft);
    color: #6F665F;
    font-size: .74rem;
    font-weight: 500;
}

/* =========================================================
   FILTER BAR
========================================================= */
.filter-card { padding: 16px 20px; }

.filter-label {
    display: block;
    margin-bottom: 5px;
    color: var(--sr-muted);
    font-size: .72rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .3px;
}
.filter-input {
    width: 100%;
    height: 40px;
    padding: 6px 12px;
    border: 1px solid #CDB9A8;
    border-radius: 10px;
    background: #fff;
    color: var(--sr-brown);
    font-size: .88rem;
}
.filter-input:focus {
    outline: none;
    border-color: var(--sr-accent);
    box-shadow: 0 0 0 3px rgba(111,78,55,.12);
}
.filter-actions { display: flex; gap: 8px; flex-wrap: wrap; }

.btn-generate, .btn-print {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    height: 40px;
    padding: 0 16px;
    border-radius: 10px;
    font-size: .84rem;
    font-weight: 600;
    white-space: nowrap;
    text-decoration: none;
}
.btn-generate { background: var(--sr-brown); border: 1px solid var(--sr-brown); color: #fff; }
.btn-generate:hover { background: #3d2b20; color: #fff; }
.btn-print { background: #fff; border: 1px solid #CDB9A8; color: var(--sr-brown); }
.btn-print:hover { background: var(--sr-cream); color: var(--sr-brown); border-color: var(--sr-accent); }

/* =========================================================
   KPI CARDS
========================================================= */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
}
.summary-card {
    background: #fff;
    border: 1px solid var(--sr-line);
    border-radius: var(--sr-radius);
    box-shadow: var(--sr-shadow);
    padding: 18px 20px;
    min-width: 0;
}
.summary-label { color: var(--sr-muted); font-size: .76rem; font-weight: 600; text-transform: uppercase; letter-spacing: .3px; }
.summary-value { margin-top: 6px; color: var(--sr-brown); font-size: 1.8rem; font-weight: 700; line-height: 1.1; font-variant-numeric: tabular-nums; }

.sa-delta { margin-top: 6px; font-size: .74rem; font-weight: 600; }
.sa-up { color: #2E7D32; }
.sa-down { color: #C62828; }
.sa-flat { color: var(--sr-muted); font-weight: 500; }

/* Secondary stats: one quiet strip instead of four heavy cards */
.stat-strip {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    padding: 0;
    overflow: hidden;
}
.stat-item { padding: 16px 20px; min-width: 0; border-left: 1px solid var(--sr-line); }
.stat-item:first-child { border-left: 0; }
.stat-item .stat-value { margin-top: 3px; color: var(--sr-ink); font-size: 1.15rem; font-weight: 700; font-variant-numeric: tabular-nums; }

/* =========================================================
   KEY TAKEAWAYS
========================================================= */
.sa-takeaways {
    background: var(--sr-soft);
    border: 1px solid var(--sr-line);
    border-left: 4px solid var(--sr-accent);
    border-radius: var(--sr-radius);
    padding: 14px 18px;
}
.sa-takeaways-toggle {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    padding: 0;
    border: 0;
    background: transparent;
    color: var(--sr-ink);
    font-size: .92rem;
    font-weight: 700;
    text-align: left;
}
.sa-takeaways-toggle i { transition: transform .2s ease; color: var(--sr-accent); }
.sa-takeaways-toggle[aria-expanded="true"] i { transform: rotate(180deg); }
.sa-takeaways ul { margin: 10px 0 0; padding-left: 18px; font-size: .85rem; color: var(--sr-ink); }
.sa-takeaways li { margin-bottom: 5px; line-height: 1.45; }

/* =========================================================
   SALES OVERVIEW (CHART)
========================================================= */
.overview-card { padding: 0; border: 0; background: transparent; }

.overview-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 16px;
}
.overview-total { color: var(--sr-brown); font-size: 1.05rem; font-weight: 700; }

.mode-tabs {
    display: inline-flex;
    gap: 2px;
    padding: 3px;
    border: 1px solid var(--sr-line);
    border-radius: 10px;
    background: var(--sr-soft);
}
.mode-tab {
    display: inline-block;
    padding: 6px 14px;
    border-radius: 8px;
    color: #6F665F;
    font-size: .78rem;
    font-weight: 600;
    text-decoration: none;
}
.mode-tab:hover { color: var(--sr-brown); background: #fff; }
.mode-tab.active { color: #fff; background: var(--sr-brown); }

.sales-chart-area { display: flex; gap: 8px; width: 100%; min-width: 0; height: 300px; }

.sales-chart-yaxis {
    flex: 0 0 54px;
    height: 240px;
    margin-top: 24px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    align-items: flex-end;
    box-sizing: border-box;
}
.sales-chart-yaxis span { color: var(--sr-muted); font-size: .68rem; font-weight: 500; line-height: 1; white-space: nowrap; }

.sales-chart-viewport {
    position: relative;
    flex: 1 1 auto;
    min-width: 0;
    overflow-x: auto;
    overflow-y: hidden;
    scrollbar-width: thin;
    -webkit-overflow-scrolling: touch;
}
.sales-chart-plot { position: relative; min-width: 100%; width: max-content; height: 290px; }

.sales-chart-grid {
    position: absolute;
    left: 0; right: 0; top: 24px;
    height: 240px;
    pointer-events: none;
    background: repeating-linear-gradient(to bottom, transparent 0, transparent calc(25% - 1px), #EFE7DF calc(25% - 1px), #EFE7DF 25%);
    border-bottom: 1px solid #EFE7DF;
}

.chart {
    position: relative;
    display: flex;
    align-items: flex-end;
    gap: 10px;
    height: 290px;
    padding: 0 8px;
    min-width: 100%;
    width: max-content;
    box-sizing: border-box;
}
.chart-column {
    flex: 1 0 56px;
    min-width: 56px;
    height: 290px;
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    align-items: center;
    gap: 6px;
}
.chart-value { min-height: 12px; color: var(--sr-brown); font-size: .68rem; font-weight: 600; line-height: 1; white-space: nowrap; }
.chart-dense .chart-value { visibility: hidden; }
.chart-dense .chart-column:hover .chart-value { visibility: visible; }

.chart-bar-wrap { width: 100%; height: 240px; display: flex; align-items: flex-end; justify-content: center; }
.chart-bar {
    position: relative;
    width: min(40px, 72%);
    min-height: 3px;
    background: #A98467;
    border-radius: 8px 8px 2px 2px;
    transition: height .2s ease, background .15s ease;
}
.chart-bar:hover { background: var(--sr-accent); }
.chart-bar.is-best { background: var(--sr-brown); }

.chart-bar::after {
    content: attr(data-tooltip);
    position: absolute;
    left: 50%;
    bottom: calc(100% + 8px);
    transform: translateX(-50%);
    padding: 7px 10px;
    border-radius: 8px;
    background: var(--sr-ink);
    color: #fff;
    font-size: .72rem;
    line-height: 1.25;
    white-space: nowrap;
    opacity: 0;
    pointer-events: none;
    transition: opacity .15s ease;
    z-index: 50;
}
.chart-bar:hover::after { opacity: 1; }

.chart-label { color: #6F665F; font-size: .68rem; font-weight: 500; text-align: center; line-height: 1.15; min-height: 26px; }
.chart-weekly .chart-column, .chart-monthly .chart-column { flex-basis: 74px; min-width: 74px; }
.chart-orders { display: none; }

.overview-highlights {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
    margin-top: 14px;
}
.overview-highlight { background: var(--sr-soft); border: 1px solid var(--sr-line); border-radius: 10px; padding: 10px 12px; min-width: 0; }
.overview-highlight small { display: block; color: var(--sr-muted); font-size: .66rem; font-weight: 600; text-transform: uppercase; letter-spacing: .25px; margin-bottom: 2px; }
.overview-highlight strong { color: var(--sr-ink); font-size: .88rem; font-weight: 600; overflow-wrap: anywhere; }

.empty-report { text-align: center; padding: 36px 16px; color: var(--sr-muted); }
.empty-report i { display: block; font-size: 2rem; color: #C9B6A5; margin-bottom: 6px; }
.empty-report p { margin: 0; font-size: .85rem; }

/* =========================================================
   ANALYTICS TABS
========================================================= */
.sr-tabs {
    display: flex;
    gap: 6px;
    margin: 0 0 16px;
    padding: 0 0 2px;
    list-style: none;
    overflow-x: auto;
    scrollbar-width: none;
    -webkit-overflow-scrolling: touch;
    border-bottom: 1px solid var(--sr-line);
}
.sr-tabs::-webkit-scrollbar { display: none; }
.sr-tabs button {
    flex: 0 0 auto;
    padding: 9px 14px;
    border: 0;
    border-bottom: 3px solid transparent;
    background: transparent;
    color: var(--sr-muted);
    font-size: .84rem;
    font-weight: 600;
    white-space: nowrap;
}
.sr-tabs button:hover { color: var(--sr-brown); }
.sr-tabs button.active { color: var(--sr-brown); border-bottom-color: var(--sr-brown); }

.sr-panel { height: 100%; padding: 16px 18px; border: 1px solid var(--sr-line); border-radius: 12px; background: #fff; min-width: 0; }
.sr-panel h4 { margin: 0 0 10px; color: var(--sr-ink); font-size: .9rem; font-weight: 700; }
.sa-note { margin-top: 10px; color: var(--sr-muted); font-size: .74rem; line-height: 1.45; }

.sa-table { width: 100%; border-collapse: collapse; font-size: .84rem; }
.sa-table th { padding: 4px 8px 8px 0; border-bottom: 1px solid var(--sr-line); color: var(--sr-muted); font-size: .7rem; font-weight: 600; text-align: left; text-transform: uppercase; letter-spacing: .25px; }
.sa-table td { padding: 9px 8px 9px 0; border-bottom: 1px solid #F4EEE8; color: var(--sr-ink); }
.sa-table tr:last-child td { border-bottom: 0; }
.sa-table th:not(:first-child), .sa-table td:not(:first-child) { text-align: right; white-space: nowrap; padding-right: 0; padding-left: 8px; font-variant-numeric: tabular-nums; }

.sa-bar-track { background: #F4EEE8; border-radius: 6px; height: 8px; overflow: hidden; }
.sa-bar-fill { background: var(--sr-accent); height: 100%; border-radius: 6px; }
.sa-hour-row { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; font-size: .78rem; }
.sa-hour-label { width: 58px; color: var(--sr-muted); flex-shrink: 0; }
.sa-hour-row .sa-bar-track { flex: 1; }
.sa-hour-count { width: 28px; text-align: right; flex-shrink: 0; font-variant-numeric: tabular-nums; }

.sa-kpi-small { font-size: 1.25rem; font-weight: 700; color: var(--sr-ink); }
.sa-kpi-small-label { font-size: .74rem; color: var(--sr-muted); }

/* =========================================================
   COMPLETED TRANSACTIONS
========================================================= */
.completed-transaction-filter-form {
    display: flex;
    align-items: flex-end;
    gap: 10px;
    flex-wrap: wrap;
    margin: 0 0 16px;
    padding: 12px;
    border: 1px solid var(--sr-line);
    border-radius: 12px;
    background: var(--sr-soft);
}
.completed-transaction-filter-group { display: flex; flex-direction: column; gap: 5px; min-width: 0; }
.completed-transaction-filter-group label { color: var(--sr-muted); font-size: .68rem; font-weight: 600; text-transform: uppercase; letter-spacing: .3px; }
.completed-transaction-filter-group input,
.completed-transaction-filter-group select {
    height: 38px;
    padding: 6px 10px;
    border: 1px solid #CDB9A8;
    border-radius: 8px;
    background: #fff;
    color: var(--sr-brown);
    font-size: .84rem;
    box-sizing: border-box;
    outline: none;
}
.completed-transaction-filter-group input:focus,
.completed-transaction-filter-group select:focus { border-color: var(--sr-accent); box-shadow: 0 0 0 3px rgba(111,78,55,.12); }

.completed-transaction-search-wrap { position: relative; width: 300px; max-width: 100%; }
.completed-transaction-filter-search { width: 100%; padding-right: 40px !important; }
.completed-transaction-search-clear {
    position: absolute; top: 50%; right: 7px; transform: translateY(-50%);
    display: inline-flex; align-items: center; justify-content: center;
    width: 26px; height: 26px; padding: 0; border: 0; border-radius: 50%;
    background: transparent; color: #7B6D62; font-size: 21px; line-height: 1; cursor: pointer;
}
.completed-transaction-search-clear:hover { background: #F1E8DF; color: var(--sr-brown); }
.completed-transaction-search-clear[hidden] { display: none !important; }
.completed-transaction-filter-period { width: 170px; }
.completed-transaction-filter-month { width: 160px; }
.completed-transaction-filter-month-wrap { display: none; }
.completed-transactions-ajax-busy { opacity: .58; pointer-events: none; transition: opacity .12s ease; }

.transaction-table { width: 100%; margin: 0; border-collapse: separate; border-spacing: 0; }
.transaction-table th {
    padding: 11px 10px;
    background: var(--sr-soft);
    border-bottom: 1px solid var(--sr-line);
    color: var(--sr-muted);
    font-size: .7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .3px;
    white-space: nowrap;
}
.transaction-table td { padding: 13px 10px; border-bottom: 1px solid #F0E8E0; color: var(--sr-brown); font-size: .84rem; vertical-align: middle; }
.transaction-table tbody tr:hover td { background: #FDFAF6; }

.transaction-col-index { width: 6%; }
.transaction-col-order { width: 30%; }
.transaction-col-payment { width: 16%; }
.transaction-col-amount { width: 14%; }
.transaction-col-date { width: 17%; }
.transaction-col-details { width: 17%; }

.transaction-index-badge {
    display: inline-flex; align-items: center; justify-content: center;
    width: 28px; height: 28px; border-radius: 50%;
    background: var(--sr-cream); color: var(--sr-accent); font-size: .7rem; font-weight: 700;
}
.transaction-order-number { color: var(--sr-ink); font-size: .86rem; font-weight: 700; overflow-wrap: anywhere; }
.transaction-claim-number { margin-top: 2px; color: var(--sr-muted); font-size: .72rem; }
.transaction-customer { display: flex; align-items: center; gap: 5px; margin-top: 4px; color: #6F665F; font-size: .74rem; }
.transaction-customer i { color: var(--sr-accent); }

.transaction-payment-stack { display: inline-flex; flex-direction: column; align-items: flex-start; gap: 5px; }
.payment-badge { display: inline-flex; align-items: center; padding: 5px 10px; border-radius: 7px; font-size: .76rem; font-weight: 600; line-height: 1; white-space: nowrap; }
.payment-badge.gcash { background: #E7F1FF; color: var(--sr-blue); border: 1px solid var(--sr-blue); }
.payment-badge.cash { background: var(--sr-cream); color: var(--sr-accent); border: 1px solid #D8C4B2; }
.transaction-proof-indicator { color: var(--sr-accent); font-size: .66rem; font-weight: 500; white-space: nowrap; }

.transaction-amount-cell { font-weight: 700; white-space: nowrap; font-variant-numeric: tabular-nums; }
.transaction-date, .transaction-time { display: block; color: #6F665F; line-height: 1.25; white-space: nowrap; }
.transaction-date { font-size: .78rem; font-weight: 500; }
.transaction-time { font-size: .7rem; margin-top: 2px; }

.btn-view-transaction {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 7px 12px; border: 1px solid #CDB9A8; border-radius: 9px;
    background: #fff; color: var(--sr-brown); font-size: .74rem; font-weight: 600; white-space: nowrap;
    transition: background-color .15s ease, color .15s ease;
}
.btn-view-transaction:hover { background: var(--sr-brown); border-color: var(--sr-brown); color: #fff; }

.transaction-pagination { display: flex; justify-content: center; margin: 22px 0 0; }
.transaction-pagination .page-link { margin: 0 2px; border-radius: 8px; border-color: var(--sr-line); color: var(--sr-accent); font-size: .8rem; white-space: nowrap; }
.transaction-pagination .page-item.active .page-link { background: var(--sr-accent); border-color: var(--sr-brown); color: #fff; }
.transaction-pagination .page-item.disabled .page-link, .transaction-pagination .page-link.disabled { color: #A79B91; background: #F7F5F2; pointer-events: none; }
.transaction-page-info { margin-top: 10px; color: var(--sr-muted); font-size: .76rem; text-align: center; }

.report-footer-summary { display: flex; justify-content: flex-end; gap: 28px; margin-top: 16px; padding-top: 14px; border-top: 1px solid var(--sr-line); }
.report-footer-summary div { color: var(--sr-muted); font-size: .8rem; }
.report-footer-summary strong { color: var(--sr-brown); font-size: .92rem; }

/* =========================================================
   TOAST
========================================================= */
.sales-report-toast-wrap { position: fixed; top: 88px; right: 24px; z-index: 2000; width: min(420px, calc(100vw - 32px)); pointer-events: none; }
.sales-report-toast {
    position: relative; display: flex; align-items: flex-start; gap: 12px; padding: 13px 14px;
    background: #fff; border: 1px solid var(--sr-line); border-left: 6px solid #4A8B5A; border-radius: 12px;
    box-shadow: 0 10px 28px rgba(44,34,30,.18); color: var(--sr-ink); pointer-events: auto; overflow: hidden;
    animation: srToastIn .22s ease-out;
}
.sales-report-toast-icon { flex: 0 0 30px; width: 30px; height: 30px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; background: #EAF6EE; color: var(--sr-green); }
.sales-report-toast-message { flex: 1; padding-top: 3px; font-size: .9rem; line-height: 1.45; font-weight: 500; }
.sales-report-toast-close { flex: 0 0 auto; border: 0; background: transparent; color: var(--sr-accent); width: 30px; height: 30px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; }
.sales-report-toast-close:hover { background: #F0E6D6; }
.sales-report-toast-progress { position: absolute; left: 0; bottom: 0; height: 3px; width: 100%; background: #4A8B5A; transform-origin: left center; animation: srToastProgress 3.5s linear forwards; }
.sales-report-toast.is-restored { animation: none; }
.sales-report-toast.is-closing { animation: srToastOut .18s ease-in forwards; }
@keyframes srToastIn { from { opacity: 0; transform: translate(8px, -8px); } to { opacity: 1; transform: none; } }
@keyframes srToastOut { from { opacity: 1; } to { opacity: 0; transform: translate(8px, -6px); } }
@keyframes srToastProgress { from { transform: scaleX(1); } to { transform: scaleX(0); } }

/* =========================================================
   TRANSACTION DETAILS MODAL
========================================================= */
.transaction-details-dialog { --bs-modal-width: 920px; max-width: 920px; }
.transaction-detail-modal { border: 0; border-radius: 18px; overflow: hidden; background: #fff; box-shadow: 0 18px 55px rgba(44,34,30,.24); }

.txd-header { display: flex; align-items: center; gap: 12px; padding: 16px 20px; border-bottom: 1px solid var(--sr-line); background: #FFFCF9; }
.txd-header .btn-close { margin: 0 0 0 4px; }
.txd-header-icon { display: inline-flex; align-items: center; justify-content: center; flex: 0 0 auto; width: 40px; height: 40px; border-radius: 12px; background: var(--sr-brown); color: #fff; font-size: 1.05rem; }
.txd-header-text { flex: 1 1 auto; min-width: 0; }
.transaction-detail-modal .txd-header .modal-title { margin: 0; color: var(--sr-brown); font-size: 1.02rem; font-weight: 700; line-height: 1.2; }
.txd-header-sub { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; margin-top: 3px; color: var(--sr-muted); font-size: .76rem; font-weight: 500; }
.txd-sep { display: inline-block; width: 3px; height: 3px; border-radius: 50%; background: #C9BBAE; }
.txd-status { display: inline-flex; align-items: center; gap: 5px; flex: 0 0 auto; padding: 6px 11px; border: 1px solid #B9DCC4; border-radius: 999px; background: #EAF6EE; color: var(--sr-green); font-size: .72rem; font-weight: 700; line-height: 1; }

.txd-body { padding: 18px 20px 20px; background: #FAF7F3; }

.txd-hero { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 16px; padding: 16px 20px; border-radius: 14px; background: var(--sr-brown); color: #fff; }
.txd-hero-label { color: rgba(255,255,255,.7); font-size: .7rem; font-weight: 600; text-transform: uppercase; letter-spacing: .4px; }
.txd-hero-amount { margin-top: 2px; font-size: 1.85rem; font-weight: 700; letter-spacing: -.4px; line-height: 1.1; font-variant-numeric: tabular-nums; }
.txd-hero-side { display: flex; flex-direction: column; align-items: flex-end; gap: 6px; }
.txd-hero-meta { color: rgba(255,255,255,.72); font-size: .74rem; }
.txd-pay { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 999px; font-size: .76rem; font-weight: 700; line-height: 1; }
.txd-pay.is-cash { background: var(--sr-cream); color: var(--sr-accent); }
.txd-pay.is-gcash { background: #E7F1FF; color: var(--sr-blue); }

.txd-columns { display: grid; grid-template-columns: minmax(0, 5fr) minmax(0, 7fr); gap: 14px; align-items: start; }
.txd-col { display: flex; flex-direction: column; gap: 14px; min-width: 0; }
.txd-card { padding: 14px 16px; border: 1px solid var(--sr-line); border-radius: 14px; background: #fff; }
.txd-card-title { display: flex; align-items: center; gap: 7px; margin: 0 0 12px; color: var(--sr-brown); font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; }
.txd-card-title i { color: var(--sr-accent); font-size: .9rem; }
.txd-count { margin-left: auto; min-width: 22px; padding: 2px 7px; border-radius: 999px; background: var(--sr-cream); color: var(--sr-accent); font-size: .68rem; text-align: center; letter-spacing: 0; }
.txd-muted { color: var(--sr-muted); font-size: .8rem; }

.txd-customer { display: flex; align-items: center; gap: 12px; }
.txd-avatar { display: inline-flex; align-items: center; justify-content: center; flex: 0 0 auto; width: 42px; height: 42px; border-radius: 50%; background: var(--sr-cream); border: 1px solid #D9C5B4; color: var(--sr-accent); font-weight: 700; }
.txd-customer-text { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.txd-customer-text strong { color: var(--sr-ink); font-size: .92rem; overflow-wrap: anywhere; }
.txd-customer-text a { color: var(--sr-accent); font-size: .8rem; text-decoration: none; }

.txd-kv { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin: 0; }
.txd-kv dt { color: var(--sr-muted); font-size: .68rem; font-weight: 600; text-transform: uppercase; letter-spacing: .3px; }
.txd-kv dd { margin: 2px 0 0; color: var(--sr-ink); font-size: .86rem; font-weight: 600; }

.txd-timeline { position: relative; margin: 0; padding: 0; list-style: none; }
.txd-timeline li { position: relative; display: flex; gap: 12px; padding-bottom: 14px; }
.txd-timeline li:last-child { padding-bottom: 0; }
.txd-timeline li::before { content: ""; position: absolute; left: 5px; top: 14px; bottom: -2px; width: 2px; background: var(--sr-line); }
.txd-timeline li:last-child::before { display: none; }
.txd-tl-dot { position: relative; z-index: 1; flex: 0 0 auto; width: 12px; height: 12px; margin-top: 3px; border-radius: 50%; border: 2px solid #CDB9A8; background: #fff; }
.txd-timeline li.is-done .txd-tl-dot { border-color: var(--sr-green); background: var(--sr-green); }
.txd-timeline strong { display: block; color: var(--sr-ink); font-size: .82rem; line-height: 1.2; }
.txd-timeline small { display: block; margin-top: 2px; color: var(--sr-muted); font-size: .74rem; }
.txd-duration { display: inline-flex; align-items: center; gap: 6px; margin-top: 12px; padding: 5px 10px; border-radius: 999px; background: var(--sr-cream); color: var(--sr-accent); font-size: .72rem; font-weight: 600; }

.txd-items { margin: 0; padding: 0; list-style: none; }
.txd-item { display: grid; grid-template-columns: auto minmax(0, 1fr) auto; gap: 12px; padding: 12px 0; border-bottom: 1px dashed var(--sr-line); }
.txd-item:first-child { padding-top: 0; }
.txd-item:last-child { padding-bottom: 0; border-bottom: 0; }
.txd-item-qty { display: inline-flex; align-items: center; justify-content: center; min-width: 34px; height: 26px; padding: 0 7px; border-radius: 8px; background: var(--sr-cream); color: var(--sr-accent); font-size: .78rem; font-weight: 700; }
.txd-item-name { color: var(--sr-ink); font-size: .9rem; font-weight: 600; line-height: 1.3; overflow-wrap: anywhere; }
.txd-free { display: inline-block; margin-left: 6px; padding: 2px 7px; border-radius: 999px; background: #EAF6EE; color: var(--sr-green); font-size: .6rem; font-weight: 700; letter-spacing: .4px; vertical-align: middle; }
.txd-item-unit { margin-top: 2px; color: var(--sr-muted); font-size: .74rem; }
.txd-chips { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 8px; }
.txd-chip { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border: 1px solid var(--sr-line); border-radius: 999px; background: var(--sr-soft); color: var(--sr-accent); font-size: .7rem; font-weight: 500; line-height: 1.3; }
.txd-chip i { font-size: .7rem; }
.txd-chip-addon { background: #FFF7E8; border-color: #F0DDB8; color: #8A5A12; }
.txd-cups { margin: 8px 0 0; padding: 8px 10px; list-style: none; border-radius: 8px; background: var(--sr-soft); color: var(--sr-accent); font-size: .74rem; }
.txd-cups li + li { margin-top: 3px; }
.txd-item-price { color: var(--sr-ink); font-size: .9rem; font-weight: 700; white-space: nowrap; font-variant-numeric: tabular-nums; }
.txd-item-price.is-free { color: #A39A91; text-decoration: line-through; font-weight: 500; }

.txd-summary { background: #FFFCF9; }
.txd-line { display: flex; justify-content: space-between; gap: 12px; padding: 5px 0; color: #6F665F; font-size: .82rem; font-variant-numeric: tabular-nums; }
.txd-line.is-discount { color: var(--sr-green); }
.txd-line.txd-total { margin-top: 6px; padding-top: 12px; border-top: 1px solid var(--sr-line); color: var(--sr-ink); font-size: .9rem; font-weight: 600; }
.txd-line.txd-total strong { color: var(--sr-brown); font-size: 1.2rem; font-weight: 700; }

.txd-proof { position: relative; display: block; overflow: hidden; border: 1px solid var(--sr-line); border-radius: 12px; background: var(--sr-soft); text-align: center; }
.txd-proof img { display: block; max-width: 100%; max-height: 320px; margin: 0 auto; object-fit: contain; }
.txd-proof-hint { position: absolute; right: 8px; bottom: 8px; display: inline-flex; align-items: center; gap: 5px; padding: 4px 9px; border-radius: 999px; background: rgba(44,34,30,.78); color: #fff; font-size: .68rem; opacity: 0; transition: opacity .15s ease; }
.txd-proof:hover .txd-proof-hint, .txd-proof:focus-visible .txd-proof-hint { opacity: 1; }

.transaction-detail-modal .modal-footer { justify-content: flex-end; padding: 12px 18px; border-top: 1px solid var(--sr-line); background: #FFFCF9; }
.transaction-detail-modal .modal-footer .btn-secondary { min-width: 76px; border: 1px solid #BFAE9F; border-radius: 8px; background: #fff; color: var(--sr-brown); font-size: .8rem; font-weight: 600; }
.transaction-detail-modal .modal-footer .btn-secondary:hover { background: var(--sr-cream); border-color: #8B6A55; }

/* =========================================================
   TABLET  (<= 991px)
========================================================= */
@media (max-width: 991.98px) {
    .sales-content { padding: 20px 16px; }
    .kpi-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
    .summary-value { font-size: 1.45rem; }
    .stat-strip { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .stat-item:nth-child(3) { border-left: 0; }
    .stat-item:nth-child(n+3) { border-top: 1px solid var(--sr-line); }
    .transaction-col-index { width: 7%; }
}

/* =========================================================
   MOBILE  (<= 767px)
========================================================= */
@media (max-width: 767.98px) {
    .sales-content { padding: 14px 12px 28px; }
    .sr-stack > * + * { margin-top: 14px; }
    .sr-card { padding: 14px; border-radius: 12px; }
    .sr-header h2 { font-size: 1.25rem; }

    .filter-actions { flex-direction: column; }
    .filter-actions .btn { width: 100%; }

    .kpi-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
    .kpi-grid .summary-card:first-child { grid-column: 1 / -1; }
    .summary-card { padding: 14px; }
    .summary-value { font-size: 1.3rem; }
    .stat-item { padding: 12px 14px; }
    .stat-item .stat-value { font-size: 1rem; }

    .overview-head { flex-direction: column; align-items: flex-start; }
    .mode-tabs { width: 100%; }
    .mode-tab { flex: 1; text-align: center; }
    .sales-chart-area { height: 270px; }
    .sales-chart-yaxis { flex-basis: 46px; height: 220px; margin-top: 22px; }
    .sales-chart-yaxis span { font-size: .62rem; }
    .sales-chart-plot, .chart, .chart-column { height: 270px; }
    .sales-chart-grid { top: 22px; height: 220px; }
    .chart-bar-wrap { height: 220px; }
    .chart-column { flex-basis: 50px; min-width: 50px; }
    .chart-weekly .chart-column, .chart-monthly .chart-column { flex-basis: 66px; min-width: 66px; }
    .overview-highlights { grid-template-columns: 1fr; }

    .sr-panel { padding: 14px; }

    .completed-transaction-filter-form { flex-direction: column; align-items: stretch; }
    .completed-transaction-filter-group,
    .completed-transaction-search-wrap,
    .completed-transaction-filter-search,
    .completed-transaction-filter-period,
    .completed-transaction-filter-month { width: 100%; max-width: 100%; }

    /* Transactions: table rows become compact cards */
    .transaction-report-card .table-responsive { overflow: visible !important; }
    .transaction-table, .transaction-table tbody { display: block; width: 100%; }
    .transaction-table thead { display: none; }

    .transaction-table tbody tr {
        display: grid;
        grid-template-columns: 28px minmax(0, 1fr) auto;
        grid-template-areas:
            "idx order  date"
            "idx amount details"
            "idx pay    details";
        column-gap: 10px;
        row-gap: 4px;
        align-items: center;
        margin: 0 0 10px;
        padding: 12px;
        background: #fff;
        border: 1px solid var(--sr-line);
        border-radius: 12px;
        box-shadow: 0 2px 6px rgba(57,39,27,.04);
    }
    .transaction-table tbody td { display: block; padding: 0; border: 0; background: transparent !important; text-align: left; }
    .transaction-table tbody tr:hover td { background: transparent; }

    .transaction-index-cell { grid-area: idx; align-self: start; }
    .transaction-index-badge { width: 24px; height: 24px; font-size: .64rem; }
    .transaction-order-cell { grid-area: order; align-self: start; }
    .transaction-order-number { font-size: .84rem; }
    .transaction-amount-cell { grid-area: amount; font-size: 1rem; padding-top: 4px !important; }
    .transaction-payment-cell { grid-area: pay; }
    .payment-badge { padding: 4px 8px; font-size: .68rem; }
    .transaction-date-cell { grid-area: date; align-self: start; text-align: right !important; }
    .transaction-date { font-size: .7rem; }
    .transaction-time { font-size: .64rem; }
    .transaction-details-cell { grid-area: details; display: flex !important; justify-content: flex-end; align-items: flex-end; align-self: end; }
    .btn-view-transaction { padding: 7px 10px; font-size: .68rem; }

    .transaction-pagination { overflow-x: auto; justify-content: flex-start; padding-bottom: 4px; }
    .transaction-pagination nav { margin: 0 auto; }
    .transaction-pagination .pagination { flex-wrap: nowrap; white-space: nowrap; }

    .report-footer-summary { flex-direction: column; gap: 6px; }

    .sales-report-toast-wrap { top: 74px; right: 14px; width: min(420px, calc(100vw - 28px)); }

    .txd-columns { grid-template-columns: 1fr; }
    .txd-header { flex-wrap: wrap; padding: 14px 16px; }
    .txd-status { order: 3; }
    .txd-body { padding: 14px; }
    .txd-hero { padding: 14px 16px; }
    .txd-hero-amount { font-size: 1.55rem; }
    .txd-hero-side { align-items: flex-start; }
}

@media (max-width: 575.98px) {
    .txd-item { grid-template-columns: auto minmax(0, 1fr); }
    .txd-item-price { grid-column: 2; justify-self: start; }
}

@media (max-width: 359.98px) {
    .kpi-grid { grid-template-columns: 1fr; }
    .stat-strip { grid-template-columns: 1fr; }
    .stat-item { border-left: 0; border-top: 1px solid var(--sr-line); }
    .stat-item:first-child { border-top: 0; }
}

/* =========================================================
   PRINT
========================================================= */
@media print {
    body { background: #fff !important; }
    .no-print, .transaction-pagination, .transaction-page-info, .sidebar, .filter-card, .mode-tabs, .sr-tabs { display: none !important; }
    .sales-content { padding: 0; }
    .sr-card, .summary-card { box-shadow: none !important; }
    .sr-tab-content > .tab-pane { display: block !important; opacity: 1 !important; margin-bottom: 14px; }
    .sa-takeaways .collapse { display: block !important; }
}

</style>


<div class="sales-page">

    <?php require_once 'sidebar.php'; ?>
    <?php require_once 'navbar.php'; ?>

    <main class="admin-main sales-content">
    <div class="sr-wrap">

        <!-- PAGE HEADER -->
        <div class="sr-header">
            <div>
                <h2>Sales Reports</h2>
                <p>
                    Local Milktea House &bull;
                    <?= date('M d, Y', strtotime($start_date)) ?> – <?= date('M d, Y', strtotime($end_date)) ?>
                </p>
            </div>
        </div>

        <!-- FILTERS -->
        <div class="sr-card filter-card no-print">
            <form method="GET" action="sales-reports.php" id="salesReportFilterForm">

                <input type="hidden" name="mode" value="<?= htmlspecialchars($report_mode) ?>">
                <input type="hidden" name="report_action" value="1">

                <div class="row g-3 align-items-end">

                    <div class="col-6 col-md-3">
                        <label class="filter-label" for="srStartDate">Start date</label>
                        <input type="date" id="srStartDate" name="start_date" class="filter-input"
                               value="<?= htmlspecialchars($start_date) ?>"
                               max="<?= htmlspecialchars($today) ?>" required>
                    </div>

                    <div class="col-6 col-md-3">
                        <label class="filter-label" for="srEndDate">End date</label>
                        <input type="date" id="srEndDate" name="end_date" class="filter-input"
                               value="<?= htmlspecialchars($end_date) ?>"
                               min="<?= htmlspecialchars($start_date) ?>" required>
                    </div>

                    <div class="col-12 col-md-6">
                        <div class="filter-actions justify-content-md-end">
                            <button type="submit" class="btn btn-generate">
                                <i class="bi bi-bar-chart-line"></i> Generate Report
                            </button>
                            <a href="print-sales-report.php?start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>"
                               target="_blank" class="btn btn-print" id="printSalesReportBtn">
                                <i class="bi bi-printer"></i> Print Report
                            </a>
                        </div>
                    </div>

                </div>
            </form>
        </div>


        <!-- PRINTABLE REPORT -->
        <section class="sr-stack mt-3" id="printableReport">

            <!-- KPI CARDS -->
            <div class="kpi-grid">

                <div class="summary-card">
                    <div class="summary-label">Total Sales</div>
                    <div class="summary-value">₱<?= number_format($totalSales, 2) ?></div>
                    <?php if ($analytics): ?>
                        <?= saDeltaBadge(saPercentChange($analytics['current']['net'], $analytics['previous']['net'])) ?>
                    <?php endif; ?>
                </div>

                <div class="summary-card">
                    <div class="summary-label">Completed Orders</div>
                    <div class="summary-value"><?= number_format($totalOrders) ?></div>
                    <?php if ($analytics): ?>
                        <?= saDeltaBadge(saPercentChange((float)$analytics['current']['orders'], (float)$analytics['previous']['orders'])) ?>
                    <?php endif; ?>
                </div>

                <div class="summary-card">
                    <div class="summary-label">Average Order</div>
                    <div class="summary-value">₱<?= number_format($averageOrder, 2) ?></div>
                    <?php if ($analytics): ?>
                        <?= saDeltaBadge(saPercentChange($analytics['current']['avg'], $analytics['previous']['avg'])) ?>
                    <?php endif; ?>
                </div>

            </div>

            <?php if ($analytics): ?>
            <?php
            $saPeso = static fn($v) => '₱' . number_format((float)$v, 2);
            $saCur  = $analytics['current'];
            $saCh   = $analytics['channel'];
            $saPay  = $analytics['payment'];
            $saOut  = $analytics['outcomes'];
            $saRef  = $analytics['refunds'];
            $saChTotal  = $saCh['online']['sales'] + $saCh['walk_in']['sales'];
            $saPayTotal = $saPay['cash']['sales'] + $saPay['gcash']['sales'];
            $saMaxHourOrders = 1;
            foreach ($analytics['hours'] as $saH) {
                $saMaxHourOrders = max($saMaxHourOrders, (int)$saH['orders']);
            }
            ?>

            <!-- MONEY IN / OUT STRIP -->
            <div class="sr-card stat-strip">
                <div class="stat-item">
                    <div class="summary-label">Gross sales</div>
                    <div class="stat-value"><?= $saPeso($saCur['gross']) ?></div>
                    <div class="sa-delta sa-flat">Before discounts</div>
                </div>
                <div class="stat-item">
                    <div class="summary-label">Discounts</div>
                    <div class="stat-value"><?= $saPeso($saCur['discounts']) ?></div>
                    <div class="sa-delta sa-flat"><?= number_format(saShare($saCur['discounts'], $saCur['gross']), 1) ?>% of gross</div>
                </div>
                <div class="stat-item">
                    <div class="summary-label">Cancelled</div>
                    <div class="stat-value"><?= number_format($saOut['cancelled']) ?> <span class="sr-sub">orders</span></div>
                    <div class="sa-delta sa-flat"><?= $saPeso($saOut['cancelled_amount']) ?> lost &middot; <?= number_format($saOut['cancel_rate'], 1) ?>%</div>
                </div>
                <div class="stat-item">
                    <div class="summary-label">Refunds paid</div>
                    <div class="stat-value"><?= $saPeso($saRef['refunded_amount']) ?></div>
                    <div class="sa-delta sa-flat"><?= number_format($saRef['pending_orders']) ?> pending (<?= $saPeso($saRef['pending_amount']) ?>)</div>
                </div>
            </div>

            <!-- KEY TAKEAWAYS -->
            <?php if (!empty($analytics['insights'])): ?>
                <div class="sa-takeaways">
                    <button class="sa-takeaways-toggle no-print-toggle" type="button"
                            data-bs-toggle="collapse" data-bs-target="#saTakeawaysBody"
                            aria-expanded="true" aria-controls="saTakeawaysBody">
                        <span><i class="bi bi-lightbulb me-1"></i> Key takeaways</span>
                        <i class="bi bi-chevron-down"></i>
                    </button>
                    <div class="collapse show" id="saTakeawaysBody">
                        <ul>
                            <?php foreach ($analytics['insights'] as $saLine): ?>
                                <li><?= htmlspecialchars($saLine) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>
            <?php endif; ?>


            <!-- SALES OVERVIEW -->
            <div class="sr-card">
            <div class="overview-card">

                <div class="overview-head">
                    <div>
                        <h3 class="sr-card-title"><i class="bi bi-graph-up"></i> Sales Overview</h3>
                        <div class="overview-total mt-1">₱<?= number_format($totalSales, 2) ?></div>
                    </div>

                    <div class="mode-tabs no-print">
                        <?php foreach ($availableModes as $modeOption): ?>
                            <a href="<?= htmlspecialchars(
                                    'sales-reports.php?' . http_build_query(array_filter([
                                        'start_date' => $start_date,
                                        'end_date' => $end_date,
                                        'mode' => $modeOption,
                                        'completed_q' => $completed_search,
                                        'completed_period' => $completed_period,
                                        'completed_month' => $completed_period === 'specific_month' ? $completed_month : '',
                                        'transaction_page' => $transactionPage
                                    ], static fn($value) => $value !== '' && $value !== null))
                                ) ?>"
                               class="mode-tab <?= $report_mode === $modeOption ? 'active' : '' ?>"
                               data-preserve-scroll="1">
                                <?= ucfirst($modeOption) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if (empty($salesOverview)): ?>

                    <div class="empty-report">
                        <i class="bi bi-bar-chart"></i>
                        <p>No completed sales transactions were recorded within the selected period.</p>
                    </div>

                <?php else: ?>

                    <div class="sales-chart-area">

                        <div class="sales-chart-yaxis" aria-hidden="true">
                            <span>₱<?= number_format($chartMax, 0) ?></span>
                            <span>₱<?= number_format($chartStep * 3, 0) ?></span>
                            <span>₱<?= number_format($chartStep * 2, 0) ?></span>
                            <span>₱<?= number_format($chartStep, 0) ?></span>
                            <span>₱0</span>
                        </div>

                        <div class="sales-chart-viewport">
                            <div class="sales-chart-plot">

                                <div class="sales-chart-grid" aria-hidden="true"></div>

                                <div class="chart <?= $report_mode === 'weekly' ? 'chart-weekly' : ($report_mode === 'monthly' ? 'chart-monthly' : '') ?> <?= $chartIsDense ? 'chart-dense' : '' ?>">

                                    <?php foreach ($salesOverview as $period): ?>

                                        <?php
                                        $heightPercentage = ($period['sales'] / $chartMax) * 100;

                                        if ($period['sales'] > 0 && $heightPercentage < 3) {
                                            $heightPercentage = 3;
                                        }

                                        $isBest = $bestPeriod
                                            && $period['sales'] > 0
                                            && $period['sales'] === $bestPeriod['sales'];

                                        $tooltipText = $period['label']
                                            . ' • ₱' . number_format($period['sales'], 2)
                                            . ' • ' . (int)$period['orders']
                                            . ' order' . ((int)$period['orders'] === 1 ? '' : 's');
                                        ?>

                                        <div class="chart-column">
                                            <div class="chart-value">₱<?= number_format($period['sales'], 0) ?></div>

                                            <div class="chart-bar-wrap">
                                                <div class="chart-bar <?= $isBest ? 'is-best' : '' ?>"
                                                     style="height: <?= $heightPercentage ?>%;"
                                                     data-tooltip="<?= htmlspecialchars($tooltipText, ENT_QUOTES, 'UTF-8') ?>"
                                                     aria-label="<?= htmlspecialchars($tooltipText, ENT_QUOTES, 'UTF-8') ?>"></div>
                                            </div>

                                            <div class="chart-label"><?= htmlspecialchars($period['label']) ?></div>
                                            <div class="chart-orders"><?= (int)$period['orders'] ?> order<?= (int)$period['orders'] === 1 ? '' : 's' ?></div>
                                        </div>

                                    <?php endforeach; ?>

                                </div>
                            </div>
                        </div>

                    </div>

                    <div class="overview-highlights">
                        <div class="overview-highlight">
                            <small>Best <?= $periodUnitLabel ?></small>
                            <strong><?= $bestPeriod && $bestPeriod['sales'] > 0
                                ? htmlspecialchars($bestPeriod['label']) . ' • ₱' . number_format($bestPeriod['sales'], 2)
                                : '—' ?></strong>
                        </div>
                        <div class="overview-highlight">
                            <small>Average per <?= $periodUnitLabel ?></small>
                            <strong>₱<?= number_format($averagePerPeriod, 2) ?></strong>
                        </div>
                        <div class="overview-highlight">
                            <small>Active <?= $periodUnitLabel ?>s</small>
                            <strong><?= $activePeriods ?> of <?= count($salesOverview) ?></strong>
                        </div>
                    </div>

                <?php endif; ?>

            </div>
            </div>


            <!-- DETAILED BREAKDOWN (TABS) -->
            <?php if ($analytics): ?>
            <div class="sr-card">

                <div class="sr-card-head">
                    <h3 class="sr-card-title"><i class="bi bi-pie-chart"></i> Detailed Breakdown</h3>
                </div>

                <ul class="sr-tabs no-print" role="tablist">
                    <li role="presentation"><button type="button" class="active" data-bs-toggle="tab" data-bs-target="#saTabSales" role="tab">Sales</button></li>
                    <li role="presentation"><button type="button" data-bs-toggle="tab" data-bs-target="#saTabProducts" role="tab">Products</button></li>
                    <li role="presentation"><button type="button" data-bs-toggle="tab" data-bs-target="#saTabTiming" role="tab">Timing</button></li>
                    <li role="presentation"><button type="button" data-bs-toggle="tab" data-bs-target="#saTabOrders" role="tab">Orders &amp; Customers</button></li>
                </ul>

                <div class="tab-content sr-tab-content">

                    <!-- SALES: channel, payment, discounts -->
                    <div class="tab-pane fade show active" id="saTabSales" role="tabpanel">
                        <div class="row g-3">

                            <div class="col-12 col-lg-6">
                                <div class="sr-panel">
                                    <h4>By order channel</h4>
                                    <table class="sa-table">
                                        <thead><tr><th>Channel</th><th>Orders</th><th>Sales</th><th>Share</th></tr></thead>
                                        <tbody>
                                            <tr><td>Online ordering</td><td><?= number_format($saCh['online']['orders']) ?></td><td><?= $saPeso($saCh['online']['sales']) ?></td><td><?= number_format(saShare($saCh['online']['sales'], $saChTotal), 1) ?>%</td></tr>
                                            <tr><td>Walk-in</td><td><?= number_format($saCh['walk_in']['orders']) ?></td><td><?= $saPeso($saCh['walk_in']['sales']) ?></td><td><?= number_format(saShare($saCh['walk_in']['sales'], $saChTotal), 1) ?>%</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="col-12 col-lg-6">
                                <div class="sr-panel">
                                    <h4>By payment method</h4>
                                    <table class="sa-table">
                                        <thead><tr><th>Method</th><th>Orders</th><th>Sales</th><th>Share</th></tr></thead>
                                        <tbody>
                                            <tr><td>Cash</td><td><?= number_format($saPay['cash']['orders']) ?></td><td><?= $saPeso($saPay['cash']['sales']) ?></td><td><?= number_format(saShare($saPay['cash']['sales'], $saPayTotal), 1) ?>%</td></tr>
                                            <tr><td>GCash</td><td><?= number_format($saPay['gcash']['orders']) ?></td><td><?= $saPeso($saPay['gcash']['sales']) ?></td><td><?= number_format(saShare($saPay['gcash']['sales'], $saPayTotal), 1) ?>%</td></tr>
                                        </tbody>
                                    </table>
                                    <div class="sa-note">Cash should match the counter; GCash should match your GCash records.</div>
                                </div>
                            </div>

                            <div class="col-12">
                                <div class="sr-panel">
                                    <h4>Discounts &amp; promotions</h4>
                                    <table class="sa-table">
                                        <thead><tr><th>Type</th><th>Orders</th><th>Amount</th></tr></thead>
                                        <tbody>
                                            <tr><td>PWD discount</td><td><?= number_format($analytics['idDiscounts']['pwd']['orders']) ?></td><td><?= $saPeso($analytics['idDiscounts']['pwd']['amount']) ?></td></tr>
                                            <tr><td>Senior citizen discount</td><td><?= number_format($analytics['idDiscounts']['senior']['orders']) ?></td><td><?= $saPeso($analytics['idDiscounts']['senior']['amount']) ?></td></tr>
                                            <?php foreach ($analytics['promotions'] as $saPr): ?>
                                                <tr><td>Promo: <?= htmlspecialchars((string)$saPr['title']) ?></td><td><?= number_format((int)$saPr['used']) ?></td><td><?= $saPeso($saPr['amount']) ?></td></tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                        </div>
                    </div>

                    <!-- PRODUCTS -->
                    <div class="tab-pane fade" id="saTabProducts" role="tabpanel">
                        <div class="row g-3">

                            <div class="col-12 col-lg-6">
                                <div class="sr-panel">
                                    <h4>Top 10 products</h4>
                                    <?php if (empty($analytics['topProducts'])): ?>
                                        <div class="sa-note">No items sold in this period.</div>
                                    <?php else: ?>
                                        <table class="sa-table">
                                            <thead><tr><th>Product</th><th>Qty</th><th>Item sales</th></tr></thead>
                                            <tbody>
                                                <?php foreach ($analytics['topProducts'] as $saP): ?>
                                                    <tr><td><?= htmlspecialchars((string)$saP['name']) ?></td><td><?= number_format((int)$saP['qty']) ?></td><td><?= $saPeso($saP['sales']) ?></td></tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="col-12 col-lg-6">
                                <div class="sr-panel">
                                    <h4>By category</h4>
                                    <?php if (empty($analytics['categories'])): ?>
                                        <div class="sa-note">No items sold in this period.</div>
                                    <?php else: ?>
                                        <table class="sa-table">
                                            <thead><tr><th>Category</th><th>Qty</th><th>Item sales</th></tr></thead>
                                            <tbody>
                                                <?php foreach ($analytics['categories'] as $saC): ?>
                                                    <tr><td><?= htmlspecialchars((string)$saC['category_name']) ?></td><td><?= number_format((int)$saC['qty']) ?></td><td><?= $saPeso($saC['sales']) ?></td></tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                        <div class="sa-note">Item sales are before order-level discounts, so they won't add up exactly to Net Sales.</div>
                                    <?php endif; ?>
                                </div>
                            </div>

                        </div>
                    </div>

                    <!-- TIMING -->
                    <div class="tab-pane fade" id="saTabTiming" role="tabpanel">
                        <div class="row g-3">

                            <div class="col-12 col-lg-6">
                                <div class="sr-panel">
                                    <h4>Peak pickup hours</h4>
                                    <?php if (empty($analytics['hours'])): ?>
                                        <div class="sa-note">No orders in this period.</div>
                                    <?php else: ?>
                                        <?php foreach ($analytics['hours'] as $saH): ?>
                                            <?php $saHr = (int)$saH['hr']; ?>
                                            <div class="sa-hour-row">
                                                <span class="sa-hour-label"><?= date('g A', strtotime(sprintf('%02d:00', $saHr))) ?></span>
                                                <div class="sa-bar-track"><div class="sa-bar-fill" style="width:<?= round(((int)$saH['orders'] / $saMaxHourOrders) * 100) ?>%"></div></div>
                                                <span class="sa-hour-count"><?= (int)$saH['orders'] ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                        <div class="sa-note">Based on pickup time of completed orders. Use it to schedule staff and prep ingredients.</div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="col-12 col-lg-6">
                                <div class="sr-panel">
                                    <h4>By day of the week</h4>
                                    <?php if (empty($analytics['weekdays'])): ?>
                                        <div class="sa-note">No sales in this period.</div>
                                    <?php else: ?>
                                        <table class="sa-table">
                                            <thead><tr><th>Day</th><th>Orders</th><th>Sales</th></tr></thead>
                                            <tbody>
                                                <?php foreach ($analytics['weekdays'] as $saD): ?>
                                                    <tr><td><?= htmlspecialchars($saD['label']) ?></td><td><?= number_format($saD['orders']) ?></td><td><?= $saPeso($saD['sales']) ?></td></tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    <?php endif; ?>
                                </div>
                            </div>

                        </div>
                    </div>

                    <!-- ORDERS & CUSTOMERS -->
                    <div class="tab-pane fade" id="saTabOrders" role="tabpanel">
                        <div class="row g-3">

                            <div class="col-12 col-lg-6">
                                <div class="sr-panel">
                                    <h4>Order outcomes <span class="sr-sub">(orders placed in this period)</span></h4>
                                    <table class="sa-table">
                                        <tbody>
                                            <tr><td>Orders placed</td><td><?= number_format($saOut['placed']) ?></td></tr>
                                            <tr><td>Completed</td><td><?= number_format($saOut['completed']) ?></td></tr>
                                            <tr><td>Cancelled</td><td><?= number_format($saOut['cancelled']) ?> (<?= number_format($saOut['cancel_rate'], 1) ?>%)</td></tr>
                                            <tr><td>Still in progress</td><td><?= number_format($saOut['in_progress']) ?></td></tr>
                                        </tbody>
                                    </table>
                                    <?php if (!empty($analytics['cancelReasons'])): ?>
                                        <div class="sa-note"><strong>Top cancellation reasons</strong></div>
                                        <table class="sa-table">
                                            <tbody>
                                                <?php foreach ($analytics['cancelReasons'] as $saR): ?>
                                                    <tr><td><?= htmlspecialchars((string)$saR['reason']) ?></td><td><?= number_format((int)$saR['orders']) ?></td></tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="col-12 col-lg-6">
                                <div class="sr-panel">
                                    <h4>Online customers</h4>
                                    <div class="row g-3">
                                        <div class="col-6"><div class="sa-kpi-small"><?= number_format($analytics['customers']['registered']) ?></div><div class="sa-kpi-small-label">Registered buyers</div></div>
                                        <div class="col-6"><div class="sa-kpi-small"><?= number_format($analytics['customers']['new']) ?></div><div class="sa-kpi-small-label">First-time buyers</div></div>
                                        <div class="col-6"><div class="sa-kpi-small"><?= number_format($analytics['customers']['returning']) ?></div><div class="sa-kpi-small-label">Returning customers</div></div>
                                        <div class="col-6"><div class="sa-kpi-small"><?= number_format($analytics['customers']['guest_orders']) ?></div><div class="sa-kpi-small-label">Guest orders</div></div>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>

                </div>
            </div>
            <?php endif; ?>

        </section>


        <!-- COMPLETED SALES TRANSACTIONS -->
        <section class="sr-card transaction-report-card mt-4">

            <div class="transaction-section">

                <div class="sr-card-head">
                    <h3 class="sr-card-title"><i class="bi bi-receipt"></i> Completed Sales Transactions</h3>
                    <span class="sr-chip">
                        <?= number_format($completedTransactionCount) ?>
                        transaction<?= $completedTransactionCount !== 1 ? 's' : '' ?>
                        • ₱<?= number_format($completedTransactionSalesTotal, 2) ?>
                    </span>
                </div>

                <form method="GET" action="sales-reports.php"
                      class="completed-transaction-filter-form no-print"
                      data-completed-transaction-filter-form>

                    <div class="completed-transaction-filter-group">
                        <label for="completedTransactionSearch">Search</label>
                        <div class="completed-transaction-search-wrap">
                            <input type="text" id="completedTransactionSearch"
                                   class="completed-transaction-filter-search"
                                   data-completed-transaction-search name="completed_q"
                                   value="<?= htmlspecialchars($completed_search) ?>"
                                   maxlength="100" placeholder="Order, claim, customer, phone… (Enter)"
                                   autocomplete="off">
                            <button type="button" class="completed-transaction-search-clear"
                                    data-completed-transaction-search-clear
                                    aria-label="Clear search text" title="Clear search text"
                                    <?= $completed_search !== '' ? '' : 'hidden' ?>>&times;</button>
                        </div>
                    </div>

                    <div class="completed-transaction-filter-group">
                        <label for="completedTransactionPeriod">Date</label>
                        <select id="completedTransactionPeriod" class="completed-transaction-filter-period"
                                data-completed-transaction-period name="completed_period">
                            <?php foreach ($completedPeriodLabels as $periodKey => $periodLabel): ?>
                                <option value="<?= htmlspecialchars($periodKey) ?>" <?= $completed_period === $periodKey ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($periodLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="completed-transaction-filter-group completed-transaction-filter-month-wrap"
                         data-completed-transaction-month-wrap>
                        <label for="completedTransactionMonth">Month</label>
                        <input type="month" id="completedTransactionMonth"
                               class="completed-transaction-filter-month"
                               data-completed-transaction-month name="completed_month"
                               value="<?= htmlspecialchars($completed_month) ?>"
                               max="<?= htmlspecialchars(date('Y-m')) ?>">
                    </div>

                </form>

                <?php if (empty($filteredSalesTransactions)): ?>

                    <div class="empty-report">
                        <i class="bi bi-receipt"></i>
                        <p>No completed sales transactions match the current search and date filters.</p>
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
                                    $saleDate = $sale['closed_at'] ?? $sale['created_at'];

                                    $payment = ucfirst(strtolower($sale['payment_method'] ?? 'N/A'));
                                    $paymentClass = strtolower($payment) === 'gcash' ? 'gcash' : 'cash';

                                    $saleOrderId = (int)$sale['id'];
                                    $transactionNumber = $transactionOffset + $index + 1;
                                    $saleItems = $transactionOrderItems[$saleOrderId] ?? [];

                                    $saleCreatedDate = !empty($sale['created_at'])
                                        ? date('M d, Y • h:i A', strtotime($sale['created_at'])) : 'N/A';

                                    $saleClosedDate = !empty($sale['closed_at'])
                                        ? date('M d, Y • h:i A', strtotime($sale['closed_at'])) : 'N/A';

                                    $salePickupDate = $sale['pickup_date'] ?? 'N/A';
                                    $salePickupTime = '';

                                    if (!empty($sale['pickup_time'])) {
                                        $pickupTimestamp = strtotime($sale['pickup_time']);
                                        if ($pickupTimestamp !== false) {
                                            $salePickupTime = date('h:i A', $pickupTimestamp);
                                        }
                                    }

                                    if ($salePickupTime === '') {
                                        $salePickupTime = 'N/A';
                                    }
                                    ?>

                                    <tr>

                                        <td class="transaction-index-cell" data-label="#">
                                            <span class="transaction-index-badge"><?= $transactionNumber ?></span>
                                        </td>

                                        <td class="transaction-order-cell" data-label="Order / Claim">
                                            <div class="transaction-order-number"><?= htmlspecialchars($sale['order_number'] ?? 'N/A') ?></div>
                                            <div class="transaction-claim-number"><?= htmlspecialchars($sale['claim_number'] ?? 'N/A') ?></div>
                                            <?php if (trim((string)($sale['customer_name'] ?? '')) !== ''): ?>
                                                <div class="transaction-customer">
                                                    <i class="bi bi-person"></i>
                                                    <?= htmlspecialchars((string)$sale['customer_name']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <td class="transaction-payment-cell" data-label="Payment">
                                            <div class="transaction-payment-stack">
                                                <span class="payment-badge <?= $paymentClass ?>"><?= htmlspecialchars($payment) ?></span>

                                                <?php if (
                                                    strtolower((string)($sale['payment_method'] ?? '')) === 'gcash'
                                                    && !empty($sale['payment_screenshot'])
                                                ): ?>
                                                    <span class="transaction-proof-indicator">
                                                        <i class="bi bi-image me-1"></i> Proof available
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>

                                        <td class="text-end amount transaction-amount-cell" data-label="Amount">
                                            ₱<?= number_format((float)$sale['total_amount'], 2) ?>
                                        </td>

                                        <td class="transaction-date-cell" data-label="Date &amp; Time">
                                            <span class="transaction-date"><?= date('M d, Y', strtotime($saleDate)) ?></span>
                                            <span class="transaction-time"><?= date('h:i A', strtotime($saleDate)) ?></span>
                                        </td>

                                        <td class="text-end transaction-details-cell" data-label="Details">
                                            <button type="button" class="btn-view-transaction"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#transactionDetailsModal<?= $saleOrderId ?>">
                                                <i class="bi bi-eye"></i> View Details
                                            </button>
                                        </td>

                                    </tr>


                                    <!-- TRANSACTION DETAILS MODAL -->
                                    <?php
                                    $txdTotal = (float)$sale['total_amount'];
                                    $txdSubtotal = ($sale['subtotal'] ?? null) !== null ? (float)$sale['subtotal'] : null;
                                    $txdDiscountAmount = (float)($sale['discount_amount'] ?? 0);
                                    $txdDiscountType = strtolower(trim((string)($sale['discount_type'] ?? 'none')));
                                    $txdDiscountRate = (float)($sale['discount_rate'] ?? 0);

                                    $txdDiscountLabel = $txdDiscountType === 'pwd'
                                        ? 'PWD discount'
                                        : ($txdDiscountType === 'senior' ? 'Senior Citizen discount' : 'Discount');
                                    if ($txdDiscountRate > 0) {
                                        $txdDiscountLabel .= ' (' . rtrim(rtrim(number_format($txdDiscountRate, 2), '0'), '.') . '%)';
                                    }

                                    $txdPromoDiscount = $txdSubtotal !== null
                                        ? max(0.0, round($txdSubtotal - $txdDiscountAmount - $txdTotal, 2))
                                        : 0.0;

                                    $txdItemCount = 0;
                                    foreach ($saleItems as $txdCountItem) {
                                        $txdItemCount += (int)($txdCountItem['quantity'] ?? 0);
                                    }

                                    $txdPickupTs = ($salePickupDate !== 'N/A') ? strtotime($salePickupDate) : false;
                                    $txdPickupDateLabel = $txdPickupTs !== false ? date('D, M d, Y', $txdPickupTs) : 'N/A';

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
                                    $txdInitial = $txdCustomerName !== '' ? mb_strtoupper(mb_substr($txdCustomerName, 0, 1)) : '?';
                                    $txdPhone = trim((string)($sale['contact_number'] ?? ''));
                                    $txdTel = preg_replace('/[^0-9+]/', '', $txdPhone);
                                    ?>
                                    <div class="modal fade no-print"
                                         id="transactionDetailsModal<?= $saleOrderId ?>"
                                         tabindex="-1"
                                         aria-labelledby="transactionDetailsLabel<?= $saleOrderId ?>"
                                         aria-hidden="true">

                                        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg transaction-details-dialog">
                                            <div class="modal-content transaction-detail-modal">

                                                <div class="modal-header txd-header">
                                                    <span class="txd-header-icon" aria-hidden="true"><i class="bi bi-receipt"></i></span>

                                                    <div class="txd-header-text">
                                                        <h5 class="modal-title" id="transactionDetailsLabel<?= $saleOrderId ?>">Transaction Details</h5>
                                                        <div class="txd-header-sub">
                                                            <span><?= htmlspecialchars($sale['order_number'] ?? 'N/A') ?></span>
                                                            <i class="txd-sep"></i>
                                                            <span><?= htmlspecialchars($sale['claim_number'] ?? 'N/A') ?></span>
                                                        </div>
                                                    </div>

                                                    <span class="txd-status"><i class="bi bi-check-circle-fill"></i> Completed</span>

                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                </div>

                                                <div class="modal-body txd-body">

                                                    <div class="txd-hero">
                                                        <div>
                                                            <div class="txd-hero-label">Total Amount</div>
                                                            <div class="txd-hero-amount">₱<?= number_format($txdTotal, 2) ?></div>
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

                                                        <div class="txd-col">

                                                            <section class="txd-card">
                                                                <h6 class="txd-card-title"><i class="bi bi-person"></i> Customer</h6>
                                                                <div class="txd-customer">
                                                                    <span class="txd-avatar" aria-hidden="true"><?= htmlspecialchars($txdInitial) ?></span>
                                                                    <div class="txd-customer-text">
                                                                        <strong><?= $txdCustomerName !== '' ? htmlspecialchars($txdCustomerName) : 'N/A' ?></strong>
                                                                        <?php if ($txdPhone !== ''): ?>
                                                                            <a href="tel:<?= htmlspecialchars($txdTel) ?>">
                                                                                <i class="bi bi-telephone"></i> <?= htmlspecialchars($txdPhone) ?>
                                                                            </a>
                                                                        <?php else: ?>
                                                                            <span class="txd-muted">No contact number</span>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                </div>
                                                            </section>

                                                            <section class="txd-card">
                                                                <h6 class="txd-card-title"><i class="bi bi-bag-check"></i> Pick-up</h6>
                                                                <dl class="txd-kv">
                                                                    <div><dt>Date</dt><dd><?= htmlspecialchars($txdPickupDateLabel) ?></dd></div>
                                                                    <div><dt>Time</dt><dd><?= htmlspecialchars($salePickupTime) ?></dd></div>
                                                                </dl>
                                                            </section>

                                                            <section class="txd-card">
                                                                <h6 class="txd-card-title"><i class="bi bi-clock-history"></i> Timeline</h6>
                                                                <ol class="txd-timeline">
                                                                    <li>
                                                                        <span class="txd-tl-dot"></span>
                                                                        <div><strong>Order placed</strong><small><?= htmlspecialchars($saleCreatedDate) ?></small></div>
                                                                    </li>
                                                                    <li class="is-done">
                                                                        <span class="txd-tl-dot"></span>
                                                                        <div><strong>Completed</strong><small><?= htmlspecialchars($saleClosedDate) ?></small></div>
                                                                    </li>
                                                                </ol>
                                                                <?php if ($txdDuration !== ''): ?>
                                                                    <div class="txd-duration"><i class="bi bi-stopwatch"></i> Fulfilled in <?= htmlspecialchars($txdDuration) ?></div>
                                                                <?php endif; ?>
                                                            </section>

                                                        </div>

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
                                                                                        <?php if ($itemIsFree): ?><span class="txd-free">FREE</span><?php endif; ?>
                                                                                    </div>

                                                                                    <div class="txd-item-unit">₱<?= number_format((float)$item['unit_price'], 2) ?> each</div>

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
                                                                    <div class="txd-line"><span>Subtotal</span><span>₱<?= number_format($txdSubtotal, 2) ?></span></div>
                                                                <?php endif; ?>

                                                                <?php if ($txdPromoDiscount > 0): ?>
                                                                    <div class="txd-line is-discount"><span>Promotion</span><span>−₱<?= number_format($txdPromoDiscount, 2) ?></span></div>
                                                                <?php endif; ?>

                                                                <?php if ($txdDiscountAmount > 0): ?>
                                                                    <div class="txd-line is-discount"><span><?= htmlspecialchars($txdDiscountLabel) ?></span><span>−₱<?= number_format($txdDiscountAmount, 2) ?></span></div>
                                                                <?php endif; ?>

                                                                <div class="txd-line txd-total">
                                                                    <span>Total Amount</span>
                                                                    <strong>₱<?= number_format($txdTotal, 2) ?></strong>
                                                                </div>

                                                            </section>

                                                            <?php if ($txdHasProof): ?>
                                                                <section class="txd-card transaction-payment-proof">
                                                                    <h6 class="txd-card-title"><i class="bi bi-image"></i> GCash Payment Proof</h6>

                                                                    <a class="txd-proof"
                                                                       href="../<?= htmlspecialchars($sale['payment_screenshot']) ?>"
                                                                       target="_blank" rel="noopener"
                                                                       title="Open full image in a new tab">
                                                                        <img src="../<?= htmlspecialchars($sale['payment_screenshot']) ?>"
                                                                             alt="GCash Payment Proof for order <?= htmlspecialchars($sale['order_number'] ?? (string)$saleOrderId, ENT_QUOTES, 'UTF-8') ?>"
                                                                             loading="lazy">
                                                                        <span class="txd-proof-hint"><i class="bi bi-arrows-fullscreen"></i> View full size</span>
                                                                    </a>
                                                                </section>
                                                            <?php endif; ?>

                                                        </div>

                                                    </div>

                                                </div>

                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                </div>

                                            </div>
                                        </div>

                                    </div>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>


                    <!-- PAGINATION -->
                    <?php if ($totalTransactionPages > 1): ?>

                        <?php
                        $paginationParams = [
                            'start_date' => $start_date,
                            'end_date' => $end_date,
                            'mode' => $report_mode,
                            'completed_q' => $completed_search,
                            'completed_period' => $completed_period,
                            'completed_month' => $completed_period === 'specific_month' ? $completed_month : ''
                        ];

                        $transactionPaginationUrl = static function (int $targetPage) use ($paginationParams): string {
                            $params = $paginationParams;
                            $params['transaction_page'] = $targetPage;
                            return '?' . http_build_query($params);
                        };

                        $visibleTransactionPages = [1];

                        for (
                            $paginationCandidate = max(2, $transactionPage - 2);
                            $paginationCandidate <= min($totalTransactionPages - 1, $transactionPage + 2);
                            $paginationCandidate++
                        ) {
                            $visibleTransactionPages[] = $paginationCandidate;
                        }

                        $visibleTransactionPages[] = $totalTransactionPages;
                        $visibleTransactionPages = array_values(array_unique($visibleTransactionPages));
                        $previousTransactionPage = 0;
                        ?>

                        <div class="transaction-pagination pagination-wrap">
                            <nav aria-label="Completed sales transaction pagination">
                                <ul class="pagination mb-0">

                                    <li class="page-item <?= $transactionPage <= 1 ? 'disabled' : '' ?>">
                                        <a class="page-link transaction-page-link <?= $transactionPage <= 1 ? 'disabled' : '' ?>"
                                           href="<?= htmlspecialchars($transactionPaginationUrl(max(1, $transactionPage - 1)), ENT_QUOTES, 'UTF-8') ?>"
                                           data-preserve-scroll="1"
                                           <?= $transactionPage <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>>Previous</a>
                                    </li>

                                    <?php foreach ($visibleTransactionPages as $paginationPage): ?>

                                        <?php if ($previousTransactionPage > 0 && $paginationPage > $previousTransactionPage + 1): ?>
                                            <li class="page-item disabled" aria-hidden="true"><span class="page-link">...</span></li>
                                        <?php endif; ?>

                                        <li class="page-item <?= $paginationPage === $transactionPage ? 'active' : '' ?>">
                                            <a class="page-link transaction-page-link"
                                               href="<?= htmlspecialchars($transactionPaginationUrl($paginationPage), ENT_QUOTES, 'UTF-8') ?>"
                                               data-preserve-scroll="1"
                                               <?= $paginationPage === $transactionPage ? 'aria-current="page"' : '' ?>><?= $paginationPage ?></a>
                                        </li>

                                        <?php $previousTransactionPage = $paginationPage; ?>

                                    <?php endforeach; ?>

                                    <li class="page-item <?= $transactionPage >= $totalTransactionPages ? 'disabled' : '' ?>">
                                        <a class="page-link transaction-page-link <?= $transactionPage >= $totalTransactionPages ? 'disabled' : '' ?>"
                                           href="<?= htmlspecialchars($transactionPaginationUrl(min($totalTransactionPages, $transactionPage + 1)), ENT_QUOTES, 'UTF-8') ?>"
                                           data-preserve-scroll="1"
                                           <?= $transactionPage >= $totalTransactionPages ? 'aria-disabled="true" tabindex="-1"' : '' ?>>Next</a>
                                    </li>

                                </ul>
                            </nav>
                        </div>

                    <?php endif; ?>

                    <div class="transaction-page-info">
                        Showing <?= count($displayTransactions) ?> of <?= number_format($completedTransactionCount) ?>
                        completed transaction<?= $completedTransactionCount !== 1 ? 's' : '' ?>
                        <?php if ($totalTransactionPages > 1): ?>
                            • Page <?= $transactionPage ?> of <?= $totalTransactionPages ?>
                        <?php endif; ?>
                    </div>

                    <div class="report-footer-summary">
                        <div>Completed Orders: <strong><?= number_format($completedTransactionCount) ?></strong></div>
                        <div>Total Sales: <strong>₱<?= number_format($completedTransactionSalesTotal, 2) ?></strong></div>
                    </div>

                <?php endif; ?>

            </div>

        </section>

    </div>
    </main>

</div>


<?php if ($reportToast): ?>
    <div class="sales-report-toast-wrap" aria-live="polite" aria-atomic="true">
        <div class="sales-report-toast" id="salesReportActionToast" role="status"
             data-toast-key="<?= htmlspecialchars($report_mode . '|' . $start_date . '|' . $end_date) ?>">
            <span class="sales-report-toast-icon"><i class="bi <?= htmlspecialchars($reportToast['icon']) ?>"></i></span>
            <span class="sales-report-toast-message"><?= htmlspecialchars($reportToast['message']) ?></span>
            <button type="button" class="sales-report-toast-close" aria-label="Close notification"><i class="bi bi-x-lg"></i></button>
            <span class="sales-report-toast-progress" aria-hidden="true"></span>
        </div>
    </div>
<?php endif; ?>

<script>

/* =========================================================
   SALES REPORT ACTION TOAST
========================================================= */
(function () {
    var STORAGE_KEY = 'adminSalesReportActionToast';
    var DURATION = 3500;

    function readState() { try { return JSON.parse(sessionStorage.getItem(STORAGE_KEY) || 'null'); } catch (e) { return null; } }
    function saveState(s) { try { sessionStorage.setItem(STORAGE_KEY, JSON.stringify(s)); } catch (e) {} }
    function clearState() { try { sessionStorage.removeItem(STORAGE_KEY); } catch (e) {} }

    var now = Date.now();
    var toast = document.getElementById('salesReportActionToast');
    var state = readState();

    if (toast) {
        var currentKey = toast.getAttribute('data-toast-key') || '';
        var existingAge = state && state.createdAt ? now - Number(state.createdAt) : Infinity;

        if (!state || state.key !== currentKey || !(existingAge >= 0) || existingAge >= DURATION) {
            state = { html: toast.outerHTML, key: currentKey, createdAt: now };
        } else {
            state.html = toast.outerHTML;
            state.key = currentKey;
        }
        saveState(state);

        try {
            var url = new URL(window.location.href);
            url.searchParams.delete('report_action');
            window.history.replaceState({}, document.title,
                url.pathname + (url.searchParams.toString() ? '?' + url.searchParams.toString() : '') + url.hash);
        } catch (e) {}

    } else {
        if (!state || !state.html || !state.createdAt || !state.key) { clearState(); return; }

        var carriedAge = now - Number(state.createdAt);
        if (!(carriedAge >= 0) || carriedAge >= DURATION) { clearState(); return; }

        var wrap = document.createElement('div');
        wrap.className = 'sales-report-toast-wrap';
        wrap.setAttribute('aria-live', 'polite');
        wrap.setAttribute('aria-atomic', 'true');
        wrap.innerHTML = state.html;
        document.body.appendChild(wrap);

        toast = wrap.querySelector('#salesReportActionToast');
        if (!toast) { clearState(); return; }
        toast.classList.add('is-restored');
    }

    var age = now - Number(state.createdAt);
    var visibleFor = DURATION - age;

    if (visibleFor <= 0) { clearState(); toast.remove(); return; }

    var progress = toast.querySelector('.sales-report-toast-progress');
    if (progress) {
        progress.style.animationDuration = DURATION + 'ms';
        progress.style.animationDelay = (-age) + 'ms';
    }

    var closeTimer = null;

    function closeToast() {
        if (!toast || toast.classList.contains('is-closing')) return;
        if (closeTimer !== null) { clearTimeout(closeTimer); closeTimer = null; }
        clearState();
        toast.classList.add('is-closing');
        setTimeout(function () { if (toast) toast.remove(); }, 190);
    }

    var closeButton = toast.querySelector('.sales-report-toast-close');
    if (closeButton) closeButton.addEventListener('click', closeToast);

    closeTimer = setTimeout(closeToast, visibleFor);

    window.addEventListener('pageshow', function (event) {
        if (event.persisted) { clearState(); if (toast) toast.remove(); }
    });
})();


/* =========================================================
   NAVIGATION / PRINT / AJAX SECTIONS
   - Transactions search/date/pagination replace ONLY the
     Completed Sales Transactions card.
   - Sales Overview Daily/Weekly/Monthly replaces ONLY the
     .overview-card block.
========================================================= */
(function () {

    const scrollStorageKey = 'salesReportsScrollY';
    const filterForm = document.getElementById('salesReportFilterForm');
    const printButton = document.getElementById('printSalesReportBtn');

    /* ---------- Modals (AJAX-safe) ---------- */
    function normalizeTransactionModals(root = document) {
        root.querySelectorAll('[id^="transactionDetailsModal"]').forEach(function (modal) {
            if (modal.parentElement !== document.body) document.body.appendChild(modal);
        });
    }

    function cleanupSalesModalState() {
        document.querySelectorAll('.modal-backdrop').forEach(function (b) { b.remove(); });
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('padding-right');
        document.body.style.removeProperty('overflow');
    }

    function showTransactionDetailsModal(modal) {
        if (!modal) return;

        if (window.bootstrap && bootstrap.Modal) {
            (bootstrap.Modal.getInstance(modal) ||
                bootstrap.Modal.getOrCreateInstance(modal, { backdrop: true, keyboard: true, focus: true })).show();
            return;
        }

        document.querySelectorAll('.modal.show').forEach(function (m) {
            if (m !== modal) { m.classList.remove('show'); m.style.display = 'none'; m.setAttribute('aria-hidden', 'true'); }
        });

        cleanupSalesModalState();
        modal.classList.add('show');
        modal.style.display = 'block';
        modal.removeAttribute('aria-hidden');
        modal.setAttribute('aria-modal', 'true');
        document.body.classList.add('modal-open');

        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop fade show';
        document.body.appendChild(backdrop);
    }

    function hideTransactionDetailsModal(modal) {
        if (!modal) return;

        if (window.bootstrap && bootstrap.Modal) {
            (bootstrap.Modal.getInstance(modal) || bootstrap.Modal.getOrCreateInstance(modal)).hide();
            return;
        }

        modal.classList.remove('show');
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
        modal.removeAttribute('aria-modal');
        cleanupSalesModalState();
    }

    normalizeTransactionModals();

    document.addEventListener('click', function (event) {
        const viewButton = event.target.closest('.btn-view-transaction');

        if (viewButton) {
            event.preventDefault();
            event.stopPropagation();

            const targetSelector = viewButton.getAttribute('data-bs-target');
            if (!targetSelector) return;

            const modal = document.querySelector(targetSelector);
            if (!modal) { console.error('Transaction details modal not found:', targetSelector); return; }

            normalizeTransactionModals();
            showTransactionDetailsModal(modal);
            return;
        }

        const dismissButton = event.target.closest('[data-bs-dismiss="modal"]');

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
        if (event.key !== 'Escape') return;
        const open = document.querySelector('.modal.show[id^="transactionDetailsModal"]');
        if (open) hideTransactionDetailsModal(open);
    });

    if ('scrollRestoration' in history) history.scrollRestoration = 'manual';

    function navigateUrlWithoutReload(url, replace = false) {
        const cleanUrl = new URL(url, window.location.href);
        cleanUrl.searchParams.delete('report_action');

        const state = { localiteaSalesReportsAjax: true };
        const href = cleanUrl.pathname + (cleanUrl.search || '') + cleanUrl.hash;

        if (replace) window.history.replaceState(state, '', href);
        else window.history.pushState(state, '', href);

        return cleanUrl;
    }

    function setSectionBusy(section, busy) {
        if (!section) return;
        section.classList.toggle('completed-transactions-ajax-busy', busy);
        section.setAttribute('aria-busy', busy ? 'true' : 'false');
    }

    async function fetchPageFragment(url, selector) {
        const requestUrl = new URL(url, window.location.href);
        requestUrl.searchParams.delete('report_action');
        requestUrl.searchParams.set('_ajax_view', '1');
        requestUrl.searchParams.set('_', String(Date.now()));

        const response = await fetch(requestUrl.toString(), {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' },
            cache: 'no-store'
        });

        if (!response.ok) throw new Error('Server returned HTTP ' + response.status);

        const html = await response.text();
        const parsed = new DOMParser().parseFromString(html, 'text/html');
        const fragment = parsed.querySelector(selector);

        if (!fragment) throw new Error('The requested sales report section was not found.');

        return fragment;
    }

    /* ---------- Transaction filter UI ---------- */
    function updateCompletedMonthVisibility(root = document) {
        const period = root.querySelector('[data-completed-transaction-period]');
        const monthWrap = root.querySelector('[data-completed-transaction-month-wrap]');
        if (!period || !monthWrap) return;
        monthWrap.style.display = period.value === 'specific_month' ? 'flex' : 'none';
    }

    function updateCompletedSearchClearButton(searchInput) {
        if (!searchInput) return;
        const clearButton = searchInput.closest('.completed-transaction-search-wrap')
            ?.querySelector('[data-completed-transaction-search-clear]');
        if (clearButton) clearButton.hidden = searchInput.value.length === 0;
    }

    updateCompletedSearchClearButton(document.querySelector('[data-completed-transaction-search]'));

    document.addEventListener('input', function (event) {
        const searchInput = event.target.closest('[data-completed-transaction-search]');
        if (searchInput) updateCompletedSearchClearButton(searchInput);
    });

    document.addEventListener('click', function (event) {
        const clearButton = event.target.closest('[data-completed-transaction-search-clear]');
        if (!clearButton) return;

        event.preventDefault();

        const searchInput = clearButton.closest('.completed-transaction-search-wrap')
            ?.querySelector('[data-completed-transaction-search]');
        if (!searchInput) return;

        searchInput.value = '';
        updateCompletedSearchClearButton(searchInput);
        searchInput.focus();
    });

    function buildCompletedTransactionUrl(overrides = {}) {
        const url = new URL(window.location.href);
        const form = document.querySelector('[data-completed-transaction-filter-form]');
        const periodSelect = form?.querySelector('[data-completed-transaction-period]');
        const monthInput = form?.querySelector('[data-completed-transaction-month]');

        const search = overrides.completed_q ?? url.searchParams.get('completed_q') ?? '';
        const period = overrides.completed_period ?? periodSelect?.value ?? url.searchParams.get('completed_period') ?? 'today';
        const month = overrides.completed_month ?? monthInput?.value ?? url.searchParams.get('completed_month') ?? '';
        const page = overrides.transaction_page ?? url.searchParams.get('transaction_page') ?? '1';

        if (search.trim() !== '') url.searchParams.set('completed_q', search.trim());
        else url.searchParams.delete('completed_q');

        url.searchParams.set('completed_period', period || 'today');

        if ((period || 'today') === 'specific_month' && month) url.searchParams.set('completed_month', month);
        else url.searchParams.delete('completed_month');

        url.searchParams.set('transaction_page', String(page || '1'));
        url.searchParams.delete('report_action');

        return url;
    }

    let completedRequestId = 0;

    async function loadCompletedTransactions(targetUrl, pushHistory = true) {
        const currentSection = document.querySelector('.transaction-report-card');
        if (!currentSection) return;

        const requestNumber = ++completedRequestId;
        setSectionBusy(currentSection, true);

        try {
            const newSection = await fetchPageFragment(targetUrl, '.transaction-report-card');
            if (requestNumber !== completedRequestId) return;

            currentSection.replaceWith(newSection);

            normalizeTransactionModals();
            updateCompletedMonthVisibility(newSection);
            updateCompletedSearchClearButton(newSection.querySelector('[data-completed-transaction-search]'));

            navigateUrlWithoutReload(targetUrl, !pushHistory);

        } catch (error) {
            console.error('Completed Sales Transactions AJAX error:', error);
            window.alert(error.message || 'Unable to load completed sales transactions.');
        } finally {
            setSectionBusy(document.querySelector('.transaction-report-card'), false);
        }
    }

    document.addEventListener('change', function (event) {
        const periodSelect = event.target.closest('[data-completed-transaction-period]');

        if (periodSelect) {
            const period = periodSelect.value || 'today';
            updateCompletedMonthVisibility(document);

            const monthInput = document.querySelector('[data-completed-transaction-month]');

            if (period === 'specific_month' && (!monthInput || !monthInput.value)) {
                if (monthInput) monthInput.focus();
                return;
            }

            loadCompletedTransactions(buildCompletedTransactionUrl({
                completed_period: period,
                completed_month: monthInput?.value || '',
                transaction_page: 1
            }), true);
            return;
        }

        const monthInput = event.target.closest('[data-completed-transaction-month]');

        if (monthInput) {
            const select = document.querySelector('[data-completed-transaction-period]');

            if (select?.value === 'specific_month' && monthInput.value) {
                loadCompletedTransactions(buildCompletedTransactionUrl({
                    completed_period: 'specific_month',
                    completed_month: monthInput.value,
                    transaction_page: 1
                }), true);
            }
        }
    });

    /* Search is manual: typing does nothing until Enter is pressed. */
    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' || event.isComposing) return;

        const searchInput = event.target.closest('[data-completed-transaction-search]');
        if (!searchInput) return;

        event.preventDefault();

        const form = searchInput.closest('[data-completed-transaction-filter-form]');
        if (form && typeof form.requestSubmit === 'function') form.requestSubmit();
    });

    document.addEventListener('submit', function (event) {
        const form = event.target.closest('[data-completed-transaction-filter-form]');
        if (!form) return;

        event.preventDefault();

        const period = form.querySelector('[data-completed-transaction-period]');
        const month = form.querySelector('[data-completed-transaction-month]');
        const search = form.querySelector('[data-completed-transaction-search]');

        if (period?.value === 'specific_month' && !month?.value) { month?.focus(); return; }

        loadCompletedTransactions(buildCompletedTransactionUrl({
            completed_period: period?.value || 'today',
            completed_month: month?.value || '',
            completed_q: search?.value || '',
            transaction_page: 1
        }), true);
    });

    document.addEventListener('click', function (event) {
        const link = event.target.closest('.transaction-page-link[href]');
        if (!link) return;

        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey ||
            link.target === '_blank' || link.classList.contains('disabled')) return;

        event.preventDefault();
        loadCompletedTransactions(link.href, true);
    });

    /* ---------- Sales Overview mode AJAX ---------- */
    let overviewRequestId = 0;

    async function loadSalesOverview(targetUrl, pushHistory = true) {
        const currentOverview = document.querySelector('.overview-card');
        if (!currentOverview) return;

        const requestNumber = ++overviewRequestId;

        currentOverview.setAttribute('aria-busy', 'true');
        currentOverview.style.opacity = '.58';
        currentOverview.style.pointerEvents = 'none';

        try {
            const newOverview = await fetchPageFragment(targetUrl, '.overview-card');
            if (requestNumber !== overviewRequestId) return;

            currentOverview.replaceWith(newOverview);
            navigateUrlWithoutReload(targetUrl, !pushHistory);

        } catch (error) {
            console.error('Sales Overview AJAX error:', error);
            window.alert(error.message || 'Unable to load the Sales Overview.');
        } finally {
            const restored = document.querySelector('.overview-card');
            if (restored) {
                restored.removeAttribute('aria-busy');
                restored.style.opacity = '';
                restored.style.pointerEvents = '';
            }
        }
    }

    document.addEventListener('click', function (event) {
        const modeLink = event.target.closest('.mode-tab[data-preserve-scroll="1"]');
        if (!modeLink) return;

        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || modeLink.target === '_blank') return;

        event.preventDefault();

        sessionStorage.setItem(scrollStorageKey, String(window.scrollY));

        const modeUrl = new URL(modeLink.href, window.location.href);
        modeUrl.searchParams.delete('report_action');

        loadSalesOverview(modeUrl, true);
    });

    window.addEventListener('popstate', function () {
        loadSalesOverview(window.location.href, false);
        loadCompletedTransactions(window.location.href, false);
    });

    updateCompletedMonthVisibility(document);

    /* ---------- Date inputs + print link ---------- */
    if (filterForm) {
        const startInput = filterForm.querySelector('[name="start_date"]');
        const endInput = filterForm.querySelector('[name="end_date"]');

        function syncDateLimits() {
            if (startInput && endInput && startInput.value) {
                endInput.min = startInput.value;
                if (endInput.value && endInput.value < startInput.value) endInput.value = startInput.value;
            }
        }

        function updatePrintLink() {
            if (!printButton || !startInput || !endInput || !startInput.value || !endInput.value) return;

            const printUrl = new URL('print-sales-report.php', window.location.href);
            printUrl.searchParams.set('start_date', startInput.value);
            printUrl.searchParams.set('end_date', endInput.value);
            printButton.href = printUrl.toString();
        }

        [startInput, endInput].forEach(function (input) {
            if (input) input.addEventListener('change', function () { syncDateLimits(); updatePrintLink(); });
        });

        syncDateLimits();
        updatePrintLink();
    }

    function restoreSalesReportScroll() {
        const savedScroll = sessionStorage.getItem(scrollStorageKey);
        if (savedScroll === null) return;

        const scrollY = Math.max(0, parseInt(savedScroll, 10) || 0);
        sessionStorage.removeItem(scrollStorageKey);

        window.scrollTo(0, scrollY);
        requestAnimationFrame(function () { window.scrollTo(0, scrollY); });
        setTimeout(function () { window.scrollTo(0, scrollY); }, 80);
        setTimeout(function () { window.scrollTo(0, scrollY); }, 250);
    }

    window.addEventListener('load', restoreSalesReportScroll);

})();

</script>