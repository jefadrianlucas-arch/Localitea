<?php
session_start();

require_once '../includes/db.php';

/* =========================================================
   STAFF ACCESS ONLY
   Admin users are NOT allowed to use this page.
========================================================= */
if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['user_role'] ?? '') !== 'staff'
) {
    header('Location: ../auth/login.php');
    exit;
}

$staff_id = (int)($_SESSION['user_id'] ?? 0);
$staff_role = (string)($_SESSION['user_role'] ?? 'staff');

$isAjaxRequest =
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['ajax'] ?? '') === '1';

/* =========================================================
   FILTERS + PAGINATION
========================================================= */
$valid_filters = [
    'pending_verification',
    'confirmed',
    'preparing',
    'ready'
];

$selected_status = $_GET['status'] ?? 'pending_verification';

if (!in_array($selected_status, $valid_filters, true)) {
    $selected_status = 'pending_verification';
}

$search = trim((string)($_GET['q'] ?? ''));

if (mb_strlen($search) > 100) {
    $search = mb_substr($search, 0, 100);
}

/* =========================================================
   CANCELLED ORDER FILTERS
   Cancelled records are a separate historical section below
   the active workflow.
========================================================= */
$cancelled_search = trim((string)($_GET['cancelled_q'] ?? ''));

if (mb_strlen($cancelled_search) > 100) {
    $cancelled_search = mb_substr($cancelled_search, 0, 100);
}

$cancelled_period = strtolower(
    trim((string)($_GET['cancelled_period'] ?? 'today'))
);

$valid_cancelled_periods = [
    'today',
    'last_week',
    'last_month',
    'specific_month'
];

if (!in_array($cancelled_period, $valid_cancelled_periods, true)) {
    $cancelled_period = 'today';
}

$cancelled_month = trim(
    (string)($_GET['cancelled_month'] ?? date('Y-m'))
);

$cancelledMonthObject = DateTime::createFromFormat(
    '!Y-m',
    $cancelled_month
);

if (
    $cancelledMonthObject === false
    || $cancelledMonthObject->format('Y-m') !== $cancelled_month
) {
    $cancelled_month = date('Y-m');
} elseif ($cancelled_month > date('Y-m')) {
    $cancelled_month = date('Y-m');
}

$per_page = 10;
$page = max(1, (int)($_GET['page'] ?? 1));

$cancelled_per_page = 10;
$cancelled_page = max(
    1,
    (int)($_GET['cancelled_page'] ?? 1)
);

/* Tracks whether the Cancelled Orders section should render expanded.
   This is set explicitly (not inferred from page number) so that
   paging back to page 1 does not cause the section to collapse. */
$cancelled_open = ($_GET['cancelled_open'] ?? '') === '1' ? '1' : '';

/* =========================================================
   PAYMENT-BASED INITIAL WORKFLOW FIX
   Only GCash orders should use Pending Verification.
   Cash orders can proceed directly to Confirmed because there
   is no digital payment proof that needs to be checked.

   This also repairs existing orders that were incorrectly saved
   as pending_verification by moving non-GCash pending orders to
   confirmed.
========================================================= */
/*
 * Pending statuses are now intentional:
 *  - pending_verification = pending review for new GCash orders and guest cash orders
 *
 * Do not automatically convert them to Confirmed.
 */

/* =========================================================
   REDIRECT HELPER
========================================================= */
function staffOrdersRedirect(
    string $status,
    string $search,
    int $page,
    ?string $action = null
): void {
    $params = [
        'status' => $status,
        'page' => $page
    ];

    if ($search !== '') {
        $params['q'] = $search;
    }

    if ($GLOBALS['cancelled_search'] !== '') {
        $params['cancelled_q'] = $GLOBALS['cancelled_search'];
    }

    if (($GLOBALS['cancelled_period'] ?? '') !== '') {
        $params['cancelled_period'] = $GLOBALS['cancelled_period'];
    }

    if (
        ($GLOBALS['cancelled_period'] ?? '') === 'specific_month'
        && ($GLOBALS['cancelled_month'] ?? '') !== ''
    ) {
        $params['cancelled_month'] = $GLOBALS['cancelled_month'];
    }

    if ((int)$GLOBALS['cancelled_page'] > 1) {
        $params['cancelled_page'] = (int)$GLOBALS['cancelled_page'];
    }

    if (($GLOBALS['cancelled_open'] ?? '') === '1') {
        $params['cancelled_open'] = '1';
    }

    if ($action !== null && $action !== '') {
        $params['action'] = $action;
    }

    header('Location: index.php?' . http_build_query($params));
    exit;
}

