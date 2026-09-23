<?php
session_start();
require_once '../includes/db.php';

$isAjaxRequest =
    ($_POST['ajax'] ?? $_GET['ajax'] ?? '') === '1' ||
    strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

function customerRedirect(string $location): void
{
    global $isAjaxRequest;

    if ($isAjaxRequest) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'redirect' => $location
        ]);
        exit;
    }

    header('Location: ' . $location);
    exit;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $productId = isset($_POST['product_id'])
        ? (int)$_POST['product_id']
        : 0;

    $size = trim(
        (string)($_POST['size'] ?? '')
    );

    $addons = isset($_POST['addons'])
        ? $_POST['addons']
        : [];

    $sugarLevel = trim(
        (string)($_POST['sugar_level'] ?? '')
    );

    $quantity = isset($_POST['quantity'])
        ? (int)$_POST['quantity']
        : 1;

    /*
     * =========================================================
     * PROMOTION CONTEXT
     * =========================================================
     *
     * These values are sent by product-view.php when the
     * customer came from Promo Featured.
     */
    $promotionId = isset($_POST['promotion_id'])
        ? (int)$_POST['promotion_id']
        : 0;

    $promotionRole = trim(
        (string)($_POST['promotion_role'] ?? '')
    );


    /*
     * =========================================================
     * GET PRODUCT
     * =========================================================
     */

    $stmt = $pdo->prepare("
        SELECT *
        FROM products
        WHERE id = ?
          AND is_archived = 0
        LIMIT 1
    ");

    $stmt->execute([
        $productId
    ]);

    $product = $stmt->fetch(PDO::FETCH_ASSOC);


    /*
     * =========================================================
     * PRODUCT VALIDATION
     * =========================================================
     */

    if (
        !$product ||
        (int)$product['is_available'] !== 1 ||
        $quantity <= 0
    ) {
        customerRedirect("product-view.php?id=" .
            $productId .
            "&error=out_of_stock");
    }


    /*
     * =========================================================
     * PROMOTION VALIDATION
     * =========================================================
     *
     * Never trust promotion_id from the browser.
     * Verify it against the database.
     */

    $validPromotionId = 0;
    $validPromotionRole = '';
    $promotionData = null;
    $promotionSequence = null;

    if ($promotionId > 0) {

        $promotionStmt = $pdo->prepare("
            SELECT
                p.id,
                r.rule_type,
                r.buy_quantity,
                r.get_quantity,
                pri.role,
                pri.product_id AS promotion_product_id,
                pri.quantity AS promotion_item_quantity,
                pri.size AS promotion_size
            FROM promotions p
            INNER JOIN promotion_rules r
                ON r.promotion_id = p.id
            INNER JOIN promotion_rule_items pri
                ON pri.rule_id = r.id
            WHERE p.id = ?
              AND pri.product_id = ?
              AND pri.role = ?
              AND p.is_active = 1
              AND p.is_archived = 0
              AND p.start_date <= CURDATE()
              AND p.end_date >= CURDATE()
            LIMIT 1
        ");

        $promotionStmt->execute([
            $promotionId,
            $productId,
            $promotionRole
        ]);

        $promotionData = $promotionStmt->fetch(PDO::FETCH_ASSOC);

        if (!$promotionData) {
            customerRedirect("product-view.php?id=" .
                $productId .
                "&error=invalid_promotion");
        }

        $validPromotionId = (int)$promotionData['id'];
        $validPromotionRole = (string)$promotionData['role'];
        $ruleType = (string)$promotionData['rule_type'];

        /*
         * BOGO / Buy X Get Y accept both BUY and GET customization steps.
         */
        if (
            in_array($ruleType, ['bogo', 'buy_x_get_y'], true) &&
            !in_array($validPromotionRole, ['buy', 'get'], true)
        ) {
            customerRedirect("product-view.php?id=" .
                $productId .
                "&error=invalid_promotion");
        }

        /*
         * The configured size belongs to the specific role being customized.
         */
        $promotionSize = strtolower(
            trim((string)($promotionData['promotion_size'] ?? ''))
        );
        $selectedSize = strtolower(trim($size));

        if (
            $promotionSize !== '' &&
            in_array($promotionSize, ['regular', 'grande'], true) &&
            $selectedSize !== $promotionSize
        ) {
            customerRedirect("product-view.php?id=" .
                $productId .
                "&promotion_id=" .
                $validPromotionId .
                "&promotion_role=" .
                urlencode($validPromotionRole) .
                "&promotion_quantity=" .
                max(1, $quantity) .
                "&error=promotion_size");
        }

        if ($ruleType === 'buy_x_get_y') {
            $requiredBuyQty = max(1, (int)$promotionData['buy_quantity']);

            if ($validPromotionRole === 'buy' && $quantity < $requiredBuyQty) {
                customerRedirect("product-view.php?id=" .
                    $productId .
                    "&promotion_id=" .
                    $validPromotionId .
                    "&promotion_role=buy" .
                    "&promotion_quantity=" .
                    $requiredBuyQty);
            }
        }

        if ($validPromotionRole === 'get') {
            /*
             * GET customization must come after a valid BUY line exists.
             * Calculate the exact number of free units from the customer's
             * current promotion BUY quantity and ignore browser quantity.
             */
            $buyStmt = $pdo->prepare("
                SELECT
                    pri.product_id,
                    pri.size,
                    r.buy_quantity,
                    r.get_quantity,
                    r.rule_type
                FROM promotion_rules r
                INNER JOIN promotion_rule_items pri
                    ON pri.rule_id = r.id
                WHERE r.promotion_id = ?
                  AND pri.role = 'buy'
                ORDER BY pri.id ASC
                LIMIT 1
            ");
            $buyStmt->execute([$validPromotionId]);
            $buyConfig = $buyStmt->fetch(PDO::FETCH_ASSOC);

            if (!$buyConfig) {
                customerRedirect("product-view.php?id=" . $productId . "&error=invalid_promotion");
            }

            $buyProductId = (int)$buyConfig['product_id'];
            $buySize = strtolower(trim((string)($buyConfig['size'] ?? '')));
            $buyRuleQty = $ruleType === 'bogo'
                ? 1
                : max(1, (int)$buyConfig['buy_quantity']);
            $configuredGetQty = $ruleType === 'bogo'
                ? 1
                : max(1, (int)$buyConfig['get_quantity']);

            $buyQuantityInCart = 0;
            foreach ($_SESSION['cart'] ?? [] as $cartItem) {
                if ((int)($cartItem['product_id'] ?? 0) !== $buyProductId) {
                    continue;
                }

                $cartSize = strtolower(trim((string)($cartItem['size'] ?? '')));
                if ($buySize !== '' && $cartSize !== $buySize) {
                    continue;
                }

                if (
                    (int)($cartItem['promotion_source_id'] ?? 0) === $validPromotionId &&
                    ($cartItem['promotion_source_role'] ?? '') === 'buy'
                ) {
                    $buyQuantityInCart += max(0, (int)($cartItem['quantity'] ?? 0));
                }
            }

            if ($buyQuantityInCart < $buyRuleQty) {
                customerRedirect("product-view.php?id=" .
                    $buyProductId .
                    "&promotion_id=" .
                    $validPromotionId .
                    "&promotion_role=buy" .
                    "&promotion_quantity=" .
                    $buyRuleQty .
                    "&error=promotion_sequence");
            }

            $sets = intdiv($buyQuantityInCart, $buyRuleQty);
            $requiredFreeQty = $sets * $configuredGetQty;

            if ($requiredFreeQty <= 0) {
                customerRedirect("product-view.php?id=" . $productId . "&error=invalid_promotion");
            }

            $quantity = $requiredFreeQty;
            $promotionSequence = 'get';
        }

        if ($validPromotionRole === 'bundle') {
            $quantity = max(1, (int)($promotionData['promotion_item_quantity'] ?? 1));
            $promotionSequence = 'bundle';
        }

        $_SESSION['selected_promotion_id'] = $validPromotionId;
    }


    /*
     * =========================================================
     * ADD-ONS
     * =========================================================
     *
     * Only database-approved add-ons are accepted.
     */

    if (!is_array($addons)) {
        $addons = [$addons];
    }

    $addons = array_values(
        array_filter(
            array_map(
                'trim',
                $addons
            ),
            function ($addon) {
                return $addon !== '';
            }
        )
    );

    $validAddons = [];
    $addonsTotal = 0.00;


    if (!empty($addons)) {

        $placeholders = implode(
            ',',
            array_fill(
                0,
                count($addons),
                '?'
            )
        );

        $addonStmt = $pdo->prepare("
            SELECT
                a.id,
                a.name,
                a.price
            FROM addons a
            INNER JOIN product_addons pa
                ON pa.addon_id = a.id
            WHERE pa.product_id = ?
              AND a.name IN ($placeholders)
              AND a.is_available = 1
              AND a.is_archived = 0
            ORDER BY a.name ASC
        ");

        $addonStmt->execute(
            array_merge(
                [$productId],
                $addons
            )
        );

        $dbAddons =
            $addonStmt->fetchAll(PDO::FETCH_ASSOC);


        foreach ($dbAddons as $addon) {

            $validAddons[] =
                $addon['name'];

            $addonsTotal +=
                (float)$addon['price'];
        }


        /*
         * Remove duplicates.
         * Sorting keeps the cart key consistent.
         */

        $validAddons =
            array_values(
                array_unique($validAddons)
            );

        sort(
            $validAddons,
            SORT_NATURAL |
            SORT_FLAG_CASE
        );
    }


    /*
     * =========================================================
     * PRODUCT PRICE
     * =========================================================
     *
     * IMPORTANT:
     * Do NOT use hardcoded prices here.
     *
     * The old code used:
     *
     * Regular = 39
     * Grande  = 49
     *
     * which was incorrect for Chocolate.
     *
     * Prices are now taken from products table.
     */

    $basePrice =
        (float)($product['price'] ?? 0);


    if ($size === 'Regular') {

        $regularPrice =
            (float)($product['regular_price'] ?? 0);

        if ($regularPrice <= 0) {

            customerRedirect("product-view.php?id=" .
                $productId .
                "&error=invalid_size");
        }

        $basePrice =
            $regularPrice;

    } elseif ($size === 'Grande') {

        $grandePrice =
            (float)($product['grande_price'] ?? 0);

        if ($grandePrice <= 0) {

            customerRedirect("product-view.php?id=" .
                $productId .
                "&error=invalid_size");
        }

        $basePrice =
            $grandePrice;

    } elseif ($size !== '') {

        customerRedirect("product-view.php?id=" .
            $productId .
            "&error=invalid_size");
    }


    /*
     * =========================================================
     * FINAL UNIT PRICE
     * =========================================================
     */

    $unitPrice = round(
        $basePrice +
        $addonsTotal,
        2
    );


    /*
     * =========================================================
     * CART KEY
     * =========================================================
     *
     * Promotion items remain separate from normal orders.
     *
     * Same product + same customization:
     *
     * Normal order
     *      !=
     * Promo order
     */

    $cartKey = md5(
        $productId .
        $size .
        $sugarLevel .
        implode(',', $validAddons) .
        $validPromotionId .
        $validPromotionRole
    );


    /*
     * =========================================================
     * CREATE CART
     * =========================================================
     */

    if (!isset($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }


    /*
     * =========================================================
     * ADD / UPDATE CART ITEM
     * =========================================================
     */

    if (isset($_SESSION['cart'][$cartKey])) {

        $_SESSION['cart'][$cartKey]['quantity'] +=
            $quantity;


        /*
         * Make absolutely sure the promotion tag remains.
         */

        if ($validPromotionId > 0) {

            $_SESSION['cart'][$cartKey][
                'promotion_source_id'
            ] = $validPromotionId;

            $_SESSION['cart'][$cartKey][
                'promotion_source_role'
            ] = $validPromotionRole;

            $_SESSION['cart'][$cartKey]['is_free'] =
                $validPromotionRole === 'get';
        }

    } else {

        $_SESSION['cart'][$cartKey] = [

            'product_id' =>
                $productId,

            'name' =>
                $product['name'],

            'image' =>
                $product['image'],

            'size' =>
                $size,

            'addons' =>
                $validAddons,

            'sugar_level' =>
                $sugarLevel,

            'price' =>
                $unitPrice,

            'quantity' =>
                $quantity
        ];


        /*
         * Tag the cart line as a promotion item.
         */

        if ($validPromotionId > 0) {

            $_SESSION['cart'][$cartKey][
                'promotion_source_id'
            ] = $validPromotionId;

            $_SESSION['cart'][$cartKey][
                'promotion_source_role'
            ] = $validPromotionRole;

            $_SESSION['cart'][$cartKey]['is_free'] =
                $validPromotionRole === 'get';
        }
    }


    /*
     * =========================================================
     * PROMOTION NEXT STEP
     * =========================================================
     */

    if ($validPromotionId > 0 && $promotionData) {

        $ruleType = (string)$promotionData['rule_type'];

        /*
         * BUY -> GET: send the customer to the configured free product
         * for its own sugar/add-on customization.
         */
        if (
            in_array($ruleType, ['bogo', 'buy_x_get_y'], true) &&
            $validPromotionRole === 'buy'
        ) {
            $getStmt = $pdo->prepare("
                SELECT
                    pri.product_id,
                    pri.size,
                    r.get_quantity,
                    r.rule_type
                FROM promotion_rules r
                INNER JOIN promotion_rule_items pri
                    ON pri.rule_id = r.id
                INNER JOIN products p
                    ON p.id = pri.product_id
                WHERE r.promotion_id = ?
                  AND pri.role = 'get'
                  AND p.is_available = 1
                  AND p.is_archived = 0
                ORDER BY pri.id ASC
                LIMIT 1
            ");
            $getStmt->execute([$validPromotionId]);
            $getConfig = $getStmt->fetch(PDO::FETCH_ASSOC);

            if (!$getConfig) {
                customerRedirect("cart.php");
            }

            $buyRuleQty = $ruleType === 'bogo'
                ? 1
                : max(1, (int)$promotionData['buy_quantity']);
            $getRuleQty = $ruleType === 'bogo'
                ? 1
                : max(1, (int)($getConfig['get_quantity'] ?? $promotionData['get_quantity'] ?? 1));

            $buyQtyInCart = 0;
            foreach ($_SESSION['cart'] as $cartItem) {
                if (
                    (int)($cartItem['promotion_source_id'] ?? 0) === $validPromotionId &&
                    ($cartItem['promotion_source_role'] ?? '') === 'buy'
                ) {
                    $buyQtyInCart += max(0, (int)($cartItem['quantity'] ?? 0));
                }
            }

            $sets = intdiv($buyQtyInCart, $buyRuleQty);
            $freeQuantity = max(1, $sets * $getRuleQty);

            customerRedirect("product-view.php?id=" .
                (int)$getConfig['product_id'] .
                "&promotion_id=" .
                $validPromotionId .
                "&promotion_role=get" .
                "&promotion_quantity=" .
                $freeQuantity);
        }

        /*
         * Bundle: keep sending the customer through every configured
         * bundle product until each one has been customized.
         */
        if ($ruleType === 'bundle' && $validPromotionRole === 'bundle') {
            $bundleStmt = $pdo->prepare("
                SELECT
                    pri.product_id,
                    pri.quantity,
                    pri.size
                FROM promotion_rule_items pri
                INNER JOIN promotion_rules r
                    ON r.id = pri.rule_id
                INNER JOIN products p
                    ON p.id = pri.product_id
                WHERE r.promotion_id = ?
                  AND pri.role = 'bundle'
                  AND p.is_available = 1
                  AND p.is_archived = 0
                ORDER BY pri.id ASC
            ");
            $bundleStmt->execute([$validPromotionId]);
            $bundleItems = $bundleStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($bundleItems as $bundleItem) {
                $nextProductId = (int)$bundleItem['product_id'];
                $nextAlreadyCustomized = false;

                foreach ($_SESSION['cart'] as $cartItem) {
                    if (
                        (int)($cartItem['promotion_source_id'] ?? 0) === $validPromotionId &&
                        ($cartItem['promotion_source_role'] ?? '') === 'bundle' &&
                        (int)($cartItem['product_id'] ?? 0) === $nextProductId
                    ) {
                        $nextAlreadyCustomized = true;
                        break;
                    }
                }

                if (!$nextAlreadyCustomized) {
                    customerRedirect("product-view.php?id=" .
                        $nextProductId .
                        "&promotion_id=" .
                        $validPromotionId .
                        "&promotion_role=bundle" .
                        "&promotion_quantity=" .
                        max(1, (int)($bundleItem['quantity'] ?? 1)));
                }
            }
        }
    }

    /*
     * =========================================================
     * GO TO CART
     * =========================================================
     */

    customerRedirect("cart.php");

} else {

    customerRedirect("menu.php");
}
?>