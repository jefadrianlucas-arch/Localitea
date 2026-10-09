<?php
session_start();
require_once '../includes/db.php';

$error = '';
$password_error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // CHECK ADMINS
    $stmt = $pdo->prepare("
        SELECT *
        FROM admins
        WHERE email = ?
        LIMIT 1
    ");
    $stmt->execute([$email]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($admin && password_verify($password, $admin['password'])) {

        session_regenerate_id(true);

        $_SESSION['user_id'] = $admin['id'];
        $_SESSION['user_name'] = $admin['full_name'];
        $_SESSION['user_email'] = $admin['email'];
        $_SESSION['user_role'] = $admin['role'];

        header("Location: ../admin/dashboard.php");
        exit;
    }

    // CHECK STAFF
    $stmt = $pdo->prepare("
        SELECT *
        FROM users
        WHERE email = ?
        AND role = 'staff'
        LIMIT 1
    ");
    $stmt->execute([$email]);
    $staff = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($staff && password_verify($password, $staff['password'])) {

        session_regenerate_id(true);

        $_SESSION['user_id'] = $staff['id'];
        $_SESSION['user_name'] = $staff['name'];
        $_SESSION['user_email'] = $staff['email'];
        $_SESSION['user_role'] = 'staff';

        header("Location: ../staff/index.php");
        exit;
    }

    // CHECK CUSTOMERS
    $stmt = $pdo->prepare("
        SELECT *
        FROM customers
        WHERE email = ?
        LIMIT 1
    ");
    $stmt->execute([$email]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($customer && password_verify($password, $customer['password'])) {

        // Customer must verify their email first
        if (empty($customer['email_verified_at'])) {

            $error = "Please verify your email before logging in.";

        } else {

            session_regenerate_id(true);

            $_SESSION['user_id'] = $customer['id'];
            $_SESSION['user_name'] = $customer['full_name'];
            $_SESSION['user_email'] = $customer['email'];
            $_SESSION['user_role'] = 'customer';

            header("Location: ../customer/index.php");
            exit;
        }

    } else {
        $password_error = "Incorrect email or password. Please try again.";
    }
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
?>

<style>
/* =========================================================
   LOCALITEA LOGIN PAGE — SPLIT CARD LAYOUT (mirrored)
   Left: login form   |   Right: brand panel (desktop only)
   On tablet / phone the brand panel is hidden and the form
   becomes a single centered card.
========================================================= */

body {
    background: #FFFFFF;
    color: #2C221E;
    overflow-x: hidden;
}

.login-page {
    width: 100%;
    min-height: calc(100vh - 140px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 40px 20px 48px;
    box-sizing: border-box;
    background: #FFFFFF;
}

.login-card {
    width: 100%;
    max-width: 920px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    background: #FFFFFF;
    border: 1px solid #E4D7CB;
    border-radius: 18px;
    box-shadow: 0 14px 40px rgba(74, 53, 37, 0.16);
    overflow: hidden;
}

/* ---------- Left form panel ---------- */
.login-form-panel {
    display: flex;
    flex-direction: column;
    justify-content: center;
    padding: 44px 48px;
    box-sizing: border-box;
    background: #FFFFFF;
}

/* Small logo — only visible when the brand panel is hidden */
.login-mobile-logo {
    display: none;
    width: 56px;
    height: 56px;
    object-fit: contain;
    border-radius: 50%;
    background: #FFFFFF;
    border: 2px solid #E4D7CB;
    box-shadow: 0 3px 10px rgba(74, 53, 37, 0.14);
    margin: 0 auto 14px;
}

.login-title {
    color: #2C221E;
    font-size: 1.7rem;
    font-weight: 800;
    letter-spacing: 0.2px;
    text-align: center;
    margin: 0 0 6px;
}

.login-subtitle {
    color: #756960;
    font-size: 0.82rem;
    text-align: center;
    margin: 0 0 24px;
}

/* Inline error under the password field */
.login-input.login-input-error {
    border-color: #B85C5C;
    box-shadow: 0 0 0 3px rgba(184, 92, 92, 0.10);
}

.login-field-error {
    display: flex;
    align-items: flex-start;
    gap: 5px;
    margin-top: 6px;
    color: #8B3030;
    font-size: 0.72rem;
    font-weight: 600;
    line-height: 1.35;
}

.login-field-error i {
    font-size: 0.8rem;
    line-height: 1.3;
}

/* Error */
.login-alert {
    border: 1px solid #B85C5C;
    border-radius: 8px;
    background: #FFF3F3;
    color: #8B3030;
    font-size: 0.78rem;
    line-height: 1.4;
    padding: 9px 11px;
    margin-bottom: 18px;
}

.login-form-label {
    display: block;
    color: #4A3525;
    font-size: 0.74rem;
    font-weight: 700;
    margin-bottom: 5px;
}

.login-input {
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

.login-input::placeholder {
    color: #A89D95;
}

.login-input:hover {
    border-color: #B8A08A;
}

.login-input:focus {
    border-color: #6F4E37;
    box-shadow: 0 0 0 3px rgba(111, 78, 55, 0.13);
    outline: none;
}

/* Password field with eye toggle */
.password-field {
    position: relative;
}

.password-field .login-input {
    padding-right: 42px;
}

.password-toggle {
    position: absolute;
    top: 50%;
    right: 6px;
    transform: translateY(-50%);
    width: 32px;
    height: 32px;
    padding: 0;
    border: 0;
    background: transparent;
    color: #6F4E37;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    cursor: pointer;
    z-index: 2;
}

.password-toggle:hover {
    color: #4A3525;
    background: #F7F1E8;
}

.password-toggle:focus-visible {
    outline: 2px solid #6F4E37;
    outline-offset: 1px;
}

.password-toggle i {
    font-size: 0.92rem;
    line-height: 1;
}

/* Forgot password */
.login-forgot {
    color: #756960;
    font-size: 0.72rem;
    font-weight: 600;
    text-decoration: none;
}

.login-forgot:hover {
    color: #6F4E37;
    text-decoration: underline;
}

/* Buttons */
.btn-localitea {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    min-height: 40px;
    border-radius: 7px;
    padding: 8px 16px;
    font-size: 0.82rem;
    font-weight: 700;
    letter-spacing: 0.2px;
    text-decoration: none;
    box-sizing: border-box;
    transition: background-color .2s ease, border-color .2s ease, box-shadow .2s ease;
}

.btn-localitea-primary {
    background: #6F4E37;
    border: 1.5px solid #6F4E37;
    color: #FFFFFF;
}

.btn-localitea-primary:hover,
.btn-localitea-primary:focus {
    background: #4A3525;
    border-color: #4A3525;
    color: #FFFFFF;
}

.btn-localitea-primary:focus {
    box-shadow: 0 0 0 3px rgba(111, 78, 55, 0.16);
}

.btn-localitea-outline {
    background: #FFFFFF;
    border: 1.5px solid #E4D7CB;
    color: #2C221E;
    box-shadow: 0 2px 6px rgba(74, 53, 37, 0.10);
}

.btn-localitea-outline:hover {
    background: #FBF8F4;
    border-color: #6F4E37;
    color: #4A3525;
}

.btn-localitea-outline:focus-visible {
    outline: 2px solid #6F4E37;
    outline-offset: 2px;
}

/* Divider */
.login-divider {
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 14px 0;
    color: #A89D95;
    font-size: 0.7rem;
}

.login-divider::before,
.login-divider::after {
    content: "";
    flex: 1;
    height: 1px;
    background: #E4D7CB;
}

/* Signup text */
.login-signup {
    color: #756960;
    font-size: 0.78rem;
    text-align: center;
}

.login-signup a {
    color: #6F4E37;
    font-weight: 800;
    text-decoration: none;
}

.login-signup a:hover {
    color: #4A3525;
    text-decoration: underline;
}

/* ---------- Right brand panel (medium brown, not too dark) ---------- */
.login-visual {
    position: relative;
    display: flex;
    flex-direction: column;
    justify-content: center;
    padding: 48px 40px;
    color: #FFFFFF;
    overflow: hidden;
    background:
        radial-gradient(ellipse 80% 55% at 82% 12%, rgba(232, 204, 172, 0.60), transparent 62%),
        radial-gradient(ellipse 70% 50% at 12% 42%, rgba(160, 118, 84, 0.90), transparent 64%),
        radial-gradient(ellipse 90% 60% at 70% 95%, rgba(196, 160, 126, 0.50), transparent 62%),
        linear-gradient(200deg, #A57B5A, #86603F);
}

/* Fine fibre-like strokes for texture */
.login-visual::before {
    content: "";
    position: absolute;
    inset: 0;
    background:
        repeating-conic-gradient(
            from 100deg at 92% 105%,
            rgba(255, 255, 255, 0.07) 0deg 1.5deg,
            transparent 1.5deg 5deg
        );
    pointer-events: none;
}

.login-visual > * {
    position: relative;
    z-index: 1;
}

.login-logo {
    position: absolute !important;
    top: 28px;
    right: 32px;
    width: 52px;
    height: 52px;
    object-fit: contain;
    border-radius: 50%;
    background: #FFFFFF;
    border: 2px solid rgba(255, 255, 255, 0.85);
    box-shadow: 0 3px 10px rgba(74, 53, 37, 0.25);
}

.login-visual-title {
    margin: 0 0 8px;
    font-size: 2rem;
    font-weight: 800;
    line-height: 1.15;
    letter-spacing: 0.2px;
    text-shadow: 0 1px 6px rgba(74, 53, 37, 0.25);
}

.login-visual-text {
    margin: 0;
    max-width: 260px;
    font-size: 1.02rem;
    line-height: 1.45;
    color: rgba(255, 255, 255, 0.95);
    text-shadow: 0 1px 4px rgba(74, 53, 37, 0.22);
}

/* =========================================================
   TABLET & PHONES — hide the brown panel, show only the form
========================================================= */
@media (max-width: 991.98px) {
    .login-page {
        min-height: calc(100vh - 110px);
        padding: 28px 16px 40px;
    }

    .login-card {
        max-width: 460px;
        grid-template-columns: 1fr;
        border-radius: 16px;
    }

    .login-visual {
        display: none;
    }

    .login-mobile-logo {
        display: block;
    }

    .login-form-panel {
        padding: 34px 30px 32px;
    }
}

/* =========================================================
   PHONES
========================================================= */
@media (max-width: 767.98px) {
    .login-page {
        min-height: calc(100vh - 92px);
        padding: 20px 12px 30px;
        align-items: flex-start;
    }

    .login-form-panel {
        padding: 28px 20px 28px;
    }

    .login-title {
        font-size: 1.4rem;
    }
}

/* =========================================================
   SMALL PHONES
========================================================= */
@media (max-width: 360px) {
    .login-page {
        padding-left: 9px;
        padding-right: 9px;
    }

    .login-form-panel {
        padding: 24px 15px 24px;
    }

    .login-input {
        min-height: 38px;
        font-size: 0.8rem;
        padding: 7px 10px;
    }
}
</style>

<div class="login-page">
    <div class="login-card">

        <!-- Left: form -->
        <section class="login-form-panel">

            <img
                src="../assets/images/logo.png"
                alt="Local Milktea House Logo"
                class="login-mobile-logo"
            >

            <h1 class="login-title">Log In</h1>

            <p class="login-subtitle">
                Please sign in to continue
            </p>

            <?php if ($error): ?>
                <div class="login-alert text-center" role="alert">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" novalidate>

                <div class="mb-3">
                    <label
                        for="loginEmail"
                        class="login-form-label"
                    >
                        Email address
                    </label>

                    <input
                        type="email"
                        id="loginEmail"
                        name="email"
                        class="login-input"
                        placeholder="juan@email.com"
                        autocomplete="email"
                        value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"
                        required
                    >
                </div>

                <div class="mb-2">
                    <label
                        for="loginPassword"
                        class="login-form-label"
                    >
                        Password
                    </label>

                    <div class="password-field">
                        <input
                            type="password"
                            id="loginPassword"
                            name="password"
                            class="login-input<?= $password_error ? ' login-input-error' : '' ?>"
                            placeholder="••••••••"
                            autocomplete="current-password"
                            <?php if ($password_error): ?>
                            aria-invalid="true"
                            aria-describedby="loginPasswordError"
                            <?php endif; ?>
                            required
                        >

                        <button
                            type="button"
                            class="password-toggle"
                            id="loginPasswordToggle"
                            aria-label="Show password"
                            aria-controls="loginPassword"
                            aria-pressed="false"
                        >
                            <i class="bi bi-eye" aria-hidden="true"></i>
                        </button>
                    </div>

                    <?php if ($password_error): ?>
                        <div
                            class="login-field-error"
                            id="loginPasswordError"
                            role="alert"
                        >
                            <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
                            <span><?= htmlspecialchars($password_error) ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="text-end mb-3">
                    <a href="forgot-password.php" class="login-forgot">
                        Forgot password?
                    </a>
                </div>

                <button
                    type="submit"
                    class="btn btn-localitea btn-localitea-primary"
                >
                    Log in
                </button>

            </form>

            <div class="login-divider">or</div>

            <a
                href="../customer/index.php?guest=1"
                class="btn btn-localitea btn-localitea-outline mb-3"
            >
                Order as Guest
            </a>

            <div class="login-signup">
                Don't have an account?
                <a href="register.php">Sign up</a>
            </div>

        </section>

        <!-- Right: brand panel (hidden on tablet / phone) -->
        <aside class="login-visual">

            <img
                src="../assets/images/logo.png"
                alt="Local Milktea House Logo"
                class="login-logo"
            >

            <h2 class="login-visual-title">
                Welcome<br>Back!
            </h2>

            <p class="login-visual-text">
                Log in to order ahead and pick up your favorite milk tea at the store.
            </p>

        </aside>

    </div>
</div>

<script>
(function () {
    const passwordInput = document.getElementById('loginPassword');
    const passwordToggle = document.getElementById('loginPasswordToggle');

    if (!passwordInput || !passwordToggle) {
        return;
    }

    passwordToggle.addEventListener('click', function () {
        const isHidden = passwordInput.type === 'password';

        passwordInput.type = isHidden ? 'text' : 'password';
        passwordToggle.setAttribute(
            'aria-label',
            isHidden ? 'Hide password' : 'Show password'
        );
        passwordToggle.setAttribute(
            'aria-pressed',
            isHidden ? 'true' : 'false'
        );

        const icon = passwordToggle.querySelector('i');

        if (icon) {
            icon.classList.toggle('bi-eye', !isHidden);
            icon.classList.toggle('bi-eye-slash', isHidden);
        }

        passwordInput.focus({ preventScroll: true });
    });
})();
</script>

<!-- Bootstrap 5.3.3 JavaScript (required for the navbar toggler and dropdowns on phone/tablet) -->
<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

<script>

document.addEventListener('DOMContentLoaded', function () {
    const email = document.getElementById('loginEmail');
    const password = document.getElementById('loginPassword');
    const error = document.getElementById('loginPasswordError');

    if (!password || !error) {
        return;
    }

    function clearError() {
        error.remove();
        password.classList.remove('login-input-error');
        password.removeAttribute('aria-invalid');
        password.removeAttribute('aria-describedby');
    }

    password.addEventListener('input', clearError);

    if (email) {
        email.addEventListener('input', clearError);
    }

    password.focus({ preventScroll: true });
    password.select();
});
</script>