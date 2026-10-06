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
        LIMIT 20
    ");
    $notificationStmt->execute([$user_id]);
    $customer_notifications = $notificationStmt->fetchAll(PDO::FETCH_ASSOC);
}

/* Which link is highlighted (display only). */
$navbar_page      = basename((string) parse_url((string) ($_SERVER['PHP_SELF'] ?? ''), PHP_URL_PATH));
$navbar_is_member = isset($_SESSION['user_id']) && ($_SESSION['user_role'] ?? '') === 'customer';
$navbar_order_page = $navbar_is_member ? 'dashboard.php' : 'monitor-guest-order.php';

$navbar_home = $navbar_page === 'index.php';
$navbar_menu = in_array($navbar_page, ['menu.php', 'product-view.php'], true);
$navbar_order = $navbar_page === $navbar_order_page;
$navbar_cart = $navbar_page === 'cart.php';
?>

<style>
/* =========================================================
   CUSTOMER NAVBAR — Local Milktea House
   Palette: espresso #2C221E · roast #4A3525 · mocha #6F4E37
            oat #F3EADF · foam #FBF7F1 · line #E6DACB
========================================================= */

.navbar-custom {
    position: sticky !important;
    top: 0;
    z-index: 1030;
    padding: 8px 0;
    background: rgba(255, 255, 255, 0.94);
    -webkit-backdrop-filter: saturate(1.4) blur(10px);
    backdrop-filter: saturate(1.4) blur(10px);
    border: 0;
    border-bottom: 1px solid #E6DACB;
    box-shadow: 0 4px 18px rgba(74, 53, 37, 0.07);
    box-sizing: border-box;
}

/* ---------- Brand: logo + "LOCAL / MILKTEA HOUSE" ---------- */
.navbar-custom .navbar-brand {
    display: inline-flex;
    flex-direction: row;
    align-items: center;
    justify-content: flex-start;
    gap: 10px;
    flex: 0 1 auto;
    min-width: 0;
    margin: 0;
    padding: 0;
    text-decoration: none;
    line-height: 1;
}

.navbar-custom .navbar-brand img {
    display: block;
    flex: 0 0 auto;
    width: clamp(38px, 3.4vw, 48px);
    height: auto;
    aspect-ratio: 1 / 1;
    object-fit: contain;
    object-position: center;
}

.navbar-custom .navbar-brand-text {
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 4px;
    min-width: 0;
}

.navbar-custom .navbar-brand-local {
    display: block;
    color: #4A3525;
    font-size: clamp(1.05rem, 1.3vw, 1.3rem);
    font-weight: 800;
    letter-spacing: 1.2px;
    line-height: 1;
    text-transform: uppercase;
    white-space: nowrap;
}

.navbar-custom .navbar-brand-sub {
    display: block;
    color: #9A7D62;
    font-size: clamp(0.56rem, 0.66vw, 0.66rem);
    font-weight: 700;
    letter-spacing: 2.4px;
    line-height: 1;
    text-transform: uppercase;
    white-space: nowrap;
}

/* ---------- Links ---------- */
.navbar-custom .navbar-nav {
    gap: 2px;
}

.navbar-custom .nav-link {
    margin: 0;
    padding: 8px 14px;
    border-radius: 50px;
    color: #5B4A3E !important;
    font-size: 0.94rem;
    font-weight: 600;
}

.navbar-custom .nav-link:hover,
.navbar-custom .nav-link:focus-visible {
    background: #F3EADF;
    color: #2C221E !important;
}

.navbar-custom .nav-link.active {
    background: #F3EADF;
    color: #2C221E !important;
}

.navbar-custom .nav-link:focus-visible,
.navbar-custom .navbar-toggler:focus-visible,
.navbar-custom .dropdown-item:focus-visible {
    outline: 3px solid rgba(111, 78, 55, 0.35);
    outline-offset: 2px;
}

/* ---------- Icon buttons (cart / bell) ---------- */
.navbar-custom .nav-icon-link {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 42px;
    height: 42px;
    margin: 0 2px;
    padding: 0 !important;
    border-radius: 50%;
}

