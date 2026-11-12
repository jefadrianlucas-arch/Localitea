<?php
session_start();
date_default_timezone_set('Asia/Manila');

/* Guest order details contain private information: do not cache or leak referrers. */
header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow, noarchive');

require_once '../includes/db.php';

/* [COMBINED LINK] CHANGE 1 of 5 — helper: parse "CLM-0001.<64-hex>,CLM-0002.<64-hex>" (max 10 pairs). */
function parseGuestOrderBundle(string $raw): array
{
    $pairs = [];
    $seen = [];
    foreach (explode(',', $raw) as $part) {
        $part = trim($part);
        if ($part === '' || strlen($part) > 130) {
            continue;
        }
        if (preg_match('/\A(.{1,50})\.([a-f0-9]{64})\z/iD', $part, $m) !== 1) {
            continue;
        }
        $token = strtolower($m[2]);
        if (trim($m[1]) === '' || isset($seen[$token])) {
            continue;
        }
        $seen[$token] = true;
        $pairs[] = ['claim' => trim($m[1]), 'token' => $token];
        if (count($pairs) >= 10) {
            break;
        }
    }
    return $pairs;
}

/*
 * Guest order access requires BOTH the claim number and a high-entropy
 * bearer token issued by checkout. Claim numbers are references, not secrets.
 */
$requestedClaimNumber = trim((string)(
    $_GET['claim_number']
    ?? $_GET['claim']
    ?? $_POST['claim_number']
    ?? ''
));
$requestedClaimNumber = substr($requestedClaimNumber, 0, 50);

/* [COMBINED LINK] CHANGE 2a of 5 — combined link parameter (?orders=...). */
$bundleRaw = substr(trim((string)($_GET['orders'] ?? $_POST['orders'] ?? '')), 0, 1600);

/*
 * The lookup field accepts either the raw 64-character access code OR the
 * complete secure order-status URL copied from the order status page. When a URL
 * is supplied, extract its claim and access token; never redirect to that URL.
 */
$rawTrackingInput = trim((string)(
    $_GET['track']
    ?? $_GET['tracking_token']
    ?? $_POST['tracking_token']
    ?? $_POST['track']
    ?? ''
));
$requestedTrackingToken = strtolower($rawTrackingInput);

if (
    $rawTrackingInput !== '' &&
    preg_match('/\A[a-f0-9]{64}\z/iD', $rawTrackingInput) !== 1 &&
    strlen($rawTrackingInput) <= 4096
) {
    $trackingLinkQuery = '';
    $trackingLinkParts = @parse_url($rawTrackingInput);
    if (is_array($trackingLinkParts) && isset($trackingLinkParts['query'])) {
        $trackingLinkQuery = (string)$trackingLinkParts['query'];
    } elseif (strpos($rawTrackingInput, 'track=') !== false) {
        $queryPosition = strpos($rawTrackingInput, '?');
        $trackingLinkQuery = $queryPosition === false
            ? $rawTrackingInput
            : substr($rawTrackingInput, $queryPosition + 1);
    }

    if ($trackingLinkQuery !== '') {
        $trackingLinkParams = [];
        parse_str($trackingLinkQuery, $trackingLinkParams);

        /* [COMBINED LINK] CHANGE 2b of 5 — pasted full URL that contains ?orders=... */
        if ($bundleRaw === '' && isset($trackingLinkParams['orders'])) {
            $bundleRaw = substr(trim((string)$trackingLinkParams['orders']), 0, 1600);
        }

        $linkToken = strtolower(trim((string)(
            $trackingLinkParams['track']
            ?? $trackingLinkParams['tracking_token']
            ?? ''
        )));
        $linkClaim = trim((string)(
            $trackingLinkParams['claim']
            ?? $trackingLinkParams['claim_number']
            ?? ''
        ));

        if (preg_match('/\A[a-f0-9]{64}\z/D', $linkToken) === 1) {
            $requestedTrackingToken = $linkToken;
            if ($linkClaim !== '') {
                $requestedClaimNumber = substr($linkClaim, 0, 50);
            }
        }
    }
}

/* [COMBINED LINK] CHANGE 2c of 5 — replaces the old $hasExplicitTrackingInput line. */
$bundlePairs = $bundleRaw !== '' ? parseGuestOrderBundle($bundleRaw) : [];
$bundleFailed = ($bundleRaw !== '' && empty($bundlePairs));
if ($bundlePairs) {
    /* A combined link replaces any single-order input. */
    $requestedClaimNumber = '';
    $requestedTrackingToken = '';
}
$hasExplicitTrackingInput = $requestedClaimNumber !== '' || $requestedTrackingToken !== '' || $bundleRaw !== '';

