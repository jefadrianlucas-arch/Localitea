<?php

require_once '../includes/db.php';

$message = '';
$messageType = 'danger';

$token = trim($_GET['token'] ?? '');

if ($token === '') {
    $message = 'Invalid verification link.';
} else {

    $tokenHash = hash('sha256', $token);

    $stmt = $pdo->prepare("
        SELECT id, full_name, email, email_verified_at, verification_expires_at
        FROM customers
        WHERE verification_token = ?
        LIMIT 1
    ");

    $stmt->execute([$tokenHash]);

    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {

        $message = 'Invalid or already used verification link.';

    } elseif (!empty($customer['email_verified_at'])) {

        $message = 'Your email address is already verified.';
        $messageType = 'success';

    } elseif (
        empty($customer['verification_expires_at']) ||
        strtotime($customer['verification_expires_at']) < time()
    ) {

        $message = 'This verification link has expired. Please register again or request a new verification email.';

    } else {

        $update = $pdo->prepare("
            UPDATE customers
            SET
                email_verified_at = NOW(),
                verification_token = NULL,
                verification_expires_at = NULL
            WHERE id = ?
        ");

        if ($update->execute([$customer['id']])) {

            $message = 'Your email has been successfully verified! You can now log in.';
            $messageType = 'success';

        } else {

            $message = 'Something went wrong while verifying your email.';
        }
    }
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
?>

<style>
    body {
        background-color: #FDFBF7;
    }

    .verification-wrapper {
        min-height: calc(100vh - 140px);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1rem;
    }

    .verification-card {
        background: #ffffff;
        border: 1px solid #E6DEC9;
        border-radius: 16px;
        box-shadow: 0 6px 16px rgba(0,0,0,.04);
        width: 100%;
        max-width: 450px;
        padding: 2.5rem;
        text-align: center;
    }

    .btn-brown-custom {
        background: #4A3525;
        border: none;
        border-radius: 50px;
        padding: 0.55rem 1.5rem;
        font-size: 0.85rem;
        font-weight: 600;
        color: #ffffff;
    }

    .btn-brown-custom:hover {
        background: #332317;
        color: #ffffff;
    }
</style>

<div class="container verification-wrapper">

    <div class="verification-card">

        <div class="mb-3">
            <?php if ($messageType === 'success'): ?>

                <i class="bi bi-check-circle-fill text-success"
                   style="font-size: 3rem;"></i>

            <?php else: ?>

                <i class="bi bi-exclamation-circle-fill text-danger"
                   style="font-size: 3rem;"></i>

            <?php endif; ?>
        </div>

        <h4 class="fw-bold mb-2" style="color: #2c221e;">
            <?= $messageType === 'success' ? 'Email Verified!' : 'Verification Failed' ?>
        </h4>

        <p class="text-muted small mb-4">
            <?= htmlspecialchars($message) ?>
        </p>

        <?php if ($messageType === 'success'): ?>

            <a href="login.php"
               class="btn btn-brown-custom">
                Go to Login
            </a>

        <?php else: ?>

            <a href="register.php"
               class="btn btn-brown-custom">
                Back to Registration
            </a>

        <?php endif; ?>

    </div>

</div>