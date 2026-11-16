<?php

require_once '../includes/db.php';
date_default_timezone_set('Asia/Manila');

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
    SELECT id, order_number, claim_number, payment_method, total_amount, created_at, closed_at
    FROM orders
    WHERE status = 'completed'
      AND DATE(COALESCE(closed_at, created_at)) BETWEEN ? AND ?
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
========================================================= */
$salesOverview = [];

if ($mode === 'daily') {

    $currentDate = new DateTime($start_date);
    $lastDate    = new DateTime($end_date);

    while ($currentDate <= $lastDate) {
        $salesOverview[$currentDate->format('Y-m-d')] = [
            'label' => $currentDate->format('M d'), 'orders' => 0, 'sales' => 0
        ];
        $currentDate->modify('+1 day');
    }

    foreach ($salesTransactions as $sale) {
        $saleDate = date('Y-m-d', strtotime($sale['closed_at'] ?? $sale['created_at']));

        if (isset($salesOverview[$saleDate])) {
            $salesOverview[$saleDate]['orders']++;
            $salesOverview[$saleDate]['sales'] += (float)$sale['total_amount'];
        }
    }
}

if ($mode === 'weekly') {

    foreach ($salesTransactions as $sale) {
        $saleDate = new DateTime($sale['closed_at'] ?? $sale['created_at']);
        $year = $saleDate->format('o');
        $week = $saleDate->format('W');
        $key  = $year . '-W' . $week;

        if (!isset($salesOverview[$key])) {
            $weekStart = new DateTime();
            $weekStart->setISODate((int)$year, (int)$week, 1);
            $weekEnd = clone $weekStart;
            $weekEnd->modify('+6 days');

            $salesOverview[$key] = [
                'label'  => $weekStart->format('M d') . ' - ' . $weekEnd->format('M d'),
                'orders' => 0,
                'sales'  => 0,
                'sort'   => $weekStart->format('Y-m-d')
            ];
        }

        $salesOverview[$key]['orders']++;
        $salesOverview[$key]['sales'] += (float)$sale['total_amount'];
    }

    uasort($salesOverview, function ($a, $b) {
        return strcmp($a['sort'], $b['sort']);
    });
}

if ($mode === 'monthly') {

    foreach ($salesTransactions as $sale) {
        $saleDate = new DateTime($sale['closed_at'] ?? $sale['created_at']);
        $key = $saleDate->format('Y-m');

        if (!isset($salesOverview[$key])) {
            $salesOverview[$key] = ['label' => $saleDate->format('F Y'), 'orders' => 0, 'sales' => 0];
        }

        $salesOverview[$key]['orders']++;
        $salesOverview[$key]['sales'] += (float)$sale['total_amount'];
    }

    ksort($salesOverview);
}

/* =========================================================
   ANALYTICS
========================================================= */
require_once '../includes/sales-analytics.php';
$analytics = salesAnalytics($pdo, $start_date, $end_date);

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

