<?php

session_start();

date_default_timezone_set('Asia/Manila');

/* Errors are logged for the owner, never printed on the customer's screen. */
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

require_once '../includes/db.php';
require_once '../includes/promotion-engine.php';

$isAjaxRequest =
    ($_POST['ajax'] ?? $_GET['ajax'] ?? '') === '1' ||
    strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

/*
 * One MySQL named lock serializes online slot reservations. It is held from
 * the capacity check through the order commit, then explicitly released.
 */
$pickupCapacityLockName = 'localitea_pickup_capacity';
$pickupCapacityLockAcquired = false;

function releasePickupCapacityLock(): void
{
    global $pdo, $pickupCapacityLockName, $pickupCapacityLockAcquired;

    if (empty($pickupCapacityLockAcquired) || !isset($pdo) || !($pdo instanceof PDO)) {
        return;
    }

    try {
        $releaseStmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $releaseStmt->execute([$pickupCapacityLockName]);
        $releaseResult = $releaseStmt->fetchColumn();

        if ((string)$releaseResult !== '1') {
            error_log('Checkout pickup lock was not owned at release time.');
        }

        // The connection no longer needs to attempt a second release.
        $pickupCapacityLockAcquired = false;
    } catch (Throwable $releaseError) {
        error_log('Checkout pickup lock release failed: ' . $releaseError->getMessage());
    }
}

/**
 * Return the first 15-minute pickup slot that is not earlier than the
 * requested preparation deadline. Slots run from 10:00 AM through 9:30 PM.
 */
function checkoutEarliestPickupSlot(DateTimeImmutable $currentTime, int $leadMinutes): DateTimeImmutable
{
    $deadline = $currentTime->modify('+' . max(0, $leadMinutes) . ' minutes');

    $secondsOfDay =
        ((int)$deadline->format('G') * 3600) +
        ((int)$deadline->format('i') * 60) +
        (int)$deadline->format('s');

    // Round upward to the next 15-minute boundary, including non-zero seconds.
    $slotMinuteOfDay = intdiv($secondsOfDay + 899, 900) * 15;

    if ($slotMinuteOfDay < 600) {
        return $deadline->setTime(10, 0, 0);
    }

    if ($slotMinuteOfDay > 1290) {
        return $deadline->setTime(0, 0, 0)->modify('+1 day')->setTime(10, 0, 0);
    }

    return $deadline->setTime(
        intdiv($slotMinuteOfDay, 60),
        $slotMinuteOfDay % 60,
        0
    );
}

/**
 * Plain, friendly error page for non-AJAX requests (no technical details).
 */
function checkoutRenderErrorPage(string $message): void
{
    header('Content-Type: text/html; charset=utf-8');

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Checkout</title></head>'
        . '<body style="margin:0;background:#F6F2EE;font-family:Arial,Helvetica,sans-serif;">'
        . '<div style="max-width:520px;margin:60px auto;padding:26px;background:#fff;'
        . 'border:2px solid #6F4E37;border-radius:14px;color:#2C221E;">'
        . '<h3 style="margin:0 0 10px;color:#4A3525;">We couldn\'t place your order</h3>'
        . '<p style="margin:0 0 18px;line-height:1.5;">'
        . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
        . '</p>'
        . '<a href="cart.php" style="display:inline-block;padding:9px 16px;background:#6F4E37;'
        . 'color:#fff;border-radius:8px;text-decoration:none;font-weight:600;">Back to Cart</a>'
        . '</div></body></html>';
}

function checkoutFail(string $message, bool $refresh = false, int $statusCode = 422, string $errorCode = 'checkout_error'): void
{
    global $isAjaxRequest, $pdo, $payment_screenshot;

    /* A validation failure inside the transaction must not leave it open. */
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // Also release the connection-scoped capacity lock on validation failures.
    releasePickupCapacityLock();

    /* Remove a GCash image already moved to storage if checkout is rejected. */
    if (!empty($payment_screenshot) && is_string($payment_screenshot)) {
        $uploadRoot = realpath(dirname(__DIR__) . '/assets/uploads/receipts');
        $candidate = dirname(__DIR__) . '/' . ltrim($payment_screenshot, '/\\');
        $realCandidate = realpath($candidate);
        if (
            $uploadRoot !== false &&
            $realCandidate !== false &&
            str_starts_with($realCandidate, $uploadRoot . DIRECTORY_SEPARATOR) &&
            is_file($realCandidate)
        ) {
            @unlink($realCandidate);
        }
    }

    http_response_code($statusCode);

    if ($isAjaxRequest) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => $message,
            'refresh' => $refresh,
            'error_code' => $errorCode
        ]);
        exit;
    }

    checkoutRenderErrorPage($message);
    exit;
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

