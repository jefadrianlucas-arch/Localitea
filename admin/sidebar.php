<?php
$sidebarUnreadNotifications = 0;

try {
    if (isset($pdo)) {
        $sidebarNotificationStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM notifications
            WHERE recipient_role = 'admin'
              AND is_read = 0
        ");
        $sidebarNotificationStmt->execute();
        $sidebarUnreadNotifications = (int)$sidebarNotificationStmt->fetchColumn();
    }
} catch (Throwable $e) {
    $sidebarUnreadNotifications = 0;
}
?>

<style>
/* =========================================================
   ADMIN SIDEBAR BASE
========================================================= */

.sidebar-admin {
    width: 260px;
    min-width: 260px;

    flex: 0 0 260px;

    min-height: 100vh;
    height: 100vh;

    position: fixed;

    top: 0;
    left: 0;
    bottom: 0;

    background: #FDF8F2;

    border-right: 1px solid #6F4E37;

    overflow-y: auto;
    overflow-x: hidden;

    z-index: 1100;

    box-sizing: border-box;
}

.sidebar-brand {
    padding: 24px 20px 16px;

    text-align: center;

    border-bottom: 1px solid #6F4E37;
}

.sidebar-brand .logo-circle {
    width: 56px;
    height: 56px;

    border-radius: 50%;

    background: #2c221e;

    display: flex;
    align-items: center;
    justify-content: center;

    margin: 0 auto 8px;
}

.sidebar-brand .logo-circle i {
    color: #E6DEC9;
    font-size: 1.5rem;
}

.sidebar-brand span {
    display: block;

    font-weight: 800;

    letter-spacing: 2px;

    color: #2c221e;

    font-size: 0.95rem;
}

.sidebar-nav {
    padding: 16px 12px;
    margin: 0;

    list-style: none;
}

.sidebar-nav .nav-link {
    width: 100%;

    color: #4A3525;

    font-weight: 500;
    font-size: 0.9rem;

    padding: 10px 14px;

    border-radius: 10px;

    margin-bottom: 4px;

    display: flex;

    align-items: center;

    gap: 10px;

    text-decoration: none;

    box-sizing: border-box;
}

.sidebar-nav .nav-link:hover {
    background: #F0E6D6;
}

.sidebar-nav .nav-link.active {
    background: #4A3525;
    color: #ffffff;
}

.sidebar-nav .nav-link i {
    width: 18px;

    flex: 0 0 18px;

    font-size: 1rem;

    text-align: center;
}

.sidebar-notification-badge {
    margin-left: auto;

    min-width: 20px;
    height: 20px;

    padding: 0 6px;

    border-radius: 999px;

    background: #B33A3A;

    color: #ffffff;

    font-size: 0.68rem;

    font-weight: 800;

    line-height: 20px;

    text-align: center;
}

.sidebar-nav .nav-link.active .sidebar-notification-badge {
    background: #ffffff;
    color: #4A3525;
}

/* =========================================================
   RESPONSIVE: off-canvas drawer below 992px
   Desktop (>= 992px): fixed 260px sidebar, always visible.
   Mobile / tablet (< 992px): the sidebar slides in as a
   drawer, opened by a .admin-menu-toggle button and closed
   by the backdrop, Escape, or picking a nav link.
========================================================= */

.sidebar-backdrop,
.admin-menu-toggle {
    display: none;
}

.admin-menu-toggle {
    flex: 0 0 auto;
    width: 42px;
    height: 42px;
    align-items: center;
    justify-content: center;
    padding: 0;
    border: 1px solid #B8A08A;
    border-radius: 12px;
    background: #FFFFFF;
    color: #4A3525;
    font-size: 1.35rem;
    line-height: 1;
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
}

.admin-menu-toggle:hover {
    background: #FDF8F2;
    border-color: #8B6F5A;
}

.admin-menu-toggle:focus-visible,
.sidebar-nav .nav-link:focus-visible {
    outline: 3px solid #C69C6D;
    outline-offset: 2px;
}

