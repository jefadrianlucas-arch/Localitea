<?php
/*
 * =========================================================
 * LOCALITEA STAFF NAVBAR
 * =========================================================
 *
 * Shared top navigation for all Staff pages.
 *
 * Includes:
 * - Hamburger button (opens the Staff sidebar on tablet / phone)
 * - Logged-in Staff profile dropdown
 * - Log out confirmation
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../includes/db.php';

/* =========================================================
   STAFF ACCESS
========================================================= */

if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['user_role'] ?? '') !== 'staff'
) {
    header('Location: ../auth/login.php');
    exit;
}

/* =========================================================
   LOAD LOGGED-IN STAFF
========================================================= */

$navbarStaffId = (int)($_SESSION['user_id'] ?? 0);

$navbarStaff = null;

if ($navbarStaffId > 0) {

    $navbarStmt = $pdo->prepare("
        SELECT
            id,
            name,
            email,
            role
        FROM users
        WHERE id = ?
          AND role = 'staff'
        LIMIT 1
    ");

    $navbarStmt->execute([
        $navbarStaffId
    ]);

    $navbarStaff = $navbarStmt->fetch(PDO::FETCH_ASSOC);
}

/* =========================================================
   STAFF NAME + EMAIL
========================================================= */

$navbarStaffName = trim(
    (string)(
        $navbarStaff['name']
        ?? $_SESSION['user_name']
        ?? 'Staff User'
    )
);

if ($navbarStaffName === '') {
    $navbarStaffName = 'Staff User';
}

$navbarStaffEmail = trim((string)($navbarStaff['email'] ?? ''));

/* First letter for avatar */
$navbarStaffInitial = strtoupper(
    mb_substr(
        $navbarStaffName,
        0,
        1
    )
);
?>

<style>

/* =========================================================
   STAFF TOPBAR
   The sidebar is fixed at 260px on desktop, so the topbar
   starts exactly at 260px (it used to overlap the sidebar
   on some laptop widths).
========================================================= */

:root {
    --staff-sidebar-width: 260px;
}

.staff-navbar {
    position: sticky;
    top: 0;
    z-index: 1000;

    margin-left: var(--staff-sidebar-width);
    width: calc(100% - var(--staff-sidebar-width));

    min-width: 0;
    min-height: 70px;

    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;

    padding: 12px 24px;

    background: #FFFFFF;

    border-bottom: 2px solid #6F4E37;

    box-shadow: 0 3px 10px rgba(44, 34, 30, .08);

    box-sizing: border-box;
}

/* =========================================================
   LEFT SIDE: hamburger + panel title
========================================================= */

.staff-navbar-left {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
}

.staff-navbar-brand {
    display: inline-flex;
    align-items: center;
    gap: 8px;

    min-width: 0;

    color: #4A3525;
    font-size: .95rem;
    font-weight: 800;
    line-height: 1;
    white-space: nowrap;
}

.staff-navbar-brand i {
    width: 34px;
    height: 34px;
    flex: 0 0 34px;

    display: inline-flex;
    align-items: center;
    justify-content: center;

    border-radius: 10px;

    background: #F0E6D6;
    color: #6F4E37;

    font-size: .95rem;
}

/* The hamburger itself is styled by sidebar.php (.staff-menu-toggle);
   it is hidden on desktop and shown below 992px. */

/* =========================================================
   STAFF PROFILE
========================================================= */

.staff-navbar-profile {
    position: relative;
    flex: 0 0 auto;
    min-width: 0;
}

.staff-navbar-profile-btn {
    display: inline-flex;
    align-items: center;
    gap: 9px;

    min-height: 46px;
    max-width: 260px;

    border: 1px solid #B8A08A;
    border-radius: 12px;

    padding: 5px 10px 5px 6px;

    background: #FFFFFF;
    color: #2C221E;

    cursor: pointer;

    box-sizing: border-box;
    text-align: left;

    transition: background .2s ease, border-color .2s ease;
}

.staff-navbar-profile-btn:hover,
.staff-navbar-profile-btn[aria-expanded="true"] {
    background: #FDF8F2;
    border-color: #8F725A;
}

.staff-navbar-profile-btn:focus-visible,
.staff-navbar-dropdown a:focus-visible,
.staff-navbar-dropdown button:focus-visible,
.logout-cancel:focus-visible,
.logout-confirm:focus-visible {
    outline: 3px solid #C69C6D;
    outline-offset: 2px;
}

/* =========================================================
   AVATAR
========================================================= */

.staff-navbar-avatar {
    width: 36px;
    height: 36px;
    flex: 0 0 36px;

    border-radius: 50%;

    background: #4A3525;
    color: #FFFFFF;

    display: flex;
    align-items: center;
    justify-content: center;

    font-size: .9rem;
    font-weight: 800;

    overflow: hidden;
}

.staff-navbar-profile-text {
    display: flex;
    flex-direction: column;
    min-width: 0;
    line-height: 1.2;
}

.staff-navbar-profile-name {
    max-width: 150px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;

    font-size: .87rem;
    font-weight: 650;
}

.staff-navbar-profile-role {
    color: #7B6D62;
    font-size: .68rem;
    font-weight: 600;
}

.staff-navbar-arrow {
    font-size: .68rem;
    transition: transform .2s ease;
}

.staff-navbar-profile-btn[aria-expanded="true"] .staff-navbar-arrow {
    transform: rotate(180deg);
}

/* =========================================================
   DROPDOWN
========================================================= */

.staff-navbar-dropdown {
    position: absolute;
    top: calc(100% + 8px);
    right: 0;

    width: 230px;

    padding: 6px;

    background: #FFFFFF;

    border: 1px solid #E6DEC9;
    border-radius: 12px;

    box-shadow: 0 8px 24px rgba(44, 34, 30, .12);

    display: none;

    z-index: 1500;

    box-sizing: border-box;
}

.staff-navbar-dropdown.show {
    display: block;
}

.staff-navbar-dropdown-head {
    padding: 8px 12px 10px;
    margin-bottom: 4px;
    border-bottom: 1px solid #EFE6DA;
    min-width: 0;
}

.staff-navbar-dropdown-head strong {
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;

    color: #2C221E;
    font-size: .86rem;
    font-weight: 700;
}

.staff-navbar-dropdown-head small {
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;

    color: #7B6D62;
    font-size: .74rem;
}

.staff-navbar-dropdown a {
    display: flex;
    align-items: center;
    gap: 10px;

    padding: 10px 12px;

    border-radius: 8px;

    color: #4A3525;

    text-decoration: none;

    font-size: .84rem;
    font-weight: 600;
}

.staff-navbar-dropdown a:hover {
    background: #F3E8DB;
}

.staff-navbar-dropdown a i {
    width: 18px;
    text-align: center;
}

.staff-navbar-dropdown .navbar-logout-link {
    margin-top: 4px;
    border-top: 1px solid #EFE6DA;
    border-radius: 0 0 8px 8px;
    padding-top: 12px;
    color: #9E3030;
}

.staff-navbar-dropdown .navbar-logout-link:hover {
    background: #FCE3E3;
    color: #9E3030;
}

/* =========================================================
   LOGOUT CONFIRMATION MODAL
========================================================= */

.logout-modal {
    position: fixed;
    inset: 0;
    z-index: 99999;

    display: none;
    align-items: center;
    justify-content: center;

    padding: 20px;

    background: rgba(44, 34, 30, .45);
}

.logout-modal.show {
    display: flex;
}

.logout-modal-box {
    width: min(380px, 100%);

    padding: 25px;

    background: #FFFFFF;

    border: 1px solid #D8C6B5;
    border-radius: 16px;

    text-align: center;

    box-shadow: 0 16px 40px rgba(44, 34, 30, .18);

    box-sizing: border-box;
}

.logout-modal-icon {
    width: 50px;
    height: 50px;

    margin: 0 auto 12px;

    display: flex;
    align-items: center;
    justify-content: center;

    border-radius: 50%;

    background: #F8E1E1;
    color: #C23A3A;

    font-size: 1.15rem;
}

.logout-modal-box h5 {
    margin: 0 0 7px;

    color: #2C221E;
    font-size: 1.05rem;
    font-weight: 800;
}

.logout-modal-box p {
    margin: 0 0 20px;

    color: #7B6D62;
    font-size: .84rem;
}

.logout-modal-actions {
    display: flex;
    justify-content: center;
    gap: 10px;
}

.logout-cancel,
.logout-confirm {
    flex: 1 1 0;
    min-height: 42px;

    border-radius: 9px;

    padding: 9px 14px;

    font-size: .84rem;
    font-weight: 700;

    cursor: pointer;
    text-decoration: none;

    display: inline-flex;
    align-items: center;
    justify-content: center;

    box-sizing: border-box;
}

.logout-cancel {
    border: 1px solid #B8A08A;
    background: #FFFFFF;
    color: #4A3525;
}

.logout-cancel:hover {
    background: #F7F1EA;
}

.logout-confirm {
    border: 1px solid #C23A3A;
    background: #C23A3A;
    color: #FFFFFF;
}

.logout-confirm:hover {
    border-color: #A72F2F;
    background: #A72F2F;
    color: #FFFFFF;
}

/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 991.98px) {

    .staff-navbar {
        margin-left: 0;
        width: 100%;
        min-height: 64px;
        padding: 10px 14px;
    }

}

