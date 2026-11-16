<?php
/*
 * Sales analytics shared by sales-reports.php (screen) and
 * print-sales-report.php (print) so both always show the same numbers.
 *
 * Save this file as includes/sales-analytics.php (next to db.php).
 *
 * Definitions used everywhere:
 *   - A SALE is an order with status = 'completed', dated by
 *     COALESCE(closed_at, created_at) (same rule your report already uses).
 *   - Net sales   = SUM(total_amount)          (what the customer paid)
 *   - Gross sales = SUM(subtotal)              (before any discount)
 *   - Discounts   = Gross - Net                (PWD/Senior + promotions)
 *   - Cancelled / placed orders are dated by created_at.
 *   - Refunds are dated by refund_processed_at.
 *   - FREE ITEMS (Buy 1 Get 1 / Buy X Get Y rewards) are saved in order_items
 *     with a name ending in " (FREE)". They are NOT sales: they are never
 *     counted as units sold, and only the add-ons the customer actually paid
 *     for on a free drink count as item sales. They are reported separately
 *     as "Free" units and under "Promotion sales".
 *   - All dates/times are Philippine time (Asia/Manila, UTC+8), regardless of
 *     the server's or Railway's own clock.
 */

/*
 * Force Philippine time for PHP *and* for this MySQL connection.
 * Call it right after db.php is loaded. It only affects the current
 * request/connection, so nothing on Railway needs to be configured.
 *
 * Note: a fixed offset ('+08:00') is used instead of 'Asia/Manila' because
 * managed MySQL servers often don't have the named time-zone tables loaded.
 * The Philippines has no daylight saving, so +08:00 is always correct.
 */
function saUsePhilippineTime(PDO $pdo): void
{
    date_default_timezone_set('Asia/Manila');

    try {
        $pdo->exec("SET time_zone = '+08:00'");
    } catch (Throwable $e) {
        error_log('saUsePhilippineTime failed: ' . $e->getMessage());
    }
}

function saPercentChange(float $current, float $previous): ?float
{
    if ($previous <= 0) {
        return null;               // no baseline to compare with
    }

    return (($current - $previous) / $previous) * 100;
}

function saShare(float $part, float $whole): float
{
    return $whole > 0 ? ($part / $whole) * 100 : 0.0;
}

