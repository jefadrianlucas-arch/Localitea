<?php
session_start();

$isAjaxRequest =
    ($_POST['ajax'] ?? $_GET['ajax'] ?? '') === '1' ||
    strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

/* POST requests (the cart page buttons) must carry the session CSRF token. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';
    if (
        !is_string($postedToken) ||
        empty($_SESSION['csrf_token']) ||
        !hash_equals((string)$_SESSION['csrf_token'], $postedToken)
    ) {
        if ($isAjaxRequest) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'Your session expired. Please refresh the cart and try again.',
                'redirect' => 'cart.php'
            ]);
            exit;
        }
        header('Location: cart.php');
        exit;
    }
}

$key = trim((string)($_POST['key'] ?? $_GET['key'] ?? ''));
$removed = false;
$message = 'The selected cart item could not be found.';

if ($key !== '' && isset($_SESSION['cart'][$key]) && is_array($_SESSION['cart'][$key])) {
    $line = $_SESSION['cart'][$key];
    $lineQuantity = (int)($line['quantity'] ?? 1);

    /* Promotion and bundle lines are removed as a whole so free items stay consistent. */
    $isLockedLine =
        (int)($line['promotion_source_id'] ?? 0) > 0 ||
        !empty($line['bundle_picks']);

    if (!$isLockedLine && $lineQuantity > 1) {
        $_SESSION['cart'][$key]['quantity'] = $lineQuantity - 1;   // remove ONE cup
        $message = 'One item removed from your cart.';
    } else {
        unset($_SESSION['cart'][$key]);                            // last cup or promo/bundle line
        $message = 'Item removed from your cart.';
    }

    $removed = true;
}

if (empty($_SESSION['cart'])) {
    unset($_SESSION['cart'], $_SESSION['selected_promotion_id']);
}

$cartCount = 0;
if (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        $cartCount += max(0, (int)($item['quantity'] ?? 0));
    }
}

if ($isAjaxRequest) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => $removed,
        'message' => $message,
        'cart_count' => $cartCount,
        'redirect' => 'cart.php'
    ]);
    exit;
}

header('Location: cart.php');
exit;
?>