/* =========================================================
   CANCEL ORDER
   Staff-side cancellation.
   Supports normal POST fallback and AJAX.
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['cancel_order'])
) {

    $ajaxResponse = [
        'success' => false,
        'message' => 'The order could not be cancelled.'
    ];

    $order_id =
        (int)($_POST['order_id'] ?? 0);

    $cancellation_reason =
        trim(
            (string)($_POST['cancellation_reason'] ?? '')
        );

    $posted_status =
        trim(
            (string)(
                $_POST['status_filter']
                ?? 'pending_verification'
            )
        );

    $posted_search =
        trim(
            (string)($_POST['q'] ?? '')
        );

    $posted_page =
        max(
            1,
            (int)($_POST['page'] ?? 1)
        );


    if (!in_array(
        $posted_status,
        $valid_filters,
        true
    )) {

        $posted_status =
            'pending_verification';
    }


    if (
        $order_id > 0
        && $cancellation_reason !== ''
    ) {

        try {

            $pdo->beginTransaction();


            /* ---------------------------------------------
               GET ORDER
            --------------------------------------------- */

            $orderStmt = $pdo->prepare("
                SELECT
                    id,
                    customer_id,
                    order_number,
                    claim_number,
                    status
                FROM orders
                WHERE id = ?
                LIMIT 1
            ");

            $orderStmt->execute([
                $order_id
            ]);

            $orderData =
                $orderStmt->fetch(
                    PDO::FETCH_ASSOC
                );


            if (!$orderData) {

                throw new RuntimeException(
                    'Order not found.'
                );
            }


            /* ---------------------------------------------
               ALLOWED CANCELLATION STATUSES
            --------------------------------------------- */

            $allowed_to_cancel = [
                'pending_verification',
                            'confirmed',
                'preparing',
                'ready'
            ];


            if (!in_array(
                $orderData['status'],
                $allowed_to_cancel,
                true
            )) {

                throw new RuntimeException(
                    'This order can no longer be cancelled.'
                );
            }


            $previous_status =
                (string)$orderData['status'];


            /* ---------------------------------------------
               CANCEL ORDER
            --------------------------------------------- */

            $stmt = $pdo->prepare("
                UPDATE orders
                SET
                    status = 'cancelled',
                    cancellation_reason = ?,
                    closed_at = NOW()
                WHERE id = ?
            ");

            $stmt->execute([
                $cancellation_reason,
                $order_id
            ]);


            /* ---------------------------------------------
               SAVE STATUS HISTORY
            --------------------------------------------- */

            $historyStmt = $pdo->prepare("
                INSERT INTO order_status_history
                (
                    order_id,
                    from_status,
                    to_status,
                    actor_role,
                    actor_id,
                    cancellation_reason,
                    created_at
                )
                VALUES
                (
                    ?,
                    ?,
                    'cancelled',
                    'staff',
                    ?,
                    ?,
                    NOW()
                )
            ");

            $historyStmt->execute([
                $order_id,
                $previous_status,
                $staff_id,
                $cancellation_reason
            ]);


            /* ---------------------------------------------
               CUSTOMER NOTIFICATION
            --------------------------------------------- */

            $orderIdentifier =
                $orderData['order_number']
                ?: (
                    $orderData['claim_number']
                    ?: 'Order'
                );


            $customerMessage =
                "Your order {$orderIdentifier} has been cancelled. "
                . "Reason: {$cancellation_reason}.";


            $notificationStmt = $pdo->prepare("
                INSERT INTO notifications
                (
                    recipient_role,
                    recipient_id,
                    type,
                    message,
                    reference_id
                )
                VALUES
                (
                    'customer',
                    ?,
                    'order_cancelled',
                    ?,
                    ?
                )
            ");

            $notificationStmt->execute([
                $orderData['customer_id'],
                $customerMessage,
                $order_id
            ]);


            $pdo->commit();


            /* ---------------------------------------------
               AJAX RESPONSE
            --------------------------------------------- */

            $ajaxResponse = [
                'success' => true,
                'order_id' => $order_id,
                'previous_status' => $previous_status,
                'new_status' => 'cancelled',
                'message' =>
                    "Order {$orderIdentifier} cancelled successfully."
            ];


        } catch (Throwable $e) {

            if (
                $pdo->inTransaction()
            ) {
                $pdo->rollBack();
            }

            error_log(
                'Staff order cancellation failed: '
                . $e->getMessage()
            );

            $ajaxResponse = [
                'success' => false,
                'message' =>
                    $e->getMessage()
            ];
        }

    } else {

        $ajaxResponse = [
            'success' => false,
            'message' =>
                $order_id <= 0
                    ? 'Invalid order.'
                    : 'Please select a cancellation reason.'
        ];
    }


    /* ---------------------------------------------
       AJAX RESPONSE
    --------------------------------------------- */

    if ($isAjaxRequest) {

        header(
            'Content-Type: application/json; charset=utf-8'
        );

        echo json_encode(
            $ajaxResponse,
            JSON_UNESCAPED_UNICODE
        );

        exit;
    }


    /* ---------------------------------------------
       NORMAL POST FALLBACK
    --------------------------------------------- */

    staffOrdersRedirect(
        $posted_status,
        $posted_search,
        $posted_page,
        $ajaxResponse['success']
            ? 'cancelled'
            : null
    );
}

/* =========================================================
   UPDATE ORDER STATUS
   Staff uses the same order workflow:
   Pending -> Confirmed -> Preparing -> Ready -> Completed
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {

    $ajaxResponse = [
    'success' => false,
    'message' => 'The order status could not be updated.'
    ];

    $order_id = (int)($_POST['order_id'] ?? 0);
    $new_status = trim((string)($_POST['status'] ?? ''));

    $posted_status = trim((string)($_POST['status_filter'] ?? 'pending_verification'));
    $posted_search = trim((string)($_POST['q'] ?? ''));
    $posted_page = max(1, (int)($_POST['page'] ?? 1));

    if (!in_array($posted_status, $valid_filters, true)) {
        $posted_status = 'pending_verification';
    }

    $allowed_statuses = [
        'confirmed',
        'preparing',
        'ready',
        'completed'
    ];

    if (
        $order_id > 0 &&
        in_array($new_status, $allowed_statuses, true)
    ) {

        $orderStmt = $pdo->prepare("
            SELECT id, customer_id, order_number, claim_number, status
            FROM orders
            WHERE id = ?
            LIMIT 1
        ");
        $orderStmt->execute([$order_id]);
        $orderData = $orderStmt->fetch(PDO::FETCH_ASSOC);

        if ($orderData) {

            $current_status = $orderData['status'];

            $allowed_transitions = [
                'pending_verification' => ['confirmed'],
                'confirmed' => ['preparing'],
                'preparing' => ['ready'],
                'ready' => ['completed'],
                'completed' => [],
                'cancelled' => []
            ];

            $next_statuses = $allowed_transitions[$current_status] ?? [];

            if (in_array($new_status, $next_statuses, true)) {

                if ($new_status === 'completed') {

                    $stmt = $pdo->prepare("
                        UPDATE orders
                        SET
                            status = ?,
                            closed_at = NOW()
                        WHERE id = ?
                    ");

                    $stmt->execute([
                        $new_status,
                        $order_id
                    ]);

                } else {

                    $stmt = $pdo->prepare("
                        UPDATE orders
                        SET status = ?
                        WHERE id = ?
                    ");

                    $stmt->execute([
                        $new_status,
                        $order_id
                    ]);
                }

                $orderIdentifier = $orderData['order_number']
                    ?: ($orderData['claim_number'] ?: 'Order');

                $notificationMap = [
                    'confirmed' => [
                        'type' => 'order_confirmed',
                        'message' => "Your order {$orderIdentifier} has been confirmed."
                    ],
                    'preparing' => [
                        'type' => 'order_preparing',
                        'message' => "Your order {$orderIdentifier} is now being prepared."
                    ],
                    'ready' => [
                        'type' => 'order_ready',
                        'message' => "Your order {$orderIdentifier} is ready for pick-up."
                    ],
                    'completed' => [
                        'type' => 'order_completed',
                        'message' => "Your order {$orderIdentifier} has been completed."
                    ]
                ];

                if (isset($notificationMap[$new_status])) {

                    $notification = $notificationMap[$new_status];

                    if (!empty($orderData['customer_id'])) {




                                            $notificationStmt = $pdo->prepare("


                                                INSERT INTO notifications


                                                (


                                                    recipient_role,


                                                    recipient_id,


                                                    type,


                                                    message,


                                                    reference_id


                                                )


                                                VALUES


                                                (


                                                    'customer',


                                                    ?,


                                                    ?,


                                                    ?,


                                                    ?


                                                )


                                            ");



                                            $notificationStmt->execute([


                                                $orderData['customer_id'],


                                                $notification['type'],


                                                $notification['message'],


                                                $order_id


                                            ]);


                    } 

                    $ajaxResponse = [
                    'success' => true,
                    'order_id' => $order_id,
                    'previous_status' => $current_status,
                    'new_status' => $new_status,
                    'message' => $notification['message']
                    ];
                }
            }
        }
    }

    if ($isAjaxRequest) {

        header('Content-Type: application/json; charset=utf-8');

        echo json_encode($ajaxResponse);

        exit;
    }

    staffOrdersRedirect(
        $posted_status,
        $posted_search,
        $posted_page,
        in_array($new_status, $allowed_statuses, true)
            ? $new_status
            : null
    );
}

/* =========================================================
   STATUS COUNTS
========================================================= */
$countStmt = $pdo->query("
    SELECT
        COUNT(*) AS all_count,
        SUM(
            status = 'pending_verification'
        ) AS pending_verification_count,
        SUM(status = 'confirmed') AS confirmed_count,
        SUM(status = 'preparing') AS preparing_count,
        SUM(status = 'ready') AS ready_count,
        SUM(status = 'completed') AS completed_count,
        SUM(status = 'cancelled') AS cancelled_count
    FROM orders
");

$counts = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$all_count = (int)($counts['all_count'] ?? 0);
$pending_verification_count = (int)($counts['pending_verification_count'] ?? 0);
$pending_count = $pending_verification_count;
$confirmed_count = (int)($counts['confirmed_count'] ?? 0);
$preparing_count = (int)($counts['preparing_count'] ?? 0);
$ready_count = (int)($counts['ready_count'] ?? 0);
$completed_count = (int)($counts['completed_count'] ?? 0);
$cancelled_count = (int)($counts['cancelled_count'] ?? 0);

/* =========================================================
   ORDER QUERY
========================================================= */
$where = [];
$params = [];

/*
 * =========================================================
 * ORDER FILTER
 *
 * Without a search:
 *      Show only the currently selected workflow.
 *
 * With a search:
 *      Search across ALL ACTIVE workflow orders:
 *      Pending Verification
 *      Confirmed
 *      Preparing
 *      Ready
 *
 * Cancelled orders are excluded because they have their
 * own separate historical section below.
 * =========================================================
 */

if ($search !== '') {

    /*
     * Search across the complete active queue instead of
     * restricting the search to the currently selected tab.
     */
    $where[] = "
        o.status IN (
            'pending_verification',
                    'confirmed',
            'preparing',
            'ready'
        )
    ";

} else {

    /*
     * No search = keep the normal workflow filter.
     */
    $where[] = 'o.status = ?';
    $params[] = $selected_status;


}


/*
 * =========================================================
 * SEARCH CONDITIONS
 * =========================================================
 */

if ($search !== '') {

    $where[] = "
        (
            o.order_number LIKE ?
            OR o.claim_number LIKE ?
            OR o.customer_name LIKE ?
            OR o.contact_number LIKE ?
            OR CAST(o.id AS CHAR) LIKE ?
        )
    ";

    $search_value =
        '%' . $search . '%';

    $params[] = $search_value;
    $params[] = $search_value;
    $params[] = $search_value;
    $params[] = $search_value;
    $params[] = $search_value;
}

$where_sql = $where
    ? 'WHERE ' . implode(' AND ', $where)
    : '';

$totalStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM orders o
    {$where_sql}
");

$totalStmt->execute($params);

$total_orders = (int)$totalStmt->fetchColumn();

$total_pages = max(1, (int)ceil($total_orders / $per_page));

if ($page > $total_pages) {
    $page = $total_pages;
}

$offset = ($page - 1) * $per_page;

/* Active orders are shown oldest first so the Staff sees
 * earlier orders first in the pick-up workflow. */
$order_by = 'o.created_at ASC, o.id ASC';

$orderSql = "
    SELECT o.*
    FROM orders o
    {$where_sql}
    ORDER BY {$order_by}
    LIMIT {$per_page} OFFSET {$offset}
";

$orderStmt = $pdo->prepare($orderSql);
$orderStmt->execute($params);

$orders = $orderStmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================================================
   CANCELLED ORDERS QUERY
   Cancelled records are always displayed in a separate
   section below the active workflow, newest cancellation first.
========================================================= */
$cancelled_where = ["o.status = 'cancelled'"];
$cancelled_params = [];

switch ($cancelled_period) {
    case 'today':
        $cancelled_where[] = 'DATE(o.closed_at) = CURDATE()';
        break;

    case 'last_week':
        /* Previous calendar week: Monday through Sunday. */
        $cancelled_where[] = "
            DATE(o.closed_at) BETWEEN
                DATE_SUB(CURDATE(), INTERVAL (WEEKDAY(CURDATE()) + 7) DAY)
                AND DATE_SUB(CURDATE(), INTERVAL (WEEKDAY(CURDATE()) + 1) DAY)
        ";
        break;

    case 'last_month':
        $cancelled_where[] = "
            DATE(o.closed_at) BETWEEN
                DATE_FORMAT(
                    DATE_SUB(CURDATE(), INTERVAL 1 MONTH),
                    '%Y-%m-01'
                )
                AND LAST_DAY(
                    DATE_SUB(CURDATE(), INTERVAL 1 MONTH)
                )
        ";
        break;

    case 'specific_month':
        $cancelledMonthStart = $cancelled_month . '-01';
        $cancelledMonthStartObject = new DateTime($cancelledMonthStart);
        $cancelledMonthEndObject =
            (clone $cancelledMonthStartObject)->modify('+1 month');

        $cancelled_where[] = "
            o.closed_at >= ?
            AND o.closed_at < ?
        ";
        $cancelled_params[] =
            $cancelledMonthStartObject->format('Y-m-d 00:00:00');
        $cancelled_params[] =
            $cancelledMonthEndObject->format('Y-m-d 00:00:00');
        break;
}

if ($cancelled_search !== '') {
    $cancelled_where[] = "
        (
            o.order_number LIKE ?
            OR o.claim_number LIKE ?
            OR o.customer_name LIKE ?
            OR o.contact_number LIKE ?
            OR CAST(o.id AS CHAR) LIKE ?
        )
    ";

    $cancelled_search_value = '%' . $cancelled_search . '%';

    $cancelled_params[] = $cancelled_search_value;
    $cancelled_params[] = $cancelled_search_value;
    $cancelled_params[] = $cancelled_search_value;
    $cancelled_params[] = $cancelled_search_value;
    $cancelled_params[] = $cancelled_search_value;
}

$cancelled_where_sql =
    'WHERE ' . implode(' AND ', $cancelled_where);

$cancelledTotalStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM orders o
    {$cancelled_where_sql}
");

$cancelledTotalStmt->execute($cancelled_params);

$cancelled_total_orders =
    (int)$cancelledTotalStmt->fetchColumn();

$cancelled_total_pages = max(
    1,
    (int)ceil(
        $cancelled_total_orders / $cancelled_per_page
    )
);

if ($cancelled_page > $cancelled_total_pages) {
    $cancelled_page = $cancelled_total_pages;
}

$cancelled_offset =
    ($cancelled_page - 1) * $cancelled_per_page;

$cancelledOrderSql = "
    SELECT o.*
    FROM orders o
    {$cancelled_where_sql}
    ORDER BY o.closed_at DESC, o.id DESC
    LIMIT {$cancelled_per_page} OFFSET {$cancelled_offset}
";

$cancelledOrderStmt = $pdo->prepare($cancelledOrderSql);
$cancelledOrderStmt->execute($cancelled_params);

$cancelled_orders =
    $cancelledOrderStmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================================================
   LOAD ORDER ITEMS FOR ACTIVE + CANCELLED CURRENT PAGE
========================================================= */
$order_items = [];

$items_order_ids = array_values(array_unique(array_merge(
    array_map('intval', array_column($orders, 'id')),
    array_map('intval', array_column($cancelled_orders, 'id'))
)));

if ($items_order_ids) {

    $placeholders = implode(
        ',',
        array_fill(0, count($items_order_ids), '?')
    );

    $itemStmt = $pdo->prepare("
        SELECT *
        FROM order_items
        WHERE order_id IN ({$placeholders})
        ORDER BY order_id ASC, id ASC
    ");

    $itemStmt->execute($items_order_ids);

    foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $order_items[(int)$item['order_id']][] = $item;
    }
}

/* =========================================================
   LOAD CANCELLATION ACTORS FOR CANCELLED ORDERS
========================================================= */
$cancelled_by_roles = [];
$cancelled_by_ids = [];

if (!empty($cancelled_orders)) {

    $history_order_ids = array_map(
        'intval',
        array_column($cancelled_orders, 'id')
    );

    $history_placeholders = implode(
        ',',
        array_fill(0, count($history_order_ids), '?')
    );

    $historyActorStmt = $pdo->prepare("
        SELECT h.order_id, h.actor_role, h.actor_id
        FROM order_status_history h
        INNER JOIN (
            SELECT order_id, MAX(id) AS max_id
            FROM order_status_history
            WHERE to_status = 'cancelled'
              AND order_id IN ({$history_placeholders})
            GROUP BY order_id
        ) latest ON latest.max_id = h.id
        WHERE h.to_status = 'cancelled'
    ");

    $historyActorStmt->execute($history_order_ids);

    foreach ($historyActorStmt->fetchAll(PDO::FETCH_ASSOC) as $historyRow) {

        $historyOrderId =
            (int)$historyRow['order_id'];

        $cancelled_by_roles[$historyOrderId] =
            strtolower(trim((string)$historyRow['actor_role']));

        $cancelled_by_ids[$historyOrderId] =
            (int)($historyRow['actor_id'] ?? 0);
    }
}

/* =========================================================
   DISPLAY HELPERS
========================================================= */
$status_labels = [
    'pending_verification' => 'Pending Verification',
    'pending_verification' => 'Pending Confirmation',
    'confirmed' => 'Confirmed',
    'preparing' => 'Preparing',
    'ready' => 'Ready for Pick-up',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled'
];

function staffStatusLabel(
    string $status,
    array $labels
): string {
    return $labels[$status]
        ?? ucwords(str_replace('_', ' ', $status));
}

function staffFormatTime(?string $value): string {

    if (!$value) {
        return '—';
    }

    $timestamp = strtotime($value);

    return $timestamp
        ? date('h:i A', $timestamp)
        : '—';
}

function staffCancellationActorLabel(?string $role): string {

    $role = strtolower(trim((string)$role));

    return match ($role) {
        'admin' => 'Admin',
        'staff' => 'Staff',
        'customer' => 'Customer',
        'system' => 'System',
        default => 'Unknown'
    };
}

function staffAssetPath(?string $path): string {

    $path = trim((string)$path);

    if ($path === '') {
        return '';
    }

    if (
        str_starts_with($path, '../') ||
        str_starts_with($path, 'http://') ||
        str_starts_with($path, 'https://')
    ) {
        return $path;
    }

    if (str_starts_with($path, 'assets/')) {
        return '../' . $path;
    }

    return '../assets/uploads/receipts/' . ltrim($path, '/');
}

function staffGetAddons(?string $addons): array
{
    if ($addons === null || trim($addons) === '') {
        return [];
    }

    $decoded = json_decode((string)$addons, true);

    /*
     * Current order records can contain add-on names only, while the
     * customization screen uses ₱10.00 per add-on. When a saved record
     * already contains a price, that saved value is used instead.
     */
    $defaultAddonPrice = 10.00;

    if (!is_array($decoded)) {
        $rawParts = array_map('trim', explode(',', (string)$addons));
        $result = [];

        foreach ($rawParts as $name) {
            if ($name !== '') {
                $result[] = [
                    'name' => $name,
                    'price' => $defaultAddonPrice
                ];
            }
        }

        return $result;
    }

    $result = [];

    foreach ($decoded as $key => $addon) {
        if (is_array($addon)) {
            $name = $addon['name']
                ?? $addon['addon_name']
                ?? $addon['title']
                ?? null;

            $price = $addon['price']
                ?? $addon['addon_price']
                ?? $defaultAddonPrice;

            if ($name !== null && trim((string)$name) !== '') {
                $result[] = [
                    'name' => trim((string)$name),
                    'price' => is_numeric($price)
                        ? (float)$price
                        : $defaultAddonPrice
                ];
            }
        } elseif (!is_int($key) && is_numeric($addon)) {
            $result[] = [
                'name' => trim((string)$key),
                'price' => (float)$addon
            ];
        } elseif (is_scalar($addon) && trim((string)$addon) !== '') {
            $result[] = [
                'name' => trim((string)$addon),
                'price' => $defaultAddonPrice
            ];
        }
    }

    return $result;
}

function staffOrdersUrl(array $overrides = []): string {

    $query = [
        'status' => $GLOBALS['selected_status'],
        'q' => $GLOBALS['search'],
        'page' => $GLOBALS['page'],
        'cancelled_q' => $GLOBALS['cancelled_search'],
        'cancelled_period' => $GLOBALS['cancelled_period'],
        'cancelled_month' => $GLOBALS['cancelled_month'],
        'cancelled_page' => $GLOBALS['cancelled_page'],
        'cancelled_open' => $GLOBALS['cancelled_open']
    ];

    foreach ($overrides as $key => $value) {
        $query[$key] = $value;
    }

    foreach ($query as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
        }
    }

    return 'index.php?' . http_build_query($query);
}

$workflow_cards = [
    'pending_verification' => [
        'label' => 'Pending Verification',
        'count' => $pending_verification_count,
        'icon' => 'bi-hourglass-split'
    ],
    'confirmed' => [
        'label' => 'Confirmed',
        'count' => $confirmed_count,
        'icon' => 'bi-check-circle'
    ],
    'preparing' => [
        'label' => 'Preparing',
        'count' => $preparing_count,
        'icon' => 'bi-cup-hot'
    ],
    'ready' => [
        'label' => 'Ready for Pick-up',
        'count' => $ready_count,
        'icon' => 'bi-bag-check'
    ]
];

$orders_section_title =
    'ACTIVE ORDERS';

$orders_section_description =
    'Manage orders currently moving through the pick-up workflow.';

$cancelled_period_labels = [
    'today' => 'Today',
    'last_week' => 'Last week',
    'last_month' => 'Last month',
    'specific_month' => 'Specific month'
];

require_once '../includes/header.php';
?>

<link
    rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
>

<style>
    .staff-orders-page {
        min-height: 100vh;
    }

    .staff-dashboard {
        background: #f8f5ef;
        min-height: 100vh;
        min-width: 0;
    }

    .staff-content {
        padding: 30px;
        min-width: 0;
    }

    .staff-page-header {
        margin-bottom: 0;
    }

    @media (min-width: 992px) {
        .staff-dashboard {
            width: calc(100% - 260px);
            margin-left: 260px;
        }
    }

    body {
        background: #F7F5F2;
    }

    .admin-orders-page {
        min-height: 100vh;
    }

   .admin-main {
    min-width: 0;
}

    .admin-topbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 20px;
        padding: 16px 24px;
        background: #ffffff;
        border-bottom: 1px solid #6F4E37;
        position: sticky;
        top: 0;
        z-index: 1000;
    }

    .admin-search-top {
        max-width: 320px;
        width: 100%;
    }

    .admin-search-top input {
        width: 100%;
        border-radius: 50px;
        border: 1px solid #B8A08A;
        padding: 9px 16px;
        font-size: 0.85rem;
        background: #FDF8F2;
        outline: none;
    }

    .admin-search-top input:focus {
        border-color: #6f4e37;
    }

    .admin-profile {
        position: relative;
    }

    .admin-profile-btn {
        display: flex;
        align-items: center;
        gap: 10px;
        border: 1px solid #B8A08A;
        background: #FFFFFF;
        border-radius: 12px;
        padding: 6px 10px;
        cursor: pointer;
        color: #2c221e;
        font-size: 0.9rem;
        font-weight: 600;
        transition: background .2s ease, border-color .2s ease, box-shadow .2s ease;
    }

    .admin-profile-btn:hover {
        background: #FDF8F2;
        border-color: #8B6F5A;
        box-shadow: 0 2px 8px rgba(74, 53, 37, .06);
    }

    .admin-avatar {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: #e6c9c9;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #4A3525;
    }
.admin-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

    .admin-profile-dropdown {
        position: absolute;
        top: calc(100% + 8px);
        right: 0;
        width: 180px;
        background: #ffffff;
        border: 1px solid #E6DEC9;
        border-radius: 12px;
        box-shadow: 0 6px 20px rgba(0,0,0,.10);
        padding: 6px;
        display: none;
        z-index: 9999;
    }

    .admin-profile-dropdown.show {
        display: block;
    }

    .admin-profile-dropdown a {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 12px;
        border-radius: 8px;
        color: #4A3525;
        text-decoration: none;
        font-size: 0.85rem;
    }

    .admin-profile-dropdown a:hover {
        background: #f0d6d6;
    }

    .admin-profile-dropdown a i {
        width: 18px;
        text-align: center;
    }

    .profile-arrow {
        font-size: 11px;
        transition: transform 0.2s ease;
    }

    .staff-orders-content {
        padding: 28px;
    }

    .page-title {
        color: #4A3525;
    }

    .page-description {
        color: #7B6D62;
    }

    .order-search-form {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        margin-bottom: 18px;
    }

    .search-box {
        position: relative;
        width: 280px;
    }

    .search-box i {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #6F4E37;
        pointer-events: none;
    }

    .search-box input {
        width: 100%;
        height: 38px;
        padding: 6px 12px 6px 34px;
        border: 1px solid #8B6F5A;
        border-radius: 8px;
        font-size: .82rem;
    }

    .search-actions {
        display: flex;
        gap: 8px;
    }

    .search-actions .btn {
        height: 38px;
        border-radius: 8px;
        font-size: .82rem;
        font-weight: 700;
    }

    .search-button {
        background: #6F4E37;
        border-color: #5A3D2B;
        color: #FFFFFF;
    }

    .search-button:hover {
        background: #5A3D2B;
        color: #FFFFFF;
    }

    .clear-button {
        background: #FFFFFF;
        border: 1px solid #8B6F5A;
        color: #6F4E37;
    }

    .workflow-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 14px;
        margin-bottom: 24px;
    }

    .workflow-card {
        display: flex;
        align-items: center;
        gap: 14px;
        min-height: 86px;
        padding: 14px 16px;
        border: 2px solid #B8A08A;
        border-radius: 12px;
        background: #FFFFFF;
        color: #4A3525;
        text-decoration: none;
        box-shadow: 0 2px 8px rgba(74, 53, 37, .04);
        transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease, background .15s ease;
    }

    .workflow-card:hover {
        box-shadow: 0 4px 12px rgba(74, 53, 37, .08);
        color: #4A3525;
        transform: translateY(-1px);
    }

    .workflow-card.active {
        border-width: 2px;
        box-shadow: 0 4px 12px rgba(74, 53, 37, .08);
    }

    /* Workflow colors */
    .workflow-card.workflow-pending_verification {
        border-color: #D8B56A;
    }

    .workflow-card.workflow-pending_verification {
        border-color: #9BB7D4;
    }

    .workflow-card.workflow-pending_verification:hover,
    .workflow-card.workflow-pending_verification.active {
        background: #FFF8E8;
        border-color: #C7922E;
    }

    .workflow-card.workflow-pending_verification:hover,
    .workflow-card.workflow-pending_verification.active {
        background: #EEF5FC;
        border-color: #5C86AD;
    }

    .workflow-card.workflow-confirmed {
        border-color: #8DAFD6;
    }

    .workflow-card.workflow-confirmed:hover,
    .workflow-card.workflow-confirmed.active {
        background: #F1F7FF;
        border-color: #4C78A8;
    }

    .workflow-card.workflow-preparing {
        border-color: #AA98D0;
    }

    .workflow-card.workflow-preparing:hover,
    .workflow-card.workflow-preparing.active {
        background: #F4EEFF;
        border-color: #7654A8;
    }

    .workflow-card.workflow-ready {
        border-color: #86B998;
    }

    .workflow-card.workflow-ready:hover,
    .workflow-card.workflow-ready.active {
        background: #F0FAF2;
        border-color: #3F8A55;
    }

    /* Stronger visual treatment for the currently selected process */
    .workflow-card.workflow-pending_verification.active {
        background: #F3D37A;
        border-color: #A66A00;
        box-shadow: 0 4px 14px rgba(166, 106, 0, .16);
    }

    .workflow-card.workflow-confirmed.active {
        background: #A9C9EE;
        border-color: #2E5F97;
        box-shadow: 0 4px 14px rgba(46, 95, 151, .16);
    }

    .workflow-card.workflow-preparing.active {
        background: #C8AFF0;
        border-color: #6B43A1;
        box-shadow: 0 4px 14px rgba(107, 67, 161, .16);
    }

    .workflow-card.workflow-ready.active {
        background: #A8D1B3;
        border-color: #2F6F43;
        box-shadow: 0 4px 14px rgba(47, 111, 67, .16);
    }

    .workflow-card.workflow-pending_verification.active .workflow-icon {
        background: #FFE8AD;
        color: #7A4B00;
    }

    .workflow-card.workflow-confirmed.active .workflow-icon {
        background: #D8E8FB;
        color: #214F82;
    }

    .workflow-card.workflow-preparing.active .workflow-icon {
        background: #E3D7FA;
        color: #52317F;
    }

    .workflow-card.workflow-ready.active .workflow-icon {
        background: #D7EBDD;
        color: #205A34;
    }

    .workflow-card.workflow-pending_verification.active .workflow-label,
    .workflow-card.workflow-pending_verification.active .workflow-count {
        color: #6E4300;
    }

    .workflow-card.workflow-confirmed.active .workflow-label,
    .workflow-card.workflow-confirmed.active .workflow-count {
        color: #214F82;
    }

    .workflow-card.workflow-preparing.active .workflow-label,
    .workflow-card.workflow-preparing.active .workflow-count {
        color: #52317F;
    }

    .workflow-card.workflow-ready.active .workflow-label,
    .workflow-card.workflow-ready.active .workflow-count {
        color: #205A34;
    }

    .workflow-icon {
        width: 44px;
        height: 44px;
        flex: 0 0 44px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 11px;
        font-size: 1.1rem;
    }

    .workflow-pending_verification .workflow-icon {
        background: #FFF1D6;
        color: #8A5A00;
    }

    .workflow-confirmed .workflow-icon {
        background: #E7F1FF;
        color: #285B9A;
    }

    .workflow-preparing .workflow-icon {
        background: #EEE7FF;
        color: #5B3A9A;
    }

    .workflow-ready .workflow-icon {
        background: #E5F6EA;
        color: #23733D;
    }

    .workflow-pending_verification .workflow-label,
    .workflow-pending_verification .workflow-count {
        color: #8A5A00;
    }

    .workflow-confirmed .workflow-label,
    .workflow-confirmed .workflow-count {
        color: #285B9A;
    }

    .workflow-preparing .workflow-label,
    .workflow-preparing .workflow-count {
        color: #5B3A9A;
    }

    .workflow-ready .workflow-label,
    .workflow-ready .workflow-count {
        color: #23733D;
    }

    .workflow-label {
        color: #7B6D62;
        font-size: .76rem;
        line-height: 1.25;
        margin-bottom: 4px;
    }

    .workflow-count {
        color: #4A3525;
        font-size: 1.45rem;
        font-weight: 900;
        line-height: 1;
    }

    /* =========================
       ACTIVE ORDERS SECTION
    ========================= */
    .active-orders-section {
        margin-top: 4px;
        padding: 18px;
        border: 2px solid #6F4E37;
        border-left: 4px solid #4A3525;
        border-radius: 14px;
        background: #FCFAF7;
    }

    .active-section-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding-bottom: 12px;
        margin-bottom: 14px;
        border-bottom: 1px solid #D8C9BD;
    }

    .active-section-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 10px;
        border-radius: 999px;
        background: #EEE4DB;
        color: #5A3D2B;
        border: 1px solid #C9B19D;
        font-size: .68rem;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: .35px;
        white-space: nowrap;
    }

    .active-orders-heading {
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        gap: 16px;
        margin: 0;
    }

    .active-orders-title {
        color: #4A3525;
        font-size: 1.08rem;
        font-weight: 900;
        letter-spacing: .2px;
        margin: 0;
    }

    .active-orders-description {
        color: #7B6D62;
        font-size: .8rem;
        margin: 3px 0 0;
    }

    .cancelled-record-bar {
        display: flex;
        justify-content: flex-end;
        margin: -10px 0 18px;
    }

    /* =========================
       CANCELLED ORDERS SECTION
    ========================= */
    /* =========================
       CANCELLED ORDERS SECTION
    ========================= */
    .cancelled-orders-section {
        margin-top: 46px;
        padding: 0;
        border: 1px solid #D89A9A;
        border-left: 4px solid #B64A4A;
        border-radius: 14px;
        background: #FFF9F9;
        overflow: hidden;
    }

    .cancelled-section-summary {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 18px;
        padding: 24px;
        cursor: pointer;
        list-style: none;
        user-select: none;
    }

    .cancelled-section-summary::-webkit-details-marker {
        display: none;
    }

    .cancelled-section-summary::marker {
        display: none;
        content: "";
    }

    .cancelled-section-summary:hover {
        background: #FFF4F4;
    }

    .cancelled-orders-section[open] .cancelled-section-summary {
        border-bottom: 1px solid #D89A9A;
        background: #FFF7F7;
    }

    .cancelled-section-content {
        padding: 0 24px 24px;
    }

    .cancelled-toggle {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        flex: 0 0 auto;
        padding: 7px 10px;
        border: 1px solid #D89A9A;
        border-radius: 999px;
        background: #FFFFFF;
        color: #A33A3A;
        font-size: .72rem;
        font-weight: 800;
        white-space: nowrap;
    }

    .cancelled-toggle-label {
        display: none;
    }

    .cancelled-toggle-open {
        display: inline;
    }

    .cancelled-toggle-icon {
        transition: transform .2s ease;
    }

    .cancelled-orders-section[open] .cancelled-toggle-open {
        display: none;
    }

    .cancelled-orders-section[open] .cancelled-toggle-close {
        display: inline;
    }

    .cancelled-orders-section[open] .cancelled-toggle-icon {
        transform: rotate(180deg);
    }

    .cancelled-history-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 10px;
        border-radius: 999px;
        background: #FCE3E3;
        color: #8E2F2F;
        border: 1px solid #D89A9A;
        font-size: .68rem;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: .35px;
        white-space: nowrap;
    }

    .cancelled-section-heading {
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 14px;
    }

    .cancelled-section-title {
        color: #A33A3A;
        font-size: 1.08rem;
        font-weight: 900;
        letter-spacing: .2px;
        margin: 0;
    }

    .cancelled-section-description {
        color: #7B6D62;
        font-size: .8rem;
        margin: 3px 0 0;
    }

    .cancelled-filter-form {
        display: flex;
        align-items: flex-end;
        gap: 8px;
        flex-wrap: wrap;
        margin-bottom: 18px;
        padding: 12px;
        border: 1px solid #C98C8C;
        border-radius: 10px;
        background: #FFF7F7;
    }

    .cancelled-filter-group {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }

    .cancelled-filter-group label {
        color: #7B6D62;
        font-size: .68rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .3px;
    }

    .cancelled-filter-group input,
    .cancelled-filter-group select {
        height: 38px;
        border: 1px solid #C98C8C;
        border-radius: 8px;
        padding: 6px 10px;
        font-size: .82rem;
        color: #4A3525;
        background: #FFFFFF;
        outline: none;
        box-sizing: border-box;
    }

    .cancelled-filter-period,
    .cancelled-filter-month {
        min-width: 155px;
    }

    .cancelled-filter-search {
        width: 290px;
        max-width: 100%;
    }

    .cancelled-filter-group input:focus,
    .cancelled-filter-group select:focus {
        border-color: #A33A3A;
        box-shadow: 0 0 0 2px rgba(163,58,58,.10);
    }

    .cancelled-filter-actions {
        display: flex;
        gap: 8px;
    }

    .cancelled-filter-actions .btn {
        height: 38px;
        border-radius: 8px;
        font-size: .82rem;
        font-weight: 700;
        padding: 7px 13px;
    }

    .cancelled-order-card {
        border-color: #C38B8B;
        border-left: 3px solid #B64A4A;
        background: #FFFFFF;
    }

    .cancelled-order-card:hover {
        border-color: #A33A3A;
        border-left-color: #8E2F2F;
    }

    .cancelled-order-card .order-items-card {
        background: #FFFDFD;
    }

    .order-records-title {
        color: #4A3525;
        font-size: .82rem;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: .4px;
        margin-bottom: 10px;
    }

    .order-record-links {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
    }

    .order-record-link {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 9px 12px;
        border: 2px solid #B8A08A;
        border-radius: 9px;
        background: #FFFFFF;
        color: #6F4E37;
        text-decoration: none;
        font-size: .8rem;
        font-weight: 800;
    }

    .order-record-link:hover,
    .order-record-link.active {
        border-color: #6F4E37;
        background: #F7F1EB;
        color: #4A3525;
    }

    .order-record-cancelled {
        border-color: #D89A9A;
        color: #A33A3A;
    }

    .order-record-cancelled:hover,
    .order-record-cancelled.active {
        border-color: #B64A4A;
        background: #FCE3E3;
        color: #8E2F2F;
    }

    .orders-grid {
        column-count: 2;
        column-gap: 16px;
        column-fill: balance;
    }

    .order-card {
        background: #FFFFFF;
        border: 1px solid #6F4E37;
        border-radius: 14px;
        padding: 20px;
        margin-bottom: 16px;
        box-shadow: 0 3px 12px rgba(74, 53, 37, .05);
        display: flex;
        flex-direction: column;
        height: auto;
        width: 100%;
        break-inside: avoid;
        -webkit-column-break-inside: avoid;
    }

    .order-card:hover {
        border-color: #5A3D2B;
    }

    /* Stronger separators inside each active order card */
    .order-card hr {
        border: 0;
        border-top: 1px solid #8B6F5A;
        opacity: 1;
        margin: 14px 0;
    }

    .order-item-row {
        padding: 12px 0;
        border-bottom: 1px solid #B08E74;
    }

    .status-badge {
        display: inline-block;
        padding: 6px 10px;
        border-radius: 20px;
        font-size: .7rem;
        font-weight: 700;
    }

    .status-pending_verification {
        background: #FFF1D6;
        color: #8A5A00;
        border: 1px solid #D8B56A;
    }

    .status-confirmed {
        background: #E7F1FF;
        color: #285B9A;
        border: 1px solid #8DAFD6;
    }

    .status-preparing {
        background: #EEE7FF;
        color: #5B3A9A;
        border: 1px solid #AA98D0;
    }

    .status-ready {
        background: #E5F6EA;
        color: #23733D;
        border: 1px solid #86B998;
    }

    .status-completed {
        background: #E8E2DD;
        color: #4A3525;
        border: 1px solid #B8A08A;
    }

    .status-cancelled {
        background: #FCE3E3;
        color: #A33A3A;
        border: 1px solid #D89A9A;
    }

    .action-btn {
        border-radius: 8px;
        padding: 8px 13px;
        font-size: .8rem;
        font-weight: 700;
    }

    .btn-confirm {
        background: #DCEEFF;
        color: #286090;
        border: 1px solid #7FA9D0;
    }

    .btn-preparing {
        background: #EEE0FF;
        color: #7040A0;
        border: 1px solid #AA88C9;
    }

    .btn-ready {
        background: #DFF4E3;
        color: #28763B;
        border: 1px solid #83B88E;
    }

    .btn-complete {
        background: #4B2E1E;
        color: #FFFFFF;
        border: 1px solid #392217;
    }

    .btn-cancel {
        background: #FCE3E3;
        color: #A33A3A;
        border: 1px solid #D89A9A;
    }

    .btn-confirm:hover,
    .btn-preparing:hover,
    .btn-ready:hover {
        filter: brightness(.97);
    }

    .btn-complete:hover {
        background: #382116;
        color: #FFFFFF;
    }

    .btn-cancel:hover {
        background: #F7D2D2;
        color: #A33A3A;
    }

    .order-actions {
        display: flex;
        flex-wrap: wrap;
        justify-content: flex-end;
        gap: 8px;
        margin-top: auto;
        padding-top: 18px;
    }

    .info-label {
        color: #7B6D62;
        font-size: .68rem;
        text-transform: uppercase;
        font-weight: 700;
        letter-spacing: .35px;
    }

    .info-value {
        color: #4A3525;
        font-size: .84rem;
        font-weight: 650;
    }

    .cancellation-box {
        background: #FFF3F3;
        border: 1px solid #C98C8C;
        border-radius: 10px;
        padding: 12px;
    }

    .empty-state {
        text-align: center;
        padding: 55px 20px;
        color: #777067;
    }

    .pagination-wrap {
        display: flex;
        justify-content: center;
        margin: 24px 0;
    }

    .pagination .page-link {
        color: #6F4E37;
        border-color: #8B6F5A;
        font-size: .8rem;
    }

    .pagination .active .page-link {
        background: #6F4E37;
        border-color: #5A3D2B;
        color: #FFFFFF;
    }

    /* =========================================================
       CANCELLED ORDER PAGINATION
       Matches Staff Notifications pagination:
       page 1 is always visible, nearby pages are shown, and
       gaps use an ellipsis.
    ========================================================= */
    .cancelled-pagination {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        width: 100%;
        margin-top: 22px;
        padding-top: 18px;
        border-top: 1px solid #E6DEC9;
        flex-wrap: wrap;
    }

    .cancelled-pagination a,
    .cancelled-pagination span {
        min-width: 34px;
        height: 34px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0 9px;
        border-radius: 8px;
        font-size: .78rem;
        font-weight: 700;
        text-decoration: none;
        box-sizing: border-box;
    }

    .cancelled-pagination a {
        color: #6F4E37;
        background: #FFFFFF;
        border: 1px solid #B8A08A;
        transition: background .15s ease, border-color .15s ease, color .15s ease;
    }

    .cancelled-pagination a:hover {
        background: #F7F0E8;
        border-color: #6F4E37;
        color: #4A3525;
    }

    .cancelled-pagination .active {
        background: #4A3525;
        border: 1px solid #4A3525;
        color: #FFFFFF;
    }

    .cancelled-pagination .disabled {
        color: #A99B91;
        background: #F5F1ED;
        border: 1px solid #E6DEC9;
        cursor: default;
    }

    .cancelled-pagination .ellipsis {
        border: none;
        background: transparent;
        color: #8B7D73;
        min-width: 22px;
        padding: 0;
    }

    .receipt-paper {
        max-width: 430px;
        margin: 0 auto;
        background: #FFFFFF;
        border: 1px dashed #8B6F5A;
        padding: 24px;
        font-family: Arial, sans-serif;
        color: #2C221E;
    }

    .receipt-line {
        border-top: 1px dashed #8B6F5A;
        margin: 14px 0;
    }

    .order-items-card {
        background: #FBF9F6;
        border: 1px solid #9C7A60;
        border-radius: 12px;
        padding: 16px 18px;
        margin: 4px 0 18px;
        flex: 1 1 auto;
        display: flex;
        flex-direction: column;
    }

    .order-items-title {
        color: #4A3525;
        font-size: .82rem;
        text-transform: uppercase;
        letter-spacing: .45px;
        font-weight: 800;
        margin-bottom: 12px;
    }


    .order-item-row:first-child {
        padding-top: 0;
    }

    .order-item-row:last-child {
        padding-bottom: 0;
        border-bottom: none;
    }

    .order-item-name {
        color: #4A3525;
        font-size: 1rem;
        font-weight: 800;
        line-height: 1.35;
    }

    .order-item-quantity {
        color: #6F4E37;
        font-size: .95rem;
        font-weight: 800;
        margin-left: 6px;
    }

    .order-item-base-price {
        color: #7B6D62;
        font-size: .82rem;
        font-weight: 700;
        margin-top: 3px;
    }

    .order-item-customization {
        color: #6B5B50;
        font-size: .88rem;
        line-height: 1.5;
        margin-top: 6px;
    }

    .order-item-customization-main {
        display: flex;
        flex-wrap: wrap;
        gap: 4px 10px;
        align-items: center;
    }

    .order-addon-label {
        display: block;
        margin-top: 7px;
        margin-bottom: 5px;
        color: #7B6D62;
        font-size: .72rem;
        text-transform: uppercase;
        letter-spacing: .3px;
        font-weight: 800;
    }

    .order-addon-list {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }

    .order-addon-chip {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 5px 9px;
        border-radius: 999px;
        background: #F3ECE5;
        border: 1px solid #D8C6B8;
        color: #5A3D2B;
        font-size: .78rem;
        font-weight: 700;
        line-height: 1.2;
    }

    .order-addon-chip-price {
        color: #6F4E37;
        font-weight: 800;
        white-space: nowrap;
    }

    .order-item-price {
        color: #4A3525;
        font-size: .95rem;
        font-weight: 800;
        white-space: nowrap;
    }

    .order-total-summary {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 14px;
        padding: 14px 0 2px;
        border-top: 1px solid #9C7A60;
        margin-top: auto;
    }

    .order-total-label {
        color: #4A3525;
        font-size: 1rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .35px;
    }

    .order-total-amount {
        color: #4A3525;
        font-size: 1.35rem;
        font-weight: 900;
        line-height: 1.1;
        white-space: nowrap;
    }

    .item-customization {
        color: #7B6D62;
        font-size: .7rem;
        line-height: 1.35;
    }

    .payment-proof-image {
        max-width: 100%;
        max-height: 70vh;
        object-fit: contain;
        border-radius: 10px;
    }

    .modal-content {
        border: 1px solid #8B6F5A;
        border-radius: 14px;
        overflow: hidden;
    }

    /* =========================================================
       FIXED ORDER ACTION TOASTS
       Stays out of normal page flow and does not move the page.
    ========================================================= */
    .orders-toast-wrap {
        position: fixed;
        top: 88px;
        right: 24px;
        z-index: 2000;
        width: min(420px, calc(100vw - 32px));
        pointer-events: none;
    }

    .orders-toast {
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
        animation: ordersToastIn .22s ease-out;
    }

    .orders-toast-success { border-left: 6px solid #4A8B5A; }
    .orders-toast-cancel { border-left: 6px solid #A33A3A; }

    .orders-toast-icon {
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

    .orders-toast-success .orders-toast-icon {
        color: #2F6E3E;
        background: #EAF6EE;
    }

    .orders-toast-cancel .orders-toast-icon {
        color: #8E2F2F;
        background: #FCE3E3;
    }

    .orders-toast-message {
        flex: 1;
        padding-top: 3px;
        font-size: .9rem;
        line-height: 1.45;
        font-weight: 700;
    }

    .orders-toast-close {
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

    .orders-toast-close:hover {
        background: #F0E6D6;
        color: #2C221E;
    }

    .orders-toast-progress {
        position: absolute;
        left: 0;
        bottom: 0;
        height: 3px;
        width: 100%;
        background: #6F4E37;
        transform-origin: left center;
        animation: ordersToastProgress 3.5s linear forwards;
    }

    .orders-toast-success .orders-toast-progress { background: #4A8B5A; }
    .orders-toast-cancel .orders-toast-progress { background: #A33A3A; }
    .orders-toast.is-restored { animation: none; }
    .orders-toast.is-closing { animation: ordersToastOut .18s ease-in forwards; }

    @keyframes ordersToastIn {
        from { opacity: 0; transform: translateY(-8px) translateX(8px); }
        to { opacity: 1; transform: translateY(0) translateX(0); }
    }

    @keyframes ordersToastOut {
        from { opacity: 1; transform: translateY(0) translateX(0); }
        to { opacity: 0; transform: translateY(-6px) translateX(8px); }
    }

    @keyframes ordersToastProgress {
        from { transform: scaleX(1); }
        to { transform: scaleX(0); }
    }

    @media (max-width: 768px) {

        .active-orders-section {
            padding: 14px;
        }

        .cancelled-section-summary {
            padding: 16px 14px;
            align-items: flex-start;
        }

        .cancelled-section-content {
            padding: 0 14px 14px;
        }

        .active-section-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .cancelled-section-heading {
            align-items: flex-start;
            flex-direction: column;
        }

        .staff-orders-content {
            padding: 18px;
        }

        .admin-topbar {
            padding: 12px 15px;
        }

        .admin-search-top {
            max-width: 220px;
        }

        .admin-profile-btn span {
            display: none;
        }

        .search-box {
            width: 100%;
        }

        .order-search-form {
            width: 100%;
        }

        .workflow-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .orders-grid {
            column-count: 1;
        }

        .order-actions {
            justify-content: flex-start;
        }

        .active-orders-heading,
        .cancelled-section-heading {
            align-items: flex-start;
            flex-direction: column;
        }

        .cancelled-filter-form {
            align-items: stretch;
            flex-direction: column;
        }

        .cancelled-filter-group,
        .cancelled-filter-period,
        .cancelled-filter-month,
        .cancelled-filter-search {
            width: 100%;
            min-width: 0;
        }

        .cancelled-filter-actions .btn {
            flex: 1;
        }

        .orders-toast-wrap {
            top: 74px;
            right: 14px;
            width: min(420px, calc(100vw - 28px));
        }
    }

    @media (max-width: 520px) {
        .workflow-grid {
            grid-template-columns: 1fr;
        }

        .cancelled-pagination {
            gap: 4px;
            margin-top: 18px;
            padding-top: 15px;
        }

        .cancelled-pagination a,
        .cancelled-pagination span {
            min-width: 31px;
            height: 31px;
            font-size: .72rem;
        }

        .cancelled-pagination .ellipsis {
            min-width: 18px;
        }
    }

    /* =========================================================
       MOBILE LAYOUT (phones)
    ========================================================= */
    @media (max-width: 767.98px) {

        .staff-orders-content {
            padding: 16px 12px 28px;
        }

        .page-title {
            font-size: 1.4rem;
        }

        .page-description {
            font-size: .85rem;
        }

        /* Search: input + button on one line */
        .order-search-form {
            flex-wrap: nowrap;
            gap: 8px;
            margin-bottom: 14px;
        }

        .search-box {
            flex: 1 1 auto;
            width: auto;
            min-width: 0;
        }

        .search-box input {
            height: 44px;
            font-size: 16px; /* prevents iOS zoom-on-focus */
        }

        .search-actions .btn {
            height: 44px;
            padding-left: 14px;
            padding-right: 14px;
        }

        /* Workflow summary: always a compact 2 x 2 grid */
        .workflow-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 16px;
        }

        .workflow-card {
            min-height: 72px;
            gap: 10px;
            padding: 10px 12px;
        }

        .workflow-icon {
            width: 36px;
            height: 36px;
            flex: 0 0 36px;
            font-size: .95rem;
            border-radius: 10px;
        }

        .workflow-label {
            font-size: .7rem;
            margin-bottom: 2px;
        }

        .workflow-count {
            font-size: 1.25rem;
        }

        /* Sections and cards: less nested padding so content gets the width */
        .active-orders-section {
            padding: 12px;
            border-left-width: 3px;
        }

        .order-card {
            padding: 14px;
            margin-bottom: 12px;
        }

        /* Buttons: two per row, easy to tap */
        .order-actions {
            justify-content: stretch;
            padding-top: 14px;
        }

        .order-actions > *,
        .order-actions .action-btn,
        .order-actions form {
            flex: 1 1 calc(50% - 8px);
            min-width: 0;
        }

        .order-actions form .action-btn {
            width: 100%;
        }

        .action-btn {
            min-height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .order-record-links {
            flex-wrap: wrap;
        }

        .pagination-wrap {
            overflow-x: auto;
        }

        /* Modals: use the width, scroll inside */
        .modal-dialog {
            margin: 10px;
            max-width: none;
        }

        .modal-body {
            padding: 14px;
        }
    }

    @media print {

        body * {
            visibility: hidden !important;
        }

        .modal.show[id^="receiptModal"],
        .modal.show[id^="receiptModal"] * {
            visibility: visible !important;
        }

        .modal.show[id^="receiptModal"] {
            position: absolute !important;
            left: 0 !important;
            top: 0 !important;
            width: 100% !important;
        }

        .modal.show[id^="receiptModal"] .modal-header,
        .modal.show[id^="receiptModal"] .modal-footer {
            display: none !important;
        }

        .receipt-paper {
            border: none !important;
            max-width: 430px !important;
        }
    }

    /* =========================================================
   ORDER PROCESSING LOADING INDICATOR
========================================================= */

.order-card {
    position: relative;
}

.order-card.processing-order {
    pointer-events: none;
}

.order-processing-overlay {
    position: absolute;
    inset: 0;
    z-index: 20;

    display: flex;
    align-items: center;
    justify-content: center;

    background: rgba(255, 255, 255, 0.82);
    backdrop-filter: blur(2px);

    border-radius: 14px;
}

.order-processing-box {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;

    padding: 18px 24px;

    background: #FFFFFF;
    border: 1px solid #D8C9BD;
    border-radius: 12px;

    box-shadow: 0 6px 20px rgba(74, 53, 37, .10);

    color: #4A3525;
    text-align: center;
}

.order-processing-spinner {
    width: 32px;
    height: 32px;

    border: 3px solid #E6D9CE;
    border-top-color: #6F4E37;

    border-radius: 50%;

    animation: staffOrderSpin .75s linear infinite;

    margin-bottom: 10px;
}

.order-processing-title {
    font-size: .85rem;
    font-weight: 800;
}

.order-processing-text {
    margin-top: 3px;
    color: #7B6D62;
    font-size: .72rem;
}

@keyframes staffOrderSpin {
    to {
        transform: rotate(360deg);
    }
}
</style>

<div class="staff-orders-page">

    <?php require_once 'sidebar.php'; ?>

     <?php require_once 'navbar.php'; ?>

    <main class="staff-dashboard flex-grow-1">

        <div class="staff-content">

        <div class="staff-orders-content">

            <h2 class="fw-bold page-title mb-1">
                <i class="bi bi-bag-check me-1"></i>
                Order Queue
            </h2>

            <p class="page-description mb-4">
                Review, manage, and monitor customer orders through the
                complete pick-up workflow.
            </p>

            <?php
            /*
             * One-time fixed action notification.
             * It is intentionally outside normal page flow so it does not
             * push content down or affect the saved scroll position.
             */
            $orderToast = null;

            $orderAction = trim((string)($_GET['action'] ?? ''));

            $orderToastMap = [
                'confirmed' => [
                    'type' => 'success',
                    'icon' => 'bi-check-circle',
                    'message' => 'Order confirmed successfully!'
                ],
                'preparing' => [
                    'type' => 'success',
                    'icon' => 'bi-cup-hot',
                    'message' => 'Order is now being prepared.'
                ],
                'ready' => [
                    'type' => 'success',
                    'icon' => 'bi-bag-check',
                    'message' => 'Order marked as ready for pick-up!'
                ],
                'completed' => [
                    'type' => 'success',
                    'icon' => 'bi-check2-all',
                    'message' => 'Order completed successfully!'
                ],
                'cancelled' => [
                    'type' => 'cancel',
                    'icon' => 'bi-x-circle',
                    'message' => 'Order cancelled successfully.'
                ]
            ];

            if (isset($orderToastMap[$orderAction])) {
                $orderToast = $orderToastMap[$orderAction];
            }
            ?>

            <?php if ($orderToast): ?>
                <div class="orders-toast-wrap" aria-live="polite" aria-atomic="true">
                    <div
                        class="orders-toast orders-toast-<?= htmlspecialchars($orderToast['type']) ?>"
                        id="ordersActionToast"
                        role="status"
                    >
                        <span class="orders-toast-icon">
                            <i class="bi <?= htmlspecialchars($orderToast['icon']) ?>"></i>
                        </span>

                        <span class="orders-toast-message">
                            <?= htmlspecialchars($orderToast['message']) ?>
                        </span>

                        <button
                            type="button"
                            class="orders-toast-close"
                            id="ordersToastClose"
                            aria-label="Close notification"
                        >
                            <i class="bi bi-x-lg"></i>
                        </button>

                        <span class="orders-toast-progress" aria-hidden="true"></span>
                    </div>
                </div>
            <?php endif; ?>

            <script>
            /* =========================================================
               ORDER ACTION TOAST
               Runs immediately (NOT on DOMContentLoaded), so a fast click
               on another workflow tab can no longer lose the toast.

               - Lives exactly DURATION ms (3.5s) from the moment the
                 action happened. Clicking tabs, or the same tab again and
                 again, NEVER extends or restarts that countdown.
               - If the Staff switches tabs, the toast is carried to the next
                 page and continues from where it left off (the progress bar
                 resumes at the correct point instead of restarting).
               - Once the time is up it is gone for good.
            ========================================================= */
            (function () {
                var STORAGE_KEY = 'staffOrdersActionToast';
                var DURATION    = 3500;  /* fixed lifetime after the action */

                function readState() {
                    try {
                        return JSON.parse(sessionStorage.getItem(STORAGE_KEY) || 'null');
                    } catch (e) { return null; }
                }

                function saveState(state) {
                    try {
                        sessionStorage.setItem(STORAGE_KEY, JSON.stringify(state));
                    } catch (e) {}
                }

                function clearState() {
                    try { sessionStorage.removeItem(STORAGE_KEY); } catch (e) {}
                }

                var now = Date.now();
                var toast = document.getElementById('ordersActionToast');
                var state = null;

                if (toast) {
                    /* Brand-new action (server-rendered toast). */
                    state = { html: toast.outerHTML, createdAt: now };
                    saveState(state);

                    /* Remove the one-time ?action= so refresh does not repeat it. */
                    try {
                        var url = new URL(window.location.href);
                        url.searchParams.delete('action');
                        window.history.replaceState(
                            {},
                            document.title,
                            url.pathname +
                            (url.searchParams.toString() ? '?' + url.searchParams.toString() : '') +
                            url.hash
                        );
                    } catch (e) {}

                } else {
                    /* No new action: carry over a toast that is still alive. */
                    state = readState();

                    if (!state || !state.html || !state.createdAt) {
                        clearState();
                        return;
                    }

                    var carriedAge = now - Number(state.createdAt);

                    if (!(carriedAge >= 0) || carriedAge >= DURATION) {
                        clearState();
                        return;
                    }

                    var wrap = document.createElement('div');
                    wrap.className = 'orders-toast-wrap';
                    wrap.setAttribute('aria-live', 'polite');
                    wrap.setAttribute('aria-atomic', 'true');
                    wrap.innerHTML = state.html;
                    document.body.appendChild(wrap);

                    toast = wrap.querySelector('#ordersActionToast');

                    if (!toast) {
                        clearState();
                        return;
                    }

                    /* Do not replay the slide-in on every tab switch. */
                    toast.classList.add('is-restored');
                }

                var age = now - Number(state.createdAt);
                var visibleFor = DURATION - age;

                if (visibleFor <= 0) {
                    clearState();
                    toast.remove();
                    return;
                }

                /* Resume the progress bar at the right point: same total
                   duration, but started "age" ms in the past. */
                var progress = toast.querySelector('.orders-toast-progress');
                if (progress) {
                    progress.style.animationDuration = DURATION + 'ms';
                    progress.style.animationDelay = (-age) + 'ms';
                }

                var closeTimer = null;

                function closeToast() {
                    if (toast.classList.contains('is-closing')) {
                        return;
                    }
                    if (closeTimer !== null) {
                        clearTimeout(closeTimer);
                        closeTimer = null;
                    }
                    clearState();
                    toast.classList.add('is-closing');
                    setTimeout(function () { toast.remove(); }, 190);
                }

                var closeButton = toast.querySelector('.orders-toast-close');
                if (closeButton) {
                    closeButton.addEventListener('click', closeToast);
                }

                closeTimer = setTimeout(closeToast, visibleFor);

                /* Back/forward cache restores a frozen page with old timers.
                   Drop the stale toast instead of leaving it stuck. */
                window.addEventListener('pageshow', function (event) {
                    if (event.persisted) {
                        clearState();
                        toast.remove();
                    }
                });
            })();
            </script>

            <!-- WORKFLOW STATUS CARDS -->
            <div class="workflow-grid">

                <?php foreach ($workflow_cards as $status_key => $workflow): ?>

                    <a
                        href="<?= htmlspecialchars(staffOrdersUrl([
                            'status' => $status_key,
                            'page' => 1
                        ])) ?>"
                        class="workflow-card workflow-<?= htmlspecialchars($status_key) ?> <?= ($search === '' && $selected_status === $status_key) ? 'active' : '' ?>"
                        data-status="<?= htmlspecialchars($status_key) ?>"
                        aria-current="<?= ($search === '' && $selected_status === $status_key) ? 'page' : 'false' ?>"
                    >

                        <div class="workflow-icon">
                            <i class="bi <?= htmlspecialchars($workflow['icon']) ?>"></i>
                        </div>

                        <div>
                            <div class="workflow-label">
                                <?= htmlspecialchars($workflow['label']) ?>
                            </div>
                            <div class="workflow-count">
                                <?= (int)$workflow['count'] ?>
                            </div>
                        </div>

                    </a>

                <?php endforeach; ?>

            </div>

            <!-- SEARCH -->
            <form method="GET" class="order-search-form">

                <input
                    type="hidden"
                    name="status"
                    value="<?= htmlspecialchars($selected_status) ?>"
                >

                <div class="search-box">
                    <i class="bi bi-search"></i>

                    <input
                        type="search"
                        name="q"
                        value="<?= htmlspecialchars($search) ?>"
                        placeholder="Search order number, claim number, customer..."
                        autocomplete="off"
                    >
                </div>

                <div class="search-actions">

                    <button
                        type="submit"
                        class="btn search-button"
                    >
                        <i class="bi bi-search me-1"></i>
                        Search
                    </button>

                    <?php if ($search !== ''): ?>

                        <a
                            href="<?= htmlspecialchars(staffOrdersUrl(['q' => '', 'page' => 1])) ?>"
                            class="btn clear-button"
                        >
                            Clear
                        </a>

                    <?php endif; ?>

                </div>

            </form>

            <!-- ACTIVE ORDERS SECTION -->
            <section class="active-orders-section">

                <div class="active-section-header">

                    <div class="active-section-badge">
                        <i class="bi bi-lightning-charge-fill"></i>
                        Active Workflow
                    </div>

                    <div class="small text-muted">
                        These orders still require store action.
                    </div>

                </div>

                <!-- ACTIVE ORDERS HEADING -->
                <div class="active-orders-heading">
                    <div>
                        <h3 class="active-orders-title">
                            <?= htmlspecialchars($orders_section_title) ?>
                        </h3>
                        <p class="active-orders-description">
                            <?= htmlspecialchars($orders_section_description) ?>
                        </p>
                    </div>

                    <?php if ($total_orders > 0): ?>
                        <div class="small text-muted">
                            Showing
                            <?= $offset + 1 ?>–
                            <?= min($offset + $per_page, $total_orders) ?>
                            of
                            <?= $total_orders ?>
                            order(s)
                        </div>
                    <?php endif; ?>
                </div>

                <!-- ORDERS -->
            <?php if (empty($orders)): ?>

                <div class="order-card empty-state">

                    <i class="bi bi-inbox fs-1 d-block mb-2"></i>

                    <?php if ($search !== ''): ?>

                        <div>
                            No orders matched
                            <strong>
                                <?= htmlspecialchars($search) ?>
                            </strong>.
                        </div>

                    <?php else: ?>

                        <div>
                            No orders found in this category.
                        </div>

                    <?php endif; ?>

                </div>

            <?php else: ?>

                <div class="orders-grid">

                <?php foreach ($orders as $order): ?>

                    <?php
                    $order_id = (int)$order['id'];
                    $items = $order_items[$order_id] ?? [];
                    $status = (string)$order['status'];
                    $cancelled_by_role =
                        $cancelled_by_roles[$order_id] ?? null;

                    $payment_proof =
                        staffAssetPath(
                            $order['payment_screenshot'] ?? ''
                        );
                    ?>

                    <div class="order-card">

                        <!-- ORDER HEADER -->
                        <div class="d-flex justify-content-between flex-wrap gap-2">

                            <div>

                                <div class="fw-bold fs-5" style="color:#4A3525;">

                                    <?= htmlspecialchars(
                                        $order['order_number']
                                        ?: 'ORD-' . $order_id
                                    ) ?>

                                </div>

                                <div class="text-muted small">

                                    Claim No:
                                    <?= htmlspecialchars(
                                        $order['claim_number']
                                        ?: 'N/A'
                                    ) ?>

                                </div>

                            </div>

                            <div class="text-end">

                                <span
                                    class="status-badge status-<?= htmlspecialchars($status) ?>"
                                >
                                    <?= htmlspecialchars(
                                        staffStatusLabel(
                                            $status,
                                            $status_labels
                                        )
                                    ) ?>
                                </span>


                            </div>

                        </div>

                        <hr>

                        <!-- CUSTOMER / PICKUP / PAYMENT -->
                        <div class="row small">

                            <div class="col-md-4 mb-3">

                                <div class="info-label">
                                    Customer
                                </div>

                                <div class="info-value">
                                    <?= htmlspecialchars(
                                        $order['customer_name']
                                    ) ?>
                                </div>

                                <div class="text-muted">
                                    <?= htmlspecialchars(
                                        $order['contact_number']
                                    ) ?>
                                </div>

                            </div>

                            <div class="col-md-4 mb-3">

                                <div class="info-label">
                                    Pick-up
                                </div>

                                <div class="info-value">

                                    <?= htmlspecialchars(
                                        $order['pickup_date']
                                    ) ?>

                                    @

                                    <?= htmlspecialchars(
                                        staffFormatTime(
                                            $order['pickup_time']
                                        )
                                    ) ?>

                                </div>

                            </div>

                            <div class="col-md-4 mb-3">

                                <div class="info-label">
                                    Payment
                                </div>

                                <div class="info-value text-uppercase">

                                    <?= htmlspecialchars(
                                        $order['payment_method']
                                    ) ?>

                                </div>

                            </div>

                        </div>

                        <!-- ORDER ITEMS -->
                        <div class="order-items-card">
                            <div class="order-items-title">
                                <i class="bi bi-cup-straw me-1"></i>
                                Order Items
                            </div>

                            <?php if ($items): ?>
                                <?php foreach ($items as $item): ?>
                                    <?php
                                    $addonDetails = staffGetAddons(
                                        $item['addons'] ?? null
                                    );
                                    ?>

                                    <div class="order-item-row">
                                        <div class="d-flex justify-content-between align-items-start gap-3">
                                            <div class="flex-grow-1">
                                                <div class="order-item-name">
                                                    <?= htmlspecialchars(
                                                        $item['product_name']
                                                    ) ?>
                                                    <span class="order-item-quantity">
                                                        × <?= (int)$item['quantity'] ?>
                                                    </span>
                                                </div>

                                                <div class="order-item-base-price">
                                                    Base Price: ₱<?= number_format(
                                                        (float)($item['unit_price'] ?? 0),
                                                        2
                                                    ) ?> each
                                                </div>

                                                <div class="order-item-customization">
                                                    <div class="order-item-customization-main">
                                                        <?php if (!empty($item['size'])): ?>
                                                            <span>
                                                                Size: <?= htmlspecialchars((string)$item['size']) ?>
                                                            </span>
                                                        <?php endif; ?>

                                                        <?php if (!empty($item['sugar_level'])): ?>
                                                            <span>
                                                                Sugar: <?= htmlspecialchars((string)$item['sugar_level']) ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>

                                                    <?php if ($addonDetails): ?>
                                                        <div class="order-addon-label">Add-ons</div>

                                                        <div class="order-addon-list">
                                                            <?php foreach ($addonDetails as $addonDetail): ?>
                                                                <span class="order-addon-chip">
                                                                    <?= htmlspecialchars($addonDetail['name']) ?>
                                                                    <span class="order-addon-chip-price">
                                                                        +₱<?= number_format((float)$addonDetail['price'], 2) ?>
                                                                    </span>
                                                                </span>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                            <div class="order-item-price">
                                                ₱<?= number_format(
                                                    (float)$item['subtotal'],
                                                    2
                                                ) ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-muted small">
                                    No order items were found.
                                </div>
                            <?php endif; ?>

                            <!-- ORDER TOTAL -->
                            <div class="order-total-summary">
                                <span class="order-total-label">Total</span>
                                <span class="order-total-amount">
                                    ₱<?= number_format(
                                        (float)$order['total_amount'],
                                        2
                                    ) ?>
                                </span>
                            </div>
                        </div>

                        <!-- ACTIONS -->
                        <div class="order-actions">

                            <!-- PRINT RECEIPT -->
                            <button
                                type="button"
                                class="btn action-btn btn-outline-dark"
                                data-bs-toggle="modal"
                                data-bs-target="#receiptModal<?= $order_id ?>"
                            >
                                <i class="bi bi-printer me-1"></i>
                                Print Receipt
                            </button>

                            <!-- GCASH PROOF -->
                            <?php if (
                                strtolower(
                                    (string)$order['payment_method']
                                ) === 'gcash'
                                && $payment_proof !== ''
                            ): ?>

                                <button
                                    type="button"
                                    class="btn action-btn btn-outline-dark"
                                    data-bs-toggle="modal"
                                    data-bs-target="#paymentProofModal<?= $order_id ?>"
                                >
                                    <i class="bi bi-image me-1"></i>
                                    GCash Proof
                                </button>

                            <?php endif; ?>

                            <!-- PENDING -> CONFIRMED -->
                            <?php if (in_array($status, ['pending_verification'], true)): ?>

                                <form method="POST">

                                    <input
                                        type="hidden"
                                        name="order_id"
                                        value="<?= $order_id ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="status"
                                        value="confirmed"
                                    >

                                    <input
                                        type="hidden"
                                        name="status_filter"
                                        value="<?= htmlspecialchars($selected_status) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="q"
                                        value="<?= htmlspecialchars($search) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="page"
                                        value="<?= $page ?>"
                                    >

                                    <button
                                        type="submit"
                                        name="update_status"
                                        class="btn action-btn btn-confirm"
                                    >
                                        <i class="bi bi-check-circle me-1"></i>
                                        Confirm Order
                                    </button>

                                </form>

                            <?php endif; ?>

                            <!-- CONFIRMED -> PREPARING -->
                            <?php if ($status === 'confirmed'): ?>

                                <form method="POST">

                                    <input
                                        type="hidden"
                                        name="order_id"
                                        value="<?= $order_id ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="status"
                                        value="preparing"
                                    >

                                    <input
                                        type="hidden"
                                        name="status_filter"
                                        value="<?= htmlspecialchars($selected_status) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="q"
                                        value="<?= htmlspecialchars($search) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="page"
                                        value="<?= $page ?>"
                                    >

                                    <button
                                        type="submit"
                                        name="update_status"
                                        class="btn action-btn btn-preparing"
                                    >
                                        <i class="bi bi-cup-hot me-1"></i>
                                        Start Preparing
                                    </button>

                                </form>

                            <?php endif; ?>

                            <!-- PREPARING -> READY -->
                            <?php if ($status === 'preparing'): ?>

                                <form method="POST">

                                    <input
                                        type="hidden"
                                        name="order_id"
                                        value="<?= $order_id ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="status"
                                        value="ready"
                                    >

                                    <input
                                        type="hidden"
                                        name="status_filter"
                                        value="<?= htmlspecialchars($selected_status) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="q"
                                        value="<?= htmlspecialchars($search) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="page"
                                        value="<?= $page ?>"
                                    >

                                    <button
                                        type="submit"
                                        name="update_status"
                                        class="btn action-btn btn-ready"
                                    >
                                        <i class="bi bi-bag-check me-1"></i>
                                        Mark as Ready
                                    </button>

                                </form>

                            <?php endif; ?>

                            <!-- READY -> COMPLETED -->
                            <?php if ($status === 'ready'): ?>

                                <form method="POST">

                                    <input
                                        type="hidden"
                                        name="order_id"
                                        value="<?= $order_id ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="status"
                                        value="completed"
                                    >

                                    <input
                                        type="hidden"
                                        name="status_filter"
                                        value="<?= htmlspecialchars($selected_status) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="q"
                                        value="<?= htmlspecialchars($search) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="page"
                                        value="<?= $page ?>"
                                    >

                                    <button
                                        type="submit"
                                        name="update_status"
                                        class="btn action-btn btn-complete"
                                    >
                                        <i class="bi bi-check2-all me-1"></i>
                                        Complete Order
                                    </button>

                                </form>

                            <?php endif; ?>

                            <!-- CANCEL -->
                            <?php if (
                                in_array(
                                    $status,
                                    [
                                        'pending_verification',
                                                                            'confirmed',
                                        'preparing',
                                        'ready'
                                    ],
                                    true
                                )
                            ): ?>

                                <button
                                    type="button"
                                    class="btn action-btn btn-cancel"
                                    data-bs-toggle="modal"
                                    data-bs-target="#cancelModal<?= $order_id ?>"
                                >
                                    <i class="bi bi-x-circle me-1"></i>
                                    Cancel
                                </button>

                            <?php endif; ?>

                        </div>

                    </div>

                    <!-- =================================================
                         RECEIPT MODAL
                    ================================================== -->
                    <div
                        class="modal fade"
                        id="receiptModal<?= $order_id ?>"
                        tabindex="-1"
                        aria-hidden="true"
                    >

                        <div class="modal-dialog modal-dialog-centered modal-lg">

                            <div class="modal-content">

                                <div class="modal-header">

                                    <h5
                                        class="modal-title fw-bold"
                                        style="color:#4A3525;"
                                    >
                                        Print Receipt
                                    </h5>

                                    <button
                                        type="button"
                                        class="btn-close"
                                        data-bs-dismiss="modal"
                                    ></button>

                                </div>

                                <div class="modal-body bg-light">

                                    <div
                                        class="receipt-paper"
                                        id="receiptPaper<?= $order_id ?>"
                                    >

                                        <div class="text-center">

                                            <h4 class="fw-bold mb-1">
                                                Local Milktea House
                                            </h4>

                                            <div class="small text-muted">
                                                Order Receipt
                                            </div>

                                        </div>

                                        <div class="receipt-line"></div>

                                        <div class="d-flex justify-content-between small">
                                            <span>Order #</span>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    $order['order_number']
                                                    ?: 'N/A'
                                                ) ?>
                                            </strong>
                                        </div>

                                        <div class="d-flex justify-content-between small">
                                            <span>Claim #</span>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    $order['claim_number']
                                                    ?: 'N/A'
                                                ) ?>
                                            </strong>
                                        </div>

                                        <div class="d-flex justify-content-between small">
                                            <span>Customer</span>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    $order['customer_name']
                                                ) ?>
                                            </strong>
                                        </div>

                                        <div class="d-flex justify-content-between small">
                                            <span>Pickup</span>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    $order['pickup_date']
                                                ) ?>
                                                <?= htmlspecialchars(
                                                    staffFormatTime(
                                                        $order['pickup_time']
                                                    )
                                                ) ?>
                                            </strong>
                                        </div>

                                        <div class="d-flex justify-content-between small">
                                            <span>Payment</span>
                                            <strong>
                                                <?= htmlspecialchars(
                                                    $order['payment_method']
                                                ) ?>
                                            </strong>
                                        </div>

                                        <div class="receipt-line"></div>

                                        <?php foreach ($items as $item): ?>

                                            <div class="mb-2">

                                                <div class="d-flex justify-content-between small">

                                                    <span>
                                                        <?= (int)$item['quantity'] ?>
                                                        ×
                                                        <?= htmlspecialchars(
                                                            $item['product_name']
                                                        ) ?>
                                                    </span>

                                                    <strong>
                                                        ₱<?= number_format(
                                                            (float)$item['subtotal'],
                                                            2
                                                        ) ?>
                                                    </strong>

                                                </div>

                                                <?php
                                                $receipt_customizations = [];

                                                if (!empty($item['size'])) {
                                                    $receipt_customizations[] =
                                                        'Size: ' . $item['size'];
                                                }

                                                if (!empty($item['sugar_level'])) {
                                                    $receipt_customizations[] =
                                                        'Sugar: ' . $item['sugar_level'];
                                                }

                                                if (!empty($item['addons'])) {

                                                    $receipt_addons =
                                                        json_decode(
                                                            (string)$item['addons'],
                                                            true
                                                        );

                                                    if (is_array($receipt_addons)) {

                                                        $receipt_addons_text =
                                                            implode(
                                                                ', ',
                                                                array_map(
                                                                    'strval',
                                                                    $receipt_addons
                                                                )
                                                            );

                                                    } else {

                                                        $receipt_addons_text =
                                                            (string)$item['addons'];
                                                    }

                                                    if (
                                                        trim($receipt_addons_text) !== ''
                                                    ) {

                                                        $receipt_customizations[] =
                                                            'Add-ons: ' .
                                                            $receipt_addons_text;
                                                    }
                                                }
                                                ?>

                                                <?php if ($receipt_customizations): ?>

                                                    <div
                                                        class="text-muted"
                                                        style="font-size:.68rem;"
                                                    >
                                                        <?= htmlspecialchars(
                                                            implode(
                                                                ' • ',
                                                                $receipt_customizations
                                                            )
                                                        ) ?>
                                                    </div>

                                                <?php endif; ?>

                                            </div>

                                        <?php endforeach; ?>

                                        <div class="receipt-line"></div>

                                        <div class="d-flex justify-content-between fw-bold">

                                            <span>Total</span>

                                            <span>
                                                ₱<?= number_format(
                                                    (float)$order['total_amount'],
                                                    2
                                                ) ?>
                                            </span>

                                        </div>

                                        <div class="text-center small text-muted mt-4">
                                            Thank you for ordering with us!
                                        </div>

                                    </div>

                                </div>

                                <div class="modal-footer">

                                    <button
                                        type="button"
                                        class="btn btn-outline-secondary"
                                        data-bs-dismiss="modal"
                                    >
                                        Close
                                    </button>

                                    <button
                                        type="button"
                                        class="btn btn-dark"
                                        onclick="printReceipt('receiptModal<?= $order_id ?>')"
                                    >
                                        <i class="bi bi-printer me-1"></i>
                                        Print Receipt
                                    </button>

                                </div>

                            </div>

                        </div>

                    </div>

                    <!-- =================================================
                         GCASH PROOF MODAL
                    ================================================== -->
                    <?php if (
                        strtolower(
                            (string)$order['payment_method']
                        ) === 'gcash'
                        && $payment_proof !== ''
                    ): ?>

                        <div
                            class="modal fade"
                            id="paymentProofModal<?= $order_id ?>"
                            tabindex="-1"
                            aria-hidden="true"
                        >

                            <div class="modal-dialog modal-dialog-centered modal-lg">

                                <div class="modal-content">

                                    <div class="modal-header">

                                        <h5 class="modal-title">
                                            GCash Payment Proof
                                        </h5>

                                        <button
                                            type="button"
                                            class="btn-close"
                                            data-bs-dismiss="modal"
                                        ></button>

                                    </div>

                                    <div class="modal-body text-center">

                                        <img
                                            src="<?= htmlspecialchars($payment_proof) ?>"
                                            alt="GCash Payment Proof"
                                            class="payment-proof-image"
                                        >

                                    </div>

                                </div>

                            </div>

                        </div>

                    <?php endif; ?>

                    <!-- =================================================
                         CANCEL MODAL
                    ================================================== -->
                    <?php if (
                        in_array(
                            $status,
                            [
                                'pending_verification',
                                                            'confirmed',
                                'preparing',
                                'ready'
                            ],
                            true
                        )
                    ): ?>

                        <div
                            class="modal fade"
                            id="cancelModal<?= $order_id ?>"
                            tabindex="-1"
                            aria-hidden="true"
                        >

                            <div class="modal-dialog modal-dialog-centered">

                                <div class="modal-content">

                                    <form method="POST">

                                        <div class="modal-header">

                                            <h5 class="modal-title fw-bold">
                                                Cancel Order
                                            </h5>

                                            <button
                                                type="button"
                                                class="btn-close"
                                                data-bs-dismiss="modal"
                                            ></button>

                                        </div>

                                        <div class="modal-body">

                                            <p class="small text-muted">

                                                You are cancelling

                                                <strong>
                                                    <?= htmlspecialchars(
                                                        $order['order_number']
                                                        ?: 'this order'
                                                    ) ?>
                                                </strong>.

                                                Please provide a reason.

                                            </p>

                                            <input
                                                type="hidden"
                                                name="cancel_order"
                                                value="1"
                                            >

                                            <input
                                                type="hidden"
                                                name="order_id"
                                                value="<?= $order_id ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="status_filter"
                                                value="<?= htmlspecialchars($selected_status) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="q"
                                                value="<?= htmlspecialchars($search) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="page"
                                                value="<?= $page ?>"
                                            >

                                            <label class="form-label small fw-semibold">
                                                Cancellation Reason
                                            </label>

                                            <select
                                                name="cancellation_reason"
                                                class="form-select"
                                                required
                                            >
                                                <option value="" selected disabled>
                                                    Select a reason
                                                </option>

                                                <option value="Customer did not arrive for pick-up">
                                                    Customer did not arrive for pick-up
                                                </option>

                                                <option value="Payment could not be verified">
                                                    Payment could not be verified
                                                </option>

                                                <option value="Payment issue">
                                                    Payment issue
                                                </option>

                                                <option value="Product unavailable">
                                                    Product unavailable
                                                </option>

                                                <option value="Order cannot be fulfilled">
                                                    Order cannot be fulfilled
                                                </option>

                                                <option value="Duplicate order">
                                                    Duplicate order
                                                </option>

                                                <option value="Incorrect order details">
                                                    Incorrect order details
                                                </option>

                                                <option value="Store operational issue">
                                                    Store operational issue
                                                </option>

                                                <option value="Other">
                                                    Other
                                                </option>

                                            </select>

                                            <div
                                                class="mt-3"
                                                data-other-reason
                                                style="display:none;"
                                            >

                                                <label class="form-label small fw-semibold">
                                                    Other Reason
                                                </label>

                                                <textarea
                                                    class="form-control"
                                                    rows="3"
                                                    maxlength="255"
                                                    placeholder="Enter the cancellation reason..."
                                                ></textarea>

                                            </div>

                                        </div>

                                        <div class="modal-footer">

                                            <button
                                                type="button"
                                                class="btn btn-outline-secondary"
                                                data-bs-dismiss="modal"
                                            >
                                                Keep Order
                                            </button>

                                            <button
                                                type="submit"
                                                class="btn btn-danger"
                                            >
                                                <i class="bi bi-x-circle me-1"></i>
                                                Cancel Order
                                            </button>

                                        </div>

                                    </form>

                                </div>

                            </div>

                        </div>

                    <?php endif; ?>

                <?php endforeach; ?>

                </div>

                <!-- PAGINATION -->
                <?php if ($total_pages > 1): ?>

                    <div class="pagination-wrap">

                        <nav aria-label="Order pagination">

                            <ul class="pagination mb-0">

                                <li
                                    class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"
                                >
                                    <a
                                        class="page-link"
                                        href="<?= htmlspecialchars(
                                            staffOrdersUrl([
                                                'page' => max(1, $page - 1)
                                            ])
                                        ) ?>"
                                    >
                                        Previous
                                    </a>
                                </li>

                                <?php
                                $start_page = max(1, $page - 2);
                                $end_page = min(
                                    $total_pages,
                                    $page + 2
                                );

                                for (
                                    $p = $start_page;
                                    $p <= $end_page;
                                    $p++
                                ):
                                ?>

                                    <li
                                        class="page-item <?= $p === $page ? 'active' : '' ?>"
                                    >

                                        <a
                                            class="page-link"
                                            href="<?= htmlspecialchars(
                                                staffOrdersUrl([
                                                    'page' => $p
                                                ])
                                            ) ?>"
                                        >
                                            <?= $p ?>
                                        </a>

                                    </li>

                                <?php endfor; ?>

                                <li
                                    class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>"
                                >

                                    <a
                                        class="page-link"
                                        href="<?= htmlspecialchars(
                                            staffOrdersUrl([
                                                'page' => min(
                                                    $total_pages,
                                                    $page + 1
                                                )
                                            ])
                                        ) ?>"
                                    >
                                        Next
                                    </a>

                                </li>

                            </ul>

                        </nav>

                    </div>

                <?php endif; ?>

            <?php endif; ?>

            </section>

            <!-- =====================================================
                 CANCELLED ORDERS
                 Collapsed by default so historical records do not
                 make the Orders page unnecessarily long.
            ====================================================== -->
            <details
                class="cancelled-orders-section"
                <?= (
                    $cancelled_search !== ''
                    || $cancelled_period !== 'today'
                    || $cancelled_open === '1'
                ) ? 'open' : '' ?>
            >
                <summary class="cancelled-section-summary">

                    <div class="cancelled-section-heading">

                        <div>
                            <h3 class="cancelled-section-title">
                                <i class="bi bi-x-circle me-1"></i>
                                CANCELLED ORDERS
                            </h3>

                            <p class="cancelled-section-description">
                                View orders that were cancelled during the order process.
                            </p>
                        </div>

                        <div class="text-end">
                            <div class="cancelled-history-badge mb-2">
                                <i class="bi bi-archive-fill"></i>
                                Historical Records
                            </div>
                            <div class="small text-muted">
                                <?= $cancelled_total_orders ?> cancelled order(s)
                            </div>
                        </div>

                    </div>

                    <div class="cancelled-toggle" aria-hidden="true">
                        <span class="cancelled-toggle-label cancelled-toggle-open">
                            Open
                        </span>
                        <span class="cancelled-toggle-label cancelled-toggle-close">
                            Close
                        </span>
                        <i class="bi bi-chevron-down cancelled-toggle-icon"></i>
                    </div>

                </summary>

                <div class="cancelled-section-content">

                    <form method="GET" class="cancelled-filter-form" data-cancelled-filter-form>

                        <input
                            type="hidden"
                            name="status"
                            value="<?= htmlspecialchars($selected_status) ?>"
                        >

                        <input
                            type="hidden"
                            name="q"
                            value="<?= htmlspecialchars($search) ?>"
                        >

                        <input
                            type="hidden"
                            name="page"
                            value="<?= $page ?>"
                        >

                        <input
                            type="hidden"
                            name="cancelled_open"
                            value="1"
                        >

                        <div class="cancelled-filter-group">

                            <label for="cancelledPeriod">
                                Cancelled Date
                            </label>

                            <select
                                id="cancelledPeriod"
                                name="cancelled_period"
                                class="cancelled-filter-period"
                                data-cancelled-period
                            >
                                <?php foreach ($cancelled_period_labels as $periodKey => $periodLabel): ?>
                                    <option
                                        value="<?= htmlspecialchars($periodKey) ?>"
                                        <?= $cancelled_period === $periodKey ? 'selected' : '' ?>
                                    >
                                        <?= htmlspecialchars($periodLabel) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                        </div>

                        <div
                            class="cancelled-filter-group"
                            data-cancelled-month-wrap
                            style="<?= $cancelled_period === 'specific_month' ? '' : 'display:none;' ?>"
                        >

                            <label for="cancelledMonth">
                                Choose Month
                            </label>

                            <input
                                type="month"
                                id="cancelledMonth"
                                name="cancelled_month"
                                class="cancelled-filter-month"
                                value="<?= htmlspecialchars($cancelled_month) ?>"
                                max="<?= date('Y-m') ?>"
                                data-cancelled-month
                            >

                        </div>

                        <div class="cancelled-filter-group">

                            <label for="cancelledSearch">
                                Search Cancelled Orders
                            </label>

                            <input
                                type="search"
                                id="cancelledSearch"
                                name="cancelled_q"
                                class="cancelled-filter-search"
                                value="<?= htmlspecialchars($cancelled_search) ?>"
                                data-cancelled-search
                                placeholder="Order number, claim number, customer..."
                                autocomplete="off"
                            >

                        </div>

                        <div class="cancelled-filter-actions">

                            <button
                                type="submit"
                                class="btn search-button"
                            >
                                <i class="bi bi-search me-1"></i>
                                Search
                            </button>

                            <?php if (
                                $cancelled_search !== ''
                                || $cancelled_period !== 'today'
                            ): ?>

                                <a
                                    href="<?= htmlspecialchars(
                                        staffOrdersUrl([
                                            'cancelled_q' => '',
                                            'cancelled_period' => 'today',
                                            'cancelled_month' => '',
                                            'cancelled_page' => 1,
                                            'cancelled_open' => '1'
                                        ])
                                    ) ?>"
                                    class="btn clear-button"
                                >
                                    Clear
                                </a>

                            <?php endif; ?>

                        </div>

                    </form>

                    <?php if (empty($cancelled_orders)): ?>

                        <div class="order-card empty-state">

                            <i class="bi bi-inbox fs-1 d-block mb-2"></i>

                            <?php if (
                                $cancelled_search !== ''
                            ): ?>

                                <div>
                                    No cancelled orders matched the selected filters.
                                </div>

                            <?php else: ?>

                                <div>
                                    No cancelled orders have been recorded.
                                </div>

                            <?php endif; ?>

                        </div>

                    <?php else: ?>

                        <div class="small text-muted mb-2">

                            Showing
                            <?= $cancelled_offset + 1 ?>–
                            <?= min(
                                $cancelled_offset + $cancelled_per_page,
                                $cancelled_total_orders
                            ) ?>
                            of
                            <?= $cancelled_total_orders ?>
                            cancelled order(s)

                        </div>

                        <div class="orders-grid">

                            <?php foreach ($cancelled_orders as $cancelled_order): ?>

                                <?php
                                $cancelled_order_id =
                                    (int)$cancelled_order['id'];

                                $cancelled_items =
                                    $order_items[$cancelled_order_id] ?? [];

                                $cancelled_by_role =
                                    $cancelled_by_roles[$cancelled_order_id]
                                    ?? null;

                                $cancelled_payment_proof =
                                    staffAssetPath(
                                        $cancelled_order['payment_screenshot']
                                        ?? ''
                                    );
                                ?>

                                <div class="order-card cancelled-order-card">

                                    <div class="d-flex justify-content-between flex-wrap gap-2">

                                        <div>

                                            <div
                                                class="fw-bold fs-5"
                                                style="color:#4A3525;"
                                            >
                                                <?= htmlspecialchars(
                                                    $cancelled_order['order_number']
                                                    ?: 'ORD-' . $cancelled_order_id
                                                ) ?>
                                            </div>

                                            <div class="text-muted small">
                                                Claim No:
                                                <?= htmlspecialchars(
                                                    $cancelled_order['claim_number']
                                                    ?: 'N/A'
                                                ) ?>
                                            </div>

                                        </div>

                                        <div class="text-end">

                                            <span class="status-badge status-cancelled">
                                                Cancelled
                                            </span>

                                        </div>

                                    </div>

                                    <hr>

                                    <div class="row small">

                                        <div class="col-md-4 mb-3">

                                            <div class="info-label">
                                                Customer
                                            </div>

                                            <div class="info-value">
                                                <?= htmlspecialchars(
                                                    $cancelled_order['customer_name']
                                                ) ?>
                                            </div>

                                            <div class="text-muted">
                                                <?= htmlspecialchars(
                                                    $cancelled_order['contact_number']
                                                ) ?>
                                            </div>

                                        </div>

                                        <div class="col-md-4 mb-3">

                                            <div class="info-label">
                                                Pick-up
                                            </div>

                                            <div class="info-value">
                                                <?= htmlspecialchars(
                                                    $cancelled_order['pickup_date']
                                                ) ?>
                                                @
                                                <?= htmlspecialchars(
                                                    staffFormatTime(
                                                        $cancelled_order['pickup_time']
                                                    )
                                                ) ?>
                                            </div>

                                        </div>

                                        <div class="col-md-4 mb-3">

                                            <div class="info-label">
                                                Payment
                                            </div>

                                            <div class="info-value text-uppercase">
                                                <?= htmlspecialchars(
                                                    $cancelled_order['payment_method']
                                                ) ?>
                                            </div>

                                        </div>

                                    </div>

                                    <div class="cancellation-box mt-1">

                                        <div class="row small">

                                            <div class="col-md-4 mb-2 mb-md-0">
                                                <div class="info-label">
                                                    Cancellation Reason
                                                </div>

                                                <div class="info-value text-danger">
                                                    <?= !empty(
                                                        $cancelled_order['cancellation_reason']
                                                    )
                                                        ? htmlspecialchars(
                                                            $cancelled_order['cancellation_reason']
                                                        )
                                                        : 'No reason recorded.' ?>
                                                </div>
                                            </div>

                                            <div class="col-md-4 mb-2 mb-md-0">
                                                <div class="info-label">
                                                    Cancelled At
                                                </div>

                                                <div class="info-value">
                                                    <?= !empty($cancelled_order['closed_at'])
                                                        ? htmlspecialchars(
                                                            date(
                                                                'M d, Y h:i A',
                                                                strtotime(
                                                                    $cancelled_order['closed_at']
                                                                )
                                                            )
                                                        )
                                                        : '—' ?>
                                                </div>
                                            </div>

                                            <div class="col-md-4">
                                                <div class="info-label">
                                                    Cancelled By
                                                </div>

                                                <div class="info-value text-danger">
                                                    <?= htmlspecialchars(
                                                        staffCancellationActorLabel(
                                                            $cancelled_by_role
                                                        )
                                                    ) ?>
                                                </div>
                                            </div>

                                        </div>

                                    </div>

                                    <div class="order-items-card mt-3">

                                        <div class="order-items-title">
                                            <i class="bi bi-cup-straw me-1"></i>
                                            Order Items
                                        </div>

                                        <?php if ($cancelled_items): ?>

                                            <?php foreach ($cancelled_items as $item): ?>

                                                <?php
                                                $addonDetails = staffGetAddons(
                                                    $item['addons'] ?? null
                                                );
                                                ?>

                                                <div class="order-item-row">

                                                    <div class="d-flex justify-content-between align-items-start gap-3">

                                                        <div class="flex-grow-1">

                                                            <div class="order-item-name">
                                                                <?= htmlspecialchars(
                                                                    $item['product_name']
                                                                ) ?>

                                                                <span class="order-item-quantity">
                                                                    × <?= (int)$item['quantity'] ?>
                                                                </span>
                                                            </div>

                                                            <div class="order-item-base-price">
                                                                Base Price: ₱<?= number_format(
                                                                    (float)($item['unit_price'] ?? 0),
                                                                    2
                                                                ) ?> each
                                                            </div>

                                                            <?php if (
                                                                !empty($item['size'])
                                                                || !empty($item['sugar_level'])
                                                            ): ?>

                                                                <div class="order-item-customization">
                                                                    <div class="order-item-customization-main">

                                                                        <?php if (!empty($item['size'])): ?>
                                                                            <span>
                                                                                Size: <?= htmlspecialchars(
                                                                                    $item['size']
                                                                                ) ?>
                                                                            </span>
                                                                        <?php endif; ?>

                                                                        <?php if (!empty($item['sugar_level'])): ?>
                                                                            <span>
                                                                                Sugar: <?= htmlspecialchars(
                                                                                    $item['sugar_level']
                                                                                ) ?>
                                                                            </span>
                                                                        <?php endif; ?>

                                                                    </div>
                                                                </div>

                                                            <?php endif; ?>

                                                            <?php if ($addonDetails): ?>

                                                                <span class="order-addon-label">
                                                                    Add-ons
                                                                </span>

                                                                <div class="order-addon-list">

                                                                    <?php foreach ($addonDetails as $addon): ?>

                                                                        <span class="order-addon-chip">

                                                                            <?= htmlspecialchars(
                                                                                $addon['name']
                                                                            ) ?>

                                                                            <span class="order-addon-chip-price">
                                                                                +₱<?= number_format(
                                                                                    (float)$addon['price'],
                                                                                    2
                                                                                ) ?>
                                                                            </span>

                                                                        </span>

                                                                    <?php endforeach; ?>

                                                                </div>

                                                            <?php endif; ?>

                                                        </div>

                                                        <div class="order-item-price">
                                                            ₱<?= number_format(
                                                                (float)($item['subtotal'] ?? 0),
                                                                2
                                                            ) ?>
                                                        </div>

                                                    </div>

                                                </div>

                                            <?php endforeach; ?>

                                        <?php else: ?>

                                            <div class="text-muted small">
                                                No order items were found for this order.
                                            </div>

                                        <?php endif; ?>

                                        <div class="order-total-summary">

                                            <span class="order-total-label">
                                                Total
                                            </span>

                                            <span class="order-total-amount">
                                                ₱<?= number_format(
                                                    (float)$cancelled_order['total_amount'],
                                                    2
                                                ) ?>
                                            </span>

                                        </div>

                                    </div>

                                    <div class="order-actions">

                                        <button
                                            type="button"
                                            class="action-btn btn-outline-dark border"
                                            data-bs-toggle="modal"
                                            data-bs-target="#detailsModal<?= $cancelled_order_id ?>"
                                        >
                                            <i class="bi bi-eye me-1"></i>
                                            View Details
                                        </button>

                                    </div>

                                </div>

                                <!-- CANCELLED ORDER DETAILS MODAL -->
                                <div
                                    class="modal fade"
                                    id="detailsModal<?= $cancelled_order_id ?>"
                                    tabindex="-1"
                                    aria-hidden="true"
                                >
                                    <div class="modal-dialog modal-lg modal-dialog-centered">

                                        <div class="modal-content">

                                            <div class="modal-header">

                                                <h5 class="modal-title fw-bold">
                                                    Cancelled Order Details
                                                </h5>

                                                <button
                                                    type="button"
                                                    class="btn-close"
                                                    data-bs-dismiss="modal"
                                                    aria-label="Close"
                                                ></button>

                                            </div>

                                            <div class="modal-body">

                                                <div class="mb-3">

                                                    <div class="details-section-title">
                                                        Order Information
                                                    </div>

                                                    <div class="row small">

                                                        <div class="col-md-6 mb-2">
                                                            <div class="info-label">
                                                                Order Number
                                                            </div>
                                                            <div class="info-value">
                                                                <?= htmlspecialchars(
                                                                    $cancelled_order['order_number']
                                                                    ?: 'ORD-' . $cancelled_order_id
                                                                ) ?>
                                                            </div>
                                                        </div>

                                                        <div class="col-md-6 mb-2">
                                                            <div class="info-label">
                                                                Claim Number
                                                            </div>
                                                            <div class="info-value">
                                                                <?= htmlspecialchars(
                                                                    $cancelled_order['claim_number']
                                                                    ?: 'N/A'
                                                                ) ?>
                                                            </div>
                                                        </div>

                                                        <div class="col-md-6 mb-2">
                                                            <div class="info-label">
                                                                Customer
                                                            </div>
                                                            <div class="info-value">
                                                                <?= htmlspecialchars(
                                                                    $cancelled_order['customer_name']
                                                                ) ?>
                                                            </div>
                                                        </div>

                                                        <div class="col-md-6 mb-2">
                                                            <div class="info-label">
                                                                Payment
                                                            </div>
                                                            <div class="info-value text-uppercase">
                                                                <?= htmlspecialchars(
                                                                    $cancelled_order['payment_method']
                                                                ) ?>
                                                            </div>
                                                        </div>

                                                    </div>

                                                </div>

                                                <div class="cancellation-box mb-3">

                                                    <div class="details-section-title text-danger">
                                                        Cancellation Details
                                                    </div>

                                                    <div class="row small">

                                                        <div class="col-md-4 mb-2">
                                                            <div class="info-label">
                                                                Reason
                                                            </div>
                                                            <div class="info-value">
                                                                <?= !empty(
                                                                    $cancelled_order['cancellation_reason']
                                                                )
                                                                    ? htmlspecialchars(
                                                                        $cancelled_order['cancellation_reason']
                                                                    )
                                                                    : 'No reason recorded.' ?>
                                                            </div>
                                                        </div>

                                                        <div class="col-md-4 mb-2">
                                                            <div class="info-label">
                                                                Cancelled At
                                                            </div>
                                                            <div class="info-value">
                                                                <?= !empty($cancelled_order['closed_at'])
                                                                    ? htmlspecialchars(
                                                                        date(
                                                                            'M d, Y h:i A',
                                                                            strtotime(
                                                                                $cancelled_order['closed_at']
                                                                            )
                                                                        )
                                                                    )
                                                                    : '—' ?>
                                                            </div>
                                                        </div>

                                                        <div class="col-md-4 mb-2">
                                                            <div class="info-label">
                                                                Cancelled By
                                                            </div>
                                                            <div class="info-value">
                                                                <?= htmlspecialchars(
                                                                    staffCancellationActorLabel(
                                                                        $cancelled_by_role
                                                                    )
                                                                ) ?>
                                                            </div>
                                                        </div>

                                                    </div>

                                                </div>

                                                <?php if ($cancelled_payment_proof && strtolower(
                                                    trim((string)$cancelled_order['payment_method'])
                                                ) === 'gcash'): ?>

                                                    <div class="mb-3">

                                                        <div class="details-section-title">
                                                            GCash Payment Proof
                                                        </div>

                                                        <a
                                                            href="<?= htmlspecialchars($cancelled_payment_proof) ?>"
                                                            target="_blank"
                                                            rel="noopener"
                                                            class="btn btn-sm btn-outline-dark"
                                                        >
                                                            <i class="bi bi-image me-1"></i>
                                                            Open Payment Proof
                                                        </a>

                                                    </div>

                                                <?php endif; ?>

                                            </div>

                                        </div>

                                    </div>
                                </div>

                            <?php endforeach; ?>

                        </div>

                        <?php if ($cancelled_total_pages > 1): ?>

                            <div class="cancelled-pagination" aria-label="Cancelled order pagination">

                                <?php if ($cancelled_page > 1): ?>

                                    <a
                                        href="<?= htmlspecialchars(
                                            staffOrdersUrl([
                                                'cancelled_page' => $cancelled_page - 1,
                                                'cancelled_open' => '1'
                                            ])
                                        ) ?>"
                                        aria-label="Previous page"
                                        data-cancelled-pagination
                                    >
                                        <i class="bi bi-chevron-left"></i>
                                    </a>

                                <?php else: ?>

                                    <span class="disabled" aria-hidden="true">
                                        <i class="bi bi-chevron-left"></i>
                                    </span>

                                <?php endif; ?>

                                <?php
                                $cancelledPaginationPages = [1];

                                for (
                                    $cancelled_p = max(2, $cancelled_page - 2);
                                    $cancelled_p <= min(
                                        $cancelled_total_pages - 1,
                                        $cancelled_page + 2
                                    );
                                    $cancelled_p++
                                ) {
                                    $cancelledPaginationPages[] = $cancelled_p;
                                }

                                $cancelledPaginationPages[] = $cancelled_total_pages;

                                $cancelledPaginationPages = array_values(
                                    array_unique($cancelledPaginationPages)
                                );

                                $cancelledPreviousPage = 0;

                                foreach (
                                    $cancelledPaginationPages
                                    as $cancelled_p
                                ):

                                    if (
                                        $cancelledPreviousPage > 0
                                        && $cancelled_p > $cancelledPreviousPage + 1
                                    ):
                                ?>

                                        <span class="ellipsis" aria-hidden="true">
                                            ...
                                        </span>

                                    <?php endif; ?>

                                    <?php if ($cancelled_p === $cancelled_page): ?>

                                        <span
                                            class="active"
                                            aria-current="page"
                                        >
                                            <?= $cancelled_p ?>
                                        </span>

                                    <?php else: ?>

                                        <a
                                            href="<?= htmlspecialchars(
                                                staffOrdersUrl([
                                                    'cancelled_page' => $cancelled_p,
                                                    'cancelled_open' => '1'
                                                ])
                                            ) ?>"
                                            data-cancelled-pagination
                                        >
                                            <?= $cancelled_p ?>
                                        </a>

                                    <?php endif; ?>

                                <?php
                                    $cancelledPreviousPage = $cancelled_p;
                                endforeach;
                                ?>

                                <?php if ($cancelled_page < $cancelled_total_pages): ?>

                                    <a
                                        href="<?= htmlspecialchars(
                                            staffOrdersUrl([
                                                'cancelled_page' => $cancelled_page + 1,
                                                'cancelled_open' => '1'
                                            ])
                                        ) ?>"
                                        aria-label="Next page"
                                        data-cancelled-pagination
                                    >
                                        <i class="bi bi-chevron-right"></i>
                                    </a>

                                <?php else: ?>

                                    <span class="disabled" aria-hidden="true">
                                        <i class="bi bi-chevron-right"></i>
                                    </span>

                                <?php endif; ?>

                            </div>

                        <?php endif; ?>

                    <?php endif; ?>

                </div>

            </details>


        </div>

    </main>

