<?php

session_start();

date_default_timezone_set('Asia/Manila');

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../includes/db.php';
require_once '../includes/promotion-engine.php';

$isAjaxRequest =
    ($_POST['ajax'] ?? $_GET['ajax'] ?? '') === '1' ||
    strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

function checkoutFail(string $message): void
{
    global $isAjaxRequest;

    if ($isAjaxRequest) {
        http_response_code(422);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => $message
        ]);
        exit;
    }

    die($message);
}



/*
|--------------------------------------------------------------------------
| ONLY ACCEPT POST REQUEST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    header("Location: cart.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| CHECKOUT ACTION
|--------------------------------------------------------------------------
*/

if (!isset($_POST['checkout_action'])) {

    header("Location: cart.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| CUSTOMER INFORMATION
|--------------------------------------------------------------------------
*/

$customer_name = trim($_POST['full_name'] ?? '');
$customer_phone = trim((string)($_POST['mobile_number'] ?? ''));

/* Accept common Philippine formats, then normalize to 09XXXXXXXXX. */
$customer_phone = preg_replace('/[\s\-()]+/', '', $customer_phone);

if (str_starts_with($customer_phone, '+63')) {
    $customer_phone = '0' . substr($customer_phone, 3);
} elseif (str_starts_with($customer_phone, '63')) {
    $customer_phone = '0' . substr($customer_phone, 2);
}


/*
|--------------------------------------------------------------------------
| BASIC VALIDATION
|--------------------------------------------------------------------------
*/

if ($customer_name === '') {

    checkoutFail("Please provide your full name.");
}


if ($customer_phone === '') {

    checkoutFail("Please provide your mobile number.");
}

if (!preg_match('/^09\d{9}$/', $customer_phone)) {

    checkoutFail("Please enter a valid 11-digit Philippine mobile number.");
}


/*
|--------------------------------------------------------------------------
| PICKUP INFORMATION
|--------------------------------------------------------------------------
*/

$pickup_type = trim($_POST['pickup_type'] ?? '');

$pickup_date = trim($_POST['pickup_date'] ?? '');

$pickup_time = trim($_POST['pickup_time'] ?? '');


if ($pickup_type === '') {

    checkoutFail("Please select a pick-up option.");
}


/*
|--------------------------------------------------------------------------
| PICK-UP NOW
|--------------------------------------------------------------------------
*/

if ($pickup_type === 'Now') {

    $pickup_date = date('Y-m-d');

    $pickup_time = date('H:i:s');
}


/*
|--------------------------------------------------------------------------
| PICK-UP LATER
|--------------------------------------------------------------------------
*/

if ($pickup_type === 'Pick-up later') {

    if ($pickup_date === '' || $pickup_time === '') {

        checkoutFail("Please select a pick-up date and time.");
    }


    /*
     * Prevent past pickup dates.
     */

    if ($pickup_date < date('Y-m-d')) {

        checkoutFail("Pick-up date cannot be in the past.");
    }


    /*
     * Normalize time.
     */

    if (strlen($pickup_time) === 5) {

        $pickup_time .= ':00';
    }
}


/*
|--------------------------------------------------------------------------
| VALIDATE PICKUP TIME
|--------------------------------------------------------------------------
*/

if (
    $pickup_time < '09:00:00' ||
    $pickup_time > '22:00:00'
) {

    checkoutFail("Invalid pick-up time. Operating hours are strictly 9:00 AM to 10:00 PM.");
}


/*
|--------------------------------------------------------------------------
| PAYMENT METHOD
|--------------------------------------------------------------------------
*/

$payment_method = strtolower(
    trim($_POST['payment_method'] ?? '')
);


/*
 * Convert:
 *
 * Cash     -> cash
 * G-Cash   -> gcash
 * G Cash   -> gcash
 * GCash    -> gcash
 */

$payment_method = str_replace(
    ['-', ' ', '_'],
    '',
    $payment_method
);


if (!in_array($payment_method, ['cash', 'gcash'], true)) {

    checkoutFail("Invalid payment method.");
}


/*
|--------------------------------------------------------------------------
| CUSTOMER / GUEST SESSION
|--------------------------------------------------------------------------
|
| Registered customers use their logged-in customer ID.
| Guests are allowed to check out without an account, so their
| customer_id is stored as NULL and their name/mobile are kept
| directly on the order record.
|
*/

$isRegisteredCustomer =
    isset($_SESSION['user_id']) &&
    ($_SESSION['user_role'] ?? '') === 'customer';

$customer_id = $isRegisteredCustomer
    ? (int)$_SESSION['user_id']
    : null;


/*
|--------------------------------------------------------------------------
| CART VALIDATION
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['cart']) ||
    empty($_SESSION['cart'])
) {

    checkoutFail("Your cart is empty.");
}


/*
|--------------------------------------------------------------------------
| CALCULATE TOTAL
|--------------------------------------------------------------------------
|
| Never trust the total from the browser.
| We calculate it again from the session cart.
|
*/

$subtotal = 0;


foreach ($_SESSION['cart'] as $item) {

    $price = (float)($item['price'] ?? 0);

    $quantity = (int)($item['quantity'] ?? 0);


    if ($price < 0 || $quantity <= 0) {

        continue;
    }


    $subtotal += $price * $quantity;
}


$subtotal = round($subtotal, 2);


if ($subtotal <= 0) {

    checkoutFail("Invalid order subtotal.");
}


/*
|--------------------------------------------------------------------------
| APPLY ACTIVE PROMOTION
|--------------------------------------------------------------------------
|
| The promotion engine evaluates active promotions using the server-side
| session cart. Only one promotion is applied per order.
|
*/

$appliedPromotion = localiteaApplyBestPromotion(
    $pdo,
    $_SESSION['cart'],
    $subtotal
);

$promotion_discount = round(
    (float)($appliedPromotion['discount'] ?? 0),
    2
);

/*
 * Buy/Get promotions may add free reward items that were not present
 * in the original session cart. Their normal value is included in the
 * gross subtotal so the order can keep a consistent subtotal/discount/
 * total calculation.
 */
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

$gross_subtotal = round(
    $subtotal + $promotion_added_reward_value,
    2
);

$total_amount = round(
    max(0, $gross_subtotal - $promotion_discount),
    2
);


if ($total_amount < 0) {

    checkoutFail("Invalid order total.");
}


/*
|--------------------------------------------------------------------------
| PAYMENT SCREENSHOT
|--------------------------------------------------------------------------
*/

$payment_screenshot = null;


/*
|--------------------------------------------------------------------------
| GCASH PAYMENT SCREENSHOT
|--------------------------------------------------------------------------
*/

if ($payment_method === 'gcash') {


    /*
     * Screenshot is required for GCash.
     */

    if (
        !isset($_FILES['payment_screenshot']) ||
        $_FILES['payment_screenshot']['error'] !== UPLOAD_ERR_OK
    ) {

        checkoutFail("Please upload your GCash payment screenshot.");
    }


    $file = $_FILES['payment_screenshot'];


    /*
     * Maximum 5MB.
     */

    $maxFileSize = 5 * 1024 * 1024;


    if ($file['size'] > $maxFileSize) {

        checkoutFail("The payment screenshot must not exceed 5MB.");
    }


    /*
     * Validate actual MIME type.
     *
     * Do not rely only on the filename extension.
     */

    $finfo = new finfo(FILEINFO_MIME_TYPE);

    $mimeType = $finfo->file($file['tmp_name']);


    $allowedMimeTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png'
    ];


    if (!isset($allowedMimeTypes[$mimeType])) {

        checkoutFail("Invalid payment screenshot. Only JPG, JPEG, or PNG files are allowed.");
    }


    /*
     * Create upload directory if it doesn't exist.
     */

    $uploadDirectory =
        dirname(__DIR__) .
        '/assets/uploads/receipts/';


    if (!is_dir($uploadDirectory)) {

        if (!mkdir($uploadDirectory, 0755, true)) {

            checkoutFail("Unable to create the payment upload directory.");
        }
    }


    /*
     * Generate a unique filename.
     */

    $extension = $allowedMimeTypes[$mimeType];


    $uniqueFilename =
        'gcash_' .
        date('Ymd_His') .
        '_' .
        bin2hex(random_bytes(6)) .
        '.' .
        $extension;


    $destination =
        $uploadDirectory .
        $uniqueFilename;


    /*
     * Move uploaded screenshot.
     */

    if (!move_uploaded_file(
        $file['tmp_name'],
        $destination
    )) {

        checkoutFail("Failed to upload the GCash payment screenshot.");
    }


    /*
     * This is what will be stored in the database.
     *
     * Example:
     * assets/uploads/receipts/gcash_20260913_....png
     */

    $payment_screenshot =
        'assets/uploads/receipts/' .
        $uniqueFilename;
}


/*
|--------------------------------------------------------------------------
| INITIAL ORDER STATUS
|--------------------------------------------------------------------------
*/

/*
 * Initial order status.
 *
 * GCash orders and guest cash orders stay in Pending Verification so
 * Staff/Admin can review them before the order moves to preparation.
 * Registered-customer cash orders keep the existing fast workflow.
 */
if ($payment_method === 'gcash') {
    $initial_status = 'pending_verification';
} elseif ($isRegisteredCustomer) {
    $initial_status = 'confirmed';
} else {
    /* Guest cash orders use the same Pending Verification queue.
       Staff/Admin must confirm them before preparation. */
    $initial_status = 'pending_verification';
}


/*
|--------------------------------------------------------------------------
| DATABASE TRANSACTION
|--------------------------------------------------------------------------
*/

try {

    $pdo->beginTransaction();


    /*
     * ================================================================
     * INSERT ORDER
     * ================================================================
     */

    $stmtOrder = $pdo->prepare("
        INSERT INTO orders
        (
            order_number,
            claim_number,
            customer_id,
            customer_name,
            contact_number,
            pickup_date,
            pickup_time,
            payment_method,
            payment_screenshot,
            subtotal,
            total_amount,
            status
        )
        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?
        )
    ");


    /*
     * Use temporary values first.
     */

    $stmtOrder->execute([
        null,
        null,
        $customer_id,
        $customer_name,
        $customer_phone,
        $pickup_date,
        $pickup_time,
        $payment_method,
        $payment_screenshot,
        $gross_subtotal,
        $total_amount,
        $initial_status
    ]);


    /*
     * Get newly created order ID.
     */

    $order_id = (int)$pdo->lastInsertId();


    if ($order_id <= 0) {

        throw new Exception(
            "Failed to generate order ID."
        );
    }


    /*
     * ================================================================
     * GENERATE ORDER NUMBER
     * ================================================================
     *
     * Example:
     *
     * ORD-20260913-0016
     *
     */

    $order_number =
        'ORD-' .
        date('Ymd') .
        '-' .
        str_pad(
            $order_id,
            4,
            '0',
            STR_PAD_LEFT
        );


    /*
     * ================================================================
     * GENERATE CLAIM NUMBER
     * ================================================================
     *
     * Example:
     *
     * CLM-0016
     *
     */

    $claim_number =
        'CLM-' .
        str_pad(
            $order_id,
            4,
            '0',
            STR_PAD_LEFT
        );


    /*
     * ================================================================
     * UPDATE ORDER NUMBERS
     * ================================================================
     */

    $stmtUpdateOrder = $pdo->prepare("
        UPDATE orders
        SET
            order_number = ?,
            claim_number = ?
        WHERE id = ?
    ");


    $stmtUpdateOrder->execute([
        $order_number,
        $claim_number,
        $order_id
    ]);


    /*
     * ================================================================
     * SAVE APPLIED PROMOTION
     * ================================================================
     */

    if (!empty($appliedPromotion['promotion_id']) && $promotion_discount > 0) {
        $stmtPromotion = $pdo->prepare("
            INSERT INTO order_promotions
            (
                order_id,
                promotion_id,
                promotion_title,
                rule_type,
                discount_amount,
                promotion_snapshot,
                created_at
            )
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");

        $stmtPromotion->execute([
            $order_id,
            (int)$appliedPromotion['promotion_id'],
            (string)$appliedPromotion['promotion_title'],
            (string)$appliedPromotion['rule_type'],
            $promotion_discount,
            $appliedPromotion['snapshot']
        ]);
    }


    /*
     * ================================================================
     * INSERT ORDER ITEMS
     * ================================================================
     *
     * For a Buy/Get promotion, the engine tells us which original cart
     * quantities became free and which free reward lines must be added.
     * This keeps the order item records consistent with the saved
     * subtotal, promotion discount, and final total.
     */

    $stmtItem = $pdo->prepare("
        INSERT INTO order_items
        (
            order_id,
            product_id,
            product_name,
            quantity,
            unit_price,
            subtotal,
            size,
            addons,
            sugar_level
        )
        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?
        )
    ");


    /*
     * ================================================================
     * SAVE ORIGINAL CART ITEMS
     * ================================================================
     */

    foreach ($_SESSION['cart'] as $cartKey => $item) {

        $product_id =
            isset($item['product_id'])
                ? (int)$item['product_id']
                : null;

        $product_name =
            trim($item['name'] ?? '');

        $quantity =
            (int)($item['quantity'] ?? 0);

        $unit_price =
            (float)($item['price'] ?? 0);


        if (
            $quantity <= 0 ||
            $product_name === '' ||
            $unit_price < 0
        ) {
            continue;
        }


        /*
         * Remove only the quantities that became free because the
         * customer already had the Get product in the cart.
         */
        $freeQuantity = (int)(
            $promotion_free_allocations[(string)$cartKey] ?? 0
        );

        $freeQuantity = max(
            0,
            min($quantity, $freeQuantity)
        );

        $paidQuantity =
            $quantity - $freeQuantity;


        if ($paidQuantity <= 0) {
            continue;
        }


        $item_subtotal =
            round(
                $unit_price * $paidQuantity,
                2
            );


        /*
         * ================================================================
         * SAVE PRODUCT CUSTOMIZATIONS
         * ================================================================
         */

        $item_size = trim(
            (string)($item['size'] ?? '')
        );

        $item_sugar_level = trim(
            (string)($item['sugar_level'] ?? '')
        );

        $item_addons = $item['addons'] ?? null;

        if (is_array($item_addons)) {

            $item_addons = json_encode(
                array_values($item_addons),
                JSON_UNESCAPED_UNICODE
            );

            if ($item_addons === false) {
                $item_addons = null;
            }

        } elseif ($item_addons !== null) {

            $item_addons = trim(
                (string)$item_addons
            );

            if ($item_addons === '') {
                $item_addons = null;
            }
        }


        $stmtItem->execute([
            $order_id,
            $product_id,
            $product_name,
            $paidQuantity,
            $unit_price,
            $item_subtotal,
            $item_size !== '' ? $item_size : null,
            $item_addons,
            $item_sugar_level !== '' ? $item_sugar_level : null
        ]);
    }


    /*
     * ================================================================
     * SAVE FREE PROMOTION REWARD ITEMS
     * ================================================================
     *
     * The normal unit price is retained on these lines so the order
     * subtotal includes the item's value. The promotion discount then
     * removes that value from the amount the customer actually pays.
     * The name is marked FREE for staff, customer history, and receipts.
     */

    foreach ($promotion_reward_items as $rewardItem) {

        $rewardProductId =
            isset($rewardItem['product_id'])
                ? (int)$rewardItem['product_id']
                : null;

        $rewardName = trim(
            (string)($rewardItem['name'] ?? '')
        );

        $rewardQuantity = (int)(
            $rewardItem['quantity'] ?? 0
        );

        $rewardUnitPrice = (float)(
            $rewardItem['price'] ?? 0
        );

        if (
            $rewardProductId <= 0 ||
            $rewardName === '' ||
            $rewardQuantity <= 0 ||
            $rewardUnitPrice < 0
        ) {
            continue;
        }

        $rewardSubtotal = round(
            $rewardUnitPrice * $rewardQuantity,
            2
        );

        $rewardSize = trim(
            (string)($rewardItem['size'] ?? '')
        );

        $rewardSugar = trim(
            (string)($rewardItem['sugar_level'] ?? '')
        );

        $rewardAddons = $rewardItem['addons'] ?? null;

        if (is_array($rewardAddons)) {

            $rewardAddons = json_encode(
                array_values($rewardAddons),
                JSON_UNESCAPED_UNICODE
            );

            if ($rewardAddons === false) {
                $rewardAddons = null;
            }

        } elseif ($rewardAddons !== null) {

            $rewardAddons = trim(
                (string)$rewardAddons
            );

            if ($rewardAddons === '') {
                $rewardAddons = null;
            }
        }

        $freeProductName =
            $rewardName . ' (FREE)';


        $stmtItem->execute([
            $order_id,
            $rewardProductId,
            $freeProductName,
            $rewardQuantity,
            $rewardUnitPrice,
            $rewardSubtotal,
            $rewardSize !== '' ? $rewardSize : null,
            $rewardAddons,
            $rewardSugar !== '' ? $rewardSugar : null
        ]);
    }


    /*
     * ================================================================
     * STAFF + ADMIN NEW ORDER NOTIFICATION
     * ================================================================
     *
     * Every newly placed order must notify the Admin, regardless of
     * payment method. GCash orders will also receive the separate
     * payment-verification notification below.
     */

    try {

        $notificationMessage =
            "New order {$order_number} / {$claim_number} has been placed.";

        /*
         * Keep the existing Staff notification.
         */
        $stmtNotification = $pdo->prepare("
            INSERT INTO notifications
            (
                recipient_role,
                recipient_id,
                type,
                message,
                reference_id
            )
            VALUES
            (
                'staff',
                NULL,
                ?,
                ?,
                ?
            )
        ");

        $stmtNotification->execute([
            'new_order',
            $notificationMessage,
            $order_id
        ]);

        /*
         * Also notify Admin for every new order, including Cash orders
         * that are automatically set to Confirmed.
         */
        $stmtAdminNewOrderNotification = $pdo->prepare("
            INSERT INTO notifications
            (
                recipient_role,
                recipient_id,
                type,
                message,
                reference_id
            )
            VALUES
            (
                'admin',
                NULL,
                ?,
                ?,
                ?
            )
        ");

        $stmtAdminNewOrderNotification->execute([
            'new_order',
            $notificationMessage,
            $order_id
        ]);

    } catch (Exception $notificationError) {

        /*
         * Do not fail the entire order just because a notification
         * insert has a problem.
         *
         * The order itself can still be completed.
         */

    }


    /*
     * ================================================================
     * ADMIN GCASH NOTIFICATION
     * ================================================================
     *
     * GCash orders are placed in pending_verification so the
     * admin knows the payment proof needs to be reviewed.
     * Cash orders do not receive this notification.
     */

    if ($payment_method === 'gcash') {

        $gcashNotificationMessage =
            "Order {$order_number} is pending verification. This is a GCash order. Please review the payment proof.";

        /*
         * STAFF GCASH NOTIFICATION
         *
         * Keep the existing new_order notification above.
         * This is an additional notification so Staff knows
         * that the new order is specifically waiting for
         * GCash payment verification.
         */
        try {

            $stmtStaffGcashNotification = $pdo->prepare("
                INSERT INTO notifications
                (
                    recipient_role,
                    recipient_id,
                    type,
                    message,
                    reference_id
                )
                VALUES
                (
                    'staff',
                    NULL,
                    ?,
                    ?,
                    ?
                )
            ");

            $stmtStaffGcashNotification->execute([
                'gcash_pending_verification',
                $gcashNotificationMessage,
                $order_id
            ]);

        } catch (Exception $staffGcashNotificationError) {

            /* Do not fail the order if this notification fails. */

        }

        /*
         * ADMIN GCASH NOTIFICATION
         *
         * The Admin Notifications page will use this same
         * notification type and message.
         */
        try {

            $stmtAdminGcashNotification = $pdo->prepare("
                INSERT INTO notifications
                (
                    recipient_role,
                    recipient_id,
                    type,
                    message,
                    reference_id
                )
                VALUES
                (
                    'admin',
                    NULL,
                    ?,
                    ?,
                    ?
                )
            ");

            $stmtAdminGcashNotification->execute([
                'gcash_pending_verification',
                $gcashNotificationMessage,
                $order_id
            ]);

        } catch (Exception $adminGcashNotificationError) {

            /* Do not fail the order if this notification fails. */

        }
    }

    /*
     * ================================================================
     * COMMIT
     * ================================================================
     */

    $pdo->commit();


    /*
     * ================================================================
     * CLEAR CART
     * ================================================================
     */

    unset($_SESSION['cart']);
    unset($_SESSION['selected_promotion_id']);


    /*
     * ================================================================
     * OPTIONAL SUCCESS SESSION DATA
     * ================================================================
     *
     * Useful if receipt.php needs these values.
     */

    $_SESSION['last_order_id'] = $order_id;
    $_SESSION['last_order_number'] = $order_number;
    $_SESSION['last_claim_number'] = $claim_number;


    /*
     * ================================================================
     * REDIRECT AFTER CHECKOUT
     * ================================================================
     *
     * Guests receive a one-time confirmation page in the same browser
     * session. It shows the Order No. and Claim No. and does not provide
     * a print-receipt option. They can later use Claim No. + mobile on
     * monitor-guest-order.php.
     */

    if (!$isRegisteredCustomer) {

        $_SESSION['guest_last_order_id'] = $order_id;
        $_SESSION['guest_confirmation_token'] = bin2hex(random_bytes(16));

        $successRedirect =
            "guest-order-confirmation.php?token=" .
            urlencode($_SESSION['guest_confirmation_token']);

    } else {

        $successRedirect =
            "receipt.php?order=" .
            urlencode($order_number);
    }

    if ($isAjaxRequest) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'message' => 'Your order has been placed successfully.',
            'order_number' => $order_number,
            'claim_number' => $claim_number,
            'redirect' => $successRedirect
        ]);
        exit;
    }

    header('Location: ' . $successRedirect);
    exit;


} catch (Throwable $e) {


    /*
     * Roll back database changes if possible.
     */

    if ($pdo->inTransaction()) {

        $pdo->rollBack();
    }


    /*
     * If the GCash image was successfully uploaded but
     * the database transaction failed, remove the orphan file.
     */

    if (
        $payment_screenshot !== null &&
        $payment_method === 'gcash'
    ) {

        $uploadedFile =
            dirname(__DIR__) .
            '/' .
            $payment_screenshot;


        if (is_file($uploadedFile)) {

            @unlink($uploadedFile);
        }
    }


    if ($isAjaxRequest) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Unable to place your order right now.'
        ]);
        exit;
    }

    /*
     * Show error while developing.
     */

    echo "<div style='
        font-family:Arial;
        max-width:700px;
        margin:50px auto;
        padding:25px;
        border:1px solid #ddd;
        border-radius:10px;
        background:#fff;
    '>";

    echo "<h3 style='color:#b02a37;'>Checkout Error</h3>";

    echo "<p>";
    echo htmlspecialchars(
        $e->getMessage()
    );
    echo "</p>";

    echo "<a href='cart.php'>
            Return to Cart
          </a>";

    echo "</div>";

    exit;
}

?>