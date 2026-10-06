<?php
session_start();

require_once '../includes/db.php';
require_once '../includes/mailer.php';


$current_role = (string)($_SESSION['user_role'] ?? '');

if (
    !isset($_SESSION['user_id']) ||
    !in_array($current_role, ['admin', 'staff'], true)
) {
    header('Location: ../auth/login.php');
    exit;
}

$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$admin_id = $current_user_id;
$admin_role = $current_role;
$orders_page = $current_role === 'staff' ? 'index.php' : 'orders.php';

$isAjaxRequest =
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['ajax'] ?? '') === '1';

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

$cancelled_per_page = 10;
$cancelled_page = max(
    1,
    (int)($_GET['cancelled_page'] ?? 1)
);


$cancelled_open = ($_GET['cancelled_open'] ?? '') === '1' ? '1' : '';

$refund_search = trim((string)($_GET['refund_q'] ?? ''));

if (mb_strlen($refund_search) > 100) {
    $refund_search = mb_substr($refund_search, 0, 100);
}

$refund_status_filter = strtolower(
    trim((string)($_GET['refund_status'] ?? 'pending'))
);

$valid_refund_status_filters = [
    'pending',
    'refunded',
    'rejected'
];

if (!in_array($refund_status_filter, $valid_refund_status_filters, true)) {
    $refund_status_filter = 'pending';
}

$refund_period = strtolower(
    trim((string)($_GET['refund_period'] ?? 'today'))
);

$valid_refund_periods = [
    'today',
    'last_week',
    'last_month',
    'specific_date'
];

if (!in_array($refund_period, $valid_refund_periods, true)) {
    $refund_period = 'today';
}

$refund_date = trim(
    (string)($_GET['refund_date'] ?? date('Y-m-d'))
);

$refundDateObject = DateTime::createFromFormat(
    '!Y-m-d',
    $refund_date
);

if (
    $refundDateObject === false
    || $refundDateObject->format('Y-m-d') !== $refund_date
) {
    $refund_date = date('Y-m-d');
} elseif ($refund_date > date('Y-m-d')) {
    $refund_date = date('Y-m-d');
}

$refund_open = ($_GET['refund_open'] ?? '') === '1' ? '1' : '';

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

        $target_is_active =
            $targetOrder
            && in_array(
                $targetOrder['status'] ?? '',
                $valid_filters,
                true
            );

        if ($target_notification_id > 0) {

            $markTargetNotificationStmt =
                $pdo->prepare("
                    UPDATE notifications
                    SET is_read = 1
                    WHERE id = ?
                      AND recipient_role = ?
                      AND is_read = 0
                ");

            $markTargetNotificationStmt->execute([
                $target_notification_id,
                $current_role
            ]);
        }

        $markTargetOrderNotificationsStmt =
            $pdo->prepare("
                UPDATE notifications
                SET is_read = 1
                WHERE recipient_role = ?
                  AND reference_id = ?
                  AND is_read = 0
            ");

        $markTargetOrderNotificationsStmt->execute([
            $current_role,
            $target_order_id
        ]);


        if ($target_is_active) {

            $selected_status =
                $targetOrder['status'];

            $search = '';
            $page = 1;
        }

    } catch (Throwable $e) {

        error_log(
            'Admin target order lookup failed: '
            . $e->getMessage()
        );
    }
}


function adminOrdersRedirect(
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

    if (($GLOBALS['cancelled_open'] ?? '') === '1') {
        $params['cancelled_open'] = '1';
    }

    if (($GLOBALS['refund_search'] ?? '') !== '') {
        $params['refund_q'] = $GLOBALS['refund_search'];
    }

    if (($GLOBALS['refund_period'] ?? '') !== '') {
        $params['refund_period'] = $GLOBALS['refund_period'];
    }

    if (($GLOBALS['refund_period'] ?? '') === 'specific_date'
        && ($GLOBALS['refund_date'] ?? '') !== '') {
        $params['refund_date'] = $GLOBALS['refund_date'];
    }

    if (($GLOBALS['refund_open'] ?? '') === '1') {
        $params['refund_open'] = '1';
    }

    if ($action !== null && $action !== '') {
        $params['action'] = $action;

        if (in_array($action, ['refunded', 'refund_rejected'], true)) {
            $params['refund_open'] = '1';
        }
    }

    header('Location: ' . ($GLOBALS['orders_page'] ?? 'index.php') . '?' . http_build_query($params));
    exit;
}

