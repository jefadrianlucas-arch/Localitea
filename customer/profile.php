<?php
/* =========================================================
   LOCALITEA CUSTOMER PROFILE
   Customer-only personal information and password settings.
========================================================= */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../includes/db.php';

if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['user_role'] ?? '') !== 'customer'
) {
    header('Location: ../auth/login.php');
    exit;
}

$customerId = (int)$_SESSION['user_id'];
$errors = [];
$successMessage = null;

$customerStmt = $pdo->prepare("\n    SELECT id, full_name, email, contact_number, password, created_at\n    FROM customers\n    WHERE id = ?\n    LIMIT 1\n");
$customerStmt->execute([$customerId]);
$customer = $customerStmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    session_unset();
    session_destroy();
    header('Location: ../auth/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $profileAction = trim((string)($_POST['profile_action'] ?? 'details'));

    if ($profileAction === 'details') {
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $contactNumber = trim((string)($_POST['contact_number'] ?? ''));

        if ($fullName === '') {
            $errors[] = 'Full name is required.';
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }

        if ($contactNumber === '') {
            $errors[] = 'Mobile number is required.';
        } else {
            $normalized = preg_replace('/[\s\-()]+/', '', $contactNumber);
            if (!preg_match('/^(?:\+63|63|0)9\d{9}$/', $normalized)) {
                $errors[] = 'Please enter a valid Philippine mobile number.';
            }
        }

        if (!$errors) {
            $duplicateStmt = $pdo->prepare("\n                SELECT COUNT(*)\n                FROM customers\n                WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))\n                  AND id <> ?\n            ");
            $duplicateStmt->execute([$email, $customerId]);

            if ((int)$duplicateStmt->fetchColumn() > 0) {
                $errors[] = 'That email address is already in use by another account.';
            }
        }

        if (!$errors) {
            try {
                $updateStmt = $pdo->prepare("\n                    UPDATE customers\n                    SET full_name = ?, email = ?, contact_number = ?\n                    WHERE id = ?\n                ");
                $updateStmt->execute([$fullName, $email, $contactNumber, $customerId]);

                $_SESSION['user_name'] = $fullName;
                $_SESSION['user_email'] = $email;
                $successMessage = 'Profile updated successfully.';

                $customerStmt->execute([$customerId]);
                $customer = $customerStmt->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $errors[] = 'Unable to save your profile right now.';
            }
        }
    } elseif ($profileAction === 'password') {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_new_password'] ?? '');

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            $errors[] = 'Please fill in all password fields to change your password.';
        } elseif (!password_verify($currentPassword, (string)$customer['password'])) {
            $errors[] = 'Current password is incorrect.';
        } elseif (strlen($newPassword) < 8) {
            $errors[] = 'New password must be at least 8 characters.';
        } elseif ($newPassword !== $confirmPassword) {
            $errors[] = 'New password and confirmation do not match.';
        }

        if (!$errors) {
            try {
                $passwordStmt = $pdo->prepare("\n                    UPDATE customers\n                    SET password = ?\n                    WHERE id = ?\n                ");
                $passwordStmt->execute([
                    password_hash($newPassword, PASSWORD_DEFAULT),
                    $customerId
                ]);
                $successMessage = 'Password updated successfully.';

                $customerStmt->execute([$customerId]);
                $customer = $customerStmt->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $errors[] = 'Unable to update your password right now.';
            }
        }
    } else {
        $errors[] = 'Invalid profile action.';
    }
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
?>