.navbar-custom .nav-icon-link i {
    font-size: 1.25rem;
    line-height: 1;
}

.navbar-custom .icon-badge {
    position: absolute;
    top: 1px;
    right: -1px;
    min-width: 19px;
    height: 19px;
    padding: 0 5px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 2px solid #ffffff;
    border-radius: 50rem;
    background: #D6453B;
    color: #ffffff;
    font-size: 0.64rem;
    font-weight: 800;
    line-height: 1;
    font-variant-numeric: tabular-nums;
    box-sizing: content-box;
}

.navbar-custom .icon-badge[hidden] {
    display: none;
}

/* The cart badge uses the brand brown; the bell stays red. */
.navbar-custom .icon-badge.cart-badge {
    background: #332317;
}

/* ---------- Account pill ---------- */
.navbar-custom .nav-account {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    max-width: 100%;
    padding: 5px 12px 5px 6px;
    border: 1.5px solid #E0D2C2;
    background: #ffffff;
    color: #2C221E !important;
}

.navbar-custom .nav-account:hover,
.navbar-custom .nav-account:focus-visible,
.navbar-custom .nav-account.show {
    border-color: #B8A08A;
    background: #FBF7F1;
}

.navbar-custom .nav-account-avatar {
    flex: 0 0 auto;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: #332317;
    color: #ffffff;
    font-size: 0.95rem;
}

