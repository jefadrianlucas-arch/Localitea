<?php
session_start();
date_default_timezone_set('Asia/Manila');

require_once '../includes/db.php';

$token = trim((string)($_GET['token'] ?? ''));
$sessionToken = trim((string)($_SESSION['guest_confirmation_token'] ?? ''));
$orderId = (int)($_SESSION['guest_last_order_id'] ?? 0);

if ($token === '' || $sessionToken === '' || !hash_equals($sessionToken, $token) || $orderId <= 0) {
    header('Location: menu.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT id, order_number, claim_number, customer_name, contact_number, pickup_date, pickup_time, payment_method, total_amount, status
    FROM orders
    WHERE id = ? AND customer_id IS NULL
    LIMIT 1
");
$stmt->execute([$orderId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    header('Location: menu.php');
    exit;
}

$statusLabels = [
    'pending_confirmation' => 'Pending Confirmation',
    'pending_verification' => 'Pending Verification',
    'confirmed' => 'Confirmed',
    'preparing' => 'Preparing',
    'ready' => 'Ready for Pick-up',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
];

$statusLabel = $statusLabels[$order['status']] ?? ucfirst(str_replace('_', ' ', $order['status']));

// The one-time confirmation token is intentionally not displayed as a reusable code.
unset($_SESSION['guest_confirmation_token']);
unset($_SESSION['guest_last_order_id']);

require_once '../includes/header.php';
require_once '../includes/navbar.php';
?>

<style>
    body { background:#FDFBF7; }
    .guest-confirm-wrap { max-width:760px; margin:0 auto; padding:56px 16px; }
    .guest-confirm-card { background:#fff; border:1px solid #E6DEC9; border-radius:20px; box-shadow:0 8px 24px rgba(44,34,30,.07); padding:32px; }
    .guest-confirm-icon { width:64px; height:64px; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 18px; background:#F4EBDD; color:#4A3525; font-size:1.7rem; }
    .order-code { background:#F8F3EC; border:1px solid #DCCAB7; border-radius:12px; padding:16px; }
    .order-code strong { color:#4A3525; font-size:1.1rem; letter-spacing:.3px; }
    .detail-label { color:#8A7A6C; font-size:.72rem; text-transform:uppercase; font-weight:800; letter-spacing:.6px; }
    .detail-value { color:#2C221E; font-weight:700; }
    .status-pill { display:inline-flex; padding:7px 12px; border-radius:999px; background:#EEF5FC; color:#496F93; font-weight:800; font-size:.8rem; }
    .btn-brown { background:#4A3525; border-color:#4A3525; color:#fff; border-radius:999px; font-weight:700; padding:11px 18px; }
    .btn-brown:hover { background:#342317; border-color:#342317; color:#fff; }
    @media (max-width:576px) { .guest-confirm-wrap{padding:34px 12px;} .guest-confirm-card{padding:22px;} }
</style>

<main class="guest-confirm-wrap">
    <section class="guest-confirm-card">
        <div class="guest-confirm-icon"><i class="bi bi-check2-circle"></i></div>
        <h2 class="text-center fw-bold mb-2" style="color:#4A3525;">Order Placed Successfully</h2>
        <p class="text-center text-muted mb-4">Please save your claim number. You can use it to view your order status.</p>

        <div class="order-code text-center mb-4">
            <div class="detail-label">Order Number</div>
            <strong><?= htmlspecialchars($order['order_number']) ?></strong>
            <hr class="my-3">
            <div class="detail-label">Claim Number</div>
            <strong><?= htmlspecialchars($order['claim_number']) ?></strong>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-6"><div class="detail-label">Customer</div><div class="detail-value"><?= htmlspecialchars($order['customer_name']) ?></div></div>
            <div class="col-md-6"><div class="detail-label">Mobile</div><div class="detail-value"><?= htmlspecialchars($order['contact_number']) ?></div></div>
            <div class="col-md-6"><div class="detail-label">Pick-up</div><div class="detail-value"><?= htmlspecialchars($order['pickup_date']) ?> @ <?= htmlspecialchars(date('h:i A', strtotime($order['pickup_time']))) ?></div></div>
            <div class="col-md-6"><div class="detail-label">Payment</div><div class="detail-value text-uppercase"><?= htmlspecialchars($order['payment_method']) ?></div></div>
            <div class="col-md-6"><div class="detail-label">Status</div><div><span class="status-pill"><?= htmlspecialchars($statusLabel) ?></span></div></div>
            <div class="col-md-6"><div class="detail-label">Total</div><div class="detail-value">₱<?= number_format((float)$order['total_amount'], 2) ?></div></div>
        </div>

        <?php if ($order['status'] === 'pending_verification'): ?>
            <?php if (strtolower((string)$order['payment_method']) === 'gcash'): ?>
                <div class="alert alert-info border-0 mb-4">Your GCash payment is waiting for verification by the store.</div>
            <?php else: ?>
                <div class="alert alert-warning border-0 mb-4">Your cash order is waiting for confirmation by the store. The order will not be prepared until it is confirmed.</div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="d-flex justify-content-center gap-2 flex-wrap">
            <a href="monitor-guest-order.php" class="btn btn-brown">View Order Status</a>
            <a href="menu.php" class="btn btn-outline-secondary rounded-pill px-4">Order More</a>
        </div>
    </section>
</main>

<?php require_once '../includes/footer.php'; ?>
