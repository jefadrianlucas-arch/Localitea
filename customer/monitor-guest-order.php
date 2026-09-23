<?php
session_start();
date_default_timezone_set('Asia/Manila');

require_once '../includes/db.php';

/*
 * Guest order status lookup.
 * The guest only needs the Claim Number shown after checkout.
 * No SMS, OTP, email, or mobile-number entry is required here.
 */
$claimNumber = trim((string)(
    $_GET['claim_number']
    ?? $_GET['claim']
    ?? $_POST['claim_number']
    ?? ''
));

$claimNumber = substr($claimNumber, 0, 50);

$statusLabels = [
    'pending_verification' => 'Pending Verification',
    'confirmed' => 'Confirmed',
    'preparing' => 'Preparing',
    'ready' => 'Ready for Pick-up',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
];

$workflowStatuses = [
    'pending_verification',
    'confirmed',
    'preparing',
    'ready',
    'completed'
];

$error = '';
$order = null;
$items = [];
$cancellationActorName = '';
$cancellationActorRole = '';

/**
 * Resolve the person who cancelled the order from the status history.
 * Staff accounts are stored in users.name; admin/superadmin accounts
 * are stored in admins.full_name.
 */
function getGuestCancellationActor(PDO $pdo, int $orderId): array
{
    if ($orderId <= 0) {
        return [
            'name' => '',
            'role' => '',
        ];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT
                h.actor_role,
                h.actor_id,
                u.name AS staff_name,
                a.full_name AS admin_name
            FROM order_status_history h
            LEFT JOIN users u
                ON LOWER(TRIM(h.actor_role)) IN ('staff', 'customer')
               AND u.id = h.actor_id
            LEFT JOIN admins a
                ON LOWER(TRIM(h.actor_role)) IN ('admin', 'superadmin')
               AND a.id = h.actor_id
            WHERE h.order_id = ?
              AND h.to_status = 'cancelled'
            ORDER BY h.id DESC
            LIMIT 1
        ");

        $stmt->execute([$orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return [
                'name' => '',
                'role' => '',
            ];
        }

        $role = strtolower(trim((string)($row['actor_role'] ?? '')));
        $name = '';

        if (in_array($role, ['admin', 'superadmin'], true)) {
            $name = trim((string)($row['admin_name'] ?? ''));
        } elseif ($role === 'staff') {
            $name = trim((string)($row['staff_name'] ?? ''));
        } elseif ($role === 'customer') {
            $name = trim((string)($row['staff_name'] ?? ''));
        }

        return [
            'name' => $name,
            'role' => $role,
        ];
    } catch (Throwable $e) {
        /* Keep the guest status page working even if history is unavailable. */
        return [
            'name' => '',
            'role' => '',
        ];
    }
}

if ($claimNumber !== '') {
    $stmt = $pdo->prepare("
        SELECT
            id,
            order_number,
            claim_number,
            customer_name,
            pickup_date,
            pickup_time,
            payment_method,
            total_amount,
            status
        FROM orders
        WHERE claim_number = ?
          AND customer_id IS NULL
        LIMIT 1
    ");

    $stmt->execute([$claimNumber]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($order) {
        if ((string)$order['status'] === 'cancelled') {
            $cancellationActor = getGuestCancellationActor(
                $pdo,
                (int)$order['id']
            );

            $cancellationActorName = $cancellationActor['name'];
            $cancellationActorRole = $cancellationActor['role'];
        }

        $itemStmt = $pdo->prepare("
            SELECT
                product_name,
                quantity,
                unit_price,
                subtotal,
                size,
                addons,
                sugar_level
            FROM order_items
            WHERE order_id = ?
            ORDER BY id ASC
        ");

        $itemStmt->execute([(int)$order['id']]);
        $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $error = 'We could not find a guest order with that Claim Number.';
    }
}

/* =========================================================
   AJAX STATUS POLLING
   The page can quietly check the current status while open.
========================================================= */
if (
    ($_GET['ajax'] ?? '') === 'status'
    && $claimNumber !== ''
) {
    header('Content-Type: application/json; charset=utf-8');

    if (!$order) {
        echo json_encode([
            'success' => false,
            'message' => 'Order not found.'
        ]);
        exit;
    }

    if ((string)$order['status'] === 'cancelled') {
        $cancellationActor = getGuestCancellationActor(
            $pdo,
            (int)$order['id']
        );

        $cancellationActorName = $cancellationActor['name'];
        $cancellationActorRole = $cancellationActor['role'];
    }

    echo json_encode([
        'success' => true,
        'status' => (string)$order['status'],
        'status_label' => $statusLabels[$order['status']] ?? ucfirst(str_replace('_', ' ', $order['status'])),
        'cancellation_actor_name' => $cancellationActorName,
        'cancellation_actor_role' => $cancellationActorRole
    ]);
    exit;
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
?>

<style>
    body {
        background: #FDFBF7;
    }

    .order-status-wrap {
        max-width: 820px;
        margin: 0 auto;
        padding: 48px 16px;
    }

    .order-status-card {
        background: #FFFFFF;
        border: 1px solid #E6DEC9;
        border-radius: 20px;
        box-shadow: 0 8px 24px rgba(44, 34, 30, .07);
        padding: 28px;
    }

    .btn-brown {
        background: #4A3525;
        border-color: #4A3525;
        color: #FFFFFF;
        border-radius: 999px;
        font-weight: 700;
    }

    .btn-brown:hover {
        background: #342317;
        border-color: #342317;
        color: #FFFFFF;
    }

    .status-pill {
        display: inline-flex;
        align-items: center;
        padding: 7px 12px;
        border-radius: 999px;
        background: #EEF5FC;
        color: #496F93;
        font-weight: 800;
        font-size: .8rem;
    }

    .status-timeline {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 8px;
        margin-top: 24px;
    }

    .status-step {
        text-align: center;
        color: #9A8B7D;
        font-size: .72rem;
        font-weight: 700;
    }

    .status-step-dot {
        width: 34px;
        height: 34px;
        margin: 0 auto 7px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        border: 1px solid #D9CABB;
        background: #F8F3EC;
        color: #9A8B7D;
        font-size: .8rem;
    }

    .status-step.done,
    .status-step.current {
        color: #4A3525;
    }

    .status-step.done .status-step-dot,
    .status-step.current .status-step-dot {
        background: #4A3525;
        border-color: #4A3525;
        color: #FFFFFF;
    }

    .status-step.current .status-step-dot {
        box-shadow: 0 0 0 4px #F1E6DA;
    }

    .order-item {
        border: 1px solid #E8DFD4;
        border-radius: 12px;
        padding: 14px;
    }

    .last-checked {
        color: #8A7A6C;
        font-size: .75rem;
    }

    @media (max-width: 576px) {
        .order-status-wrap {
            padding: 30px 12px;
        }

        .order-status-card {
            padding: 20px;
        }

        .status-timeline {
            grid-template-columns: 1fr;
            gap: 9px;
            text-align: left;
        }

        .status-step {
            display: flex;
            align-items: center;
            gap: 9px;
            text-align: left;
        }

        .status-step-dot {
            margin: 0;
            flex: 0 0 34px;
        }
    }
</style>

<main class="order-status-wrap">
    <section class="order-status-card">
        <h2 class="fw-bold mb-2" style="color:#4A3525;">Order Status</h2>
        <p class="text-muted mb-4">
            Enter your Claim Number to view the current status of your order.
        </p>

        <form method="GET" class="row g-3 mb-4">
            <div class="col-12">
                <label class="form-label fw-semibold">Claim Number</label>
                <input
                    type="text"
                    name="claim_number"
                    class="form-control"
                    value="<?= htmlspecialchars($claimNumber) ?>"
                    placeholder="CLM-0001"
                    maxlength="50"
                    required
                >
            </div>

            <div class="col-12">
                <button type="submit" class="btn btn-brown px-4">
                    View Order Status
                </button>
            </div>
        </form>

        <?php if ($error): ?>
            <div class="alert alert-danger border-0">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($order): ?>
            <?php
                $currentStatus = (string)$order['status'];
                $currentIndex = array_search($currentStatus, $workflowStatuses, true);
                if ($currentIndex === false) {
                    $currentIndex = -1;
                }
            ?>

            <div class="border-top pt-4" id="orderStatusResult">
                <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                    <div>
                        <div class="text-muted small">Order Number</div>
                        <div class="fw-bold fs-5" style="color:#4A3525;">
                            <?= htmlspecialchars($order['order_number']) ?>
                        </div>
                    </div>

                    <div id="statusPillWrap">
                        <span class="status-pill" id="statusPill">
                            <?= htmlspecialchars($statusLabels[$currentStatus] ?? ucfirst(str_replace('_', ' ', $currentStatus))) ?>
                        </span>
                    </div>
                </div>

                <div class="mb-4">
                    <div class="text-muted small mb-1">Claim Number</div>
                    <div class="fw-bold" style="color:#4A3525;">
                        <?= htmlspecialchars($order['claim_number']) ?>
                    </div>
                </div>

                <div class="status-timeline" id="statusTimeline">
                    <?php foreach ($workflowStatuses as $index => $status): ?>
                        <?php
                            $isDone = $currentIndex >= 0 && $index < $currentIndex;
                            $isCurrent = $index === $currentIndex;
                        ?>

                        <div
                            class="status-step <?= $isDone ? 'done' : '' ?> <?= $isCurrent ? 'current' : '' ?>"
                            data-status="<?= htmlspecialchars($status) ?>"
                        >
                            <div class="status-step-dot">
                                <i class="bi <?= $isDone ? 'bi-check-lg' : ($isCurrent ? 'bi-circle-fill' : 'bi-circle') ?>"></i>
                            </div>
                            <span>
                                <?= htmlspecialchars($statusLabels[$status]) ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="row g-3 mt-4 mb-4">
                    <div class="col-md-6">
                        <div class="text-muted small">Pick-up</div>
                        <div class="fw-semibold">
                            <?= htmlspecialchars($order['pickup_date']) ?> @
                            <?= htmlspecialchars(date('h:i A', strtotime($order['pickup_time']))) ?>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="text-muted small">Payment</div>
                        <div class="fw-semibold text-uppercase">
                            <?= htmlspecialchars($order['payment_method']) ?>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="text-muted small">Total</div>
                        <div class="fw-semibold">
                            ₱<?= number_format((float)$order['total_amount'], 2) ?>
                        </div>
                    </div>
                </div>

                <?php foreach ($items as $item): ?>
                    <div class="order-item mb-2">
                        <div class="d-flex justify-content-between gap-3">
                            <div class="fw-bold">
                                <?= htmlspecialchars($item['product_name']) ?>
                                × <?= (int)$item['quantity'] ?>
                            </div>

                            <div class="fw-semibold">
                                ₱<?= number_format((float)$item['subtotal'], 2) ?>
                            </div>
                        </div>

                        <?php if (!empty($item['size']) || !empty($item['sugar_level'])): ?>
                            <div class="text-muted small">
                                <?php if (!empty($item['size'])): ?>
                                    Size: <?= htmlspecialchars($item['size']) ?>
                                <?php endif; ?>

                                <?php if (!empty($item['sugar_level'])): ?>
                                    · Sugar: <?= htmlspecialchars($item['sugar_level']) ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($item['addons'])): ?>
                            <div class="text-muted small mt-1">
                                Add-ons: <?= htmlspecialchars((string)$item['addons']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <div
                    id="statusMessage"
                    class="alert <?= $currentStatus === 'cancelled' ? 'alert-danger' : 'alert-info' ?> border-0 mt-3 mb-2"
                >
                    <?php if ($currentStatus === 'pending_verification'): ?>
                        <?php if (strtolower((string)$order['payment_method']) === 'gcash'): ?>
                            Your GCash payment is waiting for verification by the store.
                        <?php else: ?>
                            Your cash order is waiting for confirmation by the store.
                        <?php endif; ?>
                    <?php elseif ($currentStatus === 'confirmed'): ?>
                        Your order has been confirmed by the store.
                    <?php elseif ($currentStatus === 'preparing'): ?>
                        Your order is being prepared.
                    <?php elseif ($currentStatus === 'ready'): ?>
                        Your order is ready for pick-up.
                    <?php elseif ($currentStatus === 'completed'): ?>
                        Your order has been completed.
                    <?php elseif ($currentStatus === 'cancelled'): ?>
                        <?php if ($cancellationActorName !== ''): ?>
                            Your order has been cancelled by
                            <?= htmlspecialchars($cancellationActorName) ?>.
                        <?php elseif ($cancellationActorRole === 'staff'): ?>
                            Your order has been cancelled by a staff member.
                        <?php elseif (in_array($cancellationActorRole, ['admin', 'superadmin'], true)): ?>
                            Your order has been cancelled by an administrator.
                        <?php elseif ($cancellationActorRole === 'customer'): ?>
                            Your order has been cancelled by the customer.
                        <?php else: ?>
                            Your order has been cancelled by the store.
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <div class="last-checked" id="lastChecked">
                    Status shown as of <?= htmlspecialchars(date('h:i A')) ?>
                </div>
            </div>

            <?php if (in_array($currentStatus, $workflowStatuses, true)): ?>
                <script>
                (function () {
                    const claimNumber = <?= json_encode($order['claim_number']) ?>;
                    const statusLabels = <?= json_encode($statusLabels) ?>;
                    const workflowStatuses = <?= json_encode($workflowStatuses) ?>;
                    const paymentMethod = <?= json_encode(strtolower((string)$order['payment_method'])) ?>;
                    let cancellationActorName = <?= json_encode($cancellationActorName) ?>;
                    let cancellationActorRole = <?= json_encode($cancellationActorRole) ?>;
                    const statusPill = document.getElementById('statusPill');
                    const statusMessage = document.getElementById('statusMessage');
                    const timeline = document.getElementById('statusTimeline');
                    const lastChecked = document.getElementById('lastChecked');

                    function updateStatusMessageStyle(status) {
                        statusMessage.classList.remove('alert-info', 'alert-success', 'alert-danger');

                        if (status === 'cancelled') {
                            statusMessage.classList.add('alert-danger');
                        } else if (status === 'completed') {
                            statusMessage.classList.add('alert-success');
                        } else {
                            statusMessage.classList.add('alert-info');
                        }
                    }

                    function messageForStatus(status) {
                        if (status === 'pending_verification') {
                            return paymentMethod === 'gcash'
                                ? 'Your GCash payment is waiting for verification by the store.'
                                : 'Your cash order is waiting for confirmation by the store.';
                        }
                        if (status === 'confirmed') return 'Your order has been confirmed by the store.';
                        if (status === 'preparing') return 'Your order is being prepared.';
                        if (status === 'ready') return 'Your order is ready for pick-up.';
                        if (status === 'completed') return 'Your order has been completed.';
                        if (status === 'cancelled') {
                            if (cancellationActorName) {
                                return 'Your order has been cancelled by ' + cancellationActorName + '.';
                            }

                            if (cancellationActorRole === 'staff') {
                                return 'Your order has been cancelled by a staff member.';
                            }

                            if (['admin', 'superadmin'].includes(cancellationActorRole)) {
                                return 'Your order has been cancelled by an administrator.';
                            }

                            if (cancellationActorRole === 'customer') {
                                return 'Your order has been cancelled by the customer.';
                            }

                            return 'Your order has been cancelled by the store.';
                        }
                        return '';
                    }

                    function renderTimeline(status) {
                        const currentIndex = workflowStatuses.indexOf(status);
                        const steps = timeline.querySelectorAll('[data-status]');

                        steps.forEach(function (step) {
                            const stepStatus = step.getAttribute('data-status');
                            const index = workflowStatuses.indexOf(stepStatus);
                            const dot = step.querySelector('.status-step-dot i');

                            step.classList.toggle('done', currentIndex >= 0 && index < currentIndex);
                            step.classList.toggle('current', index === currentIndex);

                            dot.className = 'bi ' + (
                                currentIndex >= 0 && index < currentIndex
                                    ? 'bi-check-lg'
                                    : index === currentIndex
                                        ? 'bi-circle-fill'
                                        : 'bi-circle'
                            );
                        });
                    }

                    async function refreshStatus() {
                        try {
                            const response = await fetch(
                                'monitor-guest-order.php?ajax=status&claim=' + encodeURIComponent(claimNumber),
                                {
                                    method: 'GET',
                                    cache: 'no-store',
                                    headers: {
                                        'Accept': 'application/json'
                                    }
                                }
                            );

                            if (!response.ok) return;

                            const data = await response.json();

                            if (!data.success || !data.status) return;

                            const currentStatus = data.status;

                            cancellationActorName =
                                data.cancellation_actor_name || '';
                            cancellationActorRole =
                                data.cancellation_actor_role || '';

                            statusPill.textContent =
                                data.status_label ||
                                statusLabels[currentStatus] ||
                                currentStatus;

                            statusMessage.textContent = messageForStatus(currentStatus);
                            updateStatusMessageStyle(currentStatus);
                            renderTimeline(currentStatus);

                            lastChecked.textContent =
                                'Status updated at ' +
                                new Date().toLocaleTimeString([], {
                                    hour: '2-digit',
                                    minute: '2-digit'
                                });

                            if (currentStatus === 'completed' || currentStatus === 'cancelled') {
                                clearInterval(pollTimer);
                            }
                        } catch (error) {
                            /* Keep the page usable even if a background refresh fails. */
                        }
                    }

                    const pollTimer = setInterval(refreshStatus, 8000);
                })();
                </script>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</main>

<?php require_once '../includes/footer.php'; ?>