@media (max-width: 767.98px) {

    .staff-navbar {
        padding: 8px 12px;
    }

    /* Phone: avatar only, the name is inside the dropdown */
    .staff-navbar-profile-text {
        display: none;
    }

    .staff-navbar-profile-btn {
        min-height: 44px;
        padding-right: 9px;
    }

    .staff-navbar-dropdown {
        width: min(230px, calc(100vw - 24px));
    }

}

@media (max-width: 380px) {

    .staff-navbar-brand span {
        max-width: 90px;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .staff-navbar-avatar {
        width: 32px;
        height: 32px;
        flex-basis: 32px;
    }

}

@media (prefers-reduced-motion: reduce) {

    .staff-navbar *,
    .logout-modal * {
        transition: none !important;
    }

}

</style>

<!-- =========================================================
     STAFF NAVBAR
========================================================= -->

<nav class="staff-navbar no-print" aria-label="Staff top bar">

    <!-- LEFT: HAMBURGER + TITLE -->
    <div class="staff-navbar-left">

        <button
            type="button"
            class="staff-menu-toggle"
            aria-label="Open menu"
            aria-controls="staffSidebar"
            aria-expanded="false"
        >
            <i class="bi bi-list" aria-hidden="true"></i>
        </button>

        <div class="staff-navbar-brand">
            <i class="bi bi-person-badge" aria-hidden="true"></i>
            <span>Staff Panel</span>
        </div>

    </div>


    <!-- STAFF PROFILE -->
    <div class="staff-navbar-profile">

        <button
            type="button"
            class="staff-navbar-profile-btn"
            id="staffNavbarProfileBtn"
            aria-expanded="false"
            aria-haspopup="true"
            aria-controls="staffNavbarProfileDropdown"
            aria-label="Account menu for <?= htmlspecialchars($navbarStaffName, ENT_QUOTES, 'UTF-8') ?>"
        >

            <span class="staff-navbar-avatar" aria-hidden="true">
                <?= htmlspecialchars($navbarStaffInitial, ENT_QUOTES, 'UTF-8') ?>
            </span>

            <span class="staff-navbar-profile-text">
                <span class="staff-navbar-profile-name">
                    <?= htmlspecialchars($navbarStaffName, ENT_QUOTES, 'UTF-8') ?>
                </span>
                <span class="staff-navbar-profile-role">Staff</span>
            </span>

            <i class="bi bi-chevron-down staff-navbar-arrow" aria-hidden="true"></i>

        </button>

        <div
            class="staff-navbar-dropdown"
            id="staffNavbarProfileDropdown"
        >

            <div class="staff-navbar-dropdown-head">
                <strong><?= htmlspecialchars($navbarStaffName, ENT_QUOTES, 'UTF-8') ?></strong>
                <small>
                    <?= htmlspecialchars($navbarStaffEmail !== '' ? $navbarStaffEmail : 'Staff account', ENT_QUOTES, 'UTF-8') ?>
                </small>
            </div>

            <a href="profile.php">
                <i class="bi bi-person" aria-hidden="true"></i>
                <span>Profile</span>
            </a>

            <a href="../auth/logout.php" class="navbar-logout-link" id="logoutBtn">
                <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
                <span>Log Out</span>
            </a>

        </div>

    </div>

</nav>

<!-- =========================================================
     STAFF LOGOUT CONFIRMATION MODAL
========================================================= -->

<div
    class="logout-modal no-print"
    id="logoutModal"
    aria-hidden="true"
>

    <div
        class="logout-modal-box"
        role="dialog"
        aria-modal="true"
        aria-labelledby="logoutModalTitle"
    >

        <div class="logout-modal-icon">
            <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
        </div>

        <h5 id="logoutModalTitle">Confirm Logout</h5>

        <p>Are you sure you want to log out? You will need to sign in again to use the Staff panel.</p>

        <div class="logout-modal-actions">

            <button
                type="button"
                class="logout-cancel"
                id="cancelLogout"
            >
                Cancel
            </button>

            <a
                href="../auth/logout.php"
                class="logout-confirm"
            >
                Log Out
            </a>

        </div>

    </div>

</div>

<script>
/* =========================================================
   STAFF NAVBAR
   PROFILE DROPDOWN + LOGOUT CONFIRMATION
========================================================= */

(function () {

    const profileButton = document.getElementById('staffNavbarProfileBtn');
    const profileDropdown = document.getElementById('staffNavbarProfileDropdown');
    const logoutBtn = document.getElementById('logoutBtn');
    const logoutModal = document.getElementById('logoutModal');
    const cancelLogout = document.getElementById('cancelLogout');

    if (!profileButton || !profileDropdown) {
        return;
    }

    if (profileButton.dataset.navbarInitialized === '1') {
        return;
    }

    profileButton.dataset.navbarInitialized = '1';

    /* ---------- PROFILE DROPDOWN ---------- */

    function setDropdown(open) {
        profileButton.setAttribute('aria-expanded', open ? 'true' : 'false');
        profileDropdown.classList.toggle('show', open);
    }

    profileButton.addEventListener('click', function (event) {
        event.stopPropagation();
        setDropdown(profileButton.getAttribute('aria-expanded') !== 'true');
    });

    profileDropdown.addEventListener('click', function (event) {
        event.stopPropagation();
    });

    document.addEventListener('click', function (event) {
        if (!event.target.closest('.staff-navbar-profile')) {
            setDropdown(false);
        }
    });

    /* ---------- LOGOUT CONFIRMATION ---------- */

    if (!logoutBtn || !logoutModal || !cancelLogout) {
        return;
    }

    let returnFocusTo = null;

    function openLogoutModal() {
        returnFocusTo = document.activeElement;
        setDropdown(false);

        logoutModal.classList.add('show');
        logoutModal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';

        cancelLogout.focus({ preventScroll: true });
    }

    function closeLogoutModal() {
        logoutModal.classList.remove('show');
        logoutModal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';

        if (returnFocusTo && typeof returnFocusTo.focus === 'function') {
            returnFocusTo.focus({ preventScroll: true });
        }
    }

    /* The link keeps a real href, so it still logs out if JS fails. */
    logoutBtn.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();
        openLogoutModal();
    });

    cancelLogout.addEventListener('click', closeLogoutModal);

    logoutModal.addEventListener('click', function (event) {
        if (event.target === logoutModal) {
            closeLogoutModal();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }

        if (logoutModal.classList.contains('show')) {
            closeLogoutModal();
            return;
        }

        setDropdown(false);
    });

})();
</script>