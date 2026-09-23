<?php

$cart_count = isset($_SESSION['cart'])
    ? array_sum(array_column($_SESSION['cart'], 'quantity'))
    : 0;

$notification_count = 0;
$customer_notifications = [];

if (
    isset($_SESSION['user_id']) &&
    ($_SESSION['user_role'] ?? '') === 'customer'
) {
    $user_id = (int) $_SESSION['user_id'];

    /* Unread customer notifications */
    $notificationCountStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM notifications
        WHERE recipient_role = 'customer'
          AND recipient_id = ?
          AND is_read = 0
    ");
    $notificationCountStmt->execute([$user_id]);
    $notification_count = (int) $notificationCountStmt->fetchColumn();

    /* Latest customer notifications */
    $notificationStmt = $pdo->prepare("
        SELECT
            n.id,
            n.type,
            n.message,
            n.reference_id,
            n.is_read,
            n.created_at,
            o.order_number,
            o.claim_number
        FROM notifications n
        LEFT JOIN orders o
            ON o.id = n.reference_id
        WHERE n.recipient_role = 'customer'
          AND n.recipient_id = ?
        ORDER BY n.created_at DESC, n.id DESC
        LIMIT 5
    ");
    $notificationStmt->execute([$user_id]);
    $customer_notifications = $notificationStmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<style>
/* =========================================================
   CUSTOMER NAVBAR
========================================================= */

.navbar-custom {
    position: sticky !important;
    top: 0;
    background: #ffffff;

    /* Dark brown outline */
    border: 2px solid #6F4E37;

    box-shadow: 0 3px 12px rgba(0, 0, 0, 0.08);
    padding: 14px 0;
    z-index: 1030;

    box-sizing: border-box;
}

.navbar-custom .navbar-brand {
    display: inline-flex;
    align-items: center;
    padding: 0;
    margin: 0;
    text-decoration: none;
    flex-shrink: 0;
}

.navbar-custom .navbar-brand img {
    display: block;
    width: 155px;
    height: 55px;
    object-fit: contain;
    object-position: center;
}

/* Responsive logo */
@media (max-width: 991.98px) {
    .navbar-custom .navbar-brand img {
        width: 125px;
        height: 48px;
    }
}

@media (max-width: 575.98px) {
    .navbar-custom .navbar-brand img {
        width: 105px;
        height: 44px;
    }
}

.navbar-custom .nav-link {
    font-size: 1rem;
    font-weight: 500;
    color: #444444 !important;
    margin: 0 8px;
    transition: color 0.25s ease;
}

.navbar-custom .nav-link:hover {
    color: #6f4e37 !important;
}

.navbar-custom .nav-icon-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 42px;
    height: 42px;
    margin: 0 2px;
    padding: 0 !important;
}

.navbar-custom .nav-icon-link i {
    font-size: 1.35rem;
}

.navbar-custom .icon-badge {
    position: absolute;
    top: 2px;
    right: -2px;
    min-width: 18px;
    height: 18px;
    padding: 0 5px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 50rem;
    background: #dc3545;
    color: #ffffff;
    font-size: 0.65rem;
    font-weight: 700;
    line-height: 1;
}

/* Notification dropdown */
.navbar-custom .notification-dropdown {
    width: 360px;
    max-width: calc(100vw - 24px);
    padding: 0;
    border: 1px solid #e6dec9;
    border-radius: 14px;
    box-shadow: 0 10px 24px rgba(0, 0, 0, 0.12);
    overflow: hidden;
}

.navbar-custom .notification-header {
    padding: 14px 16px;
    font-size: 0.95rem;
    font-weight: 700;
    color: #2c221e;
}

.navbar-custom .notification-dropdown .dropdown-divider {
    margin: 0;
}

.navbar-custom .notification-item {
    display: block;
    padding: 12px 14px !important;
    margin: 0;
    white-space: normal !important;
    color: #333333 !important;
}

.navbar-custom .notification-item:hover {
    background: #f7f1e8;
}

.navbar-custom .notification-item.unread {
    background: #fdf8f2;
    border-left: 3px solid #6f4e37;
}

.navbar-custom .notification-content {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    width: 100%;
}

.navbar-custom .notification-icon {
    flex: 0 0 28px;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: #f5eee5;
    color: #6f4e37;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.82rem;
    margin-top: 1px;
}

.navbar-custom .notification-text {
    flex: 1 1 auto;
    min-width: 0;
}

.navbar-custom .notification-message {
    margin: 0;
    color: #4a3525;
    font-size: 0.82rem;
    font-weight: 600;
    line-height: 1.35;
    overflow-wrap: anywhere;
}

.navbar-custom .notification-order {
    display: block;
    margin-top: 4px;
    color: #6f6f6f;
    font-size: 0.71rem;
    line-height: 1.2;
}

.navbar-custom .notification-time {
    display: block;
    margin-top: 3px;
    color: #8a7f75;
    font-size: 0.68rem;
    line-height: 1.2;
}
.notification-unread {
    background: #FDF8F2;
    border-left: 3px solid #6F4E37;
}

.navbar-custom .notification-empty {
    padding: 18px 16px;
    text-align: center;
    color: #8a7f75;
    font-size: 0.8rem;
}

.navbar-custom .notification-view-all {
    display: block;
    padding: 11px 16px !important;
    text-align: center;
    color: #6f4e37 !important;
    font-size: 0.8rem;
    font-weight: 600;
}

.navbar-custom .notification-view-all:hover {
    background: #f7f1e8;
}

/* General dropdowns */
.navbar-custom .dropdown-menu {
    border: 1px solid #e6dec9;
    border-radius: 14px;
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
}

.navbar-custom .dropdown-item {
    padding: 10px 18px;
}

.navbar-custom .navbar-toggler {
    border: none;
}

.navbar-custom .navbar-toggler:focus {
    box-shadow: none;
}

/* =========================================================
   LOGOUT CONFIRMATION MODAL
========================================================= */

.logout-modal {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.45);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
}

