<?php
/* =========================================================
   AUTOMATIC BEST SELLERS
   ---------------------------------------------------------
   Best sellers are no longer picked by hand. They are the
   products customers actually bought the most:

     - only COMPLETED, non-refunded orders count
     - free cups from Buy 1 Take 1 / Buy X Get Y promos
       ("... (FREE)" order lines) are not counted
     - archived / unavailable products, Add-ons and Bundles
       are skipped
     - the last $days days are ranked first; if that gives
       fewer than $limit products, the remaining spots are
       filled from all-time sales
   Used by customer/index.php and admin/settings.php so both
   always show the same list.
========================================================= */

function bestSellerRanking(PDO $pdo, int $limit, ?int $days): array
{
    $limit = max(1, $limit);

    $dateFilter = '';
    if ($days !== null) {
        $dateFilter = 'AND o.created_at >= DATE_SUB(NOW(), INTERVAL ' . max(1, $days) . ' DAY)';
    }

    $sql = "
        SELECT
            p.id,
            SUM(oi.quantity) AS units_sold,
            MAX(o.created_at) AS last_sold
        FROM order_items oi
        INNER JOIN orders o ON o.id = oi.order_id
        INNER JOIN products p ON p.id = oi.product_id
        INNER JOIN categories c ON c.id = p.category_id
        WHERE o.status = 'completed'
          AND COALESCE(o.refund_status, 'none') <> 'refunded'
          AND oi.product_name NOT LIKE '% (FREE)'
          AND p.is_available = 1
          AND p.is_archived = 0
          AND COALESCE(c.name, '') NOT IN ('Add-ons', 'Bundle')
          $dateFilter
        GROUP BY p.id
        HAVING units_sold > 0
        ORDER BY units_sold DESC, last_sold DESC, p.id ASC
        LIMIT $limit
    ";

    return array_map('intval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
}

/* Returns product ids, best seller first. */
function getBestSellerIds(PDO $pdo, int $limit = 4, int $days = 30): array
{
    try {
        $ids = bestSellerRanking($pdo, $limit, $days);

        if (count($ids) < $limit) {
            foreach (bestSellerRanking($pdo, $limit, null) as $id) {
                if (!in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
                if (count($ids) >= $limit) {
                    break;
                }
            }
        }

        return $ids;
    } catch (PDOException $e) {
        error_log('Best seller ranking failed: ' . $e->getMessage());
        return [];
    }
}

/*
 * Keep products.is_bestseller in step with the ranking so any other page
 * that still reads that column (menu badges, etc.) stays correct.
 * Only writes when the list actually changed.
 */
function syncBestSellerFlags(PDO $pdo, array $ids): void
{
    try {
        $current = array_map('intval', $pdo->query(
            "SELECT id FROM products WHERE is_bestseller = 1"
        )->fetchAll(PDO::FETCH_COLUMN));

        $a = $current;
        $b = array_map('intval', $ids);
        sort($a);
        sort($b);

        if ($a === $b) {
            return;
        }

        $pdo->beginTransaction();
        $pdo->exec("UPDATE products SET is_bestseller = 0 WHERE is_bestseller = 1");

        if ($b) {
            $pdo->exec(
                "UPDATE products SET is_bestseller = 1 WHERE id IN (" . implode(',', $b) . ")"
            );
        }

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Best seller flag sync failed: ' . $e->getMessage());
    }
}