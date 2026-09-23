<?php

require_once '../includes/db.php';


/* =========================================================
   UNREAD NEW ORDER COUNT
========================================================= */

$stmtNotificationCount = $pdo->query("
    SELECT COUNT(*)
    FROM notifications
    WHERE recipient_role = 'staff'
      AND is_read = 0
");

$unreadNewOrders = (int)$stmtNotificationCount->fetchColumn();

/* =========================================================
   CURRENT PAGE
========================================================= */

$currentPage = basename($_SERVER['PHP_SELF']);

?>

<style>

/* =========================================================
   STAFF SIDEBAR
========================================================= */

.sidebar-staff {

    width: 260px;
    height: 100vh;

    position: fixed;

    top: 0;
    left: 0;

    flex-shrink: 0;

    background: #FDF8F2;

    border-right: 1px solid #8B6F5A;

    display: flex;
    flex-direction: column;

    overflow-y: auto;

    z-index: 1000;
}


/* =========================================================
   BRAND
========================================================= */

.sidebar-brand {

    padding: 24px 20px 16px;

    text-align: center;

    border-bottom: 1px solid #8B6F5A;
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


/* =========================================================
   NAVIGATION
========================================================= */

.sidebar-nav {

    padding: 16px 12px;
}

.sidebar-nav .nav-item {

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

    transition:
        background 0.2s ease,
        color 0.2s ease;
}


/* =========================================================
   NAVIGATION HOVER
========================================================= */

.sidebar-nav .nav-link:hover {

    background: #F0E6D6;

    color: #4A3525;

    text-decoration: none;
}


/* =========================================================
   ACTIVE NAVIGATION
========================================================= */

.sidebar-nav .nav-link.active {

    background: #4A3525;

    color: #ffffff;

    font-weight: 600;
}

.sidebar-nav .nav-link.active:hover {

    background: #4A3525;

    color: #ffffff;
}


/* =========================================================
   NAVIGATION ICON
========================================================= */

.sidebar-nav .nav-link i {

    font-size: 1rem;

    width: 18px;

    min-width: 18px;

    text-align: center;
}


/* =========================================================
   NOTIFICATION BADGE
========================================================= */

.notification-badge {

    margin-left: auto;

    min-width: 22px;
    height: 22px;

    padding: 0 6px;

    display: inline-flex;

    align-items: center;
    justify-content: center;

    background: #8B3A2F;

    color: #ffffff;

    border-radius: 20px;

    font-size: 11px;

    font-weight: 700;

    line-height: 1;
}

/* =========================================================
   RESPONSIVE: off-canvas drawer below 992px
   (Matches the admin sidebar pattern.)
========================================================= */

.staff-sidebar-close,
.staff-sidebar-backdrop,
.staff-menu-toggle {
    display: none;
}

.staff-menu-toggle {
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

.staff-menu-toggle:hover {
    background: #FDF8F2;
    border-color: #8B6F5A;
}

.staff-menu-toggle:focus-visible,
.staff-sidebar-close:focus-visible,
.sidebar-nav .nav-link:focus-visible {
    outline: 3px solid #C69C6D;
    outline-offset: 2px;
}

@media (max-width: 991.98px) {

    .sidebar-staff {
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

    body.staff-nav-open .sidebar-staff {
        transform: translateX(0);
        visibility: visible;
        box-shadow: 0 0 40px rgba(44, 34, 30, .35);
        transition:
            transform .25s ease,
            visibility 0s linear 0s,
            box-shadow .25s ease;
    }

    .sidebar-brand {
        position: relative;
    }

    .staff-sidebar-close {
        display: inline-flex;
        position: absolute;
        top: 10px;
        right: 10px;
        width: 40px;
        height: 40px;
        align-items: center;
        justify-content: center;
        border: 0;
        border-radius: 10px;
        background: transparent;
        color: #4A3525;
        font-size: 1.15rem;
        cursor: pointer;
    }

    .staff-sidebar-close:hover {
        background: #F0E6D6;
    }

    .sidebar-nav .nav-link { 
        min-height: 46px;
    }

    .staff-sidebar-backdrop {
        display: block;
        position: fixed;
        inset: 0;
        z-index: 1190;
        background: rgba(44, 34, 30, .5);
        opacity: 0;
        pointer-events: none;
        transition: opacity .25s ease;
    }

    body.staff-nav-open .staff-sidebar-backdrop {
        opacity: 1;
        pointer-events: auto;
    }

    body.staff-nav-open {
        overflow: hidden;
    }

    .staff-menu-toggle {
        display: inline-flex;
    }

    .staff-menu-toggle.staff-menu-toggle-floating {
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

@media (prefers-reduced-motion: reduce) {
    .sidebar-staff,
    .staff-sidebar-backdrop {
        transition: none !important;
    }
}


/* =========================================================
   LIVE STAFF NOTIFICATION TOAST
========================================================= */
.localitea-notification-toast-container {
    position: fixed;
    top: 18px;
    right: 18px;
    z-index: 3000;
    display: flex;
    flex-direction: column;
    gap: 10px;
    width: min(380px, calc(100vw - 36px));
    pointer-events: none;
}

.localitea-notification-toast {
    pointer-events: auto;
    border: 1px solid #6F4E37 !important;
    border-left: 4px solid #4A3525 !important;
    border-radius: 11px !important;
    box-shadow: 0 8px 24px rgba(44, 34, 30, .16) !important;
    overflow: hidden;
}

.localitea-notification-toast .toast-body {
    color: #4A3525;
    font-size: .82rem;
    line-height: 1.45;
}

</style>


<!-- =========================================================
     STAFF SIDEBAR
========================================================= -->

<div class="d-flex flex-column sidebar-staff" id="staffSidebar" aria-label="Staff navigation">


    <!-- =====================================================
         BRAND
    ====================================================== -->

    <div class="sidebar-brand">

        <button
            type="button"
            class="staff-sidebar-close"
            id="staffSidebarClose"
            aria-label="Close menu"
        >
            <i class="bi bi-x-lg"></i>
        </button>

        <div class="logo-circle">

            <i class="bi bi-cup-hot-fill"></i>

        </div>

        <span>LOCAL</span>

    </div>


    <!-- =====================================================
         NAVIGATION
    ====================================================== -->

    <ul class="nav flex-column sidebar-nav flex-grow-1">


        <!-- =================================================
             DASHBOARD
        ================================================== -->

        <li class="nav-item">

            <a
                href="index.php"
                class="nav-link <?= $currentPage === 'index.php' ? 'active' : '' ?>"
            >

                <i class="bi bi-grid-1x2-fill"></i>

                <span>
                    Order Queue
                </span>

            </a>

        </li>

        <!-- =================================================
             NOTIFICATIONS
        ================================================== -->

        <li class="nav-item">

            <a
                href="notifications.php"
                class="nav-link <?= $currentPage === 'notifications.php' ? 'active' : '' ?>"
            >

                <i class="bi bi-bell-fill"></i>

                <span>
                    Notifications
                </span>


                <?php if ($unreadNewOrders > 0): ?>

                    <span
                        class="notification-badge"
                        id="sidebarNotificationBadge"
                    >

                        <?= $unreadNewOrders > 99
                            ? '99+'
                            : $unreadNewOrders
                        ?>

                    </span>

                <?php endif; ?>

            </a>

        </li>

    </ul>

</div>

<div class="staff-sidebar-backdrop" id="staffSidebarBackdrop"></div>

<script>
(function () {
    var body = document.body;
    var sidebar = document.getElementById('staffSidebar');
    var backdrop = document.getElementById('staffSidebarBackdrop');
    var closeBtn = document.getElementById('staffSidebarClose');
    var lastToggle = null;

    if (!sidebar) {
        return;
    }

    function isOpen() {
        return body.classList.contains('staff-nav-open');
    }

    function setOpen(open) {
        body.classList.toggle('staff-nav-open', open);

        document.querySelectorAll('.staff-menu-toggle').forEach(function (btn) {
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });

        if (open && closeBtn) {
            closeBtn.focus({ preventScroll: true });
        } else if (!open && lastToggle) {
            lastToggle.focus({ preventScroll: true });
        }
    }

    function bindToggles() {
        var toggles = document.querySelectorAll('.staff-menu-toggle');

        if (!toggles.length) {
            var floating = document.createElement('button');
            floating.type = 'button';
            floating.className = 'staff-menu-toggle staff-menu-toggle-floating';
            floating.setAttribute('aria-label', 'Open menu');
            floating.setAttribute('aria-controls', 'staffSidebar');
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
            body.classList.remove('staff-nav-open');
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && isOpen()) {
            setOpen(false);
        }
    });

    window.addEventListener('resize', function () {
        if (window.innerWidth >= 992 && isOpen()) {
            body.classList.remove('staff-nav-open');
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
       LIVE STAFF NOTIFICATIONS
       Poll every 5 seconds.
    ===================================================== */

    const notificationEndpoint =
        'notifications.php?ajax=notifications';

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
            new_order: 'New Order',
            payment: 'Payment Update',
            order_update: 'Order Update',
            customer_cancelled_order: 'Order Cancelled',
            gcash_pending_verification: 'GCash Payment Verification'
        };

        return titles[type]
            || String(type || 'Notification')
                .replace(/[_-]/g, ' ')
                .replace(/\b\w/g, char => char.toUpperCase());
    }


    function getNotificationIcon(type) {

        const icons = {
            new_order: 'bi-cart-check',
            payment: 'bi-credit-card',
            order_update: 'bi-arrow-repeat',
            customer_cancelled_order: 'bi-x-circle',
            gcash_pending_verification: 'bi-credit-card'
        };

        return icons[type] || 'bi-bell';
    }


    function updateBadge(unreadCount) {

        if (!badge) {
            return;
        }

        const count = Number(unreadCount || 0);

        badge.hidden = count <= 0;
        badge.textContent =
            count > 99 ? '99+' : String(count);
    }


    function showImportantToast(notification) {

        if (
            !notification
            || !importantNotificationTypes.has(
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
            container = document.createElement('div');
            container.className =
                'localitea-notification-toast-container';
            container.setAttribute('aria-live', 'polite');
            container.setAttribute('aria-atomic', 'true');
            document.body.appendChild(container);
        }

        const wrapper = document.createElement('div');
        wrapper.className =
            'toast localitea-notification-toast';
        wrapper.setAttribute('role', 'status');

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

                <small>Now</small>

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

        container.appendChild(wrapper);

        if (window.bootstrap && bootstrap.Toast) {
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
            setTimeout(function () {
                wrapper.remove();
            }, 3500);
        }
    }


    async function fetchNotifications() {

        try {

            const response =
                await fetch(
                    notificationEndpoint +
                    '&_=' + Date.now(),
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

            if (!data || !data.success) {
                return;
            }

            const notifications =
                Array.isArray(data.notifications)
                    ? data.notifications
                    : [];

            updateBadge(
                data.unread_count
            );

            if (!initialized) {

                notifications.forEach(
                    function (notification) {
                        knownNotificationIds.add(
                            String(notification.id)
                        );
                    }
                );

                initialized = true;

            } else {

                const newNotifications =
                    notifications.filter(
                        function (notification) {
                            return !knownNotificationIds.has(
                                String(notification.id)
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
                            String(notification.id)
                        );
                    }
                );
            }

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
                'Live staff notifications error:',
                error
            );
        }
    }


    function startNotificationPolling() {

        fetchNotifications();

        setInterval(
            fetchNotifications,
            5000
        );
    }


    if (document.readyState === 'loading') {

        document.addEventListener(
            'DOMContentLoaded',
            startNotificationPolling
        );

    } else {

        startNotificationPolling();
    }

})();
</script>
