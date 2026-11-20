<?php

require_once '../includes/db.php';
require_once '../includes/sales-analytics.php';
saUsePhilippineTime($pdo);   // Philippine time for PHP + MySQL, independent of Railway

/* =========================================================
   ADMIN ACCESS
========================================================= */
if (
    !isset($_SESSION['user_role']) ||
    !in_array($_SESSION['user_role'], ['admin'], true)
) {
    header('Location: ../auth/login.php');
    exit;
}

/* =========================================================
   REPORT PARAMETERS + VALIDATION
========================================================= */
$start_date = $_GET['start_date'] ?? '';
$end_date   = $_GET['end_date'] ?? '';
$mode       = $_GET['mode'] ?? 'daily';

$startObject = DateTime::createFromFormat('Y-m-d', $start_date);
$endObject   = DateTime::createFromFormat('Y-m-d', $end_date);

if (
    !$startObject || $startObject->format('Y-m-d') !== $start_date ||
    !$endObject   || $endObject->format('Y-m-d') !== $end_date ||
    $start_date > $end_date
) {
    header('Location: sales-reports.php');
    exit;
}

$allowed_modes = ['daily', 'weekly', 'monthly'];

if (!in_array($mode, $allowed_modes, true)) {
    $mode = 'daily';
}

$modeLabels = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'];
$modeLabel  = $modeLabels[$mode];

/* Payment filter: all / cash / gcash (same as the Sales Reports page). */
$pay = strtolower(trim((string)($_GET['pay'] ?? 'all')));

if (!in_array($pay, ['all', 'cash', 'gcash'], true)) {
    $pay = 'all';
}

$payLabels = ['all' => 'All payments', 'cash' => 'Cash only', 'gcash' => 'GCash only'];
$payLabel  = $payLabels[$pay];
$paySql    = $pay === 'all' ? '' : " AND payment_method = '" . $pay . "'";   // whitelisted above

/* Optional sections: ?show=payment,channel,... (missing = all shown, "none" = none). */
$sectionLabels = [
    'payment'    => 'Payment Summary',
    'channel'    => 'Order Channel',
    'products'   => 'Top 5 Products',
    'categories' => 'Top Categories',
    'discounts'  => 'Discounts & Promotions',
    'outcomes'   => 'Orders, Cancellations & Refunds',
    'promos'     => 'Promotion Sales',
    'hours'      => 'Busiest Pickup Hours',
    'customers'  => 'Online Customers',
];

if (isset($_GET['show'])) {
    $selectedSections = array_values(array_intersect(
        array_keys($sectionLabels),
        array_map('trim', explode(',', strtolower((string)$_GET['show'])))
    ));
} else {
    $selectedSections = array_keys($sectionLabels);
}

$allSectionsOn = count($selectedSections) === count($sectionLabels);
$showQs        = $allSectionsOn ? '' : '&show=' . urlencode($selectedSections ? implode(',', $selectedSections) : 'none');

$secAttr = static function (string $key) use ($selectedSections): string {
    return 'data-section="' . $key . '"' . (in_array($key, $selectedSections, true) ? '' : ' style="display:none"');
};

/* =========================================================
   GENERATED INFO + BUSINESS DETAILS
   >>> Replace the email and phone below with the real ones. <<<
========================================================= */
$reportGeneratedAt     = date('Y-m-d H:i:s');
$reportGeneratedBy     = $_SESSION['user_name'] ?? 'Admin';
$reportGeneratedByRole = ucfirst((string)($_SESSION['user_role'] ?? 'admin'));

$businessName    = 'LOCAL MILKTEA HOUSE';
$businessAddress = 'Blk 5 Lot 2 Block C2a St. Nicolasa Virata, General Mariano Alvarez, Cavite, Philippines 4117';
$businessEmail   = 'localmilkteahouse@example.com';
$businessPhone   = '0900 000 0000';

/* =========================================================
   BUSINESS LOGO (assets/images/logo.png, .svg, .webp, .jpg)
========================================================= */
$businessLogoPath = $businessLogoPath ?? null;

if ($businessLogoPath === null) {
    foreach (['../assets/images', '../assets/img', '../assets/logo', '../assets/uploads', '../assets'] as $logoDir) {
        foreach (['png', 'svg', 'webp', 'jpg', 'jpeg'] as $logoExt) {
            if (is_file(__DIR__ . '/' . $logoDir . '/logo.' . $logoExt)) {
                $businessLogoPath = $logoDir . '/logo.' . $logoExt;
                break 2;
            }
        }
    }
}

$businessLogoUrl = null;

if ($businessLogoPath !== null && is_file(__DIR__ . '/' . $businessLogoPath)) {
    $businessLogoUrl = $businessLogoPath . '?v=' . (int)@filemtime(__DIR__ . '/' . $businessLogoPath);
}

