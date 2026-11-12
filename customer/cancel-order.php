<?php

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

if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['user_role'] ?? '') !== 'customer'
) {
    customerRedirect('../auth/login.php');
}

$user_id = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    customerRedirect('dashboard.php');
}

$order_id = (int) ($_POST['order_id'] ?? 0);
$selectedCancellationReason = trim((string) ($_POST['cancellation_reason'] ?? ''));
$otherCancellationReason = trim((string) ($_POST['cancellation_reason_other'] ?? ''));

if ($order_id <= 0) {
    customerRedirect('dashboard.php');
}

$allowed_reasons = [
    'Changed my mind',
    'Ordered by mistake',
    'Pickup time is no longer convenient',
    'Wrong order details',
    'Other'
];

if (!in_array($selectedCancellationReason, $allowed_reasons, true)) {
    customerRedirect('dashboard.php');
}

/*
 * When Other is selected, require the customer's explanation and store it
 * along with the selected reason. The existing column is VARCHAR(255), so
 * keep the explanation within 220 characters after the "Other: " prefix.
 */
if ($selectedCancellationReason === 'Other') {
    $otherReasonLength = preg_match_all('/./us', $otherCancellationReason, $unusedMatches);
    if (
        $otherCancellationReason === '' ||
        $otherReasonLength === false ||
        $otherReasonLength > 220
    ) {
        customerRedirect('dashboard.php');
    }

    $cancellation_reason = 'Other: ' . $otherCancellationReason;
} else {
    $cancellation_reason = $selectedCancellationReason;
}

/*
 * Customer cancellation is allowed only before preparation begins:
 * - Pending Verification
 * - Awaiting Store Confirmation (order_queue)
 * - Confirmed
 *
 * Keep the status condition in the UPDATE so a request cannot cancel an order
 * if staff moves it to Preparing between the page render and this request.
 */
$stmt = $pdo->prepare("
    UPDATE orders
    SET
        status = 'cancelled',
        cancellation_reason = ?,
        closed_at = NOW()
    WHERE id = ?
      AND customer_id = ?
      AND status IN ('pending_verification', 'order_queue', 'confirmed')");

$stmt->execute([
    $cancellation_reason,
    $order_id,
    $user_id
]);

/* If no row was updated, the order is not owned by this customer or has moved
   to a status where customer cancellation is not allowed. */
if ($stmt->rowCount() === 0) {
    customerRedirect('dashboard.php');
}

/* Get order information for notifications. */
$orderStmt = $pdo->prepare("
    SELECT order_number, claim_number
    FROM orders
    WHERE id = ?
      AND customer_id = ?
    LIMIT 1");

$orderStmt->execute([
    $order_id,
    $user_id
]);

$order = $orderStmt->fetch(PDO::FETCH_ASSOC);

$orderIdentifier = !empty($order['order_number'])
    ? $order['order_number']
    : ($order['claim_number'] ?? 'Order');

/* STAFF NOTIFICATION */
$staffNotificationStmt = $pdo->prepare("
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
    )");

$staffNotificationStmt->execute([
    'customer_cancelled_order',
    "Customer cancelled {$orderIdentifier}. Reason: {$cancellation_reason}.",
    $order_id
]);

/* CUSTOMER NOTIFICATION */
$customerNotificationStmt = $pdo->prepare("
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
        'customer',
        ?,
        ?,
        ?,
        ?
    )");

$customerNotificationStmt->execute([
    $user_id,
    'order_cancelled',
    "Your order {$orderIdentifier} has been cancelled. Reason: {$cancellation_reason}.",
    $order_id
]);

if ($isAjaxRequest) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'message' => 'Your order has been cancelled successfully.',
        'redirect' => 'dashboard.php'
    ]);
    exit;
}

header('Location: dashboard.php');
exit;