</div>

<!-- =========================================================
     SCRIPT 1
     SCROLL POSITION
========================================================= -->
<script>
/* Preserve the Staff's scroll position when searching/filtering orders.
   The page still refreshes normally, but it returns to the previous
   position instead of jumping to the top. */
document.addEventListener('DOMContentLoaded', function () {

    const savedScrollY =
        sessionStorage.getItem('staffOrdersScrollY');

    if (savedScrollY !== null) {

        sessionStorage.removeItem('staffOrdersScrollY');

        requestAnimationFrame(function () {

            window.scrollTo(
                0,
                parseInt(savedScrollY, 10) || 0
            );

        });
    }


    const preserveScrollForms =
        document.querySelectorAll(
            '.order-search-form, .cancelled-filter-form, form:has(button[name="update_status"]), form:has(button[name="cancel_order"])'
        );


    preserveScrollForms.forEach(function (form) {

        form.addEventListener('submit', function () {

            sessionStorage.setItem(
                'staffOrdersScrollY',
                String(window.scrollY)
            );

        });

    });


    /* Also preserve scroll position when clicking pagination links
       (both the main Orders pagination and the Cancelled Orders
       pagination), so paging through results does not jump back
       to the top of the page. */
    const paginationLinks =
        document.querySelectorAll(
            '.pagination .page-link[href]'
        );


    paginationLinks.forEach(function (link) {

        link.addEventListener('click', function (event) {

            if (link.closest('.page-item.disabled')) {
                return;
            }


            sessionStorage.setItem(
                'staffOrdersScrollY',
                String(window.scrollY)
            );

        });

    });

});
</script>