<style>
body{background:#F7F5F2;color:#2C221E;overflow-x:hidden}
.customer-profile-page{min-height:calc(100vh - 80px);padding:34px 0 50px}
.profile-heading{margin-bottom:22px}
.profile-heading h2{color:#4A3525;font-weight:800;margin:0}
.profile-heading p{margin:5px 0 0;color:#8A7F75;font-size:.9rem}
.profile-grid{display:grid;grid-template-columns:1.1fr .9fr;gap:20px}
.customer-profile-card{background:#fff;border:1px solid #E6DEC9;border-radius:18px;box-shadow:0 7px 22px rgba(44,34,30,.05);overflow:hidden}
.customer-profile-card-header{padding:18px 20px;border-bottom:1px solid #EFE7DE;background:#FFFDFC}
.customer-profile-card-header h5{margin:0;color:#2C221E;font-weight:800}
.customer-profile-card-header p{margin:4px 0 0;color:#8A7F75;font-size:.78rem}
.customer-profile-card-body{padding:20px}
.profile-avatar{width:58px;height:58px;border-radius:50%;background:#4A3525;color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.25rem;font-weight:800;box-shadow:0 5px 14px rgba(74,53,37,.18)}
.account-id{font-size:.72rem;color:#8A7F75;margin-top:5px}
.field-label{font-size:.73rem;font-weight:700;color:#6F6258;margin-bottom:6px}
.custom-input{border:1px solid #D9CEC3;border-radius:10px;padding:10px 12px;font-size:.86rem;background:#fff}
.custom-input:focus{border-color:#6F4E37;box-shadow:0 0 0 .16rem rgba(111,78,55,.12)}
.section-divider{border-top:1px dashed #E6DEC9;margin:20px 0}
.btn-brown{background:#4A3525;border:1px solid #4A3525;color:#fff;border-radius:999px;font-weight:700;padding:10px 18px}
.btn-brown:hover{background:#342317;border-color:#342317;color:#fff}
.profile-meta{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:18px}
.meta-box{background:#FDF8F2;border:1px solid #E9DED2;border-radius:12px;padding:12px}
.meta-label{font-size:.68rem;text-transform:uppercase;letter-spacing:.5px;color:#8A7F75;font-weight:800}
.meta-value{margin-top:4px;font-size:.82rem;color:#4A3525;font-weight:700;overflow-wrap:anywhere}
.alert{border-radius:12px;font-size:.82rem}
@media(max-width:991.98px){.profile-grid{grid-template-columns:1fr}.customer-profile-page{padding:26px 0 42px}}
@media(max-width:576px){.customer-profile-page{padding:20px 12px 38px}.profile-heading h2{font-size:1.35rem}.customer-profile-card-body{padding:15px}.customer-profile-card-header{padding:15px}.profile-meta{grid-template-columns:1fr}.btn-brown{width:100%}}
</style>

<main class="customer-profile-page">
    <div class="container">
        <div class="profile-heading">
            <h2><i class="bi bi-person-circle me-2"></i>My Profile</h2>
            <p>Manage your personal information and account password.</p>
        </div>

        <?php foreach ($errors as $error): ?>
            <div class="alert alert-danger py-2 px-3 mb-2"><?= htmlspecialchars($error) ?></div>
        <?php endforeach; ?>

        <?php if ($successMessage): ?>
            <div class="alert alert-success py-2 px-3 mb-3"><?= htmlspecialchars($successMessage) ?></div>
        <?php endif; ?>

        <div class="profile-grid">
            <section class="customer-profile-card">
                <div class="customer-profile-card-header">
                    <h5>Personal Information</h5>
                    <p>These details are used for your customer account and orders.</p>
                </div>
                <div class="customer-profile-card-body">
                    <div class="d-flex align-items-center gap-3 mb-4">
                        <div class="profile-avatar"><?= htmlspecialchars(strtoupper(substr(trim((string)$customer['full_name']), 0, 1))) ?></div>
                        <div>
                            <div class="fw-bold" style="color:#4A3525;font-size:1rem;"><?= htmlspecialchars($customer['full_name']) ?></div>
                            <div class="account-id">Customer Account #<?= (int)$customer['id'] ?></div>
                        </div>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="profile_action" value="details">
                        <div class="mb-3">
                            <label class="field-label">Full Name</label>
                            <input type="text" name="full_name" class="form-control custom-input" value="<?= htmlspecialchars($customer['full_name']) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="field-label">Email Address</label>
                            <input type="email" name="email" class="form-control custom-input" value="<?= htmlspecialchars($customer['email']) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="field-label">Mobile Number</label>
                            <input type="text" name="contact_number" class="form-control custom-input" value="<?= htmlspecialchars($customer['contact_number']) ?>" required>
                        </div>

                        <div class="profile-meta">
                            <div class="meta-box"><div class="meta-label">Account Type</div><div class="meta-value">Customer</div></div>
                            <div class="meta-box"><div class="meta-label">Member Since</div><div class="meta-value"><?= htmlspecialchars(date('M d, Y', strtotime($customer['created_at']))) ?></div></div>
                        </div>

                        <div class="text-end mt-4">
                            <button type="submit" class="btn btn-brown"><i class="bi bi-check2 me-1"></i>Save Changes</button>
                        </div>
                    </form>
                </div>
            </section>

            <section class="customer-profile-card">
                <div class="customer-profile-card-header">
                    <h5>Security</h5>
                    <p>Change your password when you need to secure your account.</p>
                </div>
                <div class="customer-profile-card-body">
                    <div class="meta-box mb-3"><div class="meta-label">Password</div><div class="meta-value">••••••••</div></div>
                    <form method="POST">
                        <input type="hidden" name="profile_action" value="password">
                        <div class="mb-3"><label class="field-label">Current Password</label><input type="password" name="current_password" class="form-control custom-input" placeholder="Enter your current password"></div>
                        <div class="mb-3"><label class="field-label">New Password</label><input type="password" name="new_password" class="form-control custom-input" placeholder="At least 8 characters"></div>
                        <div class="mb-3"><label class="field-label">Confirm New Password</label><input type="password" name="confirm_new_password" class="form-control custom-input" placeholder="Re-enter your new password"></div>
                        <div class="section-divider"></div>
                        <button type="submit" class="btn btn-brown w-100"><i class="bi bi-shield-lock me-1"></i>Update Password</button>
                    </form>
                </div>
            </section>
        </div>
    </div>
</main>

<?php require_once '../includes/footer.php'; ?>
