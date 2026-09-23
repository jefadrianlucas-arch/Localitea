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
   REPORT PARAMETERS
========================================================= */

$start_date = $_GET['start_date'] ?? '';
$end_date   = $_GET['end_date'] ?? '';
$mode       = $_GET['mode'] ?? 'daily';


/* =========================================================
   VALIDATE DATES
========================================================= */

$startObject = DateTime::createFromFormat('Y-m-d', $start_date);
$endObject   = DateTime::createFromFormat('Y-m-d', $end_date);

if (
    !$startObject ||
    $startObject->format('Y-m-d') !== $start_date ||
    !$endObject ||
    $endObject->format('Y-m-d') !== $end_date
) {
    header('Location: sales-reports.php');
    exit;
}

if ($start_date > $end_date) {
    header('Location: sales-reports.php');
    exit;
}


/* =========================================================
   VALIDATE REPORT MODE
========================================================= */

$allowed_modes = [
    'daily',
    'weekly',
    'monthly'
];

if (!in_array($mode, $allowed_modes, true)) {
    $mode = 'daily';
}


/* =========================================================
   GENERATED INFORMATION
========================================================= */

$reportGeneratedAt = date('Y-m-d H:i:s');
$reportGeneratedBy = $_SESSION['user_name'] ?? 'Admin';


/* =========================================================
   GET COMPLETED SALES
========================================================= */

