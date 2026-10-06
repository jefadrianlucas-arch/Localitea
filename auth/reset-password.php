<?php

session_start();
require_once '../includes/db.php';

$error = '';
$success = '';
$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$customer = null;

if ($token === '') {
    $error = 'Invalid or missing password reset link.';
} else {
    $tokenHash = hash('sha256', $token);

    $stmt = $pdo->prepare("
        SELECT id, full_name, email
        FROM customers
        WHERE reset_token = ?
          AND reset_expires_at IS NOT NULL
          AND reset_expires_at > NOW()
          AND is_active = 1
          AND is_archived = 0
          AND email_verified_at IS NOT NULL
        LIMIT 1
    ");
    $stmt->execute([$tokenHash]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        $error = 'This password reset link is invalid or has expired.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $customer) {
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        $update = $pdo->prepare("
            UPDATE customers
            SET password = ?,
                reset_token = NULL,
                reset_expires_at = NULL,
                updated_at = NOW()
            WHERE id = ?
              AND reset_token = ?
              AND reset_expires_at > NOW()
        ");

        $update->execute([
            $hashedPassword,
            (int)$customer['id'],
            $tokenHash
        ]);

        if ($update->rowCount() !== 1) {
            $error = 'The password reset link is no longer valid. Please request a new one.';
        } else {
            $success = 'Your password has been successfully changed. You can now log in with your new password.';
            $customer = null;
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

.reset-page {
    width: 100%;
    min-height: calc(100vh - 140px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 32px 16px 40px;
}

.reset-card {
    width: 100%;
    max-width: 420px;
    background: #FFFFFF;
    border: 2px solid #6F4E37;
    border-radius: 18px;
    box-shadow: 0 8px 24px rgba(74, 53, 37, 0.10);
    padding: 30px;
}

.reset-title {
    color: #2C221E;
    font-size: 1.25rem;
    font-weight: 800;
    margin-bottom: 5px;
}

.reset-subtitle {
    color: #756960;
    font-size: 0.82rem;
    line-height: 1.5;
}

.reset-label {
    display: block;
    color: #4A3525;
    font-size: 0.78rem;
    font-weight: 700;
    margin-bottom: 6px;
}

.reset-input {
    width: 100%;
    min-height: 43px;
    border: 1.5px solid #B8A08A;
    border-radius: 9px;
    padding: 9px 12px;
    font-size: 0.84rem;
    box-sizing: border-box;
}

.reset-input:focus {
    border-color: #6F4E37;
    box-shadow: 0 0 0 3px rgba(111, 78, 55, 0.13);
    outline: none;
}

.reset-button,
.reset-login-button {
    width: 100%;
    min-height: 43px;
    border-radius: 50px;
    font-size: .84rem;
    font-weight: 700;
}

.reset-button {
    background: #6F4E37;
    border: 1px solid #6F4E37;
    color: #FFFFFF;
}

.reset-button:hover {
    background: #4A3525;
    border-color: #4A3525;
    color: #FFFFFF;
}

.reset-login-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    background: #FFFFFF;
    border: 1px solid #6F4E37;
    color: #6F4E37;
}

.reset-login-button:hover {
    background: #6F4E37;
    color: #FFFFFF;
}

@media (max-width: 575.98px) {
    .reset-page {
        min-height: calc(100vh - 92px);
        padding: 20px 12px 30px;
        align-items: flex-start;
    }

    .reset-card {
        max-width: 100%;
        padding: 23px 18px;
        border-radius: 16px;
    }
}
</style>

<div class="reset-page">
    <div class="reset-card">

        <div class="text-center mb-4">
            <h1 class="reset-title">Reset Password</h1>
            <p class="reset-subtitle mb-0">
                Create a new password for your Localitea customer account.
            </p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger small" role="alert">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success small" role="alert">
                <?= htmlspecialchars($success) ?>
            </div>

            <a href="login.php" class="reset-login-button">
                Back to Login
            </a>
        <?php elseif ($customer): ?>
            <form method="POST" novalidate>
                <input
                    type="hidden"
                    name="token"
                    value="<?= htmlspecialchars($token) ?>"
                >

                <div class="mb-3">
                    <label for="newPassword" class="reset-label">
                        New Password
                    </label>

                    <input
                        type="password"
                        id="newPassword"
                        name="password"
                        class="reset-input"
                        minlength="8"
                        autocomplete="new-password"
                        required
                    >
                </div>

                <div class="mb-3">
                    <label for="confirmPassword" class="reset-label">
                        Confirm New Password
                    </label>

                    <input
                        type="password"
                        id="confirmPassword"
                        name="confirm_password"
                        class="reset-input"
                        minlength="8"
                        autocomplete="new-password"
                        required
                    >
                </div>

                <button type="submit" class="reset-button">
                    Change Password
                </button>
            </form>
        <?php endif; ?>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<?php
require_once '../includes/footer.php';
?>
