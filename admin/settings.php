<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once '../includes/db.php';
require_once '../includes/mailer.php';

if (
    !isset($_SESSION['user_role']) ||
    ($_SESSION['user_role'] ?? '') !== 'admin'
) {
    header("Location: ../auth/login.php");
    exit;
}

/*
 * Admin ID is used to protect the currently logged-in administrator
 * from being archived or having their administrator role removed
 * from User Account Settings.
 */
$loggedInAdminId = (int)(
    $_SESSION['admin_id']
    ?? $_SESSION['user_id']
    ?? 0
);

/* =========================================================
   PRODUCT TYPES
========================================================= */

$productTypes = [
    'Classic Milktea',
    'Premium Milktea',
    'Fruit Tea',
    'Cold Brew and Premium Iced Coffee',
    'Frappe',
    'Sip and Snack',
    'Promo and Bundles'
];

$errors = [];

/* =========================================================
   SELECTED CATEGORY
========================================================= */

$selectedCategory = $_GET['category'] ?? 'All Products';

/* =========================================================
   SETTINGS TAB
========================================================= */
$selectedTab = $_GET['tab'] ?? 'products';
if (!in_array($selectedTab, ['products', 'promotions', 'user_accounts'], true)) {
    $selectedTab = 'products';
}


/* =========================================================
   USER ACCOUNT SETTINGS
========================================================= */

/*
 * User Account Settings manages customer accounts from the customers
 * table, staff accounts from users, and administrator accounts from admins.
 * Each account source provides an is_archived field.
 */
$userAccountArchiveSupported = true;

$userAccountStatus = strtolower(
    trim((string)($_GET['user_account_status'] ?? 'active'))
);

if (!in_array($userAccountStatus, ['active', 'archived'], true)) {
    $userAccountStatus = 'active';
}

$userAccountFilter = strtolower(
    trim((string)($_GET['user_account_filter'] ?? 'all'))
);

if (!in_array(
    $userAccountFilter,
    ['all', 'customers', 'staff', 'admins'],
    true
)) {
    $userAccountFilter = 'all';
}

$userAccountSearch = trim(
    (string)($_GET['user_account_search'] ?? '')
);

$userAccounts = [];

$userAccountCounts = [
    'all' => 0,
    'customers' => 0,
    'staff' => 0,
    'admins' => 0,
];

$userAccountArchivedCount = 0;
$userAccountPendingCount = 0;

/* ---------------------------------------------------------
   ACCOUNT ACTION HELPERS
--------------------------------------------------------- */

function settingsUserAccountRedirect(
    string $status = 'active',
    string $filter = 'all',
    string $search = ''
): never {
    $params = [
        'tab' => 'user_accounts',
        'user_account_status' => $status,
        'user_account_filter' => $filter,
    ];

    if ($search !== '') {
        $params['user_account_search'] = $search;
    }

    header('Location: settings.php?' . http_build_query($params));
    exit;
}

function settingsUserAccountEmailExists(
    PDO $pdo,
    string $table,
    string $email,
    int $excludeId = 0
): bool {
    if (!in_array($table, ['customers', 'users', 'admins'], true)) {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM {$table}
        WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))
          AND id <> ?
          AND is_archived = 0
    ");

    $stmt->execute([$email, $excludeId]);

    return (int)$stmt->fetchColumn() > 0;
}

function settingsUserAccountEmailExistsAnywhere(
    PDO $pdo,
    string $email
): bool {
    foreach (['customers', 'users', 'admins'] as $table) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM {$table}
            WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))
              AND is_archived = 0
        ");
        $stmt->execute([$email]);

        if ((int)$stmt->fetchColumn() > 0) {
            return true;
        }
    }

    return false;
}

function settingsUserAccountActiveAdministratorCount(PDO $pdo): int {
    $adminTableCount = $pdo->query("
        SELECT COUNT(*)
        FROM admins
        WHERE role = 'admin'
          AND is_archived = 0
    ")->fetchColumn();

    $usersTableCount = $pdo->query("
        SELECT COUNT(*)
        FROM users
        WHERE role = 'admin'
          AND is_archived = 0
    ")->fetchColumn();

    return (int)$adminTableCount + (int)$usersTableCount;
}

/* ---------------------------------------------------------
   ADD ACCOUNT
--------------------------------------------------------- */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['add_user_account'])
) {
    $accountRole = strtolower(trim((string)($_POST['new_account_role'] ?? '')));
    $name = trim((string)($_POST['new_account_name'] ?? ''));
    $email = strtolower(trim((string)($_POST['new_account_email'] ?? '')));
    $phone = trim((string)($_POST['new_account_phone'] ?? ''));

    $accountCreateErrors = [];

    if ($accountRole !== 'staff') {
        $accountCreateErrors[] = 'New accounts can only be created with the Staff role.';
    }

    if ($name === '') {
        $accountCreateErrors[] = 'Name is required.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $accountCreateErrors[] = 'Please provide a valid email address.';
    }

    if (strlen($phone) > 20) {
        $accountCreateErrors[] = 'Phone number must not exceed 20 characters.';
    }

    if (
        !$accountCreateErrors &&
        settingsUserAccountEmailExistsAnywhere($pdo, $email)
    ) {
        $accountCreateErrors[] = 'That email address is already used by another active account.';
    }

    if (!$accountCreateErrors) {
        try {
            /*
             * The Admin does not create or know the employee's password.
             * A random unusable password hash is stored temporarily. The
             * employee sets their own password after verifying their email.
             */
            $temporaryPasswordHash = password_hash(
                bin2hex(random_bytes(32)),
                PASSWORD_DEFAULT
            );

            $verificationToken = bin2hex(random_bytes(32));
            $verificationTokenHash = hash('sha256', $verificationToken);
            $verificationExpiresAt = date(
                'Y-m-d H:i:s',
                time() + (30 * 60)
            );

            $verificationLink =
                'http://localhost/Localitea_Fixed%20Working%20Staff%20Orders%20No%20Admin%20orders%20yet/auth/verify-staff-admin.php?token=' .
                urlencode($verificationToken);

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO users
                (
                    name,
                    email,
                    phone,
                    password,
                    role,
                    is_archived,
                    email_verified_at,
                    verification_token,
                    verification_expires_at,
                    created_at
                )
                VALUES (?, ?, ?, ?, 'staff', 0, NULL, ?, ?, NOW())
            " );

            $stmt->execute([
                $name,
                $email,
                $phone,
                $temporaryPasswordHash,
                $verificationTokenHash,
                $verificationExpiresAt
            ]);

            /* Use the same verification mailer used by customer registration. */
            sendVerificationEmail(
                $email,
                $name,
                $verificationLink
            );

            $pdo->commit();

            header(
                'Location: settings.php?tab=user_accounts&user_account_add_success=1&user_account_status=active&user_account_filter=all'
            );
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors[] = $e->getMessage() !== ''
                ? 'Failed to create the account: ' . $e->getMessage()
                : 'Failed to create the account because the verification email could not be sent.';
        }
    } else {
        $errors = array_merge($errors, $accountCreateErrors);
    }
}

