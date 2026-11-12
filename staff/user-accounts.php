<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once '../includes/db.php';

/* =========================================================
   STAFF ACCESS ONLY
========================================================= */
if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['user_role'] ?? '') !== 'staff'
) {
    header('Location: ../auth/login.php');
    exit;
}

$staffId = (int)$_SESSION['user_id'];

/* =========================================================
   USER ACCOUNT SETTINGS (STAFF)

   Scope: Staff manage CUSTOMER accounts only (view, search, edit
   basic contact details, delete and restore).

   Staff cannot view, create, edit, promote, delete or restore Staff
   or Admin accounts. Those stay in Admin > Settings > User Accounts.
   Staff manage their own details through Profile.
========================================================= */

$perPage = 10;

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* CSRF token for every state-changing request. */
if (empty($_SESSION['staff_accounts_csrf'])) {
    $_SESSION['staff_accounts_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['staff_accounts_csrf'];

/* Current filters (kept across redirects). */
$status = strtolower(trim((string)($_GET['status'] ?? $_POST['status'] ?? 'customers')));

/* Keep old bookmarked status links working after the label changes. */
if ($status === 'active' || $status === 'inactive') {
    $status = 'customers';
} elseif ($status === 'archived') {
    $status = 'deleted';
}

if (!in_array($status, ['customers', 'deleted'], true)) {
    $status = 'customers';
}

$search = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
if (mb_strlen($search) > 100) {
    $search = mb_substr($search, 0, 100);
}

$page = max(1, (int)($_GET['page'] ?? $_POST['page'] ?? 1));

function staffAccountsUrl(array $overrides = []): string
{
    global $status, $search, $page;

    $params = array_merge(
        [
            'status' => $status,
            'q' => $search,
            'page' => $page,
        ],
        $overrides
    );

    if (($params['q'] ?? '') === '') {
        unset($params['q']);
    }

    if ((int)($params['page'] ?? 1) <= 1) {
        unset($params['page']);
    }

    return 'user-accounts.php?' . http_build_query($params);
}

function staffAccountsFlash(string $type, string $message): void
{
    $_SESSION['staff_accounts_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

/* =========================================================
   POST ACTIONS
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedToken = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($csrfToken, $postedToken)) {
        staffAccountsFlash(
            'danger',
            'Your session expired. Please try again.'
        );
        header('Location: ' . staffAccountsUrl());
        exit;
    }

    $accountId = (int)($_POST['account_id'] ?? 0);

    /* ---------------- EDIT CUSTOMER ---------------- */
    if (isset($_POST['update_customer'])) {

        $name = trim((string)($_POST['account_name'] ?? ''));
        $phone = trim((string)($_POST['account_phone'] ?? ''));

        $errors = [];

        if ($accountId <= 0) {
            $errors[] = 'Invalid account.';
        }

        if ($name === '') {
            $errors[] = 'Name is required.';
        } elseif (mb_strlen($name) > 100) {
            $errors[] = 'Name must not exceed 100 characters.';
        }

        if (!preg_match('/^09[0-9]{9}$/D', $phone)) {
            $errors[] = 'Please enter a valid 11-digit Philippine mobile number starting with 09.';
        }

        if (!$errors) {
            try {
                $existsStmt = $pdo->prepare("
                    SELECT id
                    FROM customers
                    WHERE id = ?
                      AND is_archived = 0
                    LIMIT 1
                ");
                $existsStmt->execute([$accountId]);

                if (!$existsStmt->fetchColumn()) {
                    $errors[] = 'Customer account not found or already deleted.';
                }
            } catch (Throwable $e) {
                error_log('Staff customer lookup failed: ' . $e->getMessage());
                $errors[] = 'Unable to verify the account. Please try again.';
            }
        }

        if ($errors) {
            staffAccountsFlash('danger', implode(' ', $errors));
            header('Location: ' . staffAccountsUrl());
            exit;
        }

        try {
            $stmt = $pdo->prepare("
                UPDATE customers
                SET
                    full_name = ?,
                    contact_number = ?,
                    updated_at = NOW()
                WHERE id = ?
                  AND is_archived = 0
            ");
            $stmt->execute([
                $name,
                $phone,
                $accountId,
            ]);

            if ($stmt->rowCount() === 0) {
                $checkStmt = $pdo->prepare("
                    SELECT id
                    FROM customers
                    WHERE id = ?
                      AND is_archived = 0
                    LIMIT 1
                ");
                $checkStmt->execute([$accountId]);

                if (!$checkStmt->fetchColumn()) {
                    throw new RuntimeException('Customer account not found or already deleted.');
                }
            }

            staffAccountsFlash('success', 'Customer account details updated successfully.');
        } catch (Throwable $e) {
            error_log('Staff customer update failed: ' . $e->getMessage());
            staffAccountsFlash('danger', 'Failed to update the customer account.');
        }

        header('Location: ' . staffAccountsUrl());
        exit;
    }

    /*
     * ---------------- DELETE CUSTOMER ----------------
     * Keep the customer record so existing orders and payments remain linked.
     */
    if (isset($_POST['delete_customer'])) {

        if ($accountId <= 0) {
            staffAccountsFlash('danger', 'Invalid account.');
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE customers
                    SET is_archived = 1,
                        is_active = 0,
                        verification_token = NULL,
                        verification_expires_at = NULL,
                        reset_token = NULL,
                        reset_expires_at = NULL,
                        updated_at = NOW()
                    WHERE id = ?
                      AND is_archived = 0
                ");
                $stmt->execute([$accountId]);

                if ($stmt->rowCount() === 1) {
                    staffAccountsFlash('success', 'Customer account deleted. Previous orders and payments are kept.');
                } else {
                    staffAccountsFlash('danger', 'Customer account not found or already deleted.');
                }
            } catch (Throwable $e) {
                error_log('Staff customer delete failed: ' . $e->getMessage());
                staffAccountsFlash('danger', 'Failed to delete the customer account.');
            }
        }

        header('Location: ' . staffAccountsUrl());
        exit;
    }

    /* ---------------- RESTORE CUSTOMER ---------------- */
    if (isset($_POST['restore_customer'])) {

        if ($accountId <= 0) {
            staffAccountsFlash('danger', 'Invalid account.');
            header('Location: ' . staffAccountsUrl());
            exit;
        }

        try {
            $restore = $pdo->prepare("
                UPDATE customers
                SET is_archived = 0,
                    is_active = 1,
                    updated_at = NOW()
                WHERE id = ?
                  AND is_archived = 1
            ");
            $restore->execute([$accountId]);

            if ($restore->rowCount() === 1) {
                staffAccountsFlash('success', 'Customer account restored and reactivated.');
            } else {
                staffAccountsFlash('danger', 'Deleted customer account not found.');
            }
        } catch (Throwable $e) {
            error_log('Staff customer restore failed: ' . $e->getMessage());
            staffAccountsFlash('danger', 'Failed to restore the account.');
        }

        header('Location: ' . staffAccountsUrl(['status' => 'deleted']));
        exit;
    }
}

/* =========================================================
   LOAD DATA
========================================================= */
$flash = $_SESSION['staff_accounts_flash'] ?? null;
unset($_SESSION['staff_accounts_flash']);

$customerCount = 0;
$deletedCount = 0;
$totalRows = 0;
$accounts = [];
$loadError = '';

try {
    $countStmt = $pdo->query("
        SELECT
            SUM(CASE WHEN is_archived = 0 THEN 1 ELSE 0 END) AS customer_count,
            SUM(CASE WHEN is_archived = 1 THEN 1 ELSE 0 END) AS deleted_count
        FROM customers
    ");
    $counts = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $customerCount = (int)($counts['customer_count'] ?? 0);
    $deletedCount = (int)($counts['deleted_count'] ?? 0);

    $where = $status === 'deleted'
        ? 'is_archived = 1'
        : 'is_archived = 0';
    $params = [];

    if ($search !== '') {
        $where .= ' AND (full_name LIKE ? OR email LIKE ? OR contact_number LIKE ?)';
        $like = '%' . addcslashes($search, '%_\\') . '%';
        array_push($params, $like, $like, $like);
    }

    $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE {$where}");
    $totalStmt->execute($params);
    $totalRows = (int)$totalStmt->fetchColumn();

    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $listStmt = $pdo->prepare("
        SELECT id, full_name, email, contact_number, created_at, is_active, is_archived
        FROM customers
        WHERE {$where}
        ORDER BY full_name ASC, id ASC
        LIMIT " . (int)$perPage . " OFFSET " . (int)$offset
    );
    $listStmt->execute($params);
    $accounts = $listStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Staff user accounts load failed: ' . $e->getMessage());
    $loadError = 'Unable to load customer accounts right now.';
    $totalPages = 1;
}

$firstShown = $totalRows > 0 ? (($page - 1) * $perPage) + 1 : 0;
$lastShown = $totalRows > 0 ? $firstShown + count($accounts) - 1 : 0;

/* ---------------------------------------------------------
   AJAX account-list endpoint.
   Returns only the current account data and count metadata;
   the browser updates the tabs, rows, and pagination without
   reloading the entire Staff page.
--------------------------------------------------------- */
if (($_GET['ajax'] ?? '') === 'accounts') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    $ajaxAccounts = [];
    foreach ($accounts as $account) {
        $registeredLabel = '—';
        if (!empty($account['created_at'])) {
            $registeredTimestamp = strtotime((string)$account['created_at']);
            if ($registeredTimestamp !== false) {
                $registeredLabel = date('M d, Y', $registeredTimestamp);
            }
        }

        $ajaxAccounts[] = [
            'id' => (int)$account['id'],
            'full_name' => (string)$account['full_name'],
            'email' => (string)$account['email'],
            'contact_number' => (string)($account['contact_number'] ?? ''),
            'registered_label' => $registeredLabel,
            'is_active' => (int)($account['is_active'] ?? 1),
            'is_archived' => (int)($account['is_archived'] ?? 0),
        ];
    }

    echo json_encode([
        'success' => true,
        'status' => $status,
        'search' => $search,
        'page' => $page,
        'per_page' => $perPage,
        'customer_count' => $customerCount,
        'deleted_count' => $deletedCount,
        'total_rows' => $totalRows,
        'total_pages' => $totalPages,
        'first_shown' => $firstShown,
        'last_shown' => $lastShown,
        'accounts' => $ajaxAccounts,
        'load_error' => $loadError,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

require_once '../includes/header.php';
?>

<link
    rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
>

<style>
    body {
        background: #F7F5F2;
    }

    .staff-accounts-page {
        min-height: 100vh;
    }

    .staff-accounts-main {
        min-width: 0;
        width: calc(100% - 260px);
        margin-left: 260px;
        padding: 28px;
    }

    .staff-accounts-title {
        color: #4A3525;
        font-weight: 700;
    }

    .staff-accounts-subtitle {
        color: #7B6D62;
        margin-bottom: 0;
    }

    .staff-accounts-card {
        background: #FFFFFF;
        border: 1px solid #8B6F5A;
        border-radius: 16px;
        box-shadow: 0 4px 16px rgba(74, 53, 37, .06);
    }

    /* Success confirmations for Edit, Delete, and Restore actions. */
    .staff-accounts-toast-container {
        position: fixed;
        top: 18px;
        right: 18px;
        z-index: 2050;
        width: min(380px, calc(100vw - 36px));
        pointer-events: none;
    }

    .staff-accounts-toast {
        width: 100%;
        background: #FFFFFF;
        color: #38291F;
        border: 1px solid #8B6F5A;
        border-left: 4px solid #198754;
        border-radius: 12px;
        box-shadow: 0 8px 24px rgba(44, 34, 30, .18);
        pointer-events: auto;
    }

    .staff-accounts-toast .toast-body {
        color: #38291F;
        font-size: .9rem;
        font-weight: 600;
        line-height: 1.45;
        padding: 14px 16px;
        overflow-wrap: anywhere;
    }

    @media (max-width: 575.98px) {
        .staff-accounts-toast-container {
            top: 12px;
            right: 12px;
            width: calc(100vw - 24px);
        }
    }

    .staff-accounts-tabs {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }

    .staff-accounts-tab {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 16px;
        border-radius: 999px;
        border: 1px solid #8B6F5A;
        background: #FFFFFF;
        color: #6F4E37;
        text-decoration: none;
        font-weight: 600;
        font-size: .9rem;
        min-height: 40px;
    }

    .staff-accounts-tab:hover {
        background: #F6EEE5;
        border-color: #6F4E37;
        color: #4A3525;
    }

    .staff-accounts-tab.active {
        background: #6F4E37;
        border-color: #4A3525;
        color: #FFFFFF;
    }

    .staff-accounts-tab .count {
        background: rgba(0, 0, 0, .12);
        border-radius: 999px;
        padding: 1px 8px;
        font-size: .78rem;
    }

    .staff-accounts-toolbar-controls {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        flex: 1 1 420px;
        gap: 14px;
        min-width: 0;
    }

    .staff-accounts-search {
        display: flex;
        flex: 0 1 300px;
        width: 300px;
        max-width: 300px;
        margin: 0;
        min-width: 0;
    }

    .staff-accounts-search-field {
        position: relative;
        width: 100%;
    }

    .staff-accounts-search-field > i {
        position: absolute;
        top: 50%;
        left: 14px;
        transform: translateY(-50%);
        color: #6F4E37;
        pointer-events: none;
    }

    .staff-accounts-search .form-control {
        min-height: 42px;
        width: 100%;
        padding-left: 40px;
        color: #38291F;
        background: #F7F0E8;
        border: 1px solid #6F4E37;
        border-radius: 10px;
        box-shadow: none;
    }

    .staff-accounts-search .form-control::placeholder {
        color: #795D48;
        opacity: 1;
    }

    .staff-accounts-search .form-control:focus {
        color: #38291F;
        background: #FFFFFF;
        border-color: #4A3525;
        box-shadow: 0 0 0 .15rem rgba(111, 78, 55, .18);
        outline: 0;
    }

    .btn-brand {
        background: #6F4E37;
        border-color: #6F4E37;
        color: #FFFFFF;
        font-weight: 600;
        min-height: 42px;
    }

    .btn-brand:hover,
    .btn-brand:focus {
        background: #5A3E2B;
        border-color: #5A3E2B;
        color: #FFFFFF;
    }

    .btn-brand:disabled {
        background: #6F4E37;
        border-color: #6F4E37;
        opacity: .7;
    }

    /* Visible brown outline around the customer table on desktop and tablet. */
    #staffAccountsResults .table-responsive {
        border: 1px solid #8B6F5A;
        border-radius: 10px;
        overflow: hidden;
    }

    .staff-accounts-table {
        margin-bottom: 0;
        vertical-align: middle;
        border-color: #B99D84;
    }

    .staff-accounts-table thead th {
        background: #F6EEE5;
        color: #4A3525;
        font-size: .78rem;
        text-transform: uppercase;
        letter-spacing: .04em;
        border-bottom: 1px solid #8B6F5A;
        white-space: nowrap;
        padding: 12px 16px;
    }

    .staff-accounts-table td {
        padding: 14px 16px;
        border-color: #D1BDAA;
    }

    .staff-account-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: #EADBC8;
        color: #6F4E37;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 auto;
    }

    .staff-account-name {
        font-weight: 600;
        color: #38291F;
        overflow-wrap: anywhere;
    }

    .staff-account-email {
        overflow-wrap: anywhere;
    }

    .staff-account-actions {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        flex-wrap: wrap;
        width: 100%;
    }

    .staff-accounts-table td.cell-actions {
        text-align: center;
        vertical-align: middle;
    }

    .staff-account-actions > .btn,
    .staff-account-actions > form {
        flex: 0 1 auto;
    }

    .staff-accounts-pagination .page-link {
        color: #6F4E37;
        min-width: 40px;
        min-height: 40px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .staff-accounts-pagination .page-item.active .page-link {
        background: #6F4E37;
        border-color: #6F4E37;
        color: #fff;
    }

    #staffAccountsResults {
        transition: opacity .16s ease;
    }

    #staffAccountsResults.is-loading {
        opacity: .55;
        pointer-events: none;
    }

    .staff-accounts-ajax-feedback:empty {
        display: none;
    }

    .staff-accounts-empty {
        padding: 48px 16px;
        text-align: center;
        color: #7B6D62;
    }

    .staff-accounts-empty i {
        font-size: 2.4rem;
        color: #C9AF94;
    }

    .modal-header {
        background: #F6EEE5;
        border-bottom: 1px solid #E8DED3;
    }

    .modal-title {
        color: #4A3525;
        font-weight: 700;
    }

    /* Edit Customer fields: brown borders at rest and while focused. */
    #accountEditModal .modal-body .form-control {
        color: #212529 !important;
        background-color: #FFFFFF !important;
        border: 1px solid #6F4E37 !important;
        -webkit-text-fill-color: #212529;
        caret-color: #212529;
        opacity: 1;
    }

    #accountEditModal .modal-body .form-control::placeholder {
        color: #6C757D !important;
        opacity: 1;
    }

    #accountEditModal .modal-body .form-control:focus {
        border-color: #4A3525 !important;
        box-shadow: 0 0 0 .15rem rgba(111, 78, 55, .18);
        outline: 0;
    }

    #accountEditModal .btn-cancel-brown {
        background: #6F4E37;
        border: 1px solid #6F4E37;
        color: #FFFFFF;
        font-weight: 600;
    }

    #accountEditModal .btn-cancel-brown:hover,
    #accountEditModal .btn-cancel-brown:focus {
        background: #4A3525;
        border-color: #4A3525;
        color: #FFFFFF;
    }

    #accountEditModal .btn-cancel-brown:focus-visible {
        outline: 3px solid rgba(111, 78, 55, .25);
        outline-offset: 2px;
    }

    /* ---------- Tablet ---------- */
    @media (max-width: 991.98px) {
        .staff-accounts-main {
            width: 100%;
            margin-left: 0;
            padding: 20px 16px;
        }

        .staff-accounts-search {
            flex-basis: 280px;
            width: 280px;
            max-width: 280px;
        }
    }

    /* ---------- Phone: table rows become cards ---------- */
    @media (max-width: 767.98px) {
        .staff-accounts-main {
            padding: 16px 12px;
        }

        .staff-accounts-toolbar-controls {
            width: 100%;
            flex: 1 1 100%;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 10px;
        }

        .staff-accounts-search {
            width: 100%;
            max-width: none;
            flex: 1 1 100%;
        }

        .staff-accounts-table thead {
            display: none;
        }

        .staff-accounts-table,
        .staff-accounts-table tbody,
        .staff-accounts-table tr,
        .staff-accounts-table td {
            display: block;
            width: 100%;
        }

        #staffAccountsResults .table-responsive {
            border: 0;
            border-radius: 0;
            overflow: visible;
        }

        .staff-accounts-table tr {
            border: 1px solid #8B6F5A;
            border-radius: 12px;
            margin: 12px;
            width: auto;
            padding: 6px 0;
            background: #FFFFFF;
        }

        .staff-accounts-table td {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            padding: 8px 14px;
            border: 0;
            text-align: right;
            overflow-wrap: anywhere;
        }

        .staff-accounts-table td::before {
            content: attr(data-label);
            font-size: .72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #7B6D62;
            text-align: left;
            flex: 0 0 38%;
        }

        .staff-accounts-table td.cell-name {
            text-align: left;
            justify-content: flex-start;
        }

        .staff-accounts-table td.cell-name::before,
        .staff-accounts-table td.cell-actions::before {
            display: none;
        }

        .staff-accounts-table td.cell-actions {
            border-top: 1px solid #F0E7DD;
            margin-top: 6px;
            padding-top: 12px;
        }

        .staff-account-actions {
            width: 100%;
            justify-content: center;
            align-items: center;
            flex-wrap: wrap;
        }

        .staff-account-actions > .btn,
        .staff-account-actions > form {
            flex: 0 1 112px;
            min-width: 100px;
        }

        .staff-account-actions > .btn,
        .staff-account-actions > form .btn {
            width: 100%;
            min-height: 44px;
        }

        .modal-footer .btn {
            flex: 1 1 auto;
            min-height: 44px;
        }
    }