.logout-modal.show {
    display: flex;
}

.logout-modal-box {
    width: 360px;
    max-width: calc(100% - 30px);
    background: #ffffff;
    border: 1px solid #b8a08a;
    border-radius: 15px;
    padding: 25px;
    text-align: center;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
}

.logout-modal-icon {
    width: 48px;
    height: 48px;
    margin: 0 auto 12px;
    border-radius: 50%;
    background: #f8e1e1;
    color: #dc3545;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
}

.logout-modal-box h5 {
    margin-bottom: 6px;
    color: #2c221e;
    font-weight: 700;
}

.logout-modal-box p {
    margin-bottom: 20px;
    color: #8a7f75;
    font-size: 0.85rem;
}

.logout-modal-actions {
    display: flex;
    justify-content: center;
    gap: 10px;
}

.logout-cancel,
.logout-confirm {
    min-width: 100px;
    padding: 9px 16px;
    border-radius: 8px;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
}

.logout-cancel {
    background: #ffffff;
    color: #4a3525;
    border: 1px solid #b8a08a;
}

.logout-cancel:hover {
    background: #f7f1e8;
}

.logout-confirm {
    background: #dc3545;
    color: #ffffff;
    border: 1px solid #dc3545;
}

.logout-confirm:hover {
    background: #bb2d3b;
    color: #ffffff;
}

