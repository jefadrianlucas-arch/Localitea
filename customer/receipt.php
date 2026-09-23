<?php
require_once '../includes/db.php';

$order_number = $_GET['order'] ?? '';

$stmt = $pdo->prepare("
    SELECT *
    FROM orders
    WHERE order_number = ?
");

$stmt->execute([$order_number]);

$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    die("Order not found.");
}

$stmt_items = $pdo->prepare("
SELECT *
FROM order_items
WHERE order_id = ?
");

$stmt_items->execute([$order['id']]);
$items = $stmt_items->fetchAll();

require_once '../includes/header.php';
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div id="receipt-content" class="card card-custom p-4 bg-white shadow-sm">
                <div class="text-center mb-4">
                    <h3 class="fw-bold text-primary-brown mb-1"><i class="bi bi-cup-hot-fill"></i> Local Milktea House</h3>
                    <p class="text-muted small mb-0">Pick-up Only Order Receipt</p>
                    <span class="badge bg-success mt-2">Status: <?= $order['status'] ?></span>
                </div>
                
                <div class="border-top border-bottom py-3 mb-3">
                    <div class="row small mb-1">
                        <div class="row small mb-1">
                        <div class="col-6 text-muted">Order Number:</div>
                        <div class="col-6 fw-bold text-end">
                            <?= htmlspecialchars($order['order_number']) ?>
                        </div>
                    </div>

                    <div class="row small mb-1">
                        <div class="col-6 text-muted">Claim Number:</div>
                        <div class="col-6 fw-bold text-end">
                            <?= htmlspecialchars($order['claim_number']) ?>
                        </div>
                    </div>
                    </div>
                    <div class="row small mb-1">
                        <div class="col-6 text-muted">Customer Name:</div>
                        <div class="col-6 fw-semibold text-end"><?= htmlspecialchars($order['customer_name']) ?></div>
                    </div>
                    <div class="row small mb-1">
                        <div class="col-6 text-muted">Pickup Schedule:</div>
                        <div class="col-6 fw-semibold text-end"><?= $order['pickup_date'] ?> @ <?= date('g:i A', strtotime($order['pickup_time'])) ?></div>
                    </div>
                    <div class="row small">
                        <div class="col-6 text-muted">Payment Method:</div>
                        <div class="col-6 fw-semibold text-end"><?= $order['payment_method'] ?></div>
                    </div>
                </div>

                <h6 class="fw-bold text-dark-brown mb-2">Order Items</h6>
                <table class="table table-sm align-middle mb-3">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th class="text-center">Qty</th>
                            <th class="text-end">Price</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $itm): ?>
                            <tr>
                                <td><?= htmlspecialchars($itm['product_name']) ?></td>
                                <td class="text-center"><?= $itm['quantity'] ?></td>
                                <td class="text-end">₱<?= number_format($itm['subtotal'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="d-flex justify-content-between border-top pt-3 mb-4">
                    <span class="fw-bold fs-5">Total Amount:</span>
                    <span class="fw-bold fs-5 text-primary-brown">₱<?= number_format($order['total_amount'], 2) ?></span>
                </div>

                <div class="text-center text-muted small">
                    <p class="mb-1">Please present this receipt/order number upon pick-up.</p>
                    <p class="mb-0">Thank you for ordering with us!</p>
                </div>
            </div>

            <div class="d-flex gap-2 mt-4 no-print">
                <a href="dashboard.php" class="btn btn-outline-dark flex-grow-1"><i class="bi bi-speedometer2"></i> Dashboard</a>
                <button onclick="window.print()" class="btn btn-primary-custom flex-grow-1"><i class="bi bi-printer"></i> Print Receipt</button>
            </div>
        </div>
    </div>
</div>

<style>
@media print {
    .no-print { display: none !important; }
    body { background-color: white !important; }
    .card { border: none !important; box-shadow: none !important; }
}
</style>

<?php require_once '../includes/footer.php'; ?>