</style>

<div class="staff-accounts-page">

    <?php require_once 'sidebar.php'; ?>

    <?php require_once 'navbar.php'; ?>

    <main class="staff-accounts-main">

        <div class="mb-3">
            <h2 class="staff-accounts-title mb-0">
                <i class="bi bi-people me-2"></i>User Accounts
            </h2>
        </div>

        <?php if ($flash && ($flash['type'] ?? '') === 'success'): ?>
            <div class="staff-accounts-toast-container" aria-live="polite" aria-atomic="true">
                <div
                    class="toast staff-accounts-toast"
                    id="staffAccountsActionToast"
                    role="status"
                    data-bs-delay="3500"
                >
                    <div class="d-flex align-items-center">
                        <div class="toast-body">
                            <i class="bi bi-check-circle-fill text-success me-2" aria-hidden="true"></i>
                            <?= h($flash['message'] ?? 'Action completed successfully.') ?>
                        </div>
                        <button
                            type="button"
                            class="btn-close me-3"
                            data-bs-dismiss="toast"
                            aria-label="Close notification"
                        ></button>
                    </div>
                </div>
            </div>
        <?php elseif ($flash): ?>
            <div
                class="alert alert-danger alert-dismissible fade show"
                role="alert"
            >
                <i class="bi bi-exclamation-triangle me-1"></i>
                <?= h($flash['message'] ?? 'The action could not be completed.') ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if ($loadError): ?>
            <div class="alert alert-danger" role="alert"><?= h($loadError) ?></div>
        <?php endif; ?>

        <div class="staff-accounts-card">

            <div class="p-3 p-md-4 d-flex flex-wrap align-items-center justify-content-between gap-3 border-bottom">

                <div class="staff-accounts-tabs" role="tablist" aria-label="Customer account view">
                    <a
                        class="staff-accounts-tab <?= $status === 'customers' ? 'active' : '' ?>"
                        href="<?= h(staffAccountsUrl(['status' => 'customers', 'page' => 1])) ?>"
                        data-accounts-navigation
                        data-status="customers"
                    >
                        <i class="bi bi-people"></i> Customer Accounts
                        <span class="count" id="customerAccountsCount"><?= $customerCount ?></span>
                    </a>
                    <a
                        class="staff-accounts-tab <?= $status === 'deleted' ? 'active' : '' ?>"
                        href="<?= h(staffAccountsUrl(['status' => 'deleted', 'page' => 1])) ?>"
                        data-accounts-navigation
                        data-status="deleted"
                    >
                        <i class="bi bi-trash"></i> Deleted Accounts
                        <span class="count" id="deletedAccountsCount"><?= $deletedCount ?></span>
                    </a>
                </div>

                <div class="staff-accounts-toolbar-controls">
                    <form method="GET" action="user-accounts.php" class="staff-accounts-search" role="search">
                        <input type="hidden" name="status" id="accountSearchStatus" value="<?= h($status) ?>">
                        <div class="staff-accounts-search-field">
                            <i class="bi bi-search" aria-hidden="true"></i>
                            <input
                                type="text"
                                name="q"
                                class="form-control"
                                placeholder="Search"
                                value="<?= h($search) ?>"
                                maxlength="100"
                                aria-label="Search customer accounts. Press Enter to search."
                                autocomplete="off"
                            >
                        </div>
                        <button type="submit" class="visually-hidden" tabindex="-1" aria-hidden="true">Search</button>
                    </form>
                </div>

            </div>

            <div id="staffAccountsAjaxFeedback" class="staff-accounts-ajax-feedback px-3 px-md-4 pt-3" role="status" aria-live="polite"></div>

            <div id="staffAccountsResults" aria-live="polite" aria-busy="false">
            <?php if (!$accounts): ?>

                <div class="staff-accounts-empty">
                    <i class="bi bi-person-x"></i>
                    <p class="mt-2 mb-0 fw-semibold">
                        <?= $search !== ''
                            ? 'No customer accounts match your search.'
                            : ($status === 'deleted'
                                ? 'No deleted customer accounts.'
                                : 'No customer accounts yet.') ?>
                    </p>
                </div>

            <?php else: ?>

                <div class="table-responsive">
                    <table class="table staff-accounts-table">
                        <thead>
                            <tr>
                                <th scope="col">Customer</th>
                                <th scope="col">Email</th>
                                <th scope="col">Mobile Number</th>
                                <th scope="col">Registered</th>
                                <th scope="col">Status</th>
                                <th scope="col" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($accounts as $account): ?>
                            <?php
                            $isDeleted = (int)$account['is_archived'] === 1;
                            $isActive = (int)($account['is_active'] ?? 1) === 1;
                            $displayName = trim((string)$account['full_name']);
                            $initial = mb_strtoupper(mb_substr($displayName !== '' ? $displayName : '?', 0, 1));
                            ?>
                            <tr>
                                <td class="cell-name" data-label="Customer">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="staff-account-avatar" aria-hidden="true"><?= h($initial) ?></span>
                                        <span class="staff-account-name"><?= h($displayName) ?></span>
                                    </div>
                                </td>
                                <td data-label="Email">
                                    <span class="staff-account-email"><?= h($account['email']) ?></span>
                                </td>
                                <td data-label="Mobile Number">
                                    <?= $account['contact_number'] !== null && $account['contact_number'] !== ''
                                        ? h($account['contact_number'])
                                        : '<span class="text-muted">—</span>' ?>
                                </td>
                                <td data-label="Registered">
                                    <?= !empty($account['created_at'])
                                        ? h(date('M d, Y', strtotime((string)$account['created_at'])))
                                        : '—' ?>
                                </td>
                                <td data-label="Status">
                                    <?php if ($isDeleted): ?>
                                        <span class="badge text-bg-danger">Deleted</span>
                                    <?php elseif ($isActive): ?>
                                        <span class="badge text-bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-warning">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="cell-actions text-center" data-label="Actions">
                                    <div class="staff-account-actions">
                                        <?php if (!$isDeleted): ?>
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-primary"
                                                data-account-edit
                                                data-id="<?= (int)$account['id'] ?>"
                                                data-name="<?= h($account['full_name']) ?>"
                                                data-email="<?= h($account['email']) ?>"
                                                data-phone="<?= h($account['contact_number']) ?>"
                                            >
                                                <i class="bi bi-pencil-square me-1"></i>Edit
                                            </button>
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-danger"
                                                data-account-delete
                                                data-id="<?= (int)$account['id'] ?>"
                                                data-name="<?= h($account['full_name']) ?>"
                                            >
                                                <i class="bi bi-trash me-1"></i>Delete
                                            </button>
                                        <?php else: ?>
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-success"
                                                data-account-restore
                                                data-id="<?= (int)$account['id'] ?>"
                                                data-name="<?= h($account['full_name']) ?>"
                                            >
                                                <i class="bi bi-arrow-counterclockwise me-1"></i>Restore
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="p-3 p-md-4 d-flex flex-wrap align-items-center justify-content-between gap-2 border-top">
                    <small class="text-muted" aria-live="polite">
                        Showing <?= $firstShown ?>–<?= $lastShown ?> of <?= $totalRows ?> accounts
                        <span class="d-block d-sm-inline">· Page <?= $page ?> of <?= $totalPages ?></span>
                    </small>

                    <?php if ($totalPages > 1): ?>
                        <nav aria-label="Customer accounts pages">
                            <ul class="pagination pagination-sm mb-0 flex-wrap staff-accounts-pagination">
                                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= h(staffAccountsUrl(['page' => max(1, $page - 1)])) ?>" aria-label="Previous" data-accounts-navigation>&laquo;</a>
                                </li>
                                <?php
                                $startPage = max(1, $page - 2);
                                $endPage = min($totalPages, $page + 2);
                                for ($p = $startPage; $p <= $endPage; $p++):
                                ?>
                                    <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                        <a class="page-link" href="<?= h(staffAccountsUrl(['page' => $p])) ?>" data-accounts-navigation><?= $p ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= h(staffAccountsUrl(['page' => min($totalPages, $page + 1)])) ?>" aria-label="Next" data-accounts-navigation>&raquo;</a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>

            <?php endif; ?>
            </div><!-- /#staffAccountsResults -->

        </div>

    </main>

