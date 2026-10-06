<?php

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../includes/db.php';
require_once '../includes/promotion-engine.php';

$successMessage = '';

/*
|--------------------------------------------------------------------------
| EDIT CART ITEM
|--------------------------------------------------------------------------
|
| Customers can edit size, sugar level, and add-ons directly from the cart.
| Promotion metadata is preserved from the existing session cart line.
|
*/
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['cart_action'] ?? '') === 'edit_cart_item'
) {
    $cartKey = (string)($_POST['cart_key'] ?? '');

    if (
        $cartKey === '' ||
        !isset($_SESSION['cart'][$cartKey]) ||
        !is_array($_SESSION['cart'][$cartKey])
    ) {
        header('Location: cart.php?edit_error=' . urlencode('The selected cart item could not be found.'));
        exit;
    }

    $cartItem = $_SESSION['cart'][$cartKey];
    $productId = (int)($cartItem['product_id'] ?? 0);

    $size = trim((string)($_POST['size'] ?? ''));
    $sugarLevel = trim((string)($_POST['sugar_level'] ?? ''));
    $addons = $_POST['addons'] ?? [];

    $discountType = strtolower(
        trim((string)($_POST['discount_type'] ?? ($cartItem['discount_type'] ?? 'none')))
    );

    if (!in_array($discountType, ['none', 'pwd', 'senior'], true)) {
        $discountType = 'none';
    }

    /* PWD / Senior Citizen ID details (cleared when the discount is removed). */
    $discountIdName = '';
    $discountIdNumber = '';

    if ($discountType !== 'none') {
        $discountIdName = trim(preg_replace('/\s+/', ' ', (string)($_POST['discount_id_name'] ?? '')));
        $discountIdNumber = strtoupper(trim((string)($_POST['discount_id_number'] ?? '')));

        if ($discountIdName === '' || mb_strlen($discountIdName) > 100) {
            header('Location: cart.php?edit_error=' . urlencode('Please enter the name on your ID for the discount.'));
            exit;
        }

        if (!preg_match('/^[A-Z0-9][A-Z0-9\-\/ ]{2,29}$/', $discountIdNumber)) {
            header('Location: cart.php?edit_error=' . urlencode('Please enter a valid ID number (letters, numbers and dashes only).'));
            exit;
        }
    }

    if (!is_array($addons)) {
        $addons = [$addons];
    }

    $addons = array_values(array_filter(array_map('trim', $addons), static function ($addon) {
        return $addon !== '';
    }));

    $allowedSugars = ['0%', '25%', '50%', '75%', '100%'];

    if ($productId <= 0 || !in_array($sugarLevel, $allowedSugars, true)) {
        header('Location: cart.php?edit_error=' . urlencode('Please select valid customization options.'));
        exit;
    }

    $editProductStmt = $pdo->prepare("
        SELECT id, name, image, price, regular_price, grande_price, is_available, is_archived
        FROM products
        WHERE id = ?
        LIMIT 1
    ");
    $editProductStmt->execute([$productId]);
    $editProduct = $editProductStmt->fetch(PDO::FETCH_ASSOC);

    if (!$editProduct || (int)$editProduct['is_archived'] === 1) {
        header('Location: cart.php?edit_error=' . urlencode('This product is no longer available.'));
        exit;
    }

    $basePrice = (float)($editProduct['price'] ?? 0);

    if ($size === 'Regular') {
        $basePrice = (float)($editProduct['regular_price'] ?? 0);
        if ($basePrice <= 0) {
            header('Location: cart.php?edit_error=' . urlencode('The selected size is not available for this product.'));
            exit;
        }
    } elseif ($size === 'Grande') {
        $basePrice = (float)($editProduct['grande_price'] ?? 0);
        if ($basePrice <= 0) {
            header('Location: cart.php?edit_error=' . urlencode('The selected size is not available for this product.'));
            exit;
        }
    } elseif ($size !== '') {
        header('Location: cart.php?edit_error=' . urlencode('The selected size is invalid.'));
        exit;
    }

    /* Keep active promotion size constraints when editing promo lines. */
    $promotionSourceId = (int)($cartItem['promotion_source_id'] ?? 0);
    $promotionSourceRole = trim((string)($cartItem['promotion_source_role'] ?? ''));

    if ($promotionSourceId > 0 && in_array($promotionSourceRole, ['buy', 'get'], true)) {
        $promotionSizeStmt = $pdo->prepare("
            SELECT pri.size AS promotion_size
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
        $promotionSizeStmt->execute([
            $promotionSourceId,
            $productId,
            $promotionSourceRole,
        ]);

        $promotionSizeRow = $promotionSizeStmt->fetch(PDO::FETCH_ASSOC);
        $configuredSize = strtolower(trim((string)($promotionSizeRow['promotion_size'] ?? '')));

        if (in_array($configuredSize, ['regular', 'grande'], true)) {
            $requiredSize = ucfirst($configuredSize);
            if ($size !== $requiredSize) {
                header('Location: cart.php?edit_error=' . urlencode('This promotion requires the configured ' . $requiredSize . ' size.'));
                exit;
            }
        }
    }

    $validAddons = [];
    $addonsTotal = 0.00;

    if (!empty($addons)) {
        $placeholders = implode(',', array_fill(0, count($addons), '?'));
        $editAddonStmt = $pdo->prepare("
            SELECT a.name, a.price
            FROM addons a
            INNER JOIN product_addons pa
                ON pa.addon_id = a.id
            WHERE pa.product_id = ?
              AND a.name IN ($placeholders)
              AND a.is_available = 1
              AND a.is_archived = 0
            ORDER BY a.name ASC
        ");
        $editAddonStmt->execute(array_merge([$productId], $addons));
        $dbAddons = $editAddonStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($dbAddons as $addon) {
            $validAddons[] = (string)$addon['name'];
            $addonsTotal += (float)$addon['price'];
        }

        $validAddons = array_values(array_unique($validAddons));
        sort($validAddons, SORT_NATURAL | SORT_FLAG_CASE);
    }

    $unitPrice = round($basePrice + $addonsTotal, 2);
    $quantity = max(1, (int)($cartItem['quantity'] ?? 1));

    $newCartKey = md5(
        $productId .
        $size .
        $sugarLevel .
        $discountType .
        implode(',', $validAddons) .
        $promotionSourceId .
        $promotionSourceRole
    );

    $updatedItem = $cartItem;
    $updatedItem['product_id'] = $productId;
    $updatedItem['name'] = $editProduct['name'];
    $updatedItem['image'] = $editProduct['image'];
    $updatedItem['size'] = $size;
    $updatedItem['addons'] = $validAddons;
    $updatedItem['sugar_level'] = $sugarLevel;
    $updatedItem['discount_type'] = $discountType;
    $updatedItem['discount_id_name'] = $discountIdName;
    $updatedItem['discount_id_number'] = $discountIdNumber;
    $updatedItem['price'] = $unitPrice;
    $updatedItem['quantity'] = $quantity;

    if ($promotionSourceId > 0) {
        $updatedItem['promotion_source_id'] = $promotionSourceId;
        $updatedItem['promotion_source_role'] = $promotionSourceRole;
        $updatedItem['is_free'] = $promotionSourceRole === 'get';
    }

    if ($newCartKey !== $cartKey) {
        if (isset($_SESSION['cart'][$newCartKey])) {
            $_SESSION['cart'][$newCartKey]['quantity'] =
                (int)($_SESSION['cart'][$newCartKey]['quantity'] ?? 0) + $quantity;
        } else {
            $_SESSION['cart'][$newCartKey] = $updatedItem;
        }
        unset($_SESSION['cart'][$cartKey]);
    } else {
        $_SESSION['cart'][$cartKey] = $updatedItem;
    }

    header('Location: cart.php?edit_success=1');
    exit;
}

if (empty($_SESSION['cart'])) {
    unset($_SESSION['selected_promotion_id']);
}

/*
|--------------------------------------------------------------------------
| CALCULATE CART SUBTOTAL
|--------------------------------------------------------------------------
*/

$subtotal = 0;

if (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {

    foreach ($_SESSION['cart'] as $item) {

        $itemPrice = (float)($item['price'] ?? 0);
        $itemQuantity = (int)($item['quantity'] ?? 0);

        $subtotal += $itemPrice * $itemQuantity;
    }
}

$subtotal = round($subtotal, 2);


/*
|--------------------------------------------------------------------------
| PREVIEW ACTIVE PROMOTION
|--------------------------------------------------------------------------
|
| The cart previews the same server-side promotion calculation used by
| checkout.php.
|
*/

$appliedPromotion = localiteaApplyBestPromotion(
    $pdo,
    $_SESSION['cart'] ?? [],
    $subtotal
);

$promotion_discount = round(
    (float)($appliedPromotion['discount'] ?? 0),
    2
);

$promotion_reward_items =
    is_array($appliedPromotion['reward_items'] ?? null)
        ? $appliedPromotion['reward_items']
        : [];

$promotion_free_allocations =
    is_array($appliedPromotion['free_allocations'] ?? null)
        ? $appliedPromotion['free_allocations']
        : [];

$promotion_added_reward_value = round(
    (float)($appliedPromotion['added_reward_value'] ?? 0),
    2
);

$display_subtotal = round(
    $subtotal + $promotion_added_reward_value,
    2
);


/*
|--------------------------------------------------------------------------
| PREVIEW PWD / SENIOR CITIZEN DISCOUNT
|--------------------------------------------------------------------------
*/

$selectedDiscountTypes = [];
$discountEligibleBase = 0.00;

foreach ($_SESSION['cart'] ?? [] as $cartKey => $item) {

    $itemDiscountType = strtolower(
        trim((string)($item['discount_type'] ?? 'none'))
    );

    if (!in_array($itemDiscountType, ['none', 'pwd', 'senior'], true)) {
        $itemDiscountType = 'none';
    }

    if ($itemDiscountType === 'none') {
        continue;
    }

    $itemQuantity = max(
        0,
        (int)($item['quantity'] ?? 0)
    );

    $itemPrice = max(
        0,
        (float)($item['price'] ?? 0)
    );

    $freeQuantity = (int)(
        $promotion_free_allocations[(string)$cartKey] ?? 0
    );

    $freeQuantity = max(
        0,
        min($itemQuantity, $freeQuantity)
    );

    $paidQuantity = $itemQuantity - $freeQuantity;

    if ($paidQuantity <= 0) {
        continue;
    }

    $selectedDiscountTypes[$itemDiscountType] = true;

    $discountEligibleBase +=
        $itemPrice * $paidQuantity;
}

$discountEligibleBase = round(
    $discountEligibleBase,
    2
);

$cartDiscountType =
    !empty($selectedDiscountTypes)
        ? array_key_first($selectedDiscountTypes)
        : 'none';

$pwdDiscountRate = 20.00;
$seniorDiscountRate = 20.00;

$discountSettingsStmt = $pdo->prepare("
    SELECT setting_key, setting_value
    FROM settings
    WHERE setting_key IN (
        'pwd_discount_rate',
        'senior_discount_rate'
    )
");

$discountSettingsStmt->execute();

foreach (
    $discountSettingsStmt->fetchAll(PDO::FETCH_ASSOC)
    as $setting
) {

    $settingValue = (float)$setting['setting_value'];

    if ($settingValue < 0 || $settingValue > 100) {
        continue;
    }

    if ($setting['setting_key'] === 'pwd_discount_rate') {
        $pwdDiscountRate = $settingValue;
    }

    if ($setting['setting_key'] === 'senior_discount_rate') {
        $seniorDiscountRate = $settingValue;
    }
}

$cartDiscountRate = 0.00;

if ($cartDiscountType === 'pwd') {
    $cartDiscountRate = $pwdDiscountRate;
} elseif ($cartDiscountType === 'senior') {
    $cartDiscountRate = $seniorDiscountRate;
}

$customer_discount = round(
    $discountEligibleBase *
    ($cartDiscountRate / 100),
    2
);


/*
|--------------------------------------------------------------------------
| DO NOT STACK PWD/SENIOR WITH PROMOTION
|--------------------------------------------------------------------------
|
| This preview mirrors checkout.php:
| - promotion remains when it gives the larger discount
| - PWD/Senior replaces it when the PWD/Senior discount is larger
|
*/

$displayPromotionDiscount = $promotion_discount;
$displayDiscountAmount = 0.00;
$displayDiscountType = 'none';

if (
    count($selectedDiscountTypes) === 1 &&
    $cartDiscountType !== 'none' &&
    $customer_discount > $promotion_discount
) {

    $displayPromotionDiscount = 0.00;
    $displayDiscountAmount = $customer_discount;
    $displayDiscountType = $cartDiscountType;

    /*
     * A PWD/Senior discount cannot be combined with a promotion reward.
     * Hide reward value from the gross display when PWD/Senior wins.
     */
    $display_subtotal = $subtotal;

} elseif (
    count($selectedDiscountTypes) === 1 &&
    $cartDiscountType !== 'none' &&
    $customer_discount > 0 &&
    $promotion_discount <= 0
) {

    $displayDiscountAmount = $customer_discount;
    $displayDiscountType = $cartDiscountType;
}

$cart_total = round(
    max(
        0,
        $display_subtotal
            - $displayPromotionDiscount
            - $displayDiscountAmount
    ),
    2
);


/*
|--------------------------------------------------------------------------
| PRE-FILL CUSTOMER DETAILS
|--------------------------------------------------------------------------
*/

$prefillFullName = '';
$prefillMobile = '';

if (
    isset($_SESSION['user_id']) &&
    isset($_SESSION['user_role']) &&
    $_SESSION['user_role'] === 'customer'
) {
    /*
     * Keep your existing customers table structure.
     */
    $stmtCustomer = $pdo->prepare("
        SELECT full_name, contact_number
        FROM customers
        WHERE id = ?
    ");

    $stmtCustomer->execute([$_SESSION['user_id']]);
    $customerData = $stmtCustomer->fetch(PDO::FETCH_ASSOC);

    if ($customerData) {

        $prefillFullName = $customerData['full_name'] ?? '';
        $prefillMobile = $customerData['contact_number'] ?? '';
    }
}

/*
|--------------------------------------------------------------------------
| LOAD CART PRODUCT DATA FOR EDIT MODALS
|--------------------------------------------------------------------------
*/
$cartProducts = [];
$cartProductAddons = [];
$cartPromotionSizes = [];

$cartProductIds = [];
$cartPromotionContexts = [];

if (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $cartItem) {
        $productId = (int)($cartItem['product_id'] ?? 0);
        if ($productId > 0) {
            $cartProductIds[$productId] = $productId;
        }

        $promotionId = (int)($cartItem['promotion_source_id'] ?? 0);
        $promotionRole = trim((string)($cartItem['promotion_source_role'] ?? ''));

        if ($promotionId > 0 && in_array($promotionRole, ['buy', 'get'], true) && $productId > 0) {
            $cartPromotionContexts[] = [
                'promotion_id' => $promotionId,
                'product_id' => $productId,
                'role' => $promotionRole,
            ];
        }
    }
}

if (!empty($cartProductIds)) {
    $productPlaceholders = implode(',', array_fill(0, count($cartProductIds), '?'));

    $cartProductStmt = $pdo->prepare("
        SELECT id, name, image, price, regular_price, grande_price, is_available, is_archived
        FROM products
        WHERE id IN ($productPlaceholders)
    ");
    $cartProductStmt->execute(array_values($cartProductIds));

    foreach ($cartProductStmt->fetchAll(PDO::FETCH_ASSOC) as $cartProduct) {
        $cartProducts[(int)$cartProduct['id']] = $cartProduct;
    }

    $cartAddonStmt = $pdo->prepare("
        SELECT
            pa.product_id,
            a.id,
            a.name,
            a.price
        FROM product_addons pa
        INNER JOIN addons a
            ON pa.addon_id = a.id
        WHERE pa.product_id IN ($productPlaceholders)
          AND a.is_available = 1
          AND a.is_archived = 0
        ORDER BY pa.sort_order ASC, a.name ASC
    ");
    $cartAddonStmt->execute(array_values($cartProductIds));

    foreach ($cartAddonStmt->fetchAll(PDO::FETCH_ASSOC) as $cartAddon) {
        $pid = (int)$cartAddon['product_id'];
        $cartProductAddons[$pid][] = $cartAddon;
    }
}

if (!empty($cartPromotionContexts)) {
    $seenPromotionContexts = [];

    foreach ($cartPromotionContexts as $context) {
        $contextKey = $context['promotion_id'] . '|' . $context['product_id'] . '|' . $context['role'];

        if (isset($seenPromotionContexts[$contextKey])) {
            continue;
        }

        $seenPromotionContexts[$contextKey] = true;

        $promoStmt = $pdo->prepare("
            SELECT pri.size AS promotion_size
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
        $promoStmt->execute([
            $context['promotion_id'],
            $context['product_id'],
            $context['role'],
        ]);

        $promoRow = $promoStmt->fetch(PDO::FETCH_ASSOC);
        $cartPromotionSizes[$contextKey] = strtolower(trim((string)($promoRow['promotion_size'] ?? '')));
    }
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';

?>

<style>
    /* Sticky footer: laging nasa ilalim ng screen ang footer kahit maikli ang content (empty cart / success) */
    html {
        min-height: 100%;
    }

    body {
        min-height: 100vh;
        display: flex;
        flex-direction: column;
    }

    body > .ck-page {
        flex: 1 0 auto;
        width: 100%;
    }

    /* =========================================================
       CART / CHECKOUT — LocaliTea
       Palette: espresso #2C221E · roast #4A3525 · mocha #6F4E37
                oat #F3EADF · foam #FBF7F1 · line #E6DACB
    ========================================================= */
    body {
        background-color: #FBF7F1;
    }

    .ck-page {
        max-width: 1040px;
    }

    /* ---------- Layout ---------- */
    .ck-grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(320px, 400px);
        gap: 20px;
        align-items: start;
    }

    .ck-card {
        background: #ffffff;
        border: 1px solid #E6DACB;
        border-radius: 20px;
        box-shadow: 0 10px 26px rgba(74, 53, 37, .06);
        padding: 22px 24px;
    }
    .ck-summary {
        position: sticky;
        top: 90px;
    }

    .ck-section + .ck-section {
        margin-top: 22px;
        padding-top: 22px;
        border-top: 1px solid #EFE5D9;
    }
    .ck-section-title {
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 0 0 14px;
        color: #2C221E;
        font-size: 1rem;
        font-weight: 600;
    }
    .ck-section-icon {
        flex: 0 0 auto;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 30px;
        height: 30px;
        border-radius: 50%;
        background: #F3EADF;
        color: #4A3525;
        font-size: .9rem;
    }

    /* ---------- Fields ---------- */
    .ck-fields {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }
    .ck-fields.is-single { grid-template-columns: minmax(0, 1fr); }
    .ck-field-label {
        display: block;
        margin-bottom: 5px;
        color: #6D5B4C;
        font-size: .78rem;
        font-weight: 500;
    }

    .custom-input,
    .ck-card .form-control {
        background-color: #ffffff;
        border: 1.5px solid #E0D2C2;
        border-radius: 12px;
        padding: 10px 13px;
        color: #2C221E;
        font-size: .92rem;
    }
    .custom-input::placeholder { color: #B2A394; }
    .custom-input:focus,
    .form-control:focus {
        border-color: #4A3525 !important;
        box-shadow: 0 0 0 .2rem rgba(74, 53, 37, .14) !important;
    }
    .custom-input:disabled,
    .ck-card .form-control:disabled {
        background-color: #F7F2EB;
        color: #A39485;
    }

    /* ---------- Choice tiles (pick-up + payment) ----------
       The real radio stays in the DOM and covers the tile, so
       required-validation and the existing handlers still work. */
    .ck-choices {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
    }
    .ck-choices + .ck-fields { margin-top: 12px; }

    .ck-choice.form-check {
        position: relative;
        padding: 0;
        margin: 0;
        min-height: 0;
    }
    .ck-choice.form-check .form-check-input {
        position: absolute;
        inset: 0;
        z-index: 1;
        float: none;
        width: 100%;
        height: 100%;
        margin: 0;
        opacity: 0;
        cursor: pointer;
    }
    .ck-choice .form-check-label {
        display: flex;
        align-items: center;
        gap: 10px;
        width: 100%;
        height: 100%;
        padding: 12px 14px;
        border: 1.5px solid #E0D2C2;
        border-radius: 14px;
        background: #ffffff;
        color: #2C221E;
        font-size: .9rem;
        font-weight: 500;
        line-height: 1.2;
    }
    .ck-choice .form-check-label i {
        font-size: 1.1rem;
        color: #8A7A6C;
    }
    .ck-choice:hover .form-check-input:not(:checked) + .form-check-label {
        border-color: #B8A08A;
        background: #FDF9F4;
    }
    .ck-choice .form-check-input:checked + .form-check-label {
        background: #332317;
        border-color: #332317;
        color: #ffffff;
    }
    .ck-choice .form-check-input:checked + .form-check-label i { color: #E9D9C6; }
    .ck-choice .form-check-input:focus-visible + .form-check-label {
        outline: 3px solid rgba(111, 78, 55, .35);
        outline-offset: 2px;
    }

    /* ---------- Order summary ---------- */
    .ck-summary-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        margin-bottom: 14px;
    }
    .ck-summary-title {
        margin: 0;
        color: #2C221E;
        font-size: 1.05rem;
        font-weight: 600;
    }

    .ck-items {
        display: flex;
        flex-direction: column;
    }

    .ck-item {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr) auto;
        column-gap: 10px;
        row-gap: 6px;
        padding: 13px 0;
        border-bottom: 1px solid #EFE5D9;
    }
    .ck-item:first-child { padding-top: 0; }
    .ck-item:last-child { border-bottom: 0; }

    .ck-item-qty {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 32px;
        height: 24px;
        padding: 0 8px;
        border-radius: 50px;
        background: #F3EADF;
        color: #4A3525;
        font-size: .76rem;
        font-weight: 600;
        font-variant-numeric: tabular-nums;
    }
    .ck-item-name {
        min-width: 0;
        color: #2C221E;
        font-size: .92rem;
        font-weight: 600;
        line-height: 1.3;
        overflow-wrap: anywhere;
    }
    .ck-item-price {
        color: #2C221E;
        font-size: .92rem;
        font-weight: 600;
        white-space: nowrap;
        font-variant-numeric: tabular-nums;
    }
    .ck-item-price.is-free { color: #2F7A4A; }

    .ck-item-meta,
    .ck-item-actions {
        grid-column: 2 / 4;
    }
    .ck-item-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 5px;
    }
    .ck-chip {
        display: inline-block;
        padding: 2px 9px;
        border-radius: 50px;
        background: #F6EFE6;
        color: #6D5B4C;
        font-size: .72rem;
        font-weight: 500;
        line-height: 1.5;
    }
    .ck-chip.is-discount {
        background: #EFE0CF;
        color: #4A3525;
        font-weight: 500;
    }
    .ck-item-note {
        flex-basis: 100%;
        color: #6B625B;
        font-size: .72rem;
    }
    .ck-item-charge {
        flex-basis: 100%;
        color: #2C221E;
        font-size: .74rem;
        font-weight: 500;
    }

    /* Free / promotion lines */
    .ck-item.is-free {
        margin: 4px 0;
        padding: 12px;
        border: 0;
        border-radius: 14px;
        background: #F3F7EC;
    }
    .cart-free-label {
        display: inline-flex;
        align-items: center;
        height: 24px;
        padding: 0 9px;
        border-radius: 50px;
        background: #2F7A4A;
        color: #ffffff;
        font-size: .68rem;
        font-weight: 600;
        letter-spacing: .2px;
    }
    .cart-free-note {
        flex-basis: 100%;
        color: #55704F;
        font-size: .72rem;
        font-weight: 500;
    }
    .ck-item-lead {
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    /* Edit / remove */
    .cart-action-links {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .cart-edit-link,
    a.cart-remove-link {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
        height: 30px;
        padding: 0 12px;
        border-radius: 50px;
        font-size: .74rem;
        font-weight: 500;
        text-decoration: none;
    }
    .cart-edit-link {
        border: 1.5px solid #D5C6BA;
        background: #ffffff;
        color: #4A3525 !important;
    }
    .cart-edit-link:hover {
        background: #F3EADF;
        border-color: #4A3525;
        color: #2C221E !important;
    }
    a.cart-remove-link {
        border: 1.5px solid transparent;
        background: transparent;
        color: #B3382F !important;
    }
    a.cart-remove-link:hover {
        background: #FCEBEA;
        color: #8E2820 !important;
    }

    /* Totals */
    .ck-totals {
        margin-top: 8px;
        padding-top: 14px;
        border-top: 1px solid #EFE5D9;
    }
    .ck-row {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 8px;
        color: #6D5B4C;
        font-size: .86rem;
        font-variant-numeric: tabular-nums;
    }
    .ck-row.is-promo { color: #2F7A4A; }
    .ck-row.is-discount { color: #6F4E37; }
    .ck-row.is-total {
        margin: 12px 0 16px;
        padding-top: 14px;
        border-top: 1px dashed #D9CABB;
        color: #2C221E;
        font-size: 1.25rem;
        font-weight: 600;
    }

    .btn-brown-custom {
        background-color: #332317;
        border: 1.5px solid #24170F;
        color: #ffffff;
        border-radius: 50px;
        padding: .75rem 1.2rem;
        font-size: .95rem;
        font-weight: 500;
        letter-spacing: .3px;
        box-shadow: 0 6px 16px rgba(51, 35, 23, .2);
    }
    .btn-brown-custom:hover,
    .btn-brown-custom:focus-visible {
        background-color: #24170F;
        border-color: #1A100B;
        color: #ffffff;
    }
    .btn-brown-custom:disabled { opacity: .7; }

    /* Empty + success states */
    .ck-empty {
        max-width: 480px;
        margin: 0 auto;
        padding: 44px 28px;
        text-align: center;
    }
    .ck-empty-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 68px;
        height: 68px;
        margin-bottom: 14px;
        border-radius: 50%;
        background: #F3EADF;
        color: #6F4E37;
        font-size: 1.8rem;
    }
    .ck-empty h2 {
        margin: 0 0 6px;
        color: #2C221E;
        font-size: 1.2rem;
        font-weight: 600;
    }
    .ck-empty p {
        margin: 0 0 18px;
        color: #8A7A6C;
        font-size: .9rem;
    }

    /* ---------- Modals (Edit item + GCash) ----------
       Compact dialogs that never grow taller than the screen:
       the header and footer stay put, only the body scrolls. */
    .cart-edit-modal,
    #gcashModal { --bs-modal-margin: .75rem; }

    .cart-edit-modal .modal-dialog,
    #gcashModal .modal-dialog {
        margin: .75rem auto;
        min-height: calc(100% - 1.5rem);
    }
    .cart-edit-modal .modal-dialog { max-width: 420px; }
    #gcashModal .modal-dialog      { max-width: 380px; }

    .cart-edit-modal .modal-content,
    #gcashModal .modal-content {
        display: flex;
        flex-direction: column;
        border: none;
        border-radius: 18px;
        overflow: hidden;
        max-height: calc(100vh - 1.5rem);
        max-height: calc(100dvh - 1.5rem);
    }

    /* The edit form sits between .modal-content and .modal-body,
       so it has to take part in the flex column. */
    .cart-edit-modal .modal-content > form {
        display: flex;
        flex-direction: column;
        flex: 1 1 auto;
        min-height: 0;
        margin: 0;
    }

    .cart-edit-modal .modal-header,
    #gcashModal .modal-header {
        flex: 0 0 auto;
        padding: 12px 16px;
        background: #332317;
        color: #ffffff;
    }
    .cart-edit-modal .modal-title,
    #gcashModal .modal-title {
        margin: 0;
        font-size: .95rem;
        font-weight: 600;
        line-height: 1.3;
    }
    .cart-edit-modal .btn-close,
    #gcashModal .btn-close { transform: scale(.8); }

    /* Bootstrap's "scrollable" dialog is stretched to full height; let it fit its content instead. */
    .cart-edit-modal .modal-dialog-scrollable { height: auto; }

    .cart-edit-modal .modal-body,
    #gcashModal .modal-body {
        flex: 0 1 auto;
        min-height: 0;
        /* header (~48px) + footer (~58px) + dialog margins (24px) + a little spare */
        max-height: calc(100vh - 9rem);
        max-height: calc(100dvh - 9rem);
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
        overscroll-behavior: contain;
        padding: 14px 16px;
    }

    /* thinner text inside the modals (the markup uses Bootstrap's bold helpers) */
    .cart-edit-modal .fw-bold,
    #gcashModal .fw-bold { font-weight: 600 !important; }
    .cart-edit-modal .fw-semibold,
    #gcashModal .fw-semibold { font-weight: 500 !important; }

    .cart-edit-modal .modal-footer,
    #gcashModal .modal-footer {
        flex: 0 0 auto;
        gap: 8px;
        margin: 0;
        padding: 10px 16px;
        background: #FDF8F2;
        border-top: 1px solid #E5DAD1;
    }
    .cart-edit-modal .modal-footer > *,
    #gcashModal .modal-footer > * { margin: 0; }
    .cart-edit-modal .modal-footer .btn,
    #gcashModal .modal-footer .btn {
        min-height: 38px;
        padding: 0 18px;
        border-radius: 50px;
        font-size: .85rem;
        font-weight: 500;
    }

    /* Edit item: option tiles */
    .cart-edit-modal .modal-body .mb-3 { margin-bottom: .8rem !important; }
    .cart-edit-modal .modal-body .mb-3:last-child { margin-bottom: 0 !important; }
    .cart-edit-modal .form-label {
        margin-bottom: 6px;
        color: #2C221E;
        font-size: .82rem;
        font-weight: 600;
    }
    .cart-edit-modal .d-flex.flex-wrap.gap-3 { gap: .4rem !important; }
    .cart-edit-modal .small.text-muted { font-size: .72rem !important; }
    .cart-edit-modal .form-check-label .text-muted { white-space: nowrap; font-size: .74rem; }

    .cart-edit-modal .form-check {
        position: relative;
        padding: 0;
        margin: 0;
        min-height: 0;
    }
    .cart-edit-modal .form-check .form-check-input {
        position: absolute;
        inset: 0;
        z-index: 1;
        float: none;
        width: 100%;
        height: 100%;
        margin: 0;
        opacity: 0;
        cursor: pointer;
    }
    .cart-edit-modal .form-check .form-check-input:disabled { cursor: not-allowed; }
    .cart-edit-modal .form-check-label {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        width: 100%;
        height: 100%;
        min-width: 56px;
        padding: 7px 11px;
        border: 1.5px solid #E0D2C2;
        border-radius: 11px;
        background: #ffffff;
        color: #2C221E;
        font-size: .8rem;
        font-weight: 500;
        line-height: 1.25;
        text-align: center;
    }
    .cart-edit-modal .form-check-input:disabled + .form-check-label { opacity: .5; }
    .cart-edit-modal .form-check-input:focus-visible + .form-check-label {
        outline: 3px solid rgba(111, 78, 55, .35);
        outline-offset: 2px;
    }
    /* single choice = filled */
    .cart-edit-modal .form-check-input[type="radio"]:checked + .form-check-label {
        background: #332317;
        border-color: #332317;
        color: #ffffff;
    }
    .cart-edit-modal .form-check-input[type="radio"]:checked + .form-check-label .text-muted {
        color: #E9D9C6 !important;
    }
    /* multi choice (add-ons) = checkbox mark */
    .cart-edit-modal .form-check-input[type="checkbox"] + .form-check-label {
        justify-content: flex-start;
        text-align: left;
    }
    .cart-edit-modal .form-check-input[type="checkbox"] + .form-check-label::before {
        content: "";
        flex: 0 0 16px;
        width: 16px;
        height: 16px;
        border: 1.5px solid #B8A08A;
        border-radius: 5px;
        background: #ffffff center / 10px no-repeat;
    }
    .cart-edit-modal .form-check-input[type="checkbox"]:checked + .form-check-label {
        border-color: #332317;
        background: #F7F0E8;
    }
    .cart-edit-modal .form-check-input[type="checkbox"]:checked + .form-check-label::before {
        border-color: #332317;
        background-color: #332317;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Cpath fill='none' stroke='%23fff' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round' d='M3.5 8.5l3 3 6-7'/%3E%3C/svg%3E");
    }

    /* GCash modal */
    .gcash-qr-container {
        background-color: #FBF7F1;
        border: 1.5px solid #E0D2C2;
        border-radius: 14px;
        padding: 12px;
        margin-bottom: .75rem !important;
        text-align: center;
        font-size: .85rem;
    }
    .gcash-qr {
        width: 150px;
        max-width: 100%;
        height: auto;
        border-radius: 10px;
        background-color: #ffffff;
        padding: 6px;
    }
    #gcashModal .gcash-qr-container .mt-3 { margin-top: .5rem !important; font-size: .78rem; }
    .gcash-amount {
        font-size: 1.3rem;
        font-weight: 600;
        color: #4A3525;
        line-height: 1.2;
    }
    .gcash-instructions {
        background-color: #F7F0E8;
        border-radius: 12px;
        padding: 10px 14px;
        margin-bottom: .75rem !important;
        font-size: .78rem;
        line-height: 1.45;
    }
    .gcash-instructions .fw-bold { margin-bottom: .3rem !important; font-size: .82rem; }
    .gcash-upload-box {
        border: 1.5px dashed #B8A08A;
        background-color: #FBF7F1;
        border-radius: 12px;
        padding: 10px 12px;
    }
    #gcashModal .form-text { font-size: .72rem; }
    #gcashModal .custom-input { padding: 7px 11px; font-size: .84rem; }

    /* ---------- AJAX checkout loading overlay ---------- */
    .checkout-loading-overlay {
        position: fixed;
        inset: 0;
        z-index: 2000;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 20px;
        background: rgba(74, 53, 37, .28);
        backdrop-filter: blur(3px);
    }
    .checkout-loading-overlay.is-visible { display: flex; }
    .checkout-loading-box {
        width: min(92vw, 360px);
        padding: 28px 24px;
        background: #FFFFFF;
        border: 2px solid #6F4E37;
        border-radius: 18px;
        box-shadow: 0 16px 40px rgba(44, 34, 30, .18);
        text-align: center;
    }
    .checkout-loading-spinner {
        width: 44px;
        height: 44px;
        margin: 0 auto 14px;
        border: 4px solid #E8DFD4;
        border-top-color: #6F4E37;
        border-radius: 50%;
        animation: checkoutSpin .75s linear infinite;
    }
    .checkout-loading-title {
        margin: 0;
        color: #4A3525;
        font-size: 1rem;
        font-weight: 600;
    }
    .checkout-loading-text {
        margin: 6px 0 0;
        color: #8A7A6C;
        font-size: .82rem;
    }
    body.checkout-loading-active { overflow: hidden; }
    @keyframes checkoutSpin { to { transform: rotate(360deg); } }

    @media (prefers-reduced-motion: no-preference) {
        .ck-choice .form-check-label,
        .cart-edit-link,
        a.cart-remove-link,
        .btn-brown-custom,
        .cart-edit-modal .form-check-label {
            transition: background-color .15s ease, border-color .15s ease, color .15s ease;
        }
    }
    @media (prefers-reduced-motion: reduce) {
        .checkout-loading-spinner { animation-duration: 2s; }
    }

    /* ---------- Long carts: the item list scrolls, totals + Checkout stay in view ---------- */
    .ck-items-scroll {
        max-height: min(46vh, 380px);
        overflow-y: auto;
        overscroll-behavior: contain;
        padding-right: 6px;
        margin-right: -6px;
        scrollbar-width: thin;
        scrollbar-color: #C9B8A6 transparent;
    }
    .ck-items-scroll::-webkit-scrollbar { width: 6px; }
    .ck-items-scroll::-webkit-scrollbar-thumb { background: #C9B8A6; border-radius: 10px; }
    @media (min-width: 900px) {
        /* Desktop: the whole summary card fits the screen; only the item list scrolls,
           so Subtotal / Total / Checkout always stay visible. */
        .ck-summary {
            display: flex;
            flex-direction: column;
            /* 250px = navbar + page spacing above the card, so the card (incl. Checkout)
               fits fully on screen without scrolling the page */
            max-height: calc(100vh - 250px);
            max-height: calc(100dvh - 250px);
        }
        .ck-summary .ck-summary-head,
        .ck-summary .ck-totals { flex: 0 0 auto; }
        .ck-items-scroll {
            flex: 1 1 auto;
            min-height: 120px;
            max-height: none;
        }
    }

    /* ---------- Tablet / mobile ---------- */
    @media (max-width: 899.98px) {
        .ck-grid {
            grid-template-columns: minmax(0, 1fr);
            gap: 16px;
        }
        .ck-summary { position: static; }
    }

    @media (max-width: 575.98px) {
        .container.ck-page {
            padding-top: 1rem !important;
            padding-bottom: 1.5rem !important;
        }
        .ck-title { font-size: 1.3rem; }
        .ck-card { padding: 18px 16px; border-radius: 18px; }

        /* 16px stops iOS Safari zooming into the field on focus */
        .custom-input,
        .ck-card .form-control {
            font-size: 16px;
            min-height: 46px;
        }
        .ck-choice .form-check-label {
            padding: 12px;
            font-size: .86rem;
            min-height: 48px;
        }
        .ck-fields { grid-template-columns: minmax(0, 1fr); }

        .cart-edit-link,
        a.cart-remove-link {
            height: 36px;
            padding: 0 14px;
            font-size: .78rem;
        }

        .btn-brown-custom { min-height: 50px; }

        .cart-edit-modal .modal-dialog,
        #gcashModal .modal-dialog { max-width: calc(100% - 1.5rem); }
        .cart-edit-modal .form-check-label { min-height: 40px; }
        .gcash-qr { width: min(150px, 46vw); }
        .checkout-loading-box { padding: 24px 18px; border-radius: 16px; }
    }
</style>


<div
    id="checkoutLoadingOverlay"
    class="checkout-loading-overlay"
    aria-hidden="true"
>
    <div
        class="checkout-loading-box"
        role="status"
        aria-live="polite"
        aria-label="Placing order"
    >
        <div class="checkout-loading-spinner" aria-hidden="true"></div>
        <p class="checkout-loading-title">Placing your order...</p>
        <p class="checkout-loading-text">Please wait while we process your checkout.</p>
    </div>
</div>

<div class="container ck-page py-4 py-md-5">

    <?php if (isset($_GET['edit_success'])): ?>
        <div class="alert alert-success border-0 small fw-semibold py-2 mb-3" role="alert">
            <i class="bi bi-check-circle me-1"></i> Cart item updated successfully.
        </div>
    <?php endif; ?>

    <?php if (!empty($_GET['edit_error'])): ?>
        <div class="alert alert-danger border-0 small fw-semibold py-2 mb-3" role="alert">
            <i class="bi bi-exclamation-circle me-1"></i>
            <?= htmlspecialchars((string)$_GET['edit_error']) ?>
        </div>
    <?php endif; ?>

    <?php if ($successMessage): ?>

        <div class="ck-card ck-empty">

            <span class="ck-empty-icon"><i class="bi bi-check2-circle"></i></span>

            <div class="alert alert-success border-0 bg-light text-success fw-bold py-3 mb-3">
                <?= htmlspecialchars($successMessage) ?>
            </div>

            <a href="menu.php" class="btn btn-brown-custom px-4">
                Order More Milktea
            </a>

        </div>

    <?php else: ?>

        <?php if (empty($_SESSION['cart'])): ?>

            <div class="ck-card ck-empty">

                <span class="ck-empty-icon"><i class="bi bi-cart-x"></i></span>

                <h2>Your cart is empty.</h2>

                <p>Please add products to your cart before checking out.</p>

                <a href="menu.php" class="btn btn-brown-custom px-4">
                    Go to Menu
                </a>

            </div>

        <?php else: ?>

            <h1 class="visually-hidden">Checkout</h1>

            <!--
            |--------------------------------------------------------------------------
            | MAIN CHECKOUT FORM
            |--------------------------------------------------------------------------
            |
            | IMPORTANT:
            | enctype="multipart/form-data" is required because the GCash
            | screenshot will be uploaded through this form.
            |
            -->

            <form
                id="checkoutForm"
                action="checkout.php"
                method="POST"
                enctype="multipart/form-data"
                onsubmit="return handleCheckoutSubmit(event)"
            >

                <div class="ck-grid">

                    <!--
                    ================================================================
                    LEFT SIDE
                    Guest Details / Pickup / Payment
                    ================================================================
                    -->

                    <section class="ck-card ck-details">

                        <!-- Guest Details -->
                        <div class="ck-section">

                            <h2 class="ck-section-title">
                                <span class="ck-section-icon"><i class="bi bi-person"></i></span>
                                Guest details
                            </h2>

                            <div class="ck-fields is-single">

                                <div>
                                    <label for="full_name" class="ck-field-label">Full name</label>
                                    <input
                                        type="text"
                                        name="full_name"
                                        id="full_name"
                                        class="form-control custom-input"
                                        value="<?= htmlspecialchars($prefillFullName) ?>"
                                        autocomplete="name"
                                        required
                                    >
                                </div>

                                <div>
                                    <label for="mobile_number" class="ck-field-label">Mobile number</label>
                                    <input
                                        type="text"
                                        name="mobile_number"
                                        id="mobile_number"
                                        class="form-control custom-input"
                                        placeholder="09XXXXXXXXX"
                                        value="<?= htmlspecialchars($prefillMobile) ?>"
                                        inputmode="tel"
                                        autocomplete="tel"
                                        required
                                    >
                                </div>

                            </div>

                        </div>


                        <!-- Pickup -->
                        <div class="ck-section">

                            <h2 class="ck-section-title">
                                <span class="ck-section-icon"><i class="bi bi-clock"></i></span>
                                Pick-up time
                            </h2>

                            <div class="ck-choices">

                                <div class="form-check ck-choice">
                                    <input
                                        class="form-check-input"
                                        type="radio"
                                        name="pickup_type"
                                        id="now"
                                        value="Now"
                                        required
                                        onclick="togglePickupFields(false)"
                                    >
                                    <label class="form-check-label" for="now">
                                        <i class="bi bi-lightning-charge"></i> Now
                                    </label>
                                </div>

                                <div class="form-check ck-choice">
                                    <input
                                        class="form-check-input"
                                        type="radio"
                                        name="pickup_type"
                                        id="later"
                                        value="Pick-up later"
                                        required
                                        onclick="togglePickupFields(true)"
                                    >
                                    <label class="form-check-label" for="later">
                                        <i class="bi bi-calendar-event"></i> Pick-up later
                                    </label>
                                </div>

                            </div>

                            <div class="ck-fields" id="pickup-later-fields">

                                <div>
                                    <label for="pickup_date" class="ck-field-label">Date</label>
                                    <input
                                        type="date"
                                        name="pickup_date"
                                        id="pickup_date"
                                        class="form-control custom-input"
                                        value="<?= date('Y-m-d') ?>"
                                        min="<?= date('Y-m-d') ?>"
                                        disabled
                                    >
                                </div>

                                <div>
                                    <label for="pickup_time" class="ck-field-label">Time (9AM-10PM)</label>
                                    <input
                                        type="time"
                                        name="pickup_time"
                                        id="pickup_time"
                                        class="form-control custom-input"
                                        min="09:00"
                                        max="22:00"
                                        disabled
                                    >
                                </div>

                            </div>

                        </div>


                        <!-- Payment -->
                        <div class="ck-section">

                            <h2 class="ck-section-title">
                                <span class="ck-section-icon"><i class="bi bi-wallet2"></i></span>
                                Payment method
                            </h2>

                            <div class="ck-choices">

                                <!-- CASH -->
                                <div class="form-check ck-choice">
                                    <input
                                        class="form-check-input"
                                        type="radio"
                                        name="payment_method"
                                        id="cash"
                                        value="Cash"
                                        required
                                        onchange="handlePaymentMethodChange()"
                                    >
                                    <label class="form-check-label" for="cash">
                                        <i class="bi bi-cash-stack"></i> Cash
                                    </label>
                                </div>

                                <!-- GCASH -->
                                <div class="form-check ck-choice">
                                    <input
                                        class="form-check-input"
                                        type="radio"
                                        name="payment_method"
                                        id="gcash"
                                        value="G-Cash"
                                        required
                                        onchange="handlePaymentMethodChange()"
                                    >
                                    <label class="form-check-label" for="gcash">
                                        <i class="bi bi-phone"></i> G-Cash
                                    </label>
                                </div>

                            </div>

                        </div>

                    </section>


                    <!--
                    ================================================================
                    RIGHT SIDE
                    Order Details / Cart
                    ================================================================
                    -->

                    <aside class="ck-card ck-summary">

                        <div class="ck-summary-head">
                            <h2 class="ck-summary-title">Order summary</h2>
                        </div>

                        <div class="ck-items-scroll">
                            <div class="ck-items">

                                <?php

                                        foreach ($_SESSION['cart'] as $key => $item):

                                            $itemPrice = (float)($item['price'] ?? 0);
                                            $itemQuantity = (int)($item['quantity'] ?? 0);

                                            $freeQuantity = (int)(
                                                $promotion_free_allocations[(string)$key] ?? 0
                                            );

                                            $freeQuantity = max(
                                                0,
                                                min($itemQuantity, $freeQuantity)
                                            );

                                            $paidQuantity = $itemQuantity - $freeQuantity;

                                            /*
                                             * The promotion makes only the drink base free.
                                             * Add-ons selected on the free item remain payable.
                                             */
                                            $itemPricing = localiteaCalculateItemPricing(
                                                $pdo,
                                                $item
                                            );
                                            $addonUnitPrice = (float)(
                                                $itemPricing['addons_unit_price'] ?? 0
                                            );
                                            $freeAddonTotal = round(
                                                $addonUnitPrice * $freeQuantity,
                                                2
                                            );

                                            $itemTotal = round(
                                                ($itemPrice * $paidQuantity) +
                                                $freeAddonTotal,
                                                2
                                            );

                                            $itemProductId = (int)($item['product_id'] ?? 0);
                                            $editModalId = 'editCartModal_' . substr(md5((string)$key), 0, 12);

                                ?>

                                <?php if ($paidQuantity > 0): ?>
                                    <div class="ck-item">
                                        <span class="ck-item-qty"><?= $paidQuantity ?>x</span>
                                        <span class="ck-item-name"><?= htmlspecialchars($item['name'] ?? '') ?></span>
                                        <span class="ck-item-price">₱<?= number_format($itemTotal, 2) ?></span>

                                        <div class="ck-item-meta">
                                            <?php if (!empty($item['size'])): ?>
                                                <span class="ck-chip"><?= htmlspecialchars($item['size']) ?></span>
                                            <?php endif; ?>

                                            <?php if (!empty($item['sugar_level'])): ?>
                                                <span class="ck-chip">Sugar: <?= htmlspecialchars($item['sugar_level']) ?></span>
                                            <?php endif; ?>

                                            <?php if (!empty($item['addons'])): ?>
                                                <span class="ck-chip">
                                                    Add-ons:
                                                    <?= htmlspecialchars(
                                                        is_array($item['addons'])
                                                            ? implode(', ', $item['addons'])
                                                            : $item['addons']
                                                    ) ?>
                                                </span>
                                            <?php endif; ?>

                                            <?php
                                                $itemDiscountType = strtolower(
                                                    trim((string)($item['discount_type'] ?? 'none'))
                                                );
                                            ?>

                                            <?php if ($itemDiscountType === 'pwd'): ?>
                                                <span class="ck-chip is-discount">PWD Discount</span>
                                            <?php elseif ($itemDiscountType === 'senior'): ?>
                                                <span class="ck-chip is-discount">Senior Citizen Discount</span>
                                            <?php endif; ?>

                                            <?php if ($itemDiscountType !== 'none' && !empty($item['discount_id_number'])): ?>
                                                <span class="ck-chip">
                                                    ID: <?= htmlspecialchars((string)($item['discount_id_name'] ?? '')) ?>
                                                    (<?= htmlspecialchars((string)$item['discount_id_number']) ?>)
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="ck-item-actions cart-action-links">
                                            <?php if (isset($cartProducts[$itemProductId])): ?>
                                                <a
                                                    href="#<?= htmlspecialchars($editModalId) ?>"
                                                    class="cart-edit-link"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#<?= htmlspecialchars($editModalId) ?>"
                                                    title="Edit item"
                                                >
                                                    <i class="bi bi-pencil"></i> Edit
                                                </a>
                                            <?php endif; ?>
                                            <a
                                                href="remove-from-cart.php?key=<?= urlencode($key) ?>"
                                                class="cart-remove-link"
                                                title="Remove item"
                                                aria-label="Remove item"
                                            >
                                                <i class="bi bi-trash"></i> Remove
                                            </a>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($freeQuantity > 0): ?>
                                    <div class="ck-item is-free cart-free-line">
                                        <span class="ck-item-qty"><?= $freeQuantity ?>x</span>
                                        <span class="ck-item-name">
                                            <span class="cart-free-label">FREE</span>
                                            <?= htmlspecialchars($item['name'] ?? '') ?>
                                        </span>
                                        <span class="ck-item-price <?= $freeAddonTotal > 0 ? '' : 'is-free' ?>">
                                            <?php if ($freeAddonTotal > 0): ?>
                                                +₱<?= number_format($freeAddonTotal, 2) ?>
                                            <?php else: ?>
                                                FREE
                                            <?php endif; ?>
                                        </span>

                                        <div class="ck-item-meta">
                                            <?php if (!empty($item['size'])): ?>
                                                <span class="ck-chip"><?= htmlspecialchars($item['size']) ?></span>
                                            <?php endif; ?>

                                            <?php if (!empty($item['sugar_level'])): ?>
                                                <span class="ck-chip">Sugar: <?= htmlspecialchars($item['sugar_level']) ?></span>
                                            <?php endif; ?>

                                            <?php if (!empty($item['addons'])): ?>
                                                <span class="ck-chip">
                                                    Add-ons:
                                                    <?= htmlspecialchars(
                                                        is_array($item['addons'])
                                                            ? implode(', ', $item['addons'])
                                                            : $item['addons']
                                                    ) ?>
                                                </span>
                                            <?php endif; ?>

                                            <div class="cart-free-note">
                                                Drink base included in promotion
                                            </div>

                                            <?php if ($freeAddonTotal > 0): ?>
                                                <div class="ck-item-charge">
                                                    Add-on charge: +₱<?= number_format($freeAddonTotal, 2) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="ck-item-actions cart-action-links">
                                            <?php if (isset($cartProducts[$itemProductId])): ?>
                                                <a
                                                    href="#<?= htmlspecialchars($editModalId) ?>"
                                                    class="cart-edit-link"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#<?= htmlspecialchars($editModalId) ?>"
                                                    title="Edit free item"
                                                >
                                                    <i class="bi bi-pencil"></i> Edit
                                                </a>
                                            <?php endif; ?>
                                            <a
                                                href="remove-from-cart.php?key=<?= urlencode($key) ?>"
                                                class="cart-remove-link"
                                                title="Remove free item"
                                                aria-label="Remove free item"
                                            >
                                                <i class="bi bi-trash"></i> Remove
                                            </a>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php endforeach; ?>


                                <?php foreach ($promotion_reward_items as $rewardItem): ?>

                                    <?php

                                            $sourceCartKey = trim((string)($rewardItem['source_cart_key'] ?? ''));

                                            /* Explicit customized GET lines are already shown above. */
                                            if (
                                                $sourceCartKey !== '' &&
                                                isset($_SESSION['cart'][$sourceCartKey])
                                            ) {
                                                continue;
                                            }

                                            $rewardQuantity = (int)($rewardItem['quantity'] ?? 0);

                                            if ($rewardQuantity <= 0) {
                                                continue;
                                            }

                                            $rewardPricing = localiteaCalculateItemPricing(
                                                $pdo,
                                                $rewardItem
                                            );
                                            $rewardAddonTotal = round(
                                                (float)($rewardPricing['addons_unit_price'] ?? 0) * $rewardQuantity,
                                                2
                                            );

                                    ?>

                                    <div class="ck-item is-free">
                                        <span class="ck-item-qty"><?= $rewardQuantity ?>x</span>
                                        <span class="ck-item-name">
                                            <span class="cart-free-label">FREE</span>
                                            <?= htmlspecialchars($rewardItem['name'] ?? '') ?>
                                        </span>
                                        <span class="ck-item-price <?= $rewardAddonTotal > 0 ? '' : 'is-free' ?>">
                                            <?php if ($rewardAddonTotal > 0): ?>
                                                +₱<?= number_format($rewardAddonTotal, 2) ?>
                                            <?php else: ?>
                                                FREE
                                            <?php endif; ?>
                                        </span>

                                        <div class="ck-item-meta">
                                            <?php if (!empty($rewardItem['size'])): ?>
                                                <span class="ck-chip"><?= htmlspecialchars($rewardItem['size']) ?></span>
                                            <?php endif; ?>

                                            <?php if (!empty($rewardItem['sugar_level'])): ?>
                                                <span class="ck-chip">Sugar: <?= htmlspecialchars($rewardItem['sugar_level']) ?></span>
                                            <?php endif; ?>

                                            <?php if (!empty($rewardItem['addons'])): ?>
                                                <span class="ck-chip">
                                                    Add-ons:
                                                    <?= htmlspecialchars(
                                                        is_array($rewardItem['addons'])
                                                            ? implode(', ', $rewardItem['addons'])
                                                            : $rewardItem['addons']
                                                    ) ?>
                                                </span>
                                            <?php endif; ?>

                                            <div class="cart-free-note">
                                                Drink base included in promotion
                                            </div>

                                            <?php if ($rewardAddonTotal > 0): ?>
                                                <div class="ck-item-charge">
                                                    Add-on charge: +₱<?= number_format($rewardAddonTotal, 2) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                <?php endforeach; ?>

                            </div>
                        </div>


                        <!-- TOTAL -->
                        <div class="ck-totals">

                            <div class="ck-row">
                                <span>Subtotal</span>
                                <span>₱<?= number_format($display_subtotal, 2) ?></span>
                            </div>

                            <?php if ($promotion_discount > 0): ?>
                                <div class="ck-row is-promo">
                                    <span>
                                        Promotion:
                                        <?= htmlspecialchars(
                                            $appliedPromotion['promotion_title'] ?? 'Discount'
                                        ) ?>
                                    </span>
                                    <span>-₱<?= number_format($promotion_discount, 2) ?></span>
                                </div>
                            <?php endif; ?>

                            <?php if ($displayDiscountAmount > 0 && $displayDiscountType !== 'none'): ?>
                                <div class="ck-row is-discount">
                                    <span>
                                        <?= $displayDiscountType === 'pwd'
                                            ? 'PWD Discount (' . rtrim(rtrim(number_format($cartDiscountRate, 2), '0'), '.') . '%)'
                                            : 'Senior Citizen Discount (' . rtrim(rtrim(number_format($cartDiscountRate, 2), '0'), '.') . '%)' ?>
                                    </span>
                                    <span>-₱<?= number_format($displayDiscountAmount, 2) ?></span>
                                </div>
                            <?php endif; ?>

                            <div class="ck-row is-total">
                                <span>Total</span>
                                <span>₱<?= number_format($cart_total, 2) ?></span>
                            </div>

                            <input
                                type="hidden"
                                name="checkout_action"
                                value="1"
                            >

                            <button
                                type="submit"
                                id="checkoutButton"
                                class="btn btn-brown-custom w-100 py-2"
                            >
                                Checkout
                            </button>

                        </div>

                    </aside>

                </div>

            </form>

                                <?php foreach ($_SESSION['cart'] as $key => $item): ?>
                                    <?php
                                        $itemProductId = (int)($item['product_id'] ?? 0);
                                        $itemProduct = $cartProducts[$itemProductId] ?? null;

                                        if (!$itemProduct) {
                                            continue;
                                        }

                                        $itemAddons = $cartProductAddons[$itemProductId] ?? [];
                                        $editModalId = 'editCartModal_' . substr(md5((string)$key), 0, 12);
                                        $existingAddons = is_array($item['addons'] ?? null) ? $item['addons'] : [];

                                        $promotionContextKey =
                                            (int)($item['promotion_source_id'] ?? 0) . '|' .
                                            $itemProductId . '|' .
                                            (string)($item['promotion_source_role'] ?? '');

                                        $configuredPromotionSize = $cartPromotionSizes[$promotionContextKey] ?? '';
                                        $fixedPromotionSize = in_array($configuredPromotionSize, ['regular', 'grande'], true)
                                            ? ucfirst($configuredPromotionSize)
                                            : '';

                                        $editSugar = trim((string)($item['sugar_level'] ?? ''));
                                        if (!in_array($editSugar, ['0%', '25%', '50%', '75%', '100%'], true)) {
                                            $editSugar = '25%';
                                        }
                                    ?>

                                    <div
                                        class="modal fade cart-edit-modal"
                                        id="<?= htmlspecialchars($editModalId) ?>"
                                        tabindex="-1"
                                        aria-hidden="true"
                                    >
                                        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">
                                                        <i class="bi bi-pencil-square me-1"></i>
                                                        Edit <?= htmlspecialchars($item['name'] ?? 'Item') ?>
                                                    </h5>
                                                    <button
                                                        type="button"
                                                        class="btn-close btn-close-white"
                                                        data-bs-dismiss="modal"
                                                        aria-label="Close"
                                                    ></button>
                                                </div>

                                                <form method="POST" action="cart.php">
                                                    <input type="hidden" name="cart_action" value="edit_cart_item">
                                                    <input type="hidden" name="cart_key" value="<?= htmlspecialchars($key, ENT_QUOTES) ?>">

                                                    <div class="modal-body">
                                                        <?php if (
                                                            (float)($itemProduct['regular_price'] ?? 0) > 0 ||
                                                            (float)($itemProduct['grande_price'] ?? 0) > 0
                                                        ): ?>
                                                            <div class="mb-3">
                                                                <label class="form-label">Size</label>
                                                                <div class="row g-2">
                                                                    <?php $regularPrice = (float)($itemProduct['regular_price'] ?? 0); ?>
                                                                    <?php if ($regularPrice > 0): ?>
                                                                        <div class="col-6">
                                                                            <div class="form-check">
                                                                                <input
                                                                                    class="form-check-input"
                                                                                    type="radio"
                                                                                    name="size"
                                                                                    value="Regular"
                                                                                    id="<?= htmlspecialchars($editModalId) ?>_regular"
                                                                                    <?= ($item['size'] ?? '') === 'Regular' ? 'checked' : '' ?>
                                                                                    <?= ($fixedPromotionSize !== '' && $fixedPromotionSize !== 'Regular') ? 'disabled' : '' ?>
                                                                                >
                                                                                <label class="form-check-label" for="<?= htmlspecialchars($editModalId) ?>_regular">
                                                                                    Regular (₱<?= number_format($regularPrice, 2) ?>)
                                                                                </label>
                                                                            </div>
                                                                        </div>
                                                                    <?php endif; ?>

                                                                    <?php $grandePrice = (float)($itemProduct['grande_price'] ?? 0); ?>
                                                                    <?php if ($grandePrice > 0): ?>
                                                                        <div class="col-6">
                                                                            <div class="form-check">
                                                                                <input
                                                                                    class="form-check-input"
                                                                                    type="radio"
                                                                                    name="size"
                                                                                    value="Grande"
                                                                                    id="<?= htmlspecialchars($editModalId) ?>_grande"
                                                                                    <?= ($item['size'] ?? '') === 'Grande' ? 'checked' : '' ?>
                                                                                    <?= ($fixedPromotionSize !== '' && $fixedPromotionSize !== 'Grande') ? 'disabled' : '' ?>
                                                                                >
                                                                                <label class="form-check-label" for="<?= htmlspecialchars($editModalId) ?>_grande">
                                                                                    Grande (₱<?= number_format($grandePrice, 2) ?>)
                                                                                </label>
                                                                            </div>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                </div>

                                                                <?php if ($fixedPromotionSize !== ''): ?>
                                                                    <div class="small text-muted mt-2">
                                                                        <i class="bi bi-info-circle me-1"></i>
                                                                        This promotion uses <?= htmlspecialchars($fixedPromotionSize) ?> size.
                                                                    </div>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php else: ?>
                                                            <input
                                                                type="hidden"
                                                                name="size"
                                                                value="<?= htmlspecialchars((string)($item['size'] ?? ''), ENT_QUOTES) ?>"
                                                            >
                                                        <?php endif; ?>

                                                        <div class="mb-3">
                                                            <label class="form-label">Add-ons</label>

                                                            <?php if (($item['promotion_source_role'] ?? '') === 'get'): ?>
                                                                <div class="small text-muted mb-2">
                                                                    <i class="bi bi-info-circle me-1"></i>
                                                                    The drink is free under the promotion, but selected add-ons are charged separately.
                                                                </div>
                                                            <?php endif; ?>

                                                            <?php if (!empty($itemAddons)): ?>
                                                                <div class="row g-2">
                                                                    <?php foreach ($itemAddons as $addon): ?>
                                                                        <div class="col-12 col-sm-6">
                                                                            <div class="form-check">
                                                                                <input
                                                                                    class="form-check-input"
                                                                                    type="checkbox"
                                                                                    name="addons[]"
                                                                                    id="<?= htmlspecialchars($editModalId) ?>_addon_<?= (int)$addon['id'] ?>"
                                                                                    value="<?= htmlspecialchars($addon['name'], ENT_QUOTES) ?>"
                                                                                    <?= in_array($addon['name'], $existingAddons, true) ? 'checked' : '' ?>
                                                                                >
                                                                                <label class="form-check-label" for="<?= htmlspecialchars($editModalId) ?>_addon_<?= (int)$addon['id'] ?>">
                                                                                    <?= htmlspecialchars($addon['name']) ?>
                                                                                    <span class="text-muted">(₱<?= number_format((float)$addon['price'], 2) ?>)</span>
                                                                                </label>
                                                                            </div>
                                                                        </div>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            <?php else: ?>
                                                                <div class="small text-muted">No add-ons available for this product.</div>
                                                            <?php endif; ?>
                                                        </div>

                                                        <div class="mb-3">
                                                            <label class="form-label">Sugar Level</label>
                                                            <div class="d-flex flex-wrap gap-3">
                                                                <?php foreach (['0%', '25%', '50%', '75%', '100%'] as $sugarIndex => $sugarOption): ?>
                                                                    <div class="form-check">
                                                                        <input
                                                                            class="form-check-input"
                                                                            type="radio"
                                                                            name="sugar_level"
                                                                            id="<?= htmlspecialchars($editModalId) ?>_sugar_<?= $sugarIndex ?>"
                                                                            value="<?= htmlspecialchars($sugarOption, ENT_QUOTES) ?>"
                                                                            <?= $editSugar === $sugarOption ? 'checked' : '' ?>
                                                                            required
                                                                        >
                                                                        <label class="form-check-label" for="<?= htmlspecialchars($editModalId) ?>_sugar_<?= $sugarIndex ?>">
                                                                            <?= htmlspecialchars($sugarOption) ?>
                                                                        </label>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        </div>

                                                        <?php
                                                            $editDiscountType = strtolower(
                                                                trim((string)($item['discount_type'] ?? 'none'))
                                                            );

                                                            if (!in_array($editDiscountType, ['none', 'pwd', 'senior'], true)) {
                                                                $editDiscountType = 'none';
                                                            }
                                                        ?>

                                                        <div>
                                                            <label class="form-label">Discount</label>
                                                            <div class="d-flex flex-wrap gap-3">
                                                                <div class="form-check">
                                                                    <input
                                                                        class="form-check-input"
                                                                        type="radio"
                                                                        name="discount_type"
                                                                        id="<?= htmlspecialchars($editModalId) ?>_discount_none"
                                                                        value="none"
                                                                        <?= $editDiscountType === 'none' ? 'checked' : '' ?>
                                                                    >
                                                                    <label
                                                                        class="form-check-label"
                                                                        for="<?= htmlspecialchars($editModalId) ?>_discount_none"
                                                                    >
                                                                        None
                                                                    </label>
                                                                </div>

                                                                <div class="form-check">
                                                                    <input
                                                                        class="form-check-input"
                                                                        type="radio"
                                                                        name="discount_type"
                                                                        id="<?= htmlspecialchars($editModalId) ?>_discount_pwd"
                                                                        value="pwd"
                                                                        <?= $editDiscountType === 'pwd' ? 'checked' : '' ?>
                                                                    >
                                                                    <label
                                                                        class="form-check-label"
                                                                        for="<?= htmlspecialchars($editModalId) ?>_discount_pwd"
                                                                    >
                                                                        PWD (20%)
                                                                    </label>
                                                                </div>

                                                                <div class="form-check">
                                                                    <input
                                                                        class="form-check-input"
                                                                        type="radio"
                                                                        name="discount_type"
                                                                        id="<?= htmlspecialchars($editModalId) ?>_discount_senior"
                                                                        value="senior"
                                                                        <?= $editDiscountType === 'senior' ? 'checked' : '' ?>
                                                                    >
                                                                    <label
                                                                        class="form-check-label"
                                                                        for="<?= htmlspecialchars($editModalId) ?>_discount_senior"
                                                                    >
                                                                        Senior Citizen (20%)
                                                                    </label>
                                                                </div>
                                                            </div>

                                                            <div class="cart-discount-details row g-2 mt-1" <?= $editDiscountType === 'none' ? 'hidden' : '' ?>>
                                                                <div class="col-12 col-sm-6">
                                                                    <label class="form-label small mb-1">Name on ID</label>
                                                                    <input
                                                                        type="text"
                                                                        class="form-control form-control-sm"
                                                                        name="discount_id_name"
                                                                        maxlength="100"
                                                                        placeholder="Full name as shown on the ID"
                                                                        autocomplete="off"
                                                                        value="<?= htmlspecialchars((string)($item['discount_id_name'] ?? '')) ?>"
                                                                        <?= $editDiscountType === 'none' ? '' : 'required' ?>
                                                                    >
                                                                </div>
                                                                <div class="col-12 col-sm-6">
                                                                    <label class="form-label small mb-1">ID Number</label>
                                                                    <input
                                                                        type="text"
                                                                        class="form-control form-control-sm"
                                                                        name="discount_id_number"
                                                                        maxlength="30"
                                                                        placeholder="PWD / Senior Citizen ID number"
                                                                        autocomplete="off"
                                                                        value="<?= htmlspecialchars((string)($item['discount_id_number'] ?? '')) ?>"
                                                                        <?= $editDiscountType === 'none' ? '' : 'required' ?>
                                                                    >
                                                                </div>
                                                            </div>

                                                            <div class="small text-muted mt-2">
                                                                Optional. Valid ID must be presented upon pick-up.
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">
                                                            Cancel
                                                        </button>
                                                        <button type="submit" class="btn btn-brown-custom px-4">
                                                            <i class="bi bi-check-lg me-1"></i>
                                                            Save Changes
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                <?php endforeach; ?>


        <?php endif; ?>

    <?php endif; ?>

</div>


<!--
===============================================================================
GCASH PAYMENT MODAL
===============================================================================
-->

<div
    class="modal fade"
    id="gcashModal"
    tabindex="-1"
    aria-labelledby="gcashModalLabel"
    aria-hidden="true"
>

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <div class="modal-header gcash-modal-header">

                <h5
                    class="modal-title fw-bold"
                    id="gcashModalLabel"
                >
                    <i class="bi bi-phone"></i>
                    GCash Payment
                </h5>

                <button
                    type="button"
                    class="btn-close btn-close-white"
                    data-bs-dismiss="modal"
                    aria-label="Close"
                ></button>

            </div>


            <div class="modal-body">

                <!-- QR CODE -->
                <div class="gcash-qr-container mb-3">

                    <div class="fw-bold mb-2">
                        Scan the GCash QR Code
                    </div>

                    <img
                        src="../assets/images/qrexample.png"
                        alt="GCash QR Code"
                        class="gcash-qr"
                    >

                    <div class="mt-3">
                        Amount to Pay:
                    </div>

                    <div
                        class="gcash-amount"
                        id="gcashModalAmount"
                    >
                        ₱<?= number_format($cart_total, 2) ?>
                    </div>

                </div>


                <!-- INSTRUCTIONS -->
                <div class="gcash-instructions mb-3">

                    <div class="fw-bold mb-2">
                        Payment Instructions
                    </div>

                    <ol class="mb-0 ps-3">

                        <li>
                            Open your GCash application.
                        </li>

                        <li>
                            Scan the QR code above.
                        </li>

                        <li>
                            Pay the exact amount shown.
                        </li>

                        <li>
                            Take a screenshot of your successful payment.
                        </li>

                        <li>
                            Upload the screenshot below.
                        </li>

                    </ol>

                </div>


                <!-- UPLOAD -->
                <div class="gcash-upload-box">

                    <label
                        for="payment_screenshot"
                        class="form-label fw-bold small"
                    >
                        Upload Payment Screenshot
                    </label>

                    <input
                    type="file"
                    name="payment_screenshot"
                    id="payment_screenshot"
                    class="form-control custom-input"
                    accept=".jpg,.jpeg,.png,image/jpeg,image/png"
                    form="checkoutForm">

                    <div class="form-text">
                        JPG, JPEG, or PNG only. Maximum file size: 5MB.
                    </div>

                    <div
                        id="gcashFileError"
                        class="text-danger small mt-2 d-none"
                    ></div>

                </div>

            </div>


            <div class="modal-footer">

                <button
                    type="button"
                    class="btn btn-secondary"
                    data-bs-dismiss="modal"
                >
                    Cancel
                </button>

                <button
                    type="button"
                    class="btn btn-brown-custom"
                    onclick="submitGcashPayment()"
                >
                    <i class="bi bi-check-circle"></i>
                    Submit Payment
                </button>

            </div>

        </div>

    </div>

</div>


<script>

/*
|--------------------------------------------------------------------------
| PICKUP FIELDS
|--------------------------------------------------------------------------
*/

function togglePickupFields(isLater) {

    const dateInput = document.getElementById('pickup_date');
    const timeInput = document.getElementById('pickup_time');

    if (!dateInput || !timeInput) {
        return;
    }

    if (isLater) {

        dateInput.disabled = false;
        dateInput.required = true;

        timeInput.disabled = false;
        timeInput.required = true;

    } else {

        dateInput.disabled = true;
        dateInput.required = false;

        dateInput.value = "<?= date('Y-m-d') ?>";

        timeInput.disabled = true;
        timeInput.required = false;

        timeInput.value = "";

    }
}


/*
|--------------------------------------------------------------------------
| PAYMENT METHOD
|--------------------------------------------------------------------------
*/

function handlePaymentMethodChange() {

    const gcashRadio = document.getElementById('gcash');

    if (!gcashRadio) {
        return;
    }

    /*
     * We intentionally do NOT make the screenshot input required
     * at the HTML level.
     *
     * Otherwise, the browser may block the form before our GCash
     * modal can open.
     */

}


/*
|--------------------------------------------------------------------------
| VALIDATE PICKUP TIME
|--------------------------------------------------------------------------
*/

function validateTime() {

    const nowRadio = document.getElementById('now');
    const laterRadio = document.getElementById('later');

    const timeInput = document.getElementById('pickup_time');
    const dateInput = document.getElementById('pickup_date');

    if (!nowRadio || !laterRadio || !timeInput || !dateInput) {
        return true;
    }


    /*
     * PICK-UP LATER
     */

    if (laterRadio.checked) {

        if (!dateInput.value) {

            alert("Please select a pick-up date.");

            dateInput.focus();

            return false;
        }


        if (!timeInput.value) {

            alert("Please select a pick-up time.");

            timeInput.focus();

            return false;
        }


        if (
            timeInput.value < "09:00" ||
            timeInput.value > "22:00"
        ) {

            alert(
                "Please select a pick-up time between 9:00 AM and 10:00 PM."
            );

            timeInput.focus();

            return false;
        }

    }


    /*
     * PICK-UP NOW
     */

    if (nowRadio.checked) {

        const currentTime = new Date();

        const hours = currentTime.getHours();
        const minutes = currentTime.getMinutes();

        const currentMinutes = (hours * 60) + minutes;

        const openingMinutes = 9 * 60;
        const closingMinutes = 22 * 60;

        if (
            currentMinutes < openingMinutes ||
            currentMinutes > closingMinutes
        ) {

            alert(
                "The store is open from 9:00 AM to 10:00 PM. Please choose Pick-up Later."
            );

            return false;
        }

    }

    return true;
}


/*
|--------------------------------------------------------------------------
| CHECKOUT SUBMISSION
|--------------------------------------------------------------------------
*/

let gcashConfirmed = false;


function handleCheckoutSubmit(event) {

    /*
     * Always stop the browser's normal form submission.
     * Checkout is sent through AJAX so the current page remains visible
     * while the order is being created.
     */
    event.preventDefault();


    /*
     * Validate pickup first.
     */
    if (!validateTime()) {
        return false;
    }


    /*
     * Check selected payment method.
     */
    const selectedPayment = document.querySelector(
        'input[name="payment_method"]:checked'
    );

    if (!selectedPayment) {
        alert("Please select a payment method.");
        return false;
    }


    const paymentValue = selectedPayment.value.toLowerCase().replace(/[- _]/g, '');

    /*
     * GCash still requires the payment screenshot modal first.
     */
    if (paymentValue === 'gcash') {
        showGcashModal();
        return false;
    }


    /*
     * Cash can be submitted immediately through AJAX.
     */
    processCheckoutAjax();
    return false;
}


/*
|--------------------------------------------------------------------------
| AJAX CHECKOUT
|--------------------------------------------------------------------------
*/

let checkoutSubmitting = false;


function setCheckoutLoading(isLoading) {

    const overlay = document.getElementById('checkoutLoadingOverlay');
    const button = document.getElementById('checkoutButton');

    if (overlay) {
        overlay.classList.toggle('is-visible', isLoading);
        overlay.setAttribute('aria-hidden', isLoading ? 'false' : 'true');
    }

    document.body.classList.toggle('checkout-loading-active', isLoading);

    if (button) {
        button.disabled = isLoading;
        button.innerHTML = isLoading
            ? '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Processing...'
            : 'Checkout';
    }
}


async function processCheckoutAjax() {

    const form = document.getElementById('checkoutForm');

    if (!form || checkoutSubmitting) {
        return;
    }

    checkoutSubmitting = true;
    setCheckoutLoading(true);

    try {
        const formData = new FormData(form);

        /* Tell checkout.php to return JSON instead of redirecting itself. */
        formData.set('ajax', '1');

        const response = await fetch(form.action, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            cache: 'no-store',
            credentials: 'same-origin'
        });

        const contentType = response.headers.get('content-type') || '';
        let data;

        if (contentType.includes('application/json')) {
            data = await response.json();
        } else {
            const text = await response.text();
            throw new Error(
                text || 'The server returned an unexpected response.'
            );
        }

        if (!response.ok || !data.success) {
            throw new Error(
                data.message || 'Unable to place your order right now.'
            );
        }

        /*
         * Keep the loading overlay visible while the browser moves to
         * dashboard.php. This prevents duplicate submissions/clicks.
         */
        window.location.href = data.redirect || 'dashboard.php';

    } catch (error) {

        checkoutSubmitting = false;
        setCheckoutLoading(false);

        alert(
            error && error.message
                ? error.message
                : 'Unable to place your order right now.'
        );
    }
}


/*
|--------------------------------------------------------------------------
| OPEN GCASH MODAL
|--------------------------------------------------------------------------
*/

function showGcashModal() {

    const modalElement = document.getElementById('gcashModal');

    if (!modalElement) {

        alert("GCash payment modal could not be loaded.");

        return;
    }


    /*
     * Clear previous error.
     */

    const errorElement = document.getElementById('gcashFileError');

    if (errorElement) {

        errorElement.textContent = '';
        errorElement.classList.add('d-none');

    }


    /*
     * Show Bootstrap modal.
     */

    const modal = bootstrap.Modal.getOrCreateInstance(modalElement);

    modal.show();
}


/*
|--------------------------------------------------------------------------
| SUBMIT GCASH PAYMENT
|--------------------------------------------------------------------------
*/

function submitGcashPayment() {

    const fileInput = document.getElementById('payment_screenshot');
    const errorElement = document.getElementById('gcashFileError');
    const form = document.getElementById('checkoutForm');


    if (!fileInput || !form) {

        return;
    }


    /*
     * Reset error.
     */

    errorElement.textContent = '';
    errorElement.classList.add('d-none');


    /*
     * Require screenshot.
     */

    if (!fileInput.files || fileInput.files.length === 0) {

        errorElement.textContent =
            "Please upload your GCash payment screenshot.";

        errorElement.classList.remove('d-none');

        return;
    }


    const file = fileInput.files[0];


    /*
     * Maximum 5MB.
     */

    const maxSize = 5 * 1024 * 1024;

    if (file.size > maxSize) {

        errorElement.textContent =
            "The screenshot must not exceed 5MB.";

        errorElement.classList.remove('d-none');

        return;
    }


    const allowedTypes = [
        'image/jpeg',
        'image/png'
    ];

    if (!allowedTypes.includes(file.type)) {

        errorElement.textContent =
            "Only JPG, JPEG, or PNG files are allowed.";

        errorElement.classList.remove('d-none');

        return;
    }

    gcashConfirmed = true;

    const modalElement = document.getElementById('gcashModal');

    if (modalElement) {
        const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
        modal.hide();
    }

    processCheckoutAjax();
}

</script>

<script>
/* PWD / Senior ID fields inside the cart "Edit item" modals. */
document.addEventListener('change', function (event) {
    const radio = event.target;
    if (!radio || radio.name !== 'discount_type') return;

    const form = radio.closest('form');
    const details = form ? form.querySelector('.cart-discount-details') : null;
    if (!details) return;

    const needsId = radio.value === 'pwd' || radio.value === 'senior';
    details.hidden = !needsId;

    details.querySelectorAll('input').forEach(function (input) {
        input.required = needsId;
        if (!needsId) input.value = '';
    });

    if (needsId) {
        const first = details.querySelector('input');
        if (first) first.focus();
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>