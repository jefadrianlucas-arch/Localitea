<?php
/*
 * =========================================================
 * LOCALITEA STAFF PROFILE
 * =========================================================
 *
 * This page is for the currently logged-in staff member only.
 *
 * Features:
 * - View staff profile
 * - Edit personal information
 * - Change password
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../includes/db.php';

/* =========================================================
   STAFF ACCESS ONLY
========================================================= */

if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['user_role'] ?? '') !== 'staff'
) {
    header('Location: ../auth/login.php');
    exit;
}

/* =========================================================
   RESOLVE LOGGED-IN STAFF
========================================================= */

$staffId = (int)($_SESSION['user_id'] ?? 0);

if ($staffId <= 0) {
    header('Location: ../auth/login.php');
    exit;
}

/* =========================================================
   LOAD STAFF
========================================================= */

$staffStmt = $pdo->prepare("
    SELECT
        id,
        name,
        email,
        phone,
        password,
        role,
        created_at
    FROM users
    WHERE id = ?
      AND role = 'staff'
    LIMIT 1
");

$staffStmt->execute([
    $staffId
]);

$staff = $staffStmt->fetch(PDO::FETCH_ASSOC);

if (!$staff) {
    session_unset();
    session_destroy();

    header('Location: ../auth/login.php');
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
    $name = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));

    /* -----------------------------------------------------
       VALIDATION
    ----------------------------------------------------- */

    if ($name === '') {
        $errors[] = 'Full name is required.';
    }

    if (
        $email === '' ||
        !filter_var($email, FILTER_VALIDATE_EMAIL)
    ) {
        $errors[] = 'Please enter a valid email address.';
    }

    /* -----------------------------------------------------
       CHECK DUPLICATE EMAIL
    ----------------------------------------------------- */

    if (!$errors) {
        $duplicateStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM users
            WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))
              AND id != ?
        ");

        $duplicateStmt->execute([
            $email,
            $staffId
        ]);

        if ((int)$duplicateStmt->fetchColumn() > 0) {
            $errors[] =
                'Another user account already uses that email address.';
        }
    }

    /* -----------------------------------------------------
       UPDATE DATABASE
    ----------------------------------------------------- */

    if (!$errors) {
        try {
            $updateStmt = $pdo->prepare("
                UPDATE users
                SET
                    name = ?,
                    email = ?,
                    phone = ?
                WHERE id = ?
                  AND role = 'staff'
            ");

            $updateStmt->execute([
                $name,
                $email,
                $phone,
                $staffId
            ]);

            /* Keep session information synchronized. */
            $_SESSION['user_name'] = $name;
            $_SESSION['user_email'] = $email;
            $_SESSION['user_id'] = $staffId;
            $_SESSION['user_role'] = 'staff';

            header(
                'Location: profile.php?profile_success=1'
            );
            exit;

        } catch (Throwable $e) {
            $errors[] = 'Failed to update your profile.';
        }
    }

    /* Keep typed values visible if validation fails. */
    $staff['name'] = $name;
    $staff['email'] = $email;
    $staff['phone'] = $phone;
}

