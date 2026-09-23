<?php
/*
 * =========================================================
 * LOCALITEA STAFF NAVBAR
 * =========================================================
 *
 * Shared top navigation for all Staff pages.
 *
 * Includes:
 * - Logged-in Staff profile
 * - Profile dropdown
 * - Logout
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
   STAFF NAME
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

/* First letter for avatar */
$navbarStaffInitial = strtoupper(
    substr(
        $navbarStaffName,
        0,
        1
    )
);
?>

<style>

/* =========================================================
   STAFF TOPBAR
========================================================= */

.staff-navbar {
    position: sticky;
    top: 0;
    z-index: 1000;

    margin-left: clamp(220px, 18vw, 260px);

    width: calc(100% - clamp(220px, 18vw, 260px));

    min-width: 0;

    display: flex;
    justify-content: space-between;
    align-items: center;

    padding: 16px 24px;

    background: #ffffff;

    border-bottom: 2px solid #6F4E37;

    box-shadow:
        0 3px 10px rgba(44, 34, 30, .08);

    box-sizing: border-box;
}

/* =========================================================
   STAFF PROFILE
========================================================= */

.staff-navbar-profile {
    position: relative;
}

.staff-navbar-profile-btn {
    display: flex;
    align-items: center;

    gap: 10px;

    border: 1px solid #8B6A55;

    background: #ffffff;

    padding: 6px 10px 6px 6px;

    border-radius: 12px;

    cursor: pointer;

    color: #2c221e;

    font-size: .92rem;

    font-weight: 600;

    min-height: 46px;
}

.staff-navbar-profile-btn:hover,
.staff-navbar-profile-btn[aria-expanded="true"] {
    background: #FDF8F2;

    border-color: #6F4E37;
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

    display: flex;
    align-items: center;
    justify-content: center;

    color: #ffffff;

    font-size: .9rem;
    font-weight: 800;

    overflow: hidden;
}

.staff-navbar-arrow {
    font-size: 11px;
}

/* =========================================================
   DROPDOWN
========================================================= */

.staff-navbar-dropdown {
    position: absolute;

    top: calc(100% + 8px);
    right: 0;

    width: 200px;

    background: #ffffff;

    border: 2px solid #6F4E37;

    border-radius: 12px;

    box-shadow:
        0 10px 24px rgba(44, 34, 30, .16);

    padding: 6px;

    display: none;

    z-index: 1200;

    overflow: hidden;
}

.staff-navbar-dropdown.show {
    display: block;
}

.staff-navbar-dropdown a {
    display: flex;
    align-items: center;

    gap: 10px;

    padding: 10px 12px;

    border-radius: 8px;

    color: #4A3525;

    text-decoration: none;

    font-size: .85rem;
}

.staff-navbar-dropdown a:hover {
    background: #F0E6D6;
}

/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 991.98px) {

    .staff-navbar {
        margin-left: 0;

        width: 100%;

        padding: 12px 15px;
    }

}

@media (max-width: 768px) {

    .staff-navbar {
        padding: 10px 12px;
    }

    .staff-navbar-profile-btn span {
        display: none;
    }

}

@media (max-width: 480px) {

    .staff-navbar {
        padding: 8px 10px;
    }

    .staff-navbar-profile-btn {
        padding-right: 7px;
    }

    .staff-navbar-avatar {
        width: 34px;
        height: 34px;

        flex-basis: 34px;
    }

}

