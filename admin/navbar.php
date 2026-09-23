<?php
/* =========================================================
   LOCALITEA ADMIN NAVBAR
   Shared navbar for all files inside /admin

   - Does NOT include db.php
   - Does NOT include header.php
   - Parent Admin page loads required files
   - sidebar.php handles the sidebar
   - This file owns the single navbar hamburger
   - Search is a GLOBAL ADMIN SYSTEM SEARCH
========================================================= */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* =========================================================
   ADMIN ACCESS
========================================================= */

if (
    !isset($_SESSION['user_id']) ||
    !in_array(
        $_SESSION['user_role'] ?? '',
        ['admin', 'superadmin'],
        true
    )
) {
    header('Location: ../auth/login.php');
    exit;
}

/* =========================================================
   ADMIN PROFILE PICTURE
========================================================= */

$navbarProfilePicture = trim(
    (string)(
        $_SESSION['admin_profile_picture'] ?? ''
    )
);

if (
    $navbarProfilePicture === '' &&
    !empty($_SESSION['user_email']) &&
    isset($pdo) &&
    $pdo instanceof PDO
) {
    try {

        $navbarProfilePictureStmt =
            $pdo->prepare("
                SELECT profile_picture
                FROM admins
                WHERE LOWER(TRIM(email))
                    = LOWER(TRIM(?))
                LIMIT 1
            ");

        $navbarProfilePictureStmt->execute([
            (string) $_SESSION['user_email']
        ]);

        $navbarProfilePicture =
            trim(
                (string)
                $navbarProfilePictureStmt
                    ->fetchColumn()
            );

    } catch (Throwable $e) {
        $navbarProfilePicture = '';
    }
}

$navbarHasProfilePicture =
    $navbarProfilePicture !== '' &&
    $navbarProfilePicture !== 'default-admin.png';

/* =========================================================
   ADMIN NAME
========================================================= */

$navbarAdminName =
    trim(
        (string)(
            $_SESSION['user_name']
            ?? 'Admin User'
        )
    );

if ($navbarAdminName === '') {
    $navbarAdminName = 'Admin User';
}

$navbarAdminInitial =
    strtoupper(
        substr(
            $navbarAdminName,
            0,
            1
        )
    );
?>

<style>
:root {
    --admin-sidebar-width: 260px;
}

/* =========================================================
   SHARED ADMIN NAVBAR
========================================================= */

.localitea-admin-navbar {
    position: sticky;
    top: 0;
    z-index: 1100;

    margin-left: var(--admin-sidebar-width);
    width: calc(100% - var(--admin-sidebar-width));

    min-height: 70px;

    display: grid;

    grid-template-columns:
        auto
        minmax(0, 1fr)
        auto;

    align-items: center;

    gap: 18px;

    padding: 12px 24px;

    background: #FFFFFF;

    border-bottom: 2px solid #6F4E37;

    box-shadow:
        0 3px 10px rgba(44, 34, 30, .08);

    box-sizing: border-box;

    min-width: 0;
}

/* =========================================================
   LEFT SIDE
========================================================= */

.localitea-admin-navbar-left {
    display: inline-flex;
    align-items: center;

    gap: 10px;

    min-width: 0;

    flex: 0 0 auto;
}

.localitea-admin-navbar-title {
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

.localitea-admin-navbar-title i {
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

/* =========================================================
   HAMBURGER
   sidebar.php listens to .admin-menu-toggle
========================================================= */

.localitea-admin-menu-toggle {
    width: 40px;
    height: 40px;

    flex: 0 0 40px;

    display: none;

    align-items: center;
    justify-content: center;

    padding: 0;

    border: 1px solid #B8A08A;
    border-radius: 10px;

    background: #FFFFFF;

    color: #4A3525;

    cursor: pointer;

    font-size: 1.1rem;
    line-height: 1;

    box-sizing: border-box;

    -webkit-tap-highlight-color: transparent;
}

.localitea-admin-menu-toggle:hover {
    background: #FDF8F2;
    border-color: #8F725A;
}

.localitea-admin-menu-toggle:focus-visible {
    outline: 3px solid #C69C6D;
    outline-offset: 2px;
}

/* =========================================================
   GLOBAL SYSTEM SEARCH
========================================================= */

.localitea-admin-search-wrapper {
    position: relative;

    width: min(500px, 100%);

    min-width: 0;

    justify-self: center;

    margin: 0 auto;
}

.localitea-admin-navbar-search {
    width: 100%;

    position: relative;
}

.localitea-admin-navbar-search input {
    width: 100%;

    height: 40px;

    border: 1px solid #B8A08A;

    border-radius: 50px;

    padding:
        8px 16px 8px 42px;

    font-size: .84rem;

    color: #2C221E;

    background: #FDF8F2;

    outline: none;

    box-sizing: border-box;

    transition:
        border-color .2s ease,
        box-shadow .2s ease,
        background .2s ease;
}

.localitea-admin-navbar-search input::placeholder {
    color: #9A8B80;
}

.localitea-admin-navbar-search input:focus {
    border-color: #6F4E37;

    background: #FFFFFF;

    box-shadow:
        0 0 0 3px
        rgba(111, 78, 55, .08);
}

/* Search icon */

.localitea-admin-navbar-search::before {
    content: '';

    position: absolute;

    left: 15px;
    top: 50%;

    transform:
        translateY(-50%);

    width: 14px;
    height: 14px;

    border:
        2px solid #8B6A55;

    border-radius: 50%;

    pointer-events: none;

    z-index: 2;

    box-sizing: border-box;
}

.localitea-admin-navbar-search::after {
    content: '';

    position: absolute;

    left: 27px;

    top: calc(50% + 5px);

    width: 6px;
    height: 2px;

    background: #8B6A55;

    border-radius: 2px;

    transform: rotate(45deg);

    pointer-events: none;

    z-index: 2;
}

/* =========================================================
   SEARCH RESULTS
========================================================= */

.localitea-admin-search-results {
    position: absolute;

    top: calc(100% + 8px);

    left: 0;
    right: 0;

    width: 100%;

    max-height: 360px;

    overflow-y: auto;

    padding: 7px;

    background: #FFFFFF;

    border:
        1px solid #D8C6B5;

    border-radius: 13px;

    box-shadow:
        0 10px 28px
        rgba(44, 34, 30, .14);

    display: none;

    z-index: 2000;

    box-sizing: border-box;
}

.localitea-admin-search-results.show {
    display: block;
}

.localitea-admin-search-result {
    width: 100%;

    display: flex;

    align-items: center;

    gap: 11px;

    padding: 10px 11px;

    border: 0;

    border-radius: 9px;

    background: transparent;

    color: #4A3525;

    text-decoration: none;

    text-align: left;

    cursor: pointer;

    box-sizing: border-box;
}

.localitea-admin-search-result:hover,
.localitea-admin-search-result.active {
    background: #F3E8DB;
}

.localitea-admin-search-result-icon {
    width: 34px;
    height: 34px;

    flex: 0 0 34px;

    display: inline-flex;

    align-items: center;
    justify-content: center;

    border-radius: 9px;

    background: #F0E6D6;

    color: #6F4E37;

    font-size: .9rem;
}

.localitea-admin-search-result-content {
    min-width: 0;
    flex: 1;
}

.localitea-admin-search-result-title {
    display: block;

    color: #4A3525;

    font-size: .8rem;

    font-weight: 800;

    line-height: 1.2;
}

.localitea-admin-search-result-description {
    display: block;

    margin-top: 2px;

    color: #8B7D73;

    font-size: .66rem;

    line-height: 1.25;
}

.localitea-admin-search-no-results {
    padding: 15px 12px;

    color: #8B7D73;

    font-size: .75rem;

    text-align: center;
}

.localitea-admin-search-results::-webkit-scrollbar {
    width: 6px;
}

.localitea-admin-search-results::-webkit-scrollbar-track {
    background: transparent;
}

.localitea-admin-search-results::-webkit-scrollbar-thumb {
    background: #D8C6B5;
    border-radius: 10px;
}

/* =========================================================
   PROFILE
========================================================= */

.localitea-admin-navbar-profile {
    position: relative;

    flex: 0 0 auto;

    min-width: 0;
}

.localitea-admin-navbar-profile-btn {
    display: inline-flex;

    align-items: center;

    gap: 9px;

    min-height: 46px;

    max-width: 240px;

    border:
        1px solid #B8A08A;

    border-radius: 12px;

    padding:
        5px 10px 5px 6px;

    background: #FFFFFF;

    color: #2C221E;

    cursor: pointer;

    font-size: .87rem;
    font-weight: 650;

    box-sizing: border-box;

    white-space: nowrap;

    transition:
        background .2s ease,
        border-color .2s ease;
}

.localitea-admin-navbar-profile-btn:hover,
.localitea-admin-navbar-profile-btn[aria-expanded="true"] {
    background: #FDF8F2;
    border-color: #8F725A;
}

.localitea-admin-navbar-avatar {
    width: 36px;
    height: 36px;

    flex: 0 0 36px;

    display: inline-flex;

    align-items: center;
    justify-content: center;

    overflow: hidden;

    border-radius: 50%;

    background: #E6C9C9;

    color: #4A3525;

    font-size: .82rem;
    font-weight: 800;
}

.localitea-admin-navbar-avatar img {
    width: 100%;
    height: 100%;

    object-fit: cover;

    display: block;
}

.localitea-admin-navbar-profile-name {
    max-width: 150px;

    overflow: hidden;

    text-overflow: ellipsis;

    white-space: nowrap;
}

.localitea-admin-navbar-profile-arrow {
    font-size: .68rem;

    transition:
        transform .2s ease;
}

.localitea-admin-navbar-profile-btn[
    aria-expanded="true"
]
.localitea-admin-navbar-profile-arrow {
    transform: rotate(180deg);
}

/* =========================================================
   PROFILE DROPDOWN
========================================================= */

.localitea-admin-navbar-dropdown {
    position: absolute;

    top:
        calc(
            100% + 8px
        );

    right: 0;

    width: 190px;

    padding: 6px;

    background: #FFFFFF;

    border:
        1px solid #E6DEC9;

    border-radius: 12px;

    box-shadow:
        0 8px 24px
        rgba(44, 34, 30, .12);

    display: none;

    z-index: 1500;

    box-sizing: border-box;
}

.localitea-admin-navbar-dropdown.show {
    display: block;
}

.localitea-admin-navbar-dropdown a {
    display: flex;

    align-items: center;

    gap: 10px;

    padding:
        10px 12px;

    border-radius: 8px;

    color: #4A3525;

    text-decoration: none;

    font-size: .84rem;

    font-weight: 600;
}

.localitea-admin-navbar-dropdown a:hover {
    background: #F3E8DB;
}

.localitea-admin-navbar-dropdown a i {
    width: 18px;
    text-align: center;
}

.localitea-admin-navbar-dropdown
.navbar-logout-link:hover {
    background: #FCE3E3;
    color: #9E3030;
}

/* =========================================================
   LOGOUT MODAL
========================================================= */

.localitea-admin-logout-modal {
    position: fixed;

    inset: 0;

    z-index: 99999;

    display: none;

    align-items: center;
    justify-content: center;

    padding: 20px;

    background:
        rgba(44, 34, 30, .45);
}

.localitea-admin-logout-modal.show {
    display: flex;
}

.localitea-admin-logout-box {
    width:
        min(
            380px,
            100%
        );

    padding: 25px;

    background: #FFFFFF;

    border:
        1px solid #D8C6B5;

    border-radius: 16px;

    text-align: center;

    box-shadow:
        0 16px 40px
        rgba(44, 34, 30, .18);

    box-sizing: border-box;
}

.localitea-admin-logout-icon {
    width: 50px;
    height: 50px;

    margin:
        0 auto 12px;

    display: flex;

    align-items: center;
    justify-content: center;

    border-radius: 50%;

    background: #F8E1E1;

    color: #C23A3A;

    font-size: 1.15rem;
}

.localitea-admin-logout-box h5 {
    margin:
        0 0 7px;

    color: #2C221E;

    font-size: 1.05rem;
    font-weight: 800;
}

.localitea-admin-logout-box p {
    margin:
        0 0 20px;

    color: #7B6D62;

    font-size: .84rem;
}

.localitea-admin-logout-actions {
    display: flex;

    justify-content: center;

    gap: 10px;
}

.localitea-admin-logout-cancel,
.localitea-admin-logout-confirm {
    flex: 1 1 0;

    min-height: 42px;

    border-radius: 9px;

    padding:
        9px 14px;

    font-size: .84rem;

    font-weight: 700;

    cursor: pointer;

    text-decoration: none;

    display: inline-flex;

    align-items: center;
    justify-content: center;

    box-sizing: border-box;
}

.localitea-admin-logout-cancel {
    border:
        1px solid #B8A08A;

    background: #FFFFFF;

    color: #4A3525;
}

.localitea-admin-logout-cancel:hover {
    background: #F7F1EA;
}

.localitea-admin-logout-confirm {
    border:
        1px solid #C23A3A;

    background: #C23A3A;

    color: #FFFFFF;
}

.localitea-admin-logout-confirm:hover {
    border-color: #A72F2F;

    background: #A72F2F;

    color: #FFFFFF;
}

/* =========================================================
   TABLET
========================================================= */

@media (max-width: 991.98px) {

    .localitea-admin-navbar {
        margin-left: 0;

        width: 100%;

        grid-template-columns:
            auto
            minmax(0, 1fr)
            auto;

        min-height: 66px;

        padding:
            11px 18px;

        gap: 14px;
    }

    .localitea-admin-menu-toggle {
        display: inline-flex;
    }

    .localitea-admin-navbar-left {
        gap: 9px;
    }

    .localitea-admin-navbar-title {
        font-size: .9rem;
    }

    .localitea-admin-search-wrapper {
        width: min(430px, 100%);
    }

    .localitea-admin-navbar-profile-name {
        max-width: 110px;
    }
}

/* =========================================================
   MOBILE
========================================================= */

@media (max-width: 767.98px) {

    .localitea-admin-navbar {

        min-height: 62px;

        grid-template-columns:
            auto
            minmax(0, 1fr)
            auto;

        padding:
            10px 14px;

        gap: 9px;
    }

    .localitea-admin-navbar-left {
        gap: 7px;
    }

    .localitea-admin-menu-toggle {

        width: 38px;
        height: 38px;

        flex-basis: 38px;

        border-radius: 9px;

        font-size: 1rem;
    }

    .localitea-admin-navbar-title {

        gap: 6px;

        font-size: .82rem;
    }

    .localitea-admin-navbar-title i {

        width: 31px;
        height: 31px;

        flex-basis: 31px;

        border-radius: 9px;

        font-size: .84rem;
    }

    .localitea-admin-navbar-title span {

        display: inline;

        max-width: 90px;

        overflow: hidden;

        text-overflow: ellipsis;

        white-space: nowrap;
    }

    .localitea-admin-search-wrapper {

        width: 100%;
    }

    .localitea-admin-navbar-search input {

        height: 38px;

        padding:
            7px 10px 7px 37px;

        font-size: 16px;
    }

    .localitea-admin-navbar-search::before {

        left: 12px;

        width: 13px;
        height: 13px;
    }

    .localitea-admin-navbar-search::after {

        left: 23px;

        top:
            calc(50% + 5px);
    }

    .localitea-admin-navbar-profile-btn {

        width: 40px;
        height: 40px;

        min-height: 40px;

        padding: 4px;

        justify-content: center;

        border-radius: 10px;
    }

    .localitea-admin-navbar-profile-name,
    .localitea-admin-navbar-profile-arrow {

        display: none;
    }

    .localitea-admin-navbar-avatar {

        width: 32px;
        height: 32px;

        flex-basis: 32px;
    }

    .localitea-admin-search-results {

        max-height: 300px;
    }
}

/* =========================================================
   SMALL PHONES
========================================================= */

@media (max-width: 480px) {

    .localitea-admin-navbar {

        min-height: 58px;

        grid-template-columns:
            auto
            minmax(0, 1fr)
            auto;

        padding:
            9px 10px;

        gap: 7px;
    }

    .localitea-admin-navbar-left {
        gap: 5px;
    }

    .localitea-admin-menu-toggle {

        width: 35px;
        height: 35px;

        flex-basis: 35px;

        border-radius: 9px;

        font-size: .95rem;
    }

    .localitea-admin-navbar-title {

        font-size: .72rem;

        gap: 5px;
    }

    .localitea-admin-navbar-title i {

        width: 29px;
        height: 29px;

        flex-basis: 29px;

        font-size: .76rem;
    }

    .localitea-admin-navbar-title span {

        max-width: 65px;
    }

    .localitea-admin-navbar-search input {

        height: 35px;

        padding:
            6px 8px 6px 33px;

        font-size: 16px;
    }

    .localitea-admin-navbar-search::before {

        left: 10px;

        width: 12px;
        height: 12px;
    }

    .localitea-admin-navbar-search::after {

        left: 21px;

        top:
            calc(50% + 5px);

        width: 5px;
    }

    .localitea-admin-navbar-profile-btn {

        width: 35px;
        height: 35px;

        border-color: transparent;

        background: transparent;

        padding: 2px;
    }

    .localitea-admin-navbar-profile-btn:hover,
    .localitea-admin-navbar-profile-btn[
        aria-expanded="true"
    ] {

        border-color: #B8A08A;

        background: #FDF8F2;
    }

    .localitea-admin-navbar-avatar {

        width: 29px;
        height: 29px;

        flex-basis: 29px;
    }

    .localitea-admin-search-result {
        padding: 9px;
    }

    .localitea-admin-search-result-description {
        font-size: .62rem;
    }

    .localitea-admin-logout-box {
        padding: 21px;
    }
}

/* =========================================================
   VERY SMALL PHONES
========================================================= */

@media (max-width: 360px) {

    .localitea-admin-navbar {

        grid-template-columns:
            auto
            minmax(50px, 1fr)
            auto;

        gap: 5px;

        padding: 8px;
    }

    .localitea-admin-navbar-title span {

        max-width: 55px;

        font-size: .67rem;
    }

    .localitea-admin-navbar-search input {

        padding-left: 31px;
        padding-right: 6px;
    }

    .localitea-admin-navbar-profile-btn {

        width: 33px;
        height: 33px;
    }

    .localitea-admin-navbar-avatar {

        width: 27px;
        height: 27px;

        flex-basis: 27px;
    }
}

/* =========================================================
   320PX
========================================================= */

@media (max-width: 320px) {

    .localitea-admin-navbar {

        grid-template-columns:
            auto
            minmax(45px, 1fr)
            auto;

        padding: 7px;
    }

    .localitea-admin-navbar-title span {
        display: none;
    }

    .localitea-admin-navbar-search input {
        padding-left: 29px;
    }

    .localitea-admin-navbar-search::before {
        left: 8px;
    }

    .localitea-admin-navbar-search::after {
        left: 19px;
    }
}

/* =========================================================
   REDUCED MOTION
========================================================= */

@media (prefers-reduced-motion: reduce) {

    .localitea-admin-navbar *,
    .localitea-admin-logout-modal * {
        transition: none !important;
    }
}
</style>

<!-- =========================================================
     SHARED ADMIN NAVBAR
========================================================= -->

<nav
    class="localitea-admin-navbar no-print"
    aria-label="Admin navigation bar"
>

    <!-- =====================================================
         LEFT SIDE
    ====================================================== -->

    <div class="localitea-admin-navbar-left">

        <!-- SINGLE HAMBURGER -->
        <button
            type="button"
            class="admin-menu-toggle localitea-admin-menu-toggle"
            aria-label="Open admin menu"
            aria-controls="adminSidebar"
            aria-expanded="false"
        >
            <i
                class="bi bi-list"
                aria-hidden="true"
            ></i>
        </button>

        <!-- ADMIN PANEL -->
        <div class="localitea-admin-navbar-title">

            <i
                class="bi bi-person-badge"
                aria-hidden="true"
            ></i>

            <span>
                Admin Panel
            </span>

        </div>

    </div>

    <!-- =====================================================
         GLOBAL SYSTEM SEARCH
    ====================================================== -->

    <div
        class="localitea-admin-search-wrapper"
        id="localiteaAdminSearchWrapper"
    >

        <form
            class="localitea-admin-navbar-search"
            id="localiteaAdminSearchForm"
            role="search"
            autocomplete="off"
        >

            <input
                type="search"
                id="localiteaAdminSearchInput"
                placeholder="Search system..."
                aria-label="Search the Admin system"
                aria-autocomplete="list"
                aria-controls="localiteaAdminSearchResults"
                aria-expanded="false"
                spellcheck="false"
            >

        </form>

        <div
            class="localitea-admin-search-results"
            id="localiteaAdminSearchResults"
            role="listbox"
            aria-label="Admin system search results"
        ></div>

    </div>

    <!-- =====================================================
         PROFILE
    ====================================================== -->

    <div class="localitea-admin-navbar-profile">

        <button
            type="button"
            class="localitea-admin-navbar-profile-btn"
            id="localiteaAdminNavbarProfileBtn"
            aria-haspopup="true"
            aria-expanded="false"
        >

            <span
                class="localitea-admin-navbar-avatar"
            >

                <?php if ($navbarHasProfilePicture): ?>

                    <img
                        src="../assets/uploads/admins/<?= htmlspecialchars(
                            $navbarProfilePicture,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                        alt="Admin profile"
                    >

                <?php else: ?>

                    <?= htmlspecialchars(
                        $navbarAdminInitial,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>

                <?php endif; ?>

            </span>

            <span
                class="localitea-admin-navbar-profile-name"
            >
                <?= htmlspecialchars(
                    $navbarAdminName,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </span>

            <i
                class="bi bi-chevron-down localitea-admin-navbar-profile-arrow"
                aria-hidden="true"
            ></i>

        </button>

        <div
            class="localitea-admin-navbar-dropdown"
            id="localiteaAdminNavbarDropdown"
        >

            <a href="profile.php">

                <i
                    class="bi bi-person"
                    aria-hidden="true"
                ></i>

                <span>
                    Profile
                </span>

            </a>

            <a
                href="#"
                class="navbar-logout-link"
                id="localiteaAdminNavbarLogoutBtn"
            >

                <i
                    class="bi bi-box-arrow-right"
                    aria-hidden="true"
                ></i>

                <span>
                    Log Out
                </span>

            </a>

        </div>

    </div>

</nav>

<!-- =========================================================
     LOGOUT CONFIRMATION MODAL
========================================================= -->

<div
    class="localitea-admin-logout-modal"
    id="localiteaAdminLogoutModal"
    aria-hidden="true"
>

    <div
        class="localitea-admin-logout-box"
        role="dialog"
        aria-modal="true"
        aria-labelledby="localiteaAdminLogoutTitle"
    >

        <div class="localitea-admin-logout-icon">

            <i
                class="bi bi-box-arrow-right"
                aria-hidden="true"
            ></i>

        </div>

        <h5 id="localiteaAdminLogoutTitle">
            Confirm Logout
        </h5>

        <p>
            Are you sure you want to logout?
        </p>

        <div class="localitea-admin-logout-actions">

            <button
                type="button"
                class="localitea-admin-logout-cancel"
                id="localiteaAdminLogoutCancel"
            >
                Cancel
            </button>

            <a
                href="../auth/logout.php"
                class="localitea-admin-logout-confirm"
            >
                Log Out
            </a>

        </div>

    </div>

</div>

<script>
(function () {

    /* =====================================================
       GLOBAL ADMIN SYSTEM SEARCH
    ===================================================== */

    const searchWrapper =
        document.getElementById(
            'localiteaAdminSearchWrapper'
        );

    const searchForm =
        document.getElementById(
            'localiteaAdminSearchForm'
        );

    const searchInput =
        document.getElementById(
            'localiteaAdminSearchInput'
        );

    const searchResults =
        document.getElementById(
            'localiteaAdminSearchResults'
        );

    /*
       Admin system destinations.

       Promotions is included as its own searchable
       destination even though it is currently located
       inside settings.php.
    */
    const systemPages = [

        {
            title: 'Dashboard',
            description: 'Admin dashboard and business overview',
            keywords: [
                'dashboard',
                'home',
                'overview',
                'main'
            ],
            url: 'dashboard.php',
            icon: 'bi-grid-1x2-fill'
        },

        {
            title: 'Order Queue',
            description: 'View and manage customer orders',
            keywords: [
                'order',
                'orders',
                'queue',
                'order queue',
                'customer order',
                'pending',
                'processing',
                'preparing',
                'ready',
                'completed',
                'cancelled'
            ],
            url: 'orders.php',
            icon: 'bi-bag-check-fill'
        },

        {
            title: 'Sales Reports',
            description: 'View sales totals and sales reports',
            keywords: [
                'sales',
                'sales report',
                'sales reports',
                'report',
                'reports',
                'revenue',
                'income',
                'transactions'
            ],
            url: 'sales-reports.php',
            icon: 'bi-file-earmark-bar-graph-fill'
        },

        {
            title: 'Notifications',
            description: 'View admin notifications and updates',
            keywords: [
                'notification',
                'notifications',
                'alerts',
                'updates'
            ],
            url: 'notifications.php',
            icon: 'bi-bell-fill'
        },

        {
            title: 'Product Management',
            description: 'Manage products, categories, sizes, and add-ons',
            keywords: [
                'product',
                'products',
                'product management',
                'manage products',
                'menu',
                'categories',
                'category',
                'add-on',
                'add-ons',
                'addons',
                'availability',
                'sizes'
            ],
            url: 'settings.php?tab=products',
            icon: 'bi-box-seam-fill'
        },

        {
            title: 'Promotions',
            description: 'Manage promotions and promotional offers',
            keywords: [
                'promotion',
                'promotions',
                'promo',
                'promos',
                'discount',
                'discounts',
                'buy one take one',
                'bogo',
                'bundle',
                'bundles'
            ],
            url: 'settings.php?tab=promotions',
            icon: 'bi-megaphone-fill'
        },

        {
            title: 'User Account Settings',
            description: 'Manage customer, staff, and administrator accounts',
            keywords: [
                'user',
                'users',
                'user account',
                'user accounts',
                'user account settings',
                'account settings',
                'accounts',
                'customer account',
                'customer accounts',
                'staff account',
                'staff accounts',
                'admin account',
                'admin accounts',
                'administrator account',
                'administrator accounts'
            ],
            url: 'settings.php?tab=user_accounts',
            icon: 'bi-people-fill'
        },

        {
            title: 'Settings',
            description: 'Open system settings',
            keywords: [
                'settings',
                'system settings',
                'configuration',
                'preferences'
            ],
            url: 'settings.php',
            icon: 'bi-gear-fill'
        },

        {
            title: 'Profile',
            description: 'View and manage your admin profile',
            keywords: [
                'profile',
                'admin profile',
                'my profile',
                'personal information',
                'account profile'
            ],
            url: 'profile.php',
            icon: 'bi-person-fill'
        }

    ];

    let activeResultIndex = -1;

    function normalize(value) {

        return String(
            value || ''
        )
            .toLowerCase()
            .trim();

    }

    function getMatches(query) {

        const normalizedQuery =
            normalize(query);

        if (!normalizedQuery) {
            return [];
        }

        /*
           Allow searches with multiple words.

           Example:
           "product management"

           becomes:
           ["product", "management"]
        */
        const queryWords =
            normalizedQuery
                .split(/\s+/)
                .filter(Boolean);

        return systemPages
            .map(function (page) {

                const searchableText =
                    [
                        page.title,
                        page.description,
                        ...page.keywords
                    ]
                        .join(' ')
                        .toLowerCase();

                /*
                   Every word typed by the admin must be
                   found somewhere in the searchable text.
                */
                const matchesAllWords =
                    queryWords.every(
                        function (word) {

                            return searchableText.includes(
                                word
                            );

                        }
                    );

                /*
                   Exact title gets priority.
                */
                const exactTitleMatch =
                    normalize(page.title) ===
                    normalizedQuery;

                /*
                   Titles starting with the search
                   also get priority.
                */
                const titleStartsWithQuery =
                    normalize(page.title)
                        .startsWith(
                            normalizedQuery
                        );

                return {
                    page: page,
                    matchesAllWords:
                        matchesAllWords,
                    exactTitleMatch:
                        exactTitleMatch,
                    titleStartsWithQuery:
                        titleStartsWithQuery
                };

            })
            .filter(function (result) {

                return result.matchesAllWords;

            })
            .sort(function (a, b) {

                if (
                    a.exactTitleMatch !==
                    b.exactTitleMatch
                ) {

                    return a.exactTitleMatch
                        ? -1
                        : 1;
                }

                if (
                    a.titleStartsWithQuery !==
                    b.titleStartsWithQuery
                ) {

                    return a.titleStartsWithQuery
                        ? -1
                        : 1;
                }

                return 0;

            })
            .map(function (result) {

                return result.page;

            });

    }

    function closeSearchResults() {

        if (searchResults) {

            searchResults.classList.remove(
                'show'
            );

        }

        if (searchInput) {

            searchInput.setAttribute(
                'aria-expanded',
                'false'
            );

        }

        activeResultIndex = -1;

    }

    function openSearchResults() {

        if (searchResults) {

            searchResults.classList.add(
                'show'
            );

        }

        if (searchInput) {

            searchInput.setAttribute(
                'aria-expanded',
                'true'
            );

        }

    }

    function navigateToPage(page) {

        if (!page || !page.url) {
            return;
        }

        window.location.href =
            page.url;

    }

    function renderSearchResults(
        query
    ) {

        if (
            !searchResults
        ) {
            return;
        }

        const matches =
            getMatches(query);

        searchResults.innerHTML = '';

        activeResultIndex = -1;

        if (
            !query.trim()
        ) {

            closeSearchResults();

            return;
        }

        openSearchResults();

        if (!matches.length) {

            const empty =
                document.createElement(
                    'div'
                );

            empty.className =
                'localitea-admin-search-no-results';

            empty.textContent =
                'No matching section found.';

            searchResults.appendChild(
                empty
            );

            return;
        }

        matches.forEach(
            function (
                page,
                index
            ) {

                const link =
                    document.createElement(
                        'a'
                    );

                link.href =
                    page.url;

                link.className =
                    'localitea-admin-search-result';

                link.setAttribute(
                    'role',
                    'option'
                );

                link.dataset.index =
                    String(index);

                link.innerHTML = `

                    <span
                        class="localitea-admin-search-result-icon"
                    >
                        <i class="bi ${page.icon}"></i>
                    </span>

                    <span
                        class="localitea-admin-search-result-content"
                    >

                        <span
                            class="localitea-admin-search-result-title"
                        >
                            ${escapeHtml(
                                page.title
                            )}
                        </span>

                        <span
                            class="localitea-admin-search-result-description"
                        >
                            ${escapeHtml(
                                page.description
                            )}
                        </span>

                    </span>

                `;

                link.addEventListener(
                    'click',
                    function () {

                        closeSearchResults();

                    }
                );

                searchResults.appendChild(
                    link
                );

            }
        );

    }

    function setActiveResult(
        index
    ) {

        if (
            !searchResults
        ) {
            return;
        }

        const resultLinks =
            searchResults.querySelectorAll(
                '.localitea-admin-search-result'
            );

        if (!resultLinks.length) {
            return;
        }

        if (
            index < 0
        ) {
            index =
                resultLinks.length - 1;
        }

        if (
            index >= resultLinks.length
        ) {
            index = 0;
        }

        resultLinks.forEach(
            function (
                link,
                linkIndex
            ) {

                link.classList.toggle(
                    'active',
                    linkIndex === index
                );

            }
        );

        activeResultIndex =
            index;

        const activeLink =
            resultLinks[index];

        if (activeLink) {

            activeLink.scrollIntoView({
                block: 'nearest'
            });

        }

    }

    function getActiveMatch(
        query,
        index
    ) {

        const matches =
            getMatches(query);

        return (
            matches[index] ||
            matches[0] ||
            null
        );

    }

    function escapeHtml(value) {

        return String(
            value ?? ''
        )
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

    if (
        searchInput &&
        searchForm
    ) {

        searchInput.addEventListener(
            'input',
            function () {

                renderSearchResults(
                    searchInput.value
                );

            }
        );

        searchInput.addEventListener(
            'focus',
            function () {

                if (
                    searchInput.value.trim()
                ) {

                    renderSearchResults(
                        searchInput.value
                    );

                }

            }
        );

        searchInput.addEventListener(
            'keydown',
            function (event) {

                const resultLinks =
                    searchResults
                        ? searchResults.querySelectorAll(
                            '.localitea-admin-search-result'
                        )
                        : [];

                if (
                    event.key === 'ArrowDown'
                ) {

                    if (
                        !resultLinks.length
                    ) {
                        return;
                    }

                    event.preventDefault();

                    setActiveResult(
                        activeResultIndex + 1
                    );

                    return;
                }

                if (
                    event.key === 'ArrowUp'
                ) {

                    if (
                        !resultLinks.length
                    ) {
                        return;
                    }

                    event.preventDefault();

                    setActiveResult(
                        activeResultIndex - 1
                    );

                    return;
                }

                if (
                    event.key === 'Enter'
                ) {

                    event.preventDefault();

                    const selected =
                        getActiveMatch(
                            searchInput.value,
                            activeResultIndex
                        );

                    if (selected) {

                        navigateToPage(
                            selected
                        );

                    }

                    return;
                }

                if (
                    event.key === 'Escape'
                ) {

                    closeSearchResults();

                }

            }
        );

        searchForm.addEventListener(
            'submit',
            function (event) {

                event.preventDefault();

                const selected =
                    getActiveMatch(
                        searchInput.value,
                        activeResultIndex
                    );

                if (selected) {

                    navigateToPage(
                        selected
                    );

                }

            }
        );

    }

    document.addEventListener(
        'click',
        function (event) {

            if (
                searchWrapper &&
                !searchWrapper.contains(
                    event.target
                )
            ) {

                closeSearchResults();

            }

        }
    );

    /* =====================================================
       PROFILE DROPDOWN
    ===================================================== */

    const profileButton =
        document.getElementById(
            'localiteaAdminNavbarProfileBtn'
        );

    const profileDropdown =
        document.getElementById(
            'localiteaAdminNavbarDropdown'
        );

    const logoutButton =
        document.getElementById(
            'localiteaAdminNavbarLogoutBtn'
        );

    const logoutModal =
        document.getElementById(
            'localiteaAdminLogoutModal'
        );

    const logoutCancel =
        document.getElementById(
            'localiteaAdminLogoutCancel'
        );

    function closeProfileDropdown() {

        if (
            profileDropdown
        ) {

            profileDropdown.classList.remove(
                'show'
            );

        }

        if (
            profileButton
        ) {

            profileButton.setAttribute(
                'aria-expanded',
                'false'
            );

        }

    }

    if (
        profileButton &&
        profileDropdown
    ) {

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
                    isOpen
                        ? 'false'
                        : 'true'
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

    }

    document.addEventListener(
        'click',
        function (event) {

            if (
                !event.target.closest(
                    '.localitea-admin-navbar-profile'
                )
            ) {

                closeProfileDropdown();

            }

        }
    );

    /* =====================================================
       LOGOUT MODAL
    ===================================================== */

    function openLogoutModal() {

        closeProfileDropdown();

        closeSearchResults();

        if (
            !logoutModal
        ) {
            return;
        }

        logoutModal.classList.add(
            'show'
        );

        logoutModal.setAttribute(
            'aria-hidden',
            'false'
        );

        document.body.style.overflow =
            'hidden';

    }

    function closeLogoutModal() {

        if (
            !logoutModal
        ) {
            return;
        }

        logoutModal.classList.remove(
            'show'
        );

        logoutModal.setAttribute(
            'aria-hidden',
            'true'
        );

        document.body.style.overflow =
            '';

    }

    if (
        logoutButton
    ) {

        logoutButton.addEventListener(
            'click',
            function (event) {

                event.preventDefault();

                event.stopPropagation();

                openLogoutModal();

            }
        );

    }

    if (
        logoutCancel
    ) {

        logoutCancel.addEventListener(
            'click',
            closeLogoutModal
        );

    }

    if (
        logoutModal
    ) {

        logoutModal.addEventListener(
            'click',
            function (event) {

                if (
                    event.target ===
                    logoutModal
                ) {

                    closeLogoutModal();

                }

            }
        );

    }

    document.addEventListener(
        'keydown',
        function (event) {

            if (
                event.key ===
                'Escape'
            ) {

                if (
                    logoutModal &&
                    logoutModal.classList.contains(
                        'show'
                    )
                ) {

                    closeLogoutModal();

                    return;
                }

                closeProfileDropdown();

                closeSearchResults();

            }

        }
    );

})();
</script>