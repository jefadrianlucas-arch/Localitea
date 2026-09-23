<?php

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

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
        customerRedirect('cart.php?edit_error=' . urlencode('The selected cart item could not be found.'));
    }

    $cartItem = $_SESSION['cart'][$cartKey];
    $productId = (int)($cartItem['product_id'] ?? 0);

    $size = trim((string)($_POST['size'] ?? ''));
    $sugarLevel = trim((string)($_POST['sugar_level'] ?? ''));
    $addons = $_POST['addons'] ?? [];

    if (!is_array($addons)) {
        $addons = [$addons];
    }

    $addons = array_values(array_filter(array_map('trim', $addons), static function ($addon) {
        return $addon !== '';
    }));

    $allowedSugars = ['0%', '25%', '50%', '75%', '100%'];

    if ($productId <= 0 || !in_array($sugarLevel, $allowedSugars, true)) {
        customerRedirect('cart.php?edit_error=' . urlencode('Please select valid customization options.'));
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
        customerRedirect('cart.php?edit_error=' . urlencode('This product is no longer available.'));
    }

    $basePrice = (float)($editProduct['price'] ?? 0);

    if ($size === 'Regular') {
        $basePrice = (float)($editProduct['regular_price'] ?? 0);
        if ($basePrice <= 0) {
            customerRedirect('cart.php?edit_error=' . urlencode('The selected size is not available for this product.'));
        }
    } elseif ($size === 'Grande') {
        $basePrice = (float)($editProduct['grande_price'] ?? 0);
        if ($basePrice <= 0) {
            customerRedirect('cart.php?edit_error=' . urlencode('The selected size is not available for this product.'));
        }
    } elseif ($size !== '') {
        customerRedirect('cart.php?edit_error=' . urlencode('The selected size is invalid.'));
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
                customerRedirect('cart.php?edit_error=' . urlencode('This promotion requires the configured ' . $requiredSize . ' size.'));
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

    customerRedirect('cart.php?edit_success=1');
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
| The cart only previews the server-side promotion calculation.
| checkout.php recalculates the same promotion again before saving
| the order, so the browser cannot change the final price.
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

$cart_total = round(
    max(0, $display_subtotal - $promotion_discount),
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

    body {
        background-color: #FDFBF7;
    }

    .custom-box {
        background-color: #ffffff;
        border: 1px solid #E6DEC9;
        border-radius: 16px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.02);
    }

    .section-title {
        font-weight: bold;
        font-size: 0.85rem;
        border-bottom: 2px solid #4A3525;
        padding-bottom: 4px;
        margin-bottom: 10px;
        color: #2c221e;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .btn-brown-custom {
        background-color: #4A3525;
        border-color: #4A3525;
        color: #ffffff;
        border-radius: 50px;
        padding: 0.5rem 1.2rem;
        font-size: 0.9rem;
        font-weight: 600;
        transition: all 0.2s ease-in-out;
    }

    .btn-brown-custom:hover {
        background-color: #332317;
        border-color: #332317;
        color: #ffffff;
        transform: translateY(-1px);
    }

    .custom-input {
        background-color: #ffffff;
        border: 1px solid #E6DEC9;
        border-radius: 8px;
        padding: 7px 10px;
        font-size: 0.85rem;
    }

    .custom-input:focus,
    .form-control:focus {
        border-color: #4A3525 !important;
        box-shadow: 0 0 0 0.15rem rgba(74, 53, 37, 0.15) !important;
    }

    .form-check-input {
        border-color: #ced4da;
    }

    .form-check-input:focus {
        border-color: #4A3525 !important;
        box-shadow: 0 0 0 0.15rem rgba(74, 53, 37, 0.15) !important;
    }

    .form-check-input:checked {
        background-color: #4A3525 !important;
        border-color: #4A3525 !important;
    }

    a.cart-remove-link {
        color: #dc3545 !important;
        transition: color 0.2s;
    }

    a.cart-remove-link:hover {
        color: #a71d2a !important;
    }

    .cart-action-links {
        display: inline-flex;
        align-items: center;
        gap: 7px;
    }

    .cart-edit-link {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 34px;
        height: 30px;
        padding: 0 9px;
        border-radius: 8px;
        border: 1px solid #E6DEC9;
        background: #ffffff;
        color: #4A3525 !important;
        font-size: .72rem;
        font-weight: 700;
        text-decoration: none;
        transition: all .2s ease;
    }

    .cart-edit-link:hover {
        background: #F8F3EA;
        border-color: #8B6F5A;
        color: #332317 !important;
    }

    .cart-free-line {
        background: #F8F3EA;
        border: 1px solid #E6DEC9;
        padding: 7px 6px;
        border-radius: 8px;
    }

    .cart-free-label {
        color: #198754;
        font-size: .68rem;
        font-weight: 800;
        letter-spacing: .2px;
    }

    .cart-free-note {
        color: #6b625b;
        font-size: .68rem;
    }

    .cart-edit-modal .modal-header {
        background: #4A3525;
        color: #ffffff;
    }

    .cart-edit-modal .modal-title {
        font-size: 1rem;
        font-weight: 700;
    }

    .cart-edit-modal .form-label {
        font-size: .78rem;
        font-weight: 700;
        color: #2c221e;
    }

    .cart-edit-modal .form-check-label {
        font-size: .82rem;
    }

    .cart-edit-modal .modal-footer .btn {
        min-height: 42px;
        border-radius: 50px;
        font-weight: 600;
    }

    /*
    |--------------------------------------------------------------------------
    | GCASH MODAL
    |--------------------------------------------------------------------------
    */

    .gcash-modal-header {
        background-color: #4A3525;
        color: #ffffff;
        border-radius: 12px 12px 0 0;
    }

    .gcash-qr-container {
        background-color: #FDFBF7;
        border: 1px solid #E6DEC9;
        border-radius: 12px;
        padding: 15px;
        text-align: center;
    }

    .gcash-qr {
        width: 220px;
        max-width: 100%;
        height: auto;
        border-radius: 8px;
        background-color: #ffffff;
        padding: 8px;
    }

    .gcash-amount {
        font-size: 1.4rem;
        font-weight: 700;
        color: #4A3525;
    }

    .gcash-instructions {
        background-color: #F8F3EA;
        border: 1px solid #E6DEC9;
        border-radius: 10px;
        padding: 12px;
        font-size: 0.85rem;
    }

    .gcash-upload-box {
        border: 1px dashed #4A3525;
        background-color: #FDFBF7;
        border-radius: 10px;
        padding: 12px;
    }

    .modal-content {
        border: none;
        border-radius: 12px;
        overflow: hidden;
    }


    /* =========================================================
       MOBILE LAYOUT (phones)
    ========================================================= */
    @media (max-width: 767.98px) {

        .container.py-5 {
            padding-top: 1.25rem !important;
            padding-bottom: 1.5rem !important;
        }

        .custom-box.p-4,
        .custom-box.p-3 {
            padding: 1rem !important;
        }

        /* 16px stops iOS Safari zooming into the field on focus */
        .custom-input,
        .form-control,
        .form-select {
            font-size: 16px;
            min-height: 44px;
        }

        /* Bigger, easier radio buttons and labels */
        .form-check {
            display: flex;
            align-items: center;
            gap: 10px;
            min-height: 44px;
            padding-left: 0;
        }

        .form-check .form-check-input {
            float: none;
            flex: 0 0 auto;
            width: 1.3em;
            height: 1.3em;
            margin: 0;
        }

        .form-check .form-check-label {
            font-size: .95rem !important;
        }

        .section-title {
            font-size: .9rem;
        }

        /* Cart lines: readable detail text and a real tap target for delete */
        a.cart-remove-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            margin: -6px 0 -6px -8px;
            font-size: 1.1rem;
        }

        .cart-action-links {
            gap: 5px;
        }

        .cart-edit-link {
            min-width: 32px;
            height: 34px;
            padding: 0 8px;
            font-size: .7rem;
        }

        .custom-box .text-muted[style*="font-size:0.7rem"],
        .custom-box .text-muted[style*="font-size: 0.7rem"] {
            font-size: .78rem !important;
            line-height: 1.3 !important;
            margin-left: 2rem !important;
        }

        .btn-brown-custom {
            min-height: 48px;
        }

        .modal-dialog {
            margin: 10px;
        }

        .gcash-qr {
            width: min(220px, 70vw);
        }
    }
