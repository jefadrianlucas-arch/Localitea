<?php
require_once '../includes/db.php';

/* ADMIN ACCESS */
if (
    !isset($_SESSION['user_id']) ||
    !in_array($_SESSION['user_role'] ?? '', ['admin'], true)
) {
    header('Location: ../auth/login.php');
    exit;
}

/* AJAX: GET ALL ADMIN NOTIFICATIONS */
if (
    $_SERVER['REQUEST_METHOD'] === 'GET' &&
    ($_GET['ajax'] ?? '') === 'notifications'
) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    try {
        $ajaxStmt = $pdo->prepare("
            SELECT
                n.id,
                n.type,
                n.message,
                n.reference_id,
                n.is_read,
                n.created_at,
                o.order_number,
                o.claim_number,
                o.status AS order_status
            FROM notifications n
            LEFT JOIN orders o ON o.id = n.reference_id
            WHERE n.recipient_role = 'admin'
            ORDER BY n.is_read ASC, n.created_at DESC, n.id DESC
        ");

        $ajaxStmt->execute();

        $ajaxNotifications = $ajaxStmt->fetchAll(PDO::FETCH_ASSOC);

        $ajaxUnreadCount = 0;

        foreach ($ajaxNotifications as $row) {
            if ((int)$row['is_read'] === 0) {
                $ajaxUnreadCount++;
            }
        }

        echo json_encode([
            'success' => true,
            'unread_count' => $ajaxUnreadCount,
            'notifications' => $ajaxNotifications
        ], JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        http_response_code(500);

        echo json_encode([
            'success' => false,
            'unread_count' => 0,
            'notifications' => [],
            'message' => 'Unable to load notifications.'
        ]);
    }

    exit;
}

/* MARK SINGLE NOTIFICATION AS READ */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['mark_read'])
) {
    $notification_id = (int)($_POST['notification_id'] ?? 0);
    $isAjaxAction = ($_POST['ajax'] ?? '') === 'notification_action';

    if ($notification_id <= 0) {

        if ($isAjaxAction) {
            header('Content-Type: application/json; charset=utf-8');

            echo json_encode([
                'success' => false,
                'message' => 'Invalid notification.'
            ]);

            exit;
        }

        header('Location: notifications.php');
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE notifications
            SET is_read = 1
            WHERE id = ?
              AND recipient_role = 'admin'
              AND is_read = 0
        ");

        $stmt->execute([
            $notification_id
        ]);

        if ($isAjaxAction) {

            $countStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM notifications
                WHERE recipient_role = 'admin'
                  AND is_read = 0
            ");

            $countStmt->execute();

            $currentUnreadCount = (int)$countStmt->fetchColumn();

            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');

            echo json_encode([
                'success' => true,
                'message' => 'Notification marked as read.',
                'unread_count' => $currentUnreadCount
            ]);

            exit;
        }

    } catch (Throwable $e) {

        if ($isAjaxAction) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');

            echo json_encode([
                'success' => false,
                'message' => 'Unable to mark notification as read.'
            ]);

            exit;
        }
    }

    header('Location: notifications.php');
    exit;
}

/* MARK ALL NOTIFICATIONS AS READ */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['mark_all_read'])
) {
    $isAjaxAction = ($_POST['ajax'] ?? '') === 'notification_action';

    try {

        $stmt = $pdo->prepare("
            UPDATE notifications
            SET is_read = 1
            WHERE recipient_role = 'admin'
              AND is_read = 0
        ");

        $stmt->execute();

        if ($isAjaxAction) {

            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');

            echo json_encode([
                'success' => true,
                'message' => 'All notifications marked as read.',
                'unread_count' => 0
            ]);

            exit;
        }

    } catch (Throwable $e) {

        if ($isAjaxAction) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');

            echo json_encode([
                'success' => false,
                'message' => 'Unable to mark all notifications as read.'
            ]);

            exit;
        }
    }

    header('Location: notifications.php');
    exit;
}

