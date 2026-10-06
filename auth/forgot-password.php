<?php

session_start();
require_once '../includes/db.php';
require_once '../includes/mailer.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim($_POST['email'] ?? '');

    if ($email === '') {
        $error = 'Please enter your email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $stmt = $pdo->prepare("
            SELECT id, full_name, email
            FROM customers
            WHERE email = ?
              AND is_active = 1
              AND is_archived = 0
              AND email_verified_at IS NOT NULL
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);

        // Do not reveal whether the email exists in the system.
        $success = 'If an account exists with that email address, password reset instructions will be sent to it.';

        if ($customer) {
            try {
                $rawToken = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $rawToken);
                $expiresAt = $pdo->query("SELECT DATE_ADD(NOW(), INTERVAL 1 HOUR)")->fetchColumn();

                $update = $pdo->prepare("
                    UPDATE customers
                    SET reset_token = ?,
                        reset_expires_at = ?
                    WHERE id = ?
                ");
                $update->execute([
                    $tokenHash,
                    $expiresAt,
                    (int)$customer['id']
                ]);

                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                    ? 'https'
                    : 'http';

                $basePath = dirname(dirname($_SERVER['SCRIPT_NAME']));
                $basePath = rtrim(str_replace('\\', '/', $basePath), '/');

                $resetLink =
                    $scheme . '://' .
                    $_SERVER['HTTP_HOST'] .
                    $basePath .
                    '/auth/reset-password.php?token=' .
                    rawurlencode($rawToken);

                sendPasswordResetEmail(
                    $customer['email'],
                    $customer['full_name'],
                    $resetLink
                );
            } catch (Throwable $e) {
                error_log('Localitea password reset error: ' . $e->getMessage());
                $error = 'We could not send the reset email right now. Please try again later.';
                $success = '';
            }
        }
    }
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
?>

<style>
body {
    background: #FBF8F4;
    color: #2C221E;
    overflow-x: hidden;
}

.forgot-page {
    width: 100%;
    min-height: calc(100vh - 140px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 32px 16px 40px;
    box-sizing: border-box;
}

.forgot-card {
    width: 100%;
    max-width: 420px;
    background: #FFFFFF;
    border: 2px solid #6F4E37;
    border-radius: 18px;
    box-shadow: 0 8px 24px rgba(74, 53, 37, 0.10);
    padding: 30px;
    box-sizing: border-box;
}

.forgot-logo {
    width: 64px;
    height: 64px;
    display: block;
    margin: 0 auto 12px;
    object-fit: contain;
    border-radius: 50%;
    border: 1px solid #D8C6B8;
    box-shadow: 0 3px 10px rgba(74, 53, 37, 0.10);
}

.forgot-title {
    color: #2C221E;
    font-size: 1.25rem;
    font-weight: 800;
    margin-bottom: 4px;
}

.forgot-subtitle {
    color: #756960;
    font-size: 0.82rem;
    line-height: 1.5;
    margin-bottom: 0;
}

.forgot-alert {
    border-radius: 10px;
    font-size: 0.78rem;
    line-height: 1.4;
    padding: 9px 11px;
    margin-bottom: 18px;
}

.forgot-alert-error {
    border: 1px solid #B85C5C;
    background: #FFF3F3;
    color: #8B3030;
}

.forgot-alert-success {
    border: 1px solid #86B89A;
    background: #F2FBF5;
    color: #28643D;
}

.forgot-form-label {
    display: block;
    color: #4A3525;
    font-size: 0.78rem;
    font-weight: 700;
    margin-bottom: 6px;
}

.forgot-input {
    width: 100%;
    min-height: 43px;
    border: 1.5px solid #B8A08A;
    border-radius: 9px;
    background: #FFFFFF;
    color: #2C221E;
    padding: 9px 12px;
    font-size: 0.84rem;
    box-sizing: border-box;
    transition: border-color .2s ease, box-shadow .2s ease;
}

.forgot-input:focus {
    border-color: #6F4E37;
    box-shadow: 0 0 0 3px rgba(111, 78, 55, 0.13);
    outline: none;
}

.btn-localitea {
    width: 100%;
    min-height: 43px;
    border-radius: 50px;
    padding: 9px 16px;
    font-size: 0.84rem;
    font-weight: 700;
}

.btn-localitea-primary {
    background: #6F4E37;
    border: 1.5px solid #6F4E37;
    color: #FFFFFF;
}

.btn-localitea-primary:hover {
    background: #4A3525;
    border-color: #4A3525;
    color: #FFFFFF;
}

.forgot-back {
    display: inline-block;
    margin-top: 16px;
    color: #6F4E37;
    font-size: 0.78rem;
    font-weight: 700;
    text-decoration: none;
}

.forgot-back:hover {
    color: #4A3525;
    text-decoration: underline;
}

@media (max-width: 575.98px) {
    .forgot-page {
        min-height: calc(100vh - 92px);
        padding: 20px 12px 30px;
        align-items: flex-start;
    }

    .forgot-card {
        max-width: 100%;
        padding: 23px 18px;
        border-radius: 16px;
    }
}
</style>

<div class="forgot-page">
    <div class="forgot-card">

        <div class="text-center mb-4">
            <img
                src="../assets/images/logo.png"
                alt="Local Milktea House Logo"
                class="forgot-logo"
            >

            <h1 class="forgot-title">Forgot Password?</h1>

            <p class="forgot-subtitle">
                Enter the email address connected to your customer account.
            </p>
        </div>

        <?php if ($error): ?>
            <div class="forgot-alert forgot-alert-error text-center" role="alert">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="forgot-alert forgot-alert-success text-center" role="alert">
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <form method="POST" novalidate>
            <div class="mb-3">
                <label for="forgotEmail" class="forgot-form-label">
                    Email address
                </label>

                <input
                    type="email"
                    id="forgotEmail"
                    name="email"
                    class="forgot-input"
                    placeholder="juan@email.com"
                    autocomplete="email"
                    value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                    required
                >
            </div>

            <button
                type="submit"
                class="btn btn-localitea btn-localitea-primary"
            >
                Send Reset Instructions
            </button>
        </form>

        <div class="text-center">
            <a href="login.php" class="forgot-back">
                <i class="bi bi-arrow-left me-1"></i>
                Back to Login
            </a>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<?php
require_once '../includes/footer.php';
?>
