<?php
/*
 * =========================================================
 * LOCALITEA ADMIN PROFILE
 * =========================================================
 *
 * This page is for the currently logged-in admin only.
 * It does NOT manage customers or other users.
 *
 * Features:
 * - View admin profile
 * - Edit personal information
 * - Change password
 * - Upload profile picture
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../includes/db.php';

/* =========================================================
   ACCESS CONTROL
========================================================= */

if (
    !isset($_SESSION['user_role']) ||
    !in_array($_SESSION['user_role'], ['admin'], true)
) {
    header("Location: ../auth/login.php");
    exit;
}

/* =========================================================
   RESOLVE LOGGED-IN ADMIN
========================================================= */

$adminId = (int)($_SESSION['admin_id'] ?? 0);

/*
 * Resolve the administrator from the admins table using the logged-in
 * email first. This avoids treating users.id as admins.id when the
 * login/session was created from the users table.
 */
if ($adminId <= 0 && !empty($_SESSION['user_email'])) {
    $lookup = $pdo->prepare("
        SELECT id
        FROM admins
        WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))
        LIMIT 1
    ");
    $lookup->execute([
        (string)$_SESSION['user_email']
    ]);

    $adminId = (int)$lookup->fetchColumn();
}

/*
 * Fallback for login versions that store the admin ID directly.
 */
