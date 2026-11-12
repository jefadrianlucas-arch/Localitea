<?php
/*
 * Saves a customer's feedback for one of their COMPLETED orders.
 * Called by the "Send Feedback" form inside the Order History
 * "View Details" modal on customer/dashboard.php (AJAX, JSON reply).
 * One feedback per order (enforced by the database UNIQUE key).
 */
require_once '../includes/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

date_default_timezone_set('Asia/Manila');

$wantsJson = stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;

function feedbackReply(bool $success, string $message, array $extra = []): void
{
    global $wantsJson;

    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
        exit;
    }

    /* Plain form post (JavaScript off): go back to Order History. */
    header('Location: dashboard.php?view=history');
    exit;
}

/* Customers only. */
$customerId = filter_var(
    $_SESSION['user_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if (($_SESSION['user_role'] ?? '') !== 'customer' || $customerId === false) {
    feedbackReply(false, 'Please log in again to send feedback.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    feedbackReply(false, 'Invalid request.');
}

/* Reject posts sent from other websites. */
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '');
$host = (string)($_SERVER['HTTP_HOST'] ?? '');

if ($origin !== '') {
    $originHost = (string)parse_url($origin, PHP_URL_HOST);
    $originPort = parse_url($origin, PHP_URL_PORT);

    if ($originPort) {
        $originHost .= ':' . $originPort;
    }

    if (strcasecmp($originHost, $host) !== 0) {
        feedbackReply(false, 'Invalid request.');
    }
}

$orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$message = trim(str_replace("\r\n", "\n", (string)($_POST['message'] ?? '')));

if ($orderId === false) {
    feedbackReply(false, 'Invalid order.');
}

if ($message === '') {
    feedbackReply(false, 'Please write your feedback first.');
}

if (mb_strlen($message) > 500) {
    feedbackReply(false, 'Feedback must be 500 characters or less.');
}

try {
    /* The order must belong to this customer and be completed. */
    $orderStmt = $pdo->prepare("
        SELECT id
        FROM orders
        WHERE id = ?
          AND customer_id = ?
          AND status = 'completed'
        LIMIT 1
    ");
    $orderStmt->execute([$orderId, $customerId]);

    if (!$orderStmt->fetch()) {
        feedbackReply(false, 'You can only give feedback on your own completed orders.');
    }

    $insertStmt = $pdo->prepare("
        INSERT INTO order_feedback (order_id, customer_id, message)
        VALUES (?, ?, ?)
    ");
    $insertStmt->execute([$orderId, $customerId, $message]);

    feedbackReply(true, 'Thank you for your feedback!', [
        'feedback' => [
            'message' => $message,
            'created_at' => date('M j, Y g:i A'),
        ],
    ]);
} catch (PDOException $e) {
    /* 23000 = duplicate: feedback for this order already exists. */
    if ($e->getCode() === '23000') {
        feedbackReply(false, 'You already sent feedback for this order.');
    }

    error_log('submit-feedback.php: ' . $e->getMessage());
    feedbackReply(false, 'Could not send your feedback. Please try again.');
}