/* =========================================================
   COMPLETED SALES
========================================================= */
$salesStmt = $pdo->prepare("
    SELECT id, order_number, claim_number, payment_method, subtotal, total_amount, created_at, closed_at
    FROM orders
    WHERE status = 'completed'
      AND DATE(COALESCE(closed_at, created_at)) BETWEEN ? AND ?
      $paySql
    ORDER BY COALESCE(closed_at, created_at) ASC, id ASC
");

$salesStmt->execute([$start_date, $end_date]);
$salesTransactions = $salesStmt->fetchAll(PDO::FETCH_ASSOC);

$totalSales  = 0;
$totalOrders = count($salesTransactions);

foreach ($salesTransactions as $sale) {
    $totalSales += (float)$sale['total_amount'];
}

$averageOrder = $totalOrders > 0 ? $totalSales / $totalOrders : 0;

/* =========================================================
   PAYMENT SUMMARY
========================================================= */
$paymentSummary = [
    'Cash'  => ['orders' => 0, 'sales' => 0],
    'GCash' => ['orders' => 0, 'sales' => 0]
];

foreach ($salesTransactions as $sale) {
    $paymentMethod = strtolower(trim($sale['payment_method'] ?? ''));

    if ($paymentMethod === 'cash') {
        $paymentSummary['Cash']['orders']++;
        $paymentSummary['Cash']['sales'] += (float)$sale['total_amount'];
    } elseif ($paymentMethod === 'gcash') {
        $paymentSummary['GCash']['orders']++;
        $paymentSummary['GCash']['sales'] += (float)$sale['total_amount'];
    }
}

/* =========================================================
   SALES OVERVIEW
   One row per day / week / month with: orders, gross sales, discounts,
   net sales, average order, Cash / GCash split and share of the total.
========================================================= */
$salesOverview = [];

$ovNewPeriod = static fn(string $label, string $sort): array => [
    'label' => $label, 'sort' => $sort,
    'orders' => 0, 'sales' => 0.0, 'gross' => 0.0, 'discount' => 0.0,
    'cash' => 0.0, 'gcash' => 0.0,
];

$rangeStartObj = new DateTime($start_date);
$rangeEndObj   = new DateTime($end_date);

if ($mode === 'daily') {
    $currentDate = clone $rangeStartObj;

    while ($currentDate <= $rangeEndObj) {
        $dayKey = $currentDate->format('Y-m-d');
        $salesOverview[$dayKey] = $ovNewPeriod($currentDate->format('D, M d'), $dayKey);
        $currentDate->modify('+1 day');
    }
}

foreach ($salesTransactions as $sale) {
    $saleDate = new DateTime($sale['closed_at'] ?? $sale['created_at']);

    if ($mode === 'daily') {
        $key   = $saleDate->format('Y-m-d');
        $label = $saleDate->format('D, M d');
        $sort  = $key;
    } elseif ($mode === 'weekly') {
        $year = (int)$saleDate->format('o');
        $week = (int)$saleDate->format('W');
        $key  = $year . '-W' . sprintf('%02d', $week);

        $weekStart = new DateTime();
        $weekStart->setISODate($year, $week, 1);
        $weekEnd = clone $weekStart;
        $weekEnd->modify('+6 days');

        /* Show only the days that are inside the report period. */
        $shownStart = $weekStart < $rangeStartObj ? $rangeStartObj : $weekStart;
        $shownEnd   = $weekEnd > $rangeEndObj ? $rangeEndObj : $weekEnd;

        $label = $shownStart->format('M d') . ' - ' . $shownEnd->format('M d');
        $sort  = $weekStart->format('Y-m-d');
    } else {
        $key   = $saleDate->format('Y-m');
        $label = $saleDate->format('F Y');
        $sort  = $key;
    }

    if (!isset($salesOverview[$key])) {
        $salesOverview[$key] = $ovNewPeriod($label, $sort);
    }

    $net   = (float)$sale['total_amount'];
    $gross = isset($sale['subtotal']) ? (float)$sale['subtotal'] : $net;

    $salesOverview[$key]['orders']++;
    $salesOverview[$key]['sales']    += $net;
    $salesOverview[$key]['gross']    += $gross;
    $salesOverview[$key]['discount'] += max(0.0, $gross - $net);

    $saleMethod = strtolower(trim((string)($sale['payment_method'] ?? '')));

    if ($saleMethod === 'cash') {
        $salesOverview[$key]['cash'] += $net;
    } elseif ($saleMethod === 'gcash') {
        $salesOverview[$key]['gcash'] += $net;
    }
}

uasort($salesOverview, static fn($a, $b) => strcmp($a['sort'], $b['sort']));

/* Highlights + totals for the overview */
$ovUnit   = ['daily' => 'day', 'weekly' => 'week', 'monthly' => 'month'][$mode];
$ovBest   = null;
$ovLowest = null;
$ovActive = 0;
$ovTotals = ['gross' => 0.0, 'discount' => 0.0];

foreach ($salesOverview as $ovPeriod) {
    $ovTotals['gross']    += $ovPeriod['gross'];
    $ovTotals['discount'] += $ovPeriod['discount'];

    if ($ovPeriod['orders'] > 0) {
        $ovActive++;

        if ($ovBest === null || $ovPeriod['sales'] > $ovBest['sales']) {
            $ovBest = $ovPeriod;
        }
        if ($ovLowest === null || $ovPeriod['sales'] < $ovLowest['sales']) {
            $ovLowest = $ovPeriod;
        }
    }
}

$ovAveragePerPeriod = count($salesOverview) > 0 ? $totalSales / count($salesOverview) : 0;

/* =========================================================
   ANALYTICS
========================================================= */
$analytics = salesAnalytics($pdo, $start_date, $end_date, $pay);

$paPeso = static fn($v) => '₱' . number_format((float)$v, 2);

if ($analytics) {
    $paCur  = $analytics['current'];
    $paPrev = $analytics['previous'];
    $paCh   = $analytics['channel'];
    $paOut  = $analytics['outcomes'];
    $paRef  = $analytics['refunds'];
    $paChTotal = $paCh['online']['sales'] + $paCh['walk_in']['sales'];

    $paChange = static function ($now, $before): string {
        $pct = saPercentChange((float)$now, (float)$before);
        return $pct === null ? 'no previous data' : (($pct >= 0 ? '▲ ' : '▼ ') . number_format(abs($pct), 1) . '% vs previous');
    };

    $paHours = $analytics['hours'];
    usort($paHours, static fn($a, $b) => (int)$b['orders'] <=> (int)$a['orders']);
    $paHours = array_slice($paHours, 0, 5);

    /* Keep the print short: top 5 products and categories. */
    $paProducts   = array_slice($analytics['topProducts'], 0, 5);
    $paCategories = array_slice($analytics['categories'], 0, 5);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Report - Local Milktea House</title>

    <style>

        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 24px 12px;
            background: #f2f2f2;
            color: #222;
            font-family: Arial, Helvetica, sans-serif;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* ---------- Screen controls ---------- */
        .print-controls {
            width: 210mm;
            max-width: 100%;
            margin: 0 auto 14px;
            padding: 12px 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            background: #fff;
            border: 1px solid #8B6F5A;
            border-radius: 10px;
        }

        .print-mode-label { margin-bottom: 6px; font-size: 12px; font-weight: 700; color: #4A3525; }
        .print-mode-tabs { display: flex; gap: 6px; flex-wrap: wrap; }

        .print-mode-tab {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 72px; padding: 7px 12px;
            border: 1px solid #8B6F5A; border-radius: 7px;
            background: #fff; color: #4A3525;
            text-decoration: none; font-size: 11px; font-weight: 700;
        }
        .print-mode-tab:hover { background: #F7F1E8; }
        .print-mode-tab.active { background: #4A3525; border-color: #4A3525; color: #fff; }

        .print-now-btn {
            border: 1px solid #4A3525; border-radius: 7px; padding: 9px 16px;
            background: #4A3525; color: #fff; font-size: 12px; font-weight: 700; cursor: pointer;
        }
        .print-now-btn:hover { background: #3d2b20; }

        .section-picker { flex: 1 0 100%; padding-top: 10px; border-top: 1px dashed #d8c9bb; }
        .section-picker .sp-links { margin-left: 8px; font-weight: 400; }
        .section-picker .sp-links a { color: #8B6F5A; text-decoration: none; }
        .section-picker .sp-links a:hover { text-decoration: underline; }
        .sp-list { display: flex; flex-wrap: wrap; gap: 6px 14px; }
        .sp-item { display: inline-flex; align-items: center; gap: 5px; font-size: 11px; color: #4A3525; cursor: pointer; user-select: none; }
        .sp-item input { accent-color: #4A3525; margin: 0; cursor: pointer; }

        /* ---------- Paper ---------- */
        .report {
            width: 210mm;
            max-width: 100%;
            min-height: 297mm;
            margin: 0 auto;
            padding: 14mm 15mm;
            background: #fff;
            font-size: 11px;
            line-height: 1.4;
            display: flex;
            flex-direction: column;
        }

        /* ---------- Letterhead ---------- */
        .letterhead {
            display: grid;
            grid-template-columns: 90px minmax(0, 1fr);
            align-items: center;
            gap: 12px;
            padding-bottom: 10px;
            border-bottom: 2px solid #222;
        }

        .logo { display: flex; align-items: center; height: 64px; }
        .logo img { max-width: 100%; max-height: 100%; object-fit: contain; display: block; }

        .lh-center { text-align: center; min-width: 0; }
        .business-name { margin: 0; font-size: 18px; font-weight: 700; letter-spacing: .3px; }
        .business-contact { margin-top: 4px; font-size: 10px; line-height: 1.5; color: #333; }

        .printed-by { text-align: right; font-size: 9.5px; line-height: 1.45; color: #333; overflow-wrap: anywhere; }
        .printed-by .label { display: block; font-size: 8.5px; font-weight: 700; letter-spacing: .4px; text-transform: uppercase; color: #666; }
        .printed-by strong { display: block; font-size: 11px; color: #222; }

        /* ---------- Title band ---------- */
        .title-band {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            flex-wrap: wrap;
            gap: 6px 16px;
            margin: 12px 0 12px;
        }
        .report-name { font-size: 16px; font-weight: 700; letter-spacing: .5px; }
        .report-meta { font-size: 11px; color: #333; }
        .report-meta b { font-weight: 700; }

        /* ---------- KPI row ---------- */
        .kpis {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            border: 1px solid #333;
            border-radius: 4px;
            margin-bottom: 12px;
        }
        .kpi { padding: 8px 10px; border-left: 1px solid #ccc; min-width: 0; }
        .kpi:first-child { border-left: 0; }
        .kpi .k-label { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .3px; color: #555; }
        .kpi .k-value { margin-top: 2px; font-size: 15px; font-weight: 700; }
        .kpi .k-sub { margin-top: 1px; font-size: 9px; color: #666; }

        /* ---------- Blocks ---------- */
        .grid2 {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 12px 18px;
            margin-bottom: 12px;
        }

        .blk { min-width: 0; page-break-inside: avoid; }
        .blk.full { grid-column: 1 / -1; }

        .blk-title {
            margin: 0 0 4px;
            padding-bottom: 4px;
            border-bottom: 1px solid #333;
            font-size: 10.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .3px;
        }

        .t { width: 100%; border-collapse: collapse; }
        .t th {
            padding: 4px 3px;
            border-bottom: 1px solid #999;
            font-size: 9.5px;
            font-weight: 700;
            text-align: left;
            color: #444;
        }
        .t td { padding: 3.5px 3px; border-bottom: 1px solid #e3e3e3; font-size: 10.5px; }
        .t th:not(:first-child), .t td:not(:first-child) { text-align: right; white-space: nowrap; }
        .t tr.total td { border-top: 1px solid #333; border-bottom: 0; font-weight: 700; }
        .t tr:last-child td { border-bottom: 0; }

        .note { margin-top: 4px; font-size: 9px; color: #555; }

        .empty { padding: 14px 0; font-size: 11px; color: #666; text-align: center; }

        /* ---------- Overview ---------- */
        .ov-hl {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            margin-bottom: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        .ov-hl > div { padding: 6px 9px; border-left: 1px solid #ddd; min-width: 0; }
        .ov-hl > div:first-child { border-left: 0; }
        .ov-hl span { display: block; font-size: 8.5px; font-weight: 700; letter-spacing: .3px; text-transform: uppercase; color: #666; }
        .ov-hl strong { display: block; margin-top: 1px; font-size: 11.5px; overflow-wrap: anywhere; }
        .ov-hl em { display: block; font-style: normal; font-size: 9px; color: #666; }

        .t.ov th, .t.ov td { padding: 3px 4px; font-size: 10px; }
        .t.ov thead { display: table-header-group; }
        .t.ov tfoot { display: table-row-group; }
        .t.ov tr { page-break-inside: avoid; }
        .t.ov tr.zero td { color: #999; }
        .t.ov tr.best td { background: #f4efe8; font-weight: 700; }
        .t.ov tfoot td { padding-top: 5px; border-top: 1.5px solid #333; border-bottom: 0; font-weight: 700; }
        .t.ov .share-cell { text-align: left; }
        .t.ov .sbar { display: inline-block; width: 34px; height: 6px; margin-right: 5px; vertical-align: middle; background: #e6e0d8; border-radius: 3px; overflow: hidden; }
        .t.ov .sbar i { display: block; height: 100%; background: #4A3525; }

        /* ---------- Promotion table: fixed layout, wraps instead of sticking out ---------- */
        .t.t-promo { table-layout: fixed; width: 100%; }
        .t.t-promo th, .t.t-promo td { vertical-align: top; overflow-wrap: anywhere; }
        .t.t-promo th:not(:first-child), .t.t-promo td:not(:first-child) { white-space: normal; }
        .t.t-promo th:nth-child(2), .t.t-promo td:nth-child(2),
        .t.t-promo th:nth-child(3), .t.t-promo td:nth-child(3) { text-align: left; }
        .t.t-promo td small { color: #666; font-size: 9px; }
        .t td:first-child { overflow-wrap: anywhere; }

        .report-footer {
            margin-top: auto;
            padding-top: 8px;
            border-top: 1px solid #333;
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 12px;
            font-size: 9.5px;
            color: #333;
        }

        .report-footer-title {
            font-weight: 700;
            letter-spacing: .2px;
        }

        .report-footer .printed-by {
            flex: 0 0 auto;
            text-align: right;
        }

        /* ---------- Small screens (preview only) ---------- */
        @media (max-width: 700px) {
            .report { padding: 14px; }
            .letterhead { grid-template-columns: 1fr; text-align: center; }
            .logo { justify-content: center; }
            .report-footer { flex-direction: column; align-items: center; text-align: center; }
            .report-footer .printed-by { text-align: center; }
            .kpis { grid-template-columns: 1fr 1fr; }
            .kpi:nth-child(3) { border-left: 0; }
            .kpi:nth-child(n+3) { border-top: 1px solid #ccc; }
            .grid2 { grid-template-columns: 1fr; }
            .ov-hl { grid-template-columns: 1fr 1fr; }
            .t.ov { display: block; overflow-x: auto; }
        }

        /* ---------- Print ---------- */
        @media print {

            @page { size: A4; margin: 0; }

            body { padding: 0; background: #fff; }
            .print-controls { display: none !important; }

            .report { width: 210mm; min-height: 275mm; margin: 0; padding: 11mm 13mm; font-size: 10.5px; }

            /* Always keep the compact desktop layout on paper. */
            .letterhead { grid-template-columns: 90px minmax(0, 1fr); text-align: left; }
            .logo { justify-content: flex-start; }
            .report-footer { flex-direction: row; align-items: flex-end; text-align: left; }
            .report-footer .printed-by { text-align: right; }
            .kpis { grid-template-columns: repeat(4, minmax(0, 1fr)); }
            .kpi:nth-child(n) { border-top: 0; }
            .kpi:nth-child(n+2) { border-left: 1px solid #ccc; }
            .grid2 { grid-template-columns: 1fr 1fr; }
            .ov-hl { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        }

    </style>
</head>

<body>

<div class="print-controls">
    <div>
        <div class="print-mode-label">Sales Overview Layout</div>
        <div class="print-mode-tabs">
            <?php foreach ($allowed_modes as $printMode): ?>
                <a href="print-sales-report.php?start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>&mode=<?= urlencode($printMode) ?>&pay=<?= urlencode($pay) ?><?= $showQs ?>"
                   class="print-mode-tab <?= $mode === $printMode ? 'active' : '' ?>">
                    <?= htmlspecialchars($modeLabels[$printMode]) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div>
        <div class="print-mode-label">Payment</div>
        <div class="print-mode-tabs">
            <?php foreach ($payLabels as $payKey => $payText): ?>
                <a href="print-sales-report.php?start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>&mode=<?= urlencode($mode) ?>&pay=<?= urlencode($payKey) ?><?= $showQs ?>"
                   class="print-mode-tab <?= $pay === $payKey ? 'active' : '' ?>">
                    <?= htmlspecialchars($payKey === 'all' ? 'All' : ($payKey === 'cash' ? 'Cash' : 'GCash')) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <button type="button" class="print-now-btn" onclick="window.print()">Print Report</button>

    <div class="section-picker">
        <div class="print-mode-label">
            Include in print
            <span class="sp-links"><a href="#" id="spAll">All</a> &middot; <a href="#" id="spNone">None</a></span>
        </div>
        <div class="sp-list">
            <?php foreach ($sectionLabels as $secKey => $secText): ?>
                <label class="sp-item">
                    <input type="checkbox" class="sp-check" value="<?= $secKey ?>" <?= in_array($secKey, $selectedSections, true) ? 'checked' : '' ?>>
                    <span><?= htmlspecialchars($secText) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
    </div>
</div>


<div class="report">

    <!-- LETTERHEAD -->
    <div class="letterhead">

        <div class="logo">
            <?php if ($businessLogoUrl !== null): ?>
                <img src="<?= htmlspecialchars($businessLogoUrl, ENT_QUOTES, 'UTF-8') ?>"
                     alt="<?= htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8') ?> logo">
            <?php endif; ?>
        </div>

        <div class="lh-center">
            <h1 class="business-name"><?= htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8') ?></h1>
            <div class="business-contact">
                <div><?= htmlspecialchars($businessAddress, ENT_QUOTES, 'UTF-8') ?></div>
                <div>
                    <?= htmlspecialchars($businessEmail, ENT_QUOTES, 'UTF-8') ?>
                    &nbsp;|&nbsp;
                    <?= htmlspecialchars($businessPhone, ENT_QUOTES, 'UTF-8') ?>
                </div>
            </div>
        </div>

    </div>


    <!-- TITLE + PERIOD -->
    <div class="title-band">
        <div class="report-name">SALES REPORT</div>
        <div class="report-meta">
            <b>Period:</b>
            <?= date('F d, Y', strtotime($start_date)) ?> – <?= date('F d, Y', strtotime($end_date)) ?>
            &nbsp;&nbsp;<b>Overview:</b> <?= htmlspecialchars($modeLabel) ?>
            <?php if ($pay !== 'all'): ?>
                &nbsp;&nbsp;<b>Payment:</b> <?= htmlspecialchars($payLabel) ?>
            <?php endif; ?>
        </div>
    </div>


    <?php if ($analytics): ?>

    <div class="grid2">

        <!-- PAYMENT -->
        <div class="blk" <?= $secAttr('payment') ?>>
            <div class="blk-title">Payment Summary</div>
            <table class="t">
                <thead><tr><th>Method</th><th>Orders</th><th>Sales</th></tr></thead>
                <tbody>
                    <?php if ($pay !== 'gcash'): ?>
                    <tr><td>Cash</td><td><?= number_format($paymentSummary['Cash']['orders']) ?></td><td><?= $paPeso($paymentSummary['Cash']['sales']) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($pay !== 'cash'): ?>
                    <tr><td>GCash</td><td><?= number_format($paymentSummary['GCash']['orders']) ?></td><td><?= $paPeso($paymentSummary['GCash']['sales']) ?></td></tr>
                    <?php endif; ?>
                    <tr class="total"><td>Total</td><td><?= number_format($totalOrders) ?></td><td><?= $paPeso($totalSales) ?></td></tr>
                </tbody>
            </table>
        </div>

        <!-- CHANNEL -->
        <div class="blk" <?= $secAttr('channel') ?>>
            <div class="blk-title">Order Channel</div>
            <table class="t">
                <thead><tr><th>Channel</th><th>Orders</th><th>Sales</th><th>Share</th></tr></thead>
                <tbody>
                    <tr><td>Online</td><td><?= number_format($paCh['online']['orders']) ?></td><td><?= $paPeso($paCh['online']['sales']) ?></td><td><?= number_format(saShare($paCh['online']['sales'], $paChTotal), 1) ?>%</td></tr>
                    <tr><td>Walk-in</td><td><?= number_format($paCh['walk_in']['orders']) ?></td><td><?= $paPeso($paCh['walk_in']['sales']) ?></td><td><?= number_format(saShare($paCh['walk_in']['sales'], $paChTotal), 1) ?>%</td></tr>
                </tbody>
            </table>
        </div>

        <!-- TOP PRODUCTS -->
        <div class="blk" <?= $secAttr('products') ?>>
            <div class="blk-title">Top 5 Products</div>
            <?php if (empty($paProducts)): ?>
                <div class="empty">No items sold in this period.</div>
            <?php else: ?>
                <table class="t">
                    <thead><tr><th>Product</th><th>Qty Sold</th><th>Free</th><th>Item Sales</th></tr></thead>
                    <tbody>
                        <?php foreach ($paProducts as $paP): ?>
                            <tr><td><?= htmlspecialchars((string)$paP['name']) ?></td><td><?= number_format((int)$paP['qty']) ?></td><td><?= (int)$paP['free_qty'] > 0 ? number_format((int)$paP['free_qty']) : '-' ?></td><td><?= $paPeso($paP['sales']) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- CATEGORIES -->
        <div class="blk" <?= $secAttr('categories') ?>>
            <div class="blk-title">Top Categories</div>
            <?php if (empty($paCategories)): ?>
                <div class="empty">No items sold in this period.</div>
            <?php else: ?>
                <table class="t">
                    <thead><tr><th>Category</th><th>Qty Sold</th><th>Free</th><th>Item Sales</th></tr></thead>
                    <tbody>
                        <?php foreach ($paCategories as $paC): ?>
                            <tr><td><?= htmlspecialchars((string)$paC['category_name']) ?></td><td><?= number_format((int)$paC['qty']) ?></td><td><?= (int)$paC['free_qty'] > 0 ? number_format((int)$paC['free_qty']) : '-' ?></td><td><?= $paPeso($paC['sales']) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="note">Free promo items are not counted as sold or as sales. Item sales are before discounts.</div>
            <?php endif; ?>
        </div>

        <!-- DISCOUNTS -->
        <div class="blk" <?= $secAttr('discounts') ?>>
            <div class="blk-title">Discounts &amp; Promotions</div>
            <table class="t">
                <thead><tr><th>Type</th><th>Orders</th><th>Amount</th></tr></thead>
                <tbody>
                    <tr><td>PWD</td><td><?= number_format($analytics['idDiscounts']['pwd']['orders']) ?></td><td><?= $paPeso($analytics['idDiscounts']['pwd']['amount']) ?></td></tr>
                    <tr><td>Senior citizen</td><td><?= number_format($analytics['idDiscounts']['senior']['orders']) ?></td><td><?= $paPeso($analytics['idDiscounts']['senior']['amount']) ?></td></tr>
                    <tr><td>Promotions (value given away)</td><td><?= number_format((int)$analytics['promoSummary']['orders']) ?></td><td><?= $paPeso($analytics['promoSummary']['discount']) ?></td></tr>
                    <tr class="total"><td>Total (gross − net)</td><td></td><td><?= $paPeso($paCur['discounts']) ?></td></tr>
                </tbody>
            </table>
        </div>

        <!-- OUTCOMES -->
        <div class="blk" <?= $secAttr('outcomes') ?>>
            <div class="blk-title">Orders, Cancellations &amp; Refunds</div>
            <table class="t">
                <thead><tr><th>Measure</th><th>Orders</th><th>Amount</th></tr></thead>
                <tbody>
                    <tr><td>Placed in period</td><td><?= number_format($paOut['placed']) ?></td><td></td></tr>
                    <tr><td>Completed</td><td><?= number_format($paOut['completed']) ?></td><td></td></tr>
                    <tr><td>Cancelled (<?= number_format($paOut['cancel_rate'], 1) ?>%)</td><td><?= number_format($paOut['cancelled']) ?></td><td></td></tr>
                    <tr><td>&nbsp;&nbsp;before preparation (nothing made)</td><td><?= number_format($paOut['stage']['before']['orders']) ?></td><td></td></tr>
                    <tr><td>&nbsp;&nbsp;after preparation started (made)</td><td><?= number_format($paOut['stage']['after']['orders']) ?></td><td><?= $paPeso($paOut['stage']['after']['amount']) ?></td></tr>
                    <tr><td>In progress</td><td><?= number_format($paOut['in_progress']) ?></td><td></td></tr>
                    <tr><td>Refunds paid</td><td><?= number_format($paRef['refunded_orders']) ?></td><td><?= $paPeso($paRef['refunded_amount']) ?></td></tr>
                    <tr><td>Refunds pending</td><td><?= number_format($paRef['pending_orders']) ?></td><td><?= $paPeso($paRef['pending_amount']) ?></td></tr>
                </tbody>
            </table>
            <?php if (!empty($analytics['cancelReasons'])): ?>
                <div class="note"><b>Top cancel reasons:</b>
                    <?php
                    $paReasons = [];
                    foreach ($analytics['cancelReasons'] as $paR) {
                        $paReasons[] = htmlspecialchars((string)$paR['reason']) . ' (' . (int)$paR['orders'] . ')';
                    }
                    echo implode('; ', $paReasons);
                    ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- PROMOTION SALES -->
        <div class="blk full" <?= $secAttr('promos') ?>>
            <div class="blk-title">Promotion Sales</div>
            <?php if (empty($analytics['promotions'])): ?>
                <div class="empty">No promotions were used on completed orders in this period.</div>
            <?php else: ?>
                <table class="t t-promo">
                    <colgroup>
                        <col style="width:24%"><col style="width:21%"><col style="width:10%"><col style="width:8%">
                        <col style="width:9%"><col style="width:14%"><col style="width:14%">
                    </colgroup>
                    <thead><tr><th>Promotion</th><th>Promo Period</th><th>Status</th><th>Orders</th><th>Free Items</th><th>Value Given</th><th>Sales (Paid)</th></tr></thead>
                    <tbody>
                        <?php foreach ($analytics['promotions'] as $paPr): ?>
                            <tr>
                                <td><?= htmlspecialchars((string)$paPr['title']) ?> <small>(ID #<?= (int)$paPr['promotion_id'] ?>)</small></td>
                                <?php $paPrPeriod = saPromoPeriod($paPr['start_date'] ?? null, $paPr['end_date'] ?? null); ?>
                                <td><?= $paPrPeriod !== '' ? htmlspecialchars($paPrPeriod) : '&mdash;' ?><br><small>Sold: <?= htmlspecialchars(saPromoPeriod($paPr['first_sold'] ?? null, $paPr['last_sold'] ?? null)) ?></small></td>
                                <td><?= htmlspecialchars(saPromoStatus($paPr['is_active'] ?? null, $paPr['is_archived'] ?? null, $paPr['end_date'] ?? null)) ?></td>
                                <td><?= number_format((int)$paPr['used']) ?></td>
                                <td><?= number_format((int)$paPr['free_items']) ?></td>
                                <td><?= $paPeso($paPr['amount']) ?></td>
                                <td><?= $paPeso($paPr['sales']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="total"><td>Total</td><td></td><td></td><td><?= number_format((int)$analytics['promoSummary']['orders']) ?></td><td><?= number_format((int)$analytics['promoSummary']['free_items']) ?></td><td><?= $paPeso($analytics['promoSummary']['discount']) ?></td><td><?= $paPeso($analytics['promoSummary']['sales']) ?></td></tr>
                    </tbody>
                </table>
                <div class="note">Free items are not counted as sales. "Sales (Paid)" is what customers actually paid on orders that used a promotion.</div>
            <?php endif; ?>
        </div>

        <!-- PEAK HOURS -->
        <div class="blk" <?= $secAttr('hours') ?>>
            <div class="blk-title">Busiest Pickup Hours</div>
            <?php if (empty($paHours)): ?>
                <div class="empty">No orders in this period.</div>
            <?php else: ?>
                <table class="t">
                    <thead><tr><th>Hour</th><th>Orders</th><th>Sales</th></tr></thead>
                    <tbody>
                        <?php foreach ($paHours as $paH): ?>
                            <?php $paHr = (int)$paH['hr']; ?>
                            <tr>
                                <td><?= date('g A', strtotime(sprintf('%02d:00', $paHr))) ?> – <?= date('g A', strtotime(sprintf('%02d:00', ($paHr + 1) % 24))) ?></td>
                                <td><?= number_format((int)$paH['orders']) ?></td>
                                <td><?= $paPeso($paH['sales']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- CUSTOMERS -->
        <div class="blk" <?= $secAttr('customers') ?>>
            <div class="blk-title">Online Customers</div>
            <table class="t">
                <tbody>
                    <tr><td>Registered customers who bought</td><td><?= number_format($analytics['customers']['registered']) ?></td></tr>
                    <tr><td>First-time buyers</td><td><?= number_format($analytics['customers']['new']) ?></td></tr>
                    <tr><td>Returning customers</td><td><?= number_format($analytics['customers']['returning']) ?></td></tr>
                    <tr><td>Guest (no account) orders</td><td><?= number_format($analytics['customers']['guest_orders']) ?></td></tr>
                </tbody>
            </table>
        </div>

    </div>

    <?php endif; ?>


    <!-- SALES OVERVIEW -->
    <div class="blk" style="page-break-inside:auto;">

        <div class="blk-title">
            Sales Overview (<?= htmlspecialchars($modeLabel) ?>)<?= $pay !== 'all' ? ' &ndash; ' . htmlspecialchars($payLabel) : '' ?>
        </div>

        <?php if ($totalOrders === 0): ?>

            <div class="empty">No completed sales transactions were recorded during the selected period.</div>

        <?php else: ?>

            <table class="t ov">
                <thead>
                    <tr>
                        <th><?= $mode === 'daily' ? 'Date' : ($mode === 'weekly' ? 'Week' : 'Month') ?></th>
                        <th>Orders</th>
                        <th>Gross Sales</th>
                        <th>Discounts</th>
                        <th>Net Sales</th>
                        <th>Avg Order</th>
                        <?php if ($pay !== 'gcash'): ?><th>Cash</th><?php endif; ?>
                        <?php if ($pay !== 'cash'): ?><th>GCash</th><?php endif; ?>
                        <th class="share-cell">Share</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($salesOverview as $ovRow): ?>
                        <?php
                        $ovEmpty = $ovRow['orders'] === 0;
                        $ovIsBest = $ovBest !== null && !$ovEmpty && $ovRow['sort'] === $ovBest['sort'];
                        $ovShare = $totalSales > 0 ? ($ovRow['sales'] / $totalSales) * 100 : 0;
                        $ovCell  = static fn(float $v) => $ovEmpty ? '&ndash;' : htmlspecialchars('₱' . number_format($v, 2));
                        ?>
                        <tr class="<?= $ovEmpty ? 'zero' : '' ?> <?= $ovIsBest ? 'best' : '' ?>">
                            <td><?= htmlspecialchars($ovRow['label']) ?></td>
                            <td><?= number_format($ovRow['orders']) ?></td>
                            <td><?= $ovCell($ovRow['gross']) ?></td>
                            <td><?= $ovCell($ovRow['discount']) ?></td>
                            <td><?= $ovCell($ovRow['sales']) ?></td>
                            <td><?= $ovCell($ovRow['orders'] > 0 ? $ovRow['sales'] / $ovRow['orders'] : 0) ?></td>
                            <?php if ($pay !== 'gcash'): ?><td><?= $ovCell($ovRow['cash']) ?></td><?php endif; ?>
                            <?php if ($pay !== 'cash'): ?><td><?= $ovCell($ovRow['gcash']) ?></td><?php endif; ?>
                            <td class="share-cell">
                                <?php if ($ovEmpty): ?>&ndash;<?php else: ?>
                                    <span class="sbar"><i style="width:<?= number_format($ovShare, 1, '.', '') ?>%"></i></span><?= number_format($ovShare, 1) ?>%
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td>TOTAL</td>
                        <td><?= number_format($totalOrders) ?></td>
                        <td><?= $paPeso($ovTotals['gross']) ?></td>
                        <td><?= $paPeso($ovTotals['discount']) ?></td>
                        <td><?= $paPeso($totalSales) ?></td>
                        <td><?= $paPeso($averageOrder) ?></td>
                        <?php if ($pay !== 'gcash'): ?><td><?= $paPeso($paymentSummary['Cash']['sales']) ?></td><?php endif; ?>
                        <?php if ($pay !== 'cash'): ?><td><?= $paPeso($paymentSummary['GCash']['sales']) ?></td><?php endif; ?>
                        <td class="share-cell">100%</td>
                    </tr>
                </tfoot>
            </table>

            <div class="note">
                Gross = before discounts. Net = what customers actually paid. Only completed orders are counted,
                dated by the day the order was closed. Free promo items are not counted as sales.
            </div>

        <?php endif; ?>

    </div>


    <!-- FOOTER -->
    <div class="report-footer">
        <div class="report-footer-title">LOCAL MILKTEA HOUSE &mdash; End of Sales Report</div>
        <div class="printed-by">
            <span class="label">Printed by</span>
            <strong><?= htmlspecialchars($reportGeneratedBy, ENT_QUOTES, 'UTF-8') ?></strong>
            <span><?= htmlspecialchars($reportGeneratedByRole, ENT_QUOTES, 'UTF-8') ?></span><br>
            <span><?= date('M d, Y h:i A', strtotime($reportGeneratedAt)) ?></span>
        </div>
    </div>

</div>
<script>
(function () {
    var checks = document.querySelectorAll('.sp-check');
    var total  = checks.length;

    function apply() {
        var on = [];
        checks.forEach(function (c) {
            if (c.checked) on.push(c.value);
            document.querySelectorAll('[data-section="' + c.value + '"]').forEach(function (el) {
                el.style.display = c.checked ? '' : 'none';
            });
        });

        var show = on.length === total ? null : (on.length ? on.join(',') : 'none');

        var url = new URL(window.location.href);
        if (show === null) url.searchParams.delete('show'); else url.searchParams.set('show', show);
        history.replaceState(null, '', url.toString());

        document.querySelectorAll('a.print-mode-tab').forEach(function (a) {
            var u = new URL(a.href, window.location.href);
            if (show === null) u.searchParams.delete('show'); else u.searchParams.set('show', show);
            a.href = u.toString();
        });
    }

    checks.forEach(function (c) { c.addEventListener('change', apply); });

    function setAll(v) { checks.forEach(function (c) { c.checked = v; }); apply(); }
    document.getElementById('spAll').addEventListener('click', function (e) { e.preventDefault(); setAll(true); });
    document.getElementById('spNone').addEventListener('click', function (e) { e.preventDefault(); setAll(false); });
})();
</script>
</body>
</html>