$salesStmt = $pdo->prepare("
    SELECT
        id,
        order_number,
        claim_number,
        payment_method,
        total_amount,
        created_at,
        closed_at
    FROM orders
    WHERE status = 'completed'
      AND DATE(COALESCE(closed_at, created_at))
          BETWEEN ? AND ?
    ORDER BY
        COALESCE(closed_at, created_at) ASC,
        id ASC
");

$salesStmt->execute([
    $start_date,
    $end_date
]);

$salesTransactions = $salesStmt->fetchAll(PDO::FETCH_ASSOC);


/* =========================================================
   SALES SUMMARY
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
   PAYMENT SUMMARY
========================================================= */

$paymentSummary = [
    'Cash' => [
        'orders' => 0,
        'sales' => 0
    ],
    'GCash' => [
        'orders' => 0,
        'sales' => 0
    ]
];

foreach ($salesTransactions as $sale) {

    $paymentMethod = strtolower(
        trim($sale['payment_method'] ?? '')
    );

    if ($paymentMethod === 'cash') {

        $paymentSummary['Cash']['orders']++;

        $paymentSummary['Cash']['sales']
            += (float)$sale['total_amount'];

    } elseif ($paymentMethod === 'gcash') {

        $paymentSummary['GCash']['orders']++;

        $paymentSummary['GCash']['sales']
            += (float)$sale['total_amount'];
    }
}


/* =========================================================
   SALES OVERVIEW
========================================================= */

$salesOverview = [];


/* ---------------------------------------------------------
   DAILY
--------------------------------------------------------- */

if ($mode === 'daily') {

    $currentDate = new DateTime($start_date);
    $lastDate = new DateTime($end_date);

    while ($currentDate <= $lastDate) {

        $dateKey = $currentDate->format('Y-m-d');

        $salesOverview[$dateKey] = [
            'label' => $currentDate->format('M d'),
            'orders' => 0,
            'sales' => 0
        ];

        $currentDate->modify('+1 day');
    }


    foreach ($salesTransactions as $sale) {

        $saleDate = date(
            'Y-m-d',
            strtotime(
                $sale['closed_at'] ?? $sale['created_at']
            )
        );

        if (isset($salesOverview[$saleDate])) {

            $salesOverview[$saleDate]['orders']++;

            $salesOverview[$saleDate]['sales']
                += (float)$sale['total_amount'];
        }
    }
}


/* ---------------------------------------------------------
   WEEKLY
--------------------------------------------------------- */

if ($mode === 'weekly') {

    foreach ($salesTransactions as $sale) {

        $saleDate = new DateTime(
            $sale['closed_at'] ?? $sale['created_at']
        );

        $year = $saleDate->format('o');
        $week = $saleDate->format('W');

        $key = $year . '-W' . $week;

        if (!isset($salesOverview[$key])) {

            $weekStart = new DateTime();

            $weekStart->setISODate(
                (int)$year,
                (int)$week,
                1
            );

            $weekEnd = clone $weekStart;
            $weekEnd->modify('+6 days');

            $salesOverview[$key] = [
                'label' =>
                    $weekStart->format('M d')
                    . ' - '
                    . $weekEnd->format('M d'),

                'orders' => 0,
                'sales' => 0,

                'sort' =>
                    $weekStart->format('Y-m-d')
            ];
        }

        $salesOverview[$key]['orders']++;

        $salesOverview[$key]['sales']
            += (float)$sale['total_amount'];
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


/* ---------------------------------------------------------
   MONTHLY
--------------------------------------------------------- */

if ($mode === 'monthly') {

    foreach ($salesTransactions as $sale) {

        $saleDate = new DateTime(
            $sale['closed_at'] ?? $sale['created_at']
        );

        $key = $saleDate->format('Y-m');

        if (!isset($salesOverview[$key])) {

            $salesOverview[$key] = [
                'label' => $saleDate->format('F Y'),
                'orders' => 0,
                'sales' => 0
            ];
        }

        $salesOverview[$key]['orders']++;

        $salesOverview[$key]['sales']
            += (float)$sale['total_amount'];
    }

    ksort($salesOverview);
}


/* =========================================================
   MODE LABEL
========================================================= */

$modeLabels = [
    'daily' => 'Daily',
    'weekly' => 'Weekly',
    'monthly' => 'Monthly'
];

$modeLabel = $modeLabels[$mode];

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Sales Report - Local Milktea House
    </title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 30px;
            background: #f2f2f2;
            color: #222;
            font-family:
                Arial,
                Helvetica,
                sans-serif;
        }


        /* =====================================================
           REPORT PAPER
        ===================================================== */

        .report {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            padding: 25mm 20mm;
            background: #ffffff;
        }


        /* =====================================================
           HEADER
        ===================================================== */

        .report-header {
            text-align: center;
            margin-bottom: 25px;
        }

        .business-name {
            margin: 0;
            font-size: 22px;
            font-weight: 700;
            letter-spacing: .3px;
        }

        .report-name {
            margin: 5px 0 20px;
            font-size: 18px;
            font-weight: 700;
        }


        /* =====================================================
           REPORT INFORMATION
        ===================================================== */

        .report-info {
            width: 100%;
            margin-bottom: 25px;
        }

        .report-info-row {
            display: flex;
            margin-bottom: 5px;
            font-size: 13px;
        }

        .report-info-label {
            width: 115px;
            font-weight: 700;
        }


        /* =====================================================
           SECTION
        ===================================================== */

        .section {
            margin-top: 24px;
        }

        .section-title {
            padding-bottom: 7px;
            border-bottom: 1px solid #333;
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
        }


        /* =====================================================
           SUMMARY
        ===================================================== */

        .summary-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        .summary-table td {
            padding: 5px 0;
            font-size: 13px;
        }

        .summary-table td:last-child {
            text-align: right;
            font-weight: 700;
        }


        /* =====================================================
           PAYMENT TABLE
        ===================================================== */

        .payment-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        .payment-table th {
            padding: 7px 5px;
            border-bottom: 1px solid #333;
            font-size: 12px;
            text-align: left;
        }

        .payment-table td {
            padding: 7px 5px;
            border-bottom: 1px solid #ddd;
            font-size: 12px;
        }

        .payment-table th:nth-child(2),
        .payment-table td:nth-child(2) {
            text-align: center;
        }

        .payment-table th:last-child,
        .payment-table td:last-child {
            text-align: right;
        }

        .payment-total td {
            border-top: 1px solid #333;
            border-bottom: none;
            font-weight: 700;
        }


        /* =====================================================
           SALES OVERVIEW
        ===================================================== */

        .overview-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        .daily-overview-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
            margin-top: 10px;
        }

        .daily-overview-table {
            width: 100%;
            border-collapse: collapse;
        }

        .daily-overview-table th {
            padding: 6px 4px;
            border-bottom: 1px solid #333;
            font-size: 10px;
            text-align: left;
        }

        .daily-overview-table td {
            padding: 4px;
            border-bottom: 1px solid #ddd;
            font-size: 10px;
        }

        .daily-overview-table th:nth-child(2),
        .daily-overview-table td:nth-child(2) {
            text-align: center;
        }

        .daily-overview-table th:last-child,
        .daily-overview-table td:last-child {
            text-align: right;
        }

        .daily-overview-total {
            margin-top: 10px;
            border-top: 1px solid #333;
            padding-top: 6px;
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            font-size: 11px;
            font-weight: 700;
        }

        .overview-table th {
            padding: 8px 5px;
            border-bottom: 1px solid #333;
            font-size: 12px;
            text-align: left;
        }

        .overview-table td {
            padding: 7px 5px;
            border-bottom: 1px solid #ddd;
            font-size: 12px;
        }

        .overview-table th:nth-child(2),
        .overview-table td:nth-child(2) {
            text-align: center;
        }

        .overview-table th:last-child,
        .overview-table td:last-child {
            text-align: right;
        }

        .overview-total td {
            border-top: 1px solid #333;
            border-bottom: none;
            font-weight: 700;
        }


        /* =====================================================
           EMPTY
        ===================================================== */

        .empty-report {
            padding: 20px 0;
            font-size: 12px;
            color: #666;
            text-align: center;
        }


        /* =====================================================
           FOOTER
        ===================================================== */

        .report-footer {
            margin-top: 35px;
            padding-top: 12px;
            border-top: 1px solid #333;
            text-align: center;
            font-size: 11px;
        }


        /* =====================================================
           PRINT REPORT CONTROLS
        ===================================================== */

        .print-controls {
            width: 210mm;
            margin: 0 auto 15px;
            padding: 14px;
            background: #ffffff;
            border: 1px solid #8B6F5A;
            border-radius: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
        }

        .print-mode-label {
            margin-bottom: 7px;
            font-size: 12px;
            font-weight: 700;
            color: #4A3525;
        }

        .print-mode-tabs {
            display: flex;
            gap: 7px;
            flex-wrap: wrap;
        }

        .print-mode-tab {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 72px;
            padding: 7px 12px;
            border: 1px solid #8B6F5A;
            border-radius: 7px;
            background: #ffffff;
            color: #4A3525;
            text-decoration: none;
            font-size: 11px;
            font-weight: 700;
        }

        .print-mode-tab:hover {
            background: #F7F1E8;
            color: #4A3525;
        }

        .print-mode-tab.active {
            background: #4A3525;
            border-color: #4A3525;
            color: #ffffff;
        }

        .print-now-btn {
            border: 1px solid #4A3525;
            border-radius: 7px;
            padding: 8px 13px;
            background: #4A3525;
            color: #ffffff;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
        }

        .print-now-btn:hover {
            background: #3d2b20;
        }

        /* =====================================================
           PRINT
        ===================================================== */

        @media print {

            @page {
                size: A4;
                margin: 0;
            }

            body {
                padding: 0;
                background: #ffffff;
            }

            .print-controls {
                display: none !important;
            }

            .report {
                width: 210mm;
                min-height: 297mm;
                margin: 0;
                padding: 14mm 17mm;
            }

            .report-header {
                margin-bottom: 18px;
            }

            .report-info {
                margin-bottom: 18px;
            }

            .report-info-row {
                margin-bottom: 4px;
                font-size: 12px;
            }

            .section {
                margin-top: 16px;
            }

            .summary-table td {
                padding: 3px 0;
            }

            .payment-table th,
            .payment-table td {
                padding: 5px 4px;
            }

            .overview-table th,
            .overview-table td {
                padding: 5px 4px;
            }

            .daily-overview-grid {
                gap: 14px;
            }

            .daily-overview-table th {
                padding: 5px 4px;
            }

            .daily-overview-table td {
                padding: 3px 4px;
            }

            .report-footer {
                margin-top: 22px;
            }

        }


    </style>

</head>


<body>


<div class="print-controls">

    <div>
        <div class="print-mode-label">
            Report Overview
        </div>

        <div class="print-mode-tabs">

            <?php foreach ($allowed_modes as $printMode): ?>

                <a
                    href="print-sales-report.php?start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>&mode=<?= urlencode($printMode) ?>"
                    class="print-mode-tab <?= $mode === $printMode ? 'active' : '' ?>"
                >
                    <?= htmlspecialchars($modeLabels[$printMode]) ?>
                </a>

            <?php endforeach; ?>

        </div>
    </div>

    <button
        type="button"
        class="print-now-btn"
        onclick="window.print()"
    >
        Print Report
    </button>

</div>


<div class="report">


    <!-- =====================================================
         HEADER
    ====================================================== -->

    <div class="report-header">

        <h1 class="business-name">
            LOCAL MILKTEA HOUSE
        </h1>

        <div class="report-name">
            SALES REPORT
        </div>

    </div>


    <!-- =====================================================
         REPORT INFORMATION
         Shows the selected reporting period and the exact
         date/time when this printable report was generated.
    ====================================================== -->

    <div class="report-info">

        <div class="report-info-row">
            <div class="report-info-label">
                Report Period
            </div>

            <div>
                <?= date('F d, Y', strtotime($start_date)) ?>
                – 
                <?= date('F d, Y', strtotime($end_date)) ?>
            </div>
        </div>

        <div class="report-info-row">
            <div class="report-info-label">
                Report Type
            </div>

            <div>
                <?= htmlspecialchars($modeLabel) ?>
            </div>
        </div>

        <div class="report-info-row">
            <div class="report-info-label">
                Generated On
            </div>

            <div>
                <?= date('F d, Y h:i A', strtotime($reportGeneratedAt)) ?>
            </div>
        </div>

        <div class="report-info-row">
            <div class="report-info-label">
                Generated By
            </div>

            <div>
                <?= htmlspecialchars($reportGeneratedBy) ?>
            </div>
        </div>

    </div>

    <!-- =====================================================
         SALES SUMMARY
    ====================================================== -->

    <div class="section">

        <div class="section-title">
            Sales Summary
        </div>


        <table class="summary-table">

            <tr>

                <td>
                    Total Sales
                </td>

                <td>
                    ₱<?= number_format($totalSales, 2) ?>
                </td>

            </tr>


            <tr>

                <td>
                    Total Completed Orders
                </td>

                <td>
                    <?= number_format($totalOrders) ?>
                </td>

            </tr>


            <tr>

                <td>
                    Average Order
                </td>

                <td>
                    ₱<?= number_format($averageOrder, 2) ?>
                </td>

            </tr>

        </table>

    </div>


    <!-- =====================================================
         PAYMENT SUMMARY
    ====================================================== -->

    <div class="section">

        <div class="section-title">
            Payment Summary
        </div>


        <table class="payment-table">

            <thead>

                <tr>

                    <th>
                        Payment Method
                    </th>

                    <th>
                        Orders
                    </th>

                    <th>
                        Sales
                    </th>

                </tr>

            </thead>


            <tbody>

                <tr>

                    <td>
                        Cash
                    </td>

                    <td>
                        <?= $paymentSummary['Cash']['orders'] ?>
                    </td>

                    <td>
                        ₱<?= number_format(
                            $paymentSummary['Cash']['sales'],
                            2
                        ) ?>
                    </td>

                </tr>


                <tr>

                    <td>
                        GCash
                    </td>

                    <td>
                        <?= $paymentSummary['GCash']['orders'] ?>
                    </td>

                    <td>
                        ₱<?= number_format(
                            $paymentSummary['GCash']['sales'],
                            2
                        ) ?>
                    </td>

                </tr>


                <tr class="payment-total">

                    <td>
                        Total
                    </td>

                    <td>
                        <?= number_format($totalOrders) ?>
                    </td>

                    <td>
                        ₱<?= number_format(
                            $totalSales,
                            2
                        ) ?>
                    </td>

                </tr>

            </tbody>

        </table>

    </div>


    <!-- =====================================================
         SALES OVERVIEW
    ====================================================== -->

    <div class="section">

        <div class="section-title">

            Sales Overview
            (<?= htmlspecialchars($modeLabel) ?>)

        </div>


        <?php if (empty($salesOverview)): ?>

            <div class="empty-report">

                No completed sales transactions were recorded
                during the selected period.

            </div>

        <?php elseif ($mode === 'daily'): ?>

            <?php
                $dailyPeriods = array_values($salesOverview);
                $dailySplit = (int) ceil(count($dailyPeriods) / 2);
                $dailyLeft = array_slice($dailyPeriods, 0, $dailySplit);
                $dailyRight = array_slice($dailyPeriods, $dailySplit);
            ?>

            <?php if (count($dailyPeriods) <= 14): ?>

                <table class="overview-table">

                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Completed Orders</th>
                            <th>Sales</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($dailyPeriods as $period): ?>
                            <tr>
                                <td><?= htmlspecialchars($period['label']) ?></td>
                                <td><?= number_format($period['orders']) ?></td>
                                <td>₱<?= number_format($period['sales'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>

                </table>

            <?php else: ?>

                <div class="daily-overview-grid">

                    <table class="daily-overview-table">

                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Orders</th>
                                <th>Sales</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($dailyLeft as $period): ?>
                                <tr>
                                    <td><?= htmlspecialchars($period['label']) ?></td>
                                    <td><?= number_format($period['orders']) ?></td>
                                    <td>₱<?= number_format($period['sales'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>

                    </table>

                    <table class="daily-overview-table">

                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Orders</th>
                                <th>Sales</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($dailyRight as $period): ?>
                                <tr>
                                    <td><?= htmlspecialchars($period['label']) ?></td>
                                    <td><?= number_format($period['orders']) ?></td>
                                    <td>₱<?= number_format($period['sales'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

            <div class="daily-overview-total">
                <div>TOTAL</div>
                <div style="text-align:center;"><?= number_format($totalOrders) ?></div>
                <div style="text-align:right;">₱<?= number_format($totalSales, 2) ?></div>
            </div>

        <?php else: ?>

            <table class="overview-table">

                <thead>
                    <tr>
                        <th>
                            <?= $mode === 'daily' ? 'Date' : 'Period' ?>
                        </th>
                        <th>Completed Orders</th>
                        <th>Sales</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ($salesOverview as $period): ?>
                        <tr>
                            <td><?= htmlspecialchars($period['label']) ?></td>
                            <td><?= number_format($period['orders']) ?></td>
                            <td>₱<?= number_format($period['sales'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>

                    <tr class="overview-total">
                        <td>TOTAL</td>
                        <td><?= number_format($totalOrders) ?></td>
                        <td>₱<?= number_format($totalSales, 2) ?></td>
                    </tr>

                </tbody>

            </table>

        <?php endif; ?>

    </div>


    <!-- =====================================================
         FOOTER
    ====================================================== -->

    <div class="report-footer">

        LOCAL MILKTEA HOUSE<br>

        End of Sales Report

    </div>


</div>





</body>
</html>