.navbar-custom .nav-account-name {
    min-width: 0;
    max-width: 150px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* ---------- Login / Register ---------- */
.navbar-custom .btn {
    border-radius: 50px;
    padding: 8px 18px;
    font-size: 0.9rem;
    font-weight: 700;
}

.navbar-custom .btn-outline-dark {
    border: 1.5px solid #4A3525;
    color: #4A3525;
    background: transparent;
}

.navbar-custom .btn-outline-dark:hover {
    background: #4A3525;
    border-color: #4A3525;
    color: #ffffff;
}

.navbar-custom .btn-primary-custom {
    border: 1.5px solid #24170F;
    background: #332317;
    color: #ffffff;
}

.navbar-custom .btn-primary-custom:hover {
    background: #24170F;
    border-color: #1A100B;
    color: #ffffff;
}

/* ---------- Dropdowns ---------- */
.navbar-custom .dropdown-menu {
    margin-top: 10px;
    padding: 6px;
    border: 1px solid #E6DACB;
    border-radius: 16px;
    box-shadow: 0 14px 34px rgba(74, 53, 37, 0.14);
}

.navbar-custom .dropdown-item {
    padding: 10px 14px;
    border-radius: 10px;
    color: #3A2C24;
    font-size: 0.9rem;
    font-weight: 600;
}

.navbar-custom .dropdown-item:hover,
.navbar-custom .dropdown-item:focus {
    background: #F3EADF;
    color: #2C221E;
}

.navbar-custom .dropdown-item.text-danger:hover {
    background: #FCEBEA;
    color: #B3382F !important;
}

.navbar-custom .dropdown-divider {
    margin: 6px 4px;
    border-color: #EFE5D9;
}

/* Notifications */
.navbar-custom .notification-dropdown {
    width: 370px;
    max-width: calc(100vw - 24px);
    padding: 0;
    max-height: min(72vh, 480px);
    overflow-x: hidden;
    overflow-y: auto;
    overscroll-behavior: contain;
}

.navbar-custom .notification-header {
    position: sticky;
    top: 0;
    z-index: 1;
    padding: 14px 16px 12px;
    background: #ffffff;
    color: #2C221E;
    font-size: 0.98rem;
    font-weight: 800;
}

.navbar-custom .notification-dropdown .dropdown-divider {
    margin: 0;
}

.navbar-custom .notification-dropdown-item {
    display: block;
    margin: 0;
    padding: 12px 14px !important;
    border-radius: 0;
    white-space: normal !important;
    color: #333333 !important;
}

.navbar-custom .notification-dropdown-item:hover {
    background: #F7F0E8;
}

.navbar-custom .notification-dropdown-item.notification-unread,
.navbar-custom .notification-unread {
    background: #FDF8F2;
    border-left: 3px solid #6F4E37;
}

.navbar-custom .notification-content {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    width: 100%;
}

.navbar-custom .notification-icon {
    flex: 0 0 30px;
    width: 30px;
    height: 30px;
    margin-top: 1px;
    border-radius: 50%;
    background: #F3EADF;
    color: #6F4E37;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.82rem;
}

.navbar-custom .notification-text {
    flex: 1 1 auto;
    min-width: 0;
    max-width: 100%;
    overflow: hidden;
}

.navbar-custom .notification-message {
    margin: 0;
    color: #4A3525;
    font-size: 0.83rem;
    font-weight: 700;
    line-height: 1.35;
    overflow-wrap: anywhere;
}

.navbar-custom .notification-order {
    display: block;
    max-width: 100%;
    margin-top: 4px;
    color: #6F6F6F;
    font-size: 0.72rem;
    line-height: 1.2;
    overflow-wrap: anywhere;
    word-break: break-word;
}

.navbar-custom .notification-time {
    display: block;
    max-width: 100%;
    margin-top: 3px;
    color: #8A7F75;
    font-size: 0.69rem;
    line-height: 1.2;
    overflow-wrap: anywhere;
    word-break: break-word;
}

.navbar-custom .notification-empty {
    padding: 22px 16px;
    text-align: center;
    color: #8A7F75;
    font-size: 0.82rem;
}

.navbar-custom .notification-dropdown li.text-center .dropdown-item {
    margin: 4px;
    text-align: center;
    color: #6F4E37;
}

/* Keep "Mark All as Read" visible at the bottom while the list scrolls */
.navbar-custom .notification-dropdown li.notification-footer {
    position: sticky;
    bottom: 0;
    z-index: 1;
    background: #ffffff;
    border-top: 1px solid #EFE5D9;
    box-shadow: 0 -6px 10px -8px rgba(44, 34, 30, .18);
}

.navbar-custom .navbar-toggler {
    border: 0;
    border-radius: 12px;
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
    z-index: 9999;
    display: none;
    align-items: center;
    justify-content: center;
    background: rgba(44, 34, 30, 0.5);
    -webkit-backdrop-filter: blur(2px);
    backdrop-filter: blur(2px);
}

.logout-modal.show {
    display: flex;
}

.logout-modal-box {
    width: 360px;
    max-width: calc(100% - 30px);
    padding: 26px 24px 22px;
    background: #ffffff;
    border: 1px solid #E6DACB;
    border-radius: 20px;
    text-align: center;
    box-shadow: 0 18px 44px rgba(44, 34, 30, 0.22);
}

.logout-modal-icon {
    width: 52px;
    height: 52px;
    margin: 0 auto 12px;
    border-radius: 50%;
    background: #FCEBEA;
    color: #D6453B;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
}

.logout-modal-box h5 {
    margin-bottom: 6px;
    color: #2C221E;
    font-weight: 800;
}

.logout-modal-box p {
    margin-bottom: 20px;
    color: #8A7F75;
    font-size: 0.86rem;
}

.logout-modal-actions {
    display: flex;
    justify-content: center;
    gap: 10px;
}

.logout-cancel,
.logout-confirm {
    min-width: 108px;
    padding: 10px 16px;
    border-radius: 50px;
    font-size: 0.86rem;
    font-weight: 700;
    cursor: pointer;
    text-decoration: none;
}

.logout-cancel {
    background: #ffffff;
    color: #4A3525;
    border: 1.5px solid #D5C6BA;
}

.logout-cancel:hover {
    background: #F3EADF;
    border-color: #4A3525;
}

.logout-confirm {
    background: #332317;
    color: #ffffff;
    border: 1.5px solid #332317;
}

.logout-confirm:hover {
    background: #24170F;
    color: #ffffff;
}

@media (prefers-reduced-motion: no-preference) {
    .navbar-custom .nav-link,
    .navbar-custom .btn,
    .navbar-custom .dropdown-item,
    .logout-cancel,
    .logout-confirm {
        transition: background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease;
    }
}

/* =========================================================
   PHONES & TABLETS
========================================================= */
@media (max-width: 575.98px) {
    .navbar-custom .navbar-brand {
        gap: 8px;
    }

    .navbar-custom .navbar-brand img {
        width: clamp(34px, 10vw, 40px);
    }

    .navbar-custom .navbar-brand-local {
        font-size: 1rem;
        letter-spacing: 1px;
    }

    .navbar-custom .navbar-brand-sub {
        font-size: 0.52rem;
        letter-spacing: 1.8px;
    }
}

@media (max-width: 359.98px) {
    .navbar-custom .navbar-brand-sub {
        display: none;
    }
}

@media (max-width: 991.98px) {
    .navbar-custom .navbar-toggler {
        min-width: 44px;
        min-height: 44px;
        padding: 8px 10px;
    }

    .navbar-custom .navbar-cart-mobile {
        width: 44px;
        height: 44px;
        margin: 0 4px 0 auto !important;
    }

    /* Opened menu: a tidy panel with easy-to-tap rows */
    .navbar-custom .navbar-collapse {
        margin-top: 8px;
        padding: 8px 0 4px;
        border-top: 1px solid #EFE5D9;
    }

    .navbar-custom .navbar-nav {
        align-items: stretch !important;
        gap: 2px;
    }

    .navbar-custom .navbar-nav .nav-item {
        margin: 0 !important;
    }

    .navbar-custom .navbar-nav .nav-link {
        display: flex;
        align-items: center;
        min-height: 46px;
        margin: 0;
        padding: 10px 14px;
        border-radius: 12px;
    }

    .navbar-custom .navbar-nav .nav-icon-link {
        width: auto;
        height: auto;
        justify-content: flex-start;
        border-radius: 12px;
        padding: 10px 14px !important;
    }

    .navbar-custom .nav-account {
        width: 100%;
        border-radius: 12px;
    }

    .navbar-custom .nav-account-name {
        max-width: none;
        flex: 1 1 auto;
    }

    /* Login / Register stack full width */
    .navbar-custom .navbar-nav .btn {
        width: 100%;
        min-height: 46px;
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
        order: 2;
        margin-left: 12px;
        font-size: 0.94rem;
        font-weight: 600;
    }

    .navbar-custom #notificationDropdown .icon-badge {
        order: 3;
        position: static;
        margin-left: auto;
    }

    .logout-modal-box {
        padding: 22px 18px 18px;
    }

    .logout-cancel,
    .logout-confirm {
        flex: 1 1 0;
        min-height: 46px;
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
            <span class="navbar-brand-text">
                <span class="navbar-brand-local">Local</span>
                <span class="navbar-brand-sub">Milktea House</span>
            </span>
        </a>

        <!-- Cart shortcut: always visible on phones, without opening the menu -->
        <a
            class="nav-link nav-icon-link position-relative ms-auto me-1 d-lg-none navbar-cart-mobile<?= $navbar_cart ? ' active' : '' ?>"
            href="../customer/cart.php"
            aria-label="Cart"
        >
            <i class="bi bi-cart3"></i>
            <span class="icon-badge cart-badge" data-cart-badge <?= $cart_count > 0 ? '' : 'hidden' ?>><?= $cart_count > 99 ? '99+' : (int) $cart_count ?></span>
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
                    <a class="nav-link<?= $navbar_home ? ' active' : '' ?>" href="../customer/index.php"<?= $navbar_home ? ' aria-current="page"' : '' ?>>Home</a>
                </li>

                <li class="nav-item">
                    <a class="nav-link<?= $navbar_menu ? ' active' : '' ?>" href="../customer/menu.php"<?= $navbar_menu ? ' aria-current="page"' : '' ?>>Menu</a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="../customer/index.php#promotions">Promotions</a>
                </li>

                <li class="nav-item">
                    <a
                        class="nav-link<?= $navbar_order ? ' active' : '' ?>"
                        href="<?= (isset($_SESSION['user_id']) && ($_SESSION['user_role'] ?? '') === 'customer')
                            ? '../customer/dashboard.php'
                            : '../customer/monitor-guest-order.php' ?>"
                        <?= $navbar_order ? ' aria-current="page"' : '' ?>
                    >
                        Order
                    </a>
                </li>

                <!-- Cart (desktop; phones use the shortcut next to the menu button) -->
                <li class="nav-item me-1 d-none d-lg-block">
                    <a
                        class="nav-link nav-icon-link position-relative<?= $navbar_cart ? ' active' : '' ?>"
                        href="../customer/cart.php"
                        aria-label="Cart"
                    >
                        <i class="bi bi-cart3"></i>
                        <span class="icon-badge cart-badge" data-cart-badge <?= $cart_count > 0 ? '' : 'hidden' ?>><?= $cart_count > 99 ? '99+' : (int) $cart_count ?></span>
                    </a>
                </li>

                <!-- Notifications -->
                <?php if (isset($_SESSION['user_id']) && ($_SESSION['user_role'] ?? '') === 'customer'): ?>
                    <li class="nav-item dropdown me-1">
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

                            <li class="text-center notification-footer">
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
                            class="nav-link nav-account dropdown-toggle fw-semibold"
                            href="#"
                            role="button"
                            data-bs-toggle="dropdown"
                            aria-expanded="false"
                        >
                            <span class="nav-account-avatar"><i class="bi bi-person-fill"></i></span>
                            <span class="nav-account-name"><?= htmlspecialchars((string) ($_SESSION['user_name'] ?? '')) ?></span>
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

<script>
/*
 * =========================================================
 * LIVE CART BADGE
 * =========================================================
 * The badge above is rendered by PHP when the page loads, so it
 * went stale whenever an item was added without a full reload
 * (AJAX "Add" buttons, or coming back with the browser's Back button).
 *
 * This keeps it in sync by re-reading the session cart:
 *   - after any fetch() call to a cart URL (add / update / remove)
 *   - when the page is restored from the back/forward cache
 *   - when the tab becomes visible again
 *   - on demand:  window.updateCartBadge(n)  or
 *                 document.dispatchEvent(new Event('cart:updated'))
 */
(function () {
    var badges = document.querySelectorAll('[data-cart-badge]');
    if (!badges.length) { return; }

    function setBadge(count) {
        count = parseInt(count, 10);
        if (isNaN(count) || count < 0) { count = 0; }

        badges.forEach(function (badge) {
            badge.textContent = count > 99 ? '99+' : String(count);
            badge.hidden = count <= 0;
        });
    }

    var nativeFetch = window.fetch ? window.fetch.bind(window) : null;
    var refreshing = false;
    var pending = false;

    function refreshBadge() {
        if (!nativeFetch) { return; }
        if (refreshing) { pending = true; return; }
        refreshing = true;

        nativeFetch('../customer/cart-count.php', {
            method: 'GET',
            cache: 'no-store',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
            .then(function (response) {
                return response.ok ? response.json() : null;
            })
            .then(function (data) {
                if (data && typeof data.count !== 'undefined') {
                    setBadge(data.count);
                }
            })
            .catch(function () { /* keep the server-rendered number */ })
            .then(function () {
                refreshing = false;
                if (pending) { pending = false; refreshBadge(); }
            });
    }

    window.updateCartBadge = setBadge;
    window.refreshCartBadge = refreshBadge;

    /* Refresh after any cart-related fetch(); the response is passed through untouched. */
    if (nativeFetch) {
        window.fetch = function (input) {
            var url = '';
            try {
                url = typeof input === 'string' ? input : (input && input.url) || '';
            } catch (e) { url = ''; }

            var request = nativeFetch.apply(window, arguments);

            if (/cart/i.test(url) && !/cart-count/i.test(url)) {
                request.then(function () { refreshBadge(); }, function () {});
            }

            return request;
        };
    }

    document.addEventListener('cart:updated', refreshBadge);

    window.addEventListener('pageshow', function (event) {
        if (event.persisted) { refreshBadge(); }
    });

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') { refreshBadge(); }
    });
})();
</script>