/* UNREAD COUNT */
$stmtUnread = $pdo->prepare("
    SELECT COUNT(*)
    FROM notifications
    WHERE recipient_role = 'admin'
      AND is_read = 0
");

$stmtUnread->execute();

$unread_count = (int)$stmtUnread->fetchColumn();

/* SERVER-SIDE PAGINATION */
$notifications_per_page = 10;

$notification_page = max(
    1,
    (int)($_GET['page'] ?? 1)
);

$countNotificationsStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM notifications
    WHERE recipient_role = 'admin'
");

$countNotificationsStmt->execute();

$total_notifications = (int)$countNotificationsStmt->fetchColumn();

$total_notification_pages = max(
    1,
    (int)ceil(
        $total_notifications / $notifications_per_page
    )
);

if ($notification_page > $total_notification_pages) {
    $notification_page = $total_notification_pages;
}

$notification_offset =
    ($notification_page - 1) *
    $notifications_per_page;

/* CURRENT PAGE NOTIFICATIONS */
$stmtNotifications = $pdo->prepare("
    SELECT
        n.id,
        n.type,
        n.message,
        n.reference_id,
        n.is_read,
        n.created_at,
        o.order_number,
        o.claim_number,
        o.status AS order_status
    FROM notifications n
    LEFT JOIN orders o ON o.id = n.reference_id
    WHERE n.recipient_role = 'admin'
    ORDER BY
        n.is_read ASC,
        n.created_at DESC,
        n.id DESC
    LIMIT {$notifications_per_page}
    OFFSET {$notification_offset}
");

$stmtNotifications->execute();

$notifications =
    $stmtNotifications->fetchAll(
        PDO::FETCH_ASSOC
    );

$page_title = 'Notifications';
$page_description = 'View and manage admin notifications.';

require_once '../includes/header.php';
?>

<link
    rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
>

<style>
.admin-dashboard {
    background: #f8f5ef;
    min-height: 100vh;
}

.admin-content {
    padding: 30px;
}

.notifications-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 25px;
    gap: 20px;
}

.notifications-title h2 {
    margin: 0;
    color: #4b2e1e;
    font-weight: 700;
}

.notifications-title p {
    margin: 5px 0 0;
    color: #777;
}

.notification-actions {
    display: flex;
    align-items: center;
    gap: 10px;
}

.unread-badge {
    display: inline-flex;
    align-items: center;
    background: #fff1cc;
    color: #956c00;
    padding: 8px 13px;
    border-radius: 30px;
    font-size: 13px;
    font-weight: 600;
}

.btn-mark-all {
    border: 1px solid #4b2e1e;
    background: #fff;
    color: #4b2e1e;
    border-radius: 8px;
    padding: 9px 14px;
    font-size: 13px;
    font-weight: 600;
    transition: .2s ease;
    cursor: pointer;
}

.btn-mark-all:hover {
    background: #4b2e1e;
    color: #fff;
}

.notification-section {
    background: #fff;
    border: 2px solid #4A3525;
    border-radius: 16px;
    padding: 25px;
    box-shadow: 0 3px 12px rgba(0, 0, 0, .04);
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 15px;
}

.notification-card {
    display: flex;
    align-items: flex-start;
    gap: 15px;
    background: #fffdf9;
    border: 1px solid #6F4E37;
    border-radius: 14px;
    padding: 18px;
    transition:
        background .2s ease,
        border-color .2s ease,
        transform .2s ease;
}

.notification-card.unread {
    background: #fffaf4;
    border-left: 4px solid #5a3825;
}

.notification-card.unread:hover {
    background: #fff7ed;
}

.notification-card.read {
    opacity: .78;
}

.notification-icon {
    width: 46px;
    height: 46px;
    min-width: 46px;
    border-radius: 12px;
    background: #f3e8d8;
    color: #6b4226;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
}

.notification-card.read .notification-icon {
    background: #f1eee9;
    color: #999;
}

.notification-content {
    flex: 1;
    min-width: 0;
}

.notification-type {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 5px;
}

.notification-type strong {
    color: #4b2e1e;
    font-size: 14px;
}

.new-badge {
    background: #5a3825;
    color: #fff;
    padding: 3px 7px;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 600;
}

.notification-message {
    color: #555;
    font-size: 14px;
    line-height: 1.5;
    margin-bottom: 6px;
    overflow-wrap: anywhere;
}

.notification-time {
    color: #999;
    font-size: 12px;
}

.notification-order {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 10px;
}

.order-tag {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: #fdf8f2;
    border: 1px solid #B8A08A;
    color: #6b4226;
    padding: 5px 9px;
    border-radius: 7px;
    font-size: 11px;
    font-weight: 600;
    overflow-wrap: anywhere;
}

.notification-card-actions {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 7px;
}

.btn-view-order {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    text-decoration: none;
    background: #4b2e1e;
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 8px 12px;
    font-size: 12px;
    font-weight: 600;
    transition: .2s ease;
}

.btn-view-order:hover {
    background: #351f14;
    color: #fff;
}

.btn-mark-read {
    border: none;
    background: transparent;
    color: #6b4226;
    padding: 5px 7px;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    transition: .2s ease;
}

.btn-mark-read:hover {
    color: #351f14;
    text-decoration: underline;
}

.empty-notifications {
    grid-column: 1 / -1;
    text-align: center;
    padding: 70px 20px;
    color: #888;
}

.empty-notifications i {
    display: block;
    font-size: 55px;
    color: #cdbda9;
    margin-bottom: 15px;
}

.empty-notifications h5 {
    color: #6b4226;
    margin-bottom: 5px;
    font-weight: 700;
}

.empty-notifications p {
    margin: 0;
    font-size: 13px;
}

.notification-pagination {
    grid-column: 1 / -1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    width: 100%;
    margin-top: 7px;
    padding-top: 18px;
    border-top: 1px solid #E6DEC9;
    flex-wrap: wrap;
}

.notification-pagination a,
.notification-pagination span {
    min-width: 34px;
    height: 34px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0 9px;
    border-radius: 8px;
    font-size: .78rem;
    font-weight: 700;
    text-decoration: none;
}

.notification-pagination a {
    color: #6F4E37;
    background: #fff;
    border: 1px solid #B8A08A;
    transition: .15s ease;
}

.notification-pagination a:hover {
    background: #F7F0E8;
    border-color: #6F4E37;
    color: #4A3525;
}

.notification-pagination .active {
    background: #4A3525;
    border: 1px solid #4A3525;
    color: #fff;
}

.notification-pagination .disabled {
    color: #A99B91;
    background: #F5F1ED;
    border: 1px solid #E6DEC9;
    cursor: default;
}

.notification-pagination .ellipsis {
    border: none;
    background: transparent;
    color: #8B7D73;
    min-width: 22px;
    padding: 0;
}

.notification-live-loading {
    grid-column: 1 / -1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 26px 15px;
    color: #7B6D62;
    font-size: .8rem;
}

.notification-live-loading .spinner-border {
    width: 14px;
    height: 14px;
    border-width: 2px;
}

.notification-action-toast {
    position: fixed;
    right: 20px;
    bottom: 20px;
    width: min(340px, calc(100vw - 30px));
    z-index: 1090;
}

.notification-action-toast .toast {
    width: 100%;
    border: 1px solid #D8C6B5;
    border-radius: 12px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, .12);
    overflow: hidden;
}