if (!isset($_POST['checkout_action']) || (string)$_POST['checkout_action'] !== '1') {
    header('Location: cart.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| CSRF + DUPLICATE SUBMISSION PROTECTION
|--------------------------------------------------------------------------
|
| Both tokens are stored in the PHP session. The CSRF token protects the
| request from cross-site submission. The checkout request token is consumed
| only after a successful order commit, preventing the same form from being
| submitted twice in one session.
*/
$postedCsrfToken = $_POST['csrf_token'] ?? '';
if (
    !is_string($postedCsrfToken) ||
    empty($_SESSION['csrf_token']) ||
    !is_string($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $postedCsrfToken)
) {
    checkoutFail('Your session could not be verified. Please refresh the cart and try again.', false, 403);
}

$postedCheckoutRequestToken = $_POST['checkout_request_token'] ?? '';
if (
    !is_string($postedCheckoutRequestToken) ||
    empty($_SESSION['checkout_request_token']) ||
    !is_string($_SESSION['checkout_request_token']) ||
    !hash_equals($_SESSION['checkout_request_token'], $postedCheckoutRequestToken)
) {
    checkoutFail('This checkout request has already been used or has expired. Please review your cart and try again.', true, 409);
}


/*
|--------------------------------------------------------------------------
| CUSTOMER INFORMATION
|--------------------------------------------------------------------------
*/

$rawCustomerName = $_POST['full_name'] ?? '';
$rawCustomerPhone = $_POST['mobile_number'] ?? '';

if (!is_string($rawCustomerName) || !is_string($rawCustomerPhone)) {
    checkoutFail('Please provide valid customer information.');
}

$customer_name = preg_replace('/\s+/u', ' ', trim($rawCustomerName));
$customer_phone = trim($rawCustomerPhone);

if ($customer_name === '') {
    checkoutFail('Please provide your full name.');
}

/* Accept Unicode letters, spaces, periods, apostrophes, and hyphens. */
$nameCharacterCount = 0;
if (preg_match_all('/./us', $customer_name, $nameCharacters) === false) {
    checkoutFail('Please enter a valid full name.');
}
$nameCharacterCount = count($nameCharacters[0]);
if (
    $nameCharacterCount < 2 ||
    $nameCharacterCount > 100 ||
    preg_match('/\A[\p{L}\p{M}][\p{L}\p{M} .\'’\-]*\z/u', $customer_name) !== 1
) {
    checkoutFail('Please enter a valid full name using letters, spaces, periods, apostrophes, or hyphens (2–100 characters).');
}

if ($customer_phone === '') {
    checkoutFail('Please provide your mobile number.');
}
if (strlen($customer_phone) > 30) {
    checkoutFail('Please enter a valid Philippine mobile number.');
}

/* Remove common visual separators, then normalize to 09XXXXXXXXX. */
$customer_phone = preg_replace('/[\s\-().]+/', '', $customer_phone);
if (str_starts_with($customer_phone, '+63')) {
    $customer_phone = '0' . substr($customer_phone, 3);
} elseif (str_starts_with($customer_phone, '63')) {
    $customer_phone = '0' . substr($customer_phone, 2);
}

if (!preg_match('/\A09\d{9}\z/', $customer_phone)) {
    checkoutFail('Please enter a valid 11-digit Philippine mobile number.');
}



/*
|--------------------------------------------------------------------------
| PICKUP INFORMATION
|--------------------------------------------------------------------------
*/

$pickupTypeInput = $_POST['pickup_type'] ?? '';
$pickupDateInput = $_POST['pickup_date'] ?? '';
$pickupTimeInput = $_POST['pickup_time'] ?? '';

if (!is_string($pickupTypeInput) || !is_string($pickupDateInput) || !is_string($pickupTimeInput)) {
    checkoutFail('Please provide valid pick-up information.');
}

$pickup_type = trim($pickupTypeInput);
$pickup_date = trim($pickupDateInput);
$pickup_time = trim($pickupTimeInput);

if (!in_array($pickup_type, ['Now', 'Pick-up later'], true)) {
    checkoutFail('Invalid pick-up option. Please choose Pick-up Now or Pick-up Later.');
}

$manilaTimezone = new DateTimeZone('Asia/Manila');
$manilaNow = new DateTimeImmutable('now', $manilaTimezone);
$currentMinuteOfDay = ((int)$manilaNow->format('G') * 60) + (int)$manilaNow->format('i');
$openingMinute = 10 * 60;
$lastPickupMinute = (21 * 60) + 30; // 9:30 PM; the store closes at 10:00 PM.
$isPickupNow = ($pickup_type === 'Now');
$selectedPickupDateTime = null;

if ($isPickupNow) {
    if ($currentMinuteOfDay < $openingMinute) {
        checkoutFail('Pick-up Now is available starting at 10:00 AM. Please choose Pick-up Later for an earlier booking.', false, 422, 'pickup_now_unavailable');
    }

    /* Pick-up Now receives its actual date/time only after the capacity lock. */
    $pickup_date = '';
    $pickup_time = '';
} else {
    if ($pickup_date === '' || $pickup_time === '') {
        checkoutFail('Please select a pick-up date and time.', false, 422, 'pickup_selection_required');
    }

    /* Strict YYYY-MM-DD calendar-date validation. */
    if (!preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $pickup_date)) {
        checkoutFail('Please select a valid pick-up date.', false, 422, 'pickup_invalid_date');
    }
    $parsedPickupDate = DateTimeImmutable::createFromFormat('!Y-m-d', $pickup_date, $manilaTimezone);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if (
        !$parsedPickupDate ||
        ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) ||
        $parsedPickupDate->format('Y-m-d') !== $pickup_date
    ) {
        checkoutFail('Please select a valid pick-up date.');
    }

    $todayDate = $manilaNow->format('Y-m-d');
    $tomorrowDate = $manilaNow->modify('+1 day')->format('Y-m-d');
    if ($pickup_date < $todayDate || $pickup_date > $tomorrowDate) {
        checkoutFail('Advance pick-up is available for today or tomorrow only. Please choose a date within this booking period.');
    }

    /* Accept only a real 24-hour HH:MM or HH:MM:SS value. */
    if (!preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?\z/', $pickup_time)) {
        checkoutFail('Please select a valid pick-up time.', false, 422, 'pickup_invalid_time');
    }

    $timeFormat = strlen($pickup_time) === 5 ? '!H:i' : '!H:i:s';
    $parsedPickupTime = DateTimeImmutable::createFromFormat($timeFormat, $pickup_time, $manilaTimezone);
    $timeErrors = DateTimeImmutable::getLastErrors();
    $expectedTime = strlen($pickup_time) === 5
        ? $parsedPickupTime?->format('H:i')
        : $parsedPickupTime?->format('H:i:s');

    if (
        !$parsedPickupTime ||
        ($timeErrors !== false && ($timeErrors['warning_count'] > 0 || $timeErrors['error_count'] > 0)) ||
        $expectedTime !== $pickup_time
    ) {
        checkoutFail('Please select a valid pick-up time.');
    }

    if (strlen($pickup_time) === 5) {
        $pickup_time .= ':00';
    }

    $selectedHour = (int)substr($pickup_time, 0, 2);
    $selectedMinute = (int)substr($pickup_time, 3, 2);
    $selectedSecond = (int)substr($pickup_time, 6, 2);
    $selectedMinuteOfDay = ($selectedHour * 60) + $selectedMinute;

    if ($selectedMinuteOfDay < $openingMinute || $selectedMinuteOfDay > $lastPickupMinute) {
        checkoutFail('Pick-up time must be between 10:00 AM and 9:30 PM.', false, 422, 'pickup_invalid_time');
    }

    if (($selectedMinute % 15) !== 0 || $selectedSecond !== 0) {
        checkoutFail('Pick-up times must use 15-minute intervals, such as 10:00 AM, 10:15 AM, or 10:30 AM.', false, 422, 'pickup_invalid_interval');
    }

    $selectedPickupDateTime = new DateTimeImmutable(
        $pickup_date . ' ' . $pickup_time,
        $manilaTimezone
    );

    if ($selectedPickupDateTime <= $manilaNow) {
        checkoutFail('The selected pick-up time has already passed. Please choose a later time.', false, 422, 'pickup_time_passed');
    }
}



/*
|--------------------------------------------------------------------------
| PAYMENT METHOD
|--------------------------------------------------------------------------
*/

$paymentMethodInput = $_POST['payment_method'] ?? '';
if (!is_string($paymentMethodInput)) {
    checkoutFail('Please select a valid payment method.');
}
$payment_method = strtolower(trim($paymentMethodInput));


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