</style>


<div class="container py-5">

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

        <div class="custom-box p-5 text-center mx-auto" style="max-width: 550px;">

            <div class="alert alert-success border-0 bg-light text-success fw-bold py-3 mb-3">
                <?= htmlspecialchars($successMessage) ?>
            </div>

            <a href="menu.php" class="btn btn-brown-custom px-4 py-2">
                Order More Milktea
            </a>

        </div>

    <?php else: ?>

        <?php if (empty($_SESSION['cart'])): ?>

            <div class="custom-box p-5 text-center mx-auto" style="max-width: 550px;">

                <i class="bi bi-cart-x fs-1 text-muted"></i>

                <h5 class="fw-bold mt-3">
                    Your cart is empty.
                </h5>

                <p class="text-muted small">
                    Please add products to your cart before checking out.
                </p>

                <a href="menu.php" class="btn btn-brown-custom px-4">
                    Go to Menu
                </a>

            </div>

        <?php else: ?>

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

                <div
                    class="row g-3 justify-content-center mx-auto"
                    style="max-width: 900px;"
                >

                    <!--
                    ================================================================
                    LEFT SIDE
                    Guest Details / Pickup / Payment
                    ================================================================
                    -->

                    <div class="col-lg-6 col-md-6">

                        <div class="custom-box p-3">

                            <!-- Guest Details -->
                            <div class="mb-2">

                                <div class="section-title">
                                    Guest Details
                                </div>

                                <div class="row g-2">

                                    <div class="col-12">

                                        <label
                                            class="form-label text-muted small mb-1"
                                            style="font-size: 0.75rem;"
                                        >
                                            Full Name
                                        </label>

                                        <input
                                            type="text"
                                            name="full_name"
                                            class="form-control custom-input"
                                            value="<?= htmlspecialchars($prefillFullName) ?>"
                                            required
                                        >

                                    </div>


                                    <div class="col-12">

                                        <label
                                            class="form-label text-muted small mb-1"
                                            style="font-size: 0.75rem;"
                                        >
                                            Mobile Number
                                        </label>

                                        <input
                                            type="text"
                                            name="mobile_number"
                                            class="form-control custom-input"
                                            placeholder="09XXXXXXXXX"
                                            value="<?= htmlspecialchars($prefillMobile) ?>"
                                            required
                                        >

                                    </div>

                                </div>

                            </div>


                            <!-- Pickup -->
                            <div class="mb-2">

                                <div class="section-title">
                                    Choose Pick-up Time
                                </div>

                                <div class="d-flex gap-4 mb-2">

                                    <div class="form-check">

                                        <input
                                            class="form-check-input"
                                            type="radio"
                                            name="pickup_type"
                                            id="now"
                                            value="Now"
                                            required
                                            onclick="togglePickupFields(false)"
                                        >

                                        <label
                                            class="form-check-label fw-bold text-dark small"
                                            for="now"
                                        >
                                            Now
                                        </label>

                                    </div>


                                    <div class="form-check">

                                        <input
                                            class="form-check-input"
                                            type="radio"
                                            name="pickup_type"
                                            id="later"
                                            value="Pick-up later"
                                            required
                                            onclick="togglePickupFields(true)"
                                        >

                                        <label
                                            class="form-check-label fw-bold text-dark small"
                                            for="later"
                                        >
                                            Pick-up Later
                                        </label>

                                    </div>

                                </div>


                                <div
                                    class="row g-2"
                                    id="pickup-later-fields"
                                >

                                    <div class="col-sm-6">

                                        <label
                                            class="form-label text-muted small mb-1"
                                            style="font-size: 0.75rem;"
                                        >
                                            Date
                                        </label>

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


                                    <div class="col-sm-6">

                                        <label
                                            class="form-label text-muted small mb-1"
                                            style="font-size: 0.75rem;"
                                        >
                                            Time (9AM-10PM)
                                        </label>

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
                            <div>

                                <div class="section-title">
                                    Payment Method
                                </div>

                                <div class="d-flex flex-column gap-1">

                                    <!-- CASH -->
                                    <div class="form-check">

                                        <input
                                            class="form-check-input"
                                            type="radio"
                                            name="payment_method"
                                            id="cash"
                                            value="Cash"
                                            required
                                            onchange="handlePaymentMethodChange()"
                                        >

                                        <label
                                            class="form-check-label fw-bold text-dark small"
                                            for="cash"
                                        >
                                            Cash
                                        </label>

                                    </div>


                                    <!-- GCASH -->
                                    <div
                                        class="form-check d-flex justify-content-between align-items-center pe-2"
                                    >

                                        <div>

                                            <input
                                                class="form-check-input"
                                                type="radio"
                                                name="payment_method"
                                                id="gcash"
                                                value="G-Cash"
                                                required
                                                onchange="handlePaymentMethodChange()"
                                            >

                                            <label
                                                class="form-check-label fw-bold text-dark small"
                                                for="gcash"
                                            >
                                                G-Cash
                                            </label>

                                        </div>

                                        <span
                                            class="badge px-2 py-1"
                                            style="
                                                background-color:#4A3525;
                                                font-size:0.7rem;
                                            "
                                        >
                                            GCash
                                        </span>

                                    </div>

                                </div>

                            </div>

                        </div>

                    </div>


                    <!--
                    ================================================================
                    RIGHT SIDE
                    Order Details / Cart
                    ================================================================
                    -->

                    <div class="col-lg-6 col-md-6">

                        <div
                            class="custom-box p-3 d-flex flex-column justify-content-between"
                            style="min-height: 380px;"
                        >

                            <div>

                                <div class="section-title text-center">
                                    Order Details
                                </div>

                                <h6
                                    class="fw-bold text-center mb-2 small"
                                    style="color:#4A3525;"
                                >
                                    My Cart
                                </h6>


                                <div
                                    class="d-flex flex-column gap-2 mb-2 pe-1"
                                    style="
                                        max-height:140px;
                                        overflow-y:auto;
                                    "
                                >

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
                                        <div class="d-flex justify-content-between align-items-start border-bottom pb-1">
                                            <div class="flex-grow-1 min-width-0">
                                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                                    <div class="cart-action-links">
                                                        <a
                                                            href="remove-from-cart.php?key=<?= urlencode($key) ?>" data-ajax-remove="true"
                                                            class="cart-remove-link text-decoration-none small"
                                                            title="Remove item"
                                                            aria-label="Remove item"
                                                        >
                                                            <i class="bi bi-trash"></i>
                                                        </a>
                                                        <?php if (isset($cartProducts[$itemProductId])): ?>
                                                            <a
                                                                href="#<?= htmlspecialchars($editModalId) ?>"
                                                                class="cart-edit-link"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#<?= htmlspecialchars($editModalId) ?>"
                                                                title="Edit item"
                                                            >
                                                                <i class="bi bi-pencil me-1"></i> Edit
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>

                                                    <span class="fw-bold small"><?= $paidQuantity ?>x</span>
                                                    <span class="fw-bold text-dark small"><?= htmlspecialchars($item['name'] ?? '') ?></span>
                                                </div>

                                                <div class="text-muted ms-4" style="font-size:0.7rem; line-height:1.1;">
                                                    <?php if (!empty($item['size'])): ?>
                                                        <div><?= htmlspecialchars($item['size']) ?></div>
                                                    <?php endif; ?>

                                                    <?php if (!empty($item['sugar_level'])): ?>
                                                        <div>Sugar: <?= htmlspecialchars($item['sugar_level']) ?></div>
                                                    <?php endif; ?>

                                                    <?php if (!empty($item['addons'])): ?>
                                                        <div>
                                                            Add-ons:
                                                            <?= htmlspecialchars(
                                                                is_array($item['addons'])
                                                                    ? implode(', ', $item['addons'])
                                                                    : $item['addons']
                                                            ) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                            <span class="fw-bold text-dark small text-nowrap ms-2">
                                                ₱<?= number_format($itemTotal, 2) ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($freeQuantity > 0): ?>
                                        <div class="d-flex justify-content-between align-items-start border-bottom pb-1 cart-free-line">
                                            <div class="flex-grow-1 min-width-0">
                                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                                    <div class="cart-action-links">
                                                        <a
                                                            href="remove-from-cart.php?key=<?= urlencode($key) ?>" data-ajax-remove="true"
                                                            class="cart-remove-link text-decoration-none small"
                                                            title="Remove free item"
                                                            aria-label="Remove free item"
                                                        >
                                                            <i class="bi bi-trash"></i>
                                                        </a>
                                                        <?php if (isset($cartProducts[$itemProductId])): ?>
                                                            <a
                                                                href="#<?= htmlspecialchars($editModalId) ?>"
                                                                class="cart-edit-link"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#<?= htmlspecialchars($editModalId) ?>"
                                                                title="Edit free item"
                                                            >
                                                                <i class="bi bi-pencil me-1"></i> Edit
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>

                                                    <span class="cart-free-label">FREE</span>
                                                    <span class="fw-bold small"><?= $freeQuantity ?>x</span>
                                                    <span class="fw-bold text-dark small"><?= htmlspecialchars($item['name'] ?? '') ?></span>
                                                </div>

                                                <div class="text-muted ms-4" style="font-size:0.7rem; line-height:1.1;">
                                                    <?php if (!empty($item['size'])): ?>
                                                        <div><?= htmlspecialchars($item['size']) ?></div>
                                                    <?php endif; ?>

                                                    <?php if (!empty($item['sugar_level'])): ?>
                                                        <div>Sugar: <?= htmlspecialchars($item['sugar_level']) ?></div>
                                                    <?php endif; ?>

                                                    <?php if (!empty($item['addons'])): ?>
                                                        <div>
                                                            Add-ons:
                                                            <?= htmlspecialchars(
                                                                is_array($item['addons'])
                                                                    ? implode(', ', $item['addons'])
                                                                    : $item['addons']
                                                            ) ?>
                                                        </div>
                                                    <?php endif; ?>

                                                    <div class="cart-free-note">
                                                        Drink base included in promotion
                                                    </div>

                                                    <?php if ($freeAddonTotal > 0): ?>
                                                        <div class="text-dark fw-semibold">
                                                            Add-on charge: +₱<?= number_format($freeAddonTotal, 2) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                            <span class="fw-bold small text-nowrap ms-2 <?= $freeAddonTotal > 0 ? 'text-dark' : 'text-success' ?>">
                                                <?php if ($freeAddonTotal > 0): ?>
                                                    +₱<?= number_format($freeAddonTotal, 2) ?>
                                                <?php else: ?>
                                                    FREE
                                                <?php endif; ?>
                                            </span>
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

                                        <div
                                            class="d-flex justify-content-between align-items-start border-bottom pb-1"
                                            style="background:#F8F3EA; padding:6px 4px; border-radius:6px;"
                                        >

                                            <div>

                                                <div class="d-flex align-items-center gap-2">

                                                    <span class="fw-bold small text-success">
                                                        FREE
                                                    </span>

                                                    <span class="fw-bold small">
                                                        <?= $rewardQuantity ?>x
                                                    </span>

                                                    <span class="fw-bold text-dark small">
                                                        <?= htmlspecialchars($rewardItem['name'] ?? '') ?>
                                                    </span>

                                                </div>

                                                <div
                                                    class="text-muted ms-4"
                                                    style="font-size:0.7rem; line-height:1.1;"
                                                >
                                                    <?php if (!empty($rewardItem['size'])): ?>
                                                        <div><?= htmlspecialchars($rewardItem['size']) ?></div>
                                                    <?php endif; ?>

                                                    <?php if (!empty($rewardItem['sugar_level'])): ?>
                                                        <div>
                                                            Sugar:
                                                            <?= htmlspecialchars($rewardItem['sugar_level']) ?>
                                                        </div>
                                                    <?php endif; ?>

                                                    <?php if (!empty($rewardItem['addons'])): ?>
                                                        <div>
                                                            Add-ons:
                                                            <?= htmlspecialchars(
                                                                is_array($rewardItem['addons'])
                                                                    ? implode(', ', $rewardItem['addons'])
                                                                    : $rewardItem['addons']
                                                            ) ?>
                                                        </div>
                                                    <?php endif; ?>

                                                    <div>
                                                        Drink base included in promotion
                                                    </div>
                                                    <?php if ($rewardAddonTotal > 0): ?>
                                                        <div class="text-dark fw-semibold">
                                                            Add-on charge: +₱<?= number_format($rewardAddonTotal, 2) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>

                                            </div>

                                            <span class="fw-bold small <?= $rewardAddonTotal > 0 ? 'text-dark' : 'text-success' ?>">
                                                <?php if ($rewardAddonTotal > 0): ?>
                                                    +₱<?= number_format($rewardAddonTotal, 2) ?>
                                                <?php else: ?>
                                                    FREE
                                                <?php endif; ?>
                                            </span>

                                        </div>

                                    <?php endforeach; ?>

                                </div>

                            </div>


                            <!-- TOTAL -->
                            <div class="pt-2 border-top">

                                <div
                                    class="d-flex justify-content-between text-muted mb-1"
                                    style="font-size:0.75rem;"
                                >

                                    <span>
                                        Subtotal:
                                    </span>

                                    <span>
                                        ₱<?= number_format($display_subtotal, 2) ?>
                                    </span>

                                </div>


                                <?php if ($promotion_discount > 0): ?>

                                    <div
                                        class="d-flex justify-content-between mb-1"
                                        style="font-size:0.75rem; color:#198754;"
                                    >

                                        <span>
                                            Promotion:
                                            <?= htmlspecialchars(
                                                $appliedPromotion['promotion_title'] ?? 'Discount'
                                            ) ?>
                                        </span>

                                        <span>
                                            -₱<?= number_format($promotion_discount, 2) ?>
                                        </span>

                                    </div>

                                <?php endif; ?>


                                <div
                                    class="d-flex justify-content-between fw-bold mb-2 text-dark"
                                    style="font-size:0.85rem;"
                                >

                                    <span>
                                        Total:
                                    </span>

                                    <span>
                                        ₱<?= number_format($cart_total, 2) ?>
                                    </span>

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

                        </div>

                    </div>

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

                                                <form method="POST" action="cart.php" data-ajax-form="true" data-ajax-loading-text="Saving changes...">
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

                                                        <div>
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
     * If the user has already confirmed GCash,
     * allow the form to submit normally.
     */

    if (gcashConfirmed) {

        event.preventDefault();
        return submitCheckoutAjax(document.getElementById('checkoutForm'));
    }


    /*
     * Validate pickup first.
     */

    if (!validateTime()) {

        event.preventDefault();

        return false;
    }


    /*
     * Check selected payment method.
     */

    const selectedPayment = document.querySelector(
        'input[name="payment_method"]:checked'
    );


    if (!selectedPayment) {

        event.preventDefault();

        alert("Please select a payment method.");

        return false;
    }


    /*
     * CASH
     *
     * Submit normally.
     */

    if (selectedPayment.value.toLowerCase() === 'cash') {

        event.preventDefault();
        return submitCheckoutAjax(document.getElementById('checkoutForm'));
    }


    /*
     * GCASH
     *
     * Open modal instead of immediately submitting.
     */

    if (selectedPayment.value.toLowerCase().replace(/[- ]/g, '') === 'gcash') {

        event.preventDefault();

        showGcashModal();

        return false;
    }


    return true;
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


    /*
     * Validate file extension/type.
     */

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


    /*
     * Mark GCash as confirmed.
     *
     * This prevents the submit handler from opening
     * the modal again.
     */

    gcashConfirmed = true;


    /*
     * Disable submit buttons to prevent duplicate orders.
     */

    const checkoutButton =
        document.getElementById('checkoutButton');

    if (checkoutButton) {

        checkoutButton.disabled = true;

        checkoutButton.innerHTML =
            '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';

    }


    const modalElement =
        document.getElementById('gcashModal');

    const modal =
        bootstrap.Modal.getOrCreateInstance(modalElement);

    modal.hide();


    /*
     * Submit the actual form.
     *
     * requestSubmit() keeps the form's validation and submit
     * event flow intact.
     */

    form.requestSubmit();
}

</script>


<?php require_once '../includes/footer.php'; ?>