/* =========================================================
   CANCEL ORDER
   Admin-only cancellation.
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
     * Replace that marker with the text entered by the Admin.
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
                    payment_screenshot
                FROM orders
                WHERE id = ?
                LIMIT 1
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
            --------------------------------------------- */

            $allowed_to_cancel = [
                'order_queue',
                'pending_verification',
                            'confirmed',
                'preparing',
                'ready'
            ];


            if (!in_array(
                $orderData['status'],
                $allowed_to_cancel,
                true
            )) {

                throw new RuntimeException(
                    'This order can no longer be cancelled.'
                );
            }


            $previous_status =
                (string)$orderData['status'];

            $refund_status = 'none';
            $refund_requested_at = null;

            /*
             * Cancelled GCash orders with uploaded payment proof are
             * placed into Pending Refunds, except when the cancellation
             * reason is "Payment could not be verified".
             *
             * A payment-verification failure means the proof was not
             * accepted as a valid payment, so it must not become a refund.
             */
            $normalizedCancellationReason = strtolower(
                trim((string)$cancellation_reason)
            );

            if (
                strtolower(trim((string)$orderData['payment_method'])) === 'gcash'
                && trim((string)$orderData['payment_screenshot']) !== ''
                && $normalizedCancellationReason !== 'payment could not be verified'
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
            ");

            $stmt->execute([
                $cancellation_reason,
                $refund_status,
                $refund_requested_at,
                $order_id
            ]);


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
                    ?,
                    ?,
                    ?,
                    NOW()
                )
            ");

            $historyStmt->execute([
                $order_id,
                $previous_status,
                $current_role,
                $current_user_id,
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
                'Admin order cancellation failed: '
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

    adminOrdersRedirect(
        $posted_status,
        $posted_search,
        $posted_page,
        $ajaxResponse['success']
            ? 'cancelled'
            : null
    );
}

/* =========================================================
   PROCESS REFUND
   Marks a pending GCash refund as refunded only after the Admin
   uploads proof of the actual refund transaction.
========================================================= */

if (
    $current_role === 'admin'
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['process_refund'])
) {

    $ajaxResponse = [
        'success' => false,
        'message' => 'The refund could not be processed.'
    ];

    $order_id = (int)($_POST['order_id'] ?? 0);
    $posted_search = trim((string)($_POST['q'] ?? ''));
    $posted_page = max(1, (int)($_POST['page'] ?? 1));
    $posted_status = trim((string)($_POST['status_filter'] ?? 'pending_verification'));

    if (!in_array($posted_status, $valid_filters, true)) {
        $posted_status = 'pending_verification';
    }

    if ($order_id > 0) {

        $uploadedRefundProof = null;

        try {
            $pdo->beginTransaction();

            $orderStmt = $pdo->prepare("
                SELECT
                    id,
                    customer_id,
                    order_number,
                    status,
                    refund_status,
                    payment_method,
                    payment_screenshot
                FROM orders
                WHERE id = ?
                LIMIT 1
            ");

            $orderStmt->execute([$order_id]);
            $orderData = $orderStmt->fetch(PDO::FETCH_ASSOC);

            if (!$orderData) {
                throw new RuntimeException('Order not found.');
            }

            if (
                $orderData['status'] !== 'cancelled'
                || $orderData['refund_status'] !== 'pending'
                || strtolower(trim((string)$orderData['payment_method'])) !== 'gcash'
                || trim((string)$orderData['payment_screenshot']) === ''
            ) {
                throw new RuntimeException(
                    'This order is not eligible for refund processing.'
                );
            }

            if (
                !isset($_FILES['refund_proof_image'])
                || $_FILES['refund_proof_image']['error'] !== UPLOAD_ERR_OK
            ) {
                throw new RuntimeException(
                    'Please upload a picture proving that the refund was sent.'
                );
            }

            $refundProofFile = $_FILES['refund_proof_image'];
            $maxRefundProofSize = 5 * 1024 * 1024;

            if ((int)$refundProofFile['size'] > $maxRefundProofSize) {
                throw new RuntimeException(
                    'The refund proof must not exceed 5MB.'
                );
            }

            $refundProofFinfo = new finfo(FILEINFO_MIME_TYPE);
            $refundProofMime = $refundProofFinfo->file(
                $refundProofFile['tmp_name']
            );

            $allowedRefundProofTypes = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png'
            ];

            if (!isset($allowedRefundProofTypes[$refundProofMime])) {
                throw new RuntimeException(
                    'Invalid refund proof. Only JPG, JPEG, or PNG files are allowed.'
                );
            }

            $refundUploadDirectory =
                dirname(__DIR__) .
                '/assets/uploads/refunds/';

            if (!is_dir($refundUploadDirectory)) {
                if (!mkdir($refundUploadDirectory, 0755, true)) {
                    throw new RuntimeException(
                        'Unable to create the refund proof upload directory.'
                    );
                }
            }

            $refundFilename =
                'refund_' .
                date('Ymd_His') .
                '_' .
                bin2hex(random_bytes(6)) .
                '.' .
                $allowedRefundProofTypes[$refundProofMime];

            $refundDestination =
                $refundUploadDirectory .
                $refundFilename;

            if (!move_uploaded_file(
                $refundProofFile['tmp_name'],
                $refundDestination
            )) {
                throw new RuntimeException(
                    'Failed to upload the refund proof.'
                );
            }

            $uploadedRefundProof =
                'assets/uploads/refunds/' .
                $refundFilename;

            $stmt = $pdo->prepare("
                UPDATE orders
                SET
                    refund_status = 'refunded',
                    refund_proof_image = ?,
                    refund_processed_at = NOW(),
                    refund_processed_by = ?
                WHERE id = ?
                  AND status = 'cancelled'
                  AND refund_status = 'pending'
            ");

            $stmt->execute([
                $uploadedRefundProof,
                $admin_id,
                $order_id
            ]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'The refund status could not be updated.'
                );
            }

            $orderIdentifier = $orderData['order_number'] ?: 'Order';

            if (!empty($orderData['customer_id']) && $notification['type'] !== null) {
                $customerMessage =
                    "Your refund for order {$orderIdentifier} has been processed. "
                    . "The refund proof is available in your Order History.";

                $notificationStmt = $pdo->prepare("
                    INSERT INTO notifications
                    (recipient_role, recipient_id, type, message, reference_id)
                    VALUES ('customer', ?, 'refund_processed', ?, ?)
                ");

                $notificationStmt->execute([
                    $orderData['customer_id'],
                    $customerMessage,
                    $order_id
                ]);
            }

            $pdo->commit();

            $ajaxResponse = [
                'success' => true,
                'order_id' => $order_id,
                'new_refund_status' => 'refunded',
                'refund_proof_image' => $uploadedRefundProof,
                'message' =>
                    "Refund for {$orderIdentifier} was marked as refunded."
            ];

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($uploadedRefundProof !== null) {
                $orphanRefundProof =
                    dirname(__DIR__) .
                    '/' .
                    $uploadedRefundProof;

                if (is_file($orphanRefundProof)) {
                    @unlink($orphanRefundProof);
                }
            }

            error_log(
                'Admin refund processing failed: ' . $e->getMessage()
            );

            $ajaxResponse = [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }

    } else {
        $ajaxResponse = [
            'success' => false,
            'message' => 'Invalid order.'
        ];
    }

    if ($isAjaxRequest) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($ajaxResponse, JSON_UNESCAPED_UNICODE);
        exit;
    }

    adminOrdersRedirect(
        $posted_status,
        $posted_search,
        $posted_page,
        $ajaxResponse['success'] ? 'refunded' : null
    );
}

/* =========================================================
   REJECT REFUND
   Marks a pending GCash refund as rejected.
========================================================= */

if (
    $current_role === 'admin'
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['reject_refund'])
) {

    $ajaxResponse = [
        'success' => false,
        'message' => 'The refund could not be rejected.'
    ];

    $order_id = (int)($_POST['order_id'] ?? 0);
    $posted_search = trim((string)($_POST['q'] ?? ''));
    $posted_page = max(1, (int)($_POST['page'] ?? 1));
    $posted_status = trim((string)($_POST['status_filter'] ?? 'pending_verification'));

    if (!in_array($posted_status, $valid_filters, true)) {
        $posted_status = 'pending_verification';
    }

    if ($order_id > 0) {

        try {
            $pdo->beginTransaction();

            $orderStmt = $pdo->prepare("
                SELECT
                    id,
                    customer_id,
                    order_number,
                    status,
                    refund_status,
                    payment_method,
                    payment_screenshot
                FROM orders
                WHERE id = ?
                LIMIT 1
            ");

            $orderStmt->execute([$order_id]);
            $orderData = $orderStmt->fetch(PDO::FETCH_ASSOC);

            if (!$orderData) {
                throw new RuntimeException('Order not found.');
            }

            if (
                $orderData['status'] !== 'cancelled'
                || $orderData['refund_status'] !== 'pending'
                || strtolower(trim((string)$orderData['payment_method'])) !== 'gcash'
                || trim((string)$orderData['payment_screenshot']) === ''
            ) {
                throw new RuntimeException(
                    'This order is not eligible for refund rejection.'
                );
            }

            $rejection_reason = trim(
                (string)($_POST['refund_rejection_reason'] ?? '')
            );

            if ($rejection_reason === '') {
                $rejection_reason = 'Refund rejected by admin.';
            }

            if (mb_strlen($rejection_reason) > 255) {
                $rejection_reason = mb_substr($rejection_reason, 0, 255);
            }

            $stmt = $pdo->prepare("
                UPDATE orders
                SET
                    refund_status = 'rejected',
                    refund_rejection_reason = ?,
                    refund_processed_at = NOW(),
                    refund_processed_by = ?
                WHERE id = ?
                  AND status = 'cancelled'
                  AND refund_status = 'pending'
            ");

            $stmt->execute([
                $rejection_reason,
                $admin_id,
                $order_id
            ]);

            $orderIdentifier = $orderData['order_number'] ?: 'Order';

            if (!empty($orderData['customer_id']) && $notification['type'] !== null) {
                $customerMessage =
                    "The refund for order {$orderIdentifier} was rejected. "
                    . "Reason: {$rejection_reason}";

                $notificationStmt = $pdo->prepare("
                    INSERT INTO notifications
                    (recipient_role, recipient_id, type, message, reference_id)
                    VALUES ('customer', ?, 'refund_rejected', ?, ?)
                ");

                $notificationStmt->execute([
                    $orderData['customer_id'],
                    $customerMessage,
                    $order_id
                ]);
            }

            $pdo->commit();

            $ajaxResponse = [
                'success' => true,
                'order_id' => $order_id,
                'new_refund_status' => 'rejected',
                'message' =>
                    "Refund for {$orderIdentifier} was rejected."
            ];

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'Admin refund rejection failed: ' . $e->getMessage()
            );

            $ajaxResponse = [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }

    } else {
        $ajaxResponse = [
            'success' => false,
            'message' => 'Invalid order.'
        ];
    }

    if ($isAjaxRequest) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($ajaxResponse, JSON_UNESCAPED_UNICODE);
        exit;
    }

    adminOrdersRedirect(
        $posted_status,
        $posted_search,
        $posted_page,
        $ajaxResponse['success'] ? 'refund_rejected' : null
    );
}

/* =========================================================
   UPDATE ORDER STATUS
   Admin uses the same order workflow as Staff:
   Pending -> Confirmed -> Preparing -> Ready -> Completed
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {

    $ajaxResponse = [
    'success' => false,
    'message' => 'The order status could not be updated.'
    ];

    $order_id = (int)($_POST['order_id'] ?? 0);
    $new_status = trim((string)($_POST['status'] ?? ''));

    $posted_status = trim((string)($_POST['status_filter'] ?? 'pending_verification'));
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

    if (
        $order_id > 0 &&
        in_array($new_status, $allowed_statuses, true)
    ) {

        $orderStmt = $pdo->prepare("
            SELECT id, customer_id, order_number, claim_number, status
            FROM orders
            WHERE id = ?
            LIMIT 1
        ");
        $orderStmt->execute([$order_id]);
        $orderData = $orderStmt->fetch(PDO::FETCH_ASSOC);

        if ($orderData) {

            $current_status = trim((string)$orderData['status']);
            $status_was_already_set = ($current_status === $new_status);

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

            /*
             * If another request already moved the order to the requested
             * status, treat it as successful instead of returning a false
             * error to the Staff UI. This also prevents double-click/AJAX
             * race conditions from getting stuck on Processing.
             */
            if ($status_was_already_set) {

                $orderIdentifier = $orderData['order_number']
                    ?: ($orderData['claim_number'] ?: 'Order');

                $ajaxResponse = [
                    'success' => true,
                    'order_id' => $order_id,
                    'previous_status' => $current_status,
                    'new_status' => $new_status,
                    'message' => "Order {$orderIdentifier} is already {$new_status}."
                ];

            } elseif (in_array($new_status, $next_statuses, true)) {

                if ($new_status === 'completed') {

                    $stmt = $pdo->prepare("
                        UPDATE orders
                        SET
                            status = ?,
                            closed_at = NOW()
                        WHERE id = ?
                    ");

                    $stmt->execute([
                        $new_status,
                        $order_id
                    ]);

                } else {

                    $stmt = $pdo->prepare("
                        UPDATE orders
                        SET status = ?
                        WHERE id = ?
                    ");

                    $stmt->execute([
                        $new_status,
                        $order_id
                    ]);
                }

                $orderIdentifier = $orderData['order_number']
                    ?: ($orderData['claim_number'] ?: 'Order');

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

                if (isset($notificationMap[$new_status])) {

                    $notification = $notificationMap[$new_status];

                    /*
                     * Moving Pending -> Order Queue is an internal staff
                     * action, so it has no customer notification type.
                     * Do not insert a NULL notification type into the DB.
                     */
                    if (
                        !empty($orderData['customer_id'])
                        && $notification['type'] !== null
                    ) {




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


                                                    ?,


                                                    ?,


                                                    ?


                                                )


                                            ");



                                            $notificationStmt->execute([


                                                $orderData['customer_id'],


                                                $notification['type'],


                                                $notification['message'],


                                                $order_id


                                            ]);



                                            /* ---------------------------------------------
                                               CUSTOMER PREPARING EMAIL
                                               Send an email only when the Admin changes
                                               the order status to Preparing.
                                            --------------------------------------------- */

                                            if ($new_status === 'preparing') {

                                                try {

                                                    $customerStmt = $pdo->prepare("
                                                        SELECT full_name, email
                                                        FROM customers
                                                        WHERE id = ?
                                                        LIMIT 1
                                                    ");

                                                    $customerStmt->execute([
                                                        $orderData['customer_id']
                                                    ]);

                                                    $customerData =
                                                        $customerStmt->fetch(PDO::FETCH_ASSOC);

                                                    if (
                                                        $customerData &&
                                                        !empty($customerData['email'])
                                                    ) {

                                                        sendOrderPreparingEmail(
                                                            $customerData['email'],
                                                            $customerData['full_name'] ?? 'Customer',
                                                            $orderIdentifier,
                                                            $orderData['claim_number'] ?? ''
                                                        );
                                                    }

                                                } catch (Throwable $e) {

                                                    /*
                                                     * Do not fail the order-status update
                                                     * if the email server is unavailable.
                                                     */
                                                    error_log(
                                                        'Localitea preparing email failed: '
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
                    'message' => $notification['message']
                    ];
                }
            } else {
                $ajaxResponse['message'] = 'This order cannot be moved to the selected status from its current status.';
            }
        } else {
            $ajaxResponse['message'] = 'Order not found.';
        }
    } else {
        $ajaxResponse['message'] = 'Invalid order status request.';
    }

    if ($isAjaxRequest) {

        header('Content-Type: application/json; charset=utf-8');

        echo json_encode($ajaxResponse);

        exit;
    }

    adminOrdersRedirect(
        $posted_status,
        $posted_search,
        $posted_page,
        in_array($new_status, $allowed_statuses, true)
            ? $new_status
            : null
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
   PENDING REFUNDS

   Keep "Payment could not be verified" cancellations OUT of
   Pending Refunds, including older records that may have been
   marked pending before this rule was added.

   Backfill other existing cancelled GCash orders that already
   have uploaded payment proof so they appear in the dropdown.
========================================================= */

/* Remove any previously pending refund that was caused by an
 * unsuccessful payment verification. */
$pdo->exec("
    UPDATE orders o
    SET
        o.refund_status = 'none',
        o.refund_requested_at = NULL
    WHERE o.status = 'cancelled'
      AND LOWER(TRIM(COALESCE(o.cancellation_reason, ''))) =
          'payment could not be verified'
      AND o.refund_status = 'pending'
");

/* Backfill valid cancelled GCash orders with payment proof. */
$pdo->exec("
    UPDATE orders o
    SET
        o.refund_status = 'pending',
        o.refund_requested_at = COALESCE(
            o.refund_requested_at,
            o.closed_at,
            NOW()
        )
    WHERE o.status = 'cancelled'
      AND o.refund_status = 'none'
      AND LOWER(TRIM(COALESCE(o.payment_method, ''))) = 'gcash'
      AND o.payment_screenshot IS NOT NULL
      AND TRIM(o.payment_screenshot) <> ''
      AND LOWER(TRIM(COALESCE(o.cancellation_reason, ''))) <>
          'payment could not be verified'
");

$pendingRefundStmt = $pdo->query("
    SELECT COUNT(*)
    FROM orders
    WHERE status = 'cancelled'
      AND refund_status = 'pending'
      AND LOWER(TRIM(COALESCE(payment_method, ''))) = 'gcash'
      AND payment_screenshot IS NOT NULL
      AND TRIM(payment_screenshot) <> ''
      AND LOWER(TRIM(COALESCE(cancellation_reason, ''))) <>
          'payment could not be verified'
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

/* Active orders are shown newest first: the most recent order
 * appears at the top of the list. created_at is the primary order;
 * id breaks ties when orders share the exact same timestamp. */
$order_by = 'o.created_at DESC, o.id DESC';

$orderSql = "
    SELECT o.*
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
 * Active orders use created_at DESC, id DESC.
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
              o.created_at > ?
              OR (
                  o.created_at = ?
                  AND o.id >= ?
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
        SELECT o.*
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
$cancelled_where = ["o.status = 'cancelled'"];
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

if ($cancelled_search !== '') {
    $cancelled_where[] = "
        (
            o.order_number LIKE ?
            OR o.claim_number LIKE ?
            OR o.customer_name LIKE ?
            OR o.contact_number LIKE ?
            OR CAST(o.id AS CHAR) LIKE ?
        )
    ";

    $cancelled_search_value = '%' . $cancelled_search . '%';

    $cancelled_params[] = $cancelled_search_value;
    $cancelled_params[] = $cancelled_search_value;
    $cancelled_params[] = $cancelled_search_value;
    $cancelled_params[] = $cancelled_search_value;
    $cancelled_params[] = $cancelled_search_value;
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
 * Put the requested cancelled order on the correct page.
 * Cancelled orders use closed_at DESC, id DESC.
 */
if (
    $target_order_id > 0
    && isset($targetOrder)
    && $targetOrder
    && (string)$targetOrder['status'] === 'cancelled'
) {
    $targetClosedAt = (string)($targetOrder['closed_at'] ?? '');

    if ($targetClosedAt !== '') {
        $targetCancelledPageStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM orders o
            WHERE o.status = 'cancelled'
              AND o.closed_at >= ?
              AND o.closed_at <= ?
              AND (
                  o.closed_at > ?
                  OR (
                      o.closed_at = ?
                      AND o.id >= ?
                  )
              )
        ");

        /*
         * The selected month is already applied to the cancelled
         * query above. Count only records in that same month that
         * appear before the target in closed_at DESC, id DESC.
         */
        $monthStart = $cancelled_month . '-01 00:00:00';
        $nextMonthStart = date(
            'Y-m-d 00:00:00',
            strtotime($monthStart . ' +1 month')
        );

        $targetCancelledPageStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM orders o
            WHERE o.status = 'cancelled'
              AND o.closed_at >= ?
              AND o.closed_at < ?
              AND (
                  o.closed_at > ?
                  OR (
                      o.closed_at = ?
                      AND o.id >= ?
                  )
              )
        ");

        $targetCancelledPageStmt->execute([
            $monthStart,
            $nextMonthStart,
            $targetClosedAt,
            $targetClosedAt,
            $target_order_id
        ]);

        $targetCancelledPosition = max(
            1,
            (int)$targetCancelledPageStmt->fetchColumn()
        );

        $cancelled_page = max(
            1,
            (int)ceil(
                $targetCancelledPosition / $cancelled_per_page
            )
        );

        $cancelled_offset =
            ($cancelled_page - 1) *
            $cancelled_per_page;

        $cancelledOrderStmt = $pdo->prepare($cancelledOrderSql);
        $cancelledOrderStmt->execute($cancelled_params);

        $cancelled_orders =
            $cancelledOrderStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

/* =========================================================
   PENDING REFUNDS QUERY
   Pending = current pending refunds.
   Refunded / Rejected = refund history.
   Search is optional and narrows whichever refund status is selected.
========================================================= */
$refund_history_mode =
    $refund_status_filter !== 'pending'
    || $refund_search !== '';

$pending_refund_where = [
    "o.status = 'cancelled'",
    "LOWER(TRIM(COALESCE(o.payment_method, ''))) = 'gcash'",
    "o.payment_screenshot IS NOT NULL",
    "TRIM(o.payment_screenshot) <> ''",
    "LOWER(TRIM(COALESCE(o.cancellation_reason, ''))) <> 'payment could not be verified'"
];

$pending_refund_params = [];

/*
 * Always filter by the selected refund status.
 *
 * Pending:
 *   Shows all current pending refunds without a date restriction.
 *
 * Refunded / Rejected:
 *   Shows refund history using the selected history date filter.
 *
 * Search:
 *   Optional additional narrowing by order/customer details.
 */
$pending_refund_where[] = 'o.refund_status = ?';
$pending_refund_params[] = $refund_status_filter;

if ($refund_history_mode) {

    $refund_date_expression =
        'COALESCE(o.refund_processed_at, o.refund_requested_at, o.closed_at)';

    switch ($refund_period) {

        case 'today':
            $pending_refund_where[] =
                "DATE({$refund_date_expression}) = CURDATE()";
            break;

        case 'last_week':
            $pending_refund_where[] = "
                DATE({$refund_date_expression}) BETWEEN
                    DATE_SUB(CURDATE(), INTERVAL (WEEKDAY(CURDATE()) + 7) DAY)
                    AND DATE_SUB(CURDATE(), INTERVAL (WEEKDAY(CURDATE()) + 1) DAY)
            ";
            break;

        case 'last_month':
            $pending_refund_where[] = "
                DATE({$refund_date_expression}) BETWEEN
                    DATE_FORMAT(
                        DATE_SUB(CURDATE(), INTERVAL 1 MONTH),
                        '%Y-%m-01'
                    )
                    AND LAST_DAY(
                        DATE_SUB(CURDATE(), INTERVAL 1 MONTH)
                    )
            ";
            break;

        case 'specific_date':
            $pending_refund_where[] =
                "DATE({$refund_date_expression}) = ?";
            $pending_refund_params[] = $refund_date;
            break;
    }
}

if ($refund_search !== '') {

    $pending_refund_where[] = "
        (
            o.order_number LIKE ?
            OR o.claim_number LIKE ?
            OR o.customer_name LIKE ?
            OR o.contact_number LIKE ?
            OR CAST(o.id AS CHAR) LIKE ?
        )
    ";

    $refund_search_value = '%' . $refund_search . '%';

    for ($i = 0; $i < 5; $i++) {
        $pending_refund_params[] = $refund_search_value;
    }
}

$pending_refund_where_sql =
    'WHERE ' . implode(' AND ', $pending_refund_where);

$pendingRefundTotalStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM orders o
    {$pending_refund_where_sql}
");
$pendingRefundTotalStmt->execute($pending_refund_params);
$pending_refund_filtered_count =
    (int)$pendingRefundTotalStmt->fetchColumn();

$pendingRefundOrderStmt = $pdo->prepare("
    SELECT o.*
    FROM orders o
    {$pending_refund_where_sql}
    ORDER BY COALESCE(o.refund_processed_at, o.refund_requested_at, o.closed_at) DESC,
             o.id DESC
");
$pendingRefundOrderStmt->execute($pending_refund_params);

$pending_refund_orders =
    $pendingRefundOrderStmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================================================
   LOAD ORDER ITEMS FOR ACTIVE + PENDING REFUNDS + CANCELLED CURRENT PAGE
========================================================= */
$order_items = [];

$items_order_ids = array_values(array_unique(array_merge(
    array_map('intval', array_column($orders, 'id')),
    array_map('intval', array_column($pending_refund_orders, 'id')),
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
   LOAD CANCELLATION ACTORS FOR CANCELLED ORDERS
========================================================= */
$cancelled_by_roles = [];
$cancelled_by_ids = [];

if (!empty($cancelled_orders) || !empty($pending_refund_orders)) {

    $history_order_ids = array_values(array_unique(array_merge(
        array_map('intval', array_column($cancelled_orders, 'id')),
        array_map('intval', array_column($pending_refund_orders, 'id'))
    )));

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
    'pending_verification' => 'Pending',
    'order_queue' => 'Order Queue',
    'confirmed' => 'Confirmed',
    'preparing' => 'Preparing',
    'ready' => 'Ready for Pick-up',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled'
];

function adminStatusLabel(
    string $status,
    array $labels
): string {
    return $labels[$status]
        ?? ucwords(str_replace('_', ' ', $status));
}

function adminFormatTime(?string $value): string {

    if (!$value) {
        return '—';
    }

    $timestamp = strtotime($value);

    return $timestamp
        ? date('h:i A', $timestamp)
        : '—';
}

function adminFormatDateTime(?string $value): string {

    if (!$value) {
        return '—';
    }

    $timestamp = strtotime($value);

    return $timestamp
        ? date('M d, Y h:i A', $timestamp)
        : '—';
}

function adminCancellationActorLabel(?string $role): string {

    $role = strtolower(trim((string)$role));

    return match ($role) {
        'admin' => 'Admin',
        'staff' => 'Staff',
        'customer' => 'Customer',
        'system' => 'System',
        default => 'Unknown'
    };
}

function adminAssetPath(?string $path): string {

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

function adminGetAddonPriceMap(): array
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
        error_log('Admin add-on price lookup failed: ' . $e->getMessage());
    }

    return $priceMap;
}

function adminGetAddonPrice(string $name, float $fallback = 0.00): float
{
    $normalizedName = strtolower(trim($name));

    if ($normalizedName === '') {
        return $fallback;
    }

    $priceMap = adminGetAddonPriceMap();

    return array_key_exists($normalizedName, $priceMap)
        ? (float)$priceMap[$normalizedName]
        : $fallback;
}

function adminGetAddons(?string $addons): array
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
                    'price' => adminGetAddonPrice(
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
                    : adminGetAddonPrice(
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
                'price' => adminGetAddonPrice(
                    $name,
                    $defaultAddonPrice
                )
            ];
        }
    }

    return $result;
}

function adminOrdersUrl(array $overrides = []): string {

    $query = [
        'status' => $GLOBALS['selected_status'],
        'q' => $GLOBALS['search'],
        'page' => $GLOBALS['page'],
        'cancelled_q' => $GLOBALS['cancelled_search'],
        'cancelled_period' => $GLOBALS['cancelled_period'],
        'cancelled_month' => $GLOBALS['cancelled_month'],
        'cancelled_page' => $GLOBALS['cancelled_page'],
        'cancelled_open' => $GLOBALS['cancelled_open'],
        'refund_q' => $GLOBALS['refund_search'],
        'refund_status' => $GLOBALS['refund_status_filter'],
        'refund_period' => $GLOBALS['refund_period'],
        'refund_date' => $GLOBALS['refund_date'],
        'refund_open' => $GLOBALS['refund_open']
    ];

    foreach ($overrides as $key => $value) {
        $query[$key] = $value;
    }

    foreach ($query as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
        }
    }

    return ($GLOBALS['orders_page'] ?? 'orders.php') . '?' . http_build_query($query);
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

$refund_period_labels = [
    'today' => 'Today',
    'last_week' => 'Last week',
    'last_month' => 'Last month',
    'specific_date' => 'Specific date'
];

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

    .admin-orders-page {
        min-height: 100vh;
    }

    /* Staff sidebar is fixed at 260px on desktop. */
    .admin-main {
        min-width: 0;
        width: calc(100% - 260px);
        margin-left: 260px;
    }

    .admin-content {
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

    .workflow-card.workflow-order_queue {
        border-color: #C9B19D;
    }

    .workflow-card.workflow-order_queue:hover,
    .workflow-card.workflow-order_queue.active {
        background: #F7F1E8;
        border-color: #8B6F5A;
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

    .workflow-order_queue .workflow-icon {
        background: #EEE4DB;
        color: #6F4E37;
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
        padding: 18px;
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
        align-items: flex-end;
        justify-content: space-between;
        gap: 16px;
        margin: 0;
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

    /* Order Queue status pill (same as the Admin side) */
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

    /* =========================================================
       CANCELLED ORDER PAGINATION
       Matches the Notifications pagination pattern:
       page 1 is always visible, nearby pages are shown, and
       gaps are represented by an ellipsis.
    ========================================================= */

    .cancelled-pagination {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        width: 100%;
        margin-top: 22px;
        padding-top: 18px;
        border-top: 1px solid #E6DEC9;
        flex-wrap: wrap;
    }

    .cancelled-pagination a,
    .cancelled-pagination span {
        min-width: 34px;
        height: 34px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0 9px;
        border-radius: 8px;
        font-size: .78rem;
        font-weight: 500;
        text-decoration: none;
        box-sizing: border-box;
    }

    .cancelled-pagination a {
        color: #6F4E37;
        background: #FFFFFF;
        border: 1px solid #B8A08A;
        transition:
            background .15s ease,
            border-color .15s ease,
            color .15s ease;
    }

    .cancelled-pagination a:hover {
        background: #F7F0E8;
        border-color: #6F4E37;
        color: #4A3525;
    }

    .cancelled-pagination .active {
        background: #4A3525;
        border: 1px solid #4A3525;
        color: #FFFFFF;
    }

    .cancelled-pagination .disabled {
        color: #A99B91;
        background: #F5F1ED;
        border: 1px solid #E6DEC9;
        cursor: default;
    }

    .cancelled-pagination .ellipsis {
        border: none;
        background: transparent;
        color: #8B7D73;
        min-width: 22px;
        padding: 0;
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

    .orders-toast-message {
        flex: 1;
        padding-top: 3px;
        font-size: .9rem;
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

    @media (max-width: 991.98px) {
        /* Sidebar becomes off-canvas on tablet/mobile. */
        .admin-main {
            width: 100%;
            margin-left: 0;
        }
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

        .admin-content {
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

        .admin-content {
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

    @media print {

        body * {
            visibility: hidden !important;
        }

        .modal.show[id^="receiptModal"],
        .modal.show[id^="receiptModal"] * {
            visibility: visible !important;
        }

        .modal.show[id^="receiptModal"] {
            position: absolute !important;
            left: 0 !important;
            top: 0 !important;
            width: 100% !important;
        }

        .modal.show[id^="receiptModal"] .modal-header,
        .modal.show[id^="receiptModal"] .modal-footer {
            display: none !important;
        }

        .receipt-paper {
            border: none !important;
            max-width: 430px !important;
        }
    }
    /* =========================================================
   PREPARING PAGE-LEVEL LOADING INDICATOR
========================================================= */

.admin-preparing-loading-overlay {
    position: fixed;
    inset: 0;
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: rgba(44, 34, 30, .48);
    backdrop-filter: blur(2px);
}

.admin-preparing-loading-box {
    width: min(420px, calc(100vw - 32px));
    padding: 26px 24px;
    text-align: center;
    background: #FFFFFF;
    border: 2px solid #6F4E37;
    border-radius: 16px;
    box-shadow: 0 16px 40px rgba(44, 34, 30, .22);
    color: #4A3525;
}

.admin-preparing-loading-spinner {
    width: 44px;
    height: 44px;
    margin: 0 auto 14px;
    border: 4px solid #E8DED3;
    border-top-color: #6F4E37;
    border-radius: 50%;
    animation: adminPreparingSpin .8s linear infinite;
}

.admin-preparing-loading-title {
    font-size: 1rem;
    font-weight: 500;
    margin-bottom: 6px;
}

.admin-preparing-loading-text {
    color: #7B6D62;
    font-size: .82rem;
    line-height: 1.5;
}

@keyframes adminPreparingSpin {
    to {
        transform: rotate(360deg);
    }
}

/* =========================================================
   ADMIN ORDER PROCESSING LOADING INDICATOR
========================================================= */

.order-card {
    position: relative;
}

.order-card.processing-order {
    pointer-events: none;
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

    animation: adminOrderSpin .75s linear infinite;

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

@keyframes adminOrderSpin {
    to {
        transform: rotate(360deg);
    }
}


/* =========================================================
   TARGET ORDER FROM ADMIN NOTIFICATION
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

    .admin-orders-table-wrap {
        width: 100%;
        overflow-x: auto;
        background: #FFFFFF;
        border: 1px solid #6F4E37;
        border-radius: 14px;
        box-shadow: 0 3px 12px rgba(74, 53, 37, .05);
    }

    .admin-orders-table {
        width: 100%;
        min-width: 1000px;
        border-collapse: separate;
        border-spacing: 0;
        table-layout: fixed;
    }

    .admin-orders-table th:nth-child(1),
    .admin-orders-table td:nth-child(1) { width: 14%; }

    .admin-orders-table th:nth-child(2),
    .admin-orders-table td:nth-child(2) { width: 11%; }

    .admin-orders-table th:nth-child(3),
    .admin-orders-table td:nth-child(3) { width: 17%; }

    .admin-orders-table th:nth-child(4),
    .admin-orders-table td:nth-child(4) { width: 12%; }

    .admin-orders-table th:nth-child(5),
    .admin-orders-table td:nth-child(5) { width: 9%; }

    .admin-orders-table th:nth-child(6),
    .admin-orders-table td:nth-child(6) { width: 13%; }

    .admin-orders-table th:nth-child(7),
    .admin-orders-table td:nth-child(7) { width: 8%; }

    .admin-orders-table th:nth-child(8),
    .admin-orders-table td:nth-child(8) { width: 9%; }

    .admin-orders-table th:nth-child(9),
    .admin-orders-table td:nth-child(9) { width: 12%; }

    .admin-orders-table thead th {
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

    .admin-orders-table tbody tr.order-card {
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

    .admin-orders-table tbody tr.order-card:hover {
        background: #FDF8F2;
    }

    .admin-orders-table tbody td {
        padding: 11px 10px;
        vertical-align: middle;
        border-bottom: 1px solid #E6DCCF;
        color: #4A3525;
        font-size: .78rem;
        font-weight: 400;
        background: inherit;
    }

    .admin-orders-table tbody td > * {
        max-width: 100%;
    }

    .admin-orders-table .order-table-primary {
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .admin-orders-table .order-table-date,
    .admin-orders-table .order-table-claim,
    .admin-orders-table .order-table-payment,
    .admin-orders-table .order-table-discount-badge,
    .admin-orders-table .order-table-none,
    .admin-orders-table .order-table-total,
    .admin-orders-table .status-badge {
        font-weight: 500;
    }

    .admin-orders-table tbody tr:last-child td {
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

    .order-table-actions-cell {
        min-width: 145px;
    }

    .btn-view-details {
        border: 1px solid #6F4E37;
        background: #FFFFFF;
        color: #6F4E37;
        font-weight: 500;
        white-space: nowrap;
    }

    .btn-view-details:hover {
        background: #6F4E37;
        border-color: #6F4E37;
        color: #FFFFFF;
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
         * The admin navbar is fixed above the page. Do not vertically
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

    .admin-details-items-table {
        table-layout: fixed;
        width: 100%;
    }

    .admin-details-items-table th:nth-child(1),
    .admin-details-items-table td:nth-child(1) {
        width: 54%;
    }

    .admin-details-items-table th:nth-child(2),
    .admin-details-items-table td:nth-child(2) {
        width: 10%;
    }

    .admin-details-items-table th:nth-child(3),
    .admin-details-items-table td:nth-child(3) {
        width: 18%;
    }

    .admin-details-items-table th:nth-child(4),
    .admin-details-items-table td:nth-child(4) {
        width: 18%;
    }

    .admin-details-items-table {
        border: 1px solid #8B6F5A;
        border-radius: 10px;
        overflow: hidden;
        background: #FFFFFF;
    }

    .admin-details-items-table th {
        background: #F7F1E8;
        color: #4A3525;
        font-size: .72rem;
        text-transform: uppercase;
        letter-spacing: .3px;
        font-weight: 500;
        border-bottom: 1px solid #8B6F5A;
    }

    .admin-details-items-table td {
        color: #3F2D20 !important;
        font-size: .86rem !important;
        font-weight: 600;
        border-color: #E6DCCF;
    }

    .admin-details-items-table th {
        color: #3F2D20 !important;
        font-size: .76rem !important;
    }

    .admin-details-items-table td:first-child {
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

    .admin-orders-table tbody tr.view-order-target td {
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

        .admin-orders-table-wrap {
            border-radius: 10px;
        }

        .admin-orders-table {
            min-width: 1020px;
        }

        .admin-orders-table thead th,
        .admin-orders-table tbody td {
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

    .order-details-modal-dialog .admin-details-items-table {
        border-color: #CDBBAA;
        border-radius: 10px;
    }

    .order-details-modal-dialog .admin-details-items-table th {
        background: #F7F1E8;
        color: #6A5647 !important;
        font-size: .68rem !important;
        font-weight: 500 !important;
        padding: 9px 10px;
    }

    .order-details-modal-dialog .admin-details-items-table td {
        color: #4A3525 !important;
        font-size: .80rem !important;
        font-weight: 400 !important;
        padding: 10px;
    }

    .order-details-modal-dialog .admin-details-items-table td:first-child {
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
    .admin-content { overflow-x: clip; }
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

    /* ---- Tablet & up: keep the table tabular, just more compact ---- */
    @media (min-width: 576px) and (max-width: 1199.98px) {
        .admin-orders-table { min-width: 720px; }

        .admin-orders-table th:nth-child(1), .admin-orders-table td:nth-child(1) { width: 14%; }
        .admin-orders-table th:nth-child(2), .admin-orders-table td:nth-child(2) { width: 9%; }
        .admin-orders-table th:nth-child(3), .admin-orders-table td:nth-child(3) { width: 16%; }
        .admin-orders-table th:nth-child(4), .admin-orders-table td:nth-child(4) { width: 12%; }
        .admin-orders-table th:nth-child(5), .admin-orders-table td:nth-child(5) { width: 8%; }
        .admin-orders-table th:nth-child(6), .admin-orders-table td:nth-child(6) { width: 12%; }
        .admin-orders-table th:nth-child(7), .admin-orders-table td:nth-child(7) { width: 9%; }
        .admin-orders-table th:nth-child(8), .admin-orders-table td:nth-child(8) { width: 10%; }
        .admin-orders-table th:nth-child(9), .admin-orders-table td:nth-child(9) { width: 14%; }

        .admin-orders-table thead th {
            padding: 10px 6px;
            font-size: .64rem;
            letter-spacing: .2px;
            white-space: normal;
        }

        .admin-orders-table tbody td {
            padding: 9px 6px;
            font-size: .72rem;
        }

        .admin-orders-table .order-table-primary { white-space: normal; overflow-wrap: anywhere; }
        .admin-orders-table .order-table-date { white-space: normal; font-size: .64rem; }
        .admin-orders-table .order-table-claim { white-space: normal; overflow-wrap: anywhere; }
        .admin-orders-table .order-table-payment { padding: 4px 6px; font-size: .64rem; min-height: 0; }
        .admin-orders-table .order-table-discount-badge { white-space: normal; font-size: .62rem; }
        .admin-orders-table .status-badge { padding: 4px 7px; font-size: .62rem; white-space: normal; text-align: center; }
        .admin-orders-table .order-table-actions-cell { min-width: 0; }

        .admin-orders-table .btn-view-details {
            padding: 6px 8px;
            font-size: .68rem;
            white-space: normal;
            line-height: 1.2;
        }
        .admin-orders-table .btn-view-details i { display: none; }
    }

    /* ---- Tablet: 3 tabs per row, table scrolls inside its own box ---- */
    @media (min-width: 768px) and (max-width: 1199.98px) {
        .workflow-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
        .admin-content { padding: 22px; }
        .admin-orders-table-wrap { -webkit-overflow-scrolling: touch; }
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
        .admin-orders-table-wrap {
            overflow: visible;
            background: transparent;
            border: 0;
            border-radius: 0;
            box-shadow: none;
        }

        .admin-orders-table,
        .admin-orders-table tbody {
            display: block;
            width: 100%;
            min-width: 0;
        }

        .admin-orders-table thead {
            position: absolute;
            width: 1px;
            height: 1px;
            overflow: hidden;
            clip: rect(0 0 0 0);
            white-space: nowrap;
        }

        .admin-orders-table tbody tr.order-card {
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

        .admin-orders-table tbody tr.order-card:last-child { margin-bottom: 0; }

        .admin-orders-table tbody tr.order-card.view-order-target {
            background: #FFF8D8;
            outline: 3px solid #F1C40F;
            outline-offset: 2px;
        }

        .admin-orders-table tbody tr.order-card td {
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
        .admin-orders-table tbody tr.order-card td::before {
            display: block;
            margin-bottom: 3px;
            color: #7B6D62;
            font-size: .64rem;
            font-weight: 500;
            letter-spacing: .35px;
            text-transform: uppercase;
        }

        .admin-orders-table td:nth-child(2)::before { content: "Claim No."; }
        .admin-orders-table td:nth-child(4)::before { content: "Pick-up"; }
        .admin-orders-table td:nth-child(5)::before { content: "Payment"; }
        .admin-orders-table td:nth-child(6)::before { content: "Discount"; }
        .admin-orders-table td:nth-child(7)::before { content: "Total"; }
        .admin-orders-table td:nth-child(8)::before { content: "Status"; }

        /* Order number + customer + action use the full card width */
        .admin-orders-table tbody tr.order-card td:nth-child(1),
        .admin-orders-table tbody tr.order-card td:nth-child(3),
        .admin-orders-table tbody tr.order-card td:nth-child(9) {
            grid-column: 1 / -1;
        }

        .admin-orders-table tbody tr.order-card td:nth-child(1) {
            padding-bottom: 10px;
            border-bottom: 1px solid #E6DCCF;
        }

        .admin-orders-table .order-table-primary { white-space: normal; }
        .admin-orders-table .order-table-date { white-space: normal; }
        .admin-orders-table .order-table-actions-cell { min-width: 0; }

        .admin-orders-table .btn-view-details {
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

        .order-details-modal-dialog .admin-details-items-table { min-width: 520px; }

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
        .admin-content { padding: 14px 10px 24px; }
        .workflow-card { padding: 9px 10px; gap: 8px; }
        .workflow-icon { width: 32px; height: 32px; flex-basis: 32px; }
        .workflow-count { font-size: 1.1rem; }
        .admin-orders-table tbody tr.order-card { grid-template-columns: 1fr; }
    }

    /* =========================================================
       ACTIVE ORDERS TABLE: NO BOX, NO SIDE SCROLL
       - The outer box (border / rounded corners / background) is
         removed; rows sit directly on the page.
       - Table fits the screen width (no horizontal scrollbar) on
         tablets/laptops/desktops (900px and up).
       - Status stays on ONE line; View Details has its own room.
       Phones (<576px) keep the card layout untouched.
    ========================================================= */
    @media (min-width: 576px) {
        .admin-orders-table-wrap {
            background: transparent;
            border: 0;
            border-radius: 0;
            box-shadow: none;
        }

        .admin-orders-table th:nth-child(1), .admin-orders-table td:nth-child(1) { width: 13%; }
        .admin-orders-table th:nth-child(2), .admin-orders-table td:nth-child(2) { width: 9%; }
        .admin-orders-table th:nth-child(3), .admin-orders-table td:nth-child(3) { width: 14%; }
        .admin-orders-table th:nth-child(4), .admin-orders-table td:nth-child(4) { width: 10%; }
        .admin-orders-table th:nth-child(5), .admin-orders-table td:nth-child(5) { width: 7%; }
        .admin-orders-table th:nth-child(6), .admin-orders-table td:nth-child(6) { width: 9%; }
        .admin-orders-table th:nth-child(7), .admin-orders-table td:nth-child(7) { width: 7%; }
        .admin-orders-table th:nth-child(8), .admin-orders-table td:nth-child(8) { width: 16%; }
        .admin-orders-table th:nth-child(9), .admin-orders-table td:nth-child(9) { width: 15%; }

        .admin-orders-table thead th:first-child { border-radius: 10px 0 0 10px; }
        .admin-orders-table thead th:last-child { border-radius: 0 10px 10px 0; }
        .admin-orders-table thead th { border-bottom: 0; padding: 12px 8px; white-space: normal; }
        .admin-orders-table tbody td { padding: 12px 8px; }

        /* Long values wrap inside their column instead of forcing a scrollbar */
        .admin-orders-table .order-table-primary,
        .admin-orders-table .order-table-date,
        .admin-orders-table .order-table-claim {
            white-space: normal;
            overflow: visible;
            text-overflow: clip;
            overflow-wrap: anywhere;
        }

        /* Status: always one line, centered pill */
        .admin-orders-table thead th:nth-child(8),
        .admin-orders-table tbody td:nth-child(8) { padding-left: 16px; }
        .admin-orders-table .status-badge {
            display: inline-block;
            white-space: nowrap;
            text-align: center;
            padding: 6px 12px;
            font-size: .7rem;
        }

        /* View Details: one line, not squeezed against the edge */
        .admin-orders-table thead th:nth-child(9),
        .admin-orders-table tbody td:nth-child(9) { padding-right: 12px; }
        .admin-orders-table .order-table-actions-cell { min-width: 0; }
        .admin-orders-table .btn-view-details {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            white-space: nowrap;
            padding: 7px 14px;
            font-size: .78rem;
            line-height: 1.2;
        }
    }

    /* Laptops / desktops: table is exactly as wide as the page, no scrolling */
    @media (min-width: 900px) {
        .admin-orders-table-wrap { overflow: visible; }
        .admin-orders-table { min-width: 0; width: 100%; }
    }

    /* Slightly smaller pills / button on narrower laptops so they still fit */
    @media (min-width: 900px) and (max-width: 1199.98px) {
        .admin-orders-table .status-badge { padding: 5px 9px; font-size: .66rem; }
        .admin-orders-table .btn-view-details { padding: 7px 10px; font-size: .74rem; }
    }

    /* Small tablets (576-899px) are too narrow to fit 9 columns,
       so only here the table may scroll inside its own area. */
    @media (min-width: 576px) and (max-width: 899.98px) {
        .admin-orders-table-wrap { overflow-x: auto; }
        .admin-orders-table { min-width: 800px; }
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

</style>

<div class="admin-orders-page">

    <!-- =====================================================
         ADMIN SIDEBAR
         This shared Orders layout uses the current user's sidebar.
    ====================================================== -->
    <?php require_once 'sidebar.php'; ?>

    <?php require_once 'navbar.php'; ?>

    <main class="admin-main admin-content">

            <h2 class="fw-bold page-title mb-1">
                <i class="bi bi-bag-check me-1"></i>
                 Orders Queue
            </h2>

            <p class="page-description mb-4">
                Review, process, and monitor customer orders through the
                complete pick-up workflow.
            </p>

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
               - If the Admin switches tabs, the toast is carried to the next
                 page and continues from where it left off (the progress bar
                 resumes at the correct point instead of restarting).
               - Once the time is up it is gone for good.
            ========================================================= */
            (function () {
                var STORAGE_KEY = 'adminOrdersActionToast';
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
                        href="<?= htmlspecialchars(adminOrdersUrl([
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

                <div class="search-actions">

                    <button
                        type="submit"
                        class="btn search-button"
                    >
                        <i class="bi bi-search me-1"></i>
                        Search
                    </button>

                    <?php if ($search !== ''): ?>

                        <a
                            href="<?= htmlspecialchars(adminOrdersUrl(['q' => '', 'page' => 1])) ?>"
                            class="btn clear-button"
                        >
                            Clear
                        </a>

                    <?php endif; ?>

                </div>

            </form>

            <!-- ACTIVE ORDERS SECTION -->
            <section class="active-orders-section">

                <div class="active-section-header">

                    <div class="active-section-badge">
                        <i class="bi bi-lightning-charge-fill"></i>
                        Active Workflow
                    </div>

                    <div class="small text-muted">
                        These orders still require store action.
                    </div>

                </div>

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

                    <div class="admin-orders-table-wrap">

                        <table class="admin-orders-table">

                            <thead>
                                <tr>
                                    <th>Order</th>
                                    <th>Claim No.</th>
                                    <th>Customer</th>
                                    <th>Pick-up</th>
                                    <th>Payment</th>
                                    <th>Discount</th>
                                    <th class="text-end">Total</th>
                                    <th>Status</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>

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
                                            <div class="order-table-primary">
                                                <?= htmlspecialchars(
                                                    $order['order_number']
                                                    ?: 'ORD-' . $order_id
                                                ) ?>
                                            </div>
                                            <div class="order-table-date">
                                                <?= htmlspecialchars(
                                                    adminFormatDateTime($order['created_at'] ?? null)
                                                ) ?>
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
                                                    adminFormatTime($order['pickup_time'])
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

                                        <td>
                                            <span
                                                class="status-badge status-<?= htmlspecialchars($status) ?>"
                                            >
                                                <?= htmlspecialchars(
                                                    adminStatusLabel(
                                                        $status,
                                                        $status_labels
                                                    )
                                                ) ?>
                                            </span>
                                        </td>

                                        <td class="text-end order-table-actions-cell">
                                            <button
                                                type="button"
                                                class="btn action-btn btn-view-details"
                                                data-order-details-modal="orderDetailsModal<?= $order_id ?>"
                                            
                                                
                                            >
                                                <i class="bi bi-eye me-1"></i>
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
                        $payment_proof = adminAssetPath(
                            $order['payment_screenshot'] ?? ''
                        );

                        $discount_type = strtolower(
                            trim((string)($order['discount_type'] ?? 'none'))
                        );
                        $discount_rate = (float)($order['discount_rate'] ?? 0);
                        $discount_amount = (float)($order['discount_amount'] ?? 0);
                        ?>

                        <!-- =================================================
                             ORDER DETAILS MODAL
                        ================================================== -->
                        <div
                            class="modal fade"
                            id="orderDetailsModal<?= $order_id ?>"
                            tabindex="-1"
                            aria-hidden="true"
                        >
                            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable order-details-modal-dialog">
                                <div class="modal-content">

                                    <div class="modal-header details-header">
                                        <div>
                                            <h5 class="modal-title fw-bold" style="color:#4A3525;">
                                                Order Details
                                            </h5>
                                            <div class="small text-muted">
                                                <?= htmlspecialchars(
                                                    $order['order_number'] ?: 'ORD-' . $order_id
                                                ) ?>
                                                • Claim #<?= htmlspecialchars(
                                                    $order['claim_number'] ?: 'N/A'
                                                ) ?>
                                            </div>
                                        </div>

                                        <button
                                            type="button"
                                            class="btn-close"
                                            data-bs-dismiss="modal"
                                            aria-label="Close"
                                        ></button>
                                    </div>

                                    <div class="modal-body p-4">

                                        <!-- ORDER STATUS -->
                                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4 order-status-row">
                                            <div>
                                                <div class="info-label">Current Status</div>
                                                <div class="info-value fs-5">
                                                    <?= htmlspecialchars(
                                                        adminStatusLabel(
                                                            $status,
                                                            $status_labels
                                                        )
                                                    ) ?>
                                                </div>
                                            </div>

                                            <span class="status-badge status-<?= htmlspecialchars($status) ?>">
                                                <?= htmlspecialchars(
                                                    adminStatusLabel(
                                                        $status,
                                                        $status_labels
                                                    )
                                                ) ?>
                                            </span>
                                        </div>

                                        <hr class="order-modal-divider">

                                        <!-- CUSTOMER / PICKUP / PAYMENT -->
                                    
                                        

                                        <div class="row g-3 mb-4 order-info-grid">
                                            <div class="col-md-3">
                                                <div class="info-label">Customer</div>
                                                <div class="info-value">
                                                    <?= htmlspecialchars($order['customer_name']) ?>
                                                </div>
                                            </div>

                                            <div class="col-md-3">
                                                <div class="info-label">Contact Number</div>
                                                <div class="info-value">
                                                    <?= htmlspecialchars($order['contact_number']) ?>
                                                </div>
                                            </div>

                                            <div class="col-md-3">
                                                <div class="info-label">Pick-up Date</div>
                                                <div class="info-value">
                                                    <?= htmlspecialchars($order['pickup_date']) ?>
                                                </div>
                                            </div>

                                            <div class="col-md-3">
                                                <div class="info-label">Pick-up Time</div>
                                                <div class="info-value">
                                                    <?= htmlspecialchars(
                                                        adminFormatTime($order['pickup_time'])
                                                    ) ?>
                                                </div>
                                            </div>

                                            <div class="col-md-3">
                                                <div class="info-label">Payment Method</div>
                                                <div class="info-value text-uppercase <?php
                                                    echo strtolower(trim((string)$order['payment_method'])) === 'gcash'
                                                        ? 'payment-gcash'
                                                        : '';
                                                ?>">
                                                    <?= htmlspecialchars($order['payment_method']) ?>
                                                </div>
                                            </div>

                                            <div class="col-md-3">
                                                <div class="info-label">Placed At</div>
                                                <div class="info-value">
                                                    <?= htmlspecialchars(
                                                        adminFormatDateTime($order['created_at'] ?? null)
                                                    ) ?>
                                                </div>
                                            </div>

                                            <div class="col-md-3">
                                                <div class="info-label">Discount Type</div>
                                                <div class="info-value">
                                                    <?php if ($discount_type === 'pwd'): ?>
                                                        PWD
                                                    <?php elseif ($discount_type === 'senior'): ?>
                                                        Senior Citizen
                                                    <?php else: ?>
                                                        None
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                            <div class="col-md-3">
                                                <div class="info-label">Discount Rate</div>
                                                <div class="info-value">
                                                    <?= number_format($discount_rate, 0) ?>%
                                                </div>
                                            </div>
                                        </div>

                                        <!-- DISCOUNT SUMMARY -->
                                        <?php if (
                                            in_array($discount_type, ['pwd', 'senior'], true)
                                            && $discount_amount > 0
                                        ): ?>
                                            <div class="discount-summary-box mb-4">
                                                <div>
                                                    <div class="info-label">
                                                        <?= $discount_type === 'pwd' ? 'PWD Discount' : 'Senior Citizen Discount' ?>
                                                    </div>
                                                    <div class="discount-summary-title">
                                                        <?= number_format($discount_rate, 0) ?>% discount applied
                                                    </div>
                                                </div>

                                                <div class="discount-summary-amount">
                                                    -₱<?= number_format($discount_amount, 2) ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <hr class="order-modal-divider">

                                        <?php if ($items): ?>
                                            <div class="table-responsive mb-4">
                                                <table class="table admin-details-items-table align-middle mb-0">
                                                    <thead>
                                                        <tr>
                                                            <th>Item</th>
                                                            <th class="text-center">Qty</th>
                                                            <th class="text-end">Unit Price</th>
                                                            <th class="text-end">Subtotal</th>
                                                        </tr>
                                                    </thead>

                                                    <tbody>
                                                        <?php foreach ($items as $item): ?>
                                                            <?php
                                                            $customizations = [];

                                                            if (!empty($item['size'])) {
                                                                $customizations[] =
                                                                    'Size: ' . $item['size'];
                                                            }

                                                            if (!empty($item['sugar_level'])) {
                                                                $customizations[] =
                                                                    'Sugar: ' . $item['sugar_level'];
                                                            }

                                                            $item_discount_type = strtolower(
                                                                trim((string)($item['discount_type'] ?? 'none'))
                                                            );
                                                            $item_discount_rate = (float)($item['discount_rate'] ?? 0);
                                                            $item_discount_amount = (float)($item['discount_amount'] ?? 0);

                                                            if (
                                                                in_array($item_discount_type, ['pwd', 'senior'], true)
                                                                && $item_discount_amount > 0
                                                            ) {
                                                                $customizations[] =
                                                                    ($item_discount_type === 'pwd' ? 'PWD' : 'Senior Citizen')
                                                                    . ' Discount: '
                                                                    . number_format($item_discount_rate, 0)
                                                                    . '% (-₱'
                                                                    . number_format($item_discount_amount, 2)
                                                                    . ')';
                                                            }

                                                            $addonDetails = adminGetAddons(
                                                                $item['addons'] ?? null
                                                            );
                                                            ?>

                                                            <tr>
                                                                <td>
                                                                    <div class="fw-semibold">
                                                                        <?= htmlspecialchars($item['product_name']) ?>
                                                                    </div>

                                                                    <?php if ($customizations): ?>
                                                                        <div class="item-customization">
                                                                            <?= htmlspecialchars(
                                                                                implode(' • ', $customizations)
                                                                            ) ?>
                                                                        </div>
                                                                    <?php endif; ?>

                                                                    <?php if ($addonDetails): ?>
                                                                        <div class="order-addon-label mt-2">
                                                                            Add-ons
                                                                        </div>

                                                                        <div class="order-addon-list">
                                                                            <?php foreach ($addonDetails as $addonDetail): ?>
                                                                                <span class="order-addon-chip">
                                                                                    <?= htmlspecialchars($addonDetail['name']) ?>
                                                                                    <span class="order-addon-chip-price">
                                                                                        +₱<?= number_format(
                                                                                            (float)$addonDetail['price'],
                                                                                            2
                                                                                        ) ?>
                                                                                    </span>
                                                                                </span>
                                                                            <?php endforeach; ?>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                </td>

                                                                <td class="text-center">
                                                                    <?= (int)$item['quantity'] ?>
                                                                </td>

                                                                <td class="text-end">
                                                                    ₱<?= number_format(
                                                                        (float)$item['unit_price'],
                                                                        2
                                                                    ) ?>
                                                                </td>

                                                                <td class="text-end fw-semibold">
                                                                    ₱<?= number_format(
                                                                        (float)$item['subtotal'],
                                                                        2
                                                                    ) ?>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php else: ?>
                                            <div class="alert alert-warning small">
                                                No order items were found for this order.
                                            </div>
                                        <?php endif; ?>

                                        <!-- ORDER SUMMARY -->
                                        <div class="order-detail-summary-box">
                                            <div class="d-flex justify-content-between small mb-2">
                                                <span>Subtotal</span>
                                                <strong>
                                                    ₱<?= number_format(
                                                        (float)$order['subtotal'],
                                                        2
                                                    ) ?>
                                                </strong>
                                            </div>

                                            <?php if (
                                                in_array($discount_type, ['pwd', 'senior'], true)
                                                && $discount_amount > 0
                                            ): ?>
                                                <div class="d-flex justify-content-between small mb-2 discount-summary-line">
                                                    <span>
                                                        <?= $discount_type === 'pwd' ? 'PWD Discount' : 'Senior Citizen Discount' ?>
                                                        (<?= number_format($discount_rate, 0) ?>%)
                                                    </span>
                                                    <strong>
                                                        -₱<?= number_format($discount_amount, 2) ?>
                                                    </strong>
                                                </div>
                                            <?php endif; ?>

                                            <div class="order-detail-summary-divider"></div>

                                            <div class="d-flex justify-content-between">
                                                <span class="fw-bold">Total</span>
                                                <span class="fw-bold order-detail-final-total">
                                                    ₱<?= number_format(
                                                        (float)$order['total_amount'],
                                                        2
                                                    ) ?>
                                                </span>
                                            </div>
                                        </div>

                                        <!-- ACTIONS -->
                                        <div class="order-detail-actions mt-4">

                                            <button
                                                type="button"
                                                class="btn action-btn btn-outline-dark"
                                                onclick="printReceipt('receiptModal<?= $order_id ?>')"
                                            >
                                                <i class="bi bi-printer me-1"></i>
                                                Print Receipt
                                            </button>

                                            <?php if (
                                                strtolower((string)$order['payment_method']) === 'gcash'
                                                && $payment_proof !== ''
                                            ): ?>
                                                <button
                                                    type="button"
                                                    class="btn action-btn btn-outline-dark"
                                                    data-admin-payment-proof
                                                    data-proof-src="<?= htmlspecialchars($payment_proof, ENT_QUOTES) ?>"
                                                >
                                                    <i class="bi bi-image me-1"></i>
                                                    GCash Proof
                                                </button>
                                            <?php endif; ?>

                                            <?php if ($status === 'pending_verification'): ?>
                                                <form method="POST" class="workflow-action">
                                                    <input type="hidden" name="order_id" value="<?= $order_id ?>">
                                                    <input type="hidden" name="status" value="order_queue">
                                                    <input type="hidden" name="status_filter" value="<?= htmlspecialchars($selected_status) ?>">
                                                    <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>">
                                                    <input type="hidden" name="page" value="<?= $page ?>">
                                                    <button type="submit" name="update_status" class="btn action-btn btn-preparing">
                                                        <i class="bi bi-inbox me-1"></i>
                                                        Move to Order Queue
                                                    </button>
                                                </form>
                                            <?php elseif ($status === 'order_queue'): ?>
                                                <form method="POST" class="workflow-action">
                                                    <input type="hidden" name="order_id" value="<?= $order_id ?>">
                                                    <input type="hidden" name="status" value="confirmed">
                                                    <input type="hidden" name="status_filter" value="<?= htmlspecialchars($selected_status) ?>">
                                                    <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>">
                                                    <input type="hidden" name="page" value="<?= $page ?>">
                                                    <button type="submit" name="update_status" class="btn action-btn btn-confirm">
                                                        <i class="bi bi-check-circle me-1"></i>
                                                        Confirm Order
                                                    </button>
                                                </form>
                                            <?php elseif ($status === 'confirmed'): ?>
                                                <form method="POST" class="workflow-action">
                                                    <input type="hidden" name="order_id" value="<?= $order_id ?>">
                                                    <input type="hidden" name="status" value="preparing">
                                                    <input type="hidden" name="status_filter" value="<?= htmlspecialchars($selected_status) ?>">
                                                    <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>">
                                                    <input type="hidden" name="page" value="<?= $page ?>">
                                                    <button type="submit" name="update_status" class="btn action-btn btn-preparing">
                                                        <i class="bi bi-cup-hot me-1"></i>
                                                        Start Preparing
                                                    </button>
                                                </form>
                                            <?php elseif ($status === 'preparing'): ?>
                                                <form method="POST" class="workflow-action">
                                                    <input type="hidden" name="order_id" value="<?= $order_id ?>">
                                                    <input type="hidden" name="status" value="ready">
                                                    <input type="hidden" name="status_filter" value="<?= htmlspecialchars($selected_status) ?>">
                                                    <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>">
                                                    <input type="hidden" name="page" value="<?= $page ?>">
                                                    <button type="submit" name="update_status" class="btn action-btn btn-ready">
                                                        <i class="bi bi-bag-check me-1"></i>
                                                        Mark as Ready
                                                    </button>
                                                </form>
                                            <?php elseif ($status === 'ready'): ?>
                                                <form method="POST" class="workflow-action">
                                                    <input type="hidden" name="order_id" value="<?= $order_id ?>">
                                                    <input type="hidden" name="status" value="completed">
                                                    <input type="hidden" name="status_filter" value="<?= htmlspecialchars($selected_status) ?>">
                                                    <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>">
                                                    <input type="hidden" name="page" value="<?= $page ?>">
                                                    <button type="submit" name="update_status" class="btn action-btn btn-complete">
                                                        <i class="bi bi-check2-all me-1"></i>
                                                        Complete Order
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <?php if (
                                                in_array(
                                                    $status,
                                                    [
                                                        'pending_verification',
                                                        'confirmed',
                                                        'preparing',
                                                        'ready'
                                                    ],
                                                    true
                                                )
                                            ): ?>
                                                <button
                                                    type="button"
                                                    class="btn action-btn btn-cancel"
                                                    data-admin-cancel-order
                                                    data-order-id="<?= $order_id ?>"
                                                    data-order-number="<?= htmlspecialchars($order['order_number'] ?: 'ORD-' . $order_id, ENT_QUOTES) ?>"
                                                >
                                                    <i class="bi bi-x-circle me-1"></i>
                                                    Cancel Order
                                                </button>
                                            <?php endif; ?>

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

                                        <div class="d-flex justify-content-between small">
                                            <span>Pickup</span>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    $order['pickup_date']
                                                ) ?>
                                                <?= htmlspecialchars(
                                                    adminFormatTime(
                                                        $order['pickup_time']
                                                    )
                                                ) ?>
                                            </strong>
                                        </div>

                                        <div class="d-flex justify-content-between small">
                                            <span>Payment</span>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    $order['payment_method']
                                                ) ?>
                                            </strong>
                                        </div>

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
                                            adminOrdersUrl([
                                                'page' => max(1, $page - 1)
                                            ])
                                        ) ?>"
                                    >
                                        Previous
                                    </a>
                                </li>

                                <?php
                                $start_page = max(1, $page - 2);
                                $end_page = min(
                                    $total_pages,
                                    $page + 2
                                );

                                for (
                                    $p = $start_page;
                                    $p <= $end_page;
                                    $p++
                                ):
                                ?>

                                    <li
                                        class="page-item <?= $p === $page ? 'active' : '' ?>"
                                    >

                                        <a
                                            class="page-link"
                                            href="<?= htmlspecialchars(
                                                adminOrdersUrl([
                                                    'page' => $p
                                                ])
                                            ) ?>"
                                        >
                                            <?= $p ?>
                                        </a>

                                    </li>

                                <?php endfor; ?>

                                <li
                                    class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>"
                                >

                                    <a
                                        class="page-link"
                                        href="<?= htmlspecialchars(
                                            adminOrdersUrl([
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

            <?php if ($current_role === 'admin'): ?>

<!-- =====================================================
     PENDING REFUNDS
     Collapsed by default, just like Cancelled Orders.
     It stays on the same Orders page and expands inline.
===================================================== -->
<details
    class="pending-refunds-section cancelled-orders-section"
    id="pending-refunds"
    <?= (
        ($_GET['refund_open'] ?? '') === '1'
    ) ? 'open' : '' ?>
>

    <summary class="cancelled-section-summary pending-refunds-summary">

        <div class="cancelled-section-heading pending-refunds-heading">

            <div class="pending-refunds-main">

                <div class="pending-refunds-icon" aria-hidden="true">
                    <i class="bi bi-arrow-counterclockwise"></i>
                </div>

                <div>
                    <h3 class="pending-refunds-title">
                        Pending Refunds
                    </h3>

                    <p class="pending-refunds-description">
                        Cancelled GCash orders with payment proof awaiting refund processing.
                    </p>
                </div>

            </div>

            <div class="text-end">
                <div
                    class="pending-refunds-count"
                    aria-label="Pending refund count"
                >
                    <?= $pending_refund_count ?>
                </div>
            </div>

        </div>

        <div class="cancelled-toggle" aria-hidden="true">
            <span class="cancelled-toggle-label cancelled-toggle-open">
                Open
            </span>
            <span class="cancelled-toggle-label cancelled-toggle-close">
                Close
            </span>
            <i class="bi bi-chevron-down cancelled-toggle-icon"></i>
        </div>

    </summary>

    <div class="cancelled-section-content pending-refunds-content">

        <form method="GET" class="cancelled-filter-form pending-refund-filter-form" data-refund-filter-form>

            <input type="hidden" name="status" value="<?= htmlspecialchars($selected_status) ?>">
            <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>">
            <input type="hidden" name="page" value="<?= $page ?>">
            <input type="hidden" name="refund_open" value="1">

            <div class="cancelled-filter-group">
                <label for="refundStatus">Refund Status</label>
                <select
                    id="refundStatus"
                    name="refund_status"
                    class="pending-refund-filter-status"
                    data-refund-status
                >
                    <option
                        value="pending"
                        <?= $refund_status_filter === 'pending' ? 'selected' : '' ?>
                    >
                        Pending
                    </option>
                    <option
                        value="refunded"
                        <?= $refund_status_filter === 'refunded' ? 'selected' : '' ?>
                    >
                        Refunded
                    </option>
                    <option
                        value="rejected"
                        <?= $refund_status_filter === 'rejected' ? 'selected' : '' ?>
                    >
                        Rejected
                    </option>
                </select>
            </div>

            <div class="cancelled-filter-group">
                <label for="refundPeriod">History Date</label>
                <select
                    id="refundPeriod"
                    name="refund_period"
                    class="cancelled-filter-period"
                    data-refund-period
                >
                    <?php foreach ($refund_period_labels as $periodKey => $periodLabel): ?>
                        <option
                            value="<?= htmlspecialchars($periodKey) ?>"
                            <?= $refund_period === $periodKey ? 'selected' : '' ?>
                        >
                            <?= htmlspecialchars($periodLabel) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div
                class="cancelled-filter-group"
                data-refund-date-wrap
                style="<?= $refund_period === 'specific_date' ? '' : 'display:none;' ?>"
            >
                <label for="refundDate">Choose Date</label>
                <input
                    type="date"
                    id="refundDate"
                    name="refund_date"
                    class="pending-refund-filter-date"
                    value="<?= htmlspecialchars($refund_date) ?>"
                    max="<?= date('Y-m-d') ?>"
                    data-refund-date
                >
            </div>

            <div class="cancelled-filter-group">
                <label for="refundSearch">Search Refund History</label>
                <input
                    type="search"
                    id="refundSearch"
                    name="refund_q"
                    class="cancelled-filter-search"
                    value="<?= htmlspecialchars($refund_search) ?>"
                    placeholder="Order number, claim number, customer..."
                    autocomplete="off"
                    data-refund-search
                >
            </div>

            <div class="cancelled-filter-actions">
                <button type="submit" class="btn btn-sm cancelled-filter-apply">
                    <i class="bi bi-search me-1"></i>
                    Search
                </button>

                <a
                    href="<?= htmlspecialchars(adminOrdersUrl([
                        'refund_q' => '',
                        'refund_status' => 'pending',
                        'refund_period' => 'today',
                        'refund_date' => date('Y-m-d'),
                        'refund_open' => '1'
                    ])) ?>"
                    class="btn btn-sm cancelled-clear-link"
                    data-refund-clear
                >
                    Clear
                </a>
            </div>

        </form>

        <div class="small text-muted mb-3">
            <?php if ($refund_history_mode): ?>
                Showing <?= $pending_refund_filtered_count ?> refund history record(s)
                <?php if ($refund_period === 'specific_date'): ?>
                    for <?= htmlspecialchars(date('M d, Y', strtotime($refund_date))) ?>
                <?php else: ?>
                    for <?= htmlspecialchars($refund_period_labels[$refund_period] ?? 'Today') ?>
                <?php endif; ?>
            <?php else: ?>
                Showing <?= $pending_refund_filtered_count ?> current pending refund(s)
            <?php endif; ?>
        </div>

        <div class="small text-muted mb-3">
            <?= $refund_history_mode
                ? 'Searching refund history. Use the status and date filters to narrow the results.'
                : 'Current pending refunds are shown. Use the search bar to view refund history.' ?>
        </div>

        <?php if (empty($pending_refund_orders)): ?>

            <div class="order-card empty-state">
                <i class="bi bi-arrow-counterclockwise fs-1 d-block mb-2"></i>
                <div>
                    <?php if ($refund_history_mode): ?>
                        No refund history found for this search/filter.
                    <?php elseif ($refund_status_filter !== 'pending'): ?>
                        Use the search bar to view <?= htmlspecialchars(ucfirst($refund_status_filter)) ?> refund history.
                    <?php else: ?>
                        No pending GCash refunds at the moment.
                    <?php endif; ?>
                </div>
            </div>

        <?php else: ?>

            <div class="orders-grid">

                <?php foreach ($pending_refund_orders as $pending_refund_order): ?>

                    <?php
                    $pending_refund_order_id = (int)$pending_refund_order['id'];
                    $pending_refund_items = $order_items[$pending_refund_order_id] ?? [];
                    $pending_refund_actor = $cancelled_by_roles[$pending_refund_order_id] ?? null;
                    $pending_refund_proof = adminAssetPath(
                        $pending_refund_order['payment_screenshot'] ?? ''
                    );
                    $pending_refund_processed_proof = adminAssetPath(
                        $pending_refund_order['refund_proof_image'] ?? ''
                    );
                    $pending_refund_status =
                        strtolower(trim((string)($pending_refund_order['refund_status'] ?? 'pending')));

                    $pending_refund_status_label = match ($pending_refund_status) {
                        'refunded' => 'Refunded',
                        'rejected' => 'Refund Rejected',
                        default => 'Refund Pending'
                    };
                    ?>

                    <div
                        class="order-card cancelled-order-card pending-refund-order-card"
                        id="pending-refund-order-<?= $pending_refund_order_id ?>"
                        data-order-id="<?= $pending_refund_order_id ?>"
                    >

                        <div class="d-flex justify-content-between flex-wrap gap-2">

                            <div>
                                <div class="fw-bold fs-5" style="color:#4A3525;">
                                    <?= htmlspecialchars(
                                        $pending_refund_order['order_number']
                                        ?: 'ORD-' . $pending_refund_order_id
                                    ) ?>
                                </div>

                                <div class="text-muted small">
                                    Claim No:
                                    <?= htmlspecialchars(
                                        $pending_refund_order['claim_number']
                                        ?: 'N/A'
                                    ) ?>
                                </div>
                            </div>

                            <div class="text-end">
                                <span class="status-badge status-cancelled">
                                    <?= htmlspecialchars($pending_refund_status_label) ?>
                                </span>
                            </div>

                        </div>

                        <hr>

                        <div class="row small">

                            <div class="col-md-4 mb-3">
                                <div class="info-label">Customer</div>
                                <div class="info-value">
                                    <?= htmlspecialchars($pending_refund_order['customer_name']) ?>
                                </div>
                                <div class="text-muted">
                                    <?= htmlspecialchars($pending_refund_order['contact_number']) ?>
                                </div>
                            </div>

                            <div class="col-md-4 mb-3">
                                <div class="info-label">Pick-up</div>
                                <div class="info-value">
                                    <?= htmlspecialchars($pending_refund_order['pickup_date']) ?>
                                    @
                                    <?= htmlspecialchars(adminFormatTime($pending_refund_order['pickup_time'])) ?>
                                </div>
                            </div>

                            <div class="col-md-4 mb-3">
                                <div class="info-label">Payment</div>
                                <div class="info-value text-uppercase payment-gcash">
                                    GCash
                                </div>
                            </div>

                        </div>

                        <div class="cancellation-box mt-1">

                            <div class="row small">

                                <div class="col-md-4 mb-2 mb-md-0">
                                    <div class="info-label">Cancellation Reason</div>
                                    <div class="info-value text-danger">
                                        <?= !empty($pending_refund_order['cancellation_reason'])
                                            ? htmlspecialchars($pending_refund_order['cancellation_reason'])
                                            : 'No reason recorded.' ?>
                                    </div>
                                </div>

                                <div class="col-md-4 mb-2 mb-md-0">
                                    <div class="info-label">Cancelled At</div>
                                    <div class="info-value">
                                        <?= !empty($pending_refund_order['closed_at'])
                                            ? htmlspecialchars(
                                                date(
                                                    'M d, Y h:i A',
                                                    strtotime($pending_refund_order['closed_at'])
                                                )
                                            )
                                            : '—' ?>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <div class="info-label">Cancelled By</div>
                                    <div class="info-value text-danger">
                                        <?= htmlspecialchars(
                                            adminCancellationActorLabel($pending_refund_actor)
                                        ) ?>
                                    </div>
                                </div>

                            </div>

                        </div>

                        <?php if ($pending_refund_status === 'pending'): ?>
                            <div class="pending-refund-action-box mt-3">

                                <div class="pending-refund-action-header">
                                    <div>
                                        <div class="details-section-title mb-1">
                                            Refund Processing
                                        </div>
                                        <div class="small text-muted">
                                            Requested:
                                            <?= !empty($pending_refund_order['refund_requested_at'])
                                                ? htmlspecialchars(
                                                    date(
                                                        'M d, Y h:i A',
                                                        strtotime($pending_refund_order['refund_requested_at'])
                                                    )
                                                )
                                                : '—' ?>
                                        </div>
                                    </div>

                                    <span class="pending-refund-action-note">
                                        Review the proof before choosing an action.
                                    </span>
                                </div>

                                <div class="pending-refund-action-buttons">
                                    <?php if ($pending_refund_proof): ?>
                                        <button
                                            type="button"
                                            class="pending-refund-btn pending-refund-btn-proof"
                                            data-bs-toggle="modal"
                                            data-bs-target="#pendingPaymentProofModal<?= $pending_refund_order_id ?>"
                                        >
                                            <i class="bi bi-image me-1"></i>
                                            Open Payment Proof
                                        </button>
                                    <?php endif; ?>

                                    <button
                                        type="button"
                                        class="pending-refund-btn pending-refund-btn-refunded"
                                        data-bs-toggle="modal"
                                        data-bs-target="#processRefundModal<?= $pending_refund_order_id ?>"
                                    >
                                        <i class="bi bi-check-circle me-1"></i>
                                        Mark as Refunded
                                    </button>

                                    <button
                                        type="button"
                                        class="pending-refund-btn pending-refund-btn-rejected"
                                        data-bs-toggle="modal"
                                        data-bs-target="#rejectRefundModal<?= $pending_refund_order_id ?>"
                                    >
                                        <i class="bi bi-x-circle me-1"></i>
                                        Reject Refund
                                    </button>
                                </div>

                            </div>
                        <?php elseif ($refund_history_mode): ?>
                            <div class="pending-refund-action-box mt-3">
                                <div class="pending-refund-action-header">
                                    <div>
                                        <div class="details-section-title mb-1">
                                            Refund History
                                        </div>
                                        <div class="small text-muted">
                                            <?= $pending_refund_status === 'refunded' ? 'Processed: ' : 'Rejected: ' ?>
                                            <?= !empty($pending_refund_order['refund_processed_at'])
                                                ? htmlspecialchars(
                                                    date(
                                                        'M d, Y h:i A',
                                                        strtotime($pending_refund_order['refund_processed_at'])
                                                    )
                                                )
                                                : '—' ?>
                                        </div>
                                    </div>
                                </div>

                                <?php if ($pending_refund_status === 'refunded' && $pending_refund_processed_proof): ?>
                                    <div class="mt-3">
                                        <button
                                            type="button"
                                            class="pending-refund-btn pending-refund-btn-proof w-100 w-md-auto"
                                            data-bs-toggle="modal"
                                            data-bs-target="#refundProcessedProofModal<?= $pending_refund_order_id ?>"
                                        >
                                            <i class="bi bi-image me-1"></i>
                                            View Refund Proof
                                        </button>
                                    </div>
                                <?php endif; ?>

                                <?php if ($pending_refund_status === 'rejected' && !empty($pending_refund_order['refund_rejection_reason'])): ?>
                                    <div class="small text-danger mt-2">
                                        Reason:
                                        <?= htmlspecialchars($pending_refund_order['refund_rejection_reason']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <!-- GCASH PAYMENT PROOF MODAL -->
                        <?php if ($pending_refund_proof): ?>
                            <div
                                class="modal fade pending-refund-modal"
                                id="pendingPaymentProofModal<?= $pending_refund_order_id ?>"
                                tabindex="-1"
                                aria-hidden="true"
                            >
                                <div class="modal-dialog modal-dialog-centered modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <div>
                                                <h5 class="modal-title fw-bold mb-1">
                                                    GCash Payment Proof
                                                </h5>
                                                <div class="small text-muted">
                                                    <?= htmlspecialchars(
                                                        $pending_refund_order['order_number']
                                                        ?: 'Order #' . $pending_refund_order_id
                                                    ) ?>
                                                </div>
                                            </div>

                                            <button
                                                type="button"
                                                class="btn-close"
                                                data-bs-dismiss="modal"
                                                aria-label="Close"
                                            ></button>
                                        </div>

                                        <div class="modal-body pending-refund-proof-modal-body">
                                            <img
                                                src="<?= htmlspecialchars($pending_refund_proof) ?>"
                                                alt="GCash Payment Proof"
                                                class="pending-refund-proof-image"
                                            >
                                        </div>

                                        <div class="modal-footer">
                                            <button
                                                type="button"
                                                class="btn btn-outline-dark"
                                                data-bs-dismiss="modal"
                                            >
                                                Close
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- MARK AS REFUNDED MODAL -->
                        <div
                            class="modal fade pending-refund-modal"
                            id="processRefundModal<?= $pending_refund_order_id ?>"
                            tabindex="-1"
                            aria-hidden="true"
                        >
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content">
                                    <form method="POST" enctype="multipart/form-data">
                                        <div class="modal-header">
                                            <div>
                                                <h5 class="modal-title fw-bold mb-1">
                                                    Mark as Refunded
                                                </h5>
                                                <div class="small text-muted">
                                                    <?= htmlspecialchars(
                                                        $pending_refund_order['order_number']
                                                        ?: 'this order'
                                                    ) ?>
                                                </div>
                                            </div>

                                            <button
                                                type="button"
                                                class="btn-close"
                                                data-bs-dismiss="modal"
                                                aria-label="Close"
                                            ></button>
                                        </div>

                                        <div class="modal-body">
                                            <div class="refund-proof-upload-info">
                                                <i class="bi bi-info-circle-fill"></i>
                                                <div>
                                                    Upload a screenshot or photo showing the successful GCash refund transaction.
                                                    This proof will be saved with the order and the customer will be notified.
                                                </div>
                                            </div>

                                            <div class="mb-3">
                                                <label
                                                    class="form-label small fw-semibold"
                                                    for="refundProofImage<?= $pending_refund_order_id ?>"
                                                >
                                                    Refund Proof
                                                </label>
                                                <input
                                                    type="file"
                                                    id="refundProofImage<?= $pending_refund_order_id ?>"
                                                    name="refund_proof_image"
                                                    class="form-control"
                                                    accept="image/jpeg,image/png"
                                                    required
                                                >
                                                <div class="form-text">
                                                    JPG, JPEG, or PNG. Maximum 5MB.
                                                </div>
                                            </div>

                                            <input type="hidden" name="process_refund" value="1">
                                            <input type="hidden" name="order_id" value="<?= $pending_refund_order_id ?>">
                                            <input type="hidden" name="status_filter" value="<?= htmlspecialchars($selected_status) ?>">
                                            <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>">
                                            <input type="hidden" name="page" value="<?= $page ?>">
                                        </div>

                                        <div class="modal-footer">
                                            <button
                                                type="button"
                                                class="btn btn-outline-dark"
                                                data-bs-dismiss="modal"
                                            >
                                                Cancel
                                            </button>

                                            <button
                                                type="submit"
                                                class="btn pending-refund-modal-confirm-btn"
                                            >
                                                <i class="bi bi-check-circle me-1"></i>
                                                Confirm Refund
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- PROCESSED REFUND PROOF MODAL -->
                        <?php if ($pending_refund_processed_proof): ?>
                            <div
                                class="modal fade pending-refund-modal"
                                id="refundProcessedProofModal<?= $pending_refund_order_id ?>"
                                tabindex="-1"
                                aria-hidden="true"
                            >
                                <div class="modal-dialog modal-dialog-centered modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <div>
                                                <h5 class="modal-title fw-bold mb-1">
                                                    Refund Proof
                                                </h5>
                                                <div class="small text-muted">
                                                    <?= htmlspecialchars(
                                                        $pending_refund_order['order_number']
                                                        ?: 'Order #' . $pending_refund_order_id
                                                    ) ?>
                                                </div>
                                            </div>

                                            <button
                                                type="button"
                                                class="btn-close"
                                                data-bs-dismiss="modal"
                                                aria-label="Close"
                                            ></button>
                                        </div>

                                        <div class="modal-body pending-refund-proof-modal-body">
                                            <img
                                                src="<?= htmlspecialchars($pending_refund_processed_proof) ?>"
                                                alt="Refund Proof"
                                                class="pending-refund-proof-image"
                                            >
                                        </div>

                                        <div class="modal-footer">
                                            <button
                                                type="button"
                                                class="btn btn-outline-dark"
                                                data-bs-dismiss="modal"
                                            >
                                                Close
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- REJECT REFUND MODAL -->
                        <div
                            class="modal fade pending-refund-modal"
                            id="rejectRefundModal<?= $pending_refund_order_id ?>"
                            tabindex="-1"
                            aria-hidden="true"
                        >
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content">
                                    <form method="POST">
                                        <div class="modal-header">
                                            <div>
                                                <h5 class="modal-title fw-bold mb-1">
                                                    Reject Refund
                                                </h5>
                                                <div class="small text-muted">
                                                    <?= htmlspecialchars(
                                                        $pending_refund_order['order_number']
                                                        ?: 'this order'
                                                    ) ?>
                                                </div>
                                            </div>

                                            <button
                                                type="button"
                                                class="btn-close"
                                                data-bs-dismiss="modal"
                                                aria-label="Close"
                                            ></button>
                                        </div>

                                        <div class="modal-body">
                                            <div class="refund-rejection-alert">
                                                <i class="bi bi-exclamation-circle-fill"></i>
                                                <span>
                                                    The customer will be notified that the refund was rejected.
                                                </span>
                                            </div>

                                            <label
                                                class="form-label small fw-semibold"
                                                for="refundRejectionReason<?= $pending_refund_order_id ?>"
                                            >
                                                Reason for Rejection
                                            </label>

                                            <textarea
                                                id="refundRejectionReason<?= $pending_refund_order_id ?>"
                                                name="refund_rejection_reason"
                                                class="form-control"
                                                rows="4"
                                                maxlength="255"
                                                placeholder="Enter the reason for rejecting this refund..."
                                                required
                                            ></textarea>

                                            <div class="form-text">
                                                Maximum 255 characters.
                                            </div>

                                            <input type="hidden" name="reject_refund" value="1">
                                            <input type="hidden" name="order_id" value="<?= $pending_refund_order_id ?>">
                                            <input type="hidden" name="status_filter" value="<?= htmlspecialchars($selected_status) ?>">
                                            <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>">
                                            <input type="hidden" name="page" value="<?= $page ?>">
                                        </div>

                                        <div class="modal-footer">
                                            <button
                                                type="button"
                                                class="btn btn-outline-dark"
                                                data-bs-dismiss="modal"
                                            >
                                                Cancel
                                            </button>

                                            <button
                                                type="submit"
                                                class="btn pending-refund-modal-reject-btn"
                                            >
                                                <i class="bi bi-x-circle me-1"></i>
                                                Reject Refund
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="order-items-card mt-3">

                            <div class="order-items-title">
                                <i class="bi bi-cup-straw me-1"></i>
                                Order Items
                            </div>

                            <?php if ($pending_refund_items): ?>

                                <?php foreach ($pending_refund_items as $item): ?>

                                    <?php
                                    $addonDetails = adminGetAddons($item['addons'] ?? null);
                                    ?>

                                    <div class="order-item-row">

                                        <div class="d-flex justify-content-between align-items-start gap-3">

                                            <div class="flex-grow-1">

                                                <div class="order-item-name">
                                                    <?= htmlspecialchars($item['product_name']) ?>
                                                    <span class="order-item-quantity">
                                                        × <?= (int)$item['quantity'] ?>
                                                    </span>
                                                </div>

                                                <div class="order-item-base-price">
                                                    Base Price: ₱<?= number_format(
                                                        (float)($item['unit_price'] ?? 0),
                                                        2
                                                    ) ?> each
                                                </div>

                                                <?php if (
                                                    !empty($item['size'])
                                                    || !empty($item['sugar_level'])
                                                ): ?>
                                                    <div class="order-item-customization">
                                                        <div class="order-item-customization-main">
                                                            <?php if (!empty($item['size'])): ?>
                                                                <span>
                                                                    Size: <?= htmlspecialchars($item['size']) ?>
                                                                </span>
                                                            <?php endif; ?>
                                                            <?php if (!empty($item['sugar_level'])): ?>
                                                                <span>
                                                                    Sugar: <?= htmlspecialchars($item['sugar_level']) ?>
                                                                </span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>

                                                <?php if ($addonDetails): ?>
                                                    <span class="order-addon-label">Add-ons</span>
                                                    <div class="order-addon-list">
                                                        <?php foreach ($addonDetails as $addon): ?>
                                                            <span class="order-addon-chip">
                                                                <?= htmlspecialchars($addon['name']) ?>
                                                            </span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>

                                            </div>

                                            <div class="order-item-total text-end">
                                                ₱<?= number_format(
                                                    (float)($item['total_price'] ?? 0),
                                                    2
                                                ) ?>
                                            </div>

                                        </div>

                                    </div>

                                <?php endforeach; ?>

                            <?php else: ?>
                                <div class="small text-muted">
                                    No item details available.
                                </div>
                            <?php endif; ?>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </div>

</details>

            <?php endif; ?>

<!-- =====================================================
                 CANCELLED ORDERS
                 Collapsed by default so historical records do not
                 make the Orders page unnecessarily long.
            ====================================================== -->
            <details
                class="cancelled-orders-section"
                <?= (
                    $cancelled_search !== ''
                    || $cancelled_open === '1'
                ) ? 'open' : '' ?>
            >
                <summary class="cancelled-section-summary">

                    <div class="cancelled-section-heading">

                        <div>
                            <h3 class="cancelled-section-title">
                                <i class="bi bi-x-circle me-1"></i>
                                CANCELLED ORDERS
                            </h3>

                            <p class="cancelled-section-description">
                                View orders that were cancelled during the order process.
                            </p>
                        </div>

                        <div class="text-end">
                            <div class="cancelled-history-badge mb-2">
                                <i class="bi bi-archive-fill"></i>
                                Historical Records
                            </div>
                            <div class="small text-muted">
                                <?= $cancelled_total_orders ?> cancelled order(s)
                            </div>
                        </div>

                    </div>

                    <div class="cancelled-toggle" aria-hidden="true">
                        <span class="cancelled-toggle-label cancelled-toggle-open">
                            Open
                        </span>
                        <span class="cancelled-toggle-label cancelled-toggle-close">
                            Close
                        </span>
                        <i class="bi bi-chevron-down cancelled-toggle-icon"></i>
                    </div>

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

                            <label for="cancelledPeriod">
                                Cancelled Date
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

                        <div class="cancelled-filter-group">

                            <label for="cancelledSearch">
                                Search Cancelled Orders
                            </label>

                            <input
                                type="search"
                                id="cancelledSearch"
                                name="cancelled_q"
                                class="cancelled-filter-search"
                                value="<?= htmlspecialchars($cancelled_search) ?>"
                                placeholder="Order number, claim number, customer..."
                                autocomplete="off"
                                data-cancelled-search
                            >

                        </div>

                        <div class="cancelled-filter-actions">

                            <button
                                type="submit"
                                class="btn search-button"
                            >
                                <i class="bi bi-search me-1"></i>
                                Search
                            </button>

                            <?php if ($cancelled_search !== ''): ?>

                                <a
                                    href="<?= htmlspecialchars(
                                        adminOrdersUrl([
                                            'cancelled_q' => '',
                                            'cancelled_page' => 1,
                                            'cancelled_open' => '1'
                                        ])
                                    ) ?>"
                                    class="btn clear-button cancelled-clear-link"
                                >
                                    Clear
                                </a>

                            <?php endif; ?>

                        </div>

                    </form>

                    <?php if (empty($cancelled_orders)): ?>

                        <div class="order-card empty-state">

                            <i class="bi bi-inbox fs-1 d-block mb-2"></i>

                            <?php if (
                                $cancelled_search !== ''
                            ): ?>

                                <div>
                                    No cancelled orders matched the selected filters.
                                </div>

                            <?php else: ?>

                                <div>
                                    No cancelled orders have been recorded.
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
                            cancelled order(s)

                        </div>

                        <div class="orders-grid">

                            <?php foreach ($cancelled_orders as $cancelled_order): ?>

                                <?php
                                $cancelled_order_id =
                                    (int)$cancelled_order['id'];

                                $cancelled_items =
                                    $order_items[$cancelled_order_id] ?? [];

                                $cancelled_by_role =
                                    $cancelled_by_roles[$cancelled_order_id]
                                    ?? null;

                                $cancelled_payment_proof =
                                    adminAssetPath(
                                        $cancelled_order['payment_screenshot']
                                        ?? ''
                                    );
                                ?>

                                <div
                                    class="order-card cancelled-order-card <?= (
                                        false
                                    ) ? 'view-order-target' : '' ?>"
                                    id="order-<?= $cancelled_order_id ?>"
                                    data-order-id="<?= $cancelled_order_id ?>"
                                >

                                    <div class="d-flex justify-content-between flex-wrap gap-2">

                                        <div>

                                            <div
                                                class="fw-bold fs-5"
                                                style="color:#4A3525;"
                                            >
                                                <?= htmlspecialchars(
                                                    $cancelled_order['order_number']
                                                    ?: 'ORD-' . $cancelled_order_id
                                                ) ?>
                                            </div>

                                            <div class="text-muted small">
                                                Claim No:
                                                <?= htmlspecialchars(
                                                    $cancelled_order['claim_number']
                                                    ?: 'N/A'
                                                ) ?>
                                            </div>

                                        </div>

                                        <div class="text-end">

                                            <span class="status-badge status-cancelled">
                                                Cancelled
                                            </span>

                                        </div>

                                    </div>

                                    <hr>

                                    <div class="row small">

                                        <div class="col-md-4 mb-3">

                                            <div class="info-label">
                                                Customer
                                            </div>

                                            <div class="info-value">
                                                <?= htmlspecialchars(
                                                    $cancelled_order['customer_name']
                                                ) ?>
                                            </div>

                                            <div class="text-muted">
                                                <?= htmlspecialchars(
                                                    $cancelled_order['contact_number']
                                                ) ?>
                                            </div>

                                        </div>

                                        <div class="col-md-4 mb-3">

                                            <div class="info-label">
                                                Pick-up
                                            </div>

                                            <div class="info-value">
                                                <?= htmlspecialchars(
                                                    $cancelled_order['pickup_date']
                                                ) ?>
                                                @
                                                <?= htmlspecialchars(
                                                    adminFormatTime(
                                                        $cancelled_order['pickup_time']
                                                    )
                                                ) ?>
                                            </div>

                                        </div>

                                        <div class="col-md-4 mb-3">

                                            <div class="info-label">
                                                Payment
                                            </div>

                                            <div
                                                class="info-value text-uppercase <?=
                                                    strtolower(trim((string)$cancelled_order['payment_method'])) === 'gcash'
                                                        ? 'payment-gcash'
                                                        : ''
                                                ?>"
                                            >
                                                <?= htmlspecialchars(
                                                    $cancelled_order['payment_method']
                                                ) ?>
                                            </div>

                                        </div>

                                    </div>

                                    <div class="cancellation-box mt-1">

                                        <div class="row small">

                                            <div class="col-md-4 mb-2 mb-md-0">
                                                <div class="info-label">
                                                    Cancellation Reason
                                                </div>

                                                <div class="info-value text-danger">
                                                    <?= !empty(
                                                        $cancelled_order['cancellation_reason']
                                                    )
                                                        ? htmlspecialchars(
                                                            $cancelled_order['cancellation_reason']
                                                        )
                                                        : 'No reason recorded.' ?>
                                                </div>
                                            </div>

                                            <div class="col-md-4 mb-2 mb-md-0">
                                                <div class="info-label">
                                                    Cancelled At
                                                </div>

                                                <div class="info-value">
                                                    <?= !empty($cancelled_order['closed_at'])
                                                        ? htmlspecialchars(
                                                            date(
                                                                'M d, Y h:i A',
                                                                strtotime(
                                                                    $cancelled_order['closed_at']
                                                                )
                                                            )
                                                        )
                                                        : '—' ?>
                                                </div>
                                            </div>

                                            <div class="col-md-4">
                                                <div class="info-label">
                                                    Cancelled By
                                                </div>

                                                <div class="info-value text-danger">
                                                    <?= htmlspecialchars(
                                                        adminCancellationActorLabel(
                                                            $cancelled_by_role
                                                        )
                                                    ) ?>
                                                </div>
                                            </div>

                                        </div>

                                    </div>

                                    <div class="order-items-card mt-3">

                                        <div class="order-items-title">
                                            <i class="bi bi-cup-straw me-1"></i>
                                            Order Items
                                        </div>

                                        <?php if ($cancelled_items): ?>

                                            <?php foreach ($cancelled_items as $item): ?>

                                                <?php
                                                $addonDetails = adminGetAddons(
                                                    $item['addons'] ?? null
                                                );
                                                ?>

                                                <div class="order-item-row">

                                                    <div class="d-flex justify-content-between align-items-start gap-3">

                                                        <div class="flex-grow-1">

                                                            <div class="order-item-name">
                                                                <?= htmlspecialchars(
                                                                    $item['product_name']
                                                                ) ?>

                                                                <span class="order-item-quantity">
                                                                    × <?= (int)$item['quantity'] ?>
                                                                </span>
                                                            </div>

                                                            <div class="order-item-base-price">
                                                                Base Price: ₱<?= number_format(
                                                                    (float)($item['unit_price'] ?? 0),
                                                                    2
                                                                ) ?> each
                                                            </div>

                                                            <?php if (
                                                                !empty($item['size'])
                                                                || !empty($item['sugar_level'])
                                                            ): ?>

                                                                <div class="order-item-customization">
                                                                    <div class="order-item-customization-main">

                                                                        <?php if (!empty($item['size'])): ?>
                                                                            <span>
                                                                                Size: <?= htmlspecialchars(
                                                                                    $item['size']
                                                                                ) ?>
                                                                            </span>
                                                                        <?php endif; ?>

                                                                        <?php if (!empty($item['sugar_level'])): ?>
                                                                            <span>
                                                                                Sugar: <?= htmlspecialchars(
                                                                                    $item['sugar_level']
                                                                                ) ?>
                                                                            </span>
                                                                        <?php endif; ?>

                                                                    </div>
                                                                </div>

                                                            <?php endif; ?>

                                                            <?php if ($addonDetails): ?>

                                                                <span class="order-addon-label">
                                                                    Add-ons
                                                                </span>

                                                                <div class="order-addon-list">

                                                                    <?php foreach ($addonDetails as $addon): ?>

                                                                        <span class="order-addon-chip">

                                                                            <?= htmlspecialchars(
                                                                                $addon['name']
                                                                            ) ?>

                                                                            <span class="order-addon-chip-price">
                                                                                +₱<?= number_format(
                                                                                    (float)$addon['price'],
                                                                                    2
                                                                                ) ?>
                                                                            </span>

                                                                        </span>

                                                                    <?php endforeach; ?>

                                                                </div>

                                                            <?php endif; ?>

                                                        </div>

                                                        <div class="order-item-price">
                                                            ₱<?= number_format(
                                                                (float)($item['subtotal'] ?? 0),
                                                                2
                                                            ) ?>
                                                        </div>

                                                    </div>

                                                </div>

                                            <?php endforeach; ?>

                                        <?php else: ?>

                                            <div class="text-muted small">
                                                No order items were found for this order.
                                            </div>

                                        <?php endif; ?>

                                        <div class="order-total-summary">

                                            <span class="order-total-label">
                                                Total
                                            </span>

                                            <span class="order-total-amount">
                                                ₱<?= number_format(
                                                    (float)$cancelled_order['total_amount'],
                                                    2
                                                ) ?>
                                            </span>

                                        </div>

                                    </div>

                                    <div class="order-actions">

                                        <button
                                            type="button"
                                            class="action-btn btn-outline-dark border"
                                            data-bs-toggle="modal"
                                            data-bs-target="#detailsModal<?= $cancelled_order_id ?>"
                                        >
                                            <i class="bi bi-eye me-1"></i>
                                            View Details
                                        </button>

                                    </div>

                                </div>

                                <!-- CANCELLED ORDER DETAILS MODAL -->
                                <div
                                    class="modal fade"
                                    id="detailsModal<?= $cancelled_order_id ?>"
                                    tabindex="-1"
                                    aria-hidden="true"
                                >
                                    <div class="modal-dialog modal-lg modal-dialog-centered">

                                        <div class="modal-content">

                                            <div class="modal-header">

                                                <h5 class="modal-title fw-bold">
                                                    Cancelled Order Details
                                                </h5>

                                                <button
                                                    type="button"
                                                    class="btn-close"
                                                    data-bs-dismiss="modal"
                                                    aria-label="Close"
                                                ></button>

                                            </div>

                                            <div class="modal-body">

                                                <div class="mb-3">

                                                    <div class="details-section-title">
                                                        Order Information
                                                    </div>

                                                    <div class="row small">

                                                        <div class="col-md-6 mb-2">
                                                            <div class="info-label">
                                                                Order Number
                                                            </div>
                                                            <div class="info-value">
                                                                <?= htmlspecialchars(
                                                                    $cancelled_order['order_number']
                                                                    ?: 'ORD-' . $cancelled_order_id
                                                                ) ?>
                                                            </div>
                                                        </div>

                                                        <div class="col-md-6 mb-2">
                                                            <div class="info-label">
                                                                Claim Number
                                                            </div>
                                                            <div class="info-value">
                                                                <?= htmlspecialchars(
                                                                    $cancelled_order['claim_number']
                                                                    ?: 'N/A'
                                                                ) ?>
                                                            </div>
                                                        </div>

                                                        <div class="col-md-6 mb-2">
                                                            <div class="info-label">
                                                                Customer
                                                            </div>
                                                            <div class="info-value">
                                                                <?= htmlspecialchars(
                                                                    $cancelled_order['customer_name']
                                                                ) ?>
                                                            </div>
                                                        </div>

                                                        <div class="col-md-6 mb-2">
                                                            <div class="info-label">
                                                                Payment
                                                            </div>
                                                            <div
                                                                class="info-value text-uppercase <?=
                                                                    strtolower(trim((string)$cancelled_order['payment_method'])) === 'gcash'
                                                                        ? 'payment-gcash'
                                                                        : ''
                                                                ?>"
                                                            >
                                                                <?= htmlspecialchars(
                                                                    $cancelled_order['payment_method']
                                                                ) ?>
                                                            </div>
                                                        </div>

                                                    </div>

                                                </div>

                                                <div class="cancellation-box mb-3">

                                                    <div class="details-section-title text-danger">
                                                        Cancellation Details
                                                    </div>

                                                    <div class="row small">

                                                        <div class="col-md-4 mb-2">
                                                            <div class="info-label">
                                                                Reason
                                                            </div>
                                                            <div class="info-value">
                                                                <?= !empty(
                                                                    $cancelled_order['cancellation_reason']
                                                                )
                                                                    ? htmlspecialchars(
                                                                        $cancelled_order['cancellation_reason']
                                                                    )
                                                                    : 'No reason recorded.' ?>
                                                            </div>
                                                        </div>

                                                        <div class="col-md-4 mb-2">
                                                            <div class="info-label">
                                                                Cancelled At
                                                            </div>
                                                            <div class="info-value">
                                                                <?= !empty($cancelled_order['closed_at'])
                                                                    ? htmlspecialchars(
                                                                        date(
                                                                            'M d, Y h:i A',
                                                                            strtotime(
                                                                                $cancelled_order['closed_at']
                                                                            )
                                                                        )
                                                                    )
                                                                    : '—' ?>
                                                            </div>
                                                        </div>

                                                        <div class="col-md-4 mb-2">
                                                            <div class="info-label">
                                                                Cancelled By
                                                            </div>
                                                            <div class="info-value">
                                                                <?= htmlspecialchars(
                                                                    adminCancellationActorLabel(
                                                                        $cancelled_by_role
                                                                    )
                                                                ) ?>
                                                            </div>
                                                        </div>

                                                    </div>

                                                </div>

                                                <?php if ($cancelled_payment_proof && strtolower(
                                                    trim((string)$cancelled_order['payment_method'])
                                                ) === 'gcash'): ?>

                                                    <div class="mb-3">

                                                        <div class="details-section-title">
                                                            GCash Payment Proof
                                                        </div>

                                                        <a
                                                            href="<?= htmlspecialchars($cancelled_payment_proof) ?>"
                                                            target="_blank"
                                                            rel="noopener"
                                                            class="btn btn-sm btn-outline-dark"
                                                        >
                                                            <i class="bi bi-image me-1"></i>
                                                            Open Payment Proof
                                                        </a>

                                                    </div>

                                                <?php endif; ?>

                                            </div>

                                        </div>

                                    </div>
                                </div>

                            <?php endforeach; ?>

                        </div>

                        <?php if ($cancelled_total_pages > 1): ?>

                            <div class="cancelled-pagination" aria-label="Cancelled order pagination">

                                <?php if ($cancelled_page > 1): ?>

                                    <a
                                        href="<?= htmlspecialchars(
                                            adminOrdersUrl([
                                                'cancelled_page' => $cancelled_page - 1,
                                                'cancelled_open' => '1'
                                            ])
                                        ) ?>"
                                        aria-label="Previous page"
                                        data-cancelled-pagination
                                    >
                                        <i class="bi bi-chevron-left"></i>
                                    </a>

                                <?php else: ?>

                                    <span class="disabled" aria-hidden="true">
                                        <i class="bi bi-chevron-left"></i>
                                    </span>

                                <?php endif; ?>

                                <?php
                                $cancelledPaginationPages = [1];

                                for (
                                    $cancelled_p = max(2, $cancelled_page - 2);
                                    $cancelled_p <= min(
                                        $cancelled_total_pages - 1,
                                        $cancelled_page + 2
                                    );
                                    $cancelled_p++
                                ) {
                                    $cancelledPaginationPages[] = $cancelled_p;
                                }

                                $cancelledPaginationPages[] = $cancelled_total_pages;

                                $cancelledPaginationPages = array_values(
                                    array_unique($cancelledPaginationPages)
                                );

                                $cancelledPreviousPage = 0;

                                foreach (
                                    $cancelledPaginationPages
                                    as $cancelled_p
                                ):

                                    if (
                                        $cancelledPreviousPage > 0
                                        && $cancelled_p > $cancelledPreviousPage + 1
                                    ):
                                ?>

                                        <span class="ellipsis" aria-hidden="true">
                                            ...
                                        </span>

                                    <?php endif; ?>

                                    <?php if ($cancelled_p === $cancelled_page): ?>

                                        <span
                                            class="active"
                                            aria-current="page"
                                        >
                                            <?= $cancelled_p ?>
                                        </span>

                                    <?php else: ?>

                                        <a
                                            href="<?= htmlspecialchars(
                                                adminOrdersUrl([
                                                    'cancelled_page' => $cancelled_p,
                                                    'cancelled_open' => '1'
                                                ])
                                            ) ?>"
                                            data-cancelled-pagination
                                        >
                                            <?= $cancelled_p ?>
                                        </a>

                                    <?php endif; ?>

                                <?php
                                    $cancelledPreviousPage = $cancelled_p;
                                endforeach;
                                ?>

                                <?php if ($cancelled_page < $cancelled_total_pages): ?>

                                    <a
                                        href="<?= htmlspecialchars(
                                            adminOrdersUrl([
                                                'cancelled_page' => $cancelled_page + 1,
                                                'cancelled_open' => '1'
                                            ])
                                        ) ?>"
                                        aria-label="Next page"
                                        data-cancelled-pagination
                                    >
                                        <i class="bi bi-chevron-right"></i>
                                    </a>

                                <?php else: ?>

                                    <span class="disabled" aria-hidden="true">
                                        <i class="bi bi-chevron-right"></i>
                                    </span>

                                <?php endif; ?>

                            </div>

                        <?php endif; ?>

                    <?php endif; ?>

                </div>

            </details>


    </main>

</div>

<!-- =========================================================
     SHARED ORDER MODALS
     These stay outside the AJAX-replaced Active Orders section.
========================================================= -->

<!-- SHARED CANCEL ORDER MODAL -->
<div class="modal fade" id="adminCancelOrderModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">

            <form
                method="POST"
                action="<?= htmlspecialchars($orders_page, ENT_QUOTES, 'UTF-8') ?>"
                id="adminCancelOrderForm"
            >

                <div class="modal-header">
                    <h5
                        class="modal-title fw-bold"
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
                        You are cancelling
                        <strong id="adminCancelOrderNumber">
                            this order
                        </strong>.
                        Please select a cancellation reason.
                    </p>

                    <input
                        type="hidden"
                        name="cancel_order"
                        value="1"
                    >

                    <input
                        type="hidden"
                        name="order_id"
                        id="adminCancelOrderId"
                        value=""
                    >

                    <input
                        type="hidden"
                        name="status_filter"
                        id="adminCancelStatusFilter"
                        value="<?= htmlspecialchars($selected_status) ?>"
                    >

                    <input
                        type="hidden"
                        name="q"
                        id="adminCancelSearch"
                        value="<?= htmlspecialchars($search) ?>"
                    >

                    <input
                        type="hidden"
                        name="page"
                        id="adminCancelPage"
                        value="<?= (int)$page ?>"
                    >

                    <div class="mb-3">

                        <label
                            class="form-label small fw-semibold"
                            for="adminCancelReason"
                        >
                            Cancellation Reason
                        </label>

                        <select
                            name="cancellation_reason"
                            id="adminCancelReason"
                            class="form-select"
                            required
                        >
                            <option
                                value=""
                                selected
                                disabled
                            >
                                Select a cancellation reason
                            </option>

                            <optgroup label="Payment-related reasons">

                                <option value="Payment could not be verified">
                                    Payment could not be verified
                                </option>

                                <option value="Payment screenshot does not match order total">
                                    Payment screenshot does not match order total
                                </option>

                            </optgroup>

                            <optgroup label="Store-caused reasons">

                                <option value="Item unavailable / out of stock">
                                    Item unavailable / out of stock
                                </option>

                                <option value="Store unable to fulfill due to closure or operational issue">
                                    Store unable to fulfill due to closure or operational issue
                                </option>

                                <option value="Pricing or system error on the order">
                                    Pricing or system error on the order
                                </option>

                            </optgroup>

                            <optgroup label="Other reasons">

                                <option value="Customer requested cancellation">
                                    Customer requested cancellation
                                </option>

                                <option value="Duplicate order">
                                    Duplicate order
                                </option>

                                <option value="Incorrect order details">
                                    Incorrect order details
                                </option>

                                <option value="__other__">
                                    Other — please specify
                                </option>

                            </optgroup>
                        </select>

                    </div>

                    <div
                        id="adminOtherCancellationReasonWrap"
                        class="mb-2"
                        style="display:none;"
                    >

                        <label
                            class="form-label small fw-semibold"
                            for="adminOtherCancellationReason"
                        >
                            Other Cancellation Reason
                        </label>

                        <textarea
                            name="other_cancellation_reason"
                            id="adminOtherCancellationReason"
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
                        id="adminConfirmCancellationButton"
                    >
                        <i class="bi bi-x-circle me-1"></i>
                        Confirm Cancellation
                    </button>

                </div>

            </form>

        </div>
    </div>
</div>

<!-- SHARED PAYMENT PROOF MODAL -->
<div class="modal fade" id="adminPaymentProofModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header details-header">
                <h5 class="modal-title fw-bold" style="color:#4A3525;">GCash Payment Proof</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <img
                    id="adminPaymentProofImage"
                    src=""
                    alt="GCash payment proof"
                    class="payment-proof-image"
                >
            </div>
        </div>
    </div>
</div>

<!-- =========================================================
     RELIABLE ORDER DETAIL / SHARED MODAL HANDLERS
========================================================= -->
<script>
document.addEventListener('DOMContentLoaded', function () {

    const adminOrdersContent = document.querySelector('.admin-content');

    function cleanupStaleModalState() {
        document.querySelectorAll('.modal-backdrop').forEach(function (backdrop) {
            backdrop.remove();
        });

        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('padding-right');
        document.body.style.removeProperty('overflow');
    }


    function openLocaliteaModal(modalElement) {
        if (!modalElement) {
            return;
        }

        /* Remove stale Localitea/Bootstrap backdrops first. */
        document
            .querySelectorAll('[data-localitea-modal-backdrop], .modal-backdrop')
            .forEach(function (backdrop) {
                backdrop.remove();
            });

        /* Close any other visible modal before opening this one. */
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

    function showModalCleanly(modalElement) {
        if (!modalElement) {
            return;
        }

        /*
         * Keep Bootstrap when available, but retain the Localitea fallback.
         * This is important for Staff because the Orders section can be
         * refreshed/re-rendered and Bootstrap's data-api may not reliably
         * bind the newly rendered View Details buttons.
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

            /* Fallback in case Bootstrap's hidden event is interrupted. */
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

    if (adminOrdersContent) {

        adminOrdersContent.addEventListener('click', function (event) {

            /* =====================================================
               VIEW DETAILS
               Open the exact order modal ourselves. Do NOT rely on
               Bootstrap's data-api because this Orders section can be
               refreshed/re-rendered and the dynamically rendered buttons
               may otherwise stop opening their modal.
            ===================================================== */
            const detailsButton = event.target.closest(
                '.btn-view-details[data-order-details-modal]'
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
                        'Staff Order Details modal not found:',
                        modalId
                    );
                    return;
                }

                showModalCleanly(detailsModal);
                return;
            }

            /* =====================================================
               CANCEL ORDER
               Hide the details modal first, then open the shared
               cancellation modal. Nested Bootstrap modals are avoided.
            ===================================================== */
            const cancelButton = event.target.closest(
                '[data-admin-cancel-order]'
            );

            if (cancelButton) {
                event.preventDefault();
                event.stopPropagation();
                event.stopImmediatePropagation();

                const cancelModal =
                    document.getElementById('adminCancelOrderModal');

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
                    document.getElementById('adminCancelOrderId');
                const orderNumberText =
                    document.getElementById('adminCancelOrderNumber');
                const reasonInput =
                    document.getElementById('adminCancelReason');

                const otherReasonWrap =
                    document.getElementById(
                        'adminOtherCancellationReasonWrap'
                    );

                const otherReasonInput =
                    document.getElementById(
                        'adminOtherCancellationReason'
                    );

                if (orderIdInput) orderIdInput.value = orderId;
                if (orderNumberText) {
                    orderNumberText.textContent = orderNumber;
                }

                if (reasonInput) {
                    reasonInput.value = '';
                }

                if (otherReasonInput) {
                    otherReasonInput.value = '';
                    otherReasonInput.required = false;
                }

                if (otherReasonWrap) {
                    otherReasonWrap.style.display = 'none';
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
                '[data-admin-payment-proof]'
            );

            if (proofButton) {
                event.preventDefault();
                event.stopPropagation();
                event.stopImmediatePropagation();

                const proofModal =
                    document.getElementById('adminPaymentProofModal');

                const proofImage =
                    document.getElementById('adminPaymentProofImage');

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
     * delegation area. Handle its close button directly so it works
     * with both Bootstrap and the Localitea fallback modal.
     */
    document.addEventListener('click', function (event) {
        const dismissButton = event.target.closest(
            '#adminPaymentProofModal [data-bs-dismiss="modal"]'
        );

        if (!dismissButton) {
            return;
        }

        const proofModal = document.getElementById(
            'adminPaymentProofModal'
        );

        if (!proofModal) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();

        if (window.bootstrap && bootstrap.Modal) {
            try {
                const instance =
                    bootstrap.Modal.getInstance(proofModal) ||
                    bootstrap.Modal.getOrCreateInstance(proofModal, {
                        backdrop: true,
                        keyboard: true,
                        focus: true
                    });

                instance.hide();
            } catch (error) {
                console.warn('GCash proof modal close failed:', error);
            }
        }

        window.setTimeout(function () {
            proofModal.classList.remove('show');
            proofModal.style.display = 'none';
            proofModal.setAttribute('aria-hidden', 'true');
            proofModal.removeAttribute('aria-modal');

            document.querySelectorAll(
                '.modal-backdrop, [data-localitea-modal-backdrop]'
            ).forEach(function (backdrop) {
                backdrop.remove();
            });

            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('padding-right');
            document.body.style.removeProperty('overflow');

            if (paymentProofImage) {
                paymentProofImage.removeAttribute('src');
            }
        }, 250);
    });

    /*
     * UNIVERSAL MODAL CLOSE HANDLER
     *
     * Do not rely only on Bootstrap's data-api. The Staff Orders
     * section can be refreshed/re-rendered, so every modal close
     * button is handled explicitly in the capture phase.
     *
     * This is intentionally self-contained so the GCash Proof modal,
     * Receipt modal, Order Details modal, and Cancel modal all use the
     * same reliable close behavior.
     */
    document.addEventListener('click', function (event) {
        const dismissButton = event.target.closest('[data-bs-dismiss="modal"]');

        if (!dismissButton) {
            return;
        }

        const modalElement = dismissButton.closest('.modal');

        if (!modalElement) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();

        let bootstrapHandled = false;

        if (window.bootstrap && bootstrap.Modal) {
            try {
                const instance =
                    bootstrap.Modal.getInstance(modalElement) ||
                    bootstrap.Modal.getOrCreateInstance(modalElement, {
                        backdrop: true,
                        keyboard: true,
                        focus: true
                    });

                instance.hide();
                bootstrapHandled = true;
            } catch (error) {
                console.warn('Bootstrap modal close failed:', error);
            }
        }

        /*
         * Always force the visual state closed as a fallback.
         * This prevents a stuck gray backdrop when Bootstrap's
         * transition/event chain is interrupted.
         */
        window.setTimeout(function () {
            modalElement.classList.remove('show');
            modalElement.style.display = 'none';
            modalElement.setAttribute('aria-hidden', 'true');
            modalElement.removeAttribute('aria-modal');
            modalElement.removeAttribute('role');

            document.querySelectorAll(
                '.modal-backdrop, [data-localitea-modal-backdrop]'
            ).forEach(function (backdrop) {
                backdrop.remove();
            });

            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('padding-right');
            document.body.style.removeProperty('overflow');

            if (
                bootstrapHandled &&
                window.bootstrap &&
                bootstrap.Modal
            ) {
                try {
                    const instance = bootstrap.Modal.getInstance(modalElement);
                    if (instance) {
                        instance.dispose();
                    }
                } catch (error) {
                    console.warn('Modal dispose warning:', error);
                }
            }
        }, 250);

        cleanupStaleModalState();
    }, true);

    /* Shared cancellation modal: keep the reason box clean whenever it closes. */
    const cancelModal = document.getElementById('adminCancelOrderModal');

    if (cancelModal) {
        cancelModal.addEventListener('hidden.bs.modal', function () {
            const reasonInput =
                document.getElementById('adminCancelReason');

            if (reasonInput) {
                reasonInput.value = '';
            }

            cleanupStaleModalState();
        });
    }

    /* Shared GCash proof modal cleanup. */
    const paymentProofModal =
        document.getElementById('adminPaymentProofModal');
    const paymentProofImage =
        document.getElementById('adminPaymentProofImage');

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
/* Preserve the Admin's scroll position when searching/filtering orders.
   The page still refreshes normally, but it returns to the previous
   position instead of jumping to the top. */
document.addEventListener('DOMContentLoaded', function () {

    /*
     * When an Admin opened this page from a notification, the target
     * order must take priority over the normal saved-scroll restoration.
     * Otherwise the saved position can immediately move the page away
     * from the order that was just opened.
     */
    const targetOrderFromUrl =
        Number(new URLSearchParams(window.location.search).get('order_id') || 0);

    const savedScrollY =
        sessionStorage.getItem('adminOrdersScrollY');

    if (savedScrollY !== null && !targetOrderFromUrl) {

        sessionStorage.removeItem('adminOrdersScrollY');

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
                'adminOrdersScrollY',
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
                'adminOrdersScrollY',
                String(window.scrollY)
            );

        });

    });

});
</script>


<!-- =========================================================
     SCRIPT 2
     ADMIN PROFILE
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
   Self-contained handler.
   IMPORTANT: this function must not depend on the modal helper
   functions declared inside another DOMContentLoaded callback.
========================================================= */

function printReceipt(modalId) {

    const modalElement = document.getElementById(modalId);

    if (!modalElement) {
        console.error('Receipt modal not found:', modalId);
        return;
    }

    let printStarted = false;
    let cleanupTimer = null;

    /*
     * Close every other modal first.  Use Bootstrap when available,
     * but also force-remove stale modal state so a previous GCash
     * proof/details modal cannot block the receipt.
     */
    document.querySelectorAll('.modal.show').forEach(function (openModal) {
        if (openModal === modalElement) {
            return;
        }

        try {
            if (window.bootstrap && bootstrap.Modal) {
                const instance =
                    bootstrap.Modal.getInstance(openModal) ||
                    bootstrap.Modal.getOrCreateInstance(openModal);

                instance.hide();
            }
        } catch (error) {
            console.warn('Could not hide previous modal:', error);
        }

        openModal.classList.remove('show');
        openModal.style.display = 'none';
        openModal.setAttribute('aria-hidden', 'true');
        openModal.removeAttribute('aria-modal');
    });

    /* Remove stale Bootstrap/Localitea backdrops. */
    document.querySelectorAll(
        '.modal-backdrop, [data-localitea-modal-backdrop]'
    ).forEach(function (backdrop) {
        backdrop.remove();
    });

    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('padding-right');
    document.body.style.removeProperty('overflow');

    /*
     * Open the receipt directly and deterministically.
     * We intentionally do not rely on Bootstrap's data-api here.
     * This also works if Bootstrap's modal plugin is unavailable.
     */
    modalElement.classList.add('show');
    modalElement.style.display = 'block';
    modalElement.removeAttribute('aria-hidden');
    modalElement.setAttribute('aria-modal', 'true');
    modalElement.setAttribute('role', 'dialog');

    document.body.classList.add('modal-open');
    document.body.style.overflow = 'hidden';

    /*
     * The print stylesheet only displays receipt content when the
     * receipt modal has the "show" class, so wait briefly for the
     * browser to apply the layout before opening Print.
     */
    window.setTimeout(function () {
        printStarted = true;

        try {
            window.print();
        } catch (error) {
            console.error('Print dialog could not be opened:', error);
            cleanupReceiptModal();
        }
    }, 350);

    function cleanupReceiptModal() {
        if (cleanupTimer !== null) {
            window.clearTimeout(cleanupTimer);
            cleanupTimer = null;
        }

        modalElement.classList.remove('show');
        modalElement.style.display = 'none';
        modalElement.setAttribute('aria-hidden', 'true');
        modalElement.removeAttribute('aria-modal');
        modalElement.removeAttribute('role');

        document.querySelectorAll(
            '.modal-backdrop, [data-localitea-modal-backdrop]'
        ).forEach(function (backdrop) {
            backdrop.remove();
        });

        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('padding-right');
        document.body.style.removeProperty('overflow');

        /*
         * Reset Bootstrap's instance if one exists, without requiring
         * Bootstrap to be present.
         */
        if (window.bootstrap && bootstrap.Modal) {
            try {
                const instance = bootstrap.Modal.getInstance(modalElement);
                if (instance) {
                    instance.dispose();
                }
            } catch (error) {
                console.warn('Receipt modal cleanup warning:', error);
            }
        }
    }

    const cleanupAfterPrint = function () {
        cleanupReceiptModal();
        window.removeEventListener('afterprint', cleanupAfterPrint);
    };

    window.addEventListener('afterprint', cleanupAfterPrint);

    /*
     * Some browsers do not reliably fire afterprint when the print
     * dialog is cancelled.  Keep a delayed safety cleanup, but only
     * after enough time for the print dialog to be used.
     */
    cleanupTimer = window.setTimeout(function () {
        if (printStarted) {
            cleanupReceiptModal();
            window.removeEventListener('afterprint', cleanupAfterPrint);
        }
    }, 120000);
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
           AJAX TOAST
        ===================================================== */

        function showAjaxOrderToast(
            message
        ) {

            const existingToast =
                document.getElementById(
                    'ajaxOrdersToast'
                );


            if (existingToast) {

                const existingWrap =
                    existingToast.closest(
                        '.orders-toast-wrap'
                    );


                if (existingWrap) {
                    existingWrap.remove();
                }

            }


            const wrap =
                document.createElement(
                    'div'
                );


            wrap.className =
                'orders-toast-wrap';


            wrap.setAttribute(
                'aria-live',
                'polite'
            );


            wrap.setAttribute(
                'aria-atomic',
                'true'
            );


            wrap.innerHTML = `

                <div
                    class="orders-toast orders-toast-success"
                    id="ajaxOrdersToast"
                    role="status"
                >

                    <span
                        class="orders-toast-icon"
                    >

                        <i
                            class="bi bi-check-circle"
                        ></i>

                    </span>


                    <span
                        class="orders-toast-message"
                    >
                        ${message}
                    </span>


                    <button
                        type="button"
                        class="orders-toast-close"
                        aria-label="Close notification"
                    >

                        <i
                            class="bi bi-x-lg"
                        ></i>

                    </button>


                    <span
                        class="orders-toast-progress"
                        aria-hidden="true"
                    ></span>

                </div>

            `;


            document.body.appendChild(
                wrap
            );


            const toast =
                wrap.querySelector(
                    '.orders-toast'
                );


            const closeButton =
                wrap.querySelector(
                    '.orders-toast-close'
                );


            function removeToast() {

                if (!wrap.isConnected) {
                    return;
                }


                toast.classList.add(
                    'orders-toast-is-closing'
                );


                setTimeout(
                    function () {

                        wrap.remove();

                    },
                    180
                );

            }


            if (closeButton) {

                closeButton.addEventListener(
                    'click',
                    removeToast
                );

            }


            setTimeout(
                removeToast,
                3500
            );

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


                        if (submitButton.disabled) {
                            return;
                        }


                        const originalButtonHTML =
                            submitButton.innerHTML;


                        submitButton.disabled =
                            true;


                        submitButton.innerHTML = `

                            <span
                                class="spinner-border spinner-border-sm me-1"
                                aria-hidden="true"
                            ></span>

                            Processing...

                        `;


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


                            removeOrderCard(
                                form
                            );


                            showAjaxOrderToast(
                                data.message
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


                            submitButton.disabled =
                                false;


                            submitButton.innerHTML =
                                originalButtonHTML;

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


                        if (submitButton.disabled) {
                            return;
                        }


                        const originalButtonHTML =
                            submitButton.innerHTML;


                        /* -----------------------------------------
                           SHOW LOADING INDICATOR
                        ----------------------------------------- */

                        submitButton.disabled =
                            true;


                        submitButton.innerHTML = `

                            <span
                                class="spinner-border spinner-border-sm me-1"
                                aria-hidden="true"
                            ></span>

                            Cancelling...

                        `;


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
                                    document.querySelector('.pending-refunds-count');

                                if (refundCountElement) {
                                    const currentRefundCount =
                                        parseInt(refundCountElement.textContent.trim(), 10) || 0;
                                    refundCountElement.textContent = currentRefundCount + 1;
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

                            showAjaxOrderToast(
                                data.message
                                ||
                                'Order cancelled successfully.'
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


                            submitButton.disabled =
                                false;


                            submitButton.innerHTML =
                                originalButtonHTML;

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
   ADMIN AJAX WORKFLOW NAVIGATION + ORDER ACTIONS
========================================================= */

document.addEventListener(
    'DOMContentLoaded',
    function () {

        const adminOrdersContent =
            document.querySelector(
                '.admin-content'
            );

        if (!adminOrdersContent) {
            return;
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

        function cleanupAdminBootstrapModalState() {

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
                            'Admin modal cleanup warning:',
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
           The Admin page itself does NOT reload.
        ===================================================== */

        async function loadAdminActiveOrders(
            targetUrl,
            pushHistory = true
        ) {

            const activeSection =
                document.querySelector(
                    '.active-orders-section'
                );

            if (!activeSection) {
                return;
            }


            const requestedUrl =
                new URL(
                    targetUrl,
                    window.location.href
                );


            const selectedStatus =
                requestedUrl.searchParams.get(
                    'status'
                ) ||
                'pending_verification';


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


            /* Subtle loading state */

            activeSection.setAttribute(
                'aria-busy',
                'true'
            );

            activeSection.style.opacity =
                '0.55';

            activeSection.style.pointerEvents =
                'none';


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
                cleanupAdminBootstrapModalState();


                /* Replace only Active Orders */

                activeSection.replaceWith(
                    newActiveSection
                );


                /*
                 * Clean again after replacement in case Bootstrap
                 * queued backdrop cleanup from the modal hide call.
                 */
                cleanupAdminBootstrapModalState();


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
                            adminOrdersAjax:
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

                console.error(
                    'Admin Active Orders AJAX error:',
                    error
                );


                activeSection.innerHTML =
                    previousHTML;


                alert(
                    'Unable to load orders. Please try again.'
                );

            }


            const restoredSection =
                document.querySelector(
                    '.active-orders-section'
                );


            if (restoredSection) {

                restoredSection.removeAttribute(
                    'aria-busy'
                );

                restoredSection.style.opacity =
                    '';

                restoredSection.style.pointerEvents =
                    '';

            }

            /*
             * Final safety cleanup. The page must never remain
             * dimmed or locked after an Active Orders AJAX request.
             */
            cleanupAdminBootstrapModalState();

        }


        /* =====================================================
           WORKFLOW TABS
        ===================================================== */

        adminOrdersContent.addEventListener(
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


                loadAdminActiveOrders(
                    workflowLink.href,
                    true
                );

            }
        );


        /* =====================================================
           ACTIVE ORDER PAGINATION
           Cancelled-order pagination is left normal.
        ===================================================== */

        adminOrdersContent.addEventListener(
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


                loadAdminActiveOrders(
                    paginationLink.href,
                    true
                );

            }
        );


        /* =====================================================
           ACTIVE ORDER SEARCH
        ===================================================== */

        adminOrdersContent.addEventListener(
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
                        'pending_verification'
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


                loadAdminActiveOrders(
                    currentUrl.toString(),
                    true
                );

            }
        );


        /* =====================================================
           BROWSER BACK / FORWARD
        ===================================================== */

        window.addEventListener(
            'popstate',
            function () {

                loadAdminActiveOrders(
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

        adminOrdersContent.addEventListener(
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

                    handleAdminStatusUpdate(
                        form
                    );

                    return;

                }


                if (cancelInput) {

                    event.preventDefault();
                    event.stopImmediatePropagation();

                    handleAdminCancelOrder(
                        form
                    );

                }

            },
            true
        );


        /* =====================================================
           AJAX STATUS UPDATE
        ===================================================== */

        async function handleAdminStatusUpdate(
            form
        ) {

            const button =
                form.querySelector(
                    'button[name="update_status"]'
                );


            if (
                !button ||
                button.disabled
            ) {
                return;
            }


            const originalHTML =
                button.innerHTML;


            const orderCard =
                form.closest(
                    '.order-card'
                );


            let processingOverlay =
                null;

            let screenProcessingOverlay =
                null;

            const requestedStatus =
                String(
                    new FormData(form).get('status') ||
                    ''
                );

            const isPreparing =
                requestedStatus === 'preparing';


            /* Card-level processing overlay */

            if (orderCard) {

                orderCard.classList.add(
                    'processing-order'
                );


                processingOverlay =
                    document.createElement(
                        'div'
                    );


                processingOverlay.className =
                    'order-processing-overlay';


                processingOverlay.innerHTML = `
                    <div class="order-processing-box">

                        <div
                            class="order-processing-spinner"
                            aria-hidden="true"
                        ></div>

                        <div class="order-processing-title">
                            Processing Order
                        </div>

                        <div class="order-processing-text">
                            Please wait...
                        </div>

                    </div>
                `;


                orderCard.appendChild(
                    processingOverlay
                );

            }


            /* Preparing also sends the customer's email in the same AJAX
             * request, so give the Admin a clear page-level wait state. */
            if (isPreparing) {

                screenProcessingOverlay =
                    document.createElement('div');

                screenProcessingOverlay.className =
                    'admin-preparing-loading-overlay';

                screenProcessingOverlay.setAttribute(
                    'role',
                    'status'
                );

                screenProcessingOverlay.setAttribute(
                    'aria-live',
                    'polite'
                );

                screenProcessingOverlay.innerHTML = `
                    <div class="admin-preparing-loading-box">

                        <div
                            class="admin-preparing-loading-spinner"
                            aria-hidden="true"
                        ></div>

                        <div class="admin-preparing-loading-title">
                            Starting Preparation
                        </div>

                        <div class="admin-preparing-loading-text">
                            Please wait while the order is updated and the customer notification is sent.
                        </div>

                    </div>
                `;

                document.body.appendChild(
                    screenProcessingOverlay
                );

            }


            button.disabled =
                true;


            button.innerHTML = `
                <span
                    class="spinner-border spinner-border-sm me-1"
                    aria-hidden="true"
                ></span>
                Processing...
            `;


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
                        'The order status could not be updated.'
                    );

                }


                /*
                 * Refresh only the active order section.
                 */
                await loadAdminActiveOrders(
                    window.location.href,
                    false
                );


                if (screenProcessingOverlay) {

                    screenProcessingOverlay.remove();
                    screenProcessingOverlay = null;

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


                if (
                    typeof showAjaxOrderToast ===
                    'function'
                ) {

                    showAjaxOrderToast(
                        data.message ||
                        'Order status updated successfully.'
                    );

                }


            } catch (error) {

                console.error(
                    'AJAX Admin order status update failed:',
                    error
                );


                if (processingOverlay) {

                    processingOverlay.remove();
                    processingOverlay = null;

                }


                if (screenProcessingOverlay) {

                    screenProcessingOverlay.remove();
                    screenProcessingOverlay = null;

                }


                if (orderCard) {

                    orderCard.classList.remove(
                        'processing-order'
                    );

                }


                button.disabled =
                    false;


                button.innerHTML =
                    originalHTML;


                alert(
                    error.message ||
                    'Unable to update the order.'
                );

            }

        }


        /* =====================================================
           AJAX CANCEL ORDER
        ===================================================== */

        async function handleAdminCancelOrder(
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
                button.disabled
            ) {
                return;
            }


            const originalHTML =
                button.innerHTML;


            button.disabled =
                true;


            button.innerHTML = `
                <span
                    class="spinner-border spinner-border-sm me-1"
                    aria-hidden="true"
                ></span>
                Cancelling...
            `;


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

                await loadAdminActiveOrders(
                    window.location.href,
                    false
                );


                if (
                    typeof showAjaxOrderToast ===
                    'function'
                ) {

                    showAjaxOrderToast(
                        data.message ||
                        'Order cancelled successfully.'
                    );

                }


            } catch (error) {

                console.error(
                    'AJAX Admin order cancellation failed:',
                    error
                );


                button.disabled =
                    false;


                button.innerHTML =
                    originalHTML;


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

    const adminOrdersContent =
        document.querySelector('.admin-content');

    if (!adminOrdersContent) {
        return;
    }

    let cancelledSearchTimer = null;
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
                        adminOrdersCancelledAjax: true
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
                'Admin Cancelled Orders AJAX error:',
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
    adminOrdersContent.addEventListener(
        'change',
        function (event) {

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

    adminOrdersContent.addEventListener(
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
                    cancelled_page: 1
                });

            loadCancelledOrders(
                targetUrl,
                true
            );
        }
    );

    /* Live search: no whole-page refresh. */
    adminOrdersContent.addEventListener(
        'input',
        function (event) {

            const searchInput =
                event.target.closest(
                    '[data-cancelled-search]'
                );

            if (!searchInput) {
                return;
            }

            clearTimeout(
                cancelledSearchTimer
            );

            cancelledSearchTimer =
                setTimeout(
                    function () {

                        const period =
                            document.querySelector(
                                '[data-cancelled-period]'
                            );

                        const month =
                            document.querySelector(
                                '[data-cancelled-month]'
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
                                    searchInput.value,
                                cancelled_page: 1
                            });

                        /*
                         * Live search should not create a new history
                         * entry for every keystroke, but the current URL
                         * must still track the active cancelled search so
                         * pagination and Back/Forward keep the same filter.
                         */
                        const cleanUrl =
                            new URL(
                                targetUrl.toString()
                            );

                        window.history.replaceState(
                            {
                                adminOrdersCancelledAjax: true
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

                        loadCancelledOrders(
                            targetUrl,
                            false
                        );

                    },
                    300
                );
        }
    );

    /* Clear only Cancelled Orders filters. */
    adminOrdersContent.addEventListener(
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
 * Cancellation reason dropdown.
 *
 * The existing Admin cancellation flow still submits
 * cancellation_reason to PHP. "Other" uses the marker __other__
 * and sends its actual text through other_cancellation_reason.
 */
document.addEventListener('DOMContentLoaded', function () {

    const reasonSelect =
        document.getElementById('adminCancelReason');

    const otherWrap =
        document.getElementById(
            'adminOtherCancellationReasonWrap'
        );

    const otherInput =
        document.getElementById(
            'adminOtherCancellationReason'
        );

    const form =
        document.getElementById('adminCancelOrderForm');

    if (
        !reasonSelect ||
        !otherWrap ||
        !otherInput
    ) {
        return;
    }

    function syncOtherReasonField() {

        const isOther =
            reasonSelect.value === '__other__';

        otherWrap.style.display =
            isOther ? '' : 'none';

        otherInput.required =
            isOther;

        if (!isOther) {
            otherInput.value = '';
        }
    }

    reasonSelect.addEventListener(
        'change',
        syncOtherReasonField
    );

    if (form) {

        form.addEventListener(
            'submit',
            function (event) {

                if (
                    reasonSelect.value === '__other__'
                ) {

                    const customReason =
                        otherInput.value.trim();

                    if (customReason === '') {

                        event.preventDefault();

                        otherInput.focus();

                        otherInput.reportValidity();

                        return;
                    }
                }
            }
        );
    }

    syncOtherReasonField();

});
</script>

<script>
/* =========================================================
   PENDING REFUND FILTERS
   Status/date/search changes refresh ONLY the Pending Refunds
   dropdown. The rest of Admin Orders stays on the page.
========================================================= */
document.addEventListener('DOMContentLoaded', function () {

    const content = document.querySelector('.admin-content');

    if (!content) return;

    let refundRequest = null;

    function buildRefundUrl(form) {
        const url = new URL(
            form.getAttribute('action') || window.location.href,
            window.location.href
        );

        const formData = new FormData(form);

        /* Start from the current page query so unrelated Orders state
           (active tab, search, pagination, etc.) stays intact. */
        formData.forEach(function (value, key) {
            url.searchParams.set(key, value);
        });

        url.searchParams.set('refund_open', '1');
        return url;
    }

    async function refreshPendingRefunds(form, pushHistory = true) {
        if (!form) return;

        const currentSection = document.querySelector('#pending-refunds');
        if (!currentSection) return;

        if (refundRequest) {
            refundRequest.abort();
        }

        const requestedUrl = buildRefundUrl(form);
        const controller = new AbortController();
        refundRequest = controller;

        currentSection.setAttribute('aria-busy', 'true');
        currentSection.style.opacity = '.65';
        currentSection.style.pointerEvents = 'none';

        try {
            /* Fetch the normal page, then replace ONLY the Pending Refunds
               <details> element. The browser never navigates away. */
            const response = await fetch(requestedUrl.toString(), {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'text/html'
                },
                cache: 'no-store',
                signal: controller.signal
            });

            if (!response.ok) {
                throw new Error('Unable to refresh Pending Refunds.');
            }

            const html = await response.text();
            const parser = new DOMParser();
            const parsedDocument = parser.parseFromString(
                html,
                'text/html'
            );

            const newSection =
                parsedDocument.querySelector('#pending-refunds');

            if (!newSection) {
                throw new Error(
                    'Pending Refunds section was not found.'
                );
            }

            /* Keep the dropdown open after every filter refresh. */
            if (currentSection.hasAttribute('open')) {
                newSection.setAttribute('open', '');
            }

            currentSection.replaceWith(newSection);

            if (pushHistory) {
                window.history.pushState(
                    { adminOrdersRefundAjax: true },
                    '',
                    requestedUrl.pathname +
                    (requestedUrl.search ? requestedUrl.search : '') +
                    requestedUrl.hash
                );
            }

        } catch (error) {

            if (error.name !== 'AbortError') {
                console.error(
                    'Admin Pending Refunds AJAX error:',
                    error
                );

                alert(
                    error.message ||
                    'Unable to load Pending Refunds.'
                );
            }

        } finally {

            if (refundRequest === controller) {
                refundRequest = null;
            }

            const restoredSection =
                document.querySelector('#pending-refunds');

            if (restoredSection) {
                restoredSection.removeAttribute('aria-busy');
                restoredSection.style.opacity = '';
                restoredSection.style.pointerEvents = '';
            }
        }
    }

    /* Status dropdown: AJAX-refresh Pending Refunds only. */
    content.addEventListener('change', function (event) {

        const statusSelect =
            event.target.closest('[data-refund-status]');

        if (statusSelect) {

            const form =
                statusSelect.closest('[data-refund-filter-form]');

            if (form) {
                refreshPendingRefunds(form);
            }

            return;
        }

        /* Date-period dropdown: AJAX-refresh immediately except when
           the Admin needs to choose a specific date first. */
        const periodSelect =
            event.target.closest('[data-refund-period]');

        if (periodSelect) {

            const form =
                periodSelect.closest('[data-refund-filter-form]');

            const dateWrap =
                form
                    ? form.querySelector('[data-refund-date-wrap]')
                    : null;

            const dateInput =
                form
                    ? form.querySelector('[data-refund-date]')
                    : null;

            if (periodSelect.value === 'specific_date') {

                if (dateWrap) {
                    dateWrap.style.display = '';
                }

                if (dateInput) {
                    dateInput.focus();
                }

                return;
            }

            if (dateWrap) {
                dateWrap.style.display = 'none';
            }

            if (form) {
                refreshPendingRefunds(form);
            }

            return;
        }

        /* Specific date: AJAX-refresh when the date is selected. */
        const dateInput =
            event.target.closest('[data-refund-date]');

        if (dateInput) {

            const form =
                dateInput.closest('[data-refund-filter-form]');

            const periodSelect =
                form
                    ? form.querySelector('[data-refund-period]')
                    : null;

            if (
                form &&
                periodSelect &&
                periodSelect.value === 'specific_date' &&
                dateInput.value
            ) {
                refreshPendingRefunds(form);
            }
        }
    });

    /* Search button: AJAX-refresh Pending Refunds only. Typing itself
       never triggers a request, so the input keeps its normal focus. */
    content.addEventListener('submit', function (event) {

        const form =
            event.target.closest('[data-refund-filter-form]');

        if (!form) return;

        event.preventDefault();
        refreshPendingRefunds(form);
    });

    /* Clear filters without refreshing the entire Orders page. */
    content.addEventListener('click', function (event) {

        const clearLink =
            event.target.closest('[data-refund-clear]');

        if (!clearLink) return;

        event.preventDefault();

        const form =
            document.querySelector('[data-refund-filter-form]');

        if (!form) return;

        const statusSelect =
            form.querySelector('[data-refund-status]');
        const periodSelect =
            form.querySelector('[data-refund-period]');
        const dateInput =
            form.querySelector('[data-refund-date]');
        const dateWrap =
            form.querySelector('[data-refund-date-wrap]');
        const searchInput =
            form.querySelector('[data-refund-search]');

        if (statusSelect) statusSelect.value = 'pending';
        if (periodSelect) periodSelect.value = 'today';
        if (dateInput) dateInput.value = '<?= date('Y-m-d') ?>';
        if (dateWrap) dateWrap.style.display = 'none';
        if (searchInput) searchInput.value = '';

        refreshPendingRefunds(form);
    });

    /* Browser Back/Forward also refreshes only the Pending Refunds
       dropdown, rather than navigating the entire Orders page. */
    window.addEventListener('popstate', function () {
        const form =
            document.querySelector('[data-refund-filter-form]');

        if (form) {
            refreshPendingRefunds(form, false);
        }
    });

});
</script>



<style id="admin-order-action-hover-fix">
/* =========================================================
   KEEP ADMIN ORDER ACTION BUTTONS COLORED ON HOVER/FOCUS
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