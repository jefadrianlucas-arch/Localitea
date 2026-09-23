<?php
/*
 * =========================================================
 * LOCALITEA STAFF / ADMIN ACCOUNT VERIFICATION
 * =========================================================
 *
 * Accounts created by an administrator are not given a password.
 * The employee verifies the real email address from the invitation
 * link and creates their own password here.
 */

require_once '../includes/db.php';

$error = '';
$success = '';
$account = null;
$accountSource = null;
$tokenHash = null;
$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));

if ($token === '' || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
    $error = 'This verification link is invalid.';
} else {
    $tokenHash = hash('sha256', $token);

    try {
        $lookupUsers = $pdo->prepare("
            SELECT
                id,
                name AS full_name,
                email,
                role,
                verification_expires_at,
                is_archived,
                email_verified_at
            FROM users
            WHERE verification_token = ?
            LIMIT 1
        ");
        $lookupUsers->execute([$tokenHash]);
        $account = $lookupUsers->fetch(PDO::FETCH_ASSOC);

        if ($account) {
            $accountSource = 'users';
        } else {
            $lookupAdmins = $pdo->prepare("
                SELECT
                    id,
                    full_name,
                    email,
                    role,
                    verification_expires_at,
                    is_archived,
                    email_verified_at
                FROM admins
                WHERE verification_token = ?
                LIMIT 1
            ");
            $lookupAdmins->execute([$tokenHash]);
            $account = $lookupAdmins->fetch(PDO::FETCH_ASSOC);

            if ($account) {
                $accountSource = 'admins';
            }
        }

        if (!$account) {
            $error = 'This verification link is invalid or has already been used.';
        } elseif ((int)$account['is_archived'] === 1) {
            $error = 'This account has been archived. Please contact the administrator.';
        } elseif (!empty($account['email_verified_at'])) {
            $error = 'This account has already been verified. You may proceed to the login page.';
        } elseif (
            empty($account['verification_expires_at']) ||
            strtotime((string)$account['verification_expires_at']) < time()
        ) {
            $error = 'This verification link has expired. Please ask the administrator to resend the verification email.';
        }
    } catch (Throwable $e) {
        $error = 'Unable to process the verification link right now.';
    }
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    $account &&
    $accountSource &&
    $error === ''
) {
    $password = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        try {
            if ($tokenHash === null) {
                throw new RuntimeException('This verification link is invalid.');
            }

            $pdo->beginTransaction();

            $nameColumn = $accountSource === 'users' ? 'name' : 'full_name';

            $verifyStmt = $pdo->prepare("
                SELECT
                    id,
                    {$nameColumn} AS full_name,
                    email,
                    role,
                    verification_expires_at,
                    is_archived,
                    email_verified_at
                FROM {$accountSource}
                WHERE verification_token = ?
                LIMIT 1
                FOR UPDATE
            ");
            $verifyStmt->execute([$tokenHash]);
            $current = $verifyStmt->fetch(PDO::FETCH_ASSOC);

            if (!$current) {
                throw new RuntimeException('This verification link is invalid or has already been used.');
            }

            if ((int)$current['is_archived'] === 1) {
                throw new RuntimeException('This account has been archived. Please contact the administrator.');
            }

            if (!empty($current['email_verified_at'])) {
                throw new RuntimeException('This account has already been verified.');
            }

            if (
                empty($current['verification_expires_at']) ||
                strtotime((string)$current['verification_expires_at']) < time()
            ) {
                throw new RuntimeException('This verification link has expired. Please ask the administrator to resend the verification email.');
            }

            $updateStmt = $pdo->prepare("
                UPDATE {$accountSource}
                SET
                    password = ?,
                    email_verified_at = NOW(),
                    verification_token = NULL,
                    verification_expires_at = NULL
                WHERE id = ?
            ");

            $updateStmt->execute([
                password_hash($password, PASSWORD_DEFAULT),
                (int)$current['id']
            ]);

            $pdo->commit();

            header('Location: login.php?account_activated=1');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = $e->getMessage() !== ''
                ? $e->getMessage()
                : 'Unable to activate the account right now.';
        }
    }
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
?>

<style>
    body {
        background: #FDFBF7;
    }

    .verification-wrapper {
        min-height: calc(100vh - 140px);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 30px 15px;
    }

    .verification-card {
        width: 100%;
        max-width: 460px;
        background: #ffffff;
        border: 1px solid #B8A08A;
        border-radius: 16px;
        box-shadow: 0 8px 24px rgba(44,34,30,.08);
        padding: 32px;
    }

    .verification-icon {
        width: 54px;
        height: 54px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: #F3E8DD;
        border: 2px solid #8C6749;
        color: #4A3525;
        font-size: 22px;
    }

    .verification-input {
        border: 1px solid #8F8074;
        border-radius: 8px;
        padding: 10px 12px;
    }

    .verification-input:focus {
        border-color: #4A3525;
        box-shadow: 0 0 0 0.15rem rgba(74,53,37,.15);
    }

    .verification-role {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 5px 10px;
        border-radius: 999px;
        background: #F3E8DD;
        color: #4A3525;
        border: 1px solid #B8A08A;
        font-size: .76rem;
        font-weight: 700;
    }
</style>

<div class="container verification-wrapper">
    <div class="verification-card">

        <div class="text-center mb-4">
            <div class="verification-icon mb-3">
                <i class="bi bi-shield-check"></i>
            </div>

            <h3 class="fw-bold mb-1" style="color:#2c221e;">
                <?= $account ? 'Verify Your Account' : 'Account Verification' ?>
            </h3>

            <p class="text-muted mb-0">
                <?= $account
                    ? 'Verify your email and create your password to finish setting up your account.'
                    : 'We could not validate this verification link.'
                ?>
            </p>
        </div>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
                <i class="bi bi-exclamation-triangle-fill mt-1"></i>
                <div><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            </div>

            <?php if (str_contains($error, 'expired') || str_contains($error, 'resend')): ?>
                <div class="text-center mt-3">
                    <a href="login.php" class="btn btn-outline-secondary">
                        Back to Login
                    </a>
                </div>
            <?php elseif (str_contains($error, 'already been verified')): ?>
                <div class="text-center mt-3">
                    <a href="login.php" class="btn btn-primary">
                        Go to Login
                    </a>
                </div>
            <?php endif; ?>

        <?php elseif ($account): ?>

            <div class="rounded-3 p-3 mb-4" style="background:#FDFBF7;border:1px solid #D0C0B1;">
                <div class="fw-semibold" style="color:#2c221e;">
                    <?= htmlspecialchars((string)$account['full_name'], ENT_QUOTES, 'UTF-8') ?>
                </div>
                <div class="small text-muted mt-1">
                    <?= htmlspecialchars((string)$account['email'], ENT_QUOTES, 'UTF-8') ?>
                </div>
                <div class="mt-2">
                    <span class="verification-role">
                        <i class="bi bi-person-badge"></i>
                        <?= htmlspecialchars(ucwords((string)$account['role']), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </div>
            </div>

            <form method="POST">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">

                <div class="mb-3">
                    <label class="form-label fw-semibold" for="password">
                        Create Password
                    </label>
                    <input
                        type="password"
                        name="password"
                        id="password"
                        class="form-control verification-input"
                        minlength="8"
                        autocomplete="new-password"
                        required
                    >
                    <div class="form-text">
                        Use at least 8 characters.
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-semibold" for="confirm_password">
                        Confirm Password
                    </label>
                    <input
                        type="password"
                        name="confirm_password"
                        id="confirm_password"
                        class="form-control verification-input"
                        minlength="8"
                        autocomplete="new-password"
                        required
                    >
                </div>

                <button type="submit" class="btn btn-dark w-100 py-2">
                    <i class="bi bi-check-circle me-1"></i>
                    Verify & Create Account
                </button>
            </form>

        <?php endif; ?>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
