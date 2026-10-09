<?php
require_once '../includes/db.php';
require_once '../includes/mailer.php';

/*
|--------------------------------------------------------------------------
| PWD / SENIOR ID PHOTO HELPERS
|--------------------------------------------------------------------------
| Photos are saved in storage/discount-ids/ (blocked from direct URL
| access). Admin views them through admin/view-discount-id.php.
*/

/* Returns '' when the upload is acceptable, otherwise an error message. */
function validateDiscountIdPhoto(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return "Please upload a clear photo of your ID.";
    }

    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        return "The ID photo must not exceed 5MB.";
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);

    if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
        return "Invalid ID photo. Only JPG, JPEG, or PNG files are allowed.";
    }

    return '';
}

/* Moves a validated upload into storage. Returns the relative path or null. */
function saveDiscountIdPhoto(array $file): ?string
{
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $extension = $finfo->file($file['tmp_name']) === 'image/png' ? 'png' : 'jpg';

    $directory = dirname(__DIR__) . '/storage/discount-ids/';

    if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
        return null;
    }

    /* Block direct access (Apache / XAMPP). */
    $htaccess = $directory . '.htaccess';

    if (!is_file($htaccess)) {
        @file_put_contents(
            $htaccess,
            "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"
        );
    }

    $filename = 'discountid_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;

    if (!move_uploaded_file($file['tmp_name'], $directory . $filename)) {
        return null;
    }

    return 'storage/discount-ids/' . $filename;
}

$error = '';
$success = '';
$duplicateDiscountId = false;
$password_error = '';
$confirm_error = '';

$name = '';
$email = '';
$mobile = '';
$password = '';
$confirm_password = '';

