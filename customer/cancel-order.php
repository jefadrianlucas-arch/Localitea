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

$user_id = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    customerRedirect('dashboard.php');
}

$order_id = (int)($_POST['order_id'] ?? 0);
$cancellation_reason = trim($_POST['cancellation_reason'] ?? '');

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

if (!in_array($cancellation_reason, $allowed_reasons, true)) {
    customerRedirect('dashboard.php');
}

/*
 * Cancel ONLY when the order is still:
 * - Pending Verification
 * - Confirmed
 */
$stmt = $pdo->prepare("
    UPDATE orders
    SET
        status = 'cancelled',
        cancellation_reason = ?,
        closed_at = NOW()
    WHERE id = ?
      AND customer_id = ?
      AND status IN ('pending_verification', 'confirmed')
");

$stmt->execute([
    $cancellation_reason,
    $order_id,
    $user_id
]);

/*
 * If no order was updated, the order is already in a
 * status where customer cancellation is not allowed.
 */
if ($stmt->rowCount() === 0) {
    customerRedirect('dashboard.php');
}

/*
 * Get order information.
 */
$orderStmt = $pdo->prepare("
    SELECT order_number, claim_number
    FROM orders
    WHERE id = ?
      AND customer_id = ?
    LIMIT 1
");

$orderStmt->execute([
    $order_id,
    $user_id
]);

$order = $orderStmt->fetch(PDO::FETCH_ASSOC);

$orderIdentifier = !empty($order['order_number'])
    ? $order['order_number']
    : ($order['claim_number'] ?? 'Order');


/*
 * STAFF NOTIFICATION
 */
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
    )
");

$staffNotificationStmt->execute([
    'customer_cancelled_order',
    "Customer cancelled {$orderIdentifier}. Reason: {$cancellation_reason}.",
    $order_id
]);


/*
 * CUSTOMER NOTIFICATION
 */
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
    )
");

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