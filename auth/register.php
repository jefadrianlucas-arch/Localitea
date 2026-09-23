<?php
require_once '../includes/db.php';
require_once '../includes/mailer.php';

$error = '';
$success = '';

// Initialize form values so they are always defined.
// Safe fields are preserved after validation errors.
$name = '';
$email = '';
$mobile = '';
$password = '';
$confirm_password = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $mobile = trim($_POST['mobile'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if ($name === '' || $email === '' || $mobile === '' || $password === '') {

        $error = "Please fill in all required fields.";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = "Please enter a valid email address.";

    } elseif (!preg_match('/^09\d{9}$/', $mobile)) {

        $error = "Please enter a valid 11-digit Philippine mobile number starting with 09.";

    } elseif (strlen($password) < 6) {

        $error = "Password must be at least 6 characters long.";

    } elseif ($password !== $confirm_password) {

        $error = "Passwords do not match.";

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

                /*
                 * Local XAMPP verification URL
                 */
                $verificationLink =
                    'http://localhost/Localitea_Fixed%20Working%20Staff%20Orders%20No%20Admin%20orders%20yet/auth/verify-email.php?token='
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

            } catch (Exception $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $error =
                    "Registration failed because the verification email could not be sent. Please try again.";
            }
        }
    }
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
?>

<style>
/* =========================================================
   LOCALITEA REGISTER PAGE
   Responsive across phones, tablets, laptops and desktop
========================================================= */

body {
    background: #FBF8F4;
    color: #2C221E;
    overflow-x: hidden;
}

