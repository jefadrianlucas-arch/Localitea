<?php

require_once '../includes/db.php';
require_once '../includes/sales-analytics.php';
saUsePhilippineTime($pdo);

/* ADMIN ACCESS */
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['admin'], true)) {
    header('Location: ../auth/login.php');
    exit;
}

/* PARAMETERS */
$start_date = $_GET['start_date'] ?? '';
$end_date   = $_GET['end_date'] ?? '';

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

$pay = strtolower(trim((string)($_GET['pay'] ?? 'all')));
if (!in_array($pay, ['all', 'cash', 'gcash'], true)) {
    $pay = 'all';
}
$payLabels = ['all' => 'All payments', 'cash' => 'Cash only', 'gcash' => 'GCash only'];
$payLabel  = $payLabels[$pay];

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


$analytics = salesAnalytics($pdo, $start_date, $end_date, $pay);
$paPeso    = static fn($v) => '₱' . number_format((float)$v, 2);

$paProducts   = $analytics ? $analytics['topProducts'] : [];
$paCategories = $analytics ? $analytics['categories'] : [];

$backQs = 'start_date=' . urlencode($start_date) . '&end_date=' . urlencode($end_date);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Sales Report - Local Milktea House</title>

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

        .t.t-prod td:first-child { width: 55%; }
        .blk.gap { margin-bottom: 16px; }
    </style>
</head>

<body>

<div class="print-controls">
    <div>
        <div class="print-mode-label">Payment</div>
        <div class="print-mode-tabs">
            <?php foreach ($payLabels as $payKey => $payText): ?>
                <a href="print-product-report.php?<?= $backQs ?>&pay=<?= urlencode($payKey) ?>"
                   class="print-mode-tab <?= $pay === $payKey ? 'active' : '' ?>">
                    <?= htmlspecialchars($payKey === 'all' ? 'All' : ($payKey === 'cash' ? 'Cash' : 'GCash')) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="print-mode-tabs">
        <a href="print-sales-report.php?<?= $backQs ?>&pay=<?= urlencode($pay) ?>" class="print-mode-tab">&larr; Sales Report</a>
        <button type="button" class="print-now-btn" onclick="window.print()">Print Report</button>
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



    <div class="title-band">
        <div class="report-name">PRODUCT SALES REPORT</div>
        <div class="report-meta">
            <b>Period:</b>
            <?= date('F d, Y', strtotime($start_date)) ?> – <?= date('F d, Y', strtotime($end_date)) ?>
            <?php if ($pay !== 'all'): ?>
                &nbsp;&nbsp;<b>Payment:</b> <?= htmlspecialchars($payLabel) ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$analytics): ?>
        <div class="empty">Product data is unavailable right now.</div>
    <?php else: ?>

    <div class="blk gap">
        <div class="blk-title">Top 10 Products</div>
        <?php if (empty($paProducts)): ?>
            <div class="empty">No items sold in this period.</div>
        <?php else: ?>
            <table class="t">
                <thead><tr><th>#</th><th>Product</th><th>Qty Sold</th><th>Free</th><th>Item Sales</th></tr></thead>
                <tbody>
                    <?php foreach ($paProducts as $i => $paP): ?>
                        <tr><td style="text-align:left"><?= $i + 1 ?></td><td style="text-align:left"><?= htmlspecialchars((string)$paP['name']) ?></td><td><?= number_format((int)$paP['qty']) ?></td><td><?= (int)$paP['free_qty'] > 0 ? number_format((int)$paP['free_qty']) : '-' ?></td><td><?= $paPeso($paP['sales']) ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="blk gap">
        <div class="blk-title">Sales by Category</div>
        <?php if (empty($paCategories)): ?>
            <div class="empty">No items sold in this period.</div>
        <?php else: ?>
            <table class="t">
                <thead><tr><th>#</th><th>Category</th><th>Qty Sold</th><th>Free</th><th>Item Sales</th></tr></thead>
                <tbody>
                    <?php foreach ($paCategories as $i => $paC): ?>
                        <tr><td style="text-align:left"><?= $i + 1 ?></td><td style="text-align:left"><?= htmlspecialchars((string)$paC['category_name']) ?></td><td><?= number_format((int)$paC['qty']) ?></td><td><?= (int)$paC['free_qty'] > 0 ? number_format((int)$paC['free_qty']) : '-' ?></td><td><?= $paPeso($paC['sales']) ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <div class="note">Free promo items are not counted as sold or as sales. Item sales are before discounts.</div>
    </div>

    <?php endif; ?>

    <!-- FOOTER -->
    <div class="report-footer">
        <div class="report-footer-title">LOCAL MILKTEA HOUSE &mdash; End of Product Sales Report</div>
        <div class="printed-by">
            <span class="label">Printed by</span>
            <strong><?= htmlspecialchars($reportGeneratedBy, ENT_QUOTES, 'UTF-8') ?></strong>
            <span><?= htmlspecialchars($reportGeneratedByRole, ENT_QUOTES, 'UTF-8') ?></span><br>
            <span><?= date('M d, Y h:i A', strtotime($reportGeneratedAt)) ?></span>
        </div>
    </div>


</div>
</body>
</html>