<!-- =========================================================
     SCRIPT 2
     STAFF PROFILE
     OTHER CANCELLATION REASON
     PRINT RECEIPT
========================================================= -->
<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {

        /* =====================================================
           OTHER CANCELLATION REASON
        ===================================================== */

        document
            .querySelectorAll(
                '[id^="cancelModal"]'
            )
            .forEach(function (modal) {

                const select =
                    modal.querySelector(
                        'select[name="cancellation_reason"]'
                    );


                const otherWrap =
                    modal.querySelector(
                        '[data-other-reason]'
                    );


                const otherTextarea =
                    otherWrap
                        ? otherWrap.querySelector(
                            'textarea'
                        )
                        : null;


                const form =
                    modal.querySelector('form');


                if (!select || !form) {
                    return;
                }


                select.addEventListener(
                    'change',
                    function () {

                        if (this.value === 'Other') {

                            if (otherWrap) {
                                otherWrap.style.display =
                                    'block';
                            }


                            if (otherTextarea) {

                                otherTextarea.required =
                                    true;

                                otherTextarea.focus();

                            }

                        } else {

                            if (otherWrap) {
                                otherWrap.style.display =
                                    'none';
                            }


                            if (otherTextarea) {

                                otherTextarea.required =
                                    false;

                                otherTextarea.value = '';

                            }

                        }

                    }
                );


                form.addEventListener(
                    'submit',
                    function (event) {

                        if (select.value === 'Other') {

                            const reason =
                                otherTextarea
                                    ? otherTextarea.value.trim()
                                    : '';


                            if (reason === '') {

                                event.preventDefault();

                                if (otherTextarea) {
                                    otherTextarea.focus();
                                }

                                return;
                            }


                            /*
                             * Replace the select value with
                             * the custom reason before submitting.
                             */
                            select.value = reason;


                            /*
                             * The select does not contain the
                             * custom value as an option, so add
                             * it temporarily.
                             */
                            const customOption =
                                document.createElement(
                                    'option'
                                );


                            customOption.value =
                                reason;


                            customOption.textContent =
                                reason;


                            customOption.selected =
                                true;


                            select.appendChild(
                                customOption
                            );

                        }

                    }
                );

            });

    }
);