</div>

<!-- =====================================================
     EDIT CUSTOMER MODAL
====================================================== -->
<div class="modal fade" id="accountEditModal" tabindex="-1" aria-labelledby="accountEditTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <form method="POST" action="user-accounts.php" class="modal-content" id="accountEditForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <input type="hidden" name="account_id" id="editAccountId" value="">
            <input type="hidden" name="status" class="account-modal-status" value="<?= h($status) ?>">
            <input type="hidden" name="q" class="account-modal-search" value="<?= h($search) ?>">
            <input type="hidden" name="page" class="account-modal-page" value="<?= (int)$page ?>">

            <div class="modal-header">
                <h5 class="modal-title" id="accountEditTitle">
                    <i class="bi bi-pencil-square me-2"></i>Edit Customer
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="mb-3">
                    <label for="editAccountName" class="form-label fw-semibold">Full name</label>
                    <input type="text" class="form-control" id="editAccountName" name="account_name" maxlength="100" required>
                </div>

                <div class="mb-3">
                    <label for="editAccountEmail" class="form-label fw-semibold">Email address</label>
                    <input type="email" class="form-control" id="editAccountEmail" maxlength="150" readonly>
                </div>

                <div class="mb-3">
                    <label for="editAccountPhone" class="form-label fw-semibold">Mobile Number</label>
                    <input
                        type="tel"
                        class="form-control"
                        id="editAccountPhone"
                        name="account_phone"
                        maxlength="11"
                        inputmode="numeric"
                        pattern="09[0-9]{9}"
                        placeholder="09XXXXXXXXX"
                        title="Enter an 11-digit Philippine mobile number starting with 09."
                        required
                    >
                </div>

            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-cancel-brown" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" name="update_customer" value="1" class="btn btn-success" id="accountEditSubmit">
                    <i class="bi bi-check2-circle me-1"></i>Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- =====================================================
     DELETE CONFIRM MODAL