.notification-action-toast .toast-header {
    background: #4b2e1e;
    color: #fff;
    border-bottom: none;
    padding: 9px 12px;
    font-size: 12px;
    font-weight: 600;
}

.notification-action-toast .toast-header i {
    font-size: 14px;
}

.notification-action-toast .toast-body {
    background: #fff;
    color: #555;
    padding: 11px 12px;
    font-size: 12px;
    line-height: 1.4;
}

@media (max-width: 1100px) {
    .admin-content {
        padding: 25px 20px;
    }

    .notification-card {
        gap: 12px;
    }
}

@media (max-width: 768px) {
    .admin-dashboard {
        min-width: 0;
    }

    .admin-content {
        width: 100%;
        padding: 15px;
    }

    .notifications-header {
        display: block;
        margin-bottom: 18px;
    }

    .notifications-title h2 {
        font-size: 21px;
    }

    .notifications-title p {
        font-size: 12px;
    }

    .notification-actions {
        width: 100%;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 14px;
    }

    .notification-section {
        width: 100%;
        padding: 12px;
        border-radius: 12px;
        grid-template-columns: 1fr;
    }

    .notification-pagination,
    .notification-live-loading,
    .empty-notifications {
        grid-column: auto;
    }

    .notification-card {
        width: 100%;
        display: flex;
        flex-direction: column;
        align-items: stretch;
        gap: 10px;
        padding: 14px;
        overflow: hidden;
    }

    .notification-icon {
        width: 40px;
        height: 40px;
        min-width: 40px;
        font-size: 17px;
    }

    .notification-content {
        width: 100%;
        min-width: 0;
    }

    .notification-type {
        flex-wrap: wrap;
        gap: 6px;
    }

    .notification-type strong {
        font-size: 13px;
    }

    .notification-message {
        width: 100%;
        font-size: 13px;
        line-height: 1.45;
    }

    .notification-order {
        width: 100%;
        gap: 5px;
    }

    .order-tag {
        max-width: 100%;
        font-size: 10px;
    }

    .notification-time {
        font-size: 11px;
        margin-top: 6px;
    }

    .notification-card-actions {
        width: 100%;
        align-items: stretch;
        gap: 6px;
        padding-top: 10px;
        border-top: 1px solid #8B6F5A;
    }

    .btn-view-order {
        width: 100%;
        min-width: 0;
        text-align: center;
    }

    .btn-mark-read {
        width: 100%;
        text-align: center;
        padding: 7px;
    }

    .empty-notifications {
        padding: 50px 15px;
    }
}

@media (max-width: 576px) {
    .notification-action-toast {
        right: 15px;
        left: 15px;
        bottom: 15px;
        width: auto;
    }
}

@media (max-width: 480px) {
    .admin-content {
        padding: 10px;
    }

    .notification-section {
        padding: 12px;
    }

    .notifications-title h2 {
        font-size: 19px;
    }

    .notifications-title p {
        font-size: 11px;
    }

    .notification-actions {
        display: block;
        margin-top: 12px;
    }

    .unread-badge {
        display: inline-flex;
        margin-bottom: 7px;
    }

    .notification-actions form {
        width: 100%;
    }

    .btn-mark-all {
        width: 100%;
        display: block;
    }

    .notification-card {
        padding: 12px;
        border-radius: 10px;
    }

    .notification-message {
        font-size: 12px;
    }

    .notification-time {
        font-size: 10px;
    }

    .notification-pagination {
        gap: 4px;
    }

    .notification-pagination a,
    .notification-pagination span {
        min-width: 32px;
        height: 32px;
        font-size: .72rem;
    }
}
</style>