@media (max-width: 991.98px) {

    .sidebar-admin {
        width: min(84vw, 300px);
        min-width: 0;
        height: 100vh;
        height: 100dvh;
        transform: translateX(-100%);
        visibility: hidden;
        box-shadow: none;
        transition:
            transform .25s ease,
            visibility 0s linear .25s,
            box-shadow .25s ease;
        overscroll-behavior: contain;
        padding-bottom: env(safe-area-inset-bottom, 0px);
        z-index: 1200;
    }

    body.admin-nav-open .sidebar-admin {
        transform: translateX(0);
        visibility: visible;
        box-shadow: 0 0 40px rgba(44, 34, 30, .35);
        transition:
            transform .25s ease,
            visibility 0s linear 0s,
            box-shadow .25s ease;
    }

    .sidebar-nav .nav-link {
        min-height: 46px;
    }

    .sidebar-backdrop {
        display: block;
        position: fixed;
        inset: 0;
        z-index: 1190;
        background: rgba(44, 34, 30, .5);
        opacity: 0;
        pointer-events: none;
        transition: opacity .25s ease;
    }

    body.admin-nav-open .sidebar-backdrop {
        opacity: 1;
        pointer-events: auto;
    }

    body.admin-nav-open {
        overflow: hidden;
    }

    .admin-menu-toggle {
        display: inline-flex;
    }

    .admin-menu-toggle.admin-menu-toggle-floating {
        position: fixed;
        top: 10px;
        left: 10px;
        z-index: 1050;
        box-shadow: 0 3px 10px rgba(44, 34, 30, .18);
    }

    .modal {
        z-index: 1250;
    }

    .modal-backdrop {
        z-index: 1240;
    }
}

@media (min-width: 992px) {
    .admin-main,
    .admin-dashboard,
    .sales-page,
    .settings-page,
    .profile-page,
    .admin-shell-main {
        margin-left: 260px;
        width: calc(100% - 260px);
        min-width: 0;
    }
}

@media (max-width: 991.98px) {
    .admin-main,
    .admin-dashboard,
    .sales-page,
    .settings-page,
    .profile-page,
    .admin-shell-main {
        margin-left: 0;
        width: 100%;
        min-width: 0;
    }
}

@media (prefers-reduced-motion: reduce) {
    .sidebar-admin,
    .sidebar-backdrop {
        transition: none !important;
    }
}

/* =========================================================
   LIVE NOTIFICATION TOAST
========================================================= */

.localitea-notification-toast-container {
    position: fixed;

    right: 20px;
    bottom: 20px;

    width: min(
        340px,
        calc(100vw - 40px)
    );

    z-index: 2000;
}


.localitea-notification-toast {
    border: 1px solid #6F4E37 !important;

    border-left: 4px solid #4A3525 !important;

    border-radius: 11px !important;

    box-shadow:
        0 8px 24px rgba(44, 34, 30, .16) !important;
}


.localitea-notification-toast .toast-body {
    padding: 11px 12px;

    color: #2C221E;

    font-size: .76rem;
    line-height: 1.35;
}


@media (max-width: 767.98px) {

    .localitea-notification-toast-container {
        right: 12px;
        bottom: 78px;

        width: min(
            320px,
            calc(100vw - 24px)
        );
    }
}
</style>

<div class="d-flex flex-column sidebar-admin" id="adminSidebar" aria-label="Admin navigation">

    <div class="sidebar-brand">
        <div class="logo-circle">
            <i class="bi bi-cup-hot-fill"></i>
        </div>
        <span>LOCAL</span>
    </div>

    <ul class="nav flex-column sidebar-nav flex-grow-1">

                <!-- DASHBOARD -->
        <li class="nav-item">
            <a
                href="./dashboard.php"
                class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'active' : '' ?>"
            >
                <i class="bi bi-grid-1x2-fill"></i>
                Dashboard
            </a>
        </li>

        <!-- ORDERS -->
        <li class="nav-item">
            <a
                href="orders.php"
                class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'orders.php' ? 'active' : '' ?>"
            >
                <i class="bi bi-bag-check-fill"></i>
                Order Queue
            </a>
        </li>

                    <!-- SALES REPORTS -->
                    <li class="nav-item">
                        <a
                            href="sales-reports.php"
                            class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'sales-reports.php' ? 'active' : '' ?>"
                        >
                            <i class="bi bi-file-earmark-bar-graph-fill"></i>
                            Sales Reports
                        </a>
                    </li>

                    <!-- NOTIFICATIONS -->
                    <li class="nav-item">
                        <a
                            href="notifications.php"
                            class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'notifications.php' ? 'active' : '' ?>"
                        >
                            <i class="bi bi-bell-fill"></i>
                            Notifications

                            <?php if ($sidebarUnreadNotifications > 0): ?>

                                <span
                                    class="sidebar-notification-badge"
                                    id="sidebarNotificationBadge"
                                >
                                    <?= $sidebarUnreadNotifications > 99
                                        ? '99+'
                                        : $sidebarUnreadNotifications ?>
                                </span>

                            <?php endif; ?>

                        </a>
                    </li>

                    <!-- SETTINGS -->
                    <li class="nav-item">
                        <a
                            href="settings.php"
                            class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'settings.php' ? 'active' : '' ?>"
                        >
                            <i class="bi bi-gear-fill"></i>
                            Settings
                        </a>
                    </li>

                </ul>
            </div>

