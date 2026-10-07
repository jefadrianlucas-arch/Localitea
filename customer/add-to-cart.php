<?php
session_start();
require_once '../includes/db.php';

$isAjaxRequest =
    ($_POST['ajax'] ?? $_GET['ajax'] ?? '') === '1' ||
    strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

function customerRedirect(string $location, string $message = ''): void
{
    global $isAjaxRequest;

    if ($isAjaxRequest) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'redirect' => $location,
            'message' => $message
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

    $discountType = strtolower(
    trim((string)($_POST['discount_type'] ?? 'none'))
);

if (!in_array($discountType, ['none', 'pwd', 'senior'], true)) {
    $discountType = 'none';
}

    /*
     * PWD / Senior Citizen: the customer must give the name and
     * ID number shown on the ID. They are printed on the receipt.
     */
    $discountIdName = '';
    $discountIdNumber = '';

    if ($discountType !== 'none') {
        $discountIdName = trim(preg_replace('/\s+/', ' ', (string)($_POST['discount_id_name'] ?? '')));
        $discountIdNumber = strtoupper(trim((string)($_POST['discount_id_number'] ?? '')));

        $idBackUrl = 'product-view.php?id=' . (int)($_POST['product_id'] ?? 0);

        if ($discountIdName === '' || mb_strlen($discountIdName) > 100) {
            customerRedirect(
                $idBackUrl . '&error=discount_id',
                'Please enter the name on your ' . strtoupper($discountType) . ' ID.'
            );
        }

        if (!preg_match('/^[A-Z0-9][A-Z0-9\-\/ ]{2,29}$/', $discountIdNumber)) {
            customerRedirect(
                $idBackUrl . '&error=discount_id',
                'Please enter a valid ID number (letters, numbers and dashes only).'
            );
        }

        /* One discount = one cardholder per order. */
        foreach ($_SESSION['cart'] ?? [] as $existingItem) {
            $existingId = strtoupper((string)($existingItem['discount_id_number'] ?? ''));
            if (
                ($existingItem['discount_type'] ?? 'none') === $discountType
                && $existingId !== ''
                && $existingId !== $discountIdNumber
            ) {
                customerRedirect(
                    $idBackUrl . '&error=discount_id',
                    'Your cart already uses ID ' . $existingId . ' for the discount. Use the same ID for the whole order.'
                );
            }
        }
    }

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
     * Bundle slot (promotion_rule_items.id). A bundle slot may be filled
     * by any available product from the same category as the product the
     * admin configured for that slot.
     */
    $promotionSlotId = isset($_POST['promotion_slot_id'])
        ? (int)$_POST['promotion_slot_id']
        : 0;


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
    $validPromotionSlotId = 0;
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
                pri.size AS promotion_size,
                pri.id AS slot_id
            FROM promotions p
            INNER JOIN promotion_rules r
                ON r.promotion_id = p.id
            INNER JOIN promotion_rule_items pri
                ON pri.rule_id = r.id
            INNER JOIN products slotp
                ON slotp.id = pri.product_id
            INNER JOIN products chosen
                ON chosen.id = ?
            WHERE p.id = ?
              AND (
                    (? = 0 AND pri.product_id = chosen.id)
                    OR (
                        ? > 0
                        AND pri.id = ?
                        AND pri.role = 'bundle'
                        AND slotp.category_id = chosen.category_id
                    )
                  )
              AND pri.role = ?
              AND p.is_active = 1
              AND p.is_archived = 0
              AND p.start_date <= CURDATE()
              AND p.end_date >= CURDATE()
            LIMIT 1
        ");

        $promotionStmt->execute([
            $productId,
            $promotionId,
            $promotionSlotId,
            $promotionSlotId,
            $promotionSlotId,
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

        if ($validPromotionRole === 'bundle') {
            $validPromotionSlotId = (int)($promotionData['slot_id'] ?? 0);
        }

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
                ($promotionSlotId > 0 ? "&promotion_slot=" . $promotionSlotId : '') .
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
     * MENU BUNDLE FLAVORS
     * =========================================================
     *
     * Menu bundles (e.g. "Classic Milktea & Fruit Tea 7+1") are listed in
     * menu_bundle_options. The customer must pick a flavor for every cup,
     * and every pick is verified against the database here.
     */
    $bundlePicks = [];
    $bundlePickKey = '';

    if ($validPromotionId === 0) {
        $menuBundleConfig = null;

        try {
            $bundleCfgStmt = $pdo->prepare("
                SELECT size, cups, category_ids
                FROM menu_bundle_options
                WHERE product_id = ?
            ");

            if ($bundleCfgStmt && $bundleCfgStmt->execute([$productId])) {
                $cfgRows = [];

                foreach ($bundleCfgStmt->fetchAll(PDO::FETCH_ASSOC) as $cfgRow) {
                    $cfgRows[(string)$cfgRow['size']] = $cfgRow;
                }

                $menuBundleConfig = $cfgRows[$size] ?? ($cfgRows[''] ?? null);
            }
        } catch (Throwable $e) {
            $menuBundleConfig = null;
        }

        if ($menuBundleConfig) {
            $requiredCups = (int)$menuBundleConfig['cups'];
            $allowedCategoryIds = array_values(array_filter(array_map(
                'intval',
                explode(',', (string)$menuBundleConfig['category_ids'])
            )));

            $postedPicks = $_POST['bundle_picks'] ?? [];

            if (!is_array($postedPicks)) {
                $postedPicks = [];
            }

            $postedPicks = array_values(array_map('intval', $postedPicks));

            if ($requiredCups > 0 && $allowedCategoryIds) {
                if (
                    count($postedPicks) !== $requiredCups ||
                    in_array(0, $postedPicks, true)
                ) {
                    customerRedirect(
                        "product-view.php?id=" . $productId . "&error=bundle_picks",
                        'Please choose a flavor for every cup of the bundle.'
                    );
                }

                $uniquePickIds = array_values(array_unique($postedPicks));
                $pickPlaceholders = implode(',', array_fill(0, count($uniquePickIds), '?'));

                $pickStmt = $pdo->prepare("
                    SELECT
                        id,
                        name,
                        category_id,
                        price,
                        regular_price,
                        grande_price,
                        is_available,
                        is_archived
                    FROM products
                    WHERE id IN ($pickPlaceholders)
                ");
                $pickStmt->execute($uniquePickIds);

                $pickProducts = [];

                foreach ($pickStmt->fetchAll(PDO::FETCH_ASSOC) as $pickRow) {
                    $pickProducts[(int)$pickRow['id']] = $pickRow;
                }

                foreach ($postedPicks as $pickId) {
                    $pick = $pickProducts[$pickId] ?? null;

                    /* Cups are part of the bundle price: any priced flavor is allowed. */
                    $pickSizePrice = max(
                        (float)($pick['price'] ?? 0),
                        (float)($pick['regular_price'] ?? 0),
                        (float)($pick['grande_price'] ?? 0)
                    );

                    if (
                        !$pick ||
                        (int)$pick['is_available'] !== 1 ||
                        (int)$pick['is_archived'] !== 0 ||
                        !in_array((int)$pick['category_id'], $allowedCategoryIds, true) ||
                        $pickSizePrice <= 0
                    ) {
                        customerRedirect(
                            "product-view.php?id=" . $productId . "&error=bundle_picks",
                            'One of the selected flavors is no longer available. Please choose again.'
                        );
                    }

                    $bundlePicks[] = [
                        'id' => $pickId,
                        'name' => (string)$pick['name'],
                    ];
                }

                /* The picks describe one bundle; add more bundles one at a time. */
                $quantity = 1;

                $bundlePickIds = array_map(
                    static fn(array $pickItem): int => (int)$pickItem['id'],
                    $bundlePicks
                );
                sort($bundlePickIds);
                $bundlePickKey = '@' . md5(implode(',', $bundlePickIds));
            }
        }
    }


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
    $discountType .
    implode(',', $validAddons) .
    $validPromotionId .
    $validPromotionRole .
    ($validPromotionSlotId > 0 ? '#' . $validPromotionSlotId : '') .
    $bundlePickKey
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

    $_SESSION['cart'][$cartKey]['quantity'] += $quantity;

    $_SESSION['cart'][$cartKey]['discount_type'] = $discountType;
    $_SESSION['cart'][$cartKey]['discount_id_name'] = $discountIdName;
    $_SESSION['cart'][$cartKey]['discount_id_number'] = $discountIdNumber;




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

            if ($validPromotionSlotId > 0) {
                $_SESSION['cart'][$cartKey]['promotion_slot_id'] =
                    $validPromotionSlotId;
            }
        }

    } else {

        $_SESSION['cart'][$cartKey] = [
    'product_id' => $productId,
    'name' => $product['name'],
    'image' => $product['image'],
    'size' => $size,
    'addons' => $validAddons,
    'sugar_level' => $sugarLevel,
    'discount_type' => $discountType,
    'discount_id_name' => $discountIdName,
    'discount_id_number' => $discountIdNumber,
    'price' => $unitPrice,
    'quantity' => $quantity
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

            if ($validPromotionSlotId > 0) {
                $_SESSION['cart'][$cartKey]['promotion_slot_id'] =
                    $validPromotionSlotId;
            }
        }
    }


    if ($bundlePicks) {
        $_SESSION['cart'][$cartKey]['bundle_picks'] = $bundlePicks;
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
            /*
             * Every slot of the bundle must be filled. A slot is filled by
             * any cart line carrying its promotion_slot_id, so the customer
             * may pick a different flavor (same category) for each slot.
             *
             * The configured product is only the default shown first. Its
             * own availability does not matter: if it is sold out, the first
             * available product of the same category is shown instead.
             */
            $bundleStmt = $pdo->prepare("
                SELECT
                    pri.id AS slot_id,
                    pri.product_id,
                    pri.quantity,
                    pri.size,
                    slotp.category_id,
                    slotp.is_available AS default_available,
                    slotp.is_archived AS default_archived
                FROM promotion_rule_items pri
                INNER JOIN promotion_rules r
                    ON r.id = pri.rule_id
                INNER JOIN products slotp
                    ON slotp.id = pri.product_id
                WHERE r.promotion_id = ?
                  AND pri.role = 'bundle'
                ORDER BY pri.id ASC
            ");
            $bundleStmt->execute([$validPromotionId]);
            $bundleItems = $bundleStmt->fetchAll(PDO::FETCH_ASSOC);

            /* How many sets each slot has been filled for. */
            $slotSets = [];

            foreach ($bundleItems as $bundleItem) {
                $slotKey = (int)$bundleItem['slot_id'];
                $slotQty = 0;

                foreach ($_SESSION['cart'] as $cartItem) {
                    if (
                        (int)($cartItem['promotion_source_id'] ?? 0) !== $validPromotionId ||
                        ($cartItem['promotion_source_role'] ?? '') !== 'bundle'
                    ) {
                        continue;
                    }

                    $lineSlot = (int)($cartItem['promotion_slot_id'] ?? 0);

                    /* Older cart lines had no slot id: match by product. */
                    if (
                        $lineSlot === 0 &&
                        (int)($cartItem['product_id'] ?? 0) === (int)$bundleItem['product_id']
                    ) {
                        $lineSlot = $slotKey;
                    }

                    if ($lineSlot === $slotKey) {
                        $slotQty += max(0, (int)($cartItem['quantity'] ?? 0));
                    }
                }

                $slotSets[$slotKey] = (int)ceil(
                    $slotQty / max(1, (int)$bundleItem['quantity'])
                );
            }

            $targetSets = $slotSets ? max($slotSets) : 0;

            foreach ($bundleItems as $bundleItem) {
                $slotKey = (int)$bundleItem['slot_id'];

                if ($slotSets[$slotKey] >= $targetSets) {
                    continue;
                }

                $nextProductId = (int)$bundleItem['product_id'];

                if (
                    (int)$bundleItem['default_available'] !== 1 ||
                    (int)$bundleItem['default_archived'] !== 0
                ) {
                    $fallbackStmt = $pdo->prepare("
                        SELECT id
                        FROM products
                        WHERE category_id = ?
                          AND is_available = 1
                          AND is_archived = 0
                        ORDER BY name ASC
                        LIMIT 1
                    ");
                    $fallbackStmt->execute([(int)$bundleItem['category_id']]);
                    $fallbackId = (int)$fallbackStmt->fetchColumn();

                    if ($fallbackId <= 0) {
                        customerRedirect(
                            "cart.php",
                            'A bundle item is currently unavailable.'
                        );
                    }

                    $nextProductId = $fallbackId;
                }

                customerRedirect("product-view.php?id=" .
                    $nextProductId .
                    "&promotion_id=" .
                    $validPromotionId .
                    "&promotion_role=bundle" .
                    "&promotion_slot=" .
                    $slotKey .
                    "&promotion_quantity=" .
                    max(1, (int)($bundleItem['quantity'] ?? 1)));
            }
        }
    }

    /*
     * =========================================================
     * RETURN TO MENU AFTER ADDING TO CART
     * =========================================================
     *
     * The item has already been saved in the session cart above.
     * Keep the customer on the menu so they can continue selecting
     * additional products before opening the cart or checking out.
     *
     * For AJAX requests, return a success message that the
     * customer-side AJAX handler can display as an alert/toast.
     */

    $_SESSION['added_to_cart_toast'] = true;

    if ($isAjaxRequest) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'message' => 'Added to cart.',
            'redirect' => 'menu.php'
        ]);
        exit;
    }

    customerRedirect("menu.php");

} else {

    customerRedirect("menu.php");
}
?>