<div>

    <?php require_once 'sidebar.php'; ?>

    <?php require_once 'navbar.php'; ?>

    <main class="admin-dashboard">

        <div class="admin-content">

            <div class="notifications-header">

                <div class="notifications-title">

                    <h2>
                        <i class="bi bi-bell me-2"></i>
                        Notifications
                    </h2>

                    <p>
                        View recent order notifications and updates.
                    </p>

                </div>

                <div class="notification-actions">

                    <?php if ($unread_count > 0): ?>

                        <span class="unread-badge">
                            <i class="bi bi-bell-fill me-1"></i>
                            <?= $unread_count ?> unread
                        </span>

                        <form method="POST">

                            <button
                                type="submit"
                                name="mark_all_read"
                                class="btn-mark-all"
                            >
                                <i class="bi bi-check2-all me-1"></i>
                                Mark All as Read
                            </button>

                        </form>

                    <?php else: ?>

                        <span class="unread-badge">
                            <i class="bi bi-check-circle me-1"></i>
                            All caught up
                        </span>

                    <?php endif; ?>

                </div>

            </div>

            <div class="notification-section">

                <?php if (empty($notifications)): ?>

                    <div class="empty-notifications">

                        <i class="bi bi-bell-slash"></i>

                        <h5>
                            No Notifications
                        </h5>

                        <p>
                            There are currently no notifications to display.
                        </p>

                    </div>

                <?php else: ?>

                    <?php foreach ($notifications as $notification): ?>

                        <?php
                        $isUnread =
                            ((int)$notification['is_read'] === 0);

                        $notificationType =
                            $notification['type'] ?? 'notification';

                        $notificationTitles = [
                            'new_order' =>
                                'New Order',

                            'payment' =>
                                'Payment Update',

                            'order_update' =>
                                'Order Update',

                            'customer_cancelled_order' =>
                                'Order Cancelled by Customer',

                            'gcash_pending_verification' =>
                                'GCash Payment Verification'
                        ];

                        $notificationIcons = [
                            'new_order' =>
                                'bi-cart-check',

                            'payment' =>
                                'bi-credit-card',

                            'order_update' =>
                                'bi-arrow-repeat',

                            'customer_cancelled_order' =>
                                'bi-x-circle',

                            'gcash_pending_verification' =>
                                'bi-credit-card'
                        ];

                        $notificationTitle =
                            $notificationTitles[$notificationType]
                            ?? ucwords(
                                str_replace(
                                    ['_', '-'],
                                    ' ',
                                    $notificationType
                                )
                            );

                        $notificationIcon =
                            $notificationIcons[$notificationType]
                            ?? 'bi-bell';

                        $orderStatus =
                            $notification['order_status'] ?? '';

                        $orderStatusUrl =
                            'orders.php';

                        if (
                            in_array(
                                $orderStatus,
                                [
                                    'pending_verification',
                                    'confirmed',
                                    'preparing',
                                    'ready'
                                ],
                                true
                            )
                        ) {
                            $orderStatusUrl =
                                'orders.php?status=' .
                                urlencode($orderStatus);
                        }

                        $notificationDate =
                            !empty($notification['created_at'])
                            ? date(
                                'M d, Y • h:i A',
                                strtotime($notification['created_at'])
                            )
                            : '';
                        ?>

                        <div
                            class="notification-card
                            <?= $isUnread ? 'unread' : 'read' ?>"
                        >

                            <div class="notification-icon">

                                <i
                                    class="bi <?= htmlspecialchars(
                                        $notificationIcon
                                    ) ?>"
                                ></i>

                            </div>

                            <div class="notification-content">

                                <div class="notification-type">

                                    <strong>
                                        <?= htmlspecialchars(
                                            $notificationTitle
                                        ) ?>
                                    </strong>

                                    <?php if ($isUnread): ?>

                                        <span class="new-badge">
                                            NEW
                                        </span>

                                    <?php endif; ?>

                                </div>

                                <div class="notification-message">

                                    <?= htmlspecialchars(
                                        $notification['message'] ?? ''
                                    ) ?>

                                </div>

                                <?php if (
                                    !empty(
                                        $notification['order_number']
                                    ) ||
                                    !empty(
                                        $notification['claim_number']
                                    )
                                ): ?>

                                    <div class="notification-order">

                                        <?php if (
                                            !empty(
                                                $notification['order_number']
                                            )
                                        ): ?>

                                            <span class="order-tag">

                                                <i class="bi bi-receipt"></i>

                                                <?= htmlspecialchars(
                                                    $notification[
                                                        'order_number'
                                                    ]
                                                ) ?>

                                            </span>

                                        <?php endif; ?>

                                        <?php if (
                                            !empty(
                                                $notification['claim_number']
                                            )
                                        ): ?>

                                            <span class="order-tag">

                                                <i class="bi bi-ticket-perforated"></i>

                                                <?= htmlspecialchars(
                                                    $notification[
                                                        'claim_number'
                                                    ]
                                                ) ?>

                                            </span>

                                        <?php endif; ?>

                                    </div>

                                <?php endif; ?>

                                <div class="notification-time">

                                    <i class="bi bi-clock me-1"></i>

                                    <?= htmlspecialchars(
                                        $notificationDate
                                    ) ?>

                                </div>

                            </div>

                            <div class="notification-card-actions">

                                <?php if (
                                    !empty(
                                        $notification['reference_id']
                                    ) &&
                                    !empty(
                                        $notification['order_number']
                                    )
                                ): ?>

                                    <a
                                        href="<?= htmlspecialchars(
                                            $orderStatusUrl
                                        ) ?>"
                                        class="btn-view-order"
                                    >

                                        <i class="bi bi-eye"></i>

                                        View Order

                                    </a>

                                <?php endif; ?>

                                <?php if ($isUnread): ?>

                                    <form method="POST">

                                        <input
                                            type="hidden"
                                            name="notification_id"
                                            value="<?= (int)$notification['id'] ?>"
                                        >

                                        <button
                                            type="submit"
                                            name="mark_read"
                                            class="btn-mark-read"
                                        >

                                            <i class="bi bi-check2 me-1"></i>

                                            Mark as Read

                                        </button>

                                    </form>

                                <?php else: ?>

                                    <span
                                        class="text-muted"
                                        style="font-size:11px;"
                                    >

                                        <i class="bi bi-check2-all me-1"></i>

                                        Read

                                    </span>

                                <?php endif; ?>

                            </div>

                        </div>

                    <?php endforeach; ?>

                    <?php if ($total_notification_pages > 1): ?>

                        <div
                            class="notification-pagination"
                            aria-label="Notification pages"
                        >

                            <?php if ($notification_page > 1): ?>

                                <a
                                    href="?page=<?= $notification_page - 1 ?>"
                                    aria-label="Previous page"
                                >
                                    <i class="bi bi-chevron-left"></i>
                                </a>

                            <?php else: ?>

                                <span
                                    class="disabled"
                                    aria-hidden="true"
                                >
                                    <i class="bi bi-chevron-left"></i>
                                </span>

                            <?php endif; ?>

                            <?php
                            $paginationPages = [1];

                            for (
                                $i = max(
                                    2,
                                    $notification_page - 2
                                );

                                $i <= min(
                                    $total_notification_pages - 1,
                                    $notification_page + 2
                                );

                                $i++
                            ) {
                                $paginationPages[] = $i;
                            }

                            if ($total_notification_pages > 1) {
                                $paginationPages[] =
                                    $total_notification_pages;
                            }

                            $paginationPages =
                                array_values(
                                    array_unique(
                                        $paginationPages
                                    )
                                );

                            $previousPage = 0;
                            ?>

                            <?php foreach (
                                $paginationPages
                                as $pageNumber
                            ): ?>

                                <?php if (
                                    $previousPage > 0 &&
                                    $pageNumber >
                                    $previousPage + 1
                                ): ?>

                                    <span
                                        class="ellipsis"
                                        aria-hidden="true"
                                    >
                                        ...
                                    </span>

                                <?php endif; ?>

                                <?php if (
                                    $pageNumber ===
                                    $notification_page
                                ): ?>

                                    <span
                                        class="active"
                                        aria-current="page"
                                    >
                                        <?= $pageNumber ?>
                                    </span>

                                <?php else: ?>

                                    <a
                                        href="?page=<?= $pageNumber ?>"
                                    >
                                        <?= $pageNumber ?>
                                    </a>

                                <?php endif; ?>

                                <?php
                                $previousPage =
                                    $pageNumber;
                                ?>

                            <?php endforeach; ?>

                            <?php if (
                                $notification_page <
                                $total_notification_pages
                            ): ?>

                                <a
                                    href="?page=<?= $notification_page + 1 ?>"
                                    aria-label="Next page"
                                >
                                    <i class="bi bi-chevron-right"></i>
                                </a>

                            <?php else: ?>

                                <span
                                    class="disabled"
                                    aria-hidden="true"
                                >
                                    <i class="bi bi-chevron-right"></i>
                                </span>

                            <?php endif; ?>

                        </div>

                    <?php endif; ?>

                <?php endif; ?>

            </div>

        </div>

    </main>

    <div
        class="notification-action-toast"
        aria-live="polite"
        aria-atomic="true"
    >

        <div
            id="notificationActionToast"
            class="toast"
            role="status"
            data-bs-delay="2500"
        >

            <div class="toast-header">

                <i class="bi bi-check-circle-fill me-2"></i>

                <strong class="me-auto">
                    Notifications
                </strong>

                <button
                    type="button"
                    class="btn-close btn-close-white"
                    data-bs-dismiss="toast"
                    aria-label="Close"
                ></button>

            </div>

            <div
                class="toast-body"
                id="notificationActionToastMessage"
            >
                Done.
            </div>

        </div>

    </div>