$hasSessionOrderParameter = isset($_GET['session_order_id']) && (string)$_GET['session_order_id'] !== '';
$requestedSessionOrderId = $hasSessionOrderParameter
    ? filter_var($_GET['session_order_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;

$claimNumber = $requestedClaimNumber;
$trackingToken = $requestedTrackingToken;
$trackingTokenIsValidFormat = preg_match('/\A[a-f0-9]{64}\z/D', $trackingToken) === 1;
$usingSessionFallback = false;
$invalidSessionOrderRequest = false;
$sessionOrderId = false;

$isAuthenticatedAccountSession =
    isset($_SESSION['user_id'])
    || isset($_SESSION['admin_id'])
    || isset($_SESSION['staff_id'])
    || isset($_SESSION['customer_id'])
    || in_array(
        strtolower((string)($_SESSION['user_role'] ?? '')),
        ['customer', 'admin', 'superadmin', 'staff'],
        true
    );

$now = time();
$guestOrderTtl = 24 * 60 * 60; // Recent-order convenience list: maximum 24 hours.
$guestOrderLimit = 10;
$guestOrdersSession = $_SESSION['guest_orders'] ?? [];
if (!is_array($guestOrdersSession)) {
    $guestOrdersSession = [];
}

/* Migrate the older one-order session entry into the new session-scoped list. */
if (!$isAuthenticatedAccountSession) {
    $legacyId = filter_var($_SESSION['last_guest_order_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $legacyClaim = trim((string)($_SESSION['last_guest_claim_number'] ?? ''));
    $legacyToken = strtolower(trim((string)($_SESSION['last_guest_tracking_token'] ?? '')));
    $legacySavedAt = filter_var($_SESSION['last_guest_order_saved_at'] ?? null, FILTER_VALIDATE_INT);

    if (
        $legacyId !== false && $legacyClaim !== '' &&
        preg_match('/\A[a-f0-9]{64}\z/D', $legacyToken) === 1 &&
        $legacySavedAt !== false && $legacySavedAt <= $now &&
        ($now - $legacySavedAt) <= $guestOrderTtl
    ) {
        $legacyKey = (string)(int)$legacyId;
        if (!isset($guestOrdersSession[$legacyKey])) {
            $guestOrdersSession[$legacyKey] = [
                'claim_number' => $legacyClaim,
                'tracking_token' => $legacyToken,
                'saved_at' => $legacySavedAt,
            ];
        }
    }
}

/* Normalize the session list and expire malformed/old entries. */
$normalizedGuestOrders = [];
foreach ($guestOrdersSession as $savedOrderId => $savedOrder) {
    $savedId = filter_var((string)$savedOrderId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($savedId === false || !is_array($savedOrder)) {
        continue;
    }
    $savedClaim = trim((string)($savedOrder['claim_number'] ?? ''));
    $savedToken = strtolower(trim((string)($savedOrder['tracking_token'] ?? '')));
    $savedAt = filter_var($savedOrder['saved_at'] ?? null, FILTER_VALIDATE_INT);
    if (
        $savedClaim === '' ||
        preg_match('/\A[a-f0-9]{64}\z/D', $savedToken) !== 1 ||
        $savedAt === false || $savedAt > $now ||
        ($now - $savedAt) > $guestOrderTtl
    ) {
        continue;
    }
    $normalizedGuestOrders[(string)(int)$savedId] = [
        'claim_number' => substr($savedClaim, 0, 50),
        'tracking_token' => $savedToken,
        'saved_at' => $savedAt,
    ];
}
/* Keep only the ten most recently accessed orders in this server-side session. */
uasort($normalizedGuestOrders, static function ($a, $b) {
    return (int)($b['saved_at'] ?? 0) <=> (int)($a['saved_at'] ?? 0);
});
$guestOrdersSession = array_slice($normalizedGuestOrders, 0, $guestOrderLimit, true);
$_SESSION['guest_orders'] = $guestOrdersSession;

/* CSRF protection for the customer-facing "Forget Saved Orders" action. */
if (empty($_SESSION['guest_tracking_csrf_token'])) {
    $_SESSION['guest_tracking_csrf_token'] = bin2hex(random_bytes(32));
}
$guestTrackingCsrfToken = (string)$_SESSION['guest_tracking_csrf_token'];

/* =========================================================
   GUEST GCASH REFUND DETAILS
   Only a secure order credential already held in this PHP
   session can authorize a guest refund-details submission.
========================================================= */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['submit_guest_refund_details'] ?? '') === '1'
) {
    $refundOrderId = filter_var(
        $_POST['guest_refund_order_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    $refundAccountName = trim((string)($_POST['refund_account_name'] ?? ''));
    $refundMobileNumber = trim((string)($_POST['refund_mobile_number'] ?? ''));
    $submittedCsrf = (string)($_POST['guest_refund_csrf_token'] ?? '');
    $newRefundImageAbsolutePath = null;
    $refundDatabaseWriteCompleted = false;
    $refundFlashType = 'danger';
    $refundFlashMessage = 'Your refund details could not be submitted. Please try again.';

    try {
        if (
            $isAuthenticatedAccountSession
            || $submittedCsrf === ''
            || !hash_equals($guestTrackingCsrfToken, $submittedCsrf)
        ) {
            throw new RuntimeException('Your guest session could not be verified. Refresh the page and try again.');
        }

        if ($refundOrderId === false || $refundOrderId < 1) {
            throw new RuntimeException('Invalid order selected.');
        }

        if ($refundAccountName === '' || mb_strlen($refundAccountName) > 100) {
            throw new RuntimeException('Enter the GCash account name (up to 100 characters).');
        }

        // Accept 09XXXXXXXXX, 639XXXXXXXXX, and +639XXXXXXXXX.
        $normalizedRefundMobile = preg_replace('/[\s()\-]/', '', $refundMobileNumber);
        if (!preg_match('/\A(?:09\d{9}|639\d{9}|\+639\d{9})\z/', $normalizedRefundMobile)) {
            throw new RuntimeException('Enter a valid Philippine GCash mobile number.');
        }

        $refundSessionEntry = $guestOrdersSession[(string)$refundOrderId] ?? null;
        if (!is_array($refundSessionEntry)) {
            throw new RuntimeException('This order is no longer available in your current guest session. Reopen its secure order link.');
        }

        $refundClaimNumber = trim((string)($refundSessionEntry['claim_number'] ?? ''));
        $refundTrackingToken = strtolower(trim((string)($refundSessionEntry['tracking_token'] ?? '')));
        if (
            $refundClaimNumber === ''
            || preg_match('/\A[a-f0-9]{64}\z/D', $refundTrackingToken) !== 1
        ) {
            throw new RuntimeException('The secure order details could not be verified. Reopen your secure order link.');
        }

        // Verify the order against the per-order token hash before accepting personal refund details.
        $eligibleRefundStmt = $pdo->prepare("
            SELECT
                id,
                claim_number,
                payment_method,
                payment_screenshot,
                cancellation_reason,
                refund_status,
                refund_supporting_image
            FROM orders
            WHERE id = ?
              AND claim_number = ?
              AND customer_id IS NULL
              AND order_source = 'online'
              AND guest_tracking_token_hash = ?
              AND status = 'cancelled'
            LIMIT 1
        ");
        $eligibleRefundStmt->execute([
            (int)$refundOrderId,
            $refundClaimNumber,
            hash('sha256', $refundTrackingToken)
        ]);
        $eligibleRefundOrder = $eligibleRefundStmt->fetch(PDO::FETCH_ASSOC);

        if (
            !$eligibleRefundOrder
            || strtolower(trim((string)$eligibleRefundOrder['payment_method'])) !== 'gcash'
            || trim((string)($eligibleRefundOrder['payment_screenshot'] ?? '')) === ''
            || strtolower(trim((string)($eligibleRefundOrder['cancellation_reason'] ?? ''))) === 'payment could not be verified'
            || strtolower(trim((string)($eligibleRefundOrder['refund_status'] ?? 'none'))) !== 'pending'
        ) {
            throw new RuntimeException('This cancelled order is not eligible for GCash refund details.');
        }

        // Keep the previous supporting image when no replacement is uploaded.
        $supportingImagePath = $eligibleRefundOrder['refund_supporting_image'] ?? null;
        if (
            isset($_FILES['refund_supporting_image'])
            && (int)($_FILES['refund_supporting_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
        ) {
            $refundUpload = $_FILES['refund_supporting_image'];
            if ((int)$refundUpload['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('The supporting image could not be uploaded. Please try again.');
            }
            if ((int)$refundUpload['size'] > 5 * 1024 * 1024) {
                throw new RuntimeException('The supporting image must not exceed 5MB.');
            }

            $refundFinfo = new finfo(FILEINFO_MIME_TYPE);
            $refundMime = $refundFinfo->file($refundUpload['tmp_name']);
            $allowedRefundMimes = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp'
            ];
            if (!isset($allowedRefundMimes[$refundMime])) {
                throw new RuntimeException('Upload a JPG, PNG, or WEBP image only.');
            }

            $refundUploadDirectory = dirname(__DIR__) . '/assets/uploads/refunds';
            if (
                !is_dir($refundUploadDirectory)
                && !mkdir($refundUploadDirectory, 0755, true)
                && !is_dir($refundUploadDirectory)
            ) {
                throw new RuntimeException('The refund upload folder could not be created.');
            }

            $refundFilename = 'refund_guest_' . (int)$refundOrderId . '_' . bin2hex(random_bytes(8))
                . '.' . $allowedRefundMimes[$refundMime];
            $newRefundImageAbsolutePath = $refundUploadDirectory . '/' . $refundFilename;
            if (!move_uploaded_file($refundUpload['tmp_name'], $newRefundImageAbsolutePath)) {
                $newRefundImageAbsolutePath = null;
                throw new RuntimeException('The supporting image could not be saved.');
            }
            $supportingImagePath = 'assets/uploads/refunds/' . $refundFilename;
        }

        $saveGuestRefundStmt = $pdo->prepare("
            UPDATE orders
            SET refund_account_name = ?,
                refund_mobile_number = ?,
                refund_supporting_image = ?,
                refund_details_submitted_at = NOW()
            WHERE id = ?
              AND claim_number = ?
              AND customer_id IS NULL
              AND order_source = 'online'
              AND guest_tracking_token_hash = ?
              AND status = 'cancelled'
              AND LOWER(TRIM(payment_method)) = 'gcash'
              AND payment_screenshot IS NOT NULL
              AND TRIM(payment_screenshot) <> ''
              AND LOWER(TRIM(COALESCE(cancellation_reason, ''))) <> 'payment could not be verified'
              AND refund_status = 'pending'
        ");
        $saveGuestRefundStmt->execute([
            $refundAccountName,
            $normalizedRefundMobile,
            $supportingImagePath,
            (int)$refundOrderId,
            $refundClaimNumber,
            hash('sha256', $refundTrackingToken)
        ]);
        $refundDatabaseWriteCompleted = true;

        // Confirm the saved values because MySQL can report zero changed rows for identical values.
        $confirmGuestRefundStmt = $pdo->prepare("
            SELECT refund_account_name, refund_mobile_number, refund_supporting_image
            FROM orders
            WHERE id = ?
              AND claim_number = ?
              AND customer_id IS NULL
              AND order_source = 'online'
              AND guest_tracking_token_hash = ?
              AND status = 'cancelled'
              AND refund_status = 'pending'
            LIMIT 1
        ");
        $confirmGuestRefundStmt->execute([
            (int)$refundOrderId,
            $refundClaimNumber,
            hash('sha256', $refundTrackingToken)
        ]);
        $confirmedGuestRefund = $confirmGuestRefundStmt->fetch(PDO::FETCH_ASSOC);

        if (
            !$confirmedGuestRefund
            || (string)$confirmedGuestRefund['refund_account_name'] !== $refundAccountName
            || (string)$confirmedGuestRefund['refund_mobile_number'] !== $normalizedRefundMobile
            || (string)($confirmedGuestRefund['refund_supporting_image'] ?? '') !== (string)($supportingImagePath ?? '')
        ) {
            throw new RuntimeException('Your GCash refund details could not be saved. Please try again.');
        }

        $refundFlashType = 'success';
        $refundFlashMessage = 'Your GCash refund details have been submitted. The store will review and process your refund.';
    } catch (Throwable $refundError) {
        if (!$refundDatabaseWriteCompleted && $newRefundImageAbsolutePath !== null && is_file($newRefundImageAbsolutePath)) {
            @unlink($newRefundImageAbsolutePath);
        }
        $refundFlashMessage = $refundError instanceof RuntimeException
            ? $refundError->getMessage()
            : 'Something went wrong while submitting your GCash refund details. Please try again.';
    }

    $_SESSION['guest_refund_flash'] = [
        'order_id' => $refundOrderId !== false ? (int)$refundOrderId : 0,
        'type' => $refundFlashType,
        'message' => $refundFlashMessage,
        'created_at' => time()
    ];

    $refundRedirect = 'monitor-guest-order.php';
    if ($refundOrderId !== false && isset($guestOrdersSession[(string)(int)$refundOrderId])) {
        $refundRedirect .= '?session_order_id=' . (int)$refundOrderId;
    }
    header('Location: ' . $refundRedirect);
    exit;
}

/* Forgetting recent orders removes only this browser session's saved access list. */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['ajax'] ?? '') === 'forget_orders'
) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private, max-age=0');

    $submittedCsrf = (string)($_POST['csrf_token'] ?? '');
    if (
        $isAuthenticatedAccountSession
        || $submittedCsrf === ''
        || !hash_equals($guestTrackingCsrfToken, $submittedCsrf)
    ) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'This guest session could not be verified. Refresh the page and try again.'
        ]);
        exit;
    }

    unset(
        $_SESSION['guest_orders'],
        $_SESSION['last_guest_order_id'],
        $_SESSION['last_guest_claim_number'],
        $_SESSION['last_guest_tracking_token'],
        $_SESSION['last_guest_order_saved_at']
    );

    echo json_encode([
        'success' => true,
        'message' => 'Recent guest orders were removed from this browser session.'
    ]);
    exit;
}

/* Secondary per-session throttle for invalid manual/token lookups. */
$lookupWindowSeconds = 900;
$lookupMaxAttempts = 10;
$lookupLimiter = $_SESSION['guest_tracking_lookup_limiter'] ?? null;
if (
    !is_array($lookupLimiter)
    || !isset($lookupLimiter['started_at'], $lookupLimiter['attempts'])
    || !is_numeric($lookupLimiter['started_at'])
    || !is_numeric($lookupLimiter['attempts'])
    || ($now - (int)$lookupLimiter['started_at']) >= $lookupWindowSeconds
    || (int)$lookupLimiter['started_at'] > $now
) {
    $lookupLimiter = ['started_at' => $now, 'attempts' => 0];
}
$lookupLimiter['attempts'] = max(0, (int)$lookupLimiter['attempts']);
$guestLookupRateLimited = $lookupLimiter['attempts'] >= $lookupMaxAttempts;
$guestLookupError = 'We could not verify this guest order. Please check the order link or copy it again from your checkout confirmation.';

/*
 * [COMBINED LINK] CHANGE 3 of 5
 * Combined order link: each claim + token pair is verified against its own
 * hash. Valid orders join this session's list; the newest one is opened.
 * Invalid pairs are ignored.
 */
if ($bundlePairs) {
    if ($guestLookupRateLimited) {
        $bundleFailed = true;
    } else {
        $bundleConditions = [];
        $bundleParams = [];
        foreach ($bundlePairs as $pair) {
            $bundleConditions[] = '(claim_number = ? AND guest_tracking_token_hash = ?)';
            $bundleParams[] = $pair['claim'];
            $bundleParams[] = hash('sha256', $pair['token']);
        }

        $bundleStmt = $pdo->prepare("
            SELECT id, claim_number, guest_tracking_token_hash
            FROM orders
            WHERE customer_id IS NULL
              AND order_source = 'online'
              AND (" . implode(' OR ', $bundleConditions) . ")
        ");
        $bundleStmt->execute($bundleParams);

        $bundleRowsByHash = [];
        foreach ($bundleStmt->fetchAll(PDO::FETCH_ASSOC) as $bundleRow) {
            $bundleRowsByHash[strtolower((string)$bundleRow['guest_tracking_token_hash'])] = $bundleRow;
        }

        $firstValidPair = null;
        foreach ($bundlePairs as $pair) {
            $bundleRow = $bundleRowsByHash[hash('sha256', $pair['token'])] ?? null;
            if (!$bundleRow) {
                continue;
            }
            $bundleKey = (string)(int)$bundleRow['id'];
            if (!isset($guestOrdersSession[$bundleKey])) {
                $guestOrdersSession[$bundleKey] = [
                    'claim_number' => (string)$bundleRow['claim_number'],
                    'tracking_token' => $pair['token'],
                    'saved_at' => $now,
                ];
            }
            if ($firstValidPair === null) {
                $firstValidPair = ['claim' => (string)$bundleRow['claim_number'], 'token' => $pair['token']];
            }
        }

        if ($firstValidPair === null) {
            $lookupLimiter['attempts']++;
            $_SESSION['guest_tracking_lookup_limiter'] = $lookupLimiter;
            $bundleFailed = true;
        } else {
            uasort($guestOrdersSession, static function ($a, $b) {
                return (int)($b['saved_at'] ?? 0) <=> (int)($a['saved_at'] ?? 0);
            });
            $guestOrdersSession = array_slice($guestOrdersSession, 0, $guestOrderLimit, true);
            $_SESSION['guest_orders'] = $guestOrdersSession;

            $claimNumber = $firstValidPair['claim'];
            $trackingToken = $firstValidPair['token'];
            $trackingTokenIsValidFormat = true;
        }
    }
}

/*
 * A session order ID is only accepted when the current server-side session
 * already contains that order's access credential. A bare ID is not enough.
 */
if (!$isAuthenticatedAccountSession && !$hasExplicitTrackingInput) {
    if ($hasSessionOrderParameter) {
        if ($requestedSessionOrderId !== false && isset($guestOrdersSession[(string)(int)$requestedSessionOrderId])) {
            $sessionOrderId = (int)$requestedSessionOrderId;
            $sessionEntry = $guestOrdersSession[(string)$sessionOrderId];
            $claimNumber = (string)$sessionEntry['claim_number'];
            $trackingToken = (string)$sessionEntry['tracking_token'];
            $trackingTokenIsValidFormat = true;
            $usingSessionFallback = true;
        } else {
            $invalidSessionOrderRequest = true;
        }
    } elseif (!empty($guestOrdersSession)) {
        /*
         * Prefer the newest still-active order. The ID, claim number, and token
         * hash must all match a server-side session entry before it is selected.
         */
        $candidateConditions = [];
        $candidateParams = [];
        foreach ($guestOrdersSession as $candidateId => $candidateEntry) {
            $candidateConditions[] = '(id = ? AND claim_number = ? AND guest_tracking_token_hash = ?)';
            $candidateParams[] = (int)$candidateId;
            $candidateParams[] = (string)$candidateEntry['claim_number'];
            $candidateParams[] = hash('sha256', (string)$candidateEntry['tracking_token']);
        }

        $newestActiveOrderId = 0;
        if ($candidateConditions) {
            $activeCandidateStmt = $pdo->prepare("
                SELECT id
                FROM orders
                WHERE customer_id IS NULL
                  AND order_source = 'online'
                  AND status IN ('pending_verification', 'order_queue', 'confirmed', 'preparing', 'ready')
                  AND (" . implode(' OR ', $candidateConditions) . ")
                ORDER BY created_at DESC, id DESC
                LIMIT 1
            ");
            $activeCandidateStmt->execute($candidateParams);
            $newestActiveOrderId = (int)$activeCandidateStmt->fetchColumn();
        }

        if ($newestActiveOrderId > 0 && isset($guestOrdersSession[(string)$newestActiveOrderId])) {
            $sessionOrderId = $newestActiveOrderId;
            $sessionEntry = $guestOrdersSession[(string)$newestActiveOrderId];
        } else {
            uasort($guestOrdersSession, static function ($a, $b) {
                return (int)($b['saved_at'] ?? 0) <=> (int)($a['saved_at'] ?? 0);
            });
            $latestSessionOrderId = (int)array_key_first($guestOrdersSession);
            $sessionOrderId = $latestSessionOrderId;
            $sessionEntry = $guestOrdersSession[(string)$latestSessionOrderId];
        }

        $claimNumber = (string)$sessionEntry['claim_number'];
        $trackingToken = (string)$sessionEntry['tracking_token'];
        $trackingTokenIsValidFormat = true;
        $usingSessionFallback = true;
    }
}

$statusLabels = [
    'pending_verification' => 'Pending Verification',
    'order_queue' => 'Awaiting Store Confirmation',
    'confirmed' => 'Confirmed',
    'preparing' => 'Preparing',
    'ready' => 'Ready for Pick-up',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
];

$workflowStatuses = [
    'pending_verification',
    'confirmed',
    'preparing',
    'ready',
    'completed'
];

$error = $invalidSessionOrderRequest ? 'That order is not available in this guest session. Reopen its secure order link to access it.' : '';
/* [COMBINED LINK] CHANGE 3b of 5 — show the generic verification error for a failed combined link. */
if (!$invalidSessionOrderRequest && $hasExplicitTrackingInput && ($claimNumber === '' || $trackingToken === '')) {
    $error = $bundleFailed
        ? $guestLookupError
        : 'Paste the complete secure order link copied from checkout.';
}
$order = null;
$items = [];
$cancellationActorName = '';
$cancellationActorRole = '';

/**
 * Resolve the person who cancelled the order from the status history.
 * Staff accounts are stored in users.name; admin/superadmin accounts
 * are stored in admins.full_name.
 */
function getGuestCancellationActor(PDO $pdo, int $orderId): array
{
    if ($orderId <= 0) {
        return [
            'name' => '',
            'role' => '',
        ];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT
                h.actor_role,
                h.actor_id,
                u.name AS staff_name,
                a.full_name AS admin_name
            FROM order_status_history h
            LEFT JOIN users u
                ON LOWER(TRIM(h.actor_role)) IN ('staff', 'customer')
               AND u.id = h.actor_id
            LEFT JOIN admins a
                ON LOWER(TRIM(h.actor_role)) IN ('admin', 'superadmin')
               AND a.id = h.actor_id
            WHERE h.order_id = ?
              AND h.to_status = 'cancelled'
            ORDER BY h.id DESC
            LIMIT 1
        ");

        $stmt->execute([$orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return [
                'name' => '',
                'role' => '',
            ];
        }

        $role = strtolower(trim((string)($row['actor_role'] ?? '')));
        $name = '';

        if (in_array($role, ['admin', 'superadmin'], true)) {
            $name = trim((string)($row['admin_name'] ?? ''));
        } elseif ($role === 'staff') {
            $name = trim((string)($row['staff_name'] ?? ''));
        } elseif ($role === 'customer') {
            $name = trim((string)($row['staff_name'] ?? ''));
        }

        return [
            'name' => $name,
            'role' => $role,
        ];
    } catch (Throwable $e) {
        /* Keep the guest status page working even if history is unavailable. */
        return [
            'name' => '',
            'role' => '',
        ];
    }
}

if ($claimNumber !== '') {
    if ($guestLookupRateLimited && !$usingSessionFallback) {
        $error = $guestLookupError;
    } elseif ($trackingTokenIsValidFormat) {
        $lookupSql = "
            SELECT
                id,
                order_number,
                claim_number,
                customer_name,
                pickup_date,
                pickup_time,
                payment_method,
                payment_screenshot,
                total_amount,
                status,
                cancellation_reason,
                refund_status,
                refund_requested_at,
                refund_account_name,
                refund_mobile_number,
                refund_supporting_image,
                refund_details_submitted_at,
                refund_proof_image,
                refund_rejection_reason
            FROM orders
            WHERE claim_number = ?
              AND customer_id IS NULL
              AND order_source = 'online'
              AND guest_tracking_token_hash = ?
        ";
        $lookupParams = [
            $claimNumber,
            hash('sha256', $trackingToken)
        ];
        if ($usingSessionFallback) {
            $lookupSql .= " AND id = ?";
            $lookupParams[] = (int)$sessionOrderId;
        }
        $lookupSql .= " LIMIT 1";

        $stmt = $pdo->prepare($lookupSql);
        $stmt->execute($lookupParams);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($order) {
        /* A valid access proves possession; clear prior failed attempts. */
        $_SESSION['guest_tracking_lookup_limiter'] = [
            'started_at' => $now,
            'attempts' => 0
        ];
        $authorizedOrderId = (string)(int)$order['id'];
        $existingSessionEntry = $guestOrdersSession[$authorizedOrderId] ?? null;
        $originalSavedAt = is_array($existingSessionEntry)
            ? (int)($existingSessionEntry['saved_at'] ?? $now)
            : $now;

        $guestOrdersSession[$authorizedOrderId] = [
            'claim_number' => (string)$order['claim_number'],
            'tracking_token' => (string)$trackingToken,
            'saved_at' => $originalSavedAt,
        ];
        uasort($guestOrdersSession, static function ($a, $b) {
            return (int)($b['saved_at'] ?? 0) <=> (int)($a['saved_at'] ?? 0);
        });
        $guestOrdersSession = array_slice($guestOrdersSession, 0, $guestOrderLimit, true);
        $_SESSION['guest_orders'] = $guestOrdersSession;

        // Backward compatibility while older pages are being replaced.
        $_SESSION['last_guest_order_id'] = (int)$order['id'];
        $_SESSION['last_guest_claim_number'] = (string)$order['claim_number'];
        $_SESSION['last_guest_tracking_token'] = (string)$trackingToken;
        $_SESSION['last_guest_order_saved_at'] = $originalSavedAt;
        if ((string)$order['status'] === 'cancelled') {
            $cancellationActor = getGuestCancellationActor(
                $pdo,
                (int)$order['id']
            );

            $cancellationActorName = $cancellationActor['name'];
            $cancellationActorRole = $cancellationActor['role'];
        }

        $itemStmt = $pdo->prepare("
            SELECT
                product_name,
                quantity,
                unit_price,
                subtotal,
                size,
                addons,
                sugar_level
            FROM order_items
            WHERE order_id = ?
            ORDER BY id ASC
        ");

        $itemStmt->execute([(int)$order['id']]);
        $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

        /* Existing feedback for this order (if the guest already sent one). */
        $existingFeedback = null;

        try {
            $feedbackStmt = $pdo->prepare("
                SELECT message, created_at
                FROM order_feedback
                WHERE order_id = ?
                LIMIT 1
            ");
            $feedbackStmt->execute([(int)$order['id']]);
            $existingFeedback = $feedbackStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            /* Keep the status page working even if the feedback table is missing. */
            $existingFeedback = null;
        }
    } else {
        $lookupLimiter['attempts']++;
        $_SESSION['guest_tracking_lookup_limiter'] = $lookupLimiter;
        $error = $guestLookupError;
    }
    } else {
        /* Do not reveal whether a claim number exists when the token is absent/invalid. */
        $lookupLimiter['attempts']++;
        $_SESSION['guest_tracking_lookup_limiter'] = $lookupLimiter;
        $error = $guestLookupError;
    }
}

/* =========================================================
   AJAX STATUS POLLING
   The page can quietly check the current status while open.
========================================================= */
if (
    ($_GET['ajax'] ?? '') === 'status'
    && $claimNumber !== ''
) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private, max-age=0');

    if ($guestLookupRateLimited && !$usingSessionFallback) {
        http_response_code(429);
        header('Retry-After: 900');
        echo json_encode([
            'success' => false,
            'message' => $guestLookupError
        ]);
        exit;
    }

    if (!$order) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => $guestLookupError
        ]);
        exit;
    }

    if ((string)$order['status'] === 'cancelled') {
        $cancellationActor = getGuestCancellationActor(
            $pdo,
            (int)$order['id']
        );

        $cancellationActorName = $cancellationActor['name'];
        $cancellationActorRole = $cancellationActor['role'];
    }

    echo json_encode([
        'success' => true,
        'status' => (string)$order['status'],
        'status_label' => $statusLabels[$order['status']] ?? ucfirst(str_replace('_', ' ', $order['status'])),
        'cancellation_actor_name' => $cancellationActorName,
        'cancellation_actor_role' => $cancellationActorRole,
        'refund_status' => strtolower(trim((string)($order['refund_status'] ?? 'none')))
    ]);
    exit;
}

/* Fetch the orders already authorized in this browser's guest session. */
$sessionOrderSummaries = [];
if (!$isAuthenticatedAccountSession && !empty($guestOrdersSession)) {
    $sessionIds = array_values(array_filter(array_map('intval', array_keys($guestOrdersSession)), static function ($id) {
        return $id > 0;
    }));
    if ($sessionIds) {
        $placeholders = implode(',', array_fill(0, count($sessionIds), '?'));
        $summaryStmt = $pdo->prepare("
            SELECT id, order_number, claim_number, status, total_amount, pickup_date, pickup_time, created_at, guest_tracking_token_hash, payment_method, payment_screenshot, cancellation_reason, refund_status
            FROM orders
            WHERE id IN ($placeholders)
              AND customer_id IS NULL
              AND order_source = 'online'
            ORDER BY id DESC
        ");
        $summaryStmt->execute($sessionIds);
        foreach ($summaryStmt->fetchAll(PDO::FETCH_ASSOC) as $summary) {
            $sid = (string)(int)$summary['id'];
            $sessionEntry = $guestOrdersSession[$sid] ?? null;
            if (!is_array($sessionEntry)) {
                continue;
            }
            $expectedHash = hash('sha256', (string)$sessionEntry['tracking_token']);
            $storedHash = strtolower((string)($summary['guest_tracking_token_hash'] ?? ''));
            if ($storedHash === '' || !hash_equals($storedHash, $expectedHash)) {
                unset($guestOrdersSession[$sid]);
                continue;
            }
            $summary['is_current'] = $order && (int)$order['id'] === (int)$summary['id'];
            $sessionOrderSummaries[] = $summary;
        }
        $_SESSION['guest_orders'] = $guestOrdersSession;
    }
}

$activeGuestStatuses = [
    'pending_verification',
    'order_queue',
    'confirmed',
    'preparing',
    'ready'
];
$activeSessionOrderSummaries = [];
$pastSessionOrderSummaries = [];

usort($sessionOrderSummaries, static function ($a, $b) {
    $dateCompare = strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
    return $dateCompare !== 0 ? $dateCompare : ((int)$b['id'] <=> (int)$a['id']);
});

foreach ($sessionOrderSummaries as $summary) {
    if (in_array((string)$summary['status'], $activeGuestStatuses, true)) {
        $activeSessionOrderSummaries[] = $summary;
    } else {
        $pastSessionOrderSummaries[] = $summary;
    }
}

/* [COMBINED LINK] One link that reopens every ACTIVE order in this session. */
$activeOrdersLinkForCopy = '';
$activeLinkParts = [];
foreach ($activeSessionOrderSummaries as $activeSummary) {
    $activeEntry = $guestOrdersSession[(string)(int)$activeSummary['id']] ?? null;
    if (is_array($activeEntry)) {
        $activeLinkParts[] = (string)$activeEntry['claim_number'] . '.' . (string)$activeEntry['tracking_token'];
    }
}
if ($activeLinkParts) {
    $activeOrdersLinkForCopy = 'monitor-guest-order.php?orders=' . rawurlencode(implode(',', $activeLinkParts));
}

$guestRefundFlash = null;
$storedGuestRefundFlash = $_SESSION['guest_refund_flash'] ?? null;
if (is_array($storedGuestRefundFlash)) {
    $flashOrderId = (int)($storedGuestRefundFlash['order_id'] ?? 0);
    $flashCreatedAt = (int)($storedGuestRefundFlash['created_at'] ?? 0);
    if ($flashCreatedAt > 0 && (time() - $flashCreatedAt) <= 900) {
        if ($order && $flashOrderId === (int)$order['id']) {
            $guestRefundFlash = $storedGuestRefundFlash;
            unset($_SESSION['guest_refund_flash']);
        }
    } else {
        unset($_SESSION['guest_refund_flash']);
    }
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
?>

<style>
    /* Responsive layout for the guest Order Status page. */
    html {
        min-height: 100%;
        width: 100%;
    }

    body {
        background: #FDFBF7;
        min-height: 100vh;
        display: flex;
        flex-direction: column;
        overflow-x: hidden;
    }

    .order-status-wrap {
        flex: 1 0 auto;
        width: 100%;
        max-width: 880px;
        margin: 0 auto;
        padding: clamp(22px, 4vw, 48px) clamp(12px, 2.5vw, 20px);
        min-width: 0;
    }

    .order-status-card {
        width: 100%;
        min-width: 0;
        background: #FFFFFF;
        border: 1.5px solid #6F4E37;
        border-radius: clamp(14px, 2vw, 20px);
        box-shadow: 0 8px 24px rgba(44, 34, 30, .07);
        padding: clamp(16px, 3.2vw, 28px);
    }

    .order-status-card > h2 {
        font-size: clamp(1.45rem, 3vw, 2rem);
        line-height: 1.2;
        overflow-wrap: anywhere;
    }

    .order-status-card p {
        overflow-wrap: anywhere;
    }

    .btn-brown {
        background: #4A3525;
        border-color: #4A3525;
        color: #FFFFFF;
        border-radius: 999px;
        font-weight: 700;
    }

    .btn-brown:hover,
    .btn-brown:focus-visible {
        background: #342317;
        border-color: #342317;
        color: #FFFFFF;
    }

    .btn-brown:focus-visible,
    .guest-order-row-action:focus-visible,
    #toggleGuestLookup:focus-visible {
        outline: 3px solid rgba(111, 78, 55, .28);
        outline-offset: 2px;
    }

    .status-pill {
        display: inline-flex;
        align-items: center;
        max-width: 100%;
        padding: 7px 12px;
        border-radius: 999px;
        background: #EEF5FC;
        color: #496F93;
        font-weight: 800;
        font-size: .8rem;
        overflow-wrap: anywhere;
    }

    .status-timeline {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 8px;
        margin-top: 24px;
        min-width: 0;
    }

    /* Compact progress view is reserved for phone and narrow tablet layouts. */
    .mobile-order-progress {
        display: none;
        margin: 20px 0 8px;
        padding: 14px;
        border: 1px solid #E8DFD4;
        border-radius: 12px;
        background: #FFFEFC;
    }

    .mobile-progress-heading,
    .mobile-progress-caption {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
    }

    .mobile-progress-heading {
        color: #4A3525;
        font-size: .85rem;
        font-weight: 700;
        margin-bottom: 10px;
    }

    .mobile-progress-step {
        flex: 0 0 auto;
        color: #6F4E37;
        font-size: .75rem;
        font-weight: 700;
    }

    .mobile-progress-track {
        height: 8px;
        overflow: hidden;
        border-radius: 999px;
        background: #E9E0D7;
    }

    .mobile-progress-fill {
        width: 20%;
        height: 100%;
        border-radius: inherit;
        background: #6F4E37;
        transition: width .25s ease;
    }

    .mobile-progress-caption {
        margin-top: 8px;
        color: #756658;
        font-size: .75rem;
    }

    .mobile-progress-current {
        color: #4A3525;
        font-weight: 700;
        text-align: right;
    }

    .mobile-order-progress.is-cancelled .mobile-progress-fill {
        background: #B42318;
    }

    .mobile-order-progress.is-cancelled .mobile-progress-current,
    .mobile-order-progress.is-cancelled .mobile-progress-step {
        color: #B42318;
    }

    .status-step {
        min-width: 0;
        text-align: center;
        color: #9A8B7D;
        font-size: .72rem;
        font-weight: 700;
        overflow-wrap: anywhere;
    }

    .status-step-dot {
        width: 34px;
        height: 34px;
        margin: 0 auto 7px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        border: 1px solid #D9CABB;
        background: #F8F3EC;
        color: #9A8B7D;
        font-size: .8rem;
    }

    .status-step.done,
    .status-step.current {
        color: #4A3525;
    }

    .status-step.done .status-step-dot,
    .status-step.current .status-step-dot {
        background: #4A3525;
        border-color: #4A3525;
        color: #FFFFFF;
    }

    .status-step.current .status-step-dot {
        box-shadow: 0 0 0 4px #F1E6DA;
    }

    .order-item {
        min-width: 0;
        border: 1px solid #E8DFD4;
        border-radius: 12px;
        padding: 14px;
    }

    .order-item .d-flex > .fw-bold {
        min-width: 0;
        overflow-wrap: anywhere;
    }

    .order-item .d-flex > .fw-semibold {
        flex: 0 0 auto;
        white-space: nowrap;
    }

    .order-summary-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.4fr) repeat(2, minmax(0, 1fr));
        gap: 10px;
        margin: 20px 0;
    }

    .order-summary-item {
        min-width: 0;
        padding: 12px;
        border: 1px solid #E8DFD4;
        border-radius: 12px;
        background: #FFFEFC;
    }

    .order-summary-label {
        margin-bottom: 4px;
        color: #8A7A6C;
        font-size: .75rem;
    }

    .order-summary-value {
        color: #3F342B;
        font-weight: 700;
        overflow-wrap: anywhere;
    }

    .guest-order-items-panel {
        min-width: 0;
        margin: 16px 0;
        border: 1px solid #E8DFD4;
        border-radius: 12px;
        overflow: hidden;
        background: #FFFFFF;
    }

    .guest-order-items-toggle,
    .guest-orders-toggle {
        width: 100%;
        min-width: 0;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        text-align: left;
        color: #4A3525;
        background: #FFFEFC;
        border: 0;
        cursor: pointer;
    }

    .guest-order-items-toggle {
        min-height: 54px;
        padding: 12px 14px;
    }

    .guest-order-items-toggle:hover,
    .guest-orders-toggle:hover {
        background: #FBF5EF;
    }

    .guest-order-items-title {
        min-width: 0;
        font-weight: 800;
    }

    .guest-order-items-hint {
        display: block;
        margin-top: 2px;
        color: #8A7A6C;
        font-size: .75rem;
        font-weight: 400;
    }

    .guest-panel-toggle-label {
        flex: 0 0 auto;
        color: #6F4E37;
        font-size: .75rem;
        font-weight: 700;
    }

    .guest-panel-toggle-icon {
        flex: 0 0 auto;
        font-size: .8rem;
    }

    .guest-order-items-content {
        padding: 0 12px 12px;
    }

    .guest-order-items-panel.is-collapsed .guest-order-items-content,
    .guest-session-orders.is-collapsed .guest-session-orders-content {
        display: none;
    }

    .guest-order-items-panel.is-collapsed .guest-order-items-toggle,
    .guest-session-orders.is-collapsed .guest-orders-toggle {
        border-radius: inherit;
    }

    .last-checked {
        color: #8A7A6C;
        font-size: .75rem;
        overflow-wrap: anywhere;
    }

    .guest-orders-overview {
        display: grid;
        gap: 14px;
        width: 100%;
        min-width: 0;
        margin: 0 0 22px;
    }

    .guest-session-orders,
    .guest-past-orders {
        width: 100%;
        min-width: 0;
        border: 1.5px solid #B08A68;
        border-radius: 14px;
        padding: clamp(12px, 2.2vw, 16px);
        background: #FFFEFC;
    }

    .guest-orders-section-heading,
    .guest-past-orders > summary {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        min-width: 0;
        color: #4A3525;
    }

    .guest-orders-section-heading h3,
    .guest-past-orders-title {
        margin: 0;
        color: #4A3525;
        font-size: 1rem;
        font-weight: 800;
    }

    .guest-orders-section-description,
    .guest-past-orders-description {
        display: block;
        margin-top: 3px;
        color: #756658;
        font-size: .76rem;
        line-height: 1.4;
        font-weight: 400;
    }

    .guest-orders-count {
        flex: 0 0 auto;
        min-width: 28px;
        text-align: center;
    }

    .guest-session-orders-list,
    .guest-past-orders-list {
        min-width: 0;
        margin-top: 10px;
    }

    .guest-session-order-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        min-width: 0;
        padding: 12px 10px;
        border-top: 1px solid #EEE5DC;
        border-radius: 9px;
    }

    .guest-session-order-row:first-child {
        border-top: 0;
    }

    .guest-session-order-row.is-current {
        border: 1.5px solid #6F4E37;
        margin: 7px 0;
        background: #FBF5EF;
        box-shadow: 0 0 0 1px rgba(111, 78, 55, .08);
    }

    .guest-session-order-meta {
        flex: 1 1 auto;
        min-width: 0;
        overflow-wrap: anywhere;
    }

    .guest-session-order-number {
        color: #4A3525;
        font-weight: 800;
        line-height: 1.35;
        overflow-wrap: anywhere;
    }

    .guest-session-order-subline {
        margin-top: 3px;
        color: #756658;
        font-size: .77rem;
        line-height: 1.4;
        overflow-wrap: anywhere;
    }

    .guest-session-order-status {
        display: inline-flex;
        align-items: center;
        max-width: 100%;
        margin-top: 7px;
        border-radius: 999px;
        padding: 4px 9px;
        background: #EEF5FC;
        color: #496F93;
        font-size: .75rem;
        font-weight: 700;
        overflow-wrap: anywhere;
    }

    .guest-session-order-status[data-session-order-status="preparing"] {
        background: #F1EAFE;
        color: #6841A5;
    }

    .guest-session-order-status[data-session-order-status="ready"] {
        background: #E8F5EC;
        color: #287A46;
    }

    .guest-session-order-status[data-session-order-status="pending_verification"],
    .guest-session-order-status[data-session-order-status="order_queue"] {
        background: #FFF3DB;
        color: #8A5A00;
    }

    .guest-session-order-status[data-session-order-status="cancelled"] {
        background: #FDECEC;
        color: #A33A3A;
    }

    .guest-order-row-action {
        flex: 0 0 auto;
        min-height: 38px;
        display: inline-flex;
        justify-content: center;
        align-items: center;
        white-space: nowrap;
        font-size: .8rem;
    }

    .guest-selected-order-label {
        flex: 0 0 auto;
        color: #4A3525;
        font-size: .75rem;
        font-weight: 800;
        white-space: nowrap;
    }

    .guest-past-orders {
        padding: 0;
        overflow: hidden;
    }

    .guest-past-orders > summary {
        min-height: 64px;
        padding: 12px 16px;
        cursor: pointer;
        list-style: none;
    }

    .guest-past-orders > summary::-webkit-details-marker {
        display: none;
    }

    .guest-past-orders > summary::after {
        content: "\f282";
        font-family: "bootstrap-icons";
        color: #6F4E37;
        font-size: .9rem;
        flex: 0 0 auto;
        transition: transform .2s ease;
    }

    .guest-past-orders[open] > summary {
        border-bottom: 1px solid #E8DFD4;
        background: #FFFEFC;
    }

    .guest-past-orders[open] > summary::after {
        transform: rotate(180deg);
    }

    .guest-past-orders-list {
        padding: 0 12px 10px;
    }

    .guest-orders-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 10px;
        padding: 0 2px;
    }

    .guest-orders-retention-note {
        color: #8A7A6C;
        font-size: .72rem;
        line-height: 1.4;
    }

    #forgetGuestOrders {
        min-height: 36px;
        padding: 6px 10px;
        border: 1px solid #B08A68;
        border-radius: 8px;
        color: #6F4E37;
        background: #FFFFFF;
        font-size: .75rem;
        font-weight: 700;
    }

    #forgetGuestOrders:hover:not(:disabled) {
        background: #FBF5EF;
        border-color: #6F4E37;
    }

    .guest-orders-empty {
        padding: 12px 10px 4px;
        color: #756658;
        font-size: .82rem;
    }

    /* Secondary lookup toggle: understated, but clearly part of the Localitea palette. */
    #toggleGuestLookup {
        min-height: 40px;
        padding: 7px 12px;
        color: #4A3525;
        background: #FFFFFF;
        border: 1px solid #6F4E37;
        border-radius: 8px;
        font-weight: 600;
    }

    #toggleGuestLookup:hover,
    #toggleGuestLookup:active {
        color: #342317;
        background: #F5EAE0;
        border-color: #4A3525;
    }

    #guestLookupPanel button[type="submit"],
    [data-copy-link] {
        min-height: 40px;
    }

    #guestLookupPanel .form-control {
        width: 100%;
        min-width: 0;
        color: #000000;
        background-color: #FFFFFF;
    }

    /* The pasted order-link field has a darker outline and legible black text. */
    #guestLookupPanel #guestTrackingCode {
        border: 1px solid #000000;
        color: #000000;
        background-color: #FFFFFF;
        caret-color: #000000;
    }

    #guestLookupPanel #guestTrackingCode::placeholder {
        color: #333333;
        opacity: 1;
    }

    #guestLookupPanel #guestTrackingCode:focus {
        border-color: #4A3525;
        box-shadow: 0 0 0 3px rgba(111, 78, 55, .16);
        outline: 0;
    }

    #orderStatusResult {
        min-width: 0;
        overflow-wrap: anywhere;
    }

    .guest-refund-panel {
        margin-top: 16px;
        padding: 16px;
        border: 1px solid #D8C9BD;
        border-radius: 12px;
        background: #FBF8F4;
    }

    .guest-refund-panel h3 {
        margin: 0;
        color: #4A3525;
        font-size: .98rem;
        font-weight: 800;
    }

    .guest-refund-panel .form-control {
        border-color: #D8C9BD;
        border-radius: 9px;
        font-size: .88rem;
    }

    .guest-refund-panel .form-control:focus {
        border-color: #6F4E37;
        box-shadow: 0 0 0 3px rgba(111, 78, 55, .12);
    }

    .guest-refund-status {
        display: inline-flex;
        align-items: center;
        padding: 5px 10px;
        border-radius: 999px;
        background: #FFF1D6;
        color: #8A5A00;
        font-size: .72rem;
        font-weight: 800;
    }

    .guest-refund-image {
        display: inline-block;
        max-width: min(100%, 360px);
        max-height: 420px;
        border: 1px solid #E0D3C4;
        border-radius: 10px;
        object-fit: contain;
        background: #FFFFFF;
    }

    @media (max-width: 767.98px) {
        .order-status-wrap {
            padding-top: 28px;
            padding-bottom: 28px;
        }

        .order-status-card {
            box-shadow: 0 5px 18px rgba(44, 34, 30, .06);
        }

        /* On mobile, replace the tall timeline with a compact progress bar. */
        .status-timeline {
            display: none;
        }

        .mobile-order-progress {
            display: block;
        }

        .order-summary-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
            margin: 14px 0;
        }

        .order-summary-pickup {
            grid-column: 1 / -1;
        }

        .order-summary-item {
            padding: 10px;
        }

        .guest-orders-toggle {
            align-items: flex-start;
            padding: 0;
        }

        .guest-orders-toggle-copy {
            display: flex;
            min-width: 0;
            flex: 1 1 auto;
            flex-direction: column;
            gap: 3px;
        }

        .guest-orders-toggle-title {
            color: #4A3525;
            font-size: 1rem;
            font-weight: 800;
            line-height: 1.25;
        }

        .guest-orders-toggle-description {
            color: #756658;
            font-size: .75rem;
            font-weight: 400;
            line-height: 1.35;
        }

        .guest-orders-count {
            flex: 0 0 auto;
            margin-left: auto;
        }

        .guest-session-orders.is-collapsed .guest-orders-toggle {
            min-height: 44px;
            align-items: center;
        }

        .status-step {
            display: flex;
            align-items: center;
            gap: 10px;
            text-align: left;
            font-size: .8rem;
        }

        .status-step-dot {
            margin: 0;
            flex: 0 0 34px;
        }

        .guest-session-order-row {
            align-items: flex-start;
            flex-wrap: wrap;
            row-gap: 9px;
            padding: 12px 9px;
        }

        .guest-session-order-meta {
            flex: 1 1 100%;
        }

        .guest-order-row-action,
        .guest-selected-order-label {
            margin-left: auto;
        }

        .guest-session-order-row.is-current {
            padding: 11px 9px;
        }

        .guest-orders-footer {
            align-items: flex-start;
        }
    }

    @media (max-width: 575.98px) {
        .order-status-wrap {
            padding: 20px 10px;
        }

        .order-status-card {
            padding: 16px;
            border-radius: 15px;
        }

        .order-status-card > h2 {
            margin-bottom: 8px !important;
        }

        .status-pill {
            font-size: .75rem;
            padding: 6px 9px;
        }

        .guest-session-orders {
            margin-bottom: 18px;
        }

        .guest-session-order-row {
            gap: 9px;
        }

        .guest-session-order-row .btn {
            max-width: 100%;
            min-height: 40px;
            padding-inline: 14px;
        }

        .order-item {
            padding: 12px;
        }

        #orderStatusResult > .d-flex:first-child {
            gap: 10px !important;
        }

        [data-copy-link] {
            max-width: 100%;
            white-space: normal;
        }

        /* The floating page-top control can cover important order details on phones. */
        .back-to-top,
        #backToTop,
        #back-to-top,
        .back-to-top-btn,
        #backToTopBtn,
        #back-to-top-btn,
        .scroll-to-top,
        .scroll-to-top-btn,
        #scrollToTop,
        .scroll-top,
        #scroll-top,
        #scrollTopBtn,
        #topBtn,
        #toTopBtn {
            display: none !important;
        }
    }

    @media (max-width: 380px) {
        .order-status-wrap {
            padding: 16px 7px;
        }

        .order-status-card {
            padding: 13px;
            border-radius: 13px;
        }

        .guest-session-orders {
            padding: 10px;
        }

        .guest-session-order-row.is-current {
            padding: 10px 8px;
        }

        .order-item {
            padding: 10px;
        }

        .btn-brown,
        .guest-order-row-action {
            font-size: .78rem;
        }
    }
