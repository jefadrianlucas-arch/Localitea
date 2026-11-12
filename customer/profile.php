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

/* CSRF token protects profile, password, and discount application forms. */
if (empty($_SESSION['profile_csrf'])) {
    $_SESSION['profile_csrf'] = bin2hex(random_bytes(32));
}
$profileCsrf = (string)$_SESSION['profile_csrf'];

$customerStmt = $pdo->prepare("\n    SELECT
        id, full_name, email, contact_number, password, created_at,
        discount_type, discount_id_name, discount_id_number, discount_id_image,
        verification_status, verification_rejection_reason, verified_at
    FROM customers
    WHERE id = ?
    LIMIT 1
");
$customerStmt->execute([$customerId]);
$customer = $customerStmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    session_unset();
    session_destroy();
    header('Location: ../auth/login.php');
    exit;
}

$discountFormType = '';
$discountFormName = '';
$discountFormNumber = '';

/* Detect whether the optional archive column exists; older deployments may not have it. */
$hasDiscountArchiveColumn = false;
try {
    $hasDiscountArchiveColumn = (bool)$pdo
        ->query("SHOW COLUMNS FROM customers LIKE 'discount_id_archived'")
        ->fetch();
} catch (Throwable $e) {
    $hasDiscountArchiveColumn = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $profileAction = trim((string)($_POST['profile_action'] ?? 'details'));
    $postedCsrf = (string)($_POST['profile_csrf'] ?? '');

    if (!hash_equals($profileCsrf, $postedCsrf)) {
        $errors[] = 'Your session token is invalid or expired. Please refresh the page and try again.';
    } elseif ($profileAction === 'details') {
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
    } elseif ($profileAction === 'discount_application') {
        $discountFormType = strtolower(trim((string)($_POST['discount_type'] ?? '')));
        $discountFormName = trim(preg_replace('/\s+/', ' ', (string)($_POST['discount_id_name'] ?? '')));
        $discountFormNumber = strtoupper(trim((string)($_POST['discount_id_number'] ?? '')));

        if (!in_array($discountFormType, ['pwd', 'senior'], true)) {
            $errors[] = 'Please choose PWD or Senior Citizen.';
        }

        if ($discountFormName === '' || mb_strlen($discountFormName) > 100) {
            $errors[] = 'Please enter the name shown on your ID (up to 100 characters).';
        }

        if ($discountFormType === 'pwd') {
            $discountFormNumber = preg_replace('/[\s-]/', '', $discountFormNumber);
            if (!preg_match('/^\d{16}$/', $discountFormNumber)) {
                $errors[] = 'PWD ID number must contain exactly 16 digits.';
            }
        } elseif ($discountFormType === 'senior') {
            if (!preg_match('/^[A-Z0-9][A-Z0-9\-\/ ]{2,29}$/', $discountFormNumber)) {
                $errors[] = 'Please enter a valid Senior Citizen ID number.';
            }
        }

        $idPhoto = $_FILES['discount_id_image'] ?? null;
        if (!$idPhoto || !is_array($idPhoto) || (int)($idPhoto['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors[] = 'Please upload a clear photo of your ID.';
        } elseif ((int)($idPhoto['size'] ?? 0) <= 0 || (int)$idPhoto['size'] > 5 * 1024 * 1024) {
            $errors[] = 'The ID photo must be greater than 0 bytes and must not exceed 5MB.';
        } elseif (!is_uploaded_file((string)($idPhoto['tmp_name'] ?? ''))) {
            $errors[] = 'The uploaded ID photo could not be verified. Please try again.';
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $photoMime = $finfo->file($idPhoto['tmp_name']);
            $imageInfo = @getimagesize($idPhoto['tmp_name']);
            if (
                !in_array($photoMime, ['image/jpeg', 'image/png'], true) ||
                !$imageInfo ||
                !in_array($imageInfo[2] ?? 0, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)
            ) {
                $errors[] = 'Invalid ID photo. Only valid JPG, JPEG, or PNG image files are allowed.';
            }
        }

        $currentVerificationStatus = strtolower(trim((string)($customer['verification_status'] ?? 'none')));
        if ($currentVerificationStatus === 'pending') {
            $errors[] = 'Your ID application is already waiting for admin review. Please wait for the result before submitting another one.';
        } elseif ($currentVerificationStatus === 'verified') {
            $errors[] = 'Your ID has already been approved. Contact the store if your verified details need to be changed.';
        } elseif (!in_array($currentVerificationStatus, ['', 'none', 'rejected'], true)) {
            $errors[] = 'Your current ID application cannot be changed right now. Please contact the store.';
        }

        if (!$errors) {
            $newPhotoRelative = null;
            $newPhotoFullPath = null;
            $oldPhotoRelative = '';

            try {
                $pdo->beginTransaction();

                /* Lock this account so a concurrent submission or admin decision cannot be overwritten. */
                $lockStmt = $pdo->prepare("
                    SELECT verification_status, discount_id_image
                    FROM customers
                    WHERE id = ?
                    LIMIT 1
                    FOR UPDATE
                ");
                $lockStmt->execute([$customerId]);
                $lockedCustomer = $lockStmt->fetch(PDO::FETCH_ASSOC);

                $lockedStatus = strtolower(trim((string)($lockedCustomer['verification_status'] ?? 'none')));
                if (!$lockedCustomer || !in_array($lockedStatus, ['', 'none', 'rejected'], true)) {
                    throw new RuntimeException('APPLICATION_STATE_CHANGED');
                }

                /* Reject an ID number already submitted by another customer. */
                $duplicateKey = strtoupper(preg_replace('/[\s-]+/', '', $discountFormNumber));
                $duplicateStmt = $pdo->prepare("
                    SELECT id
                    FROM customers
                    WHERE id <> ?
                      AND UPPER(REPLACE(REPLACE(TRIM(COALESCE(discount_id_number, '')), '-', ''), ' ', '')) = ?
                    LIMIT 1
                ");
                $duplicateStmt->execute([$customerId, $duplicateKey]);
                if ($duplicateStmt->fetchColumn()) {
                    throw new DomainException('DUPLICATE_DISCOUNT_ID');
                }

                $uploadDirectory = dirname(__DIR__) . '/storage/discount-ids/';
                if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
                    throw new RuntimeException('Unable to prepare the ID photo storage directory.');
                }

                /* Keep private ID photos blocked from direct Apache access. */
                $htaccessPath = $uploadDirectory . '.htaccess';
                if (!is_file($htaccessPath)) {
                    $htaccessWritten = @file_put_contents(
                        $htaccessPath,
                        "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
                        LOCK_EX
                    );
                    if ($htaccessWritten === false) {
                        throw new RuntimeException('Unable to secure the ID photo storage directory.');
                    }
                }

                $photoMime = (new finfo(FILEINFO_MIME_TYPE))->file($idPhoto['tmp_name']);
                $photoExtension = $photoMime === 'image/png' ? 'png' : 'jpg';
                $photoFilename = 'discountid_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $photoExtension;
                $newPhotoFullPath = $uploadDirectory . $photoFilename;
                $newPhotoRelative = 'storage/discount-ids/' . $photoFilename;

                if (!move_uploaded_file($idPhoto['tmp_name'], $newPhotoFullPath)) {
                    throw new RuntimeException('Unable to save the ID photo. Please try again.');
                }

                $oldPhotoRelative = (string)($lockedCustomer['discount_id_image'] ?? '');
                $updateSql = "
                    UPDATE customers
                    SET
                        discount_type = ?,
                        discount_id_name = ?,
                        discount_id_number = ?,
                        discount_id_image = ?,
                        verification_status = 'pending',
                        verification_rejection_reason = NULL,
                        verified_by = NULL,
                        verified_at = NULL
                ";
                if ($hasDiscountArchiveColumn) {
                    $updateSql .= ", discount_id_archived = 0\n";
                }
                $updateSql .= "WHERE id = ? AND verification_status IN ('none', 'rejected')";

                $updateStmt = $pdo->prepare($updateSql);
                $updateStmt->execute([
                    $discountFormType,
                    $discountFormName,
                    $discountFormNumber,
                    $newPhotoRelative,
                    $customerId
                ]);

                if ($updateStmt->rowCount() !== 1) {
                    throw new RuntimeException('APPLICATION_STATE_CHANGED');
                }

                $pdo->commit();

                /* Remove the previous rejected photo only after the new record is safely saved. */
                if (
                    $oldPhotoRelative !== '' &&
                    strpos($oldPhotoRelative, 'storage/discount-ids/') === 0
                ) {
                    $oldPhotoPath = dirname(__DIR__) . '/storage/discount-ids/' . basename($oldPhotoRelative);
                    if (is_file($oldPhotoPath) && $oldPhotoPath !== $newPhotoFullPath) {
                        @unlink($oldPhotoPath);
                    }
                }

                $successMessage = 'Your ID application was submitted successfully and is now waiting for admin review. Your discount will remain unavailable until it is approved.';
                $discountFormType = '';
                $discountFormName = '';
                $discountFormNumber = '';

                $customerStmt->execute([$customerId]);
                $customer = $customerStmt->fetch(PDO::FETCH_ASSOC);
            } catch (DomainException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($newPhotoFullPath && is_file($newPhotoFullPath)) {
                    @unlink($newPhotoFullPath);
                }
                if ($e->getMessage() === 'DUPLICATE_DISCOUNT_ID') {
                    $errors[] = 'This ID number is already associated with another customer account.';
                } else {
                    error_log('Discount application failed: ' . $e->getMessage());
                    $errors[] = 'Unable to submit your ID application right now. Please try again.';
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($newPhotoFullPath && is_file($newPhotoFullPath)) {
                    @unlink($newPhotoFullPath);
                }
                error_log('Discount application failed: ' . $e->getMessage());
                if ($e->getMessage() === 'APPLICATION_STATE_CHANGED') {
                    $errors[] = 'Your application status has changed. Refresh the page to see the latest status.';
                } else {
                    $errors[] = 'Unable to submit your ID application right now. Please try again.';
                }
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

.profile-grid > .customer-profile-card:first-child{grid-column:1;grid-row:1}
.discount-card{grid-column:1 / -1;grid-row:2}
.profile-grid > .customer-profile-card:last-child:not(.discount-card){grid-column:2;grid-row:1}
.discount-status{display:flex;align-items:flex-start;gap:10px;padding:13px 14px;border-radius:12px;margin-bottom:16px;font-size:.84rem;line-height:1.45}
.discount-status i{font-size:1.05rem;flex:0 0 auto;margin-top:1px}
.discount-status--none{background:#FDF8F2;border:1px solid #E9DED2;color:#5E4A3A}
.discount-status--pending{background:#FFF7E6;border:1px solid #EED9A8;color:#805400}
.discount-status--verified{background:#EAF5EE;border:1px solid #BFE0CB;color:#1F6B3F}
.discount-status--rejected{background:#FDEDED;border:1px solid #EDBDBD;color:#8B3030}
.discount-help{font-size:.76rem;color:#82766D;line-height:1.45}
.discount-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.discount-form-grid .full-width{grid-column:1 / -1}
.discount-status-meta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:12px}
@media(max-width:576px){.discount-form-grid,.discount-status-meta{grid-template-columns:1fr}.discount-form-grid .full-width{grid-column:auto}}
@media(max-width:991.98px){.profile-grid{grid-template-columns:1fr}.profile-grid > .customer-profile-card{grid-column:1;grid-row:auto}.customer-profile-page{padding:26px 0 42px}}
@media(max-width:576px){.customer-profile-page{padding:20px 12px 38px}.profile-heading h2{font-size:1.35rem}.customer-profile-card-body{padding:15px}.customer-profile-card-header{padding:15px}.profile-meta{grid-template-columns:1fr}.btn-brown{width:100%}}
</style>

<main class="customer-profile-page">
    <div class="container">
        <div class="profile-heading">
            <h2><i class="bi bi-person-circle me-2"></i>My Profile</h2>
            <p>Manage your personal information, account password, and discount verification.</p>
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
                        <input type="hidden" name="profile_csrf" value="<?= htmlspecialchars($profileCsrf, ENT_QUOTES, 'UTF-8') ?>">
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

            <?php
                $discountStatus = strtolower(trim((string)($customer['verification_status'] ?? 'none')));
                if (!in_array($discountStatus, ['none', 'pending', 'verified', 'rejected'], true)) {
                    $discountStatus = 'none';
                }
                $discountTypeLabel = ($customer['discount_type'] ?? '') === 'pwd'
                    ? 'PWD'
                    : (($customer['discount_type'] ?? '') === 'senior' ? 'Senior Citizen' : '');
                $maskedDiscountId = (string)($customer['discount_id_number'] ?? '');
                if ($maskedDiscountId !== '' && strlen($maskedDiscountId) > 4) {
                    $maskedDiscountId = str_repeat('•', max(0, strlen($maskedDiscountId) - 4)) . substr($maskedDiscountId, -4);
                }
            ?>
            <section class="customer-profile-card discount-card">
                <div class="customer-profile-card-header">
                    <h5><i class="bi bi-patch-check me-1"></i> PWD / Senior Citizen Discount</h5>
                    <p>Submit your valid ID for admin review. Your customer account remains usable while your application is being reviewed.</p>
                </div>
                <div class="customer-profile-card-body">
                    <?php if ($discountStatus === 'verified'): ?>
                        <div class="discount-status discount-status--verified">
                            <i class="bi bi-check-circle-fill"></i>
                            <div>
                                <strong>Your ID has been approved.</strong><br>
                                Your <?= htmlspecialchars($discountTypeLabel, ENT_QUOTES, 'UTF-8') ?> discount eligibility is verified. You may use the discount according to the store's discount policy.
                            </div>
                        </div>
                        <div class="discount-status-meta">
                            <div class="meta-box"><div class="meta-label">Discount Type</div><div class="meta-value"><?= htmlspecialchars($discountTypeLabel, ENT_QUOTES, 'UTF-8') ?></div></div>
                            <div class="meta-box"><div class="meta-label">ID Number</div><div class="meta-value"><?= htmlspecialchars($maskedDiscountId, ENT_QUOTES, 'UTF-8') ?></div></div>
                        </div>
                    <?php elseif ($discountStatus === 'pending'): ?>
                        <div class="discount-status discount-status--pending">
                            <i class="bi bi-clock-history"></i>
                            <div>
                                <strong>Your ID application is pending review.</strong><br>
                                You can continue using your account and placing regular orders. The PWD / Senior Citizen discount will not be available until the admin approves your ID.
                            </div>
                        </div>
                        <div class="discount-status-meta">
                            <div class="meta-box"><div class="meta-label">Discount Type</div><div class="meta-value"><?= htmlspecialchars($discountTypeLabel, ENT_QUOTES, 'UTF-8') ?></div></div>
                            <div class="meta-box"><div class="meta-label">Name on ID</div><div class="meta-value"><?= htmlspecialchars((string)$customer['discount_id_name'], ENT_QUOTES, 'UTF-8') ?></div></div>
                            <div class="meta-box"><div class="meta-label">ID Number</div><div class="meta-value"><?= htmlspecialchars($maskedDiscountId, ENT_QUOTES, 'UTF-8') ?></div></div>
                            <div class="meta-box"><div class="meta-label">Status</div><div class="meta-value">Pending verification</div></div>
                        </div>
                    <?php elseif ($discountStatus === 'rejected'): ?>
                        <div class="discount-status discount-status--rejected">
                            <i class="bi bi-exclamation-circle-fill"></i>
                            <div>
                                <strong>Your previous ID application was rejected.</strong><br>
                                <?= htmlspecialchars((string)($customer['verification_rejection_reason'] ?? 'Please submit a corrected ID photo or details.'), ENT_QUOTES, 'UTF-8') ?>
                                <div class="mt-1">You may submit a corrected application below. Your discount remains unavailable until it is approved.</div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="discount-status discount-status--none">
                            <i class="bi bi-info-circle-fill"></i>
                            <div>
                                <strong>You have not submitted a discount ID.</strong><br>
                                This application is optional. You can submit your PWD or Senior Citizen ID now or continue ordering without a discount and apply later.
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (in_array($discountStatus, ['none', 'rejected'], true)): ?>
                        <form method="POST" enctype="multipart/form-data" autocomplete="off">
                            <input type="hidden" name="profile_csrf" value="<?= htmlspecialchars($profileCsrf, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="profile_action" value="discount_application">
                            <div class="discount-form-grid">
                                <div>
                                    <label class="field-label" for="profileDiscountType">Discount Type</label>
                                    <select id="profileDiscountType" name="discount_type" class="form-select custom-input" required>
                                        <option value="">Choose one</option>
                                        <option value="pwd" <?= $discountFormType === 'pwd' ? 'selected' : (($discountStatus === 'rejected' && ($customer['discount_type'] ?? '') === 'pwd') ? 'selected' : '') ?>>PWD</option>
                                        <option value="senior" <?= $discountFormType === 'senior' ? 'selected' : (($discountStatus === 'rejected' && ($customer['discount_type'] ?? '') === 'senior') ? 'selected' : '') ?>>Senior Citizen</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="field-label" for="profileDiscountName">Name on ID</label>
                                    <input type="text" id="profileDiscountName" name="discount_id_name" class="form-control custom-input" maxlength="100" placeholder="Full name shown on your ID" value="<?= htmlspecialchars($discountFormName !== '' ? $discountFormName : ($discountStatus === 'rejected' ? (string)($customer['discount_id_name'] ?? '') : ''), ENT_QUOTES, 'UTF-8') ?>" required>
                                </div>
                                <div>
                                    <label class="field-label" for="profileDiscountNumber">ID Number</label>
                                    <input type="text" id="profileDiscountNumber" name="discount_id_number" class="form-control custom-input" maxlength="30" placeholder="Enter your ID number" value="<?= htmlspecialchars($discountFormNumber !== '' ? $discountFormNumber : ($discountStatus === 'rejected' ? (string)($customer['discount_id_number'] ?? '') : ''), ENT_QUOTES, 'UTF-8') ?>" required>
                                    <div class="discount-help mt-1" id="profileDiscountNumberHint">Select PWD or Senior Citizen to see the accepted ID number format.</div>
                                </div>
                                <div>
                                    <label class="field-label" for="profileDiscountPhoto">Photo of ID</label>
                                    <input type="file" id="profileDiscountPhoto" name="discount_id_image" class="form-control custom-input" accept=".jpg,.jpeg,.png,image/jpeg,image/png" required>
                                    <div class="discount-help mt-1">Upload a clear JPG or PNG image. Maximum file size: 5 MB.</div>
                                </div>
                                <div class="full-width d-flex justify-content-end mt-1">
                                    <button type="submit" class="btn btn-brown"><i class="bi bi-upload me-1"></i><?= $discountStatus === 'rejected' ? 'Resubmit ID for Review' : 'Submit ID for Verification' ?></button>
                                </div>
                            </div>
                        </form>
                    <?php endif; ?>
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
                        <input type="hidden" name="profile_csrf" value="<?= htmlspecialchars($profileCsrf, ENT_QUOTES, 'UTF-8') ?>">
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

<script>
document.addEventListener('DOMContentLoaded', function () {
    const type = document.getElementById('profileDiscountType');
    const number = document.getElementById('profileDiscountNumber');
    const hint = document.getElementById('profileDiscountNumberHint');
    const photo = document.getElementById('profileDiscountPhoto');

    function updateDiscountHint() {
        if (!type || !number || !hint) return;
        if (type.value === 'pwd') {
            number.placeholder = '16-digit PWD ID number';
            number.maxLength = 16;
            hint.textContent = 'PWD ID number must contain exactly 16 digits.';
        } else if (type.value === 'senior') {
            number.placeholder = 'Senior Citizen ID number';
            number.maxLength = 30;
            hint.textContent = 'Use the letters, numbers, spaces, or dashes shown on your ID.';
        } else {
            number.placeholder = 'Enter your ID number';
            number.maxLength = 30;
            hint.textContent = 'Select PWD or Senior Citizen to see the accepted ID number format.';
        }
    }

    if (type) type.addEventListener('change', updateDiscountHint);
    updateDiscountHint();

    if (photo) {
        photo.addEventListener('change', function () {
            const file = this.files && this.files[0];
            if (!file) return;
            if (file.size > 5 * 1024 * 1024) {
                this.value = '';
                alert('The ID photo must not exceed 5MB.');
                return;
            }
            if (!['image/jpeg', 'image/png'].includes(file.type)) {
                this.value = '';
                alert('Only JPG, JPEG, or PNG images are allowed.');
            }
        });
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>
