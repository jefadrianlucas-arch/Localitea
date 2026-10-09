<?php
/*
|--------------------------------------------------------------------------
| PWD / SENIOR ID VERIFICATION REQUESTS (ADMIN ONLY)
|--------------------------------------------------------------------------
|
| Put this file in the same folder as orders.php (admin/).
|
| Customers upload their PWD / Senior ID once, when they sign up.
|
| Approve -> the account becomes "verified". From then on the customer can
|            use the 20% discount on every order, with no new upload.
| Reject  -> a reason is required. The account stays without a discount.
| Archive -> hides a reviewed record from the main list. The customer keeps
|            the discount and the ID photo is kept. Restore brings it back.
|
*/

session_start();

require_once '../includes/db.php';

if (
    !isset($_SESSION['user_id']) ||
    !in_array($_SESSION['user_role'] ?? '', ['admin'], true)
) {
    header('Location: ../auth/login.php');
    exit;
}

$admin_id = (int)$_SESSION['user_id'];

if (empty($_SESSION['dv_csrf'])) {
    $_SESSION['dv_csrf'] = bin2hex(random_bytes(16));
}

$csrf = $_SESSION['dv_csrf'];

/* Archive needs one extra column. The page still works without it. */
$archiveReady = false;

try {
    $archiveReady = (bool)$pdo
        ->query("SHOW COLUMNS FROM customers LIKE 'discount_id_archived'")
        ->fetch();
} catch (Throwable $e) {
    $archiveReady = false;
}