</div>

<script>
(function () {

    const NOTIFICATIONS_PER_PAGE = 10;

    let cachedNotificationData = null;
    let knownNotificationIds = null;

    const toastElement =
        document.getElementById(
            'notificationActionToast'
        );

    const toastMessage =
        document.getElementById(
            'notificationActionToastMessage'
        );

    function showNotificationToast(message) {

        if (
            !toastElement ||
            !toastMessage
        ) {
            return;
        }

        toastMessage.textContent = message;

        if (
            typeof bootstrap !== 'undefined' &&
            bootstrap.Toast
        ) {

            bootstrap.Toast
                .getOrCreateInstance(
                    toastElement,
                    {
                        delay: 2500
                    }
                )
                .show();

        } else {

            toastElement.classList.add('show');

            setTimeout(
                function () {
                    toastElement.classList.remove('show');
                },
                2500
            );
        }
    }

    function escapeHtml(value) {

        return String(value ?? '')
            .replace(
                /&/g,
                '&amp;'
            )
            .replace(
                /</g,
                '&lt;'
            )
            .replace(
                />/g,
                '&gt;'
            )
            .replace(
                /"/g,
                '&quot;'
            )
            .replace(
                /'/g,
                '&#039;'
            );
    }

    function notificationTitle(type) {

        const titles = {
            new_order:
                'New Order',

            payment:
                'Payment Update',

            order_update:
                'Order Update',

            customer_cancelled_order:
                'Order Cancelled by Customer',

            gcash_pending_verification:
                'GCash Payment Verification'
        };

        return (
            titles[type] ||
            String(
                type ||
                'notification'
            )
                .replace(
                    /[_-]/g,
                    ' '
                )
                .replace(
                    /\b\w/g,
                    function (char) {
                        return char.toUpperCase();
                    }
                )
        );
    }

    function notificationIcon(type) {

        const icons = {

            new_order:
                'bi-cart-check',

            payment:
                'bi-credit-card',

            order_update:
                'bi-arrow-repeat',

            customer_cancelled_order:
                'bi-x-circle',

            gcash_pending_verification:
                'bi-credit-card'
        };

        return (
            icons[type] ||
            'bi-bell'
        );
    }

    function orderStatusUrl(notification) {

        const allowedStatuses = [
            'pending_verification',
            'confirmed',
            'preparing',
            'ready'
        ];

        if (
            notification.reference_id &&
            notification.order_number &&
            allowedStatuses.includes(
                notification.order_status
            )
        ) {

            return (
                'orders.php?status=' +
                encodeURIComponent(
                    notification.order_status
                )
            );
        }

        return 'orders.php';
    }

    function formatDate(value) {

        if (!value) {
            return '';
        }

        const date =
            new Date(
                String(
                    value
                ).replace(
                    ' ',
                    'T'
                )
            );

        if (
            Number.isNaN(
                date.getTime()
            )
        ) {
            return escapeHtml(value);
        }

        return (
            date.toLocaleDateString(
                'en-US',
                {
                    month: 'short',
                    day: '2-digit',
                    year: 'numeric'
                }
            )
            +
            ' • ' +
            date.toLocaleTimeString(
                'en-US',
                {
                    hour: '2-digit',
                    minute: '2-digit'
                }
            )
        );
    }

    function buildPageUrl(page) {

        const url =
            new URL(
                window.location.href
            );

        url.searchParams.set(
            'page',
            String(page)
        );

        return (
            url.pathname +
            (
                url.search
                    ? url.search
                    : ''
            ) +
            url.hash
        );
    }

    function getCurrentPage(totalPages) {

        const params =
            new URLSearchParams(
                window.location.search
            );

        let page =
            parseInt(
                params.get('page') ||
                '1',
                10
            );

        if (
            !Number.isFinite(page) ||
            page < 1
        ) {
            page = 1;
        }

        if (page > totalPages) {
            page = totalPages;
        }

        return page;
    }

    function buildPagination(
        currentPage,
        totalPages
    ) {

        if (totalPages <= 1) {
            return '';
        }

        const pages = [1];

        for (
            let page =
                Math.max(
                    2,
                    currentPage - 2
                );

            page <=
                Math.min(
                    totalPages - 1,
                    currentPage + 2
                );

            page++
        ) {
            pages.push(page);
        }

        if (totalPages > 1) {
            pages.push(totalPages);
        }

        const uniquePages =
            [...new Set(pages)];

        let html = '';
        let previousPage = 0;

        html +=
            currentPage > 1
            ? `
                <a
                    href="${escapeHtml(
                        buildPageUrl(
                            currentPage - 1
                        )
                    )}"
                    aria-label="Previous page"
                >
                    <i class="bi bi-chevron-left"></i>
                </a>
            `
            : `
                <span
                    class="disabled"
                    aria-hidden="true"
                >
                    <i class="bi bi-chevron-left"></i>
                </span>
            `;

        uniquePages.forEach(
            function (pageNumber) {

                if (
                    previousPage > 0 &&
                    pageNumber >
                    previousPage + 1
                ) {

                    html += `
                        <span
                            class="ellipsis"
                            aria-hidden="true"
                        >
                            ...
                        </span>
                    `;
                }

                html +=
                    pageNumber === currentPage
                    ? `
                        <span
                            class="active"
                            aria-current="page"
                        >
                            ${pageNumber}
                        </span>
                    `
                    : `
                        <a
                            href="${escapeHtml(
                                buildPageUrl(
                                    pageNumber
                                )
                            )}"
                        >
                            ${pageNumber}
                        </a>
                    `;

                previousPage =
                    pageNumber;
            }
        );

        html +=
            currentPage < totalPages
            ? `
                <a
                    href="${escapeHtml(
                        buildPageUrl(
                            currentPage + 1
                        )
                    )}"
                    aria-label="Next page"
                >
                    <i class="bi bi-chevron-right"></i>
                </a>
            `
            : `
                <span
                    class="disabled"
                    aria-hidden="true"
                >
                    <i class="bi bi-chevron-right"></i>
                </span>
            `;

        return `
            <div
                class="notification-pagination"
                aria-label="Notification pages"
            >
                ${html}
            </div>
        `;
    }

    function renderNotifications(
        data,
        showLoader = false
    ) {

        if (
            !data ||
            !data.success
        ) {
            return;
        }

        const notifications =
            Array.isArray(
                data.notifications
            )
                ? data.notifications
                : [];

        const unreadCount =
            Number(
                data.unread_count || 0
            );

        cachedNotificationData =
            data;

        window.localiteaAdminNotificationData =
            data;

        const actions =
            document.querySelector(
                '.notification-actions'
            );

        if (actions) {

            if (unreadCount > 0) {

                actions.innerHTML = `
                    <span class="unread-badge">
                        <i class="bi bi-bell-fill me-1"></i>
                        ${unreadCount} unread
                    </span>

                    <form method="POST">

                        <button
                            type="submit"
                            name="mark_all_read"
                            class="btn-mark-all"
                        >
                            <i class="bi bi-check2-all me-1"></i>
                            Mark All as Read
                        </button>

                    </form>
                `;

            } else {

                actions.innerHTML = `
                    <span class="unread-badge">
                        <i class="bi bi-check-circle me-1"></i>
                        All caught up
                    </span>
                `;
            }
        }

        const section =
            document.querySelector(
                '.notification-section'
            );

        if (!section) {
            return;
        }

        const totalPages =
            Math.max(
                1,
                Math.ceil(
                    notifications.length /
                    NOTIFICATIONS_PER_PAGE
                )
            );

        const currentPage =
            getCurrentPage(
                totalPages
            );

        const start =
            (
                currentPage - 1
            ) *
            NOTIFICATIONS_PER_PAGE;

        const pageNotifications =
            notifications.slice(
                start,
                start +
                NOTIFICATIONS_PER_PAGE
            );

        if (showLoader) {

            section.innerHTML = `
                <div class="notification-live-loading">

                    <div
                        class="spinner-border spinner-border-sm"
                        role="status"
                        aria-hidden="true"
                    ></div>

                    <span>
                        Loading notifications...
                    </span>

                </div>
            `;
        }

        if (!notifications.length) {

            section.innerHTML = `
                <div class="empty-notifications">

                    <i class="bi bi-bell-slash"></i>

                    <h5>
                        No Notifications
                    </h5>

                    <p>
                        There are currently no notifications to display.
                    </p>

                </div>
            `;

            return;
        }

        const cards =
            pageNotifications.map(
                function (notification) {

                    const isUnread =
                        Number(
                            notification.is_read
                        ) === 0;

                    const title =
                        notificationTitle(
                            notification.type
                        );

                    const icon =
                        notificationIcon(
                            notification.type
                        );

                    const orderUrl =
                        orderStatusUrl(
                            notification
                        );

                    let orderInformation =
                        '';

                    if (
                        notification.order_number ||
                        notification.claim_number
                    ) {

                        orderInformation = `
                            <div class="notification-order">

                                ${
                                    notification.order_number
                                        ? `
                                            <span class="order-tag">

                                                <i class="bi bi-receipt"></i>

                                                ${escapeHtml(
                                                    notification.order_number
                                                )}

                                            </span>
                                        `
                                        : ''
                                }

                                ${
                                    notification.claim_number
                                        ? `
                                            <span class="order-tag">

                                                <i class="bi bi-ticket-perforated"></i>

                                                ${escapeHtml(
                                                    notification.claim_number
                                                )}

                                            </span>
                                        `
                                        : ''
                                }

                            </div>
                        `;
                    }

                    const actionHtml =
                        (
                            notification.reference_id &&
                            notification.order_number
                        )
                        ? `
                            <a
                                href="${escapeHtml(
                                    orderUrl
                                )}"
                                class="btn-view-order"
                            >
                                <i class="bi bi-eye"></i>
                                View Order
                            </a>
                        `
                        : '';

                    const readHtml =
                        isUnread
                        ? `
                            <form method="POST">

                                <input
                                    type="hidden"
                                    name="notification_id"
                                    value="${Number(
                                        notification.id
                                    )}"
                                >

                                <button
                                    type="submit"
                                    name="mark_read"
                                    class="btn-mark-read"
                                >
                                    <i class="bi bi-check2 me-1"></i>
                                    Mark as Read
                                </button>

                            </form>
                        `
                        : `
                            <span
                                class="text-muted"
                                style="font-size:11px;"
                            >
                                <i class="bi bi-check2-all me-1"></i>
                                Read
                            </span>
                        `;

                    return `
                        <div
                            class="notification-card
                            ${isUnread ? 'unread' : 'read'}"
                        >

                            <div class="notification-icon">
                                <i class="bi ${escapeHtml(
                                    icon
                                )}"></i>
                            </div>

                            <div class="notification-content">

                                <div class="notification-type">

                                    <strong>
                                        ${escapeHtml(
                                            title
                                        )}
                                    </strong>

                                    ${
                                        isUnread
                                        ? `
                                            <span class="new-badge">
                                                NEW
                                            </span>
                                        `
                                        : ''
                                    }

                                </div>

                                <div class="notification-message">
                                    ${escapeHtml(
                                        notification.message
                                    )}
                                </div>

                                ${orderInformation}

                                <div class="notification-time">

                                    <i class="bi bi-clock me-1"></i>

                                    ${formatDate(
                                        notification.created_at
                                    )}

                                </div>

                            </div>

                            <div class="notification-card-actions">

                                ${actionHtml}

                                ${readHtml}

                            </div>

                        </div>
                    `;
                }
            );

        const paginationHtml =
            buildPagination(
                currentPage,
                totalPages
            );

        section.innerHTML =
            cards.join('') +
            paginationHtml;
    }

    window.LocaliteaNotificationPageUpdater =
        function (
            data,
            hasNewNotifications = false
        ) {

            if (
                !data ||
                !data.success
            ) {
                return;
            }

            const notifications =
                Array.isArray(
                    data.notifications
                )
                    ? data.notifications
                    : [];

            let detectedNew = false;

            const currentIds =
                new Set(
                    notifications.map(
                        function (
                            notification
                        ) {
                            return String(
                                notification.id
                            );
                        }
                    )
                );

            if (
                knownNotificationIds === null
            ) {

                knownNotificationIds =
                    currentIds;

            } else {

                notifications.forEach(
                    function (
                        notification
                    ) {

                        if (
                            !knownNotificationIds.has(
                                String(
                                    notification.id
                                )
                            )
                        ) {
                            detectedNew = true;
                        }
                    }
                );

                knownNotificationIds =
                    currentIds;
            }

            renderNotifications(
                data,
                Boolean(
                    hasNewNotifications ||
                    detectedNew
                )
            );
        };

    async function fetchAllNotifications() {

        try {

            const response =
                await fetch(
                    'notifications.php?ajax=notifications&_=' +
                    Date.now(),
                    {
                        method: 'GET',
                        cache: 'no-store',
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
                    'Notification request failed.'
                );
            }

            const data =
                await response.json();

            if (
                data &&
                data.success
            ) {

                window.LocaliteaNotificationPageUpdater(
                    data,
                    false
                );
            }

        } catch (error) {

            console.error(
                'Unable to load Admin notifications:',
                error
            );
        }
    }

    document.addEventListener(
        'click',
        async function (event) {

            const paginationLink =
                event.target.closest(
                    '.notification-pagination a[href]'
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

            const pageUrl =
                new URL(
                    paginationLink.href,
                    window.location.href
                );

            window.history.pushState(
                {},
                '',
                pageUrl.pathname +
                (
                    pageUrl.search
                        ? pageUrl.search
                        : ''
                ) +
                pageUrl.hash
            );

            if (cachedNotificationData) {

                window.LocaliteaNotificationPageUpdater(
                    cachedNotificationData,
                    false
                );

            } else {

                await fetchAllNotifications();
            }
        }
    );

    window.addEventListener(
        'popstate',
        function () {

            if (cachedNotificationData) {

                window.LocaliteaNotificationPageUpdater(
                    cachedNotificationData,
                    false
                );

            } else {

                fetchAllNotifications();
            }
        }
    );

    async function refreshNotificationPage() {

        try {

            const response =
                await fetch(
                    'notifications.php?ajax=notifications&_=' +
                    Date.now(),
                    {
                        method: 'GET',
                        cache: 'no-store',
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
                    'Notification request failed.'
                );
            }

            const data =
                await response.json();

            if (
                data &&
                data.success
            ) {

                window.LocaliteaNotificationPageUpdater(
                    data,
                    false
                );
            }

        } catch (error) {

            console.error(
                'Unable to refresh notifications:',
                error
            );
        }
    }

    document.addEventListener(
        'submit',
        async function (event) {

            const form =
                event.target;

            if (
                !(form instanceof HTMLFormElement)
            ) {
                return;
            }

            const markReadButton =
                form.querySelector(
                    'button[name="mark_read"]'
                );

            const markAllButton =
                form.querySelector(
                    'button[name="mark_all_read"]'
                );

            /* MARK SINGLE AS READ */
            if (markReadButton) {

                event.preventDefault();

                const formData =
                    new FormData(form);

                formData.set(
                    'mark_read',
                    '1'
                );

                formData.set(
                    'ajax',
                    'notification_action'
                );

                markReadButton.disabled =
                    true;

                const originalHtml =
                    markReadButton.innerHTML;

                markReadButton.innerHTML = `
                    <span
                        class="spinner-border spinner-border-sm me-1"
                        aria-hidden="true"
                    ></span>
                    Marking...
                `;

                try {

                    const response =
                        await fetch(
                            'notifications.php',
                            {
                                method: 'POST',
                                body: formData,
                                cache: 'no-store',
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

                    if (
                        !data ||
                        !data.success
                    ) {

                        throw new Error(
                            data?.message ||
                            'Unable to mark notification as read.'
                        );
                    }

                    showNotificationToast(
                        data.message ||
                        'Notification marked as read.'
                    );

                    await refreshNotificationPage();

                } catch (error) {

                    console.error(
                        'Mark notification as read failed:',
                        error
                    );

                    showNotificationToast(
                        error.message ||
                        'Unable to mark notification as read.'
                    );

                    markReadButton.disabled =
                        false;

                    markReadButton.innerHTML =
                        originalHtml;
                }

                return;
            }

            /* MARK ALL AS READ */
            if (markAllButton) {

                event.preventDefault();

                const formData =
                    new FormData(form);

                formData.set(
                    'mark_all_read',
                    '1'
                );

                formData.set(
                    'ajax',
                    'notification_action'
                );

                markAllButton.disabled =
                    true;

                const originalHtml =
                    markAllButton.innerHTML;

                markAllButton.innerHTML = `
                    <span
                        class="spinner-border spinner-border-sm me-1"
                        aria-hidden="true"
                    ></span>
                    Marking...
                `;

                try {

                    const response =
                        await fetch(
                            'notifications.php',
                            {
                                method: 'POST',
                                body: formData,
                                cache: 'no-store',
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

                    if (
                        !data ||
                        !data.success
                    ) {

                        throw new Error(
                            data?.message ||
                            'Unable to mark all notifications as read.'
                        );
                    }

                    showNotificationToast(
                        data.message ||
                        'All notifications marked as read.'
                    );

                    await refreshNotificationPage();

                } catch (error) {

                    console.error(
                        'Mark all notifications as read failed:',
                        error
                    );

                    showNotificationToast(
                        error.message ||
                        'Unable to mark all notifications as read.'
                    );

                    markAllButton.disabled =
                        false;

                    markAllButton.innerHTML =
                        originalHtml;
                }
            }
        }
    );

    if (
        document.readyState === 'loading'
    ) {

        document.addEventListener(
            'DOMContentLoaded',
            fetchAllNotifications
        );

    } else {

        fetchAllNotifications();
    }

})();
</script>

<?php require_once '../includes/footer.php'; ?>