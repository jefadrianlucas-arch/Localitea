<?php
session_start();

$isAjaxRequest =
    ($_POST['ajax'] ?? $_GET['ajax'] ?? '') === '1' ||
    strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

$key = trim((string)($_GET['key'] ?? ''));
$removed = false;

if ($key !== '' && isset($_SESSION['cart'][$key])) {
    unset($_SESSION['cart'][$key]);
    $removed = true;
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
        'message' => $removed ? 'Item removed from your cart.' : 'The selected cart item could not be found.',
        'cart_count' => $cartCount,
        'redirect' => 'cart.php'
    ]);
    exit;
}

header('Location: cart.php');
exit;
?>