.register-page {
    width: 100%;
    min-height: calc(100vh - 140px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 32px 16px 40px;
    box-sizing: border-box;
}

.register-card {
    width: 100%;
    max-width: 450px;
    background: #FFFFFF;
    border: 2px solid #6F4E37;
    border-radius: 18px;
    box-shadow: 0 8px 24px rgba(74, 53, 37, 0.10);
    padding: 30px;
    box-sizing: border-box;
}

/* Logo */
.register-logo {
    width: 64px;
    height: 64px;
    display: block;
    margin: 0 auto 12px;
    object-fit: contain;
    border-radius: 50%;
    border: 1px solid #D8C6B8;
    box-shadow: 0 3px 10px rgba(74, 53, 37, 0.10);
}

/* Heading */
.register-title {
    color: #2C221E;
    font-size: 1.25rem;
    font-weight: 800;
    letter-spacing: 0.2px;
    margin-bottom: 4px;
}

.register-subtitle {
    color: #756960;
    font-size: 0.82rem;
    margin-bottom: 0;
}

/* Labels */
.register-form-label {
    display: block;
    color: #4A3525;
    font-size: 0.78rem;
    font-weight: 700;
    margin-bottom: 6px;
}

/* Inputs */
.register-input {
    width: 100%;
    min-height: 43px;
    border: 1.5px solid #B8A08A;
    border-radius: 9px;
    background: #FFFFFF;
    color: #2C221E;
    padding: 9px 12px;
    font-size: 0.84rem;
    box-shadow: none;
    transition: border-color .2s ease, box-shadow .2s ease, background-color .2s ease;
    box-sizing: border-box;
}

.register-input::placeholder {
    color: #A89D95;
}

.register-input:hover {
    border-color: #8B6F5A;
}

.register-input:focus {
    border-color: #6F4E37;
    background: #FFFFFF;
    box-shadow: 0 0 0 3px rgba(111, 78, 55, 0.13);
    outline: none;
}

.register-input.field-error {
    border-color: #B85C5C;
    box-shadow: 0 0 0 3px rgba(184, 92, 92, 0.10);
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
    padding-right: 43px;
}

.password-toggle {
    position: absolute;
    top: 50%;
    right: 8px;
    width: 34px;
    height: 34px;
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
    font-size: 0.95rem;
}

/* Terms */
.register-terms {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    color: #756960;
    font-size: 0.75rem;
    line-height: 1.4;
}

.register-terms .form-check-input {
    flex: 0 0 auto;
    width: 16px;
    height: 16px;
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
    min-height: 43px;
    background: #6F4E37;
    border: 1.5px solid #6F4E37;
    border-radius: 50px;
    color: #FFFFFF;
    padding: 9px 16px;
    font-size: 0.84rem;
    font-weight: 700;
    letter-spacing: 0.2px;
    box-shadow: 0 3px 8px rgba(111, 78, 55, 0.18);
    transition: transform .2s ease, box-shadow .2s ease, background-color .2s ease;
}

.btn-register:hover {
    background: #4A3525;
    border-color: #4A3525;
    color: #FFFFFF;
    transform: translateY(-1px);
    box-shadow: 0 5px 12px rgba(74, 53, 37, 0.22);
}

.btn-register:focus {
    background: #6F4E37;
    border-color: #6F4E37;
    color: #FFFFFF;
    box-shadow: 0 0 0 3px rgba(111, 78, 55, 0.16);
}

/* Bottom login text */
.register-login-text {
    color: #756960;
    font-size: 0.78rem;
}

.register-login-text a {
    color: #6F4E37;
    font-weight: 800;
    text-decoration: none;
}

.register-login-text a:hover {
    color: #4A3525;
    text-decoration: underline;
}

/* =========================================================
   LOCALITEA FORM ALERT / TOAST
   Styled to match the order notification toast
========================================================= */

.register-toast-container {
    position: fixed;
    right: 20px;
    bottom: 20px;
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
   TABLET
========================================================= */
@media (max-width: 991.98px) {
    .register-page {
        min-height: calc(100vh - 110px);
        padding: 28px 16px 36px;
    }

    .register-card {
        max-width: 440px;
        padding: 28px;
    }
}

/* =========================================================
   PHONES
========================================================= */
@media (max-width: 575.98px) {
    .register-page {
        min-height: calc(100vh - 92px);
        padding: 20px 12px 30px;
        align-items: flex-start;
    }

    .register-card {
        max-width: 100%;
        padding: 23px 18px;
        border-radius: 16px;
    }

    .register-logo {
        width: 58px;
        height: 58px;
        margin-bottom: 10px;
    }

    .register-title {
        font-size: 1.12rem;
    }

    .register-subtitle {
        font-size: 0.77rem;
    }

    .register-input {
        min-height: 42px;
        font-size: 0.82rem;
    }

    .btn-register {
        min-height: 42px;
        font-size: 0.82rem;
    }

    .register-toast-container {
        right: 12px;
        bottom: 12px;
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

    .register-card {
        padding: 20px 15px;
        border-radius: 15px;
    }

    .register-logo {
        width: 54px;
        height: 54px;
    }

    .register-title {
        font-size: 1.05rem;
    }

    .register-subtitle {
        font-size: 0.74rem;
    }

    .register-form-label {
        font-size: 0.74rem;
    }

    .register-input {
        min-height: 40px;
        font-size: 0.8rem;
        padding: 8px 10px;
    }

    .password-field .register-input {
        padding-right: 40px;
    }

    .password-toggle {
        width: 32px;
        height: 32px;
        right: 6px;
    }

    .btn-register {
        min-height: 40px;
        font-size: 0.8rem;
    }

    .register-terms,
    .register-login-text {
        font-size: 0.73rem;
    }
}
</style>

<div class="register-page">
    <div class="register-card">

        <!-- Logo & Heading -->
        <div class="text-center mb-4">

            <img
                src="../assets/images/download.png"
                alt="Local Milktea House Logo"
                class="register-logo"
            >

            <h1 class="register-title">Create Account</h1>

            <p class="register-subtitle">
                Please fill in the details to sign up
            </p>

        </div>

        <form id="registerForm" method="POST" novalidate>

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
                        class="register-input"
                        placeholder="••••••••"
                        autocomplete="new-password"
                        minlength="6"
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
                        class="register-input"
                        placeholder="••••••••"
                        autocomplete="new-password"
                        minlength="6"
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
            </div>

            <!-- Terms and Conditions -->
            <div class="mb-4 register-terms">
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
                    <a href="#">
                        Terms and Conditions
                    </a>
                </label>
            </div>

            <button
                type="submit"
                class="btn btn-register mb-3"
            >
                Sign Up
            </button>

        </form>

        <div class="text-center register-login-text">
            Already have an account?
            <a href="login.php">
                Log in
            </a>
        </div>

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

    if (!form) {
        return;
    }

    /* ---------------------------------------------------------
       TOAST
       Matches the compact Localitea order-notification style.
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

    function clearFieldErrors() {

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

            if (showToast) {
                showRegisterToast(
                    'Passwords do not match.'
                );

                confirmPassword.focus();
            }

            return false;
        }

        confirmPassword.classList.remove('field-error');
        confirmPassword.removeAttribute('aria-invalid');

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
            password.value.length < 6
        ) {

            markFieldError(password);
            valid = false;

        }

        if (
            !confirmPassword ||
            confirmPassword.value === ''
        ) {

            markFieldError(confirmPassword);
            valid = false;

        } else if (
            password &&
            confirmPassword.value !== password.value
        ) {

            markFieldError(confirmPassword);
            valid = false;

        }

        if (!terms || !terms.checked) {

            markFieldError(terms);
            valid = false;

        }

        if (!valid) {

            let message =
                'Please check the highlighted field(s).';

            if (
                confirmPassword &&
                confirmPassword.classList.contains('field-error') &&
                password &&
                confirmPassword.value !== password.value
            ) {
                message =
                    'Passwords do not match.';
            } else if (
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
            } else if (
                terms &&
                terms.classList.contains('field-error')
            ) {
                message =
                    'Please agree to the Terms and Conditions.';
            } else if (
                password &&
                password.classList.contains('field-error')
            ) {
                message =
                    'Password must be at least 6 characters long.';
            }

            showRegisterToast(message);

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