</style>

<main class="order-status-wrap">
    <section class="order-status-card">
        <h2 class="fw-bold mb-2" style="color:#4A3525;">Order Status</h2>

        <?php if ($order): ?>
            <p class="text-muted mb-3">
                Your secure order link is verified. This page refreshes your order status automatically while the order is active.
            </p>
            <button
                type="button"
                class="btn btn-outline-secondary btn-sm mb-3"
                id="toggleGuestLookup"
                aria-expanded="false"
                aria-controls="guestLookupPanel"
            >Look Up Another Order</button>
        <?php else: ?>
            <p class="text-muted mb-3">
                Paste the secure order link from your checkout confirmation into the field below. Local Milktea House will fill in the order details automatically when the link is valid.
            </p>
        <?php endif; ?>

        <div id="guestLookupPanel" class="<?= $order ? 'd-none' : '' ?> mb-4">
            <form method="GET" class="row g-3" autocomplete="off">
                <input
                    type="hidden"
                    id="guestClaimNumber"
                    name="claim_number"
                    value="<?= htmlspecialchars($order ? '' : $claimNumber, ENT_QUOTES, 'UTF-8') ?>"
                >
                <div class="col-12">
                    <label class="form-label fw-semibold" for="guestTrackingCode">Order Link</label>
                    <input
                        type="password"
                        id="guestTrackingCode"
                        name="track"
                        class="form-control"
                        value=""
                        placeholder="Paste the secure order link"
                        maxlength="4096"
                        spellcheck="false"
                        autocapitalize="off"
                        autocomplete="off"
                        aria-describedby="guestOrderLinkHelp"
                        required
                    >
                    <div class="form-text" id="guestOrderLinkHelp">Paste the complete order link copied from checkout. The claim number and access code are filled in automatically.</div>
                    <div id="guestTrackingLinkFeedback" class="form-text text-success d-none" role="status" aria-live="polite"></div>
                </div>

                <div class="col-12">
                    <button type="submit" class="btn btn-brown px-4">
                        Verify and View Order
                    </button>
                </div>
            </form>
        </div>

        <?php if (!$isAuthenticatedAccountSession && !empty($sessionOrderSummaries)): ?>
            <section class="guest-orders-overview" id="guestOrdersOverview" aria-label="Recent guest orders">
                <section class="guest-session-orders" id="guestActiveOrders" aria-labelledby="guestActiveOrdersTitle">
                    <div class="guest-orders-section-heading">
                        <div>
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <h3 id="guestActiveOrdersTitle">Active Orders</h3>
                                <?php if ($activeOrdersLinkForCopy !== ''): ?>
                                    <button
                                        type="button"
                                        class="btn btn-brown btn-sm px-3"
                                        data-copy-link="<?= htmlspecialchars($activeOrdersLinkForCopy, ENT_QUOTES, 'UTF-8') ?>"
                                        data-copy-success="Active order link copied"
                                    ><i class="bi bi-link-45deg me-1"></i>Copy Active Order Link</button>
                                    <span class="small text-muted" id="guestCopyFeedback" aria-live="polite"></span>
                                <?php endif; ?>
                            </div>
                            <span class="guest-orders-section-description">Orders that are still moving through the pick-up process.</span>
                        </div>
                        <span class="badge rounded-pill text-bg-light guest-orders-count" id="activeOrdersCount"><?= count($activeSessionOrderSummaries) ?></span>
                    </div>

                    <div class="guest-session-orders-list" id="guestActiveOrdersList">
                        <?php foreach ($activeSessionOrderSummaries as $sessionSummary): ?>
                            <?php
                                $summaryStatus = (string)$sessionSummary['status'];
                                $summaryLabel = $statusLabels[$summaryStatus] ?? ucfirst(str_replace('_', ' ', $summaryStatus));
                                $summaryIsCurrent = !empty($sessionSummary['is_current']);
                            ?>
                            <article class="guest-session-order-row <?= $summaryIsCurrent ? 'is-current' : '' ?>" data-order-row-id="<?= (int)$sessionSummary['id'] ?>">
                                <div class="guest-session-order-meta">
                                    <div class="guest-session-order-number"><?= htmlspecialchars((string)$sessionSummary['order_number'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="guest-session-order-subline">
                                        <?= htmlspecialchars((string)$sessionSummary['claim_number'], ENT_QUOTES, 'UTF-8') ?>
                                        · ₱<?= number_format((float)$sessionSummary['total_amount'], 2) ?>
                                        · Pick-up <?= htmlspecialchars(date('M j, g:i A', strtotime((string)$sessionSummary['pickup_date'] . ' ' . (string)$sessionSummary['pickup_time'])), ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                    <span
                                        class="guest-session-order-status"
                                        data-session-order-id="<?= (int)$sessionSummary['id'] ?>"
                                        data-session-order-status="<?= htmlspecialchars($summaryStatus, ENT_QUOTES, 'UTF-8') ?>"
                                        data-session-order-active="1"
                                    ><?= htmlspecialchars($summaryLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                                <?php if ($summaryIsCurrent): ?>
                                    <span class="guest-selected-order-label">Selected</span>
                                <?php else: ?>
                                    <a class="btn btn-brown btn-sm guest-order-row-action" data-ajax-view-order href="monitor-guest-order.php?session_order_id=<?= (int)$sessionSummary['id'] ?>">View Details</a>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <div class="guest-orders-empty <?= !empty($activeSessionOrderSummaries) ? 'd-none' : '' ?>" id="guestActiveOrdersEmpty">
                        No active orders right now. Your completed and cancelled orders are available under Past Orders.
                    </div>
                </section>

                <?php if (!empty($pastSessionOrderSummaries)): ?>
                    <?php
                        $selectedOrderIsPast = $order && in_array((string)$order['status'], ['completed', 'cancelled'], true);
                        $pastOrdersInitiallyOpen = empty($activeSessionOrderSummaries) || $selectedOrderIsPast;
                    ?>
                    <details class="guest-past-orders" id="guestPastOrders" <?= $pastOrdersInitiallyOpen ? 'open' : '' ?>>
                        <summary>
                            <span>
                                <span class="guest-past-orders-title">Past Orders</span>
                                <span class="guest-past-orders-description">Completed and cancelled orders.</span>
                            </span>
                            <span class="badge rounded-pill text-bg-light guest-orders-count" id="pastOrdersCount"><?= count($pastSessionOrderSummaries) ?></span>
                        </summary>
                        <div class="guest-past-orders-list" id="guestPastOrdersList">
                            <?php foreach ($pastSessionOrderSummaries as $sessionSummary): ?>
                                <?php
                                    $summaryStatus = (string)$sessionSummary['status'];
                                    $summaryLabel = $statusLabels[$summaryStatus] ?? ucfirst(str_replace('_', ' ', $summaryStatus));
                                    $summaryIsCurrent = !empty($sessionSummary['is_current']);
                                ?>
                                <article class="guest-session-order-row <?= $summaryIsCurrent ? 'is-current' : '' ?>" data-order-row-id="<?= (int)$sessionSummary['id'] ?>">
                                    <div class="guest-session-order-meta">
                                        <div class="guest-session-order-number"><?= htmlspecialchars((string)$sessionSummary['order_number'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="guest-session-order-subline">
                                            <?= htmlspecialchars((string)$sessionSummary['claim_number'], ENT_QUOTES, 'UTF-8') ?>
                                            · ₱<?= number_format((float)$sessionSummary['total_amount'], 2) ?>
                                            · <?= htmlspecialchars(date('M j, Y', strtotime((string)$sessionSummary['created_at'])), ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                        <span
                                            class="guest-session-order-status"
                                            data-session-order-id="<?= (int)$sessionSummary['id'] ?>"
                                            data-session-order-status="<?= htmlspecialchars($summaryStatus, ENT_QUOTES, 'UTF-8') ?>"
                                            data-session-order-active="0"
                                        ><?= htmlspecialchars($summaryLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php if (
                                            (string)($sessionSummary['status'] ?? '') === 'cancelled'
                                            && strtolower((string)($sessionSummary['payment_method'] ?? '')) === 'gcash'
                                            && trim((string)($sessionSummary['payment_screenshot'] ?? '')) !== ''
                                            && strtolower(trim((string)($sessionSummary['cancellation_reason'] ?? ''))) !== 'payment could not be verified'
                                            && in_array(strtolower((string)($sessionSummary['refund_status'] ?? 'none')), ['pending', 'refunded', 'rejected'], true)
                                        ): ?>
                                            <span class="guest-session-order-status" style="background:<?= strtolower((string)$sessionSummary['refund_status']) === 'pending' ? '#FFF1D6' : (strtolower((string)$sessionSummary['refund_status']) === 'refunded' ? '#E8F5EC' : '#FDECEC') ?>;color:<?= strtolower((string)$sessionSummary['refund_status']) === 'pending' ? '#8A5A00' : (strtolower((string)$sessionSummary['refund_status']) === 'refunded' ? '#287A46' : '#A33A3A') ?>;">
                                                <?= htmlspecialchars(ucwords((string)$sessionSummary['refund_status']), ENT_QUOTES, 'UTF-8') ?> Refund
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($summaryIsCurrent): ?>
                                        <span class="guest-selected-order-label">Selected</span>
                                    <?php else: ?>
                                        <a class="btn btn-brown btn-sm guest-order-row-action" data-ajax-view-order href="monitor-guest-order.php?session_order_id=<?= (int)$sessionSummary['id'] ?>">View Details</a>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php else: ?>
                    <details class="guest-past-orders d-none" id="guestPastOrders">
                        <summary>
                            <span><span class="guest-past-orders-title">Past Orders</span><span class="guest-past-orders-description">Completed and cancelled orders.</span></span>
                            <span class="badge rounded-pill text-bg-light guest-orders-count" id="pastOrdersCount">0</span>
                        </summary>
                        <div class="guest-past-orders-list" id="guestPastOrdersList"></div>
                    </details>
                <?php endif; ?>

                <div class="guest-orders-footer">
                    <span class="guest-orders-retention-note">Recent orders stay in this browser session for up to 24 hours. Saved secure order links can still be reopened separately.</span>
                    <button
                        type="button"
                        id="forgetGuestOrders"
                        data-csrf-token="<?= htmlspecialchars($guestTrackingCsrfToken, ENT_QUOTES, 'UTF-8') ?>"
                    >Forget Saved Orders</button>
                </div>
            </section>
        <?php endif; ?>

        <?php if (!$order && $error): ?>
            <div class="alert alert-danger border-0">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($order): ?>
            <?php
                $currentStatus = (string)$order['status'];
                $currentIndex = array_search($currentStatus, $workflowStatuses, true);
                // Cash orders enter order_queue directly; display that as the first guest-facing milestone.
                if ($currentStatus === 'order_queue') {
                    $currentIndex = 0;
                } elseif ($currentIndex === false) {
                    $currentIndex = -1;
                }
            ?>

            <div class="border-top pt-4" id="orderStatusResult" data-session-order-id="<?= (int)$order['id'] ?>" data-payment-method="<?= htmlspecialchars(strtolower((string)$order['payment_method']), ENT_QUOTES, 'UTF-8') ?>" data-initial-status="<?= htmlspecialchars($currentStatus, ENT_QUOTES, 'UTF-8') ?>" data-refund-status="<?= htmlspecialchars(strtolower((string)($order['refund_status'] ?? 'none')), ENT_QUOTES, 'UTF-8') ?>">
                <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                    <div>
                        <div class="text-muted small">Order Number</div>
                        <div class="fw-bold fs-5" style="color:#4A3525;">
                            <?= htmlspecialchars($order['order_number']) ?>
                        </div>
                    </div>

                    <div id="statusPillWrap">
                        <span class="status-pill" id="statusPill">
                            <?= htmlspecialchars($statusLabels[$currentStatus] ?? ucfirst(str_replace('_', ' ', $currentStatus))) ?>
                        </span>
                    </div>
                </div>

                <div class="mb-4">
                    <div class="text-muted small mb-1">Claim Number</div>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <div class="fw-bold" style="color:#4A3525;">
                            <?= htmlspecialchars($order['claim_number'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    </div>
                </div>

                <div class="status-timeline" id="statusTimeline">
                    <?php foreach ($workflowStatuses as $index => $status): ?>
                        <?php
                            $isDone = $currentIndex >= 0 && $index < $currentIndex;
                            $isCurrent = $index === $currentIndex;
                        ?>

                        <div
                            class="status-step <?= $isDone ? 'done' : '' ?> <?= $isCurrent ? 'current' : '' ?>"
                            data-status="<?= htmlspecialchars($status) ?>"
                        >
                            <div class="status-step-dot">
                                <i class="bi <?= $isDone ? 'bi-check-lg' : ($isCurrent ? 'bi-circle-fill' : 'bi-circle') ?>"></i>
                            </div>
                            <span>
                                <?= htmlspecialchars($currentStatus === 'order_queue' && $status === 'pending_verification' ? 'Awaiting Confirmation' : $statusLabels[$status]) ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php
                    $mobileProgressStep = $currentIndex >= 0 ? $currentIndex + 1 : 0;
                    $mobileProgressPercent = $currentIndex >= 0
                        ? (int)round(($mobileProgressStep / count($workflowStatuses)) * 100)
                        : 100;
                    $mobileProgressStepText = $currentIndex >= 0
                        ? 'Step ' . $mobileProgressStep . ' of ' . count($workflowStatuses)
                        : 'Stopped';
                    $mobileProgressLabel = $currentStatus === 'order_queue'
                        ? 'Awaiting Confirmation'
                        : ($statusLabels[$currentStatus] ?? ucfirst(str_replace('_', ' ', $currentStatus)));
                ?>
                <div class="mobile-order-progress <?= $currentStatus === 'cancelled' ? 'is-cancelled' : '' ?>" id="mobileOrderProgress" aria-label="Order progress">
                    <div class="mobile-progress-heading">
                        <span>Order progress</span>
                        <span class="mobile-progress-step" data-mobile-step><?= htmlspecialchars($mobileProgressStepText) ?></span>
                    </div>
                    <div
                        class="mobile-progress-track"
                        role="progressbar"
                        aria-label="Order progress"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        aria-valuenow="<?= $mobileProgressPercent ?>"
                    >
                        <div class="mobile-progress-fill" data-mobile-progress-fill style="width:<?= $mobileProgressPercent ?>%;"></div>
                    </div>
                    <div class="mobile-progress-caption">
                        <span>Current stage</span>
                        <span class="mobile-progress-current" data-mobile-current-stage><?= htmlspecialchars($mobileProgressLabel) ?></span>
                    </div>
                </div>

                <div class="order-summary-grid" aria-label="Pick-up and payment details">
                    <div class="order-summary-item order-summary-pickup">
                        <div class="order-summary-label">Pick-up</div>
                        <div class="order-summary-value">
                            <?= htmlspecialchars($order['pickup_date']) ?> @ <?= htmlspecialchars(date('h:i A', strtotime($order['pickup_time']))) ?>
                        </div>
                    </div>
                    <div class="order-summary-item">
                        <div class="order-summary-label">Payment</div>
                        <div class="order-summary-value text-uppercase"><?= htmlspecialchars($order['payment_method']) ?></div>
                    </div>
                    <div class="order-summary-item">
                        <div class="order-summary-label">Total</div>
                        <div class="order-summary-value">₱<?= number_format((float)$order['total_amount'], 2) ?></div>
                    </div>
                </div>

                <section class="guest-order-items-panel" id="guestOrderItemsPanel" data-compact-panel="items">
                    <button
                        type="button"
                        class="guest-order-items-toggle"
                        data-compact-toggle
                        aria-expanded="true"
                        aria-controls="guestOrderItemsContent"
                    >
                        <span>
                            <span class="guest-order-items-title">Order Items (<?= count($items) ?>)</span>
                            <span class="guest-order-items-hint">Products and customization details</span>
                        </span>
                        <span class="guest-panel-toggle-label" data-toggle-label>Hide details</span>
                        <i class="bi bi-chevron-up guest-panel-toggle-icon" data-toggle-icon aria-hidden="true"></i>
                    </button>
                    <div class="guest-order-items-content" id="guestOrderItemsContent">
                    <?php foreach ($items as $item): ?>
                        <div class="order-item mb-2">
                            <div class="d-flex justify-content-between gap-3">
                                <div class="fw-bold">
                                    <?= htmlspecialchars($item['product_name']) ?>
                                    × <?= (int)$item['quantity'] ?>
                                </div>

                                <div class="fw-semibold">
                                    ₱<?= number_format((float)$item['subtotal'], 2) ?>
                                </div>
                            </div>

                            <?php if (!empty($item['size']) || !empty($item['sugar_level'])): ?>
                                <div class="text-muted small">
                                    <?php if (!empty($item['size'])): ?>
                                        Size: <?= htmlspecialchars($item['size']) ?>
                                    <?php endif; ?>

                                    <?php if (!empty($item['sugar_level'])): ?>
                                        · Sugar: <?= htmlspecialchars($item['sugar_level']) ?>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($item['addons'])): ?>
                                <div class="text-muted small mt-1">
                                    Add-ons: <?= htmlspecialchars((string)$item['addons']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </section>

                <div
                    id="statusMessage"
                    class="alert <?= $currentStatus === 'cancelled' ? 'alert-danger' : 'alert-info' ?> border-0 mt-3 mb-2"
                >
                    <?php if ($currentStatus === 'pending_verification'): ?>
                        <?php if (strtolower((string)$order['payment_method']) === 'gcash'): ?>
                            Your GCash payment is waiting for verification by the store.
                        <?php else: ?>
                            Your cash order is waiting for confirmation by the store.
                        <?php endif; ?>
                    <?php elseif ($currentStatus === 'order_queue'): ?>
                        Your order has been received and is waiting for store confirmation.
                    <?php elseif ($currentStatus === 'confirmed'): ?>
                        Your order has been confirmed by the store.
                    <?php elseif ($currentStatus === 'preparing'): ?>
                        Your order is being prepared.
                    <?php elseif ($currentStatus === 'ready'): ?>
                        Your order is ready for pick-up.
                    <?php elseif ($currentStatus === 'completed'): ?>
                        Your order has been completed.
                    <?php elseif ($currentStatus === 'cancelled'): ?>
                        <?php if ($cancellationActorName !== ''): ?>
                            Your order has been cancelled by
                            <?= htmlspecialchars($cancellationActorName) ?>.
                        <?php elseif ($cancellationActorRole === 'staff'): ?>
                            Your order has been cancelled by a staff member.
                        <?php elseif (in_array($cancellationActorRole, ['admin', 'superadmin'], true)): ?>
                            Your order has been cancelled by an administrator.
                        <?php elseif ($cancellationActorRole === 'customer'): ?>
                            Your order has been cancelled by the customer.
                        <?php else: ?>
                            Your order has been cancelled by the store.
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <?php
                    $guestRefundStatus = strtolower(trim((string)($order['refund_status'] ?? 'none')));
                    $guestRefundApplies = (
                        $currentStatus === 'cancelled'
                        && strtolower(trim((string)($order['payment_method'] ?? ''))) === 'gcash'
                        && trim((string)($order['payment_screenshot'] ?? '')) !== ''
                        && strtolower(trim((string)($order['cancellation_reason'] ?? ''))) !== 'payment could not be verified'
                    );
                    $guestRefundEligible = $guestRefundApplies && $guestRefundStatus === 'pending';
                    $guestRefundDetailsSubmitted = (
                        trim((string)($order['refund_account_name'] ?? '')) !== ''
                        && trim((string)($order['refund_mobile_number'] ?? '')) !== ''
                    );
                    $guestRefundProofPath = trim((string)($order['refund_proof_image'] ?? ''));
                    $guestRefundProofUrl = $guestRefundProofPath !== '' ? '../' . ltrim($guestRefundProofPath, '/') : '';
                    $guestRefundSupportingPath = trim((string)($order['refund_supporting_image'] ?? ''));
                    $guestRefundSupportingUrl = $guestRefundSupportingPath !== '' ? '../' . ltrim($guestRefundSupportingPath, '/') : '';
                ?>

                <?php if ($guestRefundFlash): ?>
                    <div class="alert alert-<?= htmlspecialchars((string)$guestRefundFlash['type'], ENT_QUOTES, 'UTF-8') ?> border-0 mt-3 mb-2" role="status">
                        <?= htmlspecialchars((string)$guestRefundFlash['message'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <?php if ($guestRefundEligible): ?>
                    <section class="guest-refund-panel" id="guestRefundPanel" aria-labelledby="guestRefundTitle">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                            <div>
                                <h3 id="guestRefundTitle"><i class="bi bi-arrow-counterclockwise me-1"></i> GCash Refund</h3>
                                <div class="small text-muted mt-1">This order was cancelled and is eligible for a GCash refund.</div>
                            </div>
                            <span class="guest-refund-status">Refund Pending</span>
                        </div>

                        <?php if ($guestRefundDetailsSubmitted): ?>
                            <div class="alert alert-success py-2 small mb-3">
                                <i class="bi bi-check-circle me-1"></i>
                                Your GCash recipient details have been submitted. You may update them while the refund is pending.
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning py-2 small mb-3">
                                Please provide the GCash account details where you want to receive the refund.
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="monitor-guest-order.php" enctype="multipart/form-data" class="guest-refund-form">
                            <input type="hidden" name="submit_guest_refund_details" value="1">
                            <input type="hidden" name="guest_refund_order_id" value="<?= (int)$order['id'] ?>">
                            <input type="hidden" name="guest_refund_csrf_token" value="<?= htmlspecialchars($guestTrackingCsrfToken, ENT_QUOTES, 'UTF-8') ?>">

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold" for="guestRefundAccountName<?= (int)$order['id'] ?>">GCash Account Name</label>
                                    <input type="text" class="form-control" id="guestRefundAccountName<?= (int)$order['id'] ?>" name="refund_account_name" maxlength="100" autocomplete="name" value="<?= htmlspecialchars((string)($order['refund_account_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Name registered to GCash" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold" for="guestRefundMobile<?= (int)$order['id'] ?>">GCash Mobile Number</label>
                                    <input type="tel" class="form-control" id="guestRefundMobile<?= (int)$order['id'] ?>" name="refund_mobile_number" maxlength="16" inputmode="tel" autocomplete="tel" value="<?= htmlspecialchars((string)($order['refund_mobile_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="09XXXXXXXXX or +639XXXXXXXXX" required>
                                </div>
                                <div class="col-12">
                                    <label class="form-label small fw-semibold" for="guestRefundSupportingImage<?= (int)$order['id'] ?>">Supporting Screenshot (Optional)</label>
                                    <input type="file" class="form-control" id="guestRefundSupportingImage<?= (int)$order['id'] ?>" name="refund_supporting_image" accept="image/jpeg,image/png,image/webp">
                                    <div class="form-text">JPG, PNG, or WEBP. Maximum 5MB. You can leave this blank.</div>
                                    <?php if ($guestRefundSupportingUrl !== ''): ?>
                                        <div class="small mt-2">Previously uploaded: <a href="<?= htmlspecialchars($guestRefundSupportingUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">View supporting screenshot</a></div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="d-flex justify-content-end mt-3">
                                <button type="submit" class="btn btn-brown px-4">
                                    <i class="bi bi-send me-1"></i>
                                    <?= $guestRefundDetailsSubmitted ? 'Update Refund Details' : 'Submit Refund Details' ?>
                                </button>
                            </div>
                        </form>
                    </section>
                <?php elseif ($guestRefundApplies && in_array($guestRefundStatus, ['refunded', 'rejected'], true)): ?>
                    <section class="guest-refund-panel" id="guestRefundPanel" aria-labelledby="guestRefundTitle">
                        <h3 id="guestRefundTitle" class="mb-3"><i class="bi bi-arrow-counterclockwise me-1"></i> GCash Refund</h3>

                        <?php if ($guestRefundStatus === 'refunded'): ?>
                            <div class="alert alert-success mb-3">
                                <strong><i class="bi bi-check-circle me-1"></i> Refund processed.</strong>
                                <div class="small mt-1">The store has recorded your refund as completed.</div>
                            </div>
                            <?php if ($guestRefundProofUrl !== ''): ?>
                                <div class="small fw-bold mb-2" style="color:#4A3525;">Store's Refund Proof</div>
                                <a href="<?= htmlspecialchars($guestRefundProofUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" aria-label="Open store refund proof in a new tab">
                                    <img src="<?= htmlspecialchars($guestRefundProofUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Store's GCash refund proof" class="guest-refund-image">
                                </a>
                                <div class="small text-muted mt-2">Select the image to view it full size.</div>
                            <?php else: ?>
                                <div class="alert alert-warning small mb-0">The refund was marked as processed, but no refund screenshot was saved. Please contact the store administrator.</div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="alert alert-danger mb-3">
                                <strong>Refund rejected.</strong>
                                <?= htmlspecialchars(trim((string)($order['refund_rejection_reason'] ?? '')) ?: 'Please contact the store for more information.', ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($guestRefundDetailsSubmitted): ?>
                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <div class="small text-muted">GCash Account Name</div>
                                    <div class="fw-semibold"><?= htmlspecialchars((string)$order['refund_account_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="small text-muted">GCash Mobile Number</div>
                                    <div class="fw-semibold"><?= htmlspecialchars((string)$order['refund_mobile_number'], ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                            </div>
                            <?php if (!empty($order['refund_details_submitted_at'])): ?>
                                <div class="small text-muted mt-2">Details submitted on <?= htmlspecialchars(date('M j, Y g:i A', strtotime((string)$order['refund_details_submitted_at'])), ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                            <?php if ($guestRefundSupportingUrl !== ''): ?>
                                <div class="small mt-2"><i class="bi bi-paperclip me-1"></i>Your supporting screenshot: <a href="<?= htmlspecialchars($guestRefundSupportingUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">View supporting screenshot</a></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="alert alert-warning small mb-0">No GCash recipient details were submitted for this order.</div>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>

                <div class="last-checked" id="lastChecked">
                    Status shown as of <?= htmlspecialchars(date('h:i A')) ?>
                </div>

                <?php $feedbackActionUrl = 'submit-feedback.php'; ?>

    <div class="order-feedback-box mt-4 pt-3 border-top <?= $currentStatus === 'completed' ? '' : 'd-none' ?>" data-feedback-box id="guestFeedbackSection">
        <h6 class="fw-bold mb-2" style="color:#4A3525;">Your Feedback</h6>

        <div data-feedback-sent class="<?= $existingFeedback ? '' : 'd-none' ?>">
            <div class="alert alert-success border-0 mb-0">
                <div class="small fw-semibold mb-1">Thank you for your feedback!</div>
                <div data-feedback-text style="white-space:pre-line;"><?= $existingFeedback ? htmlspecialchars((string)$existingFeedback['message']) : '' ?></div>
                <div class="small text-muted mt-1" data-feedback-date><?= $existingFeedback ? htmlspecialchars(date('M j, Y g:i A', strtotime((string)$existingFeedback['created_at']))) : '' ?></div>
            </div>
        </div>

        <form
            class="order-feedback-form <?= $existingFeedback ? 'd-none' : '' ?>"
            method="POST"
            action="<?= htmlspecialchars($feedbackActionUrl) ?>"
            novalidate
        >
            <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
            <input type="hidden" name="claim_number" value="<?= htmlspecialchars($order['claim_number']) ?>">
            <label class="form-label small text-muted mb-1">
                How was your order? Tell us what you liked or what we can improve.
            </label>
            <textarea
                name="message"
                class="form-control"
                rows="3"
                maxlength="500"
                placeholder="Write your feedback here..."
                required
            ></textarea>
            <div class="d-flex justify-content-between align-items-center mt-2">
                <small class="text-muted"><span data-feedback-count>0</span>/500</small>
                <button type="submit" class="btn btn-sm text-white" style="background:#4A3525;">Send Feedback</button>
            </div>
            <div class="small text-danger mt-2 d-none" data-feedback-error></div>
        </form>
    </div>
            </div>

            <?php if ($order): ?>
                <script>
                (function () {
                    const statusLabels = <?= json_encode($statusLabels) ?>;
                    const workflowStatuses = <?= json_encode($workflowStatuses) ?>;
                    let cancellationActorName = <?= json_encode($cancellationActorName) ?>;
                    let cancellationActorRole = <?= json_encode($cancellationActorRole) ?>;
                    let pollTimer = null;
                    let requestInProgress = false;

                    function updateStatusMessageStyle(statusMessage, status) {
                        if (!statusMessage) return;
                        statusMessage.classList.remove('alert-info', 'alert-success', 'alert-danger');
                        if (status === 'cancelled') {
                            statusMessage.classList.add('alert-danger');
                        } else if (status === 'completed') {
                            statusMessage.classList.add('alert-success');
                        } else {
                            statusMessage.classList.add('alert-info');
                        }
                    }

                    function messageForStatus(status, paymentMethod) {
                        if (status === 'order_queue') return 'Your order has been received and is waiting for store confirmation.';
                        if (status === 'pending_verification') {
                            return paymentMethod === 'gcash'
                                ? 'Your GCash payment is waiting for verification by the store.'
                                : 'Your cash order is waiting for confirmation by the store.';
                        }
                        if (status === 'confirmed') return 'Your order has been confirmed by the store.';
                        if (status === 'preparing') return 'Your order is being prepared.';
                        if (status === 'ready') return 'Your order is ready for pick-up.';
                        if (status === 'completed') return 'Your order has been completed.';
                        if (status === 'cancelled') {
                            if (cancellationActorName) return 'Your order has been cancelled by ' + cancellationActorName + '.';
                            if (cancellationActorRole === 'staff') return 'Your order has been cancelled by a staff member.';
                            if (['admin', 'superadmin'].includes(cancellationActorRole)) return 'Your order has been cancelled by an administrator.';
                            if (cancellationActorRole === 'customer') return 'Your order has been cancelled by the customer.';
                            return 'Your order has been cancelled by the store.';
                        }
                        return '';
                    }

                    function renderTimeline(timeline, status) {
                        if (!timeline) return;
                        const timelineStatus = status === 'order_queue' ? 'pending_verification' : status;
                        const currentIndex = workflowStatuses.indexOf(timelineStatus);
                        timeline.querySelectorAll('[data-status]').forEach(function (step) {
                            const index = workflowStatuses.indexOf(step.getAttribute('data-status'));
                            const dot = step.querySelector('.status-step-dot i');
                            step.classList.toggle('done', currentIndex >= 0 && index < currentIndex);
                            step.classList.toggle('current', index === currentIndex);
                            if (dot) {
                                dot.className = 'bi ' + (
                                    currentIndex >= 0 && index < currentIndex
                                        ? 'bi-check-lg'
                                        : index === currentIndex ? 'bi-circle-fill' : 'bi-circle'
                                );
                            }
                        });
                    }

                    function renderMobileProgress(result, status) {
                        const progress = result.querySelector('#mobileOrderProgress');
                        if (!progress) return;

                        const normalizedStatus = status === 'order_queue' ? 'pending_verification' : status;
                        const currentIndex = workflowStatuses.indexOf(normalizedStatus);
                        const stepLabel = progress.querySelector('[data-mobile-step]');
                        const currentStage = progress.querySelector('[data-mobile-current-stage]');
                        const fill = progress.querySelector('[data-mobile-progress-fill]');
                        const track = progress.querySelector('[role="progressbar"]');
                        const cancelled = status === 'cancelled';
                        const percent = cancelled ? 100 : (currentIndex >= 0 ? Math.round(((currentIndex + 1) / workflowStatuses.length) * 100) : 0);

                        progress.classList.toggle('is-cancelled', cancelled);
                        if (stepLabel) stepLabel.textContent = cancelled ? 'Stopped' : (currentIndex >= 0 ? 'Step ' + (currentIndex + 1) + ' of ' + workflowStatuses.length : 'Status update');
                        if (currentStage) {
                            currentStage.textContent = status === 'order_queue'
                                ? 'Awaiting Confirmation'
                                : (statusLabels[status] || status.replaceAll('_', ' '));
                        }
                        if (fill) fill.style.width = percent + '%';
                        if (track) track.setAttribute('aria-valuenow', String(percent));
                    }

                    async function refreshStatus() {
                        const result = document.getElementById('orderStatusResult');
                        if (!result || requestInProgress) return;
                        const orderId = result.getAttribute('data-session-order-id');
                        if (!orderId) return;

                        const statusPill = result.querySelector('#statusPill');
                        const statusMessage = result.querySelector('#statusMessage');
                        const timeline = result.querySelector('#statusTimeline');
                        const lastChecked = result.querySelector('#lastChecked');
                        const paymentMethod = (result.getAttribute('data-payment-method') || '').toLowerCase();

                        requestInProgress = true;
                        try {
                            const statusUrl = 'monitor-guest-order.php?ajax=status&session_order_id=' + encodeURIComponent(orderId);
                            const response = await fetch(statusUrl, {
                                method: 'GET',
                                cache: 'no-store',
                                credentials: 'same-origin',
                                headers: { 'Accept': 'application/json' }
                            });
                            if (!response.ok) return;
                            const data = await response.json();
                            if (!data.success || !data.status) return;

                            const previousStatus = result.getAttribute('data-initial-status') || '';
                            const currentStatus = data.status;
                            cancellationActorName = data.cancellation_actor_name || '';
                            cancellationActorRole = data.cancellation_actor_role || '';

                            if (currentStatus === 'cancelled' && previousStatus !== 'cancelled') {
                                // Reload once when an active order is cancelled so any eligible GCash refund form is rendered from the latest database values.
                                window.location.reload();
                                return;
                            }

                            if (statusPill) {
                                statusPill.textContent = data.status_label || statusLabels[currentStatus] || currentStatus;
                            }
                            if (statusMessage) {
                                statusMessage.textContent = messageForStatus(currentStatus, paymentMethod);
                                updateStatusMessageStyle(statusMessage, currentStatus);
                            }
                            renderTimeline(timeline, currentStatus);
                            renderMobileProgress(result, currentStatus);

                            const feedbackSection = result.querySelector('#guestFeedbackSection');
                            if (currentStatus === 'completed' && feedbackSection) {
                                feedbackSection.classList.remove('d-none');
                            }
                            if (lastChecked) {
                                lastChecked.textContent = 'Status updated at ' + new Date().toLocaleTimeString([], {
                                    hour: '2-digit', minute: '2-digit'
                                });
                            }
                            result.setAttribute('data-initial-status', currentStatus);

                            if (currentStatus === 'completed' || currentStatus === 'cancelled') {
                                if (pollTimer) window.clearInterval(pollTimer);
                                pollTimer = null;
                            }
                        } catch (error) {
                            /* Keep the page usable if a background status request fails. */
                        } finally {
                            requestInProgress = false;
                        }
                    }

                    let refundPollTimer = null;
                    let refundRequestInProgress = false;

                    async function refreshRefundStatus() {
                        const result = document.getElementById('orderStatusResult');
                        if (!result || refundRequestInProgress) return;
                        const orderId = result.getAttribute('data-session-order-id');
                        const paymentMethod = (result.getAttribute('data-payment-method') || '').toLowerCase();
                        const currentStatus = result.getAttribute('data-initial-status') || '';
                        const currentRefundStatus = result.getAttribute('data-refund-status') || 'none';

                        if (!orderId || currentStatus !== 'cancelled' || paymentMethod !== 'gcash' || currentRefundStatus !== 'pending') {
                            return;
                        }

                        refundRequestInProgress = true;
                        try {
                            const response = await fetch(
                                'monitor-guest-order.php?ajax=status&session_order_id=' + encodeURIComponent(orderId),
                                {
                                    method: 'GET',
                                    cache: 'no-store',
                                    credentials: 'same-origin',
                                    headers: { 'Accept': 'application/json' }
                                }
                            );
                            if (!response.ok) return;
                            const data = await response.json();
                            if (!data.success) return;

                            const latestRefundStatus = String(data.refund_status || 'none').toLowerCase();
                            if (latestRefundStatus !== currentRefundStatus) {
                                // Reload the selected order so the customer sees the form, proof, or rejection message that matches the saved refund state.
                                window.location.reload();
                                return;
                            }
                        } catch (error) {
                            // Keep the displayed refund information when a background check fails.
                        } finally {
                            refundRequestInProgress = false;
                        }
                    }

                    function restartRefundPolling() {
                        if (refundPollTimer) window.clearInterval(refundPollTimer);
                        refundPollTimer = null;

                        const result = document.getElementById('orderStatusResult');
                        if (!result) return;

                        const isPendingGcashRefund =
                            (result.getAttribute('data-initial-status') || '') === 'cancelled'
                            && (result.getAttribute('data-payment-method') || '').toLowerCase() === 'gcash'
                            && (result.getAttribute('data-refund-status') || 'none') === 'pending';

                        if (isPendingGcashRefund) {
                            refreshRefundStatus();
                            refundPollTimer = window.setInterval(refreshRefundStatus, 15000);
                        }
                    }

                    function restartPolling() {
                        if (pollTimer) window.clearInterval(pollTimer);
                        pollTimer = null;
                        const result = document.getElementById('orderStatusResult');
                        if (!result) return;
                        refreshStatus();
                        const status = result.getAttribute('data-initial-status') || '';
                        if (status !== 'completed' && status !== 'cancelled') {
                            pollTimer = window.setInterval(refreshStatus, 8000);
                        }
                        restartRefundPolling();
                    }

                    window.localiteaGuestStatusController = {
                        restart: restartPolling,
                        refresh: refreshStatus,
                        refreshRefund: refreshRefundStatus
                    };
                    restartPolling();
                })();
                </script>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</main>

<script>
/* A guest only needs to paste the complete secure order link. */
(function () {
    const claimInput = document.getElementById('guestClaimNumber');
    const trackingInput = document.getElementById('guestTrackingCode');
    const feedback = document.getElementById('guestTrackingLinkFeedback');
    const lookupForm = trackingInput ? trackingInput.closest('form') : null;
    const tokenPattern = /^[a-f0-9]{64}$/i;

    if (!claimInput || !trackingInput || !lookupForm) return;

    function parsePastedTrackingLink() {
        /* [COMBINED LINK] CHANGE 5a of 5 — reset the "combined link" flag on every parse. */
        trackingInput.dataset.bundle = '';

        const value = trackingInput.value.trim();
        if (!value || tokenPattern.test(value)) return false;

        // Only process values that look like a URL; manual codes are left alone.
        if (!/^(https?:\/\/|\/)/i.test(value)) return false;

        try {
            const url = new URL(value, window.location.href);

            /* [COMBINED LINK] CHANGE 5b — combined link: ?orders=CLM-0001.<token>,CLM-0002.<token> */
            const bundle = (url.searchParams.get('orders') || '').trim();
            if (bundle) {
                const parts = bundle.split(',').filter(function (part) {
                    return /\.[a-f0-9]{64}$/i.test(part.trim());
                });
                if (parts.length) {
                    claimInput.value = '';
                    trackingInput.dataset.bundle = '1';
                    if (feedback) {
                        feedback.textContent = 'Order link recognized (' + parts.length + (parts.length === 1 ? ' order' : ' orders') + '). Ready to open.';
                        feedback.classList.remove('d-none');
                    }
                    return true;
                }
            }

            const claim = (url.searchParams.get('claim') || url.searchParams.get('claim_number') || '').trim();
            const token = (url.searchParams.get('track') || url.searchParams.get('tracking_token') || '').trim();

            if (!tokenPattern.test(token)) return false;

            if (claim) claimInput.value = claim;
            trackingInput.value = token;
            if (feedback) {
                feedback.textContent = 'Order link recognized. Ready to open your order status.';
                feedback.classList.remove('d-none');
            }
            return true;
        } catch (error) {
            return false;
        }
    }

    trackingInput.addEventListener('input', parsePastedTrackingLink);
    trackingInput.addEventListener('paste', function () {
        window.setTimeout(parsePastedTrackingLink, 0);
    });
    lookupForm.addEventListener('submit', function (event) {
        parsePastedTrackingLink();

        /* [COMBINED LINK] CHANGE 5c — a combined link is verified by the server, so let it submit. */
        if (trackingInput.dataset.bundle === '1') return;

        const token = trackingInput.value.trim();
        if (!claimInput.value.trim() || !tokenPattern.test(token)) {
            event.preventDefault();
            trackingInput.setCustomValidity('Paste the complete secure order link copied from checkout.');
            trackingInput.reportValidity();
            trackingInput.addEventListener('input', function clearOrderLinkError() {
                trackingInput.setCustomValidity('');
                trackingInput.removeEventListener('input', clearOrderLinkError);
            });
        }
    });
})();

/* Remove tracking tokens persisted by the previous browser-local implementation. */
(function () {
    try {
        localStorage.removeItem('localitea_guest_order_links_v1');
    } catch (error) {
        // The secure tracking link remains available even when browser storage is disabled.
    }
})();

/* Compact secondary panels on small screens while leaving the desktop details expanded. */
(function () {
    const mobileLayout = window.matchMedia('(max-width: 767.98px)');

    function setPanelCollapsed(panel, collapsed) {
        if (!panel) return;
        const kind = panel.getAttribute('data-compact-panel');
        const toggle = panel.querySelector('[data-compact-toggle]');
        panel.classList.toggle('is-collapsed', Boolean(collapsed));
        panel.dataset.compactInitialized = '1';
        if (toggle) {
            toggle.setAttribute('aria-expanded', String(!collapsed));
            const label = toggle.querySelector('[data-toggle-label]');
            if (label) {
                label.textContent = collapsed
                    ? (kind === 'orders' ? 'Show orders' : 'Show details')
                    : (kind === 'orders' ? 'Hide orders' : 'Hide details');
            }
            const icon = toggle.querySelector('[data-toggle-icon]');
            if (icon) icon.className = 'bi ' + (collapsed ? 'bi-chevron-down' : 'bi-chevron-up') + ' guest-panel-toggle-icon';
        }
    }

    function initializeCompactPanels(root, preservedState) {
        const settings = preservedState || {};
        const panels = root.querySelectorAll ? root.querySelectorAll('[data-compact-panel]') : [];
        panels.forEach(function (panel) {
            const kind = panel.getAttribute('data-compact-panel');
            const stateKey = kind === 'orders' ? 'guestSessionOrdersCollapsed' : 'guestOrderItemsCollapsed';
            if (typeof settings[stateKey] === 'boolean') {
                setPanelCollapsed(panel, settings[stateKey]);
            } else if (panel.dataset.compactInitialized !== '1') {
                setPanelCollapsed(panel, mobileLayout.matches);
            }
        });
    }

    document.addEventListener('click', function (event) {
        const toggle = event.target.closest ? event.target.closest('[data-compact-toggle]') : null;
        if (!toggle) return;
        const panel = toggle.closest('[data-compact-panel]');
        if (!panel) return;
        setPanelCollapsed(panel, !panel.classList.contains('is-collapsed'));
    });

    window.localiteaInitializeCompactPanels = initializeCompactPanels;
    initializeCompactPanels(document);
})();

/* Order feedback: works for dynamically loaded modals too (event delegation). */
(function () {
    const lookupToggle = document.getElementById('toggleGuestLookup');
    const lookupPanel = document.getElementById('guestLookupPanel');
    if (lookupToggle && lookupPanel) {
        lookupToggle.addEventListener('click', function () {
            const isHidden = lookupPanel.classList.toggle('d-none');
            lookupToggle.setAttribute('aria-expanded', String(!isHidden));
            lookupToggle.textContent = isHidden ? 'Look Up Another Order' : 'Hide Manual Lookup';
            if (!isHidden) {
                const orderLinkInput = document.getElementById('guestTrackingCode');
                if (orderLinkInput) orderLinkInput.focus();
            }
        });
    }

    // Poll active orders only. When one finishes, move its row into Past Orders.
    // Every status lookup is still authorized by the server-side session credential.
    async function refreshSessionOrderBadges() {
        const badges = document.querySelectorAll('[data-session-order-active="1"][data-session-order-id][data-session-order-status]');
        for (const badge of badges) {
            const orderId = badge.getAttribute('data-session-order-id');
            if (!orderId) continue;
            try {
                const response = await fetch(
                    'monitor-guest-order.php?ajax=status&session_order_id=' + encodeURIComponent(orderId),
                    { method: 'GET', credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'application/json' } }
                );
                if (!response.ok) continue;
                const data = await response.json();
                if (!data.success || !data.status) continue;

                badge.textContent = data.status_label || data.status.replaceAll('_', ' ');
                badge.setAttribute('data-session-order-status', data.status);

                if (data.status === 'completed' || data.status === 'cancelled') {
                    badge.setAttribute('data-session-order-active', '0');
                    const row = badge.closest('[data-order-row-id]');
                    const pastList = document.getElementById('guestPastOrdersList');
                    const pastSection = document.getElementById('guestPastOrders');
                    const activeCount = document.getElementById('activeOrdersCount');
                    const pastCount = document.getElementById('pastOrdersCount');
                    const activeList = document.getElementById('guestActiveOrdersList');

                    if (row && pastList && activeList && row.parentElement === activeList) {
                        pastList.prepend(row);
                        if (pastSection) {
                            pastSection.classList.remove('d-none');
                            if (String(document.getElementById('orderStatusResult')?.getAttribute('data-session-order-id') || '') === String(orderId)) {
                                pastSection.open = true;
                            }
                        }
                    }

                    const remainingActive = activeList ? activeList.querySelectorAll('[data-order-row-id]').length : 0;
                    const totalPast = pastList ? pastList.querySelectorAll('[data-order-row-id]').length : 0;
                    if (activeCount) activeCount.textContent = String(remainingActive);
                    if (pastCount) pastCount.textContent = String(totalPast);
                    const emptyState = document.getElementById('guestActiveOrdersEmpty');
                    if (emptyState && remainingActive === 0) emptyState.classList.remove('d-none');
                }
            } catch (error) {
                // Leave the last known status visible when a background request fails.
            }
        }
    }

    async function showSessionOrderWithoutReload(link, pushHistory) {
        if (!link || link.dataset.loading === '1') return;
        const originalLabel = link.textContent.trim();
        link.dataset.loading = '1';
        link.classList.add('disabled');
        link.setAttribute('aria-disabled', 'true');
        link.textContent = 'Loading...';

        try {
            const response = await fetch(link.href, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'Accept': 'text/html' },
                referrerPolicy: 'no-referrer'
            });
            if (!response.ok) throw new Error('Order details could not be loaded.');

            const html = await response.text();
            const nextDocument = new DOMParser().parseFromString(html, 'text/html');
            const nextResult = nextDocument.querySelector('#orderStatusResult');
            const currentResult = document.getElementById('orderStatusResult');
            if (!nextResult || !currentResult) throw new Error('Order details were not found.');

            const nextOrdersOverview = nextDocument.getElementById('guestOrdersOverview');
            const currentOrdersOverview = document.getElementById('guestOrdersOverview');
            const currentItemsPanel = currentResult.querySelector('[data-compact-panel="items"]');
            const compactPanelState = {
                guestOrderItemsCollapsed: currentItemsPanel ? currentItemsPanel.classList.contains('is-collapsed') : null
            };

            currentResult.replaceWith(document.importNode(nextResult, true));
            if (nextOrdersOverview && currentOrdersOverview) {
                currentOrdersOverview.replaceWith(document.importNode(nextOrdersOverview, true));
            } else if (nextOrdersOverview && !currentOrdersOverview) {
                const currentResultAfterSwap = document.getElementById('orderStatusResult');
                if (currentResultAfterSwap) currentResultAfterSwap.parentNode.insertBefore(document.importNode(nextOrdersOverview, true), currentResultAfterSwap);
            } else if (!nextOrdersOverview && currentOrdersOverview) {
                currentOrdersOverview.remove();
            }

            if (window.localiteaInitializeCompactPanels) {
                window.localiteaInitializeCompactPanels(document, compactPanelState);
            }

            if (pushHistory) {
                window.history.pushState({ sessionOrderId: new URL(link.href).searchParams.get('session_order_id') }, '', link.href);
            }
            if (window.localiteaGuestStatusController) {
                window.localiteaGuestStatusController.restart();
            }
            refreshSessionOrderBadges();
        } catch (error) {
            window.alert(error && error.message ? error.message : 'Could not load this order. Please try again.');
        } finally {
            link.dataset.loading = '0';
            link.classList.remove('disabled');
            link.removeAttribute('aria-disabled');
            link.textContent = originalLabel;
        }
    }

    document.addEventListener('click', function (event) {
        const link = event.target.closest ? event.target.closest('[data-ajax-view-order]') : null;
        if (!link) return;
        event.preventDefault();
        showSessionOrderWithoutReload(link, true);
    });

    window.addEventListener('popstate', function () {
        const currentUrl = new URL(window.location.href);
        const selectedOrderId = currentUrl.searchParams.get('session_order_id');
        /* [COMBINED LINK] CHANGE 5d — also treat ?orders=... as a secure order link. */
        const hasSecureOrderLink =
            currentUrl.searchParams.has('orders') ||
            currentUrl.searchParams.has('claim') ||
            currentUrl.searchParams.has('claim_number') ||
            currentUrl.searchParams.has('track') ||
            currentUrl.searchParams.has('tracking_token');
        const temporaryLink = document.createElement('a');

        if (selectedOrderId && /^\d+$/.test(selectedOrderId)) {
            temporaryLink.href = 'monitor-guest-order.php?session_order_id=' + encodeURIComponent(selectedOrderId);
        } else if (hasSecureOrderLink) {
            temporaryLink.href = currentUrl.toString();
        } else {
            temporaryLink.href = window.location.pathname;
        }

        temporaryLink.textContent = 'View Order';
        showSessionOrderWithoutReload(temporaryLink, false);
    });

    document.addEventListener('click', async function (event) {
        const button = event.target.closest ? event.target.closest('#forgetGuestOrders') : null;
        if (!button || button.disabled) return;

        const confirmed = window.confirm(
            'Remove the recent guest-order list from this browser? This will not cancel or delete your actual orders. You can still use any secure order-status links you saved.'
        );
        if (!confirmed) return;

        const originalLabel = button.textContent;
        button.disabled = true;
        button.textContent = 'Removing...';

        try {
            const body = new URLSearchParams();
            body.set('ajax', 'forget_orders');
            body.set('csrf_token', button.getAttribute('data-csrf-token') || '');

            const response = await fetch(window.location.pathname, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                },
                body: body.toString()
            });
            const data = await response.json().catch(function () { return null; });
            if (!response.ok || !data || !data.success) {
                throw new Error(data && data.message ? data.message : 'Could not remove saved orders. Please refresh and try again.');
            }

            window.location.replace(window.location.pathname);
        } catch (error) {
            window.alert(error && error.message ? error.message : 'Could not remove saved orders. Please try again.');
            button.disabled = false;
            button.textContent = originalLabel;
        }
    });

    if (document.querySelector('[data-session-order-id][data-session-order-status]')) {
        refreshSessionOrderBadges();
        window.setInterval(refreshSessionOrderBadges, 15000);
    }

    async function copyGuestText(text) {
        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            await navigator.clipboard.writeText(text);
            return;
        }

        // Fallback for browsers where Clipboard API is unavailable.
        const helper = document.createElement('textarea');
        helper.value = text;
        helper.setAttribute('readonly', 'readonly');
        helper.style.position = 'fixed';
        helper.style.left = '-9999px';
        helper.style.top = '0';
        document.body.appendChild(helper);
        helper.select();
        const copied = document.execCommand('copy');
        helper.remove();
        if (!copied) throw new Error('Clipboard copy failed');
    }

    document.addEventListener('click', async function (event) {
        const button = event.target.closest ? event.target.closest('[data-copy-link]') : null;
        if (!button) return;

        const rawValue = button.getAttribute('data-copy-link');
        const value = new URL(rawValue, window.location.href).href;
        const oldLabel = button.textContent.trim();
        const feedback = document.getElementById('guestCopyFeedback');

        button.disabled = true;
        try {
            await copyGuestText(value);
            button.textContent = 'Copied!';
            if (feedback) feedback.textContent = button.getAttribute('data-copy-success') || 'Copied';
        } catch (error) {
            if (feedback) feedback.textContent = 'Copy failed. Please select and copy the link manually.';
            button.textContent = 'Copy failed';
        } finally {
            window.setTimeout(function () {
                button.textContent = oldLabel;
                button.disabled = false;
            }, 1800);
        }
    });

    document.addEventListener('input', function (event) {
        var textarea = event.target.closest ? event.target.closest('.order-feedback-form textarea') : null;
        if (!textarea) return;

        var counter = textarea.form.querySelector('[data-feedback-count]');
        if (counter) counter.textContent = textarea.value.length;
    });

    document.addEventListener('submit', function (event) {
        var form = event.target.closest ? event.target.closest('.order-feedback-form') : null;
        if (!form) return;

        event.preventDefault();
        if (form.dataset.sending === '1') return;

        var box = form.closest('[data-feedback-box]');
        var sent = box.querySelector('[data-feedback-sent]');
        var errorBox = form.querySelector('[data-feedback-error]');
        var button = form.querySelector('button[type="submit"]');
        var textarea = form.querySelector('textarea[name="message"]');
        var oldLabel = button.textContent;

        function showError(message) {
            errorBox.textContent = message;
            errorBox.classList.remove('d-none');
        }

        errorBox.classList.add('d-none');

        if (!textarea.value.trim()) {
            showError('Please write your feedback first.');
            return;
        }

        form.dataset.sending = '1';
        button.disabled = true;
        button.textContent = 'Sending...';

        fetch(form.getAttribute('action'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
            body: new FormData(form)
        })
        .then(function (response) {
            return response.json().catch(function () {
                return { success: false, message: 'Unexpected response from the server.' };
            });
        })
        .then(function (data) {
            if (data.success && data.feedback) {
                sent.querySelector('[data-feedback-text]').textContent = data.feedback.message;
                sent.querySelector('[data-feedback-date]').textContent = data.feedback.created_at;
                sent.classList.remove('d-none');
                form.classList.add('d-none');

                /* Hide any "Give Feedback" shortcut buttons for this order. */
                var orderId = form.querySelector('input[name="order_id"]').value;
                document.querySelectorAll('[data-feedback-btn="' + orderId + '"]').forEach(function (btn) {
                    btn.remove();
                });
            } else {
                showError(data.message || 'Could not send your feedback. Please try again.');
            }
        })
        .catch(function () {
            showError('Network error. Please try again.');
        })
        .then(function () {
            form.dataset.sending = '0';
            button.disabled = false;
            button.textContent = oldLabel;
        });
    });
})();
</script>

<?php require_once '../includes/footer.php'; ?>