$hasCustomerRole = ($_SESSION['user_role'] ?? '') === 'customer';
$sessionCustomerId = filter_var(
    $_SESSION['user_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($hasCustomerRole && $sessionCustomerId === false) {
    checkoutFail('Your customer session has expired. Please sign in again.');
}

$isRegisteredCustomer = $hasCustomerRole && $sessionCustomerId !== false;
$customer_id = $isRegisteredCustomer ? (int)$sessionCustomerId : null;

if (!$isRegisteredCustomer && !empty($_SESSION['user_id'])) {
    checkoutFail('Please sign in again before placing this order.');
}


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
| SERVER-SIDE CART / PRODUCT / CUSTOMIZATION VALIDATION
|--------------------------------------------------------------------------
|
| Recheck every cart line against current database data. Session prices are
| treated as a display snapshot, not as the source of truth. If a price has
| changed, update the session cart and ask the customer to review the new
| total before placing the order.
*/
$allowedSugarLevels = ['0%', '25%', '50%', '75%', '100%'];
$allowedSizes = ['', 'Regular', 'Grande'];
$cartPriceChanged = false;
$productCache = [];
$productLookupStmt = $pdo->prepare("
    SELECT id, category_id, name, price, regular_price, grande_price, is_available, is_archived
    FROM products
    WHERE id = ?
    LIMIT 1
");

foreach ($_SESSION['cart'] as $cartKey => $cartLine) {
    if (!is_array($cartLine)) {
        checkoutFail('Your cart contains an invalid item. Please remove it and try again.');
    }

    $rawProductId = $cartLine['product_id'] ?? null;
    $productId = filter_var($rawProductId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($productId === false) {
        checkoutFail('A product in your cart is invalid. Please remove it and try again.');
    }

    $rawQuantity = $cartLine['quantity'] ?? null;
    $quantity = filter_var($rawQuantity, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($quantity === false) {
        checkoutFail('Every product quantity must be a whole number greater than zero.');
    }

    if (!isset($productCache[$productId])) {
        $productLookupStmt->execute([$productId]);
        $productCache[$productId] = $productLookupStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $dbProduct = $productCache[$productId];

    if (
        !$dbProduct ||
        (int)$dbProduct['is_available'] !== 1 ||
        (int)$dbProduct['is_archived'] !== 0
    ) {
        checkoutFail('One of the products in your cart is no longer available. Please remove it or choose another product.');
    }

    $itemSize = $cartLine['size'] ?? '';
    if (!is_string($itemSize)) {
        checkoutFail('A product in your cart has an invalid size. Please edit the item.');
    }
    $itemSize = trim($itemSize);
    if (!in_array($itemSize, $allowedSizes, true)) {
        checkoutFail('A product in your cart has an invalid size. Please edit the item.');
    }

    if ($itemSize === 'Regular') {
        $basePrice = (float)($dbProduct['regular_price'] ?? 0);
    } elseif ($itemSize === 'Grande') {
        $basePrice = (float)($dbProduct['grande_price'] ?? 0);
    } else {
        $basePrice = (float)($dbProduct['price'] ?? 0);
    }
    if (!is_finite($basePrice) || $basePrice <= 0) {
        checkoutFail('The selected size or price for ' . (string)$dbProduct['name'] . ' is no longer available. Please edit your cart.');
    }

    $itemSugar = $cartLine['sugar_level'] ?? '';
    if (!is_string($itemSugar) || !in_array(trim($itemSugar), $allowedSugarLevels, true)) {
        checkoutFail('A product in your cart has an invalid sugar level. Please edit the item.');
    }

    $discountType = strtolower(trim((string)($cartLine['discount_type'] ?? 'none')));
    if (!in_array($discountType, ['none', 'pwd', 'senior'], true)) {
        checkoutFail('A product in your cart has an invalid discount selection. Please review your cart.');
    }

    /* Validate add-ons and obtain their current prices from the database. */
    $rawAddons = $cartLine['addons'] ?? [];
    if ($rawAddons === null || $rawAddons === '') {
        $rawAddons = [];
    }
    if (!is_array($rawAddons)) {
        checkoutFail('A product in your cart has invalid add-ons. Please edit the item.');
    }

    $addonNames = [];
    foreach ($rawAddons as $addonName) {
        if (!is_string($addonName)) {
            checkoutFail('A product in your cart has invalid add-ons. Please edit the item.');
        }
        $addonName = trim($addonName);
        if ($addonName === '') {
            checkoutFail('A product in your cart has an empty add-on selection. Please edit the item.');
        }
        $addonNames[] = $addonName;
    }
    if (count($addonNames) !== count(array_unique($addonNames))) {
        checkoutFail('A product in your cart contains a repeated add-on. Please edit the item.');
    }

    $addonsTotal = 0.00;
    if ($addonNames) {
        $addonPlaceholders = implode(',', array_fill(0, count($addonNames), '?'));
        $addonValidationStmt = $pdo->prepare("
            SELECT a.name, a.price
            FROM addons a
            INNER JOIN product_addons pa ON pa.addon_id = a.id
            WHERE pa.product_id = ?
              AND a.name IN ($addonPlaceholders)
              AND a.is_available = 1
              AND a.is_archived = 0
        ");
        $addonValidationStmt->execute(array_merge([$productId], $addonNames));
        $currentAddons = $addonValidationStmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($currentAddons) !== count($addonNames)) {
            checkoutFail('One of the add-ons in your cart is no longer available for this product. Please edit the item.');
        }
        foreach ($currentAddons as $currentAddon) {
            $addonPrice = (float)$currentAddon['price'];
            if (!is_finite($addonPrice) || $addonPrice < 0) {
                checkoutFail('An add-on price is invalid. Please edit your cart.');
            }
            $addonsTotal += $addonPrice;
        }
    }

    /* Validate any promotion tag; expired or mismatched promo lines cannot be ordered. */
    $promotionId = (int)($cartLine['promotion_source_id'] ?? 0);
    $promotionRole = trim((string)($cartLine['promotion_source_role'] ?? ''));
    $promotionSlotId = (int)($cartLine['promotion_slot_id'] ?? 0);
    if ($promotionId > 0) {
        if (!in_array($promotionRole, ['qualifying', 'buy', 'get', 'bundle'], true)) {
            checkoutFail('A promotion item in your cart is no longer valid. Please remove it and add it again.');
        }

        if ($promotionRole === 'bundle') {
            if ($promotionSlotId <= 0) {
                checkoutFail('A bundle item in your cart is missing its configured slot. Please add the bundle again.');
            }
            $promotionValidationStmt = $pdo->prepare("
                SELECT pri.size AS promotion_size, slotp.category_id
                FROM promotions p
                INNER JOIN promotion_rules r ON r.promotion_id = p.id
                INNER JOIN promotion_rule_items pri ON pri.rule_id = r.id
                INNER JOIN products slotp ON slotp.id = pri.product_id
                WHERE p.id = ?
                  AND pri.id = ?
                  AND pri.role = 'bundle'
                  AND slotp.category_id = ?
                  AND p.is_active = 1
                  AND p.is_archived = 0
                  AND p.start_date <= CURDATE()
                  AND p.end_date >= CURDATE()
                LIMIT 1
            ");
            $promotionValidationStmt->execute([$promotionId, $promotionSlotId, (int)$dbProduct['category_id']]);
        } else {
            if ($promotionSlotId > 0) {
                checkoutFail('A promotion item in your cart has an invalid bundle slot. Please add it again.');
            }
            $promotionValidationStmt = $pdo->prepare("
                SELECT pri.size AS promotion_size
                FROM promotions p
                INNER JOIN promotion_rules r ON r.promotion_id = p.id
                INNER JOIN promotion_rule_items pri ON pri.rule_id = r.id
                WHERE p.id = ?
                  AND pri.product_id = ?
                  AND pri.role = ?
                  AND p.is_active = 1
                  AND p.is_archived = 0
                  AND p.start_date <= CURDATE()
                  AND p.end_date >= CURDATE()
                LIMIT 1
            ");
            $promotionValidationStmt->execute([$promotionId, $productId, $promotionRole]);
        }

        $promotionRow = $promotionValidationStmt->fetch(PDO::FETCH_ASSOC);
        if (!$promotionRow) {
            checkoutFail('A promotion in your cart has expired or its product configuration changed. Please review your cart.');
        }
        $requiredPromotionSize = strtolower(trim((string)($promotionRow['promotion_size'] ?? '')));
        if (
            in_array($requiredPromotionSize, ['regular', 'grande'], true) &&
            strtolower($itemSize) !== $requiredPromotionSize
        ) {
            checkoutFail('A promotion item has an incorrect size. Please edit or add the promotion again.');
        }
    } elseif ($promotionRole !== '' || $promotionSlotId > 0) {
        checkoutFail('A promotion item in your cart is invalid. Please remove it and add it again.');
    }

    /* Verify menu bundle flavor picks against the current bundle configuration. */
    $bundlePicks = $cartLine['bundle_picks'] ?? [];
    if ($bundlePicks !== [] && !is_array($bundlePicks)) {
        checkoutFail('A bundle in your cart has invalid flavor selections. Please edit the bundle.');
    }
    if ($promotionId === 0) {
        $bundleConfigStmt = $pdo->prepare("SELECT size, cups, category_ids FROM menu_bundle_options WHERE product_id = ?");
        $bundleConfigStmt->execute([$productId]);
        $bundleConfigs = $bundleConfigStmt->fetchAll(PDO::FETCH_ASSOC);
        $bundleConfig = null;
        foreach ($bundleConfigs as $configRow) {
            if ((string)$configRow['size'] === $itemSize) {
                $bundleConfig = $configRow;
                break;
            }
        }
        if ($bundleConfig) {
            $requiredCups = (int)$bundleConfig['cups'];
            $allowedCategories = array_values(array_filter(array_map('intval', explode(',', (string)$bundleConfig['category_ids']))));
            if ($requiredCups <= 0 || !$allowedCategories || count($bundlePicks) !== $requiredCups) {
                checkoutFail('A bundle in your cart is missing one or more flavor selections. Please edit the bundle.');
            }
            $pickIds = [];
            foreach ($bundlePicks as $pick) {
                $pickId = is_array($pick) ? filter_var($pick['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
                if ($pickId === false) {
                    checkoutFail('A bundle contains an invalid flavor selection. Please edit the bundle.');
                }
                $pickIds[] = (int)$pickId;
            }
            $uniquePickIds = array_values(array_unique($pickIds));
            $pickPlaceholders = implode(',', array_fill(0, count($uniquePickIds), '?'));
            $bundlePickStmt = $pdo->prepare("
                SELECT id, name, category_id, price, regular_price, grande_price, is_available, is_archived
                FROM products
                WHERE id IN ($pickPlaceholders)
            ");
            $bundlePickStmt->execute($uniquePickIds);
            $bundlePickProducts = [];
            foreach ($bundlePickStmt->fetchAll(PDO::FETCH_ASSOC) as $pickProduct) {
                $bundlePickProducts[(int)$pickProduct['id']] = $pickProduct;
            }
            foreach ($pickIds as $index => $pickId) {
                $pickProduct = $bundlePickProducts[$pickId] ?? null;
                $pickSizePrice = $pickProduct
                    ? max((float)$pickProduct['price'], (float)($pickProduct['regular_price'] ?? 0), (float)($pickProduct['grande_price'] ?? 0))
                    : 0;
                if (
                    !$pickProduct ||
                    (int)$pickProduct['is_available'] !== 1 ||
                    (int)$pickProduct['is_archived'] !== 0 ||
                    !in_array((int)$pickProduct['category_id'], $allowedCategories, true) ||
                    $pickSizePrice <= 0
                ) {
                    checkoutFail('One of the selected bundle flavors is no longer available. Please edit the bundle.');
                }
                $bundlePicks[$index]['id'] = $pickId;
                $bundlePicks[$index]['name'] = (string)$pickProduct['name'];
            }
            $_SESSION['cart'][$cartKey]['bundle_picks'] = $bundlePicks;
        } elseif (!empty($bundlePicks)) {
            checkoutFail('The bundle configuration is no longer available. Please remove and add the bundle again.');
        }
    }

    $currentUnitPrice = round($basePrice + $addonsTotal, 2);
    $storedUnitPrice = $cartLine['price'] ?? null;
    if (!is_numeric($storedUnitPrice) || (float)$storedUnitPrice < 0) {
        checkoutFail('A product in your cart has an invalid price. Please remove it and add it again.');
    }
    if (abs((float)$storedUnitPrice - $currentUnitPrice) > 0.009) {
        $_SESSION['cart'][$cartKey]['price'] = $currentUnitPrice;
        $_SESSION['cart'][$cartKey]['name'] = (string)$dbProduct['name'];
        $_SESSION['cart'][$cartKey]['addons'] = $addonNames;
        $cartPriceChanged = true;
    } else {
        $_SESSION['cart'][$cartKey]['price'] = $currentUnitPrice;
        $_SESSION['cart'][$cartKey]['name'] = (string)$dbProduct['name'];
        $_SESSION['cart'][$cartKey]['addons'] = $addonNames;
    }
    $_SESSION['cart'][$cartKey]['quantity'] = (int)$quantity;
    $_SESSION['cart'][$cartKey]['size'] = $itemSize;
    $_SESSION['cart'][$cartKey]['sugar_level'] = trim($itemSugar);
    $_SESSION['cart'][$cartKey]['discount_type'] = $discountType;
}

if ($cartPriceChanged) {
    checkoutFail('A product or add-on price has changed. Your cart has been updated. Please review the new total before placing your order.', true, 409);
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

    if (!is_finite($price) || $price < 0 || $quantity <= 0) {
        checkoutFail('Your cart contains an invalid item. Please review your cart.');
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

/*
 * ================================================================
 * PWD / SENIOR CITIZEN DISCOUNT
 * ================================================================
 *
 * The discount selection is stored on each session-cart line by
 * add-to-cart.php. Only paid quantities are eligible; free
 * promotion quantities are excluded.
 */
$selectedDiscountTypes = [];
$discountEligibleBase = 0.00;

foreach ($_SESSION['cart'] as $cartKey => $item) {

    $discountType = strtolower(
        trim((string)($item['discount_type'] ?? 'none'))
    );

    if (!in_array($discountType, ['none', 'pwd', 'senior'], true)) {
        checkoutFail('A product in your cart has an invalid discount selection. Please review your cart.');
    }

    if ($discountType === 'none') {
        continue;
    }

    $quantity = max(0, (int)($item['quantity'] ?? 0));
    $unitPrice = max(0, (float)($item['price'] ?? 0));

    $freeQuantity = (int)(
        $promotion_free_allocations[(string)$cartKey] ?? 0
    );

    $freeQuantity = max(
        0,
        min($quantity, $freeQuantity)
    );

    $paidQuantity = $quantity - $freeQuantity;

    if ($paidQuantity <= 0) {
        continue;
    }

    $selectedDiscountTypes[$discountType] = true;

    $discountEligibleBase +=
        $unitPrice * $paidQuantity;
}

$discountEligibleBase = round(
    $discountEligibleBase,
    2
);

if (count($selectedDiscountTypes) > 1) {
    checkoutFail(
        "Please use only one discount type per order: PWD or Senior Citizen."
    );
}

$selectedDiscountType =
    !empty($selectedDiscountTypes)
        ? array_key_first($selectedDiscountTypes)
        : 'none';


if ($selectedDiscountType !== 'none' && (!$isRegisteredCustomer || !$customer_id)) {
    checkoutFail('PWD and Senior Citizen discounts are available only to approved customer accounts.');
}

/*
 * Read discount rates from the existing settings table.
 * Keep 20% as the safe default if the setting has not been added yet.
 */
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

    $value = (float)$setting['setting_value'];

    if ($value < 0 || $value > 100) {
        continue;
    }

    if ($setting['setting_key'] === 'pwd_discount_rate') {
        $pwdDiscountRate = $value;
    }

    if ($setting['setting_key'] === 'senior_discount_rate') {
        $seniorDiscountRate = $value;
    }
}

$selectedDiscountRate = 0.00;

if ($selectedDiscountType === 'pwd') {
    $selectedDiscountRate = $pwdDiscountRate;
} elseif ($selectedDiscountType === 'senior') {
    $selectedDiscountRate = $seniorDiscountRate;
}

$customer_discount = round(
    $discountEligibleBase *
    ($selectedDiscountRate / 100),
    2
);

/*
 * Do not stack PWD/Senior with an active promotion.
 * The larger applicable discount is used.
 *
 * When the promotion is selected, its own reward items remain intact.
 * When PWD/Senior is larger, the promotion is removed for this order.
 */
$appliedDiscountType = 'none';
$appliedDiscountRate = 0.00;
$discount_amount = 0.00;

if (
    $selectedDiscountType !== 'none' &&
    $customer_discount > $promotion_discount
) {

    $appliedDiscountType = $selectedDiscountType;
    $appliedDiscountRate = $selectedDiscountRate;
    $discount_amount = $customer_discount;

    $promotion_discount = 0.00;
    $promotion_reward_items = [];
    $promotion_free_allocations = [];
    $promotion_added_reward_value = 0.00;

    $gross_subtotal = $subtotal;
} elseif (
    $selectedDiscountType !== 'none' &&
    $customer_discount > 0 &&
    $promotion_discount <= 0
) {

    $appliedDiscountType = $selectedDiscountType;
    $appliedDiscountRate = $selectedDiscountRate;
    $discount_amount = $customer_discount;
}

/*
 * ================================================================
 * ONLINE ORDER LIMIT — MAXIMUM 10 DRINK UNITS
 * ================================================================
 *
 * Count actual drinks, not cart rows:
 * - Standard drinks: one drink per product quantity.
 * - Sip-and-Snack combos: one drink per combo quantity.
 * - Configured menu bundles: use menu_bundle_options.cups.
 * - Snacks (category 4) and add-ons (category 5): zero drink units.
 * - Added free promotion rewards: count their actual drink quantity too.
 *
 * Promotion free_allocations are already part of the original cart
 * quantities, so they are not added a second time.
 */
$maxOnlineDrinkUnits = 10;
$onlineDrinkUnits = 0;

$bundleDrinkCountStmt = $pdo->prepare("
    SELECT cups
    FROM menu_bundle_options
    WHERE product_id = ?
      AND size = ?
    LIMIT 1
");

foreach ($_SESSION['cart'] as $cartKey => $cartLine) {
    $productId = (int)($cartLine['product_id'] ?? 0);
    $quantity = (int)($cartLine['quantity'] ?? 0);
    $productRow = $productCache[$productId] ?? null;

    if (!$productRow || $productId <= 0 || $quantity <= 0) {
        checkoutFail('Your cart contains an item whose drink quantity could not be verified. Please review your cart.');
    }

    $categoryId = (int)($productRow['category_id'] ?? 0);

    // Category 4 is Snacks and category 5 is Add-ons in this database.
    if (in_array($categoryId, [4, 5], true)) {
        continue;
    }

    $itemSizeForCount = trim((string)($cartLine['size'] ?? ''));
    $bundleDrinkCountStmt->execute([$productId, $itemSizeForCount]);
    $configuredBundleCups = $bundleDrinkCountStmt->fetchColumn();

    if ($configuredBundleCups !== false) {
        $cupsPerBundle = (int)$configuredBundleCups;

        if ($cupsPerBundle <= 0) {
            checkoutFail('A bundle in your cart has an invalid drink configuration. Please remove it and add it again.');
        }

        $onlineDrinkUnits += $cupsPerBundle * $quantity;
    } elseif ($categoryId === 15) {
        // A product in the Bundle category must have a verifiable cup configuration.
        $bundlePicksForCount = $cartLine['bundle_picks'] ?? [];

        if (!is_array($bundlePicksForCount) || count($bundlePicksForCount) === 0) {
            checkoutFail('We could not verify the drink count for a bundle in your cart. Please remove it and add it again.');
        }

        $onlineDrinkUnits += count($bundlePicksForCount) * $quantity;
    } else {
        // Includes one drink per Sip-and-Snack combo.
        $onlineDrinkUnits += $quantity;
    }

    if ($onlineDrinkUnits > $maxOnlineDrinkUnits) {
        checkoutFail(
            'Online orders are limited to 10 drink units per order. Your cart contains '
            . $onlineDrinkUnits
            . ' drink units. Please contact Local Milktea House directly to arrange a bulk order.'
        );
    }
}

/*
 * Added Buy/Get reward products are saved as separate order-item rows,
 * so they must be included in the same 10-drink limit.
 */
if (!empty($promotion_reward_items)) {
    $rewardProductLookupStmt = $pdo->prepare("
        SELECT category_id
        FROM products
        WHERE id = ?
        LIMIT 1
    ");

    foreach ($promotion_reward_items as $rewardItem) {
        if (!is_array($rewardItem)) {
            checkoutFail('A promotional reward could not be verified. Please review your cart.');
        }

        $rewardProductId = filter_var(
            $rewardItem['product_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $rewardQuantity = filter_var(
            $rewardItem['quantity'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if ($rewardProductId === false || $rewardQuantity === false) {
            checkoutFail('A promotional reward has an invalid quantity. Please review your cart.');
        }

        $rewardProductLookupStmt->execute([(int)$rewardProductId]);
        $rewardProductRow = $rewardProductLookupStmt->fetch(PDO::FETCH_ASSOC);

        if (!$rewardProductRow) {
            checkoutFail('A promotional reward product could not be verified. Please review your cart.');
        }

        $rewardCategoryId = (int)($rewardProductRow['category_id'] ?? 0);

        if (in_array($rewardCategoryId, [4, 5], true)) {
            continue;
        }

        $rewardSize = trim((string)($rewardItem['size'] ?? ''));
        $bundleDrinkCountStmt->execute([(int)$rewardProductId, $rewardSize]);
        $rewardBundleCups = $bundleDrinkCountStmt->fetchColumn();

        if ($rewardBundleCups !== false) {
            $cupsPerRewardBundle = (int)$rewardBundleCups;

            if ($cupsPerRewardBundle <= 0) {
                checkoutFail('A promotional bundle has an invalid drink configuration. Please review your cart.');
            }

            $onlineDrinkUnits += $cupsPerRewardBundle * (int)$rewardQuantity;
        } elseif ($rewardCategoryId === 15) {
            $rewardBundlePicks = $rewardItem['bundle_picks'] ?? [];

            if (!is_array($rewardBundlePicks) || count($rewardBundlePicks) === 0) {
                checkoutFail('We could not verify the drink count for a promotional bundle. Please review your cart.');
            }

            $onlineDrinkUnits += count($rewardBundlePicks) * (int)$rewardQuantity;
        } else {
            $onlineDrinkUnits += (int)$rewardQuantity;
        }

        if ($onlineDrinkUnits > $maxOnlineDrinkUnits) {
            checkoutFail(
                'Online orders are limited to 10 drink units per order. Your order, including free promotional drinks, contains '
                . $onlineDrinkUnits
                . ' drink units. Please contact Local Milktea House directly to arrange a bulk order.'
            );
        }
    }
}

if ($onlineDrinkUnits > $maxOnlineDrinkUnits) {
    checkoutFail(
        'Online orders are limited to 10 drink units per order. Your order contains '
        . $onlineDrinkUnits
        . ' drink units. Please contact Local Milktea House directly to arrange a bulk order.'
    );
}

$total_amount = round(
    max(
        0,
        $gross_subtotal
            - $promotion_discount
            - $discount_amount
    ),
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
        !is_array($_FILES['payment_screenshot']) ||
        !isset($_FILES['payment_screenshot']['error']) ||
        (int)$_FILES['payment_screenshot']['error'] !== UPLOAD_ERR_OK
    ) {
        checkoutFail("Please upload your GCash payment screenshot.");
    }

    $file = $_FILES['payment_screenshot'];
    if (
        empty($file['tmp_name']) ||
        !is_uploaded_file($file['tmp_name']) ||
        !isset($file['size']) ||
        (int)$file['size'] <= 0
    ) {
        checkoutFail('The uploaded GCash screenshot is invalid. Please choose the image again.');
    }


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


    if (@getimagesize($file['tmp_name']) === false) {
        checkoutFail('The GCash screenshot is not a valid image file. Please upload a JPG or PNG image.');
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
     * Uploaded files can be created as 0600 (owner-only), which makes Apache
     * answer 403 Forbidden when staff/admin open the screenshot. Make the
     * stored proof readable by the web server.
     */
    @chmod($destination, 0644);


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
 * GCash orders stay in Pending until the payment proof is verified.
 * All cash orders (registered customers and guests) enter the Order Queue,
 * the same place walk-in orders go. Admin then moves them to Confirmed.
 */
if ($payment_method === 'gcash') {
    $initial_status = 'pending_verification';
} else {
    $initial_status = 'order_queue';
}


/*
|--------------------------------------------------------------------------
| DATABASE TRANSACTION
|--------------------------------------------------------------------------
*/

try {

    /*
     * ================================================================
     * RESERVE A PICKUP SLOT SAFELY
     * ================================================================
     * MySQL named locks are connection-scoped and survive transaction
     * commits. Acquire the lock before checking capacity, then keep it until
     * the order has been inserted and committed. This prevents concurrent
     * guest/customer checkouts from both taking the final place in a slot.
     */
    $pickupLockStmt = $pdo->prepare('SELECT GET_LOCK(?, 10)');
    $pickupLockStmt->execute([$pickupCapacityLockName]);
    $pickupLockResult = $pickupLockStmt->fetchColumn();

    if ((string)$pickupLockResult !== '1') {
        checkoutFail('Pickup scheduling is busy right now. Please try placing your order again.', true, 409, 'pickup_scheduling_busy');
    }
    $pickupCapacityLockAcquired = true;

    // Refresh the time only after obtaining the lock; previous validation may be stale.
    $pickupNow = new DateTimeImmutable('now', $manilaTimezone);
    // Preparation time in minutes per payment method (Cash and GCash are both 10).
    $pickupLeadByPayment = ['cash' => 10, 'gcash' => 10];
    $pickupLeadMinutes = $pickupLeadByPayment[$payment_method] ?? 10;
    $earliestPickupSlot = checkoutEarliestPickupSlot($pickupNow, $pickupLeadMinutes);
    $pickupToday = $pickupNow->format('Y-m-d');
    $pickupTomorrow = $pickupNow->modify('+1 day')->format('Y-m-d');

    if ($isPickupNow) {
        $freshMinuteOfDay = ((int)$pickupNow->format('G') * 60) + (int)$pickupNow->format('i');
        if ($freshMinuteOfDay < $openingMinute) {
            checkoutFail('Pick-up Now is available starting at 10:00 AM. Please choose Pick-up Later.', false, 422, 'pickup_now_unavailable');
        }

        // Pick-up Now must be fulfilled today; it cannot silently roll into tomorrow.
        if (
            $earliestPickupSlot->format('Y-m-d') !== $pickupToday ||
            (((int)$earliestPickupSlot->format('G') * 60) + (int)$earliestPickupSlot->format('i')) > $lastPickupMinute
        ) {
            checkoutFail('No pickup slots remain today with enough preparation time. Please choose Pick-up Later for tomorrow.', true, 409, 'pickup_no_slots_today');
        }

        $pickup_date = $pickupToday;
    } else {
        // Recheck the today/tomorrow window using the fresh server time.
        if ($pickup_date < $pickupToday || $pickup_date > $pickupTomorrow) {
            checkoutFail('Advance pick-up is available for today or tomorrow only. Please choose a date within this booking period.', true, 409, 'pickup_date_window');
        }

        if ($selectedPickupDateTime === null || $selectedPickupDateTime < $earliestPickupSlot) {
            if ($earliestPickupSlot->format('Y-m-d') > $pickupTomorrow) {
                checkoutFail('No valid pickup slots remain within the today-or-tomorrow booking window. Please contact the store for assistance.', true, 409, 'pickup_no_slots_today');
            }

            checkoutFail(
                'The selected pickup time does not allow enough preparation time for this payment method. The earliest eligible slot is ' .
                $earliestPickupSlot->format('g:i A') . ' on ' . $earliestPickupSlot->format('M j, Y') . '. Please select that time or a later slot.',
                true,
                409,
                'pickup_lead_time'
            );
        }
    }

    $pdo->beginTransaction();

    $pickupSlotCountStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM orders
        WHERE order_source = 'online'
          AND COALESCE(status, '') <> 'cancelled'
          AND pickup_date = ?
          AND pickup_time = ?
    ");
    $maximumOnlineOrdersPerSlot = 5;

    if ($isPickupNow) {
        $candidateSlot = $earliestPickupSlot;
        $lastSlotToday = $pickupNow->setTime(21, 30, 0);
        $slotAssigned = false;

        while ($candidateSlot->format('Y-m-d') === $pickupToday && $candidateSlot <= $lastSlotToday) {
            $candidateDate = $candidateSlot->format('Y-m-d');
            $candidateTime = $candidateSlot->format('H:i:s');

            $pickupSlotCountStmt->execute([$candidateDate, $candidateTime]);
            $existingSlotOrders = (int)$pickupSlotCountStmt->fetchColumn();

            if ($existingSlotOrders < $maximumOnlineOrdersPerSlot) {
                $pickup_date = $candidateDate;
                $pickup_time = $candidateTime;
                $slotAssigned = true;
                break;
            }

            $candidateSlot = $candidateSlot->modify('+15 minutes');
        }

        if (!$slotAssigned) {
            checkoutFail('No pickup slots remain today. Please choose Pick-up Later for tomorrow.', true, 409, 'pickup_no_slots_today');
        }
    } else {
        $pickupSlotCountStmt->execute([$pickup_date, $pickup_time]);
        $existingSlotOrders = (int)$pickupSlotCountStmt->fetchColumn();

        if ($existingSlotOrders >= $maximumOnlineOrdersPerSlot) {
            checkoutFail('This pickup slot is already full. Please choose another available 15-minute slot.', true, 409, 'pickup_slot_full');
        }
    }

    /*
     * ================================================================
     * ENFORCE ONE-TIME PWD / SENIOR DISCOUNT USE
     * ================================================================
     * The discount is consumed only when it is actually applied to this
     * order. Lock the customer's row to prevent two simultaneous checkouts
     * from both passing the previous-use check.
     * A successfully inserted discounted order is the permanent record
     * that the discount has been used; a rolled-back checkout does not count.
     */
    if ($appliedDiscountType !== 'none') {
        if (!$isRegisteredCustomer || !$customer_id) {
            checkoutFail('PWD and Senior Citizen discounts are available only to approved customer accounts.');
        }
        $verifiedDiscountIdName = '';
        $verifiedDiscountIdNumber = '';

        $lockedCustomerStmt = $pdo->prepare("
            SELECT discount_type, verification_status, discount_id_name, discount_id_number
            FROM customers
            WHERE id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $lockedCustomerStmt->execute([$customer_id]);
        $lockedCustomer = $lockedCustomerStmt->fetch(PDO::FETCH_ASSOC);

        $lockedType = strtolower(trim((string)($lockedCustomer['discount_type'] ?? '')));
        $lockedStatus = strtolower(trim((string)($lockedCustomer['verification_status'] ?? '')));
        $lockedIdName = trim((string)($lockedCustomer['discount_id_name'] ?? ''));
        $lockedIdNumber = strtoupper(trim((string)($lockedCustomer['discount_id_number'] ?? '')));

        if (
            !$lockedCustomer ||
            $lockedStatus !== 'verified' ||
            $lockedType !== $appliedDiscountType ||
            $lockedIdName === '' ||
            $lockedIdNumber === ''
        ) {
            checkoutFail('Your PWD/Senior Citizen ID must be approved and match your account before using the discount.');
        }


        /* The ID name and number must both match the verified account record. */
        $cartDiscountIdName = '';
        $cartDiscountIdNumber = '';
        foreach ($_SESSION['cart'] as $discountCartItem) {
            if (strtolower(trim((string)($discountCartItem['discount_type'] ?? 'none'))) === $appliedDiscountType) {
                $cartDiscountIdName = preg_replace('/\s+/u', ' ', trim((string)($discountCartItem['discount_id_name'] ?? '')));
                $cartDiscountIdNumber = strtoupper(trim((string)($discountCartItem['discount_id_number'] ?? '')));
                break;
            }
        }
        $normalizedApprovedName = preg_replace('/\s+/u', ' ', trim($lockedIdName));
        $cartDiscountIdNameCompare = function_exists('mb_strtolower')
            ? mb_strtolower($cartDiscountIdName, 'UTF-8')
            : strtolower($cartDiscountIdName);
        $approvedDiscountIdNameCompare = function_exists('mb_strtolower')
            ? mb_strtolower($normalizedApprovedName, 'UTF-8')
            : strtolower($normalizedApprovedName);
        if (
            $cartDiscountIdName === '' ||
            $cartDiscountIdNameCompare !== $approvedDiscountIdNameCompare
        ) {
            checkoutFail('The name entered for the discount does not match the approved ID on your account.');
        }

        // Confirm the cart's submitted ID corresponds to the approved ID on this account.
        $cartDiscountIdNumber = '';
        foreach ($_SESSION['cart'] as $discountCartItem) {
            $lineType = strtolower(trim((string)($discountCartItem['discount_type'] ?? 'none')));
            if ($lineType === $appliedDiscountType) {
                $cartDiscountIdNumber = strtoupper(trim((string)($discountCartItem['discount_id_number'] ?? '')));
                break;
            }
        }

        if ($cartDiscountIdNumber === '' || !hash_equals($lockedIdNumber, $cartDiscountIdNumber)) {
            checkoutFail('The discount ID in your cart does not match the approved ID on your account.');
        }

        $verifiedDiscountIdName = $lockedIdName;
        $verifiedDiscountIdNumber = $lockedIdNumber;

        // Any prior order where this discount was actually applied consumes it,
        // regardless of that order's current status.
        $previousUseStmt = $pdo->prepare("
            SELECT id
            FROM orders
            WHERE customer_id = ?
              AND LOWER(COALESCE(discount_type, 'none')) IN ('pwd', 'senior')
              AND COALESCE(discount_amount, 0) > 0
            LIMIT 1
        ");
        $previousUseStmt->execute([$customer_id]);

        if ($previousUseStmt->fetchColumn()) {
            checkoutFail('Your one-time PWD/Senior Citizen discount has already been used on a previous order.');
        }
    }


    /*
     * Secure guest tracking credential.
     * The raw random token is sent only in the post-checkout tracking URL;
     * only its SHA-256 hash is persisted. Registered-customer orders do not
     * need a guest tracking token.
     */
    $guestTrackingToken = null;
    $guestTrackingTokenHash = null;

    if (!$isRegisteredCustomer) {
        $guestTrackingToken = bin2hex(random_bytes(32)); // 256 bits of entropy
        $guestTrackingTokenHash = hash('sha256', $guestTrackingToken);
    }

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
            order_source,
            guest_tracking_token_hash,
            customer_id,
            customer_name,
            contact_number,
            pickup_date,
            pickup_time,
            payment_method,
            payment_screenshot,
            subtotal,
            discount_type,
            discount_rate,
            discount_amount,
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
        'online',
        $guestTrackingTokenHash,
        $customer_id,
        $customer_name,
        $customer_phone,
        $pickup_date,
        $pickup_time,
        $payment_method,
        $payment_screenshot,
        $gross_subtotal,
        $appliedDiscountType,
        $appliedDiscountRate,
        $discount_amount,
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
 * INSERT ONLINE PAYMENT RECORD
 * ================================================================
 *
 * Online payments are initially unverified.
 *
 * Cash:
 *   Payment will be verified when received at pick-up.
 *
 * GCash:
 *   Payment will be verified after Admin or Staff checks the proof.
 *
 * The payment proof uses the same uploaded file path saved in orders.
 */

$paymentStmt = $pdo->prepare("
    INSERT INTO payments
    (
        order_id,
        payment_method,
        amount,
        proof_image,
        is_verified,
        verified_at,
        verified_by
    )
    VALUES (?, ?, ?, ?, 0, NULL, NULL)
");

$paymentStmt->execute([
    $order_id,
    $payment_method,
    $total_amount,
    $payment_screenshot
]);


    /*
     * ================================================================
     * SAVE PWD / SENIOR CITIZEN ID DETAILS
     * ================================================================
     *
     * The customer typed the name and ID number on the product page.
     * They are saved in orders.notes (read by the admin and staff
     * receipt) and, when the optional columns exist, in
     * discount_id_name / discount_id_number.
     */

    if ($appliedDiscountType !== 'none') {

        $discountIdName = '';
        $discountIdNumber = '';

        foreach ($_SESSION['cart'] as $discountCartItem) {

            $lineDiscountType = strtolower(
                trim((string)($discountCartItem['discount_type'] ?? 'none'))
            );

            if (
                $lineDiscountType === $appliedDiscountType &&
                !empty($discountCartItem['discount_id_number'])
            ) {
                $discountIdName = trim((string)($discountCartItem['discount_id_name'] ?? ''));
                $discountIdNumber = trim((string)$discountCartItem['discount_id_number']);
                break;
            }
        }

        if (!empty($verifiedDiscountIdName) && !empty($verifiedDiscountIdNumber)) {

            $discountIdName = $verifiedDiscountIdName;
            $discountIdNumber = $verifiedDiscountIdNumber;

            $discountNote =
                strtoupper($appliedDiscountType) .
                ' ID: ' . $discountIdName . ' / ' . $discountIdNumber;

            $pdo->prepare("
                UPDATE orders
                SET notes = CASE
                    WHEN notes IS NULL OR notes = '' THEN ?
                    ELSE CONCAT(notes, ' | ', ?)
                END
                WHERE id = ?
            ")->execute([$discountNote, $discountNote, $order_id]);

            try {
                $colCheck = $pdo->query("SHOW COLUMNS FROM orders LIKE 'discount_id_number'");

                if ($colCheck && $colCheck->fetch()) {
                    $pdo->prepare("
                        UPDATE orders
                        SET discount_id_name = ?, discount_id_number = ?
                        WHERE id = ?
                    ")->execute([$discountIdName, $discountIdNumber, $order_id]);
                }
            } catch (Throwable $discountColumnError) {
                error_log('Checkout discount ID columns skipped: ' . $discountColumnError->getMessage());
            }
        }
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
            sugar_level,
            discount_type,
            discount_rate,
            discount_amount
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
            $product_id === null ||
            $quantity <= 0 ||
            $product_name === '' ||
            !is_finite($unit_price) ||
            $unit_price < 0
        ) {
            checkoutFail('An invalid product was found while saving your order. No order was placed.');
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

        $item_discount_type = strtolower(
            trim((string)($item['discount_type'] ?? 'none'))
        );

        if (!in_array($item_discount_type, ['none', 'pwd', 'senior'], true)) {
            $item_discount_type = 'none';
        }

        $item_discount_rate = 0.00;
        $item_discount_amount = 0.00;

        if (
            $appliedDiscountType !== 'none' &&
            $item_discount_type === $appliedDiscountType &&
            $item_subtotal > 0
        ) {
            $item_discount_rate = $appliedDiscountRate;

            $item_discount_amount = round(
                $item_subtotal *
                ($item_discount_rate / 100),
                2
            );
        }


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

        /*
         * Menu bundle flavors are saved together with the add-ons so the
         * staff (and the receipt) can see what to prepare for each cup.
         */
        if (!empty($item['bundle_picks']) && is_array($item['bundle_picks'])) {
            $bundleCupLines = [];
            $bundleCupNo = 1;

            foreach ($item['bundle_picks'] as $bundlePick) {
                $bundleCupLines[] = 'Cup ' . $bundleCupNo . ': ' .
                    trim((string)($bundlePick['name'] ?? ''));
                $bundleCupNo++;
            }

            $existingItemAddons = [];

            if (is_array($item_addons)) {
                $existingItemAddons = $item_addons;
            } elseif ($item_addons !== null && trim((string)$item_addons) !== '') {
                $existingItemAddons = [trim((string)$item_addons)];
            }

            $item_addons = array_merge($bundleCupLines, $existingItemAddons);
        }

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
            $item_sugar_level !== '' ? $item_sugar_level : null,
            $item_discount_type,
            $item_discount_rate,
            $item_discount_amount
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
            !is_finite($rewardUnitPrice) ||
            $rewardUnitPrice < 0
        ) {
            checkoutFail('A promotion reward is invalid. Please review the promotion and try again.');
        }

        $rewardAvailabilityStmt = $pdo->prepare("
            SELECT is_available, is_archived
            FROM products
            WHERE id = ?
            LIMIT 1
        ");
        $rewardAvailabilityStmt->execute([$rewardProductId]);
        $rewardAvailability = $rewardAvailabilityStmt->fetch(PDO::FETCH_ASSOC);
        if (
            !$rewardAvailability ||
            (int)$rewardAvailability['is_available'] !== 1 ||
            (int)$rewardAvailability['is_archived'] !== 0
        ) {
            checkoutFail('A free product in the promotion is no longer available. Please review your order.');
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
            $rewardSugar !== '' ? $rewardSugar : null,
            'none',
            0.00,
            0.00
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

    // The slot is now committed and visible to the next checkout request.
    releasePickupCapacityLock();

    unset($_SESSION['checkout_request_token']);

    /*
     * Remember every guest order in this server-side session.
     * This is a session-scoped order list, not a permanent guest history.
     * The raw tokens are held only server-side so the guest can copy a
     * secure link later; the database stores only each token's SHA-256 hash.
     */
    if (!$isRegisteredCustomer && $guestTrackingToken !== null) {
        $guestOrders = $_SESSION['guest_orders'] ?? [];
        if (!is_array($guestOrders)) {
            $guestOrders = [];
        }

        $nowForGuestOrders = time();
        $guestOrderTtl = 30 * 24 * 60 * 60;

        // Migrate the previous single-order session entry so this checkout
        // doesn't discard an earlier guest order when a second order is placed.
        $legacyId = filter_var($_SESSION['last_guest_order_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $legacyClaim = trim((string)($_SESSION['last_guest_claim_number'] ?? ''));
        $legacyToken = strtolower(trim((string)($_SESSION['last_guest_tracking_token'] ?? '')));
        $legacySavedAt = filter_var($_SESSION['last_guest_order_saved_at'] ?? null, FILTER_VALIDATE_INT);
        if (
            $legacyId !== false && $legacyClaim !== '' &&
            preg_match('/\A[a-f0-9]{64}\z/D', $legacyToken) === 1 &&
            $legacySavedAt !== false && $legacySavedAt <= $nowForGuestOrders &&
            ($nowForGuestOrders - $legacySavedAt) <= $guestOrderTtl
        ) {
            $legacyKey = (string)(int)$legacyId;
            if (!isset($guestOrders[$legacyKey])) {
                $guestOrders[$legacyKey] = [
                    'claim_number' => $legacyClaim,
                    'tracking_token' => $legacyToken,
                    'saved_at' => $legacySavedAt,
                ];
            }
        }

        // Discard malformed or expired session entries without touching database orders.
        foreach ($guestOrders as $savedOrderId => $savedOrder) {
            $savedId = filter_var((string)$savedOrderId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $savedAt = is_array($savedOrder)
                ? filter_var($savedOrder['saved_at'] ?? null, FILTER_VALIDATE_INT)
                : false;
            $savedToken = is_array($savedOrder)
                ? strtolower(trim((string)($savedOrder['tracking_token'] ?? '')))
                : '';
            $savedClaim = is_array($savedOrder)
                ? trim((string)($savedOrder['claim_number'] ?? ''))
                : '';

            if (
                $savedId === false ||
                $savedAt === false ||
                $savedAt > $nowForGuestOrders ||
                ($nowForGuestOrders - $savedAt) > $guestOrderTtl ||
                $savedClaim === '' ||
                preg_match('/\A[a-f0-9]{64}\z/D', $savedToken) !== 1
            ) {
                unset($guestOrders[$savedOrderId]);
            }
        }

        $guestOrders[(string)(int)$order_id] = [
            'claim_number' => (string)$claim_number,
            'tracking_token' => (string)$guestTrackingToken,
            'saved_at' => $nowForGuestOrders,
        ];

        $_SESSION['guest_orders'] = $guestOrders;

        // Retain the previous single-order keys for safe migration of existing browser sessions.
        $_SESSION['last_guest_order_id'] = (int)$order_id;
        $_SESSION['last_guest_claim_number'] = (string)$claim_number;
        $_SESSION['last_guest_tracking_token'] = (string)$guestTrackingToken;
        $_SESSION['last_guest_order_saved_at'] = $nowForGuestOrders;
    } elseif ($isRegisteredCustomer) {
        // Do not surface guest orders while this session is authenticated as a customer.
        unset(
            $_SESSION['last_guest_order_id'],
            $_SESSION['last_guest_claim_number'],
            $_SESSION['last_guest_tracking_token'],
            $_SESSION['last_guest_order_saved_at']
        );
    }


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
     * After checkout, send the customer directly to the order-monitoring
     * page instead of a separate receipt/confirmation page.
     *
     * Registered customers go to their customer dashboard, which is the
     * Monitor Order page for logged-in customers.
     *
     * Guests go directly to the guest Monitor Order page with the newly
     * generated Claim Number so the order is shown immediately.
     */

    if (!$isRegisteredCustomer) {

        $successRedirect =
            "monitor-guest-order.php?claim=" .
            urlencode($claim_number) .
            "&track=" .
            urlencode((string)$guestTrackingToken);

    } else {

        $successRedirect = "dashboard.php";
    }

    if ($isAjaxRequest) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'message' => 'Your order has been placed successfully.',
            'order_number' => $order_number,
            'claim_number' => $claim_number,
            'pickup_type' => $pickup_type,
            'pickup_date' => $pickup_date,
            'pickup_time' => $pickup_time,
            'pickup_time_label' => (new DateTimeImmutable($pickup_date . ' ' . $pickup_time, $manilaTimezone))->format('g:i A'),
            'pickup_date_label' => (new DateTimeImmutable($pickup_date . ' ' . $pickup_time, $manilaTimezone))->format('F j, Y'),
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

    // Release the named lock on every exception path as well.
    releasePickupCapacityLock();

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


    /* Keep the real reason in the server log only. */
    error_log('Checkout failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

    http_response_code(500);

    if ($isAjaxRequest) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Unable to place your order right now. Please try again.'
        ]);
        exit;
    }

    checkoutRenderErrorPage('Something went wrong while placing your order. Please try again, or contact Local Milktea House if the problem continues.');
    exit;
}

?>