/* =========================================================
   CHANGE PASSWORD
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['change_password'])
) {
    $currentPassword = (string)(
        $_POST['current_password'] ?? ''
    );

    $newPassword = (string)(
        $_POST['new_password'] ?? ''
    );

    $confirmPassword = (string)(
        $_POST['confirm_password'] ?? ''
    );

    /* -----------------------------------------------------
       VALIDATION
    ----------------------------------------------------- */

    if ($currentPassword === '') {
        $errors[] = 'Current password is required.';
    }

    if ($newPassword === '') {
        $errors[] = 'New password is required.';
    } elseif (strlen($newPassword) < 8) {
        $errors[] =
            'New password must be at least 8 characters.';
    }

    if ($confirmPassword === '') {
        $errors[] =
            'Please confirm your new password.';
    } elseif ($newPassword !== $confirmPassword) {
        $errors[] =
            'New password and confirmation do not match.';
    }

    /* -----------------------------------------------------
       VERIFY CURRENT PASSWORD
    ----------------------------------------------------- */

    if (
        !$errors &&
        !password_verify(
            $currentPassword,
            (string)$staff['password']
        )
    ) {
        $errors[] = 'Current password is incorrect.';
    }

    /* -----------------------------------------------------
       SAVE NEW PASSWORD
    ----------------------------------------------------- */

    if (!$errors) {
        try {
            $passwordHash = password_hash(
                $newPassword,
                PASSWORD_DEFAULT
            );

            $passwordStmt = $pdo->prepare("
                UPDATE users
                SET password = ?
                WHERE id = ?
                  AND role = 'staff'
            ");

            $passwordStmt->execute([
                $passwordHash,
                $staffId
            ]);

            header(
                'Location: profile.php?password_success=1'
            );
            exit;

        } catch (Throwable $e) {
            $errors[] =
                'Failed to change your password.';
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

/* =========================================================
   DISPLAY VALUES
========================================================= */

$staffName = trim(
    (string)($staff['name'] ?? 'Staff User')
);

$staffEmail = trim(
    (string)($staff['email'] ?? '')
);

$staffPhone = trim(
    (string)($staff['phone'] ?? '')
);

$staffInitial = strtoupper(
    substr(
        $staffName !== ''
            ? $staffName
            : 'S',
        0,
        1
    )
);

require_once '../includes/header.php';
?>

<style>
body {
    background: #F7F5F2;
}

/* =========================================================
   PAGE
========================================================= */

.staff-profile-page {
    min-height: 100vh;
    min-width: 0;
    box-sizing: border-box;
    background: #F7F3EE;
}


/* =========================================================
   CONTENT
========================================================= */

.staff-profile-content {
    padding: 28px;

    max-width: 1200px;

    margin: 0 auto;
}

.staff-profile-heading {
    margin-bottom: 22px;
}

.staff-profile-heading h2 {
    display: flex;
    align-items: center;

    gap: 10px;

    font-size: 1.5rem;

    color: #2C221E;

    margin-bottom: 6px;
}

.staff-profile-heading h2 i {
    width: 42px;
    height: 42px;

    display: inline-flex;

    align-items: center;
    justify-content: center;

    border-radius: 12px;

    background: #4A3525;
    color: #FFFFFF;

    font-size: 1rem;
}

.staff-profile-heading p {
    margin: 0 0 0 52px;

    color: #766C65;

    font-size: .9rem;
}

/* =========================================================
   GRID
========================================================= */

.staff-profile-grid {
    display: grid;

    grid-template-columns: 1fr 1fr;

    gap: 22px;
}

/* =========================================================
   CARDS
========================================================= */

.staff-profile-card {
    background: #FFFFFF;

    border: 1px solid #E6DEC9;

    border-radius: 18px;

    box-shadow:
        0 5px 18px rgba(44, 34, 30, .05);

    overflow: hidden;
}

.staff-profile-card-header {
    padding: 18px 20px;

    border-bottom: 1px solid #EEE6DC;
}

.staff-profile-card-header h5 {
    margin: 0;

    color: #2C221E;

    font-weight: 800;
}

.staff-profile-card-header p {
    margin: 4px 0 0;

    color: #8A817A;

    font-size: .78rem;
}

.staff-profile-card-body {
    padding: 20px;
}

/* =========================================================
   PROFILE SUMMARY
========================================================= */

.staff-profile-summary {
    display: flex;

    flex-direction: column;

    align-items: center;

    text-align: center;

    padding: 28px 20px 22px;

    border-bottom: 1px solid #EEE6DC;
}

.staff-profile-avatar-large {
    width: 108px;
    height: 108px;

    border-radius: 50%;

    background: #4A3525;

    border: 4px solid #F0E6D6;

    display: flex;
    align-items: center;
    justify-content: center;

    color: #FFFFFF;

    font-size: 2.6rem;
    font-weight: 800;

    margin-bottom: 14px;
}

.staff-profile-summary h4 {
    color: #2C221E;

    font-weight: 800;

    margin-bottom: 5px;
}

.staff-profile-role-badge {
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

/* =========================================================
   DETAILS
========================================================= */

.staff-profile-detail-list {
    padding: 18px 20px;
}

.staff-profile-detail {
    display: flex;

    justify-content: space-between;

    gap: 15px;

    padding: 10px 0;

    border-bottom: 1px solid #F1ECE6;
}

.staff-profile-detail:last-child {
    border-bottom: 0;
}

.staff-profile-detail-label {
    color: #8A817A;

    font-size: .78rem;
}

.staff-profile-detail-value {
    text-align: right;

    color: #3A302A;

    font-weight: 700;

    font-size: .82rem;

    word-break: break-word;
}

/* =========================================================
   FORMS
========================================================= */

.staff-profile-card .form-label {
    color: #4A3525;

    font-size: .8rem;

    font-weight: 700;
}

.staff-profile-card .form-control {
    border-color: #D8CCBE;

    border-radius: 10px;

    min-height: 43px;
}

.staff-profile-card .form-control:focus {
    border-color: #6F4E37;

    box-shadow:
        0 0 0 .2rem rgba(111, 78, 55, .12);
}

.staff-profile-card .form-text {
    font-size: .72rem;

    color: #8A817A;
}

.staff-btn-brown {
    background: #4A3525;

    border-color: #4A3525;

    color: #FFFFFF;

    border-radius: 10px;

    font-weight: 700;

    padding: 10px 16px;
}

.staff-btn-brown:hover {
    background: #332317;

    border-color: #332317;

    color: #FFFFFF;
}

.staff-btn-outline-brown {
    border: 1px solid #8B6A55;

    color: #4A3525;

    background: #FFFFFF;

    border-radius: 10px;

    font-weight: 700;

    padding: 10px 16px;
}

.staff-btn-outline-brown:hover {
    background: #F7F0E8;

    color: #332317;
}

/* =========================================================
   PASSWORD NOTE
========================================================= */

.staff-password-note {
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

.staff-profile-alert {
    border-radius: 12px;

    font-size: .82rem;
}

.staff-profile-alert ul {
    padding-left: 18px;
}

/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 1000px) {

    .staff-profile-grid {
        grid-template-columns: 1fr;
    }

}

@media (max-width: 768px) {

    .staff-profile-content {
        padding: 15px;
    }

    .staff-profile-topbar {
        padding: 12px 15px;
    }

    .staff-profile-search {
        max-width: 220px;
    }

    .staff-profile-btn span {
        display: none;
    }

}

@media (max-width: 480px) {

    .staff-profile-topbar {
        gap: 10px;
    }

    .staff-profile-search {
        max-width: 170px;
    }

    .staff-profile-content {
        padding: 12px;
    }

    .staff-profile-heading h2 {
        font-size: 1.25rem;
    }

    .staff-profile-heading p {
        margin-left: 0;
        margin-top: 8px;
    }

    .staff-profile-detail {
        align-items: flex-start;
        flex-direction: column;
        gap: 3px;
    }

    .staff-profile-detail-value {
        text-align: left;
    }

}
</style>

<div class="staff-profile-page">

    <?php require_once 'sidebar.php'; ?>

    <?php require_once 'navbar.php'; ?>

    <!-- =====================================================
         CONTENT
    ====================================================== -->

    <main class="staff-profile-content">

        <div class="staff-profile-heading">

            <h2>
                <i class="bi bi-person-circle"></i>
                My Profile
            </h2>

            <p>
                Manage your personal staff account information.
            </p>

        </div>

        <?php if ($successMessage): ?>

            <div class="alert alert-success staff-profile-alert">

                <i class="bi bi-check-circle-fill me-1"></i>

                <?= htmlspecialchars($successMessage) ?>

            </div>

        <?php endif; ?>

        <?php if (!empty($errors)): ?>

            <div class="alert alert-danger staff-profile-alert">

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

        <div class="staff-profile-grid">

            <!-- =================================================
                 LEFT: PROFILE SUMMARY
            ================================================== -->

            <section class="staff-profile-card">

                <div class="staff-profile-summary">

                    <div class="staff-profile-avatar-large">
                        <?= htmlspecialchars($staffInitial) ?>
                    </div>

                    <h4>
                        <?= htmlspecialchars($staffName) ?>
                    </h4>

                    <span class="staff-profile-role-badge">

                        <i class="bi bi-person-badge"></i>

                        Staff

                    </span>

                </div>

                <div class="staff-profile-detail-list">

                    <div class="staff-profile-detail">

                        <span class="staff-profile-detail-label">
                            Email
                        </span>

                        <span class="staff-profile-detail-value">
                            <?= htmlspecialchars($staffEmail) ?>
                        </span>

                    </div>

                    <div class="staff-profile-detail">

                        <span class="staff-profile-detail-label">
                            Phone
                        </span>

                        <span class="staff-profile-detail-value">
                            <?= $staffPhone !== ''
                                ? htmlspecialchars($staffPhone)
                                : '—'
                            ?>
                        </span>

                    </div>

                    <div class="staff-profile-detail">

                        <span class="staff-profile-detail-label">
                            Account ID
                        </span>

                        <span class="staff-profile-detail-value">
                            #<?= (int)$staff['id'] ?>
                        </span>

                    </div>

                    <div class="staff-profile-detail">

                        <span class="staff-profile-detail-label">
                            Account Created
                        </span>

                        <span class="staff-profile-detail-value">

                            <?php
                            $createdAt =
                                $staff['created_at'] ?? null;

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

                    <div class="staff-profile-detail">

                        <span class="staff-profile-detail-label">
                            Account Type
                        </span>

                        <span class="staff-profile-detail-value">
                            Staff
                        </span>

                    </div>

                </div>

            </section>

            <!-- =================================================
                 RIGHT: PERSONAL INFORMATION
            ================================================== -->

            <section class="staff-profile-card">

                <div class="staff-profile-card-header">

                    <h5>
                        <i class="bi bi-pencil-square me-1"></i>
                        Personal Information
                    </h5>

                    <p>
                        Update the information shown on your staff profile.
                    </p>

                </div>

                <div class="staff-profile-card-body">

                    <form method="POST">

                        <div class="mb-3">

                            <label
                                class="form-label"
                                for="staffName"
                            >
                                Full Name
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                id="staffName"
                                name="name"
                                maxlength="100"
                                value="<?= htmlspecialchars(
                                    $staff['name'] ?? ''
                                ) ?>"
                                required
                            >

                        </div>

                        <div class="mb-3">

                            <label
                                class="form-label"
                                for="staffEmail"
                            >
                                Email Address
                            </label>

                            <input
                                type="email"
                                class="form-control"
                                id="staffEmail"
                                name="email"
                                maxlength="150"
                                value="<?= htmlspecialchars(
                                    $staff['email'] ?? ''
                                ) ?>"
                                required
                            >

                        </div>

                        <div class="mb-4">

                            <label
                                class="form-label"
                                for="staffPhone"
                            >
                                Phone Number
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                id="staffPhone"
                                name="phone"
                                maxlength="30"
                                value="<?= htmlspecialchars(
                                    $staff['phone'] ?? ''
                                ) ?>"
                            >

                        </div>

                        <div class="mb-4">

                            <label class="form-label">
                                Role
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                value="Staff"
                                disabled
                            >

                            <div class="form-text">
                                Your staff role cannot be changed from this page.
                            </div>

                        </div>

                        <button
                            type="submit"
                            name="update_profile"
                            class="btn staff-btn-brown"
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

            <section class="staff-profile-card">

                <div class="staff-profile-card-header">

                    <h5>
                        <i class="bi bi-lock me-1"></i>
                        Change Password
                    </h5>

                    <p>
                        Keep your staff account secure.
                    </p>

                </div>

                <div class="staff-profile-card-body">

                    <div class="staff-password-note">

                        Use at least 8 characters and avoid sharing
                        your password with other users.

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
                            class="btn staff-btn-brown"
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

<?php
require_once '../includes/footer.php';
?>