/* =========================================================
   PRINT RECEIPT
========================================================= */

function printReceipt(modalId) {

    const modalElement =
        document.getElementById(
            modalId
        );


    if (!modalElement) {
        return;
    }


    const modalInstance =
        bootstrap.Modal.getInstance(
            modalElement
        )
        ||
        bootstrap.Modal.getOrCreateInstance(
            modalElement
        );


    modalInstance.show();


    setTimeout(
        function () {

            window.print();

        },
        150
    );
}

</script>


<!-- =========================================================
     SCRIPT 3
     AJAX WORKFLOW NAVIGATION
     AJAX ORDER STATUS
     AJAX CANCEL ORDER
========================================================= -->
<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {

        const staffOrdersContent =
            document.querySelector(
                '.staff-orders-content'
            );


        if (!staffOrdersContent) {
            return;
        }


        /* =====================================================
           AJAX TOAST
        ===================================================== */

        function showAjaxOrderToast(message) {

            const existing =
                document.getElementById(
                    'ajaxStaffOrderToast'
                );

            if (existing) {

                const existingWrap =
                    existing.closest(
                        '.orders-toast-wrap'
                    );

                if (existingWrap) {
                    existingWrap.remove();
                }
            }


            const wrap =
                document.createElement(
                    'div'
                );

            wrap.className =
                'orders-toast-wrap';

            wrap.setAttribute(
                'aria-live',
                'polite'
            );

            wrap.setAttribute(
                'aria-atomic',
                'true'
            );


            wrap.innerHTML = `
                <div
                    class="orders-toast orders-toast-success"
                    id="ajaxStaffOrderToast"
                    role="status"
                >

                    <span class="orders-toast-icon">
                        <i class="bi bi-check-circle"></i>
                    </span>

                    <span class="orders-toast-message">
                        ${escapeHtml(message)}
                    </span>

                    <button
                        type="button"
                        class="orders-toast-close"
                        aria-label="Close notification"
                    >
                        <i class="bi bi-x-lg"></i>
                    </button>

                    <span
                        class="orders-toast-progress"
                        aria-hidden="true"
                    ></span>

                </div>
            `;


            document.body.appendChild(
                wrap
            );


            const toast =
                wrap.querySelector(
                    '#ajaxStaffOrderToast'
                );


            const closeButton =
                wrap.querySelector(
                    '.orders-toast-close'
                );


            function closeToast() {

                if (!wrap) {
                    return;
                }

                wrap.classList.add(
                    'is-closing'
                );

                setTimeout(
                    function () {
                        wrap.remove();
                    },
                    190
                );
            }


            if (closeButton) {

                closeButton.addEventListener(
                    'click',
                    closeToast
                );

            }


            setTimeout(
                closeToast,
                3500
            );

        }


        /* =====================================================
           BASIC HTML ESCAPE FOR TOAST MESSAGE
        ===================================================== */

        function escapeHtml(value) {

            const div =
                document.createElement(
                    'div'
                );

            div.textContent =
                String(value ?? '');

            return div.innerHTML;
        }


        /* =====================================================
           UPDATE WORKFLOW CARD COUNTS + ACTIVE STATE
        ===================================================== */

        function syncWorkflowCards(
            parsedDocument,
            selectedStatus,
            hasSearch
      ) {

            document
                .querySelectorAll(
                    '.workflow-card[data-status]'
                )
                .forEach(
                    function (card) {

                        const status =
                            card.dataset.status;

                        const newCard =
                            parsedDocument.querySelector(
                                '.workflow-card.workflow-' +
                                status
                            );


                        if (newCard) {

                            const currentCount =
                                card.querySelector(
                                    '.workflow-count'
                                );

                            const newCount =
                                newCard.querySelector(
                                    '.workflow-count'
                                );


                            if (
                                currentCount &&
                                newCount
                            ) {

                                currentCount.textContent =
                                    newCount.textContent.trim();

                            }

                        }


                        const isActive =
                        !hasSearch &&
                        status === selectedStatus;  


                        card.classList.toggle(
                            'active',
                            isActive
                        );


                        card.setAttribute(
                            'aria-current',
                            isActive
                                ? 'page'
                                : 'false'
                        );

                    }
                );

        }


        /* =====================================================
           SYNC SEARCH FORM
        ===================================================== */

        function syncSearchForm(
            parsedDocument
        ) {

            const currentForm =
                document.querySelector(
                    '.order-search-form'
                );

            const newForm =
                parsedDocument.querySelector(
                    '.order-search-form'
                );


            if (
                !currentForm ||
                !newForm
            ) {
                return;
            }


            const currentSearch =
                currentForm.querySelector(
                    'input[name="q"]'
                );

            const newSearch =
                newForm.querySelector(
                    'input[name="q"]'
                );


            if (
                currentSearch &&
                newSearch
            ) {

                currentSearch.value =
                    newSearch.value;

            }


            const currentStatus =
                currentForm.querySelector(
                    'input[name="status"]'
                );

            const newStatus =
                newForm.querySelector(
                    'input[name="status"]'
                );


            if (
                currentStatus &&
                newStatus
            ) {

                currentStatus.value =
                    newStatus.value;

            }


            const currentActions =
                currentForm.querySelector(
                    '.search-actions'
                );

            const newActions =
                newForm.querySelector(
                    '.search-actions'
                );


            if (
                currentActions &&
                newActions
            ) {

                currentActions.innerHTML =
                    newActions.innerHTML;

            }

        }


        /* =====================================================
           LOAD ONLY ACTIVE ORDERS INTO THE PAGE
           
           No normal browser page reload happens.
           index.php is fetched in the background and the
           active-orders-section is extracted from the response.
        ===================================================== */

        async function loadStaffActiveOrders(
            targetUrl,
            pushHistory = true
        ) {

            const activeSection =
                document.querySelector(
                    '.active-orders-section'
                );


            if (!activeSection) {
                return;
            }


            const requestedUrl =
                new URL(
                    targetUrl,
                    window.location.href
                );


            const selectedStatus =
                requestedUrl.searchParams.get(
                    'status'
                ) ||
                'pending_verification';

                const searchValue =
    (
             requestedUrl.searchParams.get(
                    'q'
                ) || ''
            ).trim();

        const hasSearch =
            searchValue !== '';


            const previousHTML =
                activeSection.innerHTML;


            activeSection.setAttribute(
                'aria-busy',
                'true'
            );


            activeSection.style.opacity =
                '0.55';


            activeSection.style.pointerEvents =
                'none';


            try {

                const response =
                    await fetch(
                        requestedUrl.toString(),
                        {
                            method: 'GET',

                            headers: {
                                'X-Requested-With':
                                    'XMLHttpRequest',

                                'Accept':
                                    'text/html'
                            },

                            cache: 'no-store'
                        }
                    );


                if (!response.ok) {

                    throw new Error(
                        'Server returned HTTP ' +
                        response.status
                    );

                }


                const html =
                    await response.text();


                const parser =
                    new DOMParser();


                const parsedDocument =
                    parser.parseFromString(
                        html,
                        'text/html'
                    );


                const newActiveSection =
                    parsedDocument.querySelector(
                        '.active-orders-section'
                    );


                if (!newActiveSection) {

                    throw new Error(
                        'Active Orders section was not found.'
                    );

                }


                /* ---------------------------------------------
                   Replace ONLY the Active Orders section
                --------------------------------------------- */

                activeSection.replaceWith(
                    newActiveSection
                );


                /* ---------------------------------------------
                   Update workflow counts and active tab
                --------------------------------------------- */

                syncWorkflowCards(
                    parsedDocument,
                    selectedStatus,
                    hasSearch
               );

                /* ---------------------------------------------
                   Update search box / clear button
                --------------------------------------------- */

                syncSearchForm(
                    parsedDocument
                );


                /* ---------------------------------------------
                   Update browser URL
                --------------------------------------------- */

                if (pushHistory) {

                    const cleanUrl =
                        new URL(
                            requestedUrl.toString()
                        );


                    cleanUrl.searchParams.delete(
                        'ajax'
                    );


                    window.history.pushState(
                        {
                            staffOrdersAjax:
                                true
                        },
                        '',
                        cleanUrl.pathname +
                        (
                            cleanUrl.search
                                ? cleanUrl.search
                                : ''
                        ) +
                        cleanUrl.hash
                    );

                }


            } catch (error) {

                console.error(
                    'Staff Active Orders AJAX error:',
                    error
                );


                /*
                 * Restore the old section if the request
                 * fails.
                 */
                activeSection.innerHTML =
                    previousHTML;


                alert(
                    'Unable to load orders. Please try again.'
                );

            }


            const restoredSection =
                document.querySelector(
                    '.active-orders-section'
                );


            if (restoredSection) {

                restoredSection.removeAttribute(
                    'aria-busy'
                );


                restoredSection.style.opacity =
                    '';


                restoredSection.style.pointerEvents =
                    '';

            }

        }


        /* =====================================================
           WORKFLOW TABS
        ===================================================== */

        staffOrdersContent.addEventListener(
            'click',
            function (event) {

                const workflowLink =
                    event.target.closest(
                        '.workflow-card[data-status]'
                    );


                if (!workflowLink) {
                    return;
                }


                /*
                 * Keep normal browser behavior for
                 * Ctrl / Cmd / Shift / Alt clicks.
                 */
                if (
                    event.ctrlKey ||
                    event.metaKey ||
                    event.shiftKey ||
                    event.altKey ||
                    workflowLink.target === '_blank'
                ) {
                    return;
                }


                event.preventDefault();


                loadStaffActiveOrders(
                    workflowLink.href,
                    true
                );

            }
        );


        /* =====================================================
           ACTIVE ORDER PAGINATION
        ===================================================== */

        staffOrdersContent.addEventListener(
            'click',
            function (event) {

                const paginationLink =
                    event.target.closest(
                        '.active-orders-section .pagination a.page-link[href]'
                    );


                if (!paginationLink) {
                    return;
                }


                const pageItem =
                    paginationLink.closest(
                        '.page-item'
                    );


                if (
                    pageItem &&
                    pageItem.classList.contains(
                        'disabled'
                    )
                ) {
                    event.preventDefault();
                    return;
                }


                if (
                    event.ctrlKey ||
                    event.metaKey ||
                    event.shiftKey ||
                    event.altKey ||
                    paginationLink.target === '_blank'
                ) {
                    return;
                }


                event.preventDefault();


                loadStaffActiveOrders(
                    paginationLink.href,
                    true
                );

            }
        );


        /* =====================================================
           ACTIVE ORDER SEARCH
        ===================================================== */

        staffOrdersContent.addEventListener(
            'submit',
            function (event) {

                const form =
                    event.target.closest(
                        '.order-search-form'
                    );


                if (!form) {
                    return;
                }


                event.preventDefault();


                const formData =
                    new FormData(
                        form
                    );


                const currentUrl =
                    new URL(
                        window.location.href
                    );


                currentUrl.searchParams.set(
                    'status',
                    String(
                        formData.get('status') ||
                        'pending_verification'
                    )
                );


                const searchValue =
                    String(
                        formData.get('q') ||
                        ''
                    ).trim();


                if (searchValue !== '') {

                    currentUrl.searchParams.set(
                        'q',
                        searchValue
                    );

                } else {

                    currentUrl.searchParams.delete(
                        'q'
                    );

                }


                currentUrl.searchParams.set(
                    'page',
                    '1'
                );


                loadStaffActiveOrders(
                    currentUrl.toString(),
                    true
                );

            }
        );


        /* =====================================================
           CANCELLED ORDER FILTERS
           Handled by the dedicated AJAX script below.
        ===================================================== */


        /* =====================================================
           BROWSER BACK / FORWARD
        ===================================================== */

        window.addEventListener(
            'popstate',
            function () {

                loadStaffActiveOrders(
                    window.location.href,
                    false
                );

            }
        );


        /* =====================================================
           DYNAMIC "OTHER" CANCELLATION REASON
        ===================================================== */

        staffOrdersContent.addEventListener(
            'change',
            function (event) {

                const select =
                    event.target.closest(
                        'select[name="cancellation_reason"]'
                    );


                if (!select) {
                    return;
                }


                const modal =
                    select.closest(
                        '.modal'
                    );


                if (!modal) {
                    return;
                }


                const otherWrap =
                    modal.querySelector(
                        '[data-other-reason]'
                    );


                const textarea =
                    otherWrap
                        ? otherWrap.querySelector(
                            'textarea'
                        )
                        : null;


                if (select.value === 'Other') {

                    if (otherWrap) {
                        otherWrap.style.display =
                            'block';
                    }


                    if (textarea) {

                        textarea.required =
                            true;

                    }

                } else {

                    if (otherWrap) {
                        otherWrap.style.display =
                            'none';
                    }


                    if (textarea) {

                        textarea.required =
                            false;

                        textarea.value =
                            '';

                    }

                }

            }
        );


        /* =====================================================
           AJAX ORDER ACTIONS
           
           Capture phase is intentional.
           Your old direct form listeners are still in the
           existing file, so capture-phase prevents them from
           submitting a second request.
        ===================================================== */

        staffOrdersContent.addEventListener(
            'submit',
            function (event) {

                const form =
                    event.target.closest(
                        'form'
                    );


                if (!form) {
                    return;
                }


                const statusButton =
                    form.querySelector(
                        'button[name="update_status"]'
                    );


                const cancelInput =
                    form.querySelector(
                        'input[name="cancel_order"]'
                    );


                if (
                    !statusButton &&
                    !cancelInput
                ) {
                    return;
                }


                event.preventDefault();

                event.stopImmediatePropagation();


                if (statusButton) {

                    handleStatusUpdate(
                        form
                    );

                    return;
                }


                if (cancelInput) {

                    handleCancelOrder(
                        form
                    );

                }

            },
            true
        );


        /* =====================================================
   AJAX ORDER STATUS PROCESSING
   Shows a clear loading overlay on the order card
===================================================== */

async function handleStatusUpdate(
    form
) {

    const button =
        form.querySelector(
            'button[name="update_status"]'
        );


    if (!button || button.disabled) {
        return;
    }


    const originalHTML =
        button.innerHTML;


    /* -----------------------------------------------------
       FIND ORDER CARD
    ----------------------------------------------------- */

    const orderCard =
        form.closest(
            '.order-card'
        );


    /* -----------------------------------------------------
       CREATE LOADING OVERLAY
    ----------------------------------------------------- */

    let processingOverlay =
        null;


    if (orderCard) {

        orderCard.classList.add(
            'processing-order'
        );


        processingOverlay =
            document.createElement(
                'div'
            );


        processingOverlay.className =
            'order-processing-overlay';


        processingOverlay.innerHTML = `
            <div class="order-processing-box">

                <div
                    class="order-processing-spinner"
                    aria-hidden="true"
                ></div>

                <div class="order-processing-title">
                    Processing Order
                </div>

                <div class="order-processing-text">
                    Please wait...
                </div>

            </div>
        `;


        orderCard.appendChild(
            processingOverlay
        );

    }


    /* -----------------------------------------------------
       DISABLE BUTTON
    ----------------------------------------------------- */

    button.disabled =
        true;


    button.innerHTML = `
        <span
            class="spinner-border spinner-border-sm me-1"
            aria-hidden="true"
        ></span>
        Processing...
    `;


    /* -----------------------------------------------------
       PREPARE FORM DATA
    ----------------------------------------------------- */

    const formData =
        new FormData(
            form
        );


    formData.set(
        'update_status',
        '1'
    );


    formData.set(
        'ajax',
        '1'
    );


    try {

        const response =
            await fetch(
                form.action ||
                window.location.href,
                {
                    method: 'POST',

                    body: formData,

                    headers: {
                        'X-Requested-With':
                            'XMLHttpRequest',

                        'Accept':
                            'application/json'
                    }
                }
            );


        if (!response.ok) {

            throw new Error(
                'Server returned HTTP ' +
                response.status
            );

        }


        const data =
            await response.json();


        if (!data.success) {

            throw new Error(
                data.message ||
                'The order status could not be updated.'
            );

        }


        /* -------------------------------------------------
           SUCCESS
           Refresh only Active Orders
        ------------------------------------------------- */

        await loadStaffActiveOrders(
            window.location.href,
            false
        );


        showAjaxOrderToast(
            data.message ||
            'Order status updated successfully.'
        );


    } catch (error) {

        console.error(
            'AJAX order status update failed:',
            error
        );


        /* ---------------------------------------------
           REMOVE LOADING OVERLAY
        --------------------------------------------- */

        if (processingOverlay) {

            processingOverlay.remove();

        }


        if (orderCard) {

            orderCard.classList.remove(
                'processing-order'
            );

        }


        /* ---------------------------------------------
           RESTORE BUTTON
        --------------------------------------------- */

        button.disabled =
            false;


        button.innerHTML =
            originalHTML;


        alert(
            error.message ||
            'Unable to update the order.'
        );

    }

}


        /* =====================================================
           CANCEL ORDER
        ===================================================== */

        async function handleCancelOrder(
            form
        ) {

            const select =
                form.querySelector(
                    'select[name="cancellation_reason"]'
                );


            const otherWrap =
                form.querySelector(
                    '[data-other-reason]'
                );


            const textarea =
                otherWrap
                    ? otherWrap.querySelector(
                        'textarea'
                    )
                    : null;


            /*
             * "Other" needs to be converted into an actual
             * option value before FormData is created.
             */
            if (
                select &&
                select.value === 'Other'
            ) {

                const reason =
                    textarea
                        ? textarea.value.trim()
                        : '';


                if (reason === '') {

                    if (textarea) {
                        textarea.focus();
                    }

                    return;

                }


                let customOption =
                    Array.from(
                        select.options
                    ).find(
                        function (option) {
                            return option.value === reason;
                        }
                    );


                if (!customOption) {

                    customOption =
                        document.createElement(
                            'option'
                        );


                    customOption.value =
                        reason;


                    customOption.textContent =
                        reason;


                    customOption.selected =
                        true;


                    select.appendChild(
                        customOption
                    );

                }


                select.value =
                    reason;

            }


            const button =
                form.querySelector(
                    'button[type="submit"]'
                );


            if (!button || button.disabled) {
                return;
            }


            const originalHTML =
                button.innerHTML;


            button.disabled =
                true;


            button.innerHTML = `
                <span
                    class="spinner-border spinner-border-sm me-1"
                    aria-hidden="true"
                ></span>
                Cancelling...
            `;


            const formData =
                new FormData(
                    form
                );


            formData.set(
                'cancel_order',
                '1'
            );


            formData.set(
                'ajax',
                '1'
            );


            try {

                const response =
                    await fetch(
                        form.action ||
                        window.location.href,
                        {
                            method: 'POST',

                            body: formData,

                            headers: {
                                'X-Requested-With':
                                    'XMLHttpRequest',

                                'Accept':
                                    'application/json'
                            }
                        }
                    );


                if (!response.ok) {

                    throw new Error(
                        'Server returned HTTP ' +
                        response.status
                    );

                }


                const data =
                    await response.json();


                if (!data.success) {

                    throw new Error(
                        data.message ||
                        'The order could not be cancelled.'
                    );

                }


                /*
                 * Close the Bootstrap modal first.
                 */
                const modal =
                    form.closest(
                        '.modal'
                    );


                if (modal) {

                    const modalInstance =
                        bootstrap.Modal.getInstance(
                            modal
                        ) ||
                        bootstrap.Modal.getOrCreateInstance(
                            modal
                        );


                    modalInstance.hide();

                }


                /*
                 * Reload ONLY Active Orders.
                 */
                await loadStaffActiveOrders(
                    window.location.href,
                    false
                );


                showAjaxOrderToast(
                    data.message ||
                    'Order cancelled successfully.'
                );


            } catch (error) {

                console.error(
                    'AJAX order cancellation failed:',
                    error
                );


                alert(
                    error.message ||
                    'Unable to cancel the order.'
                );


                button.disabled =
                    false;


                button.innerHTML =
                    originalHTML;

            }

        }

    }
);