if ($adminId <= 0 && !empty($_SESSION['user_id'])) {
    $candidateId = (int)$_SESSION['user_id'];

    $lookup = $pdo->prepare("
        SELECT id
        FROM admins
        WHERE id = ?
        LIMIT 1
    ");
    $lookup->execute([$candidateId]);

    $adminId = (int)$lookup->fetchColumn();
}

/*
 * Final fallback: resolve by the logged-in admin's name.
 */
if ($adminId <= 0 && !empty($_SESSION['user_name'])) {
    $lookup = $pdo->prepare("
        SELECT id
        FROM admins
        WHERE full_name = ?
        LIMIT 1
    ");
    $lookup->execute([
        (string)$_SESSION['user_name']
    ]);

    $adminId = (int)$lookup->fetchColumn();
}

if ($adminId <= 0 && !empty($_SESSION['user_name'])) {
    $lookup = $pdo->prepare("
        SELECT id
        FROM admins
        WHERE full_name = ?
        LIMIT 1
    ");
    $lookup->execute([
        (string)$_SESSION['user_name']
    ]);

    $adminId = (int)$lookup->fetchColumn();
}

if ($adminId <= 0) {
    header("Location: ../auth/login.php");
    exit;
}

/* =========================================================
   LOAD ADMIN
========================================================= */

$adminStmt = $pdo->prepare("
    SELECT
        id,
        full_name,
        email,
        password,
        profile_picture,
        role,
        created_at
    FROM admins
    WHERE id = ?
    LIMIT 1
");

$adminStmt->execute([
    $adminId
]);

$admin = $adminStmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    session_unset();
    session_destroy();

    header("Location: ../auth/login.php");
    exit;
}

$errors = [];
$successMessage = null;

/* =========================================================
   UPDATE PROFILE
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['update_profile'])
) {
    $fullName = trim((string)($_POST['full_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));

    if ($fullName === '') {
        $errors[] = 'Full name is required.';
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    /* Check duplicate email only when the basic fields are valid. */
    if (!$errors) {
        $duplicateStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM admins
            WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))
              AND id != ?
        ");

        $duplicateStmt->execute([
            $email,
            $adminId
        ]);

        if ((int)$duplicateStmt->fetchColumn() > 0) {
            $errors[] = 'Another admin account already uses that email address.';
        }
    }

    $newProfilePicture = $admin['profile_picture'] ?: 'default-admin.png';
    $uploadedNewPicture = null;

    /* =====================================================
       PROFILE PICTURE
    ===================================================== */

    if (
        isset($_FILES['profile_picture']) &&
        $_FILES['profile_picture']['error'] !== UPLOAD_ERR_NO_FILE
    ) {
        if ($_FILES['profile_picture']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'There was an error uploading the profile picture.';
        } else {
            $allowedExtensions = [
                'jpg',
                'jpeg',
                'png',
                'webp'
            ];

            $extension = strtolower(
                pathinfo(
                    $_FILES['profile_picture']['name'],
                    PATHINFO_EXTENSION
                )
            );

            if (!in_array($extension, $allowedExtensions, true)) {
                $errors[] = 'Only JPG, JPEG, PNG, and WEBP profile pictures are allowed.';
            } elseif ((int)$_FILES['profile_picture']['size'] > 5 * 1024 * 1024) {
                $errors[] = 'Profile picture must not exceed 5 MB.';
            } else {
                $uploadDir = __DIR__ . '/../assets/uploads/admins/';

                if (
                    !is_dir($uploadDir) &&
                    !mkdir($uploadDir, 0755, true) &&
                    !is_dir($uploadDir)
                ) {
                    $errors[] = 'Failed to prepare the admin profile picture folder.';
                } else {
                    $uploadedName =
                        uniqid('admin_', true) .
                        '.' .
                        $extension;

                    $destination =
                        $uploadDir .
                        $uploadedName;

                    if (
                        move_uploaded_file(
                            $_FILES['profile_picture']['tmp_name'],
                            $destination
                        )
                    ) {
                        $newProfilePicture = $uploadedName;
                        $uploadedNewPicture = $uploadedName;
                    } else {
                        $errors[] = 'Failed to save the new profile picture.';
                    }
                }
            }
        }
    }

    if (!$errors) {
        try {
            $updateStmt = $pdo->prepare("
                UPDATE admins
                SET
                    full_name = ?,
                    email = ?,
                    profile_picture = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");

            $updateStmt->execute([
                $fullName,
                $email,
                $newProfilePicture,
                $adminId
            ]);

            $oldPicture = $admin['profile_picture'] ?? '';

            if (
                $uploadedNewPicture &&
                !empty($oldPicture) &&
                $oldPicture !== 'default-admin.png' &&
                $oldPicture !== $uploadedNewPicture
            ) {
                $oldPath =
                    __DIR__ . '/../assets/uploads/admins/' .
                    $oldPicture;

                if (file_exists($oldPath)) {
                    unlink($oldPath);
                }
            }

            /*
             * Keep the current session display name in sync.
             * These keys are harmless when another login version
             * does not use them.
             */
            $_SESSION['user_name'] = $fullName;
            $_SESSION['user_email'] = $email;
            $_SESSION['admin_id'] = $adminId;
            $_SESSION['admin_profile_picture'] = $newProfilePicture;

            header(
                "Location: profile.php?profile_success=1"
            );
            exit;

        } catch (Throwable $e) {
            if (
                $uploadedNewPicture &&
                $uploadedNewPicture !== ($admin['profile_picture'] ?? '')
            ) {
                $newPath =
                    __DIR__ . '/../assets/uploads/admins/' .
                    $uploadedNewPicture;

                if (file_exists($newPath)) {
                    unlink($newPath);
                }
            }

            $errors[] = 'Failed to update your profile.';
        }
    }

    /*
     * Keep typed values visible when validation fails.
     */
    $admin['full_name'] = $fullName;
    $admin['email'] = $email;
}

/* =========================================================
   CHANGE PASSWORD
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['change_password'])
) {
    $currentPassword =
        (string)($_POST['current_password'] ?? '');

    $newPassword =
        (string)($_POST['new_password'] ?? '');

    $confirmPassword =
        (string)($_POST['confirm_password'] ?? '');

    if ($currentPassword === '') {
        $errors[] = 'Current password is required.';
    }

    if ($newPassword === '') {
        $errors[] = 'New password is required.';
    } elseif (strlen($newPassword) < 8) {
        $errors[] = 'New password must be at least 8 characters.';
    }

    if ($confirmPassword === '') {
        $errors[] = 'Please confirm your new password.';
    } elseif ($newPassword !== $confirmPassword) {
        $errors[] = 'New password and confirmation do not match.';
    }

    if (!$errors && !password_verify($currentPassword, $admin['password'])) {
        $errors[] = 'Current password is incorrect.';
    }

    if (!$errors) {
        try {
            $passwordHash = password_hash(
                $newPassword,
                PASSWORD_DEFAULT
            );

            $passwordStmt = $pdo->prepare("
                UPDATE admins
                SET
                    password = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");

            $passwordStmt->execute([
                $passwordHash,
                $adminId
            ]);

            header(
                "Location: profile.php?password_success=1"
            );
            exit;

        } catch (Throwable $e) {
            $errors[] = 'Failed to change your password.';
        }
    }
}

/* =========================================================
   SUCCESS MESSAGES
========================================================= */

if (isset($_GET['profile_success'])) {
    $successMessage =
        'Your profile has been updated successfully.';
}

if (isset($_GET['password_success'])) {
    $successMessage =
        'Your password has been changed successfully.';
}

require_once '../includes/header.php';
?>

<style>
body {
    background: #F7F5F2;
}

/* =========================================================
   PAGE LAYOUT
========================================================= */

.profile-page {
    min-height: 100vh;
    min-width: 0;
    box-sizing: border-box;
    background: #F7F3EE;
}

.profile-topbar {
    position: sticky;
    top: 0;
    z-index: 1100;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    padding: 16px 24px;
    background: #ffffff;
    border-bottom: 2px solid #6F4E37;
    box-shadow: 0 3px 10px rgba(44, 34, 30, .08);
}

.profile-search {
    max-width: 320px;
    width: 100%;
}

.profile-search input {
    width: 100%;
    border-radius: 50px;
    border: 1px solid #B8A08A;
    padding: 9px 16px;
    font-size: .85rem;
    background: #FDF8F2;
    outline: none;
}

.profile-search input:focus {
    border-color: #6F4E37;
}

/* =========================================================
   TOPBAR ADMIN MENU
========================================================= */

.admin-profile {
    position: relative;
}

.admin-profile-btn {
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

.admin-profile-btn:hover,
.admin-profile-btn[aria-expanded="true"] {
    background: #FDF8F2;
    border-color: #6F4E37;
}

.admin-profile .avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: #4A3525;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    overflow: hidden;
}

.admin-profile .avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.profile-arrow {
    font-size: 11px;
}

.admin-profile-dropdown {
    position: absolute;
    top: calc(100% + 8px);
    right: 0;
    width: 200px;
    background: #ffffff;
    border: 2px solid #6F4E37;
    border-radius: 12px;
    box-shadow: 0 10px 24px rgba(44,34,30,.16);
    padding: 6px;
    display: none;
    z-index: 1200;
    overflow: hidden;
}

.admin-profile-dropdown.show {
    display: block;
}

.admin-profile-dropdown a {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    border-radius: 8px;
    color: #4A3525;
    text-decoration: none;
    font-size: .85rem;
}

.admin-profile-dropdown a:hover {
    background: #F0E6D6;
}

/* =========================================================
   CONTENT
========================================================= */

.profile-content {
    padding: 28px;
    max-width: 1200px;
    margin: 0 auto;
}

.profile-heading {
    margin-bottom: 22px;
}

.profile-heading h2 {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 1.5rem;
    color: #2C221E;
    margin-bottom: 6px;
}

.profile-heading h2 i {
    width: 42px;
    height: 42px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 12px;
    background: #4A3525;
    color: #ffffff;
    font-size: 1rem;
}

.profile-heading p {
    margin: 0 0 0 52px;
    color: #766C65;
    font-size: .9rem;
}

/* =========================================================
   CARDS
========================================================= */

.profile-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 22px;
}