====================================================== -->
<div class="modal fade" id="accountDeleteModal" tabindex="-1" aria-labelledby="accountDeleteTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="user-accounts.php" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <input type="hidden" name="account_id" id="deleteAccountId" value="">
            <input type="hidden" name="status" class="account-modal-status" value="<?= h($status) ?>">
            <input type="hidden" name="q" class="account-modal-search" value="<?= h($search) ?>">
            <input type="hidden" name="page" class="account-modal-page" value="<?= (int)$page ?>">

            <div class="modal-header">
                <h5 class="modal-title" id="accountDeleteTitle">
                    <i class="bi bi-trash me-2"></i>Delete customer account?
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">
                    <strong id="deleteAccountName">This customer</strong> will no longer be able to sign in.
                    Their previous orders and payment records will be kept. You can restore the account
                    later from Deleted Accounts.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" name="delete_customer" value="1" class="btn btn-danger">
                    <i class="bi bi-trash me-1"></i>Delete Account
                </button>
            </div>
        </form>
    </div>
</div>

<!-- =====================================================
     RESTORE CONFIRM MODAL
====================================================== -->
<div class="modal fade" id="accountRestoreModal" tabindex="-1" aria-labelledby="accountRestoreTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="user-accounts.php" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <input type="hidden" name="account_id" id="restoreAccountId" value="">
            <input type="hidden" name="status" class="account-modal-status" value="<?= h($status) ?>">
            <input type="hidden" name="q" class="account-modal-search" value="<?= h($search) ?>">
            <input type="hidden" name="page" class="account-modal-page" value="<?= (int)$page ?>">

            <div class="modal-header">
                <h5 class="modal-title" id="accountRestoreTitle">
                    <i class="bi bi-arrow-counterclockwise me-2"></i>Restore account?
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">
                    <strong id="restoreAccountName">This customer</strong> will be able to sign in again.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" name="restore_customer" value="1" class="btn btn-success">
                    <i class="bi bi-arrow-counterclockwise me-1"></i>Restore
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    'use strict';

    /* ---------------------------------------------------------
       Modal helpers. Uses Bootstrap when available and falls back
       to a minimal show/hide so the page never becomes unusable.
    --------------------------------------------------------- */
    var fallbackBackdrop = null;

    function openModal(modal) {
        if (window.bootstrap && window.bootstrap.Modal) {
            window.bootstrap.Modal.getOrCreateInstance(modal).show();
            return;
        }
        modal.style.display = 'block';
        modal.classList.add('show');
        modal.removeAttribute('aria-hidden');
        document.body.classList.add('modal-open');
        fallbackBackdrop = document.createElement('div');
        fallbackBackdrop.className = 'modal-backdrop fade show';
        document.body.appendChild(fallbackBackdrop);
    }

    function closeModal(modal) {
        if (window.bootstrap && window.bootstrap.Modal) {
            var instance = window.bootstrap.Modal.getInstance(modal);
            if (instance) { instance.hide(); }
            return;
        }
        modal.classList.remove('show');
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');
        if (fallbackBackdrop) {
            fallbackBackdrop.remove();
            fallbackBackdrop = null;
        }
    }

    if (!(window.bootstrap && window.bootstrap.Modal)) {
        document.addEventListener('click', function (event) {
            var dismiss = event.target.closest('[data-bs-dismiss="modal"]');
            if (dismiss) {
                var modal = dismiss.closest('.modal');
                if (modal) { closeModal(modal); }
            }
            var alertClose = event.target.closest('[data-bs-dismiss="alert"]');
            if (alertClose) {
                var box = alertClose.closest('.alert');
                if (box) { box.remove(); }
            }
        });
    }

    var editPhoneInput = document.getElementById('editAccountPhone');
    if (editPhoneInput) {
        editPhoneInput.addEventListener('input', function () {
            this.value = this.value.replace(/\D/g, '').slice(0, 11);
        });
    }

    var editModal = document.getElementById('accountEditModal');
    var deleteModal = document.getElementById('accountDeleteModal');
    var restoreModal = document.getElementById('accountRestoreModal');

    /* ---------------------------------------------------------
       AJAX account navigation.
       Tabs, search (Enter), and pagination update only the list.
       Browser Back/Forward restores the matching list through AJAX.
    --------------------------------------------------------- */
    var accountsResults = document.getElementById('staffAccountsResults');
    var accountsFeedback = document.getElementById('staffAccountsAjaxFeedback');
    var accountsSearchForm = document.querySelector('.staff-accounts-search');
    var accountsSearchInput = accountsSearchForm
        ? accountsSearchForm.querySelector('input[name="q"]')
        : null;
    var accountsSearchStatus = document.getElementById('accountSearchStatus');
    var accountsRequestNumber = 0;

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function buildAccountsUrl(changes) {
        var url = new URL(window.location.href);
        url.searchParams.delete('ajax');
        Object.keys(changes).forEach(function (key) {
            var value = changes[key];
            if (value === null || value === undefined || value === '') {
                url.searchParams.delete(key);
            } else {
                url.searchParams.set(key, String(value));
            }
        });
        if (!url.searchParams.has('status')) {
            url.searchParams.set('status', 'customers');
        }
        if (url.searchParams.get('page') === '1') {
            url.searchParams.delete('page');
        }
        return url;
    }

    function updateModalFilterValues(state) {
        document.querySelectorAll('.account-modal-status').forEach(function (input) {
            input.value = state.status;
        });
        document.querySelectorAll('.account-modal-search').forEach(function (input) {
            input.value = state.search;
        });
        document.querySelectorAll('.account-modal-page').forEach(function (input) {
            input.value = state.page;
        });
    }

    function makePaginationUrl(state, pageNumber) {
        var url = buildAccountsUrl({
            status: state.status,
            q: state.search,
            page: pageNumber
        });
        return url.pathname + url.search;
    }

    function renderPagination(state) {
        if (state.total_pages <= 1) {
            return '';
        }

        var startPage = Math.max(1, state.page - 2);
        var endPage = Math.min(state.total_pages, state.page + 2);
        var html = '<nav aria-label="Customer accounts pages"><ul class="pagination pagination-sm mb-0 flex-wrap staff-accounts-pagination">';

        var previousPage = Math.max(1, state.page - 1);
        html += '<li class="page-item' + (state.page <= 1 ? ' disabled' : '') + '">';
        html += '<a class="page-link" href="' + escapeHtml(makePaginationUrl(state, previousPage)) + '" aria-label="Previous" data-accounts-navigation>&laquo;</a></li>';

        for (var pageNumber = startPage; pageNumber <= endPage; pageNumber++) {
            html += '<li class="page-item' + (pageNumber === state.page ? ' active' : '') + '">';
            html += '<a class="page-link" href="' + escapeHtml(makePaginationUrl(state, pageNumber)) + '" data-accounts-navigation>' + pageNumber + '</a></li>';
        }

        var nextPage = Math.min(state.total_pages, state.page + 1);
        html += '<li class="page-item' + (state.page >= state.total_pages ? ' disabled' : '') + '">';
        html += '<a class="page-link" href="' + escapeHtml(makePaginationUrl(state, nextPage)) + '" aria-label="Next" data-accounts-navigation>&raquo;</a></li>';
        html += '</ul></nav>';
        return html;
    }

    function renderAccountRow(account, deletedView) {
        var name = String(account.full_name || '').trim();
        var initial = Array.from(name || '?')[0].toLocaleUpperCase();
        var phone = String(account.contact_number || '');
        var actions = '';

        if (deletedView) {
            actions = '<button type="button" class="btn btn-sm btn-outline-success" data-account-restore data-id="' +
                Number(account.id) + '" data-name="' + escapeHtml(name) + '"><i class="bi bi-arrow-counterclockwise me-1"></i>Restore</button>';
        } else {
            actions = '<button type="button" class="btn btn-sm btn-primary" data-account-edit data-id="' +
                Number(account.id) + '" data-name="' + escapeHtml(name) + '" data-email="' + escapeHtml(account.email) +
                '" data-phone="' + escapeHtml(phone) + '"><i class="bi bi-pencil-square me-1"></i>Edit</button>' +
                '<button type="button" class="btn btn-sm btn-danger" data-account-delete data-id="' +
                Number(account.id) + '" data-name="' + escapeHtml(name) + '"><i class="bi bi-trash me-1"></i>Delete</button>';
        }

        var statusBadge = deletedView
            ? '<span class="badge text-bg-danger">Deleted</span>'
            : (Number(account.is_active) === 1
                ? '<span class="badge text-bg-success">Active</span>'
                : '<span class="badge text-bg-warning">Inactive</span>');

        return '<tr>' +
            '<td class="cell-name" data-label="Customer"><div class="d-flex align-items-center gap-2">' +
                '<span class="staff-account-avatar" aria-hidden="true">' + escapeHtml(initial) + '</span>' +
                '<span class="staff-account-name">' + escapeHtml(name) + '</span></div></td>' +
            '<td data-label="Email"><span class="staff-account-email">' + escapeHtml(account.email) + '</span></td>' +
            '<td data-label="Mobile Number">' + (phone ? escapeHtml(phone) : '<span class="text-muted">—</span>') + '</td>' +
            '<td data-label="Registered">' + escapeHtml(account.registered_label || '—') + '</td>' +
            '<td data-label="Status">' + statusBadge + '</td>' +
            '<td class="cell-actions text-center" data-label="Actions"><div class="staff-account-actions">' + actions + '</div></td>' +
            '</tr>';
    }

    function renderAccountsResults(data) {
        var state = {
            status: data.status,
            search: data.search || '',
            page: Number(data.page) || 1,
            total_pages: Number(data.total_pages) || 1,
            total_rows: Number(data.total_rows) || 0,
            first_shown: Number(data.first_shown) || 0,
            last_shown: Number(data.last_shown) || 0
        };
        var rows = Array.isArray(data.accounts) ? data.accounts : [];

        if (data.load_error) {
            return '<div class="alert alert-danger m-3" role="alert">' + escapeHtml(data.load_error) + '</div>';
        }

        if (!rows.length) {
            var emptyMessage = state.search
                ? 'No customer accounts match your search.'
                : (state.status === 'deleted' ? 'No deleted customer accounts.' : 'No customer accounts yet.');
            return '<div class="staff-accounts-empty"><i class="bi bi-person-x"></i><p class="mt-2 mb-0 fw-semibold">' +
                escapeHtml(emptyMessage) + '</p></div>';
        }

        var html = '<div class="table-responsive"><table class="table staff-accounts-table"><thead><tr>' +
            '<th scope="col">Customer</th><th scope="col">Email</th><th scope="col">Mobile Number</th>' +
            '<th scope="col">Registered</th><th scope="col">Status</th><th scope="col" class="text-center">Actions</th>' +
            '</tr></thead><tbody>';
        rows.forEach(function (account) {
            html += renderAccountRow(account, state.status === 'deleted');
        });
        html += '</tbody></table></div>';
        html += '<div class="p-3 p-md-4 d-flex flex-wrap align-items-center justify-content-between gap-2 border-top">';
        html += '<small class="text-muted" aria-live="polite">Showing ' + state.first_shown + '–' + state.last_shown +
            ' of ' + state.total_rows + ' accounts <span class="d-block d-sm-inline">· Page ' + state.page +
            ' of ' + state.total_pages + '</span></small>';
        html += renderPagination(state) + '</div>';
        return html;
    }

    async function loadAccountsView(url, pushHistory) {
        if (!accountsResults) { return; }
        var requestNumber = ++accountsRequestNumber;
        var requestUrl = new URL(url, window.location.href);
        var cleanUrl = new URL(requestUrl.href);
        cleanUrl.searchParams.delete('ajax');
        requestUrl.searchParams.set('ajax', 'accounts');

        accountsResults.classList.add('is-loading');
        accountsResults.setAttribute('aria-busy', 'true');
        if (accountsFeedback) { accountsFeedback.innerHTML = ''; }

        try {
            var response = await fetch(requestUrl.toString(), {
                method: 'GET',
                cache: 'no-store',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (!response.ok) {
                throw new Error('Unable to load customer accounts. Please try again.');
            }

            var data = await response.json();
            if (requestNumber !== accountsRequestNumber) { return; }
            if (!data || data.success !== true) {
                throw new Error((data && data.message) || 'Unable to load customer accounts.');
            }

            accountsResults.innerHTML = renderAccountsResults(data);
            accountsResults.classList.remove('is-loading');
            accountsResults.setAttribute('aria-busy', 'false');

            var customerTab = document.querySelector('[data-status="customers"]');
            var deletedTab = document.querySelector('[data-status="deleted"]');
            if (customerTab) {
                customerTab.classList.toggle('active', data.status === 'customers');
                var customerCount = document.getElementById('customerAccountsCount');
                if (customerCount) { customerCount.textContent = String(data.customer_count || 0); }
            }
            if (deletedTab) {
                deletedTab.classList.toggle('active', data.status === 'deleted');
                var deletedCount = document.getElementById('deletedAccountsCount');
                if (deletedCount) { deletedCount.textContent = String(data.deleted_count || 0); }
            }

            if (accountsSearchStatus) { accountsSearchStatus.value = data.status; }
            if (accountsSearchInput && accountsSearchInput.value !== (data.search || '')) {
                accountsSearchInput.value = data.search || '';
            }
            updateModalFilterValues({
                status: data.status,
                search: data.search || '',
                page: Number(data.page) || 1
            });

            if (pushHistory) {
                window.history.pushState({}, '', cleanUrl.pathname + cleanUrl.search);
            }

            /* Keep tab URLs current for open-in-new-tab and copied links. */
            if (customerTab) {
                var customerUrl = buildAccountsUrl({
                    status: 'customers',
                    q: data.search || '',
                    page: 1
                });
                customerTab.href = customerUrl.pathname + customerUrl.search;
            }
            if (deletedTab) {
                var deletedUrl = buildAccountsUrl({
                    status: 'deleted',
                    q: data.search || '',
                    page: 1
                });
                deletedTab.href = deletedUrl.pathname + deletedUrl.search;
            }
        } catch (error) {
            if (requestNumber !== accountsRequestNumber) { return; }
            accountsResults.classList.remove('is-loading');
            accountsResults.setAttribute('aria-busy', 'false');
            if (accountsFeedback) {
                accountsFeedback.innerHTML = '<div class="alert alert-danger mb-3" role="alert">' +
                    escapeHtml(error.message || 'Unable to load customer accounts.') + '</div>';
            }
        }
    }

    document.addEventListener('click', function (event) {
        var navigationLink = event.target.closest('a[data-accounts-navigation]');
        if (!navigationLink || event.defaultPrevented || event.button !== 0 ||
            event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        event.preventDefault();
        var navigationUrl;
        if (navigationLink.hasAttribute('data-status')) {
            navigationUrl = buildAccountsUrl({
                status: navigationLink.dataset.status,
                page: 1
            });
        } else {
            navigationUrl = new URL(navigationLink.href, window.location.href);
        }
        loadAccountsView(navigationUrl, true);
    });

    if (accountsSearchForm && accountsSearchInput) {
        accountsSearchForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var url = buildAccountsUrl({
                status: accountsSearchStatus ? accountsSearchStatus.value : 'customers',
                q: accountsSearchInput.value.trim(),
                page: 1
            });
            loadAccountsView(url, true);
        });
    }

    window.addEventListener('popstate', function () {
        loadAccountsView(window.location.href, false);
    });

    /* ---------------- Open modals from row buttons ---------------- */
    document.addEventListener('click', function (event) {
        var edit = event.target.closest('[data-account-edit]');
        var deleteButton = event.target.closest('[data-account-delete]');
        var restore = event.target.closest('[data-account-restore]');

        if (edit && editModal) {
            document.getElementById('editAccountId').value = edit.dataset.id || '';
            document.getElementById('editAccountName').value = edit.dataset.name || '';
            document.getElementById('editAccountEmail').value = edit.dataset.email || '';
            document.getElementById('editAccountPhone').value = edit.dataset.phone || '';
            openModal(editModal);
        }

        if (deleteButton && deleteModal) {
            document.getElementById('deleteAccountId').value = deleteButton.dataset.id || '';
            document.getElementById('deleteAccountName').textContent = deleteButton.dataset.name || 'This customer';
            openModal(deleteModal);
        }

        if (restore && restoreModal) {
            document.getElementById('restoreAccountId').value = restore.dataset.id || '';
            document.getElementById('restoreAccountName').textContent = restore.dataset.name || 'This customer';
            openModal(restoreModal);
        }
    });

    /* ---------------- Prevent double submits ---------------- */
    document.querySelectorAll('.modal form').forEach(function (form) {
        form.addEventListener('submit', function () {
            if (form.dataset.submitting === '1') {
                return;
            }
            form.dataset.submitting = '1';
            var button = form.querySelector('button[type="submit"]');
            if (button) {
                setTimeout(function () { button.disabled = true; }, 0);
            }
        });
    });

    /* ---------------- Show success toast after Edit/Delete/Restore ---------------- */
    var actionToast = document.getElementById('staffAccountsActionToast');
    if (actionToast) {
        if (window.bootstrap && window.bootstrap.Toast) {
            window.bootstrap.Toast.getOrCreateInstance(actionToast, {
                autohide: true,
                delay: 3500
            }).show();
        } else {
            actionToast.classList.add('show');
            window.setTimeout(function () {
                actionToast.classList.remove('show');
                actionToast.remove();
            }, 3500);
        }
    }
})();
</script>

</body>
</html>