/* =========================================================
   STAFF LOGOUT CONFIRMATION MODAL
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

<!-- =========================================================
     STAFF NAVBAR
========================================================= -->

<div class="staff-navbar">

    <!-- STAFF PANEL -->
    <div class="staff-navbar-brand">
        <i class="bi bi-person-badge"></i>
        <span>Staff Panel</span>
    </div>


    <!-- STAFF PROFILE -->
    <div class="staff-navbar-profile">

        <button
            type="button"
            class="staff-navbar-profile-btn"
            id="staffNavbarProfileBtn"
            aria-expanded="false"
            aria-haspopup="true"
        >

            <div class="staff-navbar-avatar">
                <?= htmlspecialchars($navbarStaffInitial) ?>
            </div>

            <span>
                <?= htmlspecialchars($navbarStaffName) ?>
            </span>

            <i class="bi bi-chevron-down staff-navbar-arrow"></i>

        </button>

        <div
            class="staff-navbar-dropdown"
            id="staffNavbarProfileDropdown"
        >

            <a href="profile.php">
                <i class="bi bi-person"></i>
                Profile
            </a>

            <a href="#" id="logoutBtn">
            <i class="bi bi-box-arrow-right"></i>
            Log Out
           </a>

        </div>

    </div>

</div>

<!-- =========================================================
     STAFF LOGOUT CONFIRMATION MODAL
========================================================= -->

<div class="logout-modal" id="logoutModal">

    <div class="logout-modal-box">

        <div class="logout-modal-icon">
            <i class="bi bi-box-arrow-right"></i>
        </div>

        <h5>Confirm Logout</h5>

        <p>Are you sure you want to logout?</p>

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

    /* =====================================================
       PROFILE DROPDOWN
    ===================================================== */

    const profileButton =
        document.getElementById(
            'staffNavbarProfileBtn'
        );

    const profileDropdown =
        document.getElementById(
            'staffNavbarProfileDropdown'
        );


    if (
        profileButton &&
        profileDropdown &&
        profileButton.dataset.navbarInitialized !== '1'
    ) {

        profileButton.dataset.navbarInitialized = '1';


        profileButton.addEventListener(
            'click',
            function (event) {

                event.stopPropagation();

                const isOpen =
                    profileButton.getAttribute(
                        'aria-expanded'
                    ) === 'true';


                profileButton.setAttribute(
                    'aria-expanded',
                    isOpen ? 'false' : 'true'
                );


                profileDropdown.classList.toggle(
                    'show',
                    !isOpen
                );

            }
        );


        profileDropdown.addEventListener(
            'click',
            function (event) {

                event.stopPropagation();

            }
        );


        document.addEventListener(
            'click',
            function (event) {

                if (
                    !event.target.closest(
                        '.staff-navbar-profile'
                    )
                ) {

                    profileButton.setAttribute(
                        'aria-expanded',
                        'false'
                    );

                    profileDropdown.classList.remove(
                        'show'
                    );

                }

            }
        );

    }


    /* =====================================================
       LOGOUT CONFIRMATION
    ===================================================== */

    const logoutBtn =
        document.getElementById(
            'logoutBtn'
        );

    const logoutModal =
        document.getElementById(
            'logoutModal'
        );

    const cancelLogout =
        document.getElementById(
            'cancelLogout'
        );


    if (
        !logoutBtn ||
        !logoutModal ||
        !cancelLogout
    ) {
        return;
    }


    /* -----------------------------------------------------
       OPEN LOGOUT MODAL
    ----------------------------------------------------- */

    logoutBtn.addEventListener(
        'click',
        function (event) {

            event.preventDefault();
            event.stopPropagation();


            /* Close Staff profile dropdown */

            if (profileButton) {

                profileButton.setAttribute(
                    'aria-expanded',
                    'false'
                );

            }


            if (profileDropdown) {

                profileDropdown.classList.remove(
                    'show'
                );

            }


            /* Open logout confirmation */

            logoutModal.classList.add(
                'show'
            );

        }
    );


    /* -----------------------------------------------------
       CANCEL LOGOUT
    ----------------------------------------------------- */

    cancelLogout.addEventListener(
        'click',
        function () {

            logoutModal.classList.remove(
                'show'
            );

        }
    );


    /* -----------------------------------------------------
       CLICK OUTSIDE MODAL
    ----------------------------------------------------- */

    logoutModal.addEventListener(
        'click',
        function (event) {

            if (
                event.target === logoutModal
            ) {

                logoutModal.classList.remove(
                    'show'
                );

            }

        }
    );


    /* -----------------------------------------------------
       ESCAPE KEY
    ----------------------------------------------------- */

    document.addEventListener(
        'keydown',
        function (event) {

            if (
                event.key === 'Escape' &&
                logoutModal.classList.contains(
                    'show'
                )
            ) {

                logoutModal.classList.remove(
                    'show'
                );

            }

        }
    );

})();
</script>