.profile-card {
    background: #ffffff;
    border: 1px solid #E6DEC9;
    border-radius: 18px;
    box-shadow: 0 5px 18px rgba(44,34,30,.05);
    overflow: hidden;
}

.profile-card-header {
    padding: 18px 20px;
    border-bottom: 1px solid #EEE6DC;
}

.profile-card-header h5 {
    margin: 0;
    color: #2C221E;
    font-weight: 800;
}

.profile-card-header p {
    margin: 4px 0 0;
    color: #8A817A;
    font-size: .78rem;
}

.profile-card-body {
    padding: 20px;
}

/* =========================================================
   PROFILE SUMMARY
========================================================= */

.profile-summary {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    padding: 28px 20px 22px;
    border-bottom: 1px solid #EEE6DC;
}

.profile-avatar-large {
    width: 108px;
    height: 108px;
    border-radius: 50%;
    background: #4A3525;
    border: 4px solid #F0E6D6;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    overflow: hidden;
    margin-bottom: 14px;
}

.profile-avatar-large i {
    font-size: 2.5rem;
}

.profile-avatar-large img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.profile-summary h4 {
    color: #2C221E;
    font-weight: 800;
    margin-bottom: 5px;
}

.profile-summary .role-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 10px;
    border-radius: 50px;
    background: #F0E6D6;
    color: #6F4E37;
    font-size: .72rem;
    font-weight: 800;
    text-transform: capitalize;
}

