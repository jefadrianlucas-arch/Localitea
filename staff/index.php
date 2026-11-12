<?php
session_start();
date_default_timezone_set('Asia/Manila');

require_once '../includes/db.php';
require_once '../includes/mailer.php';

/* =========================================================
   STAFF ACCESS ONLY
   This page is reserved for authenticated Staff accounts.
   Admin order and refund management remains in ../admin/orders.php.
========================================================= */
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

if (($_SESSION['user_role'] ?? '') !== 'staff') {
    if (($_SESSION['user_role'] ?? '') === 'admin') {
        header('Location: ../admin/orders.php');
    } else {
        header('Location: ../auth/login.php');
    }
    exit;
}

/* Sign out Staff accounts that were archived while logged in. */
$activeStaffStmt = $pdo->prepare("
    SELECT id
    FROM users
    WHERE id = ?
      AND role = 'staff'
      AND is_archived = 0
    LIMIT 1
");
$activeStaffStmt->execute([(int)$_SESSION['user_id']]);

if (!$activeStaffStmt->fetch()) {
    $_SESSION = [];
    session_destroy();
    header('Location: ../auth/login.php');
    exit;
}

$staff_id = (int)$_SESSION['user_id'];
$staff_role = 'staff';

/* =========================================================
   WALK-IN CONFIRMATION (set by walk-in-order.php, shown once)
========================================================= */
$walkinPlaced = null;

if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'xmlhttprequest'
    && !empty($_SESSION['walkin_success'])
    && is_array($_SESSION['walkin_success'])
) {
    $walkinPlaced = $_SESSION['walkin_success'];
    unset($_SESSION['walkin_success']);
}

/* =========================================================
   CSRF PROTECTION
   Every POST must carry the session token, either in the
   csrf_token form field or in the X-CSRF-Token header.
========================================================= */
if (empty($_SESSION['staff_csrf_token'])) {
    $_SESSION['staff_csrf_token'] = bin2hex(random_bytes(32));
}

$staff_csrf_token = $_SESSION['staff_csrf_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedCsrfToken = (string)($_POST['csrf_token'] ?? '');

    if ($postedCsrfToken === '') {
        $postedCsrfToken = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    }

    if (!hash_equals($staff_csrf_token, $postedCsrfToken)) {
        $isAjaxCsrfRequest =
            ($_POST['ajax'] ?? '') === '1'
            || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

        if ($isAjaxCsrfRequest) {
            /* HTTP 200 so the page shows this message in its usual error toast. */
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'Your session has expired. Please refresh the page and try again.'
            ]);
            exit;
        }

        http_response_code(403);
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Session expired</title></head>'
            . '<body style="font-family:sans-serif;padding:24px;">'
            . '<p>Your session has expired. Nothing was changed.</p>'
            . '<p><a href="index.php">Go back to Orders</a></p>'
            . '</body></html>';
        exit;
    }
}

/*
 * No-show timing policy:
 * - The order must be in Ready status (checked separately).
 * - The requested pickup date/time must have passed.
 * - The order must also have been Ready for at least 30 minutes.
 * The grace period starts from whichever is later: scheduled pickup time
 * or ready_at. Both are interpreted as Manila-local datetimes.
 */
const STAFF_NO_SHOW_GRACE_MINUTES = 30;

function staffGetNoShowTiming(array $order): array
{
    $pickupDate = trim((string)($order['pickup_date'] ?? ''));
    $pickupTime = trim((string)($order['pickup_time'] ?? ''));
    $readyAt = trim((string)($order['ready_at'] ?? ''));

    if ($pickupDate === '' || $pickupTime === '') {
        return ['eligible' => false, 'eligible_at' => 0];
    }

    try {
        $manilaTimezone = new DateTimeZone('Asia/Manila');

        if (preg_match('/^\d{2}:\d{2}$/', $pickupTime)) {
            $pickupTime .= ':00';
        }

        $pickupAtText = $pickupDate . ' ' . $pickupTime;
        $pickupAt = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $pickupAtText,
            $manilaTimezone
        );

        if (!$pickupAt || $pickupAt->format('Y-m-d H:i:s') !== $pickupAtText) {
            return ['eligible' => false, 'eligible_at' => 0];
        }

        if ($readyAt !== '') {
            /* ready_at is explicitly stored in Manila local time. */
            $readyAtText = substr($readyAt, 0, 19);
            $readyAtDateTime = DateTimeImmutable::createFromFormat(
                '!Y-m-d H:i:s',
                $readyAtText,
                $manilaTimezone
            );

            if (!$readyAtDateTime || $readyAtDateTime->format('Y-m-d H:i:s') !== $readyAtText) {
                return ['eligible' => false, 'eligible_at' => 0];
            }

            $readyAtUnix = $readyAtDateTime->getTimestamp();
        } else {
            /* updated_at is TIMESTAMP in the current schema, so its Unix value
               is timezone-safe and supports Staff transitions until staff/index.php
               also starts saving ready_at explicitly. */
            $readyAtUnix = (int)($order['updated_at_unix'] ?? 0);
        }

        if ($readyAtUnix <= 0) {
            return ['eligible' => false, 'eligible_at' => 0];
        }

        $eligibleAt = max(
            $pickupAt->getTimestamp(),
            $readyAtUnix
        ) + (STAFF_NO_SHOW_GRACE_MINUTES * 60);

        $now = new DateTimeImmutable('now', $manilaTimezone);

        return [
            'eligible' => $now->getTimestamp() >= $eligibleAt,
            'eligible_at' => $eligibleAt,
        ];
    } catch (Throwable $e) {
        error_log('Staff no-show timing calculation failed: ' . $e->getMessage());
        return ['eligible' => false, 'eligible_at' => 0];
    }
}

$isAjaxRequest =
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['ajax'] ?? '') === '1';

/* =========================================================
   PAYMENT VERIFICATION BEFORE ORDER TRANSITIONS
========================================================= */
function staffVerifyPaymentForStatusTransition(
    PDO $pdo,
    array $orderData,
    string $currentStatus,
    string $newStatus,
    int $staffId
): void {

    $verifyGcash = (
        $currentStatus === 'pending_verification'
        && $newStatus === 'order_queue'
    );

    $verifyAtCompletion = (
        $currentStatus === 'ready'
        && $newStatus === 'completed'
    );

    if (!$verifyGcash && !$verifyAtCompletion) {
        return;
    }

    $orderMethod = strtolower(trim(
        (string)($orderData['payment_method'] ?? '')
    ));

    if (!in_array($orderMethod, ['cash', 'gcash'], true)) {
        throw new RuntimeException('The order has an invalid payment method.');
    }

    if ($verifyGcash) {

        if ($orderMethod !== 'gcash') {
            throw new RuntimeException(
                'Only GCash orders can be reviewed from Pending Verification.'
            );
        }

        /* The review UI is not treated as proof. The checks below validate the payment record and uploaded proof. */
    }

    $paymentStmt = $pdo->prepare("
        SELECT
            id,
            payment_method,
            amount,
            proof_image,
            is_verified
        FROM payments
        WHERE order_id = ?
        LIMIT 1
        FOR UPDATE
    ");

    $paymentStmt->execute([(int)$orderData['id']]);
    $paymentData = $paymentStmt->fetch(PDO::FETCH_ASSOC);

    if (!$paymentData) {
        throw new RuntimeException(
            'Payment record not found. This order cannot be processed until its payment record is corrected.'
        );
    }

    $recordMethod = strtolower(trim(
        (string)$paymentData['payment_method']
    ));

    if ($recordMethod !== $orderMethod) {
        throw new RuntimeException(
            'The payment method in the payment record does not match the order.'
        );
    }

    if (
        round((float)$paymentData['amount'], 2)
        !== round((float)$orderData['total_amount'], 2)
    ) {
        throw new RuntimeException(
            'The payment amount does not match the order total.'
        );
    }

    $isVerified = (int)($paymentData['is_verified'] ?? 0);
    $paymentId = (int)$paymentData['id'];

    if ($verifyGcash) {

        $orderProof = trim(
            (string)($orderData['payment_screenshot'] ?? '')
        );

        $paymentProof = trim(
            (string)($paymentData['proof_image'] ?? '')
        );

        if (
            $orderProof === ''
            || $paymentProof === ''
            || !hash_equals($orderProof, $paymentProof)
        ) {
            throw new RuntimeException(
                'The GCash proof is missing or does not match the payment record. The order cannot be moved to the queue.'
            );
        }

        if ($isVerified !== 1) {
            $verifyStmt = $pdo->prepare("
                UPDATE payments
                SET
                    is_verified = 1,
                    verified_at = NOW(),
                    verified_by_staff_id = ?
                WHERE id = ?
                  AND (is_verified = 0 OR is_verified IS NULL)
            ");

            $verifyStmt->execute([$staffId, $paymentId]);

            if ($verifyStmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'GCash payment verification failed. Please refresh and review the payment again.'
                );
            }
        }
    }

    if ($verifyAtCompletion) {

        if ($orderMethod === 'gcash' && $isVerified !== 1) {
            throw new RuntimeException(
                'This GCash payment has not been verified. The order cannot be completed.'
            );
        }

        if ($orderMethod === 'cash' && $isVerified !== 1) {
            $verifyStmt = $pdo->prepare("
                UPDATE payments
                SET
                    is_verified = 1,
                    verified_at = NOW(),
                    verified_by_staff_id = ?
                WHERE id = ?
                  AND (is_verified = 0 OR is_verified IS NULL)
            ");

            $verifyStmt->execute([$staffId, $paymentId]);

            if ($verifyStmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'Cash payment verification failed. The order was not completed.'
                );
            }
        }
    }
}

/* =========================================================
   FILTERS + PAGINATION
========================================================= */
$valid_filters = [
    'pending_verification',
    'order_queue',
    'confirmed',
    'preparing',
    'ready'
];

$valid_view_filters = $valid_filters;

$selected_status = $_GET['status'] ?? 'order_queue';

if (!in_array($selected_status, $valid_view_filters, true)) {
    $selected_status = 'order_queue';
}

$search = trim((string)($_GET['q'] ?? ''));

if (mb_strlen($search) > 100) {
    $search = mb_substr($search, 0, 100);
}

/* =========================================================
   CANCELLED ORDER FILTERS
   Cancelled records are a separate historical section below
   the active workflow.
========================================================= */
$cancelled_search = trim((string)($_GET['cancelled_q'] ?? ''));

if (mb_strlen($cancelled_search) > 100) {
    $cancelled_search = mb_substr($cancelled_search, 0, 100);
}

$cancelled_period = strtolower(
    trim((string)($_GET['cancelled_period'] ?? 'today'))
);

$valid_cancelled_periods = [
    'today',
    'last_week',
    'last_month',
    'specific_month'
];

if (!in_array($cancelled_period, $valid_cancelled_periods, true)) {
    $cancelled_period = 'today';
}

$cancelled_month = trim(
    (string)($_GET['cancelled_month'] ?? date('Y-m'))
);

$cancelledMonthObject = DateTime::createFromFormat(
    '!Y-m',
    $cancelled_month
);

if (
    $cancelledMonthObject === false
    || $cancelledMonthObject->format('Y-m') !== $cancelled_month
) {
    $cancelled_month = date('Y-m');
} elseif ($cancelled_month > date('Y-m')) {
    $cancelled_month = date('Y-m');
}

$per_page = 10;
$page = max(1, (int)($_GET['page'] ?? 1));

$cancelled_per_page = 5;
$cancelled_page = max(
    1,
    (int)($_GET['cancelled_page'] ?? 1)
);

/* Order History status filter: all finished orders, or only one kind. */
$history_status = strtolower(trim((string)($_GET['history_status'] ?? 'all')));

if (!in_array($history_status, ['all', 'completed', 'cancelled'], true)) {
    $history_status = 'all';
}

/* Tracks whether the Cancelled Orders section should render expanded.
   This is set explicitly (not inferred from page number) so that
   paging back to page 1 does not cause the section to collapse. */
$cancelled_open = ($_GET['cancelled_open'] ?? '') === '1' ? '1' : '';

/* =========================================================
   VIEW ORDER TARGET
   When the Staff clicks View Order from notifications.php,
   the exact order ID + notification ID are passed here.
   The target order determines:
   - the correct workflow tab,
   - the correct pagination page,
   - notification read state,
   - and the exact order card that will be highlighted/scrolled to.
========================================================= */
$target_order_id =
    max(0, (int)($_GET['order_id'] ?? 0));

$target_notification_id =
    max(0, (int)($_GET['notification_id'] ?? 0));

$targetOrder = null;
$target_is_active = false;

if ($target_order_id > 0) {

    try {

        $targetOrderStmt = $pdo->prepare("
            SELECT
                id,
                status,
                created_at,
                closed_at
            FROM orders
            WHERE id = ?
            LIMIT 1
        ");

        $targetOrderStmt->execute([
            $target_order_id
        ]);

        $targetOrder =
            $targetOrderStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        /*
         * A View Order target is valid only while the order is still
         * inside the active Staff workflow. Once the order is completed
         * or cancelled, the old target must never highlight another card.
         */
        $target_is_active =
            $targetOrder
            && in_array(
                $targetOrder['status'] ?? '',
                $valid_filters,
                true
            );

        /*
         * Mark the exact notification as read.
         */
        if ($target_notification_id > 0) {

            $markTargetNotificationStmt =
                $pdo->prepare("
                    UPDATE notifications
                    SET is_read = 1
                    WHERE id = ?
                      AND recipient_role = 'staff'
                      AND is_read = 0
                ");

            $markTargetNotificationStmt->execute([
                $target_notification_id
            ]);
        }

        /*
         * Mark all unread staff notifications related to the
         * same order as read. One order may create multiple
         * staff notifications.
         */
        $markTargetOrderNotificationsStmt =
            $pdo->prepare("
                UPDATE notifications
                SET is_read = 1
                WHERE recipient_role = 'staff'
                  AND reference_id = ?
                  AND is_read = 0
            ");

        $markTargetOrderNotificationsStmt->execute([
            $target_order_id
        ]);

        /*
         * Only active orders are valid View Order targets.
         */
        if ($target_is_active) {

            $selected_status =
                $targetOrder['status'];

            $search = '';
            $page = 1;
        }

    } catch (Throwable $e) {

        error_log(
            'Staff target order lookup failed: '
            . $e->getMessage()
        );
    }
}

/* =========================================================
   REDIRECT HELPER
========================================================= */
function staffOrdersRedirect(
    string $status,
    string $search,
    int $page,
    ?string $action = null
): void {
    $params = [
        'status' => $status,
        'page' => $page
    ];

    if ($search !== '') {
        $params['q'] = $search;
    }

    if ($GLOBALS['cancelled_search'] !== '') {
        $params['cancelled_q'] = $GLOBALS['cancelled_search'];
    }

    if (($GLOBALS['cancelled_period'] ?? '') !== '') {
        $params['cancelled_period'] = $GLOBALS['cancelled_period'];
    }

    if (
        ($GLOBALS['cancelled_period'] ?? '') === 'specific_month'
        && ($GLOBALS['cancelled_month'] ?? '') !== ''
    ) {
        $params['cancelled_month'] = $GLOBALS['cancelled_month'];
    }

    if ((int)$GLOBALS['cancelled_page'] > 1) {
        $params['cancelled_page'] = (int)$GLOBALS['cancelled_page'];
    }

    if (($GLOBALS['history_status'] ?? 'all') !== 'all') {
        $params['history_status'] = $GLOBALS['history_status'];
    }

    if (($GLOBALS['cancelled_open'] ?? '') === '1') {
        $params['cancelled_open'] = '1';
    }

    if ($action !== null && $action !== '') {
        $params['action'] = $action;

    }

    header('Location: index.php?' . http_build_query($params));
    exit;
}

/* =========================================================
   CANCEL ORDER
   Staff-only cancellation.
   Supports normal POST fallback and AJAX.
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['cancel_order'])
) {

    $ajaxResponse = [
        'success' => false,
        'message' => 'The order could not be cancelled.'
    ];

    $order_id =
        (int)($_POST['order_id'] ?? 0);

    $cancellation_reason =
        trim(
            (string)($_POST['cancellation_reason'] ?? '')
        );

    /*
     * The cancellation dropdown uses __other__ for a custom reason.
     * Replace that marker with the text entered by the Staff.
     */
    if ($cancellation_reason === '__other__') {
        $other_cancellation_reason = trim(
            (string)($_POST['other_cancellation_reason'] ?? '')
        );

        if ($other_cancellation_reason !== '') {
            $cancellation_reason = $other_cancellation_reason;
        }
    }

    /* Keep the stored reason within the database's 255-character limit. */
    if (mb_strlen($cancellation_reason) > 255) {
        $cancellation_reason = mb_substr($cancellation_reason, 0, 255);
    }

    /* This exact value is reserved for a Ready order that was not collected. */
    $noShowCancellationReason = 'Customer did not collect order (No-show)';
    $isNoShowCancellation = ($cancellation_reason === $noShowCancellationReason);

    $posted_status =
        trim(
            (string)(
                $_POST['status_filter']
                ?? 'pending_verification'
            )
        );

    $posted_search =
        trim(
            (string)($_POST['q'] ?? '')
        );

    $posted_page =
        max(
            1,
            (int)($_POST['page'] ?? 1)
        );

    if (!in_array(
        $posted_status,
        $valid_filters,
        true
    )) {

        $posted_status =
            'pending_verification';
    }

    if (
        $order_id > 0
        && $cancellation_reason !== ''
    ) {

        try {

            $pdo->beginTransaction();

            /* ---------------------------------------------
               GET ORDER
            --------------------------------------------- */

            $orderStmt = $pdo->prepare("
                SELECT
                    id,
                    customer_id,
                    order_number,
                    claim_number,
                    status,
                    payment_method,
                    payment_screenshot,
                    order_source,
                    pickup_date,
                    pickup_time,
                    ready_at,
                    UNIX_TIMESTAMP(updated_at) AS updated_at_unix
                FROM orders
                WHERE id = ?
                LIMIT 1
                FOR UPDATE
            ");

            $orderStmt->execute([
                $order_id
            ]);

            $orderData =
                $orderStmt->fetch(
                    PDO::FETCH_ASSOC
                );

            if (!$orderData) {

                throw new RuntimeException(
                    'Order not found.'
                );
            }

            /* ---------------------------------------------
               ALLOWED CANCELLATION STATUSES

               Normal cancellations remain limited to orders that have
               not entered preparation. A Ready order may only be closed
               through the dedicated No-show action after the 30-minute grace period.
            --------------------------------------------- */

            $allowed_to_cancel = [
                'pending_verification',
                'order_queue',
                'confirmed'
            ];

            $previous_status = (string)$orderData['status'];
            $orderPaymentMethod = strtolower(trim((string)($orderData['payment_method'] ?? '')));

            $noShowTiming = staffGetNoShowTiming($orderData);
            $isEligibleReadyNoShow = (
                $isNoShowCancellation
                && $previous_status === 'ready'
                && in_array($orderPaymentMethod, ['cash', 'gcash'], true)
                && $noShowTiming['eligible']
            );

            $isEligibleNormalCancellation = (
                !$isNoShowCancellation
                && in_array($previous_status, $allowed_to_cancel, true)
            );

            if (!$isEligibleReadyNoShow && !$isEligibleNormalCancellation) {
                if (
                    $isNoShowCancellation
                    && $previous_status === 'ready'
                    && in_array($orderPaymentMethod, ['cash', 'gcash'], true)
                    && !$noShowTiming['eligible']
                ) {
                    $availableAtText = $noShowTiming['eligible_at'] > 0
                        ? (new DateTimeImmutable('@' . $noShowTiming['eligible_at']))
                            ->setTimezone(new DateTimeZone('Asia/Manila'))
                            ->format('g:i A, M j, Y')
                        : 'after the pickup and Ready timestamps can be verified';

                    throw new RuntimeException(
                        'This order cannot be marked as No-show yet. The later of the scheduled pickup time and Ready time must pass, followed by '
                        . STAFF_NO_SHOW_GRACE_MINUTES
                        . ' minutes. No-show becomes available at '
                        . $availableAtText
                        . '.'
                    );
                }

                if ($previous_status === 'ready' && !$isNoShowCancellation) {
                    throw new RuntimeException(
                        'Ready orders can only be closed using the dedicated No-show action.'
                    );
                }

                if ($isNoShowCancellation && $previous_status !== 'ready') {
                    throw new RuntimeException(
                        'The No-show reason can only be used after an order is marked Ready for pick-up.'
                    );
                }

                if (
                    $isNoShowCancellation
                    && !in_array($orderPaymentMethod, ['cash', 'gcash'], true)
                ) {
                    throw new RuntimeException(
                        'The No-show reason requires a valid Cash or GCash payment method.'
                    );
                }

                throw new RuntimeException(
                    'This order can no longer be cancelled.'
                );
            }

            $refund_status = 'none';
            $refund_requested_at = null;

            /*
             * Pending Refunds is also the Staff review queue for cancelled
             * GCash orders with submitted payment proof. Include every
             * cancellation reason, including "Payment could not be verified".
             *
             * This does NOT authorize a payout. The Process Refund handler
             * separately requires a verified payment record whose method,
             * amount, and proof match the order. Unverified/mismatched proof
             * remains reviewable here but cannot be marked as refunded.
             */
            /* Walk-in GCash payments have no screenshot; their proof is the
               13-digit GCash reference number saved in payments. */
            $hasWalkinGcashReference = false;

            if (
                $orderPaymentMethod === 'gcash'
                && ($orderData['order_source'] ?? '') === 'walk_in'
            ) {
                $walkinRefStmt = $pdo->prepare("
                    SELECT gcash_reference
                    FROM payments
                    WHERE order_id = ?
                    LIMIT 1
                ");
                $walkinRefStmt->execute([$order_id]);
                $hasWalkinGcashReference =
                    trim((string)$walkinRefStmt->fetchColumn()) !== '';
            }

            if (
                !$isNoShowCancellation
                && $orderPaymentMethod === 'gcash'
                && (
                    trim((string)$orderData['payment_screenshot']) !== ''
                    || $hasWalkinGcashReference
                )
            ) {
                $refund_status = 'pending';
                $refund_requested_at = date('Y-m-d H:i:s');
            }

            /* ---------------------------------------------
               CANCEL ORDER
            --------------------------------------------- */

            $stmt = $pdo->prepare("
                UPDATE orders
                SET
                    status = 'cancelled',
                    cancellation_reason = ?,
                    closed_at = NOW(),
                    refund_status = ?,
                    refund_requested_at = ?
                WHERE id = ?
                  AND status = ?
            ");

            $stmt->execute([
                $cancellation_reason,
                $refund_status,
                $refund_requested_at,
                $order_id,
                $previous_status
            ]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'The order status changed before cancellation. Refresh the page and try again.'
                );
            }

            /* ---------------------------------------------
               SAVE STATUS HISTORY
            --------------------------------------------- */

            $historyStmt = $pdo->prepare("
                INSERT INTO order_status_history
                (
                    order_id,
                    from_status,
                    to_status,
                    actor_role,
                    actor_id,
                    cancellation_reason,
                    created_at
                )
                VALUES
                (
                    ?,
                    ?,
                    'cancelled',
                    'staff',
                    ?,
                    ?,
                    NOW()
                )
            ");

            $historyStmt->execute([
                $order_id,
                $previous_status,
                $staff_id,
                $cancellation_reason
            ]);

            /* ---------------------------------------------
               CUSTOMER NOTIFICATION
            --------------------------------------------- */

            $orderIdentifier =
                $orderData['order_number']
                ?: (
                    $orderData['claim_number']
                    ?: 'Order'
                );

            $customerMessage =
                "Your order {$orderIdentifier} has been cancelled. "
                . "Reason: {$cancellation_reason}.";

            /* Guest orders have no customer account to notify. */
            if (!empty($orderData['customer_id'])) {
                $notificationStmt = $pdo->prepare("
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
                        'order_cancelled',
                        ?,
                        ?
                    )
                ");

                $notificationStmt->execute([
                    $orderData['customer_id'],
                    $customerMessage,
                    $order_id
                ]);
            }

            $pdo->commit();

            /* ---------------------------------------------
               AJAX RESPONSE
            --------------------------------------------- */

            $ajaxResponse = [
                'success' => true,
                'order_id' => $order_id,
                'previous_status' => $previous_status,
                'new_status' => 'cancelled',
                'refund_status' => $refund_status,
                'message' =>
                    "Order {$orderIdentifier} cancelled successfully."
            ];

        } catch (Throwable $e) {

            if (
                $pdo->inTransaction()
            ) {
                $pdo->rollBack();
            }

            error_log(
                'Staff order cancellation failed: '
                . $e->getMessage()
            );

            $ajaxResponse = [
                'success' => false,
                'message' =>
                    $e->getMessage()
            ];
        }

    } else {

        $ajaxResponse = [
            'success' => false,
            'message' =>
                $order_id <= 0
                    ? 'Invalid order.'
                    : 'Please select a cancellation reason.'
        ];
    }

    /* ---------------------------------------------
       AJAX RESPONSE
    --------------------------------------------- */

    if ($isAjaxRequest) {

        header(
            'Content-Type: application/json; charset=utf-8'
        );

        echo json_encode(
            $ajaxResponse,
            JSON_UNESCAPED_UNICODE
        );

        exit;
    }

    /* ---------------------------------------------
       NORMAL POST FALLBACK
    --------------------------------------------- */

    staffOrdersRedirect(
        $posted_status,
        $posted_search,
        $posted_page,
        $ajaxResponse['success']
            ? 'cancelled'
            : null
    );
}

/* =========================================================
   UPDATE ORDER STATUS
   Payment verification and order updates are transactional.
========================================================= */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['update_status'])
) {

    $ajaxResponse = [
        'success' => false,
        'message' => 'The order status could not be updated.'
    ];

    $order_id = (int)($_POST['order_id'] ?? 0);
    $new_status = trim((string)($_POST['status'] ?? ''));

    $posted_status = trim(
        (string)($_POST['status_filter'] ?? 'pending_verification')
    );
    $posted_search = trim((string)($_POST['q'] ?? ''));
    $posted_page = max(1, (int)($_POST['page'] ?? 1));

    if (!in_array($posted_status, $valid_filters, true)) {
        $posted_status = 'pending_verification';
    }

    $allowed_statuses = [
        'order_queue',
        'confirmed',
        'preparing',
        'ready',
        'completed'
    ];

    $emailSent = null;
    $emailError = null;
    $orderData = null;
    $current_status = '';
    $orderIdentifier = 'Order';
    $stale_status_transition = false;

    if (
        $order_id <= 0
        || !in_array($new_status, $allowed_statuses, true)
    ) {
        $ajaxResponse['message'] = 'Invalid order or status.';
    } else {
        try {
            $pdo->beginTransaction();

            /* Lock the order to prevent two simultaneous status changes. */
            $orderStmt = $pdo->prepare("
                SELECT
                    id,
                    customer_id,
                    order_number,
                    claim_number,
                    status,
                    payment_method,
                    payment_screenshot,
                    total_amount
                FROM orders
                WHERE id = ?
                LIMIT 1
                FOR UPDATE
            ");

            $orderStmt->execute([$order_id]);
            $orderData = $orderStmt->fetch(PDO::FETCH_ASSOC);

            if (!$orderData) {
                throw new RuntimeException('Order not found.');
            }

            $current_status = (string)$orderData['status'];
            $orderIdentifier = $orderData['order_number']
                ?: ($orderData['claim_number'] ?: 'Order');

            /*
             * IDEMPOTENT GCASH REVIEW
             *
             * If a repeated request arrives after the first request has
             * already verified this GCash payment and moved the order,
             * return success for that exact completed state. This prevents
             * a false failure toast without allowing an unverified payment
             * or a different status transition.
             */
            $alreadyProcessedGcashReview = (
                $current_status === 'order_queue'
                && $new_status === 'order_queue'
                && strtolower(trim((string)$orderData['payment_method'])) === 'gcash'
            );

            if ($alreadyProcessedGcashReview) {
                $alreadyProcessedPaymentStmt = $pdo->prepare("
                    SELECT
                        payment_method,
                        amount,
                        proof_image,
                        is_verified
                    FROM payments
                    WHERE order_id = ?
                    LIMIT 1
                    FOR UPDATE
                ");
                $alreadyProcessedPaymentStmt->execute([$order_id]);
                $alreadyProcessedPayment = $alreadyProcessedPaymentStmt->fetch(PDO::FETCH_ASSOC);

                $orderProof = trim((string)($orderData['payment_screenshot'] ?? ''));
                $recordProof = trim((string)($alreadyProcessedPayment['proof_image'] ?? ''));

                $alreadyVerifiedCorrectly = (
                    $alreadyProcessedPayment
                    && strtolower(trim((string)$alreadyProcessedPayment['payment_method'])) === 'gcash'
                    && (int)$alreadyProcessedPayment['is_verified'] === 1
                    && round((float)$alreadyProcessedPayment['amount'], 2) === round((float)$orderData['total_amount'], 2)
                    && $orderProof !== ''
                    && $recordProof !== ''
                    && hash_equals($orderProof, $recordProof)
                );

                if (!$alreadyVerifiedCorrectly) {
                    throw new RuntimeException(
                        'The order is already in the queue, but its GCash verification record is inconsistent. Please inspect the payment record.'
                    );
                }

                $pdo->commit();

                $ajaxResponse = [
                    'success' => true,
                    'already_processed' => true,
                    'order_id' => $order_id,
                    'previous_status' => 'pending_verification',
                    'new_status' => 'order_queue',
                    'message' => "GCash payment for order {$orderIdentifier} was already verified and the order is in Order Queue.",
                    'email_sent' => null,
                    'email_error' => null
                ];

            } elseif ($current_status === $new_status) {

                /*
                 * A duplicate click can arrive after another request has
                 * already moved this order to the requested status. Treat
                 * that exact state as an idempotent success: do not create
                 * duplicate history/notifications and, importantly, let
                 * the browser refresh the current order list.
                 */
                $pdo->commit();

                $currentStatusLabel = ucwords(
                    str_replace('_', ' ', $current_status)
                );

                $ajaxResponse = [
                    'success' => true,
                    'already_processed' => true,
                    'order_id' => $order_id,
                    'previous_status' => $current_status,
                    'new_status' => $current_status,
                    'message' => "Order {$orderIdentifier} is already in {$currentStatusLabel} status.",
                    'email_sent' => null,
                    'email_error' => null
                ];

            } else {

                $allowed_transitions = [
                    'pending_verification' => ['order_queue'],
                    'order_queue' => ['confirmed'],
                    'confirmed' => ['preparing'],
                    'preparing' => ['ready'],
                    'ready' => ['completed'],
                    'completed' => [],
                    'cancelled' => []
                ];

                $next_statuses = $allowed_transitions[$current_status] ?? [];

                if (!in_array($new_status, $next_statuses, true)) {
                    $stale_status_transition = true;
                    throw new RuntimeException(
                        'This order has already moved to a different status. The order list will be refreshed.'
                    );
                }

                /* Payment and order checks are both enforced by PHP. */
                staffVerifyPaymentForStatusTransition(
                    $pdo,
                    $orderData,
                    $current_status,
                    $new_status,
                    $staff_id
                );

                if ($new_status === 'completed') {
                    $stmt = $pdo->prepare("
                        UPDATE orders
                        SET status = ?, closed_at = NOW()
                        WHERE id = ? AND status = ?
                    ");

                    $stmt->execute([
                        $new_status,
                        $order_id,
                        $current_status
                    ]);
                } elseif ($new_status === 'ready') {
                    /* Store Ready time explicitly in Manila local time. */
                    $readyAtForDatabase = (new DateTimeImmutable(
                        'now',
                        new DateTimeZone('Asia/Manila')
                    ))->format('Y-m-d H:i:s');

                    $stmt = $pdo->prepare("
                        UPDATE orders
                        SET status = ?, ready_at = ?
                        WHERE id = ? AND status = ?
                    ");

                    $stmt->execute([
                        $new_status,
                        $readyAtForDatabase,
                        $order_id,
                        $current_status
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE orders
                        SET status = ?
                        WHERE id = ? AND status = ?
                    ");

                    $stmt->execute([
                        $new_status,
                        $order_id,
                        $current_status
                    ]);
                }

                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException(
                        'The order status could not be updated. Please refresh and try again.'
                    );
                }

                $pdo->commit();

                $notificationMap = [
                    'order_queue' => [
                        'type' => null,
                        'message' => "Order {$orderIdentifier} moved to Order Queue."
                    ],
                    'confirmed' => [
                        'type' => 'order_confirmed',
                        'message' => "Your order {$orderIdentifier} has been confirmed."
                    ],
                    'preparing' => [
                        'type' => 'order_preparing',
                        'message' => "Your order {$orderIdentifier} is now being prepared."
                    ],
                    'ready' => [
                        'type' => 'order_ready',
                        'message' => "Your order {$orderIdentifier} is ready for pick-up."
                    ],
                    'completed' => [
                        'type' => 'order_completed',
                        'message' => "Your order {$orderIdentifier} has been completed."
                    ]
                ];

                $notification = $notificationMap[$new_status];

                if (
                    !empty($orderData['customer_id'])
                    && $notification['type'] !== null
                ) {
                    try {
                        $notificationStmt = $pdo->prepare("
                            INSERT INTO notifications
                            (
                                recipient_role,
                                recipient_id,
                                type,
                                message,
                                reference_id
                            )
                            VALUES ('customer', ?, ?, ?, ?)
                        ");

                        $notificationStmt->execute([
                            $orderData['customer_id'],
                            $notification['type'],
                            $notification['message'],
                            $order_id
                        ]);
                    } catch (Throwable $notificationError) {
                        error_log(
                            'Staff order notification failed: '
                            . $notificationError->getMessage()
                        );
                    }

                    /* Send the existing Preparing email without rolling back the order. */
                    if ($new_status === 'preparing') {
                        try {
                            $customerStmt = $pdo->prepare("
                                SELECT full_name, email
                                FROM customers
                                WHERE id = ?
                                LIMIT 1
                            ");

                            $customerStmt->execute([$orderData['customer_id']]);
                            $customerData = $customerStmt->fetch(PDO::FETCH_ASSOC);

                            if (!$customerData) {
                                throw new RuntimeException('Customer record not found.');
                            }

                            if (empty($customerData['email'])) {
                                throw new RuntimeException('Customer has no email address.');
                            }

                            sendOrderPreparingEmail(
                                $customerData['email'],
                                $customerData['full_name'] ?? 'Customer',
                                $orderIdentifier,
                                $orderData['claim_number'] ?? ''
                            );

                            $emailSent = true;
                        } catch (Throwable $e) {
                            $emailSent = false;
                            $emailError = $e->getMessage();

                            error_log(
                                'Staff preparing email failed: '
                                . $e->getMessage()
                            );
                        }
                    }
                }

                $ajaxResponse = [
                    'success' => true,
                    'order_id' => $order_id,
                    'previous_status' => $current_status,
                    'new_status' => $new_status,
                    'message' => $notification['message'],
                    'email_sent' => $emailSent,
                    'email_error' => $emailError
                ];
            }

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'Staff order status update failed: '
                . $e->getMessage()
            );

            $ajaxResponse = [
                'success' => false,
                'stale_status' => $stale_status_transition,
                'current_status' => $current_status,
                'message' => $e->getMessage()
            ];
        }
    }

    if ($isAjaxRequest) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($ajaxResponse, JSON_UNESCAPED_UNICODE);
        exit;
    }

    staffOrdersRedirect(
        $posted_status,
        $posted_search,
        $posted_page,
        $ajaxResponse['success'] ? $new_status : null
    );
}

/* =========================================================
   STATUS COUNTS
========================================================= */
$countStmt = $pdo->query("
    SELECT
        COUNT(*) AS all_count,
        SUM(status = 'order_queue') AS order_queue_count,
        SUM(
            status = 'pending_verification'
        ) AS pending_verification_count,
        SUM(status = 'confirmed') AS confirmed_count,
        SUM(status = 'preparing') AS preparing_count,
        SUM(status = 'ready') AS ready_count,
        SUM(status = 'completed') AS completed_count,
        SUM(status = 'cancelled') AS cancelled_count
    FROM orders
");

/* =========================================================
   PENDING GCash REFUND COUNT
   Staff can see how many cancelled GCash orders are awaiting Admin review.
   Refund details, approval, rejection, and payout remain Admin-only.
========================================================= */
$pendingRefundStmt = $pdo->query("
    SELECT COUNT(*)
    FROM orders
    WHERE status = 'cancelled'
      AND refund_status = 'pending'
      AND LOWER(TRIM(COALESCE(payment_method, ''))) = 'gcash'
      AND (
          (payment_screenshot IS NOT NULL AND TRIM(payment_screenshot) <> '')
          OR EXISTS (
              SELECT 1
              FROM payments p
              WHERE p.order_id = orders.id
                AND p.gcash_reference IS NOT NULL
                AND p.gcash_reference <> ''
          )
      )
      AND COALESCE(cancellation_reason, '') <> 'Customer did not collect order (No-show)'
");

$pending_refund_count = (int)$pendingRefundStmt->fetchColumn();

$counts = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$all_count = (int)($counts['all_count'] ?? 0);
$order_queue_count = (int)($counts['order_queue_count'] ?? 0);
$pending_verification_count = (int)($counts['pending_verification_count'] ?? 0);
$pending_count = $pending_verification_count;
$confirmed_count = (int)($counts['confirmed_count'] ?? 0);
$preparing_count = (int)($counts['preparing_count'] ?? 0);
$ready_count = (int)($counts['ready_count'] ?? 0);
$completed_count = (int)($counts['completed_count'] ?? 0);
$cancelled_count = (int)($counts['cancelled_count'] ?? 0);

/* =========================================================
   ORDER QUERY
========================================================= */
$where = [];
$params = [];

/*
 * =========================================================
 * ORDER FILTER
 *
 * Without a search:
 *      Show only the currently selected workflow.
 *
 * With a search:
 *      Search across ALL ACTIVE workflow orders:
 *      Pending Verification
 *      Confirmed
 *      Preparing
 *      Ready
 *
 * Cancelled orders remain in their separate historical
 * section below.
 * =========================================================
 */

if ($search !== '') {

    $where[] = "
        o.status IN (
            'order_queue',
            'pending_verification',
            'confirmed',
            'preparing',
            'ready'
        )
    ";

} else {

    $where[] = 'o.status = ?';
    $params[] = $selected_status;

}

/*
 * =========================================================
 * SEARCH CONDITIONS
 * =========================================================
 */

if ($search !== '') {

    $where[] = "
        (
            o.order_number LIKE ?
            OR o.claim_number LIKE ?
            OR o.customer_name LIKE ?
            OR o.contact_number LIKE ?
            OR CAST(o.id AS CHAR) LIKE ?
        )
    ";

    $search_value =
        '%' . $search . '%';

    $params[] = $search_value;
    $params[] = $search_value;
    $params[] = $search_value;
    $params[] = $search_value;
    $params[] = $search_value;
}

$where_sql = $where
    ? 'WHERE ' . implode(' AND ', $where)
    : '';

$totalStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM orders o
    {$where_sql}
");

$totalStmt->execute($params);

$total_orders = (int)$totalStmt->fetchColumn();

$total_pages = max(1, (int)ceil($total_orders / $per_page));

if ($page > $total_pages) {
    $page = $total_pages;
}

$offset = ($page - 1) * $per_page;

/* Active orders are shown oldest first so the Staff sees
 * earlier orders first in the pick-up workflow. */
/* FIFO: the customer who placed the order first stays at the front.
 * created_at is the primary queue order; id breaks ties when orders share
 * the exact same timestamp. */
$order_by = 'o.created_at ASC, o.id ASC';

$orderSql = "
    SELECT
        o.*,
        UNIX_TIMESTAMP(o.updated_at) AS updated_at_unix
    FROM orders o
    {$where_sql}
    ORDER BY {$order_by}
    LIMIT {$per_page} OFFSET {$offset}
";

$orderStmt = $pdo->prepare($orderSql);
$orderStmt->execute($params);

$orders = $orderStmt->fetchAll(PDO::FETCH_ASSOC);

/*
 * Put the requested active order on the page where it belongs.
 * Active orders use created_at ASC, id ASC.
 */
if (
    $target_order_id > 0
    && isset($targetOrder)
    && $targetOrder
    && in_array((string)$targetOrder['status'], $valid_filters, true)
) {
    $targetStatus = (string)$targetOrder['status'];

    $targetPageStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM orders o
        WHERE o.status = ?
          AND (
              o.created_at < ?
              OR (
                  o.created_at = ?
                  AND o.id <= ?
              )
          )
    ");

    $targetCreatedAt = (string)$targetOrder['created_at'];

    $targetPageStmt->execute([
        $targetStatus,
        $targetCreatedAt,
        $targetCreatedAt,
        $target_order_id
    ]);

    $targetPosition = max(
        1,
        (int)$targetPageStmt->fetchColumn()
    );

    $page = max(
        1,
        (int)ceil($targetPosition / $per_page)
    );

    $offset = ($page - 1) * $per_page;

    /*
     * Rebuild the active-order query after recalculating the target page.
     * The original query still contained the previous OFFSET, which meant
     * a View Order target on page 2+ could be calculated correctly but
     * the old page of orders would still be fetched.
     */
    $orderSql = "
        SELECT
            o.*,
            UNIX_TIMESTAMP(o.updated_at) AS updated_at_unix
        FROM orders o
        {$where_sql}
        ORDER BY {$order_by}
        LIMIT {$per_page} OFFSET {$offset}
    ";

    $orderStmt = $pdo->prepare($orderSql);
    $orderStmt->execute($params);
    $orders = $orderStmt->fetchAll(PDO::FETCH_ASSOC);
}

/* =========================================================
   CANCELLED ORDERS QUERY
   Cancelled records are always displayed in a separate
   section below the active workflow, newest cancellation first.
========================================================= */
$cancelled_where = [
    $history_status === 'all'
        ? "o.status IN ('completed', 'cancelled')"
        : ($history_status === 'completed'
            ? "o.status = 'completed'"
            : "o.status = 'cancelled'")
];
$cancelled_params = [];

switch ($cancelled_period) {
    case 'today':
        $cancelled_where[] = 'DATE(o.closed_at) = CURDATE()';
        break;

    case 'last_week':
        /*
         * Previous calendar week: Monday through Sunday.
         */
        $cancelled_where[] = "
            DATE(o.closed_at) BETWEEN
                DATE_SUB(CURDATE(), INTERVAL (WEEKDAY(CURDATE()) + 7) DAY)
                AND DATE_SUB(CURDATE(), INTERVAL (WEEKDAY(CURDATE()) + 1) DAY)
        ";
        break;

    case 'last_month':
        $cancelled_where[] = "
            DATE(o.closed_at) BETWEEN
                DATE_FORMAT(
                    DATE_SUB(CURDATE(), INTERVAL 1 MONTH),
                    '%Y-%m-01'
                )
                AND LAST_DAY(
                    DATE_SUB(CURDATE(), INTERVAL 1 MONTH)
                )
        ";
        break;

    case 'specific_month':
        $cancelledMonthStart =
            $cancelled_month . '-01';

        $cancelledMonthStartObject =
            new DateTime($cancelledMonthStart);

        $cancelledMonthEndObject =
            (clone $cancelledMonthStartObject)->modify('+1 month');

        $cancelled_where[] = "
            o.closed_at >= ?
            AND o.closed_at < ?
        ";

        $cancelled_params[] =
            $cancelledMonthStartObject->format('Y-m-d 00:00:00');

        $cancelled_params[] =
            $cancelledMonthEndObject->format('Y-m-d 00:00:00');
        break;
}

/* GCash reference search: typed with or without spaces/dashes. */
$historyGcashSearchSql = '';
$historyGcashSearchDigits = preg_replace('/\D+/', '', $cancelled_search);

if ($cancelled_search !== '' && strlen($historyGcashSearchDigits) >= 4) {
    try {
        $pdo->query('SELECT gcash_reference FROM payments LIMIT 0');
        $historyGcashSearchSql = "
            OR EXISTS (
                SELECT 1
                FROM payments gp
                WHERE gp.order_id = o.id
                  AND gp.gcash_reference LIKE ?
            )
        ";
    } catch (Throwable $e) {
        $historyGcashSearchSql = '';
    }
}

if ($cancelled_search !== '') {
    $cancelled_where[] = "
        (
            o.order_number LIKE ?
            OR o.claim_number LIKE ?
            OR o.customer_name LIKE ?
            OR o.contact_number LIKE ?
            OR CAST(o.id AS CHAR) LIKE ?
            {$historyGcashSearchSql}
        )
    ";

    $cancelled_search_value = '%' . $cancelled_search . '%';

    $cancelled_params[] = $cancelled_search_value;
    $cancelled_params[] = $cancelled_search_value;
    $cancelled_params[] = $cancelled_search_value;
    $cancelled_params[] = $cancelled_search_value;
    $cancelled_params[] = $cancelled_search_value;

    if ($historyGcashSearchSql !== '') {
        $cancelled_params[] = '%' . $historyGcashSearchDigits . '%';
    }
}

$cancelled_where_sql =
    'WHERE ' . implode(' AND ', $cancelled_where);

$cancelledTotalStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM orders o
    {$cancelled_where_sql}
");

$cancelledTotalStmt->execute($cancelled_params);

$cancelled_total_orders =
    (int)$cancelledTotalStmt->fetchColumn();

$cancelled_total_pages = max(
    1,
    (int)ceil(
        $cancelled_total_orders / $cancelled_per_page
    )
);

if ($cancelled_page > $cancelled_total_pages) {
    $cancelled_page = $cancelled_total_pages;
}

$cancelled_offset =
    ($cancelled_page - 1) * $cancelled_per_page;

$cancelledOrderSql = "
    SELECT o.*
    FROM orders o
    {$cancelled_where_sql}
    ORDER BY o.closed_at DESC, o.id DESC
    LIMIT {$cancelled_per_page} OFFSET {$cancelled_offset}
";

$cancelledOrderStmt = $pdo->prepare($cancelledOrderSql);
$cancelledOrderStmt->execute($cancelled_params);

$cancelled_orders =
    $cancelledOrderStmt->fetchAll(PDO::FETCH_ASSOC);

/*
 * Put a requested cancelled order on the correct page using the exact same
 * date/search filters and closed_at DESC, id DESC ordering as the history table.
 */
if (
    $target_order_id > 0
    && isset($targetOrder)
    && $targetOrder
    && in_array((string)$targetOrder['status'], ['completed', 'cancelled'], true)
) {
    $targetClosedAt = (string)($targetOrder['closed_at'] ?? '');

    if ($targetClosedAt !== '') {
        $targetCancelledPageStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM orders o
            {$cancelled_where_sql}
              AND (
                  o.closed_at > ?
                  OR (o.closed_at = ? AND o.id >= ?)
              )
        ");

        $targetCancelledPageStmt->execute(array_merge(
            $cancelled_params,
            [$targetClosedAt, $targetClosedAt, $target_order_id]
        ));

        $targetCancelledPosition = (int)$targetCancelledPageStmt->fetchColumn();

        /* Only reposition when the target is included in the selected filters. */
        if ($targetCancelledPosition > 0) {
            $cancelled_page = max(
                1,
                (int)ceil($targetCancelledPosition / $cancelled_per_page)
            );

            $cancelled_offset = ($cancelled_page - 1) * $cancelled_per_page;

            /* Rebuild LIMIT/OFFSET: the original SQL contains the old offset. */
            $cancelledOrderSql = "
                SELECT o.*
                FROM orders o
                {$cancelled_where_sql}
                ORDER BY o.closed_at DESC, o.id DESC
                LIMIT {$cancelled_per_page} OFFSET {$cancelled_offset}
            ";

            $cancelledOrderStmt = $pdo->prepare($cancelledOrderSql);
            $cancelledOrderStmt->execute($cancelled_params);
            $cancelled_orders = $cancelledOrderStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

/* =========================================================
   LOAD ORDER ITEMS FOR ACTIVE + PENDING REFUNDS + CANCELLED CURRENT PAGE
========================================================= */
$order_items = [];

$items_order_ids = array_values(array_unique(array_merge(
    array_map('intval', array_column($orders, 'id')),
    array_map('intval', array_column($cancelled_orders, 'id'))
)));

if ($items_order_ids) {

    $placeholders = implode(
        ',',
        array_fill(0, count($items_order_ids), '?')
    );

    $itemStmt = $pdo->prepare("
        SELECT *
        FROM order_items
        WHERE order_id IN ({$placeholders})
        ORDER BY order_id ASC, id ASC
    ");

    $itemStmt->execute($items_order_ids);

    foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $order_items[(int)$item['order_id']][] = $item;
    }
}

/* =========================================================
   LOAD GCASH REFERENCE NUMBERS (walk-in GCash payments)
========================================================= */
$gcash_references = [];

if ($items_order_ids) {
    try {
        $refStmt = $pdo->prepare("
            SELECT order_id, gcash_reference
            FROM payments
            WHERE order_id IN ({$placeholders})
              AND gcash_reference IS NOT NULL
              AND gcash_reference <> ''
        ");
        $refStmt->execute($items_order_ids);

        foreach ($refStmt->fetchAll(PDO::FETCH_ASSOC) as $refRow) {
            $gcash_references[(int)$refRow['order_id']] = (string)$refRow['gcash_reference'];
        }
    } catch (Throwable $e) {
        /* The page still works before the gcash_reference column is added. */
        error_log('Staff GCash reference lookup failed: ' . $e->getMessage());
    }
}

function staffFormatGcashReference(string $reference): string
{
    /* 13 digits shown as 4-3-6 groups, the way GCash receipts print them. */
    return preg_match('/^\d{13}$/', $reference)
        ? substr($reference, 0, 4) . ' ' . substr($reference, 4, 3) . ' ' . substr($reference, 7)
        : $reference;
}

/* =========================================================
   LOAD CANCELLATION ACTORS FOR CANCELLED ORDERS
========================================================= */
$cancelled_by_roles = [];
$cancelled_by_ids = [];

if (!empty($cancelled_orders)) {

    $history_order_ids = array_values(array_unique(
        array_map('intval', array_column($cancelled_orders, 'id'))
    ));

    $history_placeholders = implode(
        ',',
        array_fill(0, count($history_order_ids), '?')
    );

    $historyActorStmt = $pdo->prepare("
        SELECT h.order_id, h.actor_role, h.actor_id
        FROM order_status_history h
        INNER JOIN (
            SELECT order_id, MAX(id) AS max_id
            FROM order_status_history
            WHERE to_status = 'cancelled'
              AND order_id IN ({$history_placeholders})
            GROUP BY order_id
        ) latest ON latest.max_id = h.id
        WHERE h.to_status = 'cancelled'
    ");

    $historyActorStmt->execute($history_order_ids);

    foreach ($historyActorStmt->fetchAll(PDO::FETCH_ASSOC) as $historyRow) {

        $historyOrderId =
            (int)$historyRow['order_id'];

        $cancelled_by_roles[$historyOrderId] =
            strtolower(trim((string)$historyRow['actor_role']));

        $cancelled_by_ids[$historyOrderId] =
            (int)($historyRow['actor_id'] ?? 0);
    }
}

/* =========================================================
   DISPLAY HELPERS
========================================================= */
$status_labels = [
    'order_queue' => 'Order Queue',
    'pending_verification' => 'Pending',
    'confirmed' => 'Confirmed',
    'preparing' => 'Preparing',
    'ready' => 'Ready for Pick-up',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled'
];

function staffStatusLabel(
    string $status,
    array $labels
): string {
    return $labels[$status]
        ?? ucwords(str_replace('_', ' ', $status));
}

function staffFormatTime(?string $value): string {

    if (!$value) {
        return '—';
    }

    $timestamp = strtotime($value);

    return $timestamp
        ? date('h:i A', $timestamp)
        : '—';
}

function staffFormatDateTime(?string $value): string {

    if (!$value) {
        return '—';
    }

    $timestamp = strtotime($value);

    return $timestamp
        ? date('M d, Y h:i A', $timestamp)
        : '—';
}

function staffCancellationActorLabel(?string $role): string {

    $role = strtolower(trim((string)$role));

    return match ($role) {
        'admin' => 'Admin',
        'staff' => 'Staff',
        'customer' => 'Customer',
        'system' => 'System',
        default => 'Unknown'
    };
}

function staffAssetPath(?string $path): string {

    $path = trim((string)$path);

    if ($path === '') {
        return '';
    }

    if (
        str_starts_with($path, '../') ||
        str_starts_with($path, 'http://') ||
        str_starts_with($path, 'https://')
    ) {
        return $path;
    }

    if (str_starts_with($path, 'assets/')) {
        return '../' . $path;
    }

    return '../assets/uploads/receipts/' . ltrim($path, '/');
}

/* Name + ID number of the PWD / Senior cardholder.
   Uses the dedicated columns when they exist, otherwise reads the line
   "PWD ID: Name / 12345" that walk-in-order.php writes into orders.notes. */
function staffDiscountCardholder(array $order): array
{
    $name = trim((string)($order['discount_id_name'] ?? ''));
    $idNumber = trim((string)($order['discount_id_number'] ?? ''));

    if ($name === '' || $idNumber === '') {
        foreach (explode(' | ', (string)($order['notes'] ?? '')) as $part) {
            $part = trim($part);
            if (preg_match('/^(?:PWD|SENIOR) ID:\s*(.+)$/i', $part, $m)) {
                $pos = strrpos($m[1], ' / ');
                if ($pos !== false) {
                    $name = trim(substr($m[1], 0, $pos));
                    $idNumber = trim(substr($m[1], $pos + 3));
                }
                break;
            }
        }
    }

    return ['name' => $name, 'id' => $idNumber];
}

function staffGetAddonPriceMap(): array
{
    static $priceMap = null;

    if ($priceMap !== null) {
        return $priceMap;
    }

    $priceMap = [];

    try {
        global $pdo;

        $stmt = $pdo->query("
            SELECT name, price
            FROM addons
        ");

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = trim((string)($row['name'] ?? ''));

            if ($name !== '') {
                $priceMap[strtolower($name)] = (float)($row['price'] ?? 0);
            }
        }
    } catch (Throwable $e) {
        /*
         * The order details page must still render even if the add-ons
         * table cannot be read. The caller will use its safe fallback.
         */
        error_log('Staff add-on price lookup failed: ' . $e->getMessage());
    }

    return $priceMap;
}

function staffGetAddonPrice(string $name, float $fallback = 0.00): float
{
    $normalizedName = strtolower(trim($name));

    if ($normalizedName === '') {
        return $fallback;
    }

    $priceMap = staffGetAddonPriceMap();

    return array_key_exists($normalizedName, $priceMap)
        ? (float)$priceMap[$normalizedName]
        : $fallback;
}

function staffGetAddons(?string $addons): array
{
    if ($addons === null || trim($addons) === '') {
        return [];
    }

    $decoded = json_decode((string)$addons, true);

    /*
     * Order records may contain either:
     *   1. add-on names only, or
     *   2. add-on names together with an explicitly saved price.
     *
     * When the order record contains only the name, use the current
     * price from the real `addons` table instead of hard-coding ₱10.00.
     */
    $defaultAddonPrice = 10.00;

    if (!is_array($decoded)) {
        $rawParts = array_map('trim', explode(',', (string)$addons));
        $result = [];

        foreach ($rawParts as $name) {
            if ($name !== '') {
                $result[] = [
                    'name' => $name,
                    'price' => staffGetAddonPrice(
                        $name,
                        $defaultAddonPrice
                    )
                ];
            }
        }

        return $result;
    }

    $result = [];

    foreach ($decoded as $key => $addon) {
        if (is_array($addon)) {
            $name = $addon['name']
                ?? $addon['addon_name']
                ?? $addon['title']
                ?? null;

            if ($name !== null && trim((string)$name) !== '') {
                $name = trim((string)$name);

                $savedPrice = $addon['price']
                    ?? $addon['addon_price']
                    ?? null;

                $price = is_numeric($savedPrice)
                    ? (float)$savedPrice
                    : staffGetAddonPrice(
                        $name,
                        $defaultAddonPrice
                    );

                $result[] = [
                    'name' => $name,
                    'price' => $price
                ];
            }
        } elseif (!is_int($key) && is_numeric($addon)) {
            $name = trim((string)$key);

            $result[] = [
                'name' => $name,
                'price' => (float)$addon
            ];
        } elseif (is_scalar($addon) && trim((string)$addon) !== '') {
            $name = trim((string)$addon);

            $result[] = [
                'name' => $name,
                'price' => staffGetAddonPrice(
                    $name,
                    $defaultAddonPrice
                )
            ];
        }
    }

    return $result;
}

function staffOrdersUrl(array $overrides = []): string {

    $query = [
        'status' => $GLOBALS['selected_status'],
        'q' => $GLOBALS['search'],
        'page' => $GLOBALS['page'],
        'cancelled_q' => $GLOBALS['cancelled_search'],
        'cancelled_period' => $GLOBALS['cancelled_period'],
        'cancelled_month' => $GLOBALS['cancelled_month'],
        'cancelled_page' => $GLOBALS['cancelled_page'],
        'cancelled_open' => $GLOBALS['cancelled_open'],
        'history_status' => $GLOBALS['history_status'] === 'all' ? '' : $GLOBALS['history_status'],
    ];

    foreach ($overrides as $key => $value) {
        $query[$key] = $value;
    }

    foreach ($query as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
        }
    }

    return 'index.php?' . http_build_query($query);
}

$workflow_cards = [
    'pending_verification' => [
        'label' => 'Pending',
        'count' => $pending_verification_count,
        'icon' => 'bi-hourglass-split'
    ],
    'order_queue' => [
        'label' => 'Order Queue',
        'count' => $order_queue_count,
        'icon' => 'bi-inbox'
    ],
    'confirmed' => [
        'label' => 'Confirmed',
        'count' => $confirmed_count,
        'icon' => 'bi-check-circle'
    ],
    'preparing' => [
        'label' => 'Preparing',
        'count' => $preparing_count,
        'icon' => 'bi-cup-hot'
    ],
    'ready' => [
        'label' => 'Ready for Pick-up',
        'count' => $ready_count,
        'icon' => 'bi-bag-check'
    ]
];

$orders_section_title = 'ACTIVE ORDERS';
$orders_section_description =
    'Manage orders currently moving through the pick-up workflow.';

$cancelled_period_labels = [
    'today' => 'Today',
    'last_week' => 'Last week',
    'last_month' => 'Last month',
    'specific_month' => 'Specific month'
];

$page_title = 'Staff Orders';
require_once '../includes/header.php';
?>

<link
    rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
>

<style>
    body {
        background: #F7F5F2;
    }

    .staff-orders-page {
        min-height: 100vh;
    }

   .staff-main {
    min-width: 0;
}

    /* The desktop sidebar is fixed, so reserve its width for the page content. */
    @media (min-width: 992px) {
        .staff-orders-page > .staff-main {
            margin-left: 260px;
            width: calc(100% - 260px);
        }
    }

    /* The sidebar becomes an off-canvas drawer on smaller screens. */
    @media (max-width: 991.98px) {
        .staff-orders-page > .staff-main {
            margin-left: 0;
            width: 100%;
        }
    }

    .staff-content {
        padding: 28px;
    }

    .page-title {
        color: #4A3525;
    }

    .page-description {
        color: #7B6D62;
    }

    .order-search-form {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        margin-bottom: 18px;
    }

    .search-box {
        position: relative;
        width: 280px;
    }

    .search-box i {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #6F4E37;
        pointer-events: none;
    }

    .search-box input {
        width: 100%;
        height: 38px;
        padding: 6px 12px 6px 34px;
        border: 1px solid #8B6F5A;
        border-radius: 8px;
        font-size: .82rem;
    }

    .search-actions {
        display: flex;
        gap: 8px;
    }

    .search-actions .btn {
        height: 38px;
        border-radius: 8px;
        font-size: .82rem;
        font-weight: 500;
    }

    .search-button {
        background: #6F4E37;
        border-color: #5A3D2B;
        color: #FFFFFF;
    }

    .search-button:hover {
        background: #5A3D2B;
        color: #FFFFFF;
    }

    .clear-button {
        background: #FFFFFF;
        border: 1px solid #8B6F5A;
        color: #6F4E37;
    }

    .workflow-grid {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 14px;
        margin-bottom: 24px;
    }

    .workflow-card {
        display: flex;
        align-items: center;
        gap: 14px;
        min-height: 86px;
        padding: 14px 16px;
        border: 2px solid #B8A08A;
        border-radius: 12px;
        background: #FFFFFF;
        color: #4A3525;
        text-decoration: none;
        box-shadow: 0 2px 8px rgba(74, 53, 37, .04);
        transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease, background .15s ease;
    }

    .workflow-card:hover {
        box-shadow: 0 4px 12px rgba(74, 53, 37, .08);
        color: #4A3525;
        transform: translateY(-1px);
    }

    .workflow-card.active {
        border-width: 2px;
        box-shadow: 0 4px 12px rgba(74, 53, 37, .08);
    }

    /* Workflow colors */
    .workflow-card.workflow-pending_verification {
        border-color: #D8B56A;
    }

    .workflow-card.workflow-pending_verification:hover,
    .workflow-card.workflow-pending_verification.active {
        background: #FFF8E8;
        border-color: #C7922E;
    }

    .workflow-card.workflow-confirmed {
        border-color: #8DAFD6;
    }

    .workflow-card.workflow-confirmed:hover,
    .workflow-card.workflow-confirmed.active {
        background: #F1F7FF;
        border-color: #4C78A8;
    }

    .workflow-card.workflow-preparing {
        border-color: #AA98D0;
    }

    .workflow-card.workflow-preparing:hover,
    .workflow-card.workflow-preparing.active {
        background: #F4EEFF;
        border-color: #7654A8;
    }

    .workflow-card.workflow-ready {
        border-color: #86B998;
    }

    .workflow-card.workflow-ready:hover,
    .workflow-card.workflow-ready.active {
        background: #F0FAF2;
        border-color: #3F8A55;
    }

    /* Stronger visual treatment for the currently selected process */
    .workflow-card.workflow-pending_verification.active {
        background: #F3D37A;
        border-color: #A66A00;
        box-shadow: 0 4px 14px rgba(166, 106, 0, .16);
    }

    .workflow-card.workflow-confirmed.active {
        background: #A9C9EE;
        border-color: #2E5F97;
        box-shadow: 0 4px 14px rgba(46, 95, 151, .16);
    }

    .workflow-card.workflow-preparing.active {
        background: #C8AFF0;
        border-color: #6B43A1;
        box-shadow: 0 4px 14px rgba(107, 67, 161, .16);
    }

    .workflow-card.workflow-ready.active {
        background: #A8D1B3;
        border-color: #2F6F43;
        box-shadow: 0 4px 14px rgba(47, 111, 67, .16);
    }

    .workflow-card.workflow-pending_verification.active .workflow-icon {
        background: #FFE8AD;
        color: #7A4B00;
    }

    .workflow-card.workflow-confirmed.active .workflow-icon {
        background: #D8E8FB;
        color: #214F82;
    }

    .workflow-card.workflow-preparing.active .workflow-icon {
        background: #E3D7FA;
        color: #52317F;
    }

    .workflow-card.workflow-ready.active .workflow-icon {
        background: #D7EBDD;
        color: #205A34;
    }

    .workflow-card.workflow-pending_verification.active .workflow-label,
    .workflow-card.workflow-pending_verification.active .workflow-count {
        color: #6E4300;
    }

    .workflow-card.workflow-confirmed.active .workflow-label,
    .workflow-card.workflow-confirmed.active .workflow-count {
        color: #214F82;
    }

    .workflow-card.workflow-preparing.active .workflow-label,
    .workflow-card.workflow-preparing.active .workflow-count {
        color: #52317F;
    }

    .workflow-card.workflow-ready.active .workflow-label,
    .workflow-card.workflow-ready.active .workflow-count {
        color: #205A34;
    }

    .workflow-icon {
        width: 44px;
        height: 44px;
        flex: 0 0 44px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 11px;
        font-size: 1.1rem;
    }

    .workflow-pending_verification .workflow-icon {
        background: #FFF1D6;
        color: #8A5A00;
    }

    .workflow-confirmed .workflow-icon {
        background: #E7F1FF;
        color: #285B9A;
    }

    .workflow-preparing .workflow-icon {
        background: #EEE7FF;
        color: #5B3A9A;
    }

    .workflow-ready .workflow-icon {
        background: #E5F6EA;
        color: #23733D;
    }

    .workflow-pending_verification .workflow-label,
    .workflow-pending_verification .workflow-count {
        color: #8A5A00;
    }

    .workflow-confirmed .workflow-label,
    .workflow-confirmed .workflow-count {
        color: #285B9A;
    }

    .workflow-preparing .workflow-label,
    .workflow-preparing .workflow-count {
        color: #5B3A9A;
    }

    .workflow-ready .workflow-label,
    .workflow-ready .workflow-count {
        color: #23733D;
    }

    .workflow-label {
        color: #7B6D62;
        font-size: .76rem;
        line-height: 1.25;
        margin-bottom: 4px;
    }

    .workflow-count {
        color: #4A3525;
        font-size: 1.45rem;
        font-weight: 600;
        line-height: 1;
    }

    /* =========================
       ACTIVE ORDERS SECTION
    ========================= */
    .active-orders-section {
        margin-top: 4px;
        padding: 14px 16px;
        border: 2px solid #6F4E37;
        border-left: 4px solid #4A3525;
        border-radius: 14px;
        background: #FCFAF7;
    }

    .active-section-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding-bottom: 12px;
        margin-bottom: 14px;
        border-bottom: 1px solid #D8C9BD;
    }

    .active-section-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 10px;
        border-radius: 999px;
        background: #EEE4DB;
        color: #5A3D2B;
        border: 1px solid #C9B19D;
        font-size: .68rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .35px;
        white-space: nowrap;
    }

    .active-orders-heading {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin: 0 0 8px;
    }

    .active-orders-title {
        color: #4A3525;
        font-size: 1.08rem;
        font-weight: 600;
        letter-spacing: .2px;
        margin: 0;
    }

    .active-orders-description {
        color: #7B6D62;
        font-size: .8rem;
        margin: 3px 0 0;
    }

    .cancelled-record-bar {
        display: flex;
        justify-content: flex-end;
        margin: -10px 0 18px;
    }
/* =========================
   PENDING REFUNDS DROPDOWN
========================= */
.pending-refunds-section.cancelled-orders-section {
    margin-top: 26px;
    padding: 0;
    border: 2px solid #C9B19D;
    border-left: 4px solid #6F4E37;
    border-radius: 14px;
    background: #FCFAF7;
    overflow: hidden;
}

.pending-refunds-summary {
    padding: 18px 20px;
    background: #FCFAF7;
}

.pending-refunds-summary:hover {
    background: #F7F0EA;
}

.pending-refunds-section[open] .pending-refunds-summary {
    border-bottom: 1px solid #C9B19D;
    background: #F9F3EE;
}

.pending-refunds-heading {
    width: 100%;
    min-width: 0;
}

.pending-refunds-main {
    display: flex;
    align-items: center;
    gap: 14px;
    min-width: 0;
}

.pending-refunds-icon {
    width: 44px;
    height: 44px;
    flex: 0 0 44px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 11px;
    background: #EFE4DA;
    color: #6F4E37;
    font-size: 1.1rem;
}

.pending-refunds-title {
    margin: 0;
    color: #4A3525;
    font-size: .96rem;
    font-weight: 600;
    letter-spacing: .15px;
}

.pending-refunds-description {
    margin: 3px 0 0;
    color: #7B6D62;
    font-size: .78rem;
    line-height: 1.35;
}

.pending-refunds-count {
    min-width: 42px;
    height: 42px;
    padding: 0 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    background: #6F4E37;
    color: #FFFFFF;
    font-size: 1.05rem;
    font-weight: 600;
}

.pending-refunds-content {
    padding: 0 20px 20px;
}

.pending-refund-action-box {
    padding: 16px;
    border: 1px solid #C9B19D;
    border-radius: 12px;
    background: #FCFAF7;
}

.pending-refund-action-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    margin-bottom: 14px;
}

.pending-refund-action-note {
    color: #7B6D62;
    font-size: .74rem;
    text-align: right;
}

.pending-refund-action-buttons {
    display: grid;
    grid-template-columns: minmax(150px, 1.1fr) minmax(150px, 1fr) minmax(140px, .9fr);
    gap: 10px;
    align-items: stretch;
}

.pending-refund-form {
    margin: 0;
    min-width: 0;
}

.pending-refund-btn {
    width: 100%;
    min-height: 44px;
    padding: 10px 14px;
    border-radius: 8px;
    border: 1px solid transparent;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    font-size: .82rem;
    font-weight: 500;
    line-height: 1.2;
    white-space: normal;
    transition: all .15s ease;
}

.pending-refund-btn-proof {
    background: #FFFFFF;
    border-color: #343A40;
    color: #343A40;
}

.pending-refund-btn-proof:hover {
    background: #343A40;
    color: #FFFFFF;
}

.pending-refund-btn-refunded {
    background: #DCEEFF;
    border-color: #9CC9F5;
    color: #175A91;
}

.pending-refund-btn-refunded:hover {
    background: #C8E3FA;
    border-color: #78B7EC;
}

.pending-refund-btn-rejected {
    background: #FFF1F1;
    border-color: #E6A4A4;
    color: #B03A3A;
}

.pending-refund-btn-rejected:hover {
    background: #FFE1E1;
    border-color: #D88383;
}

.pending-refund-proof-modal-body {
    background: #F4F1EE;
    min-height: min(65vh, 680px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.pending-refund-proof-image {
    display: block;
    width: auto;
    max-width: 100%;
    max-height: 68vh;
    object-fit: contain;
    border-radius: 8px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, .10);
}

.refund-rejection-alert {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin-bottom: 18px;
    padding: 11px 12px;
    border: 1px solid #E6A4A4;
    border-radius: 8px;
    background: #FFF5F5;
    color: #8C3030;
    font-size: .82rem;
    line-height: 1.45;
}

.pending-refund-modal-reject-btn {
    background: #B64A4A;
    border-color: #B64A4A;
    color: #FFFFFF;
    font-weight: 500;
}

.pending-refund-modal-reject-btn:hover {
    background: #9F3E3E;
    border-color: #9F3E3E;
    color: #FFFFFF;
}

.refund-proof-upload-info {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin-bottom: 18px;
    padding: 12px 13px;
    border: 1px solid #9CC9F5;
    border-radius: 8px;
    background: #F1F8FF;
    color: #275A82;
    font-size: .82rem;
    line-height: 1.45;
}

.pending-refund-modal-confirm-btn {
    background: #2F7D4A;
    border-color: #2F7D4A;
    color: #FFFFFF;
    font-weight: 500;
}

.pending-refund-modal-confirm-btn:hover {
    background: #25663C;
    border-color: #25663C;
    color: #FFFFFF;
}

@media (max-width: 991.98px) {
    .pending-refund-action-buttons {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .pending-refund-action-note {
        text-align: left;
    }
}

@media (max-width: 767.98px) {
    .pending-refunds-section.cancelled-orders-section {
        margin-top: 18px;
    }

    .pending-refunds-summary {
        padding: 14px;
        gap: 12px;
    }

    .pending-refunds-main {
        align-items: flex-start;
        gap: 10px;
        flex: 1 1 auto;
    }

    .pending-refunds-icon {
        width: 36px;
        height: 36px;
        flex-basis: 36px;
        font-size: .95rem;
        border-radius: 10px;
    }

    .pending-refunds-title {
        font-size: .84rem;
    }

    .pending-refunds-description {
        font-size: .7rem;
    }

    .pending-refunds-count {
        min-width: 36px;
        height: 36px;
        padding: 0 10px;
        font-size: .95rem;
    }

    .pending-refunds-content {
        padding: 0 14px 14px;
    }

    .pending-refund-action-box {
        padding: 12px;
    }

    .pending-refund-action-header {
        align-items: flex-start;
        flex-direction: column;
        gap: 4px;
        margin-bottom: 12px;
    }

    .pending-refund-action-note {
        font-size: .7rem;
        text-align: left;
    }

    .pending-refund-action-buttons {
        grid-template-columns: 1fr;
        gap: 8px;
    }

    .pending-refund-btn {
        min-height: 42px;
        font-size: .78rem;
    }

    .pending-refund-proof-modal-body {
        min-height: 42vh;
        padding: 12px;
    }

    .pending-refund-proof-image {
        max-height: 58vh;
    }

    .pending-refund-modal .modal-dialog {
        margin: .75rem;
    }

    .pending-refund-modal .modal-footer {
        display: grid;
        grid-template-columns: 1fr;
        gap: 8px;
    }

    .pending-refund-modal .modal-footer .btn {
        width: 100%;
        margin: 0;
    }
}

    /* =========================
       CANCELLED ORDERS SECTION
    ========================= */
    .cancelled-orders-section {
        margin-top: 46px;
        padding: 0;
        border: 1px solid #D89A9A;
        border-left: 4px solid #B64A4A;
        border-radius: 14px;
        background: #FFF9F9;
        overflow: hidden;
    }

    .cancelled-section-summary {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 18px;
        padding: 24px;
        cursor: pointer;
        list-style: none;
        user-select: none;
    }

    .cancelled-section-summary::-webkit-details-marker {
        display: none;
    }

    .cancelled-section-summary::marker {
        display: none;
        content: "";
    }

    .cancelled-section-summary:hover {
        background: #FFF4F4;
    }

    .cancelled-orders-section[open] .cancelled-section-summary {
        border-bottom: 1px solid #D89A9A;
        background: #FFF7F7;
    }

    .cancelled-section-content {
        padding: 0 24px 24px;
    }

    .cancelled-toggle {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        flex: 0 0 auto;
        padding: 7px 10px;
        border: 1px solid #D89A9A;
        border-radius: 999px;
        background: #FFFFFF;
        color: #A33A3A;
        font-size: .72rem;
        font-weight: 500;
        white-space: nowrap;
    }

    .cancelled-toggle-label {
        display: none;
    }

    .cancelled-toggle-open {
        display: inline;
    }

    .cancelled-toggle-icon {
        transition: transform .2s ease;
    }

    .cancelled-orders-section[open] .cancelled-toggle-open {
        display: none;
    }

    .cancelled-orders-section[open] .cancelled-toggle-close {
        display: inline;
    }

    .cancelled-orders-section[open] .cancelled-toggle-icon {
        transform: rotate(180deg);
    }

    .cancelled-history-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 10px;
        border-radius: 999px;
        background: #FCE3E3;
        color: #8E2F2F;
        border: 1px solid #D89A9A;
        font-size: .68rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .35px;
        white-space: nowrap;
    }

    .cancelled-section-heading {
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 14px;
    }

    .cancelled-section-title {
        color: #A33A3A;
        font-size: 1.08rem;
        font-weight: 600;
        letter-spacing: .2px;
        margin: 0;
    }

    .cancelled-section-description {
        color: #7B6D62;
        font-size: .8rem;
        margin: 3px 0 0;
    }

    .cancelled-filter-form {
        display: flex;
        align-items: flex-end;
        gap: 8px;
        flex-wrap: wrap;
        margin-bottom: 18px;
        padding: 12px;
        border: 1px solid #C98C8C;
        border-radius: 10px;
        background: #FFF7F7;
    }

    .cancelled-filter-group {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }

    .cancelled-filter-group label {
        color: #7B6D62;
        font-size: .68rem;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: .3px;
    }

    .cancelled-filter-group input,
    .cancelled-filter-group select {
        height: 38px;
        border: 1px solid #C98C8C;
        border-radius: 8px;
        padding: 6px 10px;
        font-size: .82rem;
        color: #4A3525;
        background: #FFFFFF;
        outline: none;
        box-sizing: border-box;
    }

    .cancelled-filter-period {
        min-width: 155px;
    }

    .cancelled-filter-month {
        min-width: 155px;
    }

    .pending-refund-filter-date {
        min-width: 155px;
    }

    .pending-refund-filter-status {
        min-width: 155px;
    }

    .pending-refund-filter-form {
        border-color: #C9B19D;
        background: #FCFAF7;
    }

    .pending-refund-filter-form .cancelled-filter-group input,
    .pending-refund-filter-form .cancelled-filter-group select {
        border-color: #C9B19D;
    }

    .pending-refund-filter-form .cancelled-filter-group input:focus,
    .pending-refund-filter-form .cancelled-filter-group select:focus {
        border-color: #6F4E37;
        box-shadow: 0 0 0 2px rgba(111,78,55,.10);
    }

    .pending-refund-filter-form .cancelled-filter-apply {
        background: #6F4E37;
        border-color: #5A3D2B;
        color: #FFFFFF;
    }

    .pending-refund-filter-form .cancelled-filter-apply:hover {
        background: #5A3D2B;
        border-color: #4A3525;
        color: #FFFFFF;
    }

    .pending-refund-filter-form .cancelled-clear-link {
        border: 1px solid #8B6F5A;
        color: #6F4E37;
        background: #FFFFFF;
    }

    .pending-refund-filter-form .cancelled-clear-link:hover {
        background: #F4EEE9;
        color: #5A3D2B;
    }

    .cancelled-filter-search {
        width: 290px;
        max-width: 100%;
    }

    .cancelled-filter-group input:focus,
    .cancelled-filter-group select:focus {
        border-color: #A33A3A;
        box-shadow: 0 0 0 2px rgba(163,58,58,.10);
    }

    .cancelled-filter-actions {
        display: flex;
        gap: 8px;
    }

    .cancelled-filter-actions .btn {
        height: 38px;
        border-radius: 8px;
        font-size: .82rem;
        font-weight: 500;
        padding: 7px 13px;
    }

    .cancelled-order-card {
        border-color: #C38B8B;
        border-left: 3px solid #B64A4A;
        background: #FFFFFF;
    }

    .cancelled-order-card:hover {
        border-color: #A33A3A;
        border-left-color: #8E2F2F;
    }

    .cancelled-order-card .order-items-card {
        background: #FFFDFD;
    }

    .order-records-title {
        color: #4A3525;
        font-size: .82rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .4px;
        margin-bottom: 10px;
    }

    .order-record-links {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
    }

    .order-record-link {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 9px 12px;
        border: 2px solid #B8A08A;
        border-radius: 9px;
        background: #FFFFFF;
        color: #6F4E37;
        text-decoration: none;
        font-size: .8rem;
        font-weight: 500;
    }

    .order-record-link:hover,
    .order-record-link.active {
        border-color: #6F4E37;
        background: #F7F1EB;
        color: #4A3525;
    }

    .order-record-cancelled {
        border-color: #D89A9A;
        color: #A33A3A;
    }

    .order-record-cancelled:hover,
    .order-record-cancelled.active {
        border-color: #B64A4A;
        background: #FCE3E3;
        color: #8E2F2F;
    }

    .orders-grid {
        column-count: 2;
        column-gap: 16px;
        column-fill: balance;
    }

    .order-card {
        background: #FFFFFF;
        border: 1px solid #6F4E37;
        border-radius: 14px;
        padding: 20px;
        margin-bottom: 16px;
        box-shadow: 0 3px 12px rgba(74, 53, 37, .05);
        display: flex;
        flex-direction: column;
        height: auto;
        width: 100%;
        break-inside: avoid;
        -webkit-column-break-inside: avoid;
    }

    .order-card:hover {
        border-color: #5A3D2B;
    }

    /* Stronger separators inside each active order card */
    .order-card hr {
        border: 0;
        border-top: 1px solid #8B6F5A;
        opacity: 1;
        margin: 14px 0;
    }

    .order-item-row {
        padding: 12px 0;
        border-bottom: 1px solid #B08E74;
    }

    .status-badge {
        display: inline-block;
        padding: 6px 10px;
        border-radius: 20px;
        font-size: .7rem;
        font-weight: 500;
    }

    /* Order Queue tab (new orders from walk-in, online and guest) */
    .workflow-card.workflow-order_queue { border-color: #C9B19D; }
    .workflow-card.workflow-order_queue:hover { background: #F7F0EA; border-color: #6F4E37; }
    .workflow-card.workflow-order_queue.active {
        background: #D9C2AE;
        border-color: #4A3525;
        box-shadow: 0 4px 14px rgba(74, 53, 37, .16);
    }
    .workflow-order_queue .workflow-icon { background: #EFE4DA; color: #6F4E37; }
    .workflow-order_queue .workflow-label,
    .workflow-order_queue .workflow-count { color: #6F4E37; }
    .workflow-card.workflow-order_queue.active .workflow-icon { background: #F3E8DE; color: #4A3525; }
    .workflow-card.workflow-order_queue.active .workflow-label,
    .workflow-card.workflow-order_queue.active .workflow-count { color: #3F2D20; }
    .status-order_queue {
        background: #F3ECE5;
        color: #6F4E37;
        border: 1px solid #C9B19D;
    }

    .status-pending_verification {
        background: #FFF1D6;
        color: #8A5A00;
        border: 1px solid #D8B56A;
    }

    .status-confirmed {
        background: #E7F1FF;
        color: #285B9A;
        border: 1px solid #8DAFD6;
    }

    .status-preparing {
        background: #EEE7FF;
        color: #5B3A9A;
        border: 1px solid #AA98D0;
    }

    .status-ready {
        background: #E5F6EA;
        color: #23733D;
        border: 1px solid #86B998;
    }

    .status-completed {
        background: #E8E2DD;
        color: #4A3525;
        border: 1px solid #B8A08A;
    }

    .status-cancelled {
        background: #FCE3E3;
        color: #A33A3A;
        border: 1px solid #D89A9A;
    }

    .action-btn {
        border-radius: 8px;
        padding: 8px 13px;
        font-size: .8rem;
        font-weight: 500;
    }

    .btn-confirm {
        background: #DCEEFF;
        color: #286090;
        border: 1px solid #7FA9D0;
    }

    .btn-preparing {
        background: #EEE0FF;
        color: #7040A0;
        border: 1px solid #AA88C9;
    }

    .btn-ready {
        background: #DFF4E3;
        color: #28763B;
        border: 1px solid #83B88E;
    }

    .btn-complete {
        background: #4B2E1E;
        color: #FFFFFF;
        border: 1px solid #392217;
    }

    .btn-cancel {
        background: #FCE3E3;
        color: #A33A3A;
        border: 1px solid #D89A9A;
    }

    .btn-confirm:hover,
    .btn-preparing:hover,
    .btn-ready:hover {
        filter: brightness(.97);
    }

    .btn-complete:hover {
        background: #382116;
        color: #FFFFFF;
    }

    .btn-cancel:hover {
        background: #F7D2D2;
        color: #A33A3A;
    }

    .order-actions {
        display: flex;
        flex-wrap: wrap;
        justify-content: flex-end;
        gap: 8px;
        margin-top: auto;
        padding-top: 18px;
    }

    .info-label {
        color: #7B6D62;
        font-size: .68rem;
        text-transform: uppercase;
        font-weight: 500;
        letter-spacing: .35px;
    }

    .info-value {
        color: #4A3525;
        font-size: .84rem;
        font-weight: 650;
    }

    /* GCash payment text */
    .info-value.payment-gcash {
        color: #1877F2;
        font-weight: 500;
    }

    .cancellation-box {
        background: #FFF3F3;
        border: 1px solid #C98C8C;
        border-radius: 10px;
        padding: 12px;
    }

    .empty-state {
        text-align: center;
        padding: 55px 20px;
        color: #777067;
    }

    .pagination-wrap {
        display: flex;
        justify-content: center;
        margin: 24px 0;
    }

    .pagination .page-link {
        color: #6F4E37;
        border-color: #8B6F5A;
        font-size: .8rem;
    }

    .pagination .active .page-link {
        background: #6F4E37;
        border-color: #5A3D2B;
        color: #FFFFFF;
    }

    /* Cancelled Orders keeps the same Bootstrap pagination appearance as
       Active Orders and GCash Refunds. This class is retained for the
       section-specific AJAX click delegation only. */
    .cancelled-pagination {
        width: 100%;
        border-top: 1px solid #E6DEC9;
        padding-top: 18px;
        margin-top: 22px;
    }

    .cancelled-pagination .pagination {
        margin-bottom: 0;
    }

    .receipt-paper {
        max-width: 430px;
        margin: 0 auto;
        background: #FFFFFF;
        border: 1px dashed #8B6F5A;
        padding: 24px;
        font-family: Arial, sans-serif;
        color: #2C221E;
    }

    .receipt-line {
        border-top: 1px dashed #8B6F5A;
        margin: 14px 0;
    }

    .order-items-card {
        background: #FBF9F6;
        border: 1px solid #9C7A60;
        border-radius: 12px;
        padding: 16px 18px;
        margin: 4px 0 18px;
        flex: 1 1 auto;
        display: flex;
        flex-direction: column;
    }

    .order-items-title {
        color: #4A3525;
        font-size: .82rem;
        text-transform: uppercase;
        letter-spacing: .45px;
        font-weight: 500;
        margin-bottom: 12px;
    }

    .order-item-row:first-child {
        padding-top: 0;
    }

    .order-item-row:last-child {
        padding-bottom: 0;
        border-bottom: none;
    }

    .order-item-name {
        color: #4A3525;
        font-size: 1rem;
        font-weight: 500;
        line-height: 1.35;
    }

    .order-item-quantity {
        color: #6F4E37;
        font-size: .95rem;
        font-weight: 500;
        margin-left: 6px;
    }

    .order-item-base-price {
        color: #7B6D62;
        font-size: .82rem;
        font-weight: 500;
        margin-top: 3px;
    }

    .order-item-customization {
        color: #6B5B50;
        font-size: .88rem;
        line-height: 1.5;
        margin-top: 6px;
    }

    .order-item-customization-main {
        display: flex;
        flex-wrap: wrap;
        gap: 4px 10px;
        align-items: center;
    }

    .order-addon-label {
        display: block;
        margin-top: 7px;
        margin-bottom: 5px;
        color: #7B6D62;
        font-size: .72rem;
        text-transform: uppercase;
        letter-spacing: .3px;
        font-weight: 500;
    }

    .order-addon-list {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }

    .order-addon-chip {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 5px 9px;
        border-radius: 999px;
        background: #F3ECE5;
        border: 1px solid #D8C6B8;
        color: #5A3D2B;
        font-size: .78rem;
        font-weight: 500;
        line-height: 1.2;
    }

    .order-addon-chip-price {
        color: #6F4E37;
        font-weight: 500;
        white-space: nowrap;
    }

    .order-item-price {
        color: #4A3525;
        font-size: .95rem;
        font-weight: 500;
        white-space: nowrap;
    }

    .order-total-summary {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 14px;
        padding: 14px 0 2px;
        border-top: 1px solid #9C7A60;
        margin-top: auto;
    }

    .order-total-label {
        color: #4A3525;
        font-size: 1rem;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: .35px;
    }

    .order-total-amount {
        color: #4A3525;
        font-size: 1.35rem;
        font-weight: 600;
        line-height: 1.1;
        white-space: nowrap;
    }

    .item-customization {
        color: #7B6D62;
        font-size: .7rem;
        line-height: 1.35;
    }

    .payment-proof-image {
        max-width: 100%;
        max-height: 70vh;
        object-fit: contain;
        border-radius: 10px;
    }

    /*
     * GCash review modal layout.
     * Keep the dialog below the fixed Staff navbar and within the viewport.
     * The header and footer stay visible while only the content scrolls.
     */
    #staffGcashReviewModal {
        padding: 78px 12px 12px;
        overflow-x: hidden;
        overflow-y: auto;
    }

    #staffGcashReviewModal .gcash-review-dialog {
        display: flex;
        align-items: flex-start;
        width: min(900px, calc(100vw - 24px));
        max-width: 900px;
        min-height: 0;
        margin: 0 auto;
    }

    #staffGcashReviewModal .modal-content {
        display: flex;
        flex-direction: column;
        width: 100%;
        max-height: calc(100vh - 90px);
        min-height: 0;
        overflow: hidden;
    }

    #staffGcashReviewModal #staffGcashReviewForm {
        display: flex;
        flex: 1 1 auto;
        flex-direction: column;
        min-height: 0;
        overflow: hidden;
    }

    #staffGcashReviewModal .modal-header,
    #staffGcashReviewModal .modal-footer {
        flex: 0 0 auto;
    }

    #staffGcashReviewModal .modal-header {
        align-items: flex-start;
        gap: 12px;
        padding: 15px 18px;
        background: #FFFCF9;
        border-bottom: 1px solid #D9C9BB;
    }

    #staffGcashReviewModal .modal-header .btn-close {
        flex: 0 0 auto;
        min-width: 36px;
        min-height: 36px;
        margin: 0 0 0 auto;
        padding: 10px;
        border-radius: 8px;
        opacity: .8;
    }

    #staffGcashReviewModal .modal-body {
        flex: 1 1 auto;
        min-height: 0;
        overflow-x: hidden;
        overflow-y: auto;
        overscroll-behavior: contain;
        padding: 16px 18px !important;
    }

    #staffGcashReviewModal .modal-footer {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        padding: 12px 18px;
        background: #FFFCF9;
        border-top: 1px solid #D9C9BB;
    }

    #staffGcashReviewModal .modal-footer .btn {
        min-height: 42px;
        white-space: normal;
    }

    .gcash-review-dialog {
        max-width: 900px;
    }

    .gcash-review-summary {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
        padding: 13px 15px;
        background: #F8F3EE;
        border: 1px solid #E2D3C5;
        border-radius: 10px;
    }

    .gcash-review-total {
        color: #4A3525;
        font-size: 1.45rem;
        font-weight: 750;
        line-height: 1.2;
    }

    .gcash-review-proof-frame {
        min-height: 170px;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 12px;
        overflow: hidden;
        background: #F7F7F7;
        border: 1px solid #D8D0C9;
        border-radius: 10px;
    }

    .gcash-review-image {
        display: block;
        width: auto;
        max-width: 100%;
        max-height: 36vh;
        object-fit: contain;
        border-radius: 8px;
    }

    #staffGcashVerifyButton:disabled {
        opacity: .55;
        cursor: not-allowed;
    }

    @media (max-width: 575.98px) {
        #staffGcashReviewModal {
            padding: 72px 8px 8px;
        }

        #staffGcashReviewModal .gcash-review-dialog {
            width: 100%;
            max-width: none;
            margin: 0 auto;
        }

        #staffGcashReviewModal .modal-content {
            max-height: calc(100vh - 80px);
            border-radius: 12px;
        }

        #staffGcashReviewModal .modal-header {
            padding: 12px 14px;
        }

        #staffGcashReviewModal .modal-body {
            padding: 12px 14px !important;
        }

        #staffGcashReviewModal .modal-footer {
            display: grid;
            grid-template-columns: 1fr;
            gap: 8px;
            padding: 10px 14px;
        }

        #staffGcashReviewModal .modal-footer > button {
            width: 100%;
            min-height: 44px;
            margin: 0;
        }

        .gcash-review-image {
            max-height: 30vh;
        }
    }

    @media (max-height: 700px) and (min-width: 576px) {
        #staffGcashReviewModal {
            padding-top: 70px;
        }

        #staffGcashReviewModal .modal-content {
            max-height: calc(100vh - 80px);
        }

        .gcash-review-image {
            max-height: 28vh;
        }
    }

    .modal-content {
        border: 1px solid #8B6F5A;
        border-radius: 14px;
        overflow: hidden;
    }

    /* =========================================================
       FIXED ORDER ACTION TOASTS
       Stays out of normal page flow and does not move the page.
    ========================================================= */
    .orders-toast-wrap {
        position: fixed;
        top: 88px;
        right: 24px;
        z-index: 2000;
        width: min(420px, calc(100vw - 32px));
        pointer-events: none;
    }

    /* Independent AJAX notifications stack instead of waiting in a FIFO queue. */
    .orders-toast-stack {
        position: fixed;
        top: 88px;
        right: 24px;
        z-index: 2001;
        display: flex;
        flex-direction: column;
        align-items: stretch;
        gap: 10px;
        width: min(420px, calc(100vw - 32px));
        max-height: calc(100vh - 108px);
        overflow-y: auto;
        padding: 2px;
        pointer-events: none;
    }

    .orders-toast-stack .orders-toast {
        width: 100%;
        flex: 0 0 auto;
        pointer-events: auto;
    }

    .orders-toast {
        position: relative;
        display: flex;
        align-items: flex-start;
        gap: 12px;
        padding: 13px 14px;
        background: #ffffff;
        border: 2px solid #6F4E37;
        border-radius: 12px;
        box-shadow: 0 10px 28px rgba(44,34,30,.18);
        color: #2C221E;
        pointer-events: auto;
        overflow: hidden;
        animation: ordersToastIn .22s ease-out;
    }

    .orders-toast-success { border-left: 6px solid #4A8B5A; }
    .orders-toast-cancel { border-left: 6px solid #A33A3A; }

    .orders-toast-icon {
        flex: 0 0 30px;
        width: 30px;
        height: 30px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: #F3EADF;
        color: #4A3525;
        font-size: 15px;
        margin-top: 1px;
    }

    .orders-toast-success .orders-toast-icon {
        color: #2F6E3E;
        background: #EAF6EE;
    }

    .orders-toast-cancel .orders-toast-icon {
        color: #8E2F2F;
        background: #FCE3E3;
    }

    .orders-toast-copy {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 2px;
    }

    .orders-toast-title {
        color: #2F6E3E;
        font-size: .94rem;
        line-height: 1.3;
        font-weight: 700;
    }

    .orders-toast-message {
        flex: 1;
        padding-top: 1px;
        font-size: .86rem;
        line-height: 1.45;
        font-weight: 500;
    }

    .orders-toast-close {
        flex: 0 0 auto;
        border: 0;
        background: transparent;
        color: #6F4E37;
        width: 30px;
        height: 30px;
        border-radius: 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
    }

    .orders-toast-close:hover {
        background: #F0E6D6;
        color: #2C221E;
    }

    .orders-toast-progress {
        position: absolute;
        left: 0;
        bottom: 0;
        height: 3px;
        width: 100%;
        background: #6F4E37;
        transform-origin: left center;
        animation: ordersToastProgress 3.5s linear forwards;
    }

    .orders-toast-success .orders-toast-progress { background: #4A8B5A; }
    .orders-toast-cancel .orders-toast-progress { background: #A33A3A; }
    .orders-toast.is-restored { animation: none; }
    .orders-toast.is-closing { animation: ordersToastOut .18s ease-in forwards; }

    @keyframes ordersToastIn {
        from { opacity: 0; transform: translateY(-8px) translateX(8px); }
        to { opacity: 1; transform: translateY(0) translateX(0); }
    }

    @keyframes ordersToastOut {
        from { opacity: 1; transform: translateY(0) translateX(0); }
        to { opacity: 0; transform: translateY(-6px) translateX(8px); }
    }

    @keyframes ordersToastProgress {
        from { transform: scaleX(1); }
        to { transform: scaleX(0); }
    }

    @media (max-width: 768px) {

        .active-orders-section {
            padding: 14px;
        }

        .cancelled-section-summary {
            padding: 16px 14px;
            align-items: flex-start;
        }

        .cancelled-section-content {
            padding: 0 14px 14px;
        }

        .active-section-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .cancelled-section-heading {
            align-items: flex-start;
            flex-direction: column;
        }

        .staff-content {
            padding: 18px;
        }

        .search-box {
            width: 100%;
        }

        .order-search-form {
            width: 100%;
        }

        .workflow-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .orders-grid {
            column-count: 1;
        }

        .order-actions {
            justify-content: flex-start;
        }

        .active-orders-heading,
        .cancelled-section-heading {
            align-items: flex-start;
            flex-direction: column;
        }

        .cancelled-filter-form {
            align-items: stretch;
            flex-direction: column;
        }

        .cancelled-filter-group,
        .cancelled-filter-period,
        .cancelled-filter-month,
        .pending-refund-filter-date,
        .pending-refund-filter-status,
        .cancelled-filter-search {
            width: 100%;
            min-width: 0;
        }

        .cancelled-filter-actions .btn {
            flex: 1;
        }

        .orders-toast-wrap {
            top: 74px;
            right: 14px;
            width: min(420px, calc(100vw - 28px));
        }

        .orders-toast-stack {
            top: 74px;
            right: 14px;
            width: min(420px, calc(100vw - 28px));
            max-height: calc(100vh - 90px);
        }
    }

    @media (max-width: 520px) {
        .workflow-grid {
            grid-template-columns: 1fr;
        }
    }

    /* =========================================================
       MOBILE LAYOUT (phones)
    ========================================================= */
    @media (max-width: 767.98px) {

        .staff-content {
            padding: 16px 12px 28px;
        }

        .page-title {
            font-size: 1.4rem;
        }

        .page-description {
            font-size: .85rem;
        }

        /* Search: input + button on one line */
        .order-search-form {
            flex-wrap: nowrap;
            gap: 8px;
            margin-bottom: 14px;
        }

        .search-box {
            flex: 1 1 auto;
            width: auto;
            min-width: 0;
        }

        .search-box input {
            height: 44px;
            font-size: 16px; /* prevents iOS zoom-on-focus */
        }

        .search-actions .btn {
            height: 44px;
            padding-left: 14px;
            padding-right: 14px;
        }

        /* Workflow summary: always a compact 2 x 2 grid */
        .workflow-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 16px;
        }

        .workflow-card {
            min-height: 72px;
            gap: 10px;
            padding: 10px 12px;
        }

        .workflow-icon {
            width: 36px;
            height: 36px;
            flex: 0 0 36px;
            font-size: .95rem;
            border-radius: 10px;
        }

        .workflow-label {
            font-size: .7rem;
            margin-bottom: 2px;
        }

        .workflow-count {
            font-size: 1.25rem;
        }

        /* Sections and cards: less nested padding so content gets the width */
        .active-orders-section {
            padding: 12px;
            border-left-width: 3px;
        }

        .order-card {
            padding: 14px;
            margin-bottom: 12px;
        }

        /* Buttons: two per row, easy to tap */
        .order-actions {
            justify-content: stretch;
            padding-top: 14px;
        }

        .order-actions > *,
        .order-actions .action-btn,
        .order-actions form {
            flex: 1 1 calc(50% - 8px);
            min-width: 0;
        }

        .order-actions form .action-btn {
            width: 100%;
        }

        .action-btn {
            min-height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .order-record-links {
            flex-wrap: wrap;
        }

        .pagination-wrap {
            overflow-x: auto;
        }

        /* Modals: use the width, scroll inside */
        .modal-dialog {
            margin: 10px;
            max-width: none;
        }

        .modal-body {
            padding: 14px;
        }
    }

    /* Print copy of the receipt (filled by printReceipt). Hidden on screen. */
    #receiptPrintArea {
        display: none;
    }

    @media print {

        /* margin: 0 also removes the browser's date / URL / page-number lines. */
        @page {
            margin: 0;
        }

        html,
        body {
            margin: 0 !important;
            padding: 0 !important;
            background: #FFFFFF !important;
            overflow: visible !important;
            height: auto !important;
            min-height: 0 !important;
        }

        /* Print ONLY the receipt: everything else is removed from the layout,
           so no blank second page is produced. */
        body.is-printing-receipt > *:not(#receiptPrintArea) {
            display: none !important;
        }

        body.is-printing-receipt #receiptPrintArea {
            display: block !important;
            width: 80mm;
            max-width: 100%;
            margin: 0 auto !important;   /* centred on the page */
            padding: 6mm 4mm;
            box-sizing: border-box;
            color: #000000;
            font-size: 14px;
            line-height: 1.45;
        }

        /* Bigger, easier-to-read text on the printed copy. */
        #receiptPrintArea .small {
            font-size: 13px !important;
        }

        #receiptPrintArea h4 {
            font-size: 22px !important;
        }

        #receiptPrintArea .receipt-paper strong {
            font-weight: 700;
        }

        #receiptPrintArea .receipt-paper {
            width: 100% !important;
            max-width: none !important;
            margin: 0 !important;
            padding: 0 !important;
            border: none !important;
            box-shadow: none !important;
            background: #FFFFFF !important;
        }

        #receiptPrintArea .text-muted {
            color: #333333 !important;
        }

        #receiptPrintArea .receipt-line {
            border-top: 1px dashed #000000 !important;
        }
    }
    /* =========================================================
   LEGACY PROCESSING STYLES
   The current order workflow only applies a spinner to the
   Start Preparing button; no order row is dimmed or covered.
========================================================= */

.order-card {
    position: relative;
}

.order-card.processing-order {
    /* Keep the row visually marked without blocking text selection. */
    user-select: text;
}

.order-processing-overlay {
    position: absolute;
    inset: 0;
    z-index: 20;

    display: flex;
    align-items: center;
    justify-content: center;

    background: rgba(255, 255, 255, 0.82);
    backdrop-filter: blur(2px);

    border-radius: 14px;
}

.order-processing-box {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;

    padding: 18px 24px;

    background: #FFFFFF;
    border: 1px solid #D8C9BD;
    border-radius: 12px;

    box-shadow: 0 6px 20px rgba(74, 53, 37, .10);

    color: #4A3525;
    text-align: center;
}

.order-processing-spinner {
    width: 32px;
    height: 32px;

    border: 3px solid #E6D9CE;
    border-top-color: #6F4E37;

    border-radius: 50%;

    animation: staffOrderSpin .75s linear infinite;

    margin-bottom: 10px;
}

.order-processing-title {
    font-size: .85rem;
    font-weight: 500;
}

.order-processing-text {
    margin-top: 3px;
    color: #7B6D62;
    font-size: .72rem;
}

@keyframes staffOrderSpin {
    to {
        transform: rotate(360deg);
    }
}

/* =========================================================
   TARGET ORDER FROM STAFF NOTIFICATION
========================================================= */
.order-card.view-order-target {
    scroll-margin-top: 120px;
    outline: 4px solid #F1C40F;
    outline-offset: 3px;
    box-shadow:
        0 0 0 6px rgba(241, 196, 15, .16),
        0 8px 22px rgba(166, 106, 0, .14);
    position: relative;
    z-index: 5;
}

    /* =========================================================
       TABULAR ACTIVE ORDERS
       Adviser-requested compact table view. Full order details
       are shown only after clicking View Details.
    ========================================================= */
    .orders-grid {
        column-count: initial;
        column-gap: 0;
        column-fill: initial;
    }

    .staff-orders-table-wrap {
        width: 100%;
        overflow-x: auto;
        background: #FFFFFF;
        border: 1px solid #6F4E37;
        border-radius: 14px;
        box-shadow: 0 3px 12px rgba(74, 53, 37, .05);
    }

    .staff-orders-table {
        width: 100%;
        min-width: 1100px;
        border-collapse: separate;
        border-spacing: 0;
        table-layout: fixed;
    }

    .staff-orders-table th:nth-child(1),
    .staff-orders-table td:nth-child(1) { width: 12%; }

    .staff-orders-table th:nth-child(2),
    .staff-orders-table td:nth-child(2) { width: 8%; }

    .staff-orders-table th:nth-child(3),
    .staff-orders-table td:nth-child(3) { width: 13%; }

    .staff-orders-table th:nth-child(4),
    .staff-orders-table td:nth-child(4) { width: 9%; }

    .staff-orders-table th:nth-child(5),
    .staff-orders-table td:nth-child(5) { width: 7%; }

    .staff-orders-table th:nth-child(6),
    .staff-orders-table td:nth-child(6) { width: 8%; }

    .staff-orders-table th:nth-child(7),
    .staff-orders-table td:nth-child(7) { width: 7%; }

    .staff-orders-table th:nth-child(8),
    .staff-orders-table td:nth-child(8) { width: 11%; }

    .staff-orders-table th:nth-child(9),
    .staff-orders-table td:nth-child(9) { width: 14%; }

    .staff-orders-table th:nth-child(10),
    .staff-orders-table td:nth-child(10) { width: 11%; }

    .staff-orders-table thead th {
        background: #F5EEE7;
        color: #4A3525;
        font-size: .72rem;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: .35px;
        white-space: nowrap;
        padding: 13px 12px;
        border-bottom: 1px solid #8B6F5A;
    }

    .staff-orders-table tbody tr.order-card {
        display: table-row;
        width: auto;
        margin: 0;
        padding: 0;
        background: #FFFFFF;
        border: 0;
        border-radius: 0;
        box-shadow: none;
        break-inside: auto;
        -webkit-column-break-inside: auto;
    }

    .staff-orders-table tbody tr.order-card:hover {
        background: #FDF8F2;
    }

    .staff-orders-table tbody td {
        padding: 11px 10px;
        vertical-align: middle;
        border-bottom: 1px solid #E6DCCF;
        color: #4A3525;
        font-size: .78rem;
        font-weight: 400;
        background: inherit;
    }

    .staff-orders-table tbody td > * {
        max-width: 100%;
    }

    .staff-orders-table .order-table-primary {
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .staff-orders-table .order-table-date,
    .staff-orders-table .order-table-claim,
    .staff-orders-table .order-table-payment,
    .staff-orders-table .order-table-discount-badge,
    .staff-orders-table .order-table-none,
    .staff-orders-table .order-table-total,
    .staff-orders-table .status-badge {
        font-weight: 500;
    }

    .staff-orders-table tbody tr:last-child td {
        border-bottom: 0;
    }

    .order-table-primary {
        color: #4A3525;
        font-weight: 500;
        line-height: 1.25;
        white-space: nowrap;
    }

    .order-table-date {
        color: #7B6D62;
        font-size: .68rem;
        margin-top: 2px;
        white-space: nowrap;
    }

    .order-table-claim {
        color: #6F4E37;
        font-weight: 500;
        white-space: nowrap;
    }

    .order-table-payment {
        display: inline-flex;
        align-items: center;
        min-height: 28px;
        padding: 5px 9px;
        border-radius: 8px;
        background: #F5EFE9;
        color: #4A3525;
        font-size: .68rem;
        font-weight: 500;
        letter-spacing: .3px;
    }

    .order-table-payment.payment-gcash {
        background: #E4F1FF;
        color: #1877C9;
    }

    .order-table-discount-badge {
        color: #5E6A3A;
        background: #EEF3DF;
        border: 1px solid #BFCB9A;
        border-radius: 7px;
        padding: 4px 7px;
        font-size: .67rem;
        font-weight: 500;
        white-space: nowrap;
        display: inline-block;
    }

    .order-table-none {
        color: #8A8179;
        font-size: .7rem;
        font-weight: 500;
    }

    .order-table-total {
        color: #4A3525;
        font-weight: 600;
        white-space: nowrap;
    }

    .order-table-next-action-cell {
        min-width: 0;
        text-align: center;
    }

    .order-table-next-action-form {
        display: flex;
        justify-content: center;
        width: auto;
        margin: 0;
    }

    .btn-table-next-action {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
        width: auto;
        min-width: 0;
        max-width: 164px;
        min-height: 36px;
        padding: 7px 10px;
        border: 1px solid transparent;
        border-radius: 8px;
        font-size: .72rem;
        font-weight: 650;
        line-height: 1.15;
        text-align: center;
        white-space: nowrap;
        transition: background .18s ease, border-color .18s ease, color .18s ease, transform .18s ease;
    }

    /* Each next action uses its workflow/status color instead of one brown style. */
    .btn-table-next-action.btn-pending-action {
        background: #FFF1D6;
        border-color: #D8B56A;
        color: #8A5A00;
    }
    .btn-table-next-action.btn-confirm {
        background: #DCEEFF;
        border-color: #7FA9D0;
        color: #286090;
    }
    .btn-table-next-action.btn-preparing {
        background: #EEE0FF;
        border-color: #AA88C9;
        color: #7040A0;
    }
    .btn-table-next-action.btn-ready {
        background: #DFF4E3;
        border-color: #83B88E;
        color: #28763B;
    }
    .btn-table-next-action.btn-complete {
        background: #23733D;
        border-color: #1E6535;
        color: #FFFFFF;
    }

    .btn-table-next-action.btn-pending-action:hover,
    .btn-table-next-action.btn-pending-action:focus-visible {
        background: #F9E5B7;
        border-color: #C49B45;
        color: #754B00;
    }
    .btn-table-next-action.btn-confirm:hover,
    .btn-table-next-action.btn-confirm:focus-visible {
        background: #C7E2FB;
        border-color: #5F91C0;
        color: #214F80;
    }
    .btn-table-next-action.btn-preparing:hover,
    .btn-table-next-action.btn-preparing:focus-visible {
        background: #E1D0FA;
        border-color: #9671BF;
        color: #5A3289;
    }
    .btn-table-next-action.btn-ready:hover,
    .btn-table-next-action.btn-ready:focus-visible {
        background: #C8EBD0;
        border-color: #6CA77A;
        color: #1F6331;
    }
    .btn-table-next-action.btn-complete:hover,
    .btn-table-next-action.btn-complete:focus-visible {
        background: #1D6535;
        border-color: #18572D;
        color: #FFFFFF;
    }

    .btn-table-next-action:focus-visible,
    .btn-view-details:focus-visible {
        outline: 3px solid #C69C6D;
        outline-offset: 2px;
    }

    .btn-table-next-action:disabled {
        opacity: .65;
        cursor: wait;
    }

    .order-table-details-cell,
    .order-table-actions-cell {
        min-width: 0;
        text-align: right;
    }

    .btn-view-details {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: auto;
        min-width: 112px;
        min-height: 36px;
        padding: 6px 10px;
        border: 1px solid #6F4E37;
        border-radius: 8px;
        background: #FFFFFF;
        color: #6F4E37;
        font-size: .70rem;
        font-weight: 500;
        line-height: 1.2;
        white-space: nowrap;
    }

    .btn-view-details:hover {
        background: #F3E8DB;
        border-color: #6F4E37;
        color: #4A3525;
    }

    .staff-orders-table tr.processing-order td {
        opacity: .62;
    }

    .staff-orders-table tr.processing-order .btn-table-next-action {
        opacity: 1;
    }

    .order-detail-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
    }

    .order-detail-actions form {
        margin: 0;
    }

    /* Keep the workflow controls together on the right side. */
    .order-detail-actions form.workflow-action {
        margin-left: auto;
    }

    .order-detail-actions .action-btn {
        min-height: 40px;
    }

    /* Compact Order Details modal: keep the item columns visually closer. */
    .order-details-modal-dialog {
        width: 100%;
        max-width: 900px;

        /*
         * The staff navbar is fixed above the page. Do not vertically
         * center a tall order modal behind it; start it below the navbar
         * and let only the modal body scroll.
         */
        margin: 82px auto 14px;
        max-height: calc(100vh - 96px);
    }

    .order-details-modal-dialog.modal-dialog-centered {
        align-items: flex-start;
    }

    .order-details-modal-dialog .modal-content {
        max-height: calc(100vh - 96px);
        overflow: hidden;
    }

    .order-details-modal-dialog .modal-header {
        flex: 0 0 auto;
    }

    .order-details-modal-dialog .modal-body {
        padding: 20px 22px !important;
        overflow-y: auto;
    }

    /* Darker and slightly larger text inside Order Details. */
    .order-details-modal-dialog .info-label {
        color: #5A4638 !important;
        font-size: .75rem !important;
        font-weight: 800 !important;
    }

    .order-details-modal-dialog .info-value {
        color: #3F2D20 !important;
        font-size: .92rem !important;
        font-weight: 750 !important;
        line-height: 1.35;
    }

    .order-details-modal-dialog .modal-title {
        color: #3F2D20 !important;
        font-size: 1.15rem !important;
        font-weight: 800 !important;
    }

    .order-details-modal-dialog .modal-header .small {
        color: #5A4A40 !important;
        font-size: .82rem !important;
        font-weight: 600;
    }

    .order-details-modal-dialog .details-section-title {
        color: #3F2D20 !important;
        font-size: .96rem !important;
        font-weight: 800 !important;
    }

    .staff-details-items-table {
        table-layout: fixed;
        width: 100%;
    }

    .staff-details-items-table th:nth-child(1),
    .staff-details-items-table td:nth-child(1) {
        width: 54%;
    }

    .staff-details-items-table th:nth-child(2),
    .staff-details-items-table td:nth-child(2) {
        width: 10%;
    }

    .staff-details-items-table th:nth-child(3),
    .staff-details-items-table td:nth-child(3) {
        width: 18%;
    }

    .staff-details-items-table th:nth-child(4),
    .staff-details-items-table td:nth-child(4) {
        width: 18%;
    }

    .staff-details-items-table {
        border: 1px solid #8B6F5A;
        border-radius: 10px;
        overflow: hidden;
        background: #FFFFFF;
    }

    .staff-details-items-table th {
        background: #F7F1E8;
        color: #4A3525;
        font-size: .72rem;
        text-transform: uppercase;
        letter-spacing: .3px;
        font-weight: 500;
        border-bottom: 1px solid #8B6F5A;
    }

    .staff-details-items-table td {
        color: #3F2D20 !important;
        font-size: .86rem !important;
        font-weight: 600;
        border-color: #E6DCCF;
    }

    .staff-details-items-table th {
        color: #3F2D20 !important;
        font-size: .76rem !important;
    }

    .staff-details-items-table td:first-child {
        font-size: .9rem !important;
        font-weight: 500;
        line-height: 1.4;
    }

    .discount-summary-box {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        padding: 14px 16px;
        border: 1px solid #BFCB9A;
        border-radius: 10px;
        background: #F4F8E9;
    }

    .discount-summary-title {
        margin-top: 2px;
        color: #5E6A3A;
        font-size: .86rem;
        font-weight: 500;
    }

    .discount-summary-amount {
        color: #5E6A3A;
        font-size: 1rem;
        font-weight: 600;
        white-space: nowrap;
    }

    .order-detail-summary-box {
        width: 340px;
        max-width: 100%;
        margin-left: auto;
        box-sizing: border-box;
        padding: 13px 14px;
        border: 1px solid #8B6F5A;
        border-radius: 10px;
        background: #FDF9F5;
        overflow: hidden;
    }

    .order-detail-summary-box .d-flex {
        display: flex;
        align-items: center;
        justify-content: flex-end !important;
        gap: 18px;
        width: 100%;
        min-width: 0;
    }

    .order-detail-summary-box .d-flex > :first-child {
        flex: 1 1 auto;
        min-width: 0;
        white-space: normal;
    }

    .order-detail-summary-box .d-flex > :last-child {
        flex: 0 0 auto;
        margin-left: auto;
        text-align: right;
        white-space: nowrap;
    }

    .discount-summary-line {
        color: #5E6A3A;
    }

    .order-detail-summary-divider {
        border-top: 1px solid #B8A08A;
        margin: 10px 0;
    }

    .order-detail-final-total {
        color: #4A3525;
        font-size: 1.05rem;
    }

    .staff-orders-table tbody tr.view-order-target td {
        background: #FFF8D8;
        border-top: 1px solid #E4BD3E;
        border-bottom: 1px solid #E4BD3E;
    }

    @media (max-width: 991.98px) {
        .order-details-modal-dialog {
            max-width: calc(100vw - 20px);
            margin-left: auto;
            margin-right: auto;
        }
    }

    @media (max-width: 767.98px) {
        .order-details-modal-dialog {
            width: calc(100% - 20px);
            max-width: none;
            margin: 68px auto 10px;
            max-height: calc(100vh - 78px);
        }

        .order-details-modal-dialog .modal-content {
            max-height: calc(100vh - 78px);
        }

        .order-details-modal-dialog .modal-body {
            padding: 14px !important;
        }

        .order-detail-summary-box {
            width: 100%;
        }

        .order-detail-summary-box .d-flex {
            gap: 14px;
        }

        .staff-orders-table-wrap {
            border-radius: 10px;
        }

        .staff-orders-table {
            min-width: 1020px;
        }

        .staff-orders-table thead th,
        .staff-orders-table tbody td {
            padding: 10px;
        }

        .discount-summary-box {
            align-items: flex-start;
            flex-direction: column;
        }
    }

    /* POLISHED ORDER DETAILS MODAL */
    .order-details-modal-dialog .modal-content {
        border: 1px solid #CDBBAA;
        border-radius: 16px;
        box-shadow: 0 18px 50px rgba(60, 43, 31, .18);
    }

    .order-details-modal-dialog .details-header {
        padding: 18px 22px 14px;
        border-bottom: 1px solid #D9C9BB;
        background: #FFFCF9;
    }

    .order-details-modal-dialog .modal-title {
        font-size: 1.08rem !important;
        font-weight: 600 !important;
    }

    .order-details-modal-dialog .modal-header .small {
        font-size: .76rem !important;
        font-weight: 400 !important;
        color: #7B6D62 !important;
        margin-top: 3px;
    }

    .order-details-modal-dialog .modal-body {
        padding: 18px 22px 20px !important;
    }

    .order-details-modal-dialog .info-label {
        color: #7B6D62 !important;
        font-size: .68rem !important;
        font-weight: 500 !important;
        text-transform: uppercase;
        letter-spacing: .35px;
        margin-bottom: 3px;
    }

    .order-details-modal-dialog .info-value {
        color: #4A3525 !important;
        font-size: .86rem !important;
        font-weight: 400 !important;
        line-height: 1.35;
    }

    .order-details-modal-dialog .info-value.fs-5 {
        font-size: .92rem !important;
        font-weight: 500 !important;
    }

    .order-details-modal-dialog .status-badge {
        font-size: .68rem !important;
        font-weight: 500 !important;
        padding: 5px 9px;
        border-radius: 999px;
        white-space: nowrap;
    }

    .order-details-modal-dialog .order-status-row {
        min-height: 44px;
        margin-bottom: 10px !important;
        align-items: center;
    }

    .order-modal-divider {
        border: 0;
        border-top: 1px solid #D9C9BB;
        opacity: 1;
        margin: 14px 0 16px;
    }

    .order-details-modal-dialog .order-info-grid {
        margin-bottom: 0 !important;
    }

    .order-details-modal-dialog .staff-details-items-table {
        border-color: #CDBBAA;
        border-radius: 10px;
    }

    .order-details-modal-dialog .staff-details-items-table th {
        background: #F7F1E8;
        color: #6A5647 !important;
        font-size: .68rem !important;
        font-weight: 500 !important;
        padding: 9px 10px;
    }

    .order-details-modal-dialog .staff-details-items-table td {
        color: #4A3525 !important;
        font-size: .80rem !important;
        font-weight: 400 !important;
        padding: 10px;
    }

    .order-details-modal-dialog .staff-details-items-table td:first-child {
        font-size: .84rem !important;
        font-weight: 500 !important;
    }

    .order-details-modal-dialog .order-detail-actions .action-btn,
    .order-details-modal-dialog .order-detail-actions button {
        font-weight: 500 !important;
    }

    @media (max-width: 767.98px) {
        .order-details-modal-dialog .details-header {
            padding: 14px 16px 12px;
        }

        .order-details-modal-dialog .modal-body {
            padding: 14px 16px 16px !important;
        }

        .order-details-modal-dialog .order-status-row {
            min-height: 40px;
        }

        .order-modal-divider {
            margin: 12px 0 14px;
        }
    }

    /* =========================================================
       RESPONSIVE POLISH (desktop / tablet / mobile)
       Added last so it safely overrides the earlier rules.
    ========================================================= */

    /* ---- Shared safety ---- */
    .staff-content { overflow-x: clip; }
    .workflow-card { min-width: 0; }
    .workflow-label { overflow-wrap: anywhere; }
    .pagination { flex-wrap: wrap; justify-content: center; row-gap: 6px; }

    /* ---- Small desktops / laptops with the sidebar open (5 tabs) ---- */
    @media (min-width: 1200px) and (max-width: 1499.98px) {
        .workflow-grid { gap: 10px; }
        .workflow-card { gap: 10px; padding: 12px; }
        .workflow-icon { width: 38px; height: 38px; flex-basis: 38px; font-size: 1rem; }
        .workflow-label { font-size: .72rem; }
        .workflow-count { font-size: 1.3rem; }
    }

    /* ---- Tablet & compact desktop: keep the 10-column table readable ---- */
    @media (min-width: 576px) and (max-width: 1199.98px) {
        .staff-orders-table { min-width: 1100px; }

        .staff-orders-table th:nth-child(1), .staff-orders-table td:nth-child(1) { width: 13%; }
        .staff-orders-table th:nth-child(2), .staff-orders-table td:nth-child(2) { width: 8%; }
        .staff-orders-table th:nth-child(3), .staff-orders-table td:nth-child(3) { width: 14%; }
        .staff-orders-table th:nth-child(4), .staff-orders-table td:nth-child(4) { width: 9%; }
        .staff-orders-table th:nth-child(5), .staff-orders-table td:nth-child(5) { width: 7%; }
        .staff-orders-table th:nth-child(6), .staff-orders-table td:nth-child(6) { width: 8%; }
        .staff-orders-table th:nth-child(7), .staff-orders-table td:nth-child(7) { width: 7%; }
        .staff-orders-table th:nth-child(8), .staff-orders-table td:nth-child(8) { width: 12%; }
        .staff-orders-table th:nth-child(9), .staff-orders-table td:nth-child(9) { width: 11%; }
        .staff-orders-table th:nth-child(10), .staff-orders-table td:nth-child(10) { width: 11%; }

        .staff-orders-table thead th {
            padding: 10px 6px;
            font-size: .64rem;
            letter-spacing: .2px;
            white-space: normal;
        }

        .staff-orders-table tbody td {
            padding: 9px 6px;
            font-size: .72rem;
        }

        .staff-orders-table .order-table-primary { white-space: normal; overflow-wrap: anywhere; }
        .staff-orders-table .order-table-date { white-space: normal; font-size: .64rem; }
        .staff-orders-table .order-table-claim { white-space: normal; overflow-wrap: anywhere; }
        .staff-orders-table .order-table-payment { padding: 4px 6px; font-size: .64rem; min-height: 0; }
        .staff-orders-table .order-table-discount-badge { white-space: normal; font-size: .62rem; }
        .staff-orders-table .status-badge { padding: 4px 7px; font-size: .62rem; white-space: normal; text-align: center; }
        .staff-orders-table .order-table-actions-cell { min-width: 0; }

        .staff-orders-table .btn-view-details {
            padding: 6px 8px;
            font-size: .68rem;
            white-space: normal;
            line-height: 1.2;
        }
        .staff-orders-table .btn-view-details i { display: none; }
    }

    /* ---- Tablet: 3 tabs per row, table scrolls inside its own box ---- */
    @media (min-width: 768px) and (max-width: 1199.98px) {
        .workflow-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
        .staff-content { padding: 22px; }
        .staff-orders-table-wrap { -webkit-overflow-scrolling: touch; }
        .order-details-modal-dialog { max-width: min(900px, calc(100vw - 32px)); }
    }

    /* ---- Phone: 2 tabs per row, last tab full width ---- */
    @media (max-width: 767.98px) {
        .workflow-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
        .workflow-card:last-child:nth-child(odd) { grid-column: 1 / -1; }

        .active-section-header { gap: 6px; }
        .active-orders-heading { gap: 6px; }
        .active-orders-title { font-size: 1rem; }

    }

    /* ---- Small phones only: orders table becomes a list of cards.
       Tablets (576px and up) keep the real table. ---- */
    @media (max-width: 575.98px) {
        /* ---------- Orders table becomes a list of cards ---------- */
        .staff-orders-table-wrap {
            overflow: visible;
            background: transparent;
            border: 0;
            border-radius: 0;
            box-shadow: none;
        }

        .staff-orders-table,
        .staff-orders-table tbody {
            display: block;
            width: 100%;
            min-width: 0;
        }

        .staff-orders-table thead {
            position: absolute;
            width: 1px;
            height: 1px;
            overflow: hidden;
            clip: rect(0 0 0 0);
            white-space: nowrap;
        }

        .staff-orders-table tbody tr.order-card {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px 14px;
            width: 100%;
            margin: 0 0 12px;
            padding: 14px;
            background: #FFFFFF;
            border: 1px solid #6F4E37;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(74, 53, 37, .06);
        }

        .staff-orders-table tbody tr.order-card:last-child { margin-bottom: 0; }

        .staff-orders-table tbody tr.order-card.view-order-target {
            background: #FFF8D8;
            outline: 3px solid #F1C40F;
            outline-offset: 2px;
        }

        .staff-orders-table tbody tr.order-card td {
            display: block;
            width: auto;
            min-width: 0;
            padding: 0;
            border: 0;
            background: transparent;
            text-align: left !important;
            overflow-wrap: anywhere;
        }

        /* Small labels above each value */
        .staff-orders-table tbody tr.order-card td::before {
            display: block;
            margin-bottom: 3px;
            color: #7B6D62;
            font-size: .64rem;
            font-weight: 500;
            letter-spacing: .35px;
            text-transform: uppercase;
        }

        .staff-orders-table td:nth-child(2)::before { content: "Claim No."; }
        .staff-orders-table td:nth-child(4)::before { content: "Pick-up"; }
        .staff-orders-table td:nth-child(5)::before { content: "Payment"; }
        .staff-orders-table td:nth-child(6)::before { content: "Discount"; }
        .staff-orders-table td:nth-child(7)::before { content: "Total"; }
        .staff-orders-table td:nth-child(8)::before { content: "Status"; }
        .staff-orders-table td:nth-child(9)::before { content: "Next Action"; }
        .staff-orders-table td:nth-child(10)::before { content: "Details"; }

        /* Order number, customer, next action, and details use the full card width. */
        .staff-orders-table tbody tr.order-card td:nth-child(1),
        .staff-orders-table tbody tr.order-card td:nth-child(3),
        .staff-orders-table tbody tr.order-card td:nth-child(9),
        .staff-orders-table tbody tr.order-card td:nth-child(10) {
            grid-column: 1 / -1;
        }

        .staff-orders-table tbody tr.order-card td:nth-child(1) {
            padding-bottom: 10px;
            border-bottom: 1px solid #E6DCCF;
        }

        .staff-orders-table .order-table-primary { white-space: normal; }
        .staff-orders-table .order-table-date { white-space: normal; }
        .staff-orders-table .order-table-next-action-cell,
        .staff-orders-table .order-table-actions-cell,
        .staff-orders-table .order-table-details-cell { min-width: 0; }

        .staff-orders-table .order-table-next-action-form { width: 100%; }

        .staff-orders-table .btn-table-next-action,
        .staff-orders-table .btn-view-details {
            width: 100%;
            min-height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
    }

    @media (max-width: 767.98px) {
        /* ---------- Order Details modal on phones ---------- */
        .order-details-modal-dialog .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .order-details-modal-dialog .staff-details-items-table { min-width: 520px; }

        .order-detail-actions { flex-direction: column; align-items: stretch; }

        .order-detail-actions form,
        .order-detail-actions form.workflow-action {
            width: 100%;
            margin-left: 0;
        }

        .order-detail-actions .action-btn,
        .order-detail-actions form .action-btn {
            width: 100%;
            min-height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .order-detail-summary-box { width: 100%; }
        .discount-summary-box { padding: 12px; }

        /* Cancelled / Refund cards: stack their info columns cleanly */
        .cancelled-order-card .row > [class*="col-md-"] { width: 100%; }
        .cancelled-order-card .order-actions .action-btn { width: 100%; }
        .pending-refunds-summary,
        .cancelled-section-summary { flex-wrap: wrap; }
    }

    /* ---- Very small phones ---- */
    @media (max-width: 380px) {
        .staff-content { padding: 14px 10px 24px; }
        .workflow-card { padding: 9px 10px; gap: 8px; }
        .workflow-icon { width: 32px; height: 32px; flex-basis: 32px; }
        .workflow-count { font-size: 1.1rem; }
        .staff-orders-table tbody tr.order-card { grid-template-columns: 1fr; }
    }

    /* =========================================================
       ACTIVE ORDERS TABLE: SEPARATE NEXT ACTION + DETAILS
       - The outer box stays visually light.
       - Ten columns remain readable on wide screens; medium-width
         devices scroll only inside the table area.
       - Phones (<576px) use labeled order cards instead.
    ========================================================= */
    @media (min-width: 576px) {
        .staff-orders-table-wrap {
            background: transparent;
            border: 0;
            border-radius: 0;
            box-shadow: none;
        }

        .staff-orders-table th:nth-child(1), .staff-orders-table td:nth-child(1) { width: 12%; }
        .staff-orders-table th:nth-child(2), .staff-orders-table td:nth-child(2) { width: 8%; }
        .staff-orders-table th:nth-child(3), .staff-orders-table td:nth-child(3) { width: 13%; }
        .staff-orders-table th:nth-child(4), .staff-orders-table td:nth-child(4) { width: 9%; }
        .staff-orders-table th:nth-child(5), .staff-orders-table td:nth-child(5) { width: 7%; }
        .staff-orders-table th:nth-child(6), .staff-orders-table td:nth-child(6) { width: 8%; }
        .staff-orders-table th:nth-child(7), .staff-orders-table td:nth-child(7) { width: 7%; }
        .staff-orders-table th:nth-child(8), .staff-orders-table td:nth-child(8) { width: 11%; }
        .staff-orders-table th:nth-child(9), .staff-orders-table td:nth-child(9) { width: 14%; }
        .staff-orders-table th:nth-child(10), .staff-orders-table td:nth-child(10) { width: 11%; }

        .staff-orders-table thead th:first-child { border-radius: 10px 0 0 10px; }
        .staff-orders-table thead th:last-child { border-radius: 0 10px 10px 0; }
        .staff-orders-table thead th { border-bottom: 0; padding: 12px 8px; white-space: normal; }
        .staff-orders-table tbody td { padding: 12px 8px; }

        /* Long values wrap inside their column instead of forcing a scrollbar */
        .staff-orders-table .order-table-primary,
        .staff-orders-table .order-table-date,
        .staff-orders-table .order-table-claim {
            white-space: normal;
            overflow: visible;
            text-overflow: clip;
            overflow-wrap: anywhere;
        }

        /* Status: always one line, centered pill */
        .staff-orders-table thead th:nth-child(8),
        .staff-orders-table tbody td:nth-child(8) { padding-left: 16px; }
        .staff-orders-table .status-badge {
            display: inline-block;
            white-space: nowrap;
            text-align: center;
            padding: 6px 12px;
            font-size: .7rem;
        }

        /* Keep the two actions visually separate and comfortably tappable. */
        .staff-orders-table thead th:nth-child(9),
        .staff-orders-table tbody td:nth-child(9) { padding-right: 8px; }
        .staff-orders-table thead th:nth-child(10),
        .staff-orders-table tbody td:nth-child(10) { padding-right: 12px; }
        .staff-orders-table .order-table-next-action-cell,
        .staff-orders-table .order-table-actions-cell { min-width: 0; }
        .staff-orders-table .btn-table-next-action { padding: 7px 8px; font-size: .72rem; }
        .staff-orders-table .btn-view-details {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            white-space: nowrap;
            padding: 6px 7px;
            font-size: .70rem;
            line-height: 1.2;
        }
    }

    /* Wide desktop screens can show all 10 columns without horizontal scrolling. */
    @media (min-width: 1400px) {
        .staff-orders-table-wrap { overflow: visible; }
        .staff-orders-table { min-width: 0; width: 100%; }
    }

    /* Slightly smaller pills / button on narrower laptops so they still fit */
    @media (min-width: 900px) and (max-width: 1199.98px) {
        .staff-orders-table .status-badge { padding: 5px 9px; font-size: .66rem; }
        .staff-orders-table .btn-table-next-action { padding: 7px 8px; font-size: .70rem; }
        .staff-orders-table .btn-view-details { padding: 7px 9px; font-size: .70rem; }
    }

    /* Tablets and compact laptops keep a readable table inside its own scroll area. */
    @media (min-width: 576px) and (max-width: 1399.98px) {
        .staff-orders-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .staff-orders-table { min-width: 1100px; }
    }

    /* =========================================================
       CANCELLED DATE + REFUND FILTER: NO BOX
       Removes the border / background / padding box around the
       "Cancelled Date" filter and the Refund filter so they sit
       cleanly on the section. Fields, buttons and behavior are
       unchanged.
    ========================================================= */
    .cancelled-filter-form,
    .cancelled-filter-form.pending-refund-filter-form {
        padding: 0;
        border: 0;
        border-radius: 0;
        background: transparent;
        box-shadow: none;
        gap: 16px 20px;
        margin-top: 22px;
        margin-bottom: 26px;
    }

    .cancelled-filter-form .cancelled-filter-actions {
        gap: 10px;
        margin-left: 4px;
    }


    .staff-refund-count-notice {
        display: flex;
        align-items: center;
        gap: 14px;
        width: 100%;
        margin: 0 0 22px;
        padding: 14px 17px;
        border: 1px solid #E6DEC9;
        border-radius: 12px;
        background: #fff;
        box-shadow: 0 3px 12px rgba(74, 53, 37, .045);
    }
    .staff-refund-count-icon {
        display: grid;
        place-items: center;
        flex: 0 0 42px;
        width: 42px;
        height: 42px;
        border-radius: 10px;
        background: #F1E8DC;
        color: #6F4E37;
        font-size: 1.2rem;
    }
    .staff-refund-count-copy { min-width: 0; flex: 1 1 auto; }
    .staff-refund-count-copy strong { display: block; color: #4A3525; font-size: .9rem; }
    .staff-refund-count-copy p { margin: 3px 0 0; color: #78695D; font-size: .78rem; line-height: 1.45; }
    .staff-refund-count-value {
        display: grid;
        place-items: center;
        flex: 0 0 40px;
        min-width: 40px;
        height: 40px;
        padding: 0 8px;
        border-radius: 20px;
        background: #F7F0E9;
        color: #4A3525;
        font-size: .95rem;
        font-weight: 800;
        font-variant-numeric: tabular-nums;
    }
    @media (max-width: 575.98px) {
        .staff-refund-count-notice { align-items: flex-start; gap: 10px; padding: 12px; }
        .staff-refund-count-icon { flex-basis: 36px; width: 36px; height: 36px; }
        .staff-refund-count-value { flex-basis: 34px; min-width: 34px; height: 34px; }
    }
</style>

<div class="staff-orders-page">

    <!-- =====================================================
         STAFF SIDEBAR
         Staff navigation is loaded from this folder.
    ====================================================== -->
    <?php require_once 'sidebar.php'; ?>

    <?php require_once 'navbar.php'; ?>

    <main class="staff-main staff-content">

            <h2 class="fw-bold page-title mb-1">
                <i class="bi bi-bag-check me-1"></i>
                 Staff Order Queue
            </h2>

           

            <?php
            /*
             * One-time fixed action notification.
             * It is intentionally outside normal page flow so it does not
             * push content down or affect the saved scroll position.
             */
            $orderToast = null;

            $orderAction = trim((string)($_GET['action'] ?? ''));

            $orderToastMap = [
                'order_queue' => [
                    'type' => 'success',
                    'icon' => 'bi-inbox',
                    'message' => 'Order moved to Order Queue.'
                ],
                'confirmed' => [
                    'type' => 'success',
                    'icon' => 'bi-check-circle',
                    'message' => 'Order confirmed successfully!'
                ],
                'preparing' => [
                    'type' => 'success',
                    'icon' => 'bi-cup-hot',
                    'message' => 'Order is now being prepared.'
                ],
                'ready' => [
                    'type' => 'success',
                    'icon' => 'bi-bag-check',
                    'message' => 'Order marked as ready for pick-up!'
                ],
                'completed' => [
                    'type' => 'success',
                    'icon' => 'bi-check2-all',
                    'message' => 'Order completed successfully!'
                ],
                'cancelled' => [
                    'type' => 'cancel',
                    'icon' => 'bi-x-circle',
                    'message' => 'Order cancelled successfully.'
                ]
            ];

            if (isset($orderToastMap[$orderAction])) {
                $orderToast = $orderToastMap[$orderAction];
            }
            ?>

            <?php if ($orderToast): ?>
                <div class="orders-toast-wrap" aria-live="polite" aria-atomic="true">
                    <div
                        class="orders-toast orders-toast-<?= htmlspecialchars($orderToast['type']) ?>"
                        id="ordersActionToast"
                        role="status"
                    >
                        <span class="orders-toast-icon">
                            <i class="bi <?= htmlspecialchars($orderToast['icon']) ?>"></i>
                        </span>

                        <span class="orders-toast-message">
                            <?= htmlspecialchars($orderToast['message']) ?>
                        </span>

                        <button
                            type="button"
                            class="orders-toast-close"
                            id="ordersToastClose"
                            aria-label="Close notification"
                        >
                            <i class="bi bi-x-lg"></i>
                        </button>

                        <span class="orders-toast-progress" aria-hidden="true"></span>
                    </div>
                </div>
            <?php endif; ?>

            <script>
            /* =========================================================
               ORDER ACTION TOAST
               Runs immediately (NOT on DOMContentLoaded), so a fast click
               on another workflow tab can no longer lose the toast.

               - Lives exactly DURATION ms (3.5s) from the moment the
                 action happened. Clicking tabs, or the same tab again and
                 again, NEVER extends or restarts that countdown.
               - If the Staff switches tabs, the toast is carried to the next
                 page and continues from where it left off (the progress bar
                 resumes at the correct point instead of restarting).
               - Once the time is up it is gone for good.
            ========================================================= */
            (function () {
                var STORAGE_KEY = 'staffOrdersActionToast';
                var DURATION    = 3500;  /* fixed lifetime after the action */

                function readState() {
                    try {
                        return JSON.parse(sessionStorage.getItem(STORAGE_KEY) || 'null');
                    } catch (e) { return null; }
                }

                function saveState(state) {
                    try {
                        sessionStorage.setItem(STORAGE_KEY, JSON.stringify(state));
                    } catch (e) {}
                }

                function clearState() {
                    try { sessionStorage.removeItem(STORAGE_KEY); } catch (e) {}
                }

                var now = Date.now();
                var toast = document.getElementById('ordersActionToast');
                var state = null;

                if (toast) {
                    /* Brand-new action (server-rendered toast). */
                    state = { html: toast.outerHTML, createdAt: now };
                    saveState(state);

                    /* Remove the one-time ?action= so refresh does not repeat it. */
                    try {
                        var url = new URL(window.location.href);
                        url.searchParams.delete('action');
                        window.history.replaceState(
                            {},
                            document.title,
                            url.pathname +
                            (url.searchParams.toString() ? '?' + url.searchParams.toString() : '') +
                            url.hash
                        );
                    } catch (e) {}

                } else {
                    /* No new action: carry over a toast that is still alive. */
                    state = readState();

                    if (!state || !state.html || !state.createdAt) {
                        clearState();
                        return;
                    }

                    var carriedAge = now - Number(state.createdAt);

                    if (!(carriedAge >= 0) || carriedAge >= DURATION) {
                        clearState();
                        return;
                    }

                    var wrap = document.createElement('div');
                    wrap.className = 'orders-toast-wrap';
                    wrap.setAttribute('aria-live', 'polite');
                    wrap.setAttribute('aria-atomic', 'true');
                    wrap.innerHTML = state.html;
                    document.body.appendChild(wrap);

                    toast = wrap.querySelector('#ordersActionToast');

                    if (!toast) {
                        clearState();
                        return;
                    }

                    /* Do not replay the slide-in on every tab switch. */
                    toast.classList.add('is-restored');
                }

                var age = now - Number(state.createdAt);
                var visibleFor = DURATION - age;

                if (visibleFor <= 0) {
                    clearState();
                    toast.remove();
                    return;
                }

                /* Resume the progress bar at the right point: same total
                   duration, but started "age" ms in the past. */
                var progress = toast.querySelector('.orders-toast-progress');
                if (progress) {
                    progress.style.animationDuration = DURATION + 'ms';
                    progress.style.animationDelay = (-age) + 'ms';
                }

                var closeTimer = null;

                function closeToast() {
                    if (toast.classList.contains('is-closing')) {
                        return;
                    }
                    if (closeTimer !== null) {
                        clearTimeout(closeTimer);
                        closeTimer = null;
                    }
                    clearState();
                    toast.classList.add('is-closing');
                    setTimeout(function () { toast.remove(); }, 190);
                }

                var closeButton = toast.querySelector('.orders-toast-close');
                if (closeButton) {
                    closeButton.addEventListener('click', closeToast);
                }

                closeTimer = setTimeout(closeToast, visibleFor);

                /* Back/forward cache restores a frozen page with old timers.
                   Drop the stale toast instead of leaving it stuck. */
                window.addEventListener('pageshow', function (event) {
                    if (event.persisted) {
                        clearState();
                        toast.remove();
                    }
                });
            })();
            </script>




            <!-- WORKFLOW STATUS CARDS -->
            <div class="workflow-grid">

                <?php foreach ($workflow_cards as $status_key => $workflow): ?>

                    <a
                        href="<?= htmlspecialchars(staffOrdersUrl([
                            'status' => $status_key,
                            'page' => 1
                        ])) ?>"
                        class="workflow-card workflow-<?= htmlspecialchars($status_key) ?> <?= ($search === '' && $selected_status === $status_key) ? 'active' : '' ?>"
                        data-status="<?= htmlspecialchars($status_key) ?>" aria-current="<?= ($search === '' && $selected_status === $status_key) ? 'page' : 'false' ?>"
                        >

                        <div class="workflow-icon">
                            <i class="bi <?= htmlspecialchars($workflow['icon']) ?>"></i>
                        </div>

                        <div>
                            <div class="workflow-label">
                                <?= htmlspecialchars($workflow['label']) ?>
                            </div>
                            <div class="workflow-count">
                                <?= (int)$workflow['count'] ?>
                            </div>
                        </div>

                    </a>

                <?php endforeach; ?>

            </div>

            <!-- SEARCH -->
            <form method="GET" class="order-search-form">

                <input
                    type="hidden"
                    name="status"
                    value="<?= htmlspecialchars($selected_status) ?>"
                >

                <div class="search-box">
                    <i class="bi bi-search"></i>

                    <input
                        type="search"
                        name="q"
                        value="<?= htmlspecialchars($search) ?>"
                        placeholder="Search order number, claim number, customer..."
                        autocomplete="off"
                    >
                </div>

                <?php if ($search !== ''): ?>
                    <div class="search-actions">
                        <a
                            href="<?= htmlspecialchars(staffOrdersUrl(['q' => '', 'page' => 1])) ?>"
                            class="btn clear-button"
                        >
                            Clear
                        </a>
                    </div>
                <?php endif; ?>

            </form>

            <!-- ACTIVE ORDERS SECTION -->
            <section class="active-orders-section">

                <!-- ACTIVE ORDERS HEADING -->
                <div class="active-orders-heading">
                    <div>
                        <h3 class="active-orders-title">
                            <?= htmlspecialchars($orders_section_title) ?>
                        </h3>
                        <p class="active-orders-description">
                            <?= htmlspecialchars($orders_section_description) ?>
                        </p>
                    </div>

                    <?php if ($total_orders > 0): ?>
                        <div class="small text-muted">
                            Showing
                            <?= $offset + 1 ?>–
                            <?= min($offset + $per_page, $total_orders) ?>
                            of
                            <?= $total_orders ?>
                            order(s)
                        </div>
                    <?php endif; ?>
                </div>

                <!-- ORDERS -->
            <?php if (empty($orders)): ?>

                <div class="order-card empty-state">

                    <i class="bi bi-inbox fs-1 d-block mb-2"></i>

                    <?php if ($search !== ''): ?>

                        <div>
                            No orders matched
                            <strong>
                                <?= htmlspecialchars($search) ?>
                            </strong>.
                        </div>

                    <?php else: ?>

                        <div>
                            No orders found in this category.
                        </div>

                    <?php endif; ?>

                </div>

            <?php else: ?>

                <!-- TABULAR ACTIVE ORDERS -->
                <div class="orders-grid">

                    <div class="staff-orders-table-wrap">

                        <table class="staff-orders-table <?= $search !== '' ? 'with-status-column' : 'without-status-column' ?>">

                            <thead>
                                <tr>
                                    <th>Order</th>
                                    <th>Claim No.</th>
                                    <th>Customer</th>
                                    <th>Pick-up</th>
                                    <th>Payment</th>
                                    <th>Discount</th>
                                    <th class="text-end">Total</th>
                                    <?php if ($search !== ''): ?>
                                        <th>Status</th>
                                    <?php endif; ?>
                                    <th>Next Action</th>
                                    <th class="text-end">Details</th>
                                </tr>
                            </thead>

                            <?php
                            /* One clear next action per active workflow status. */
                            $tableNextActions = [
                                'order_queue' => [
                                    'status' => 'confirmed',
                                    'label' => 'Confirm Order',
                                    'icon' => 'bi-check-circle',
                                    'class' => 'btn-confirm'
                                ],
                                'confirmed' => [
                                    'status' => 'preparing',
                                    'label' => 'Start Preparing',
                                    'icon' => 'bi-cup-hot',
                                    'class' => 'btn-preparing'
                                ],
                                'preparing' => [
                                    'status' => 'ready',
                                    'label' => 'Mark Ready',
                                    'icon' => 'bi-bag-check',
                                    'class' => 'btn-ready'
                                ],
                                'ready' => [
                                    'status' => 'completed',
                                    'label' => 'Complete Order',
                                    'icon' => 'bi-check2-all',
                                    'class' => 'btn-complete'
                                ]
                            ];
                            ?>
                            <tbody>
                                <?php foreach ($orders as $order): ?>

                                    <?php
                                    $order_id = (int)$order['id'];
                                    $status = (string)$order['status'];
                                    $discount_type = strtolower(
                                        trim((string)($order['discount_type'] ?? 'none'))
                                    );
                                    $discount_rate = (float)($order['discount_rate'] ?? 0);
                                    $discount_amount = (float)($order['discount_amount'] ?? 0);
                                    ?>

                                    <tr
                                        class="order-card <?= (
                                            $target_is_active
                                            && $order_id === $target_order_id
                                        ) ? 'view-order-target' : '' ?>"
                                        id="order-<?= $order_id ?>"
                                        data-order-id="<?= $order_id ?>"
                                    >

                                        <td>
                                            <div class="order-table-mobile-header">
                                                <div class="order-table-mobile-identification">
                                                    <div class="order-table-primary">
                                                        <?= htmlspecialchars(
                                                            $order['order_number']
                                                            ?: 'ORD-' . $order_id
                                                        ) ?>
                                                    </div>
                                                    <div class="order-table-date">
                                                        <?= htmlspecialchars(
                                                            staffFormatDateTime($order['created_at'] ?? null)
                                                        ) ?>
                                                    </div>
                                                </div>
                                                <span class="order-table-mobile-claim">
                                                    <span class="order-table-mobile-claim-label">Claim</span>
                                                    <?= htmlspecialchars($order['claim_number'] ?: 'N/A') ?>
                                                </span>
                                            </div>
                                        </td>

                                        <td>
                                            <span class="order-table-claim">
                                                <?= htmlspecialchars(
                                                    $order['claim_number']
                                                    ?: 'N/A'
                                                ) ?>
                                            </span>
                                        </td>

                                        <td>
                                            <div class="order-table-primary">
                                                <?= htmlspecialchars(
                                                    $order['customer_name']
                                                ) ?>
                                            </div>
                                            <div class="order-table-date">
                                                <?= htmlspecialchars(
                                                    $order['contact_number']
                                                ) ?>
                                            </div>
                                        </td>

                                        <td>
                                            <div class="order-table-primary">
                                                <?= htmlspecialchars(
                                                    $order['pickup_date']
                                                ) ?>
                                            </div>
                                            <div class="order-table-date">
                                                <?= htmlspecialchars(
                                                    staffFormatTime($order['pickup_time'])
                                                ) ?>
                                            </div>
                                        </td>

                                        <td>
                                            <span
                                                class="order-table-payment <?php
                                                    echo strtolower(trim((string)$order['payment_method'])) === 'gcash'
                                                        ? 'payment-gcash'
                                                        : '';
                                                ?>"
                                            >
                                                <?= htmlspecialchars(
                                                    strtoupper((string)$order['payment_method'])
                                                ) ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?php if (
                                                in_array($discount_type, ['pwd', 'senior'], true)
                                                && $discount_amount > 0
                                            ): ?>
                                                <div class="order-table-discount-badge">
                                                    <?= $discount_type === 'pwd' ? 'PWD' : 'Senior Citizen' ?>
                                                </div>
                                                <div class="order-table-date">
                                                    <?= number_format($discount_rate, 0) ?>% • -₱<?= number_format($discount_amount, 2) ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="order-table-none">
                                                    None
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <td class="text-end">
                                            <div class="order-table-total">
                                                ₱<?= number_format(
                                                    (float)$order['total_amount'],
                                                    2
                                                ) ?>
                                            </div>
                                        </td>

                                        <?php if ($search !== ''): ?>
                                            <td>
                                                <span
                                                    class="status-badge status-<?= htmlspecialchars($status) ?>"
                                                >
                                                    <?= htmlspecialchars(
                                                        staffStatusLabel(
                                                            $status,
                                                            $status_labels
                                                        )
                                                    ) ?>
                                                </span>
                                            </td>
                                        <?php endif; ?>

                                        <td class="order-table-next-action-cell">
                                            <?php
                                            $tableNextAction = $tableNextActions[$status] ?? null;
                                            $rowPaymentProof = staffAssetPath(
                                                $order['payment_screenshot'] ?? ''
                                            );
                                            $isPendingGcash = (
                                                $status === 'pending_verification'
                                                && strtolower(trim((string)$order['payment_method'])) === 'gcash'
                                            );
                                            ?>

                                            <?php if ($isPendingGcash): ?>
                                                <?php if ($rowPaymentProof !== ''): ?>
                                                    <button
                                                        type="button"
                                                        class="btn action-btn btn-table-next-action btn-pending-action"
                                                        data-staff-gcash-review
                                                        data-order-id="<?= $order_id ?>"
                                                        data-order-number="<?= htmlspecialchars($order['order_number'] ?: 'ORD-' . $order_id, ENT_QUOTES, 'UTF-8') ?>"
                                                        data-claim-number="<?= htmlspecialchars($order['claim_number'] ?: 'N/A', ENT_QUOTES, 'UTF-8') ?>"
                                                        data-total-amount="<?= number_format((float)$order['total_amount'], 2, '.', '') ?>"
                                                        data-proof-src="<?= htmlspecialchars($rowPaymentProof, ENT_QUOTES, 'UTF-8') ?>"
                                                        data-status-filter="<?= htmlspecialchars($selected_status, ENT_QUOTES, 'UTF-8') ?>"
                                                        data-search="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                                                        data-page="<?= (int)$page ?>"
                                                        aria-label="Review GCash payment for <?= htmlspecialchars($order['order_number'] ?: 'order ' . $order_id, ENT_QUOTES, 'UTF-8') ?>"
                                                    >
                                                        <i class="bi bi-shield-check" aria-hidden="true"></i>
                                                        Review GCash
                                                    </button>
                                                <?php else: ?>
                                                    <button
                                                        type="button"
                                                        class="btn action-btn btn-table-next-action btn-pending-action"
                                                        disabled
                                                        title="This order has no uploaded GCash proof."
                                                    >
                                                        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                                                        Proof Missing
                                                    </button>
                                                <?php endif; ?>
                                            <?php elseif ($status === 'pending_verification'): ?>
                                                <span class="order-table-none">Payment details unavailable</span>
                                            <?php elseif ($tableNextAction): ?>
                                                <form method="POST" action="index.php" class="order-table-next-action-form">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($staff_csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="order_id" value="<?= $order_id ?>">
                                                    <input type="hidden" name="status" value="<?= htmlspecialchars($tableNextAction['status'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="status_filter" value="<?= htmlspecialchars($selected_status, ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="page" value="<?= (int)$page ?>">
                                                    <button
                                                        type="submit"
                                                        name="update_status"
                                                        value="1"
                                                        class="btn action-btn btn-table-next-action <?= htmlspecialchars($tableNextAction['class'], ENT_QUOTES, 'UTF-8') ?>"
                                                        aria-label="<?= htmlspecialchars($tableNextAction['label'] . ' for ' . ($order['order_number'] ?: 'order ' . $order_id), ENT_QUOTES, 'UTF-8') ?>"
                                                    >
                                                        <i class="bi <?= htmlspecialchars($tableNextAction['icon'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
                                                        <?= htmlspecialchars($tableNextAction['label'], ENT_QUOTES, 'UTF-8') ?>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="order-table-none">No action needed</span>
                                            <?php endif; ?>
                                        </td>

                                        <td class="text-end order-table-actions-cell order-table-details-cell">
                                            <button
                                                type="button"
                                                class="btn action-btn btn-view-details"
                                                data-order-details-modal="orderDetailsModal<?= $order_id ?>"
                                                aria-label="View details for <?= htmlspecialchars($order['order_number'] ?: 'order ' . $order_id, ENT_QUOTES, 'UTF-8') ?>"
                                            >
                                                <i class="bi bi-eye me-1" aria-hidden="true"></i>
                                                View Details
                                            </button>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>
                            </tbody>

                        </table>

                    </div>

                    <!-- =============================================
                         ORDER DETAIL / RECEIPT / PAYMENT / CANCEL MODALS
                    ============================================== -->
                    <?php foreach ($orders as $order): ?>

                        <?php
                        $order_id = (int)$order['id'];
                        $items = $order_items[$order_id] ?? [];
                        $status = (string)$order['status'];
                        $payment_proof = staffAssetPath(
                            $order['payment_screenshot'] ?? ''
                        );
                        $isPendingGcash = (
                            $status === 'pending_verification'
                            && strtolower(trim((string)($order['payment_method'] ?? ''))) === 'gcash'
                        );

                        $discount_type = strtolower(
                            trim((string)($order['discount_type'] ?? 'none'))
                        );
                        $discount_rate = (float)($order['discount_rate'] ?? 0);
                        $discount_amount = (float)($order['discount_amount'] ?? 0);
                        $discount_holder = staffDiscountCardholder($order);
                        ?>

                        <!-- =================================================
                             ORDER DETAILS MODAL — SCREENSHOT-INSPIRED LAYOUT
                        ================================================== -->
                        <?php
                        $modalOrderNumber = trim((string)($order['order_number'] ?? ''));
                        if ($modalOrderNumber === '') {
                            $modalOrderNumber = 'ORD-' . $order_id;
                        }
                        $modalOrderParts = explode('-', $modalOrderNumber);
                        $modalShortNumber = (string)end($modalOrderParts);
                        if ($modalShortNumber === '') {
                            $modalShortNumber = (string)$order_id;
                        }

                        $modalClaimNumber = trim((string)($order['claim_number'] ?? ''));
                        $modalClaimNumber = $modalClaimNumber !== '' ? $modalClaimNumber : 'N/A';

                        $modalPaymentMethod = strtolower(trim((string)($order['payment_method'] ?? '')));
                        $modalPaymentLabel = $modalPaymentMethod !== ''
                            ? strtoupper($modalPaymentMethod)
                            : 'NOT SET';

                        $modalCupCount = 0;
                        foreach ($items as $modalItemForCount) {
                            $modalCupCount += max(0, (int)($modalItemForCount['quantity'] ?? 0));
                        }

                        $modalProgressStep = match ($status) {
                            'order_queue', 'confirmed' => 1,
                            'preparing' => 2,
                            'ready' => 3,
                            'completed' => 4,
                            default => 0
                        };
                        $modalProgressSteps = [
                            ['label' => 'In queue', 'number' => 1],
                            ['label' => 'Preparing', 'number' => 2],
                            ['label' => 'Ready', 'number' => 3],
                            ['label' => 'Picked up', 'number' => 4]
                        ];

                        $modalPickupDateTimestamp = !empty($order['pickup_date'])
                            ? strtotime((string)$order['pickup_date'])
                            : false;
                        $modalPickupTimeTimestamp = !empty($order['pickup_time'])
                            ? strtotime((string)$order['pickup_time'])
                            : false;
                        $modalPickupDateLabel = $modalPickupDateTimestamp
                            ? date('M j, Y', $modalPickupDateTimestamp)
                            : '—';
                        $modalPickupTimeLabel = $modalPickupTimeTimestamp
                            ? date('g:i A', $modalPickupTimeTimestamp)
                            : staffFormatTime($order['pickup_time'] ?? null);

                        $modalCreatedAtTimestamp = !empty($order['created_at'])
                            ? strtotime((string)$order['created_at'])
                            : false;
                        $modalPlacedAtLabel = $modalCreatedAtTimestamp
                            ? date('M j, Y, g:i A', $modalCreatedAtTimestamp)
                            : staffFormatDateTime($order['created_at'] ?? null);

                        $modalDiscountLabel = match ($discount_type) {
                            'pwd' => 'PWD Discount',
                            'senior' => 'Senior Citizen Discount',
                            default => 'Discount (None)'
                        };
                        $modalSubtotal = (float)($order['subtotal'] ?? $order['total_amount'] ?? 0);
                        $modalDiscountAmount = max(0, $discount_amount);
                        $modalTotal = (float)($order['total_amount'] ?? 0);
                        ?>
                        <div
                            class="modal fade order-details-modal"
                            id="orderDetailsModal<?= $order_id ?>"
                            tabindex="-1"
                            aria-hidden="true"
                        >
                            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable order-details-modal-dialog">
                                <div class="modal-content order-modal-content">

                                    <div class="modal-header details-header order-modal-header">
                                        <div class="order-modal-heading">
                                            <div class="order-modal-title-row">
                                                <h5 class="modal-title order-modal-title">
                                                    Order #<?= htmlspecialchars($modalShortNumber, ENT_QUOTES, 'UTF-8') ?>
                                                </h5>
                                                <span class="order-modal-status-pill status-<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
                                                    <?= htmlspecialchars(staffStatusLabel($status, $status_labels), ENT_QUOTES, 'UTF-8') ?>
                                                </span>
                                            </div>
                                            <div class="order-modal-subtitle">
                                                <?= htmlspecialchars($modalOrderNumber, ENT_QUOTES, 'UTF-8') ?>
                                                <span aria-hidden="true">·</span>
                                                Claim #<?= htmlspecialchars($modalClaimNumber, ENT_QUOTES, 'UTF-8') ?>
                                                <span aria-hidden="true">·</span>
                                                Placed <?= htmlspecialchars($modalPlacedAtLabel, ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                        </div>

                                        <button
                                            type="button"
                                            class="btn-close order-modal-close"
                                            data-bs-dismiss="modal"
                                            aria-label="Close"
                                        ></button>

                                        <div class="order-modal-progress" aria-label="Order progress">
                                            <?php foreach ($modalProgressSteps as $modalStep): ?>
                                                <?php
                                                $modalStepNumber = (int)$modalStep['number'];
                                                $modalStepClass = $modalProgressStep > $modalStepNumber
                                                    ? 'is-complete'
                                                    : ($modalProgressStep === $modalStepNumber ? 'is-current' : '');
                                                ?>
                                                <div class="order-modal-progress-step <?= $modalStepClass ?>">
                                                    <span class="order-modal-progress-marker">
                                                        <?php if ($modalProgressStep > $modalStepNumber): ?>
                                                            <i class="bi bi-check" aria-hidden="true"></i>
                                                        <?php else: ?>
                                                            <?= $modalStepNumber ?>
                                                        <?php endif; ?>
                                                    </span>
                                                    <span class="order-modal-progress-label">
                                                        <?= htmlspecialchars($modalStep['label'], ENT_QUOTES, 'UTF-8') ?>
                                                    </span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>

                                        <?php if ($status === 'pending_verification'): ?>
                                            <div class="order-modal-pending-note">
                                                <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                                                Payment verification is required before this order enters the queue.
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="modal-body order-modal-body">
                                        <div class="order-modal-layout">

                                            <!-- LEFT: ITEMS AND TOTAL -->
                                            <section class="order-modal-items-column" aria-label="Ordered items">
                                                <div class="order-modal-section-heading">
                                                    ITEMS (<?= count($items) ?>) <span>·</span> <?= $modalCupCount ?> CUPS
                                                </div>

                                                <?php if ($items): ?>
                                                    <div class="order-modal-item-list">
                                                        <?php foreach ($items as $item): ?>
                                                            <?php
                                                            $itemQuantity = max(0, (int)($item['quantity'] ?? 0));
                                                            $itemUnitPrice = (float)($item['unit_price'] ?? 0);
                                                            $itemLineTotal = isset($item['subtotal'])
                                                                ? (float)$item['subtotal']
                                                                : (isset($item['total_price'])
                                                                    ? (float)$item['total_price']
                                                                    : ($itemUnitPrice * $itemQuantity));
                                                            $itemCustomizations = [];
                                                            if (trim((string)($item['size'] ?? '')) !== '') {
                                                                $itemCustomizations[] = trim((string)$item['size']);
                                                            }
                                                            if (trim((string)($item['sugar_level'] ?? '')) !== '') {
                                                                $itemCustomizations[] = trim((string)$item['sugar_level']) . ' sugar';
                                                            }
                                                            $itemDiscountType = strtolower(trim((string)($item['discount_type'] ?? 'none')));
                                                            $itemDiscountRate = (float)($item['discount_rate'] ?? 0);
                                                            $itemDiscountAmount = (float)($item['discount_amount'] ?? 0);
                                                            if (in_array($itemDiscountType, ['pwd', 'senior'], true) && $itemDiscountAmount > 0) {
                                                                $itemCustomizations[] = ($itemDiscountType === 'pwd' ? 'PWD' : 'Senior')
                                                                    . ' ' . number_format($itemDiscountRate, 0) . '%';
                                                            }
                                                            $modalItemAddons = staffGetAddons($item['addons'] ?? null);
                                                            ?>
                                                            <article class="order-modal-item">
                                                                <div class="order-modal-item-quantity">
                                                                    <?= $itemQuantity ?>×
                                                                </div>

                                                                <div class="order-modal-item-main">
                                                                    <div class="order-modal-item-name">
                                                                        <?= htmlspecialchars($item['product_name'] ?? 'Item', ENT_QUOTES, 'UTF-8') ?>
                                                                    </div>

                                                                    <?php if ($itemCustomizations): ?>
                                                                        <div class="order-modal-item-tags">
                                                                            <?php foreach ($itemCustomizations as $customization): ?>
                                                                                <span><?= htmlspecialchars($customization, ENT_QUOTES, 'UTF-8') ?></span>
                                                                            <?php endforeach; ?>
                                                                        </div>
                                                                    <?php endif; ?>

                                                                    <?php if ($modalItemAddons): ?>
                                                                        <div class="order-modal-item-addons">
                                                                            <?php foreach ($modalItemAddons as $addonDetail): ?>
                                                                                <span>
                                                                                    <?= htmlspecialchars($addonDetail['name'] ?? 'Add-on', ENT_QUOTES, 'UTF-8') ?>
                                                                                    <?php if (isset($addonDetail['price'])): ?>
                                                                                        (+₱<?= number_format((float)$addonDetail['price'], 2) ?>)
                                                                                    <?php endif; ?>
                                                                                </span>
                                                                            <?php endforeach; ?>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                </div>

                                                                <div class="order-modal-item-price">
                                                                    <strong>₱<?= number_format($itemLineTotal, 2) ?></strong>
                                                                    <small>₱<?= number_format($itemUnitPrice, 2) ?> each</small>
                                                                </div>
                                                            </article>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="order-modal-empty-items">
                                                        No order items were found for this order.
                                                    </div>
                                                <?php endif; ?>

                                                <!-- TOTAL SUMMARY -->
                                                <div class="order-modal-total-card">
                                                    <div class="order-modal-total-line">
                                                        <span>Subtotal</span>
                                                        <strong>₱<?= number_format($modalSubtotal, 2) ?></strong>
                                                    </div>
                                                    <div class="order-modal-total-line">
                                                        <span>
                                                            <?= htmlspecialchars($modalDiscountLabel, ENT_QUOTES, 'UTF-8') ?>
                                                            <?php if (in_array($discount_type, ['pwd', 'senior'], true) && $discount_amount > 0): ?>
                                                                (<?= number_format($discount_rate, 0) ?>%)
                                                            <?php endif; ?>
                                                        </span>
                                                        <strong>−₱<?= number_format($modalDiscountAmount, 2) ?></strong>
                                                    </div>
                                                    <div class="order-modal-total-divider"></div>
                                                    <div class="order-modal-total-final">
                                                        <div>
                                                            <strong><?= $modalPaymentMethod === 'cash' ? 'Total to collect' : 'Order total' ?></strong>
                                                            <small>
                                                                <?php if ($modalPaymentMethod === 'cash'): ?>
                                                                    Pay with cash on pick-up
                                                                <?php elseif ($modalPaymentMethod === 'gcash' && $status === 'pending_verification'): ?>
                                                                    Awaiting GCash payment verification
                                                                <?php elseif ($modalPaymentMethod === 'gcash'): ?>
                                                                    GCash payment
                                                                <?php else: ?>
                                                                    Payment method not specified
                                                                <?php endif; ?>
                                                            </small>
                                                        </div>
                                                        <span>₱<?= number_format($modalTotal, 2) ?></span>
                                                    </div>
                                                </div>
                                            </section>

                                            <!-- RIGHT: CUSTOMER, PICK-UP, PAYMENT -->
                                            <aside class="order-modal-side-column">
                                                <section class="order-modal-info-section">
                                                    <div class="order-modal-section-heading">CUSTOMER</div>
                                                    <div class="order-modal-info-card">
                                                        <div class="order-modal-customer-name">
                                                            <?= htmlspecialchars(trim((string)($order['customer_name'] ?? '')) ?: 'Guest', ENT_QUOTES, 'UTF-8') ?>
                                                        </div>
                                                        <div class="order-modal-contact">
                                                            <i class="bi bi-telephone" aria-hidden="true"></i>
                                                            <span><?= htmlspecialchars(trim((string)($order['contact_number'] ?? '')) ?: 'No contact number', ENT_QUOTES, 'UTF-8') ?></span>
                                                        </div>
                                                    </div>
                                                </section>

                                                <section class="order-modal-info-section">
                                                    <div class="order-modal-section-heading">PICK-UP</div>
                                                    <div class="order-modal-info-card order-modal-pickup-card">
                                                        <div>
                                                            <small>Date</small>
                                                            <strong><?= htmlspecialchars($modalPickupDateLabel, ENT_QUOTES, 'UTF-8') ?></strong>
                                                        </div>
                                                        <div class="text-end">
                                                            <small>Time</small>
                                                            <strong><?= htmlspecialchars($modalPickupTimeLabel, ENT_QUOTES, 'UTF-8') ?></strong>
                                                        </div>
                                                    </div>
                                                </section>

                                                <section class="order-modal-info-section">
                                                    <div class="order-modal-section-heading">PAYMENT</div>
                                                    <div class="order-modal-info-card order-modal-payment-card">
                                                        <div class="order-modal-payment-row">
                                                            <span class="order-modal-payment-badge <?= $modalPaymentMethod === 'gcash' ? 'is-gcash' : 'is-cash' ?>">
                                                                <?= htmlspecialchars($modalPaymentLabel, ENT_QUOTES, 'UTF-8') ?>
                                                            </span>
                                                            <span class="order-modal-discount-short">
                                                                <?php if (in_array($discount_type, ['pwd', 'senior'], true) && $discount_amount > 0): ?>
                                                                    <?= $discount_type === 'pwd' ? 'PWD' : 'Senior' ?> · <?= number_format($discount_rate, 0) ?>%
                                                                <?php else: ?>
                                                                    No discount · 0%
                                                                <?php endif; ?>
                                                            </span>
                                                        </div>
                                                        <?php if ($modalPaymentMethod === 'gcash'): ?>
                                                            <?php if ($payment_proof !== ''): ?>
                                                                <div class="order-modal-proof-preview">
                                                                    <div class="order-modal-proof-preview-heading">
                                                                        <span><i class="bi bi-image me-1" aria-hidden="true"></i>GCash payment proof</span>
                                                                        <?php if ($isPendingGcash): ?>
                                                                            <span class="order-modal-proof-pending-label">Pending verification</span>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                    <img
                                                                        src="<?= htmlspecialchars($payment_proof, ENT_QUOTES, 'UTF-8') ?>"
                                                                        alt="Uploaded GCash payment proof for <?= htmlspecialchars($modalOrderNumber, ENT_QUOTES, 'UTF-8') ?>"
                                                                        class="order-modal-proof-image"
                                                                        decoding="async"
                                                                    >
                                                                </div>
                                                            <?php elseif (!empty($gcash_references[$order_id])): ?>
                                                                <div class="order-modal-payment-note">
                                                                    GCash Ref No.
                                                                    <strong><?= htmlspecialchars(staffFormatGcashReference($gcash_references[$order_id]), ENT_QUOTES, 'UTF-8') ?></strong>
                                                                </div>
                                                            <?php else: ?>
                                                                <div class="order-modal-payment-note">
                                                                    GCash payment proof has not been uploaded.
                                                                </div>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </section>

                                                <?php if (
                                                    in_array($discount_type, ['pwd', 'senior'], true)
                                                    && $discount_amount > 0
                                                    && ($discount_holder['id'] !== '' || $discount_holder['name'] !== '')
                                                ): ?>
                                                    <section class="order-modal-info-section">
                                                        <div class="order-modal-section-heading">DISCOUNT DETAILS</div>
                                                        <div class="order-modal-info-card order-modal-discount-card">
                                                            <strong><?= htmlspecialchars($discount_holder['name'] ?: ($discount_type === 'pwd' ? 'PWD' : 'Senior Citizen'), ENT_QUOTES, 'UTF-8') ?></strong>
                                                            <?php if ($discount_holder['id'] !== ''): ?>
                                                                <small>ID: <?= htmlspecialchars($discount_holder['id'], ENT_QUOTES, 'UTF-8') ?></small>
                                                            <?php endif; ?>
                                                        </div>
                                                    </section>
                                                <?php endif; ?>
                                            </aside>
                                        </div>
                                    </div>

                                    <!-- ACTIONS STAY IN THE MODAL FOOTER AT BOTTOM-RIGHT -->
                                    <div class="modal-footer order-modal-footer">
                                        <div class="order-modal-footer-actions">
                                            <?php
                                            $isReadyNoShowCandidate = ($status === 'ready');
                                            $noShowTiming = staffGetNoShowTiming($order);
                                            $noShowEligible = $noShowTiming['eligible'];
                                            ?>
                                            <?php if ($isReadyNoShowCandidate): ?>
                                                <!-- All Ready orders have a dedicated No-show action. Preparing orders remain non-cancellable. -->
                                                <button
                                                    type="button"
                                                    class="btn order-modal-cancel-button"
                                                    data-staff-cancel-order
                                                    data-no-show-only="1"
                                                    data-order-id="<?= $order_id ?>"
                                                    data-order-number="<?= htmlspecialchars($modalOrderNumber, ENT_QUOTES, 'UTF-8') ?>"
                                                    data-order-status="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"
                                                    data-order-payment-method="<?= htmlspecialchars(strtolower(trim((string)($order['payment_method'] ?? ''))), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-no-show-eligible-at="<?= (int)$noShowTiming['eligible_at'] ?>"
                                                    aria-disabled="<?= $noShowEligible ? 'false' : 'true' ?>"
                                                    title="<?= $noShowEligible
                                                        ? 'The pickup time and collection grace period have passed. Confirm that the customer did not collect the order.'
                                                        : 'Available 30 minutes after the later of the scheduled pickup time and the time the order was marked Ready.' ?>"
                                                    <?= $noShowEligible ? '' : 'disabled' ?>
                                                >
                                                    <i class="bi bi-person-x me-1" aria-hidden="true"></i>
                                                    Mark as No-show
                                                </button>
                                            <?php elseif (
                                                !$isPendingGcash
                                                && in_array($status, ['pending_verification', 'order_queue', 'confirmed'], true)
                                            ): ?>
                                                <button
                                                    type="button"
                                                    class="btn order-modal-cancel-button"
                                                    data-staff-cancel-order
                                                    data-order-id="<?= $order_id ?>"
                                                    data-order-number="<?= htmlspecialchars($modalOrderNumber, ENT_QUOTES, 'UTF-8') ?>"
                                                    data-order-status="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"
                                                    data-order-payment-method="<?= htmlspecialchars(strtolower(trim((string)($order['payment_method'] ?? ''))), ENT_QUOTES, 'UTF-8') ?>"
                                                >
                                                    <i class="bi bi-x-circle me-1" aria-hidden="true"></i>
                                                    Cancel Order
                                                </button>
                                            <?php endif; ?>

                                            <button
                                                type="button"
                                                class="btn order-modal-print-button"
                                                onclick="printReceipt('receiptModal<?= $order_id ?>')"
                                            >
                                                <i class="bi bi-printer me-1" aria-hidden="true"></i>
                                                Print Receipt
                                            </button>
                                        </div>
                                    </div>

                                </div>
                            </div>
                        </div>

                    <!-- =================================================
                         RECEIPT MODAL
                    ================================================== -->
                    <div
                        class="modal fade"
                        id="receiptModal<?= $order_id ?>"
                        tabindex="-1"
                        aria-hidden="true"
                    >

                        <div class="modal-dialog modal-dialog-centered modal-lg">

                            <div class="modal-content">

                                <div class="modal-header">

                                    <h5
                                        class="modal-title fw-bold"
                                        style="color:#4A3525;"
                                    >
                                        Print Receipt
                                    </h5>

                                    <button
                                        type="button"
                                        class="btn-close"
                                        data-bs-dismiss="modal"
                                    ></button>

                                </div>

                                <div class="modal-body bg-light">

                                    <div
                                        class="receipt-paper"
                                        id="receiptPaper<?= $order_id ?>"
                                    >

                                        <div class="text-center">

                                            <h4 class="fw-bold mb-1">
                                                Local Milktea House
                                            </h4>

                                            <div class="small text-muted">
                                                Order Receipt
                                            </div>

                                        </div>

                                        <div class="receipt-line"></div>

                                        <div class="d-flex justify-content-between small">
                                            <span>Order #</span>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    $order['order_number']
                                                    ?: 'N/A'
                                                ) ?>
                                            </strong>
                                        </div>

                                        <div class="d-flex justify-content-between small">
                                            <span>Claim #</span>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    $order['claim_number']
                                                    ?: 'N/A'
                                                ) ?>
                                            </strong>
                                        </div>

                                        <div class="d-flex justify-content-between small">
                                            <span>Customer</span>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    $order['customer_name']
                                                ) ?>
                                            </strong>
                                        </div>

                                        <?php
                                        $receiptIsWalkin = ($order['order_source'] ?? '') === 'walk_in';
                                        $receiptPaymentLabel = strtolower(trim((string)$order['payment_method'])) === 'gcash'
                                            ? 'GCash'
                                            : 'Cash';
                                        ?>

                                        <div class="d-flex justify-content-between small">
                                            <span>Order Type</span>
                                            <strong><?= $receiptIsWalkin ? 'Walk-in' : 'Online Pick-up' ?></strong>
                                        </div>

                                        <div class="d-flex justify-content-between small">
                                            <span>Date</span>
                                            <strong><?= htmlspecialchars(staffFormatDateTime($order['created_at'] ?? null)) ?></strong>
                                        </div>

                                        <?php if (!$receiptIsWalkin): ?>
                                            <div class="d-flex justify-content-between small">
                                                <span>Pick-up</span>
                                                <strong>
                                                    <?= htmlspecialchars(
                                                        !empty($order['pickup_date'])
                                                            ? date('M d, Y', strtotime((string)$order['pickup_date']))
                                                            : '—'
                                                    ) ?>
                                                    <?= htmlspecialchars(
                                                        staffFormatTime(
                                                            $order['pickup_time']
                                                        )
                                                    ) ?>
                                                </strong>
                                            </div>
                                        <?php endif; ?>

                                        <div class="d-flex justify-content-between small">
                                            <span>Payment</span>
                                            <strong><?= $receiptPaymentLabel ?></strong>
                                        </div>

                                        <?php if (!empty($gcash_references[$order_id])): ?>
                                            <div class="d-flex justify-content-between small">
                                                <span>GCash Ref No.</span>
                                                <strong><?= htmlspecialchars(staffFormatGcashReference($gcash_references[$order_id])) ?></strong>
                                            </div>
                                        <?php endif; ?>

                                        <div class="receipt-line"></div>

                                        <?php foreach ($items as $item): ?>

                                            <div class="mb-2">

                                                <div class="d-flex justify-content-between small">

                                                    <span>
                                                        <?= (int)$item['quantity'] ?>
                                                        ×
                                                        <?= htmlspecialchars(
                                                            $item['product_name']
                                                        ) ?>
                                                    </span>

                                                    <strong>
                                                        ₱<?= number_format(
                                                            (float)$item['subtotal'],
                                                            2
                                                        ) ?>
                                                    </strong>

                                                </div>

                                                <?php
                                                $receipt_customizations = [];

                                                if (!empty($item['size'])) {
                                                    $receipt_customizations[] =
                                                        'Size: ' . $item['size'];
                                                }

                                                if (!empty($item['sugar_level'])) {
                                                    $receipt_customizations[] =
                                                        'Sugar: ' . $item['sugar_level'];
                                                }

                                                if (!empty($item['addons'])) {

                                                    $receipt_addons =
                                                        json_decode(
                                                            (string)$item['addons'],
                                                            true
                                                        );

                                                    if (is_array($receipt_addons)) {

                                                        $receipt_addons_text =
                                                            implode(
                                                                ', ',
                                                                array_map(
                                                                    'strval',
                                                                    $receipt_addons
                                                                )
                                                            );

                                                    } else {

                                                        $receipt_addons_text =
                                                            (string)$item['addons'];
                                                    }

                                                    if (
                                                        trim($receipt_addons_text) !== ''
                                                    ) {

                                                        $receipt_customizations[] =
                                                            'Add-ons: ' .
                                                            $receipt_addons_text;
                                                    }
                                                }
                                                ?>

                                                <?php if ($receipt_customizations): ?>

                                                    <div
                                                        class="text-muted"
                                                        style="font-size:.68rem;"
                                                    >
                                                        <?= htmlspecialchars(
                                                            implode(
                                                                ' • ',
                                                                $receipt_customizations
                                                            )
                                                        ) ?>
                                                    </div>

                                                <?php endif; ?>

                                            </div>

                                        <?php endforeach; ?>

                                        <div class="receipt-line"></div>

                                        <div class="d-flex justify-content-between small">
                                            <span>Subtotal</span>
                                            <strong>₱<?= number_format((float)$order['subtotal'], 2) ?></strong>
                                        </div>

                                        <?php if (
                                            in_array($discount_type, ['pwd', 'senior'], true)
                                            && $discount_amount > 0
                                        ): ?>
                                            <div class="d-flex justify-content-between small">
                                                <span>
                                                    <?= $discount_type === 'pwd' ? 'PWD Discount' : 'Senior Citizen Discount' ?>
                                                    (<?= number_format($discount_rate, 0) ?>%)
                                                </span>
                                                <strong>-₱<?= number_format($discount_amount, 2) ?></strong>
                                            </div>
                                        <?php endif; ?>

                                        <div class="d-flex justify-content-between mt-1">
                                            <span class="fw-bold">Total</span>
                                            <span class="fw-bold">₱<?= number_format((float)$order['total_amount'], 2) ?></span>
                                        </div>

                                        <?php if (
                                            in_array($discount_type, ['pwd', 'senior'], true)
                                            && $discount_amount > 0
                                        ): ?>
                                            <div class="receipt-line"></div>

                                            <div class="small fw-bold mb-1">
                                                <?= $discount_type === 'pwd' ? 'PWD' : 'Senior Citizen' ?> Discount Details
                                            </div>

                                            <div class="d-flex justify-content-between small">
                                                <span>Name</span>
                                                <strong>
                                                    <?= $discount_holder['name'] !== ''
                                                        ? htmlspecialchars($discount_holder['name'])
                                                        : '______________________' ?>
                                                </strong>
                                            </div>

                                            <div class="d-flex justify-content-between small">
                                                <span>ID No.</span>
                                                <strong>
                                                    <?= $discount_holder['id'] !== ''
                                                        ? htmlspecialchars($discount_holder['id'])
                                                        : '______________________' ?>
                                                </strong>
                                            </div>
                                        <?php endif; ?>

                                        <div class="receipt-line"></div>

                                        <div class="text-center small text-muted">
                                            Thank you for your order!
                                        </div>

                                    </div>
                                    <!-- /.receipt-paper -->

                                </div>
                                <!-- /.modal-body.bg-light -->

                            </div>
                            <!-- /.modal-content -->

                        </div>
                        <!-- /.modal-dialog -->

                    </div>
                    <!-- /receiptModal<?= $order_id ?> -->

                    <?php endforeach; ?>

                </div>

                <!-- PAGINATION -->
                <?php if ($total_pages > 1): ?>

                    <div class="pagination-wrap">

                        <nav aria-label="Order pagination">

                            <ul class="pagination mb-0">

                                <li
                                    class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"
                                >
                                    <a
                                        class="page-link"
                                        href="<?= htmlspecialchars(
                                            staffOrdersUrl([
                                                'page' => max(1, $page - 1)
                                            ])
                                        ) ?>"
                                    >
                                        Previous
                                    </a>
                                </li>

                                <?php
                                /*
                                 * Always keep page 1 visible, then show a small
                                 * window around the current page and the last page.
                                 * Missing ranges are represented by an ellipses item.
                                 */
                                $activePaginationPages = [1];

                                for (
                                    $active_p = max(2, $page - 2);
                                    $active_p <= min(
                                        $total_pages - 1,
                                        $page + 2
                                    );
                                    $active_p++
                                ) {
                                    $activePaginationPages[] = $active_p;
                                }

                                $activePaginationPages[] = $total_pages;

                                $activePaginationPages = array_values(
                                    array_unique($activePaginationPages)
                                );

                                $previousActivePage = 0;

                                foreach (
                                    $activePaginationPages
                                    as $p
                                ):

                                    if (
                                        $previousActivePage > 0
                                        && $p > $previousActivePage + 1
                                    ):
                                ?>

                                    <li class="page-item disabled" aria-hidden="true">
                                        <span class="page-link">...</span>
                                    </li>

                                <?php endif; ?>

                                    <li
                                        class="page-item <?= $p === $page ? 'active' : '' ?>"
                                    >

                                        <a
                                            class="page-link"
                                            href="<?= htmlspecialchars(
                                                staffOrdersUrl([
                                                    'page' => $p
                                                ])
                                            ) ?>"
                                            <?= $p === $page ? 'aria-current="page"' : '' ?>
                                        >
                                            <?= $p ?>
                                        </a>

                                    </li>

                                <?php
                                    $previousActivePage = $p;
                                endforeach;
                                ?>

                                <li
                                    class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>"
                                >

                                    <a
                                        class="page-link"
                                        href="<?= htmlspecialchars(
                                            staffOrdersUrl([
                                                'page' => min(
                                                    $total_pages,
                                                    $page + 1
                                                )
                                            ])
                                        ) ?>"
                                    >
                                        Next
                                    </a>

                                </li>

                            </ul>

                        </nav>

                    </div>

                <?php endif; ?>

            <?php endif; ?>

            </section>

            <!-- Staff sees only the count; refund actions remain Admin-only. -->
            <div class="staff-refund-count-notice" role="status" aria-live="polite">
    <span class="staff-refund-count-icon" aria-hidden="true"><i class="bi bi-arrow-counterclockwise"></i></span>
    <div class="staff-refund-count-copy">
        <strong>Pending Refunds</strong>
        <p id="staffRefundCountMessage">
            <?php if ($pending_refund_count > 0): ?>
                GCash refunds awaiting Admin review.
            <?php else: ?>
                No pending GCash refunds.
            <?php endif; ?>
        </p>
    </div>
    <span class="staff-refund-count-value" aria-label="Pending GCash refund request count"><?= (int)$pending_refund_count ?></span>
</div>

            <!-- =====================================================
                 CANCELLED ORDERS
                 Collapsed by default so historical records do not
                 make the Orders page unnecessarily long.
            ====================================================== -->
            <details
                class="cancelled-orders-section order-history-section"
                <?= (
                    $cancelled_search !== ''
                    || $cancelled_open === '1'
                ) ? 'open' : '' ?>
            >
                <summary class="cancelled-section-summary">

                    <span class="cancelled-summary-icon" aria-hidden="true">
                        <i class="bi bi-clock-history"></i>
                    </span>

                    <div class="cancelled-summary-copy">
                        <h3 class="cancelled-section-title">Order History</h3>
                        <span class="cancelled-section-description">
                            Completed and cancelled orders
                        </span>
                    </div>

                    <span class="cancelled-summary-count">
                        <?= (int)$cancelled_total_orders ?>
                    </span>

                    <span class="cancelled-toggle" aria-hidden="true">
                        <i class="bi bi-chevron-down cancelled-toggle-icon"></i>
                    </span>

                </summary>

                <div class="cancelled-section-content">

                    <form method="GET" class="cancelled-filter-form" data-cancelled-filter-form>

                        <input
                            type="hidden"
                            name="status"
                            value="<?= htmlspecialchars($selected_status) ?>"
                        >

                        <input
                            type="hidden"
                            name="q"
                            value="<?= htmlspecialchars($search) ?>"
                        >

                        <input
                            type="hidden"
                            name="page"
                            value="<?= $page ?>"
                        >

                        <input
                            type="hidden"
                            name="cancelled_open"
                            value="1"
                        >

                        <div class="cancelled-filter-group">

                            <label for="historyStatus">
                                Status
                            </label>

                            <select
                                id="historyStatus"
                                name="history_status"
                                class="cancelled-filter-period"
                                data-history-status
                            >
                                <?php foreach (['all' => 'All', 'completed' => 'Completed', 'cancelled' => 'Cancelled'] as $historyStatusKey => $historyStatusLabel): ?>
                                    <option
                                        value="<?= $historyStatusKey ?>"
                                        <?= $history_status === $historyStatusKey ? 'selected' : '' ?>
                                    ><?= $historyStatusLabel ?></option>
                                <?php endforeach; ?>
                            </select>

                        </div>

                        <div class="cancelled-filter-group">

                            <label for="cancelledPeriod">
                                Date
                            </label>

                            <select
                                id="cancelledPeriod"
                                name="cancelled_period"
                                class="cancelled-filter-period"
                                data-cancelled-period
                            >
                                <?php foreach ($cancelled_period_labels as $periodKey => $periodLabel): ?>
                                    <option
                                        value="<?= htmlspecialchars($periodKey) ?>"
                                        <?= $cancelled_period === $periodKey ? 'selected' : '' ?>
                                    >
                                        <?= htmlspecialchars($periodLabel) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                        </div>

                        <div
                            class="cancelled-filter-group"
                            data-cancelled-month-wrap
                            style="<?= $cancelled_period === 'specific_month' ? '' : 'display:none;' ?>"
                        >

                            <label for="cancelledMonth">
                                Choose Month
                            </label>

                            <input
                                type="month"
                                id="cancelledMonth"
                                name="cancelled_month"
                                class="cancelled-filter-month"
                                value="<?= htmlspecialchars($cancelled_month) ?>"
                                max="<?= date('Y-m') ?>"
                                data-cancelled-month
                            >

                        </div>

                        <div class="cancelled-search-field">

                            <label class="visually-hidden" for="cancelledSearch">
                                Search Order History
                            </label>

                            <div class="search-box cancelled-search-box">
                                <i class="bi bi-search" aria-hidden="true"></i>

                                <input
                                    type="search"
                                    id="cancelledSearch"
                                    name="cancelled_q"
                                    class="cancelled-filter-search"
                                    value="<?= htmlspecialchars($cancelled_search) ?>"
                                    placeholder="Search order no., claim no., customer, GCash ref..."
                                    autocomplete="off"
                                    data-cancelled-search
                                >
                            </div>

                            <!-- Keeps Enter-to-search behavior without showing a Search button. -->
                            <button
                                type="submit"
                                class="visually-hidden"
                                tabindex="-1"
                                aria-hidden="true"
                            >Search</button>

                        </div>

                        

                    </form>

                    <?php if (empty($cancelled_orders)): ?>

                        <div class="order-card empty-state">

                            <i class="bi bi-inbox fs-1 d-block mb-2"></i>

                            <?php if (
                                $cancelled_search !== ''
                            ): ?>

                                <div>
                                    No orders matched the selected filters.
                                </div>

                            <?php else: ?>

                                <div>
                                    No completed or cancelled orders for this period.
                                </div>

                            <?php endif; ?>

                        </div>

                    <?php else: ?>

                        <div class="small text-muted mb-2">

                            Showing
                            <?= $cancelled_offset + 1 ?>–
                            <?= min(
                                $cancelled_offset + $cancelled_per_page,
                                $cancelled_total_orders
                            ) ?>
                            of
                            <?= $cancelled_total_orders ?>
                            order(s)

                        </div>

                        <div class="history-table-scroll-hint d-xl-none"><i class="bi bi-arrows-angle-expand me-1"></i>Swipe horizontally to view all columns.</div>
                        <div class="staff-orders-table-wrap history-orders-table-wrap cancelled-history-table-wrap">
                            <table class="staff-orders-table history-orders-table cancelled-history-table">
                                <thead>
                                    <tr>
                                        <th>Order</th>
                                        <th>Customer</th>
                                        <th>Type / Pick-up</th>
                                        <th>Payment</th>
                                        <th class="text-end">Total</th>
                                        <th>Notes</th>
                                        <th>Closed At</th>
                                        <th class="text-end">Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($cancelled_orders as $cancelled_order): ?>
                                    <?php
                                    $cancelled_order_id = (int)$cancelled_order['id'];
                                    $cancelled_items = $order_items[$cancelled_order_id] ?? [];
                                    $cancelled_by_role = $cancelled_by_roles[$cancelled_order_id] ?? null;
                                    $cancelled_payment_proof = staffAssetPath($cancelled_order['payment_screenshot'] ?? '');
                                    $cancelledIdentifier = $cancelled_order['order_number'] ?: 'ORD-' . $cancelled_order_id;
                                    $historyIsCancelled = (string)$cancelled_order['status'] === 'cancelled';
                                    $historyIsWalkin = ($cancelled_order['order_source'] ?? '') === 'walk_in';
                                    $historyIsGcash = strtolower(trim((string)$cancelled_order['payment_method'])) === 'gcash';
                                    $historyGcashRef = $gcash_references[$cancelled_order_id] ?? '';
                                    ?>
                                    <tr class="order-card history-order-row <?= ($target_order_id === $cancelled_order_id) ? 'view-order-target' : '' ?>" id="order-<?= $cancelled_order_id ?>" data-order-id="<?= $cancelled_order_id ?>">
                                        <td>
                                            <div class="order-table-primary"><?= htmlspecialchars($cancelledIdentifier) ?></div>
                                            <div class="order-table-date">Claim: <?= htmlspecialchars($cancelled_order['claim_number'] ?: 'N/A') ?></div>
                                            <span class="status-badge status-<?= $historyIsCancelled ? 'cancelled' : 'completed' ?>"><?= $historyIsCancelled ? 'Cancelled' : 'Completed' ?></span>
                                        </td>
                                        <td>
                                            <div class="order-table-primary"><?= htmlspecialchars($cancelled_order['customer_name'] ?? 'Guest') ?></div>
                                            <div class="order-table-date"><?= htmlspecialchars($cancelled_order['contact_number'] ?? '—') ?></div>
                                        </td>
                                        <td>
                                            <?php if ($historyIsWalkin): ?>
                                                <div class="order-table-primary">Walk-in</div>
                                            <?php else: ?>
                                                <div class="order-table-primary"><?= htmlspecialchars($cancelled_order['pickup_date'] ?? '—') ?></div>
                                                <div class="order-table-date"><?= htmlspecialchars(staffFormatTime($cancelled_order['pickup_time'] ?? '')) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="order-table-payment <?= $historyIsGcash ? 'payment-gcash' : '' ?>"><?= $historyIsGcash ? 'GCash' : 'Cash' ?></span>
                                            <?php if ($historyGcashRef !== ''): ?>
                                                <div class="order-table-date text-nowrap">Ref <?= htmlspecialchars(staffFormatGcashReference($historyGcashRef)) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end"><span class="order-table-total">₱<?= number_format((float)$cancelled_order['total_amount'], 2) ?></span></td>
                                        <td><div class="history-reason-cell"><?= $historyIsCancelled ? htmlspecialchars($cancelled_order['cancellation_reason'] ?: 'No reason recorded.') : '—' ?></div></td>
                                        <td>
                                            <div class="order-table-primary"><?= !empty($cancelled_order['closed_at']) ? htmlspecialchars(date('M d, Y', strtotime($cancelled_order['closed_at']))) : '—' ?></div>
                                            <div class="order-table-date"><?= !empty($cancelled_order['closed_at']) ? htmlspecialchars(date('h:i A', strtotime($cancelled_order['closed_at']))) : '' ?></div>
                                            <?php if ($historyIsCancelled): ?>
                                                <div class="order-table-date">By <?= htmlspecialchars(staffCancellationActorLabel($cancelled_by_role)) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end history-details-cell">
                                            <button type="button" class="btn btn-view-details" data-cancelled-order-details data-order-id="<?= $cancelled_order_id ?>" data-order-details-modal="detailsModal<?= $cancelled_order_id ?>" aria-label="View details for <?= htmlspecialchars($cancelledIdentifier, ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-eye me-1"></i>View Details</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Cancelled order detail modals are kept outside the table for valid markup. -->
                        <?php foreach ($cancelled_orders as $cancelled_order): ?>
                            <?php
                            $cancelled_order_id = (int)$cancelled_order['id'];
                            $cancelled_items = $order_items[$cancelled_order_id] ?? [];
                            $cancelled_by_role = $cancelled_by_roles[$cancelled_order_id] ?? null;
                            $cancelled_payment_proof = staffAssetPath($cancelled_order['payment_screenshot'] ?? '');
                            $cancelledIdentifier = $cancelled_order['order_number'] ?: 'ORD-' . $cancelled_order_id;
                            $historyIsCancelled = (string)$cancelled_order['status'] === 'cancelled';
                            $historyIsWalkin = ($cancelled_order['order_source'] ?? '') === 'walk_in';
                            $historyIsGcash = strtolower(trim((string)$cancelled_order['payment_method'])) === 'gcash';
                            ?>
                            <div class="modal fade cancelled-order-details-modal" id="detailsModal<?= $cancelled_order_id ?>" data-order-id="<?= $cancelled_order_id ?>" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
                                    <div class="modal-header"><div><h5 class="modal-title fw-bold"><?= $historyIsCancelled ? 'Cancelled' : 'Completed' ?> Order Details</h5><div class="small text-muted"><?= htmlspecialchars($cancelledIdentifier) ?></div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                                    <div class="modal-body">
                                        <div class="row g-3 mb-3">
                                            <div class="col-sm-6"><div class="info-label">Claim Number</div><div class="info-value"><?= htmlspecialchars($cancelled_order['claim_number'] ?: 'N/A') ?></div></div>
                                            <div class="col-sm-6"><div class="info-label">Customer</div><div class="info-value"><?= htmlspecialchars($cancelled_order['customer_name'] ?? 'Guest') ?></div><div class="small text-muted"><?= htmlspecialchars($cancelled_order['contact_number'] ?? '—') ?></div></div>
                                            <?php if ($historyIsWalkin): ?>
                                                <div class="col-sm-6"><div class="info-label">Order Type</div><div class="info-value">Walk-in</div></div>
                                            <?php else: ?>
                                                <div class="col-sm-6"><div class="info-label">Pick-up</div><div class="info-value"><?= htmlspecialchars($cancelled_order['pickup_date'] ?? '—') ?> @ <?= htmlspecialchars(staffFormatTime($cancelled_order['pickup_time'] ?? '')) ?></div></div>
                                            <?php endif; ?>
                                            <div class="col-sm-6"><div class="info-label">Payment Method</div><div class="info-value"><?= $historyIsGcash ? 'GCash' : 'Cash' ?></div><?php if (!empty($gcash_references[(int)$cancelled_order['id']])): ?><div class="small text-muted">GCash Ref No. <?= htmlspecialchars(staffFormatGcashReference($gcash_references[(int)$cancelled_order['id']])) ?></div><?php endif; ?></div>
                                            <div class="col-sm-6"><div class="info-label"><?= $historyIsCancelled ? 'Cancelled At' : 'Completed At' ?></div><div class="info-value"><?= !empty($cancelled_order['closed_at']) ? htmlspecialchars(date('M d, Y h:i A', strtotime($cancelled_order['closed_at']))) : '—' ?></div></div>
                                            <?php if ($historyIsCancelled): ?>
                                                <div class="col-sm-6"><div class="info-label">Cancelled By</div><div class="info-value text-danger"><?= htmlspecialchars(staffCancellationActorLabel($cancelled_by_role)) ?></div></div>
                                                <div class="col-12"><div class="info-label">Cancellation Reason</div><div class="info-value text-danger"><?= htmlspecialchars($cancelled_order['cancellation_reason'] ?: 'No reason recorded.') ?></div></div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="details-section-title mb-2">Ordered Items</div>
                                        <?php if ($cancelled_items): ?>
                                            <div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Item</th><th>Customization</th><th class="text-center">Qty</th><th class="text-end">Subtotal</th></tr></thead><tbody>
                                            <?php foreach ($cancelled_items as $item): ?>
                                                <?php $addonDetails = staffGetAddons($item['addons'] ?? null); ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($item['product_name'] ?? 'Item') ?><div class="small text-muted">₱<?= number_format((float)($item['unit_price'] ?? 0), 2) ?> each</div></td>
                                                    <td class="small text-muted"><?= htmlspecialchars(trim(($item['size'] ?? '') . (!empty($item['sugar_level']) ? ' · ' . $item['sugar_level'] : '') . ($addonDetails ? ' · ' . implode(', ', array_column($addonDetails, 'name')) : '')) ?: '—') ?></td>
                                                    <td class="text-center"><?= (int)($item['quantity'] ?? 0) ?></td>
                                                    <td class="text-end">₱<?= number_format((float)($item['subtotal'] ?? $item['total_price'] ?? 0), 2) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody></table></div>
                                        <?php else: ?><div class="small text-muted">No order items were found for this order.</div><?php endif; ?>
                                        <div class="d-flex justify-content-between border-top pt-3 mt-3"><strong>Order Total</strong><strong>₱<?= number_format((float)$cancelled_order['total_amount'], 2) ?></strong></div>
                                        <?php if ($cancelled_payment_proof && strtolower(trim((string)$cancelled_order['payment_method'])) === 'gcash'): ?>
                                            <div class="order-modal-proof-preview mt-3">
                                                <div class="order-modal-proof-preview-heading">
                                                    <span><i class="bi bi-image me-1" aria-hidden="true"></i>GCash payment proof</span>
                                                </div>
                                                <img
                                                    src="<?= htmlspecialchars($cancelled_payment_proof, ENT_QUOTES, 'UTF-8') ?>"
                                                    alt="GCash payment proof for <?= htmlspecialchars($cancelledIdentifier, ENT_QUOTES, 'UTF-8') ?>"
                                                    class="order-modal-proof-image"
                                                    decoding="async"
                                                >
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="modal-footer"><button type="button" class="btn btn-outline-dark" data-bs-dismiss="modal">Close</button></div>
                                </div></div>
                            </div>
                        <?php endforeach; ?>

                        <?php if ($cancelled_total_pages > 1): ?>
                            <div class="pagination-wrap cancelled-pagination-wrap cancelled-pagination">
                                <nav aria-label="Cancelled order pagination">
                                    <ul class="pagination mb-0">
                                        <li class="page-item <?= $cancelled_page <= 1 ? 'disabled' : '' ?>">
                                            <a
                                                class="page-link"
                                                href="<?= htmlspecialchars(staffOrdersUrl([
                                                    'cancelled_page' => max(1, $cancelled_page - 1),
                                                    'cancelled_open' => '1'
                                                ])) ?>"
                                                data-cancelled-pagination
                                                aria-label="Previous cancelled orders page"
                                            >Previous</a>
                                        </li>

                                        <?php
                                        /* Keep page 1 visible and show the current page window,
                                           matching Active Orders and GCash Refunds. */
                                        $cancelledPaginationPages = [1];
                                        for (
                                            $cancelled_p = max(2, $cancelled_page - 2);
                                            $cancelled_p <= min($cancelled_total_pages - 1, $cancelled_page + 2);
                                            $cancelled_p++
                                        ) {
                                            $cancelledPaginationPages[] = $cancelled_p;
                                        }
                                        $cancelledPaginationPages[] = $cancelled_total_pages;
                                        $cancelledPaginationPages = array_values(array_unique($cancelledPaginationPages));
                                        $cancelledPreviousPage = 0;
                                        foreach ($cancelledPaginationPages as $cancelled_p):
                                            if (
                                                $cancelledPreviousPage > 0
                                                && $cancelled_p > $cancelledPreviousPage + 1
                                            ):
                                        ?>
                                            <li class="page-item disabled" aria-hidden="true">
                                                <span class="page-link">...</span>
                                            </li>
                                        <?php endif; ?>

                                            <li class="page-item <?= $cancelled_p === $cancelled_page ? 'active' : '' ?>">
                                                <a
                                                    class="page-link"
                                                    href="<?= htmlspecialchars(staffOrdersUrl([
                                                        'cancelled_page' => $cancelled_p,
                                                        'cancelled_open' => '1'
                                                    ])) ?>"
                                                    data-cancelled-pagination
                                                    <?= $cancelled_p === $cancelled_page ? 'aria-current="page"' : '' ?>
                                                ><?= $cancelled_p ?></a>
                                            </li>
                                        <?php
                                            $cancelledPreviousPage = $cancelled_p;
                                        endforeach;
                                        ?>

                                        <li class="page-item <?= $cancelled_page >= $cancelled_total_pages ? 'disabled' : '' ?>">
                                            <a
                                                class="page-link"
                                                href="<?= htmlspecialchars(staffOrdersUrl([
                                                    'cancelled_page' => min($cancelled_total_pages, $cancelled_page + 1),
                                                    'cancelled_open' => '1'
                                                ])) ?>"
                                                data-cancelled-pagination
                                                aria-label="Next cancelled orders page"
                                            >Next</a>
                                        </li>
                                    </ul>
                                </nav>
                            </div>
                            <div class="small text-muted text-center mt-2">
                                Page <?= (int)$cancelled_page ?> of <?= (int)$cancelled_total_pages ?> ·
                                <?= (int)$cancelled_total_orders ?> order(s) · 5 per page
                            </div>
                        <?php endif; ?>

                    <?php endif; ?>

                </div>

            </details>

    </main>

</div>

<!-- =========================================================
     SHARED STAFF ORDER MODALS
     These stay outside the AJAX-replaced Active Orders section.
========================================================= -->

<!-- SHARED CANCEL ORDER MODAL -->
<div class="modal fade" id="staffCancelOrderModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">

            <form
                method="POST"
                action="index.php"
                id="staffCancelOrderForm"
            >
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($staff_csrf_token, ENT_QUOTES, 'UTF-8') ?>">

                <div class="modal-header">
                    <h5
                        class="modal-title fw-bold"
                        id="staffCancelOrderModalTitle"
                        style="color:#4A3525;"
                    >
                        Cancel Order
                    </h5>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close"
                    ></button>
                </div>

                <div class="modal-body">

                    <p class="small text-muted mb-3">
                        <span id="staffCancelOrderActionText">You are cancelling</span>
                        <strong id="staffCancelOrderNumber">
                            this order
                        </strong>.
                        <span id="staffCancelOrderInstruction">Please select a cancellation reason.</span>
                    </p>
                    <div id="staffNoShowRefundNotice" class="alert alert-warning py-2 small mb-3" role="note" hidden>
                        This order has already been prepared. Under the No-show policy, it will be closed without a refund.
                    </div>

                    <input
                        type="hidden"
                        name="cancel_order"
                        value="1"
                    >

                    <input
                        type="hidden"
                        name="order_id"
                        id="staffCancelOrderId"
                        value=""
                    >

                    <input
                        type="hidden"
                        name="status_filter"
                        id="staffCancelStatusFilter"
                        value="<?= htmlspecialchars($selected_status) ?>"
                    >

                    <input
                        type="hidden"
                        name="q"
                        id="staffCancelSearch"
                        value="<?= htmlspecialchars($search) ?>"
                    >

                    <input
                        type="hidden"
                        name="page"
                        id="staffCancelPage"
                        value="<?= (int)$page ?>"
                    >

                    <input type="hidden" id="staffCancelOrderStatus" value="">
                    <input type="hidden" id="staffCancelOrderPaymentMethod" value="">

                    <div class="mb-3">

                        <label
                            class="form-label small fw-semibold"
                            for="staffCancelReasonToggle"
                        >
                            Cancellation Reason
                        </label>

                        <input
                            type="hidden"
                            name="cancellation_reason"
                            id="staffCancelReason"
                            value=""
                        >

                        <div
                            class="staff-cancel-reason-dropdown"
                            id="staffCancelReasonDropdown"
                        >
                            <button
                                type="button"
                                class="form-select staff-cancel-reason-toggle"
                                id="staffCancelReasonToggle"
                                aria-haspopup="listbox"
                                aria-expanded="false"
                                aria-controls="staffCancelReasonMenu"
                            >
                                <span id="staffCancelReasonLabel">Select a cancellation reason</span>
                                <i class="bi bi-chevron-down" aria-hidden="true"></i>
                            </button>

                            <div
                                class="staff-cancel-reason-menu"
                                id="staffCancelReasonMenu"
                                role="listbox"
                                aria-labelledby="staffCancelReasonToggle"
                                hidden
                            >
                                <div class="staff-cancel-reason-group-label" data-standard-reason-group>Store and fulfillment reasons</div>
                                <button type="button" class="staff-cancel-reason-option" role="option" aria-selected="false" data-value="Item unavailable / out of stock" data-standard-reason>Item unavailable / out of stock</button>
                                <button type="button" class="staff-cancel-reason-option" role="option" aria-selected="false" data-value="Store unable to fulfill at selected pickup time" data-standard-reason>Store unable to fulfill at selected pickup time</button>
                                <button type="button" class="staff-cancel-reason-option" role="option" aria-selected="false" data-value="Store closure or operational issue" data-standard-reason>Store closure or operational issue</button>

                                <div class="staff-cancel-reason-group-label" data-standard-reason-group>Payment-related reasons</div>
                                <button type="button" class="staff-cancel-reason-option" role="option" aria-selected="false" data-value="Payment could not be verified" data-standard-reason>Payment could not be verified</button>
                                <button type="button" class="staff-cancel-reason-option" role="option" aria-selected="false" data-value="GCash proof is missing, unreadable, or invalid" data-standard-reason>GCash proof is missing, unreadable, or invalid</button>
                                <button type="button" class="staff-cancel-reason-option" role="option" aria-selected="false" data-value="Payment amount does not match order total" data-standard-reason>Payment amount does not match order total</button>

                                <div class="staff-cancel-reason-group-label" data-standard-reason-group>System and other reasons</div>
                                <button type="button" class="staff-cancel-reason-option" role="option" aria-selected="false" data-value="Pricing or system error on the order" data-standard-reason>Pricing or system error on the order</button>
                                <button type="button" class="staff-cancel-reason-option" role="option" aria-selected="false" data-value="Invalid order or suspected fraudulent activity" data-standard-reason>Invalid order or suspected fraudulent activity</button>
                                <button type="button" class="staff-cancel-reason-option" role="option" aria-selected="false" data-value="__other__" data-standard-reason>Other — please specify</button>

                                <div class="staff-cancel-reason-group-label" data-no-show-reason-group hidden>Pick-up outcome</div>
                                <button type="button" class="staff-cancel-reason-option" role="option" aria-selected="false" data-value="Customer did not collect order (No-show)" data-no-show-reason hidden>Customer did not collect order (No-show)</button>
                            </div>
                        </div>

                    </div>

                    <div
                        id="staffOtherCancellationReasonWrap"
                        class="mb-2"
                        style="display:none;"
                    >

                        <label
                            class="form-label small fw-semibold"
                            for="staffOtherCancellationReason"
                        >
                            Other Cancellation Reason
                        </label>

                        <textarea
                            name="other_cancellation_reason"
                            id="staffOtherCancellationReason"
                            class="form-control"
                            rows="3"
                            maxlength="255"
                            placeholder="Please specify the reason..."
                        ></textarea>

                        <div class="form-text">
                            Please provide the specific reason for cancelling this order.
                        </div>

                    </div>

                </div>

                <div class="modal-footer">

                    <button
                        type="button"
                        class="btn btn-outline-secondary"
                        data-bs-dismiss="modal"
                    >
                        Keep Order
                    </button>

                    <button
                        type="submit"
                        class="btn btn-danger"
                        id="staffConfirmCancellationButton"
                    >
                        <i class="bi bi-x-circle me-1"></i>
                        Confirm Cancellation
                    </button>

                </div>

            </form>

        </div>
    </div>
</div>

<!-- SHARED GCASH PAYMENT REVIEW MODAL -->
<div class="modal fade" id="staffGcashReviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg gcash-review-dialog">
        <div class="modal-content">
            <form method="POST" action="index.php" id="staffGcashReviewForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($staff_csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                <div class="modal-header details-header">
                    <div>
                        <h5 class="modal-title fw-bold mb-1" style="color:#4A3525;">Review GCash Payment</h5>
                        <div class="small text-muted" id="staffGcashReviewReference">Order details</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-3 p-md-4">
                    <input type="hidden" name="order_id" id="staffGcashReviewOrderId" value="">
                    <input type="hidden" name="status" value="order_queue">
                    <input type="hidden" name="status_filter" id="staffGcashReviewStatusFilter" value="pending_verification">
                    <input type="hidden" name="q" id="staffGcashReviewSearch" value="">
                    <input type="hidden" name="page" id="staffGcashReviewPage" value="1">

                    <div class="gcash-review-summary mb-3">
                        <div>
                            <div class="info-label">Order Total</div>
                            <div class="gcash-review-total" id="staffGcashReviewTotal">₱0.00</div>
                        </div>
                        <span class="status-badge status-pending_verification">Pending Verification</span>
                    </div>

                    <div class="alert alert-info py-2 small mb-3" role="note">
                        Compare the screenshot with the actual GCash transaction history or account received by the store. A screenshot by itself does not prove that the money was received.
                    </div>

                    <div class="details-section-title mb-2">Uploaded GCash Payment Screenshot</div>
                    <div class="gcash-review-proof-frame">
                        <img
                            id="staffGcashReviewImage"
                            class="gcash-review-image"
                            src=""
                            alt="Uploaded GCash payment screenshot"
                            hidden
                        >
                        <div id="staffGcashReviewImageMessage" class="small text-muted text-center" aria-live="polite">
                            Loading payment screenshot...
                        </div>
                    </div>

                    <p class="small text-muted mt-2 mb-0">
                        Verify only after confirming that the transaction was actually received in the store's GCash account and that the amount matches the order total.
                    </p>

                </div>

                <div class="modal-footer d-flex flex-column flex-sm-row justify-content-between gap-2">
                    <button
                        type="button"
                        class="btn btn-outline-danger me-sm-auto"
                        id="staffGcashRejectPaymentButton"
                        data-order-id=""
                        data-order-number=""
                    >
                        <i class="bi bi-x-circle me-1"></i>
                        Reject Payment
                    </button>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        Close
                    </button>
                    <button
                        type="submit"
                        name="update_status"
                        value="1"
                        class="btn btn-success"
                        id="staffGcashVerifyButton"
                        disabled
                    >
                        <i class="bi bi-shield-check me-1"></i>
                        Verify Payment &amp; Move to Order Queue
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- SHARED PAYMENT PROOF MODAL -->
<div class="modal fade" id="staffPaymentProofModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header details-header">
                <h5 class="modal-title fw-bold" style="color:#4A3525;">GCash Payment Proof</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <img
                    id="staffPaymentProofImage"
                    src=""
                    alt="GCash payment proof"
                    class="payment-proof-image"
                >
            </div>
        </div>
    </div>
</div>

<!-- =========================================================
     CSRF TOKEN FOR AJAX REQUESTS
     Adds the X-CSRF-Token header to every same-origin POST
     sent with fetch(). The forms also carry a hidden field.
========================================================= -->
<script>
(function () {
    const csrfToken = <?= json_encode($staff_csrf_token) ?>;
    const nativeFetch = window.fetch;

    if (typeof nativeFetch !== 'function') {
        return;
    }

    window.fetch = function (input, init) {
        try {
            const isRequest = input instanceof Request;
            const url = new URL(isRequest ? input.url : String(input), window.location.href);
            const method = String((init && init.method) || (isRequest ? input.method : 'GET')).toUpperCase();

            if (url.origin === window.location.origin && method !== 'GET' && method !== 'HEAD') {
                init = Object.assign({}, init || {});
                const headers = new Headers(init.headers || (isRequest ? input.headers : undefined));
                headers.set('X-CSRF-Token', csrfToken);
                init.headers = headers;
            }
        } catch (error) {
            /* Never block a request because of this helper. */
        }

        return nativeFetch.call(window, input, init);
    };
})();
</script>

<!-- =========================================================
     RELIABLE ORDER DETAIL / SHARED MODAL HANDLERS
========================================================= -->
<script>
document.addEventListener('DOMContentLoaded', function () {

    const staffOrdersContent = document.querySelector('.staff-content');

    function cleanupStaleModalState() {
        document.querySelectorAll('.modal-backdrop').forEach(function (backdrop) {
            backdrop.remove();
        });

        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('padding-right');
        document.body.style.removeProperty('overflow');
    }

    function showModalCleanly(modalElement) {
        if (!modalElement) {
            return;
        }

        /*
         * Keep the existing Bootstrap behavior for the other shared
         * modals on this page. View Details has its own fallback below
         * so it still works even if Bootstrap's JS is unavailable.
         */
        if (!window.bootstrap || !bootstrap.Modal) {
            openLocaliteaModal(modalElement);
            return;
        }

        const currentOpenModal = document.querySelector('.modal.show');

        if (currentOpenModal && currentOpenModal !== modalElement) {
            const currentInstance =
                bootstrap.Modal.getInstance(currentOpenModal) ||
                bootstrap.Modal.getOrCreateInstance(currentOpenModal);

            let opened = false;

            const openTarget = function () {
                if (opened) {
                    return;
                }
                opened = true;

                cleanupStaleModalState();

                const targetInstance =
                    bootstrap.Modal.getInstance(modalElement) ||
                    bootstrap.Modal.getOrCreateInstance(modalElement, {
                        backdrop: true,
                        keyboard: true,
                        focus: true
                    });

                targetInstance.show();
            };

            currentOpenModal.addEventListener(
                'hidden.bs.modal',
                openTarget,
                { once: true }
            );

            currentInstance.hide();
            window.setTimeout(openTarget, 400);
            return;
        }

        cleanupStaleModalState();

        const targetInstance =
            bootstrap.Modal.getInstance(modalElement) ||
            bootstrap.Modal.getOrCreateInstance(modalElement, {
                backdrop: true,
                keyboard: true,
                focus: true
            });

        targetInstance.show();
    }

    function openLocaliteaModal(modalElement) {
        if (!modalElement) {
            return;
        }

        /* Remove any stale local/Bootstrap backdrop first. */
        document
            .querySelectorAll('[data-localitea-modal-backdrop], .modal-backdrop')
            .forEach(function (backdrop) {
                backdrop.remove();
            });

        document
            .querySelectorAll('.modal.show')
            .forEach(function (openModal) {
                if (openModal !== modalElement) {
                    openModal.classList.remove('show');
                    openModal.style.display = 'none';
                    openModal.setAttribute('aria-hidden', 'true');
                    openModal.removeAttribute('aria-modal');
                }
            });

        modalElement.classList.add('show');
        modalElement.style.display = 'block';
        modalElement.removeAttribute('aria-hidden');
        modalElement.setAttribute('aria-modal', 'true');
        modalElement.setAttribute('role', 'dialog');

        document.body.classList.add('modal-open');
        document.body.style.overflow = 'hidden';

        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop fade show';
        backdrop.setAttribute('data-localitea-modal-backdrop', 'true');
        backdrop.addEventListener('click', function () {
            closeLocaliteaModal(modalElement);
        });
        document.body.appendChild(backdrop);
    }

    function closeLocaliteaModal(modalElement) {
        if (!modalElement) {
            return;
        }

        modalElement.classList.remove('show');
        modalElement.style.display = 'none';
        modalElement.setAttribute('aria-hidden', 'true');
        modalElement.removeAttribute('aria-modal');

        document
            .querySelectorAll('[data-localitea-modal-backdrop]')
            .forEach(function (backdrop) {
                backdrop.remove();
            });

        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
    }

    /*
     * Expose the fallback close helper for the separate AJAX workflow script
     * below. Function declarations inside this DOMContentLoaded callback are
     * otherwise private to this callback and cannot be called by other script
     * blocks.
     */
    window.closeLocaliteaModal = closeLocaliteaModal;

    /*
     * Cancelled history modals are rendered inside a <details> section with
     * overflow:hidden. Move only the selected modal to <body> before opening
     * so it is not clipped. Capture phase keeps this reliable after the
     * Cancelled Orders section is replaced through AJAX pagination/search.
     */
    document.addEventListener('click', function (event) {
        const targetElement = event.target instanceof Element ? event.target : null;
        const detailsButton = targetElement
            ? targetElement.closest('.cancelled-history-table [data-cancelled-order-details]')
            : null;

        if (!detailsButton) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();

        const orderId = detailsButton.getAttribute('data-order-id') || '';
        const modalId = detailsButton.getAttribute('data-order-details-modal') || '';
        const detailsModal = modalId ? document.getElementById(modalId) : null;

        if (
            !detailsModal
            || detailsModal.getAttribute('data-order-id') !== orderId
        ) {
            console.error('Cancelled Order Details modal does not match the selected order:', {orderId, modalId});
            return;
        }

        if (detailsModal.parentElement !== document.body) {
            detailsModal.setAttribute('data-cancelled-order-modal-detached', 'true');
            document.body.appendChild(detailsModal);
        }

        showModalCleanly(detailsModal);
    }, true);

    /* Fallback close handling for detached Cancelled Order modals when Bootstrap JS is unavailable. */
    document.addEventListener('click', function (event) {
        const targetElement = event.target instanceof Element ? event.target : null;
        const dismissButton = targetElement
            ? targetElement.closest('.cancelled-order-details-modal [data-bs-dismiss="modal"]')
            : null;

        if (!dismissButton || (window.bootstrap && bootstrap.Modal)) {
            return;
        }

        const modalElement = dismissButton.closest('.cancelled-order-details-modal');
        if (!modalElement || !modalElement.classList.contains('show')) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();
        closeLocaliteaModal(modalElement);
    }, true);

    /* =====================================================
       PENDING REFUND MODALS
       These modals are rendered inside a <details> section that has
       overflow:hidden and is refreshed through AJAX. Move the selected
       modal to <body> before opening it so the section cannot clip it.
       Handle the clicks ourselves so the buttons also work when Bootstrap's
       data API is unavailable or the refund section has been AJAX-replaced.
    ===================================================== */
    function openPendingRefundModal(modalElement) {
        if (!modalElement) {
            return;
        }

        if (modalElement.parentElement !== document.body) {
            modalElement.setAttribute('data-pending-refund-detached', 'true');
            document.body.appendChild(modalElement);
        }

        showModalCleanly(modalElement);
    }

    function closePendingRefundModal(modalElement) {
        if (!modalElement) {
            return;
        }

        if (window.bootstrap && window.bootstrap.Modal) {
            const instance =
                bootstrap.Modal.getInstance(modalElement) ||
                bootstrap.Modal.getOrCreateInstance(modalElement, {
                    backdrop: true,
                    keyboard: true,
                    focus: true
                });

            instance.hide();

            /* Safety net for stale Bootstrap instances/backdrops. */
            window.setTimeout(function () {
                if (modalElement.classList.contains('show')) {
                    closeLocaliteaModal(modalElement);
                }

                if (!document.querySelector('.modal.show')) {
                    cleanupStaleModalState();
                }
            }, 500);
        } else {
            closeLocaliteaModal(modalElement);
        }
    }

    /* Capture phase prevents a second Bootstrap data-api handler from
       opening the same modal a second time. */
    document.addEventListener('click', function (event) {
        const targetElement = event.target instanceof Element
            ? event.target
            : null;
        const openButton = targetElement
            ? targetElement.closest('.pending-refund-btn[data-bs-toggle="modal"][data-bs-target]')
            : null;

        if (!openButton) {
            return;
        }

        const modalSelector = openButton.getAttribute('data-bs-target') || '';
        const modalElement = modalSelector.startsWith('#')
            ? document.getElementById(modalSelector.slice(1))
            : null;

        if (!modalElement) {
            console.error('Pending Refund modal not found:', modalSelector);
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();

        openPendingRefundModal(modalElement);
    }, true);

    /* Explicit close behavior for pending-refund modals, including fallback
       mode when Bootstrap JavaScript is missing or has a stale instance. */
    document.addEventListener('click', function (event) {
        const targetElement = event.target instanceof Element
            ? event.target
            : null;
        const dismissButton = targetElement
            ? targetElement.closest('.pending-refund-modal [data-bs-dismiss="modal"]')
            : null;

        if (!dismissButton) {
            return;
        }

        const modalElement = dismissButton.closest('.pending-refund-modal');
        if (!modalElement) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();

        closePendingRefundModal(modalElement);
    }, true);

    /*
     * Make clicking outside an Order Details dialog close it reliably.
     * This explicit handler also covers cases where a previously created
     * Bootstrap modal instance retained a static-backdrop configuration.
     * Clicks inside .modal-dialog are left untouched.
     */
    document.addEventListener('click', function (event) {
        const activeOrderModal = document.querySelector(
            '.modal.show[id^="orderDetailsModal"]'
        );

        if (!activeOrderModal) {
            return;
        }

        const target = event.target;
        const clickedModalSurface = target === activeOrderModal;
        const clickedBackdrop = target instanceof Element &&
            target.classList.contains('modal-backdrop');

        if (!clickedModalSurface && !clickedBackdrop) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();

        if (window.bootstrap && bootstrap.Modal) {
            const instance =
                bootstrap.Modal.getInstance(activeOrderModal) ||
                bootstrap.Modal.getOrCreateInstance(activeOrderModal, {
                    backdrop: true,
                    keyboard: true,
                    focus: true
                });
            instance.hide();
        } else {
            closeLocaliteaModal(activeOrderModal);
        }
    }, true);

    /* =====================================================
       GCASH REVIEW-FIRST FLOW
       The screenshot must load before Verify Payment is enabled.
       Server-side PHP remains responsible for validating the payment.
    ===================================================== */
    const gcashReviewModal = document.getElementById('staffGcashReviewModal');
    const gcashReviewForm = document.getElementById('staffGcashReviewForm');
    const gcashReviewImage = document.getElementById('staffGcashReviewImage');
    const gcashReviewImageMessage = document.getElementById('staffGcashReviewImageMessage');
    const gcashVerifyButton = document.getElementById('staffGcashVerifyButton');
    const gcashVerifyButtonDefaultHTML = gcashVerifyButton ? gcashVerifyButton.innerHTML : '';
    const gcashRejectButton = document.getElementById('staffGcashRejectPaymentButton');

    let gcashReviewImageLoaded = false;

    function syncGcashReviewButton() {
        if (!gcashVerifyButton) {
            return;
        }

        /* Enable verification once the uploaded proof is visibly loaded. */
        gcashVerifyButton.disabled = !gcashReviewImageLoaded;
    }

    function resetGcashVerifyAction() {
        if (gcashReviewForm) {
            delete gcashReviewForm.dataset.processing;
        }

        if (gcashVerifyButton) {
            gcashVerifyButton.disabled = true;
            gcashVerifyButton.innerHTML = gcashVerifyButtonDefaultHTML;
            gcashVerifyButton.removeAttribute('aria-busy');
        }
    }

    /* Make it callable from the AJAX workflow script. */
    window.resetGcashVerifyAction = resetGcashVerifyAction;

    function resetGcashReviewModal() {
        gcashReviewImageLoaded = false;

        if (gcashReviewImage) {
            gcashReviewImage.onload = null;
            gcashReviewImage.onerror = null;
            gcashReviewImage.removeAttribute('src');
            gcashReviewImage.hidden = true;
        }

        if (gcashReviewImageMessage) {
            gcashReviewImageMessage.hidden = false;
            gcashReviewImageMessage.textContent = 'Loading payment screenshot...';
        }

        resetGcashVerifyAction();
        syncGcashReviewButton();
    }

    function openStaffGcashReview(button) {
        if (!gcashReviewModal || !gcashReviewForm || !gcashReviewImage) {
            console.error('GCash review modal or form is missing.');
            return;
        }

        resetGcashReviewModal();

        const orderId = button.getAttribute('data-order-id') || '';
        const orderNumber = button.getAttribute('data-order-number') || 'Order';
        const claimNumber = button.getAttribute('data-claim-number') || 'N/A';
        const totalAmount = Number(button.getAttribute('data-total-amount') || 0);
        const proofSrc = button.getAttribute('data-proof-src') || '';

        document.getElementById('staffGcashReviewOrderId').value = orderId;
        document.getElementById('staffGcashReviewStatusFilter').value =
            button.getAttribute('data-status-filter') || 'pending_verification';
        document.getElementById('staffGcashReviewSearch').value =
            button.getAttribute('data-search') || '';
        document.getElementById('staffGcashReviewPage').value =
            button.getAttribute('data-page') || '1';

        document.getElementById('staffGcashReviewReference').textContent =
            orderNumber + ' · Claim ' + claimNumber;
        document.getElementById('staffGcashReviewTotal').textContent =
            '₱' + totalAmount.toLocaleString('en-PH', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });

        if (gcashRejectButton) {
            gcashRejectButton.setAttribute('data-order-id', orderId);
            gcashRejectButton.setAttribute('data-order-number', orderNumber);
            gcashRejectButton.setAttribute(
                'data-status-filter',
                button.getAttribute('data-status-filter') || 'pending_verification'
            );
            gcashRejectButton.setAttribute(
                'data-search',
                button.getAttribute('data-search') || ''
            );
            gcashRejectButton.setAttribute(
                'data-page',
                button.getAttribute('data-page') || '1'
            );
        }

        if (!proofSrc) {
            if (gcashReviewImageMessage) {
                gcashReviewImageMessage.textContent =
                    'No GCash screenshot is attached to this order.';
            }
        } else {
            gcashReviewImage.onload = function () {
                if (gcashReviewImage.naturalWidth > 0) {
                    gcashReviewImageLoaded = true;
                    gcashReviewImage.hidden = false;

                    if (gcashReviewImageMessage) {
                        gcashReviewImageMessage.hidden = true;
                    }

                    syncGcashReviewButton();
                }
            };

            gcashReviewImage.onerror = function () {
                gcashReviewImageLoaded = false;
                gcashReviewImage.hidden = true;

                if (gcashReviewImageMessage) {
                    gcashReviewImageMessage.hidden = false;
                    gcashReviewImageMessage.textContent =
                        'The screenshot could not be loaded. Payment verification is disabled until the proof can be viewed.';
                }

                syncGcashReviewButton();
            };

            gcashReviewImage.src = proofSrc;

            /* A cached screenshot may already be complete without a new load event. */
            if (gcashReviewImage.complete && gcashReviewImage.naturalWidth > 0) {
                gcashReviewImage.onload();
            }
        }

        showModalCleanly(gcashReviewModal);
    }

    function closeStaffGcashReviewModal() {
        if (!gcashReviewModal) {
            return;
        }

        if (window.bootstrap && bootstrap.Modal) {
            const instance =
                bootstrap.Modal.getInstance(gcashReviewModal) ||
                bootstrap.Modal.getOrCreateInstance(gcashReviewModal, {
                    backdrop: true,
                    keyboard: true,
                    focus: true
                });

            instance.hide();

            /* Fallback for stale Bootstrap instances or interrupted transitions. */
            window.setTimeout(function () {
                if (gcashReviewModal.classList.contains('show')) {
                    closeLocaliteaModal(gcashReviewModal);
                    cleanupStaleModalState();
                    resetGcashReviewModal();
                }
            }, 450);
        } else {
            closeLocaliteaModal(gcashReviewModal);
            cleanupStaleModalState();
            resetGcashReviewModal();
        }
    }

    if (gcashReviewModal) {
        gcashReviewModal.addEventListener('hidden.bs.modal', function () {
            resetGcashReviewModal();
            cleanupStaleModalState();
        });
    }

    /*
     * This shared modal is outside .staff-content, so the existing
     * delegated modal-close listener cannot receive its close button.
     * Handle close button, backdrop click, and Escape explicitly.
     */
    document.addEventListener('click', function (event) {
        const target = event.target instanceof Element ? event.target : null;
        const dismissButton = target
            ? target.closest('#staffGcashReviewModal [data-bs-dismiss="modal"]')
            : null;
        const clickedModalBackdrop =
            gcashReviewModal && event.target === gcashReviewModal;

        if (!dismissButton && !clickedModalBackdrop) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();
        closeStaffGcashReviewModal();
    }, true);

    document.addEventListener('keydown', function (event) {
        if (
            event.key === 'Escape'
            && gcashReviewModal
            && gcashReviewModal.classList.contains('show')
        ) {
            event.preventDefault();
            event.stopPropagation();
            event.stopImmediatePropagation();
            closeStaffGcashReviewModal();
        }
    }, true);

    document.addEventListener('click', function (event) {
        const reviewButton = event.target.closest('[data-staff-gcash-review]');

        if (reviewButton) {
            event.preventDefault();
            event.stopPropagation();
            openStaffGcashReview(reviewButton);
            return;
        }

        const rejectButton = event.target.closest('#staffGcashRejectPaymentButton');

        if (rejectButton) {
            event.preventDefault();
            event.stopPropagation();

            const cancelModal = document.getElementById('staffCancelOrderModal');
            const orderIdInput = document.getElementById('staffCancelOrderId');
            const orderNumberText = document.getElementById('staffCancelOrderNumber');
            const reasonInput = document.getElementById('staffCancelReason');
            const otherReasonWrap = document.getElementById('staffOtherCancellationReasonWrap');
            const otherReasonInput = document.getElementById('staffOtherCancellationReason');

            if (!cancelModal || !orderIdInput || !reasonInput) {
                console.error('The shared cancellation modal is unavailable.');
                return;
            }

            orderIdInput.value = rejectButton.getAttribute('data-order-id') || '';
            if (orderNumberText) {
                orderNumberText.textContent =
                    rejectButton.getAttribute('data-order-number') || 'this order';
            }

            document.getElementById('staffCancelStatusFilter').value =
                rejectButton.getAttribute('data-status-filter') || 'pending_verification';
            document.getElementById('staffCancelOrderStatus').value = 'pending_verification';
            document.getElementById('staffCancelOrderPaymentMethod').value = 'gcash';
            document.getElementById('staffCancelSearch').value =
                rejectButton.getAttribute('data-search') || '';
            document.getElementById('staffCancelPage').value =
                rejectButton.getAttribute('data-page') || '1';

            reasonInput.value = 'Payment could not be verified';
            reasonInput.dispatchEvent(new Event('change', {bubbles: true}));

            if (otherReasonInput) {
                otherReasonInput.value = '';
                otherReasonInput.required = false;
            }
            if (otherReasonWrap) {
                otherReasonWrap.style.display = 'none';
            }

            /* Staff must still confirm the cancellation in the next modal. */
            showModalCleanly(cancelModal);
        }
    });

    if (staffOrdersContent) {

        staffOrdersContent.addEventListener('click', function (event) {

            /* =====================================================
               DETAILS / REVIEW PAYMENT
               Both controls open the relevant order modal. Neither control
               changes the order status; status updates use their own forms.
            ===================================================== */
            const detailsButton = event.target.closest(
                '.btn-view-details[data-order-details-modal], .btn-review-payment[data-order-details-modal]'
            );

            if (detailsButton) {
                event.preventDefault();
                event.stopPropagation();

                const modalId =
                    detailsButton.getAttribute('data-order-details-modal') || '';

                const detailsModal =
                    document.getElementById(modalId);

                if (!detailsModal) {
                    console.error(
                        'Order Details modal not found:',
                        modalId
                    );
                    return;
                }

                /*
                 * Open the exact order modal ourselves. The button no longer
                 * uses Bootstrap's data-api, so this remains reliable after
                 * the Active Orders section is replaced through AJAX.
                 */
                showModalCleanly(detailsModal);
                return;
            }

            /* =====================================================
               CANCEL ORDER
               Hide the details modal first, then open the shared
               cancellation modal. Nested Bootstrap modals are avoided.
            ===================================================== */
            const cancelButton = event.target.closest(
                '[data-staff-cancel-order]'
            );

            if (cancelButton) {
                event.preventDefault();
                event.stopPropagation();
                event.stopImmediatePropagation();

                const cancelModal =
                    document.getElementById('staffCancelOrderModal');

                if (!cancelModal) {
                    console.error('Shared cancellation modal not found.');
                    return;
                }

                const orderId =
                    cancelButton.getAttribute('data-order-id') || '';

                const orderNumber =
                    cancelButton.getAttribute('data-order-number') ||
                    'this order';

                const orderIdInput =
                    document.getElementById('staffCancelOrderId');
                const orderNumberText =
                    document.getElementById('staffCancelOrderNumber');
                const reasonInput =
                    document.getElementById('staffCancelReason');
                const orderStatusInput =
                    document.getElementById('staffCancelOrderStatus');
                const orderPaymentMethodInput =
                    document.getElementById('staffCancelOrderPaymentMethod');

                const otherReasonWrap =
                    document.getElementById(
                        'staffOtherCancellationReasonWrap'
                    );

                const otherReasonInput =
                    document.getElementById(
                        'staffOtherCancellationReason'
                    );
                const isNoShowAction =
                    cancelButton.getAttribute('data-no-show-only') === '1';
                const cancelModalTitle =
                    document.getElementById('staffCancelOrderModalTitle');
                const cancelActionText =
                    document.getElementById('staffCancelOrderActionText');
                const cancelInstruction =
                    document.getElementById('staffCancelOrderInstruction');
                const noShowNotice =
                    document.getElementById('staffNoShowRefundNotice');
                const confirmCancellationButton =
                    document.getElementById('staffConfirmCancellationButton');

                if (orderIdInput) orderIdInput.value = orderId;
                if (orderNumberText) {
                    orderNumberText.textContent = orderNumber;
                }
                if (orderStatusInput) {
                    orderStatusInput.value = cancelButton.getAttribute('data-order-status') || '';
                }
                if (orderPaymentMethodInput) {
                    orderPaymentMethodInput.value = cancelButton.getAttribute('data-order-payment-method') || '';
                }

                if (reasonInput) {
                    reasonInput.value = isNoShowAction
                        ? 'Customer did not collect order (No-show)'
                        : '';
                    reasonInput.dispatchEvent(new Event('change', { bubbles: true }));
                }

                if (otherReasonInput) {
                    otherReasonInput.value = '';
                    otherReasonInput.required = false;
                }

                if (otherReasonWrap) {
                    otherReasonWrap.style.display = 'none';
                }

                if (cancelModalTitle) {
                    cancelModalTitle.textContent = isNoShowAction
                        ? 'Mark Order as No-show'
                        : 'Cancel Order';
                }
                if (cancelActionText) {
                    cancelActionText.textContent = isNoShowAction
                        ? 'You are closing'
                        : 'You are cancelling';
                }
                if (cancelInstruction) {
                    cancelInstruction.textContent = isNoShowAction
                        ? 'Confirm that the customer did not collect this prepared order after the pickup window.'
                        : 'Please select a cancellation reason.';
                }
                if (noShowNotice) {
                    noShowNotice.textContent = 'This order can only be marked as No-show 30 minutes after the later of its scheduled pickup time or the time it was marked Ready. Verified GCash payments are not refundable under the No-show policy. Cash-on-pickup orders remain unpaid.';
                    noShowNotice.hidden = !isNoShowAction;
                }
                if (confirmCancellationButton) {
                    confirmCancellationButton.innerHTML = isNoShowAction
                        ? '<i class="bi bi-person-x me-1" aria-hidden="true"></i> Confirm No-show'
                        : '<i class="bi bi-x-circle me-1" aria-hidden="true"></i> Confirm Cancellation';
                }

                showModalCleanly(cancelModal);
                return;
            }

            /* =====================================================
               GCASH PAYMENT PROOF
               Hide the details modal first, then open the shared
               payment-proof modal.
            ===================================================== */
            const proofButton = event.target.closest(
                '[data-staff-payment-proof]'
            );

            if (proofButton) {
                event.preventDefault();
                event.stopPropagation();
                event.stopImmediatePropagation();

                const proofModal =
                    document.getElementById('staffPaymentProofModal');

                const proofImage =
                    document.getElementById('staffPaymentProofImage');

                if (!proofModal || !proofImage) {
                    console.error('Shared payment proof modal not found.');
                    return;
                }

                proofImage.src =
                    proofButton.getAttribute('data-proof-src') || '';

                showModalCleanly(proofModal);
            }
        });
    }

    /*
     * GCash proof has a shared modal outside the main order-content
     * delegation area. Handle its close button directly at document level
     * so it works with both Bootstrap and the Localitea fallback modal.
     */
    document.addEventListener('click', function (event) {
        const dismissButton = event.target.closest(
            '#staffPaymentProofModal [data-bs-dismiss="modal"]'
        );

        if (!dismissButton) {
            return;
        }

        const proofModal = document.getElementById(
            'staffPaymentProofModal'
        );

        if (!proofModal) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        if (window.bootstrap && bootstrap.Modal) {
            const instance =
                bootstrap.Modal.getInstance(proofModal) ||
                bootstrap.Modal.getOrCreateInstance(proofModal);
            instance.hide();
        } else {
            closeLocaliteaModal(proofModal);
        }

        const proofImage = document.getElementById(
            'staffPaymentProofImage'
        );

        if (proofImage) {
            proofImage.removeAttribute('src');
        }
    }, true);

    /*
     * Cancel Order modal close handling.
     * This modal lives outside .staff-content, so the delegated fallback
     * handler below never reaches its X / "Keep Order" buttons. Handle them
     * at document level (capture phase) so they always close the modal,
     * whether it was opened through Bootstrap or the Localitea fallback.
     */
    document.addEventListener('click', function (event) {
        const dismissButton = event.target.closest(
            '#staffCancelOrderModal [data-bs-dismiss="modal"]'
        );

        if (!dismissButton) {
            return;
        }

        const cancelOrderModal = document.getElementById(
            'staffCancelOrderModal'
        );

        if (!cancelOrderModal) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        if (window.bootstrap && bootstrap.Modal) {
            const instance =
                bootstrap.Modal.getInstance(cancelOrderModal) ||
                bootstrap.Modal.getOrCreateInstance(cancelOrderModal);
            instance.hide();

            /* Safety net: force-close if Bootstrap did not finish hiding. */
            window.setTimeout(function () {
                if (cancelOrderModal.classList.contains('show')) {
                    closeLocaliteaModal(cancelOrderModal);
                    cleanupStaleModalState();
                }
            }, 500);
        } else {
            closeLocaliteaModal(cancelOrderModal);
        }
    }, true);

    /*
     * Fallback close handling for the local View Details modal when
     * Bootstrap JS is unavailable. Bootstrap can still handle its own
     * modals normally when it is loaded.
     */
    staffOrdersContent.addEventListener('click', function (event) {
        const dismissButton = event.target.closest('[data-bs-dismiss="modal"]');

        if (!dismissButton) {
            return;
        }

        const modalElement = dismissButton.closest('.modal');

        if (modalElement && modalElement.classList.contains('show') &&
            (!window.bootstrap || !bootstrap.Modal)) {
            event.preventDefault();
            event.stopPropagation();
            closeLocaliteaModal(modalElement);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }

        if (window.bootstrap && bootstrap.Modal) {
            return;
        }

        const openModal = document.querySelector('.modal.show');
        if (openModal) {
            closeLocaliteaModal(openModal);
        }
    });

    /* Shared cancellation modal: keep the reason box clean whenever it closes. */
    const cancelModal = document.getElementById('staffCancelOrderModal');

    if (cancelModal) {
        cancelModal.addEventListener('hidden.bs.modal', function () {
            const reasonInput =
                document.getElementById('staffCancelReason');

            if (reasonInput) {
                reasonInput.value = '';
                reasonInput.dispatchEvent(new Event('change', { bubbles: true }));
            }

            const orderStatusInput = document.getElementById('staffCancelOrderStatus');
            const orderPaymentMethodInput = document.getElementById('staffCancelOrderPaymentMethod');
            if (orderStatusInput) orderStatusInput.value = '';
            if (orderPaymentMethodInput) orderPaymentMethodInput.value = '';

            const cancelModalTitle = document.getElementById('staffCancelOrderModalTitle');
            const cancelActionText = document.getElementById('staffCancelOrderActionText');
            const cancelInstruction = document.getElementById('staffCancelOrderInstruction');
            const noShowNotice = document.getElementById('staffNoShowRefundNotice');
            const confirmCancellationButton = document.getElementById('staffConfirmCancellationButton');

            if (cancelModalTitle) cancelModalTitle.textContent = 'Cancel Order';
            if (cancelActionText) cancelActionText.textContent = 'You are cancelling';
            if (cancelInstruction) cancelInstruction.textContent = 'Please select a cancellation reason.';
            if (noShowNotice) noShowNotice.hidden = true;
            if (confirmCancellationButton) {
                confirmCancellationButton.innerHTML = '<i class="bi bi-x-circle me-1" aria-hidden="true"></i> Confirm Cancellation';
            }

            cleanupStaleModalState();
        });
    }

    /* Shared GCash proof modal cleanup. */
    const paymentProofModal =
        document.getElementById('staffPaymentProofModal');
    const paymentProofImage =
        document.getElementById('staffPaymentProofImage');

    if (paymentProofModal && paymentProofImage) {
        paymentProofModal.addEventListener('hidden.bs.modal', function () {
            paymentProofImage.removeAttribute('src');
            cleanupStaleModalState();
        });
    }
});
</script>

<!-- =========================================================
     SCRIPT 1
     SCROLL POSITION
========================================================= -->
<script>
/* Preserve the Staff's scroll position when searching/filtering orders.
   The page still refreshes normally, but it returns to the previous
   position instead of jumping to the top. */
document.addEventListener('DOMContentLoaded', function () {

    /*
     * When an Staff opened this page from a notification, the target
     * order must take priority over the normal saved-scroll restoration.
     * Otherwise the saved position can immediately move the page away
     * from the order that was just opened.
     */
    const targetOrderFromUrl =
        Number(new URLSearchParams(window.location.search).get('order_id') || 0);

    const savedScrollY =
        sessionStorage.getItem('staffOrdersScrollY');

    if (savedScrollY !== null && !targetOrderFromUrl) {

        sessionStorage.removeItem('staffOrdersScrollY');

        requestAnimationFrame(function () {

            window.scrollTo(
                0,
                parseInt(savedScrollY, 10) || 0
            );

        });
    }

    const preserveScrollForms =
        document.querySelectorAll(
            '.order-search-form, .cancelled-filter-form, form:has(button[name="update_status"]), form:has(button[name="cancel_order"])'
        );

    preserveScrollForms.forEach(function (form) {

        form.addEventListener('submit', function () {

            sessionStorage.setItem(
                'staffOrdersScrollY',
                String(window.scrollY)
            );

        });

    });

    /* Also preserve scroll position when clicking pagination links
       (both the main Orders pagination and the Cancelled Orders
       pagination), so paging through results does not jump back
       to the top of the page. */
    const paginationLinks =
        document.querySelectorAll(
            '.pagination .page-link[href]'
        );

    paginationLinks.forEach(function (link) {

        link.addEventListener('click', function (event) {

            if (link.closest('.page-item.disabled')) {
                return;
            }

            sessionStorage.setItem(
                'staffOrdersScrollY',
                String(window.scrollY)
            );

        });

    });

});
</script>

<!-- =========================================================
     SCRIPT 2
     STAFF PROFILE
     OTHER CANCELLATION REASON
     PRINT RECEIPT
========================================================= -->
<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {

        /* =====================================================
           OTHER CANCELLATION REASON
        ===================================================== */

        document
            .querySelectorAll(
                '[id^="cancelModal"]'
            )
            .forEach(function (modal) {

                const select =
                    modal.querySelector(
                        'select[name="cancellation_reason"]'
                    );

                const otherWrap =
                    modal.querySelector(
                        '[data-other-reason]'
                    );

                const otherTextarea =
                    otherWrap
                        ? otherWrap.querySelector(
                            'textarea[name="other_cancellation_reason"]'
                        )
                        : null;

                const form =
                    modal.querySelector('form');

                if (!select || !form) {
                    return;
                }

                select.addEventListener(
                    'change',
                    function () {

                        if (this.value === 'Other') {

                            if (otherWrap) {
                                otherWrap.style.display =
                                    'block';
                            }

                            if (otherTextarea) {

                                otherTextarea.required =
                                    true;

                                otherTextarea.focus();

                            }

                        } else {

                            if (otherWrap) {
                                otherWrap.style.display =
                                    'none';
                            }

                            if (otherTextarea) {

                                otherTextarea.required =
                                    false;

                                otherTextarea.value = '';

                            }

                        }

                    }
                );

                form.addEventListener(
                    'submit',
                    function (event) {

                        if (select.value === 'Other') {

                            const reason =
                                otherTextarea
                                    ? otherTextarea.value.trim()
                                    : '';

                            if (reason === '') {

                                event.preventDefault();

                                if (otherTextarea) {
                                    otherTextarea.focus();
                                }

                                return;
                            }

                            /*
                             * Replace the select value with
                             * the custom reason before submitting.
                             */
                            select.value = reason;

                            /*
                             * The select does not contain the
                             * custom value as an option, so add
                             * it temporarily.
                             */
                            const customOption =
                                document.createElement(
                                    'option'
                                );

                            customOption.value =
                                reason;

                            customOption.textContent =
                                reason;

                            customOption.selected =
                                true;

                            select.appendChild(
                                customOption
                            );

                        }

                    }
                );

            });

    }
);

/* =========================================================
   PRINT RECEIPT
========================================================= */

function printReceipt(modalId) {

    const modalElement = document.getElementById(modalId);

    if (!modalElement) {
        return;
    }

    /* Close any other open modal first so Bootstrap does not leave
       multiple backdrops or modal-open states behind. */
    document.querySelectorAll('.modal.show').forEach(function (openModal) {
        if (openModal === modalElement) {
            return;
        }

        if (window.bootstrap && bootstrap.Modal) {
            const instance =
                bootstrap.Modal.getInstance(openModal) ||
                bootstrap.Modal.getOrCreateInstance(openModal);
            instance.hide();
        } else {
            openModal.classList.remove('show');
            openModal.style.display = 'none';
            openModal.setAttribute('aria-hidden', 'true');
        }
    });

    /* Remove stale custom/Bootstrap backdrop state before opening the
       receipt. This prevents the receipt from becoming unclickable. */
    document.querySelectorAll(
        '.modal-backdrop, [data-localitea-modal-backdrop]'
    ).forEach(function (backdrop) {
        backdrop.remove();
    });

    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('padding-right');
    document.body.style.removeProperty('overflow');

    if (window.bootstrap && bootstrap.Modal) {
        const receiptInstance =
            bootstrap.Modal.getInstance(modalElement) ||
            bootstrap.Modal.getOrCreateInstance(modalElement, {
                backdrop: true,
                keyboard: true,
                focus: true
            });

        receiptInstance.show();
    } else {
        modalElement.classList.add('show');
        modalElement.style.display = 'block';
        modalElement.removeAttribute('aria-hidden');
        modalElement.setAttribute('aria-modal', 'true');
        modalElement.setAttribute('role', 'dialog');
        document.body.classList.add('modal-open');
        document.body.style.overflow = 'hidden';

        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop fade show';
        backdrop.setAttribute('data-localitea-modal-backdrop', 'true');
        document.body.appendChild(backdrop);
    }

    /* Do not depend on shown.bs.modal. Bootstrap/custom modal timing can
       vary after AJAX updates, while a short delay is enough for the
       receipt to be painted before the browser print dialog opens. */
    window.setTimeout(function () {
        const paper = modalElement.querySelector('.receipt-paper');
        let printArea = document.getElementById('receiptPrintArea');

        if (!printArea) {
            printArea = document.createElement('div');
            printArea.id = 'receiptPrintArea';
            document.body.appendChild(printArea);
        }

        printArea.innerHTML = '';

        if (paper) {
            const copy = paper.cloneNode(true);
            copy.removeAttribute('id');
            printArea.appendChild(copy);
        }

        document.body.classList.add('is-printing-receipt');

        const clearPrintArea = function () {
            document.body.classList.remove('is-printing-receipt');
            printArea.innerHTML = '';
            window.removeEventListener('afterprint', clearPrintArea);
        };

        window.addEventListener('afterprint', clearPrintArea);
        window.print();
    }, 350);

    /* Clean the receipt modal after the browser finishes printing. */
    const cleanupAfterPrint = function () {
        if (window.bootstrap && bootstrap.Modal) {
            const instance = bootstrap.Modal.getInstance(modalElement);
            if (instance) {
                instance.hide();
            }
        } else {
            modalElement.classList.remove('show');
            modalElement.style.display = 'none';
            modalElement.setAttribute('aria-hidden', 'true');
            modalElement.removeAttribute('aria-modal');
        }

        document.querySelectorAll(
            '.modal-backdrop, [data-localitea-modal-backdrop]'
        ).forEach(function (backdrop) {
            backdrop.remove();
        });

        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('padding-right');
        document.body.style.removeProperty('overflow');

        window.removeEventListener('afterprint', cleanupAfterPrint);
    };

    window.addEventListener('afterprint', cleanupAfterPrint);
}

</script>

<!-- =========================================================
     SCRIPT 3
     AJAX ORDER STATUS + AJAX CANCEL ORDER
========================================================= -->
<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {

        /* =====================================================
           UPDATE WORKFLOW COUNT
        ===================================================== */

        function updateWorkflowCount(
            status,
            change
        ) {

            if (!status) {
                return;
            }

            const card =
                document.querySelector(
                    '.workflow-card.workflow-' +
                    status
                );

            if (!card) {
                return;
            }

            const countElement =
                card.querySelector(
                    '.workflow-count'
                );

            if (!countElement) {
                return;
            }

            const currentCount =
                parseInt(
                    countElement.textContent.trim(),
                    10
                ) || 0;

            const newCount =
                Math.max(
                    0,
                    currentCount + change
                );

            countElement.textContent =
                newCount;

        }

        /* =====================================================
           AJAX TOASTS - INDEPENDENT STACK
        ===================================================== */
        /*
         * Each successful action gets its own toast immediately when its
         * request succeeds. Toasts stack vertically and have independent
         * timers; no toast waits for another toast to close.
         * Preparing actions reserve a sequence at click time so their visual
         * order remains consistent when multiple requests finish close together.
         */
        let staffAjaxOrderToastSequence = 0;

        function getStaffAjaxToastStack() {
            let stack = document.getElementById('staffAjaxToastStack');

            if (!stack) {
                stack = document.createElement('div');
                stack.id = 'staffAjaxToastStack';
                stack.className = 'orders-toast-stack';
                stack.setAttribute('aria-live', 'polite');
                stack.setAttribute('aria-relevant', 'additions');
                document.body.appendChild(stack);
            }

            return stack;
        }

        function escapeStaffAjaxToastText(value) {
            return String(value ?? '').replace(/[&<>"']/g, function (character) {
                return {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                }[character];
            });
        }

        function displayStaffAjaxOrderToast(message, title, sequence) {
            const stack = getStaffAjaxToastStack();
            const toast = document.createElement('div');
            const safeTitle = escapeStaffAjaxToastText(title || 'Order Processed');
            const safeMessage = escapeStaffAjaxToastText(
                message || 'The order was processed successfully.'
            );
            const toastSequence = Number(sequence) || (++staffAjaxOrderToastSequence);

            toast.className = 'orders-toast orders-toast-success';
            toast.dataset.actionSequence = String(toastSequence);
            toast.setAttribute('role', 'status');
            toast.innerHTML = `
                <span class="orders-toast-icon">
                    <i class="bi bi-check-circle"></i>
                </span>
                <span class="orders-toast-copy">
                    <strong class="orders-toast-title">${safeTitle}</strong>
                    <span class="orders-toast-message">${safeMessage}</span>
                </span>
                <button type="button" class="orders-toast-close" aria-label="Close notification">
                    <i class="bi bi-x-lg"></i>
                </button>
                <span class="orders-toast-progress" aria-hidden="true"></span>
            `;

            /* Insert by click/reservation order where possible, without waiting. */
            const nextToast = Array.from(stack.children).find(function (existingToast) {
                return Number(existingToast.dataset.actionSequence || 0) > toastSequence;
            });
            stack.insertBefore(toast, nextToast || null);

            let isClosing = false;
            let closeTimer = null;

            function closeThisToast() {
                if (isClosing) {
                    return;
                }
                isClosing = true;

                if (closeTimer !== null) {
                    window.clearTimeout(closeTimer);
                }

                toast.classList.add('is-closing');
                window.setTimeout(function () {
                    toast.remove();
                    const currentStack = document.getElementById('staffAjaxToastStack');
                    if (currentStack && currentStack.childElementCount === 0) {
                        currentStack.remove();
                    }
                }, 190);
            }

            const closeButton = toast.querySelector('.orders-toast-close');
            if (closeButton) {
                closeButton.addEventListener('click', closeThisToast);
            }

            closeTimer = window.setTimeout(closeThisToast, 3500);
        }

        /* Reserve only a sequence number, not a blocking FIFO queue slot. */
        window.reserveAjaxOrderToast = function () {
            return {
                sequence: ++staffAjaxOrderToastSequence,
                resolved: false,
                cancelled: false
            };
        };

        window.completeAjaxOrderToast = function (ticket, message, title = 'Order Processed') {
            if (!ticket || ticket.cancelled || ticket.resolved) {
                return;
            }

            ticket.resolved = true;
            displayStaffAjaxOrderToast(message, title, ticket.sequence);
        };

        window.cancelAjaxOrderToast = function (ticket) {
            if (!ticket || ticket.resolved) {
                return;
            }

            ticket.cancelled = true;
        };

        /* Ordinary action confirmations also appear immediately and independently. */
        window.showAjaxOrderToast = function (message, title = 'Order Processed') {
            displayStaffAjaxOrderToast(
                message,
                title,
                ++staffAjaxOrderToastSequence
            );
        };

        /* Restore the one-time GCash success toast after its automatic page reload. */
        try {
            const storedGcashToast = sessionStorage.getItem('localiteaStaffGcashVerifiedToast');
            if (storedGcashToast) {
                sessionStorage.removeItem('localiteaStaffGcashVerifiedToast');
                window.setTimeout(function () {
                    window.showAjaxOrderToast(storedGcashToast, 'Payment Verified');
                }, 250);
            }
        } catch (storageError) {
            console.warn('Unable to restore the GCash success toast:', storageError);
        }

       /* =====================================================
   REMOVE ORDER CARD
   Works for both status forms inside the card
   and cancel forms inside the separate modal.
===================================================== */

function removeOrderCard(form) {

    let orderCard =
        form.closest('.order-card');

    /*
     * Cancel modal is outside the order card.
     * Walk backward through the modal's siblings
     * until the correct order card is found.
     */
    if (!orderCard) {

        const modal =
            form.closest('.modal');

        if (modal) {

            /* Primary lookup: cancelModal123 -> order-123. */
            const modalId = String(modal.id || '');
            const match = modalId.match(/^cancelModal(\d+)$/);

            if (match) {
                orderCard = document.getElementById(
                    'order-' + match[1]
                );
            }

            /* Backward-compatible fallback for any older modal layout. */
            if (!orderCard) {
                let previous =
                    modal.previousElementSibling;

                while (previous) {

                    if (
                        previous.classList &&
                        previous.classList.contains(
                            'order-card'
                        )
                    ) {
                        orderCard = previous;
                        break;
                    }

                    previous =
                        previous.previousElementSibling;
                }
            }
        }
    }

    if (!orderCard) {
        return;
    }

    const ordersGrid =
        orderCard.closest(
            '.orders-grid'
        );

    orderCard.style.transition =
        'opacity .20s ease, transform .20s ease';

    orderCard.style.opacity =
        '0';

    orderCard.style.transform =
        'translateY(-4px)';

    setTimeout(
        function () {

            orderCard.remove();

            /*
             * Show empty state if there are
             * no remaining visible orders.
             */
            if (
                ordersGrid
                &&
                !ordersGrid.querySelector(
                    '.order-card'
                )
            ) {

                ordersGrid.innerHTML = `

                    <div
                        class="order-card empty-state"
                    >

                        <i
                            class="bi bi-inbox fs-1 d-block mb-2"
                        ></i>

                        <div>
                            No orders found in this category.
                        </div>

                    </div>

                `;

            }

        },
        210
    );

}

        /* =====================================================
           HIDE CANCELLATION MODAL
        ===================================================== */

        function hideCancellationModal(
            form
        ) {

            const modal =
                form.closest(
                    '.modal'
                );

            if (!modal) {
                return;
            }

            if (
                window.bootstrap
                &&
                bootstrap.Modal
            ) {

                const instance =
                    bootstrap.Modal.getInstance(
                        modal
                    )
                    ||
                    bootstrap.Modal.getOrCreateInstance(
                        modal
                    );

                instance.hide();

            }

        }

        /* =====================================================
           AJAX ORDER STATUS PROCESSING
           Confirm -> Preparing -> Ready -> Completed
        ===================================================== */

        const statusForms =
            document.querySelectorAll(
                'form[data-localitea-ajax-order-action="1"]:has(button[name="update_status"])'
            );

        statusForms.forEach(
            function (form) {

                form.addEventListener(
                    'submit',
                    async function (event) {

                        event.preventDefault();

                        const submitButton =
                            form.querySelector(
                                'button[name="update_status"]'
                            );

                        if (!submitButton) {
                            return;
                        }

                        if (
                            submitButton.disabled ||
                            form.dataset.processing === '1'
                        ) {
                            return;
                        }

                        const formData =
                            new FormData(
                                form
                            );

                        const isPreparingFallback =
                            String(formData.get('status') || '') === 'preparing';

                        form.dataset.processing = '1';

                        const originalButtonHTML =
                            submitButton.innerHTML;

                        if (isPreparingFallback) {
                            submitButton.disabled = true;
                            submitButton.setAttribute('aria-busy', 'true');
                            submitButton.innerHTML = `
                                <span
                                    class="spinner-border spinner-border-sm me-1"
                                    aria-hidden="true"
                                ></span>
                                Starting...
                            `;
                        }

                        formData.set(
                            'update_status',
                            '1'
                        );

                        formData.set(
                            'ajax',
                            '1'
                        );

                        try {

                            const response =
                                await fetch(
                                    form.action ||
                                    window.location.href,
                                    {
                                        method: 'POST',

                                        body: formData,

                                        headers: {
                                            'X-Requested-With':
                                                'XMLHttpRequest'
                                        }
                                    }
                                );

                            if (!response.ok) {

                                throw new Error(
                                    'Server returned ' +
                                    response.status
                                );

                            }

                            const data =
                                await response.json();

                            if (!data.success) {

                                throw new Error(
                                    data.message
                                    ||
                                    'The order could not be updated.'
                                );

                            }

                            updateWorkflowCount(
                                data.previous_status,
                                -1
                            );

                            updateWorkflowCount(
                                data.new_status,
                                1
                            );

                            delete form.dataset.processing;

                            removeOrderCard(
                                form
                            );

                            window.showAjaxOrderToast(
                                data.message,
                                'Order Processed'
                            );

                        } catch (error) {

                            console.error(
                                'AJAX order update failed:',
                                error
                            );

                            alert(
                                error.message
                                ||
                                'Unable to update the order.'
                            );

                            if (isPreparingFallback) {
                                submitButton.disabled = false;
                                submitButton.innerHTML = originalButtonHTML;
                                submitButton.removeAttribute('aria-busy');
                            }

                            delete form.dataset.processing;

                        }

                    }
                );

            }
        );

        /* =====================================================
           AJAX CANCEL ORDER
           Cancel order without refreshing the page
        ===================================================== */

        const cancelForms =
            document.querySelectorAll(
                'form[data-localitea-ajax-order-action="1"]:has(input[name="cancel_order"])'
            );

        cancelForms.forEach(
            function (form) {

                form.addEventListener(
                    'submit',
                    async function (event) {

                        event.preventDefault();

                        const submitButton =
                            form.querySelector(
                                'button[type="submit"]'
                            );

                        if (!submitButton) {
                            return;
                        }

                        if (
                            submitButton.disabled ||
                            form.dataset.processing === '1'
                        ) {
                            return;
                        }

                        form.dataset.processing = '1';

                        const formData =
                            new FormData(
                                form
                            );

                        /* Form contains cancel_order as hidden input,
                           but set it explicitly for reliability. */
                        formData.set(
                            'cancel_order',
                            '1'
                        );

                        formData.set(
                            'ajax',
                            '1'
                        );

                        try {

                            const response =
                                await fetch(
                                    form.action ||
                                    window.location.href,
                                    {
                                        method: 'POST',

                                        body: formData,

                                        headers: {
                                            'X-Requested-With':
                                                'XMLHttpRequest'
                                        }
                                    }
                                );

                            if (!response.ok) {

                                throw new Error(
                                    'Server returned ' +
                                    response.status
                                );

                            }

                            const data =
                                await response.json();

                            if (!data.success) {

                                throw new Error(
                                    data.message
                                    ||
                                    'The order could not be cancelled.'
                                );

                            }

                            /* -------------------------------------
                               CLOSE MODAL FIRST
                            ------------------------------------- */

                            hideCancellationModal(
                                form
                            );

                            /* -------------------------------------
                               UPDATE WORKFLOW COUNTER
                            ------------------------------------- */

                            updateWorkflowCount(
                                data.previous_status,
                                -1
                            );

                            if (data.refund_status === 'pending') {
                                const refundCountElement =
                                    document.querySelector('.staff-refund-count-value');

                                if (refundCountElement) {
                                    const currentRefundCount =
                                        parseInt(refundCountElement.textContent.trim(), 10) || 0;
                                    const nextRefundCount = currentRefundCount + 1;
                                    refundCountElement.textContent = nextRefundCount;

                                    const refundCountMessage =
                                        document.getElementById('staffRefundCountMessage');

                                    if (refundCountMessage) {
                                        refundCountMessage.textContent = nextRefundCount === 1
                                            ? '1 GCash refund awaiting Admin review.'
                                            : nextRefundCount + ' GCash refunds awaiting Admin review.';
                                    }
                                }
                            }

                            /* -------------------------------------
                               REMOVE ORDER CARD
                            ------------------------------------- */

                            removeOrderCard(
                                form
                            );

                            /* -------------------------------------
                               SUCCESS TOAST
                            ------------------------------------- */

                            window.showAjaxOrderToast(
                                data.message || 'Order cancelled successfully.',
                                'Order Cancelled'
                            );

                        } catch (error) {

                            console.error(
                                'AJAX order cancellation failed:',
                                error
                            );

                            alert(
                                error.message
                                ||
                                'Unable to cancel the order.'
                            );

                            delete form.dataset.processing;

                        }

                    }
                );

            }

        );

    }

);

</script>

<script>
/* =========================================================
   STAFF AJAX WORKFLOW NAVIGATION + ORDER ACTIONS
========================================================= */

document.addEventListener(
    'DOMContentLoaded',
    function () {

        const staffOrdersContent =
            document.querySelector(
                '.staff-content'
            );

        if (!staffOrdersContent) {
            return;
        }

        /* Safe bridge to the helper defined in the modal script. */
        function resetGcashVerifyAction() {
            try {
                if (typeof window.resetGcashVerifyAction === 'function') {
                    window.resetGcashVerifyAction();
                }
            } catch (e) {
                console.warn('GCash reset failed:', e);
            }
        }

        /* =====================================================
           SYNC WORKFLOW CARDS
        ===================================================== */

        function syncWorkflowCards(
            parsedDocument,
            selectedStatus,
            hasSearch
        ) {

            document
                .querySelectorAll(
                    '.workflow-card[data-status]'
                )
                .forEach(
                    function (card) {

                        const status =
                            card.dataset.status;

                        const newCard =
                            parsedDocument.querySelector(
                                '.workflow-card.workflow-' +
                                status
                            );

                        if (newCard) {

                            const currentCount =
                                card.querySelector(
                                    '.workflow-count'
                                );

                            const newCount =
                                newCard.querySelector(
                                    '.workflow-count'
                                );

                            if (
                                currentCount &&
                                newCount
                            ) {

                                currentCount.textContent =
                                    newCount.textContent.trim();

                            }

                        }

                        const isActive =
                            !hasSearch &&
                            status === selectedStatus;

                        card.classList.toggle(
                            'active',
                            isActive
                        );

                        card.setAttribute(
                            'aria-current',
                            isActive
                                ? 'page'
                                : 'false'
                        );

                    }
                );

        }

        /* =====================================================
           SYNC SEARCH FORM
        ===================================================== */

        function syncSearchForm(
            parsedDocument
        ) {

            const currentForm =
                document.querySelector(
                    '.order-search-form'
                );

            const newForm =
                parsedDocument.querySelector(
                    '.order-search-form'
                );

            if (
                !currentForm ||
                !newForm
            ) {
                return;
            }

            const currentSearch =
                currentForm.querySelector(
                    'input[name="q"]'
                );

            const newSearch =
                newForm.querySelector(
                    'input[name="q"]'
                );

            if (
                currentSearch &&
                newSearch
            ) {

                currentSearch.value =
                    newSearch.value;

            }

            const currentStatus =
                currentForm.querySelector(
                    'input[name="status"]'
                );

            const newStatus =
                newForm.querySelector(
                    'input[name="status"]'
                );

            if (
                currentStatus &&
                newStatus
            ) {

                currentStatus.value =
                    newStatus.value;

            }

            const currentActions =
                currentForm.querySelector(
                    '.search-actions'
                );

            const newActions =
                newForm.querySelector(
                    '.search-actions'
                );

            if (
                currentActions &&
                newActions
            ) {

                currentActions.innerHTML =
                    newActions.innerHTML;

            }

        }

        /* =====================================================
           BOOTSTRAP MODAL / BACKDROP CLEANUP
           Active Orders can contain View Details / receipt /
           cancellation modals. When the section is replaced by
           AJAX, Bootstrap may leave its backdrop or body lock
           behind even though the modal itself was removed.
        ===================================================== */

        function cleanupStaffBootstrapModalState() {

            /*
             * Ask any currently open Bootstrap modal to close first.
             * The modal may belong to the Active Orders section that
             * is about to be replaced.
             */
            document
                .querySelectorAll('.modal.show')
                .forEach(function (modalElement) {

                    try {

                        if (
                            window.bootstrap &&
                            bootstrap.Modal
                        ) {

                            const modalInstance =
                                bootstrap.Modal.getInstance(
                                    modalElement
                                );

                            if (modalInstance) {
                                modalInstance.hide();
                            }

                        }

                    } catch (modalError) {

                        console.warn(
                            'Staff modal cleanup warning:',
                            modalError
                        );

                    }

                });

            /*
             * Remove stale Bootstrap backdrops immediately. This is
             * especially important after a successful AJAX refresh
             * because the old modal element may have been replaced
             * before Bootstrap finishes its normal hide animation.
             */
            document
                .querySelectorAll('.modal-backdrop')
                .forEach(function (backdrop) {
                    backdrop.remove();
                });

            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('padding-right');
            document.body.style.removeProperty('overflow');

        }

        /* =====================================================
           LOAD ONLY ACTIVE ORDERS
           The Staff page itself does NOT reload.
        ===================================================== */

        /*
         * Multiple order actions may finish close together (for example,
         * Staff starts Preparing on several orders). Only the newest list
         * refresh is allowed to replace the Active Orders section. Older
         * responses are ignored so they cannot restore stale order states.
         */
        let activeOrdersLoadSequence = 0;

        async function loadStaffActiveOrders(
            targetUrl,
            pushHistory = true
        ) {

            const activeSection =
                document.querySelector(
                    '.active-orders-section'
                );

            if (!activeSection) {
                return false;
            }

            const thisLoadSequence = ++activeOrdersLoadSequence;
            let loadSucceeded = false;

            const requestedUrl =
                new URL(
                    targetUrl,
                    window.location.href
                );

            const selectedStatus =
                requestedUrl.searchParams.get(
                    'status'
                ) ||
                'order_queue';

            const searchValue =
                (
                    requestedUrl.searchParams.get(
                        'q'
                    ) ||
                    ''
                ).trim();

            const hasSearch =
                searchValue !== '';

            const previousHTML =
                activeSection.innerHTML;

            /*
             * Announce that the list is refreshing without dimming or
             * disabling the Active Orders section. Staff must still be able
             * to select text, open details, search, paginate, and start work
             * on a different order while an earlier request completes.
             */
            activeSection.setAttribute(
                'aria-busy',
                'true'
            );

            try {

                const response =
                    await fetch(
                        requestedUrl.toString(),
                        {
                            method: 'GET',

                            headers: {
                                'X-Requested-With':
                                    'XMLHttpRequest',

                                'Accept':
                                    'text/html'
                            },

                            cache: 'no-store'
                        }
                    );

                if (!response.ok) {

                    throw new Error(
                        'Server returned HTTP ' +
                        response.status
                    );

                }

                const html =
                    await response.text();

                /* A newer refresh has already been started; ignore this HTML. */
                if (thisLoadSequence !== activeOrdersLoadSequence) {
                    return true;
                }

                const parser =
                    new DOMParser();

                const parsedDocument =
                    parser.parseFromString(
                        html,
                        'text/html'
                    );

                const newActiveSection =
                    parsedDocument.querySelector(
                        '.active-orders-section'
                    );

                if (!newActiveSection) {

                    throw new Error(
                        'Active Orders section was not found.'
                    );

                }

                /*
                 * Close/clean any Bootstrap modal BEFORE replacing the
                 * live Active Orders DOM. Otherwise its backdrop can
                 * survive after the modal node is removed.
                 */
                cleanupStaffBootstrapModalState();

                /* Replace only Active Orders */

                activeSection.replaceWith(
                    newActiveSection
                );

                /*
                 * Clean again after replacement in case Bootstrap
                 * queued backdrop cleanup from the modal hide call.
                 */
                cleanupStaffBootstrapModalState();

                /*
                 * If this request came from View Order, focus the
                 * exact target AFTER the new Active Orders section
                 * has been inserted into the live document.
                 */
                const requestedTargetId =
                    parseInt(
                        requestedUrl.searchParams.get(
                            'order_id'
                        ) || '0',
                        10
                    );

                if (
                    Number.isFinite(requestedTargetId) &&
                    requestedTargetId > 0 &&
                    typeof window.LocaliteaFocusViewOrderTarget ===
                        'function'
                ) {

                    const focused =
                        window.LocaliteaFocusViewOrderTarget(
                            requestedTargetId,
                            false
                        );

                    if (focused) {

                        const cleanUrl =
                            new URL(
                                window.location.href
                            );

                        cleanUrl.searchParams.delete(
                            'order_id'
                        );

                        cleanUrl.searchParams.delete(
                            'notification_id'
                        );

                        window.history.replaceState(
                            window.history.state,
                            document.title,
                            cleanUrl.pathname +
                            (
                                cleanUrl.search
                                    ? cleanUrl.search
                                    : ''
                            ) +
                            cleanUrl.hash
                        );
                    }
                }

                /* Update workflow counts */

                syncWorkflowCards(
                    parsedDocument,
                    selectedStatus,
                    hasSearch
                );

                /* Update search form */

                syncSearchForm(
                    parsedDocument
                );

                loadSucceeded = true;

                /* Update URL */

                if (pushHistory) {

                    const cleanUrl =
                        new URL(
                            requestedUrl.toString()
                        );

                    cleanUrl.searchParams.delete(
                        'ajax'
                    );

                    window.history.pushState(
                        {
                            staffOrdersAjax:
                                true
                        },
                        '',
                        cleanUrl.pathname +
                        (
                            cleanUrl.search
                                ? cleanUrl.search
                                : ''
                        ) +
                        cleanUrl.hash
                    );

                }

            } catch (error) {

                /* Ignore errors from an older refresh superseded by a newer one. */
                if (thisLoadSequence !== activeOrdersLoadSequence) {
                    return true;
                }

                console.error(
                    'Staff Active Orders AJAX error:',
                    error
                );

                activeSection.innerHTML =
                    previousHTML;

                alert(
                    'Unable to load orders. Please try again.'
                );

            }

            /* Only the newest request may clear the busy state and clean up. */
            if (thisLoadSequence === activeOrdersLoadSequence) {
                const restoredSection =
                    document.querySelector(
                        '.active-orders-section'
                    );

                if (restoredSection) {
                    restoredSection.removeAttribute('aria-busy');
                }

                cleanupStaffBootstrapModalState();
            }

            return loadSucceeded;

        }

        /* =====================================================
           WORKFLOW TABS
        ===================================================== */

        staffOrdersContent.addEventListener(
            'click',
            function (event) {

                const workflowLink =
                    event.target.closest(
                        '.workflow-card[data-status]'
                    );

                if (!workflowLink) {
                    return;
                }

                if (
                    event.ctrlKey ||
                    event.metaKey ||
                    event.shiftKey ||
                    event.altKey ||
                    workflowLink.target === '_blank'
                ) {
                    return;
                }

                event.preventDefault();

                loadStaffActiveOrders(
                    workflowLink.href,
                    true
                );

            }
        );

        /* =====================================================
           ACTIVE ORDER PAGINATION
           Cancelled-order pagination is left normal.
        ===================================================== */

        staffOrdersContent.addEventListener(
            'click',
            function (event) {

                const paginationLink =
                    event.target.closest(
                        '.active-orders-section .pagination a.page-link[href]'
                    );

                if (!paginationLink) {
                    return;
                }

                const pageItem =
                    paginationLink.closest(
                        '.page-item'
                    );

                if (
                    pageItem &&
                    pageItem.classList.contains(
                        'disabled'
                    )
                ) {

                    event.preventDefault();

                    return;

                }

                if (
                    event.ctrlKey ||
                    event.metaKey ||
                    event.shiftKey ||
                    event.altKey ||
                    paginationLink.target === '_blank'
                ) {
                    return;
                }

                event.preventDefault();

                loadStaffActiveOrders(
                    paginationLink.href,
                    true
                );

            }
        );

        /* =====================================================
           ACTIVE ORDER SEARCH
        ===================================================== */

        staffOrdersContent.addEventListener(
            'submit',
            function (event) {

                const form =
                    event.target.closest(
                        '.order-search-form'
                    );

                if (!form) {
                    return;
                }

                event.preventDefault();

                const formData =
                    new FormData(
                        form
                    );

                const currentUrl =
                    new URL(
                        window.location.href
                    );

                currentUrl.searchParams.set(
                    'status',
                    String(
                        formData.get('status') ||
                        'order_queue'
                    )
                );

                const searchValue =
                    String(
                        formData.get('q') ||
                        ''
                    ).trim();

                if (searchValue !== '') {

                    currentUrl.searchParams.set(
                        'q',
                        searchValue
                    );

                } else {

                    currentUrl.searchParams.delete(
                        'q'
                    );

                }

                currentUrl.searchParams.set(
                    'page',
                    '1'
                );

                loadStaffActiveOrders(
                    currentUrl.toString(),
                    true
                );

            }
        );

        /* =====================================================
           CLEAR ACTIVE-ORDER SEARCH WITH THE NATIVE X BUTTON

           Browsers fire the `search` event when the native clear (X)
           control on an input[type="search"] is used. Submit the empty
           search through the existing AJAX handler so Staff returns
           to the selected Active Orders workflow immediately.
        ===================================================== */
        staffOrdersContent.addEventListener(
            'search',
            function (event) {

                const searchInput = event.target.closest(
                    '.order-search-form input[type="search"][name="q"]'
                );

                if (!searchInput || searchInput.value.trim() !== '') {
                    return;
                }

                const searchForm = searchInput.closest('.order-search-form');

                if (searchForm && typeof searchForm.requestSubmit === 'function') {
                    searchForm.requestSubmit();
                } else if (searchForm) {
                    searchForm.dispatchEvent(new Event('submit', {
                        bubbles: true,
                        cancelable: true
                    }));
                }
            }
        );

        /* =====================================================
           BROWSER BACK / FORWARD
        ===================================================== */

        window.addEventListener(
            'popstate',
            function () {

                loadStaffActiveOrders(
                    window.location.href,
                    false
                );

            }
        );

        /* =====================================================
           AJAX ORDER ACTIONS
           Capture phase prevents the older submit handlers
           already present in this file from submitting twice.
        ===================================================== */

        staffOrdersContent.addEventListener(
            'submit',
            function (event) {

                const form =
                    event.target.closest(
                        'form'
                    );

                if (!form) {
                    return;
                }

                const statusButton =
                    form.querySelector(
                        'button[name="update_status"]'
                    );

                const cancelInput =
                    form.querySelector(
                        'input[name="cancel_order"]'
                    );

                /*
                 * Active Orders is refreshed through AJAX, so this delegated
                 * listener handles workflow forms even after new cards are
                 * inserted. Capture + stopImmediatePropagation prevents the
                 * older direct submit listeners from firing twice.
                 */
                if (statusButton) {

                    event.preventDefault();
                    event.stopImmediatePropagation();

                    handleStaffStatusUpdate(
                        form
                    );

                    return;

                }

                if (cancelInput) {

                    event.preventDefault();
                    event.stopImmediatePropagation();

                    handleStaffCancelOrder(
                        form
                    );

                }

            },
            true
        );

        /*
         * The shared GCash review modal sits outside .staff-content.
         * Handle the actual Verify button click in the capture phase so
         * older delegated submit handlers cannot intercept it or submit
         * the form through the browser's normal navigation path.
         * The form.dataset.processing guard prevents duplicate requests.
         */
        document.addEventListener('click', function (event) {
            const target = event.target instanceof Element
                ? event.target.closest('#staffGcashVerifyButton')
                : null;

            if (!target) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            event.stopImmediatePropagation();

            const reviewForm = target.closest('form#staffGcashReviewForm');
            if (reviewForm) {
                handleStaffStatusUpdate(reviewForm);
            }
        }, true);

        /* Keep keyboard / Enter-key form submission working as well. */
        document.addEventListener('submit', function (event) {
            if (!event.target || event.target.id !== 'staffGcashReviewForm') {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            event.stopImmediatePropagation();
            handleStaffStatusUpdate(event.target);
        }, true);

        /* =====================================================
           AJAX STATUS UPDATE
        ===================================================== */

        async function handleStaffStatusUpdate(
            form
        ) {

            const button =
                form.querySelector(
                    'button[name="update_status"]'
                );

            if (
                !button ||
                button.disabled ||
                form.dataset.processing === '1'
            ) {
                return;
            }

            /* Prevent repeated submits from this modal instance. */
            form.dataset.processing = '1';

            const originalHTML =
                button.innerHTML;

            const orderCard =
                form.closest(
                    '.order-card'
                );

            let processingOverlay =
                null;

            /* Keep the requested action local to this form. The Start Preparing
             * button itself shows the spinner; no full-screen overlay or backdrop
             * is added, so Staff can still select text and use the rest of the page. */
            const requestedStatus =
                String(
                    new FormData(form).get('status') ||
                    ''
                );

            const isPreparing =
                requestedStatus === 'preparing';

            /*
             * Reserve the success toast now (not when the request finishes),
             * so several quick Start Preparing clicks are confirmed in click order.
             */
            const preparingToastTicket = isPreparing
                && typeof window.reserveAjaxOrderToast === 'function'
                    ? window.reserveAjaxOrderToast()
                    : null;
            let preparingToastResolved = false;

            /*
             * Only Start Preparing gets a visible loading state because that
             * action also sends the customer's Preparing email. All other
             * status actions run quietly in the background and must not dim,
             * cover, disable, or replace their buttons with a spinner.
             *
             * form.dataset.processing above still blocks duplicate submissions
             * from the same form without adding a visible loading indicator.
             */
            if (isPreparing) {
                button.disabled = true;
                button.setAttribute('aria-busy', 'true');
                button.innerHTML = `
                    <span
                        class="spinner-border spinner-border-sm me-1"
                        aria-hidden="true"
                    ></span>
                    Starting...
                `;
            }

            const formData =
                new FormData(
                    form
                );

            formData.set(
                'update_status',
                '1'
            );

            formData.set(
                'ajax',
                '1'
            );

            /* GCash review: close the modal immediately after Verify is clicked.
             * Do not add a row-level loading state; only Start Preparing shows one. */
            if (form.id === 'staffGcashReviewForm') {
                try {
                    const instantModal = document.getElementById('staffGcashReviewModal');
                    if (instantModal) {
                        /* No fade-out animation, so it disappears immediately. */
                        instantModal.classList.remove('fade');

                        if (window.bootstrap && bootstrap.Modal) {
                            (bootstrap.Modal.getInstance(instantModal) ||
                             bootstrap.Modal.getOrCreateInstance(instantModal)).hide();
                        }

                        if (instantModal.classList.contains('show') && typeof window.closeLocaliteaModal === 'function') {
                            window.closeLocaliteaModal(instantModal);
                        }

                        document.querySelectorAll('.modal-backdrop, [data-localitea-modal-backdrop]')
                            .forEach(function (b) { b.remove(); });
                        document.body.classList.remove('modal-open');
                        document.body.style.removeProperty('overflow');
                        document.body.style.removeProperty('padding-right');

                        /* Restore the fade animation for the next time it opens. */
                        window.setTimeout(function () {
                            instantModal.classList.add('fade');
                        }, 50);
                    }
                } catch (instantCloseError) {
                    console.warn('Instant modal close warning:', instantCloseError);
                }
            }

            let serverConfirmedUpdate = false;

            try {

                const response =
                    await fetch(
                        form.action ||
                        window.location.href,
                        {
                            method: 'POST',

                            body: formData,

                            headers: {
                                'X-Requested-With':
                                    'XMLHttpRequest',

                                'Accept':
                                    'application/json'
                            }
                        }
                    );

                if (!response.ok) {

                    throw new Error(
                        'Server returned HTTP ' +
                        response.status
                    );

                }

                const data =
                    await response.json();

                if (!data.success) {

                    if (data.stale_status) {
                        /*
                         * The order has already advanced in the database.
                         * Do not show a stale-action error or a separate
                         * synchronization toast. Restore this button and
                         * quietly reload the Active Orders region in the
                         * background so the current status is shown.
                         */
                        if (preparingToastTicket && !preparingToastResolved) {
                            window.cancelAjaxOrderToast(preparingToastTicket);
                            preparingToastResolved = true;
                        }

                        if (isPreparing) {
                            button.disabled = false;
                            button.innerHTML = originalHTML;
                            button.removeAttribute('aria-busy');
                        }

                        delete form.dataset.processing;

                        void loadStaffActiveOrders(window.location.href, false).then(function (refreshed) {
                            if (!refreshed) {
                                window.location.reload();
                            }
                        });

                        return;
                    }

                    throw new Error(
                        data.message ||
                        'The order status could not be updated.'
                    );

                }

                /* From this point onward, the server confirms the transaction committed. */
                serverConfirmedUpdate = true;

                /*
                 * Update the workflow counts immediately from the committed
                 * server response. The background list refresh below then
                 * reconciles the cards and counts with the database.
                 */
                if (
                    !data.already_processed &&
                    data.previous_status &&
                    data.new_status &&
                    data.previous_status !== data.new_status
                ) {
                    updateWorkflowCount(data.previous_status, -1);
                    updateWorkflowCount(data.new_status, 1);
                }

                /*
                 * Remove this order from the visible list as soon as its own
                 * update succeeds, rather than waiting for the newest shared
                 * Active Orders refresh to replace the whole section. This is
                 * important when Staff starts Preparing on several orders:
                 * each committed order should leave the Confirmed tab on its
                 * own response, even while other orders are still processing.
                 *
                 * A non-empty search spans all active workflow statuses, so
                 * keep the row when the updated status is still part of that
                 * active search. Completed orders leave the active search too.
                 */
                const submittedStatusFilter = String(
                    formData.get('status_filter') ||
                    new URL(window.location.href).searchParams.get('status') ||
                    'order_queue'
                );
                const submittedSearchTerm = String(
                    formData.get('q') || ''
                ).trim();
                const committedStatus = String(
                    data.new_status || requestedStatus || ''
                );
                const activeSearchStatuses = [
                    'order_queue',
                    'pending_verification',
                    'confirmed',
                    'preparing',
                    'ready'
                ];
                const remainsInCurrentOrderList = submittedSearchTerm !== ''
                    ? activeSearchStatuses.includes(committedStatus)
                    : committedStatus === submittedStatusFilter;

                if (!remainsInCurrentOrderList) {
                    removeOrderCard(form);
                }

                if (preparingToastTicket && !preparingToastResolved) {
                    window.completeAjaxOrderToast(
                        preparingToastTicket,
                        data.message || 'The order is now being prepared.',
                        'Order Preparing'
                    );
                    preparingToastResolved = true;
                }

                /*
                 * GCash verification moves this order out of Pending Verification.
                 * Refresh the existing Active Orders region through its AJAX loader
                 * so the verified order disappears and the workflow counts update
                 * immediately, while any other pending GCash orders remain visible.
                 * Do this before optional modal cleanup: a Bootstrap modal error must
                 * never prevent the committed order list from being refreshed.
                 */
                if (form.id === 'staffGcashReviewForm') {

                    const refreshUrl = new URL(window.location.href);
                    const submittedReviewData = new FormData(form);
                    const reviewStatus = String(
                        submittedReviewData.get('status_filter') || 'pending_verification'
                    );
                    const reviewSearch = String(
                        submittedReviewData.get('q') || ''
                    ).trim();
                    const reviewPage = String(
                        submittedReviewData.get('page') || '1'
                    );

                    refreshUrl.searchParams.set('status', reviewStatus);
                    refreshUrl.searchParams.set('page', reviewPage);
                    refreshUrl.searchParams.delete('order_id');
                    refreshUrl.searchParams.delete('notification_id');

                    if (reviewSearch !== '') {
                        refreshUrl.searchParams.set('q', reviewSearch);
                    } else {
                        refreshUrl.searchParams.delete('q');
                    }

                    /* Release the modal's one-order submission lock. */
                    resetGcashVerifyAction();

                    /* Close the GCash review modal now that the server confirmed success. */
                    try {
                        const gcashModalEl = document.getElementById('staffGcashReviewModal');
                        if (gcashModalEl) {
                            if (window.bootstrap && bootstrap.Modal) {
                                (bootstrap.Modal.getInstance(gcashModalEl) ||
                                 bootstrap.Modal.getOrCreateInstance(gcashModalEl)).hide();
                            }
                            /* Force-close if Bootstrap did not (stale instance / fallback modal). */
                            window.setTimeout(function () {
                                if (gcashModalEl.classList.contains('show')) {
                                    if (typeof window.closeLocaliteaModal === 'function') {
                                        window.closeLocaliteaModal(gcashModalEl);
                                    } else {
                                        gcashModalEl.classList.remove('show');
                                        gcashModalEl.style.display = 'none';
                                        gcashModalEl.setAttribute('aria-hidden', 'true');
                                        gcashModalEl.removeAttribute('aria-modal');
                                    }
                                }
                                document.querySelectorAll('.modal-backdrop, [data-localitea-modal-backdrop]')
                                    .forEach(function (b) { b.remove(); });
                                document.body.classList.remove('modal-open');
                                document.body.style.removeProperty('overflow');
                                document.body.style.removeProperty('padding-right');
                            }, 400);
                        }
                    } catch (modalCloseError) {
                        console.warn('GCash modal close warning:', modalCloseError);
                    }

                    const refreshed = await loadStaffActiveOrders(
                        refreshUrl.toString(),
                        false
                    );

                    if (!refreshed) {
                        /* Last-resort recovery only if the AJAX region refresh fails. */
                        try {
                            sessionStorage.setItem(
                                'localiteaStaffGcashVerifiedToast',
                                data.message || 'GCash payment was verified and moved to Order Queue.'
                            );
                        } catch (storageError) {
                            console.warn('Unable to preserve the GCash success toast:', storageError);
                        }
                        window.location.assign(refreshUrl.toString());
                        return;
                    }

                    if (typeof window.showAjaxOrderToast === 'function') {
                        window.showAjaxOrderToast(
                            data.message || 'GCash payment was verified and the order moved to Order Queue.',
                            'Payment Verified'
                        );
                    }

                    return;
                }

                /*
                 * The order update is already committed. Stop the Start Preparing
                 * spinner as soon as the POST (including its email attempt) returns.
                 * Reconcile the list in the background; do not make the button wait
                 * for a second GET request before finishing the action.
                 */
                if (isPreparing) {
                    button.disabled = false;
                    button.innerHTML = originalHTML;
                    button.removeAttribute('aria-busy');
                }

                delete form.dataset.processing;

                const emailFailureMessage =
                    data.email_sent === false
                        ? 'Order updated, but the customer email was NOT sent: ' +
                          (data.email_error || 'unknown error')
                        : '';

                if (processingOverlay) {

                    processingOverlay.remove();
                    processingOverlay = null;

                }

                if (orderCard) {

                    orderCard.classList.remove(
                        'processing-order'
                    );

                }

                if (
                    !preparingToastTicket &&
                    typeof window.showAjaxOrderToast === 'function'
                ) {
                    window.showAjaxOrderToast(
                        data.message || 'Order status updated successfully.',
                        form.id === 'staffGcashReviewForm'
                            ? 'Payment Verified'
                            : 'Order Processed'
                    );
                }

                if (emailFailureMessage) {

                    console.error(
                        'Preparing email failed:',
                        data.email_error
                    );

                    alert(emailFailureMessage);

                }

                /*
                 * Refresh cards and workflow totals without blocking this
                 * successful action or its button. The loader discards older
                 * overlapping responses, so the newest request wins.
                 */
                void loadStaffActiveOrders(window.location.href, false).then(function (refreshed) {
                    if (!refreshed) {
                        window.location.reload();
                    }
                });

            } catch (error) {

                console.error(
                    'AJAX Staff order status update failed:',
                    error
                );

                /*
                 * The database may already have committed before a client-side
                 * UI helper fails. In that case, report the actual successful
                 * action, release the Preparing button, and quietly refresh the
                 * list. Never replace the normal success message with a
                 * "synchronizing" message or a Payment Not Verified alert.
                 */
                if (serverConfirmedUpdate) {

                    if (processingOverlay) {
                        processingOverlay.remove();
                        processingOverlay = null;
                    }

                    if (orderCard) {
                        orderCard.classList.remove('processing-order');
                    }

                    if (isPreparing) {
                        button.disabled = false;
                        button.innerHTML = originalHTML;
                        button.removeAttribute('aria-busy');
                    }

                    delete form.dataset.processing;

                    if (preparingToastTicket && !preparingToastResolved) {
                        window.completeAjaxOrderToast(
                            preparingToastTicket,
                            'The order is now being prepared.',
                            'Order Preparing'
                        );
                        preparingToastResolved = true;
                    } else if (!preparingToastTicket && typeof window.showAjaxOrderToast === 'function') {
                        window.showAjaxOrderToast(
                            form.id === 'staffGcashReviewForm'
                                ? 'Payment was verified and the order was moved to Order Queue.'
                                : 'The order was updated successfully.',
                            form.id === 'staffGcashReviewForm'
                                ? 'Payment Verified'
                                : 'Order Processed'
                        );
                    }

                    if (form.id === 'staffGcashReviewForm') {
                        resetGcashVerifyAction();
                    }

                    void loadStaffActiveOrders(window.location.href, false).then(function (refreshed) {
                        if (!refreshed) {
                            window.location.reload();
                        }
                    });

                    return;
                }

                if (preparingToastTicket && !preparingToastResolved) {
                    window.cancelAjaxOrderToast(preparingToastTicket);
                    preparingToastResolved = true;
                }

                if (processingOverlay) {

                    processingOverlay.remove();
                    processingOverlay = null;

                }

                if (orderCard) {

                    orderCard.classList.remove(
                        'processing-order'
                    );

                }

                if (isPreparing) {
                    button.disabled = false;
                    button.innerHTML = originalHTML;
                    button.removeAttribute('aria-busy');
                }

                delete form.dataset.processing;

                if (
                    form.id === 'staffGcashReviewForm'
                    && typeof window.showAjaxOrderToast === 'function'
                ) {
                    window.showAjaxOrderToast(
                        error.message || 'Unable to verify the GCash payment.',
                        'Payment Not Verified'
                    );
                } else {
                    alert(
                        error.message ||
                        'Unable to update the order.'
                    );
                }

            }

        }

        /* =====================================================
           AJAX CANCEL ORDER
        ===================================================== */

        async function handleStaffCancelOrder(
            form
        ) {

            const select =
                form.querySelector(
                    'select[name="cancellation_reason"]'
                );

            const otherWrap =
                form.querySelector(
                    '[data-other-reason]'
                );

            const textarea =
                otherWrap
                    ? otherWrap.querySelector(
                        'textarea[name="other_cancellation_reason"]'
                    )
                    : null;

            /* Convert Other into a submitted option */

            if (
                select &&
                select.value === 'Other'
            ) {

                const reason =
                    textarea
                        ? textarea.value.trim()
                        : '';

                if (reason === '') {

                    if (textarea) {
                        textarea.focus();
                    }

                    return;

                }

                let customOption =
                    Array.from(
                        select.options
                    ).find(
                        function (option) {

                            return (
                                option.value ===
                                reason
                            );

                        }
                    );

                if (!customOption) {

                    customOption =
                        document.createElement(
                            'option'
                        );

                    customOption.value =
                        reason;

                    customOption.textContent =
                        reason;

                    customOption.selected =
                        true;

                    select.appendChild(
                        customOption
                    );

                }

                select.value =
                    reason;

            }

            const button =
                form.querySelector(
                    'button[type="submit"]'
                );

            if (
                !button ||
                button.disabled ||
                form.dataset.processing === '1'
            ) {
                return;
            }

            /* Block duplicate cancellation requests without showing a loader. */
            form.dataset.processing = '1';

            const formData =
                new FormData(
                    form
                );

            formData.set(
                'cancel_order',
                '1'
            );

            formData.set(
                'ajax',
                '1'
            );

            try {

                const response =
                    await fetch(
                        form.action ||
                        window.location.href,
                        {
                            method: 'POST',

                            body: formData,

                            headers: {
                                'X-Requested-With':
                                    'XMLHttpRequest',

                                'Accept':
                                    'application/json'
                            }
                        }
                    );

                if (!response.ok) {

                    throw new Error(
                        'Server returned HTTP ' +
                        response.status
                    );

                }

                const data =
                    await response.json();

                if (!data.success) {

                    throw new Error(
                        data.message ||
                        'The order could not be cancelled.'
                    );

                }

                delete form.dataset.processing;

                /* Close cancellation modal */

                const modal =
                    form.closest(
                        '.modal'
                    );

                if (modal) {

                    const modalInstance =
                        bootstrap.Modal.getInstance(
                            modal
                        ) ||
                        bootstrap.Modal.getOrCreateInstance(
                            modal
                        );

                    modalInstance.hide();

                }

                /* Refresh only Active Orders */

                await loadStaffActiveOrders(
                    window.location.href,
                    false
                );

                if (
                    typeof window.showAjaxOrderToast ===
                    'function'
                ) {

                    window.showAjaxOrderToast(
                        data.message ||
                        'Order cancelled successfully.',
                        'Order Cancelled'
                    );

                }

            } catch (error) {

                console.error(
                    'AJAX Staff order cancellation failed:',
                    error
                );

                delete form.dataset.processing;

                alert(
                    error.message ||
                    'Unable to cancel the order.'
                );

            }

        }

    }
);
</script>

<!-- =========================================================
     SCRIPT 4
     AJAX CANCELLED ORDER FILTER + PAGINATION
     Only the Cancelled Orders section is replaced.
========================================================= -->
<script>
document.addEventListener('DOMContentLoaded', function () {

    const staffOrdersContent =
        document.querySelector('.staff-content');

    if (!staffOrdersContent) {
        return;
    }

    let cancelledRequestId = 0;

    function cancelledSearchFormDataFromUrl(targetUrl) {
        const params = targetUrl.searchParams;

        return {
            period: params.get('cancelled_period') || 'today',
            month: params.get('cancelled_month') || '',
            search: (params.get('cancelled_q') || '').trim(),
            page: params.get('cancelled_page') || '1'
        };
    }

    function syncCancelledControls(parsedDocument) {

        const currentSection =
            document.querySelector('details.cancelled-orders-section:not(.pending-refunds-section)');

        const newSection =
            parsedDocument.querySelector('details.cancelled-orders-section:not(.pending-refunds-section)');

        if (!currentSection || !newSection) {
            return;
        }

        const currentPeriod =
            currentSection.querySelector('[data-cancelled-period]');

        const newPeriod =
            newSection.querySelector('[data-cancelled-period]');

        if (currentPeriod && newPeriod) {
            currentPeriod.value = newPeriod.value;
        }

        const currentMonth =
            currentSection.querySelector('[data-cancelled-month]');

        const newMonth =
            newSection.querySelector('[data-cancelled-month]');

        if (currentMonth && newMonth) {
            currentMonth.value = newMonth.value;
        }

        const currentSearch =
            currentSection.querySelector('[data-cancelled-search]');

        const newSearch =
            newSection.querySelector('[data-cancelled-search]');

        if (currentSearch && newSearch) {
            currentSearch.value = newSearch.value;
        }
    }

    async function loadCancelledOrders(
        targetUrl,
        pushHistory = true
    ) {
        const cancelledSection =
            document.querySelector('details.cancelled-orders-section:not(.pending-refunds-section)');

        if (!cancelledSection) {
            return;
        }

        const requestedUrl =
            new URL(targetUrl, window.location.href);

        requestedUrl.searchParams.set(
            'cancelled_open',
            '1'
        );

        const requestNumber =
            ++cancelledRequestId;

        cancelledSection.setAttribute(
            'aria-busy',
            'true'
        );

        cancelledSection.style.opacity = '0.55';
        cancelledSection.style.pointerEvents = 'none';

        try {
            const response =
                await fetch(
                    requestedUrl.toString(),
                    {
                        method: 'GET',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'text/html'
                        },
                        cache: 'no-store'
                    }
                );

            if (!response.ok) {
                throw new Error(
                    'Server returned HTTP ' + response.status
                );
            }

            const html =
                await response.text();

            if (requestNumber !== cancelledRequestId) {
                return;
            }

            const parsedDocument =
                new DOMParser().parseFromString(
                    html,
                    'text/html'
                );

            const newCancelledSection =
                parsedDocument.querySelector(
                    'details.cancelled-orders-section:not(.pending-refunds-section)'
                );

            if (!newCancelledSection) {
                throw new Error(
                    'Cancelled Orders section was not found.'
                );
            }

            /* Drop detached modals from the previous result page before replacing its section. */
            document.querySelectorAll('[data-cancelled-order-modal-detached="true"]').forEach(function (modalElement) {
                if (window.bootstrap && window.bootstrap.Modal) {
                    const instance = bootstrap.Modal.getInstance(modalElement);
                    if (instance) {
                        instance.dispose();
                    }
                }
                modalElement.remove();
            });

            cancelledSection.replaceWith(
                newCancelledSection
            );

            if (pushHistory) {
                const cleanUrl =
                    new URL(
                        requestedUrl.toString()
                    );

                window.history.pushState(
                    {
                        staffOrdersCancelledAjax: true
                    },
                    '',
                    cleanUrl.pathname +
                    (
                        cleanUrl.search
                            ? cleanUrl.search
                            : ''
                    ) +
                    cleanUrl.hash
                );
            }

        } catch (error) {

            console.error(
                'Staff Cancelled Orders AJAX error:',
                error
            );

            alert(
                error.message ||
                'Unable to load cancelled orders.'
            );

        } finally {

            const restoredSection =
                document.querySelector(
                    'details.cancelled-orders-section:not(.pending-refunds-section)'
                );

            if (restoredSection) {
                restoredSection.removeAttribute(
                    'aria-busy'
                );

                restoredSection.style.opacity = '';
                restoredSection.style.pointerEvents = '';
            }
        }
    }

    function buildCancelledUrl(
        overrides = {}
    ) {
        const url =
            new URL(
                window.location.href
            );

        url.searchParams.set(
            'cancelled_open',
            '1'
        );

        if (!url.searchParams.get('cancelled_period')) {
            url.searchParams.set(
                'cancelled_period',
                'today'
            );
        }

        const period =
            overrides.cancelled_period ??
            url.searchParams.get('cancelled_period');

        const month =
            overrides.cancelled_month ??
            url.searchParams.get('cancelled_month') ??
            '';

        const search =
            overrides.cancelled_q ??
            url.searchParams.get('cancelled_q') ??
            '';

        const page =
            overrides.cancelled_page ??
            url.searchParams.get('cancelled_page') ??
            '1';

        const historyStatus =
            overrides.history_status ??
            url.searchParams.get('history_status') ??
            'all';

        if (historyStatus && historyStatus !== 'all') {
            url.searchParams.set('history_status', historyStatus);
        } else {
            url.searchParams.delete('history_status');
        }

        url.searchParams.set(
            'cancelled_period',
            period || 'today'
        );

        if (url.searchParams.get('cancelled_period') === 'specific_month') {
            if (month) {
                url.searchParams.set(
                    'cancelled_month',
                    month
                );
            }
        } else {
            url.searchParams.delete(
                'cancelled_month'
            );
        }

        if (search.trim() !== '') {
            url.searchParams.set(
                'cancelled_q',
                search.trim()
            );
        } else {
            url.searchParams.delete(
                'cancelled_q'
            );
        }

        url.searchParams.set(
            'cancelled_page',
            String(page || '1')
        );

        return url;
    }

    /* Cancelled section search/filter controls */
    staffOrdersContent.addEventListener(
        'change',
        function (event) {

            const historyStatusSelect =
                event.target.closest('[data-history-status]');

            if (historyStatusSelect) {
                loadCancelledOrders(
                    buildCancelledUrl({
                        history_status: historyStatusSelect.value || 'all',
                        cancelled_page: 1
                    }),
                    true
                );
                return;
            }

            const periodSelect =
                event.target.closest(
                    '[data-cancelled-period]'
                );

            if (periodSelect) {

                const period =
                    periodSelect.value || 'today';

                const monthInput =
                    document.querySelector(
                        '[data-cancelled-month]'
                    );

                if (period === 'specific_month') {

                    const monthWrap =
                        document.querySelector(
                            '[data-cancelled-month-wrap]'
                        );

                    if (monthWrap) {
                        monthWrap.style.display = '';
                    }

                    if (monthInput) {
                        monthInput.focus();
                    }

                    /*
                     * Do not request until the user has a
                     * valid specific month to check.
                     */
                    if (!monthInput || !monthInput.value) {
                        return;
                    }

                } else {

                    const monthWrap =
                        document.querySelector(
                            '[data-cancelled-month-wrap]'
                        );

                    if (monthWrap) {
                        monthWrap.style.display = 'none';
                    }
                }

                const targetUrl =
                    buildCancelledUrl({
                        cancelled_period: period,
                        cancelled_month:
                            monthInput
                                ? monthInput.value
                                : '',
                        cancelled_page: 1
                    });

                loadCancelledOrders(
                    targetUrl,
                    true
                );

                return;
            }

            const monthInput =
                event.target.closest(
                    '[data-cancelled-month]'
                );

            if (monthInput) {

                const periodSelect =
                    document.querySelector(
                        '[data-cancelled-period]'
                    );

                if (
                    periodSelect &&
                    periodSelect.value === 'specific_month' &&
                    monthInput.value
                ) {

                    const targetUrl =
                        buildCancelledUrl({
                            cancelled_period: 'specific_month',
                            cancelled_month: monthInput.value,
                            cancelled_page: 1
                        });

                    loadCancelledOrders(
                        targetUrl,
                        true
                    );
                }
            }
        }
    );

    staffOrdersContent.addEventListener(
        'submit',
        function (event) {

            const form =
                event.target.closest(
                    '[data-cancelled-filter-form]'
                );

            if (!form) {
                return;
            }

            event.preventDefault();

            const period =
                form.querySelector(
                    '[data-cancelled-period]'
                );

            const month =
                form.querySelector(
                    '[data-cancelled-month]'
                );

            const search =
                form.querySelector(
                    '[data-cancelled-search]'
                );

            const targetUrl =
                buildCancelledUrl({
                    cancelled_period:
                        period
                            ? period.value
                            : 'today',
                    cancelled_month:
                        month
                            ? month.value
                            : '',
                    cancelled_q:
                        search
                            ? search.value
                            : '',
                    history_status:
                        form.querySelector('[data-history-status]')
                            ? form.querySelector('[data-history-status]').value
                            : 'all',
                    cancelled_page: 1
                });

            loadCancelledOrders(
                targetUrl,
                true
            );
        }
    );

    /* Cancelled Orders search runs on Enter, matching Active Orders. */
    staffOrdersContent.addEventListener(
        'search',
        function (event) {
            const searchInput =
                event.target.closest('[data-cancelled-search]');

            if (!searchInput || searchInput.value.trim() !== '') {
                return;
            }

            const searchForm =
                searchInput.closest('[data-cancelled-filter-form]');

            if (searchForm && typeof searchForm.requestSubmit === 'function') {
                searchForm.requestSubmit();
            } else if (searchForm) {
                searchForm.dispatchEvent(new Event('submit', {
                    bubbles: true,
                    cancelable: true
                }));
            }
        }
    );

    /* Clear only Cancelled Orders filters. */
    staffOrdersContent.addEventListener(
        'click',
        function (event) {

            const clearLink =
                event.target.closest(
                    '.cancelled-clear-link'
                );

            if (clearLink) {

                if (
                    event.ctrlKey ||
                    event.metaKey ||
                    event.shiftKey ||
                    event.altKey ||
                    clearLink.target === '_blank'
                ) {
                    return;
                }

                event.preventDefault();

                const targetUrl =
                    new URL(
                        clearLink.href,
                        window.location.href
                    );

                targetUrl.searchParams.set(
                    'cancelled_period',
                    'today'
                );

                targetUrl.searchParams.delete(
                    'cancelled_month'
                );

                targetUrl.searchParams.delete(
                    'cancelled_q'
                );

                targetUrl.searchParams.set(
                    'cancelled_page',
                    '1'
                );

                targetUrl.searchParams.set(
                    'cancelled_open',
                    '1'
                );

                loadCancelledOrders(
                    targetUrl,
                    true
                );

                return;
            }

            const paginationLink =
                event.target.closest(
                    '.cancelled-pagination a[href]'
                );

            if (!paginationLink) {
                return;
            }

            if (
                event.ctrlKey ||
                event.metaKey ||
                event.shiftKey ||
                event.altKey ||
                paginationLink.target === '_blank'
            ) {
                return;
            }

            event.preventDefault();

            loadCancelledOrders(
                paginationLink.href,
                true
            );
        }
    );

    /*
     * Browser navigation must refresh the relevant Cancelled
     * section without reloading the complete Orders page.
     */
    window.addEventListener(
        'popstate',
        function () {

            loadCancelledOrders(
                window.location.href,
                false
            );
        }
    );

    /*
     * Keep the Specific month control visible after the initial
     * page load and after AJAX replacement.
     */
    const initialPeriod =
        document.querySelector(
            '[data-cancelled-period]'
        );

    const initialMonthWrap =
        document.querySelector(
            '[data-cancelled-month-wrap]'
        );

    if (
        initialPeriod &&
        initialMonthWrap
    ) {
        initialMonthWrap.style.display =
            initialPeriod.value === 'specific_month'
                ? ''
                : 'none';
    }

});
</script>

<script>
/* =========================================================
   VIEW ORDER TARGET
   Focus only the exact active order after the page or the
   Active Orders section is rendered/replaced by AJAX.
========================================================= */
(function () {

    function getTargetOrderId() {

        const url =
            new URL(window.location.href);

        const targetId =
            parseInt(
                url.searchParams.get('order_id') || '0',
                10
            );

        return Number.isFinite(targetId) && targetId > 0
            ? targetId
            : 0;
    }

    function clearViewOrderTargetFromUrl() {

        const url =
            new URL(window.location.href);

        if (
            !url.searchParams.has('order_id') &&
            !url.searchParams.has('notification_id')
        ) {
            return;
        }

        url.searchParams.delete('order_id');
        url.searchParams.delete('notification_id');

        window.history.replaceState(
            window.history.state,
            document.title,
            url.pathname +
            (url.search ? url.search : '') +
            url.hash
        );
    }

    window.LocaliteaFocusViewOrderTarget =
        function (targetId, removeUrlTarget = false) {

            const safeTargetId =
                Number(targetId || 0);

            if (
                !Number.isFinite(safeTargetId) ||
                safeTargetId <= 0
            ) {
                return false;
            }

            const activeSection =
                document.querySelector(
                    '.active-orders-section'
                );

            if (!activeSection) {
                return false;
            }

            const targetCard =
                activeSection.querySelector(
                    '#order-' + safeTargetId
                );

            /* Verify the exact order ID before styling it. */
            if (
                !targetCard ||
                String(
                    targetCard.dataset.orderId || ''
                ) !== String(safeTargetId)
            ) {
                return false;
            }

            targetCard.classList.add(
                'view-order-target'
            );

            requestAnimationFrame(function () {

                setTimeout(function () {

                    const currentCard =
                        document.querySelector(
                            '.active-orders-section #order-' +
                            safeTargetId
                        );

                    if (
                        !currentCard ||
                        String(
                            currentCard.dataset.orderId || ''
                        ) !== String(safeTargetId)
                    ) {
                        return;
                    }

                    currentCard.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center',
                        inline: 'nearest'
                    });

                }, 80);

            });

            if (removeUrlTarget) {
                clearViewOrderTargetFromUrl();
            }

            return true;
        };

    function initializeViewOrderTarget() {

        const targetId =
            getTargetOrderId();

        if (targetId <= 0) {
            return;
        }

        const focused =
            window.LocaliteaFocusViewOrderTarget(
                targetId,
                true
            );

        /* Clear stale navigation state if the exact target is absent. */
        if (!focused) {
            clearViewOrderTargetFromUrl();
        }
    }

    if (document.readyState === 'loading') {

        document.addEventListener(
            'DOMContentLoaded',
            initializeViewOrderTarget,
            { once: true }
        );

    } else {

        initializeViewOrderTarget();

    }

})();
</script>

<script>
/*
 * Staff cancellation reason dropdown.
 * The options menu is temporarily moved to the modal root and positioned
 * against the viewport. This prevents .modal-body / .modal-content overflow
 * from clipping the list while keeping the menu inside the Bootstrap modal
 * for its focus trap.
 */
document.addEventListener('DOMContentLoaded', function () {
    const reasonInput = document.getElementById('staffCancelReason');
    const dropdown = document.getElementById('staffCancelReasonDropdown');
    const toggle = document.getElementById('staffCancelReasonToggle');
    const label = document.getElementById('staffCancelReasonLabel');
    const menu = document.getElementById('staffCancelReasonMenu');
    const otherWrap = document.getElementById('staffOtherCancellationReasonWrap');
    const otherInput = document.getElementById('staffOtherCancellationReason');
    const form = document.getElementById('staffCancelOrderForm');
    const modal = document.getElementById('staffCancelOrderModal');
    const orderStatusInput = document.getElementById('staffCancelOrderStatus');
    const orderPaymentMethodInput = document.getElementById('staffCancelOrderPaymentMethod');

    if (!reasonInput || !dropdown || !toggle || !label || !menu || !otherWrap || !otherInput || !modal) {
        return;
    }

    const placeholder = 'Select a cancellation reason';
    const menuHome = document.createComment('staff cancellation reason menu home');
    menu.parentNode.insertBefore(menuHome, menu);
    let menuIsOpen = false;

    function positionMenu() {
        if (!menuIsOpen) return;

        const rect = toggle.getBoundingClientRect();
        const viewportHeight = window.visualViewport
            ? window.visualViewport.height
            : window.innerHeight;
        const viewportWidth = window.visualViewport
            ? window.visualViewport.width
            : window.innerWidth;

        const left = Math.max(8, Math.min(rect.left, viewportWidth - rect.width - 8));
        const width = Math.max(160, Math.min(rect.width, viewportWidth - 16));
        const availableBelow = Math.max(56, viewportHeight - rect.bottom - 12);
        const maxHeight = Math.min(360, availableBelow);

        menu.style.position = 'fixed';
        menu.style.top = `${Math.round(rect.bottom + 5)}px`;
        menu.style.left = `${Math.round(left)}px`;
        menu.style.right = 'auto';
        menu.style.width = `${Math.round(width)}px`;
        menu.style.maxHeight = `${Math.floor(maxHeight)}px`;
        menu.style.zIndex = '2000';
    }

    function closeMenu() {
        menu.hidden = true;
        menuIsOpen = false;
        toggle.setAttribute('aria-expanded', 'false');

        menu.style.removeProperty('position');
        menu.style.removeProperty('top');
        menu.style.removeProperty('left');
        menu.style.removeProperty('right');
        menu.style.removeProperty('width');
        menu.style.removeProperty('max-height');
        menu.style.removeProperty('z-index');

        // Restore the menu to its original position in the dropdown.
        if (menuHome.parentNode && menu.parentNode !== menuHome.parentNode) {
            menuHome.parentNode.insertBefore(menu, menuHome.nextSibling);
        }
    }

    function openMenu() {
        if (menuIsOpen) {
            positionMenu();
            return;
        }

        // Move outside .modal-body so its scroll/overflow cannot crop the list.
        // Keeping it inside the modal root preserves Bootstrap's focus trap.
        modal.appendChild(menu);
        menu.hidden = false;
        menuIsOpen = true;
        toggle.setAttribute('aria-expanded', 'true');
        positionMenu();
    }

    function syncReasonChoicesForOrder() {
        const isReadyOrder = (
            orderStatusInput
            && orderStatusInput.value === 'ready'
        );

        menu.querySelectorAll('[data-standard-reason]').forEach(option => {
            option.hidden = isReadyOrder;
        });
        menu.querySelectorAll('[data-standard-reason-group]').forEach(group => {
            group.hidden = isReadyOrder;
        });
        menu.querySelectorAll('[data-no-show-reason], [data-no-show-reason-group]').forEach(element => {
            element.hidden = !isReadyOrder;
        });

        const selectedOption = Array.from(menu.querySelectorAll('[data-value]'))
            .find(option => option.getAttribute('data-value') === reasonInput.value);

        if (selectedOption && selectedOption.hidden) {
            reasonInput.value = '';
        }
    }

    function syncSelectedReason() {
        syncReasonChoicesForOrder();

        const selected = Array.from(menu.querySelectorAll('[data-value]:not([hidden])'))
            .find(option => option.getAttribute('data-value') === reasonInput.value);

        label.textContent = selected ? selected.textContent.trim() : placeholder;

        menu.querySelectorAll('[data-value]').forEach(option => {
            const isSelected = option === selected;
            option.setAttribute('aria-selected', isSelected ? 'true' : 'false');
            option.classList.toggle('is-selected', isSelected);
        });

        const isOther = reasonInput.value === '__other__';
        otherWrap.style.display = isOther ? '' : 'none';
        otherInput.required = isOther;

        if (!isOther) {
            otherInput.value = '';
        }
    }

    toggle.addEventListener('click', function () {
        if (menuIsOpen) {
            closeMenu();
        } else {
            openMenu();
        }
    });

    menu.addEventListener('click', function (event) {
        const option = event.target.closest('[data-value]');
        if (!option) return;

        reasonInput.value = option.getAttribute('data-value') || '';
        reasonInput.dispatchEvent(new Event('change', { bubbles: true }));
        closeMenu();
        toggle.focus();
    });

    document.addEventListener('click', function (event) {
        if (menuIsOpen && !dropdown.contains(event.target) && !menu.contains(event.target)) {
            closeMenu();
        }
    });

    toggle.addEventListener('keydown', function (event) {
        if (event.key === 'ArrowDown' || event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            openMenu();
            const firstOption = menu.querySelector('[data-value]:not([hidden])');
            if (firstOption) firstOption.focus();
        } else if (event.key === 'Escape') {
            closeMenu();
        }
    });

    menu.addEventListener('keydown', function (event) {
        const options = Array.from(menu.querySelectorAll('[data-value]:not([hidden])'));
        const currentIndex = options.indexOf(document.activeElement);

        if (event.key === 'Escape') {
            event.preventDefault();
            closeMenu();
            toggle.focus();
            return;
        }

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const step = event.key === 'ArrowDown' ? 1 : -1;
            const nextIndex = Math.max(0, Math.min(options.length - 1, currentIndex + step));
            if (options[nextIndex]) options[nextIndex].focus();
        }
    });

    reasonInput.addEventListener('change', syncSelectedReason);
    window.addEventListener('resize', positionMenu);
    window.addEventListener('scroll', positionMenu, true);

    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', positionMenu);
        window.visualViewport.addEventListener('scroll', positionMenu);
    }

    modal.addEventListener('hide.bs.modal', closeMenu);

    if (form) {
        form.addEventListener('submit', function (event) {
            if (!reasonInput.value.trim()) {
                event.preventDefault();
                openMenu();
                toggle.focus();
                return;
            }

            if (reasonInput.value === '__other__' && otherInput.value.trim() === '') {
                event.preventDefault();
                otherInput.focus();
                otherInput.reportValidity();
            }
        });
    }

    syncSelectedReason();
    closeMenu();
});
</script>

<script>
/*
 * Enable No-show controls when their server-calculated deadline arrives.
 * The endpoint rechecks eligibility under a database row lock, so this
 * client-side timer is only a usability improvement, never authorization.
 */
(function () {
    function updateNoShowActionButtons() {
        const nowSeconds = Date.now() / 1000;

        document.querySelectorAll('button[data-no-show-only="1"][data-no-show-eligible-at]').forEach(function (button) {
            const eligibleAt = Number(button.getAttribute('data-no-show-eligible-at') || 0);
            const eligible = eligibleAt > 0 && nowSeconds >= eligibleAt;

            button.disabled = !eligible;
            button.setAttribute('aria-disabled', eligible ? 'false' : 'true');
            button.title = eligible
                ? 'The pickup time and collection grace period have passed. Confirm that the customer did not collect the order.'
                : 'Available 30 minutes after the later of the scheduled pickup time and the time the order was marked Ready.';
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', updateNoShowActionButtons, { once: true });
    } else {
        updateNoShowActionButtons();
    }

    window.setInterval(updateNoShowActionButtons, 15000);
    window.addEventListener('focus', updateNoShowActionButtons);
})();
</script>



<style id="staff-order-action-hover-fix">
/* =========================================================
   KEEP STAFF ORDER ACTION BUTTONS COLORED ON HOVER/FOCUS
   Bootstrap's default .btn:hover styles can override custom
   button backgrounds. Keep each workflow button consistent.
========================================================= */
.order-detail-actions .btn-confirm:hover,
.order-detail-actions .btn-confirm:focus,
.order-detail-actions .btn-confirm:focus-visible,
.order-detail-actions .btn-confirm:active {
    background-color: #DCEEFF !important;
    border-color: #7FA9D0 !important;
    color: #286090 !important;
    filter: brightness(.97);
}

.order-detail-actions .btn-preparing:hover,
.order-detail-actions .btn-preparing:focus,
.order-detail-actions .btn-preparing:focus-visible,
.order-detail-actions .btn-preparing:active {
    background-color: #EEE0FF !important;
    border-color: #AA88C9 !important;
    color: #7040A0 !important;
    filter: brightness(.97);
}

.order-detail-actions .btn-ready:hover,
.order-detail-actions .btn-ready:focus,
.order-detail-actions .btn-ready:focus-visible,
.order-detail-actions .btn-ready:active {
    background-color: #DFF4E3 !important;
    border-color: #83B88E !important;
    color: #28763B !important;
    filter: brightness(.97);
}

.order-detail-actions .btn-complete:hover,
.order-detail-actions .btn-complete:focus,
.order-detail-actions .btn-complete:focus-visible,
.order-detail-actions .btn-complete:active {
    background-color: #4B2E1E !important;
    border-color: #392217 !important;
    color: #FFFFFF !important;
}

.order-detail-actions .btn-cancel:hover,
.order-detail-actions .btn-cancel:focus,
.order-detail-actions .btn-cancel:focus-visible,
.order-detail-actions .btn-cancel:active {
    background-color: #FCE3E3 !important;
    border-color: #D89A9A !important;
    color: #A33A3A !important;
    filter: brightness(.97);
}

/* The Order Process status/timeline elements should also keep their
   intended appearance when the pointer passes over them. */
.order-detail-process .timeline-step:hover,
.order-detail-process .timeline-step:focus-within {
    background: transparent !important;
}

.order-detail-process .timeline-circle:hover {
    background: inherit;
}
</style>
<style id="staff-order-table-action-polish">
    /* Compact actions on desktop/tablet; phone cards keep full-width tap targets. */
    @media (min-width: 576px) {
        .staff-orders-table .order-table-next-action-form { width: auto; }
        .staff-orders-table .btn-table-next-action {
            width: auto;
            min-width: 0;
            max-width: 164px;
            min-height: 36px;
            padding: 7px 10px;
            white-space: nowrap;
        }
        .staff-orders-table .btn-view-details {
            width: auto;
            min-width: 112px;
            min-height: 36px;
            padding: 6px 10px;
            white-space: nowrap;
        }
        .staff-orders-table .order-table-next-action-cell,
        .staff-orders-table .order-table-details-cell {
            text-align: center !important;
        }
    }

    .staff-orders-table .btn-table-next-action.btn-pending-action {
        background: #FFF1D6 !important; border-color: #D8B56A !important; color: #8A5A00 !important;
    }
    .staff-orders-table .btn-table-next-action.btn-confirm {
        background: #DCEEFF !important; border-color: #7FA9D0 !important; color: #286090 !important;
    }
    .staff-orders-table .btn-table-next-action.btn-preparing {
        background: #EEE0FF !important; border-color: #AA88C9 !important; color: #7040A0 !important;
    }
    .staff-orders-table .btn-table-next-action.btn-ready {
        background: #DFF4E3 !important; border-color: #83B88E !important; color: #28763B !important;
    }
    .staff-orders-table .btn-table-next-action.btn-complete {
        background: #23733D !important; border-color: #1E6535 !important; color: #FFFFFF !important;
    }
    .staff-orders-table .btn-table-next-action.btn-pending-action:hover { background: #F9E5B7 !important; }
    .staff-orders-table .btn-table-next-action.btn-confirm:hover { background: #C7E2FB !important; }
    .staff-orders-table .btn-table-next-action.btn-preparing:hover { background: #E1D0FA !important; }
    .staff-orders-table .btn-table-next-action.btn-ready:hover { background: #C8EBD0 !important; }
    .staff-orders-table .btn-table-next-action.btn-complete:hover { background: #1D6535 !important; }

    /* Align the action headings with the centered controls beneath them.
       Keep phone-card labels left-aligned when the real table header is hidden. */
    @media (min-width: 576px) {
        .staff-orders-table thead th:nth-child(9),
        .staff-orders-table thead th:nth-child(10) {
            text-align: center !important;
            vertical-align: middle;
        }

        .staff-orders-table tbody td.order-table-next-action-cell,
        .staff-orders-table tbody td.order-table-details-cell {
            text-align: center !important;
            vertical-align: middle;
        }

        .staff-orders-table .order-table-next-action-form {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            margin: 0;
        }

        .staff-orders-table .btn-table-next-action,
        .staff-orders-table .btn-view-details {
            margin-left: auto;
            margin-right: auto;
        }
    }

</style>

<style id="staff-order-table-proportion-tune">
    /*
     * Final table proportions: dedicate less horizontal space to the two
     * action columns and return it to the order/customer/pick-up details.
     * Keep touch-friendly full-width actions in the existing phone-card view.
     */
    @media (min-width: 1400px) {
        .staff-orders-table th:nth-child(1), .staff-orders-table td:nth-child(1) { width: 14%; }
        .staff-orders-table th:nth-child(2), .staff-orders-table td:nth-child(2) { width: 8%; }
        .staff-orders-table th:nth-child(3), .staff-orders-table td:nth-child(3) { width: 14%; }
        .staff-orders-table th:nth-child(4), .staff-orders-table td:nth-child(4) { width: 11%; }
        .staff-orders-table th:nth-child(5), .staff-orders-table td:nth-child(5) { width: 7%; }
        .staff-orders-table th:nth-child(6), .staff-orders-table td:nth-child(6) { width: 8%; }
        .staff-orders-table th:nth-child(7), .staff-orders-table td:nth-child(7) { width: 7%; }
        .staff-orders-table th:nth-child(8), .staff-orders-table td:nth-child(8) { width: 11%; }
        .staff-orders-table th:nth-child(9), .staff-orders-table td:nth-child(9) { width: 11%; }
        .staff-orders-table th:nth-child(10), .staff-orders-table td:nth-child(10) { width: 9%; }

        .staff-orders-table thead th { padding: 10px 8px; }
        .staff-orders-table tbody td { padding: 8px 8px; }
        .staff-orders-table .order-table-date { line-height: 1.2; }
    }

    /* Tablet and compact-laptop tables scroll within their own container. */
    @media (min-width: 576px) and (max-width: 1399.98px) {
        .staff-orders-table th:nth-child(1), .staff-orders-table td:nth-child(1) { width: 13%; }
        .staff-orders-table th:nth-child(2), .staff-orders-table td:nth-child(2) { width: 8%; }
        .staff-orders-table th:nth-child(3), .staff-orders-table td:nth-child(3) { width: 14%; }
        .staff-orders-table th:nth-child(4), .staff-orders-table td:nth-child(4) { width: 10%; }
        .staff-orders-table th:nth-child(5), .staff-orders-table td:nth-child(5) { width: 7%; }
        .staff-orders-table th:nth-child(6), .staff-orders-table td:nth-child(6) { width: 8%; }
        .staff-orders-table th:nth-child(7), .staff-orders-table td:nth-child(7) { width: 7%; }
        .staff-orders-table th:nth-child(8), .staff-orders-table td:nth-child(8) { width: 11%; }
        .staff-orders-table th:nth-child(9), .staff-orders-table td:nth-child(9) { width: 12%; }
        .staff-orders-table th:nth-child(10), .staff-orders-table td:nth-child(10) { width: 10%; }

        .staff-orders-table thead th { padding: 9px 6px; }
        .staff-orders-table tbody td { padding: 8px 6px; }
    }

    /* Compact natural-width buttons and tighten the gap between the controls. */
    @media (min-width: 576px) {
        .staff-orders-table .btn-table-next-action {
            max-width: 145px;
            min-height: 34px;
            padding: 6px 7px;
            gap: 4px;
            font-size: .68rem;
        }

        .staff-orders-table .btn-view-details {
            min-width: 100px;
            min-height: 34px;
            padding: 6px 7px;
            font-size: .68rem;
        }

        .staff-orders-table thead th:nth-child(9),
        .staff-orders-table thead th:nth-child(10),
        .staff-orders-table tbody td.order-table-next-action-cell,
        .staff-orders-table tbody td.order-table-details-cell {
            text-align: center !important;
        }
    }

    /* In a single selected workflow, Status is already clear from the
       highlighted workflow card. Reclaim that space for the remaining columns. */
    .staff-orders-table.without-status-column {
        min-width: 980px;
    }

    @media (min-width: 1400px) {
        .staff-orders-table.without-status-column th:nth-child(1),
        .staff-orders-table.without-status-column td:nth-child(1) { width: 15%; }
        .staff-orders-table.without-status-column th:nth-child(2),
        .staff-orders-table.without-status-column td:nth-child(2) { width: 8%; }
        .staff-orders-table.without-status-column th:nth-child(3),
        .staff-orders-table.without-status-column td:nth-child(3) { width: 15%; }
        .staff-orders-table.without-status-column th:nth-child(4),
        .staff-orders-table.without-status-column td:nth-child(4) { width: 11%; }
        .staff-orders-table.without-status-column th:nth-child(5),
        .staff-orders-table.without-status-column td:nth-child(5) { width: 8%; }
        .staff-orders-table.without-status-column th:nth-child(6),
        .staff-orders-table.without-status-column td:nth-child(6) { width: 8%; }
        .staff-orders-table.without-status-column th:nth-child(7),
        .staff-orders-table.without-status-column td:nth-child(7) { width: 7%; }
        .staff-orders-table.without-status-column th:nth-child(8),
        .staff-orders-table.without-status-column td:nth-child(8) { width: 14%; }
        .staff-orders-table.without-status-column th:nth-child(9),
        .staff-orders-table.without-status-column td:nth-child(9) { width: 14%; }
    }

    @media (min-width: 576px) and (max-width: 1399.98px) {
        .staff-orders-table.without-status-column { min-width: 1000px; }
        .staff-orders-table.without-status-column th:nth-child(1),
        .staff-orders-table.without-status-column td:nth-child(1) { width: 14%; }
        .staff-orders-table.without-status-column th:nth-child(2),
        .staff-orders-table.without-status-column td:nth-child(2) { width: 8%; }
        .staff-orders-table.without-status-column th:nth-child(3),
        .staff-orders-table.without-status-column td:nth-child(3) { width: 14%; }
        .staff-orders-table.without-status-column th:nth-child(4),
        .staff-orders-table.without-status-column td:nth-child(4) { width: 10%; }
        .staff-orders-table.without-status-column th:nth-child(5),
        .staff-orders-table.without-status-column td:nth-child(5) { width: 7%; }
        .staff-orders-table.without-status-column th:nth-child(6),
        .staff-orders-table.without-status-column td:nth-child(6) { width: 8%; }
        .staff-orders-table.without-status-column th:nth-child(7),
        .staff-orders-table.without-status-column td:nth-child(7) { width: 7%; }
        .staff-orders-table.without-status-column th:nth-child(8),
        .staff-orders-table.without-status-column td:nth-child(8) { width: 16%; }
        .staff-orders-table.without-status-column th:nth-child(9),
        .staff-orders-table.without-status-column td:nth-child(9) { width: 16%; }
    }

    @media (min-width: 576px) {
        .staff-orders-table.without-status-column thead th:nth-child(8),
        .staff-orders-table.without-status-column thead th:nth-child(9) {
            text-align: center !important;
        }
    }
</style>



<style id="staff-orders-mobile-no-status-fix">
/*
 * Fix the phone card layout after hiding Status in a single workflow.
 * The no-status table has 9 cells; mixed search results have 10 cells.
 * Keep their mobile labels and full-width action rows mapped separately.
 */
@media (max-width: 575.98px) {
    .staff-orders-table-wrap {
        width: 100%;
        max-width: 100%;
        overflow: visible;
    }

    .staff-orders-table,
    .staff-orders-table tbody {
        display: block;
        width: 100%;
        min-width: 0 !important;
        max-width: 100%;
        table-layout: auto;
    }

    .staff-orders-table tbody tr.order-card {
        display: grid !important;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
        gap: 10px 12px;
        margin: 0 0 12px;
        padding: 14px;
    }

    .staff-orders-table tbody tr.order-card td {
        display: block;
        width: 100%;
        max-width: 100%;
        min-width: 0;
        box-sizing: border-box;
        overflow-wrap: anywhere;
    }

    /* A clear label for the customer name/contact block. */
    .staff-orders-table tbody tr.order-card td:nth-child(3)::before {
        content: "Customer" !important;
    }

    /* Filtered workflow: Order, Claim, Customer, Pick-up, Payment,
       Discount, Total, Next Action, Details (9 cells). */
    .staff-orders-table.without-status-column tbody tr.order-card td:nth-child(1),
    .staff-orders-table.without-status-column tbody tr.order-card td:nth-child(3),
    .staff-orders-table.without-status-column tbody tr.order-card td:nth-child(8),
    .staff-orders-table.without-status-column tbody tr.order-card td:nth-child(9) {
        grid-column: 1 / -1;
    }

    .staff-orders-table.without-status-column tbody tr.order-card td:nth-child(8)::before {
        content: "Next Action" !important;
    }

    .staff-orders-table.without-status-column tbody tr.order-card td:nth-child(9)::before {
        content: "Details" !important;
    }

    /* Search results: Status remains because several statuses may be mixed
       (10 cells total). */
    .staff-orders-table.with-status-column tbody tr.order-card td:nth-child(1),
    .staff-orders-table.with-status-column tbody tr.order-card td:nth-child(3),
    .staff-orders-table.with-status-column tbody tr.order-card td:nth-child(9),
    .staff-orders-table.with-status-column tbody tr.order-card td:nth-child(10) {
        grid-column: 1 / -1;
    }

    .staff-orders-table.with-status-column tbody tr.order-card td:nth-child(8)::before {
        content: "Status" !important;
    }

    .staff-orders-table.with-status-column tbody tr.order-card td:nth-child(9)::before {
        content: "Next Action" !important;
    }

    .staff-orders-table.with-status-column tbody tr.order-card td:nth-child(10)::before {
        content: "Details" !important;
    }

    /* Keep action forms and buttons inside the card at every phone width. */
    .staff-orders-table tbody tr.order-card td.order-table-next-action-cell,
    .staff-orders-table tbody tr.order-card td.order-table-actions-cell,
    .staff-orders-table tbody tr.order-card td.order-table-details-cell {
        grid-column: 1 / -1;
        width: 100%;
        min-width: 0;
        max-width: 100%;
    }

    .staff-orders-table .order-table-next-action-form {
        display: block;
        width: 100%;
        max-width: 100%;
        min-width: 0;
        margin: 0;
    }

    .staff-orders-table .btn-table-next-action,
    .staff-orders-table .btn-view-details {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        min-width: 0;
        max-width: 100%;
        min-height: 44px;
        box-sizing: border-box;
        white-space: normal;
        overflow-wrap: anywhere;
    }
}

@media (max-width: 380px) {
    .staff-orders-table tbody tr.order-card {
        grid-template-columns: minmax(0, 1fr);
        gap: 10px;
        padding: 12px;
    }
}
</style>

<style id="staff-history-tables-responsive">
/* History sections use real tables like Active Orders. Keep scrolling local
   to each table on phones/tablets so the page itself never overflows. */
.history-orders-table-wrap {
    width: 100%;
    max-width: 100%;
    overflow-x: auto !important;
    overflow-y: visible;
    -webkit-overflow-scrolling: touch;
    overscroll-behavior-x: contain;
    border: 1px solid #E6DCCF;
    border-radius: 10px;
    background: #FFFFFF;
}
.history-orders-table {
    width: 100%;
    min-width: 1120px;
    table-layout: auto;
    margin: 0;
}
.history-orders-table thead th {
    position: sticky;
    top: 0;
    z-index: 1;
    padding: 12px 10px;
    background: #F3ECE5;
    color: #5A3D2B;
    border-bottom: 1px solid #D8C9BD;
    font-size: .69rem;
    font-weight: 700;
    letter-spacing: .25px;
    white-space: nowrap;
}
.history-orders-table tbody tr.history-order-row {
    display: table-row !important;
    width: auto !important;
    padding: 0 !important;
    border: 0 !important;
    border-radius: 0 !important;
    box-shadow: none !important;
    background: #FFFFFF;
}
.history-orders-table tbody tr.history-order-row:hover { background: #FDF8F2; }
.history-orders-table tbody td {
    padding: 11px 10px;
    vertical-align: middle;
    border-bottom: 1px solid #E6DCCF;
    color: #4A3525;
    font-size: .76rem;
    background: inherit;
}
.history-orders-table tbody tr:last-child td { border-bottom: 0; }
.history-orders-table .order-table-primary { white-space: normal; overflow-wrap: anywhere; font-weight: 650; }
.history-orders-table .order-table-date { white-space: normal; overflow-wrap: anywhere; line-height: 1.35; }
.history-orders-table .order-table-total { white-space: nowrap; font-weight: 700; }
.history-orders-table .status-badge { display: inline-flex; margin-top: 5px; }
.history-orders-table .history-actions-cell { min-width: 190px; }
.history-orders-table .history-details-cell { min-width: 120px; }
.history-small-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    min-height: 34px;
    margin: 3px 0;
    padding: 6px 8px;
    border-radius: 7px;
    font-size: .68rem;
    line-height: 1.2;
    white-space: normal;
}
.history-orders-table .pending-refund-btn[disabled] { opacity: .6; cursor: not-allowed; }
.history-inline-link { display: inline-block; margin-top: 4px; color: #6F4E37; font-size: .68rem; text-decoration: underline; }
.history-state-pill {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    border-radius: 7px;
    padding: 5px 8px;
    font-size: .66rem;
    font-weight: 700;
    white-space: nowrap;
}
.history-state-verified { color: #23663C; background: #E4F4E8; border: 1px solid #A8D0B2; }
.history-state-review { color: #855600; background: #FFF3D8; border: 1px solid #E5C77E; }
.history-state-rejected { color: #9B3434; background: #FCE8E8; border: 1px solid #E1ADAD; }
.history-rejection-note { max-width: 220px; margin-top: 4px; color: #9B3434; }
.history-reason-cell { min-width: 170px; max-width: 260px; white-space: normal; overflow-wrap: anywhere; color: #874040; }
.history-orders-table .btn-view-details { min-width: 0; min-height: 36px; }
.history-orders-table tbody tr.view-order-target { outline: 2px solid #C69C6D; outline-offset: -2px; background: #FFF8E9; }
.history-orders-table tbody td::before { content: none !important; display: none !important; }
.history-table-scroll-hint { color: #806E5E; font-size: .68rem; margin: 0 0 8px; }

@media (min-width: 1400px) {
    .history-orders-table { min-width: 0; }
    .cancelled-history-table { table-layout: fixed; }
    .cancelled-history-table th:nth-child(1) { width: 13%; }
    .cancelled-history-table th:nth-child(2) { width: 14%; }
    .cancelled-history-table th:nth-child(3) { width: 10%; }
    .cancelled-history-table th:nth-child(4) { width: 8%; }
    .cancelled-history-table th:nth-child(5) { width: 8%; }
    .cancelled-history-table th:nth-child(6) { width: 19%; }
    .cancelled-history-table th:nth-child(7) { width: 16%; }
    .cancelled-history-table th:nth-child(8) { width: 12%; }
    .refund-history-table { table-layout: fixed; }
    .refund-history-table th:nth-child(1) { width: 12%; }
    .refund-history-table th:nth-child(2) { width: 13%; }
    .refund-history-table th:nth-child(3) { width: 15%; }
    .refund-history-table th:nth-child(4) { width: 12%; }
    .refund-history-table th:nth-child(5) { width: 12%; }
    .refund-history-table th:nth-child(6) { width: 8%; }
    .refund-history-table th:nth-child(7) { width: 10%; }
    .refund-history-table th:nth-child(8) { width: 18%; }
}
@media (max-width: 767.98px) {
    .history-orders-table-wrap { margin-right: 0; border-radius: 8px; }
    .history-orders-table { min-width: 1060px; }
    .history-orders-table thead th { padding: 10px 8px; font-size: .64rem; }
    .history-orders-table tbody td { padding: 9px 8px; font-size: .7rem; }
    .history-orders-table .history-actions-cell { min-width: 170px; }
    .history-orders-table .history-small-button { min-height: 40px; font-size: .72rem; }
}
@media (max-width: 575.98px) {
    /* Explicitly override Active Orders' small-phone card transformation. */
    .history-orders-table-wrap { display: block !important; overflow-x: auto !important; }
    .history-orders-table { display: table !important; width: max-content !important; min-width: 1060px !important; }
    .history-orders-table thead { display: table-header-group !important; }
    .history-orders-table tbody { display: table-row-group !important; }
    .history-orders-table tr { display: table-row !important; }
    .history-orders-table th,
    .history-orders-table td { display: table-cell !important; }
    .history-orders-table tbody tr.history-order-row { grid-template-columns: none !important; }
    .cancelled-section-content { min-width: 0; }
    .pending-refunds-content { min-width: 0; }
}
@media (max-width: 380px) {
    .history-orders-table { min-width: 1000px !important; }
}


/* =========================================================
   STAFF ORDER DETAILS — SCREENSHOT-INSPIRED LAYOUT
   Applies to Active Orders View Details modals.
========================================================= */
.order-details-modal-dialog {
    width: min(820px, calc(100vw - 32px));
    max-width: 820px;
    margin: 68px auto 16px;
    max-height: calc(100vh - 84px);
}
.order-details-modal-dialog .order-modal-content {
    display: flex;
    flex-direction: column;
    max-height: calc(100vh - 84px);
    min-height: 0;
    overflow: hidden;
    border: 1px solid #D8C8B8;
    border-radius: 22px;
    background: #FFFCF9;
    box-shadow: 0 20px 55px rgba(45, 30, 20, .22);
}
.order-details-modal-dialog .order-modal-header {
    position: relative;
    display: block;
    flex: 0 0 auto;
    padding: 22px 26px 16px;
    border-bottom: 1px solid #E8DCCF;
    background: #FFFCF9;
}
.order-modal-heading { min-width: 0; padding-right: 38px; }
.order-modal-title-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    min-width: 0;
}
.order-details-modal-dialog .order-modal-title {
    margin: 0;
    color: #2C1C13 !important;
    font-size: 1.22rem !important;
    font-weight: 800 !important;
    line-height: 1.2;
}
.order-modal-status-pill {
    display: inline-flex;
    align-items: center;
    min-height: 25px;
    padding: 4px 11px;
    border-radius: 999px;
    background: #F3E6D5;
    color: #754728;
    font-size: .68rem;
    font-weight: 700;
    white-space: nowrap;
}
.order-modal-status-pill.status-pending_verification { background: #FFF0D6; color: #8A5A17; }
.order-modal-status-pill.status-order_queue,
.order-modal-status-pill.status-confirmed { background: #F3E6D5; color: #754728; }
.order-modal-status-pill.status-preparing { background: #E9E7F4; color: #5C4A8A; }
.order-modal-status-pill.status-ready { background: #E4F1E6; color: #2E6B42; }
.order-modal-status-pill.status-completed { background: #E5EFEA; color: #326B54; }
.order-details-modal-dialog .order-modal-subtitle {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    margin-top: 5px;
    color: #78695E;
    font-size: .76rem;
    line-height: 1.45;
}
.order-modal-close {
    position: absolute;
    top: 22px;
    right: 24px;
    margin: 0 !important;
}
.order-modal-progress {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 8px;
    margin-top: 17px;
}
.order-modal-progress-step {
    position: relative;
    display: flex;
    align-items: center;
    gap: 7px;
    min-width: 0;
    color: #8B7B70;
    font-size: .72rem;
    line-height: 1.2;
}
.order-modal-progress-step:not(:last-child)::after {
    content: "";
    position: absolute;
    z-index: 0;
    top: 50%;
    left: 27px;
    right: -5px;
    height: 2px;
    background: #E4D4C3;
    transform: translateY(-50%);
}
.order-modal-progress-marker {
    position: relative;
    z-index: 1;
    display: inline-flex;
    flex: 0 0 21px;
    width: 21px;
    height: 21px;
    align-items: center;
    justify-content: center;
    border: 2px solid #D7C2AD;
    border-radius: 50%;
    background: #FFFCF9;
    color: #79563D;
    font-size: .65rem;
    font-weight: 700;
}
.order-modal-progress-label {
    position: relative;
    z-index: 1;
    padding-right: 5px;
    background: #FFFCF9;
    white-space: nowrap;
}
.order-modal-progress-step.is-complete,
.order-modal-progress-step.is-current { color: #4A3525; font-weight: 700; }
.order-modal-progress-step.is-complete .order-modal-progress-marker,
.order-modal-progress-step.is-current .order-modal-progress-marker {
    border-color: #87512F;
    background: #87512F;
    color: #FFF;
}
.order-modal-pending-note {
    margin-top: 12px;
    color: #8A641F;
    font-size: .74rem;
}
.order-details-modal-dialog .order-modal-body {
    flex: 1 1 auto;
    min-height: 0;
    padding: 20px 26px 22px !important;
    overflow-y: auto;
    background: #FFFCF9;
}
.order-modal-layout {
    display: grid;
    grid-template-columns: minmax(0, 1.35fr) minmax(245px, .95fr);
    align-items: start;
    gap: 18px;
}
.order-modal-items-column,
.order-modal-side-column { min-width: 0; }
.order-modal-section-heading {
    margin: 0 0 10px;
    color: #6D5948;
    font-size: .68rem;
    font-weight: 800;
    letter-spacing: .55px;
    line-height: 1.3;
}
.order-modal-section-heading span { padding: 0 2px; color: #A58C77; }
.order-modal-item-list { display: grid; gap: 8px; }
.order-modal-item {
    display: grid;
    grid-template-columns: 39px minmax(0, 1fr) minmax(75px, auto);
    align-items: center;
    gap: 10px;
    min-width: 0;
    padding: 10px 11px;
    border: 1px solid #E8D9C9;
    border-radius: 12px;
    background: #FFFCF9;
}
.order-modal-item-quantity {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 38px;
    min-height: 38px;
    border-radius: 10px;
    background: #F8E5B8;
    color: #76512E;
    font-size: .78rem;
    font-weight: 800;
}
.order-modal-item-main { min-width: 0; }
.order-modal-item-name {
    overflow-wrap: anywhere;
    color: #261A13;
    font-size: .86rem;
    font-weight: 700;
    line-height: 1.25;
}
.order-modal-item-tags,
.order-modal-item-addons {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    margin-top: 4px;
}
.order-modal-item-tags span,
.order-modal-item-addons span {
    display: inline-flex;
    max-width: 100%;
    padding: 3px 7px;
    border-radius: 6px;
    background: #F3E7D9;
    color: #75553D;
    font-size: .62rem;
    line-height: 1.2;
    overflow-wrap: anywhere;
}
.order-modal-item-addons span { background: #F7F1E8; color: #776A5F; }
.order-modal-item-price {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    min-width: 0;
    text-align: right;
    white-space: nowrap;
}
.order-modal-item-price strong { color: #241811; font-size: .83rem; font-weight: 800; }
.order-modal-item-price small { color: #77695F; font-size: .64rem; }
.order-modal-empty-items {
    padding: 14px;
    border: 1px dashed #D8C8B8;
    border-radius: 12px;
    color: #8A7A6E;
    font-size: .82rem;
}
.order-modal-total-card {
    margin-top: 14px;
    padding: 16px 17px;
    border: 1px solid #372318;
    border-radius: 14px;
    background: #2F1E14;
    color: #FFF8F0;
}
.order-modal-total-line {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 7px;
    color: #F4E8DD;
    font-size: .76rem;
}
.order-modal-total-line span { min-width: 0; overflow-wrap: anywhere; }
.order-modal-total-line strong { flex: 0 0 auto; color: #FFF8F0; font-weight: 600; white-space: nowrap; }
.order-modal-total-divider { height: 1px; margin: 12px 0; background: rgba(255,255,255,.2); }
.order-modal-total-final {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 12px;
}
.order-modal-total-final > div { display: flex; flex-direction: column; min-width: 0; }
.order-modal-total-final strong { color: #FFF; font-size: .78rem; }
.order-modal-total-final small { margin-top: 3px; color: #E0CFC1; font-size: .63rem; }
.order-modal-total-final > span {
    flex: 0 0 auto;
    color: #FFF;
    font-size: 1.45rem;
    font-weight: 800;
    line-height: 1.1;
    white-space: nowrap;
}
.order-modal-side-column { display: grid; gap: 14px; }
.order-modal-info-section { min-width: 0; }
.order-modal-info-section .order-modal-section-heading { margin-bottom: 10px; }
.order-modal-info-card {
    min-width: 0;
    padding: 14px 15px;
    border: 1px solid #E8D9C9;
    border-radius: 13px;
    background: #FFFCF9;
}
.order-modal-customer-name { color: #291B13; font-size: .9rem; font-weight: 700; overflow-wrap: anywhere; }
.order-modal-contact {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 8px;
    color: #805638;
    font-size: .78rem;
    overflow-wrap: anywhere;
}
.order-modal-contact i { flex: 0 0 auto; }
.order-modal-pickup-card,
.order-modal-payment-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.order-modal-pickup-card > div { display: flex; flex-direction: column; min-width: 0; }
.order-modal-pickup-card small { color: #89786B; font-size: .67rem; }
.order-modal-pickup-card strong { margin-top: 3px; color: #2D1F16; font-size: .82rem; font-weight: 700; overflow-wrap: anywhere; }
.order-modal-payment-badge {
    display: inline-flex;
    align-items: center;
    min-height: 24px;
    padding: 4px 9px;
    border-radius: 7px;
    background: #E4F0E5;
    color: #356A43;
    font-size: .65rem;
    font-weight: 800;
    white-space: nowrap;
}
.order-modal-payment-badge.is-gcash { background: #E8E6FA; color: #4B4A91; }
.order-modal-discount-short {
    color: #89786B;
    font-size: .69rem;
    text-align: right;
    overflow-wrap: anywhere;
}
.order-modal-proof-button {
    display: inline-flex;
    align-items: center;
    margin-top: 11px;
    padding: 0;
    border: 0;
    background: transparent;
    color: #805638;
    font-size: .72rem;
    font-weight: 700;
    text-decoration: underline;
    text-underline-offset: 2px;
}
.order-modal-proof-button:hover,
.order-modal-proof-button:focus-visible { color: #4A2C19; }
.order-modal-proof-preview {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-top: 12px;
    padding: 10px;
    border: 1px solid #EAD8C8;
    border-radius: 10px;
    background: #FFFEFC;
    min-width: 0;
}
.order-modal-proof-preview-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 6px;
    color: #5B4535;
    font-size: .7rem;
    font-weight: 800;
}
.order-modal-proof-pending-label {
    padding: 3px 7px;
    border-radius: 999px;
    background: #FFF1D6;
    color: #8A5A12;
    font-size: .62rem;
    font-weight: 700;
}
.order-modal-proof-image {
    display: block;
    width: 100%;
    max-width: 100%;
    max-height: 270px;
    height: auto;
    object-fit: contain;
    object-position: center;
    border: 1px solid #EFE5DB;
    border-radius: 7px;
    background: #F7F3EE;
}
.order-modal-payment-note { margin-top: 9px; color: #8A641F; font-size: .69rem; }
.order-modal-discount-card { display: flex; flex-direction: column; gap: 3px; }
.order-modal-discount-card strong { color: #3E2D20; font-size: .8rem; }
.order-modal-discount-card small { color: #867568; font-size: .69rem; }
.order-details-modal-dialog .order-modal-footer {
    display: flex;
    flex: 0 0 auto;
    justify-content: flex-end;
    padding: 13px 25px;
    border-top: 1px solid #E8DCCF;
    background: #FBF6F0;
}
.order-modal-footer-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: flex-end;
    gap: 9px;
    width: 100%;
}
.order-modal-footer-actions .btn {
    display: inline-flex;
    min-height: 42px;
    align-items: center;
    justify-content: center;
    padding: 9px 17px;
    border-radius: 9px;
    font-size: .78rem;
    font-weight: 700;
    white-space: nowrap;
}
.order-modal-cancel-button {
    border: 1px solid #B42318;
    background: #DC3545;
    color: #FFFFFF;
}
.order-modal-cancel-button:hover,
.order-modal-cancel-button:focus-visible {
    border-color: #A61B29;
    background: #B02A37;
    color: #FFFFFF;
}
.order-modal-print-button {
    border: 1px solid #242424;
    background: #FFFFFF;
    color: #242424;
}
.order-modal-print-button:hover,
.order-modal-print-button:focus-visible {
    border-color: #242424;
    background: #242424;
    color: #FFFFFF;
}

@media (max-width: 767.98px) {
    .order-details-modal-dialog {
        width: calc(100vw - 20px);
        max-width: none;
        margin: 58px auto 10px;
        max-height: calc(100vh - 68px);
    }
    .order-details-modal-dialog .order-modal-content {
        max-height: calc(100vh - 68px);
        border-radius: 17px;
    }
    .order-details-modal-dialog .order-modal-header { padding: 17px 17px 13px; }
    .order-modal-heading { padding-right: 30px; }
    .order-details-modal-dialog .order-modal-title { font-size: 1.08rem !important; }
    .order-details-modal-dialog .order-modal-subtitle { font-size: .68rem; }
    .order-modal-close { top: 17px; right: 16px; }
    .order-modal-progress { gap: 4px; margin-top: 14px; }
    .order-modal-progress-step { gap: 4px; font-size: .62rem; }
    .order-modal-progress-step:not(:last-child)::after { left: 22px; right: -4px; }
    .order-modal-progress-marker { flex-basis: 19px; width: 19px; height: 19px; font-size: .6rem; }
    .order-modal-progress-label { padding-right: 2px; }
    .order-details-modal-dialog .order-modal-body { padding: 16px !important; }
    .order-modal-proof-image { max-height: 240px; }
    .order-modal-layout { grid-template-columns: minmax(0, 1fr); gap: 18px; }
    .order-modal-side-column { grid-template-columns: minmax(0, 1fr); gap: 12px; }
    .order-details-modal-dialog .order-modal-footer { padding: 11px 15px; }
    .order-modal-footer-actions .btn { min-height: 44px; }
}
@media (max-width: 420px) {
    .order-modal-progress-label { font-size: .56rem; }
    .order-modal-item {
        grid-template-columns: 34px minmax(0, 1fr) 74px;
        gap: 7px;
        padding: 9px 8px;
    }
    .order-modal-item-quantity { width: 33px; min-height: 35px; font-size: .7rem; }
    .order-modal-item-name { font-size: .78rem; }
    .order-modal-item-price strong { font-size: .74rem; }
    .order-modal-item-price small { font-size: .59rem; }
    .order-modal-total-card { padding: 14px 13px; }
    .order-modal-total-final > span { font-size: 1.2rem; }
    .order-modal-footer-actions { gap: 7px; }
    .order-modal-footer-actions .btn { flex: 1 1 auto; padding: 9px 10px; font-size: .72rem; }
}



/* =========================================================
   ORDER DETAILS MODAL — GUARANTEED GAP BELOW FIXED STAFF NAVBAR
   Reserve space on the modal overlay itself. This avoids relying
   on dialog margins that can be affected by Bootstrap's centered
   and scrollable dialog sizing rules.
========================================================= */
.modal.show.order-details-modal {
    box-sizing: border-box !important;
    padding-top: 96px !important;
    padding-bottom: 16px !important;
    overflow-x: hidden !important;
    overflow-y: auto !important;
}

.modal.show.order-details-modal .modal-dialog.order-details-modal-dialog,
.modal.show.order-details-modal .modal-dialog.order-details-modal-dialog.modal-dialog-centered,
.modal.show.order-details-modal .modal-dialog.order-details-modal-dialog.modal-dialog-scrollable {
    width: min(820px, calc(100vw - 32px)) !important;
    max-width: 820px !important;
    height: auto !important;
    min-height: 0 !important;
    max-height: calc(100vh - 112px) !important;
    max-height: calc(100dvh - 112px) !important;
    margin: 0 auto !important;
    align-items: stretch !important;
}

.modal.show.order-details-modal .modal-dialog.order-details-modal-dialog .order-modal-content {
    display: flex !important;
    flex-direction: column !important;
    min-height: 0 !important;
    max-height: calc(100vh - 112px) !important;
    max-height: calc(100dvh - 112px) !important;
    overflow: hidden !important;
}

.modal.show.order-details-modal .modal-dialog.order-details-modal-dialog .order-modal-body {
    flex: 1 1 auto !important;
    min-height: 0 !important;
    overflow-y: auto !important;
    overscroll-behavior: contain;
}

@media (max-width: 767.98px) {
    .modal.show.order-details-modal {
        padding-top: 84px !important;
        padding-bottom: 12px !important;
    }

    .modal.show.order-details-modal .modal-dialog.order-details-modal-dialog,
    .modal.show.order-details-modal .modal-dialog.order-details-modal-dialog.modal-dialog-centered,
    .modal.show.order-details-modal .modal-dialog.order-details-modal-dialog.modal-dialog-scrollable {
        width: calc(100vw - 20px) !important;
        max-width: none !important;
        max-height: calc(100vh - 96px) !important;
        max-height: calc(100dvh - 96px) !important;
    }

    .modal.show.order-details-modal .modal-dialog.order-details-modal-dialog .order-modal-content {
        max-height: calc(100vh - 96px) !important;
        max-height: calc(100dvh - 96px) !important;
    }
}

@media (max-height: 600px) and (min-width: 768px) {
    .modal.show.order-details-modal {
        padding-top: 82px !important;
        padding-bottom: 10px !important;
    }

    .modal.show.order-details-modal .modal-dialog.order-details-modal-dialog,
    .modal.show.order-details-modal .modal-dialog.order-details-modal-dialog.modal-dialog-centered,
    .modal.show.order-details-modal .modal-dialog.order-details-modal-dialog.modal-dialog-scrollable {
        max-height: calc(100vh - 92px) !important;
    }

    .modal.show.order-details-modal .modal-dialog.order-details-modal-dialog .order-modal-content {
        max-height: calc(100vh - 92px) !important;
    }
}



/* =========================================================
   STAFF CANCEL REASON — VIEWPORT-ANCHORED MENU
   JavaScript temporarily portals the menu to the modal root and positions it
   below the toggle, outside the scroll-clipped modal body.
========================================================= */
#staffCancelReasonDropdown {
    position: relative;
    width: 100%;
}

#staffCancelReasonToggle {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    width: 100%;
    min-height: 44px;
    padding: 10px 13px;
    border: 1px solid #d7c4b1;
    border-radius: 8px;
    background-color: #fff;
    background-image: none !important;
    color: #75685d;
    text-align: left;
    font-size: .9rem;
    white-space: normal;
}

#staffCancelReasonToggle::after {
    display: none;
}

#staffCancelReasonToggle:focus,
#staffCancelReasonToggle[aria-expanded="true"] {
    border-color: #8a5938;
    box-shadow: 0 0 0 .18rem rgba(138, 89, 56, .14);
    outline: 0;
}

#staffCancelReasonToggle i {
    flex: 0 0 auto;
    color: #795238;
    transition: transform .15s ease;
}

#staffCancelReasonToggle[aria-expanded="true"] i {
    transform: rotate(180deg);
}

#staffCancelReasonMenu {
    position: fixed;
    z-index: 2000;
    max-height: 360px;
    overflow-x: hidden;
    overflow-y: auto;
    overscroll-behavior: contain;
    padding: 6px;
    border: 1px solid #ddcbb9;
    border-radius: 9px;
    background: #fffdfa;
    box-shadow: 0 10px 24px rgba(44, 29, 19, .2);
    scrollbar-width: thin;
    scrollbar-color: #c3a58d #f7f0e9;
}

#staffCancelReasonMenu[hidden],
#staffCancelReasonMenu [hidden] {
    display: none !important;
}

.staff-cancel-reason-group-label {
    padding: 9px 10px 5px;
    color: #795238;
    font-size: .7rem;
    font-weight: 800;
    letter-spacing: .045em;
    text-transform: uppercase;
}

.staff-cancel-reason-option {
    display: block;
    width: 100%;
    min-height: 36px;
    padding: 8px 10px;
    border: 0;
    border-radius: 6px;
    background: transparent;
    color: #38291f;
    text-align: left;
    font-size: .86rem;
    line-height: 1.35;
    white-space: normal;
    cursor: pointer;
}

.staff-cancel-reason-option:hover,
.staff-cancel-reason-option:focus-visible {
    background: #f4e8da;
    outline: none;
}

.staff-cancel-reason-option.is-selected {
    background: #eee0d1;
    color: #5b3925;
    font-weight: 700;
}

@media (max-width: 575.98px) {
    #staffCancelReasonMenu {
        max-height: 280px;
    }

    .staff-cancel-reason-option {
        min-height: 40px;
        padding: 9px 10px;
        font-size: .82rem;
    }
}
</style>

<style id="staff-option-one-mobile-order-cards">
/*
 * Option 1: compact order cards for phones.
 * Essential order information stays visible; Claim and Discount remain
 * available in the existing View Details modal. The workflow action is
 * the primary full-width control, with View Details as a lighter action.
 */
.order-table-mobile-header {
    display: block;
}
.order-table-mobile-claim {
    display: none;
}

/* Compact horizontal Staff refund counter, placed below Active Orders. */
.staff-refund-count-notice {
    display: flex;
    align-items: center;
    gap: 10px;
    width: min(100%, 390px);
    min-width: 0;
    margin: 18px 0 24px;
    padding: 10px 12px;
    border: 1px solid #B99B82;
    border-radius: 11px;
    background: #FFFFFF;
    box-shadow: 0 2px 8px rgba(74, 53, 37, .045);
}
.staff-refund-count-icon {
    flex: 0 0 34px;
    width: 34px;
    height: 34px;
    border-radius: 9px;
    font-size: 1rem;
}
.staff-refund-count-copy strong {
    font-size: .82rem;
    line-height: 1.2;
}
.staff-refund-count-copy p {
    margin-top: 2px;
    font-size: .7rem;
    line-height: 1.3;
}
.staff-refund-count-value {
    flex: 0 0 32px;
    min-width: 32px;
    height: 32px;
    padding: 0 6px;
    font-size: .82rem;
}

@media (max-width: 575.98px) {
    .staff-refund-count-notice {
        width: min(100%, 390px);
        margin: 14px 0 20px;
        padding: 9px 10px;
        gap: 9px;
    }

    .staff-refund-count-icon {
        flex-basis: 32px;
        width: 32px;
        height: 32px;
        font-size: .92rem;
    }

    .staff-refund-count-copy strong { font-size: .78rem; }
    .staff-refund-count-copy p { font-size: .66rem; }
    .staff-refund-count-value {
        flex-basis: 30px;
        min-width: 30px;
        height: 30px;
        font-size: .78rem;
    }

    .staff-orders-table-wrap {
        width: 100%;
        max-width: 100%;
        overflow: visible !important;
        border: 0;
        border-radius: 0;
        background: transparent;
        box-shadow: none;
    }

    .staff-orders-table,
    .staff-orders-table tbody {
        display: block !important;
        width: 100% !important;
        min-width: 0 !important;
        max-width: 100% !important;
        table-layout: auto;
    }

    .staff-orders-table thead {
        position: absolute !important;
        width: 1px !important;
        height: 1px !important;
        padding: 0 !important;
        margin: -1px !important;
        overflow: hidden !important;
        clip: rect(0, 0, 0, 0) !important;
        white-space: nowrap !important;
        border: 0 !important;
    }

    .staff-orders-table tbody tr.order-card {
        display: grid !important;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        grid-auto-flow: row;
        align-items: start;
        gap: 9px 8px;
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
        box-sizing: border-box;
        margin: 0 0 11px !important;
        padding: 12px !important;
        border: 1px solid #A98A70 !important;
        border-radius: 12px !important;
        background: #FFFFFF !important;
        box-shadow: 0 2px 9px rgba(74, 53, 37, .055) !important;
    }

    .staff-orders-table tbody tr.order-card.view-order-target {
        background: #FFF8D8 !important;
        outline: 3px solid #F1C40F;
        outline-offset: 2px;
    }

    .staff-orders-table tbody tr.order-card td {
        display: block !important;
        width: auto !important;
        max-width: 100% !important;
        min-width: 0 !important;
        box-sizing: border-box;
        padding: 0 !important;
        border: 0 !important;
        background: transparent !important;
        text-align: left !important;
        overflow-wrap: anywhere;
        word-break: normal;
        font-size: .76rem;
        line-height: 1.3;
    }

    .staff-orders-table tbody tr.order-card td::before {
        display: block;
        margin: 0 0 3px;
        color: #806F62;
        font-size: .61rem;
        font-weight: 700;
        line-height: 1.2;
        letter-spacing: .035em;
        text-transform: uppercase;
    }

    /* The order identity forms the card header. In mixed search results,
       the status badge sits at the right of the order number. */
    .staff-orders-table.without-status-column tbody tr.order-card td:nth-child(1) {
        grid-column: 1 / -1;
        padding-bottom: 8px !important;
        border-bottom: 1px solid #E8DED4 !important;
    }

    .staff-orders-table.with-status-column tbody tr.order-card td:nth-child(1) {
        grid-column: 1 / 3;
        grid-row: 1;
        padding-bottom: 8px !important;
        border-bottom: 1px solid #E8DED4 !important;
    }

    .order-table-mobile-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 7px;
        min-width: 0;
    }

    .order-table-mobile-identification {
        flex: 1 1 auto;
        min-width: 0;
    }

    .order-table-mobile-claim {
        display: inline-flex;
    }

    .staff-orders-table .order-table-primary {
        display: block;
        max-width: 100%;
        color: #38291F;
        font-size: .78rem;
        font-weight: 700;
        line-height: 1.25;
        white-space: normal !important;
        overflow-wrap: anywhere;
        text-overflow: clip;
    }

    .staff-orders-table .order-table-date {
        margin-top: 2px;
        color: #88796D;
        font-size: .65rem;
        line-height: 1.25;
        white-space: normal !important;
        overflow-wrap: anywhere;
    }

    .order-table-mobile-claim {
        display: inline-flex;
        flex: 0 0 auto;
        align-items: center;
        gap: 3px;
        max-width: 46%;
        margin-top: 1px;
        padding: 4px 6px;
        border: 1px solid #D5C1AF;
        border-radius: 6px;
        background: #FBF7F2;
        color: #6F4E37;
        font-size: .62rem;
        font-weight: 700;
        line-height: 1.2;
        overflow-wrap: anywhere;
    }

    .order-table-mobile-claim-label {
        color: #8B796A;
        font-weight: 600;
    }

    /* Claim and discount remain available in View Details; avoid repeating
       them on the compact card. */
    .staff-orders-table tbody tr.order-card td:nth-child(2),
    .staff-orders-table tbody tr.order-card td:nth-child(6) {
        display: none !important;
    }

    /* Customer spans the card width. */
    .staff-orders-table tbody tr.order-card td:nth-child(3) {
        grid-column: 1 / -1;
    }
    .staff-orders-table tbody tr.order-card td:nth-child(3)::before {
        content: "Customer";
    }
    .staff-orders-table tbody tr.order-card td:nth-child(3) .order-table-date {
        font-size: .68rem;
    }

    /* Pickup, payment, and total form one compact information row. */
    .staff-orders-table tbody tr.order-card td:nth-child(4) {
        grid-column: 1;
    }
    .staff-orders-table tbody tr.order-card td:nth-child(4)::before {
        content: "Pick-up";
    }
    .staff-orders-table tbody tr.order-card td:nth-child(5) {
        grid-column: 2;
    }
    .staff-orders-table tbody tr.order-card td:nth-child(5)::before {
        content: "Payment";
    }
    .staff-orders-table tbody tr.order-card td:nth-child(7) {
        grid-column: 3;
    }
    .staff-orders-table tbody tr.order-card td:nth-child(7)::before {
        content: "Total";
    }

    .staff-orders-table tbody tr.order-card td:nth-child(4) .order-table-primary {
        font-size: .7rem;
        line-height: 1.25;
    }
    .staff-orders-table tbody tr.order-card td:nth-child(4) .order-table-date {
        font-size: .64rem;
    }
    .staff-orders-table .order-table-payment {
        min-height: 24px;
        padding: 4px 6px;
        border-radius: 6px;
        font-size: .62rem;
        line-height: 1.2;
    }
    .staff-orders-table .order-table-total {
        color: #38291F;
        font-size: .72rem;
        font-weight: 750;
        white-space: normal;
        overflow-wrap: anywhere;
    }

    /* Search results include a status cell; position it in the card header. */
    .staff-orders-table.with-status-column tbody tr.order-card td:nth-child(8) {
        grid-column: 3;
        grid-row: 1;
        justify-self: end;
        align-self: start;
        text-align: right !important;
    }
    .staff-orders-table.with-status-column tbody tr.order-card td:nth-child(8)::before {
        display: none;
        content: none;
    }
    .staff-orders-table .status-badge {
        display: inline-flex;
        max-width: 100%;
        justify-content: center;
        padding: 4px 6px;
        border-radius: 7px;
        font-size: .59rem;
        line-height: 1.15;
        white-space: normal;
        text-align: center;
    }

    /* Next Action is the primary control and always spans the full card. */
    .staff-orders-table tbody tr.order-card td.order-table-next-action-cell {
        grid-column: 1 / -1 !important;
        width: 100% !important;
        margin-top: 1px;
    }
    .staff-orders-table tbody tr.order-card td.order-table-next-action-cell::before {
        display: none;
        content: none;
    }
    .staff-orders-table .order-table-next-action-form {
        display: block !important;
        width: 100% !important;
        max-width: none !important;
        min-width: 0 !important;
        margin: 0 !important;
    }
    .staff-orders-table .btn-table-next-action {
        display: inline-flex !important;
        align-items: center;
        justify-content: center;
        width: 100% !important;
        max-width: none !important;
        min-width: 0 !important;
        min-height: 46px !important;
        padding: 10px 12px !important;
        border-radius: 9px;
        font-size: .78rem !important;
        line-height: 1.2;
        white-space: normal;
        overflow-wrap: anywhere;
    }

    /* View Details stays available, but is visually secondary. */
    .staff-orders-table tbody tr.order-card td.order-table-details-cell {
        grid-column: 1 / -1 !important;
        width: 100% !important;
        padding-top: 1px !important;
        border-top: 1px solid #EEE5DC !important;
        text-align: center !important;
    }
    .staff-orders-table tbody tr.order-card td.order-table-details-cell::before {
        display: none;
        content: none;
    }
    .staff-orders-table .btn-view-details {
        display: inline-flex !important;
        align-items: center;
        justify-content: center;
        width: 100% !important;
        max-width: none !important;
        min-width: 0 !important;
        min-height: 40px !important;
        padding: 8px 10px !important;
        border: 0 !important;
        border-radius: 7px;
        background: transparent !important;
        color: #6F4E37 !important;
        font-size: .73rem !important;
        font-weight: 650;
        text-decoration: underline;
        text-underline-offset: 2px;
        white-space: normal;
    }

    .staff-orders-table tbody tr.processing-order {
        opacity: .78;
    }
}

@media (max-width: 359.98px) {
    .staff-orders-table tbody tr.order-card {
        gap: 8px 6px;
        padding: 10px !important;
    }
    .staff-orders-table .order-table-primary { font-size: .73rem; }
    .order-table-mobile-claim { font-size: .58rem; padding: 4px 5px; }
    .staff-orders-table tbody tr.order-card td:nth-child(4) .order-table-primary,
    .staff-orders-table .order-table-total { font-size: .66rem; }
    .staff-orders-table .order-table-payment { font-size: .58rem; padding: 4px 5px; }
}

/* Order History uses the neutral coffee palette (it holds completed AND cancelled orders). */
details.order-history-section {
    border-color: #D8C8BA !important;
    border-left-color: #6F4E37 !important;
    background: #FFFFFF !important;
}
details.order-history-section > .cancelled-section-summary:hover,
details.order-history-section[open] > .cancelled-section-summary {
    background: #FBF8F5 !important;
    border-bottom-color: #E6DAD0 !important;
}
details.order-history-section .cancelled-summary-icon {
    background: #F1E8E0 !important;
    color: #6F4E37 !important;
}
details.order-history-section .cancelled-section-title {
    color: #4A3525 !important;
}
details.order-history-section .cancelled-summary-count {
    border-color: #D8C8BA !important;
    background: #F5EEE7 !important;
    color: #4A3525 !important;
}
details.order-history-section .cancelled-toggle {
    border-color: #C9B3A2 !important;
    color: #6F4E37 !important;
}
details.order-history-section select,
details.order-history-section input[type="month"],
details.order-history-section input[type="search"] {
    border-color: #C9B3A2 !important;
}

/* Compact Cancelled Orders summary, matching the Staff refund-count panel. */
.cancelled-orders-section {
    width: min(100%, 390px);
    margin: 16px 0 22px;
    transition: width .18s ease;
}

.cancelled-orders-section[open] {
    width: 100%;
    margin-top: 18px;
}

.cancelled-section-summary {
    display: flex;
    flex-wrap: nowrap !important;
    align-items: center !important;
    gap: 10px;
    min-height: 56px;
    padding: 10px 12px;
}

.cancelled-summary-icon {
    display: grid;
    place-items: center;
    flex: 0 0 34px;
    width: 34px;
    height: 34px;
    border-radius: 9px;
    background: #FCE8E8;
    color: #A33A3A;
    font-size: 1rem;
}

.cancelled-summary-copy {
    display: block;
    flex: 1 1 auto;
    min-width: 0;
}

.cancelled-section-title {
    display: block;
    margin: 0;
    color: #8E2F2F;
    font-size: .82rem;
    line-height: 1.2;
    font-weight: 700;
    letter-spacing: 0;
    text-transform: none;
}

.cancelled-section-description {
    display: block;
    margin: 2px 0 0;
    color: #806F62;
    font-size: .68rem;
    line-height: 1.25;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.cancelled-summary-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    min-width: 30px;
    height: 30px;
    padding: 0 8px;
    border: 1px solid #E4BABA;
    border-radius: 999px;
    background: #FCE3E3;
    color: #8E2F2F;
    font-size: .78rem;
    font-weight: 750;
    font-variant-numeric: tabular-nums;
}

.cancelled-toggle {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 28px;
    width: 28px;
    height: 28px;
    padding: 0;
    border: 1px solid #D89A9A;
    border-radius: 8px;
    background: #FFFFFF;
    color: #A33A3A;
}

.cancelled-toggle-label {
    display: none !important;
}

.cancelled-section-content {
    padding: 0 16px 18px;
}

.cancelled-search-field {
    flex: 0 1 280px;
    width: 280px;
    min-width: 0;
}

.cancelled-search-box {
    width: 100%;
}

.cancelled-search-box input.cancelled-filter-search {
    display: block;
    width: 100%;
    max-width: 100%;
    height: 38px;
    padding: 6px 12px 6px 34px;
    border: 1px solid #8B6F5A;
    border-radius: 8px;
    color: #4A3525;
    background: #FFFFFF;
    font-size: .82rem;
    box-sizing: border-box;
}

.cancelled-search-box input.cancelled-filter-search:focus {
    border-color: #6F4E37;
    outline: none;
    box-shadow: 0 0 0 2px rgba(111, 78, 55, .10);
}

.cancelled-filter-form .search-actions {
    display: flex;
    gap: 8px;
    margin-left: 0;
}

.cancelled-filter-form .search-actions .btn {
    height: 38px;
    padding: 7px 13px;
    border-radius: 8px;
    font-size: .82rem;
    font-weight: 500;
}

@media (max-width: 768px) {
    .cancelled-section-summary {
        align-items: center !important;
        padding: 10px 11px;
    }

    .cancelled-section-heading {
        align-items: center;
        flex-direction: row;
        margin: 0;
    }

    .cancelled-section-content {
        padding: 0 12px 14px;
    }

    .cancelled-filter-form {
        align-items: stretch;
        flex-direction: column;
    }

    .cancelled-search-field,
    .cancelled-search-box {
        width: 100%;
        max-width: 100%;
        flex: 1 1 auto;
    }

    .cancelled-filter-form .search-actions,
    .cancelled-filter-form .search-actions .btn {
        width: auto;
    }
}
</style>

<?php if ($walkinPlaced): ?>
<!-- =========================================================
     WALK-IN ORDER PLACED: shown once, right after checkout.
     Self-contained overlay (this page does not load Bootstrap JS).
========================================================= -->
<div class="walkin-placed-overlay" id="walkinPlacedModal" role="dialog" aria-modal="true" aria-labelledby="walkinPlacedTitle">
    <div class="walkin-placed-box">
        <div class="walkin-placed-icon"><i class="bi bi-check-lg" aria-hidden="true"></i></div>
        <h5 class="walkin-placed-title" id="walkinPlacedTitle">Walk-in order placed</h5>
        <p class="walkin-placed-subtitle">The order is now in Confirmed.</p>

        <div class="walkin-placed-details">
            <div><span>Order</span><strong><?= htmlspecialchars((string)($walkinPlaced['order_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong></div>
            <div><span>Claim No.</span><strong><?= htmlspecialchars((string)($walkinPlaced['claim_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong></div>
            <div><span>Total</span><strong>₱<?= number_format((float)($walkinPlaced['total'] ?? 0), 2) ?></strong></div>
            <div><span>Payment</span><strong><?= htmlspecialchars((string)($walkinPlaced['payment_method'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong></div>
            <?php if (!empty($walkinPlaced['gcash_reference'])): ?>
                <div><span>GCash Ref No.</span><strong><?= htmlspecialchars(staffFormatGcashReference((string)$walkinPlaced['gcash_reference']), ENT_QUOTES, 'UTF-8') ?></strong></div>
            <?php endif; ?>
        </div>

        <div class="walkin-placed-actions">
            <button type="button" class="walkin-placed-done" id="walkinPlacedDoneBtn">Done</button>
            <button
                type="button"
                class="walkin-placed-print"
                id="walkinPlacedPrintBtn"
                data-receipt-modal="receiptModal<?= (int)($walkinPlaced['order_id'] ?? 0) ?>"
            >
                <i class="bi bi-printer me-1" aria-hidden="true"></i> Print Receipt
            </button>
        </div>
    </div>
</div>

<style>
.walkin-placed-overlay {
    position: fixed;
    inset: 0;
    z-index: 2000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
    background: rgba(44, 34, 30, .45);
}

.walkin-placed-overlay[hidden] {
    display: none;
}

.walkin-placed-box {
    width: min(400px, 100%);
    max-height: calc(100vh - 32px);
    overflow-y: auto;
    padding: 24px;
    border-radius: 18px;
    background: #FFFFFF;
    text-align: center;
    box-shadow: 0 16px 40px rgba(44, 34, 30, .2);
    box-sizing: border-box;
}

.walkin-placed-icon {
    width: 56px;
    height: 56px;
    margin: 0 auto 12px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #E6F4EA;
    color: #2E7D4F;
    font-size: 1.6rem;
}

.walkin-placed-title {
    margin: 0 0 4px;
    color: #2C221E;
    font-weight: 800;
}

.walkin-placed-subtitle {
    margin: 0 0 16px;
    color: #7B6D62;
    font-size: .88rem;
}

.walkin-placed-details {
    border: 1px solid #E6DEC9;
    border-radius: 12px;
    padding: 10px 14px;
    text-align: left;
    background: #FDF8F2;
}

.walkin-placed-details > div {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    padding: 5px 0;
    font-size: .9rem;
}

.walkin-placed-details span {
    color: #7B6D62;
}

.walkin-placed-details strong {
    color: #2C221E;
    text-align: right;
    overflow-wrap: anywhere;
}

.walkin-placed-actions {
    display: flex;
    gap: 10px;
    margin-top: 18px;
}

.walkin-placed-done,
.walkin-placed-print {
    flex: 1 1 0;
    min-height: 46px;
    border-radius: 10px;
    font-weight: 700;
    font-size: .92rem;
    cursor: pointer;
}

.walkin-placed-done {
    border: 1px solid #B8A08A;
    background: #FFFFFF;
    color: #4A3525;
}

.walkin-placed-done:hover {
    background: #F7F1EA;
}

.walkin-placed-print {
    border: 1px solid #6F4E37;
    background: #6F4E37;
    color: #FFFFFF;
}

.walkin-placed-print:hover {
    border-color: #4A3525;
    background: #4A3525;
}

.walkin-placed-done:focus-visible,
.walkin-placed-print:focus-visible {
    outline: 3px solid #C69C6D;
    outline-offset: 2px;
}
</style>

<script>
(function () {
    const overlay = document.getElementById('walkinPlacedModal');
    if (!overlay) return;

    document.body.appendChild(overlay);
    document.body.style.overflow = 'hidden';

    function close() {
        overlay.hidden = true;
        document.body.style.overflow = '';
    }

    document.getElementById('walkinPlacedDoneBtn').addEventListener('click', close);

    document.getElementById('walkinPlacedPrintBtn').addEventListener('click', function () {
        const receiptId = this.getAttribute('data-receipt-modal');
        close();
        if (document.getElementById(receiptId) && typeof printReceipt === 'function') {
            printReceipt(receiptId);
        }
    });

    overlay.addEventListener('click', function (event) {
        if (event.target === overlay) close();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !overlay.hidden) close();
    });

    document.getElementById('walkinPlacedPrintBtn').focus({ preventScroll: true });
})();
</script>
<?php endif; ?>
