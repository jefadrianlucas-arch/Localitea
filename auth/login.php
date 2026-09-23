<?php
session_start();
require_once '../includes/db.php';

$error = '';

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
        $error = "Invalid email or password.";
    }
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
?>

<style>
/* =========================================================
   LOCALITEA LOGIN PAGE
   Responsive across phones, tablets, laptops and desktop
========================================================= */

body {
    background: #FBF8F4;
    color: #2C221E;
    overflow-x: hidden;
}

.login-page {
    width: 100%;
    min-height: calc(100vh - 140px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 32px 16px 40px;
    box-sizing: border-box;
}

.login-card {
    width: 100%;
    max-width: 420px;
    background: #FFFFFF;
    border: 2px solid #6F4E37;
    border-radius: 18px;
    box-shadow: 0 8px 24px rgba(74, 53, 37, 0.10);
    padding: 30px;
    box-sizing: border-box;
}

/* Logo */
.login-logo {
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
.login-title {
    color: #2C221E;
    font-size: 1.25rem;
    font-weight: 800;
    letter-spacing: 0.2px;
    margin-bottom: 4px;
}

.login-subtitle {
    color: #756960;
    font-size: 0.82rem;
    margin-bottom: 0;
}

/* Error */
.login-alert {
    border: 1px solid #B85C5C;
    border-radius: 10px;
    background: #FFF3F3;
    color: #8B3030;
    font-size: 0.78rem;
    line-height: 1.4;
    padding: 9px 11px;
    margin-bottom: 18px;
}

/* Labels */
.login-form-label {
    display: block;
    color: #4A3525;
    font-size: 0.78rem;
    font-weight: 700;
    margin-bottom: 6px;
}

/* Inputs */
.login-input {
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

.login-input::placeholder {
    color: #A89D95;
}

.login-input:hover {
    border-color: #8B6F5A;
}

.login-input:focus {
    border-color: #6F4E37;
    background: #FFFFFF;
    box-shadow: 0 0 0 3px rgba(111, 78, 55, 0.13);
    outline: none;
}

/* Password field with eye toggle */
.password-field {
    position: relative;
}

.password-field .login-input {
    padding-right: 44px;
}

.password-toggle {
    position: absolute;
    top: 50%;
    right: 10px;
    transform: translateY(-50%);
    width: 32px;
    height: 32px;
    padding: 0;
    border: 0;
    background: transparent;
    color: #756960;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 7px;
    cursor: pointer;
    z-index: 2;
    transition: color .2s ease, background-color .2s ease;
}

.password-toggle:hover {
    color: #6F4E37;
    background: #F5F0EB;
}

.password-toggle:focus-visible {
    outline: 2px solid #6F4E37;
    outline-offset: 1px;
}

.password-toggle i {
    font-size: 1rem;
    line-height: 1;
}

@media (max-width: 360px) {
    .password-field .login-input {
        padding-right: 41px;
    }

    .password-toggle {
        right: 8px;
        width: 30px;
        height: 30px;
    }
}

/* Forgot password */
.login-forgot {
    color: #756960;
    font-size: 0.74rem;
    font-weight: 600;
    text-decoration: none;
}

.login-forgot:hover {
    color: #6F4E37;
    text-decoration: underline;
}

/* Buttons */
.btn-localitea {
    width: 100%;
    min-height: 43px;
    border-radius: 50px;
    padding: 9px 16px;
    font-size: 0.84rem;
    font-weight: 700;
    letter-spacing: 0.2px;
    transition: transform .2s ease, box-shadow .2s ease, background-color .2s ease;
}

.btn-localitea-primary {
    background: #6F4E37;
    border: 1.5px solid #6F4E37;
    color: #FFFFFF;
    box-shadow: 0 3px 8px rgba(111, 78, 55, 0.18);
}

.btn-localitea-primary:hover {
    background: #4A3525;
    border-color: #4A3525;
    color: #FFFFFF;
    transform: translateY(-1px);
    box-shadow: 0 5px 12px rgba(74, 53, 37, 0.22);
}

.btn-localitea-outline {
    background: #FFFFFF;
    border: 1.5px solid #6F4E37;
    color: #6F4E37;
}

.btn-localitea-outline:hover {
    background: #6F4E37;
    border-color: #6F4E37;
    color: #FFFFFF;
    transform: translateY(-1px);
    box-shadow: 0 5px 12px rgba(74, 53, 37, 0.16);
}

/* Divider */
.login-divider {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 18px 0;
    color: #9A8C83;
    font-size: 0.68rem;
    font-weight: 600;
}

.login-divider::before,
.login-divider::after {
    content: "";
    flex: 1;
    height: 1px;
    background: #D8C6B8;
}

/* Signup text */
.login-signup {
    color: #756960;
    font-size: 0.78rem;
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

/* =========================================================
   TABLET
========================================================= */
@media (max-width: 991.98px) {
    .login-page {
        min-height: calc(100vh - 110px);
        padding: 28px 16px 36px;
    }

    .login-card {
        max-width: 410px;
        padding: 28px;
    }
}

/* =========================================================
   PHONES
========================================================= */
@media (max-width: 575.98px) {
    .login-page {
        min-height: calc(100vh - 92px);
        padding: 20px 12px 30px;
        align-items: flex-start;
    }

    .login-card {
        max-width: 100%;
        padding: 23px 18px;
        border-radius: 16px;
    }

    .login-logo {
        width: 58px;
        height: 58px;
        margin-bottom: 10px;
    }

    .login-title {
        font-size: 1.12rem;
    }

    .login-subtitle {
        font-size: 0.77rem;
    }

    .login-input {
        min-height: 42px;
        font-size: 0.82rem;
    }

    .btn-localitea {
        min-height: 42px;
        font-size: 0.82rem;
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

    .login-card {
        padding: 20px 15px;
        border-radius: 15px;
    }

    .login-logo {
        width: 54px;
        height: 54px;
    }

    .login-title {
        font-size: 1.05rem;
    }

    .login-subtitle {
        font-size: 0.74rem;
    }

    .login-form-label {
        font-size: 0.74rem;
    }

    .login-input {
        min-height: 40px;
        font-size: 0.8rem;
        padding: 8px 10px;
    }

    .btn-localitea {
        min-height: 40px;
        font-size: 0.8rem;
    }
}
</style>

<div class="login-page">
    <div class="login-card">

        <!-- Logo & Heading -->
        <div class="text-center mb-4">
            <img
                src="assets/images/logo.png"
                alt="Local Milktea House Logo"
                class="login-logo"
            >

            <h1 class="login-title">Welcome Back!</h1>

            <p class="login-subtitle">
                Please sign in to continue
            </p>
        </div>

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
                        class="login-input"
                        placeholder="••••••••"
                        autocomplete="current-password"
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
            </div>

            <div class="text-end mb-4">
                <a href="#" class="login-forgot">
                    Forgot password?
                </a>
            </div>

            <button
                type="submit"
                class="btn btn-localitea btn-localitea-primary mb-2"
            >
                Log In
            </button>

        </form>

        <div class="login-divider">
            <span>OR</span>
        </div>

        <a
            href="../customer/index.php?guest=1"
            class="btn btn-localitea btn-localitea-outline text-center text-decoration-none mb-3"
        >
            Order as Guest
        </a>

        <div class="text-center login-signup">
            Don't have an account?
            <a href="register.php">Sign up</a>
        </div>

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