/* Overview rows, split in two columns when long. */
$overviewRows  = array_values($salesOverview);
$overviewSplit = count($overviewRows) > 12;
$overviewCols  = $overviewSplit
    ? array_chunk($overviewRows, (int)ceil(count($overviewRows) / 2))
    : [$overviewRows];

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
        }

        /* ---------- Letterhead ---------- */
        .letterhead {
            display: grid;
            grid-template-columns: 90px minmax(0, 1fr) 130px;
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

        /* ---------- Takeaways ---------- */
        .takeaways {
            margin-bottom: 12px;
            padding: 8px 12px;
            border: 1px solid #ccc;
            border-left: 4px solid #333;
            border-radius: 3px;
            page-break-inside: avoid;
        }
        .takeaways .blk-title { border: 0; padding: 0; margin: 0 0 4px; }
        .takeaways ul { margin: 0; padding-left: 16px; font-size: 10.5px; }
        .takeaways li { margin-bottom: 2px; }

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
        .overview-wrap { display: grid; gap: 18px; grid-template-columns: 1fr; }
        .overview-wrap.two { grid-template-columns: 1fr 1fr; }
        .overview-total {
            display: grid;
            grid-template-columns: 1fr auto auto;
            gap: 20px;
            margin-top: 6px;
            padding-top: 5px;
            border-top: 1px solid #333;
            font-size: 11px;
            font-weight: 700;
        }

        .report-footer {
            margin-top: 16px;
            padding-top: 8px;
            border-top: 1px solid #333;
            text-align: center;
            font-size: 9.5px;
            color: #333;
        }

        /* ---------- Small screens (preview only) ---------- */
        @media (max-width: 700px) {
            .report { padding: 14px; }
            .letterhead { grid-template-columns: 1fr; text-align: center; }
            .logo { justify-content: center; }
            .printed-by { text-align: center; }
            .kpis { grid-template-columns: 1fr 1fr; }
            .kpi:nth-child(3) { border-left: 0; }
            .kpi:nth-child(n+3) { border-top: 1px solid #ccc; }
            .grid2, .overview-wrap.two { grid-template-columns: 1fr; }
        }

        /* ---------- Print ---------- */
        @media print {

            @page { size: A4; margin: 0; }

            body { padding: 0; background: #fff; }
            .print-controls { display: none !important; }

            .report { width: 210mm; min-height: 0; margin: 0; padding: 11mm 13mm; font-size: 10.5px; }

            /* Always keep the compact desktop layout on paper. */
            .letterhead { grid-template-columns: 90px minmax(0, 1fr) 130px; text-align: left; }
            .logo { justify-content: flex-start; }
            .printed-by { text-align: right; }
            .kpis { grid-template-columns: repeat(4, minmax(0, 1fr)); }
            .kpi:nth-child(n) { border-top: 0; }
            .kpi:nth-child(n+2) { border-left: 1px solid #ccc; }
            .grid2 { grid-template-columns: 1fr 1fr; }
            .overview-wrap.two { grid-template-columns: 1fr 1fr; }
        }

    </style>
</head>

<body>

<div class="print-controls">
    <div>
        <div class="print-mode-label">Sales Overview Layout</div>
        <div class="print-mode-tabs">
            <?php foreach ($allowed_modes as $printMode): ?>
                <a href="print-sales-report.php?start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>&mode=<?= urlencode($printMode) ?>"
                   class="print-mode-tab <?= $mode === $printMode ? 'active' : '' ?>">
                    <?= htmlspecialchars($modeLabels[$printMode]) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <button type="button" class="print-now-btn" onclick="window.print()">Print Report</button>
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

        <div class="printed-by">
            <span class="label">Printed by</span>
            <strong><?= htmlspecialchars($reportGeneratedBy, ENT_QUOTES, 'UTF-8') ?></strong>
            <span><?= htmlspecialchars($reportGeneratedByRole, ENT_QUOTES, 'UTF-8') ?></span><br>
            <span><?= date('M d, Y h:i A', strtotime($reportGeneratedAt)) ?></span>
        </div>

    </div>


    <!-- TITLE + PERIOD -->
    <div class="title-band">
        <div class="report-name">SALES REPORT</div>
        <div class="report-meta">
            <b>Period:</b>
            <?= date('F d, Y', strtotime($start_date)) ?> – <?= date('F d, Y', strtotime($end_date)) ?>
            &nbsp;&nbsp;<b>Overview:</b> <?= htmlspecialchars($modeLabel) ?>
        </div>
    </div>


    <!-- KPIs -->
    <div class="kpis">
        <div class="kpi">
            <div class="k-label">Total Sales</div>
            <div class="k-value"><?= $paPeso($totalSales) ?></div>
            <div class="k-sub"><?= $analytics ? htmlspecialchars($paChange($paCur['net'], $paPrev['net'])) : '&nbsp;' ?></div>
        </div>
        <div class="kpi">
            <div class="k-label">Completed Orders</div>
            <div class="k-value"><?= number_format($totalOrders) ?></div>
            <div class="k-sub"><?= $analytics ? htmlspecialchars($paChange($paCur['orders'], $paPrev['orders'])) : '&nbsp;' ?></div>
        </div>
        <div class="kpi">
            <div class="k-label">Average Order</div>
            <div class="k-value"><?= $paPeso($averageOrder) ?></div>
            <div class="k-sub"><?= $analytics ? htmlspecialchars($paChange($paCur['avg'], $paPrev['avg'])) : '&nbsp;' ?></div>
        </div>
        <div class="kpi">
            <div class="k-label">Discounts Given</div>
            <div class="k-value"><?= $analytics ? $paPeso($paCur['discounts']) : '—' ?></div>
            <div class="k-sub"><?= $analytics ? 'Gross ' . $paPeso($paCur['gross']) : '&nbsp;' ?></div>
        </div>
    </div>


    <?php if ($analytics): ?>

    <!-- KEY TAKEAWAYS -->
    <?php if (!empty($analytics['insights'])): ?>
        <div class="takeaways">
            <div class="blk-title">Key Takeaways</div>
            <ul>
                <?php foreach ($analytics['insights'] as $paLine): ?>
                    <li><?= htmlspecialchars($paLine) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="grid2">

        <!-- PAYMENT -->
        <div class="blk">
            <div class="blk-title">Payment Summary</div>
            <table class="t">
                <thead><tr><th>Method</th><th>Orders</th><th>Sales</th></tr></thead>
                <tbody>
                    <tr><td>Cash</td><td><?= number_format($paymentSummary['Cash']['orders']) ?></td><td><?= $paPeso($paymentSummary['Cash']['sales']) ?></td></tr>
                    <tr><td>GCash</td><td><?= number_format($paymentSummary['GCash']['orders']) ?></td><td><?= $paPeso($paymentSummary['GCash']['sales']) ?></td></tr>
                    <tr class="total"><td>Total</td><td><?= number_format($totalOrders) ?></td><td><?= $paPeso($totalSales) ?></td></tr>
                </tbody>
            </table>
        </div>

        <!-- CHANNEL -->
        <div class="blk">
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
        <div class="blk">
            <div class="blk-title">Top 5 Products</div>
            <?php if (empty($paProducts)): ?>
                <div class="empty">No items sold in this period.</div>
            <?php else: ?>
                <table class="t">
                    <thead><tr><th>Product</th><th>Qty</th><th>Item Sales</th></tr></thead>
                    <tbody>
                        <?php foreach ($paProducts as $paP): ?>
                            <tr><td><?= htmlspecialchars((string)$paP['name']) ?></td><td><?= number_format((int)$paP['qty']) ?></td><td><?= $paPeso($paP['sales']) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- CATEGORIES -->
        <div class="blk">
            <div class="blk-title">Top Categories</div>
            <?php if (empty($paCategories)): ?>
                <div class="empty">No items sold in this period.</div>
            <?php else: ?>
                <table class="t">
                    <thead><tr><th>Category</th><th>Qty</th><th>Item Sales</th></tr></thead>
                    <tbody>
                        <?php foreach ($paCategories as $paC): ?>
                            <tr><td><?= htmlspecialchars((string)$paC['category_name']) ?></td><td><?= number_format((int)$paC['qty']) ?></td><td><?= $paPeso($paC['sales']) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="note">Item sales are before discounts.</div>
            <?php endif; ?>
        </div>

        <!-- DISCOUNTS -->
        <div class="blk">
            <div class="blk-title">Discounts &amp; Promotions</div>
            <table class="t">
                <thead><tr><th>Type</th><th>Orders</th><th>Amount</th></tr></thead>
                <tbody>
                    <tr><td>PWD</td><td><?= number_format($analytics['idDiscounts']['pwd']['orders']) ?></td><td><?= $paPeso($analytics['idDiscounts']['pwd']['amount']) ?></td></tr>
                    <tr><td>Senior citizen</td><td><?= number_format($analytics['idDiscounts']['senior']['orders']) ?></td><td><?= $paPeso($analytics['idDiscounts']['senior']['amount']) ?></td></tr>
                    <?php foreach ($analytics['promotions'] as $paPr): ?>
                        <tr><td>Promo: <?= htmlspecialchars((string)$paPr['title']) ?></td><td><?= number_format((int)$paPr['used']) ?></td><td><?= $paPeso($paPr['amount']) ?></td></tr>
                    <?php endforeach; ?>
                    <tr class="total"><td>Total (gross − net)</td><td></td><td><?= $paPeso($paCur['discounts']) ?></td></tr>
                </tbody>
            </table>
        </div>

        <!-- OUTCOMES -->
        <div class="blk">
            <div class="blk-title">Orders, Cancellations &amp; Refunds</div>
            <table class="t">
                <thead><tr><th>Measure</th><th>Orders</th><th>Amount</th></tr></thead>
                <tbody>
                    <tr><td>Placed in period</td><td><?= number_format($paOut['placed']) ?></td><td></td></tr>
                    <tr><td>Completed</td><td><?= number_format($paOut['completed']) ?></td><td></td></tr>
                    <tr><td>Cancelled (<?= number_format($paOut['cancel_rate'], 1) ?>%)</td><td><?= number_format($paOut['cancelled']) ?></td><td><?= $paPeso($paOut['cancelled_amount']) ?></td></tr>
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

        <!-- PEAK HOURS -->
        <div class="blk">
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
        <div class="blk">
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

        <div class="blk-title">Sales Overview (<?= htmlspecialchars($modeLabel) ?>)</div>

        <?php if (empty($salesOverview)): ?>

            <div class="empty">No completed sales transactions were recorded during the selected period.</div>

        <?php else: ?>

            <div class="overview-wrap <?= $overviewSplit ? 'two' : '' ?>">
                <?php foreach ($overviewCols as $colRows): ?>
                    <table class="t">
                        <thead>
                            <tr>
                                <th><?= $mode === 'daily' ? 'Date' : 'Period' ?></th>
                                <th>Orders</th>
                                <th>Sales</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($colRows as $period): ?>
                                <tr>
                                    <td><?= htmlspecialchars($period['label']) ?></td>
                                    <td><?= number_format($period['orders']) ?></td>
                                    <td><?= $paPeso($period['sales']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endforeach; ?>
            </div>

            <div class="overview-total">
                <div>TOTAL</div>
                <div><?= number_format($totalOrders) ?> orders</div>
                <div><?= $paPeso($totalSales) ?></div>
            </div>

        <?php endif; ?>

    </div>


    <!-- FOOTER -->
    <div class="report-footer">
        LOCAL MILKTEA HOUSE &mdash; End of Sales Report
    </div>

</div>
</body>
</html>