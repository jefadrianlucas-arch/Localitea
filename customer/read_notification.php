<?php

require_once '../includes/db.php';

if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['user_role'] ?? '') !== 'customer'
) {
    header('Location: ../auth/login.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| Mark One Notification as Read
|--------------------------------------------------------------------------
*/

if (isset($_GET['id'])) {

    $notification_id = (int) $_GET['id'];

    if ($notification_id > 0) {

        $stmt = $pdo->prepare("
            UPDATE notifications
            SET is_read = 1
            WHERE id = ?
              AND recipient_role = 'customer'
              AND recipient_id = ?
        ");

        $stmt->execute([
            $notification_id,
            $user_id
        ]);
    }
}

/*
|--------------------------------------------------------------------------
| Mark All Customer Notifications as Read
|--------------------------------------------------------------------------
*/

if (isset($_GET['mark_all']) && $_GET['mark_all'] === '1') {

    $stmt = $pdo->prepare("
        UPDATE notifications
        SET is_read = 1
        WHERE recipient_role = 'customer'
          AND recipient_id = ?
          AND is_read = 0
    ");

    $stmt->execute([
        $user_id
    ]);
}

/*
|--------------------------------------------------------------------------
| Return to Customer Dashboard
|--------------------------------------------------------------------------
*/

/* MARK ALL */
if (isset($_GET['mark_all']) && $_GET['mark_all'] === '1') {
    $stmt = $pdo->prepare("
        UPDATE notifications
        SET is_read = 1
        WHERE recipient_role = 'customer'
          AND recipient_id = ?
          AND is_read = 0
    ");
    $stmt->execute([$user_id]);

    // Stay on the current page
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '../customer/dashboard.php'));
    exit;
}

/* MARK ONE */
if (isset($_GET['id'])) {
    $notification_id = (int) $_GET['id'];

    if ($notification_id > 0) {
        $stmt = $pdo->prepare("
            UPDATE notifications
            SET is_read = 1
            WHERE id = ?
              AND recipient_role = 'customer'
              AND recipient_id = ?
        ");
        $stmt->execute([$notification_id, $user_id]);
    }

    // After clicking one notification, go to dashboard
    header('Location: dashboard.php');
    exit;
}