<div class="sidebar-backdrop" id="adminSidebarBackdrop"></div>

<script>
(function () {
    var body = document.body;
    var sidebar = document.getElementById('adminSidebar');
    var backdrop = document.getElementById('adminSidebarBackdrop');
    var closeBtn = document.getElementById('adminSidebarClose');
    var lastToggle = null;

    if (!sidebar) {
        return;
    }

    function isOpen() {
        return body.classList.contains('admin-nav-open');
    }

    function setOpen(open) {
        body.classList.toggle('admin-nav-open', open);

        document.querySelectorAll('.admin-menu-toggle').forEach(function (btn) {
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });

        if (open && closeBtn) {
            closeBtn.focus({ preventScroll: true });
        } else if (!open && lastToggle) {
            lastToggle.focus({ preventScroll: true });
        }
    }

    function bindToggles() {
        var toggles = document.querySelectorAll('.admin-menu-toggle');

        /* Pages without their own top bar get a floating menu button. */
        if (!toggles.length) {
            var floating = document.createElement('button');
            floating.type = 'button';
            floating.className = 'admin-menu-toggle admin-menu-toggle-floating';
            floating.setAttribute('aria-label', 'Open menu');
            floating.setAttribute('aria-controls', 'adminSidebar');
            floating.setAttribute('aria-expanded', 'false');
            floating.innerHTML = '<i class="bi bi-list"></i>';
            document.body.appendChild(floating);
            toggles = [floating];
        }

        Array.prototype.forEach.call(toggles, function (btn) {
            btn.addEventListener('click', function () {
                lastToggle = btn;
                setOpen(!isOpen());
            });
        });
    }

    if (backdrop) {
        backdrop.addEventListener('click', function () { setOpen(false); });
    }

    if (closeBtn) {
        closeBtn.addEventListener('click', function () { setOpen(false); });
    }

    sidebar.addEventListener('click', function (event) {
        if (event.target.closest('a')) {
            body.classList.remove('admin-nav-open');
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && isOpen()) {
            setOpen(false);
        }
    });

    /* Leaving mobile width while the drawer is open: reset. */
    window.addEventListener('resize', function () {
        if (window.innerWidth >= 992 && isOpen()) {
            body.classList.remove('admin-nav-open');
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindToggles);
    } else {
        bindToggles();
    }
})();
</script>

<script>
(function () {

    /* =====================================================
       LIVE ADMIN NOTIFICATIONS
       Poll every 5 seconds
    ===================================================== */

    const notificationEndpoint =
        'notifications.php?ajax=notifications';


    /* Important notification types that can show a toast */
    const importantNotificationTypes = new Set([
        'new_order',
        'payment',
        'gcash_pending_verification',
        'customer_cancelled_order'
    ]);


    let initialized = false;

    let knownNotificationIds = new Set();


    const badge =
        document.getElementById(
            'sidebarNotificationBadge'
        );


    /* =====================================================
       HELPERS
    ===================================================== */

    function escapeHtml(value) {

        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');

    }


    function getNotificationTitle(type) {

        const titles = {

            new_order:
                'New Order',

            payment:
                'Payment Update',

            order_update:
                'Order Update',

            customer_cancelled_order:
                'Order Cancelled',

            gcash_pending_verification:
                'GCash Payment Verification'

        };

        return titles[type]
            || String(type || 'Notification')
                .replace(/[_-]/g, ' ')
                .replace(/\b\w/g,
                    char => char.toUpperCase()
                );

    }


    function getNotificationIcon(type) {

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

        return icons[type] || 'bi-bell';

    }


    /* =====================================================
       UPDATE SIDEBAR BADGE
    ===================================================== */

    function updateBadge(unreadCount) {

        if (!badge) {
            return;
        }


        const count =
            Number(unreadCount || 0);


        if (count > 0) {

            badge.hidden = false;

            badge.textContent =
                count > 99
                    ? '99+'
                    : String(count);

        } else {

            badge.hidden = true;

            badge.textContent = '0';

        }

    }


    /* =====================================================
       SHOW IMPORTANT NOTIFICATION TOAST
    ===================================================== */

    function showImportantToast(notification) {

        if (
            !importantNotificationTypes.has(
                notification.type
            )
        ) {
            return;
        }


        let container =
            document.querySelector(
                '.localitea-notification-toast-container'
            );


        if (!container) {

            container =
                document.createElement('div');

            container.className =
                'localitea-notification-toast-container';

            document.body.appendChild(
                container
            );

        }


        const wrapper =
            document.createElement('div');

        wrapper.className =
            'toast localitea-notification-toast';

        wrapper.setAttribute(
            'role',
            'status'
        );

        wrapper.setAttribute(
            'aria-live',
            'polite'
        );

        wrapper.setAttribute(
            'aria-atomic',
            'true'
        );


        wrapper.innerHTML = `

            <div class="toast-header">

                <i
                    class="bi ${escapeHtml(
                        getNotificationIcon(
                            notification.type
                        )
                    )} me-2"
                ></i>

                <strong class="me-auto">

                    ${escapeHtml(
                        getNotificationTitle(
                            notification.type
                        )
                    )}

                </strong>

                <small>
                    Now
                </small>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="toast"
                    aria-label="Close"
                ></button>

            </div>

            <div class="toast-body">

                ${escapeHtml(
                    notification.message
                )}

            </div>
        `;


        container.appendChild(
            wrapper
        );


        if (
            window.bootstrap &&
            bootstrap.Toast
        ) {

            const toast =
                bootstrap.Toast.getOrCreateInstance(
                    wrapper,
                    {
                        autohide: true,
                        delay: 3500
                    }
                );

            toast.show();


            wrapper.addEventListener(
                'hidden.bs.toast',
                function () {

                    wrapper.remove();

                }
            );

        } else {

            setTimeout(
                function () {

                    wrapper.remove();

                },
                3500
            );

        }

    }


    /* =====================================================
       FETCH NOTIFICATIONS
    ===================================================== */

    async function fetchNotifications() {

        try {

            const response =
                await fetch(
                    notificationEndpoint +
                    '&_=' +
                    Date.now(),
                    {
                        method: 'GET',
                        cache: 'no-store',
                        headers: {
                            'X-Requested-With':
                                'XMLHttpRequest'
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


            if (!data.success) {
                return;
            }


            const notifications =
                Array.isArray(
                    data.notifications
                )
                    ? data.notifications
                    : [];


            /* Update unread badge */
            updateBadge(
                data.unread_count
            );


            /* =================================================
               FIRST LOAD
               Do NOT show toast for existing notifications.
            ================================================= */

            if (!initialized) {

                notifications.forEach(
                    function (notification) {

                        knownNotificationIds.add(
                            String(
                                notification.id
                            )
                        );

                    }
                );


                initialized = true;

            } else {

                /* =============================================
                   FIND ONLY NEW NOTIFICATIONS
                ============================================= */

                const newNotifications =
                    notifications.filter(
                        function (notification) {

                            return !knownNotificationIds.has(
                                String(
                                    notification.id
                                )
                            );

                        }
                    );


                newNotifications
                    .slice()
                    .reverse()
                    .forEach(
                        function (notification) {

                            showImportantToast(
                                notification
                            );

                        }
                    );


                notifications.forEach(
                    function (notification) {

                        knownNotificationIds.add(
                            String(
                                notification.id
                            )
                        );

                    }
                );

            }


            /* =================================================
               UPDATE FULL NOTIFICATIONS PAGE
               Only happens when notifications.php is open.
            ================================================= */

            if (
                typeof window.LocaliteaNotificationPageUpdater
                === 'function'
            ) {

                window.LocaliteaNotificationPageUpdater(
                    data
                );

            }


        } catch (error) {

            console.error(
                'Live notifications error:',
                error
            );

        }

    }


    /* =====================================================
       START POLLING
    ===================================================== */

    function startNotificationPolling() {

        fetchNotifications();


        setInterval(
            fetchNotifications,
            5000
        );

    }


    if (
        document.readyState === 'loading'
    ) {

        document.addEventListener(
            'DOMContentLoaded',
            startNotificationPolling
        );

    } else {

        startNotificationPolling();

    }

})();
</script>