.profile-detail-list {
    padding: 18px 20px;
}

.profile-detail {
    display: flex;
    justify-content: space-between;
    gap: 15px;
    padding: 10px 0;
    border-bottom: 1px solid #F1ECE6;
}

.profile-detail:last-child {
    border-bottom: 0;
}

.profile-detail-label {
    color: #8A817A;
    font-size: .78rem;
}

.profile-detail-value {
    text-align: right;
    color: #3A302A;
    font-weight: 700;
    font-size: .82rem;
    word-break: break-word;
}

/* =========================================================
   FORM
========================================================= */

.form-label {
    color: #4A3525;
    font-size: .8rem;
    font-weight: 700;
}

.form-control {
    border-color: #D8CCBE;
    border-radius: 10px;
    min-height: 43px;
}

.form-control:focus {
    border-color: #6F4E37;
    box-shadow: 0 0 0 .2rem rgba(111,78,55,.12);
}

.form-text {
    font-size: .72rem;
    color: #8A817A;
}

.btn-brown {
    background: #4A3525;
    border-color: #4A3525;
    color: #ffffff;
    border-radius: 10px;
    font-weight: 700;
    padding: 10px 16px;
}

.btn-brown:hover {
    background: #332317;
    border-color: #332317;
    color: #ffffff;
}

.btn-outline-brown {
    border: 1px solid #8B6A55;
    color: #4A3525;
    background: #ffffff;
    border-radius: 10px;
    font-weight: 700;
    padding: 10px 16px;
}

.btn-outline-brown:hover {
    background: #F7F0E8;
    color: #332317;
}

.password-note {
    background: #FDF8F2;
    border: 1px solid #E6DEC9;
    border-radius: 10px;
    padding: 10px 12px;
    color: #766C65;
    font-size: .75rem;
    margin-bottom: 16px;
}

/* =========================================================
   ALERTS
========================================================= */

.profile-alert {
    border-radius: 12px;
    font-size: .82rem;
}

.profile-alert ul {
    padding-left: 18px;
}