$wantsDiscount = false;
$discount_type = '';
$discount_id_name = '';
$discount_id_number = '';
$discount_id_image = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $mobile = trim($_POST['mobile'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    /* Optional: PWD / Senior Citizen details (one-time verification). */
    $wantsDiscount = ($_POST['has_discount'] ?? '') === '1';
    $discount_type = strtolower(trim((string)($_POST['discount_type'] ?? '')));
    $discount_id_name = trim(preg_replace('/\s+/', ' ', (string)($_POST['discount_id_name'] ?? '')));
    $discount_id_number = strtoupper(trim((string)($_POST['discount_id_number'] ?? '')));

    if ($name === '' || $email === '' || $mobile === '' || $password === '') {

        $error = "Please fill in all required fields.";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = "Please enter a valid email address.";

    } elseif (!preg_match('/^09\d{9}$/', $mobile)) {

        $error = "Please enter a valid 11-digit Philippine mobile number starting with 09.";

    } elseif (strlen($password) < 8) {

        $password_error = "Password must be at least 8 characters long.";

    } elseif ($password !== $confirm_password) {

        $confirm_error = "Passwords do not match.";

    } elseif ($wantsDiscount && !in_array($discount_type, ['pwd', 'senior'], true)) {

        $error = "Please choose PWD or Senior Citizen.";

    } elseif ($wantsDiscount && ($discount_id_name === '' || mb_strlen($discount_id_name) > 100)) {

        $error = "Please enter the name shown on your ID.";

    } elseif ($wantsDiscount && !preg_match('/^[A-Z0-9][A-Z0-9\-\/ ]{2,29}$/', $discount_id_number)) {

        $error = "Please enter a valid ID number (letters, numbers and dashes only).";

    } elseif (
        $wantsDiscount &&
        ($photoError = validateDiscountIdPhoto($_FILES['discount_id_image'] ?? [])) !== ''
    ) {

        $error = $photoError;

    } else {

        $stmt = $pdo->prepare("
            SELECT id
            FROM customers
            WHERE email = ?
            LIMIT 1
        ");

        $stmt->execute([$email]);

        if ($stmt->fetch()) {

            $error = "Email already registered.";

        } else {

            $hashed_password = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            // Generate secure verification token
            $token = bin2hex(random_bytes(32));

            // Store only the hash in the database
            $tokenHash = hash('sha256', $token);

            // Token expires after 30 minutes
            $expiresAt = date(
                'Y-m-d H:i:s',
                time() + (30 * 60)
            );

            try {

                // A previously submitted ID cannot be reused for discount eligibility.
                // Registration itself must still proceed as a regular customer.
                if ($wantsDiscount) {
                    $duplicateCheck = $pdo->prepare("
                        SELECT id FROM customers
                        WHERE UPPER(TRIM(discount_id_number)) = ?
                        LIMIT 1
                    ");
                    $duplicateCheck->execute([$discount_id_number]);
                    $duplicateDiscountId = (bool)$duplicateCheck->fetchColumn();
                }

                if ($wantsDiscount && !$duplicateDiscountId) {

                    $discount_id_image = saveDiscountIdPhoto($_FILES['discount_id_image']);

                    if ($discount_id_image === null) {
                        throw new RuntimeException('ID_UPLOAD');
                    }
                }

                $pdo->beginTransaction();

                $ins = $pdo->prepare("
                    INSERT INTO customers (
                        full_name,
                        email,
                        contact_number,
                        password,
                        email_verified_at,
                        verification_token,
                        verification_expires_at
                    )
                    VALUES (?, ?, ?, ?, NULL, ?, ?)
                ");

                $ins->execute([
                    $name,
                    $email,
                    $mobile,
                    $hashed_password,
                    $tokenHash,
                    $expiresAt
                ]);

                /* Save the PWD / Senior details. Status stays "pending" until an admin approves it. */
                if ($wantsDiscount && !$duplicateDiscountId && $discount_id_image !== null) {

                    $pdo->prepare("
                        UPDATE customers
                        SET
                            discount_type = ?,
                            discount_id_name = ?,
                            discount_id_number = ?,
                            discount_id_image = ?,
                            verification_status = 'pending'
                        WHERE id = ?
                    ")->execute([
                        $discount_type,
                        $discount_id_name,
                        $discount_id_number,
                        $discount_id_image,
                        (int)$pdo->lastInsertId()
                    ]);
                }

                /*
                 * Build the verification URL dynamically from the current
                 * request instead of hardcoding a host/port, so it keeps
                 * working whether the app is served via XAMPP on port 80,
                 * `php -S localhost:PORT`, or anything else.
                 */
                $protocol = (
                    !empty($_SERVER['HTTPS']) &&
                    $_SERVER['HTTPS'] !== 'off'
                ) ? 'https' : 'http';

                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

                // e.g. "/auth/register.php" or
                // "/Localitea_Fixed Best Sellers/auth/register.php"
                $scriptDir = dirname(dirname($_SERVER['SCRIPT_NAME']));

                // On Windows, dirname() can return "\" instead of "/"
                // once it collapses down to the root. Normalize before
                // it goes into a URL.
                $scriptDir = str_replace('\\', '/', $scriptDir);

                $baseUrl = $protocol . '://' . $host . rtrim($scriptDir, '/');

                $verificationLink =
                    $baseUrl . '/auth/verify-email.php?token='
                    . urlencode($token);

                // Send verification email
                sendVerificationEmail(
                    $email,
                    $name,
                    $verificationLink
                );

                $pdo->commit();

                $success =
                    "Registration successful! Please check your email and click the verification link to activate your account.";

            } catch (Throwable $e) {
                // Roll back account creation if any part of registration fails.
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                // Remove an uploaded ID photo if its database record was not saved.
                if (
                    $discount_id_image !== null &&
                    is_string($discount_id_image) &&
                    $discount_id_image !== ''
                ) {
                    $orphanedPhoto = dirname(__DIR__) . '/' . ltrim($discount_id_image, '/');
                    if (is_file($orphanedPhoto)) {
                        @unlink($orphanedPhoto);
                    }
                }

                // Keep technical details in the server log, not in the customer-facing message.
                error_log(
                    'Localitea registration/verification email failed: ' .
                    $e->getMessage()
                );

                $error =
                    'Registration failed because the verification email could not be sent. Please try again.';
            }
        }
    }
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
?>

<style>

body {
    background: #FFFFFF;
    color: #2C221E;
    overflow-x: hidden;
}

.register-page {
    width: 100%;
    min-height: calc(100vh - 140px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 40px 20px 48px;
    box-sizing: border-box;
    background: #FFFFFF;
}

.register-card {
    width: 100%;
    max-width: 920px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    background: #FFFFFF;
    border: 1px solid #E4D7CB;
    border-radius: 18px;
    box-shadow: 0 14px 40px rgba(44, 34, 30, 0.10);
    overflow: hidden;
}

/* ---------- Left brand panel ---------- */
.register-visual {
    position: relative;
    display: flex;
    flex-direction: column;
    justify-content: center;
    padding: 48px 40px;
    color: #FFFFFF;
    overflow: hidden;
    background:
        radial-gradient(ellipse 80% 55% at 18% 12%, rgba(255, 255, 255, 0.16), transparent 62%),
        radial-gradient(ellipse 70% 50% at 88% 42%, rgba(111, 78, 55, 0.42), transparent 64%),
        radial-gradient(ellipse 90% 60% at 30% 95%, rgba(196, 164, 132, 0.28), transparent 62%),
        linear-gradient(160deg, #8B6B52, #6F4E37);
}

/* Fine fibre-like strokes to give the panel texture */
.register-visual::before {
    content: "";
    position: absolute;
    inset: 0;
    background:
        repeating-conic-gradient(
            from 200deg at 8% 105%,
            rgba(255, 255, 255, 0.05) 0deg 1.5deg,
            transparent 1.5deg 5deg
        );
    pointer-events: none;
}

.register-visual > * {
    position: relative;
    z-index: 1;
}

.register-logo {
    position: absolute !important;
    top: 28px;
    left: 32px;
    width: 52px;
    height: 52px;
    object-fit: contain;
    border-radius: 50%;
    background: #FFFFFF;
    border: 2px solid rgba(255, 255, 255, 0.85);
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.25);
}

.register-visual-title {
    margin: 0 0 8px;
    font-size: 2rem;
    font-weight: 800;
    line-height: 1.15;
    letter-spacing: 0.2px;
}

.register-visual-text {
    margin: 0;
    max-width: 260px;
    font-size: 1.02rem;
    line-height: 1.45;
    color: rgba(255, 255, 255, 0.88);
}

/* ---------- Right form panel ---------- */
.register-form-panel {
    display: flex;
    flex-direction: column;
    justify-content: center;
    padding: 40px 48px;
    box-sizing: border-box;
    background: #FFFFFF;
}

.register-title {
    color: #2C221E;
    font-size: 1.7rem;
    font-weight: 800;
    letter-spacing: 0.2px;
    text-align: center;
    margin: 0 0 24px;
}

.register-form-label {
    display: block;
    color: #4A3525;
    font-size: 0.74rem;
    font-weight: 700;
    margin-bottom: 5px;
}

.register-input {
    width: 100%;
    min-height: 40px;
    border: 1.5px solid #E4D7CB;
    border-radius: 7px;
    background: #FFFFFF;
    color: #2C221E;
    padding: 8px 12px;
    font-size: 0.82rem;
    box-shadow: 0 1px 2px rgba(74, 53, 37, 0.05);
    transition: border-color .2s ease, box-shadow .2s ease;
    box-sizing: border-box;
}

.register-input::placeholder {
    color: #A89D95;
}

.register-input:hover {
    border-color: #B8A08A;
}

.register-input:focus {
    border-color: #6F4E37;
    box-shadow: 0 0 0 3px rgba(111, 78, 55, 0.13);
    outline: none;
}

.register-input.field-error {
    border-color: #B85C5C;
    box-shadow: 0 0 0 3px rgba(184, 92, 92, 0.10);
}

.register-field-error {
    display: flex;
    align-items: flex-start;
    gap: 5px;
    margin-top: 6px;
    color: #8B3030;
    font-size: 0.72rem;
    font-weight: 600;
    line-height: 1.35;
}

.register-terms-error {
    margin-top: -4px;
    margin-bottom: 12px;
}

.register-field-error[hidden] {
    display: none;
}

.register-field-error i {
    font-size: 0.8rem;
    line-height: 1.3;
}

.register-input.field-success {
    border-color: #6F4E37;
}

/* Password input with show/hide button */
.password-field {
    position: relative;
    width: 100%;
}

.password-field .register-input {
    padding-right: 42px;
}

.password-toggle {
    position: absolute;
    top: 50%;
    right: 6px;
    width: 32px;
    height: 32px;
    transform: translateY(-50%);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 0;
    border-radius: 50%;
    background: transparent;
    color: #6F4E37;
    padding: 0;
    cursor: pointer;
}

.password-toggle:hover {
    background: #F7F1E8;
    color: #4A3525;
}

.password-toggle:focus-visible {
    outline: 2px solid #6F4E37;
    outline-offset: 2px;
}

.password-toggle i {
    font-size: 0.92rem;
}

/* PWD / Senior note */
.register-discount-note {
    color: #756960;
    font-size: 0.7rem;
    line-height: 1.4;
}

.register-input[type="file"] {
    padding: 6px 10px;
}

.register-discount-note {
    margin: 0;
}

.register-discount-summary {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: -2px 0 12px;
    padding: 8px 10px;
    border: 1px solid #E4D7CB;
    border-radius: 8px;
    background: #FBF8F4;
    color: #4A3525;
    font-size: 0.74rem;
}

.register-discount-summary[hidden] {
    display: none;
}

.register-discount-summary i {
    color: #2F7D4F;
}

.register-discount-summary-text {
    flex: 1 1 auto;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-weight: 600;
}

.register-discount-edit {
    border: 0;
    background: transparent;
    color: #6F4E37;
    font-size: 0.74rem;
    font-weight: 700;
    padding: 2px 6px;
    cursor: pointer;
}

.register-discount-edit:hover {
    color: #4A3525;
    text-decoration: underline;
}

.register-policy-dialog.register-discount-dialog {
    width: min(460px, 100%);
    height: auto;
    max-height: 90vh;
}

.register-discount-footer {
    display: flex;
    gap: 10px;
    flex: 0 0 auto;
    padding: 14px 20px 18px;
    border-top: 1px solid #E4D7CB;
    background: #FBF8F4;
}

.register-discount-footer > * {
    flex: 1 1 0;
    width: auto;
}

/* Terms */
.register-terms {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    color: #756960;
    font-size: 0.72rem;
    line-height: 1.4;
}

.register-terms .form-check-input {
    flex: 0 0 auto;
    width: 15px;
    height: 15px;
    margin-top: 1px;
    border: 1.5px solid #B8A08A;
    box-shadow: none;
}

.register-terms .form-check-input:checked {
    background-color: #6F4E37;
    border-color: #6F4E37;
}

.register-terms .form-check-input:focus {
    border-color: #6F4E37;
    box-shadow: 0 0 0 3px rgba(111, 78, 55, 0.12);
}

.register-terms .form-check-input.field-error {
    border-color: #B85C5C;
}

.register-terms a {
    color: #6F4E37;
    font-weight: 700;
    text-decoration: none;
}

.register-terms a:hover {
    color: #4A3525;
    text-decoration: underline;
}

/* Primary button */
.btn-register {
    width: 100%;
    min-height: 40px;
    background: #6F4E37;
    border: 1.5px solid #6F4E37;
    border-radius: 7px;
    color: #FFFFFF;
    padding: 8px 16px;
    font-size: 0.82rem;
    font-weight: 700;
    letter-spacing: 0.2px;
    transition: background-color .2s ease, box-shadow .2s ease;
}

.btn-register:hover {
    background: #4A3525;
    border-color: #4A3525;
    color: #FFFFFF;
}

.btn-register:focus {
    background: #6F4E37;
    border-color: #6F4E37;
    color: #FFFFFF;
    box-shadow: 0 0 0 3px rgba(111, 78, 55, 0.16);
}

/* "or" divider */
.register-divider {
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 14px 0;
    color: #A89D95;
    font-size: 0.7rem;
}

.register-divider::before,
.register-divider::after {
    content: "";
    flex: 1;
    height: 1px;
    background: #E4D7CB;
}

/* Secondary (log in) button */
.btn-register-secondary {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    min-height: 40px;
    background: #FFFFFF;
    border: 1.5px solid #E4D7CB;
    border-radius: 7px;
    color: #2C221E;
    padding: 8px 16px;
    font-size: 0.8rem;
    font-weight: 700;
    text-decoration: none;
    box-shadow: 0 2px 6px rgba(74, 53, 37, 0.10);
    transition: border-color .2s ease, background-color .2s ease;
    box-sizing: border-box;
}

.btn-register-secondary:hover {
    background: #FBF8F4;
    border-color: #6F4E37;
    color: #4A3525;
}

.btn-register-secondary:focus-visible {
    outline: 2px solid #6F4E37;
    outline-offset: 2px;
}

/* =========================================================
   LOCALITEA FORM ALERT / TOAST
========================================================= */

.register-toast-container {
    position: fixed;
    right: 20px;
    top: 20px;
    width: min(340px, calc(100vw - 40px));
    z-index: 2000;
    pointer-events: none;
}

.register-toast {
    position: relative;
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    min-height: 54px;
    padding: 11px 12px;
    border: 1px solid #6F4E37;
    border-left: 4px solid #4A3525;
    border-radius: 11px;
    background: #FFFFFF;
    color: #2C221E;
    box-shadow: 0 8px 24px rgba(44, 34, 30, .16);
    pointer-events: auto;
    overflow: hidden;
}

.register-toast-success {
    border-left-color: #6F4E37;
}

.register-toast-error {
    border-left-color: #8B3030;
}

.register-toast-icon {
    width: 30px;
    min-width: 30px;
    height: 30px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: #F7F1E8;
    color: #6F4E37;
    font-size: 0.9rem;
}

.register-toast-error .register-toast-icon {
    background: #FFF0F0;
    color: #8B3030;
}

.register-toast-message {
    flex: 1 1 auto;
    min-width: 0;
    font-size: 0.76rem;
    font-weight: 600;
    line-height: 1.35;
    overflow-wrap: anywhere;
}

.register-toast-close {
    width: 28px;
    min-width: 28px;
    height: 28px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 0;
    border-radius: 50%;
    background: transparent;
    color: #8A7F75;
    padding: 0;
    cursor: pointer;
}

.register-toast-close:hover {
    background: #F7F1E8;
    color: #4A3525;
}

.register-toast-progress {
    position: absolute;
    left: 0;
    bottom: 0;
    height: 2px;
    width: 100%;
    background: #6F4E37;
    transform-origin: left;
}

.register-toast-error .register-toast-progress {
    background: #8B3030;
}

/* =========================================================
   TABLET — tighter padding, narrower card
========================================================= */
@media (max-width: 991.98px) {
    .register-page {
        min-height: calc(100vh - 92px);
        padding: 24px 16px 32px;
        align-items: flex-start;
        background: #FFFFFF;
    }

    /* Hide the brown welcome panel on tablet and mobile */
    .register-visual {
        display: none;
    }

    .register-card {
        display: block;
        max-width: 560px;
        border-radius: 16px;
        box-shadow: 0 10px 30px rgba(44, 34, 30, 0.08);
    }

    .register-form-panel {
        width: 100%;
        padding: 34px 38px 38px;
    }
}

/* =========================================================
   PHONES — brand panel becomes a compact banner on top
========================================================= */
@media (max-width: 767.98px) {
    .register-page {
        padding: 18px 12px 28px;
    }

    .register-card {
        max-width: 460px;
        border-radius: 15px;
    }

    .register-form-panel {
        padding: 26px 20px 28px;
    }

    .register-title {
        font-size: 1.4rem;
        margin-bottom: 20px;
    }

    .register-toast-container {
        right: 12px;
        top: 12px;
        width: calc(100vw - 24px);
    }
}

/* =========================================================
   SMALL PHONES
========================================================= */
@media (max-width: 360px) {
    .register-page {
        padding-left: 9px;
        padding-right: 9px;
    }

    .register-form-panel {
        padding: 22px 15px 24px;
    }

    .register-input {
        min-height: 38px;
        font-size: 0.8rem;
        padding: 7px 10px;
    }

    .register-terms {
        font-size: 0.7rem;
    }
}

/* =========================================================
   COMPACT LAYOUT — tablet/desktop: no scrollbar inside the card.
   The card grows with its content and stays centered.
   If your navbar is taller/shorter than 76px, change --nav-h.
   (Phones keep normal scrolling because the form can't fit.)
========================================================= */
:root {
    --nav-h: 76px;
}

@media (min-width: 768px) {
    .register-page {
        height: auto;
        min-height: calc(100vh - var(--nav-h));
        min-height: calc(100dvh - var(--nav-h));
        padding: 12px 20px;
        overflow: visible;
    }

    .register-card {
        height: auto;
        max-height: none;
    }

    .register-form-panel {
        padding: 20px 48px;
        overflow: visible;
    }

    .register-title {
        font-size: 1.55rem;
        margin-bottom: 16px;
    }

    .register-form-panel .mb-3 {
        margin-bottom: 9px !important;
    }

    .register-input {
        min-height: 36px;
    }
}

/* Short laptop screens */
@media (min-width: 768px) and (max-height: 720px) {
    .register-form-panel {
        padding: 18px 40px;
    }

    .register-title {
        font-size: 1.35rem;
        margin-bottom: 12px;
    }

    .register-form-panel .mb-3 {
        margin-bottom: 8px !important;
    }

    .register-form-label {
        margin-bottom: 3px;
    }

    .register-input {
        min-height: 34px;
    }

    .btn-register,
    .btn-register-secondary {
        min-height: 36px;
    }

    .register-divider {
        margin: 10px 0;
    }
}
</style>

<div class="register-page">
    <div class="register-card">

        <!-- Left: brand panel -->
        <aside class="register-visual">

            <img
                src="../assets/images/logo.png"
                alt="Local Milktea House Logo"
                class="register-logo"
            >

            <h2 class="register-visual-title">
                Create your<br>Account
            </h2>

            <p class="register-visual-text">
                Order ahead and pick up your favorite milk tea at the store.
            </p>

        </aside>

        <!-- Right: form -->
        <section class="register-form-panel">

            <h1 class="register-title">Sign Up</h1>

            <form id="registerForm" method="POST" enctype="multipart/form-data" novalidate>

                <div class="mb-3">
                    <label
                        for="registerName"
                        class="register-form-label"
                    >
                        Full Name
                    </label>

                    <input
                        type="text"
                        id="registerName"
                        name="name"
                        class="register-input"
                        placeholder="Juan Dela Cruz"
                        autocomplete="name"
                        maxlength="100"
                        value="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"
                        required
                    >
                </div>

                <div class="mb-3">
                    <label
                        for="registerEmail"
                        class="register-form-label"
                    >
                        Email address
                    </label>

                    <input
                        type="email"
                        id="registerEmail"
                        name="email"
                        class="register-input"
                        placeholder="juan@email.com"
                        autocomplete="email"
                        maxlength="150"
                        value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"
                        required
                    >
                </div>

                <div class="mb-3">
                    <label
                        for="registerMobile"
                        class="register-form-label"
                    >
                        Mobile Number
                    </label>

                    <input
                        type="tel"
                        id="registerMobile"
                        name="mobile"
                        class="register-input"
                        placeholder="09XXXXXXXXX"
                        autocomplete="tel"
                        inputmode="numeric"
                        maxlength="11"
                        value="<?= htmlspecialchars($mobile, ENT_QUOTES, 'UTF-8') ?>"
                        required
                    >
                </div>

                <div class="mb-3">
                    <label
                        for="password"
                        class="register-form-label"
                    >
                        Password
                    </label>

                    <div class="password-field">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="register-input<?= $password_error ? ' field-error' : '' ?>"
                            <?= $password_error ? 'aria-invalid="true"' : '' ?>
                            placeholder="••••••••"
                            autocomplete="new-password"
                            minlength="8"
                            required
                        >
                        <button
                            type="button"
                            class="password-toggle"
                            data-password-target="password"
                            aria-label="Show password"
                            aria-pressed="false"
                        >
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>

                    <div
                        class="register-field-error"
                        id="passwordError"
                        role="alert"
                        <?= $password_error ? '' : 'hidden' ?>
                    >
                        <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
                        <span><?= htmlspecialchars($password_error, ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                </div>

                <div class="mb-3">
                    <label
                        for="confirm_password"
                        class="register-form-label"
                    >
                        Confirm Password
                    </label>

                    <div class="password-field">
                        <input
                            type="password"
                            id="confirm_password"
                            name="confirm_password"
                            class="register-input<?= $confirm_error ? ' field-error' : '' ?>"
                            <?= $confirm_error ? 'aria-invalid="true"' : '' ?>
                            placeholder="••••••••"
                            autocomplete="new-password"
                            minlength="8"
                            required
                        >
                        <button
                            type="button"
                            class="password-toggle"
                            data-password-target="confirm_password"
                            aria-label="Show confirm password"
                            aria-pressed="false"
                        >
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>

                    <div
                        class="register-field-error"
                        id="confirmPasswordError"
                        role="alert"
                        <?= $confirm_error ? '' : 'hidden' ?>
                    >
                        <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
                        <span><?= htmlspecialchars($confirm_error, ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                </div>

                <!-- PWD / Senior Citizen (optional, one-time verification) -->
                <div class="mb-3 register-terms">
                    <input
                        type="checkbox"
                        class="form-check-input"
                        id="registerHasDiscount"
                        name="has_discount"
                        value="1"
                        <?= $wantsDiscount ? 'checked' : '' ?>
                    >

                    <label for="registerHasDiscount">
                        I am a PWD / Senior Citizen and want to avail the 20% discount
                    </label>
                </div>

                <!-- Short summary shown after the PWD / Senior details are saved -->
                <div class="register-discount-summary" id="registerDiscountSummary" hidden>
                    <i class="bi bi-patch-check-fill" aria-hidden="true"></i>
                    <span class="register-discount-summary-text" id="registerDiscountSummaryText"></span>
                    <button type="button" class="register-discount-edit" id="registerDiscountEdit">
                        Edit
                    </button>
                </div>

                <!-- PWD / Senior details modal (fields still belong to this form) -->
                <div class="register-policy-modal" id="registerDiscountModal" aria-hidden="true">
                    <div
                        class="register-policy-dialog register-discount-dialog"
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="registerDiscountTitle"
                    >
                        <div class="register-policy-header">
                            <h3 class="register-policy-title" id="registerDiscountTitle">
                                PWD / Senior Citizen discount
                            </h3>
                            <button
                                type="button"
                                class="register-policy-close"
                                id="registerDiscountClose"
                                aria-label="Close"
                            >
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>

                        <div class="register-policy-body register-discount-body">

                            <div class="mb-3">
                                <label for="registerDiscountType" class="register-form-label">
                                    Discount type
                                </label>

                                <select
                                    id="registerDiscountType"
                                    name="discount_type"
                                    class="register-input"
                                >
                                    <option value="">Choose one</option>
                                    <option value="pwd" <?= $discount_type === 'pwd' ? 'selected' : '' ?>>PWD</option>
                                    <option value="senior" <?= $discount_type === 'senior' ? 'selected' : '' ?>>Senior Citizen</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="registerDiscountName" class="register-form-label">
                                    Name on ID
                                </label>

                                <input
                                    type="text"
                                    id="registerDiscountName"
                                    name="discount_id_name"
                                    class="register-input"
                                    placeholder="Full name as shown on the ID"
                                    maxlength="100"
                                    autocomplete="off"
                                    value="<?= htmlspecialchars($discount_id_name, ENT_QUOTES, 'UTF-8') ?>"
                                >
                            </div>

                            <div class="mb-3">
                                <label for="registerDiscountNumber" class="register-form-label">
                                    ID number
                                </label>

                                <input
                                    type="text"
                                    id="registerDiscountNumber"
                                    name="discount_id_number"
                                    class="register-input"
                                    placeholder="PWD / Senior Citizen ID number"
                                    maxlength="30"
                                    autocomplete="off"
                                    value="<?= htmlspecialchars($discount_id_number, ENT_QUOTES, 'UTF-8') ?>"
                                >
                            </div>

                            <div class="mb-3">
                                <label for="registerDiscountImage" class="register-form-label">
                                    Photo of your ID
                                </label>

                                <input
                                    type="file"
                                    id="registerDiscountImage"
                                    name="discount_id_image"
                                    class="register-input"
                                    accept=".jpg,.jpeg,.png,image/jpeg,image/png"
                                >
                            </div>

                            <div
                                class="register-field-error"
                                id="registerDiscountError"
                                role="alert"
                                hidden
                            >
                                <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
                                <span></span>
                            </div>

                            <p class="register-discount-note">
                                One-time verification only. JPG or PNG, up to 5MB. Your ID photo is
                                used only to verify your discount and can be seen only by authorized
                                staff. The discount is available after our staff approves your ID.
                            </p>

                        </div>

                        <div class="register-discount-footer">
                            <button type="button" class="btn-register-secondary" id="registerDiscountCancel">
                                Cancel
                            </button>
                            <button type="button" class="btn btn-register" id="registerDiscountSave">
                                Save details
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Terms and Conditions -->
                <div class="mb-3 register-terms">
                    <input
                        type="checkbox"
                        class="form-check-input"
                        id="terms"
                        name="terms"
                        value="1"
                        required
                    >

                    <label for="terms">
                        I agree to the
                        <a href="#" data-register-policy="terms">
                            Terms and Conditions
                        </a>
                        and acknowledge the
                        <a href="#" data-register-policy="privacy">
                            Privacy Policy
                        </a>.
                    </label>
                </div>

                <div
                    class="register-field-error register-terms-error"
                    id="termsError"
                    role="alert"
                    hidden
                >
                    <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
                    <span></span>
                </div>

                <button
                    type="submit"
                    class="btn btn-register"
                >
                    Sign up
                </button>

            </form>

            <div class="register-divider">or</div>

            <a href="login.php" class="btn-register-secondary">
                Already have an account? Log in
            </a>

        </section>

    </div>
</div>

<!-- =====================================================
     REGISTER FORM ALERT TOAST
====================================================== -->

<div
    class="register-toast-container"
    id="registerToastContainer"
    aria-live="polite"
    aria-atomic="true"
>
    <?php if ($error): ?>
        <div
            id="serverErrorToast"
            class="register-toast register-toast-error"
            role="alert"
        >
            <span class="register-toast-icon" aria-hidden="true">
                <i class="bi bi-exclamation-circle"></i>
            </span>

            <span class="register-toast-message">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </span>

            <button
                type="button"
                class="register-toast-close"
                aria-label="Close notification"
                data-close-register-toast="serverErrorToast"
            >
                <i class="bi bi-x-lg"></i>
            </button>

            <span class="register-toast-progress" aria-hidden="true"></span>
        </div>
    <?php elseif ($success): ?>
        <div
            id="serverSuccessToast"
            class="register-toast register-toast-success"
            role="status"
        >
            <span class="register-toast-icon" aria-hidden="true">
                <i class="bi bi-check-circle"></i>
            </span>

            <span class="register-toast-message">
                <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
            </span>

            <button
                type="button"
                class="register-toast-close"
                aria-label="Close notification"
                data-close-register-toast="serverSuccessToast"
            >
                <i class="bi bi-x-lg"></i>
            </button>

            <span class="register-toast-progress" aria-hidden="true"></span>
        </div>
    <?php endif; ?>
    <?php if ($success && $duplicateDiscountId): ?>
        <div class="register-toast register-toast-error" role="alert">
            <span class="register-toast-icon" aria-hidden="true"><i class="bi bi-exclamation-circle"></i></span>
            <span class="register-toast-message">ID is already used. Your account was registered without discount eligibility.</span>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const form = document.getElementById('registerForm');
    const nameInput = document.getElementById('registerName');
    const emailInput = document.getElementById('registerEmail');
    const mobileInput = document.getElementById('registerMobile');
    const password = document.getElementById('password');
    const confirmPassword = document.getElementById('confirm_password');
    const terms = document.getElementById('terms');
    const toastContainer = document.getElementById('registerToastContainer');
    const passwordMsg = document.getElementById('passwordError');
    const confirmMsg = document.getElementById('confirmPasswordError');
    const termsMsg = document.getElementById('termsError');

    if (!form) {
        return;
    }

    /* ---------------------------------------------------------
       TOAST
    --------------------------------------------------------- */

    let toastTimer = null;

    function closeToast(toast) {
        if (!toast) {
            return;
        }

        toast.remove();

        if (toastTimer) {
            clearTimeout(toastTimer);
            toastTimer = null;
        }
    }

    function showRegisterToast(message, type = 'error') {

        if (!toastContainer) {
            return;
        }

        const oldToast = toastContainer.querySelector('.register-toast');

        if (oldToast) {
            oldToast.remove();
        }

        if (toastTimer) {
            clearTimeout(toastTimer);
            toastTimer = null;
        }

        const toast = document.createElement('div');

        toast.className =
            'register-toast ' +
            (type === 'success'
                ? 'register-toast-success'
                : 'register-toast-error');

        toast.setAttribute(
            'role',
            type === 'success'
                ? 'status'
                : 'alert'
        );

        const iconClass =
            type === 'success'
                ? 'bi-check-circle'
                : 'bi-exclamation-circle';

        toast.innerHTML = `
            <span class="register-toast-icon" aria-hidden="true">
                <i class="bi ${iconClass}"></i>
            </span>

            <span class="register-toast-message"></span>

            <button
                type="button"
                class="register-toast-close"
                aria-label="Close notification"
            >
                <i class="bi bi-x-lg"></i>
            </button>

            <span class="register-toast-progress" aria-hidden="true"></span>
        `;

        toast.querySelector('.register-toast-message').textContent = message;

        toast.querySelector('.register-toast-close').addEventListener(
            'click',
            function () {
                closeToast(toast);
            }
        );

        toastContainer.appendChild(toast);

        const progress = toast.querySelector('.register-toast-progress');

        if (progress) {
            progress.animate(
                [
                    { transform: 'scaleX(1)' },
                    { transform: 'scaleX(0)' }
                ],
                {
                    duration: 5000,
                    easing: 'linear',
                    fill: 'forwards'
                }
            );
        }

        toastTimer = setTimeout(function () {
            closeToast(toast);
        }, 5000);
    }

    document
        .querySelectorAll('[data-close-register-toast]')
        .forEach(function (button) {

            button.addEventListener('click', function () {

                const toastId =
                    button.getAttribute('data-close-register-toast');

                closeToast(
                    document.getElementById(toastId)
                );

            });

        });

    /* ---------------------------------------------------------
       FIELD STATE HELPERS
    --------------------------------------------------------- */

    /* Inline message shown right under the password fields */
    function showFieldMessage(field, message) {

        const box =
            field === password
                ? passwordMsg
                : field === confirmPassword
                    ? confirmMsg
                    : termsMsg;

        if (!field || !box) {
            return;
        }

        const text = box.querySelector('span');

        if (text) {
            text.textContent = message || '';
        }

        box.hidden = !message;
    }

    function clearFieldErrors() {

        showFieldMessage(password, '');
        showFieldMessage(confirmPassword, '');
        showFieldMessage(terms, '');

        [
            nameInput,
            emailInput,
            mobileInput,
            password,
            confirmPassword,
            terms
        ].forEach(function (field) {

            if (!field) {
                return;
            }

            field.classList.remove('field-error');

            field.removeAttribute('aria-invalid');

        });
    }

    function markFieldError(field) {

        if (!field) {
            return;
        }

        field.classList.add('field-error');
        field.setAttribute('aria-invalid', 'true');
    }

    function firstErrorField(fields) {

        for (const field of fields) {

            if (
                field &&
                field.classList.contains('field-error')
            ) {
                return field;
            }

        }

        return null;
    }

    /* ---------------------------------------------------------
       PASSWORD SHOW / HIDE
    --------------------------------------------------------- */

    document
        .querySelectorAll('.password-toggle')
        .forEach(function (button) {

            button.addEventListener('click', function () {

                const targetId =
                    button.getAttribute('data-password-target');

                const input =
                    document.getElementById(targetId);

                const icon =
                    button.querySelector('i');

                if (!input || !icon) {
                    return;
                }

                const showing =
                    input.type === 'text';

                input.type =
                    showing
                        ? 'password'
                        : 'text';

                icon.className =
                    showing
                        ? 'bi bi-eye'
                        : 'bi bi-eye-slash';

                button.setAttribute(
                    'aria-label',
                    showing
                        ? 'Show password'
                        : 'Hide password'
                );

                button.setAttribute(
                    'aria-pressed',
                    showing ? 'false' : 'true'
                );

            });

        });

    /* ---------------------------------------------------------
       MOBILE NUMBER
    --------------------------------------------------------- */

    if (mobileInput) {

        mobileInput.addEventListener('input', function () {

            this.value = this.value
                .replace(/\D/g, '')
                .slice(0, 11);

        });

    }

    /* ---------------------------------------------------------
       LIVE PASSWORD MATCH VALIDATION
    --------------------------------------------------------- */

    function validatePasswordMatch(showToast = false) {

        if (!password || !confirmPassword) {
            return true;
        }

        if (
            confirmPassword.value !== '' &&
            password.value !== confirmPassword.value
        ) {
            markFieldError(confirmPassword);
            showFieldMessage(confirmPassword, 'Passwords do not match.');

            if (showToast) {
                confirmPassword.focus();
            }

            return false;
        }

        confirmPassword.classList.remove('field-error');
        confirmPassword.removeAttribute('aria-invalid');
        showFieldMessage(confirmPassword, '');

        return true;
    }

    if (password && confirmPassword) {

        password.addEventListener(
            'input',
            function () {
                validatePasswordMatch(false);
            }
        );

        confirmPassword.addEventListener(
            'input',
            function () {
                validatePasswordMatch(false);
            }
        );

    }

    if (terms) {
        terms.addEventListener('change', function () {
            if (terms.checked) {
                terms.classList.remove('field-error');
                terms.removeAttribute('aria-invalid');
                showFieldMessage(terms, '');
            }
        });
    }

    if (password) {
        password.addEventListener('input', function () {
            if (password.value.length >= 8) {
                password.classList.remove('field-error');
                password.removeAttribute('aria-invalid');
                showFieldMessage(password, '');
            }
        });
    }

    /* ---------------------------------------------------------
       PWD / SENIOR DETAILS (modal)
    --------------------------------------------------------- */

    const hasDiscountBox = document.getElementById('registerHasDiscount');
    const discountModal = document.getElementById('registerDiscountModal');
    const discountSummary = document.getElementById('registerDiscountSummary');
    const discountSummaryText = document.getElementById('registerDiscountSummaryText');
    const discountEdit = document.getElementById('registerDiscountEdit');
    const discountError = document.getElementById('registerDiscountError');
    const dType = document.getElementById('registerDiscountType');
    const dName = document.getElementById('registerDiscountName');
    const dNumber = document.getElementById('registerDiscountNumber');
    const dImage = document.getElementById('registerDiscountImage');
    const discountOpenOnLoad = <?= ($wantsDiscount && !$success) ? 'true' : 'false' ?>;

    let discountSaved = false;

    function setDiscountError(message) {

        if (!discountError) {
            return;
        }

        const text = discountError.querySelector('span');

        if (text) {
            text.textContent = message || '';
        }

        discountError.hidden = !message;
    }

    function clearDiscountFieldErrors() {

        [dType, dName, dNumber, dImage].forEach(function (field) {

            if (!field) {
                return;
            }

            field.classList.remove('field-error');
            field.removeAttribute('aria-invalid');
        });
    }

    /* Returns '' when everything is fine, otherwise the first error message. */
    function validateDiscount() {

        clearDiscountFieldErrors();

        let message = '';

        if (!dType.value) {
            markFieldError(dType);
            message = 'Please choose PWD or Senior Citizen.';
        }

        if (dName.value.trim() === '') {
            markFieldError(dName);
            message = message || 'Please enter the name shown on your ID.';
        }

        if (!/^[A-Za-z0-9][A-Za-z0-9\-\/ ]{2,29}$/.test(dNumber.value.trim())) {
            markFieldError(dNumber);
            message = message || 'Please enter a valid ID number (letters, numbers and dashes only).';
        }

        if (!dImage.files.length) {
            markFieldError(dImage);
            message = message || 'Please upload a photo of your ID.';
        } else if (dImage.files[0].size > 5 * 1024 * 1024) {
            markFieldError(dImage);
            message = message || 'The ID photo must not exceed 5MB.';
        } else if (!['image/jpeg', 'image/png'].includes(dImage.files[0].type)) {
            markFieldError(dImage);
            message = message || 'The ID photo must be a JPG or PNG file.';
        }

        setDiscountError(message);

        return message;
    }

    function updateDiscountSummary() {

        if (!discountSummary || !discountSummaryText) {
            return;
        }

        if (discountSaved && hasDiscountBox && hasDiscountBox.checked) {

            const label = dType.value === 'senior' ? 'Senior Citizen' : 'PWD';
            const fileName = dImage.files.length ? dImage.files[0].name : '';

            discountSummaryText.textContent =
                label + ' ID ' + dNumber.value.trim().toUpperCase() +
                (fileName ? ' · ' + fileName : '');

            discountSummary.hidden = false;

        } else {

            discountSummary.hidden = true;
        }
    }

    function openDiscountModal() {

        if (!discountModal) {
            return;
        }

        discountModal.classList.add('is-open');
        discountModal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';

        const firstError = discountModal.querySelector('.field-error');

        (firstError || dType).focus();
    }

    function closeDiscountModal() {

        if (!discountModal) {
            return;
        }

        discountModal.classList.remove('is-open');
        discountModal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    function resetDiscount() {

        dType.value = '';
        dName.value = '';
        dNumber.value = '';
        dImage.value = '';

        discountSaved = false;

        setDiscountError('');
        clearDiscountFieldErrors();
        updateDiscountSummary();
    }

    function cancelDiscountModal() {

        /* Never saved -> the customer changed their mind, so untick the box. */
        if (!discountSaved && hasDiscountBox) {
            hasDiscountBox.checked = false;
            resetDiscount();
        }

        closeDiscountModal();

        if (hasDiscountBox) {
            hasDiscountBox.focus();
        }
    }

    if (hasDiscountBox && discountModal && dType && dName && dNumber && dImage) {

        hasDiscountBox.addEventListener('change', function () {

            if (hasDiscountBox.checked) {
                openDiscountModal();
            } else {
                closeDiscountModal();
                resetDiscount();
            }
        });

        document
            .getElementById('registerDiscountSave')
            .addEventListener('click', function () {

                if (validateDiscount() !== '') {
                    return;
                }

                discountSaved = true;
                updateDiscountSummary();
                closeDiscountModal();
            });

        document
            .getElementById('registerDiscountCancel')
            .addEventListener('click', cancelDiscountModal);

        document
            .getElementById('registerDiscountClose')
            .addEventListener('click', cancelDiscountModal);

        if (discountEdit) {
            discountEdit.addEventListener('click', openDiscountModal);
        }

        discountModal.addEventListener('click', function (event) {
            if (event.target === discountModal) {
                cancelDiscountModal();
            }
        });

        /* Enter inside the modal saves the details instead of submitting the whole form. */
        discountModal.addEventListener('keydown', function (event) {

            if (
                event.key === 'Enter' &&
                event.target.tagName === 'INPUT' &&
                event.target.type !== 'file'
            ) {
                event.preventDefault();
                document.getElementById('registerDiscountSave').click();
            }
        });

        document.addEventListener('keydown', function (event) {

            if (
                event.key === 'Escape' &&
                discountModal.classList.contains('is-open')
            ) {
                cancelDiscountModal();
            }
        });

        /* The page was reloaded by the server (error): a file cannot be refilled. */
        if (discountOpenOnLoad && hasDiscountBox.checked) {
            setDiscountError('Please upload your ID photo again.');
            openDiscountModal();
        }
    }

    /* ---------------------------------------------------------
       FORM VALIDATION
    --------------------------------------------------------- */

    form.addEventListener('submit', function (event) {

        event.preventDefault();

        clearFieldErrors();

        let valid = true;

        if (!nameInput || nameInput.value.trim() === '') {

            markFieldError(nameInput);
            valid = false;

        }

        if (
            !emailInput ||
            emailInput.value.trim() === ''
        ) {

            markFieldError(emailInput);
            valid = false;

        } else if (
            !emailInput.checkValidity()
        ) {

            markFieldError(emailInput);
            valid = false;

        }

        const mobileValue =
            mobileInput
                ? mobileInput.value.trim()
                : '';

        if (!mobileInput || mobileValue === '') {

            markFieldError(mobileInput);
            valid = false;

        } else if (
            !/^09\d{9}$/.test(mobileValue)
        ) {

            markFieldError(mobileInput);
            valid = false;

        }

        if (
            !password ||
            password.value.length < 8
        ) {

            markFieldError(password);
            showFieldMessage(
                password,
                'Password must be at least 8 characters long.'
            );
            valid = false;

        }

        if (
            !confirmPassword ||
            confirmPassword.value === ''
        ) {

            markFieldError(confirmPassword);
            showFieldMessage(
                confirmPassword,
                'Please confirm your password.'
            );
            valid = false;

        } else if (
            password &&
            confirmPassword.value !== password.value
        ) {

            markFieldError(confirmPassword);
            showFieldMessage(
                confirmPassword,
                'Passwords do not match.'
            );
            valid = false;

        }

        if (!terms || !terms.checked) {

            markFieldError(terms);
            showFieldMessage(
                terms,
                'Please agree to the Terms and Conditions and Privacy Policy.'
            );
            valid = false;

        }

        /* PWD / Senior details live in the modal. */
        let discountMessage = '';

        if (hasDiscountBox && hasDiscountBox.checked) {

            discountMessage = validateDiscount();

            if (discountMessage !== '') {
                valid = false;
            }
        }

        if (!valid) {

            let message =
                'Please check the highlighted field(s).';

            /* Password problems are shown inline under the field,
               so the toast is only for the other fields. */
            const otherInvalid = [
                nameInput,
                emailInput,
                mobileInput
            ].some(function (field) {
                return field && field.classList.contains('field-error');
            });

            if (
                mobileInput &&
                mobileInput.classList.contains('field-error')
            ) {
                message =
                    'Please enter a valid 11-digit Philippine mobile number starting with 09.';
            } else if (
                emailInput &&
                emailInput.classList.contains('field-error') &&
                emailInput.value.trim() !== ''
            ) {
                message =
                    'Please enter a valid email address.';
            }

            const pageFieldInvalid = [
                nameInput,
                emailInput,
                mobileInput,
                password,
                confirmPassword,
                terms
            ].some(function (field) {
                return field && field.classList.contains('field-error');
            });

            if (otherInvalid) {
                showRegisterToast(message);
            } else if (discountMessage !== '' && !pageFieldInvalid) {
                /* Only the ID details are missing: reopen the modal on the problem. */
                openDiscountModal();
            }

            const fieldToFocus =
                firstErrorField([
                    nameInput,
                    emailInput,
                    mobileInput,
                    password,
                    confirmPassword,
                    terms
                ]);

            if (fieldToFocus) {
                fieldToFocus.focus();
            }

            return;
        }

        /*
         * All client-side checks passed.
         * Allow the existing PHP/email-verification flow to process.
         */
        form.submit();

    });

});
</script>


<!-- =====================================================
     TERMS & PRIVACY MODALS
====================================================== -->
<style>
    .register-policy-modal {
        position: fixed;
        inset: 0;
        z-index: 3000;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 20px;
        background: rgba(44, 34, 30, 0.58);
    }

    .register-policy-modal.is-open {
        display: flex;
    }

    .register-policy-dialog {
        width: min(720px, 100%);
        height: min(80vh, 680px);
        max-height: 80vh;
        min-height: 0;
        background: #FFFFFF;
        border: 2px solid #6F4E37;
        border-radius: 18px;
        box-shadow: 0 18px 45px rgba(44, 34, 30, 0.22);
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }

    .register-policy-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 16px 20px;
        border-bottom: 1px solid #E4D7CB;
        background: #FBF8F4;
        flex: 0 0 auto;
    }

    .register-policy-title {
        margin: 0;
        color: #2C221E;
        font-size: 1.05rem;
        font-weight: 800;
    }

    .register-policy-close {
        width: 34px;
        height: 34px;
        border: 0;
        border-radius: 50%;
        background: transparent;
        color: #6F4E37;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        flex: 0 0 auto;
    }

    .register-policy-close:hover {
        background: #F4ECE4;
        color: #4A3525;
    }

    .register-policy-body {
        flex: 1 1 auto;
        min-height: 0;
        padding: 18px 20px 20px;
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
        color: #5F5048;
        font-size: 0.82rem;
        line-height: 1.65;
    }

    .register-policy-body h4 {
        margin: 0 0 7px;
        color: #4A3525;
        font-size: 0.92rem;
        font-weight: 800;
    }

    .register-policy-body p {
        margin: 0 0 14px;
    }

    .register-policy-body ul {
        margin: 0 0 14px;
        padding-left: 20px;
    }

    .register-policy-body li {
        margin-bottom: 6px;
    }

    @media (max-width: 575.98px) {
        .register-policy-modal {
            padding: 12px;
        }

        .register-policy-dialog {
            height: 86vh;
            max-height: 86vh;
            min-height: 0;
            border-radius: 15px;
        }

        .register-policy-header {
            padding: 13px 15px;
        }

        .register-policy-body {
            padding: 15px;
            font-size: 0.76rem;
        }

        .register-policy-title {
            font-size: 0.95rem;
        }
    }
</style>

<div class="register-policy-modal" id="registerTermsModal" aria-hidden="true">
    <div class="register-policy-dialog" role="dialog" aria-modal="true" aria-labelledby="registerTermsTitle">
        <div class="register-policy-header">
            <h3 class="register-policy-title" id="registerTermsTitle">Terms and Conditions</h3>
            <button type="button" class="register-policy-close" data-close-register-policy aria-label="Close Terms and Conditions">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="register-policy-body">
            <h4>1. Account Registration</h4>
            <p>
                By creating a Localitea customer account, you agree to provide accurate
                and complete information such as your name, email address, and mobile number.
            </p>

            <h4>2. Account Use</h4>
            <p>
                Your account is intended for your personal use in placing and monitoring
                Localitea orders. Keep your login credentials private and do not share them
                with other people.
            </p>

            <h4>3. Online Orders</h4>
            <p>
                Orders submitted through Localitea are for pick-up at the store. Please
                review your order details, selected options, and payment information before
                submitting the order.
            </p>

            <h4>4. Payment</h4>
            <p>
                Customers are responsible for providing correct payment information and,
                when applicable, valid proof of digital payment. Orders may be subject to
                verification before processing.
            </p>

            <h4>5. Order Status and Cancellation</h4>
            <p>
                Order status may be updated by authorized store staff. Cancellation and
                refund processing may depend on the order status and the applicable store
                policy.
            </p>

            <h4>6. Customer Responsibility</h4>
            <p>
                Customers are expected to provide correct contact details and pick-up
                information and to collect completed orders within the agreed pick-up period.
            </p>

            <h4>7. Changes to These Terms</h4>
            <p>
                Local Milktea House may update these Terms and Conditions when needed.
                Updated terms will be reflected in the system.
            </p>
        </div>
    </div>
</div>

<div class="register-policy-modal" id="registerPrivacyModal" aria-hidden="true">
    <div class="register-policy-dialog" role="dialog" aria-modal="true" aria-labelledby="registerPrivacyTitle">
        <div class="register-policy-header">
            <h3 class="register-policy-title" id="registerPrivacyTitle">Privacy Policy</h3>
            <button type="button" class="register-policy-close" data-close-register-policy aria-label="Close Privacy Policy">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="register-policy-body">
            <h4>1. Information We Collect</h4>
            <p>
                Local Milktea House may collect information you provide during registration and
                ordering, including your full name, email address, mobile number, order
                details, and payment-related information submitted for verification. If you apply for the
                PWD / Senior Citizen discount, we also collect the name and number shown on
                your ID and a photo of your ID, which are used only to verify your discount.
            </p>

            <h4>2. How We Use Your Information</h4>
            <p>
                Information is used to create and manage your account, process orders,
                verify payments when applicable, provide order status updates, and support
                customer service.
            </p>

            <h4>3. Order and Payment Information</h4>
            <p>
                Order and payment details may be stored in the system to support transaction
                processing, verification, reporting, and customer support.
            </p>

            <h4>4. Information Protection</h4>
            <p>
                Local Milktea House applies reasonable technical and organizational measures to help
                protect customer information from unauthorized access, alteration, or disclosure.
            </p>

            <h4>5. Information Sharing</h4>
            <p>
                Customer information is intended for legitimate system and store operations.
                It is not used for unrelated purposes without an appropriate basis.
            </p>

            <h4>6. Customer Rights and Requests</h4>
            <p>
                For questions or requests concerning personal information, customers may
                contact Local Milktea House through the contact information provided by the store.
            </p>

            <h4>7. Policy Updates</h4>
            <p>
                This Privacy Policy may be updated when system or business practices change.
                The latest version will be made available through the registration page.
            </p>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const policyButtons = document.querySelectorAll('[data-register-policy]');
    const closeButtons = document.querySelectorAll('[data-close-register-policy]');
    const termsModal = document.getElementById('registerTermsModal');
    const privacyModal = document.getElementById('registerPrivacyModal');

    function closeAllPolicyModals() {
        [termsModal, privacyModal].forEach(function (modal) {
            if (!modal) return;
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
        });
        document.body.style.overflow = '';
    }

    function openPolicyModal(type) {
        closeAllPolicyModals();

        const modal = type === 'privacy' ? privacyModal : termsModal;
        if (!modal) return;

        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';

        const closeButton = modal.querySelector('[data-close-register-policy]');
        if (closeButton) {
            closeButton.focus();
        }
    }

    policyButtons.forEach(function (link) {
        link.addEventListener('click', function (event) {
            event.preventDefault();
            openPolicyModal(link.getAttribute('data-register-policy'));
        });
    });

    closeButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            closeAllPolicyModals();
        });
    });

    [termsModal, privacyModal].forEach(function (modal) {
        if (!modal) return;

        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeAllPolicyModals();
            }
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeAllPolicyModals();
        }
    });
});
</script>

<!-- Bootstrap 5.3.3 JavaScript (required for the navbar toggler and dropdowns on phone/tablet) -->
<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>
</document_content>