@media (max-width: 991.98px) {
    .navbar-custom .notification-dropdown {
        width: min(360px, calc(100vw - 30px));
    }

    .navbar-custom {
        padding: 8px 0;
    }

    .navbar-custom .navbar-brand {
        font-size: 1.15rem;
        margin-right: 0;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .navbar-custom .navbar-brand i {
        font-size: 1.25rem;
        margin-right: 4px;
    }

    .navbar-custom .navbar-toggler {
        padding: 8px 10px;
        min-width: 44px;
        min-height: 44px;
    }

    .navbar-custom .navbar-toggler:focus-visible,
    .navbar-custom .nav-link:focus-visible {
        outline: 3px solid #C69C6D;
        outline-offset: 2px;
    }

    /* Opened menu: full-width, easy-to-tap rows */
    .navbar-custom .navbar-collapse {
        margin-top: 8px;
        padding-top: 6px;
        border-top: 1px solid #efe6dc;
    }

    .navbar-custom .navbar-nav {
        align-items: stretch !important;
        gap: 2px;
    }

    .navbar-custom .navbar-nav .nav-item {
        margin-right: 0 !important;
    }

    .navbar-custom .navbar-nav .nav-link {
        display: flex;
        align-items: center;
        min-height: 46px;
        margin: 0;
        padding: 10px 8px;
        border-radius: 10px;
    }

    .navbar-custom .navbar-nav .nav-link:hover {
        background: #f7f1e8;
    }

    .navbar-custom .navbar-nav .nav-icon-link {
        width: auto;
        height: auto;
        justify-content: flex-start;
        padding: 10px 8px !important;
    }

    /* Login / Register buttons stack full width */
    .navbar-custom .navbar-nav .btn {
        width: 100%;
        min-height: 44px;
        margin: 4px 0 !important;
    }

    /* Dropdown menus open inline inside the mobile menu */
    .navbar-custom .navbar-nav .dropdown-menu {
        position: static;
        float: none;
        width: 100%;
        margin: 4px 0 8px;
        box-shadow: none;
    }

    .navbar-custom .notification-dropdown {
        width: 100%;
        max-width: 100%;
    }

    /* The bell is icon-only on desktop; give it a text label in the phone menu. */
    .navbar-custom #notificationDropdown::after {
        content: "Notifications";
        margin-left: 12px;
        font-size: 1rem;
        font-weight: 500;
    }

    .navbar-custom #notificationDropdown .icon-badge {
        position: static;
        margin-left: auto;
    }

    .navbar-custom .navbar-cart-mobile {
        width: 44px;
        height: 44px;
        margin: 0 2px 0 auto !important;
    }

    .logout-modal-box {
        padding: 20px;
    }

    .logout-cancel,
    .logout-confirm {
        flex: 1 1 0;
        min-height: 44px;
    }
}

</style>