/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 1000px) {
    .profile-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {

    .profile-content {
        padding: 15px;
    }

    .profile-topbar {
        padding: 12px 15px;
    }

    .profile-search {
        max-width: 220px;
    }

    .admin-profile-btn span {
        display: none;
    }
}
</style>

<div class="profile-page">

    <?php require_once 'sidebar.php'; ?>

    <!-- =====================================================
         TOPBAR
    ====================================================== -->

    <div class="profile-topbar">

        <button type="button" class="admin-menu-toggle" aria-label="Open menu" aria-controls="adminSidebar" aria-expanded="false">
            <i class="bi bi-list"></i>
        </button>


        <div class="profile-search">
            <input
                type="text"
                placeholder="Search..."
            >
        </div>

        <div class="admin-profile">

            <button
                type="button"
                class="admin-profile-btn"
                id="adminProfileBtn"
                aria-expanded="false"
                aria-haspopup="true"
            >
                <div class="avatar">
                    <?php
                    $topbarPicture =
                        trim((string)($admin['profile_picture'] ?? ''));
                    ?>

                    <?php if (
                        $topbarPicture !== '' &&
                        $topbarPicture !== 'default-admin.png'
                    ): ?>

                        <img
                            src="../assets/uploads/admins/<?= htmlspecialchars($topbarPicture) ?>"
                            alt="Admin profile"
                        >

                    <?php else: ?>

                        <i class="bi bi-person-fill"></i>

                    <?php endif; ?>
                </div>

                <span>
                    <?= htmlspecialchars(
                        $admin['full_name'] ?? 'Admin User'
                    ) ?>
                </span>

                <i class="bi bi-chevron-down profile-arrow"></i>
            </button>

            <div
                class="admin-profile-dropdown"
                id="adminProfileDropdown"
            >
                <a href="profile.php">
                    <i class="bi bi-person"></i>
                    Profile
                </a>

                <a href="../auth/logout.php">
                    <i class="bi bi-box-arrow-right"></i>
                    Log Out
                </a>
            </div>

        </div>

    </div>

    <!-- =====================================================
         CONTENT
    ====================================================== -->

    <main class="profile-content">

        <div class="profile-heading">

            <h2>
                <i class="bi bi-person-circle"></i>
                My Profile
            </h2>

            <p>
                Manage your personal administrator account information.
            </p>

        </div>

        <?php if ($successMessage): ?>

            <div class="alert alert-success profile-alert">
                <i class="bi bi-check-circle-fill me-1"></i>
                <?= htmlspecialchars($successMessage) ?>
            </div>

        <?php endif; ?>

        <?php if (!empty($errors)): ?>

            <div class="alert alert-danger profile-alert">

                <strong>Unable to save changes:</strong>

                <ul class="mb-0 mt-2">

                    <?php foreach ($errors as $error): ?>

                        <li>
                            <?= htmlspecialchars($error) ?>
                        </li>

                    <?php endforeach; ?>

                </ul>

            </div>

        <?php endif; ?>

        <div class="profile-grid">

            <!-- =================================================
                 LEFT: PROFILE SUMMARY
            ================================================== -->

            <section class="profile-card">

                <div class="profile-summary">

                    <div class="profile-avatar-large">

                        <?php
                        $profilePicture =
                            trim((string)($admin['profile_picture'] ?? ''));
                        ?>

                        <?php if (
                            $profilePicture !== '' &&
                            $profilePicture !== 'default-admin.png'
                        ): ?>

                            <img
                                src="../assets/uploads/admins/<?= htmlspecialchars($profilePicture) ?>"
                                alt="Admin profile picture"
                            >

                        <?php else: ?>

                            <i class="bi bi-person-fill"></i>

                        <?php endif; ?>

                    </div>

                    <h4>
                        <?= htmlspecialchars(
                            $admin['full_name'] ?? 'Admin User'
                        ) ?>
                    </h4>

                    <span class="role-badge">

                        <i class="bi bi-shield-check"></i>

                        <?= htmlspecialchars(
                            ucfirst(
                                (string)($admin['role'] ?? 'admin')
                            )
                        ) ?>

                    </span>

                </div>

                <div class="profile-detail-list">

                    <div class="profile-detail">

                        <span class="profile-detail-label">
                            Email
                        </span>

                        <span class="profile-detail-value">
                            <?= htmlspecialchars(
                                $admin['email'] ?? ''
                            ) ?>
                        </span>

                    </div>

                    <div class="profile-detail">

                        <span class="profile-detail-label">
                            Account ID
                        </span>

                        <span class="profile-detail-value">
                            #<?= (int)$admin['id'] ?>
                        </span>

                    </div>

                    <div class="profile-detail">

                        <span class="profile-detail-label">
                            Account Created
                        </span>

                        <span class="profile-detail-value">

                            <?php
                            $createdAt =
                                $admin['created_at'] ?? null;

                            echo $createdAt
                                ? htmlspecialchars(
                                    date(
                                        'F d, Y',
                                        strtotime($createdAt)
                                    )
                                )
                                : '—';
                            ?>

                        </span>

                    </div>

                    <div class="profile-detail">

                        <span class="profile-detail-label">
                            Account Type
                        </span>

                        <span class="profile-detail-value">
                            Administrator
                        </span>

                    </div>

                </div>

            </section>

            <!-- =================================================
                 RIGHT: EDIT PROFILE
            ================================================== -->

            <section class="profile-card">

                <div class="profile-card-header">

                    <h5>
                        <i class="bi bi-pencil-square me-1"></i>
                        Personal Information
                    </h5>

                    <p>
                        Update the information shown on your admin profile.
                    </p>

                </div>

                <div class="profile-card-body">

                    <form
                        method="POST"
                        enctype="multipart/form-data"
                    >

                        <div class="mb-3">

                            <label
                                class="form-label"
                                for="fullName"
                            >
                                Full Name
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                id="fullName"
                                name="full_name"
                                maxlength="100"
                                value="<?= htmlspecialchars(
                                    $admin['full_name'] ?? ''
                                ) ?>"
                                required
                            >

                        </div>

                        <div class="mb-3">

                            <label
                                class="form-label"
                                for="email"
                            >
                                Email Address
                            </label>

                            <input
                                type="email"
                                class="form-control"
                                id="email"
                                name="email"
                                maxlength="150"
                                value="<?= htmlspecialchars(
                                    $admin['email'] ?? ''
                                ) ?>"
                                required
                            >

                        </div>

                        <div class="mb-3">

                            <label
                                class="form-label"
                                for="profilePicture"
                            >
                                Profile Picture
                            </label>

                            <input
                                type="file"
                                class="form-control"
                                id="profilePicture"
                                name="profile_picture"
                                accept=".jpg,.jpeg,.png,.webp"
                            >

                            <div class="form-text">
                                JPG, JPEG, PNG, or WEBP. Maximum 5 MB.
                            </div>

                        </div>

                        <div class="mb-4">

                            <label class="form-label">
                                Role
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                value="<?= htmlspecialchars(
                                    ucfirst(
                                        (string)($admin['role'] ?? 'admin')
                                    )
                                ) ?>"
                                disabled
                            >

                            <div class="form-text">
                                Your administrator role can only be changed by the appropriate account management process.
                            </div>

                        </div>

                        <button
                            type="submit"
                            name="update_profile"
                            class="btn btn-brown"
                        >
                            <i class="bi bi-check-lg me-1"></i>
                            Save Changes
                        </button>

                    </form>

                </div>

            </section>

            <!-- =================================================
                 PASSWORD
            ================================================== -->

            <section class="profile-card">

                <div class="profile-card-header">

                    <h5>
                        <i class="bi bi-lock me-1"></i>
                        Change Password
                    </h5>

                    <p>
                        Keep your administrator account secure.
                    </p>

                </div>

                <div class="profile-card-body">

                    <div class="password-note">
                        Use at least 8 characters and avoid sharing your password with other users.
                    </div>

                    <form method="POST">

                        <div class="mb-3">

                            <label
                                class="form-label"
                                for="currentPassword"
                            >
                                Current Password
                            </label>

                            <input
                                type="password"
                                class="form-control"
                                id="currentPassword"
                                name="current_password"
                                autocomplete="current-password"
                                required
                            >

                        </div>

                        <div class="mb-3">

                            <label
                                class="form-label"
                                for="newPassword"
                            >
                                New Password
                            </label>

                            <input
                                type="password"
                                class="form-control"
                                id="newPassword"
                                name="new_password"
                                minlength="8"
                                autocomplete="new-password"
                                required
                            >

                        </div>

                        <div class="mb-4">

                            <label
                                class="form-label"
                                for="confirmPassword"
                            >
                                Confirm New Password
                            </label>

                            <input
                                type="password"
                                class="form-control"
                                id="confirmPassword"
                                name="confirm_password"
                                minlength="8"
                                autocomplete="new-password"
                                required
                            >

                        </div>

                        <button
                            type="submit"
                            name="change_password"
                            class="btn btn-brown"
                        >
                            <i class="bi bi-shield-lock me-1"></i>
                            Change Password
                        </button>

                    </form>

                </div>

            </section>

        </div>

    </main>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const button =
        document.getElementById('adminProfileBtn');

    const dropdown =
        document.getElementById('adminProfileDropdown');

    if (!button || !dropdown) {
        return;
    }

    button.addEventListener('click', function (event) {
        event.stopPropagation();

        const isOpen =
            button.getAttribute('aria-expanded') === 'true';

        button.setAttribute(
            'aria-expanded',
            isOpen ? 'false' : 'true'
        );

        dropdown.classList.toggle(
            'show',
            !isOpen
        );
    });

    document.addEventListener('click', function () {
        button.setAttribute(
            'aria-expanded',
            'false'
        );

        dropdown.classList.remove('show');
    });

    dropdown.addEventListener('click', function (event) {
        event.stopPropagation();
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>
