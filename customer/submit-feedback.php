<?php

require_once '../includes/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json; charset=utf-8');

function feedbackResponse(bool $ok, string $message, int $code = 200, array $extra = []): void
{
    http_response_code($code);
    echo json_encode(array_merge([
        'success' => $ok,
        'message' => $message,
    ], $extra));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    feedbackResponse(false, 'Invalid request.', 405);
}

$orderId = (int)($_POST['order_id'] ?? 0);
$claim   = substr(trim((string)($_POST['claim_number'] ?? '')), 0, 50);
$message = trim((string)($_POST['message'] ?? ''));
$message = preg_replace("/\r\n?/", "\n", $message);

if ($orderId <= 0) {
    feedbackResponse(false, 'Invalid order.', 422);
}

if ($message === '') {
    feedbackResponse(false, 'Please write your feedback first.', 422);
}

if (mb_strlen($message, 'UTF-8') > 500) {
    feedbackResponse(false, 'Feedback must be 500 characters or less.', 422);
}

$stmt = $pdo->prepare("
    SELECT id, customer_id, claim_number, status
    FROM orders
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$orderId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    feedbackResponse(false, 'Order not found.', 404);
}

if ((string)$order['status'] !== 'completed') {
    feedbackResponse(false, 'You can only leave feedback for completed orders.', 403);
}

$customerId = null;

if ($order['customer_id'] === null) {
    /* Guest order: the claim number must match. */
    if ($claim === '' || !hash_equals((string)$order['claim_number'], $claim)) {
        feedbackResponse(false, 'Order verification failed.', 403);
    }
} else {
    /* Registered customer: must be the owner of the order. */
    if (
        !isset($_SESSION['user_id'])
        || ($_SESSION['user_role'] ?? '') !== 'customer'
        || (int)$_SESSION['user_id'] !== (int)$order['customer_id']
    ) {
        feedbackResponse(false, 'You are not allowed to leave feedback for this order.', 403);
    }
    $customerId = (int)$order['customer_id'];
}

$createdAt = date('Y-m-d H:i:s');

try {
    $insert = $pdo->prepare("
        INSERT INTO order_feedback (order_id, customer_id, message, created_at)
        VALUES (?, ?, ?, ?)
    ");
    $saved = $insert->execute([$orderId, $customerId, $message, $createdAt]);

    if (!$saved) {
        feedbackResponse(false, 'Could not save your feedback. Please try again.', 500);
    }
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        feedbackResponse(false, 'You already sent feedback for this order.', 409);
    }

    error_log('submit-feedback.php: ' . $e->getMessage());
    feedbackResponse(false, 'Could not save your feedback. Please try again.', 500);
}

feedbackResponse(true, 'Thank you for your feedback!', 200, [
    'feedback' => [
        'message'    => $message,
        'created_at' => date('M j, Y g:i A', strtotime($createdAt)),
    ],
]);