<nav class="navbar navbar-expand-lg navbar-custom sticky-top">
    <div class="container">

        <a class="navbar-brand" href="../customer/index.php" aria-label="Local Milktea House Home">
        <img
            src="../assets/images/logo.png"
            alt="Local Milktea House"
        >
        </a>

        <!-- Cart shortcut: always visible on phones, without opening the menu -->
        <a
            class="nav-link nav-icon-link position-relative ms-auto me-1 d-lg-none navbar-cart-mobile"
            href="../customer/cart.php"
            aria-label="Cart"
        >
            <i class="bi bi-cart3"></i>
            <?php if ($cart_count > 0): ?>
                <span class="icon-badge">
                    <?= $cart_count > 99 ? '99+' : $cart_count ?>
                </span>
            <?php endif; ?>
        </a>

        <button
            class="navbar-toggler"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#navbarNav"
            aria-controls="navbarNav"
            aria-expanded="false"
            aria-label="Toggle navigation"
        >
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto align-items-center">

                <li class="nav-item">
                    <a class="nav-link" href="../customer/index.php">Home</a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="../customer/menu.php">Menu</a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="../customer/index.php#promotions">Promotions</a>
                </li>

                <li class="nav-item">
                    <a
                        class="nav-link"
                        href="<?= (isset($_SESSION['user_id']) && ($_SESSION['user_role'] ?? '') === 'customer')
                            ? '../customer/dashboard.php'
                            : '../customer/monitor-guest-order.php' ?>"
                    >
                        Monitor Order
                    </a>
                </li>

                <!-- Cart (desktop; phones use the shortcut next to the menu button) -->
                <li class="nav-item dropdown me-2 d-none d-lg-block">
                    <a
                        class="nav-link nav-icon-link position-relative"
                        href="../customer/cart.php"
                        aria-label="Cart"
                    >
                        <i class="bi bi-cart3"></i>

                        <?php if ($cart_count > 0): ?>
                            <span class="icon-badge">
                                <?= $cart_count > 99 ? '99+' : $cart_count ?>
                            </span>
                        <?php endif; ?>
                    </a>
                </li>

                <!-- Notifications -->
                <?php if (isset($_SESSION['user_id']) && ($_SESSION['user_role'] ?? '') === 'customer'): ?>
                    <li class="nav-item dropdown me-2">
                        <a
                            class="nav-link nav-icon-link position-relative"
                            href="#"
                            id="notificationDropdown"
                            role="button"
                            data-bs-toggle="dropdown"
                            aria-expanded="false"
                            aria-label="Notifications"
                        >
                            <i class="bi bi-bell"></i>

                            <?php if ($notification_count > 0): ?>
                                <span class="icon-badge">
                                    <?= $notification_count > 99 ? '99+' : $notification_count ?>
                                </span>
                            <?php endif; ?>
                        </a>

                        <ul
                            class="dropdown-menu dropdown-menu-end notification-dropdown"
                            aria-labelledby="notificationDropdown"
                        >
                            <li class="notification-header">Notifications</li>
                            <li><hr class="dropdown-divider"></li>

                            <?php if (empty($customer_notifications)): ?>
                                <li class="notification-empty">
                                    No notifications yet.
                                </li>
                            <?php else: ?>
                                <?php foreach ($customer_notifications as $notification): ?>
                                    <li>
                                        <a class="dropdown-item notification-dropdown-item
                                         <?= (int)$notification['is_read'] === 0 ? 'notification-unread' : '' ?>"
                                          href="../customer/read_notification.php?id=<?= (int)$notification['id'] ?>">
                                            <div class="notification-content">
                                                <span class="notification-icon">
                                                    <i class="bi bi-bell-fill"></i>
                                                </span>

                                                <span class="notification-text">
                                                    <span class="notification-message">
                                                        <?= htmlspecialchars($notification['message']) ?>
                                                    </span>

                                                    <?php if (!empty($notification['order_number'])): ?>
                                                        <span class="notification-order">
                                                            <?= htmlspecialchars($notification['order_number']) ?>
                                                        </span>
                                                    <?php endif; ?>

                                                    <span class="notification-time">
                                                        <?= htmlspecialchars(date('M d, Y g:i A', strtotime($notification['created_at']))) ?>
                                                    </span>
                                                </span>
                                            </div>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <li><hr class="dropdown-divider"></li>

                           <li class="text-center">
                        <a href="../customer/read_notification.php?mark_all=1"
                            class="dropdown-item small fw-semibold">
                                <i class="bi bi-check2-all me-1"></i>
                                Mark All as Read
                            </a>
                    </li>
                        </ul>
                    </li>
                <?php endif; ?>

                <!-- User -->
                <?php if (isset($_SESSION['user_id'])): ?>
                    <li class="nav-item dropdown">
                        <a
                            class="nav-link dropdown-toggle fw-semibold"
                            href="#"
                            role="button"
                            data-bs-toggle="dropdown"
                            aria-expanded="false"
                        >
                            <i class="bi bi-person-circle fs-5"></i>
                            <?= htmlspecialchars($_SESSION['user_name']) ?>
                        </a>

                        <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                                <a class="dropdown-item" href="../customer/profile.php">
                                    <i class="bi bi-person me-2"></i>
                                    Profile
                                </a>
                            </li>

                            <li><hr class="dropdown-divider"></li>

                            <li>
                                <a class="dropdown-item text-danger" href="#" id="logoutBtn">
                                    <i class="bi bi-box-arrow-right me-2"></i>
                                    Logout
                                </a>
                            </li>
                        </ul>
                    </li>
                <?php else: ?>
                    <li class="nav-item">
                        <a class="btn btn-outline-dark me-2" href="../auth/login.php">Login</a>
                    </li>

                    <li class="nav-item">
                        <a class="btn btn-primary-custom" href="../auth/register.php">Register</a>
                    </li>
                <?php endif; ?>

            </ul>
        </div>
    </div>
</nav>

<!-- Logout Confirmation Modal -->
<div class="logout-modal" id="logoutModal">
    <div class="logout-modal-box">
        <div class="logout-modal-icon">
            <i class="bi bi-box-arrow-right"></i>
        </div>

        <h5>Confirm Logout</h5>
        <p>Are you sure you want to logout?</p>

        <div class="logout-modal-actions">
            <button type="button" class="logout-cancel" id="cancelLogout">
                Cancel
            </button>

            <a href="../auth/logout.php" class="logout-confirm">
                Log Out
            </a>
        </div>
    </div>
</div>

<script>
const logoutBtn = document.getElementById('logoutBtn');
const logoutModal = document.getElementById('logoutModal');
const cancelLogout = document.getElementById('cancelLogout');

if (logoutBtn && logoutModal && cancelLogout) {
    logoutBtn.addEventListener('click', function (event) {
        event.preventDefault();
        logoutModal.classList.add('show');
    });

    cancelLogout.addEventListener('click', function () {
        logoutModal.classList.remove('show');
    });

    logoutModal.addEventListener('click', function (event) {
        if (event.target === logoutModal) {
            logoutModal.classList.remove('show');
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            logoutModal.classList.remove('show');
        }
    });
}
</script>