/*
|--------------------------------------------------------------------------
| APPROVE / REJECT
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $redirectMsg = 'error';

    $postedToken = (string)($_POST['csrf'] ?? '');
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $decision = (string)($_POST['decision'] ?? '');
    $reason = trim((string)($_POST['reason'] ?? ''));

    if (mb_strlen($reason) > 255) {
        $reason = mb_substr($reason, 0, 255);
    }

    /* Archive / restore only tidies this list. The customer keeps the discount. */
    if (in_array($decision, ['archive', 'restore'], true)) {

        if (
            !$archiveReady ||
            !hash_equals($csrf, $postedToken) ||
            $customerId <= 0
        ) {
            header('Location: discount-verifications.php?msg=' . ($archiveReady ? 'error' : 'noarchive'));
            exit;
        }

        try {

            $stmt = $pdo->prepare("
                UPDATE customers
                SET discount_id_archived = ?
                WHERE id = ?
                  AND verification_status IN ('verified', 'rejected')
            ");
            $stmt->execute([$decision === 'archive' ? 1 : 0, $customerId]);

            if ($stmt->rowCount() > 0) {
                $redirectMsg = $decision === 'archive' ? 'archived' : 'restored';
            } else {
                $redirectMsg = 'notfound';
            }

        } catch (Throwable $e) {

            error_log('Discount archive failed: ' . $e->getMessage());
            $redirectMsg = 'error';
        }

        header(
            'Location: discount-verifications.php?msg=' . $redirectMsg .
            ($redirectMsg === 'restored' ? '&view=archived' : '')
        );
        exit;
    }

    if (
        hash_equals($csrf, $postedToken) &&
        $customerId > 0 &&
        in_array($decision, ['approve', 'reject'], true) &&
        ($decision === 'approve' || $reason !== '')
    ) {
        try {

            if ($decision === 'approve') {

                $stmt = $pdo->prepare("
                    UPDATE customers
                    SET
                        verification_status = 'verified',
                        verification_rejection_reason = NULL,
                        verified_by = ?,
                        verified_at = NOW()
                    WHERE id = ?
                      AND verification_status = 'pending'
                ");
                $stmt->execute([$admin_id, $customerId]);

            } else {

                $stmt = $pdo->prepare("
                    UPDATE customers
                    SET
                        verification_status = 'rejected',
                        verification_rejection_reason = ?,
                        verified_by = ?,
                        verified_at = NOW()
                    WHERE id = ?
                      AND verification_status = 'pending'
                ");
                $stmt->execute([$reason, $admin_id, $customerId]);
            }

            if ($stmt->rowCount() > 0) {
                $redirectMsg = $decision === 'approve' ? 'approved' : 'rejected';
            } else {
                $redirectMsg = 'notfound';
            }

        } catch (Throwable $e) {

            error_log('Discount verification failed: ' . $e->getMessage());
            $redirectMsg = 'error';
        }
    } elseif ($decision === 'reject' && $reason === '') {
        $redirectMsg = 'reason';
    }

    header('Location: discount-verifications.php?msg=' . $redirectMsg);
    exit;
}

/*
|--------------------------------------------------------------------------
| LOAD DATA
|--------------------------------------------------------------------------
*/

$pending = $pdo->query("
    SELECT
        id, full_name, email, contact_number,
        discount_type, discount_id_name, discount_id_number, created_at
    FROM customers
    WHERE verification_status = 'pending'
      AND discount_id_image IS NOT NULL
      AND discount_id_image <> ''
    ORDER BY created_at ASC
")->fetchAll(PDO::FETCH_ASSOC);

$archiveFilter = $archiveReady ? 'AND discount_id_archived = 0' : '';

$reviewed = $pdo->query("
    SELECT
        id, full_name, discount_type, discount_id_name, discount_id_number,
        verification_status, verification_rejection_reason, verified_at,
        0 AS is_archived_row
    FROM customers
    WHERE verification_status IN ('verified', 'rejected')
      AND discount_id_image IS NOT NULL
      AND discount_id_image <> ''
      {$archiveFilter}
    ORDER BY verified_at DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

$archivedRows = [];

if ($archiveReady) {
    $archivedRows = $pdo->query("
        SELECT
            id, full_name, discount_type, discount_id_name, discount_id_number,
            verification_status, verification_rejection_reason, verified_at,
            1 AS is_archived_row
        FROM customers
        WHERE verification_status IN ('verified', 'rejected')
          AND discount_id_image IS NOT NULL
          AND discount_id_image <> ''
          AND discount_id_archived = 1
        ORDER BY verified_at DESC
        LIMIT 50
    ")->fetchAll(PDO::FETCH_ASSOC);
}

$allReviewed = array_merge($reviewed, $archivedRows);

$counts = ['pending' => count($pending), 'verified' => 0, 'rejected' => 0];

$countRows = $pdo->query("
    SELECT verification_status, COUNT(*) AS total
    FROM customers
    WHERE verification_status IN ('verified', 'rejected')
      AND discount_id_image IS NOT NULL
      AND discount_id_image <> ''
    GROUP BY verification_status
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($countRows as $countRow) {
    $counts[$countRow['verification_status']] = (int)$countRow['total'];
}

$messages = [
    'approved' => ['success', 'ID approved. The customer can now use the PWD / Senior discount.'],
    'rejected' => ['warning', 'ID rejected. The customer will not get the discount.'],
    'reason'   => ['danger', 'Please enter a reason when rejecting an ID.'],
    'notfound' => ['danger', 'That request was already reviewed or no longer exists.'],
    'archived' => ['success', 'Record archived. The customer keeps the discount and the ID photo is kept.'],
    'restored' => ['success', 'Record restored to the main list.'],
    'noarchive' => ['danger', 'Archive is not set up yet. Run the archive SQL first.'],
    'error'    => ['danger', 'Something went wrong. Please try again.']
];

$flash = $messages[$_GET['msg'] ?? ''] ?? null;

$flashIcons = [
    'success' => 'bi-check-circle-fill',
    'warning' => 'bi-exclamation-triangle-fill',
    'danger'  => 'bi-x-octagon-fill'
];

function dvE($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function dvTypeLabel(string $type): string
{
    return $type === 'pwd' ? 'PWD' : ($type === 'senior' ? 'Senior Citizen' : '-');
}

function dvNormalizeName(string $name): string
{
    return mb_strtolower(trim(preg_replace('/\s+/', ' ', $name)));
}

function dvAgo(?string $datetime): string
{
    $time = $datetime ? strtotime($datetime) : false;

    if (!$time) {
        return '-';
    }

    $diff = time() - $time;

    if ($diff < 60) {
        return 'Just now';
    }

    if ($diff < 3600) {
        return floor($diff / 60) . ' min ago';
    }

    if ($diff < 86400) {
        return floor($diff / 3600) . ' hr ago';
    }

    return date('M j, Y g:i A', $time);
}

function dvDate(?string $datetime): string
{
    $time = $datetime ? strtotime($datetime) : false;

    return $time ? date('M j, Y g:i A', $time) : '-';
}

require_once '../includes/header.php';
?>

<link
    rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
>

<style>
    body { background: #F7F5F2; }

    .dv-page {
        padding: 28px 28px 48px;
        color: #2C221E;
    }

    /* ---------- Header ---------- */
    .dv-header {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-end;
        justify-content: space-between;
        gap: 18px;
        margin-bottom: 22px;
    }

    .dv-title {
        display: flex;
        align-items: center;
        gap: 12px;
        margin: 0 0 4px;
        color: #4A3525;
        font-size: 1.55rem;
        font-weight: 700;
    }

    .dv-title-icon {
        width: 42px;
        height: 42px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 12px;
        background: #4A3525;
        color: #FFFFFF;
        font-size: 1.15rem;
    }

    .dv-sub {
        margin: 0;
        max-width: 560px;
        color: #7A6A5E;
        font-size: .86rem;
        line-height: 1.5;
    }

    /* ---------- Stats ---------- */
    .dv-stats {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
    }

    .dv-stat {
        min-width: 112px;
        padding: 10px 16px;
        background: #FFFFFF;
        border: 1px solid #E4D7CB;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(74, 53, 37, .05);
    }

    .dv-stat-number {
        display: block;
        font-size: 1.45rem;
        font-weight: 700;
        line-height: 1.1;
        color: #4A3525;
    }

    .dv-stat-label {
        display: block;
        margin-top: 2px;
        font-size: .7rem;
        font-weight: 600;
        letter-spacing: .04em;
        text-transform: uppercase;
        color: #8A7767;
    }

    .dv-stat--pending { border-left: 4px solid #C98A1B; }
    .dv-stat--verified { border-left: 4px solid #2F7D4F; }
    .dv-stat--rejected { border-left: 4px solid #8B3030; }

    /* ---------- Flash ---------- */
    .dv-flash {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 20px;
        padding: 12px 14px;
        border-radius: 12px;
        border: 1px solid;
        font-size: .86rem;
        font-weight: 500;
    }

    .dv-flash span { flex: 1 1 auto; }

    .dv-flash-success { background: #EAF5EE; border-color: #BFE0CB; color: #1F6B3F; }
    .dv-flash-warning { background: #FFF5DD; border-color: #EBD399; color: #805400; }
    .dv-flash-danger  { background: #FDEDED; border-color: #EDBDBD; color: #8B3030; }

    .dv-flash-close {
        border: 0;
        background: transparent;
        color: inherit;
        opacity: .7;
        cursor: pointer;
        padding: 2px 6px;
    }

    .dv-flash-close:hover { opacity: 1; }

    /* ---------- Section title ---------- */
    .dv-section-title {
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 0 0 14px;
        color: #4A3525;
        font-size: 1.05rem;
        font-weight: 600;
    }

    .dv-count-pill {
        padding: 2px 10px;
        border-radius: 999px;
        background: #4A3525;
        color: #FFFFFF;
        font-size: .72rem;
        font-weight: 600;
    }

    /* ---------- Pending cards ---------- */
    .dv-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
        gap: 18px;
        margin-bottom: 40px;
    }

    .dv-card {
        display: flex;
        flex-direction: column;
        background: #FFFFFF;
        border: 1px solid #E4D7CB;
        border-radius: 16px;
        box-shadow: 0 4px 14px rgba(74, 53, 37, .06);
        overflow: hidden;
    }

    .dv-card-head {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 14px 16px;
        background: #FBF8F4;
        border-bottom: 1px solid #EFE6DC;
    }

    .dv-avatar {
        flex: 0 0 auto;
        width: 40px;
        height: 40px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        background: #6F4E37;
        color: #FFFFFF;
        font-weight: 600;
    }

    .dv-who { min-width: 0; flex: 1 1 auto; }

    .dv-who-name {
        font-weight: 600;
        color: #2C221E;
        line-height: 1.2;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .dv-who-email {
        font-size: .76rem;
        color: #8A7767;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .dv-badge {
        flex: 0 0 auto;
        padding: 4px 10px;
        border-radius: 999px;
        font-size: .7rem;
        font-weight: 600;
        letter-spacing: .02em;
        white-space: nowrap;
    }

    .dv-badge--pwd { background: #E7F0FB; color: #1F4E8C; }
    .dv-badge--senior { background: #F3E9FA; color: #6A3A8C; }
    .dv-badge--verified { background: #E8F4EC; color: #1F6B3F; }
    .dv-badge--rejected { background: #FDEDED; color: #8B3030; }

    .dv-card-body {
        display: grid;
        grid-template-columns: minmax(130px, 42%) 1fr;
        gap: 16px;
        padding: 16px;
    }

    /* ID photo */
    .dv-photo {
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        min-height: 150px;
        padding: 0;
        border: 1px solid #E4D7CB;
        border-radius: 12px;
        background: #FAF7F3;
        overflow: hidden;
        cursor: zoom-in;
    }

    .dv-photo img {
        display: block;
        width: 100%;
        height: 100%;
        max-height: 210px;
        object-fit: contain;
    }

    .dv-photo-hint {
        position: absolute;
        left: 0;
        right: 0;
        bottom: 0;
        padding: 6px 8px;
        background: linear-gradient(transparent, rgba(44, 34, 30, .72));
        color: #FFFFFF;
        font-size: .7rem;
        font-weight: 600;
        text-align: center;
        opacity: 0;
        transition: opacity .2s ease;
    }

    .dv-photo:hover .dv-photo-hint,
    .dv-photo:focus-visible .dv-photo-hint { opacity: 1; }

    .dv-photo:focus-visible {
        outline: 3px solid #C69C6D;
        outline-offset: 2px;
    }

    .dv-photo-missing {
        display: none;
        padding: 12px;
        text-align: center;
        color: #8A7767;
        font-size: .76rem;
    }

    .dv-photo.is-broken img,
    .dv-photo.is-broken .dv-photo-hint { display: none; }
    .dv-photo.is-broken .dv-photo-missing { display: block; }

    /* Details */
    .dv-details {
        display: grid;
        gap: 10px;
        margin: 0;
        align-content: start;
    }

    .dv-detail dt {
        margin: 0;
        font-size: .68rem;
        font-weight: 600;
        letter-spacing: .05em;
        text-transform: uppercase;
        color: #8A7767;
    }

    .dv-detail dd {
        margin: 1px 0 0;
        font-weight: 600;
        color: #4A3525;
        word-break: break-word;
    }

    .dv-match {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        margin-top: 4px;
        padding: 3px 9px;
        border-radius: 999px;
        font-size: .68rem;
        font-weight: 600;
    }

    .dv-match--yes { background: #E8F4EC; color: #1F6B3F; }
    .dv-match--no  { background: #FFF4DC; color: #805400; }

    /* Actions */
    .dv-actions {
        padding: 14px 16px 16px;
        border-top: 1px solid #EFE6DC;
        margin-top: auto;
    }

    .dv-btn-row {
        display: flex;
        gap: 10px;
    }

    .dv-btn-row form { flex: 1 1 0; margin: 0; }

    .dv-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        width: 100%;
        min-height: 40px;
        padding: 8px 14px;
        border-radius: 10px;
        border: 1.5px solid transparent;
        font-size: .84rem;
        font-weight: 600;
        cursor: pointer;
        transition: background-color .15s ease, border-color .15s ease, color .15s ease;
    }

    .dv-btn--approve { background: #2F7D4F; color: #FFFFFF; }
    .dv-btn--approve:hover { background: #266641; }

    .dv-btn--reject { background: #FFFFFF; border-color: #D9A3A3; color: #8B3030; }
    .dv-btn--reject:hover { background: #FDEDED; border-color: #8B3030; }

    .dv-btn--confirm-reject { background: #8B3030; color: #FFFFFF; }
    .dv-btn--confirm-reject:hover { background: #702424; }

    .dv-btn--ghost { background: #FFFFFF; border-color: #E4D7CB; color: #4A3525; }
    .dv-btn--ghost:hover { background: #FBF8F4; }

    .dv-btn:focus-visible { outline: 3px solid #C69C6D; outline-offset: 2px; }

    .dv-reject-box {
        margin-top: 12px;
        padding: 12px;
        border: 1px solid #EDBDBD;
        border-radius: 12px;
        background: #FFF8F8;
    }

    .dv-reject-box[hidden] { display: none; }

    .dv-reject-box label {
        display: block;
        margin-bottom: 6px;
        font-size: .74rem;
        font-weight: 600;
        color: #8B3030;
    }

    .dv-reject-box input[type="text"] {
        width: 100%;
        min-height: 40px;
        padding: 8px 12px;
        border: 1.5px solid #E4D7CB;
        border-radius: 8px;
        background: #FFFFFF;
        font-size: .84rem;
        box-sizing: border-box;
    }

    .dv-reject-box input[type="text"]:focus {
        outline: none;
        border-color: #8B3030;
        box-shadow: 0 0 0 3px rgba(139, 48, 48, .12);
    }

    .dv-reject-actions { display: flex; gap: 10px; margin-top: 10px; }

    /* ---------- Empty state ---------- */
    .dv-empty {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 6px;
        margin-bottom: 40px;
        padding: 40px 20px;
        background: #FFFFFF;
        border: 1.5px dashed #D9C9BA;
        border-radius: 16px;
        text-align: center;
        color: #8A7767;
    }

    .dv-empty i { font-size: 2.2rem; color: #2F7D4F; }
    .dv-empty strong { color: #4A3525; font-size: 1rem; }

    /* ---------- Reviewed table ---------- */
    .dv-table-head {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 14px;
    }

    .dv-table-head .dv-section-title { margin: 0; }

    .dv-filters { display: inline-flex; gap: 6px; }

    .dv-filter {
        padding: 6px 14px;
        border: 1.5px solid #E4D7CB;
        border-radius: 999px;
        background: #FFFFFF;
        color: #4A3525;
        font-size: .76rem;
        font-weight: 600;
        cursor: pointer;
    }

    .dv-filter:hover { background: #FBF8F4; }

    .dv-filter.is-active {
        background: #4A3525;
        border-color: #4A3525;
        color: #FFFFFF;
    }

    .dv-table-card {
        background: #FFFFFF;
        border: 1px solid #E4D7CB;
        border-radius: 16px;
        box-shadow: 0 4px 14px rgba(74, 53, 37, .06);
        overflow: hidden;
    }

    .dv-table-scroll { overflow-x: auto; }

    .dv-table {
        width: 100%;
        min-width: 820px;
        border-collapse: collapse;
        font-size: .84rem;
    }

    .dv-table th {
        padding: 12px 16px;
        background: #FBF8F4;
        border-bottom: 1px solid #EFE6DC;
        text-align: left;
        font-size: .68rem;
        font-weight: 600;
        letter-spacing: .05em;
        text-transform: uppercase;
        color: #8A7767;
        white-space: nowrap;
    }

    .dv-table td {
        padding: 12px 16px;
        border-bottom: 1px solid #F3EBE2;
        color: #4A3525;
        vertical-align: middle;
    }

    .dv-table tbody tr:last-child td { border-bottom: 0; }
    .dv-table tbody tr:hover { background: #FDFBF8; }

    .dv-table .dv-muted { color: #8A7767; font-size: .76rem; }

    .dv-link {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        color: #6F4E37;
        font-weight: 600;
        text-decoration: none;
    }

    .dv-link:hover { color: #4A3525; text-decoration: underline; }

    .dv-table-empty { padding: 28px 16px; text-align: center; color: #8A7767; }

    /* ---------- Archive ---------- */
    .dv-row-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 34px;
        height: 34px;
        padding: 0;
        border: 1.5px solid #E4D7CB;
        border-radius: 8px;
        background: #FFFFFF;
        color: #4A3525;
        font-size: 1rem;
        cursor: pointer;
    }

    .dv-row-btn:hover { background: #FBF8F4; border-color: #B8A08A; }
    .dv-row-btn:focus-visible { outline: 3px solid #C69C6D; outline-offset: 2px; }

    .dv-row-form { display: inline; margin: 0; }

    .dv-hint {
        margin: -4px 0 14px;
        color: #8A7767;
        font-size: .78rem;
    }

    .dv-flash code {
        display: inline-block;
        margin-top: 4px;
        padding: 2px 6px;
        border-radius: 6px;
        background: rgba(0, 0, 0, .06);
        color: inherit;
        font-size: .76rem;
        word-break: break-word;
    }

    /* ---------- Lightbox ---------- */
    .dv-lightbox {
        position: fixed;
        inset: 0;
        z-index: 3000;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 20px;
        background: rgba(44, 34, 30, .82);
    }

    .dv-lightbox.is-open { display: flex; }

    .dv-lightbox-inner {
        position: relative;
        max-width: min(920px, 100%);
        max-height: 100%;
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .dv-lightbox img {
        display: block;
        max-width: 100%;
        max-height: calc(100vh - 110px);
        margin: 0 auto;
        border-radius: 12px;
        background: #FFFFFF;
        box-shadow: 0 18px 50px rgba(0, 0, 0, .4);
    }

    .dv-lightbox-caption {
        text-align: center;
        color: #FFFFFF;
        font-size: .84rem;
        font-weight: 600;
    }

    .dv-lightbox-close {
        position: absolute;
        top: -14px;
        right: -14px;
        width: 36px;
        height: 36px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 0;
        border-radius: 50%;
        background: #FFFFFF;
        color: #4A3525;
        box-shadow: 0 4px 12px rgba(0, 0, 0, .3);
        cursor: pointer;
    }

    /* ---------- Responsive ---------- */
    @media (max-width: 991.98px) {
        .dv-page { padding: 68px 16px 40px; }
    }

    @media (max-width: 575.98px) {
        .dv-grid { grid-template-columns: 1fr; }
        .dv-card-body { grid-template-columns: 1fr; }
        .dv-stat { flex: 1 1 100px; }
        .dv-lightbox-close { top: -10px; right: -6px; }
    }

    /* default <strong> is too heavy */
    .dv-page strong { font-weight: 600; }
</style>

<div class="admin-orders-page">

    <?php require_once 'sidebar.php'; ?>

    <?php require_once 'navbar.php'; ?>

    <main class="admin-main admin-content">
        <div class="dv-page">

            <header class="dv-header">
                <div>
                    <h1 class="dv-title">
                        <span class="dv-title-icon"><i class="bi bi-patch-check-fill"></i></span>
                        ID Verification
                    </h1>
                    <p class="dv-sub">
                        Compare the ID photo with the name and ID number the customer entered at
                        sign-up, then approve or reject it. This is done only once per customer.
                    </p>
                </div>

                <div class="dv-stats">
                    <div class="dv-stat dv-stat--pending">
                        <span class="dv-stat-number"><?= (int)$counts['pending'] ?></span>
                        <span class="dv-stat-label">Pending</span>
                    </div>
                    <div class="dv-stat dv-stat--verified">
                        <span class="dv-stat-number"><?= (int)$counts['verified'] ?></span>
                        <span class="dv-stat-label">Verified</span>
                    </div>
                    <div class="dv-stat dv-stat--rejected">
                        <span class="dv-stat-number"><?= (int)$counts['rejected'] ?></span>
                        <span class="dv-stat-label">Rejected</span>
                    </div>
                </div>
            </header>

            <?php if ($flash): ?>
                <div class="dv-flash dv-flash-<?= dvE($flash[0]) ?>" id="dvFlash" role="status">
                    <i class="bi <?= dvE($flashIcons[$flash[0]] ?? 'bi-info-circle-fill') ?>"></i>
                    <span><?= dvE($flash[1]) ?></span>
                    <button type="button" class="dv-flash-close" id="dvFlashClose" aria-label="Dismiss">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
            <?php endif; ?>

            <?php if (!$archiveReady): ?>
                <div class="dv-flash dv-flash-warning" role="status">
                    <i class="bi bi-info-circle-fill"></i>
                    <span>
                        To turn on <strong>Archive</strong>, run this SQL once in phpMyAdmin:<br>
                        <code>ALTER TABLE `customers` ADD `discount_id_archived` TINYINT(1) NOT NULL DEFAULT 0;</code>
                    </span>
                </div>
            <?php endif; ?>

            <!-- ================= PENDING ================= -->
            <h2 class="dv-section-title">
                Waiting for review
                <span class="dv-count-pill"><?= count($pending) ?></span>
            </h2>

            <?php if (!$pending): ?>

                <div class="dv-empty">
                    <i class="bi bi-check2-circle"></i>
                    <strong>You're all caught up</strong>
                    <span>There are no PWD / Senior Citizen IDs waiting for review.</span>
                </div>

            <?php else: ?>

                <div class="dv-grid">
                    <?php foreach ($pending as $row): ?>
                        <?php
                            $rowId = (int)$row['id'];
                            $type = (string)$row['discount_type'];
                            $initial = mb_strtoupper(mb_substr(trim((string)$row['full_name']) ?: '?', 0, 1));
                            $nameMatches =
                                dvNormalizeName((string)$row['full_name']) ===
                                dvNormalizeName((string)$row['discount_id_name']);
                            $photoUrl = 'view-discount-id.php?customer=' . $rowId;
                        ?>
                        <article class="dv-card">

                            <div class="dv-card-head">
                                <span class="dv-avatar"><?= dvE($initial) ?></span>

                                <div class="dv-who">
                                    <div class="dv-who-name"><?= dvE($row['full_name']) ?></div>
                                    <div class="dv-who-email"><?= dvE($row['email']) ?></div>
                                </div>

                                <span class="dv-badge dv-badge--<?= $type === 'pwd' ? 'pwd' : 'senior' ?>">
                                    <?= dvE(dvTypeLabel($type)) ?>
                                </span>
                            </div>

                            <div class="dv-card-body">

                                <button
                                    type="button"
                                    class="dv-photo"
                                    data-dv-zoom
                                    data-src="<?= dvE($photoUrl) ?>"
                                    data-caption="<?= dvE($row['full_name'] . ' - ' . dvTypeLabel($type) . ' ID') ?>"
                                    aria-label="Enlarge ID photo of <?= dvE($row['full_name']) ?>"
                                >
                                    <img src="<?= dvE($photoUrl) ?>" alt="ID photo" loading="lazy">
                                    <span class="dv-photo-missing">
                                        <i class="bi bi-image"></i><br>Photo unavailable
                                    </span>
                                    <span class="dv-photo-hint">
                                        <i class="bi bi-zoom-in"></i> Click to enlarge
                                    </span>
                                </button>

                                <dl class="dv-details">
                                    <div class="dv-detail">
                                        <dt>Name on ID</dt>
                                        <dd><?= dvE($row['discount_id_name']) ?></dd>
                                        <?php if ($nameMatches): ?>
                                            <span class="dv-match dv-match--yes">
                                                <i class="bi bi-check-circle-fill"></i> Matches account name
                                            </span>
                                        <?php else: ?>
                                            <span class="dv-match dv-match--no">
                                                <i class="bi bi-exclamation-circle-fill"></i> Differs from account name
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="dv-detail">
                                        <dt>ID number</dt>
                                        <dd><?= dvE($row['discount_id_number']) ?></dd>
                                    </div>

                                    <div class="dv-detail">
                                        <dt>Mobile</dt>
                                        <dd><?= dvE($row['contact_number']) ?></dd>
                                    </div>

                                    <div class="dv-detail">
                                        <dt>Submitted</dt>
                                        <dd><?= dvE(dvAgo($row['created_at'])) ?></dd>
                                    </div>
                                </dl>

                            </div>

                            <div class="dv-actions">

                                <div class="dv-btn-row">
                                    <form method="POST" data-dv-confirm="Approve this ID for <?= dvE($row['full_name']) ?>?">
                                        <input type="hidden" name="csrf" value="<?= dvE($csrf) ?>">
                                        <input type="hidden" name="customer_id" value="<?= $rowId ?>">
                                        <input type="hidden" name="decision" value="approve">
                                        <button type="submit" class="dv-btn dv-btn--approve">
                                            <i class="bi bi-check-circle-fill"></i> Approve
                                        </button>
                                    </form>

                                    <button
                                        type="button"
                                        class="dv-btn dv-btn--reject"
                                        data-dv-reject-toggle="dvReject<?= $rowId ?>"
                                        aria-expanded="false"
                                        aria-controls="dvReject<?= $rowId ?>"
                                    >
                                        <i class="bi bi-x-circle"></i> Reject
                                    </button>
                                </div>

                                <form method="POST" class="dv-reject-box" id="dvReject<?= $rowId ?>" hidden>
                                    <input type="hidden" name="csrf" value="<?= dvE($csrf) ?>">
                                    <input type="hidden" name="customer_id" value="<?= $rowId ?>">
                                    <input type="hidden" name="decision" value="reject">

                                    <label for="dvReason<?= $rowId ?>">Reason for rejecting (the customer can see this)</label>
                                    <input
                                        type="text"
                                        id="dvReason<?= $rowId ?>"
                                        name="reason"
                                        maxlength="255"
                                        placeholder="e.g. The photo is blurry or the ID number does not match"
                                        required
                                    >

                                    <div class="dv-reject-actions">
                                        <button type="button" class="dv-btn dv-btn--ghost" data-dv-reject-cancel="dvReject<?= $rowId ?>">
                                            Cancel
                                        </button>
                                        <button type="submit" class="dv-btn dv-btn--confirm-reject">
                                            <i class="bi bi-x-circle-fill"></i> Confirm reject
                                        </button>
                                    </div>
                                </form>

                            </div>

                        </article>
                    <?php endforeach; ?>
                </div>

            <?php endif; ?>

            <!-- ================= REVIEWED ================= -->
            <div class="dv-table-head">
                <h2 class="dv-section-title">Recently reviewed</h2>

                <div class="dv-filters" role="group" aria-label="Filter reviewed IDs">
                    <button type="button" class="dv-filter is-active" data-dv-filter="all">All</button>
                    <button type="button" class="dv-filter" data-dv-filter="verified">Verified</button>
                    <button type="button" class="dv-filter" data-dv-filter="rejected">Rejected</button>
                    <?php if ($archiveReady): ?>
                        <button type="button" class="dv-filter" data-dv-filter="archived">
                            Archived<?= $archivedRows ? ' (' . count($archivedRows) . ')' : '' ?>
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($archiveReady): ?>
                <p class="dv-hint">
                    Archive hides a record from this list. The customer stays verified and the ID photo is kept.
                </p>
            <?php endif; ?>

            <div class="dv-table-card">
                <div class="dv-table-scroll">
                    <table class="dv-table">
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th>Name on ID</th>
                                <th>Type</th>
                                <th>ID number</th>
                                <th>Result</th>
                                <th>Reviewed</th>
                                <th>Photo</th>
                                <?php if ($archiveReady): ?><th>Action</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody id="dvReviewedBody">
                            <?php foreach ($allReviewed as $row): ?>
                                <?php
                                    $status = (string)$row['verification_status'];
                                    $isArchivedRow = (int)$row['is_archived_row'] === 1;
                                ?>
                                <tr
                                    data-dv-status="<?= dvE($status) ?>"
                                    data-dv-archived="<?= $isArchivedRow ? '1' : '0' ?>"
                                    <?= $isArchivedRow ? 'hidden' : '' ?>
                                >
                                    <td><strong><?= dvE($row['full_name']) ?></strong></td>
                                    <td><?= dvE($row['discount_id_name']) ?></td>
                                    <td>
                                        <span class="dv-badge dv-badge--<?= $row['discount_type'] === 'pwd' ? 'pwd' : 'senior' ?>">
                                            <?= dvE(dvTypeLabel((string)$row['discount_type'])) ?>
                                        </span>
                                    </td>
                                    <td><?= dvE($row['discount_id_number']) ?></td>
                                    <td>
                                        <?php if ($status === 'verified'): ?>
                                            <span class="dv-badge dv-badge--verified">
                                                <i class="bi bi-check-circle-fill"></i> Verified
                                            </span>
                                        <?php else: ?>
                                            <span class="dv-badge dv-badge--rejected">
                                                <i class="bi bi-x-circle-fill"></i> Rejected
                                            </span>
                                            <?php if (!empty($row['verification_rejection_reason'])): ?>
                                                <div class="dv-muted"><?= dvE($row['verification_rejection_reason']) ?></div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="dv-muted"><?= dvE(dvDate($row['verified_at'])) ?></td>
                                    <td>
                                        <a
                                            class="dv-link"
                                            href="#"
                                            data-dv-zoom
                                            data-src="view-discount-id.php?customer=<?= (int)$row['id'] ?>"
                                            data-caption="<?= dvE($row['full_name'] . ' - ' . dvTypeLabel((string)$row['discount_type']) . ' ID') ?>"
                                        >
                                            <i class="bi bi-image"></i> View
                                        </a>
                                    </td>
                                    <?php if ($archiveReady): ?>
                                        <td>
                                            <?php if ($isArchivedRow): ?>
                                                <form method="POST" class="dv-row-form">
                                                    <input type="hidden" name="csrf" value="<?= dvE($csrf) ?>">
                                                    <input type="hidden" name="customer_id" value="<?= (int)$row['id'] ?>">
                                                    <input type="hidden" name="decision" value="restore">
                                                    <button type="submit" class="dv-row-btn">
                                                        <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
                                                        <span class="visually-hidden">Restore</span>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <form
                                                    method="POST"
                                                    class="dv-row-form"
                                                    data-dv-confirm="Archive this record? <?= dvE($row['full_name']) ?> keeps the discount."
                                                >
                                                    <input type="hidden" name="csrf" value="<?= dvE($csrf) ?>">
                                                    <input type="hidden" name="customer_id" value="<?= (int)$row['id'] ?>">
                                                    <input type="hidden" name="decision" value="archive">
                                                    <button type="submit" class="dv-row-btn">
                                                        <i class="bi bi-archive" aria-hidden="true"></i>
                                                        <span class="visually-hidden">Archive</span>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="dv-table-empty" id="dvReviewedEmpty" hidden>
                    Nothing reviewed yet.
                </div>
            </div>

        </div>
    </main>

</div>

<!-- ID photo viewer -->
<div class="dv-lightbox" id="dvLightbox" aria-hidden="true">
    <div class="dv-lightbox-inner" role="dialog" aria-modal="true" aria-label="ID photo">
        <button type="button" class="dv-lightbox-close" id="dvLightboxClose" aria-label="Close">
            <i class="bi bi-x-lg"></i>
        </button>
        <img id="dvLightboxImage" src="" alt="ID photo">
        <div class="dv-lightbox-caption" id="dvLightboxCaption"></div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    /* ---------- Flash message ---------- */
    var flash = document.getElementById('dvFlash');
    var flashClose = document.getElementById('dvFlashClose');

    if (flash && flashClose) {
        flashClose.addEventListener('click', function () { flash.remove(); });
        setTimeout(function () { if (flash.parentNode) { flash.remove(); } }, 7000);
    }

    /* ---------- Broken photo fallback ---------- */
    document.querySelectorAll('.dv-photo img').forEach(function (img) {
        img.addEventListener('error', function () {
            img.closest('.dv-photo').classList.add('is-broken');
        });
    });

    /* ---------- Approve confirmation ---------- */
    document.querySelectorAll('form[data-dv-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.confirm(form.getAttribute('data-dv-confirm'))) {
                event.preventDefault();
            }
        });
    });

    /* ---------- Reject box ---------- */
    function setRejectOpen(id, open) {
        var box = document.getElementById(id);
        var toggle = document.querySelector('[data-dv-reject-toggle="' + id + '"]');

        if (!box) { return; }

        box.hidden = !open;

        if (toggle) {
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        if (open) {
            var input = box.querySelector('input[type="text"]');
            if (input) { input.focus(); }
        }
    }

    document.querySelectorAll('[data-dv-reject-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            var id = button.getAttribute('data-dv-reject-toggle');
            var box = document.getElementById(id);
            setRejectOpen(id, box ? box.hidden : false);
        });
    });

    document.querySelectorAll('[data-dv-reject-cancel]').forEach(function (button) {
        button.addEventListener('click', function () {
            setRejectOpen(button.getAttribute('data-dv-reject-cancel'), false);
        });
    });

    /* ---------- Reviewed filter ---------- */
    var filterButtons = document.querySelectorAll('[data-dv-filter]');
    var rows = document.querySelectorAll('#dvReviewedBody tr');
    var emptyBox = document.getElementById('dvReviewedEmpty');

    function applyFilter(wanted) {
        var shown = 0;

        filterButtons.forEach(function (b) {
            b.classList.toggle('is-active', b.getAttribute('data-dv-filter') === wanted);
        });

        rows.forEach(function (row) {
            var archived = row.getAttribute('data-dv-archived') === '1';
            var status = row.getAttribute('data-dv-status');
            var match = wanted === 'archived'
                ? archived
                : (!archived && (wanted === 'all' || status === wanted));

            row.hidden = !match;

            if (match) { shown++; }
        });

        if (emptyBox) {
            emptyBox.hidden = shown > 0;

            if (wanted === 'archived') {
                emptyBox.textContent = 'Nothing archived yet.';
            } else if (wanted === 'all' && !rows.length) {
                emptyBox.textContent = 'Nothing reviewed yet.';
            } else {
                emptyBox.textContent = 'No results for this filter.';
            }
        }
    }

    filterButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            applyFilter(button.getAttribute('data-dv-filter'));
        });
    });

    var startView = new URLSearchParams(window.location.search).get('view');
    var hasArchivedTab = !!document.querySelector('[data-dv-filter="archived"]');

    applyFilter(startView === 'archived' && hasArchivedTab ? 'archived' : 'all');

    /* ---------- ID photo viewer ---------- */
    var lightbox = document.getElementById('dvLightbox');
    var lightboxImage = document.getElementById('dvLightboxImage');
    var lightboxCaption = document.getElementById('dvLightboxCaption');
    var lightboxClose = document.getElementById('dvLightboxClose');
    var lastTrigger = null;

    function openLightbox(trigger) {
        lastTrigger = trigger;
        lightboxImage.src = trigger.getAttribute('data-src');
        lightboxCaption.textContent = trigger.getAttribute('data-caption') || '';
        lightbox.classList.add('is-open');
        lightbox.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        lightboxClose.focus();
    }

    function closeLightbox() {
        lightbox.classList.remove('is-open');
        lightbox.setAttribute('aria-hidden', 'true');
        lightboxImage.src = '';
        document.body.style.overflow = '';

        if (lastTrigger) {
            lastTrigger.focus();
        }
    }

    document.querySelectorAll('[data-dv-zoom]').forEach(function (trigger) {
        trigger.addEventListener('click', function (event) {
            event.preventDefault();
            openLightbox(trigger);
        });
    });

    lightboxClose.addEventListener('click', closeLightbox);

    lightbox.addEventListener('click', function (event) {
        if (event.target === lightbox) {
            closeLightbox();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && lightbox.classList.contains('is-open')) {
            closeLightbox();
        }
    });
});
</script>