function salesAnalytics(PDO $pdo, string $start, string $end): ?array
{
    try {

        saUsePhilippineTime($pdo);

        $saleDate = "DATE(COALESCE(o.closed_at, o.created_at))";
        $saleRange = "o.status = 'completed' AND $saleDate BETWEEN ? AND ?";

        /* ---------- previous period (same length, directly before) ---------- */
        $startObj = new DateTime($start);
        $endObj   = new DateTime($end);
        $days     = $startObj->diff($endObj)->days + 1;

        $prevEndObj = (clone $startObj)->modify('-1 day');
        $prevStartObj = (clone $prevEndObj)->modify('-' . ($days - 1) . ' days');

        $prevStart = $prevStartObj->format('Y-m-d');
        $prevEnd   = $prevEndObj->format('Y-m-d');

        $totals = function (string $s, string $e) use ($pdo, $saleRange): array {
            $st = $pdo->prepare("
                SELECT
                    COUNT(*) AS orders,
                    COALESCE(SUM(o.total_amount), 0) AS net,
                    COALESCE(SUM(o.subtotal), 0) AS gross
                FROM orders o
                WHERE $saleRange
            ");
            $st->execute([$s, $e]);
            $r = $st->fetch(PDO::FETCH_ASSOC);

            $orders = (int)$r['orders'];
            $net    = (float)$r['net'];
            $gross  = (float)$r['gross'];

            return [
                'orders'    => $orders,
                'net'       => $net,
                'gross'     => $gross,
                'discounts' => max(0.0, $gross - $net),
                'avg'       => $orders > 0 ? $net / $orders : 0.0,
            ];
        };

        $current  = $totals($start, $end);
        $previous = $totals($prevStart, $prevEnd);

        /* ---------- channel: online vs walk-in ---------- */
        $st = $pdo->prepare("
            SELECT o.order_source AS label,
                   COUNT(*) AS orders,
                   COALESCE(SUM(o.total_amount), 0) AS sales
            FROM orders o
            WHERE $saleRange
            GROUP BY o.order_source
        ");
        $st->execute([$start, $end]);
        $channel = ['online' => ['orders' => 0, 'sales' => 0.0],
                    'walk_in' => ['orders' => 0, 'sales' => 0.0]];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (isset($channel[$r['label']])) {
                $channel[$r['label']] = [
                    'orders' => (int)$r['orders'],
                    'sales'  => (float)$r['sales'],
                ];
            }
        }

        /* ---------- payment method ---------- */
        $st = $pdo->prepare("
            SELECT o.payment_method AS label,
                   COUNT(*) AS orders,
                   COALESCE(SUM(o.total_amount), 0) AS sales
            FROM orders o
            WHERE $saleRange
            GROUP BY o.payment_method
        ");
        $st->execute([$start, $end]);
        $payment = ['cash' => ['orders' => 0, 'sales' => 0.0],
                    'gcash' => ['orders' => 0, 'sales' => 0.0]];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (isset($payment[$r['label']])) {
                $payment[$r['label']] = [
                    'orders' => (int)$r['orders'],
                    'sales'  => (float)$r['sales'],
                ];
            }
        }

        /* ---------- item sales (free promo items are NOT sales) ----------
         * A free reward line is saved as "<name> (FREE)" at its normal price.
         *   - units sold  = paid lines only (free units are shown separately)
         *   - item sales  = paid lines + the add-ons the customer paid for on
         *                   the free drink. Add-on money on a free line is
         *                   (free lines total - promo discount) of that order,
         *                   spread across its free lines.
         */
        $isFree    = "(oi.product_name LIKE '% (FREE)')";
        $cleanName = "TRIM(TRAILING ' (FREE)' FROM oi.product_name)";

        $itemJoins = "
            JOIN orders o ON o.id = oi.order_id
            LEFT JOIN (
                SELECT order_id, SUM(subtotal) AS free_total
                FROM order_items
                WHERE product_name LIKE '% (FREE)'
                GROUP BY order_id
            ) fr ON fr.order_id = o.id
            LEFT JOIN (
                SELECT order_id, SUM(discount_amount) AS promo_disc
                FROM order_promotions
                GROUP BY order_id
            ) pd ON pd.order_id = o.id
        ";

        $paidQty   = "SUM(CASE WHEN $isFree THEN 0 ELSE oi.quantity END)";
        $freeQty   = "SUM(CASE WHEN $isFree THEN oi.quantity ELSE 0 END)";
        $paidSales = "SUM(CASE WHEN $isFree
                        THEN COALESCE(oi.subtotal
                             * GREATEST(0, fr.free_total - COALESCE(pd.promo_disc, 0))
                             / NULLIF(fr.free_total, 0), 0)
                        ELSE oi.subtotal END)";

        /* ---------- top products ---------- */
        $st = $pdo->prepare("
            SELECT $cleanName AS name,
                   $paidQty AS qty,
                   $freeQty AS free_qty,
                   $paidSales AS sales
            FROM order_items oi
            $itemJoins
            WHERE $saleRange
            GROUP BY name
            HAVING qty > 0
            ORDER BY qty DESC, sales DESC
            LIMIT 10
        ");
        $st->execute([$start, $end]);
        $topProducts = $st->fetchAll(PDO::FETCH_ASSOC);

        /* ---------- categories ---------- */
        $st = $pdo->prepare("
            SELECT COALESCE(c.name, 'Uncategorized') AS category_name,
                   $paidQty AS qty,
                   $freeQty AS free_qty,
                   $paidSales AS sales
            FROM order_items oi
            $itemJoins
            LEFT JOIN products p ON p.id = oi.product_id
            LEFT JOIN categories c ON c.id = p.category_id
            WHERE $saleRange
            GROUP BY category_name
            HAVING qty > 0
            ORDER BY sales DESC
        ");
        $st->execute([$start, $end]);
        $categories = $st->fetchAll(PDO::FETCH_ASSOC);

        /* ---------- peak hours (pickup time chosen by the customer) ----------
         * pickup_time is used on purpose: created_at is stored in UTC on
         * Railway, which would shift every hour by 8 hours. */
        $st = $pdo->prepare("
            SELECT HOUR(o.pickup_time) AS hr,
                   COUNT(*) AS orders,
                   COALESCE(SUM(o.total_amount), 0) AS sales
            FROM orders o
            WHERE $saleRange
            GROUP BY hr
            ORDER BY hr
        ");
        $st->execute([$start, $end]);
        $hours = $st->fetchAll(PDO::FETCH_ASSOC);

        /* ---------- weekday pattern ---------- */
        $st = $pdo->prepare("
            SELECT DAYOFWEEK(COALESCE(o.closed_at, o.created_at)) AS dow,
                   COUNT(*) AS orders,
                   COALESCE(SUM(o.total_amount), 0) AS sales
            FROM orders o
            WHERE $saleRange
            GROUP BY dow
            ORDER BY dow
        ");
        $st->execute([$start, $end]);
        $weekdays = [];
        $dowNames = [1 => 'Sunday', 2 => 'Monday', 3 => 'Tuesday', 4 => 'Wednesday',
                     5 => 'Thursday', 6 => 'Friday', 7 => 'Saturday'];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $weekdays[] = [
                'label'  => $dowNames[(int)$r['dow']] ?? '',
                'orders' => (int)$r['orders'],
                'sales'  => (float)$r['sales'],
            ];
        }

        /* ---------- order outcomes (orders PLACED in the period) ---------- */
        $st = $pdo->prepare("
            SELECT status, COUNT(*) AS orders,
                   COALESCE(SUM(total_amount), 0) AS amount
            FROM orders
            WHERE DATE(created_at) BETWEEN ? AND ?
            GROUP BY status
        ");
        $st->execute([$start, $end]);
        $outcomes = ['placed' => 0, 'completed' => 0, 'cancelled' => 0,
                     'in_progress' => 0, 'cancelled_amount' => 0.0];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $n = (int)$r['orders'];
            $outcomes['placed'] += $n;

            if ($r['status'] === 'completed') {
                $outcomes['completed'] += $n;
            } elseif ($r['status'] === 'cancelled') {
                $outcomes['cancelled'] += $n;
                $outcomes['cancelled_amount'] += (float)$r['amount'];
            } else {
                $outcomes['in_progress'] += $n;
            }
        }
        $outcomes['cancel_rate'] = saShare(
            (float)$outcomes['cancelled'],
            (float)$outcomes['placed']
        );

        $st = $pdo->prepare("
            SELECT COALESCE(NULLIF(TRIM(cancellation_reason), ''), 'No reason given') AS reason,
                   COUNT(*) AS orders
            FROM orders
            WHERE status = 'cancelled' AND DATE(created_at) BETWEEN ? AND ?
            GROUP BY reason
            ORDER BY orders DESC
            LIMIT 5
        ");
        $st->execute([$start, $end]);
        $cancelReasons = $st->fetchAll(PDO::FETCH_ASSOC);

        /* ---------- refunds ---------- */
        $st = $pdo->prepare("
            SELECT COUNT(*) AS orders, COALESCE(SUM(total_amount), 0) AS amount
            FROM orders
            WHERE refund_status = 'refunded'
              AND DATE(refund_processed_at) BETWEEN ? AND ?
        ");
        $st->execute([$start, $end]);
        $refunded = $st->fetch(PDO::FETCH_ASSOC);

        $pending = $pdo->query("
            SELECT COUNT(*) AS orders, COALESCE(SUM(total_amount), 0) AS amount
            FROM orders
            WHERE refund_status = 'pending'
        ")->fetch(PDO::FETCH_ASSOC);

        $refunds = [
            'refunded_orders'  => (int)$refunded['orders'],
            'refunded_amount'  => (float)$refunded['amount'],
            'pending_orders'   => (int)$pending['orders'],
            'pending_amount'   => (float)$pending['amount'],
        ];

        /* ---------- discounts: PWD / Senior ---------- */
        $st = $pdo->prepare("
            SELECT o.discount_type AS label,
                   COUNT(*) AS orders,
                   COALESCE(SUM(o.discount_amount), 0) AS amount
            FROM orders o
            WHERE $saleRange AND o.discount_type IN ('pwd', 'senior')
            GROUP BY o.discount_type
        ");
        $st->execute([$start, $end]);
        $idDiscounts = ['pwd' => ['orders' => 0, 'amount' => 0.0],
                        'senior' => ['orders' => 0, 'amount' => 0.0]];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $idDiscounts[$r['label']] = [
                'orders' => (int)$r['orders'],
                'amount' => (float)$r['amount'],
            ];
        }

        /* ---------- promotion sales ----------
         * One row per promotion used on a completed order:
         *   used       = orders that used it
         *   free_items = free units given away
         *   amount     = value given away (discount)
         *   sales      = what customers actually paid on those orders
         */
        $st = $pdo->prepare("
            SELECT op.promotion_title AS title,
                   op.rule_type AS rule_type,
                   COUNT(DISTINCT o.id) AS used,
                   COALESCE(SUM(op.discount_amount), 0) AS amount,
                   COALESCE(SUM(o.total_amount), 0) AS sales,
                   COALESCE(SUM(f.free_qty), 0) AS free_items
            FROM order_promotions op
            JOIN orders o ON o.id = op.order_id
            LEFT JOIN (
                SELECT order_id, SUM(quantity) AS free_qty
                FROM order_items
                WHERE product_name LIKE '% (FREE)'
                GROUP BY order_id
            ) f ON f.order_id = o.id
            WHERE $saleRange
            GROUP BY op.promotion_title, op.rule_type
            ORDER BY sales DESC
        ");
        $st->execute([$start, $end]);
        $promotions = $st->fetchAll(PDO::FETCH_ASSOC);

        $promoSummary = ['orders' => 0, 'sales' => 0.0, 'discount' => 0.0,
                         'free_items' => 0, 'share' => 0.0];
        foreach ($promotions as $pr) {
            $promoSummary['orders']     += (int)$pr['used'];
            $promoSummary['sales']      += (float)$pr['sales'];
            $promoSummary['discount']   += (float)$pr['amount'];
            $promoSummary['free_items'] += (int)$pr['free_items'];
        }
        $promoSummary['share'] = saShare($promoSummary['sales'], $current['net']);

        /* ---------- customers (online orders) ---------- */
        $st = $pdo->prepare("
            SELECT COUNT(*) AS total, COALESCE(SUM(t.is_repeat), 0) AS repeat_customers
            FROM (
                SELECT o.customer_id,
                       (EXISTS (
                           SELECT 1 FROM orders p
                           WHERE p.customer_id = o.customer_id
                             AND p.status = 'completed'
                             AND DATE(COALESCE(p.closed_at, p.created_at)) < ?
                       )) AS is_repeat
                FROM orders o
                WHERE $saleRange AND o.customer_id IS NOT NULL
                GROUP BY o.customer_id
            ) t
        ");
        $st->execute([$start, $start, $end]);
        $cust = $st->fetch(PDO::FETCH_ASSOC);

        $st = $pdo->prepare("
            SELECT COUNT(*) FROM orders o
            WHERE $saleRange AND o.customer_id IS NULL AND o.order_source = 'online'
        ");
        $st->execute([$start, $end]);

        $customers = [
            'registered' => (int)$cust['total'],
            'returning'  => (int)$cust['repeat_customers'],
            'new'        => (int)$cust['total'] - (int)$cust['repeat_customers'],
            'guest_orders' => (int)$st->fetchColumn(),
        ];

        /* ---------- plain-language takeaways ---------- */
        $insights = [];

        if ($current['orders'] === 0) {
            $insights[] = 'No completed sales were recorded in this period.';
        } else {
            $chg = saPercentChange($current['net'], $previous['net']);
            if ($chg !== null) {
                $insights[] = sprintf(
                    'Net sales are %s %.1f%% compared with the previous %d-day period (₱%s).',
                    $chg >= 0 ? 'up' : 'down',
                    abs($chg),
                    $days,
                    number_format($previous['net'], 2)
                );
            }

            $totalCh = $channel['online']['sales'] + $channel['walk_in']['sales'];
            if ($totalCh > 0) {
                $insights[] = sprintf(
                    'Online orders brought in %.0f%% of sales (₱%s); walk-in orders brought in %.0f%% (₱%s).',
                    saShare($channel['online']['sales'], $totalCh),
                    number_format($channel['online']['sales'], 2),
                    saShare($channel['walk_in']['sales'], $totalCh),
                    number_format($channel['walk_in']['sales'], 2)
                );
            }

            if (!empty($topProducts)) {
                $insights[] = sprintf(
                    'Best seller: %s (%d sold).',
                    $topProducts[0]['name'],
                    (int)$topProducts[0]['qty']
                );
            }

            if (!empty($hours)) {
                $busiest = $hours[0];
                foreach ($hours as $h) {
                    if ((int)$h['orders'] > (int)$busiest['orders']) {
                        $busiest = $h;
                    }
                }
                $hr = (int)$busiest['hr'];
                $insights[] = sprintf(
                    'Busiest pickup hour: %s–%s (%d orders).',
                    date('g A', strtotime(sprintf('%02d:00', $hr))),
                    date('g A', strtotime(sprintf('%02d:00', ($hr + 1) % 24))),
                    (int)$busiest['orders']
                );
            }
        }

        if ($promoSummary['orders'] > 0) {
            $insights[] = sprintf(
                '%d order(s) used a promotion: customers paid ₱%s on them (%.0f%% of net sales) and %d free item(s) worth ₱%s were given away.',
                $promoSummary['orders'],
                number_format($promoSummary['sales'], 2),
                $promoSummary['share'],
                $promoSummary['free_items'],
                number_format($promoSummary['discount'], 2)
            );
        }

        if ($outcomes['placed'] > 0 && $outcomes['cancelled'] > 0) {
            $line = sprintf(
                '%.1f%% of orders placed in this period were cancelled (%d of %d), worth ₱%s in lost sales.',
                $outcomes['cancel_rate'],
                $outcomes['cancelled'],
                $outcomes['placed'],
                number_format($outcomes['cancelled_amount'], 2)
            );
            if ($outcomes['cancel_rate'] >= 15) {
                $line .= ' This is high and worth reviewing.';
            }
            $insights[] = $line;
        }

        if ($refunds['pending_orders'] > 0) {
            $insights[] = sprintf(
                '%d refund request(s) worth ₱%s are still pending.',
                $refunds['pending_orders'],
                number_format($refunds['pending_amount'], 2)
            );
        }

        return [
            'range'       => ['start' => $start, 'end' => $end, 'days' => $days,
                              'prev_start' => $prevStart, 'prev_end' => $prevEnd],
            'current'     => $current,
            'previous'    => $previous,
            'channel'     => $channel,
            'payment'     => $payment,
            'topProducts' => $topProducts,
            'categories'  => $categories,
            'hours'       => $hours,
            'weekdays'    => $weekdays,
            'outcomes'    => $outcomes,
            'cancelReasons' => $cancelReasons,
            'refunds'     => $refunds,
            'idDiscounts' => $idDiscounts,
            'promotions'  => $promotions,
            'promoSummary' => $promoSummary,
            'customers'   => $customers,
            'insights'    => $insights,
        ];

    } catch (Throwable $e) {
        error_log('salesAnalytics failed: ' . $e->getMessage());
        return null;
    }
}

/* Small "up/down vs previous period" label for the screen report. */
function saDeltaBadge(?float $pct): string
{
    if ($pct === null) {
        return '<div class="sa-delta sa-flat">No previous-period data</div>';
    }

    $up = $pct >= 0;

    return sprintf(
        '<div class="sa-delta %s">%s %.1f%% vs previous period</div>',
        $up ? 'sa-up' : 'sa-down',
        $up ? '▲' : '▼',
        abs($pct)
    );
}