</script>

<!-- =========================================================
     STAFF CANCELLED ORDERS AJAX
     Date period, search, pagination, and Back/Forward update
     only the Cancelled Orders section.
========================================================= -->
<script>
document.addEventListener('DOMContentLoaded', function () {

    const staffOrdersContent =
        document.querySelector('.staff-orders-content');

    if (!staffOrdersContent) {
        return;
    }

    let cancelledSearchTimer = null;
    let cancelledRequestId = 0;

    async function loadCancelledOrders(targetUrl, pushHistory = true) {

        const cancelledSection =
            document.querySelector('.cancelled-orders-section');

        if (!cancelledSection) {
            return;
        }

        const requestedUrl = new URL(
            targetUrl,
            window.location.href
        );

        requestedUrl.searchParams.set(
            'cancelled_open',
            '1'
        );

        const requestNumber = ++cancelledRequestId;

        cancelledSection.setAttribute('aria-busy', 'true');
        cancelledSection.style.opacity = '0.55';
        cancelledSection.style.pointerEvents = 'none';

        try {
            const response = await fetch(
                requestedUrl.toString(),
                {
                    method: 'GET',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'text/html'
                    },
                    cache: 'no-store'
                }
            );

            if (!response.ok) {
                throw new Error(
                    'Server returned HTTP ' + response.status
                );
            }

            const html = await response.text();

            if (requestNumber !== cancelledRequestId) {
                return;
            }

            const parsedDocument =
                new DOMParser().parseFromString(
                    html,
                    'text/html'
                );

            const newCancelledSection =
                parsedDocument.querySelector(
                    '.cancelled-orders-section'
                );

            if (!newCancelledSection) {
                throw new Error(
                    'Cancelled Orders section was not found.'
                );
            }

            cancelledSection.replaceWith(
                newCancelledSection
            );

            if (pushHistory) {
                window.history.pushState(
                    { staffOrdersCancelledAjax: true },
                    '',
                    requestedUrl.pathname +
                    (requestedUrl.search || '') +
                    requestedUrl.hash
                );
            }

        } catch (error) {
            console.error(
                'Staff Cancelled Orders AJAX error:',
                error
            );
            alert(
                error.message ||
                'Unable to load cancelled orders.'
            );
        } finally {
            const restoredSection =
                document.querySelector(
                    '.cancelled-orders-section'
                );

            if (restoredSection) {
                restoredSection.removeAttribute('aria-busy');
                restoredSection.style.opacity = '';
                restoredSection.style.pointerEvents = '';
            }
        }
    }

    function buildCancelledUrl(overrides = {}) {

        const url = new URL(
            window.location.href
        );

        url.searchParams.set(
            'cancelled_open',
            '1'
        );

        const period =
            overrides.cancelled_period ??
            url.searchParams.get('cancelled_period') ??
            'today';

        const month =
            overrides.cancelled_month ??
            url.searchParams.get('cancelled_month') ??
            '';

        const search =
            overrides.cancelled_q ??
            url.searchParams.get('cancelled_q') ??
            '';

        const page =
            overrides.cancelled_page ??
            url.searchParams.get('cancelled_page') ??
            '1';

        url.searchParams.set(
            'cancelled_period',
            period || 'today'
        );

        if (period === 'specific_month') {
            if (month) {
                url.searchParams.set(
                    'cancelled_month',
                    month
                );
            }
        } else {
            url.searchParams.delete(
                'cancelled_month'
            );
        }

        if (String(search).trim() !== '') {
            url.searchParams.set(
                'cancelled_q',
                String(search).trim()
            );
        } else {
            url.searchParams.delete(
                'cancelled_q'
            );
        }

        url.searchParams.set(
            'cancelled_page',
            String(page || '1')
        );

        return url;
    }

    /* Period and specific-month changes. */
    staffOrdersContent.addEventListener(
        'change',
        function (event) {

            const periodSelect =
                event.target.closest(
                    '[data-cancelled-period]'
                );

            if (periodSelect) {

                const period =
                    periodSelect.value || 'today';

                const monthInput =
                    document.querySelector(
                        '[data-cancelled-month]'
                    );

                const monthWrap =
                    document.querySelector(
                        '[data-cancelled-month-wrap]'
                    );

                if (period === 'specific_month') {

                    if (monthWrap) {
                        monthWrap.style.display = '';
                    }

                    if (!monthInput || !monthInput.value) {
                        if (monthInput) {
                            monthInput.focus();
                        }
                        return;
                    }

                } else {

                    if (monthWrap) {
                        monthWrap.style.display = 'none';
                    }
                }

                loadCancelledOrders(
                    buildCancelledUrl({
                        cancelled_period: period,
                        cancelled_month:
                            monthInput ? monthInput.value : '',
                        cancelled_page: 1
                    }),
                    true
                );

                return;
            }

            const monthInput =
                event.target.closest(
                    '[data-cancelled-month]'
                );

            if (monthInput) {
                const periodSelect =
                    document.querySelector(
                        '[data-cancelled-period]'
                    );

                if (
                    periodSelect &&
                    periodSelect.value === 'specific_month' &&
                    monthInput.value
                ) {
                    loadCancelledOrders(
                        buildCancelledUrl({
                            cancelled_period: 'specific_month',
                            cancelled_month: monthInput.value,
                            cancelled_page: 1
                        }),
                        true
                    );
                }
            }
        }
    );

    /* Prevent full-page GET refresh from the Cancelled Orders form. */
    staffOrdersContent.addEventListener(
        'submit',
        function (event) {

            const form =
                event.target.closest(
                    '[data-cancelled-filter-form]'
                );

            if (!form) {
                return;
            }

            event.preventDefault();

            const period =
                form.querySelector(
                    '[data-cancelled-period]'
                );

            const month =
                form.querySelector(
                    '[data-cancelled-month]'
                );

            const search =
                form.querySelector(
                    '[data-cancelled-search]'
                );

            loadCancelledOrders(
                buildCancelledUrl({
                    cancelled_period:
                        period ? period.value : 'today',
                    cancelled_month:
                        month ? month.value : '',
                    cancelled_q:
                        search ? search.value : '',
                    cancelled_page: 1
                }),
                true
            );
        }
    );

    /* Live search: only the Cancelled Orders section is refreshed. */
    staffOrdersContent.addEventListener(
        'input',
        function (event) {

            const searchInput =
                event.target.closest(
                    '[data-cancelled-search]'
                );

            if (!searchInput) {
                return;
            }

            clearTimeout(cancelledSearchTimer);

            cancelledSearchTimer = setTimeout(
                function () {

                    const period =
                        document.querySelector(
                            '[data-cancelled-period]'
                        );

                    const month =
                        document.querySelector(
                            '[data-cancelled-month]'
                        );

                    const targetUrl =
                        buildCancelledUrl({
                            cancelled_period:
                                period ? period.value : 'today',
                            cancelled_month:
                                month ? month.value : '',
                            cancelled_q:
                                searchInput.value,
                            cancelled_page: 1
                        });

                    /* Replace the URL without creating a history entry per keystroke. */
                    window.history.replaceState(
                        { staffOrdersCancelledAjax: true },
                        '',
                        targetUrl.pathname +
                        (targetUrl.search || '') +
                        targetUrl.hash
                    );

                    loadCancelledOrders(
                        targetUrl,
                        false
                    );
                },
                300
            );
        }
    );

    /* Clear only Cancelled Orders filters. */
    staffOrdersContent.addEventListener(
        'click',
        function (event) {

            const clearLink =
                event.target.closest(
                    '.cancelled-clear-link'
                );

            if (clearLink) {
                if (
                    event.ctrlKey ||
                    event.metaKey ||
                    event.shiftKey ||
                    event.altKey ||
                    clearLink.target === '_blank'
                ) {
                    return;
                }

                event.preventDefault();

                const targetUrl = new URL(
                    clearLink.href,
                    window.location.href
                );

                targetUrl.searchParams.set(
                    'cancelled_period',
                    'today'
                );
                targetUrl.searchParams.delete(
                    'cancelled_month'
                );
                targetUrl.searchParams.delete(
                    'cancelled_q'
                );
                targetUrl.searchParams.set(
                    'cancelled_page',
                    '1'
                );
                targetUrl.searchParams.set(
                    'cancelled_open',
                    '1'
                );

                loadCancelledOrders(
                    targetUrl,
                    true
                );

                return;
            }

            const paginationLink =
                event.target.closest(
                    '.cancelled-pagination a[href]'
                );

            if (!paginationLink) {
                return;
            }

            if (
                event.ctrlKey ||
                event.metaKey ||
                event.shiftKey ||
                event.altKey ||
                paginationLink.target === '_blank'
            ) {
                return;
            }

            event.preventDefault();

            loadCancelledOrders(
                paginationLink.href,
                true
            );
        }
    );

    /* Back/Forward updates only the Cancelled Orders section here. */
    window.addEventListener(
        'popstate',
        function () {
            loadCancelledOrders(
                window.location.href,
                false
            );
        }
    );

    const initialPeriod =
        document.querySelector(
            '[data-cancelled-period]'
        );

    const initialMonthWrap =
        document.querySelector(
            '[data-cancelled-month-wrap]'
        );

    if (initialPeriod && initialMonthWrap) {
        initialMonthWrap.style.display =
            initialPeriod.value === 'specific_month'
                ? ''
                : 'none';
    }
});
</script>

<?php
require_once '../includes/footer.php';
?>