/* ---------------------------------------------------------
   RESEND ACCOUNT VERIFICATION
--------------------------------------------------------- */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['resend_user_account_verification'])
) {
    $source = trim((string)($_POST['account_source'] ?? ''));
    $accountId = (int)($_POST['account_id'] ?? 0);

    if (!in_array($source, ['users', 'admins'], true) || $accountId <= 0) {
        $errors[] = 'Invalid account for verification resend.';
    } else {
        try {
            $nameColumn = $source === 'users' ? 'name' : 'full_name';

            $stmt = $pdo->prepare("
                SELECT
                    id,
                    {$nameColumn} AS full_name,
                    email,
                    email_verified_at,
                    verification_token,
                    is_archived
                FROM {$source}
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->execute([$accountId]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$account || (int)$account['is_archived'] === 1) {
                throw new RuntimeException('Account not found or already archived.');
            }

            if (!empty($account['email_verified_at']) || empty($account['verification_token'])) {
                throw new RuntimeException('This account has already been verified.');
            }

            $verificationToken = bin2hex(random_bytes(32));
            $verificationTokenHash = hash('sha256', $verificationToken);
            $verificationExpiresAt = date(
                'Y-m-d H:i:s',
                time() + (30 * 60)
            );

            $verificationLink =
                'http://localhost/Localitea_Fixed%20Working%20Staff%20Orders%20No%20Admin%20orders%20yet/auth/verify-staff-admin.php?token=' .
                urlencode($verificationToken);

            $pdo->beginTransaction();

            $update = $pdo->prepare("
                UPDATE {$source}
                SET
                    email_verified_at = NULL,
                    verification_token = ?,
                    verification_expires_at = ?
                WHERE id = ?
                  AND is_archived = 0
            ");
            $update->execute([
                $verificationTokenHash,
                $verificationExpiresAt,
                $accountId
            ]);

            sendVerificationEmail(
                (string)$account['email'],
                (string)$account['full_name'],
                $verificationLink
            );

            $pdo->commit();

            settingsUserAccountRedirect(
                $userAccountStatus,
                $userAccountFilter,
                $userAccountSearch
            );
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors[] = $e->getMessage() !== ''
                ? $e->getMessage()
                : 'Failed to resend the verification email.';
        }
    }
}

/* ---------------------------------------------------------
   EDIT ACCOUNT
--------------------------------------------------------- */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['update_user_account'])
) {
    $source = trim((string)($_POST['account_source'] ?? ''));
    $accountId = (int)($_POST['account_id'] ?? 0);
    $name = trim((string)($_POST['account_name'] ?? ''));
    $email = trim((string)($_POST['account_email'] ?? ''));
    $phone = trim((string)($_POST['account_phone'] ?? ''));
    $role = strtolower(trim((string)($_POST['account_role'] ?? '')));
    $newPassword = (string)($_POST['account_password'] ?? '');

    $accountActionErrors = [];

    if (!in_array($source, ['customers', 'users', 'admins'], true)) {
        $accountActionErrors[] = 'Invalid account source.';
    }

    if ($accountId <= 0) {
        $accountActionErrors[] = 'Invalid account.';
    }

    if ($name === '') {
        $accountActionErrors[] = 'Name is required.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $accountActionErrors[] = 'Please provide a valid email address.';
    }

    if (
        $newPassword !== '' &&
        strlen($newPassword) < 8
    ) {
        $accountActionErrors[] = 'New password must be at least 8 characters.';
    }

    if ($source === 'customers') {
        $role = 'customer';
    } elseif ($source === 'users') {
        if (!in_array($role, ['staff', 'admin'], true)) {
            $accountActionErrors[] =
                'User accounts may only use the Staff or Admin role.';
        }
    }

    if ($source === 'admins') {
        if ($_SESSION['user_role'] !== 'admin') {
            $accountActionErrors[] =
                'Only an Admin can manage administrator accounts.';
        }

        if (!in_array($role, ['admin'], true)) {
            $accountActionErrors[] =
                'Administrator accounts may only use the Admin role.';
        }

        if ($accountId === $loggedInAdminId) {
            $accountActionErrors[] =
                'Your own administrator account is managed through Profile.';
        }
    }

    if ($source === 'users' && !$accountActionErrors) {
        $currentUserStmt = $pdo->prepare("
            SELECT role, is_archived
            FROM users
            WHERE id = ?
            LIMIT 1
        " );
        $currentUserStmt->execute([$accountId]);
        $currentUserAccount = $currentUserStmt->fetch(PDO::FETCH_ASSOC);

        if (!$currentUserAccount || (int)$currentUserAccount['is_archived'] === 1) {
            $accountActionErrors[] = 'User account not found or already archived.';
        } elseif (
            strtolower(trim((string)($currentUserAccount['role'] ?? ''))) === 'admin' &&
            $role === 'staff' &&
            settingsUserAccountActiveAdministratorCount($pdo) <= 1
        ) {
            $accountActionErrors[] =
                'At least one active administrator account must remain.';
        }

        $loggedInUserId = (int)(
            $_SESSION['user_id']
            ?? $_SESSION['admin_id']
            ?? 0
        );

        if (
            $loggedInUserId > 0 &&
            $accountId === $loggedInUserId &&
            in_array($_SESSION['user_role'], ['admin'], true) &&
            $currentUserAccount &&
            strtolower(trim((string)($currentUserAccount['role'] ?? ''))) === 'admin' &&
            $role !== 'admin'
        ) {
            $accountActionErrors[] =
                'Your own administrator role cannot be changed to Staff.';
        }
    }

    if (
        !$accountActionErrors &&
        settingsUserAccountEmailExists(
            $pdo,
            $source,
            $email,
            $accountId
        )
    ) {
        $accountActionErrors[] =
            'That email address is already used by another active account.';
    }

    if (
        !$accountActionErrors &&
        $source === 'admins'
    ) {
        $currentStmt = $pdo->prepare("
            SELECT role, is_archived
            FROM admins
            WHERE id = ?
            LIMIT 1
        ");
        $currentStmt->execute([$accountId]);
        $currentAdmin = $currentStmt->fetch(PDO::FETCH_ASSOC);

        if (!$currentAdmin || (int)$currentAdmin['is_archived'] === 1) {
            $accountActionErrors[] = 'Administrator account not found or already archived.';
        }
    }

    if ($accountActionErrors) {
        $errors = array_merge($errors, $accountActionErrors);
    } elseif ($source === 'customers') {
        try {
            if ($newPassword !== '') {
                $stmt = $pdo->prepare("
                    UPDATE customers
                    SET
                        full_name = ?,
                        email = ?,
                        contact_number = ?,
                        password = ?,
                        updated_at = NOW()
                    WHERE id = ?
                      AND is_archived = 0
                ");

                $stmt->execute([
                    $name,
                    $email,
                    $phone,
                    password_hash($newPassword, PASSWORD_DEFAULT),
                    $accountId
                ]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE customers
                    SET
                        full_name = ?,
                        email = ?,
                        contact_number = ?,
                        updated_at = NOW()
                    WHERE id = ?
                      AND is_archived = 0
                ");

                $stmt->execute([
                    $name,
                    $email,
                    $phone,
                    $accountId
                ]);
            }

            settingsUserAccountRedirect(
                'active',
                $userAccountFilter,
                $userAccountSearch
            );
        } catch (Throwable $e) {
            $errors[] = 'Failed to update the customer account.';
        }
    } elseif ($source === 'users') {
        try {
            if ($newPassword !== '') {
                $stmt = $pdo->prepare("
                    UPDATE users
                    SET
                        name = ?,
                        email = ?,
                        phone = ?,
                        password = ?,
                        role = ?
                    WHERE id = ?
                      AND is_archived = 0
                ");

                $stmt->execute([
                    $name,
                    $email,
                    $phone,
                    password_hash($newPassword, PASSWORD_DEFAULT),
                    $role,
                    $accountId
                ]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE users
                    SET
                        name = ?,
                        email = ?,
                        phone = ?,
                        role = ?
                    WHERE id = ?
                      AND is_archived = 0
                ");

                $stmt->execute([
                    $name,
                    $email,
                    $phone,
                    $role,
                    $accountId
                ]);
            }

            settingsUserAccountRedirect(
                'active',
                $userAccountFilter,
                $userAccountSearch
            );
        } catch (Throwable $e) {
            $errors[] = 'Failed to update the user account.';
        }
    } elseif ($source === 'admins') {
        try {
            if ($newPassword !== '') {
                $stmt = $pdo->prepare("
                    UPDATE admins
                    SET
                        full_name = ?,
                        email = ?,
                        password = ?,
                        role = ?,
                        updated_at = NOW()
                    WHERE id = ?
                      AND is_archived = 0
                ");

                $stmt->execute([
                    $name,
                    $email,
                    password_hash($newPassword, PASSWORD_DEFAULT),
                    $role,
                    $accountId
                ]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE admins
                    SET
                        full_name = ?,
                        email = ?,
                        role = ?,
                        updated_at = NOW()
                    WHERE id = ?
                      AND is_archived = 0
                ");

                $stmt->execute([
                    $name,
                    $email,
                    $role,
                    $accountId
                ]);
            }

            settingsUserAccountRedirect(
                'active',
                $userAccountFilter,
                $userAccountSearch
            );
        } catch (Throwable $e) {
            $errors[] = 'Failed to update the administrator account.';
        }
    }
}

/* ---------------------------------------------------------
   ARCHIVE ACCOUNT
--------------------------------------------------------- */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['archive_user_account'])
) {
    $source = trim((string)($_POST['account_source'] ?? ''));
    $accountId = (int)($_POST['account_id'] ?? 0);

    if (!$userAccountArchiveSupported) {
        $errors[] =
            'Archive is unavailable because the database archive columns could not be created.';
    } elseif (!in_array($source, ['customers', 'users', 'admins'], true) || $accountId <= 0) {
        $errors[] = 'Invalid account.';
    } elseif (
        $source === 'admins' &&
        $_SESSION['user_role'] !== 'admin'
    ) {
        $errors[] =
            'Only an Admin can archive administrator accounts.';
    } elseif (
        ($source === 'admins' && $accountId === $loggedInAdminId) ||
        (
            $source === 'users' &&
            $accountId === (int)($_SESSION['user_id'] ?? 0) &&
            $_SESSION['user_role'] === 'admin'
        )
    ) {
        $errors[] =
            'Your own administrator account cannot be archived. Use Profile instead.';
    } else {
        try {
            if ($source === 'admins') {
                $currentStmt = $pdo->prepare("
                    SELECT role, is_archived
                    FROM admins
                    WHERE id = ?
                    LIMIT 1
                ");
                $currentStmt->execute([$accountId]);
                $currentAdmin = $currentStmt->fetch(PDO::FETCH_ASSOC);

                if (!$currentAdmin) {
                    throw new RuntimeException('Administrator account not found.');
                }

                if (
                    in_array($currentAdmin['role'], ['admin'], true) &&
                    (int)$currentAdmin['is_archived'] === 0 &&
                    settingsUserAccountActiveAdministratorCount($pdo) <= 1
                ) {
                    throw new RuntimeException(
                        'At least one active administrator account must remain.'
                    );
                }

            } elseif ($source === 'users') {
                $currentStmt = $pdo->prepare("
                    SELECT role, is_archived
                    FROM users
                    WHERE id = ?
                    LIMIT 1
                " );
                $currentStmt->execute([$accountId]);
                $currentUser = $currentStmt->fetch(PDO::FETCH_ASSOC);

                if (!$currentUser) {
                    throw new RuntimeException('User account not found.');
                }

                if (
                    strtolower(trim((string)($currentUser['role'] ?? ''))) === 'admin' &&
                    (int)$currentUser['is_archived'] === 0 &&
                    settingsUserAccountActiveAdministratorCount($pdo) <= 1
                ) {
                    throw new RuntimeException(
                        'At least one active administrator account must remain.'
                    );
                }
            }

            if ($source === 'customers') {
                $stmt = $pdo->prepare("
                    UPDATE customers
                    SET is_archived = 1,
                        is_active = 0,
                        updated_at = NOW()
                    WHERE id = ?
                      AND is_archived = 0
                ");
            } else {
                $stmt = $pdo->prepare("
                    UPDATE {$source}
                    SET is_archived = 1
                    WHERE id = ?
                      AND is_archived = 0
                ");
            }
            $stmt->execute([$accountId]);

            settingsUserAccountRedirect(
                'active',
                $userAccountFilter,
                $userAccountSearch
            );
        } catch (Throwable $e) {
            $errors[] = $e->getMessage() !== ''
                ? $e->getMessage()
                : 'Failed to archive the account.';
        }
    }
}

/* ---------------------------------------------------------
   RESTORE ARCHIVED ACCOUNT
--------------------------------------------------------- */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['restore_user_account'])
) {
    $source = trim((string)($_POST['account_source'] ?? ''));
    $accountId = (int)($_POST['account_id'] ?? 0);

    if (!$userAccountArchiveSupported) {
        $errors[] =
            'Restore is unavailable because the database archive columns could not be created.';
    } elseif (!in_array($source, ['customers', 'users', 'admins'], true) || $accountId <= 0) {
        $errors[] = 'Invalid account.';
    } elseif (
        $source === 'admins' &&
        $_SESSION['user_role'] !== 'admin'
    ) {
        $errors[] =
            'Only an Admin can restore administrator accounts.';
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT email
                FROM {$source}
                WHERE id = ?
                  AND is_archived = 1
                LIMIT 1
            ");
            $stmt->execute([$accountId]);
            $archived = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$archived) {
                throw new RuntimeException('Archived account not found.');
            }

            if (
                settingsUserAccountEmailExists(
                    $pdo,
                    $source,
                    (string)$archived['email'],
                    $accountId
                )
            ) {
                throw new RuntimeException(
                    'The archived account cannot be restored because its email is already used by another active account.'
                );
            }

            if ($source === 'customers') {
                $stmt = $pdo->prepare("
                    UPDATE customers
                    SET is_archived = 0,
                        is_active = 1,
                        updated_at = NOW()
                    WHERE id = ?
                      AND is_archived = 1
                ");
            } else {
                $stmt = $pdo->prepare("
                    UPDATE {$source}
                    SET is_archived = 0
                    WHERE id = ?
                      AND is_archived = 1
                ");
            }
            $stmt->execute([$accountId]);

            settingsUserAccountRedirect(
                'archived',
                $userAccountFilter,
                $userAccountSearch
            );
        } catch (Throwable $e) {
            $errors[] = $e->getMessage() !== ''
                ? $e->getMessage()
                : 'Failed to restore the account.';
        }
    }
}

/* ---------------------------------------------------------
   LOAD CUSTOMER ACCOUNTS
--------------------------------------------------------- */

try {
    $customerStmt = $pdo->query("
        SELECT
            id,
            full_name,
            email,
            contact_number,
            created_at,
            is_archived
        FROM customers
        ORDER BY full_name ASC, id ASC
    ");

    foreach (
        $customerStmt->fetchAll(PDO::FETCH_ASSOC)
        as $customer
    ) {
        $isArchived = (int)($customer['is_archived'] ?? 0) === 1;

        $userAccounts[] = [
            'source' => 'customers',
            'id' => (int)$customer['id'],
            'full_name' => (string)$customer['full_name'],
            'email' => (string)$customer['email'],
            'phone' => (string)($customer['contact_number'] ?? ''),
            'role' => 'customer',
            'created_at' => $customer['created_at'] ?? null,
            'is_archived' => $isArchived,
        ];

        if ($isArchived) {
            $userAccountArchivedCount++;
        } else {
            $userAccountCounts['customers']++;
        }
    }
} catch (Throwable $e) {
    $errors[] = 'Unable to load customer accounts.';
}

/* ---------------------------------------------------------
   LOAD STAFF ACCOUNTS
--------------------------------------------------------- */

try {
    $staffStmt = $pdo->query("
        SELECT
            id,
            name AS full_name,
            email,
            phone,
            created_at,
            is_archived,
            email_verified_at,
            verification_token
        FROM users
        WHERE role IN ('staff', 'admin')
        ORDER BY
            CASE WHEN role = 'admin' THEN 1 ELSE 2 END,
            full_name ASC,
            id ASC
    ");

    foreach (
        $staffStmt->fetchAll(PDO::FETCH_ASSOC)
        as $staff
    ) {
        $isArchived = (int)($staff['is_archived'] ?? 0) === 1;

        $userAccounts[] = [
            'source' => 'users',
            'id' => (int)$staff['id'],
            'full_name' => (string)$staff['full_name'],
            'email' => (string)$staff['email'],
            'phone' => (string)($staff['phone'] ?? ''),
            'role' => strtolower(trim((string)($staff['role'] ?? 'staff'))),
            'created_at' => $staff['created_at'] ?? null,
            'is_archived' => $isArchived,
            'is_pending_verification' => empty($staff['email_verified_at']) && !empty($staff['verification_token']),
        ];

        if ($isArchived) {
            $userAccountArchivedCount++;
        } elseif (empty($staff['email_verified_at']) && !empty($staff['verification_token'])) {
            $userAccountPendingCount++;
            $staffRole = strtolower(trim((string)($staff['role'] ?? 'staff')));
            if ($staffRole === 'admin') {
                $userAccountCounts['admins']++;
            } else {
                $userAccountCounts['staff']++;
            }
        } else {
            $staffRole = strtolower(trim((string)($staff['role'] ?? 'staff')));
            if ($staffRole === 'admin') {
                $userAccountCounts['admins']++;
            } else {
                $userAccountCounts['staff']++;
            }
        }
    }
} catch (Throwable $e) {
    $errors[] = 'Unable to load staff accounts.';
}

/* ---------------------------------------------------------
   LOAD ADMINISTRATOR ACCOUNTS
--------------------------------------------------------- */

$archiveSelectAdmins = $userAccountArchiveSupported
    ? 'is_archived'
    : '0 AS is_archived';

try {
    $adminAccountStmt = $pdo->query("
        SELECT
            id,
            full_name,
            email,
            role,
            created_at,
            {$archiveSelectAdmins},
            email_verified_at,
            verification_token
        FROM admins
        ORDER BY
            CASE
                WHEN role = 'admin' THEN 1
                ELSE 2
            END,
            full_name ASC,
            id ASC
    ");

    foreach (
        $adminAccountStmt->fetchAll(PDO::FETCH_ASSOC)
        as $adminAccount
    ) {
        $role = 'admin';

        $isArchived = (int)($adminAccount['is_archived'] ?? 0) === 1;

        $userAccounts[] = [
            'source' => 'admins',
            'id' => (int)$adminAccount['id'],
            'full_name' => (string)$adminAccount['full_name'],
            'email' => (string)$adminAccount['email'],
            'phone' => '',
            'role' => 'admin',
            'created_at' => $adminAccount['created_at'] ?? null,
            'is_archived' => $isArchived,
            'is_pending_verification' => empty($adminAccount['email_verified_at']) && !empty($adminAccount['verification_token']),
        ];

        if ($isArchived) {
            $userAccountArchivedCount++;
        } elseif (empty($adminAccount['email_verified_at']) && !empty($adminAccount['verification_token'])) {
            $userAccountPendingCount++;
            $userAccountCounts['admins']++;
        } else {
            $userAccountCounts['admins']++;
        }
    }
} catch (Throwable $e) {
    $errors[] = 'Unable to load administrator accounts.';
}

$userAccountCounts['all'] =
    $userAccountCounts['customers'] +
    $userAccountCounts['staff'] +
    $userAccountCounts['admins'];

$filteredUserAccounts = array_values(
    array_filter(
        $userAccounts,
        static function (array $account) use (
            $userAccountFilter,
            $userAccountSearch,
            $userAccountStatus
        ): bool {
            $role = $account['role'];
            $isArchived = !empty($account['is_archived']);

            if (
                $userAccountStatus === 'active' &&
                $isArchived
            ) {
                return false;
            }

            if (
                $userAccountStatus === 'archived' &&
                !$isArchived
            ) {
                return false;
            }

            if (
                $userAccountFilter === 'customers' &&
                $role !== 'customer'
            ) {
                return false;
            }

            if (
                $userAccountFilter === 'staff' &&
                $role !== 'staff'
            ) {
                return false;
            }

            if (
                $userAccountFilter === 'admins' &&
                !in_array(
                    $role,
                    ['admin'],
                    true
                )
            ) {
                return false;
            }

            if ($userAccountSearch === '') {
                return true;
            }

            $haystack = strtolower(
                implode(
                    ' ',
                    [
                        $account['full_name'],
                        $account['email'],
                        $account['phone'],
                        $role
                    ]
                )
            );

            return strpos(
                $haystack,
                strtolower($userAccountSearch)
            ) !== false;
        }
    )
);


function renderUserAccountFilterFragment(
    array $userAccountCounts,
    string $userAccountFilter,
    string $userAccountSearch,
    string $userAccountStatus,
    int $userAccountArchivedCount,
    int $userAccountPendingCount,
    array $filteredUserAccounts,
    int $loggedInAdminId
): void {
?>
<div id="userAccountAjaxArea">
            <div class="user-account-summary-grid">

                <a
                    href="settings.php?tab=user_accounts&user_account_filter=all"
                    class="user-account-summary-card <?= $userAccountFilter === 'all' ? 'active' : '' ?>"
                >
                    <span class="user-account-summary-icon">
                        <i class="bi bi-people-fill"></i>
                    </span>
                    <span class="user-account-summary-label">
                        All Accounts
                    </span>
                    <strong class="user-account-summary-value">
                        <?= (int)$userAccountCounts['all'] ?>
                    </strong>
                </a>

                <a
                    href="settings.php?tab=user_accounts&user_account_filter=customers"
                    class="user-account-summary-card <?= $userAccountFilter === 'customers' ? 'active' : '' ?>"
                >
                    <span class="user-account-summary-icon">
                        <i class="bi bi-person"></i>
                    </span>
                    <span class="user-account-summary-label">
                        Customers
                    </span>
                    <strong class="user-account-summary-value">
                        <?= (int)$userAccountCounts['customers'] ?>
                    </strong>
                </a>

                <a
                    href="settings.php?tab=user_accounts&user_account_filter=staff"
                    class="user-account-summary-card <?= $userAccountFilter === 'staff' ? 'active' : '' ?>"
                >
                    <span class="user-account-summary-icon">
                        <i class="bi bi-person-badge"></i>
                    </span>
                    <span class="user-account-summary-label">
                        Staff
                    </span>
                    <strong class="user-account-summary-value">
                        <?= (int)$userAccountCounts['staff'] ?>
                    </strong>
                </a>

                <a
                    href="settings.php?tab=user_accounts&user_account_filter=admins"
                    class="user-account-summary-card <?= $userAccountFilter === 'admins' ? 'active' : '' ?>"
                >
                    <span class="user-account-summary-icon">
                        <i class="bi bi-shield-lock"></i>
                    </span>
                    <span class="user-account-summary-label">
                        Admin Accounts
                    </span>
                    <strong class="user-account-summary-value">
                        <?= (int)$userAccountCounts['admins'] ?>
                    </strong>
                </a>

            </div>

            <div class="user-account-toolbar">

                <form
                    method="GET"
                    class="user-account-search-form"
                >
                    <input
                        type="hidden"
                        name="tab"
                        value="user_accounts"
                    >

                    <input
                        type="hidden"
                        name="user_account_filter"
                        value="<?= htmlspecialchars($userAccountFilter) ?>"
                    >

                    <div class="user-account-search">
                        <i class="bi bi-search"></i>

                        <input
                            type="search"
                            name="user_account_search"
                            value="<?= htmlspecialchars($userAccountSearch) ?>"
                            placeholder="Search name, email, phone, or role..."
                            autocomplete="off"
                        >
                    </div>
                </form>

                <div class="user-account-toolbar-actions">

                    <a
                        href="settings.php?tab=user_accounts&user_account_status=active&user_account_filter=<?= urlencode($userAccountFilter) ?>&user_account_search=<?= urlencode($userAccountSearch) ?>"
                        class="btn btn-sm user-account-status-btn user-account-status-active <?= $userAccountStatus === 'active' ? 'active' : '' ?>"
                    >
                        <i class="bi bi-person-check me-1"></i>
                        Active
                    </a>

                    <a
                        href="settings.php?tab=user_accounts&user_account_status=archived&user_account_filter=<?= urlencode($userAccountFilter) ?>&user_account_search=<?= urlencode($userAccountSearch) ?>"
                        class="btn btn-sm user-account-status-btn user-account-status-archived <?= $userAccountStatus === 'archived' ? 'active' : '' ?>"
                    >
                        <i class="bi bi-archive me-1"></i>
                        Archived (<?= (int)$userAccountArchivedCount ?>)
                    </a>

                    <span class="user-account-pending-count">
                        <i class="bi bi-envelope-exclamation me-1"></i>
                        Pending Verification: <?= (int)$userAccountPendingCount ?>
                    </span>

                    <div class="user-account-result-count">
                        <?= count($filteredUserAccounts) ?>
                        <?= count($filteredUserAccounts) === 1 ? 'account' : 'accounts' ?>
                    </div>

                </div>

            </div>

            <?php if (empty($filteredUserAccounts)): ?>

                <div class="user-account-empty">

                    <div class="user-account-empty-icon">
                        <i class="bi bi-person-x"></i>
                    </div>

                    <div class="user-account-empty-title">
                        No accounts found
                    </div>

                    <div class="user-account-empty-text">
                        <?= $userAccountSearch !== ''
                            ? 'No accounts matched your search.'
                            : 'There are no accounts in this section yet.'
                        ?>
                    </div>

                </div>

            <?php else: ?>

                <div class="user-account-table-wrap">

                    <table class="user-account-table">

                        <thead>
                            <tr>
                                <th>Account</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Role</th>
                                <th>Account Created</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>

                        <tbody>

                            <?php foreach ($filteredUserAccounts as $account): ?>

                                <?php
                                $role = $account['role'];

                                $roleLabel = match ($role) {
                                    'customer' => 'Customer',
                                    'staff' => 'Staff',
                                    'admin' => 'Admin',
                                    default => ucfirst($role),
                                };

                                $roleIcon = match ($role) {
                                    'customer' => 'bi-person',
                                    'staff' => 'bi-person-badge',
                                    'admin' => 'bi-shield-check',
                                    default => 'bi-person',
                                };

                                $accountInitials = 'U';

                                $nameParts = preg_split(
                                    '/\s+/',
                                    trim($account['full_name'])
                                );

                                if (!empty($nameParts[0])) {
                                    $accountInitials = strtoupper(
                                        substr(
                                            $nameParts[0],
                                            0,
                                            1
                                        )
                                    );
                                }

                                if (
                                    count($nameParts) > 1 &&
                                    !empty(
                                        $nameParts[count($nameParts) - 1]
                                    )
                                ) {
                                    $accountInitials .= strtoupper(
                                        substr(
                                            $nameParts[
                                                count($nameParts) - 1
                                            ],
                                            0,
                                            1
                                        )
                                    );
                                }

                                $isLoggedInAdmin =
                                    $account['source'] === 'admins' &&
                                    $loggedInAdminId > 0 &&
                                    $account['id'] === $loggedInAdminId;
                                ?>

                                <tr class="user-account-row" data-account-key="<?= htmlspecialchars($account['source'] . '-' . $account['id'], ENT_QUOTES) ?>">

                                    <td data-label="Account">

                                        <div class="user-account-person">

                                            <div class="user-account-avatar">
                                                <?= htmlspecialchars(
                                                    $accountInitials
                                                ) ?>
                                            </div>

                                            <div>

                                                <div class="user-account-name">

                                                    <?= htmlspecialchars(
                                                        $account['full_name']
                                                    ) ?>

                                                    <?php if ($isLoggedInAdmin): ?>

                                                        <span class="user-account-you">
                                                            (You)
                                                        </span>

                                                    <?php endif; ?>

                                                </div>

                                                <div class="user-account-id">

                                                    <?= $account['source'] === 'admins'
                                                        ? 'Admin ID #'
                                                        : ($account['source'] === 'customers' ? 'Customer ID #' : 'Staff ID #') ?><?= (int)$account['id'] ?>

                                                </div>

                                            </div>

                                        </div>

                                    </td>

                                    <td class="user-account-email" data-label="Email">

                                        <?= htmlspecialchars(
                                            $account['email']
                                        ) ?>

                                    </td>

                                    <td data-label="Phone">

                                        <?php if (
                                            trim(
                                                $account['phone']
                                            ) !== ''
                                        ): ?>

                                            <?= htmlspecialchars(
                                                $account['phone']
                                            ) ?>

                                        <?php else: ?>

                                            <span class="user-account-muted">
                                                —
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <td data-label="Role">

                                        <span
                                            class="user-account-role role-<?= htmlspecialchars($role) ?>"
                                        >
                                            <i class="bi <?= $roleIcon ?>"></i>

                                            <?= htmlspecialchars(
                                                $roleLabel
                                            ) ?>
                                        </span>

                                        <?php if (!empty($account['is_pending_verification'])): ?>
                                            <span class="user-account-verification-badge pending">
                                                <i class="bi bi-envelope-exclamation me-1"></i>
                                                Pending Verification
                                            </span>
                                        <?php endif; ?>

                                    </td>

                                    <td class="user-account-date" data-label="Created">

                                        <?php
                                        $createdAt =
                                            $account['created_at']
                                            ?? null;

                                        echo $createdAt
                                            ? htmlspecialchars(
                                                date(
                                                    'M d, Y',
                                                    strtotime(
                                                        $createdAt
                                                    )
                                                )
                                            )
                                            : '—';
                                        ?>

                                    </td>

                                    <td class="user-account-actions" data-label="Actions">

                                        <?php
                                        $isArchived =
                                            !empty($account['is_archived']);

                                        $canManageAdminAccount =
                                            $_SESSION['user_role'] === 'admin';

                                        $isCurrentAdmin =
                                            (
                                                $account['source'] === 'admins' &&
                                                $account['id'] === $loggedInAdminId
                                            ) ||
                                            (
                                                $account['source'] === 'users' &&
                                                $account['role'] === 'admin' &&
                                                $account['id'] === (int)($_SESSION['user_id'] ?? 0) &&
                                                in_array($_SESSION['user_role'], ['admin'], true)
                                            );

                                        $isAdministratorAccount =
                                            $account['role'] === 'admin';

                                        $canManageAccount =
                                            !$isCurrentAdmin &&
                                            (
                                                !$isAdministratorAccount ||
                                                $canManageAdminAccount
                                            );

                                        $canEdit = $canManageAccount;
                                        $canArchive = $canManageAccount;
                                        ?>

                                        <div class="user-account-action-group">

                                            <?php if ($isArchived): ?>

                                                <form method="POST" class="d-inline">
                                                    <input
                                                        type="hidden"
                                                        name="account_source"
                                                        value="<?= htmlspecialchars($account['source']) ?>"
                                                    >
                                                    <input
                                                        type="hidden"
                                                        name="account_id"
                                                        value="<?= (int)$account['id'] ?>"
                                                    >
                                                    <button
                                                        type="submit"
                                                        name="restore_user_account"
                                                        class="btn btn-sm btn-outline-success"
                                                        <?= $canEdit || $canArchive ? '' : 'disabled' ?>
                                                    >
                                                        <i class="bi bi-arrow-counterclockwise me-1"></i>
                                                        Restore
                                                    </button>
                                                </form>


                                            <?php else: ?>

                                                <?php if (!empty($account['is_pending_verification']) && in_array($account['source'], ['users', 'admins'], true)): ?>
                                                    <form
                                                        method="POST"
                                                        class="d-inline"
                                                        onsubmit="return confirm('Send a new verification link to this email address?');"
                                                    >
                                                        <input
                                                            type="hidden"
                                                            name="account_source"
                                                            value="<?= htmlspecialchars($account['source']) ?>"
                                                        >
                                                        <input
                                                            type="hidden"
                                                            name="account_id"
                                                            value="<?= (int)$account['id'] ?>"
                                                        >
                                                        <button
                                                            type="submit"
                                                            name="resend_user_account_verification"
                                                            class="btn btn-sm btn-outline-info"
                                                            <?= $canEdit ? '' : 'disabled' ?>
                                                        >
                                                            <i class="bi bi-envelope-arrow-up me-1"></i>
                                                            Resend
                                                        </button>
                                                    </form>
                                                <?php endif; ?>

                                                <button
                                                    type="button"
                                                    class="btn btn-sm user-account-edit-btn"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#userAccountEditModal"
                                                    data-source="<?= htmlspecialchars($account['source'], ENT_QUOTES) ?>"
                                                    data-id="<?= (int)$account['id'] ?>"
                                                    data-name="<?= htmlspecialchars($account['full_name'], ENT_QUOTES) ?>"
                                                    data-email="<?= htmlspecialchars($account['email'], ENT_QUOTES) ?>"
                                                    data-phone="<?= htmlspecialchars($account['phone'], ENT_QUOTES) ?>"
                                                    data-role="<?= htmlspecialchars($account['role'], ENT_QUOTES) ?>"
                                                    <?= $canEdit ? '' : 'disabled' ?>
                                                >
                                                    <i class="bi bi-pencil me-1"></i>
                                                    Edit
                                                </button>

                                                <?php if ($canArchive): ?>
                                                    <form
                                                        method="POST"
                                                        class="d-inline"
                                                        onsubmit="return confirm('Archive this account? The account will no longer be active.');"
                                                    >
                                                        <input
                                                            type="hidden"
                                                            name="account_source"
                                                            value="<?= htmlspecialchars($account['source']) ?>"
                                                        >
                                                        <input
                                                            type="hidden"
                                                            name="account_id"
                                                            value="<?= (int)$account['id'] ?>"
                                                        >
                                                        <button
                                                            type="submit"
                                                            name="archive_user_account"
                                                            class="btn btn-sm btn-outline-warning"
                                                        >
                                                            <i class="bi bi-archive me-1"></i>
                                                            Archive
                                                        </button>
                                                    </form>

                                                <?php endif; ?>

                                            <?php endif; ?>

                                            <?php if ($isCurrentAdmin): ?>
                                                <span class="user-account-you-action">
                                                    Managed through Profile
                                                </span>
                                            <?php elseif (
                                                in_array($account['role'], ['admin'], true) &&
                                                $_SESSION['user_role'] !== 'admin'
                                            ): ?>
                                                <span class="user-account-muted">
                                                    Admin only
                                                </span>
                                            <?php endif; ?>

                                        </div>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

            <div class="user-account-note">

                <strong>
                    <i class="bi bi-info-circle me-1"></i>
                    Account separation:
                </strong>

                Customer accounts come from the
                <code>customers</code> table. Staff and Admin roles may exist in
                <code>users</code>, while administrator profiles are also
                maintained in the separate <code>admins</code> table. Admins can
                create new Staff accounts and manage existing accounts according
                to their role. Your own administrator account remains managed
                through <code>profile.php</code>. Passwords are never displayed here.
            </div>

</div>
<?php
}


/* =========================================================
   USER ACCOUNT FILTER AJAX
   Return only the filter/list area so changing
   All / Customers / Staff / Admin Accounts does not reload
   the entire settings page.
========================================================= */
if (
    $_SERVER['REQUEST_METHOD'] === 'GET' &&
    ($_GET['ajax'] ?? '') === 'user_accounts' &&
    $selectedTab === 'user_accounts'
) {
    header('Content-Type: text/html; charset=utf-8');

    renderUserAccountFilterFragment(
        $userAccountCounts,
        $userAccountFilter,
        $userAccountSearch,
        $userAccountStatus,
        $userAccountArchivedCount,
        $userAccountPendingCount,
        $filteredUserAccounts,
        $loggedInAdminId
    );
    exit;
}


/* =========================================================
   PROMOTION MANAGEMENT
========================================================= */

$promotionRuleTypes = [
    'bogo' => 'Buy 1 Take 1',
    'buy_x_get_y' => 'Buy X Get Y Free',
    'bundle' => 'Bundle / Combo',
    'percentage' => 'Percentage Discount',
    'fixed' => 'Fixed Amount Discount'
];

function settingsPromotionUploadedImage(?string $existingImage = null): array
{
    $uploadDir = '../assets/uploads/promotions/';
    $imageName = $existingImage;

    if (!isset($_FILES['promotion_image']) || $_FILES['promotion_image']['error'] === UPLOAD_ERR_NO_FILE) {
        return [$imageName, null, null];
    }

    if ($_FILES['promotion_image']['error'] !== UPLOAD_ERR_OK) {
        return [$imageName, 'There was an error uploading the promotion image.', null];
    }

    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
    $extension = strtolower(pathinfo($_FILES['promotion_image']['name'], PATHINFO_EXTENSION));

    if (!in_array($extension, $allowedExtensions, true)) {
        return [$imageName, 'Only JPG, JPEG, PNG, and WEBP images are allowed.', null];
    }

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        return [$imageName, 'Failed to prepare the promotion image folder.', null];
    }

    $newImageName = uniqid('promotion_', true) . '.' . $extension;
    $destination = $uploadDir . $newImageName;

    if (!move_uploaded_file($_FILES['promotion_image']['tmp_name'], $destination)) {
        return [$imageName, 'Failed to upload the promotion image.', null];
    }

    return [$newImageName, null, $newImageName];
}

function settingsPromotionValidDate(string $value): bool
{
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function settingsPromotionProductIds(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($id) => $id > 0)));
    if (!$ids) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("\n        SELECT p.id\n        FROM products p\n        INNER JOIN categories c ON c.id = p.category_id\n        WHERE p.id IN ({$placeholders})\n          AND p.is_archived = 0\n          AND COALESCE(c.name, '') <> 'Add-ons'\n    ");
    $stmt->execute($ids);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function settingsPromotionNormalizeSize(?string $size): ?string
{
    $size = strtolower(trim((string)$size));
    return in_array($size, ['regular', 'grande'], true) ? $size : null;
}

function settingsPromotionProductSupportsSize(PDO $pdo, int $productId, ?string $size): bool
{
    $size = settingsPromotionNormalizeSize($size);
    if ($productId <= 0 || $size === null) {
        return false;
    }

    $stmt = $pdo->prepare("\n        SELECT regular_price, grande_price\n        FROM products\n        WHERE id = ?\n          AND is_archived = 0\n        LIMIT 1\n    ");
    $stmt->execute([$productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        return false;
    }

    if ($size === 'regular') {
        return (float)($product['regular_price'] ?? 0) > 0;
    }

    return (float)($product['grande_price'] ?? 0) > 0;
}

/**
 * Return active promotions that use a product, including the configured size.
 */
function settingsPromotionActiveUsage(PDO $pdo, int $productId): array
{
    if ($productId <= 0) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT DISTINCT
            p.id,
            p.title,
            pri.role,
            pri.size
        FROM promotions p
        INNER JOIN promotion_rules r
            ON r.promotion_id = p.id
        INNER JOIN promotion_rule_items pri
            ON pri.rule_id = r.id
        WHERE pri.product_id = ?
          AND p.is_active = 1
          AND p.is_archived = 0
          AND p.start_date <= CURDATE()
          AND p.end_date >= CURDATE()
        ORDER BY p.id ASC, pri.role ASC, pri.size ASC
    ");
    $stmt->execute([$productId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Find active promotions that would become invalid when a product size is disabled.
 */
function settingsPromotionSizeConflicts(
    PDO $pdo,
    int $productId,
    bool $hasRegular,
    bool $hasGrande
): array {
    $conflicts = [];

    foreach (settingsPromotionActiveUsage($pdo, $productId) as $usage) {
        $size = strtolower(trim((string)($usage['size'] ?? '')));

        if ($size === 'regular' && !$hasRegular) {
            $conflicts[] = [
                'id' => (int)$usage['id'],
                'title' => (string)$usage['title'],
                'size' => 'Regular',
            ];
        } elseif ($size === 'grande' && !$hasGrande) {
            $conflicts[] = [
                'id' => (int)$usage['id'],
                'title' => (string)$usage['title'],
                'size' => 'Grande',
            ];
        }
    }

    return $conflicts;
}

function settingsPromotionRuleInput(PDO $pdo, string $ruleType): array
{
    $items = [];
    $buyQuantity = max(1, (int)($_POST['buy_quantity'] ?? 1));
    $getQuantity = max(1, (int)($_POST['get_quantity'] ?? 1));
    $discountValue = isset($_POST['discount_value']) ? (float)$_POST['discount_value'] : null;
    $bundlePrice = isset($_POST['bundle_price']) ? (float)$_POST['bundle_price'] : null;

    if ($ruleType === 'bogo') {
        $productId = (int)($_POST['bogo_product_id'] ?? 0);
        $size = settingsPromotionNormalizeSize($_POST['bogo_size'] ?? null);
        $valid = settingsPromotionProductIds($pdo, [$productId]);

        if (count($valid) !== 1) {
            return [null, ['Select a valid product for the Buy 1 Take 1 promotion.']];
        }

        if (!settingsPromotionProductSupportsSize($pdo, $valid[0], $size)) {
            return [null, ['Select a valid size for the selected Buy 1 Take 1 product.']];
        }

        return [[
            'rule_type' => 'bogo',
            'buy_quantity' => 1,
            'get_quantity' => 1,
            'discount_value' => null,
            'bundle_price' => null,
            'items' => [
                ['product_id' => $valid[0], 'role' => 'buy', 'quantity' => 1, 'size' => $size],
                ['product_id' => $valid[0], 'role' => 'get', 'quantity' => 1, 'size' => $size]
            ]
        ], []];
    }

    if ($ruleType === 'buy_x_get_y') {
        $buyProduct = (int)($_POST['buy_product_id'] ?? 0);
        $getProduct = (int)($_POST['get_product_id'] ?? 0);
        $buySize = settingsPromotionNormalizeSize($_POST['buy_size'] ?? null);
        $getSize = settingsPromotionNormalizeSize($_POST['get_size'] ?? null);
        $valid = settingsPromotionProductIds($pdo, [$buyProduct, $getProduct]);

        if (count($valid) !== 2 && $buyProduct !== $getProduct) {
            return [null, ['Select valid products for both the Buy and Get portions.']];
        }

        if (!$valid || !in_array($buyProduct, $valid, true) || !in_array($getProduct, $valid, true)) {
            return [null, ['Select valid products for both the Buy and Get portions.']];
        }

        if (!settingsPromotionProductSupportsSize($pdo, $buyProduct, $buySize)) {
            return [null, ['Select a valid size for the Buy product.']];
        }

        if (!settingsPromotionProductSupportsSize($pdo, $getProduct, $getSize)) {
            return [null, ['Select a valid size for the Get product.']];
        }

        return [[
            'rule_type' => 'buy_x_get_y',
            'buy_quantity' => $buyQuantity,
            'get_quantity' => $getQuantity,
            'discount_value' => null,
            'bundle_price' => null,
            'items' => [
                ['product_id' => $buyProduct, 'role' => 'buy', 'quantity' => $buyQuantity, 'size' => $buySize],
                ['product_id' => $getProduct, 'role' => 'get', 'quantity' => $getQuantity, 'size' => $getSize]
            ]
        ], []];
    }

    if ($ruleType === 'bundle') {
        $bundleProducts = $_POST['bundle_product_ids'] ?? [];
        $bundleSizes = $_POST['bundle_product_sizes'] ?? [];

        if (!is_array($bundleProducts)) {
            $bundleProducts = [];
        }
        if (!is_array($bundleSizes)) {
            $bundleSizes = [];
        }

        $bundleProducts = array_values(array_unique(array_filter(
            array_map('intval', $bundleProducts),
            static fn($id) => $id > 0
        )));

        if (count($bundleProducts) < 2) {
            return [null, ['A bundle must contain at least 2 products.']];
        }

        if (count($bundleProducts) > 6) {
            return [null, ['A bundle can contain up to 6 products.']];
        }

        $valid = settingsPromotionProductIds($pdo, $bundleProducts);
        if (count($valid) !== count($bundleProducts)) {
            return [null, ['One or more selected bundle products are invalid.']];
        }

        $bundleItems = [];
        foreach ($valid as $productId) {
            $size = settingsPromotionNormalizeSize($bundleSizes[(string)$productId] ?? ($bundleSizes[$productId] ?? null));

            if (!settingsPromotionProductSupportsSize($pdo, $productId, $size)) {
                return [null, ['Select a valid size for every selected bundle product.']];
            }

            $bundleItems[] = [
                'product_id' => $productId,
                'role' => 'bundle',
                'quantity' => 1,
                'size' => $size
            ];
        }

        $bundlePrice = (float)($_POST['bundle_price'] ?? 0);
        if ($bundlePrice <= 0) {
            return [null, ['Bundle price must be greater than 0.']];
        }

        return [[
            'rule_type' => 'bundle',
            'buy_quantity' => 1,
            'get_quantity' => 1,
            'discount_value' => null,
            'bundle_price' => $bundlePrice,
            'items' => $bundleItems
        ], []];
    }

    if ($ruleType === 'percentage') {
        $productId = (int)($_POST['discount_product_id'] ?? 0);
        $size = settingsPromotionNormalizeSize($_POST['discount_size'] ?? null);
        $valid = settingsPromotionProductIds($pdo, [$productId]);
        $discountValue = (float)($_POST['discount_value'] ?? 0);

        if (count($valid) !== 1) {
            return [null, ['Select a valid product for the percentage discount.']];
        }

        if (!settingsPromotionProductSupportsSize($pdo, $valid[0], $size)) {
            return [null, ['Select a valid size for the selected product.']];
        }

        if ($discountValue <= 0 || $discountValue > 100) {
            return [null, ['Percentage discount must be greater than 0 and no more than 100%.']];
        }

        return [[
            'rule_type' => 'percentage',
            'buy_quantity' => 1,
            'get_quantity' => 1,
            'discount_value' => $discountValue,
            'bundle_price' => null,
            'items' => [['product_id' => $valid[0], 'role' => 'qualifying', 'quantity' => 1, 'size' => $size]]
        ], []];
    }

    if ($ruleType === 'fixed') {
        $productId = (int)($_POST['discount_product_id'] ?? 0);
        $size = settingsPromotionNormalizeSize($_POST['discount_size'] ?? null);
        $valid = settingsPromotionProductIds($pdo, [$productId]);
        $discountValue = (float)($_POST['discount_value'] ?? 0);

        if (count($valid) !== 1) {
            return [null, ['Select a valid product for the fixed discount.']];
        }

        if (!settingsPromotionProductSupportsSize($pdo, $valid[0], $size)) {
            return [null, ['Select a valid size for the selected product.']];
        }

        if ($discountValue <= 0) {
            return [null, ['Fixed discount must be greater than 0.']];
        }

        return [[
            'rule_type' => 'fixed',
            'buy_quantity' => 1,
            'get_quantity' => 1,
            'discount_value' => $discountValue,
            'bundle_price' => null,
            'items' => [['product_id' => $valid[0], 'role' => 'qualifying', 'quantity' => 1, 'size' => $size]]
        ], []];
    }

    return [null, ['Please select a valid promotion type.']];
}

function settingsPromotionSaveRule(PDO $pdo, int $promotionId, array $rule): void
{
    $pdo->prepare("DELETE FROM promotion_rules WHERE promotion_id = ?")->execute([$promotionId]);

    $stmt = $pdo->prepare("\n        INSERT INTO promotion_rules\n        (promotion_id, rule_type, buy_quantity, get_quantity, discount_value, bundle_price, created_at, updated_at)\n        VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())\n    ");
    $stmt->execute([
        $promotionId,
        $rule['rule_type'],
        $rule['buy_quantity'],
        $rule['get_quantity'],
        $rule['discount_value'],
        $rule['bundle_price']
    ]);

    $ruleId = (int)$pdo->lastInsertId();
    $itemStmt = $pdo->prepare("\n        INSERT INTO promotion_rule_items\n        (rule_id, product_id, role, quantity, size, created_at)\n        VALUES (?, ?, ?, ?, ?, NOW())\n    ");

    foreach ($rule['items'] as $item) {
        $itemStmt->execute([
            $ruleId,
            (int)$item['product_id'],
            $item['role'],
            max(1, (int)$item['quantity']),
            $item['size'] ?? null
        ]);
    }
}

function settingsPromotionHandleSave(PDO $pdo, bool $editing, array $promotionRuleTypes, string $selectedCategory, bool $ajax = false): array
{
    $errors = [];
    $promotionId = (int)($_POST['promotion_id'] ?? 0);
    $title = trim((string)($_POST['promotion_title'] ?? ''));
    $description = trim((string)($_POST['promotion_description'] ?? ''));
    $ruleType = trim((string)($_POST['promotion_type'] ?? ''));
    $startDate = trim((string)($_POST['promotion_start_date'] ?? ''));
    $endDate = trim((string)($_POST['promotion_end_date'] ?? ''));

    if ($editing && $promotionId <= 0) {
        $errors[] = 'Invalid promotion.';
    }
    if ($title === '') {
        $errors[] = 'Promotion title is required.';
    }
    if (!isset($promotionRuleTypes[$ruleType])) {
        $errors[] = 'Please select a valid promotion type.';
    }
    if (!settingsPromotionValidDate($startDate) || !settingsPromotionValidDate($endDate)) {
        $errors[] = 'Please provide valid promotion dates.';
    } elseif ($endDate < $startDate) {
        $errors[] = 'Promotion end date cannot be earlier than the start date.';
    }

    if ($editing && !$errors) {
        $check = $pdo->prepare("SELECT image FROM promotions WHERE id = ? LIMIT 1");
        $check->execute([$promotionId]);
        $existing = $check->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            $errors[] = 'Promotion not found.';
        }
    }

    [$rule, $ruleErrors] = $errors ? [null, []] : settingsPromotionRuleInput($pdo, $ruleType);
    $errors = array_merge($errors, $ruleErrors);

    if ($errors) {
        return [$errors, null];
    }

    $existingImage = $existing['image'] ?? null;
    [$imageName, $imageError, $newImageName] = settingsPromotionUploadedImage($existingImage);
    if ($imageError) {
        return [[$imageError], null];
    }

    try {
        $pdo->beginTransaction();

        if ($editing) {
            $stmt = $pdo->prepare("\n                UPDATE promotions\n                SET title = ?, description = ?, image = ?, start_date = ?, end_date = ?, updated_at = NOW()\n                WHERE id = ?\n            ");
            $stmt->execute([
                $title,
                $description !== '' ? $description : null,
                $imageName,
                $startDate,
                $endDate,
                $promotionId
            ]);
        } else {
            $stmt = $pdo->prepare("\n                INSERT INTO promotions\n                (title, description, image, start_date, end_date, is_active, is_archived, created_at, updated_at)\n                VALUES (?, ?, ?, ?, ?, 1, 0, NOW(), NOW())\n            ");
            $stmt->execute([
                $title,
                $description !== '' ? $description : null,
                $imageName,
                $startDate,
                $endDate
            ]);
            $promotionId = (int)$pdo->lastInsertId();
        }

        settingsPromotionSaveRule($pdo, $promotionId, $rule);
        $pdo->commit();

        // Delete the replaced image only after the DB update is safely committed.
        if ($newImageName && !empty($existingImage) && $existingImage !== $newImageName) {
            $oldPath = '../assets/uploads/promotions/' . $existingImage;
            if (file_exists($oldPath)) {
                unlink($oldPath);
            }
        }

        if ($ajax) {
            return [[], [
                'promotion_id' => $promotionId,
                'editing' => $editing
            ]];
        }

        $param = $editing ? 'promotion_edit_success=1' : 'promotion_success=1';
        header('Location: settings.php?tab=promotions&' . $param);
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($newImageName) {
            $newPath = '../assets/uploads/promotions/' . $newImageName;
            if (file_exists($newPath)) {
                unlink($newPath);
            }
        }
        return [['Failed to save promotion.'], null];
    }
}

/* =========================================================
   PROMOTION AJAX ACTIONS
   Save / toggle / soft-delete without a full page reload.
========================================================= */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['ajax_promotion_action'])
) {
    header('Content-Type: application/json; charset=utf-8');

    $ajaxAction = trim((string)$_POST['ajax_promotion_action']);

    try {
        if ($ajaxAction === 'save' && isset($_POST['save_promotion'])) {
            $isEditingPromotion = ((int)($_POST['promotion_id'] ?? 0) > 0);
            [$promotionErrors, $promotionResult] = settingsPromotionHandleSave(
                $pdo,
                $isEditingPromotion,
                $promotionRuleTypes,
                $selectedCategory,
                true
            );

            if (!empty($promotionErrors)) {
                echo json_encode([
                    'success' => false,
                    'errors' => array_values($promotionErrors)
                ]);
                exit;
            }

            echo json_encode([
                'success' => true,
                'action' => $isEditingPromotion ? 'edit' : 'create',
                'promotion_id' => (int)($promotionResult['promotion_id'] ?? 0),
                'message' => $isEditingPromotion
                    ? 'Promotion updated successfully!'
                    : 'Promotion created successfully!'
            ]);
            exit;
        }

        if ($ajaxAction === 'toggle') {
            $promotionId = (int)($_POST['id'] ?? 0);

            if ($promotionId <= 0) {
                echo json_encode([
                    'success' => false,
                    'errors' => ['Invalid promotion.']
                ]);
                exit;
            }

            $stmt = $pdo->prepare("UPDATE promotions
                SET is_active = NOT is_active, updated_at = NOW()
                WHERE id = ? AND is_archived = 0
            ");
            $stmt->execute([$promotionId]);

            if ($stmt->rowCount() < 1) {
                echo json_encode([
                    'success' => false,
                    'errors' => ['Promotion not found or already deleted.']
                ]);
                exit;
            }

            $stateStmt = $pdo->prepare("SELECT is_active FROM promotions WHERE id = ? LIMIT 1");
            $stateStmt->execute([$promotionId]);
            $isActive = (int)$stateStmt->fetchColumn() === 1;

            echo json_encode([
                'success' => true,
                'action' => 'toggle',
                'promotion_id' => $promotionId,
                'is_active' => $isActive,
                'message' => 'Promotion status updated successfully!'
            ]);
            exit;
        }

        if ($ajaxAction === 'delete') {
            $promotionId = (int)($_POST['id'] ?? 0);

            if ($promotionId <= 0) {
                echo json_encode([
                    'success' => false,
                    'errors' => ['Invalid promotion.']
                ]);
                exit;
            }

            $stmt = $pdo->prepare("UPDATE promotions
                SET is_archived = 1, is_active = 0, updated_at = NOW()
                WHERE id = ? AND is_archived = 0
            ");
            $stmt->execute([$promotionId]);

            if ($stmt->rowCount() < 1) {
                echo json_encode([
                    'success' => false,
                    'errors' => ['Promotion not found or already deleted.']
                ]);
                exit;
            }

            echo json_encode([
                'success' => true,
                'action' => 'delete',
                'promotion_id' => $promotionId,
                'message' => 'Promotion deleted successfully!'
            ]);
            exit;
        }

        echo json_encode([
            'success' => false,
            'errors' => ['Unsupported promotion action.']
        ]);
        exit;
    } catch (Throwable $e) {
        echo json_encode([
            'success' => false,
            'errors' => ['The promotion action could not be completed.']
        ]);
        exit;
    }
}

/* Normal POST fallback for non-JavaScript use. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_promotion'])) {
    $isEditingPromotion = ((int)($_POST['promotion_id'] ?? 0) > 0);
    [$promotionErrors] = settingsPromotionHandleSave(
        $pdo,
        $isEditingPromotion,
        $promotionRuleTypes,
        $selectedCategory
    );
    $errors = array_merge($errors, $promotionErrors);
}

/* Normal GET fallback for non-JavaScript use. */
if (isset($_GET['toggle_promotion']) && isset($_GET['id'])) {
    $promotionId = (int)$_GET['id'];
    $stmt = $pdo->prepare("UPDATE promotions
        SET is_active = NOT is_active, updated_at = NOW()
        WHERE id = ? AND is_archived = 0
    ");
    $stmt->execute([$promotionId]);
    header('Location: settings.php?tab=promotions&promotion_toggle_success=1');
    exit;
}

if (isset($_GET['delete_promotion']) && isset($_GET['id'])) {
    $promotionId = (int)$_GET['id'];
    $stmt = $pdo->prepare("UPDATE promotions
        SET is_archived = 1, is_active = 0, updated_at = NOW()
        WHERE id = ? AND is_archived = 0
    ");
    $stmt->execute([$promotionId]);
    header('Location: settings.php?tab=promotions&promotion_delete_success=1');
    exit;
}

/* =========================================================
   ADD-ON MANAGEMENT
========================================================= */

/* ---------------------------------------------------------
   ADD ADD-ON
--------------------------------------------------------- */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['add_addon'])
) {

    $addonName = trim($_POST['addon_name'] ?? '');
    $addonDescription = trim($_POST['addon_description'] ?? '');
    $addonPrice = (float)($_POST['addon_price'] ?? 0);

    if ($addonName === '') {
        $errors[] = 'Add-on name is required.';
    }

    if ($addonPrice < 0) {
        $errors[] = 'Add-on price cannot be negative.';
    }

    if (empty($errors)) {
        try {
            $duplicateStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM addons
                WHERE LOWER(TRIM(name)) = LOWER(TRIM(?))
                  AND is_archived = 0
            ");
            $duplicateStmt->execute([$addonName]);

            if ((int)$duplicateStmt->fetchColumn() > 0) {
                $errors[] = 'An active add-on with that name already exists.';
            } else {
                $sortStmt = $pdo->query(
                    "SELECT COALESCE(MAX(sort_order), 0) + 1 FROM addons"
                );
                $sortOrder = (int)$sortStmt->fetchColumn();

                $insertAddon = $pdo->prepare("
                    INSERT INTO addons
                    (
                        name,
                        description,
                        price,
                        is_available,
                        is_archived,
                        sort_order,
                        created_at,
                        updated_at
                    )
                    VALUES (?, ?, ?, 1, 0, ?, NOW(), NOW())
                ");

                $insertAddon->execute([
                    $addonName,
                    $addonDescription !== '' ? $addonDescription : null,
                    $addonPrice,
                    $sortOrder
                ]);

                header(
                    "Location: settings.php?addon_success=1&category=" .
                    urlencode($selectedCategory)
                );
                exit;
            }
        } catch (PDOException $e) {
            $errors[] = 'Failed to add add-on.';
        }
    }
}


/* ---------------------------------------------------------
   EDIT ADD-ON
--------------------------------------------------------- */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['edit_addon'])
) {

    $addonId = (int)($_POST['addon_id'] ?? 0);
    $addonName = trim($_POST['addon_name'] ?? '');
    $addonDescription = trim($_POST['addon_description'] ?? '');
    $addonPrice = (float)($_POST['addon_price'] ?? 0);

    if ($addonId <= 0) {
        $errors[] = 'Invalid add-on.';
    }

    if ($addonName === '') {
        $errors[] = 'Add-on name is required.';
    }

    if ($addonPrice < 0) {
        $errors[] = 'Add-on price cannot be negative.';
    }

    if (empty($errors)) {
        try {
            $duplicateStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM addons
                WHERE LOWER(TRIM(name)) = LOWER(TRIM(?))
                  AND id != ?
                  AND is_archived = 0
            ");
            $duplicateStmt->execute([$addonName, $addonId]);

            if ((int)$duplicateStmt->fetchColumn() > 0) {
                $errors[] = 'Another active add-on already uses that name.';
            } else {
                $updateAddon = $pdo->prepare("
                    UPDATE addons
                    SET
                        name = ?,
                        description = ?,
                        price = ?,
                        updated_at = NOW()
                    WHERE id = ?
                      AND is_archived = 0
                ");

                $updateAddon->execute([
                    $addonName,
                    $addonDescription !== '' ? $addonDescription : null,
                    $addonPrice,
                    $addonId
                ]);

                header(
                    "Location: settings.php?addon_edit_success=1&category=" .
                    urlencode($selectedCategory)
                );
                exit;
            }
        } catch (PDOException $e) {
            $errors[] = 'Failed to update add-on.';
        }
    }
}


/* ---------------------------------------------------------
   TOGGLE ADD-ON AVAILABILITY
--------------------------------------------------------- */
if (
    isset($_GET['toggle_addon']) &&
    isset($_GET['id'])
) {

    $addonId = (int)$_GET['id'];

    $stmt = $pdo->prepare("
        UPDATE addons
        SET
            is_available = NOT is_available,
            updated_at = NOW()
        WHERE id = ?
          AND is_archived = 0
    ");

    $stmt->execute([$addonId]);

    header(
        "Location: settings.php?addon_toggle_success=1&category=" .
        urlencode($selectedCategory) .
        "&addons=open"
    );
    exit;
}


/* ---------------------------------------------------------
   ARCHIVE ADD-ON
--------------------------------------------------------- */
if (
    isset($_GET['archive_addon']) &&
    isset($_GET['id'])
) {

    $addonId = (int)$_GET['id'];

    $stmt = $pdo->prepare("
        UPDATE addons
        SET
            is_archived = 1,
            is_available = 0,
            updated_at = NOW()
        WHERE id = ?
          AND is_archived = 0
    ");

    $stmt->execute([$addonId]);

    header(
        "Location: settings.php?addon_archive_success=1&category=" .
        urlencode($selectedCategory) .
        "&addons=open"
    );
    exit;
}


/* ---------------------------------------------------------
   SAVE PRODUCT ADD-ONS
--------------------------------------------------------- */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['save_product_addons'])
) {

    $productId = (int)($_POST['product_id'] ?? 0);
    $addonIds = $_POST['addon_ids'] ?? [];

    if (!is_array($addonIds)) {
        $addonIds = [];
    }

    $addonIds = array_values(array_unique(array_filter(
        array_map('intval', $addonIds),
        static fn($id) => $id > 0
    )));

    if ($productId <= 0) {
        $errors[] = 'Invalid product.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $productCheck = $pdo->prepare("
                SELECT id
                FROM products
                WHERE id = ?
                  AND is_archived = 0
                LIMIT 1
            ");
            $productCheck->execute([$productId]);

            if (!$productCheck->fetchColumn()) {
                throw new Exception('Product not found.');
            }

            $deleteLinks = $pdo->prepare(
                "DELETE FROM product_addons WHERE product_id = ?"
            );
            $deleteLinks->execute([$productId]);

            if (!empty($addonIds)) {
                $validAddonStmt = $pdo->prepare("
                    SELECT id
                    FROM addons
                    WHERE id = ?
                      AND is_archived = 0
                ");

                $insertLink = $pdo->prepare("
                    INSERT INTO product_addons
                    (product_id, addon_id, sort_order)
                    VALUES (?, ?, ?)
                ");

                foreach ($addonIds as $index => $addonId) {
                    $validAddonStmt->execute([$addonId]);

                    if ($validAddonStmt->fetchColumn()) {
                        $insertLink->execute([
                            $productId,
                            $addonId,
                            $index
                        ]);
                    }
                }
            }

            $pdo->commit();

            header(
                "Location: settings.php?addon_product_success=1&category=" .
                urlencode($selectedCategory)
            );
            exit;

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors[] = 'Failed to update product add-ons.';
        }
    }
}


/* =========================================================
   EDIT PRODUCT
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['edit_product'])
) {

    $productId = (int)($_POST['product_id'] ?? 0);
    $productType = trim($_POST['product_type'] ?? '');
    $productName = trim($_POST['product_name'] ?? '');
    $hasRegular = isset($_POST['has_regular']) && $_POST['has_regular'] === '1';
    $hasGrande = isset($_POST['has_grande']) && $_POST['has_grande'] === '1';
    $regularPrice = $hasRegular ? (float)($_POST['regular_price'] ?? 0) : null;
    $grandePrice = $hasGrande ? (float)($_POST['grande_price'] ?? 0) : null;
    $returnCategory = trim($_POST['return_category'] ?? 'All Products');

    if ($productId <= 0) {
        $errors[] = "Invalid product.";
    }

    if (!in_array($productType, $productTypes, true)) {
        $errors[] = "Please select a valid product type.";
    }

    if ($productName === '') {
        $errors[] = "Product name is required.";
    }

    if (!$hasRegular && !$hasGrande) {
        $errors[] = "At least one size (Regular or Grande) must be enabled.";
    }

    if ($hasRegular && $regularPrice <= 0) {
        $errors[] = "Regular price must be greater than 0.";
    }

    if ($hasGrande && $grandePrice <= 0) {
        $errors[] = "Grande price must be greater than 0.";
    }

    if ($hasRegular && $hasGrande && $regularPrice >= $grandePrice) {
        $errors[] = "Regular price must be lower than Grande price.";
    }

    if (empty($errors)) {

        try {

            /* -------------------------------------------------
               FIND CATEGORY
            ------------------------------------------------- */

            $categoryStmt = $pdo->prepare("
                SELECT id
                FROM categories
                WHERE name = ?
                  AND is_active = 1
                LIMIT 1
            ");

            $categoryStmt->execute([$productType]);

            $category = $categoryStmt->fetch(PDO::FETCH_ASSOC);

            if (!$category) {

                $errors[] = "Selected product type was not found.";

            } else {

                /* -------------------------------------------------
                   GET CURRENT PRODUCT
                ------------------------------------------------- */

                $productStmt = $pdo->prepare("
                    SELECT image
                    FROM products
                    WHERE id = ?
                    LIMIT 1
                ");

                $productStmt->execute([$productId]);

                $currentProduct = $productStmt->fetch(PDO::FETCH_ASSOC);

                if (!$currentProduct) {

                    $errors[] = "Product not found.";

                } else {

                    /*
                     * Prevent an active promotion from requiring a size
                     * that the product is about to disable.
                     */
                    $sizeConflicts = settingsPromotionSizeConflicts(
                        $pdo,
                        $productId,
                        $hasRegular,
                        $hasGrande
                    );

                    foreach ($sizeConflicts as $conflict) {
                        $errors[] =
                            'Cannot disable ' . $conflict['size'] .
                            ' size because active promotion "' .
                            $conflict['title'] . '" uses it. Update or deactivate the promotion first.';
                    }

                    $imageName = $currentProduct['image'];


                    /* ---------------------------------------------
                       HANDLE NEW IMAGE
                    --------------------------------------------- */

                    if (
                        isset($_FILES['product_image']) &&
                        $_FILES['product_image']['error'] !== UPLOAD_ERR_NO_FILE
                    ) {

                        if (
                            $_FILES['product_image']['error'] ===
                            UPLOAD_ERR_OK
                        ) {

                            $allowedExtensions = [
                                'jpg',
                                'jpeg',
                                'png',
                                'webp'
                            ];

                            $extension = strtolower(
                                pathinfo(
                                    $_FILES['product_image']['name'],
                                    PATHINFO_EXTENSION
                                )
                            );

                            if (
                                !in_array(
                                    $extension,
                                    $allowedExtensions,
                                    true
                                )
                            ) {

                                $errors[] =
                                    "Only JPG, JPEG, PNG, and WEBP images are allowed.";

                            } else {

                                $uploadDir =
                                    '../assets/uploads/products/';

                                if (!is_dir($uploadDir)) {
                                    mkdir($uploadDir, 0755, true);
                                }

                                $newImageName =
                                    uniqid('product_', true) .
                                    '.' .
                                    $extension;

                                $destination =
                                    $uploadDir . $newImageName;

                                if (
                                    move_uploaded_file(
                                        $_FILES['product_image']['tmp_name'],
                                        $destination
                                    )
                                ) {

                                    $imageName = $newImageName;

                                } else {

                                    $errors[] =
                                        "Failed to upload the new product image.";
                                }
                            }

                        } else {

                            $errors[] =
                                "There was an error uploading the image.";
                        }
                    }


                    /* ---------------------------------------------
                       UPDATE PRODUCT
                    --------------------------------------------- */

                    if (empty($errors)) {

                        /*
                         * Create product slug
                         */
                        $baseSlug = strtolower(
                            trim(
                                preg_replace(
                                    '/[^a-zA-Z0-9]+/',
                                    '-',
                                    $productName
                                ),
                                '-'
                            )
                        );

                        $slug = $baseSlug;
                        $counter = 1;

                        while (true) {

                            $slugCheck = $pdo->prepare("
                                SELECT COUNT(*)
                                FROM products
                                WHERE slug = ?
                                  AND id != ?
                            ");

                            $slugCheck->execute([
                                $slug,
                                $productId
                            ]);

                            if (
                                (int)$slugCheck->fetchColumn() === 0
                            ) {
                                break;
                            }

                            $slug =
                                $baseSlug . '-' . $counter;

                            $counter++;
                        }


                        $primaryPrice = $regularPrice ?? $grandePrice;




                        $updateStmt = $pdo->prepare("



                            UPDATE products



                            SET



                                category_id = ?,



                                name = ?,



                                slug = ?,



                                price = ?,



                                regular_price = ?,



                                grande_price = ?,



                                image = ?,



                                updated_at = NOW()



                            WHERE id = ?



                        ");




                        $updateStmt->execute([



                            $category['id'],



                            $productName,



                            $slug,



                            $primaryPrice,



                            $regularPrice,



                            $grandePrice,



                            $imageName,



                            $productId



                        ]);


                        /*
                         * Remove old image only after
                         * successful database update.
                         */
                        if (
                            isset($_FILES['product_image']) &&
                            $_FILES['product_image']['error'] ===
                            UPLOAD_ERR_OK &&
                            !empty($currentProduct['image']) &&
                            $currentProduct['image'] !==
                                'default-product.png'
                        ) {

                            $oldImage =
                                '../assets/uploads/products/' .
                                $currentProduct['image'];

                            if (
                                file_exists($oldImage) &&
                                $currentProduct['image'] !== $imageName
                            ) {
                                unlink($oldImage);
                            }
                        }


                        if ($returnCategory === '') {
                            $returnCategory = 'All Products';
                        }

                        header(
                            "Location: settings.php?edit_success=1&category=" .
                            urlencode($returnCategory)
                        );

                        exit;
                    }
                }
            }

        } catch (PDOException $e) {

            $errors[] = "Failed to update product.";
        }
    }
}


/* =========================================================
   ADD PRODUCT
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['add_product'])
) {

    $productType = trim($_POST['product_type'] ?? '');
    $productName = trim($_POST['product_name'] ?? '');
    $hasRegular = isset($_POST['has_regular']) && $_POST['has_regular'] === '1';
    $hasGrande = isset($_POST['has_grande']) && $_POST['has_grande'] === '1';
    $regularPrice = $hasRegular ? (float)($_POST['regular_price'] ?? 0) : null;
    $grandePrice = $hasGrande ? (float)($_POST['grande_price'] ?? 0) : null;
    $returnCategory = trim($_POST['return_category'] ?? 'All Products');


    /* ---------------------------------------------------------
       VALIDATION
    --------------------------------------------------------- */

    if (!in_array($productType, $productTypes, true)) {
        $errors[] = "Please select a valid product type.";
    }

    if ($productName === '') {
        $errors[] = "Product name is required.";
    }

    if (!$hasRegular && !$hasGrande) {
        $errors[] = "At least one size (Regular or Grande) must be enabled.";
    }

    if ($hasRegular && $regularPrice <= 0) {
        $errors[] = "Regular price must be greater than 0.";
    }

    if ($hasGrande && $grandePrice <= 0) {
        $errors[] = "Grande price must be greater than 0.";
    }

    if ($hasRegular && $hasGrande && $regularPrice >= $grandePrice) {
        $errors[] = "Regular price must be lower than Grande price.";
    }


    /* ---------------------------------------------------------
       IMAGE
    --------------------------------------------------------- */

    $imageName = 'default-product.png';

    if (
        isset($_FILES['product_image']) &&
        $_FILES['product_image']['error'] !== UPLOAD_ERR_NO_FILE
    ) {

        if (
            $_FILES['product_image']['error'] ===
            UPLOAD_ERR_OK
        ) {

            $allowedExtensions = [
                'jpg',
                'jpeg',
                'png',
                'webp'
            ];

            $extension = strtolower(
                pathinfo(
                    $_FILES['product_image']['name'],
                    PATHINFO_EXTENSION
                )
            );

            if (!in_array($extension, $allowedExtensions, true)) {

                $errors[] =
                    "Only JPG, JPEG, PNG, and WEBP images are allowed.";

            } else {

                $uploadDir =
                    '../assets/uploads/products/';

                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                $imageName =
                    uniqid('product_', true) .
                    '.' .
                    $extension;

                $destination =
                    $uploadDir . $imageName;

                if (
                    !move_uploaded_file(
                        $_FILES['product_image']['tmp_name'],
                        $destination
                    )
                ) {

                    $errors[] =
                        "Failed to upload product image.";
                }
            }

        } else {

            $errors[] =
                "There was an error uploading the image.";
        }
    }


    /* ---------------------------------------------------------
       SAVE PRODUCT
    --------------------------------------------------------- */

    if (empty($errors)) {

        try {

            $pdo->beginTransaction();


            /* -------------------------------------------------
               FIND CATEGORY
            ------------------------------------------------- */

            $categoryStmt = $pdo->prepare("
                SELECT id
                FROM categories
                WHERE name = ?
                LIMIT 1
            ");

            $categoryStmt->execute([
                $productType
            ]);

            $category =
                $categoryStmt->fetch(PDO::FETCH_ASSOC);


            /* -------------------------------------------------
               CREATE CATEGORY IF NOT FOUND
            ------------------------------------------------- */

            if (!$category) {

                $slug = strtolower(
                    trim(
                        preg_replace(
                            '/[^a-zA-Z0-9]+/',
                            '-',
                            $productType
                        ),
                        '-'
                    )
                );

                $sortStmt = $pdo->query("
                    SELECT COALESCE(MAX(sort_order), 0) + 1
                    FROM categories
                ");

                $sortOrder =
                    (int)$sortStmt->fetchColumn();

                $insertCategory = $pdo->prepare("
                    INSERT INTO categories
                    (
                        name,
                        slug,
                        sort_order,
                        is_active
                    )
                    VALUES (?, ?, ?, 1)
                ");

                $insertCategory->execute([
                    $productType,
                    $slug,
                    $sortOrder
                ]);

                $categoryId =
                    $pdo->lastInsertId();

            } else {

                $categoryId =
                    $category['id'];
            }


            /* -------------------------------------------------
               PRODUCT SLUG
            ------------------------------------------------- */

            $baseSlug = strtolower(
                trim(
                    preg_replace(
                        '/[^a-zA-Z0-9]+/',
                        '-',
                        $productName
                    ),
                    '-'
                )
            );

            $slug = $baseSlug;
            $counter = 1;

            while (true) {

                $slugCheck = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM products
                    WHERE slug = ?
                ");

                $slugCheck->execute([
                    $slug
                ]);

                if (
                    (int)$slugCheck->fetchColumn() === 0
                ) {
                    break;
                }

                $slug =
                    $baseSlug . '-' . $counter;

                $counter++;
            }


            /* -------------------------------------------------
               INSERT PRODUCT
            ------------------------------------------------- */

            $insertProduct = $pdo->prepare("
                INSERT INTO products
                (
                    category_id,
                    name,
                    slug,
                    price,
                    regular_price,
                    grande_price,
                    image,
                    is_available,
                    is_archived,
                    created_at,
                    updated_at
                )
                VALUES
                (
                    ?, ?, ?, ?, ?, ?, ?, 1, 0, NOW(), NOW()
                )
            ");

            $primaryPrice = $regularPrice ?? $grandePrice;

            $insertProduct->execute([
                $categoryId,
                $productName,
                $slug,

                /* legacy price column uses the first enabled size */
                $primaryPrice,

                $regularPrice,
                $grandePrice,

                $imageName
            ]);

            $pdo->commit();


            if ($returnCategory === '') {
                $returnCategory = 'All Products';
            }

            header(
                "Location: settings.php?product_success=1&category=" .
                urlencode($returnCategory)
            );

            exit;

        } catch (PDOException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if (
                $imageName !== 'default-product.png' &&
                file_exists(
                    '../assets/uploads/products/' .
                    $imageName
                )
            ) {
                unlink(
                    '../assets/uploads/products/' .
                    $imageName
                );
            }

            $errors[] =
                "Failed to add product.";
        }
    }
}


/* =========================================================
   TOGGLE AVAILABILITY
========================================================= */

if (
    isset($_GET['toggle_availability']) &&
    isset($_GET['id'])
) {

    $id = (int)$_GET['id'];

    $currentStmt = $pdo->prepare("
        SELECT is_available
        FROM products
        WHERE id = ?
        LIMIT 1
    ");
    $currentStmt->execute([$id]);
    $currentAvailability = $currentStmt->fetchColumn();

    /* Only block the transition from Available -> Out of Stock. */
    if ((int)$currentAvailability === 1) {
        $promotionUsage = settingsPromotionActiveUsage($pdo, $id);

        if ($promotionUsage) {
            header(
                "Location: settings.php?availability_error=promotion_active&category=" .
                urlencode($_GET['category'] ?? 'All Products')
            );
            exit;
        }
    }

    $stmt = $pdo->prepare("
        UPDATE products
        SET is_available = NOT is_available,
            updated_at = NOW()
        WHERE id = ?
    ");

    $stmt->execute([$id]);


    $returnCategory =
        $_GET['category'] ??
        'All Products';

    header(
        "Location: settings.php?availability_success=1&category=" .
        urlencode($returnCategory)
    );

    exit;
}


/* =========================================================
   TOGGLE BEST SELLER
   ---------------------------------------------------------
   Admin controls which products appear in the customer's
   Best Sellers section. A maximum of 4 products can be
   selected so the homepage section stays consistent.
========================================================= */

if (
    isset($_GET['toggle_bestseller']) &&
    isset($_GET['id'])
) {

    $id = (int)$_GET['id'];
    $returnCategory = $_GET['category'] ?? 'All Products';

    $productStmt = $pdo->prepare("
        SELECT is_bestseller, is_available, is_archived
        FROM products
        WHERE id = ?
        LIMIT 1
    ");
    $productStmt->execute([$id]);
    $bestSellerProduct = $productStmt->fetch(PDO::FETCH_ASSOC);

    if (!$bestSellerProduct) {
        header(
            "Location: settings.php?bestseller_error=not_found&category=" .
            urlencode($returnCategory)
        );
        exit;
    }

    $currentlyBestSeller = (int)$bestSellerProduct['is_bestseller'] === 1;

    if ($currentlyBestSeller) {
        $toggleStmt = $pdo->prepare("
            UPDATE products
            SET is_bestseller = 0,
                updated_at = NOW()
            WHERE id = ?
        ");
        $toggleStmt->execute([$id]);

        header(
            "Location: settings.php?bestseller_success=removed&category=" .
            urlencode($returnCategory)
        );
        exit;
    }

    if (
        (int)$bestSellerProduct['is_archived'] === 1 ||
        (int)$bestSellerProduct['is_available'] !== 1
    ) {
        header(
            "Location: settings.php?bestseller_error=unavailable&category=" .
            urlencode($returnCategory)
        );
        exit;
    }

    $countStmt = $pdo->query("
        SELECT COUNT(*)
        FROM products
        WHERE is_bestseller = 1
          AND is_available = 1
          AND is_archived = 0
    ");
    $bestSellerCount = (int)$countStmt->fetchColumn();

    if ($bestSellerCount >= 4) {
        header(
            "Location: settings.php?bestseller_error=limit&category=" .
            urlencode($returnCategory)
        );
        exit;
    }

    $toggleStmt = $pdo->prepare("
        UPDATE products
        SET is_bestseller = 1,
            updated_at = NOW()
        WHERE id = ?
    ");
    $toggleStmt->execute([$id]);

    header(
        "Location: settings.php?bestseller_success=added&category=" .
        urlencode($returnCategory)
    );
    exit;
}


/* =========================================================
   DELETE PRODUCT
   ---------------------------------------------------------
   The Delete Product action is a soft delete only.
   The product is archived and kept in the database.
========================================================= */

if (
    isset($_GET['delete_product']) &&
    isset($_GET['id'])
) {

    $id = (int)$_GET['id'];
    $returnCategory = $_GET['category'] ?? 'All Products';

    /* Do not archive a product that is still used by an active promotion. */
    if (settingsPromotionActiveUsage($pdo, $id)) {
        header(
            "Location: settings.php?delete_error=promotion_active&category=" .
            urlencode($returnCategory)
        );
        exit;
    }

    $archiveStmt = $pdo->prepare("
        UPDATE products
        SET
            is_archived = 1,
            is_available = 0,
            is_bestseller = 0,
            updated_at = NOW()
        WHERE id = ?
          AND is_archived = 0
    " );

    $archiveStmt->execute([$id]);

    if ($archiveStmt->rowCount() < 1) {
        header(
            "Location: settings.php?delete_error=not_found&category=" .
            urlencode($returnCategory)
        );
        exit;
    }

    header(
        "Location: settings.php?delete_success=1&category=" .
        urlencode($returnCategory)
    );

    exit;
}


/* =========================================================
   GET PRODUCTS FOR PROMOTION BUILDER
========================================================= */
$promotionProducts = [];
$promotionProductCategories = [];
try {
    $promotionProductsStmt = $pdo->query("\n        SELECT p.id, p.name, p.regular_price, p.grande_price, c.name AS category_name, c.sort_order AS category_sort_order\n        FROM products p\n        INNER JOIN categories c ON c.id = p.category_id\n        WHERE p.is_archived = 0\n          AND COALESCE(c.name, '') <> 'Add-ons'\n        ORDER BY c.sort_order ASC, p.name ASC\n    ");
    $promotionProducts = $promotionProductsStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($promotionProducts as $promotionProduct) {
        $categoryName = trim((string)($promotionProduct['category_name'] ?? ''));
        if ($categoryName !== '' && !isset($promotionProductCategories[$categoryName])) {
            $promotionProductCategories[$categoryName] = (int)($promotionProduct['category_sort_order'] ?? 0);
        }
    }

    uasort($promotionProductCategories, static function ($a, $b) {
        return $a <=> $b;
    });
    $promotionProductCategories = array_keys($promotionProductCategories);
} catch (Throwable $e) {
    $promotionProducts = [];
    $promotionProductCategories = [];
}

/* =========================================================
   GET PRODUCTS
========================================================= */

if ($selectedCategory === 'All Products') {

    $stmt = $pdo->query("
        SELECT
            p.*,
            c.name AS category_name,
            COUNT(DISTINCT CASE
                WHEN a.is_archived = 0 THEN a.id
            END) AS addon_count,
            GROUP_CONCAT(
                CASE
                    WHEN a.is_archived = 0 THEN a.name
                    ELSE NULL
                END
                ORDER BY pa.sort_order ASC, a.sort_order ASC, a.id ASC
                SEPARATOR ', '
            ) AS addon_names
        FROM products p
        LEFT JOIN categories c
            ON p.category_id = c.id
        LEFT JOIN product_addons pa
            ON pa.product_id = p.id
        LEFT JOIN addons a
            ON a.id = pa.addon_id
        WHERE p.is_archived = 0
          AND COALESCE(c.name, '') <> 'Add-ons'
        GROUP BY p.id
        ORDER BY
            c.sort_order ASC,
            p.name ASC
    ");

} else {

    $stmt = $pdo->prepare("
        SELECT
            p.*,
            c.name AS category_name,
            COUNT(DISTINCT CASE
                WHEN a.is_archived = 0 THEN a.id
            END) AS addon_count,
            GROUP_CONCAT(
                CASE
                    WHEN a.is_archived = 0 THEN a.name
                    ELSE NULL
                END
                ORDER BY pa.sort_order ASC, a.sort_order ASC, a.id ASC
                SEPARATOR ', '
            ) AS addon_names
        FROM products p
        INNER JOIN categories c
            ON p.category_id = c.id
        LEFT JOIN product_addons pa
            ON pa.product_id = p.id
        LEFT JOIN addons a
            ON a.id = pa.addon_id
        WHERE c.name = ?
          AND p.is_archived = 0
          AND c.name <> 'Add-ons'
        GROUP BY p.id
        ORDER BY p.name ASC
    ");

    $stmt->execute([
        $selectedCategory
    ]);
}

$products =
    $stmt->fetchAll(PDO::FETCH_ASSOC);


/* =========================================================
   GET ACTIVE ADD-ONS
========================================================= */

$addonStmt = $pdo->query("
    SELECT
        id,
        name,
        description,
        price,
        is_available,
        sort_order
    FROM addons
    WHERE is_archived = 0
    ORDER BY sort_order ASC, name ASC
");

$addons =
    $addonStmt->fetchAll(PDO::FETCH_ASSOC);


/* =========================================================
   GET PRODUCT ADD-ON ASSIGNMENTS
========================================================= */

$productAddonMap = [];

$productAddonStmt = $pdo->query("
    SELECT
        product_id,
        addon_id
    FROM product_addons
    ORDER BY sort_order ASC, addon_id ASC
");

foreach ($productAddonStmt->fetchAll(PDO::FETCH_ASSOC) as $link) {
    $productId = (int)$link['product_id'];
    $addonId = (int)$link['addon_id'];

    if (!isset($productAddonMap[$productId])) {
        $productAddonMap[$productId] = [];
    }

    $productAddonMap[$productId][] = $addonId;
}


require_once '../includes/header.php';
?>

<style>

body {
    background: #F7F5F2;
}

/* =========================================================
   ADMIN PAGE LAYOUT
========================================================= */

.settings-page {
    min-height: 100vh;
    min-width: 0;
    box-sizing: border-box;
}


.settings-content {
    padding: 24px;
    box-sizing: border-box;
}

.text-primary-brown {
    color: #4A3525 !important;
}

.panel-card {
    background: #fff;
    border: 1px solid #E9E0D6;
    border-radius: 14px;
    padding: 22px;
    box-shadow: 0 3px 10px rgba(0,0,0,.04);
}

.panel-title {
    font-weight: 700;
    color: #2C221E;
    font-size: 1.05rem;
}

.btn-brown {
    background: #4A3525;
    border: 1px solid #4A3525;
    color: #fff;
    border-radius: 7px;
    font-weight: 600;
}

.btn-brown:hover {
    background: #352419;
    border-color: #352419;
    color: #fff;
}


/* =========================================================
   PRODUCT TABLE
========================================================= */

.product-table-wrapper {
    border: 1px solid #E3DCD4;
    border-radius: 10px;
    overflow-x: auto;
}

.product-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 850px;
}

.product-table thead {
    background: #F5F0E9;
}

.product-table th {
    padding: 13px 14px;
    font-size: .78rem;
    color: #4A3525;
    font-weight: 700;
    border-bottom: 1px solid #DDD4C9;
}

.product-table td {
    padding: 12px 14px;
    border-bottom: 1px solid #EEE8E2;
    vertical-align: middle;
    font-size: .82rem;
}

.product-table tr:last-child td {
    border-bottom: none;
}

.product-table tbody tr:hover {
    background: #FCFAF8;
}


/* =========================================================
   PRODUCT IMAGE
========================================================= */

.product-image {
    width: 48px;
    height: 48px;
    border-radius: 9px;
    object-fit: cover;
    border: 1px solid #E2DAD1;
}

.product-name {
    font-weight: 700;
    color: #2C221E;
}

.product-category {
    font-size: .68rem;
    color: #8B7F75;
    text-transform: uppercase;
    letter-spacing: .4px;
}

.product-price {
    font-weight: 700;
    color: #4A3525;
}


/* =========================================================
   AVAILABILITY
========================================================= */

.availability-badge {
    display: inline-block;
    padding: 5px 10px;
    border-radius: 50px;
    font-size: .68rem;
    font-weight: 700;
}

.availability-badge.available {
    background: #E7F6EC;
    color: #21884D;
}

.availability-badge.unavailable {
    background: #FBE7E7;
    color: #C33131;
}

.toggle-btn {
    font-size: .72rem;
    padding: 5px 10px;
    border-radius: 6px;
}


/* =========================================================
   THREE DOT ACTION BUTTON
========================================================= */

.action-menu-btn {
    width: 34px;
    height: 34px;
    padding: 0;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.action-menu-btn i {
    font-size: 1rem;
}

.dropdown-menu {
    border: 1px solid #E5DDD4;
    border-radius: 9px;
    box-shadow: 0 8px 20px rgba(0,0,0,.10);
    padding: 6px;
}

.dropdown-item {
    border-radius: 6px;
    font-size: .82rem;
    padding: 8px 10px;
}

.dropdown-item:hover {
    background: #F7F2EC;
}


.user-account-verification-badge {
    display: inline-flex;
    align-items: center;
    margin-top: 6px;
    padding: 4px 9px;
    border: 1px solid #A97900;
    border-radius: 999px;
    background: #FFF7D6;
    color: #6A4D00;
    font-size: 0.72rem;
    font-weight: 700;
    line-height: 1.2;
}

.user-account-pending-count {
    display: inline-flex;
    align-items: center;
    padding: 7px 11px;
    border: 1px solid #A97900;
    border-radius: 8px;
    background: #FFF7D6;
    color: #6A4D00;
    font-size: 0.76rem;
    font-weight: 700;
}

/* =========================================================
   MODALS
========================================================= */

.modal-content {
    border: none;
    border-radius: 14px;
    overflow: hidden;
}

/*
 * Promotion modal has its <form> wrapping the modal body/footer.
 * Bootstrap's .modal-dialog-scrollable expects .modal-body to be a
 * direct child of .modal-content, so the default scrolling rule does
 * not reach this layout. Keep the header/footer fixed and make only
 * the form body scrollable.
 */
/* Keep the promotion modal above the fixed admin sidebar/topbar.
 * Bootstrap's default modal z-index (1055) is lower than the
 * Localitea admin navigation (1100), which can cover the top of the modal.
 */
#promotionModal {
    z-index: 1200 !important;
}

/* Only the promotion modal backdrop is raised above the fixed admin UI.
 * Other Bootstrap modals keep their normal z-index, so their controls remain clickable.
 */
.modal-backdrop.promotion-modal-backdrop {
    z-index: 1190 !important;
}

/* Keep the User Account edit modal above the fixed admin navigation.
 * It is also moved to <body> by JavaScript below so no page container
 * can create a stacking/overflow layer that blocks its controls.
 */
#userAccountEditModal,
#userAccountAddModal {
    z-index: 1300 !important;
}

.modal-backdrop.user-account-modal-backdrop {
    z-index: 1290 !important;
}

#userAccountEditModal .modal-dialog,
#userAccountEditModal .modal-content,
#userAccountEditModal input,
#userAccountEditModal select,
#userAccountEditModal button,
#userAccountAddModal .modal-dialog,
#userAccountAddModal .modal-content,
#userAccountAddModal input,
#userAccountAddModal select,
#userAccountAddModal button {
    pointer-events: auto;
}

#promotionModal .modal-dialog {
    width: 100%;
    max-width: 900px;
    margin: 0.75rem auto;
}

#promotionModal .modal-content {
    max-height: calc(100vh - 1.5rem);
    display: flex;
    flex-direction: column;
}

#promotionModal #promotionForm {
    min-height: 0;
    display: flex;
    flex: 1 1 auto;
    flex-direction: column;
    overflow: hidden;
}

#promotionModal #promotionForm .modal-body {
    min-height: 0;
    overflow-y: auto;
    overflow-x: hidden;
    -webkit-overflow-scrolling: touch;
}

#promotionModal #promotionForm .modal-footer {
    flex: 0 0 auto;
}

/* =========================================================
   PROMOTION DELETE CONFIRMATION MODAL
========================================================= */
.promotion-delete-modal {
    z-index: 1350 !important;
}

.promotion-delete-modal .modal-dialog {
    width: 100%;
    max-width: 430px;
    margin: 1rem auto;
}

.promotion-delete-modal .modal-content {
    border: 0;
    border-radius: 16px;
    overflow: hidden;
    background: #FFFFFF;
    box-shadow: 0 14px 40px rgba(44, 34, 30, .22);
}

.promotion-delete-modal .modal-body {
    padding: 26px 24px 20px;
    text-align: center;
}

.promotion-delete-icon {
    width: 58px;
    height: 58px;
    margin: 0 auto 14px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #FCE3E3;
    color: #A33A3A;
    font-size: 1.4rem;
}

.promotion-delete-title {
    margin: 0 0 8px;
    color: #2C221E;
    font-size: 1.08rem;
    font-weight: 800;
}

.promotion-delete-message {
    margin: 0;
    color: #6F6258;
    font-size: .84rem;
    line-height: 1.5;
}

.promotion-delete-name {
    display: block;
    margin-top: 6px;
    color: #4A3525;
    font-weight: 800;
    overflow-wrap: anywhere;
}

.promotion-delete-modal .modal-footer {
    display: flex;
    justify-content: center;
    gap: 9px;
    padding: 0 24px 24px;
    border-top: 0;
}

.promotion-delete-cancel,
.promotion-delete-confirm {
    min-height: 42px;
    padding: 9px 18px;
    border-radius: 9px;
    font-size: .8rem;
    font-weight: 800;
}

.promotion-delete-cancel {
    border: 2px solid #B8A08A;
    background: #FFFFFF;
    color: #6F4E37;
}

.promotion-delete-cancel:hover,
.promotion-delete-cancel:focus {
    background: #F7F1E8;
    border-color: #8B6F5A;
    color: #4A3525;
}

.promotion-delete-confirm {
    border: 2px solid #B64A4A;
    background: #B64A4A;
    color: #FFFFFF;
}

.promotion-delete-confirm:hover,
.promotion-delete-confirm:focus {
    border-color: #8E2F2F;
    background: #8E2F2F;
    color: #FFFFFF;
}

@media (max-width: 480px) {
    .promotion-delete-modal .modal-dialog {
        max-width: none;
        margin: .75rem;
    }

    .promotion-delete-modal .modal-body {
        padding: 22px 18px 16px;
    }

    .promotion-delete-modal .modal-footer {
        padding: 0 18px 18px;
    }

    .promotion-delete-cancel,
    .promotion-delete-confirm {
        flex: 1 1 0;
        min-width: 0;
    }
}

.modal-header {
    background: #4A3525;
    color: #fff;
    padding: 16px 20px;
}

.modal-title {
    font-weight: 700;
}

.modal-header .btn-close {
    filter: brightness(0) invert(1);
}

.modal-body {
    padding: 22px;
}

.modal-footer {
    background: #FAF8F5;
    border-top: 1px solid #E8DED2;
}


/* =========================================================
   FORMS
========================================================= */

.form-label {
    font-size: .84rem;
    font-weight: 600;
    color: #3B2C24;
}

.form-control,
.form-select {
    border-radius: 7px;
    border: 1px solid #D7CEC5;
    font-size: .84rem;
}

.form-control:focus,
.form-select:focus {
    border-color: #8B6A55;
    box-shadow: 0 0 0 .15rem rgba(74,53,37,.10);
}

.price-note {
    font-size: .7rem;
    color: #8A7F75;
}

.current-product-image {
    width: 120px;
    height: 120px;
    object-fit: cover;
    border-radius: 10px;
    border: 1px solid #E2DAD1;
}


/* =========================================================
   ADD-ON MANAGEMENT
========================================================= */

.price-pair {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    min-width: 130px;
    margin-bottom: 5px;
}

.price-pair:last-child {
    margin-bottom: 0;
}

.price-pair span {
    color: #7B7067;
    font-size: .72rem;
}

.price-pair strong {
    color: #4A3525;
    font-size: .8rem;
}

.manage-product-addons-btn {
    border-color: #B8A08A;
    color: #4A3525;
    background: #FFFFFF;
    font-size: .7rem;
    font-weight: 700;
    border-radius: 7px;
    white-space: nowrap;
}

.manage-product-addons-btn:hover {
    background: #F7F2EC;
    border-color: #8B6A55;
    color: #4A3525;
}

.addon-name-preview {
    color: #8A7F75;
    font-size: .67rem;
    line-height: 1.4;
    max-width: 190px;
    margin-top: 5px;
}

.addon-management-table {
    width: 100%;
    border-collapse: collapse;
}

.addon-management-table th,
.addon-management-table td {
    padding: 10px 12px;
    border-bottom: 1px solid #EEE8E2;
    vertical-align: middle;
}

.addon-management-table th {
    background: #F5F0E9;
    color: #4A3525;
    font-size: .72rem;
    font-weight: 700;
}

.addon-management-table td {
    font-size: .8rem;
}

.addon-management-table tr:last-child td {
    border-bottom: none;
}

.addon-name {
    color: #2C221E;
    font-weight: 700;
}

.addon-description {
    color: #8A7F75;
    font-size: .68rem;
    margin-top: 2px;
}

.addon-price {
    color: #4A3525;
    font-weight: 700;
}

.addon-available {
    background: #E7F6EC;
    color: #21884D;
    border-radius: 50px;
    padding: 4px 9px;
    font-size: .65rem;
    font-weight: 700;
}

.addon-unavailable {
    background: #FBE7E7;
    color: #C33131;
    border-radius: 50px;
    padding: 4px 9px;
    font-size: .65rem;
    font-weight: 700;
}

.addon-modal-scroll {
    max-height: 460px;
    overflow-y: auto;
    border: 1px solid #E5DDD4;
    border-radius: 10px;
}

.addon-checkbox-list {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
}

.addon-checkbox-item {
    border: 1px solid #E5DDD4;
    border-radius: 10px;
    padding: 10px 11px;
    background: #FFFDFC;
}

.addon-checkbox-item:hover {
    background: #FDF8F2;
}

.addon-checkbox-item label {
    width: 100%;
    cursor: pointer;
}

.addon-checkbox-item .addon-select-name {
    color: #4A3525;
    font-size: .76rem;
    font-weight: 700;
}

.addon-checkbox-item .addon-select-price {
    color: #8A7F75;
    font-size: .68rem;
    margin-top: 2px;
}

/* =========================================================
   SIZE OPTIONS + PRICING
========================================================= */
.size-option-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}

.size-option-card {
    display: block;
    padding: 14px;
    border: 2px solid #9A7A62;
    border-radius: 12px;
    background: #FFFEFC;
    cursor: pointer;
    transition: border-color .15s ease, background .15s ease, box-shadow .15s ease;
}

.size-option-card:hover {
    border-color: #6F4E37;
    background: #FDF8F2;
}

.size-option-card:has(.size-toggle-checkbox:checked) {
    border-color: #6F4E37;
    background: #FBF6F0;
    box-shadow: 0 3px 10px rgba(74,53,37,.07);
}

.size-option-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
}

.size-option-name {
    color: #3B2C24;
    font-size: .92rem;
    font-weight: 800;
}

.size-option-note {
    margin-top: 3px;
    color: #7B6D62;
    font-size: .72rem;
    line-height: 1.35;
}

.size-toggle-checkbox {
    width: 1.05rem;
    height: 1.05rem;
    flex: 0 0 auto;
    margin-top: 1px;
    border: 2px solid #7F624E;
    cursor: pointer;
}

.size-option-card .input-group-text,
.size-option-card .form-control {
    border-color: #9A7A62;
}

.size-option-card .form-control:disabled {
    background: #F1EBE5;
    color: #9A8C80;
    cursor: not-allowed;
}

.size-price-error {
    display: none;
    margin-top: 8px;
    padding: 9px 11px;
    border: 1px solid #B64A4A;
    border-radius: 9px;
    background: #FFF1F1;
    color: #8E2F2F;
    font-size: .76rem;
    font-weight: 700;
}

.size-price-error.show {
    display: block;
}

@media (max-width: 768px) {
    .size-option-grid {
        grid-template-columns: 1fr;
    }
}

/* =========================================================
   PROMOTIONS MANAGEMENT
========================================================= */
.promotion-management-panel {
    padding: 20px;
}

.promotion-management-heading {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 18px;
    padding-bottom: 18px;
    border-bottom: 2px solid #B79A82;
}

.promotion-management-heading .panel-title {
    font-size: 1.18rem;
    color: #2C221E;
    font-weight: 800;
}

.promotion-management-subtitle {
    margin-top: 4px;
    color: #7B6D62;
    font-size: .86rem;
}

.promotion-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
    margin-top: 18px;
}

.promotion-card {
    display: flex;
    min-height: 210px;
    background: #FFFFFF;
    border: 2px solid #8B6A55;
    border-radius: 15px;
    overflow: hidden;
    box-shadow: 0 5px 16px rgba(57,39,27,.06);
}

.promotion-card-media {
    position: relative;
    flex: 0 0 185px;
    min-height: 210px;
    background: #F3ECE4;
    border-right: 1px solid #8B6A55;
    overflow: hidden;
}

.promotion-card-media img,
.promotion-image-fallback {
    width: 100%;
    height: 100%;
    min-height: 210px;
    object-fit: cover;
}

.promotion-image-fallback {
    display: flex;
    align-items: center;
    justify-content: center;
    color: #6F4E37;
    font-size: 2rem;
    background: #EFE3D7;
}

.promotion-type-badge {
    position: absolute;
    left: 10px;
    top: 10px;
    display: inline-flex;
    padding: 6px 9px;
    border-radius: 999px;
    background: rgba(255,255,255,.96);
    color: #4A3525;
    border: 1px solid #6F4E37;
    font-size: .66rem;
    font-weight: 800;
    box-shadow: 0 2px 8px rgba(44,34,30,.12);
}

.promotion-card-body {
    flex: 1 1 auto;
    min-width: 0;
    padding: 16px 17px;
    display: flex;
    flex-direction: column;
}

.promotion-card-topline {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
}

.promotion-card-topline h3 {
    margin: 0;
    color: #2C221E;
    font-size: 1rem;
    line-height: 1.3;
    font-weight: 850;
}

.promotion-status {
    flex: 0 0 auto;
    padding: 6px 10px;
    border-radius: 999px;
    font-size: .78rem;
    font-weight: 850;
    line-height: 1.15;
    border: 1px solid currentColor;
}

.promotion-status.active { background:#EAF7EE; color:#287B49; }
.promotion-status.inactive { background:#F2ECE7; color:#6F6258; }
.promotion-status.upcoming { background:#FFF5DF; color:#91630A; }
.promotion-status.expired { background:#FCEBEC; color:#B13C3C; }

.promotion-description {
    margin: 10px 0 9px;
    color: #6F6258;
    font-size: .78rem;
    line-height: 1.45;
}

.promotion-rule-summary {
    display: flex;
    gap: 8px;
    align-items: flex-start;
    padding: 9px 10px;
    border: 1px solid #8B6A55;
    border-radius: 10px;
    background: #FBF8F4;
    color: #4A3525;
    font-size: .76rem;
    font-weight: 750;
    line-height: 1.35;
}

.promotion-rule-summary i {
    color: #6F4E37;
    margin-top: 1px;
}

.promotion-validity {
    display: flex;
    align-items: center;
    gap: 7px;
    margin-top: 10px;
    color: #7B6D62;
    font-size: .72rem;
}

.promotion-validity i { color: #6F4E37; }

.promotion-card-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
    margin-top: auto;
    padding-top: 14px;
}

.promotion-card-actions .btn {
    min-height: 40px;
    border-radius: 9px;
    padding: 8px 14px;
    font-size: .76rem;
    font-weight: 800;
    line-height: 1.1;
    border-width: 2px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    white-space: nowrap;
}

.promotion-card-actions .promotion-edit-btn {
    background: #E7F1FF !important;
    border-color: #4C78A8 !important;
    color: #285B9A !important;
}

.promotion-card-actions .promotion-edit-btn:hover,
.promotion-card-actions .promotion-edit-btn:focus {
    background: #D8E8FB !important;
    border-color: #2E5F97 !important;
    color: #214F82 !important;
}

.promotion-card-actions .promotion-activate-btn {
    background: #E5F6EA !important;
    border-color: #3F8A55 !important;
    color: #23733D !important;
}

.promotion-card-actions .promotion-activate-btn:hover,
.promotion-card-actions .promotion-activate-btn:focus {
    background: #D7EBDD !important;
    border-color: #2F6F43 !important;
    color: #205A34 !important;
}

.promotion-card-actions .promotion-deactivate-btn {
    background: #FCE3E3 !important;
    border-color: #B64A4A !important;
    color: #A33A3A !important;
}

.promotion-card-actions .promotion-deactivate-btn:hover,
.promotion-card-actions .promotion-deactivate-btn:focus {
    background: #F7D2D2 !important;
    border-color: #8E2F2F !important;
    color: #8E2F2F !important;
}

.promotion-card-actions .promotion-delete-btn {
    background: #FCE3E3 !important;
    border-color: #B64A4A !important;
    color: #A33A3A !important;
}

.promotion-card-actions .promotion-delete-btn:hover,
.promotion-card-actions .promotion-delete-btn:focus {
    background: #F7D2D2 !important;
    border-color: #8E2F2F !important;
    color: #8E2F2F !important;
}

@media (max-width: 575.98px) {
    .promotion-card-actions {
        gap: 8px;
    }

    .promotion-card-actions .btn {
        min-height: 40px;
        padding: 8px 12px;
        font-size: .74rem;
        flex: 1 1 auto;
    }
}

.promotion-panel-refreshing {
    opacity: .6;
    pointer-events: none;
    transition: opacity .15s ease;
}

.promotion-empty-state {
    margin-top: 18px;
    padding: 65px 20px;
    text-align: center;
    background: #FCFAF7;
    border: 2px dashed #8B6A55;
    border-radius: 14px;
}

.promotion-empty-icon {
    width: 58px;
    height: 58px;
    margin: 0 auto 12px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #F1E8DE;
    color: #6F4E37;
    font-size: 1.5rem;
}

.promotion-empty-title {
    color: #4A3525;
    font-weight: 850;
    font-size: 1rem;
}

.promotion-empty-text {
    max-width: 460px;
    margin: 5px auto 0;
    color: #7B6D62;
    font-size: .8rem;
    line-height: 1.45;
}

.promotion-rule-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}

/* Buy X Get Y is intentionally split into two separate sections:
   BUY on top, GET/FREE underneath. */
#ruleBuyGet.buy-get-rule-layout {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.buy-get-section {
    padding: 14px;
    border: 2px solid #8B6A55;
    border-radius: 12px;
    background: #FFFFFF;
}

.buy-section {
    background: #FFFEFC;
}

.get-section {
    background: #FBF6F0;
}

.buy-get-section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 12px;
    padding-bottom: 10px;
    border-bottom: 1px solid #D8C8B9;
}

.buy-get-section-label {
    color: #4A3525;
    font-size: .78rem;
    font-weight: 850;
    letter-spacing: .4px;
}

.buy-get-section-title {
    margin-top: 2px;
    color: #7B6D62;
    font-size: .72rem;
}

.buy-get-step-badge {
    flex: 0 0 auto;
    padding: 4px 9px;
    border: 1px solid #8B6A55;
    border-radius: 999px;
    background: #F5ECE2;
    color: #4A3525;
    font-size: .68rem;
    font-weight: 800;
}

.buy-get-fields {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}

.promotion-rule-panel {
    padding: 13px;
    border: 2px solid #8B6A55;
    border-radius: 12px;
    background: #FFFEFC;
}

.promotion-rule-panel.full-width {
    grid-column: 1 / -1;
}

.promotion-rule-panel-title {
    color: #4A3525;
    font-size: .78rem;
    font-weight: 850;
    margin-bottom: 8px;
}

.bundle-products-list {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 7px;
    max-height: 220px;
    overflow-y: auto;
    padding: 2px;
}

.bundle-selected-summary {
    margin-top: 9px;
    padding: 8px 9px;
    border: 1.5px solid #8B6A55;
    border-radius: 9px;
    background: #FBF6F0;
}

.bundle-selected-summary-title {
    color: #4A3525;
    font-size: .72rem;
    font-weight: 850;
    margin-bottom: 5px;
}

.bundle-selected-summary-list {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
}

.bundle-selected-summary-item {
    display: inline-flex;
    align-items: center;
    padding: 4px 7px;
    border: 1px solid #8B6A55;
    border-radius: 999px;
    background: #FFFFFF;
    color: #4A3525;
    font-size: .67rem;
    font-weight: 700;
}

.bundle-product-option {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 9px;
    border: 2px solid #8B6A55;
    border-radius: 9px;
    background: #FFFFFF;
    cursor: pointer;
}

.bundle-product-option {
    justify-content:space-between;
    align-items:center;
    gap:10px;
}
.bundle-product-option:hover { background:#F7F1E9; }
.bundle-product-check-label {
    display:flex;
    align-items:center;
    gap:8px;
    min-width:0;
    flex:1 1 auto;
    cursor:pointer;
}
.bundle-product-option input.bundle-product-checkbox { accent-color:#4A3525; }
.bundle-product-option span { color:#4A3525; font-size:.75rem; font-weight:700; }
.bundle-product-size {
    width:120px;
    min-width:120px;
    font-size:.72rem;
    padding:6px 8px;
    border:1.5px solid #8B6A55;
}
.promotion-size-select {
    border:1.5px solid #8B6A55;
}
@media (max-width: 520px) {
    .bundle-product-option { align-items:flex-start; flex-direction:column; }
    .bundle-product-size { width:100%; min-width:0; }
}

.promotion-current-image {
    width: 110px;
    height: 75px;
    border-radius: 9px;
    border: 2px solid #8B6A55;
    object-fit: cover;
    background:#F3ECE4;
}

@media (max-width: 1050px) {
    .promotion-grid { grid-template-columns: 1fr; }
}

@media (max-width: 768px) {
    .promotion-management-heading { flex-direction:column; }
    .promotion-grid { grid-template-columns:1fr; }
    .promotion-card { flex-direction:column; }
    .promotion-card-media { flex-basis:auto; min-height:150px; border-right:0; border-bottom:1px solid #8B6A55; }
    .promotion-card-media img, .promotion-image-fallback { min-height:150px; height:150px; }
    .promotion-rule-grid, .bundle-products-list { grid-template-columns:1fr; }
    .buy-get-fields { grid-template-columns:1fr; }
}

/* =========================================================
   USER ACCOUNT SETTINGS
========================================================= */

.user-account-management-panel {
    overflow: hidden;
}

#userAccountAjaxArea.user-account-ajax-loading {
    opacity: .55;
    pointer-events: none;
    transition: opacity .15s ease;
}


.user-account-management-heading {
    align-items: center;
}

.user-account-add-btn {
    min-height: 40px;
    padding: 8px 16px;
    border-radius: 999px;
    font-size: .78rem;
    font-weight: 800;
    border-width: 2px;
    white-space: nowrap;
}

.user-account-summary-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin: 20px 0;
}

.user-account-summary-card {
    text-decoration: none;
    background: #FDF8F2;
    border: 1px solid #E6DEC9;
    border-radius: 14px;
    padding: 15px;
    color: #4A3525;
    transition: .2s ease;
}

.user-account-summary-card:hover,
.user-account-summary-card.active {
    border-color: #8B6A55;
    background: #F7F0E8;
    color: #332317;
    transform: translateY(-1px);
}

.user-account-summary-icon {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    background: #EDE2D5;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 10px;
}

.user-account-summary-label {
    display: block;
    color: #766C65;
    font-size: .72rem;
    font-weight: 700;
}

.user-account-summary-value {
    display: block;
    margin-top: 3px;
    color: #2C221E;
    font-size: 1.35rem;
    line-height: 1.1;
}

.user-account-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    padding: 0 0 18px;
}

.user-account-search-form {
    max-width: 390px;
    width: 100%;
}

.user-account-search {
    position: relative;
}

.user-account-search i {
    position: absolute;
    left: 13px;
    top: 50%;
    transform: translateY(-50%);
    color: #8B6A55;
    pointer-events: none;
}

.user-account-search input {
    width: 100%;
    border: 1px solid #B8A08A;
    background: #FDF8F2;
    border-radius: 50px;
    padding: 9px 15px 9px 37px;
    font-size: .82rem;
    outline: none;
}

.user-account-search input:focus {
    border-color: #6F4E37;
    box-shadow: 0 0 0 .2rem rgba(111,78,55,.08);
}

.user-account-result-count {
    color: #8A817A;
    font-size: .75rem;
    font-weight: 700;
    white-space: nowrap;
}

.user-account-toolbar-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.user-account-toolbar-actions .btn {
    border-radius: 999px;
    font-size: .72rem;
    font-weight: 800;
}

.user-account-actions {
    min-width: 320px;
    text-align: right;
}

.user-account-action-group {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
    flex-wrap: wrap;
}

.user-account-action-group form {
    margin: 0;
}

.user-account-action-group .btn {
    min-height: 38px;
    padding: 7px 14px;
    border-radius: 999px;
    font-size: .76rem;
    font-weight: 800;
    letter-spacing: .01em;
    white-space: nowrap;
    border-width: 1.5px;
    box-shadow: none;
}

.user-account-action-group .btn-outline-secondary,
.user-account-action-group .user-account-edit-btn {
    color: #245B8A;
    border-color: #416F97;
    background: #F5FAFF;
}

.user-account-action-group .btn-outline-secondary:hover,
.user-account-action-group .user-account-edit-btn:hover {
    color: #173F60;
    border-color: #285B83;
    background: #EAF4FC;
}

.user-account-action-group .btn-outline-success {
    color: #2F6840;
    border-color: #4E7C5D;
    background: #F3FAF5;
}

.user-account-action-group .btn-outline-success:hover {
    color: #214E2F;
    border-color: #355F43;
    background: #E7F3EA;
}

.user-account-action-group .btn-outline-warning {
    color: #7A5410;
    border-color: #8F6A20;
    background: #FFF9E9;
}

.user-account-action-group .btn-outline-warning:hover {
    color: #593C08;
    border-color: #725317;
    background: #FFF0C2;
}

.user-account-toolbar-actions .user-account-status-btn {
    min-height: 38px;
    padding: 7px 16px;
    border-radius: 999px;
    font-size: .77rem;
    font-weight: 800;
    letter-spacing: .01em;
    border-width: 2px;
    box-shadow: none;
}

.user-account-toolbar-actions .user-account-status-active {
    color: #2F6840;
    border-color: #4E7C5D;
    background: #F3FAF5;
}

.user-account-toolbar-actions .user-account-status-active.active,
.user-account-toolbar-actions .user-account-status-active:hover {
    color: #FFFFFF;
    border-color: #2F6840;
    background: #3E7A4E;
}

.user-account-toolbar-actions .user-account-status-archived {
    color: #7A5410;
    border-color: #8F6A20;
    background: #FFF9E9;
}

.user-account-toolbar-actions .user-account-status-archived.active,
.user-account-toolbar-actions .user-account-status-archived:hover {
    color: #4F3507;
    border-color: #795A18;
    background: #F2C94C;
}

.user-account-you-action {
    color: #8A817A;
    font-size: .65rem;
    font-weight: 700;
}

.user-account-table-wrap {
    overflow-x: auto;
    border: 1.5px solid #B8A08A;
    border-radius: 13px;
}

.user-account-table {
    width: 100%;
    min-width: 820px;
    border-collapse: collapse;
}

.user-account-table th {
    padding: 12px 15px;
    background: #FDF8F2;
    color: #6F6259;
    border-bottom: 1.5px solid #C8B5A5;
    text-transform: uppercase;
    letter-spacing: .04em;
    font-size: .68rem;
    font-weight: 800;
    text-align: left;
}

.user-account-table td {
    padding: 14px 15px;
    color: #3A302A;
    font-size: .8rem;
    border-bottom: 1px solid #DDD0C5;
    vertical-align: middle;
}

.user-account-table tbody tr:last-child td {
    border-bottom: 0;
}

.user-account-table tbody tr {
    transition: background-color .15s ease;
}

.user-account-table tbody tr:hover {
    background: #FFFCF9;
}

.user-account-person {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 210px;
}

.user-account-avatar {
    width: 38px;
    height: 38px;
    flex: 0 0 38px;
    border-radius: 50%;
    background: #F0E6D6;
    color: #6F4E37;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: .72rem;
    font-weight: 900;
}

.user-account-name {
    color: #2C221E;
    font-weight: 800;
}

.user-account-you {
    color: #8A817A;
    font-size: .65rem;
    font-weight: 700;
}

.user-account-id {
    color: #9A918A;
    font-size: .66rem;
    margin-top: 2px;
}

.user-account-email {
    word-break: break-word;
}

.user-account-muted {
    color: #A39B94;
}

.user-account-role {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 9px;
    border-radius: 50px;
    font-size: .68rem;
    font-weight: 800;
}

.user-account-role.role-customer {
    background: #F3EEE8;
    color: #6F6259;
}

.user-account-role.role-staff {
    background: #EDE4DA;
    color: #634A37;
}

.user-account-role.role-admin {
    background: #E6D8CA;
    color: #593D2B;
}

.user-account-role.role-admin {
    background: #D9C5AF;
    color: #422B1D;
}

.user-account-date {
    color: #6F6259;
    white-space: nowrap;
}

.user-account-note {
    margin-top: 16px;
    padding: 11px 13px;
    border: 1px solid #E6DEC9;
    background: #FDF8F2;
    border-radius: 10px;
    color: #766C65;
    font-size: .74rem;
}

.user-account-note strong {
    color: #4A3525;
}

.user-account-empty {
    padding: 55px 20px;
    text-align: center;
    color: #8A817A;
}

.user-account-empty-icon {
    width: 54px;
    height: 54px;
    margin: 0 auto 12px;
    border-radius: 16px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #F0E6D6;
    color: #6F4E37;
    font-size: 1.2rem;
}

.user-account-empty-title {
    color: #4A3525;
    font-weight: 800;
    margin-bottom: 5px;
}

.user-account-empty-text {
    font-size: .78rem;
}

@media (max-width: 1100px) {
    .user-account-summary-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .user-account-toolbar {
        align-items: flex-start;
        flex-direction: column;
    }

    .user-account-toolbar-actions {
        width: 100%;
        justify-content: space-between;
    }

    .user-account-actions {
        text-align: left;
    }

    .user-account-action-group {
        justify-content: flex-start;
    }
}

/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 768px) {


    .settings-content {
        padding: 15px;
    }

    .product-table {
        min-width: 850px;
    }

}

/* =========================================================
   MODERN PRODUCT MANAGEMENT UI OVERRIDES
========================================================= */

.settings-content {
    padding: 28px;
    max-width: 1600px;
    margin: 0 auto;
}

.settings-page {
    background: #F7F3EE;
}

.settings-content > .mb-4 {
    margin-bottom: 22px !important;
}

.settings-content > .mb-4 h2 {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 1.45rem;
    letter-spacing: -.02em;
}

.settings-content > .mb-4 h2 i {
    width: 42px;
    height: 42px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin: 0 !important;
    border-radius: 12px;
    background: #4A3525;
    color: #fff;
    font-size: 1rem;
    box-shadow: 0 5px 14px rgba(74,53,37,.14);
}

.settings-content > .mb-4 .text-muted {
    margin-left: 52px;
}

.settings-content > .panel-card {
    border: 1px solid #E8DED3;
    border-radius: 18px;
    box-shadow: 0 7px 22px rgba(57,39,27,.055);
}

.settings-tabs-card {
    padding: 8px;
    margin-bottom: 18px !important;
    background: rgba(255,255,255,.86);
}

.settings-tabs {
    gap: 6px;
    margin: 0;
}

.settings-tabs .nav-link {
    border: 0 !important;
    border-radius: 11px;
    color: #7A6D63;
    font-size: .8rem;
    font-weight: 700;
    padding: 11px 16px;
    transition: .18s ease;
}

.settings-tabs .nav-link:hover {
    color: #4A3525;
    background: #F7F1E9;
}

.settings-tabs .nav-link.active {
    background: #4A3525;
    color: #fff;
    box-shadow: 0 4px 12px rgba(74,53,37,.12);
}

.product-management-panel {
    padding: 20px;
}

.product-management-heading {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 18px;
    padding-bottom: 18px;
    border-bottom: 1px solid #EEE6DE;
}

.product-management-heading .panel-title {
    font-size: 1.08rem;
    letter-spacing: -.01em;
}

.product-management-subtitle {
    margin-top: 4px;
    color: #8A7D72;
    font-size: .76rem;
}

.product-toolbar {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    flex-wrap: wrap;
    gap: 8px;
}

.product-filter-wrap {
    position: relative;
}

.product-filter-wrap i {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #8D7F73;
    pointer-events: none;
    font-size: .78rem;
}

.product-filter {
    min-width: 235px !important;
    height: 40px;
    padding: 0 34px 0 32px !important;
    border-radius: 11px !important;
    border: 1px solid #DCCFC2 !important;
    background: #FBF9F6;
    color: #4A3525;
    font-size: .77rem !important;
    font-weight: 600;
}

.product-toolbar .btn {
    height: 40px;
    border-radius: 11px;
    padding: 0 14px;
    font-size: .76rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.product-toolbar .btn-outline-secondary {
    color: #5E5148;
    border-color: #D7CBC0;
    background: #fff;
}

.product-toolbar .btn-outline-secondary:hover {
    background: #F7F1E9;
    color: #4A3525;
    border-color: #BBAA99;
}

.product-toolbar .btn-brown {
    box-shadow: 0 5px 12px rgba(74,53,37,.12);
}

.product-table-wrapper {
    margin-top: 18px;
    border: 1px solid #E7DED5;
    border-radius: 15px;
    overflow: auto;
    background: #fff;
}

.product-table {
    min-width: 980px;
}

.product-table thead {
    background: #FBF8F4;
}

.product-table th {
    padding: 12px 16px;
    border-bottom: 1px solid #E7DED5;
    color: #8A7D72;
    font-size: .68rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .07em;
    white-space: nowrap;
}

.product-table td {
    padding: 15px 16px;
    border-bottom: 1px solid #F0EAE4;
    color: #4B4038;
}

.product-table tbody tr {
    transition: background .16s ease, box-shadow .16s ease;
}

.product-table tbody tr:hover {
    background: #FCFAF7;
    box-shadow: inset 3px 0 0 #B79A7F;
}

.product-image {
    width: 56px;
    height: 56px;
    border-radius: 13px;
    border: 1px solid #E4D9CF;
    box-shadow: 0 3px 10px rgba(61,45,35,.06);
}

.product-name {
    font-size: .84rem;
    line-height: 1.25;
    margin-bottom: 3px;
}

.product-category {
    display: inline-flex;
    align-items: center;
    width: fit-content;
    max-width: 220px;
    padding: 3px 8px;
    border-radius: 999px;
    background: #F3ECE4;
    color: #78695D;
    font-size: .6rem;
    letter-spacing: .03em;
    text-transform: none;
}

.price-pair {
    min-width: 145px;
    margin-bottom: 6px;
    padding: 7px 9px;
    border-radius: 9px;
    background: #FBF8F4;
    border: 1px solid #EEE5DD;
}

.price-pair span {
    font-size: .66rem;
    color: #96877B;
}

.price-pair strong {
    font-size: .76rem;
}

.manage-product-addons-btn {
    border: 1px solid #D9CBBE;
    background: #FBF8F4;
    color: #5B493B;
    border-radius: 9px;
    padding: 6px 10px;
    font-size: .68rem;
    font-weight: 800;
    transition: .15s ease;
}

.manage-product-addons-btn:hover {
    background: #F2E9DF;
    border-color: #BFA891;
    color: #4A3525;
}

.addon-name-preview {
    color: #9A8D81;
    font-size: .63rem;
    line-height: 1.45;
    max-width: 205px;
    margin-top: 6px;
}

.availability-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 9px;
    border-radius: 999px;
    font-size: .62rem;
    font-weight: 800;
    white-space: nowrap;
}

.availability-badge.available::before {
    content: '';
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #2E9B5B;
}

.availability-badge.unavailable::before {
    content: '';
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #C04A4A;
}

.availability-badge.available {
    background: #EAF7EE;
    color: #287B49;
}

.availability-badge.unavailable {
    background: #FCEBEC;
    color: #B13C3C;
}

.product-actions {
    display: flex;
    align-items: center;
    gap: 7px;
    white-space: nowrap;
}

.product-actions .toggle-btn {
    height: 34px;
    border-radius: 9px;
    padding: 0 10px;
    border-color: #D9CEC4;
    background: #fff;
    color: #65574D;
    font-size: .66rem;
    font-weight: 700;
}

.product-actions .toggle-btn:hover {
    background: #F7F1E9;
    border-color: #BEAD9C;
    color: #4A3525;
}

.action-menu-btn {
    width: 34px;
    height: 34px;
    border-radius: 9px;
    border-color: #D9CEC4;
    background: #fff;
    color: #65574D;
}

.action-menu-btn:hover,
.action-menu-btn[aria-expanded="true"] {
    background: #F2EAE2;
    border-color: #BFAE9D;
    color: #4A3525;
}

.product-table .dropdown-menu {
    min-width: 185px;
    border: 1px solid #E5DCD2;
    border-radius: 12px;
    box-shadow: 0 14px 28px rgba(48,35,25,.12);
    padding: 6px;
}

.product-table .dropdown-item {
    padding: 9px 10px;
    border-radius: 8px;
    font-size: .74rem;
    font-weight: 600;
    color: #584A40;
}

.product-table .dropdown-item:hover {
    background: #F7F1E9;
}

.product-table .dropdown-divider {
    margin: 5px 0;
}

.empty-products-state {
    padding: 66px 20px;
    text-align: center;
    border: 1px dashed #D9CDC1;
    border-radius: 14px;
    background: #FCFAF7;
}

.empty-products-icon {
    width: 58px;
    height: 58px;
    margin: 0 auto 12px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #F1E8DE;
    color: #8B6E59;
}

@media (max-width: 1100px) {
    .product-management-heading {
        flex-direction: column;
        align-items: stretch;
    }

    .product-toolbar {
        justify-content: flex-start;
    }
}

@media (max-width: 768px) {
    .settings-content {
        padding: 18px 14px;
    }

    .settings-content > .mb-4 h2 {
        font-size: 1.2rem;
    }

    .settings-content > .mb-4 .text-muted {
        margin-left: 0;
    }

    .product-management-panel {
        padding: 15px;
    }

    .product-toolbar {
        width: 100%;
    }

    .product-filter-wrap,
    .product-filter,
    .product-toolbar .btn {
        width: 100%;
    }

    .product-filter {
        min-width: 0 !important;
    }

    .settings-tabs {
        overflow-x: auto;
        flex-wrap: nowrap;
    }

    .settings-tabs .nav-item {
        flex: 0 0 auto;
    }
}


/* =========================================================
   PRODUCT MANAGEMENT ACCESSIBILITY + TOGGLE POLISH
========================================================= */

/* Slightly larger type across the Product Management page */
.product-management-heading .panel-title {
    font-size: 1.16rem;
}

.product-management-subtitle {
    font-size: .86rem;
}

.settings-tabs .nav-link {
    font-size: .88rem;
}

.product-filter {
    font-size: .84rem !important;
}

.product-toolbar .btn {
    font-size: .82rem;
}

.product-table th {
    font-size: .74rem;
}

.product-table td {
    font-size: .9rem;
}

.product-name {
    font-size: .94rem;
}

.product-category {
    font-size: .68rem;
    padding: 4px 9px;
}

.price-pair span {
    font-size: .72rem;
}

.price-pair strong {
    font-size: .84rem;
}

.manage-product-addons-btn {
    font-size: .76rem;
}

.addon-name-preview {
    font-size: .7rem;
}

.availability-badge {
    font-size: .7rem;
    padding: 7px 10px;
}

.product-table .dropdown-item {
    font-size: .8rem;
}

/* Explicit availability action */
.product-actions .availability-action-btn {
    min-width: 118px;
    height: 38px;
    padding: 0 14px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    font-size: .74rem;
    font-weight: 800;
    line-height: 1;
    text-decoration: none;
    background: #FFFFFF;
    transition: background-color .16s ease, border-color .16s ease, color .16s ease, transform .16s ease;
}

.product-actions .availability-action-btn.set-unavailable {
    border: 1px solid #C85A5A;
    color: #A12E2E;
}

.product-actions .availability-action-btn.set-unavailable:hover {
    background: #FDECEC;
    border-color: #A12E2E;
    color: #8B2525;
    transform: translateY(-1px);
}

.product-actions .availability-action-btn.set-available {
    border: 1px solid #3B9B67;
    color: #237548;
}

.product-actions .availability-action-btn.set-available:hover {
    background: #ECF8F0;
    border-color: #237548;
    color: #1B633C;
    transform: translateY(-1px);
}

.product-actions .availability-action-btn:focus-visible {
    outline: 3px solid rgba(121, 91, 65, .18);
    outline-offset: 2px;
}

.action-menu-btn {
    width: 38px;
    height: 38px;
}

/* Make controls inside this section easier to read */
.product-management-panel .form-control,
.product-management-panel .form-select,
.product-management-panel .form-label,
.product-management-panel .modal-title,
.product-management-panel .modal-body,
.product-management-panel .btn {
    font-size: .86rem;
}

.product-management-panel .modal-title {
    font-weight: 800;
}

.bestseller-badge {
    display: inline-flex;
    align-items: center;
    margin-top: 5px;
    padding: 4px 8px;
    border-radius: 999px;
    background: #FFF4D6;
    border: 1px solid #D6AA45;
    color: #7A5600;
    font-size: .66rem;
    font-weight: 800;
    line-height: 1;
}

/* =========================================================
   DARKER BORDERS FOR LIGHT BACKGROUNDS
   Keep outlines clearly visible for readability.
========================================================= */

.panel-card,
.product-table-wrapper,
.product-image,
.current-product-image,
.dropdown-menu,
.manage-product-addons-btn,
.addon-modal-scroll,
.addon-checkbox-item,
.form-control,
.form-select {
    border-color: #8B6A55;
}

.product-table th {
    border-bottom-color: #8B6A55;
}

.product-table td,
.addon-management-table th,
.addon-management-table td {
    border-bottom-color: #C2B2A4;
}

.product-table tr:last-child td,
.addon-management-table tr:last-child td {
    border-bottom-color: transparent;
}

.modal-footer {
    border-top-color: #B8A08A;
}

.addon-management-table,
.addon-checkbox-item {
    border-color: #8B6A55;
}


/* =========================================================
   HIGH-CONTRAST BORDERS FOR LIGHT UI
   Main outlines are intentionally darker for visibility.
========================================================= */
.settings-content > .panel-card,
.settings-tabs-card,
.product-management-panel {
    border: 2px solid #6F4E37 !important;
}

.product-table-wrapper {
    border: 2px solid #8B6A55 !important;
}

.product-table th {
    border-bottom: 2px solid #8B6A55 !important;
}

.product-table td {
    border-bottom: 1px solid #B79A82 !important;
}

.product-table tr:last-child td {
    border-bottom-color: #B79A82 !important;
}

.price-pair,
.product-filter,
.product-toolbar .btn-outline-secondary,
.product-actions .toggle-btn,
.product-actions .action-menu-btn,
.manage-product-addons-btn {
    border-color: #8B6A55 !important;
}

.product-image,
.current-product-image {
    border: 1.5px solid #8B6A55 !important;
}

.product-management-heading {
    border-bottom: 2px solid #B79A82 !important;
}

.empty-products-state {
    border-color: #8B6A55 !important;
}

/* =========================================================
   HIGH-CONTRAST CHECKBOXES
   Bootstrap's native checkbox styling was overriding the
   earlier border rule, so the actual checkbox itself remained
   too light on the white modal background.
========================================================= */
.product-addon-checkbox.form-check-input,
.size-toggle-checkbox.form-check-input {
    -webkit-appearance: none !important;
    appearance: none !important;
    width: 18px !important;
    height: 18px !important;
    min-width: 18px !important;
    min-height: 18px !important;
    margin-top: 2px !important;
    border: 2px solid #5A3D2B !important;
    border-radius: 4px !important;
    background-color: #FFFFFF !important;
    background-image: none !important;
    box-shadow: none !important;
    cursor: pointer;
    position: relative;
    opacity: 1 !important;
}

.product-addon-checkbox.form-check-input:hover,
.size-toggle-checkbox.form-check-input:hover {
    border-color: #3F2A1E !important;
    background-color: #F8F1EA !important;
}

.product-addon-checkbox.form-check-input:focus,
.size-toggle-checkbox.form-check-input:focus {
    border-color: #4A3525 !important;
    box-shadow: 0 0 0 3px rgba(74,53,37,.16) !important;
}

.product-addon-checkbox.form-check-input:checked,
.size-toggle-checkbox.form-check-input:checked {
    background-color: #4A3525 !important;
    border-color: #4A3525 !important;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Cpath fill='none' stroke='%23ffffff' stroke-linecap='round' stroke-linejoin='round' stroke-width='2.5' d='m3.2 8.2 3.1 3.1 6.5-6.6'/%3E%3C/svg%3E") !important;
    background-repeat: no-repeat !important;
    background-position: center !important;
    background-size: 12px 12px !important;
}

.product-addon-checkbox.form-check-input:checked:hover,
.size-toggle-checkbox.form-check-input:checked:hover {
    background-color: #352419 !important;
    border-color: #352419 !important;
}

.addon-checkbox-item label {
    color: #2C221E;
}

.addon-checkbox-item {
    border: 2px solid #8B6A55 !important;
}

.size-option-card {
    border-width: 2px !important;
}

/* =========================================================
   FIXED ACTION TOASTS
   Does not affect document flow or page height.
========================================================= */
.settings-toast-wrap {
    position: fixed;
    top: 88px;
    right: 24px;
    z-index: 2000;
    width: min(420px, calc(100vw - 32px));
    pointer-events: none;
}

.settings-toast {
    position: relative;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 13px 14px;
    background: #ffffff;
    border: 2px solid #6F4E37;
    border-radius: 12px;
    box-shadow: 0 10px 28px rgba(44,34,30,.18);
    color: #2C221E;
    pointer-events: auto;
    overflow: hidden;
    animation: settingsToastIn .22s ease-out;
}

.settings-toast-success { border-left: 6px solid #4A8B5A; }
.settings-toast-warning { border-left: 6px solid #A56A1F; }

.settings-toast-icon {
    flex: 0 0 30px;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #F3EADF;
    color: #4A3525;
    font-size: 15px;
    margin-top: 1px;
}

.settings-toast-success .settings-toast-icon { color: #2F6E3E; background: #EAF6EE; }
.settings-toast-warning .settings-toast-icon { color: #87551A; background: #FFF1DD; }

.settings-toast-message {
    flex: 1;
    padding-top: 3px;
    font-size: .9rem;
    line-height: 1.45;
    font-weight: 700;
}

.settings-toast-close {
    flex: 0 0 auto;
    border: 0;
    background: transparent;
    color: #6F4E37;
    width: 30px;
    height: 30px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
}

.settings-toast-close:hover { background: #F0E6D6; color: #2C221E; }

.settings-toast-progress {
    position: absolute;
    left: 0;
    bottom: 0;
    height: 3px;
    width: 100%;
    background: #6F4E37;
    transform-origin: left center;
    animation: settingsToastProgress 3.5s linear forwards;
}
.settings-toast-success .settings-toast-progress { background: #4A8B5A; }
.settings-toast-warning .settings-toast-progress { background: #A56A1F; }
.settings-toast.is-closing { animation: settingsToastOut .18s ease-in forwards; }

@keyframes settingsToastIn {
    from { opacity: 0; transform: translateY(-8px) translateX(8px); }
    to { opacity: 1; transform: translateY(0) translateX(0); }
}
@keyframes settingsToastOut {
    from { opacity: 1; transform: translateY(0) translateX(0); }
    to { opacity: 0; transform: translateY(-8px) translateX(8px); }
}
@keyframes settingsToastProgress {
    from { transform: scaleX(1); }
    to { transform: scaleX(0); }
}

@media (max-width: 768px) {
    .settings-toast-wrap { top: 76px; right: 16px; width: calc(100vw - 32px); }
    .settings-toast-message { font-size: .88rem; }
}


/* =========================================================
   MOBILE LAYOUT (phones)
   Placed last so it wins over the desktop rules above.
========================================================= */
@media (max-width: 767.98px) {

    .settings-content {
        padding: 14px 12px 28px !important;
    }

    .settings-content > .mb-4 h2 {
        font-size: 1.25rem;
    }

    .settings-content > .mb-4 .text-muted {
        margin-left: 0 !important;
    }

    /* Tabs: all three visible, scroll only if a very small screen needs it */
    .settings-tabs-card {
        padding: 6px !important;
        margin-bottom: 14px !important;
    }

    .settings-tabs {
        flex-wrap: nowrap;
        gap: 4px;
        overflow-x: auto;
        scrollbar-width: none;
    }

    .settings-tabs::-webkit-scrollbar {
        display: none;
    }

    .settings-tabs .nav-item {
        flex: 1 1 0;
        min-width: 0;
    }

    .settings-tabs .nav-link {
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 46px;
        padding: 8px 6px !important;
        font-size: .76rem !important;
        line-height: 1.2;
        text-align: center;
        white-space: normal;
    }

    .product-management-panel,
    .settings-content > .panel-card {
        padding: 14px !important;
    }

    /* ---------- Product table -> cards ---------- */
    .product-table-wrapper {
        overflow: visible !important;
        border: 0 !important;
        background: transparent !important;
        margin-top: 14px;
    }

    .product-table {
        display: block;
        width: 100%;
        min-width: 0 !important;
    }

    .product-table thead {
        display: none;
    }

    .product-table tbody {
        display: block;
    }

    .product-table tbody tr {
        display: block;
        margin-bottom: 12px;
        padding: 12px 14px;
        background: #ffffff;
        border: 1px solid #D9C9BB;
        border-radius: 14px;
        box-shadow: 0 2px 8px rgba(57, 39, 27, .05);
    }

    /* ---------- User accounts table -> cards ---------- */
    .user-account-table-wrap {
        overflow: visible;
        border: 0;
        border-radius: 0;
    }

    .user-account-table {
        display: block;
        width: 100%;
        min-width: 0;
    }

    .user-account-table thead {
        display: none;
    }

    .user-account-table tbody {
        display: block;
    }

    .user-account-table tbody tr {
        display: block;
        margin-bottom: 12px;
        padding: 12px 14px;
        background: #ffffff;
        border: 1px solid #D9C9BB;
        border-radius: 14px;
        box-shadow: 0 2px 8px rgba(57, 39, 27, .05);
    }

    /* Shared label / value rows */
    .product-table tbody td,
    .user-account-table tbody td {
        display: grid;
        grid-template-columns: 92px minmax(0, 1fr);
        column-gap: 12px;
        align-items: start;
        width: auto;
        padding: 9px 0 !important;
        border: 0 !important;
        border-bottom: 1px dashed #E3D6CA !important;
        text-align: left;
        overflow-wrap: anywhere;
    }

    .product-table tbody td::before,
    .user-account-table tbody td::before {
        content: attr(data-label);
        grid-column: 1;
        grid-row: 1 / span 6;
        color: #8a7f75;
        font-size: .68rem;
        font-weight: 700;
        letter-spacing: .04em;
        text-transform: uppercase;
        padding-top: 2px;
    }

    .product-table tbody td > *,
    .user-account-table tbody td > * {
        grid-column: 2;
        min-width: 0;
    }

    /* Text-only cells (no child element) still sit in column 2 */
    .user-account-table tbody td.user-account-email,
    .user-account-table tbody td.user-account-date,
    .user-account-table tbody td[data-label="Phone"] {
        display: grid;
    }

    /* Identity + action cells span the whole card */
    .product-table tbody td[data-label="Product"],
    .user-account-table tbody td[data-label="Account"] {
        display: block;
        padding-top: 0 !important;
        border-bottom: 1px solid #D9C9BB !important;
    }

    .product-table tbody td[data-label="Product"]::before,
    .user-account-table tbody td[data-label="Account"]::before,
    .product-table tbody td[data-label="Action"]::before,
    .user-account-table tbody td[data-label="Actions"]::before {
        display: none;
    }

    .product-table tbody td[data-label="Action"],
    .user-account-table tbody td[data-label="Actions"] {
        display: block;
        padding: 12px 0 0 !important;
        border-bottom: 0 !important;
    }

    .product-actions,
    .user-account-action-group {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: stretch;
        gap: 8px;
    }

    .product-actions .availability-action-btn,
    .user-account-action-group > * {
        flex: 1 1 auto;
        min-height: 42px;
    }

    .product-actions .dropdown {
        flex: 0 0 auto;
    }

    .product-actions .action-menu-btn {
        min-height: 42px;
        min-width: 44px;
    }
}

/* =========================================================
   FINAL ADMIN SHELL ALIGNMENT
   The shared sidebar/navbar owns the desktop 260px offset.
   Keep the page wrapper full-width so the main content is not
   offset a second time on Laptop / Laptop L screens.
========================================================= */
@media (min-width: 992px) {
    .settings-page {
        margin-left: 0 !important;
        width: 100% !important;
        max-width: none !important;
        min-width: 0 !important;
        box-sizing: border-box !important;
    }

    .settings-page > .admin-main.settings-content {
        margin-left: 260px !important;
        width: calc(100% - 260px) !important;
        max-width: none !important;
        min-width: 0 !important;
        box-sizing: border-box !important;
    }
}

@media (max-width: 991.98px) {
    .settings-page {
        margin-left: 0 !important;
        width: 100% !important;
        max-width: none !important;
        min-width: 0 !important;
        box-sizing: border-box !important;
    }

    .settings-page > .admin-main.settings-content {
        margin-left: 0 !important;
        width: 100% !important;
        max-width: none !important;
        min-width: 0 !important;
        box-sizing: border-box !important;
    }
}

</style>


<div class="settings-page">

    <?php require_once 'sidebar.php'; ?>

    <?php require_once 'navbar.php'; ?>

    <!-- =====================================================
         MAIN CONTENT
    ====================================================== -->

    <main class="admin-main settings-content">


        <?php
        /*
         * Management action notifications.
         * Rendered as fixed toasts so they do not add page height
         * or move the user's current scroll position.
         */
        $settingsToast = null;

        if (isset($_GET['user_account_add_success'])) {
            $settingsToast = ['type'=>'success','icon'=>'bi-person-plus','message'=>'Account created successfully!'];
        } elseif (isset($_GET['promotion_success'])) {
            $settingsToast = ['type'=>'success','icon'=>'bi-megaphone','message'=>'Promotion created successfully!'];
        } elseif (isset($_GET['promotion_edit_success'])) {
            $settingsToast = ['type'=>'success','icon'=>'bi-check-circle','message'=>'Promotion updated successfully!'];
        } elseif (isset($_GET['promotion_toggle_success'])) {
            $settingsToast = ['type'=>'success','icon'=>'bi-power','message'=>'Promotion status updated successfully!'];
        } elseif (isset($_GET['promotion_delete_success'])) {
            $settingsToast = ['type'=>'success','icon'=>'bi-trash3','message'=>'Promotion deleted successfully!'];
        } elseif (isset($_GET['addon_success'])) {
            $settingsToast = ['type'=>'success','icon'=>'bi-plus-circle','message'=>'Add-on added successfully!'];
        } elseif (isset($_GET['addon_edit_success'])) {
            $settingsToast = ['type'=>'success','icon'=>'bi-check-circle','message'=>'Add-on updated successfully!'];
        } elseif (isset($_GET['addon_toggle_success'])) {
            $settingsToast = ['type'=>'success','icon'=>'bi-toggle-on','message'=>'Add-on availability updated successfully!'];
        } elseif (isset($_GET['addon_archive_success'])) {
            $settingsToast = ['type'=>'success','icon'=>'bi-archive','message'=>'Add-on archived successfully!'];
        } elseif (isset($_GET['addon_product_success'])) {
            $settingsToast = ['type'=>'success','icon'=>'bi-check-circle','message'=>'Product add-ons updated successfully!'];
        } elseif (isset($_GET['product_success'])) {
            $settingsToast = ['type'=>'success','icon'=>'bi-check-circle','message'=>'Product added successfully!'];
        } elseif (isset($_GET['edit_success'])) {
            $settingsToast = ['type'=>'success','icon'=>'bi-check-circle','message'=>'Product updated successfully!'];
        } elseif (isset($_GET['availability_success'])) {
            $settingsToast = ['type'=>'success','icon'=>'bi-toggle-on','message'=>'Product availability updated successfully!'];
        } elseif (isset($_GET['bestseller_success']) && $_GET['bestseller_success'] === 'added') {
            $settingsToast = ['type'=>'success','icon'=>'bi-star-fill','message'=>'Product added to Best Sellers!'];
        } elseif (isset($_GET['bestseller_success']) && $_GET['bestseller_success'] === 'removed') {
            $settingsToast = ['type'=>'success','icon'=>'bi-star','message'=>'Product removed from Best Sellers.'];
        } elseif (isset($_GET['bestseller_error']) && $_GET['bestseller_error'] === 'limit') {
            $settingsToast = ['type'=>'warning','icon'=>'bi-exclamation-triangle','message'=>'You can select up to 4 Best Seller products. Remove one first before adding another.'];
        } elseif (isset($_GET['bestseller_error']) && $_GET['bestseller_error'] === 'unavailable') {
            $settingsToast = ['type'=>'warning','icon'=>'bi-exclamation-triangle','message'=>'Only available, non-archived products can be marked as Best Sellers.'];
        } elseif (isset($_GET['bestseller_error']) && $_GET['bestseller_error'] === 'not_found') {
            $settingsToast = ['type'=>'warning','icon'=>'bi-exclamation-triangle','message'=>'The selected product was not found.'];
        } elseif (isset($_GET['availability_error']) && $_GET['availability_error']==='promotion_active') {
            $settingsToast = ['type'=>'warning','icon'=>'bi-exclamation-triangle','message'=>'This product cannot be set to Out of Stock while it is used by an active promotion. Deactivate or update the promotion first.'];
        } elseif (isset($_GET['delete_success'])) {
            $settingsToast = ['type'=>'success','icon'=>'bi-archive','message'=>'Product archived successfully!'];
        } elseif (isset($_GET['delete_error']) && $_GET['delete_error']==='promotion_active') {
            $settingsToast = ['type'=>'warning','icon'=>'bi-exclamation-triangle','message'=>'This product cannot be archived while it is used by an active promotion. Deactivate or update the promotion first.'];
        } elseif (isset($_GET['delete_error']) && $_GET['delete_error']==='not_found') {
            $settingsToast = ['type'=>'warning','icon'=>'bi-exclamation-triangle','message'=>'The product was not found or is already archived.'];
        }
        ?>

        <?php if ($settingsToast): ?>
            <div class="settings-toast-wrap" aria-live="polite" aria-atomic="true">
                <div
                    class="settings-toast settings-toast-<?= htmlspecialchars($settingsToast['type']) ?>"
                    id="settingsActionToast"
                    role="status"
                >
                    <span class="settings-toast-icon">
                        <i class="bi <?= htmlspecialchars($settingsToast['icon']) ?>"></i>
                    </span>
                    <span class="settings-toast-message">
                        <?= htmlspecialchars($settingsToast['message']) ?>
                    </span>
                    <button
                        type="button"
                        class="settings-toast-close"
                        id="settingsToastClose"
                        aria-label="Close notification"
                    >
                        <i class="bi bi-x-lg"></i>
                    </button>
                    <span class="settings-toast-progress" aria-hidden="true"></span>
                </div>
            </div>
        <?php endif; ?>


        <!-- =====================================================
             FORM ERRORS
        ====================================================== -->

        <?php if (!empty($errors)): ?>

            <div class="alert alert-danger">

                <strong>Unable to save product:</strong>

                <ul class="mb-0 mt-2">

                    <?php foreach ($errors as $error): ?>

                        <li>
                            <?= htmlspecialchars($error) ?>
                        </li>

                    <?php endforeach; ?>

                </ul>

            </div>

        <?php endif; ?>


        <!-- =====================================================
             SETTINGS TABS
        ====================================================== -->

        <div class="panel-card settings-tabs-card">

            <ul class="nav nav-pills settings-tabs">

                <li class="nav-item">
                    <a
                        class="nav-link <?= $selectedTab === 'products' ? 'active' : '' ?>"
                        href="settings.php?tab=products&amp;category=<?= urlencode($selectedCategory) ?>"
                    >
                        Product Management
                    </a>
                </li>

                <li class="nav-item">
                    <a
                        class="nav-link <?= $selectedTab === 'promotions' ? 'active' : '' ?>"
                        href="settings.php?tab=promotions"
                    >
                        Promotions
                    </a>
                </li>

                <li class="nav-item">
                    <a
                        class="nav-link <?= $selectedTab === 'user_accounts' ? 'active' : '' ?>"
                        href="settings.php?tab=user_accounts"
                    >
                        User Account Settings
                    </a>
                </li>

            </ul>

        </div>


<?php if ($selectedTab === 'products'): ?>

        <!-- =====================================================
             PRODUCT MANAGEMENT
        ====================================================== -->

        <div class="panel-card product-management-panel">

            <div class="product-management-heading">

                <div>

                    <div class="panel-title">
                        <?= htmlspecialchars(
                            $selectedCategory === 'All Products'
                                ? 'All Products'
                                : $selectedCategory
                        ) ?>
                    </div>

                    <div class="product-management-subtitle">
                        Add and manage products, prices, customization options, and availability.
                    </div>

                </div>


                <div class="product-toolbar">

                    <!-- CATEGORY FILTER -->

                    <form method="GET" class="product-filter-wrap">

                        <i class="bi bi-funnel-fill"></i>

                        <select
                            name="category"
                            class="form-select product-filter"
                            onchange="this.form.submit()"
                        >

                            <option
                                value="All Products"
                                <?= $selectedCategory === 'All Products'
                                    ? 'selected'
                                    : '' ?>
                            >
                                All Products
                            </option>


                            <?php foreach ($productTypes as $type): ?>

                                <option
                                    value="<?= htmlspecialchars($type) ?>"
                                    <?= $selectedCategory === $type
                                        ? 'selected'
                                        : '' ?>
                                >
                                    <?= htmlspecialchars($type) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </form>


                    <!-- MANAGE ADD-ONS -->

                    <button
                        type="button"
                        class="btn btn-outline-secondary"
                        data-bs-toggle="modal"
                        data-bs-target="#manageAddonsModal"
                    >
                        <i class="bi bi-patch-plus me-1"></i>
                        Manage Add-ons
                    </button>


                    <!-- ADD PRODUCT -->

                    <button
                        type="button"
                        class="btn btn-brown"
                        data-bs-toggle="modal"
                        data-bs-target="#addProductModal"
                    >

                        <i class="bi bi-plus-lg me-1"></i>

                        Add Product

                    </button>

                </div>

            </div>


            <!-- =================================================
                 PRODUCT TABLE
            ================================================== -->

            <?php if (empty($products)): ?>

                <div class="empty-products-state">
                    <div class="empty-products-icon">
                        <i class="bi bi-cup-straw" style="font-size: 24px;"></i>
                    </div>
                    <div class="fw-semibold" style="color:#4A3525;">No products found</div>
                    <div class="text-muted small mt-1">There are no active products in this category.</div>
                </div>

            <?php else: ?>

                <div class="product-table-wrapper">

                    <table class="product-table">

                        <thead>

                            <tr>

                                <th>
                                    Product
                                </th>

                                <th>
                                    Size &amp; Pricing
                                </th>

                                <th>
                                    Add-ons
                                </th>

                                <th>
                                    Status
                                </th>

                                <th>
                                    Action
                                </th>

                            </tr>

                        </thead>


                        <tbody>

                            <?php foreach ($products as $product): ?>

                                <?php

                                $regularPrice = $product['regular_price'];
                                $grandePrice = $product['grande_price'];

                                /*
                                 * Backward compatibility for legacy products
                                 * that have no explicit size prices yet.
                                 */
                                if ($regularPrice === null && $grandePrice === null) {
                                    $regularPrice = $product['price'];
                                }

                                $hasRegular = $regularPrice !== null;
                                $hasGrande = $grandePrice !== null;

                                ?>

                                <tr>

                                    <!-- PRODUCT -->

                                    <td data-label="Product">

                                        <div class="d-flex align-items-center gap-3 product-row-info">

                                            <img
                                                class="product-image"
                                                src="../assets/uploads/products/<?= htmlspecialchars(
                                                    $product['image']
                                                    ?: 'default-product.png'
                                                ) ?>"
                                                alt="<?= htmlspecialchars(
                                                    $product['name']
                                                ) ?>"
                                                onerror="
                                                    this.onerror=null;
                                                    this.src='data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSI1NiIgaGVpZ2h0PSI1NiIgdmlld0JveD0iMCAwIDU2IDU2Ij48cmVjdCB3aWR0aD0iNTYiIGhlaWdodD0iNTYiIHJ4PSIxMyIgZmlsbD0iI0YzRUNFNCIvPjxwYXRoIGQ9Ik0xOSAxOGgxOHYxM2MwIDYtNCAxMC05IDEwcy05LTQtOS0xMFYxOFoiIGZpbGw9Im5vbmUiIHN0cm9rZT0iIzZGNEUzNyIgc3Ryb2tlLXdpZHRoPSIyIi8+PHBhdGggZD0iTTM3IDIyaDJhNSA1IDAgMCAxIDAgMTBoLTIiIGZpbGw9Im5vbmUiIHN0cm9rZT0iIzZGNEUzNyIgc3Ryb2tlLXdpZHRoPSIyIi8+PHBhdGggZD0iTTIzIDE0djRNMjggMTJ2Nk0zMyAxNHY0IiBzdHJva2U9IiM2RjRFMzciIHN0cm9rZS13aWR0aD0iMiIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIi8+PC9zdmc+';
                                                "
                                            >

                                            <div>

                                                <div class="product-name">

                                                    <?= htmlspecialchars(
                                                        $product['name']
                                                    ) ?>

                                                </div>

                                                <div class="product-category">

                                                    <?= htmlspecialchars(
                                                        $product['category_name']
                                                        ?? 'Uncategorized'
                                                    ) ?>

                                                </div>

                                                <?php if ((int)($product['is_bestseller'] ?? 0) === 1): ?>
                                                    <span class="bestseller-badge">
                                                        <i class="bi bi-star-fill me-1"></i>Best Seller
                                                    </span>
                                                <?php endif; ?>

                                            </div>

                                        </div>

                                    </td>


                                    <!-- SIZE & PRICING -->

                                    <td data-label="Size &amp; Pricing">

                                        <?php if ($hasRegular): ?>
                                            <div class="price-pair">
                                                <span>Regular</span>
                                                <strong>
                                                    ₱<?= number_format(
                                                        (float)$regularPrice,
                                                        2
                                                    ) ?>
                                                </strong>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($hasGrande): ?>
                                            <div class="price-pair">
                                                <span>Grande</span>
                                                <strong>
                                                    ₱<?= number_format(
                                                        (float)$grandePrice,
                                                        2
                                                    ) ?>
                                                </strong>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!$hasRegular && !$hasGrande): ?>
                                            <span class="text-muted">No size pricing</span>
                                        <?php endif; ?>

                                    </td>


                                    <!-- ADD-ONS -->

                                    <td data-label="Add-ons">

                                        <?php if ((int)($product['addon_count'] ?? 0) > 0): ?>

                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-secondary manage-product-addons-btn"
                                                data-bs-toggle="modal"
                                                data-bs-target="#productAddonsModal"
                                                data-product-id="<?= (int)$product['id'] ?>"
                                                data-product-name="<?= htmlspecialchars($product['name'], ENT_QUOTES) ?>"
                                                data-addon-ids="<?= htmlspecialchars(
                                                    json_encode(
                                                        $productAddonMap[(int)$product['id']] ?? []
                                                    ),
                                                    ENT_QUOTES
                                                ) ?>"
                                            >
                                                <?= (int)$product['addon_count'] ?> Add-ons
                                            </button>

                                            <div class="addon-name-preview">
                                                <?= htmlspecialchars(
                                                    $product['addon_names'] ?? '',
                                                    ENT_QUOTES
                                                ) ?>
                                            </div>

                                        <?php else: ?>

                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-secondary manage-product-addons-btn"
                                                data-bs-toggle="modal"
                                                data-bs-target="#productAddonsModal"
                                                data-product-id="<?= (int)$product['id'] ?>"
                                                data-product-name="<?= htmlspecialchars($product['name'], ENT_QUOTES) ?>"
                                                data-addon-ids="[]"
                                            >
                                                Add Add-ons
                                            </button>

                                        <?php endif; ?>

                                    </td>


                                    <!-- STATUS -->

                                    <td data-label="Status">

                                        <span
                                            class="availability-badge
                                            <?= $product['is_available']
                                                ? 'available'
                                                : 'unavailable' ?>"
                                        >

                                            <?= $product['is_available']
                                                ? 'Available'
                                                : 'Out of Stock'
                                            ?>

                                        </span>

                                    </td>


                                    <!-- ACTION -->

                                    <td data-label="Action">

                                        <div class="product-actions">

                                            <!-- AVAILABILITY ACTION -->

                                            <a
                                                href="settings.php?toggle_availability=1&id=<?= (int)$product['id'] ?>&category=<?= urlencode($selectedCategory) ?>"
                                                class="btn availability-action-btn <?= $product['is_available'] ? 'set-unavailable' : 'set-available' ?>"
                                                title="<?= $product['is_available'] ? 'Set product as out of stock' : 'Set product as available' ?>"
                                            >
                                                <?= $product['is_available'] ? 'Set Unavailable' : 'Set Available' ?>
                                            </a>


                                            <!-- THREE DOT MENU -->

                                            <div class="dropdown">

                                                <button
                                                    type="button"
                                                    class="btn btn-outline-secondary action-menu-btn"
                                                    data-bs-toggle="dropdown"
                                                    aria-expanded="false"
                                                    title="More Actions"
                                                >

                                                    <i class="bi bi-three-dots-vertical"></i>

                                                </button>


                                                <ul class="dropdown-menu dropdown-menu-end">

                                                    <!-- EDIT -->

                                                    <li>

                                                        <button
                                                            type="button"
                                                            class="dropdown-item edit-product-btn"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#editProductModal"
                                                            data-id="<?= (int)$product['id'] ?>"
                                                            data-name="<?= htmlspecialchars(
                                                                $product['name'],
                                                                ENT_QUOTES
                                                            ) ?>"
                                                            data-category="<?= htmlspecialchars(
                                                                $product['category_name'] ?? '',
                                                                ENT_QUOTES
                                                            ) ?>"
                                                            data-has-regular="<?= $hasRegular ? '1' : '0' ?>"
                                                            data-has-grande="<?= $hasGrande ? '1' : '0' ?>"
                                                            data-regular="<?= htmlspecialchars(
                                                                $hasRegular && $regularPrice !== null ? $regularPrice : '',
                                                                ENT_QUOTES
                                                            ) ?>"
                                                            data-grande="<?= htmlspecialchars(
                                                                $hasGrande && $grandePrice !== null ? $grandePrice : '',
                                                                ENT_QUOTES
                                                            ) ?>"
                                                            data-image="<?= htmlspecialchars(
                                                                $product['image'] ?: 'default-product.png',
                                                                ENT_QUOTES
                                                            ) ?>"
                                                        >

                                                            <i class="bi bi-pencil me-2"></i>

                                                            Edit Product

                                                        </button>

                                                    </li>


                                                    <!-- BEST SELLER -->

                                                    <li>

                                                        <a
                                                            class="dropdown-item <?= (int)($product['is_bestseller'] ?? 0) === 1 ? 'text-warning' : 'text-dark' ?>"
                                                            href="settings.php?toggle_bestseller=1&id=<?= (int)$product['id'] ?>&category=<?= urlencode($selectedCategory) ?>"
                                                        >
                                                            <i class="bi <?= (int)($product['is_bestseller'] ?? 0) === 1 ? 'bi-star-fill' : 'bi-star' ?> me-2"></i>
                                                            <?= (int)($product['is_bestseller'] ?? 0) === 1
                                                                ? 'Remove from Best Sellers'
                                                                : 'Mark as Best Seller' ?>
                                                        </a>

                                                    </li>


                                                    <!-- DELETE / ARCHIVE -->

                                                    <li>

                                                        <a
                                                            class="dropdown-item text-danger"
                                                            href="settings.php?delete_product=1&id=<?= (int)$product['id'] ?>&category=<?= urlencode($selectedCategory) ?>"
                                                            onclick="
                                                                return confirm(
                                                                    'Delete this product? It will be archived and kept in the database instead of being permanently deleted.'
                                                                );
                                                            "
                                                        >

                                                            <i class="bi bi-trash me-2"></i>

                                                            Delete Product

                                                        </a>

                                                    </li>

                                                </ul>

                                            </div>

                                        </div>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

        </div>

<?php elseif ($selectedTab === 'promotions'): ?>

        <!-- =====================================================
             PROMOTIONS MANAGEMENT
        ====================================================== -->
        <?php
        $promotionRows = [];
        $promotionRuleMap = [];
        $promotionRuleItemsMap = [];

        $promotionStmt = $pdo->query("\n            SELECT *\n            FROM promotions\n            WHERE is_archived = 0\n            ORDER BY created_at DESC, id DESC\n        ");
        $promotionRows = $promotionStmt->fetchAll(PDO::FETCH_ASSOC);

        if ($promotionRows) {
            $promotionIds = array_map(static fn($row) => (int)$row['id'], $promotionRows);
            $promotionPlaceholders = implode(',', array_fill(0, count($promotionIds), '?'));

            $ruleStmt = $pdo->prepare("\n                SELECT *\n                FROM promotion_rules\n                WHERE promotion_id IN ({$promotionPlaceholders})\n            ");
            $ruleStmt->execute($promotionIds);
            foreach ($ruleStmt->fetchAll(PDO::FETCH_ASSOC) as $rule) {
                $promotionRuleMap[(int)$rule['promotion_id']] = $rule;
            }

            $ruleIds = array_map(static fn($rule) => (int)$rule['id'], $ruleStmt->fetchAll(PDO::FETCH_ASSOC));
            // Re-query rule ids from the map because PDO statements are forward-only.
            $ruleIds = array_map(static fn($rule) => (int)$rule['id'], array_values($promotionRuleMap));

            if ($ruleIds) {
                $rulePlaceholders = implode(',', array_fill(0, count($ruleIds), '?'));
                $itemStmt = $pdo->prepare("\n                    SELECT pri.*, p.name AS product_name\n                    FROM promotion_rule_items pri\n                    INNER JOIN products p ON p.id = pri.product_id\n                    WHERE pri.rule_id IN ({$rulePlaceholders})\n                    ORDER BY pri.rule_id ASC, pri.role ASC, pri.id ASC\n                ");
                $itemStmt->execute($ruleIds);
                foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
                    $promotionRuleItemsMap[(int)$item['rule_id']][] = $item;
                }
            }
        }

        $todayDate = date('Y-m-d');
        ?>

        <div class="panel-card promotion-management-panel">
            <div class="promotion-management-heading">
                <div>
                    <div class="panel-title">Promotions</div>
                    <div class="promotion-management-subtitle">
                        Create and manage special offers, bundles, and product discounts.
                    </div>
                </div>

                <button
                    type="button"
                    class="btn btn-brown"
                    data-bs-toggle="modal"
                    data-bs-target="#promotionModal"
                    id="createPromotionBtn"
                >
                    <i class="bi bi-plus-lg me-1"></i>
                    Create Promotion
                </button>
            </div>

            <?php if (empty($promotionRows)): ?>
                <div class="promotion-empty-state">
                    <div class="promotion-empty-icon"><i class="bi bi-megaphone"></i></div>
                    <div class="promotion-empty-title">No promotions yet</div>
                    <div class="promotion-empty-text">Create your first promotion to feature special offers on the customer home page.</div>
                    <button type="button" class="btn btn-brown mt-3" data-bs-toggle="modal" data-bs-target="#promotionModal">
                        <i class="bi bi-plus-lg me-1"></i>Create Promotion
                    </button>
                </div>
            <?php else: ?>
                <div class="promotion-grid">
                    <?php foreach ($promotionRows as $promotion): ?>
                        <?php
                        $promotionId = (int)$promotion['id'];
                        $rule = $promotionRuleMap[$promotionId] ?? null;
                        $items = $rule ? ($promotionRuleItemsMap[(int)$rule['id']] ?? []) : [];
                        $status = 'Inactive';
                        $statusClass = 'inactive';
                        if ((int)$promotion['is_active'] === 1) {
                            if ($todayDate < $promotion['start_date']) {
                                $status = 'Upcoming';
                                $statusClass = 'upcoming';
                            } elseif ($todayDate > $promotion['end_date']) {
                                $status = 'Expired';
                                $statusClass = 'expired';
                            } else {
                                $status = 'Active';
                                $statusClass = 'active';
                            }
                        }

                        $typeLabel = $promotionRuleTypes[$rule['rule_type'] ?? ''] ?? 'Promotion';
                        $summary = 'Promotion rule not configured.';
                        $editDataItems = [];

                        if ($rule) {
                            foreach ($items as $item) {
                                $editDataItems[] = [
                                    'product_id' => (int)$item['product_id'],
                                    'role' => $item['role'],
                                    'quantity' => (int)$item['quantity'],
                                    'size' => $item['size'] ?? null
                                ];
                            }

                            $formatPromotionSize = static function ($size): string {
                                $size = strtolower(trim((string)$size));
                                return $size === 'regular' ? 'Regular' : ($size === 'grande' ? 'Grande' : 'Any size');
                            };

                            if ($rule['rule_type'] === 'bogo') {
                                $item = $items[0] ?? [];
                                $name = $item['product_name'] ?? 'Selected product';
                                $size = $formatPromotionSize($item['size'] ?? null);
                                $summary = "Buy 1 {$name} ({$size}), get 1 free";
                            } elseif ($rule['rule_type'] === 'buy_x_get_y') {
                                $buyName = 'Selected product';
                                $getName = 'Selected product';
                                $buySize = 'Any size';
                                $getSize = 'Any size';
                                foreach ($items as $item) {
                                    if ($item['role'] === 'buy') {
                                        $buyName = $item['product_name'];
                                        $buySize = $formatPromotionSize($item['size'] ?? null);
                                    }
                                    if ($item['role'] === 'get') {
                                        $getName = $item['product_name'];
                                        $getSize = $formatPromotionSize($item['size'] ?? null);
                                    }
                                }
                                $summary = "Buy {$rule['buy_quantity']} {$buyName} ({$buySize}), get {$rule['get_quantity']} {$getName} ({$getSize}) free";
                            } elseif ($rule['rule_type'] === 'bundle') {
                                $parts = [];
                                foreach (array_slice($items, 0, 4) as $item) {
                                    $parts[] = $item['product_name'] . ' (' . $formatPromotionSize($item['size'] ?? null) . ')';
                                }
                                $summary = 'Bundle: ' . implode(' + ', $parts);
                                if (count($items) > 4) $summary .= ' +' . (count($items) - 4) . ' more';
                                $summary .= ' — ₱' . number_format((float)$rule['bundle_price'], 2);
                            } elseif ($rule['rule_type'] === 'percentage') {
                                $item = $items[0] ?? [];
                                $name = $item['product_name'] ?? 'Selected product';
                                $size = $formatPromotionSize($item['size'] ?? null);
                                $summary = number_format((float)$rule['discount_value'], 0) . "% off {$name} ({$size})";
                            } elseif ($rule['rule_type'] === 'fixed') {
                                $item = $items[0] ?? [];
                                $name = $item['product_name'] ?? 'Selected product';
                                $size = $formatPromotionSize($item['size'] ?? null);
                                $summary = '₱' . number_format((float)$rule['discount_value'], 2) . " off {$name} ({$size})";
                            }
                        }

                        $imagePath = !empty($promotion['image'])
                            ? '../assets/uploads/promotions/' . $promotion['image']
                            : '';
                        $editItemsJson = htmlspecialchars(json_encode($editDataItems, JSON_UNESCAPED_UNICODE), ENT_QUOTES);
                        ?>

                        <article class="promotion-card">
                            <div class="promotion-card-media">
                                <?php if ($imagePath): ?>
                                    <img
                                        src="<?= htmlspecialchars($imagePath) ?>"
                                        alt="<?= htmlspecialchars($promotion['title']) ?>"
                                        onerror="this.onerror=null;this.style.display='none';this.nextElementSibling.style.display='flex';"
                                    >
                                    <div class="promotion-image-fallback" style="display:none;"><i class="bi bi-megaphone"></i></div>
                                <?php else: ?>
                                    <div class="promotion-image-fallback"><i class="bi bi-megaphone"></i></div>
                                <?php endif; ?>
                                <span class="promotion-type-badge"><?= htmlspecialchars($typeLabel) ?></span>
                            </div>

                            <div class="promotion-card-body">
                                <div class="promotion-card-topline">
                                    <h3><?= htmlspecialchars($promotion['title']) ?></h3>
                                    <span class="promotion-status <?= $statusClass ?>"><?= htmlspecialchars($status) ?></span>
                                </div>

                                <?php if (!empty($promotion['description'])): ?>
                                    <p class="promotion-description"><?= nl2br(htmlspecialchars($promotion['description'])) ?></p>
                                <?php endif; ?>

                                <div class="promotion-rule-summary">
                                    <i class="bi bi-tag"></i>
                                    <span><?= htmlspecialchars($summary) ?></span>
                                </div>

                                <div class="promotion-validity">
                                    <i class="bi bi-calendar3"></i>
                                    <?= htmlspecialchars(date('M d, Y', strtotime($promotion['start_date']))) ?>
                                    –
                                    <?= htmlspecialchars(date('M d, Y', strtotime($promotion['end_date']))) ?>
                                </div>

                                <div class="promotion-card-actions">
                                    <button
                                        type="button"
                                        class="btn promotion-edit-btn"
                                        data-bs-toggle="modal"
                                        data-bs-target="#promotionModal"
                                        data-promotion-id="<?= $promotionId ?>"
                                        data-title="<?= htmlspecialchars($promotion['title'], ENT_QUOTES) ?>"
                                        data-description="<?= htmlspecialchars($promotion['description'] ?? '', ENT_QUOTES) ?>"
                                        data-type="<?= htmlspecialchars($rule['rule_type'] ?? '', ENT_QUOTES) ?>"
                                        data-start="<?= htmlspecialchars($promotion['start_date'], ENT_QUOTES) ?>"
                                        data-end="<?= htmlspecialchars($promotion['end_date'], ENT_QUOTES) ?>"
                                        data-image="<?= htmlspecialchars($promotion['image'] ?? '', ENT_QUOTES) ?>"
                                        data-buy-quantity="<?= (int)($rule['buy_quantity'] ?? 1) ?>"
                                        data-get-quantity="<?= (int)($rule['get_quantity'] ?? 1) ?>"
                                        data-discount-value="<?= htmlspecialchars((string)($rule['discount_value'] ?? ''), ENT_QUOTES) ?>"
                                        data-bundle-price="<?= htmlspecialchars((string)($rule['bundle_price'] ?? ''), ENT_QUOTES) ?>"
                                        data-items="<?= $editItemsJson ?>"
                                    >
                                        <i class="bi bi-pencil me-1"></i>Edit
                                    </button>

                                    <a
                                        class="btn promotion-toggle-btn <?= (int)$promotion['is_active'] === 1 ? 'promotion-deactivate-btn' : 'promotion-activate-btn' ?>"
                                        href="settings.php?tab=promotions&amp;toggle_promotion=1&amp;id=<?= $promotionId ?>"
                                        data-promotion-id="<?= $promotionId ?>"
                                    >
                                        <i class="bi bi-power me-1"></i><?= (int)$promotion['is_active'] === 1 ? 'Deactivate' : 'Activate' ?>
                                    </a>

                                    <button
                                        type="button"
                                        class="btn promotion-delete-btn"
                                        data-bs-toggle="modal"
                                        data-bs-target="#promotionDeleteModal"
                                        data-delete-url="settings.php?tab=promotions&amp;delete_promotion=1&amp;id=<?= $promotionId ?>"
                                        data-promotion-id="<?= $promotionId ?>"
                                        data-promotion-title="<?= htmlspecialchars($promotion['title'], ENT_QUOTES) ?>"
                                    >
                                        <i class="bi bi-trash3 me-1"></i>Delete
                                    </button>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

<?php else: ?>

        <!-- =====================================================
             USER ACCOUNT SETTINGS
        ====================================================== -->

        <div class="panel-card user-account-management-panel">

            <div class="promotion-management-heading user-account-management-heading">
                <div>
                    <div class="panel-title">
                        User Account Settings
                    </div>

                    <div class="promotion-management-subtitle">
                        View and manage customer, staff, and administrator accounts. New accounts can only be added as Staff.
                    </div>
                </div>

                <button
                    type="button"
                    class="btn btn-primary user-account-add-btn"
                    data-bs-toggle="modal"
                    data-bs-target="#userAccountAddModal"
                >
                    <i class="bi bi-person-plus-fill me-1"></i>
                    Add Account
                </button>
            </div>

            <?php
            renderUserAccountFilterFragment(
                $userAccountCounts,
                $userAccountFilter,
                $userAccountSearch,
                $userAccountStatus,
                $userAccountArchivedCount,
                $userAccountPendingCount,
                $filteredUserAccounts,
                $loggedInAdminId
            );
            ?>

        </div>

        <!-- =====================================================
             USER ACCOUNT ADD MODAL
        ====================================================== -->
        <div
            class="modal fade"
            id="userAccountAddModal"
            tabindex="-1"
            aria-hidden="true"
        >
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">

                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="bi bi-person-plus-fill me-2"></i>
                            Add Account
                        </h5>

                        <button
                            type="button"
                            class="btn-close"
                            data-bs-dismiss="modal"
                            aria-label="Close"
                        ></button>
                    </div>

                    <form method="POST" id="userAccountAddForm">

                        <div class="modal-body">
                            <div class="row g-3">

                                <div class="col-md-6">
                                    <label class="form-label" for="userAccountAddName">Name</label>
                                    <input
                                        type="text"
                                        class="form-control"
                                        name="new_account_name"
                                        id="userAccountAddName"
                                        maxlength="100"
                                        required
                                    >
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label" for="userAccountAddEmail">Email</label>
                                    <input
                                        type="email"
                                        class="form-control"
                                        name="new_account_email"
                                        id="userAccountAddEmail"
                                        maxlength="150"
                                        required
                                    >
                                </div>

                                <div class="col-md-6" id="userAccountAddPhoneWrap">
                                    <label class="form-label" for="userAccountAddPhone">Phone</label>
                                    <input
                                        type="text"
                                        class="form-control"
                                        name="new_account_phone"
                                        id="userAccountAddPhone"
                                        maxlength="20"
                                    >
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label" for="userAccountAddRole">Role</label>
                                    <select
                                        class="form-select"
                                        name="new_account_role"
                                        id="userAccountAddRole"
                                        required
                                    >
                                        <option value="staff" selected>Staff</option>
                                        
                                    </select>
                                </div>


                            </div>

                            <div class="price-note mt-3">
                                The staff member will receive a verification email at this address. They must verify the email and create their own password before they can log in.
                            </div>
                        </div>

                        <div class="modal-footer">
                            <button
                                type="button"
                                class="btn btn-outline-secondary"
                                data-bs-dismiss="modal"
                            >
                                Cancel
                            </button>

                            <button
                                type="submit"
                                name="add_user_account"
                                class="btn btn-primary"
                            >
                                <i class="bi bi-person-plus-fill me-1"></i>
                                Create Account
                            </button>
                        </div>

                    </form>
                </div>
            </div>
        </div>

        <!-- =====================================================
             USER ACCOUNT EDIT MODAL
        ====================================================== -->
        <div
            class="modal fade"
            id="userAccountEditModal"
            tabindex="-1"
            aria-hidden="true"
        >
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">

                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="bi bi-person-gear me-2"></i>
                            Edit User Account
                        </h5>

                        <button
                            type="button"
                            class="btn-close"
                            data-bs-dismiss="modal"
                        ></button>
                    </div>

                    <form method="POST">
                        <input
                            type="hidden"
                            name="account_source"
                            id="userAccountEditSource"
                        >

                        <input
                            type="hidden"
                            name="account_id"
                            id="userAccountEditId"
                        >

                        <div class="modal-body">

                            <div class="row g-3">

                                <div class="col-md-6">
                                    <label
                                        class="form-label"
                                        for="userAccountEditName"
                                    >
                                        Name
                                    </label>

                                    <input
                                        type="text"
                                        class="form-control"
                                        name="account_name"
                                        id="userAccountEditName"
                                        maxlength="100"
                                        required
                                    >
                                </div>

                                <div class="col-md-6">
                                    <label
                                        class="form-label"
                                        for="userAccountEditEmail"
                                    >
                                        Email
                                    </label>

                                    <input
                                        type="email"
                                        class="form-control"
                                        name="account_email"
                                        id="userAccountEditEmail"
                                        maxlength="150"
                                        required
                                    >
                                </div>

                                <div
                                    class="col-md-6"
                                    id="userAccountEditPhoneWrap"
                                >
                                    <label
                                        class="form-label"
                                        for="userAccountEditPhone"
                                    >
                                        Phone
                                    </label>

                                    <input
                                        type="text"
                                        class="form-control"
                                        name="account_phone"
                                        id="userAccountEditPhone"
                                        maxlength="20"
                                    >
                                </div>

                                <div class="col-md-6">
                                    <label
                                        class="form-label"
                                        for="userAccountEditRole"
                                    >
                                        Role
                                    </label>

                                    <select
                                        class="form-select"
                                        name="account_role"
                                        id="userAccountEditRole"
                                        required
                                    ></select>
                                </div>

                                <div class="col-12">
                                    <label
                                        class="form-label"
                                        for="userAccountEditPassword"
                                    >
                                        New Password
                                    </label>

                                    <input
                                        type="password"
                                        class="form-control"
                                        name="account_password"
                                        id="userAccountEditPassword"
                                        minlength="8"
                                        autocomplete="new-password"
                                        placeholder="Leave blank to keep the current password"
                                    >

                                    <div class="price-note mt-1">
                                        Leave this blank when the password does not need to be changed.
                                    </div>
                                </div>

                            </div>

                        </div>

                        <div class="modal-footer">

                            <button
                                type="button"
                                class="btn btn-outline-secondary"
                                data-bs-dismiss="modal"
                            >
                                Cancel
                            </button>

                            <button
                                type="submit"
                                name="update_user_account"
                                class="btn btn-primary"
                            >
                                <i class="bi bi-check2 me-1"></i>
                                Save Changes
                            </button>

                        </div>

                    </form>

                </div>
            </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function () {

            const modalElement =
                document.getElementById('userAccountEditModal');

            const addModalElement =
                document.getElementById('userAccountAddModal');

            if (!modalElement) {
                return;
            }

            if (addModalElement && addModalElement.parentElement !== document.body) {
                document.body.appendChild(addModalElement);
            }

            /*
             * Bootstrap modals are safest when mounted directly under <body>.
             * The settings page contains sticky/fixed navigation and other
             * stacking contexts; keeping this modal inside those containers
             * can allow an invisible layer to sit above the form controls.
             */
            if (modalElement.parentElement !== document.body) {
                document.body.appendChild(modalElement);
            }

            modalElement.addEventListener('shown.bs.modal', function () {
                document.querySelectorAll('.modal-backdrop').forEach(function (backdrop) {
                    backdrop.classList.remove('user-account-modal-backdrop');
                });

                const backdrops = document.querySelectorAll('.modal-backdrop');
                const currentBackdrop = backdrops[backdrops.length - 1];
                if (currentBackdrop) {
                    currentBackdrop.classList.add('user-account-modal-backdrop');
                }
            });

            modalElement.addEventListener('hidden.bs.modal', function () {
                document.querySelectorAll('.modal-backdrop.user-account-modal-backdrop')
                    .forEach(function (backdrop) {
                        backdrop.classList.remove('user-account-modal-backdrop');
                    });
            });

            const sourceInput =
                document.getElementById('userAccountEditSource');

            const idInput =
                document.getElementById('userAccountEditId');

            const nameInput =
                document.getElementById('userAccountEditName');

            const emailInput =
                document.getElementById('userAccountEditEmail');

            const phoneInput =
                document.getElementById('userAccountEditPhone');

            const phoneWrap =
                document.getElementById('userAccountEditPhoneWrap');

            const roleSelect =
                document.getElementById('userAccountEditRole');

            const passwordInput =
                document.getElementById('userAccountEditPassword');
const addModalForm =
                document.getElementById('userAccountAddForm');
            if (addModalElement) {
                addModalElement.addEventListener('shown.bs.modal', function () {
                    document.querySelectorAll('.modal-backdrop').forEach(function (backdrop) {
                        backdrop.classList.remove('user-account-modal-backdrop');
                    });

                    const backdrops = document.querySelectorAll('.modal-backdrop');
                    const currentBackdrop = backdrops[backdrops.length - 1];
                    if (currentBackdrop) {
                        currentBackdrop.classList.add('user-account-modal-backdrop');
                    }

                    const addName = document.getElementById('userAccountAddName');
                    if (addName) {
                        addName.focus();
                    }
                });

                addModalElement.addEventListener('hidden.bs.modal', function () {
                    document.querySelectorAll('.modal-backdrop.user-account-modal-backdrop')
                        .forEach(function (backdrop) {
                            backdrop.classList.remove('user-account-modal-backdrop');
                        });
                });
            }

            function populateAndShowEditModal(button) {

                const source =
                    button.dataset.source || 'users';

                sourceInput.value = source;
                idInput.value = button.dataset.id || '';
                nameInput.value = button.dataset.name || '';
                emailInput.value = button.dataset.email || '';
                phoneInput.value = button.dataset.phone || '';
                passwordInput.value = '';

                roleSelect.innerHTML = '';
                roleSelect.disabled = false;

                if (source === 'customers') {

                    phoneWrap.classList.remove('d-none');
                    roleSelect.add(new Option('Customer', 'customer'));

                } else if (source === 'admins') {

                    phoneWrap.classList.add('d-none');
                    roleSelect.add(new Option('Admin', 'admin'));

                } else {

                    phoneWrap.classList.remove('d-none');
                    roleSelect.add(new Option('Staff', 'staff'));
                    roleSelect.add(new Option('Admin', 'admin'));
                }

                roleSelect.value =
                    button.dataset.role || roleSelect.options[0]?.value || '';

                if (window.bootstrap && bootstrap.Modal) {
                    bootstrap.Modal.getOrCreateInstance(modalElement, {
                        backdrop: true,
                        keyboard: true,
                        focus: true
                    }).show();
                }
            }

            /* Delegated handler keeps Edit working after AJAX replaces the account list. */
            document.addEventListener('click', function (event) {
                const button = event.target.closest('.user-account-edit-btn');

                if (!button || button.disabled) {
                    return;
                }

                populateAndShowEditModal(button);
            });
        });
        </script>

        <script>
        document.addEventListener('DOMContentLoaded', function () {

            const managementPanel =
                document.querySelector('.user-account-management-panel');

            if (!managementPanel) {
                return;
            }

            let activeRequest = null;

            async function loadUserAccountFilter(targetUrl, pushHistory) {
                const currentArea = document.getElementById('userAccountAjaxArea');

                if (!currentArea) {
                    window.location.href = targetUrl.toString();
                    return;
                }

                if (activeRequest) {
                    activeRequest.abort();
                }

                const ajaxUrl = new URL(targetUrl.toString(), window.location.href);
                ajaxUrl.searchParams.set('ajax', 'user_accounts');
                ajaxUrl.searchParams.set('tab', 'user_accounts');

                activeRequest = new AbortController();
                currentArea.classList.add('user-account-ajax-loading');
                currentArea.setAttribute('aria-busy', 'true');

                try {
                    const response = await fetch(ajaxUrl.toString(), {
                        method: 'GET',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'text/html'
                        },
                        cache: 'no-store',
                        signal: activeRequest.signal
                    });

                    if (!response.ok) {
                        throw new Error('Unable to load user accounts.');
                    }

                    const html = await response.text();
                    const parsed = new DOMParser().parseFromString(html, 'text/html');
                    const nextArea = parsed.querySelector('#userAccountAjaxArea');

                    if (!nextArea) {
                        throw new Error('User account content was not returned.');
                    }

                    currentArea.replaceWith(nextArea);

                    if (pushHistory) {
                        window.history.pushState(
                            { userAccountAjax: true },
                            '',
                            targetUrl.toString()
                        );
                    }
                } catch (error) {
                    if (error && error.name === 'AbortError') {
                        return;
                    }

                    console.error('User account AJAX error:', error);
                    window.location.href = targetUrl.toString();
                } finally {
                    activeRequest = null;
                }
            }

            /* Summary cards: All / Customers / Staff / Admin Accounts */
            document.addEventListener('click', function (event) {
                const card = event.target.closest('.user-account-summary-card');

                if (!card || !managementPanel.contains(card)) {
                    return;
                }

                if (
                    event.button !== 0 ||
                    event.ctrlKey ||
                    event.metaKey ||
                    event.shiftKey ||
                    event.altKey
                ) {
                    return;
                }

                event.preventDefault();

                const targetUrl = new URL(card.href, window.location.href);
                loadUserAccountFilter(targetUrl, true);
            });

            /* Browser Back / Forward also loads the account list through AJAX. */
            window.addEventListener('popstate', function () {
                const currentUrl = new URL(window.location.href);

                if (
                    currentUrl.searchParams.get('tab') === 'user_accounts' &&
                    document.getElementById('userAccountAjaxArea')
                ) {
                    loadUserAccountFilter(currentUrl, false);
                }
            });
        });
        </script>

<?php endif; ?>

    </main>

</div>


<!-- =========================================================
     PROMOTION DELETE CONFIRMATION MODAL
========================================================= -->
<div class="modal fade promotion-delete-modal" id="promotionDeleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body">
                <div class="promotion-delete-icon" aria-hidden="true">
                    <i class="bi bi-trash3"></i>
                </div>

                <h5 class="promotion-delete-title">
                    Are you sure you want to delete this?
                </h5>

                <p class="promotion-delete-message">
                    This promotion will be removed from the Promotions list. This action cannot be undone.
                    <span class="promotion-delete-name" id="promotionDeleteName"></span>
                </p>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn promotion-delete-cancel" data-bs-dismiss="modal">
                    Cancel
                </button>

                <button type="button" class="btn promotion-delete-confirm" id="confirmPromotionDeleteBtn">
                    <i class="bi bi-trash3 me-1"></i>Delete
                </button>
            </div>
        </div>
    </div>
</div>


<!-- =========================================================
     PROMOTION MODAL
========================================================= -->
<div class="modal fade" id="promotionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="promotionModalTitle">
                    <i class="bi bi-megaphone me-2"></i>
                    Create Promotion
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <form method="POST" enctype="multipart/form-data" id="promotionForm">
                <input type="hidden" name="ajax_promotion_action" id="promotionAjaxAction" value="">
                <input type="hidden" name="promotion_id" id="promotionId" value="">
                <input type="hidden" name="return_category" value="<?= htmlspecialchars($selectedCategory) ?>">

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label">Promotion Title</label>
                            <input type="text" class="form-control" name="promotion_title" id="promotionTitle" maxlength="150" placeholder="e.g. Taro Buy 1 Take 1" required>
                        </div>

                        <div class="col-md-5">
                            <label class="form-label">Promotion Type</label>
                            <select class="form-select" name="promotion_type" id="promotionType" required>
                                <option value="">Select promotion type</option>
                                <?php foreach ($promotionRuleTypes as $typeKey => $typeLabel): ?>
                                    <option value="<?= htmlspecialchars($typeKey) ?>"><?= htmlspecialchars($typeLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Start Date</label>
                            <input type="date" class="form-control" name="promotion_start_date" id="promotionStartDate" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">End Date</label>
                            <input type="date" class="form-control" name="promotion_end_date" id="promotionEndDate" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="promotion_description" id="promotionDescription" rows="3" maxlength="1000" placeholder="Short description shown to customers."></textarea>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Promotion Image</label>
                            <input type="file" class="form-control" name="promotion_image" accept=".jpg,.jpeg,.png,.webp">
                            <div class="price-note mt-1">JPG, JPEG, PNG, or WEBP. Recommended: landscape image.</div>
                            <div class="mt-2 d-none" id="promotionCurrentImageWrap">
                                <img class="promotion-current-image" id="promotionCurrentImage" alt="Current promotion image">
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="promotion-rule-panel">
                                <div class="promotion-rule-panel-title">Promotion Rule</div>

                                <div id="ruleBogo" class="promotion-rule-grid d-none">
                                    <div>
                                        <label class="form-label">Product Category</label>
                                        <select class="form-select promo-category-select" id="bogoCategory" data-target="bogoProductId">
                                            <option value="">Select category</option>
                                            <?php foreach ($promotionProductCategories as $categoryName): ?>
                                                <option value="<?= htmlspecialchars($categoryName, ENT_QUOTES) ?>"><?= htmlspecialchars($categoryName) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="form-label">Product</label>
                                        <select class="form-select" name="bogo_product_id" id="bogoProductId" disabled>
                                            <option value="">Select category first</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="form-label" for="bogoSize">Size</label>
                                        <select class="form-select promotion-size-select" name="bogo_size" id="bogoSize" disabled>
                                            <option value="">Select product first</option>
                                        </select>
                                    </div>
                                    <div class="col-12" style="grid-column:1 / -1;">
                                        <div class="price-note mt-1">Buy 1 Take 1 always uses the same product and the same size for the paid and free cup. For different Buy/Get sizes, use Buy X Get Y Free.</div>
                                    </div>
                                </div>

                                <div id="ruleBuyGet" class="promotion-rule-grid d-none buy-get-rule-layout">
                                    <div class="buy-get-section buy-section">
                                        <div class="buy-get-section-header">
                                            <div>
                                                <div class="buy-get-section-label">BUY</div>
                                                <div class="buy-get-section-title">What the customer needs to buy</div>
                                            </div>
                                            <span class="buy-get-step-badge">Step 1</span>
                                        </div>

                                        <div class="buy-get-fields">
                                            <div>
                                                <label class="form-label">Product Category</label>
                                                <select class="form-select promo-category-select" id="buyCategory" data-target="buyProductId">
                                                    <option value="">Select category</option>
                                                    <?php foreach ($promotionProductCategories as $categoryName): ?>
                                                        <option value="<?= htmlspecialchars($categoryName, ENT_QUOTES) ?>"><?= htmlspecialchars($categoryName) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="form-label">Product</label>
                                                <select class="form-select" name="buy_product_id" id="buyProductId" disabled>
                                                    <option value="">Select category first</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="form-label" for="buySize">Size</label>
                                                <select class="form-select promotion-size-select" name="buy_size" id="buySize" disabled>
                                                    <option value="">Select product first</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="form-label">Quantity</label>
                                                <input type="number" class="form-control" name="buy_quantity" id="buyQuantity" min="1" step="1" value="1">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="buy-get-section get-section">
                                        <div class="buy-get-section-header">
                                            <div>
                                                <div class="buy-get-section-label">GET <span class="promotion-free-label">FREE</span></div>
                                                <div class="buy-get-section-title">What the customer receives for free</div>
                                            </div>
                                            <span class="buy-get-step-badge">Step 2</span>
                                        </div>

                                        <div class="buy-get-fields">
                                            <div>
                                                <label class="form-label">Product Category</label>
                                                <select class="form-select promo-category-select" id="getCategory" data-target="getProductId">
                                                    <option value="">Select category</option>
                                                    <?php foreach ($promotionProductCategories as $categoryName): ?>
                                                        <option value="<?= htmlspecialchars($categoryName, ENT_QUOTES) ?>"><?= htmlspecialchars($categoryName) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="form-label">Product</label>
                                                <select class="form-select" name="get_product_id" id="getProductId" disabled>
                                                    <option value="">Select category first</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="form-label" for="getSize">Size</label>
                                                <select class="form-select promotion-size-select" name="get_size" id="getSize" disabled>
                                                    <option value="">Select product first</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="form-label">Quantity</label>
                                                <input type="number" class="form-control" name="get_quantity" id="getQuantity" min="1" step="1" value="1">
                                            </div>
                                        </div>

                                        <div class="price-note mt-2">The Get product and Get size are free. The customer only customizes and orders the Buy side.</div>
                                    </div>
                                </div>

                                <div id="ruleBundle" class="promotion-rule-grid d-none">
                                    <div class="col-12" style="grid-column:1 / -1;">
                                        <label class="form-label" for="bundleProductType">Bundle Products</label>

                                        <select class="form-select" id="bundleProductType">
                                            <option value="">Select product type</option>
                                            <?php foreach ($promotionProductCategories as $categoryName): ?>
                                                <option value="<?= htmlspecialchars($categoryName, ENT_QUOTES) ?>">
                                                    <?= htmlspecialchars($categoryName) ?>
                                                </option>
                                            <?php endforeach; ?>
                                            <option value="__all__">All Product Types</option>
                                        </select>

                                        <div class="price-note mt-1" id="bundleProductFilterHint">
                                            Select a product type to display its products.
                                        </div>

                                        <div class="bundle-selected-summary d-none" id="bundleSelectedSummary">
                                            <div class="bundle-selected-summary-title">Selected Products</div>
                                            <div class="bundle-selected-summary-list" id="bundleSelectedSummaryList"></div>
                                        </div>

                                        <div class="bundle-products-list d-none" id="bundleProductsList">
                                            <?php foreach ($promotionProducts as $product): ?>
                                                <div
                                                    class="bundle-product-option"
                                                    data-category="<?= htmlspecialchars((string)($product['category_name'] ?? ''), ENT_QUOTES) ?>"
                                                    data-product-name="<?= htmlspecialchars((string)$product['name'], ENT_QUOTES) ?>"
                                                >
                                                    <label class="bundle-product-check-label">
                                                        <input type="checkbox" name="bundle_product_ids[]" value="<?= (int)$product['id'] ?>" class="bundle-product-checkbox">
                                                        <span><?= htmlspecialchars($product['name']) ?></span>
                                                    </label>
                                                    <select
                                                        class="form-select bundle-product-size"
                                                        name="bundle_product_sizes[<?= (int)$product['id'] ?>]"
                                                        data-product-id="<?= (int)$product['id'] ?>"
                                                        data-has-regular="<?= ((float)($product['regular_price'] ?? 0) > 0) ? '1' : '0' ?>"
                                                        data-has-grande="<?= ((float)($product['grande_price'] ?? 0) > 0) ? '1' : '0' ?>"
                                                        disabled
                                                    >
                                                        <option value="">Select size</option>
                                                    </select>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>

                                        <div class="price-note mt-1">Select at least 2 products.</div>
                                    </div>
                                    <div>
                                        <label class="form-label">Bundle Price</label>
                                        <div class="input-group">
                                            <span class="input-group-text">₱</span>
                                            <input type="number" class="form-control" name="bundle_price" id="bundlePrice" min="0.01" step="0.01" placeholder="119.00">
                                        </div>
                                    </div>
                                </div>

                                <div id="ruleDiscount" class="promotion-rule-grid d-none">
                                    <div>
                                        <label class="form-label">Product Category</label>
                                        <select class="form-select promo-category-select" id="discountCategory" data-target="discountProductId">
                                            <option value="">Select category</option>
                                            <?php foreach ($promotionProductCategories as $categoryName): ?>
                                                <option value="<?= htmlspecialchars($categoryName, ENT_QUOTES) ?>"><?= htmlspecialchars($categoryName) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="form-label">Product</label>
                                        <select class="form-select" name="discount_product_id" id="discountProductId" disabled>
                                            <option value="">Select category first</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="form-label" for="discountSize">Size</label>
                                        <select class="form-select promotion-size-select" name="discount_size" id="discountSize" disabled>
                                            <option value="">Select product first</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="form-label" id="discountValueLabel">Discount</label>
                                        <div class="input-group">
                                            <span class="input-group-text" id="discountValuePrefix">%</span>
                                            <input type="number" class="form-control" name="discount_value" id="discountValue" min="0.01" step="0.01" placeholder="10">
                                        </div>
                                    </div>
                                    <div></div>
                                </div>

                                <div id="promotionRuleHint" class="price-note mt-2">
                                    Select a promotion type to configure the rule.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="save_promotion" class="btn btn-brown" id="savePromotionBtn">
                        <i class="bi bi-check-lg me-1"></i>Save Promotion
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- =========================================================
     MANAGE ADD-ONS MODAL
========================================================= -->

<div
    class="modal fade"
    id="manageAddonsModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog modal-lg modal-dialog-centered">

        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-patch-plus me-2"></i>
                    Add-ons Management
                </h5>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>
            </div>

            <div class="modal-body">

                <div class="d-flex justify-content-between align-items-center mb-3 gap-2">
                    <div>
                        <div class="panel-title">Available Add-ons</div>
                        <div class="text-muted small">
                            Manage add-on names, prices, and availability.
                        </div>
                    </div>

                    <button
                        type="button"
                        class="btn btn-brown btn-sm"
                        data-bs-toggle="modal"
                        data-bs-target="#addAddonModal"
                    >
                        <i class="bi bi-plus-lg me-1"></i>
                        Add Add-on
                    </button>
                </div>

                <?php if (empty($addons)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-patch-plus" style="font-size:36px;color:#CDBDA9;"></i>
                        <div class="mt-2">No add-ons available.</div>
                    </div>
                <?php else: ?>

                    <div class="addon-modal-scroll">
                        <table class="addon-management-table">
                            <thead>
                                <tr>
                                    <th>Add-on</th>
                                    <th>Price</th>
                                    <th>Status</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>

                                <?php foreach ($addons as $addon): ?>
                                    <tr>
                                        <td>
                                            <div class="addon-name">
                                                <?= htmlspecialchars($addon['name']) ?>
                                            </div>

                                            <?php if (!empty($addon['description'])): ?>
                                                <div class="addon-description">
                                                    <?= htmlspecialchars($addon['description']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <td class="addon-price">
                                            ₱<?= number_format((float)$addon['price'], 2) ?>
                                        </td>

                                        <td>
                                            <?php if ((int)$addon['is_available'] === 1): ?>
                                                <span class="addon-available">Available</span>
                                            <?php else: ?>
                                                <span class="addon-unavailable">Out of Stock</span>
                                            <?php endif; ?>
                                        </td>

                                        <td class="text-end">
                                            <div class="d-inline-flex gap-1">
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-outline-secondary edit-addon-btn"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#editAddonModal"
                                                    data-addon-id="<?= (int)$addon['id'] ?>"
                                                    data-addon-name="<?= htmlspecialchars($addon['name'], ENT_QUOTES) ?>"
                                                    data-addon-description="<?= htmlspecialchars($addon['description'] ?? '', ENT_QUOTES) ?>"
                                                    data-addon-price="<?= htmlspecialchars($addon['price'], ENT_QUOTES) ?>"
                                                >
                                                    <i class="bi bi-pencil"></i>
                                                </button>

                                                <a
                                                    href="settings.php?toggle_addon=1&id=<?= (int)$addon['id'] ?>&category=<?= urlencode($selectedCategory) ?>"
                                                    class="btn btn-sm btn-outline-secondary"
                                                    title="Toggle availability"
                                                >
                                                    <i class="bi bi-arrow-repeat"></i>
                                                </a>

                                                <a
                                                    href="settings.php?archive_addon=1&id=<?= (int)$addon['id'] ?>&category=<?= urlencode($selectedCategory) ?>"
                                                    class="btn btn-sm btn-outline-danger"
                                                    title="Archive add-on"
                                                    onclick="return confirm('Archive this add-on? It will no longer be available for product customization.');"
                                                >
                                                    <i class="bi bi-archive"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                            </tbody>
                        </table>
                    </div>

                <?php endif; ?>

            </div>

        </div>

    </div>

</div>


<!-- =========================================================
     ADD ADD-ON MODAL
========================================================= -->

<div
    class="modal fade"
    id="addAddonModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-plus-circle me-2"></i>
                    Add Add-on
                </h5>
                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>
            </div>

            <form method="POST">

                <div class="modal-body">

                    <div class="mb-3">
                        <label class="form-label">Add-on Name</label>
                        <input
                            type="text"
                            name="addon_name"
                            class="form-control"
                            placeholder="e.g. Cheese Foam"
                            required
                        >
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Price</label>
                        <div class="input-group">
                            <span class="input-group-text">₱</span>
                            <input
                                type="number"
                                name="addon_price"
                                class="form-control"
                                min="0"
                                step="0.01"
                                placeholder="15.00"
                                required
                            >
                        </div>
                    </div>

                    <div>
                        <label class="form-label">Description</label>
                        <textarea
                            name="addon_description"
                            class="form-control"
                            rows="3"
                            placeholder="Optional"
                        ></textarea>
                    </div>

                </div>

                <div class="modal-footer">
                    <button
                        type="button"
                        class="btn btn-outline-secondary"
                        data-bs-dismiss="modal"
                    >
                        Cancel
                    </button>

                    <button
                        type="submit"
                        name="add_addon"
                        class="btn btn-brown"
                    >
                        <i class="bi bi-check-lg me-1"></i>
                        Save Add-on
                    </button>
                </div>

            </form>

        </div>

    </div>

</div>


<!-- =========================================================
     EDIT ADD-ON MODAL
========================================================= -->

<div
    class="modal fade"
    id="editAddonModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-pencil-square me-2"></i>
                    Edit Add-on
                </h5>
                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>
            </div>

            <form method="POST">

                <input type="hidden" name="addon_id" id="editAddonId">

                <div class="modal-body">

                    <div class="mb-3">
                        <label class="form-label">Add-on Name</label>
                        <input
                            type="text"
                            name="addon_name"
                            id="editAddonName"
                            class="form-control"
                            required
                        >
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Price</label>
                        <div class="input-group">
                            <span class="input-group-text">₱</span>
                            <input
                                type="number"
                                name="addon_price"
                                id="editAddonPrice"
                                class="form-control"
                                min="0"
                                step="0.01"
                                required
                            >
                        </div>
                    </div>

                    <div>
                        <label class="form-label">Description</label>
                        <textarea
                            name="addon_description"
                            id="editAddonDescription"
                            class="form-control"
                            rows="3"
                        ></textarea>
                    </div>

                </div>

                <div class="modal-footer">
                    <button
                        type="button"
                        class="btn btn-outline-secondary"
                        data-bs-dismiss="modal"
                    >
                        Cancel
                    </button>

                    <button
                        type="submit"
                        name="edit_addon"
                        class="btn btn-brown"
                    >
                        <i class="bi bi-check-lg me-1"></i>
                        Save Changes
                    </button>
                </div>

            </form>

        </div>

    </div>

</div>


<!-- =========================================================
     PRODUCT ADD-ONS MODAL
========================================================= -->

<div
    class="modal fade"
    id="productAddonsModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog modal-lg modal-dialog-centered">

        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-link-45deg me-2"></i>
                    Manage Product Add-ons
                </h5>
                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>
            </div>

            <form method="POST">

                <input type="hidden" name="product_id" id="productAddonsProductId">

                <div class="modal-body">

                    <div class="mb-3">
                        <div class="panel-title" id="productAddonsProductName">
                            Product
                        </div>
                        <div class="text-muted small">
                            Select the add-ons customers can choose for this product.
                        </div>
                    </div>

                    <?php if (empty($addons)): ?>

                        <div class="text-center py-4 text-muted">
                            No active add-ons are available. Add an add-on first.
                        </div>

                    <?php else: ?>

                        <div class="addon-checkbox-list" id="productAddonsCheckboxList">

                            <?php foreach ($addons as $addon): ?>
                                <div class="addon-checkbox-item">
                                    <label class="d-flex align-items-start gap-2">
                                        <input
                                            type="checkbox"
                                            class="form-check-input product-addon-checkbox mt-1"
                                            name="addon_ids[]"
                                            value="<?= (int)$addon['id'] ?>"
                                            data-addon-id="<?= (int)$addon['id'] ?>"
                                        >
                                        <span>
                                            <span class="addon-select-name d-block">
                                                <?= htmlspecialchars($addon['name']) ?>
                                            </span>
                                            <span class="addon-select-price d-block">
                                                +₱<?= number_format((float)$addon['price'], 2) ?>
                                                <?php if (!(int)$addon['is_available']): ?>
                                                    • Currently Out of Stock
                                                <?php endif; ?>
                                            </span>
                                        </span>
                                    </label>
                                </div>
                            <?php endforeach; ?>

                        </div>

                    <?php endif; ?>

                </div>

                <div class="modal-footer">
                    <button
                        type="button"
                        class="btn btn-outline-secondary"
                        data-bs-dismiss="modal"
                    >
                        Cancel
                    </button>

                    <button
                        type="submit"
                        name="save_product_addons"
                        class="btn btn-brown"
                    >
                        <i class="bi bi-check-lg me-1"></i>
                        Save Add-ons
                    </button>
                </div>

            </form>

        </div>

    </div>

</div>


<!-- =========================================================
     EDIT PRODUCT MODAL
========================================================= -->

<div
    class="modal fade"
    id="editProductModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog modal-lg modal-dialog-centered">

        <div class="modal-content">

            <div class="modal-header">

                <h5 class="modal-title">

                    <i class="bi bi-pencil-square me-2"></i>

                    Edit Product

                </h5>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>

            </div>


            <form
                id="editProductForm"
                method="POST"
                enctype="multipart/form-data"
            >

                <input
                    type="hidden"
                    name="product_id"
                    id="editProductId"
                >

                <input
                    type="hidden"
                    name="return_category"
                    value="<?= htmlspecialchars($selectedCategory) ?>"
                >


                <div class="modal-body">

                    <div class="row g-3">


                        <!-- PRODUCT TYPE -->

                        <div class="col-md-6">

                            <label class="form-label">
                                Product Type
                            </label>

                            <select
                                name="product_type"
                                id="editProductType"
                                class="form-select"
                                required
                            >

                                <option
                                    value=""
                                    disabled
                                >
                                    Select product type
                                </option>

                                <?php foreach ($productTypes as $type): ?>

                                    <option
                                        value="<?= htmlspecialchars($type) ?>"
                                    >
                                        <?= htmlspecialchars($type) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>


                        <!-- PRODUCT NAME -->

                        <div class="col-md-6">

                            <label class="form-label">
                                Product Name
                            </label>

                            <input
                                type="text"
                                name="product_name"
                                id="editProductName"
                                class="form-control"
                                required
                            >

                        </div>


                        <!-- AVAILABLE SIZES + PRICING -->

                        <div class="col-12">

                            <div class="form-label mb-2">
                                Available Sizes
                            </div>

                            <div class="size-option-grid">

                                <label class="size-option-card" for="editHasRegular">
                                    <div class="size-option-top">
                                        <div>
                                            <div class="size-option-name">Regular</div>
                                            <div class="size-option-note">Enable when this product is sold in Regular size.</div>
                                        </div>
                                        <input
                                            type="checkbox"
                                            class="form-check-input size-toggle-checkbox"
                                            name="has_regular"
                                            value="1"
                                            id="editHasRegular"
                                        >
                                    </div>

                                    <div class="input-group mt-3">
                                        <span class="input-group-text">₱</span>
                                        <input
                                            type="number"
                                            name="regular_price"
                                            id="editRegularPrice"
                                            class="form-control size-price-input"
                                            min="0"
                                            step="0.01"
                                            placeholder="95.00"
                                        >
                                    </div>
                                </label>

                                <label class="size-option-card" for="editHasGrande">
                                    <div class="size-option-top">
                                        <div>
                                            <div class="size-option-name">Grande</div>
                                            <div class="size-option-note">Enable when this product is sold in Grande size.</div>
                                        </div>
                                        <input
                                            type="checkbox"
                                            class="form-check-input size-toggle-checkbox"
                                            name="has_grande"
                                            value="1"
                                            id="editHasGrande"
                                        >
                                    </div>

                                    <div class="input-group mt-3">
                                        <span class="input-group-text">₱</span>
                                        <input
                                            type="number"
                                            name="grande_price"
                                            id="editGrandePrice"
                                            class="form-control size-price-input"
                                            min="0"
                                            step="0.01"
                                            placeholder="110.00"
                                        >
                                    </div>
                                </label>

                            </div>

                            <div class="price-note mt-2">
                                At least one size must be enabled. When both sizes are enabled, the Regular price must be lower than the Grande price.
                            </div>

                            <div class="size-price-error" id="editSizePriceError" role="alert"></div>

                        </div>


                        <!-- CURRENT IMAGE -->

                        <div class="col-md-4">

                            <label class="form-label">
                                Current Image
                            </label>

                            <div>

                                <img
                                    src="../assets/uploads/products/default-product.png"
                                    id="editCurrentImage"
                                    class="current-product-image"
                                    alt="Current product image"
                                    onerror="
                                        this.onerror=null;
                                        this.src='data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxMjAiIGhlaWdodD0iMTIwIiB2aWV3Qm94PSIwIDAgMTIwIDEyMCI+PHJlY3Qgd2lkdGg9IjEyMCIgaGVpZ2h0PSIxMjAiIHJ4PSIxOCIgZmlsbD0iI0YzRUNFNCIvPjxwYXRoIGQ9Ik00MSAzOGgzOHYyOGMwIDEzLTggMjItMTkgMjJzLTE5LTktMTktMjJWMzhaIiBmaWxsPSJub25lIiBzdHJva2U9IiM2RjRFMzciIHN0cm9rZS13aWR0aD0iNCIvPjxwYXRoIGQ9Ik03OSA0N2g0YTEwIDEwIDAgMCAxIDAgMjBoLTQiIGZpbGw9Im5vbmUiIHN0cm9rZT0iIzZGNEUzNyIgc3Ryb2tlLXdpZHRoPSI0Ii8+PHBhdGggZD0iTTQ5IDI5djlNNjAgMjR2MTRNNzEgMjl2OSIgc3Ryb2tlPSIjNkY0RTM3IiBzdHJva2Utd2lkdGg9IjQiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIvPjwvc3ZnPg==';
                                    "
                                >

                            </div>

                        </div>


                        <!-- NEW IMAGE -->

                        <div class="col-md-8">

                            <label class="form-label">
                                Product Image
                            </label>

                            <input
                                type="file"
                                name="product_image"
                                id="editProductImage"
                                class="form-control"
                                accept=".jpg,.jpeg,.png,.webp"
                            >

                            <div class="price-note mt-1">

                                Leave empty to keep the current image.

                            </div>

                        </div>

                    </div>

                </div>


                <div class="modal-footer">

                    <button
                        type="button"
                        class="btn btn-outline-secondary"
                        data-bs-dismiss="modal"
                    >
                        Cancel
                    </button>

                    <button
                        type="submit"
                        name="edit_product"
                        class="btn btn-brown"
                    >

                        <i class="bi bi-check-lg me-1"></i>

                        Save Changes

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>


<!-- =========================================================
     ADD PRODUCT MODAL
========================================================= -->

<div
    class="modal fade"
    id="addProductModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog modal-lg modal-dialog-centered">

        <div class="modal-content">

            <div class="modal-header">

                <h5 class="modal-title">

                    <i class="bi bi-plus-circle me-2"></i>

                    Add Product

                </h5>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>

            </div>


            <form
                id="addProductForm"
                method="POST"
                enctype="multipart/form-data"
            >

                <input
                    type="hidden"
                    name="return_category"
                    value="<?= htmlspecialchars($selectedCategory) ?>"
                >


                <div class="modal-body">

                    <div class="row g-3">


                        <!-- PRODUCT TYPE -->

                        <div class="col-md-6">

                            <label class="form-label">
                                Product Type
                            </label>

                            <select
                                name="product_type"
                                class="form-select"
                                required
                            >

                                <option
                                    value=""
                                    selected
                                    disabled
                                >
                                    Select product type
                                </option>

                                <?php foreach ($productTypes as $type): ?>

                                    <option
                                        value="<?= htmlspecialchars($type) ?>"
                                    >
                                        <?= htmlspecialchars($type) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>


                        <!-- PRODUCT NAME -->

                        <div class="col-md-6">

                            <label class="form-label">
                                Product Name
                            </label>

                            <input
                                type="text"
                                name="product_name"
                                class="form-control"
                                placeholder="e.g. Brown Sugar Milk Tea"
                                required
                            >

                        </div>


                        <!-- AVAILABLE SIZES + PRICING -->

                        <div class="col-12">

                            <div class="form-label mb-2">
                                Available Sizes
                            </div>

                            <div class="size-option-grid">

                                <label class="size-option-card" for="addHasRegular">
                                    <div class="size-option-top">
                                        <div>
                                            <div class="size-option-name">Regular</div>
                                            <div class="size-option-note">Enable when this product is sold in Regular size.</div>
                                        </div>
                                        <input
                                            type="checkbox"
                                            class="form-check-input size-toggle-checkbox"
                                            name="has_regular"
                                            value="1"
                                            id="addHasRegular"
                                            checked
                                        >
                                    </div>

                                    <div class="input-group mt-3">
                                        <span class="input-group-text">₱</span>
                                        <input
                                            type="number"
                                            name="regular_price"
                                            id="addRegularPrice"
                                            class="form-control size-price-input"
                                            placeholder="95.00"
                                            min="0"
                                            step="0.01"
                                            required
                                        >
                                    </div>
                                </label>

                                <label class="size-option-card" for="addHasGrande">
                                    <div class="size-option-top">
                                        <div>
                                            <div class="size-option-name">Grande</div>
                                            <div class="size-option-note">Enable when this product is sold in Grande size.</div>
                                        </div>
                                        <input
                                            type="checkbox"
                                            class="form-check-input size-toggle-checkbox"
                                            name="has_grande"
                                            value="1"
                                            id="addHasGrande"
                                            checked
                                        >
                                    </div>

                                    <div class="input-group mt-3">
                                        <span class="input-group-text">₱</span>
                                        <input
                                            type="number"
                                            name="grande_price"
                                            id="addGrandePrice"
                                            class="form-control size-price-input"
                                            placeholder="110.00"
                                            min="0"
                                            step="0.01"
                                            required
                                        >
                                    </div>
                                </label>

                            </div>

                            <div class="price-note mt-2">
                                At least one size must be enabled. When both sizes are enabled, the Regular price must be lower than the Grande price.
                            </div>

                            <div class="size-price-error" id="addSizePriceError" role="alert"></div>

                        </div>


                        <!-- IMAGE -->

                        <div class="col-12">

                            <label class="form-label">

                                Product Image

                                <span class="text-muted fw-normal">
                                    (Optional)
                                </span>

                            </label>

                            <input
                                type="file"
                                name="product_image"
                                class="form-control"
                                accept=".jpg,.jpeg,.png,.webp"
                            >

                            <div class="price-note mt-1">
                                JPG, JPEG, PNG, or WEBP
                            </div>

                        </div>

                    </div>

                </div>


                <div class="modal-footer">

                    <button
                        type="button"
                        class="btn btn-outline-secondary"
                        data-bs-dismiss="modal"
                    >
                        Cancel
                    </button>

                    <button
                        type="submit"
                        name="add_product"
                        class="btn btn-brown"
                    >

                        <i class="bi bi-check-lg me-1"></i>

                        Save Product

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>


<!-- =========================================================
     EDIT PRODUCT MODAL SCRIPT
========================================================= -->

<script>

document.addEventListener('DOMContentLoaded', function () {

    /* =========================================================
       EDIT ADD-ON MODAL
    ========================================================= */

    const editAddonModal = document.getElementById('editAddonModal');

    if (editAddonModal) {
        editAddonModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;

            if (!button) {
                return;
            }

            document.getElementById('editAddonId').value =
                button.getAttribute('data-addon-id') || '';

            document.getElementById('editAddonName').value =
                button.getAttribute('data-addon-name') || '';

            document.getElementById('editAddonDescription').value =
                button.getAttribute('data-addon-description') || '';

            document.getElementById('editAddonPrice').value =
                button.getAttribute('data-addon-price') || '';
        });
    }


    /* =========================================================
       PRODUCT ADD-ON ASSIGNMENT MODAL
    ========================================================= */

    const productAddonsModal = document.getElementById('productAddonsModal');

    if (productAddonsModal) {
        productAddonsModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;

            if (!button) {
                return;
            }

            const productId =
                button.getAttribute('data-product-id') || '';

            const productName =
                button.getAttribute('data-product-name') || 'Product';

            const rawAddonIds =
                button.getAttribute('data-addon-ids') || '[]';

            let selectedAddonIds = [];

            try {
                selectedAddonIds = JSON.parse(rawAddonIds);
            } catch (error) {
                selectedAddonIds = [];
            }

            selectedAddonIds = selectedAddonIds.map(String);

            document.getElementById('productAddonsProductId').value =
                productId;

            document.getElementById('productAddonsProductName').textContent =
                productName;

            document.querySelectorAll('.product-addon-checkbox').forEach(function (checkbox) {
                checkbox.checked = selectedAddonIds.includes(
                    String(checkbox.getAttribute('data-addon-id'))
                );
            });
        });
    }

});
</script>

<script>

document.addEventListener('DOMContentLoaded', function () {

    const editModal =
        document.getElementById('editProductModal');

    if (!editModal) {
        return;
    }

    editModal.addEventListener(
        'show.bs.modal',
        function (event) {

            const button =
                event.relatedTarget;

            if (!button) {
                return;
            }

            const productId =
                button.getAttribute('data-id');

            const productName =
                button.getAttribute('data-name');

            const productCategory =
                button.getAttribute('data-category');

            const hasRegular =
                button.getAttribute('data-has-regular') === '1';

            const hasGrande =
                button.getAttribute('data-has-grande') === '1';

            const regularPrice =
                button.getAttribute('data-regular') || '';

            const grandePrice =
                button.getAttribute('data-grande') || '';

            const image =
                button.getAttribute('data-image');


            document.getElementById(
                'editProductId'
            ).value = productId;


            document.getElementById(
                'editProductName'
            ).value = productName;


            document.getElementById(
                'editRegularPrice'
            ).value = regularPrice;


            document.getElementById(
                'editGrandePrice'
            ).value = grandePrice;

            document.getElementById('editHasRegular').checked = hasRegular;
            document.getElementById('editHasGrande').checked = hasGrande;

            const editRegularInput = document.getElementById('editRegularPrice');
            const editGrandeInput = document.getElementById('editGrandePrice');
            editRegularInput.disabled = !hasRegular;
            editGrandeInput.disabled = !hasGrande;
            editRegularInput.required = hasRegular;
            editGrandeInput.required = hasGrande;

            const editSizePriceError = document.getElementById('editSizePriceError');
            if (editSizePriceError) {
                editSizePriceError.textContent = '';
                editSizePriceError.classList.remove('show');
            }


            const productType =
                document.getElementById(
                    'editProductType'
                );

            productType.value =
                productCategory;


            /*
             * If the existing category is not one
             * of the seven main product types,
             * reset the dropdown.
             */
            if (productType.value !== productCategory) {
                productType.value = '';
            }


            document.getElementById(
                'editCurrentImage'
            ).src =
                '../assets/uploads/products/' +
                image;


            /*
             * Clear previously selected file.
             */
            document.getElementById(
                'editProductImage'
            ).value = '';

        }
    );

});

</script>


<script>
/* =========================================================
   SIZE TOGGLE + PRICE VALIDATION
   Allows Regular only, Grande only, or both.
   When both are enabled, Regular must be lower than Grande.
========================================================= */
document.addEventListener('DOMContentLoaded', function () {

    function setupSizePricing(options) {
        const form = document.getElementById(options.formId);
        const regularToggle = document.getElementById(options.regularToggleId);
        const grandeToggle = document.getElementById(options.grandeToggleId);
        const regularInput = document.getElementById(options.regularInputId);
        const grandeInput = document.getElementById(options.grandeInputId);
        const errorBox = document.getElementById(options.errorId);

        if (!form || !regularToggle || !grandeToggle || !regularInput || !grandeInput) {
            return;
        }

        function setError(message) {
            if (!errorBox) return;
            errorBox.textContent = message || '';
            errorBox.classList.toggle('show', Boolean(message));
        }

        function syncSizeInputs() {
            regularInput.disabled = !regularToggle.checked;
            grandeInput.disabled = !grandeToggle.checked;

            regularInput.required = regularToggle.checked;
            grandeInput.required = grandeToggle.checked;

            validateSizes(false);
        }

        function validateSizes(showError) {
            let message = '';

            if (!regularToggle.checked && !grandeToggle.checked) {
                message = 'At least one size must be enabled.';
            } else if (regularToggle.checked && grandeToggle.checked) {
                const regular = parseFloat(regularInput.value);
                const grande = parseFloat(grandeInput.value);

                if (Number.isFinite(regular) && Number.isFinite(grande) && regular >= grande) {
                    message = 'Regular price must be lower than Grande price.';
                }
            }

            if (showError || message) {
                setError(message);
            } else {
                setError('');
            }

            return message === '';
        }

        regularToggle.addEventListener('change', syncSizeInputs);
        grandeToggle.addEventListener('change', syncSizeInputs);
        regularInput.addEventListener('input', function () { validateSizes(true); });
        grandeInput.addEventListener('input', function () { validateSizes(true); });

        form.addEventListener('submit', function (event) {
            if (!regularToggle.checked && !grandeToggle.checked) {
                event.preventDefault();
                validateSizes(true);
                return;
            }

            if (!validateSizes(true)) {
                event.preventDefault();
            }
        });

        syncSizeInputs();
    }

    setupSizePricing({
        formId: 'addProductForm',
        regularToggleId: 'addHasRegular',
        grandeToggleId: 'addHasGrande',
        regularInputId: 'addRegularPrice',
        grandeInputId: 'addGrandePrice',
        errorId: 'addSizePriceError'
    });

    setupSizePricing({
        formId: 'editProductForm',
        regularToggleId: 'editHasRegular',
        grandeToggleId: 'editHasGrande',
        regularInputId: 'editRegularPrice',
        grandeInputId: 'editGrandePrice',
        errorId: 'editSizePriceError'
    });

});
</script>

<script>
/* =========================================================
   PRESERVE PAGE POSITION ONLY AFTER SETTINGS ACTIONS
   Normal navigation to/from Settings must NOT restore an old
   scroll position. This prevents the scroll wheel from feeling
   like it is fighting the page after switching sidebar tabs.
========================================================= */
(function () {
    const SCROLL_KEY = 'localitea_settings_scroll_y_v4';
    const ACTION_PARAMS = new Set([
        'toggle_availability', 'toggle_bestseller', 'delete_product',
        'toggle_addon', 'archive_addon'
    ]);
    const RESULT_PARAMS = new Set([
        'addon_success', 'addon_edit_success', 'addon_toggle_success',
        'addon_archive_success', 'addon_product_success',
        'edit_success', 'product_success', 'availability_success', 'availability_error',
        'bestseller_success', 'bestseller_error',
        'delete_success', 'delete_error'
    ]);

    function getScrollY() {
        return Math.max(
            Number(window.scrollY || 0),
            Number(document.documentElement.scrollTop || 0),
            Number(document.body.scrollTop || 0)
        );
    }

    function saveScrollPosition() {
        try {
            sessionStorage.setItem(
                SCROLL_KEY,
                JSON.stringify({ y: getScrollY(), savedAt: Date.now() })
            );
        } catch (error) {}
    }

    function readSavedScroll() {
        try {
            const raw = sessionStorage.getItem(SCROLL_KEY);
            if (!raw) return null;

            const parsed = JSON.parse(raw);
            const y = Number(parsed && parsed.y);

            return Number.isFinite(y) && y >= 0 ? y : null;
        } catch (error) {
            return null;
        }
    }

    function clearSavedScroll() {
        try {
            sessionStorage.removeItem(SCROLL_KEY);
        } catch (error) {}
    }

    function isSettingsActionLink(link) {
        if (!link) return false;

        let url;
        try {
            url = new URL(link.href, window.location.href);
        } catch (error) {
            return false;
        }

        if (!/settings\.php$/i.test(url.pathname)) {
            return false;
        }

        for (const param of ACTION_PARAMS) {
            if (url.searchParams.has(param)) {
                return true;
            }
        }

        return false;
    }

    function hasActionResult() {
        const params = new URLSearchParams(window.location.search);

        for (const param of RESULT_PARAMS) {
            if (params.has(param)) {
                return true;
            }
        }

        return false;
    }

    function restoreAfterAction() {
        if (!hasActionResult()) {
            // This is a normal page visit/navigation. Never restore an
            // old action position that may have been left in sessionStorage.
            clearSavedScroll();
            return;
        }

        const savedY = readSavedScroll();
        if (savedY === null) {
            return;
        }

        // Let the page render once, then restore the position only once.
        window.requestAnimationFrame(function () {
            window.scrollTo(0, savedY);

            // One delayed correction handles layout changes from the
            // table/toast without repeatedly fighting user scrolling.
            setTimeout(function () {
                window.scrollTo(0, savedY);
                clearSavedScroll();
            }, 120);
        });
    }

    // Keep browser navigation's normal scroll behavior. Do not force
    // manual restoration globally.
    if ('scrollRestoration' in history) {
        history.scrollRestoration = 'auto';
    }

    document.addEventListener('DOMContentLoaded', restoreAfterAction, { once: true });

    // Save only when the user is actually performing a product/add-on
    // action. Sidebar navigation and ordinary Settings visits are ignored.
    document.addEventListener('submit', function (event) {
        const form = event.target.closest('form');
        if (!form || form.method.toUpperCase() !== 'POST') {
            return;
        }

        saveScrollPosition();
    }, true);

    document.addEventListener('click', function (event) {
        const link = event.target.closest('a[href]');
        if (!link || event.defaultPrevented) {
            return;
        }

        if (isSettingsActionLink(link)) {
            saveScrollPosition();
        }
    }, true);
})();
</script>

<script>
/* =========================================================
   PROMOTION AJAX ACTIONS
   Edit / activate / deactivate / delete without a page reload.
========================================================= */
document.addEventListener('DOMContentLoaded', function () {
    const promotionModal = document.getElementById('promotionModal');
    const promotionForm = document.getElementById('promotionForm');
    const promotionAjaxAction = document.getElementById('promotionAjaxAction');
    const deleteModal = document.getElementById('promotionDeleteModal');
    const confirmDeleteButton = document.getElementById('confirmPromotionDeleteBtn');
    const promotionName = document.getElementById('promotionDeleteName');
    let deletePromotionId = 0;
    let toastTimer = null;

    function promotionRefreshUrl() {
        const url = new URL(window.location.href);
        url.searchParams.set('tab', 'promotions');
        [
            'promotion_success',
            'promotion_edit_success',
            'promotion_toggle_success',
            'promotion_delete_success'
        ].forEach(function (param) {
            url.searchParams.delete(param);
        });
        url.searchParams.set('_promotion_refresh', String(Date.now()));
        return url.toString();
    }

    function showAjaxToast(message, type, icon) {
        type = type || 'success';
        icon = icon || 'bi-check-circle';

        if (toastTimer) clearTimeout(toastTimer);

        const existingWrap = document.querySelector('.settings-toast-wrap');
        if (existingWrap) existingWrap.remove();

        const wrap = document.createElement('div');
        wrap.className = 'settings-toast-wrap';
        wrap.setAttribute('aria-live', 'polite');
        wrap.setAttribute('aria-atomic', 'true');

        const toast = document.createElement('div');
        toast.className = 'settings-toast settings-toast-' + type;
        toast.id = 'settingsActionToast';
        toast.setAttribute('role', 'status');

        const iconWrap = document.createElement('span');
        iconWrap.className = 'settings-toast-icon';
        iconWrap.innerHTML = '<i class="bi ' + icon + '"></i>';

        const messageSpan = document.createElement('span');
        messageSpan.className = 'settings-toast-message';
        messageSpan.textContent = message;

        const closeButton = document.createElement('button');
        closeButton.type = 'button';
        closeButton.className = 'settings-toast-close';
        closeButton.setAttribute('aria-label', 'Close notification');
        closeButton.innerHTML = '<i class="bi bi-x-lg"></i>';

        const progress = document.createElement('span');
        progress.className = 'settings-toast-progress';
        progress.setAttribute('aria-hidden', 'true');

        toast.append(iconWrap, messageSpan, closeButton, progress);
        wrap.appendChild(toast);
        document.body.appendChild(wrap);

        function closeToast() {
            if (!toast.isConnected || toast.classList.contains('is-closing')) return;
            toast.classList.add('is-closing');
            setTimeout(function () {
                if (wrap.isConnected) wrap.remove();
            }, 190);
        }

        closeButton.addEventListener('click', function () {
            clearTimeout(toastTimer);
            closeToast();
        });

        toastTimer = setTimeout(closeToast, 3500);
    }

    async function refreshPromotionPanel() {
        const currentPanel = document.querySelector('.promotion-management-panel');
        if (!currentPanel) return;

        currentPanel.classList.add('promotion-panel-refreshing');

        try {
            const response = await fetch(promotionRefreshUrl(), {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Cache-Control': 'no-cache'
                },
                cache: 'no-store'
            });

            if (!response.ok) {
                throw new Error('Failed to refresh promotions.');
            }

            const html = await response.text();
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const freshPanel = doc.querySelector('.promotion-management-panel');

            if (!freshPanel) {
                throw new Error('Promotion panel was not found.');
            }

            currentPanel.replaceWith(freshPanel);
        } finally {
            const refreshedPanel = document.querySelector('.promotion-management-panel');
            if (refreshedPanel) refreshedPanel.classList.remove('promotion-panel-refreshing');
        }
    }

    async function postPromotionAction(action, promotionId, extraBody) {
        const body = new URLSearchParams();
        body.set('ajax_promotion_action', action);
        if (promotionId) body.set('id', String(promotionId));

        if (extraBody) {
            Object.keys(extraBody).forEach(function (key) {
                body.set(key, String(extraBody[key]));
            });
        }

        const response = await fetch(window.location.pathname, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body.toString()
        });

        const data = await response.json();
        if (!response.ok || !data.success) {
            throw new Error((data.errors || ['The promotion action could not be completed.']).join('\n'));
        }

        return data;
    }

    /* Toggle: works for both Activate and Deactivate. */
    document.addEventListener('click', async function (event) {
        const button = event.target.closest('.promotion-toggle-btn');
        if (!button) return;

        event.preventDefault();
        if (button.dataset.processing === '1') return;

        const promotionId = Number(button.getAttribute('data-promotion-id') || 0);
        if (!promotionId) return;

        button.dataset.processing = '1';
        button.disabled = true;

        try {
            const data = await postPromotionAction('toggle', promotionId);
            await refreshPromotionPanel();
            showAjaxToast(
                data.message || 'Promotion status updated successfully!',
                'success',
                'bi-power'
            );
        } catch (error) {
            showAjaxToast(
                error.message || 'The promotion status could not be updated.',
                'warning',
                'bi-exclamation-triangle'
            );
        } finally {
            button.disabled = false;
            button.dataset.processing = '0';
        }
    });

    /* Delete confirmation: the modal remains, but confirmation is AJAX. */
    document.addEventListener('click', function (event) {
        const button = event.target.closest('.promotion-delete-btn');
        if (!button || !deleteModal) return;

        deletePromotionId = Number(button.getAttribute('data-promotion-id') || 0);
        if (promotionName) {
            promotionName.textContent = button.getAttribute('data-promotion-title') || 'this promotion';
        }
        if (confirmDeleteButton) {
            confirmDeleteButton.disabled = false;
        }
    });

    if (confirmDeleteButton) {
        confirmDeleteButton.addEventListener('click', async function () {
            if (!deletePromotionId || confirmDeleteButton.dataset.processing === '1') return;

            confirmDeleteButton.dataset.processing = '1';
            confirmDeleteButton.disabled = true;
            confirmDeleteButton.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Deleting...';

            try {
                const data = await postPromotionAction('delete', deletePromotionId);

                if (window.bootstrap && deleteModal) {
                    bootstrap.Modal.getOrCreateInstance(deleteModal).hide();
                }

                await refreshPromotionPanel();
                showAjaxToast(
                    data.message || 'Promotion deleted successfully!',
                    'success',
                    'bi-trash3'
                );
            } catch (error) {
                showAjaxToast(
                    error.message || 'The promotion could not be deleted.',
                    'warning',
                    'bi-exclamation-triangle'
                );
            } finally {
                confirmDeleteButton.disabled = false;
                confirmDeleteButton.dataset.processing = '0';
                confirmDeleteButton.innerHTML = '<i class="bi bi-trash3 me-1"></i>Delete';
            }
        });
    }

    if (deleteModal) {
        deleteModal.addEventListener('hidden.bs.modal', function () {
            deletePromotionId = 0;
            if (promotionName) promotionName.textContent = '';
            if (confirmDeleteButton) {
                confirmDeleteButton.disabled = false;
                confirmDeleteButton.dataset.processing = '0';
                confirmDeleteButton.innerHTML = '<i class="bi bi-trash3 me-1"></i>Delete';
            }
        });
    }

    /* Save/Create/Edit promotion through AJAX, including image uploads. */
    if (promotionForm) {
        promotionForm.addEventListener('submit', async function (event) {
            event.preventDefault();
            event.stopImmediatePropagation();

            const type = document.getElementById('promotionType')?.value || '';
            let valid = true;

            if (type === 'bogo') {
                valid = Boolean(
                    document.getElementById('bogoProductId')?.value &&
                    document.getElementById('bogoSize')?.value
                );
                if (!valid) alert('Select the product and size for the Buy 1 Take 1 promotion.');
            } else if (type === 'buy_x_get_y') {
                valid = Boolean(
                    document.getElementById('buyProductId')?.value &&
                    document.getElementById('buySize')?.value &&
                    document.getElementById('getProductId')?.value &&
                    document.getElementById('getSize')?.value
                );
                if (!valid) alert('Select a product and size for both the Buy and Get portions.');
            } else if (type === 'percentage' || type === 'fixed') {
                valid = Boolean(
                    document.getElementById('discountProductId')?.value &&
                    document.getElementById('discountSize')?.value
                );
                if (!valid) alert('Select the product and size for the discount.');
            } else if (type === 'bundle') {
                const selected = Array.from(document.querySelectorAll('.bundle-product-checkbox:checked'));
                valid = selected.length >= 2;
                if (!valid) {
                    alert('Select at least 2 bundle products.');
                } else {
                    for (const checkbox of selected) {
                        const option = checkbox.closest('.bundle-product-option');
                        const sizeSelect = option ? option.querySelector('.bundle-product-size') : null;
                        if (!sizeSelect || !sizeSelect.value) {
                            valid = false;
                            alert('Select a size for every selected bundle product.');
                            break;
                        }
                    }
                }
            }

            if (!valid) return;

            const formData = new FormData(promotionForm);
            const isEditing = Number(document.getElementById('promotionId')?.value || 0) > 0;
            formData.set('ajax_promotion_action', 'save');
            formData.set('save_promotion', '1');

            const saveButton = document.getElementById('savePromotionBtn');
            const originalButtonHtml = saveButton ? saveButton.innerHTML : '';

            if (saveButton) {
                saveButton.disabled = true;
                saveButton.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Saving...';
            }

            try {
                const response = await fetch(window.location.pathname, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                });

                const data = await response.json();
                if (!response.ok || !data.success) {
                    throw new Error((data.errors || ['Failed to save promotion.']).join('\n'));
                }

                if (window.bootstrap && promotionModal) {
                    bootstrap.Modal.getOrCreateInstance(promotionModal).hide();
                }

                await refreshPromotionPanel();
                showAjaxToast(
                    data.message || (isEditing ? 'Promotion updated successfully!' : 'Promotion created successfully!'),
                    'success',
                    isEditing ? 'bi-check-circle' : 'bi-megaphone'
                );
            } catch (error) {
                showAjaxToast(
                    error.message || 'Failed to save promotion.',
                    'warning',
                    'bi-exclamation-triangle'
                );
            } finally {
                if (saveButton) {
                    saveButton.disabled = false;
                    saveButton.innerHTML = originalButtonHtml;
                }
                if (promotionAjaxAction) promotionAjaxAction.value = '';
            }
        }, true);
    }
});
</script>


<script>
/* =========================================================
   ACTION TOAST BEHAVIOR
========================================================= */
document.addEventListener('DOMContentLoaded', function () {
    const toast = document.getElementById('settingsActionToast');
    if (!toast) return;

    const closeButton = document.getElementById('settingsToastClose');
    let closeTimer = null;

    function closeToast() {
        if (toast.classList.contains('is-closing')) return;
        toast.classList.add('is-closing');
        setTimeout(function () { toast.remove(); }, 190);
    }

    if (closeButton) {
        closeButton.addEventListener('click', function () {
            clearTimeout(closeTimer);
            closeToast();
        });
    }

    closeTimer = setTimeout(closeToast, 3500);

    /* Remove one-time result parameters so refresh will not repeat the toast. */
    try {
        const url = new URL(window.location.href);
        [
            'user_account_add_success',
            'promotion_success', 'promotion_edit_success', 'promotion_toggle_success',
            'promotion_delete_success', 'addon_success', 'addon_edit_success', 'addon_toggle_success',
            'addon_archive_success', 'addon_product_success', 'product_success',
            'edit_success', 'availability_success', 'availability_error',
            'delete_success', 'delete_error'
        ].forEach(function (param) { url.searchParams.delete(param); });

        const cleanUrl = url.pathname +
            (url.searchParams.toString() ? '?' + url.searchParams.toString() : '') +
            url.hash;

        window.history.replaceState({}, document.title, cleanUrl);
    } catch (error) {}
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const manageAddonsModal = document.getElementById('manageAddonsModal');
    const addAddonModal = document.getElementById('addAddonModal');
    const editAddonModal = document.getElementById('editAddonModal');

    if (manageAddonsModal) {
        [addAddonModal, editAddonModal].forEach(function (childModal) {
            if (!childModal) return;

            childModal.addEventListener('hidden.bs.modal', function () {
                if (window.bootstrap && bootstrap.Modal) {
                    bootstrap.Modal.getOrCreateInstance(manageAddonsModal).show();
                }
            });
        });

        [addAddonModal, editAddonModal].forEach(function (childModal) {
            if (!childModal) return;

            childModal.addEventListener('show.bs.modal', function () {
                const parentInstance = bootstrap.Modal.getInstance(manageAddonsModal);
                if (parentInstance) {
                    parentInstance.hide();
                }
            });
        });
    }
});
</script>

<script>
    window.PROMOTION_PRODUCTS = <?= json_encode(array_map(static function ($p) {
        $regularAvailable = (float)($p['regular_price'] ?? 0) > 0;
        $grandeAvailable = (float)($p['grande_price'] ?? 0) > 0;

        return [
            'id' => (int)$p['id'],
            'name' => $p['name'],
            'category' => $p['category_name'] ?? '',
            'has_regular' => $regularAvailable,
            'has_grande' => $grandeAvailable,
            'regular_price' => $regularAvailable ? (float)$p['regular_price'] : null,
            'grande_price' => $grandeAvailable ? (float)$p['grande_price'] : null
        ];
    }, $promotionProducts), JSON_UNESCAPED_UNICODE) ?>;
</script>

<script>
/* =========================================================
   PROMOTION BUILDER
   Category -> Product -> Size
========================================================= */
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('promotionModal');
    const form = document.getElementById('promotionForm');
    const title = document.getElementById('promotionModalTitle');
    const saveButton = document.getElementById('savePromotionBtn');
    const typeSelect = document.getElementById('promotionType');
    const hint = document.getElementById('promotionRuleHint');
    const currentImageWrap = document.getElementById('promotionCurrentImageWrap');
    const currentImage = document.getElementById('promotionCurrentImage');
    const bundleProductType = document.getElementById('bundleProductType');
    const bundleProductsList = document.getElementById('bundleProductsList');
    const bundleProductFilterHint = document.getElementById('bundleProductFilterHint');
    const bundleSelectedSummary = document.getElementById('bundleSelectedSummary');
    const bundleSelectedSummaryList = document.getElementById('bundleSelectedSummaryList');
    const allProducts = window.PROMOTION_PRODUCTS || [];

    if (!modal || !form || !typeSelect) return;

    /* Promotion modal stays above the fixed admin navigation. */
    modal.addEventListener('shown.bs.modal', function () {
        const backdrops = document.querySelectorAll('.modal-backdrop');
        const backdrop = backdrops[backdrops.length - 1];
        if (backdrop) backdrop.classList.add('promotion-modal-backdrop');
    });

    modal.addEventListener('hidden.bs.modal', function () {
        document.querySelectorAll('.modal-backdrop.promotion-modal-backdrop').forEach(function (backdrop) {
            backdrop.classList.remove('promotion-modal-backdrop');
        });
    });

    const panels = {
        bogo: document.getElementById('ruleBogo'),
        buy_x_get_y: document.getElementById('ruleBuyGet'),
        bundle: document.getElementById('ruleBundle'),
        percentage: document.getElementById('ruleDiscount'),
        fixed: document.getElementById('ruleDiscount')
    };

    const singleProductPickers = [
        { categoryId: 'bogoCategory', productId: 'bogoProductId', sizeId: 'bogoSize' },
        { categoryId: 'buyCategory', productId: 'buyProductId', sizeId: 'buySize' },
        { categoryId: 'getCategory', productId: 'getProductId', sizeId: 'getSize' },
        { categoryId: 'discountCategory', productId: 'discountProductId', sizeId: 'discountSize' }
    ];

    function findProduct(productId) {
        return allProducts.find(function (product) {
            return Number(product.id) === Number(productId);
        }) || null;
    }

    function clearSelect(select, placeholder, disabled) {
        if (!select) return;
        select.innerHTML = '';
        const option = document.createElement('option');
        option.value = '';
        option.textContent = placeholder;
        select.appendChild(option);
        select.disabled = Boolean(disabled);
    }

    function populateProductSelect(productSelect, category, selectedId) {
        if (!productSelect) return;

        productSelect.innerHTML = '';

        if (!category) {
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = 'Select category first';
            productSelect.appendChild(placeholder);
            productSelect.disabled = true;
            return;
        }

        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Select product';
        productSelect.appendChild(placeholder);

        allProducts
            .filter(function (product) {
                return product.category === category;
            })
            .forEach(function (product) {
                const option = document.createElement('option');
                option.value = String(product.id);
                option.textContent = product.name;
                option.dataset.hasRegular = product.has_regular ? '1' : '0';
                option.dataset.hasGrande = product.has_grande ? '1' : '0';
                productSelect.appendChild(option);
            });

        productSelect.disabled = false;
        if (selectedId) {
            productSelect.value = String(selectedId);
        }
    }

    function populateSizeSelect(sizeSelect, productId, selectedSize, autoSelectSingle = true) {
        if (!sizeSelect) return;

        sizeSelect.innerHTML = '';
        sizeSelect.disabled = true;
        sizeSelect.required = false;

        const product = findProduct(productId);
        if (!product) {
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = 'Select product first';
            sizeSelect.appendChild(placeholder);
            return;
        }

        const sizes = [];
        if (product.has_regular) {
            sizes.push({
                value: 'regular',
                label: 'Regular',
                price: Number(product.regular_price || 0)
            });
        }
        if (product.has_grande) {
            sizes.push({
                value: 'grande',
                label: 'Grande',
                price: Number(product.grande_price || 0)
            });
        }

        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = sizes.length > 1 ? 'Select size' : 'Size';
        sizeSelect.appendChild(placeholder);

        sizes.forEach(function (size) {
            const option = document.createElement('option');
            option.value = size.value;
            option.textContent = size.price > 0
                ? size.label + ' (₱' + size.price.toFixed(2) + ')'
                : size.label;
            sizeSelect.appendChild(option);
        });

        if (selectedSize && sizes.some(function (size) { return size.value === String(selectedSize).toLowerCase(); })) {
            sizeSelect.value = String(selectedSize).toLowerCase();
        } else if (autoSelectSingle && sizes.length === 1) {
            sizeSelect.value = sizes[0].value;
        }

        /* Keep the select enabled so its value is submitted even when the product has only one size. */
        sizeSelect.disabled = false;

        if (sizes.length > 1 && !selectedSize) {
            sizeSelect.value = '';
        }
    }

    function handleProductChange(picker, selectedSize) {
        const productSelect = document.getElementById(picker.productId);
        const sizeSelect = document.getElementById(picker.sizeId);
        if (!productSelect || !sizeSelect) return;
        populateSizeSelect(sizeSelect, productSelect.value, selectedSize || '');
    }

    function populatePicker(picker, category, productId, size) {
        const categorySelect = document.getElementById(picker.categoryId);
        const productSelect = document.getElementById(picker.productId);
        const sizeSelect = document.getElementById(picker.sizeId);
        if (!categorySelect || !productSelect || !sizeSelect) return;

        categorySelect.value = category || '';
        populateProductSelect(productSelect, category || '', productId || null);
        populateSizeSelect(sizeSelect, productId || null, size || '');
    }

    singleProductPickers.forEach(function (picker) {
        const categorySelect = document.getElementById(picker.categoryId);
        const productSelect = document.getElementById(picker.productId);
        if (!categorySelect || !productSelect) return;

        categorySelect.addEventListener('change', function () {
            populateProductSelect(productSelect, categorySelect.value, null);
            populateSizeSelect(document.getElementById(picker.sizeId), null, '');
        });

        productSelect.addEventListener('change', function () {
            handleProductChange(picker, '');
        });
    });

    function setPanelVisibility() {
        Object.values(panels).forEach(function (panel) {
            if (panel) panel.classList.add('d-none');
        });

        const selected = typeSelect.value;
        if (panels[selected]) panels[selected].classList.remove('d-none');

        const discountPrefix = document.getElementById('discountValuePrefix');
        const discountLabel = document.getElementById('discountValueLabel');
        const discountValue = document.getElementById('discountValue');

        if (selected === 'percentage') {
            if (discountPrefix) discountPrefix.textContent = '%';
            if (discountLabel) discountLabel.textContent = 'Discount Percentage';
            if (discountValue) {
                discountValue.min = '0.01';
                discountValue.max = '100';
                discountValue.placeholder = '10';
            }
            hint.textContent = 'The selected product size receives the percentage discount.';
        } else if (selected === 'fixed') {
            if (discountPrefix) discountPrefix.textContent = '₱';
            if (discountLabel) discountLabel.textContent = 'Discount Amount';
            if (discountValue) {
                discountValue.removeAttribute('max');
                discountValue.placeholder = '10.00';
            }
            hint.textContent = 'The selected product size receives the fixed peso discount.';
        } else if (selected === 'bogo') {
            hint.textContent = 'Customer buys 1 of the selected product size and gets 1 more of the same size free.';
        } else if (selected === 'buy_x_get_y') {
            hint.textContent = 'Customer buys the required quantity of the selected Buy product and Buy size, then receives the selected Get product and Get size free.';
        } else if (selected === 'bundle') {
            hint.textContent = 'Customers receive all selected products and their selected sizes for the fixed bundle price.';
        } else {
            hint.textContent = 'Select a promotion type to configure the rule.';
        }
    }

    function syncBundleSizeControl(option) {
        const checkbox = option.querySelector('.bundle-product-checkbox');
        const sizeSelect = option.querySelector('.bundle-product-size');
        if (!checkbox || !sizeSelect) return;

        const product = findProduct(checkbox.value);
        if (!product) return;

        sizeSelect.innerHTML = '';
        const sizes = [];
        if (product.has_regular) {
            sizes.push({
                value: 'regular',
                label: 'Regular',
                price: Number(product.regular_price || 0)
            });
        }
        if (product.has_grande) {
            sizes.push({
                value: 'grande',
                label: 'Grande',
                price: Number(product.grande_price || 0)
            });
        }

        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = sizes.length > 1 ? 'Select size' : 'Size';
        sizeSelect.appendChild(placeholder);

        sizes.forEach(function (size) {
            const item = document.createElement('option');
            item.value = size.value;
            item.textContent = size.price > 0
                ? size.label + ' (₱' + size.price.toFixed(2) + ')'
                : size.label;
            sizeSelect.appendChild(item);
        });

        if (sizes.length === 1) {
            sizeSelect.value = sizes[0].value;
            sizeSelect.disabled = !checkbox.checked;
        } else {
            sizeSelect.value = sizeSelect.dataset.selectedSize || '';
            sizeSelect.disabled = !checkbox.checked;
        }

        sizeSelect.required = checkbox.checked;
    }

    function refreshBundleSelectedSummary() {
        if (!bundleSelectedSummary || !bundleSelectedSummaryList) return;

        const selected = Array.from(document.querySelectorAll('.bundle-product-checkbox:checked'));
        bundleSelectedSummaryList.innerHTML = '';

        selected.forEach(function (checkbox) {
            const option = checkbox.closest('.bundle-product-option');
            const name = option ? (option.getAttribute('data-product-name') || 'Selected product') : 'Selected product';
            const sizeSelect = option ? option.querySelector('.bundle-product-size') : null;
            const sizeLabel = sizeSelect && sizeSelect.value
                ? (sizeSelect.options[sizeSelect.selectedIndex]?.textContent || '')
                : 'Size not selected';

            const chip = document.createElement('span');
            chip.className = 'bundle-selected-summary-item';
            chip.textContent = name + ' (' + sizeLabel + ')';
            bundleSelectedSummaryList.appendChild(chip);
        });

        bundleSelectedSummary.classList.toggle('d-none', selected.length === 0);
    }

    function filterBundleProducts() {
        if (!bundleProductType || !bundleProductsList) return;

        const selectedType = bundleProductType.value;
        const options = document.querySelectorAll('.bundle-product-option');
        const showAll = selectedType === '__all__';
        let visibleCount = 0;

        options.forEach(function (option) {
            const checkbox = option.querySelector('.bundle-product-checkbox');
            const matches = showAll || (selectedType !== '' && option.getAttribute('data-category') === selectedType);
            option.style.display = matches ? 'flex' : 'none';
            if (matches) visibleCount++;
            if (checkbox) syncBundleSizeControl(option);
        });

        bundleProductsList.classList.toggle('d-none', selectedType === '');

        if (selectedType === '') {
            bundleProductFilterHint.textContent = 'Select a product type to display its products.';
        } else if (showAll) {
            bundleProductFilterHint.textContent = 'Showing products from all product types.';
        } else {
            bundleProductFilterHint.textContent = visibleCount + (visibleCount === 1 ? ' product' : ' products') + ' available in this product type.';
        }

        refreshBundleSelectedSummary();
    }

    function clearRuleInputs() {
        singleProductPickers.forEach(function (picker) {
            const category = document.getElementById(picker.categoryId);
            const product = document.getElementById(picker.productId);
            const size = document.getElementById(picker.sizeId);

            if (category) category.value = '';
            if (product) clearSelect(product, 'Select category first', true);
            if (size) clearSelect(size, 'Select product first', true);
        });

        const buyQuantity = document.getElementById('buyQuantity');
        const getQuantity = document.getElementById('getQuantity');
        const discountValue = document.getElementById('discountValue');
        const bundlePrice = document.getElementById('bundlePrice');

        if (buyQuantity) buyQuantity.value = '1';
        if (getQuantity) getQuantity.value = '1';
        if (discountValue) discountValue.value = '';
        if (bundlePrice) bundlePrice.value = '';

        document.querySelectorAll('.bundle-product-checkbox').forEach(function (checkbox) {
            checkbox.checked = false;
        });
        document.querySelectorAll('.bundle-product-size').forEach(function (sizeSelect) {
            sizeSelect.dataset.selectedSize = '';
            sizeSelect.value = '';
            sizeSelect.disabled = true;
            sizeSelect.required = false;
        });

        if (bundleProductType) bundleProductType.value = '';
        if (bundleProductsList) bundleProductsList.classList.add('d-none');
        if (bundleProductFilterHint) bundleProductFilterHint.textContent = 'Select a product type to display its products.';
        if (bundleSelectedSummary) bundleSelectedSummary.classList.add('d-none');
        if (bundleSelectedSummaryList) bundleSelectedSummaryList.innerHTML = '';
    }

    function resetForm() {
        form.reset();
        document.getElementById('promotionId').value = '';
        title.innerHTML = '<i class="bi bi-megaphone me-2"></i>Create Promotion';
        saveButton.innerHTML = '<i class="bi bi-check-lg me-1"></i>Save Promotion';
        currentImageWrap.classList.add('d-none');
        clearRuleInputs();
        setPanelVisibility();
    }

    function getItemSize(item) {
        const size = String(item?.size || '').toLowerCase();
        return size === 'regular' || size === 'grande' ? size : '';
    }

    modal.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;

        if (!button || !button.classList.contains('promotion-edit-btn')) {
            resetForm();
            return;
        }

        resetForm();

        document.getElementById('promotionId').value = button.getAttribute('data-promotion-id') || '';
        document.getElementById('promotionTitle').value = button.getAttribute('data-title') || '';
        document.getElementById('promotionDescription').value = button.getAttribute('data-description') || '';
        document.getElementById('promotionType').value = button.getAttribute('data-type') || '';
        document.getElementById('promotionStartDate').value = button.getAttribute('data-start') || '';
        document.getElementById('promotionEndDate').value = button.getAttribute('data-end') || '';
        document.getElementById('buyQuantity').value = button.getAttribute('data-buy-quantity') || '1';
        document.getElementById('getQuantity').value = button.getAttribute('data-get-quantity') || '1';
        document.getElementById('discountValue').value = button.getAttribute('data-discount-value') || '';
        document.getElementById('bundlePrice').value = button.getAttribute('data-bundle-price') || '';

        let items = [];
        try {
            items = JSON.parse(button.getAttribute('data-items') || '[]');
        } catch (error) {
            items = [];
        }

        items.forEach(function (item) {
            const product = findProduct(item.product_id);
            if (!product) return;

            const size = getItemSize(item);

            if (item.role === 'buy') {
                const picker = singleProductPickers.find(function (p) { return p.productId === 'buyProductId'; });
                populatePicker(picker, product.category, item.product_id, size);
            }

            if (item.role === 'get') {
                const picker = singleProductPickers.find(function (p) { return p.productId === 'getProductId'; });
                populatePicker(picker, product.category, item.product_id, size);
            }

            if (item.role === 'qualifying') {
                const picker = singleProductPickers.find(function (p) { return p.productId === 'discountProductId'; });
                populatePicker(picker, product.category, item.product_id, size);
            }

            if (item.role === 'bundle') {
                const option = document.querySelector('.bundle-product-checkbox[value="' + item.product_id + '"]')?.closest('.bundle-product-option');
                if (!option) return;

                const checkbox = option.querySelector('.bundle-product-checkbox');
                const sizeSelect = option.querySelector('.bundle-product-size');
                if (checkbox) checkbox.checked = true;
                if (sizeSelect) sizeSelect.dataset.selectedSize = size;
                syncBundleSizeControl(option);
                if (sizeSelect && size) sizeSelect.value = size;
            }
        });

        /* BOGO stores the same size on both buy/get rows; use the Buy row. */
        const bogoItem = items.find(function (item) { return item.role === 'buy'; }) || items[0];
        if (button.getAttribute('data-type') === 'bogo' && bogoItem) {
            const product = findProduct(bogoItem.product_id);
            if (product) {
                const picker = singleProductPickers.find(function (p) { return p.productId === 'bogoProductId'; });
                populatePicker(picker, product.category, bogoItem.product_id, getItemSize(bogoItem));
            }
        }

        if (button.getAttribute('data-type') === 'bundle' && bundleProductType) {
            const selectedBundleOptions = Array.from(document.querySelectorAll('.bundle-product-checkbox:checked'));
            const selectedCategories = Array.from(new Set(selectedBundleOptions.map(function (checkbox) {
                const option = checkbox.closest('.bundle-product-option');
                return option ? option.getAttribute('data-category') : '';
            }).filter(Boolean)));

            if (selectedCategories.length === 1) {
                bundleProductType.value = selectedCategories[0];
            } else if (selectedCategories.length > 1) {
                bundleProductType.value = '__all__';
            }
            filterBundleProducts();
        }

        const image = button.getAttribute('data-image') || '';
        if (image) {
            currentImage.src = '../assets/uploads/promotions/' + image;
            currentImageWrap.classList.remove('d-none');
        }

        title.innerHTML = '<i class="bi bi-megaphone me-2"></i>Edit Promotion';
        saveButton.innerHTML = '<i class="bi bi-check-lg me-1"></i>Save Changes';
        setPanelVisibility();
    });

    typeSelect.addEventListener('change', setPanelVisibility);

    if (bundleProductType) {
        bundleProductType.addEventListener('change', filterBundleProducts);
    }

    document.querySelectorAll('.bundle-product-option').forEach(function (option) {
        syncBundleSizeControl(option);

        const checkbox = option.querySelector('.bundle-product-checkbox');
        const sizeSelect = option.querySelector('.bundle-product-size');

        if (checkbox) {
            checkbox.addEventListener('change', function () {
                syncBundleSizeControl(option);
                refreshBundleSelectedSummary();
            });
        }

        if (sizeSelect) {
            sizeSelect.addEventListener('change', function () {
                sizeSelect.dataset.selectedSize = sizeSelect.value;
                refreshBundleSelectedSummary();
            });
        }
    });

    form.addEventListener('submit', function (event) {
        const type = typeSelect.value;

        if (type === 'bogo') {
            const product = document.getElementById('bogoProductId');
            const size = document.getElementById('bogoSize');
            if (!product?.value || !size?.value) {
                event.preventDefault();
                alert('Select the product and size for the Buy 1 Take 1 promotion.');
                return;
            }
        }

        if (type === 'buy_x_get_y') {
            const buyProduct = document.getElementById('buyProductId');
            const buySize = document.getElementById('buySize');
            const getProduct = document.getElementById('getProductId');
            const getSize = document.getElementById('getSize');
            if (!buyProduct?.value || !buySize?.value || !getProduct?.value || !getSize?.value) {
                event.preventDefault();
                alert('Select a product and size for both the Buy and Get portions.');
                return;
            }
        }

        if (type === 'percentage' || type === 'fixed') {
            const product = document.getElementById('discountProductId');
            const size = document.getElementById('discountSize');
            if (!product?.value || !size?.value) {
                event.preventDefault();
                alert('Select the product and size for the discount.');
                return;
            }
        }

        if (type === 'bundle') {
            const selected = Array.from(document.querySelectorAll('.bundle-product-checkbox:checked'));
            if (selected.length < 2) {
                event.preventDefault();
                alert('Select at least 2 bundle products.');
                return;
            }

            for (const checkbox of selected) {
                const option = checkbox.closest('.bundle-product-option');
                const sizeSelect = option ? option.querySelector('.bundle-product-size') : null;
                if (!sizeSelect || !sizeSelect.value) {
                    event.preventDefault();
                    alert('Select a size for every selected bundle product.');
                    return;
                }
            }
        }
    });

    /* Initial setup */
    singleProductPickers.forEach(function (picker) {
        const category = document.getElementById(picker.categoryId);
        const product = document.getElementById(picker.productId);
        const size = document.getElementById(picker.sizeId);
        if (category && product && !category.value) clearSelect(product, 'Select category first', true);
        if (size && !product?.value) clearSelect(size, 'Select product first', true);
    });

    setPanelVisibility();
    filterBundleProducts();
});
</script>

<?php require_once '../includes/footer.php'; ?>