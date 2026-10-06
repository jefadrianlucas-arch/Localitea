<?php
session_start();
date_default_timezone_set('Asia/Manila');

require_once '../includes/db.php';

/* =========================================================
   STAFF ACCESS ONLY
   Staff is allowed to use Walk-in Order.
========================================================= */
if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['user_role'] ?? '') !== 'staff'
) {
    header('Location: ../auth/login.php');
    exit;
}

$staff_id = (int)($_SESSION['user_id'] ?? 0);

if (!isset($_SESSION['walkin_cart']) || !is_array($_SESSION['walkin_cart'])) {
    $_SESSION['walkin_cart'] = [];
}

$categories = [
    'Classic Milktea',
    'Premium Milktea',
    'Cold Brew and Premium Iced Coffee',
    'Fruit Tea',
    'Frappe',
    'Sip and Snack',
    'Promo and Bundles'
];

$category = trim((string)($_GET['category'] ?? 'Classic Milktea'));
if (!in_array($category, $categories, true)) {
    $category = 'Classic Milktea';
}

$isAjaxCategory = ($_GET['ajax'] ?? '') === '1';

/* =========================================================
   HELPERS
========================================================= */
function walkinRedirect(string $location): void
{
    header('Location: ' . $location);
    exit;
}

function walkinIsAjax(): bool
{
    return strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

function walkinBackUrl(string $category, string $query = ''): string
{
    /* Fast cart updates (fetch) get a light response without the staff layout. */
    if (walkinIsAjax()) {
        $query .= ($query !== '' ? '&' : '') . 'partial=1';
    }
    return 'walk-in-order.php?category=' . urlencode($category) . ($query !== '' ? '&' . $query : '');
}

function walkinAddonData(PDO $pdo, int $productId): array
{
    $stmt = $pdo->prepare("
        SELECT a.id, a.name, a.price
        FROM product_addons pa
        INNER JOIN addons a ON a.id = pa.addon_id
        WHERE pa.product_id = ?
          AND a.is_available = 1
          AND a.is_archived = 0
        ORDER BY pa.sort_order ASC, a.name ASC
    ");
    $stmt->execute([$productId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* Single source of truth for PWD / Senior rates (used by cart display AND order placement). */
function walkinDiscountRates(PDO $pdo): array
{
    $rates = ['pwd' => 20.00, 'senior' => 20.00];
    try {
        $stmt = $pdo->prepare("
            SELECT setting_key, setting_value
            FROM settings
            WHERE setting_key IN ('pwd_discount_rate', 'senior_discount_rate')
        ");
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $setting) {
            $value = (float)$setting['setting_value'];
            if ($value < 0 || $value > 100) {
                continue;
            }
            if ($setting['setting_key'] === 'pwd_discount_rate') {
                $rates['pwd'] = $value;
            } elseif ($setting['setting_key'] === 'senior_discount_rate') {
                $rates['senior'] = $value;
            }
        }
    } catch (Throwable $e) {
        /* keep defaults */
    }
    return $rates;
}

function walkinFmtRate(float $rate): string
{
    return rtrim(rtrim(number_format($rate, 2), '0'), '.') . '%';
}

function walkinRenderProducts(array $products, array $productAddons, string $category): void
{
    ?>
    <div class="walkin-product-content">
        <div class="walkin-section-heading">
            <div>
                <div class="walkin-eyebrow">Menu</div>
                <h2><?= htmlspecialchars($category) ?></h2>
            </div>
            <span class="walkin-item-count"><?= count($products) ?> item<?= count($products) === 1 ? '' : 's' ?></span>
        </div>

        <?php if (empty($products)): ?>
            <div class="walkin-empty-state">
                <i class="bi bi-cup-straw"></i>
                <h5>No products available</h5>
                <p>This category currently has no available products.</p>
            </div>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($products as $product): ?>
                    <?php
                    $available = (int)$product['is_available'] === 1;
                    $addonsForProduct = $productAddons[(int)$product['id']] ?? [];
                    ?>
                    <div class="col-6 col-md-4 col-xl-3 col-xxl-2">
                        <div class="walkin-product-card <?= $available ? '' : 'is-unavailable' ?>">
                            <div class="walkin-product-image-wrap">
                                <img
                                    src="../assets/uploads/products/<?= htmlspecialchars($product['image'] ?: 'default.jpg') ?>"
                                    alt="<?= htmlspecialchars($product['name']) ?>"
                                    loading="lazy"
                                >
                                <?php if (!$available): ?>
                                    <span class="walkin-out-badge"><i class="bi bi-x-circle-fill"></i> Out of Stock</span>
                                <?php endif; ?>
                            </div>

                            <div class="walkin-product-body">
                                <div class="walkin-product-name"><?= htmlspecialchars($product['name']) ?></div>
                                <div class="walkin-product-price">₱<?= number_format((float)$product['price'], 2) ?></div>

                                <?php if ($available): ?>
                                    <button
                                        type="button"
                                        class="btn walkin-add-btn"
                                        data-product-id="<?= (int)$product['id'] ?>"
                                        data-product-name="<?= htmlspecialchars($product['name'], ENT_QUOTES) ?>"
                                        data-price="<?= htmlspecialchars((string)(float)$product['price'], ENT_QUOTES) ?>"
                                        data-regular-price="<?= htmlspecialchars((string)(float)($product['regular_price'] ?? 0), ENT_QUOTES) ?>"
                                        data-grande-price="<?= htmlspecialchars((string)(float)($product['grande_price'] ?? 0), ENT_QUOTES) ?>"
                                        data-addons='<?= htmlspecialchars(json_encode($addonsForProduct, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]', ENT_QUOTES) ?>'
                                    >
                                        <i class="bi bi-plus-circle me-1"></i> Add
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="btn walkin-add-btn" disabled>Unavailable</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

$discountRates = walkinDiscountRates($pdo);

/* =========================================================
   HANDLE WALK-IN CART ACTIONS
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    $returnCategory = trim((string)($_POST['category'] ?? $category));

    if (!in_array($returnCategory, $categories, true)) {
        $returnCategory = 'Classic Milktea';
    }

    /* ---------------- ADD ITEM ---------------- */
    if ($action === 'add_item') {
      try {
        $productId = (int)($_POST['product_id'] ?? 0);
        $size = trim((string)($_POST['size'] ?? ''));
        $sugarLevel = trim((string)($_POST['sugar_level'] ?? ''));
        $discountType = strtolower(trim((string)($_POST['discount_type'] ?? 'none')));
        $quantity = max(1, min(50, (int)($_POST['quantity'] ?? 1)));
        $selectedAddons = $_POST['addons'] ?? [];

        if (!in_array($discountType, ['none', 'pwd', 'senior'], true)) {
            $discountType = 'none';
        }

        /* PWD / Senior: the cashier must record the name and ID number shown on the customer's ID. */
        $discountIdName = '';
        $discountIdNumber = '';
        if ($discountType !== 'none') {
            $discountIdName = trim(preg_replace('/\s+/', ' ', (string)($_POST['discount_id_name'] ?? '')));
            $discountIdNumber = strtoupper(trim((string)($_POST['discount_id_number'] ?? '')));

            if ($discountIdName === '' || mb_strlen($discountIdName) > 100) {
                walkinRedirect(walkinBackUrl($returnCategory, 'error=' . urlencode('Please enter the name on the ' . strtoupper($discountType) . ' ID.')));
            }
            if (!preg_match('/^[A-Z0-9][A-Z0-9\-\/ ]{2,29}$/', $discountIdNumber)) {
                walkinRedirect(walkinBackUrl($returnCategory, 'error=' . urlencode('Please enter a valid ' . strtoupper($discountType) . ' ID number (letters, numbers, dashes only).')));
            }
        }

        if (!is_array($selectedAddons)) {
            $selectedAddons = [$selectedAddons];
        }

        $selectedAddons = array_values(array_unique(array_filter(
            array_map('trim', $selectedAddons),
            static fn($value) => $value !== ''
        )));

        /* Only one discount type per order: block mixing PWD and Senior right away. */
        if ($discountType !== 'none') {
            foreach ($_SESSION['walkin_cart'] as $existing) {
                $existingType = (string)($existing['discount_type'] ?? 'none');
                if ($existingType !== 'none' && $existingType !== $discountType) {
                    walkinRedirect(walkinBackUrl($returnCategory, 'error=' . urlencode('This order already uses a ' . strtoupper($existingType) . ' discount. Only one discount type is allowed per order.')));
                }
                /* One discount = one cardholder per order. */
                $existingId = strtoupper((string)($existing['discount_id_number'] ?? ''));
                if ($existingType === $discountType && $existingId !== '' && $existingId !== $discountIdNumber) {
                    walkinRedirect(walkinBackUrl($returnCategory, 'error=' . urlencode('This order already uses ID ' . $existingId . ' for the discount. Use the same cardholder for the whole order.')));
                }
            }
        }

        $productStmt = $pdo->prepare("
            SELECT p.id, p.name, p.image, p.price, p.regular_price, p.grande_price,
                   p.is_available, p.is_archived, c.name AS category_name
            FROM products p
            INNER JOIN categories c ON c.id = p.category_id
            WHERE p.id = ?
            LIMIT 1
        ");
        $productStmt->execute([$productId]);
        $product = $productStmt->fetch(PDO::FETCH_ASSOC);

        if (!$product || (int)$product['is_archived'] === 1 || (int)$product['is_available'] !== 1) {
            walkinRedirect(walkinBackUrl($returnCategory, 'error=' . urlencode('This product is currently unavailable.')));
        }

        $basePrice = (float)$product['price'];
        $hasRegular = (float)($product['regular_price'] ?? 0) > 0;
        $hasGrande = (float)($product['grande_price'] ?? 0) > 0;

        if ($size === 'Regular') {
            if (!$hasRegular) {
                walkinRedirect(walkinBackUrl($returnCategory, 'error=' . urlencode('Regular size is not available for this product.')));
            }
            $basePrice = (float)$product['regular_price'];
        } elseif ($size === 'Grande') {
            if (!$hasGrande) {
                walkinRedirect(walkinBackUrl($returnCategory, 'error=' . urlencode('Grande size is not available for this product.')));
            }
            $basePrice = (float)$product['grande_price'];
        } elseif ($size !== '') {
            walkinRedirect(walkinBackUrl($returnCategory, 'error=' . urlencode('Invalid size selected.')));
        }

        $allowedAddons = walkinAddonData($pdo, $productId);
        $addonPrices = [];
        foreach ($allowedAddons as $addon) {
            $addonPrices[(string)$addon['name']] = (float)$addon['price'];
        }

        $validAddons = [];
        $addonsTotal = 0.00;
        foreach ($selectedAddons as $addonName) {
            if (isset($addonPrices[$addonName])) {
                $validAddons[] = $addonName;
                $addonsTotal += $addonPrices[$addonName];
            }
        }
        sort($validAddons, SORT_NATURAL | SORT_FLAG_CASE);
        $validAddons = array_values(array_unique($validAddons));
        $addonsTotal = round($addonsTotal, 2);

        $allowedSugar = ['', '0%', '25%', '50%', '75%', '100%'];
        if (!in_array($sugarLevel, $allowedSugar, true)) {
            $sugarLevel = '';
        }

        $unitPrice = round($basePrice + $addonsTotal, 2);

        $cartKey = md5(
            $productId . '|' . $size . '|' . $sugarLevel . '|' . $discountType . '|' . implode(',', $validAddons)
        );

        if (isset($_SESSION['walkin_cart'][$cartKey])) {
            $_SESSION['walkin_cart'][$cartKey]['quantity'] = min(50, $_SESSION['walkin_cart'][$cartKey]['quantity'] + $quantity);
        } else {
            $_SESSION['walkin_cart'][$cartKey] = [
                'product_id' => $productId,
                'name' => (string)$product['name'],
                'image' => (string)$product['image'],
                'size' => $size,
                'addons' => $validAddons,
                'sugar_level' => $sugarLevel,
                'discount_type' => $discountType,
                'discount_id_name' => $discountIdName,
                'discount_id_number' => $discountIdNumber,
                'price' => $unitPrice,
                'quantity' => $quantity,
            ];
        }

        /* cart=1 -> the order drawer opens automatically so the cashier sees the item. */
        walkinRedirect(walkinBackUrl($returnCategory, 'added=1&cart=1'));
      } catch (Throwable $e) {
        error_log('Walk-in add item error: ' . $e->getMessage());
        walkinRedirect(walkinBackUrl($returnCategory, 'error=' . urlencode('Unable to add the item: ' . $e->getMessage())));
      }
    }

    /* ---------------- REMOVE ITEM ---------------- */
    if ($action === 'remove_item') {
        $cartKey = trim((string)($_POST['cart_key'] ?? ''));
        if ($cartKey !== '' && isset($_SESSION['walkin_cart'][$cartKey])) {
            unset($_SESSION['walkin_cart'][$cartKey]);
        }
        walkinRedirect(walkinBackUrl($returnCategory, 'updated=1&cart=1'));
    }

    /* ---------------- CHANGE QUANTITY ---------------- */
    if ($action === 'set_quantity') {
        $cartKey = trim((string)($_POST['cart_key'] ?? ''));
        $quantity = max(1, min(50, (int)($_POST['quantity'] ?? 1)));
        if ($cartKey !== '' && isset($_SESSION['walkin_cart'][$cartKey])) {
            $_SESSION['walkin_cart'][$cartKey]['quantity'] = $quantity;
        }
        walkinRedirect(walkinBackUrl($returnCategory, 'updated=1&cart=1'));
    }

    /* ---------------- CLEAR CART ---------------- */
    if ($action === 'clear_cart') {
        $_SESSION['walkin_cart'] = [];
        walkinRedirect(walkinBackUrl($returnCategory, 'cleared=1'));
    }

    /* ---------------- PLACE ORDER ---------------- */
    if ($action === 'place_order') {
        if (empty($_SESSION['walkin_cart'])) {
            walkinRedirect(walkinBackUrl($returnCategory, 'error=' . urlencode('Please add at least one product.')));
        }

        $customerName = trim((string)($_POST['customer_name'] ?? ''));
        $contactNumber = trim((string)($_POST['contact_number'] ?? ''));
        $paymentMethod = strtolower(trim((string)($_POST['payment_method'] ?? 'cash')));
        $notes = trim((string)($_POST['notes'] ?? ''));

        if ($customerName === '') {
            $customerName = 'Walk-in Customer';
        }

        $contactNumber = preg_replace('/[\s\-()]+/', '', $contactNumber);
        if ($contactNumber !== '' && str_starts_with($contactNumber, '+63')) {
            $contactNumber = '0' . substr($contactNumber, 3);
        } elseif ($contactNumber !== '' && str_starts_with($contactNumber, '63')) {
            $contactNumber = '0' . substr($contactNumber, 2);
        }
        if ($contactNumber === '') {
            $contactNumber = 'N/A';
        }

        if (!in_array($paymentMethod, ['cash', 'gcash'], true)) {
            $paymentMethod = 'cash';
        }

        if (mb_strlen($customerName) > 100) {
            $customerName = mb_substr($customerName, 0, 100);
        }
        if (mb_strlen($contactNumber) > 20) {
            $contactNumber = mb_substr($contactNumber, 0, 20);
        }
        if (mb_strlen($notes) > 1000) {
            $notes = mb_substr($notes, 0, 1000);
        }

        $subtotal = 0.00;
        $discountEligibleBase = 0.00;
        $selectedDiscountTypes = [];
        $discountIdName = '';
        $discountIdNumber = '';
        $lineData = [];

        $productStmt = $pdo->prepare("
            SELECT id, name, is_available, is_archived
            FROM products
            WHERE id = ?
            LIMIT 1
        ");

        foreach ($_SESSION['walkin_cart'] as $cartKey => $item) {
            $productId = (int)($item['product_id'] ?? 0);
            $quantity = max(1, (int)($item['quantity'] ?? 0));
            $unitPrice = max(0, (float)($item['price'] ?? 0));
            $lineSubtotal = round($unitPrice * $quantity, 2);
            $discountType = strtolower(trim((string)($item['discount_type'] ?? 'none')));

            if (!in_array($discountType, ['none', 'pwd', 'senior'], true)) {
                $discountType = 'none';
            }

            if ($productId <= 0) {
                continue;
            }

            $productStmt->execute([$productId]);
            $dbProduct = $productStmt->fetch(PDO::FETCH_ASSOC);

            if (!$dbProduct || (int)$dbProduct['is_archived'] === 1 || (int)$dbProduct['is_available'] !== 1) {
                walkinRedirect(walkinBackUrl($returnCategory, 'error=' . urlencode('One of the products in the walk-in cart is no longer available.')));
            }

            if ($discountType !== 'none') {
                $selectedDiscountTypes[$discountType] = true;
                $discountEligibleBase += $lineSubtotal;
                if ($discountIdNumber === '') {
                    $discountIdName = trim((string)($item['discount_id_name'] ?? ''));
                    $discountIdNumber = trim((string)($item['discount_id_number'] ?? ''));
                }
            }

            $subtotal += $lineSubtotal;
            $lineData[] = [
                'product_id' => $productId,
                'name' => (string)$dbProduct['name'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'subtotal' => $lineSubtotal,
                'size' => trim((string)($item['size'] ?? '')),
                'addons' => is_array($item['addons'] ?? null) ? array_values($item['addons']) : [],
                'sugar_level' => trim((string)($item['sugar_level'] ?? '')),
                'discount_type' => $discountType,
            ];
        }

        $subtotal = round($subtotal, 2);
        $discountEligibleBase = round($discountEligibleBase, 2);

        if ($subtotal <= 0 || empty($lineData)) {
            walkinRedirect(walkinBackUrl($returnCategory, 'error=' . urlencode('The walk-in cart contains no valid items.')));
        }

        if (isset($selectedDiscountTypes['pwd']) && isset($selectedDiscountTypes['senior'])) {
            walkinRedirect(walkinBackUrl($returnCategory, 'error=' . urlencode('Please use only one discount type per order: PWD or Senior Citizen.')));
        }

        $discountType = isset($selectedDiscountTypes['pwd'])
            ? 'pwd'
            : (isset($selectedDiscountTypes['senior']) ? 'senior' : 'none');

        if ($discountType !== 'none' && ($discountIdName === '' || $discountIdNumber === '')) {
            walkinRedirect(walkinBackUrl($returnCategory, 'error=' . urlencode('Discount ID details are missing. Please re-add the discounted item.')));
        }

        $discountRate = $discountType === 'none' ? 0.00 : (float)$discountRates[$discountType];
        $discountAmount = round($discountEligibleBase * ($discountRate / 100), 2);
        $totalAmount = round(max(0, $subtotal - $discountAmount), 2);

        try {
            $pdo->beginTransaction();

            $initialStatus = 'order_queue';
            $pickupDate = date('Y-m-d');
            $pickupTime = date('H:i:s');
            $orderNotes = 'Walk-in order';
            if ($discountType !== 'none') {
                $orderNotes .= ' | ' . strtoupper($discountType) . ' ID: ' . $discountIdName . ' / ' . $discountIdNumber;
            }
            if ($notes !== '') {
                $orderNotes .= ' | ' . $notes;
            }

            $stmtOrder = $pdo->prepare("
                INSERT INTO orders
                (
                    order_number, claim_number, customer_id, customer_name, contact_number,
                    pickup_date, pickup_time, payment_method, payment_screenshot,
                    subtotal, total_amount, discount_type, discount_rate, discount_amount,
                    status, notes, order_source
                )
                VALUES
                (NULL, NULL, NULL, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, 'walk_in')
            ");

            $stmtOrder->execute([
                $customerName,
                $contactNumber,
                $pickupDate,
                $pickupTime,
                $paymentMethod,
                $subtotal,
                $totalAmount,
                $discountType,
                $discountRate,
                $discountAmount,
                $initialStatus,
                $orderNotes,
            ]);

            $orderId = (int)$pdo->lastInsertId();
            if ($orderId <= 0) {
                throw new RuntimeException('Failed to create the walk-in order.');
            }

            /* Optional dedicated columns (see optional_discount_columns.sql). Skipped if they don't exist yet. */
            if ($discountType !== 'none') {
                try {
                    $colCheck = $pdo->query("SHOW COLUMNS FROM orders LIKE 'discount_id_number'");
                    if ($colCheck && $colCheck->fetch()) {
                        $pdo->prepare("UPDATE orders SET discount_id_name = ?, discount_id_number = ? WHERE id = ?")
                            ->execute([$discountIdName, $discountIdNumber, $orderId]);
                    }
                } catch (Throwable $e) {
                    error_log('Walk-in discount ID save skipped: ' . $e->getMessage());
                }
            }

            $orderNumber = 'ORD-' . date('Ymd') . '-' . str_pad((string)$orderId, 4, '0', STR_PAD_LEFT);
            $claimNumber = 'CLM-' . str_pad((string)$orderId, 4, '0', STR_PAD_LEFT);

            $pdo->prepare("UPDATE orders SET order_number = ?, claim_number = ? WHERE id = ?")
                ->execute([$orderNumber, $claimNumber, $orderId]);

            $stmtItem = $pdo->prepare("
                INSERT INTO order_items
                (
                    order_id, product_id, product_name, quantity, unit_price, subtotal,
                    size, addons, sugar_level, discount_type, discount_rate, discount_amount
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($lineData as $line) {
                $lineDiscountAmount = 0.00;
                if ($line['discount_type'] === $discountType && $discountType !== 'none') {
                    $lineDiscountAmount = round($line['subtotal'] * ($discountRate / 100), 2);
                }

                $addonsJson = json_encode($line['addons'], JSON_UNESCAPED_UNICODE);
                if ($addonsJson === false) {
                    $addonsJson = '[]';
                }

                $stmtItem->execute([
                    $orderId,
                    $line['product_id'],
                    $line['name'],
                    $line['quantity'],
                    $line['unit_price'],
                    $line['subtotal'],
                    $line['size'] !== '' ? $line['size'] : null,
                    $addonsJson,
                    $line['sugar_level'] !== '' ? $line['sugar_level'] : null,
                    $line['discount_type'],
                    $line['discount_type'] === $discountType ? $discountRate : 0.00,
                    $lineDiscountAmount,
                ]);
            }

            /*
             * The current database schema defines payments.verified_by
             * as a foreign key to admins.id, not users.id. A Staff ID
             * must therefore NOT be stored in this column.
             * Walk-in payments are received at the counter, so the
             * payment is recorded as verified while verified_by stays NULL.
             */
            $pdo->prepare("
                INSERT INTO payments
                (order_id, payment_method, amount, proof_image, is_verified, verified_at, verified_by)
                VALUES (?, ?, ?, NULL, 1, NOW(), NULL)
            ")->execute([$orderId, $paymentMethod, $totalAmount]);

            $pdo->prepare("
                INSERT INTO order_status_history
                (order_id, from_status, to_status, actor_role, actor_id, cancellation_reason)
                VALUES (?, NULL, ?, 'staff', ?, NULL)
            ")->execute([$orderId, $initialStatus, $staff_id]);

            /*
             * Commit the actual order first. Notifications are secondary;
             * a notification problem must never prevent a valid walk-in
             * order from reaching the Staff Order Queue.
             */
            $pdo->commit();

            $_SESSION['walkin_cart'] = [];
            $_SESSION['walkin_success'] = [
                'order_id' => $orderId,
                'order_number' => $orderNumber,
                'claim_number' => $claimNumber,
                'total' => $totalAmount,
                'payment_method' => strtoupper($paymentMethod),
            ];

            /* Send staff/admin notifications after the order is safely committed. */
            try {
                $notificationMessage = "New walk-in order {$orderNumber} / {$claimNumber} has been placed.";

                $stmtNotif = $pdo->prepare("
                    INSERT INTO notifications
                    (recipient_role, recipient_id, type, message, reference_id)
                    VALUES (?, NULL, 'new_order', ?, ?)
                ");
                $stmtNotif->execute(['staff', $notificationMessage, $orderId]);
                $stmtNotif->execute(['admin', $notificationMessage, $orderId]);
            } catch (Throwable $notificationError) {
                error_log(
                    'Walk-in notification error: '
                    . $notificationError->getMessage()
                );
            }

            /*
             * IMPORTANT: go directly to the Staff Order Queue after
             * confirming a walk-in order.
             */
            walkinRedirect(
                'index.php?status=order_queue&order_id='
                . $orderId
                . '&walkin_placed=1'
            );
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Walk-in order error: ' . $e->getMessage());
            walkinRedirect(walkinBackUrl($returnCategory, 'error=' . urlencode('Unable to place the walk-in order. Please check the database setup and try again.')));
        }
    }
}

/* =========================================================
   LOAD PRODUCTS
========================================================= */
$stmt = $pdo->prepare("
    SELECT p.*, c.name AS category_name
    FROM products p
    INNER JOIN categories c ON p.category_id = c.id
    WHERE c.name = ?
      AND c.is_active = 1
      AND p.is_archived = 0
    ORDER BY p.name ASC
");
$stmt->execute([$category]);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

$productAddons = [];
if (!empty($products)) {
    $productIds = array_values(array_map(static fn($product) => (int)$product['id'], $products));
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $addonStmt = $pdo->prepare("
        SELECT pa.product_id, a.id, a.name, a.price
        FROM product_addons pa
        INNER JOIN addons a ON a.id = pa.addon_id
        WHERE pa.product_id IN ($placeholders)
          AND a.is_available = 1
          AND a.is_archived = 0
        ORDER BY pa.product_id ASC, pa.sort_order ASC, a.name ASC
    ");
    $addonStmt->execute($productIds);
    foreach ($addonStmt->fetchAll(PDO::FETCH_ASSOC) as $addon) {
        $productAddons[(int)$addon['product_id']][] = [
            'id' => (int)$addon['id'],
            'name' => (string)$addon['name'],
            'price' => (float)$addon['price'],
        ];
    }
}

/* AJAX category request: return only the products column. */
if ($isAjaxCategory) {
    walkinRenderProducts($products, $productAddons, $category);
    exit;
}

/* =========================================================
   TOTALS FOR WALK-IN CART
========================================================= */
$walkinSubtotal = 0.00;
$walkinDiscountEligibleBase = 0.00;
$walkinDiscountTypes = [];
$walkinCartCount = 0;

foreach ($_SESSION['walkin_cart'] as $item) {
    $qty = max(1, (int)($item['quantity'] ?? 0));
    $lineSubtotal = round(max(0, (float)($item['price'] ?? 0)) * $qty, 2);
    $walkinSubtotal += $lineSubtotal;
    $walkinCartCount += $qty;
    $type = strtolower(trim((string)($item['discount_type'] ?? 'none')));
    if (in_array($type, ['pwd', 'senior'], true)) {
        $walkinDiscountTypes[$type] = true;
        $walkinDiscountEligibleBase += $lineSubtotal;
    }
}

$walkinSubtotal = round($walkinSubtotal, 2);
$walkinDiscountEligibleBase = round($walkinDiscountEligibleBase, 2);
$walkinDiscountRate = 0.00;
$walkinDiscountLabel = '';
if (isset($walkinDiscountTypes['pwd']) && !isset($walkinDiscountTypes['senior'])) {
    $walkinDiscountRate = (float)$discountRates['pwd'];
    $walkinDiscountLabel = 'PWD';
} elseif (isset($walkinDiscountTypes['senior']) && !isset($walkinDiscountTypes['pwd'])) {
    $walkinDiscountRate = (float)$discountRates['senior'];
    $walkinDiscountLabel = 'Senior';
}
$walkinDiscountAmount = round($walkinDiscountEligibleBase * ($walkinDiscountRate / 100), 2);
$walkinTotal = round(max(0, $walkinSubtotal - $walkinDiscountAmount), 2);

/* Discount cardholder already recorded in the cart (used to auto-fill the modal). */
$walkinActiveDiscount = ['type' => '', 'name' => '', 'id' => ''];
foreach ($_SESSION['walkin_cart'] as $cartItem) {
    if (($cartItem['discount_type'] ?? 'none') !== 'none' && !empty($cartItem['discount_id_number'])) {
        $walkinActiveDiscount = [
            'type' => (string)$cartItem['discount_type'],
            'name' => (string)($cartItem['discount_id_name'] ?? ''),
            'id'   => (string)$cartItem['discount_id_number'],
        ];
        break;
    }
}

$walkinSuccess = $_SESSION['walkin_success'] ?? null;
unset($_SESSION['walkin_success']);

$isPartial = (($_GET['partial'] ?? '') === '1');

if (!$isPartial) {
    require_once '../includes/header.php';
    require_once 'sidebar.php';
    require_once 'navbar.php';
}
?>

<style>
body { background: #F8F4EF; }

.walkin-page {
    min-height: calc(100vh - 70px);
    padding: 26px 24px 110px;
    width: calc(100% - 260px);
    margin-left: 260px;
}

.walkin-header { margin-bottom: 18px; }
.walkin-header h1 { color: #2C221E; font-size: 1.55rem; font-weight: 400; margin: 0; }
.walkin-header p { color: #7B6D62; margin: 5px 0 0; font-size: .86rem; }

.walkin-menu-panel {
    background: #FFFFFF;
    border: 1px solid #E2D7CE;
    border-radius: 18px;
    box-shadow: 0 6px 24px rgba(74, 53, 37, .06);
    overflow: hidden;
}

.walkin-categories {
    display: flex;
    flex-wrap: nowrap;
    gap: 8px;
    width: 100%;
    max-width: 100%;
    padding: 14px;
    border-bottom: 1px solid #E5DAD1;
    background: #FDF8F2;
    overflow-x: auto !important;
    overflow-y: hidden !important;
    scrollbar-width: auto;
    -webkit-overflow-scrolling: touch;
    touch-action: pan-x;
    overscroll-behavior-x: contain;
    white-space: nowrap;
    box-sizing: border-box;
    cursor: grab;
}
.walkin-categories:active { cursor: grabbing; }
.walkin-categories::-webkit-scrollbar { height: 5px; }
.walkin-categories::-webkit-scrollbar-track { background: #F2E9E1; }
.walkin-categories::-webkit-scrollbar-thumb { background: #B8A08A; border-radius: 10px; }
.walkin-categories::-webkit-scrollbar-thumb:hover { background: #6F4E37; }

.walkin-category-link {
    flex: 0 0 auto !important;
    display: inline-flex;
    align-items: center;
    min-width: max-content;
    padding: 9px 15px; border-radius: 50px;
    border: 1px solid #B8A08A; background: #FFFFFF; color: #4A3525;
    text-decoration: none; font-size: .82rem; font-weight: 600; white-space: nowrap;
}
.walkin-category-link:hover { background: #F0E6D6; color: #4A3525; }
.walkin-category-link.active { background: #4A3525; border-color: #4A3525; color: #FFFFFF; }

.walkin-product-content { padding: 20px; }
.walkin-section-heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 16px; }
.walkin-eyebrow { color: #9A7D62; font-size: .7rem; font-weight: 400; text-transform: uppercase; letter-spacing: .7px; }
.walkin-section-heading h2 { color: #2C221E; font-size: 1.15rem; font-weight: 400; margin: 2px 0 0; }
.walkin-item-count { padding: 6px 10px; border-radius: 50px; background: #F6EEE7; color: #6F4E37; font-size: .75rem; font-weight: 400; }

.walkin-product-card {
    height: 100%; border: 1px solid #E8DED6; border-radius: 14px; overflow: hidden;
    background: #FFFFFF; display: flex; flex-direction: column;
    transition: transform .18s ease, box-shadow .18s ease;
}
.walkin-product-card:hover { transform: translateY(-3px); box-shadow: 0 8px 20px rgba(74, 53, 37, .10); }
.walkin-product-card.is-unavailable:hover { transform: none; box-shadow: none; }

.walkin-product-image-wrap { position: relative; background: #FBF6F1; }
.walkin-product-image-wrap img { display: block; width: 100%; height: 130px; object-fit: contain; }
.walkin-product-card.is-unavailable img { opacity: .6; }

.walkin-out-badge {
    position: absolute; left: 8px; top: 8px; padding: 4px 8px; border-radius: 50px;
    background: #FBE7E7; border: 1px solid #C33131; color: #A12E2E; font-size: .62rem; font-weight: 400;
}

.walkin-product-body { padding: 11px; display: flex; flex-direction: column; flex: 1; }
.walkin-product-name { color: #2C221E; font-size: .86rem; font-weight: 400; line-height: 1.25; min-height: 34px; }
.walkin-product-price { color: #6F4E37; font-weight: 400; font-size: .92rem; margin: 6px 0 10px; }

.walkin-add-btn {
    width: 100%; margin-top: auto; min-height: 36px; border-radius: 10px;
    background: #6F4E37; color: #FFFFFF; border-color: #6F4E37; font-size: .78rem; font-weight: 400;
}
.walkin-add-btn:hover { background: #55301F; border-color: #55301F; color: #FFFFFF; }
.walkin-add-btn:disabled { background: #CFC3B9; border-color: #CFC3B9; color: #fff; }

.walkin-empty-state { padding: 56px 20px; text-align: center; color: #7B6D62; }
.walkin-empty-state i { font-size: 2.8rem; color: #B8A08A; }
.walkin-empty-state h5 { color: #4A3525; font-weight: 400; margin: 12px 0 5px; }
.walkin-empty-state p { margin: 0; font-size: .82rem; }

/* ---------- Floating "View Order" button ---------- */
.walkin-fab {
    position: fixed; right: 22px; bottom: 22px; z-index: 1300;
    display: flex; align-items: center; gap: 10px;
    background: #4A3525; color: #fff; border: 0; border-radius: 50px;
    padding: 13px 20px; font-weight: 400; font-size: .88rem;
    box-shadow: 0 10px 28px rgba(44, 34, 30, .35);
}
.walkin-fab:hover { background: #33231A; color: #fff; }
.walkin-fab-count {
    background: #fff; color: #4A3525; border-radius: 50px; min-width: 24px; height: 24px;
    display: inline-flex; align-items: center; justify-content: center; font-size: .75rem; padding: 0 7px;
}

/* ---------- Cart drawer ---------- */
.walkin-offcanvas { width: 410px !important; max-width: 100vw; z-index: 2000 !important; }
.offcanvas-backdrop, .modal-backdrop { z-index: 1990 !important; }
.walkin-modal { z-index: 2010 !important; }

.walkin-cart-head { padding: 16px 18px; border-bottom: 1px solid #E5DAD1; background: #FDF8F2; align-items: flex-start; }
.walkin-cart-head h3 { margin: 0; color: #2C221E; font-size: 1.02rem; font-weight: 400; }
.walkin-cart-head p { margin: 3px 0 0; color: #8C7B6E; font-size: .73rem; }

.walkin-cart-body { padding: 14px 18px; overflow-y: auto; flex: 1 1 auto; }
.walkin-cart-item { padding: 12px 0; border-bottom: 1px solid #EFE7E1; }.walkin-cart-item-image { flex: 0 0 58px; width: 58px; height: 58px; border-radius: 10px; overflow: hidden; background: #FBF6F1; border: 1px solid #E8DED6; }
.walkin-cart-item-image img { width: 100%; height: 100%; display: block; object-fit: cover; }

.walkin-cart-item:first-child { padding-top: 0; }
.walkin-cart-item:last-child { border-bottom: 0; }
.walkin-cart-item-name { color: #332824; font-size: .86rem; font-weight: 400; }
.walkin-cart-meta { color: #8A7A6C; font-size: .72rem; line-height: 1.45; margin-top: 3px; }
.walkin-cart-line { color: #4A3525; font-size: .84rem; font-weight: 400; white-space: nowrap; }
.walkin-cart-controls { display: flex; align-items: center; gap: 6px; margin-top: 8px; }
.walkin-qty-form, .walkin-remove-form { margin: 0; }
.walkin-qty-btn, .walkin-remove-btn {
    border: 1px solid #D5C6BA; background: #FFFFFF; color: #5D4B3E;
    border-radius: 8px; width: 30px; height: 30px; padding: 0; font-size: .85rem;
}
.walkin-qty-btn:disabled { opacity: .45; }
.walkin-remove-btn { border-color: #E2BBBB; color: #A43B3B; margin-left: auto; }
.walkin-qty-value { min-width: 26px; text-align: center; font-size: .8rem; font-weight: 400; }

.walkin-cart-empty { padding: 50px 16px; text-align: center; color: #8C7B6E; }
.walkin-cart-empty i { font-size: 2.2rem; color: #C4B1A2; }
.walkin-cart-empty p { margin: 8px 0 0; font-size: .8rem; }

.walkin-cart-footer { border-top: 1px solid #E5DAD1; padding: 14px 18px 18px; background: #FDF8F2; }
.walkin-total-row { display: flex; justify-content: space-between; gap: 14px; color: #6D5B4C; font-size: .8rem; margin-bottom: 6px; }
.walkin-total-row.discount { color: #5E6A3A; }
.walkin-total-row.grand { color: #2C221E; font-size: 1.05rem; font-weight: 400; border-top: 1px solid #E4D8CE; padding-top: 9px; margin-top: 8px; }

.walkin-place-btn, .walkin-clear-btn { width: 100%; min-height: 44px; border-radius: 10px; font-size: .84rem; font-weight: 400; }
.walkin-place-btn { margin-top: 12px; background: #4A3525; color: #FFFFFF; border-color: #4A3525; }
.walkin-place-btn:hover { background: #33231A; border-color: #33231A; color: #FFFFFF; }
.walkin-place-btn:disabled { opacity: .5; }
.walkin-clear-btn { margin-top: 8px; background: #FFFFFF; color: #A43B3B; border-color: #D9B4B4; }

/* ---------- Toast ---------- */
.walkin-toast { position: fixed; right: 22px; top: 88px; z-index: 2100; width: min(380px, calc(100vw - 36px)); }
.walkin-toast .toast { border: 1px solid #6F4E37 !important; border-left: 4px solid #4A3525 !important; border-radius: 11px !important; box-shadow: 0 8px 24px rgba(44, 34, 30, .16) !important; }
.walkin-toast .toast.is-error { border-left-color: #C33131 !important; }
/* While the order drawer (410px) is open, keep the notification beside it, never on top of it. */
body.walkin-drawer-open .walkin-toast { right: 432px; width: min(380px, calc(100vw - 470px)); }
@media (max-width: 767.98px) { body.walkin-drawer-open .walkin-toast { display: none; } }

/* ---------- Modals ---------- */
.walkin-modal .modal-content { border: 1px solid #DCCEC3; border-radius: 16px; overflow: hidden; }
.walkin-modal .modal-header { background: #FDF8F2; border-bottom: 1px solid #E5DAD1; }
.walkin-modal .modal-title { color: #2C221E; font-size: 1rem; font-weight: 400; }
.walkin-modal .modal-footer { background: #FDF8F2; border-top: 1px solid #E5DAD1; }

/* FIX: the <form> sits between .modal-content and .modal-body, which broke the
   scrollable layout and pushed the footer buttons out of view. */
.walkin-modal form { display: flex; flex-direction: column; min-height: 0; max-height: 100%; overflow: hidden; flex: 1 1 auto; }
.walkin-modal .modal-body { overflow-y: auto; }
.walkin-modal .modal-footer .walkin-place-btn { margin: 0; width: auto; padding: 0 22px; }

.walkin-modal .section-label { color: #6A5546; font-size: .76rem; font-weight: 400; margin-bottom: 7px; display: block; }
.walkin-modal .form-control { border-color: #D8C9BD; border-radius: 9px; font-size: .84rem; }

.walkin-radio-grid { display: grid; gap: 8px; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); }
.walkin-radio-card { position: relative; }
.walkin-radio-card input { position: absolute; opacity: 0; }
.walkin-radio-card label {
    display: block; padding: 10px 11px; border: 1px solid #D8C9BD; border-radius: 9px;
    background: #FFFFFF; color: #5B4A3E; font-size: .78rem; font-weight: 400; cursor: pointer; text-align: center;
}
.walkin-radio-card input:checked + label { border-color: #6F4E37; background: #F5EDE6; color: #4A3525; box-shadow: inset 0 0 0 1px #6F4E37; }
.walkin-radio-card input:focus-visible + label { outline: 2px solid #6F4E37; outline-offset: 2px; }

.walkin-addon-grid { display: grid; gap: 8px; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); }
.walkin-addon-grid .walkin-radio-card label { text-align: left; display: flex; justify-content: space-between; gap: 8px; }
.walkin-addon-grid .walkin-radio-card label span { color: #8A7A6C; font-weight: 600; }

.walkin-discount-note { color: #8A7A6C; font-size: .7rem; margin-top: 6px; }
.walkin-discount-details { margin-top: 10px; padding: 12px; border: 1px dashed #D8C9BD; border-radius: 10px; background: #FDF8F2; }
.walkin-discount-details[hidden] { display: none; }
.walkin-field-label { display: block; color: #6A5546; font-size: .74rem; margin-bottom: 4px; }
.walkin-discount-details .form-control.is-invalid { border-color: #B3402F; }

.walkin-qty-stepper { display: inline-flex; align-items: center; border: 1px solid #D8C9BD; border-radius: 10px; overflow: hidden; }
.walkin-qty-stepper button { border: 0; background: #F6EEE7; width: 40px; height: 40px; font-size: 1.1rem; color: #4A3525; }
.walkin-qty-stepper input { border: 0; width: 56px; text-align: center; font-weight: 400; height: 40px; -moz-appearance: textfield; }
.walkin-qty-stepper input::-webkit-outer-spin-button, .walkin-qty-stepper input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }

.walkin-modal-total { margin-right: auto; color: #2C221E; font-weight: 400; font-size: 1.02rem; }
.walkin-modal-total small { display: block; color: #8A7A6C; font-weight: 600; font-size: .68rem; }

.walkin-success-box { border: 1px solid #BFCB9A; background: #F4F8E9; border-radius: 13px; padding: 12px 14px; }
.walkin-success-title { color: #5E6A3A; font-size: .86rem; font-weight: 400; }
.walkin-success-meta { color: #66704B; font-size: .74rem; margin-top: 2px; }

.walkin-receipt-frame { width: 100%; height: 480px; border: 1px solid #E2D7CE; border-radius: 10px; background: #EFEAE4; }

@media (max-width: 991.98px) {
    /* Sidebar becomes off-canvas on tablet/mobile. */
    .walkin-page {
        width: 100%;
        margin-left: 0;
        padding: 18px 12px 110px;
    }
    .walkin-toast { right: 12px; top: 72px; width: min(340px, calc(100vw - 24px)); }
}
@media (max-width: 575.98px) {
    .walkin-header h1 { font-size: 1.3rem; }
    .walkin-product-content { padding: 14px 12px; }
    .walkin-product-image-wrap img { height: 105px; }
    .walkin-product-name { font-size: .78rem; min-height: 30px; }
    .walkin-product-price { font-size: .82rem; margin: 5px 0 8px; }
    .walkin-add-btn { min-height: 34px; font-size: .72rem; }
    .walkin-fab { left: 12px; right: 12px; bottom: 12px; justify-content: center; }
    .walkin-receipt-frame { height: 400px; }
}
</style>

<main class="staff-main walkin-page">
    <div class="walkin-header">
        <h1><i class="bi bi-cart-plus me-1"></i> Walk-in Order</h1>
        <p>Pick a drink, customize it, then review the order in the drawer. Orders go to the Order Queue first, then can be confirmed by Staff.</p>
    </div>

    <section class="walkin-menu-panel">
        <div class="walkin-categories" id="walkinCategories">
            <?php foreach ($categories as $cat): ?>
                <a
                    href="walk-in-order.php?category=<?= urlencode($cat) ?>"
                    class="walkin-category-link <?= $cat === $category ? 'active' : '' ?>"
                >
                    <?= htmlspecialchars($cat) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div id="walkinProductsColumn">
            <?php walkinRenderProducts($products, $productAddons, $category); ?>
        </div>
    </section>
</main>

<!-- FLOATING VIEW-ORDER BUTTON -->
<button type="button" class="walkin-fab" id="walkinFab" aria-controls="walkinCartOffcanvas">
    <i class="bi bi-receipt"></i>
    <span>View Order</span>
    <span class="walkin-fab-count"><?= (int)$walkinCartCount ?></span>
    <strong>₱<?= number_format($walkinTotal, 2) ?></strong>
</button>

<!-- CART DRAWER -->
<div class="offcanvas offcanvas-end walkin-offcanvas" tabindex="-1" id="walkinCartOffcanvas" aria-labelledby="walkinCartTitle">
    <div class="offcanvas-header walkin-cart-head">
        <div>
            <h3 id="walkinCartTitle"><i class="bi bi-receipt me-1"></i> Current Walk-in Order</h3>
            <p>Separate from the customer's online cart.</p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body p-0 d-flex flex-column">
        <div class="walkin-cart-body">
            <?php if (empty($_SESSION['walkin_cart'])): ?>
                <div class="walkin-cart-empty">
                    <i class="bi bi-cart3"></i>
                    <p>No items yet. Select a product from the menu to begin.</p>
                </div>
            <?php else: ?>
                <?php foreach ($_SESSION['walkin_cart'] as $cartKey => $item): ?>
                    <?php
                    $lineSubtotal = round((float)$item['price'] * (int)$item['quantity'], 2);
                    $metaParts = [];
                    if (!empty($item['size'])) { $metaParts[] = 'Size: ' . $item['size']; }
                    if (!empty($item['sugar_level'])) { $metaParts[] = 'Sugar: ' . $item['sugar_level']; }
                    if (!empty($item['addons']) && is_array($item['addons'])) { $metaParts[] = 'Add-ons: ' . implode(', ', $item['addons']); }
                    if (($item['discount_type'] ?? 'none') !== 'none') { $metaParts[] = strtoupper((string)$item['discount_type']) . ' discount'; if (!empty($item['discount_id_number'])) { $metaParts[] = 'ID: ' . $item['discount_id_name'] . ' (' . $item['discount_id_number'] . ')'; } }
                    ?>
                    <div class="walkin-cart-item">
                        <div class="d-flex align-items-start gap-2">
                            <div class="walkin-cart-item-image">
                                <img src="../assets/uploads/products/<?= htmlspecialchars($item['image'] ?: 'default.jpg') ?>" alt="<?= htmlspecialchars($item['name']) ?>">
                            </div>
                            <div class="flex-grow-1" style="min-width:0">
                                <div class="walkin-cart-item-name"><?= htmlspecialchars($item['name']) ?></div>
                                <?php if ($metaParts): ?>
                                    <div class="walkin-cart-meta"><?= htmlspecialchars(implode(' · ', $metaParts)) ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="walkin-cart-line">₱<?= number_format($lineSubtotal, 2) ?></div>
                        </div>

                        <div class="walkin-cart-controls">
                            <form method="POST" class="walkin-qty-form">
                                <input type="hidden" name="action" value="set_quantity">
                                <input type="hidden" name="cart_key" value="<?= htmlspecialchars((string)$cartKey) ?>">
                                <input type="hidden" name="category" value="<?= htmlspecialchars($category) ?>">
                                <input type="hidden" name="quantity" value="<?= max(1, (int)$item['quantity'] - 1) ?>">
                                <button type="submit" class="walkin-qty-btn" aria-label="Decrease quantity" <?= (int)$item['quantity'] <= 1 ? 'disabled' : '' ?>>−</button>
                            </form>

                            <span class="walkin-qty-value"><?= (int)$item['quantity'] ?></span>

                            <form method="POST" class="walkin-qty-form">
                                <input type="hidden" name="action" value="set_quantity">
                                <input type="hidden" name="cart_key" value="<?= htmlspecialchars((string)$cartKey) ?>">
                                <input type="hidden" name="category" value="<?= htmlspecialchars($category) ?>">
                                <input type="hidden" name="quantity" value="<?= min(50, (int)$item['quantity'] + 1) ?>">
                                <button type="submit" class="walkin-qty-btn" aria-label="Increase quantity">+</button>
                            </form>

                            <form method="POST" class="walkin-remove-form">
                                <input type="hidden" name="action" value="remove_item">
                                <input type="hidden" name="cart_key" value="<?= htmlspecialchars((string)$cartKey) ?>">
                                <input type="hidden" name="category" value="<?= htmlspecialchars($category) ?>">
                                <button type="submit" class="walkin-remove-btn" aria-label="Remove item"><i class="bi bi-trash3"></i></button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="walkin-cart-footer">
            <div class="walkin-total-row">
                <span>Subtotal</span>
                <strong>₱<?= number_format($walkinSubtotal, 2) ?></strong>
            </div>

            <?php if ($walkinDiscountAmount > 0): ?>
                <div class="walkin-total-row discount">
                    <span><?= htmlspecialchars($walkinDiscountLabel) ?> Discount (<?= walkinFmtRate($walkinDiscountRate) ?>)</span>
                    <strong>-₱<?= number_format($walkinDiscountAmount, 2) ?></strong>
                </div>
            <?php endif; ?>

            <div class="walkin-total-row grand">
                <span>Total</span>
                <strong>₱<?= number_format($walkinTotal, 2) ?></strong>
            </div>

            <button type="button" class="btn walkin-place-btn" id="walkinPlaceBtn" <?= empty($_SESSION['walkin_cart']) ? 'disabled' : '' ?>>
                <i class="bi bi-check2-circle me-1"></i> Place Walk-in Order
            </button>

            <form method="POST">
                <input type="hidden" name="action" value="clear_cart">
                <input type="hidden" name="category" value="<?= htmlspecialchars($category) ?>">
                <button type="submit" class="btn walkin-clear-btn" <?= empty($_SESSION['walkin_cart']) ? 'disabled' : '' ?> onclick="return confirm('Clear the current walk-in order?');">
                    Clear Order
                </button>
            </form>
        </div>
    </div>
</div>

<!-- PRODUCT CUSTOMIZATION MODAL -->
<div class="modal fade walkin-modal" id="walkinProductModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <div class="walkin-eyebrow">Customize Item</div>
                    <h5 class="modal-title" id="walkinProductModalTitle">Product</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <form method="POST" id="walkinAddForm">
                <input type="hidden" name="action" value="add_item">
                <input type="hidden" name="product_id" id="walkinProductId">
                <input type="hidden" name="category" value="<?= htmlspecialchars($category) ?>">

                <div class="modal-body">
                    <div class="mb-3">
                        <span class="section-label">Size</span>
                        <div class="walkin-radio-grid" id="walkinSizeOptions"></div>
                    </div>

                    <div class="mb-3">
                        <span class="section-label">Sugar Level</span>
                        <div class="walkin-radio-grid">
                            <?php foreach (['0%', '25%', '50%', '75%', '100%'] as $sugar): ?>
                                <?php $sid = 'walkinSugar' . str_replace('%', '', $sugar); ?>
                                <div class="walkin-radio-card">
                                    <input type="radio" name="sugar_level" value="<?= htmlspecialchars($sugar) ?>" id="<?= $sid ?>" <?= $sugar === '50%' ? 'checked' : '' ?>>
                                    <label for="<?= $sid ?>"><?= htmlspecialchars($sugar) ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="mb-3">
                        <span class="section-label">Add-ons</span>
                        <div id="walkinAddonOptions" class="walkin-addon-grid"></div>
                        <div id="walkinNoAddons" class="walkin-discount-note">No add-ons are available for this product.</div>
                    </div>

                    <div class="mb-3">
                        <span class="section-label">Discount</span>
                        <div class="walkin-radio-grid">
                            <div class="walkin-radio-card">
                                <input type="radio" name="discount_type" value="none" id="walkinDiscountNone" checked>
                                <label for="walkinDiscountNone">None</label>
                            </div>
                            <div class="walkin-radio-card">
                                <input type="radio" name="discount_type" value="pwd" id="walkinDiscountPwd">
                                <label for="walkinDiscountPwd">PWD (<?= walkinFmtRate((float)$discountRates['pwd']) ?>)</label>
                            </div>
                            <div class="walkin-radio-card">
                                <input type="radio" name="discount_type" value="senior" id="walkinDiscountSenior">
                                <label for="walkinDiscountSenior">Senior (<?= walkinFmtRate((float)$discountRates['senior']) ?>)</label>
                            </div>
                        </div>
                        <div id="walkinDiscountDetails" class="walkin-discount-details" hidden>
                            <div class="mb-2">
                                <label for="walkinDiscountIdName" class="walkin-field-label">Name on ID</label>
                                <input type="text" class="form-control" name="discount_id_name" id="walkinDiscountIdName"
                                       maxlength="100" placeholder="Full name as shown on the ID" autocomplete="off">
                            </div>
                            <div>
                                <label for="walkinDiscountIdNumber" class="walkin-field-label">ID Number</label>
                                <input type="text" class="form-control" name="discount_id_number" id="walkinDiscountIdNumber"
                                       maxlength="30" placeholder="PWD / Senior Citizen ID number" autocomplete="off">
                            </div>
                        </div>
                        <div class="walkin-discount-note">Verify the customer's valid ID. Only one discount type is allowed per order.</div>
                    </div>

                    <div class="mb-1">
                        <span class="section-label">Quantity</span>
                        <div class="walkin-qty-stepper">
                            <button type="button" id="walkinQtyMinus" aria-label="Decrease">−</button>
                            <input type="number" name="quantity" id="walkinQuantity" value="1" min="1" max="50">
                            <button type="button" id="walkinQtyPlus" aria-label="Increase">+</button>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <div class="walkin-modal-total"><small>Item total</small><span id="walkinModalTotal">₱0.00</span></div>
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn walkin-place-btn"><i class="bi bi-plus-circle me-1"></i> Add to Order</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- WALK-IN CHECKOUT MODAL -->
<div class="modal fade walkin-modal" id="walkinCheckoutModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <div class="walkin-eyebrow">Complete Order</div>
                    <h5 class="modal-title">Walk-in Customer Details</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <form method="POST" id="walkinCheckoutForm">
                <input type="hidden" name="action" value="place_order">
                <input type="hidden" name="category" value="<?= htmlspecialchars($category) ?>">

                <div class="modal-body">
                    <div class="mb-3">
                        <label for="walkinCustomerName" class="section-label">Customer Name</label>
                        <input type="text" class="form-control" name="customer_name" id="walkinCustomerName" value="Walk-in Customer" maxlength="100">
                    </div>

                    <div class="mb-3">
                        <span class="section-label">Payment Method</span>
                        <div class="walkin-radio-grid">
                            <div class="walkin-radio-card">
                                <input type="radio" name="payment_method" value="cash" id="walkinPaymentCash" checked>
                                <label for="walkinPaymentCash"><i class="bi bi-cash-stack me-1"></i> Cash</label>
                            </div>
                            <div class="walkin-radio-card">
                                <input type="radio" name="payment_method" value="gcash" id="walkinPaymentGcash">
                                <label for="walkinPaymentGcash"><i class="bi bi-phone me-1"></i> GCash</label>
                            </div>
                        </div>
                        <div class="walkin-discount-note">Walk-in payments are recorded as received and verified at the counter.</div>
                    </div>

                    <div class="mb-3">
                        <label for="walkinNotes" class="section-label">Notes <span class="fw-normal text-muted">(optional)</span></label>
                        <textarea class="form-control" name="notes" id="walkinNotes" rows="2" maxlength="1000" placeholder="Special instructions or counter notes..."></textarea>
                    </div>

                    <div class="walkin-success-box">
                        <div class="walkin-success-title">Total to collect: ₱<?= number_format($walkinTotal, 2) ?></div>
                        <div class="walkin-success-meta">A receipt will be ready to print right after you confirm.</div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Back</button>
                    <button type="submit" class="btn walkin-place-btn">Confirm &amp; Place Order</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if (isset($_GET['added']) || isset($_GET['updated']) || isset($_GET['cleared']) || isset($_GET['error'])): ?>
<div class="walkin-toast" aria-live="polite" aria-atomic="true">
    <div class="toast <?= isset($_GET['error']) ? 'is-error' : '' ?>" role="status" data-bs-delay="3500">
        <div class="toast-header">
            <i class="bi <?= isset($_GET['error']) ? 'bi-exclamation-circle' : 'bi-check-circle' ?> me-2"></i>
            <strong class="me-auto">Walk-in Order</strong>
            <small>Now</small>
            <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
        <div class="toast-body">
            <?php
            if (isset($_GET['error'])) {
                $message = (string)$_GET['error'];
            } elseif (isset($_GET['added'])) {
                $message = 'Item added to the walk-in order.';
            } elseif (isset($_GET['updated'])) {
                $message = 'Walk-in order updated.';
            } else {
                $message = 'Walk-in order cleared.';
            }
            echo htmlspecialchars($message);
            ?>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
(function () {
    'use strict';

    /* Walk-in UI controller. It does not depend on Bootstrap JS, so the page still works
       when the staff layout loads Bootstrap late or not at all. The existing HTML/CSS is kept. */
    const body = document.body;
    const productModal = document.getElementById('walkinProductModal');
    const cartEl = document.getElementById('walkinCartOffcanvas');
    const checkoutEl = document.getElementById('walkinCheckoutModal');
    const receiptEl = document.getElementById('walkinReceiptModal');
    const addForm = document.getElementById('walkinAddForm');
    const checkoutForm = document.getElementById('walkinCheckoutForm');
    const qtyInput = document.getElementById('walkinQuantity');
    const modalTotal = document.getElementById('walkinModalTotal');
    const sizeOptions = document.getElementById('walkinSizeOptions');
    const addonOptions = document.getElementById('walkinAddonOptions');
    const noAddons = document.getElementById('walkinNoAddons');
    const productIdInput = document.getElementById('walkinProductId');
    const productModalTitle = document.getElementById('walkinProductModalTitle');
    const placeBtn = document.getElementById('walkinPlaceBtn');
    const fab = document.getElementById('walkinFab') || document.querySelector('.walkin-fab');
    const printBtn = document.getElementById('walkinPrintBtn');

    let baseFallbackPrice = 0;
    let activeProductButton = null;
    let activeBackdrop = null;

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function moveOverlayToBody(el) {
        if (el && el.parentNode !== body) body.appendChild(el);
    }

    [productModal, cartEl, checkoutEl, receiptEl].forEach(moveOverlayToBody);
    document.querySelectorAll('.walkin-toast').forEach(moveOverlayToBody);

    function makeBackdrop(type) {
        closeBackdrop();
        const backdrop = document.createElement('div');
        backdrop.className = type === 'modal' ? 'modal-backdrop fade show' : 'offcanvas-backdrop fade show';
        backdrop.setAttribute('data-localitea-walkin-backdrop', '1');
        backdrop.addEventListener('click', function () {
            if (type === 'modal') closeModal(productModal || checkoutEl || receiptEl);
            else closeCart();
        });
        body.appendChild(backdrop);
        activeBackdrop = backdrop;
        return backdrop;
    }

    function closeBackdrop() {
        document.querySelectorAll('[data-localitea-walkin-backdrop]').forEach(function (el) { el.remove(); });
        activeBackdrop = null;
    }

    function openModal(el) {
        if (!el) return;
        [productModal, checkoutEl, receiptEl].forEach(function (other) {
            if (other && other !== el && other.classList.contains('show')) closeModal(other);
        });
        moveOverlayToBody(el);
        closeBackdrop();
        el.style.display = 'block';
        el.removeAttribute('aria-hidden');
        el.setAttribute('aria-modal', 'true');
        el.setAttribute('role', 'dialog');
        void el.offsetWidth;
        el.classList.add('show');
        makeBackdrop('modal');
        body.classList.add('modal-open');
        body.style.overflow = 'hidden';
    }

    function closeModal(el) {
        if (!el) return;
        el.classList.remove('show');
        el.style.display = '';
        el.removeAttribute('aria-modal');
        el.removeAttribute('role');
        el.setAttribute('aria-hidden', 'true');
        closeBackdrop();
        body.classList.remove('modal-open');
        body.style.removeProperty('overflow');
    }

    function openCart() {
        if (!cartEl) return;
        [productModal, checkoutEl, receiptEl].forEach(function (el) {
            if (el && el.classList.contains('show')) closeModal(el);
        });
        moveOverlayToBody(cartEl);
        closeBackdrop();
        cartEl.style.visibility = 'visible';
        cartEl.setAttribute('aria-hidden', 'false');
        cartEl.setAttribute('aria-modal', 'true');
        void cartEl.offsetWidth;
        cartEl.classList.add('show');
        makeBackdrop('offcanvas');
        body.classList.add('modal-open');
        body.style.overflow = 'hidden';
    }

    function closeCart() {
        if (!cartEl) return;
        cartEl.classList.remove('show');
        cartEl.style.visibility = '';
        cartEl.removeAttribute('aria-modal');
        cartEl.setAttribute('aria-hidden', 'true');
        closeBackdrop();
        body.classList.remove('modal-open');
        body.style.removeProperty('overflow');
    }

    function clampQty() {
        if (!qtyInput) return 1;
        let q = parseInt(qtyInput.value, 10);
        if (isNaN(q) || q < 1) q = 1;
        if (q > 50) q = 50;
        qtyInput.value = q;
        return q;
    }

    function updateModalTotal() {
        if (!addForm || !modalTotal) return;
        const sizeInput = addForm.querySelector('input[name="size"]:checked');
        const base = sizeInput ? Number(sizeInput.dataset.price || 0) : baseFallbackPrice;
        let addons = 0;
        addForm.querySelectorAll('input[name="addons[]"]:checked').forEach(function (c) {
            addons += Number(c.dataset.price || 0);
        });
        modalTotal.textContent = '₱' + ((base + addons) * clampQty()).toFixed(2);
    }

    function renderSizeOptions(button) {
        if (!sizeOptions) return;
        const price = Number(button.dataset.price || 0);
        const regular = Number(button.dataset.regularPrice || 0);
        const grande = Number(button.dataset.grandePrice || 0);
        const options = [];
        if (regular > 0) options.push({ value: 'Regular', price: regular });
        if (grande > 0) options.push({ value: 'Grande', price: grande });
        baseFallbackPrice = price;

        if (!options.length) {
            sizeOptions.innerHTML = '<div class="walkin-radio-card">' +
                '<input type="radio" name="size" value="" id="walkinSizeNone" data-price="' + price + '" checked>' +
                '<label for="walkinSizeNone">Standard · ₱' + price.toFixed(2) + '</label></div>';
            return;
        }
        sizeOptions.innerHTML = options.map(function (option, index) {
            const id = 'walkinSize_' + option.value.toLowerCase();
            return '<div class="walkin-radio-card">' +
                '<input type="radio" name="size" value="' + escapeHtml(option.value) + '" id="' + id + '" data-price="' + option.price + '" ' + (index === 0 ? 'checked' : '') + '>' +
                '<label for="' + id + '">' + escapeHtml(option.value) + ' · ₱' + option.price.toFixed(2) + '</label></div>';
        }).join('');
    }

    function renderAddons(button) {
        if (!addonOptions || !noAddons) return;
        let addons = [];
        try { addons = JSON.parse(button.dataset.addons || '[]'); } catch (e) { addons = []; }
        noAddons.style.display = addons.length ? 'none' : 'block';
        addonOptions.innerHTML = addons.map(function (addon) {
            const id = 'walkinAddon_' + addon.id;
            const price = Number(addon.price || 0);
            return '<div class="walkin-radio-card">' +
                '<input type="checkbox" name="addons[]" value="' + escapeHtml(addon.name) + '" id="' + id + '" data-price="' + price + '">' +
                '<label for="' + id + '">' + escapeHtml(addon.name) + ' <span>+₱' + price.toFixed(2) + '</span></label></div>';
        }).join('');
    }

    function openProductModal(button) {
        if (!button || button.disabled || !productModal || !addForm) return;
        activeProductButton = button;
        if (productModalTitle) productModalTitle.textContent = button.dataset.productName || 'Product';
        if (productIdInput) productIdInput.value = button.dataset.productId || '';
        renderSizeOptions(button);
        renderAddons(button);
        if (qtyInput) qtyInput.value = '1';
        const sugar50 = document.getElementById('walkinSugar50');
        const discountNone = document.getElementById('walkinDiscountNone');
        if (sugar50) sugar50.checked = true;
        if (discountNone) discountNone.checked = true;
        resetDiscountFields();
        updateModalTotal();
        openModal(productModal);
    }

    document.addEventListener('click', function (event) {
        const addButton = event.target.closest('.walkin-add-btn[data-product-id]');
        if (addButton) {
            event.preventDefault();
            event.stopPropagation();
            openProductModal(addButton);
            return;
        }

        const viewOrder = event.target.closest('#walkinFab, .walkin-fab');
        if (viewOrder) {
            event.preventDefault();
            event.stopPropagation();
            openCart();
            return;
        }

        const dismiss = event.target.closest('[data-bs-dismiss]');
        if (dismiss) {
            const type = dismiss.getAttribute('data-bs-dismiss');
            const target = dismiss.closest('.modal, .offcanvas, .toast');
            if (!target) return;
            event.preventDefault();
            event.stopPropagation();
            if (type === 'offcanvas') closeCart();
            else if (type === 'modal') closeModal(target);
            else target.classList.remove('show');
            return;
        }
    }, true);

    if (addForm) addForm.addEventListener('change', updateModalTotal);

    /* ---------- PWD / Senior ID fields ---------- */
    const discountDetails = document.getElementById('walkinDiscountDetails');
    const discountIdName = document.getElementById('walkinDiscountIdName');
    const discountIdNumber = document.getElementById('walkinDiscountIdNumber');
    /* Details of the discount already used in the cart (one cardholder per order). */
    const activeDiscount = <?= json_encode($walkinActiveDiscount, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    function resetDiscountFields() {
        if (!discountDetails) return;
        discountDetails.hidden = true;
        discountIdName.value = '';
        discountIdNumber.value = '';
        discountIdName.required = false;
        discountIdNumber.required = false;
        discountIdName.classList.remove('is-invalid');
        discountIdNumber.classList.remove('is-invalid');
    }

    function syncDiscountFields() {
        if (!discountDetails || !addForm) return;
        const checked = addForm.querySelector('input[name="discount_type"]:checked');
        const type = checked ? checked.value : 'none';

        if (type === 'none') { resetDiscountFields(); return; }

        const wasHidden = discountDetails.hidden;
        discountDetails.hidden = false;
        discountIdName.required = true;
        discountIdNumber.required = true;

        /* Auto-fill when the cart already has this discount type, so the cashier doesn't retype it. */
        if (wasHidden && activeDiscount && activeDiscount.type === type) {
            discountIdName.value = activeDiscount.name || '';
            discountIdNumber.value = activeDiscount.id || '';
        }
        if (wasHidden) discountIdName.focus();
    }

    if (addForm && discountDetails) {
        addForm.querySelectorAll('input[name="discount_type"]').forEach(function (r) {
            r.addEventListener('change', syncDiscountFields);
        });

        addForm.addEventListener('submit', function (e) {
            if (discountDetails.hidden) return;
            const nameOk = discountIdName.value.trim() !== '';
            const idOk = /^[A-Za-z0-9][A-Za-z0-9\-\/ ]{2,29}$/.test(discountIdNumber.value.trim());
            discountIdName.classList.toggle('is-invalid', !nameOk);
            discountIdNumber.classList.toggle('is-invalid', !idOk);
            if (!nameOk || !idOk) {
                e.preventDefault();
                e.stopImmediatePropagation();
                (nameOk ? discountIdNumber : discountIdName).focus();
                const btn = addForm.querySelector('button[type="submit"]');
                if (btn) btn.disabled = false;
                showToast(!nameOk ? 'Enter the name on the ID.' : 'Enter a valid ID number.', true);
            }
        }, true);
    }
    if (qtyInput) qtyInput.addEventListener('input', updateModalTotal);

    const minus = document.getElementById('walkinQtyMinus');
    const plus = document.getElementById('walkinQtyPlus');
    if (minus) minus.addEventListener('click', function () {
        qtyInput.value = Math.max(1, clampQty() - 1); updateModalTotal();
    });
    if (plus) plus.addEventListener('click', function () {
        qtyInput.value = Math.min(50, clampQty() + 1); updateModalTotal();
    });

    function disableSubmitOnce(form) {
        if (!form) return;
        form.addEventListener('submit', function () {
            const btn = form.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled = true;
                window.setTimeout(function () { btn.disabled = false; }, 8000);
            }
        });
    }
    disableSubmitOnce(checkoutForm);

    /* Delegated: the drawer body is re-rendered by fast cart updates, so a direct listener would be lost. */
    document.addEventListener('click', function (event) {
        const place = event.target.closest('#walkinPlaceBtn');
        if (!place || place.disabled) return;
        event.preventDefault();
        event.stopPropagation();
        closeCart();
        openModal(checkoutEl);
    }, true);

    if (printBtn) {
        printBtn.addEventListener('click', function () {
            const frame = document.getElementById('walkinReceiptFrame');
            if (!frame) return;
            try {
                frame.contentWindow.focus();
                frame.contentWindow.print();
            } catch (e) {
                const url = frame.src.replace('&embed=1', '&print=1').replace('?embed=1', '?print=1');
                window.open(url, '_blank', 'noopener');
            }
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        if (receiptEl && receiptEl.classList.contains('show')) closeModal(receiptEl);
        else if (checkoutEl && checkoutEl.classList.contains('show')) closeModal(checkoutEl);
        else if (productModal && productModal.classList.contains('show')) closeModal(productModal);
        else if (cartEl && cartEl.classList.contains('show')) closeCart();
    });

    /* Open the cart after add/update redirect. This is deliberately done without Bootstrap. */
    const params = new URLSearchParams(window.location.search);
    if (params.get('cart') === '1') {
        window.setTimeout(openCart, 30);
    }

    ['cart', 'added', 'updated', 'cleared', 'error', 'placed'].forEach(function (key) { params.delete(key); });
    const cleanQuery = params.toString();
    try {
        window.history.replaceState({}, '', window.location.pathname + (cleanQuery ? '?' + cleanQuery : ''));
    } catch (e) {}

    /* Receipt opens automatically after a successful order. */
    if (receiptEl) {
        const hasReceipt = !!receiptEl.querySelector('#walkinReceiptFrame');
        if (hasReceipt) window.setTimeout(function () { openModal(receiptEl); }, 50);
    }

    /* ---------- notifications (toast) ---------- */
    let toastTimer = null;

    function showToast(message, isError) {
        document.querySelectorAll('.walkin-toast').forEach(function (el) { el.remove(); });
        window.clearTimeout(toastTimer);
        const wrap = document.createElement('div');
        wrap.className = 'walkin-toast';
        wrap.setAttribute('aria-live', 'polite');
        wrap.setAttribute('aria-atomic', 'true');
        wrap.innerHTML =
            '<div class="toast show ' + (isError ? 'is-error' : '') + '" role="status">' +
                '<div class="toast-header">' +
                    '<i class="bi ' + (isError ? 'bi-exclamation-circle' : 'bi-check-circle') + ' me-2"></i>' +
                    '<strong class="me-auto">Walk-in Order</strong><small>Now</small>' +
                    '<button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>' +
                '</div>' +
                '<div class="toast-body"></div>' +
            '</div>';
        wrap.querySelector('.toast-body').textContent = message;
        body.appendChild(wrap);
        toastTimer = window.setTimeout(function () { wrap.remove(); }, 3500);
    }

    /* Keep notifications beside the order drawer instead of on top of it. */
    if (cartEl) {
        const syncDrawerClass = function () {
            body.classList.toggle('walkin-drawer-open', cartEl.classList.contains('show'));
        };
        new MutationObserver(syncDrawerClass).observe(cartEl, { attributes: true, attributeFilter: ['class'] });
        syncDrawerClass();
    }

    /* ---------- fast cart updates (no full page reload) ---------- */
    let cartBusy = false;

    async function postCart(form) {
        let res;
        try {
            res = await fetch(form.getAttribute('action') || window.location.href, {
                method: 'POST',
                body: new URLSearchParams(new FormData(form)),
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                redirect: 'follow'
            });
        } catch (err) {
            err.network = true;   /* request never reached the server */
            throw err;
        }

        const finalUrl = new URL(res.url, window.location.href);
        if (!res.ok) {
            return { ok: false, message: 'Something went wrong. Please try again.' };
        }

        const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
        const newBody = doc.querySelector('#walkinCartOffcanvas .offcanvas-body');
        const newFab = doc.getElementById('walkinFab');
        if (!newBody || !newFab) {
            /* e.g. the session expired and the server sent the login page: just follow it. */
            window.location.href = finalUrl.href;
            return { navigated: true };
        }

        cartEl.querySelector('.offcanvas-body').innerHTML = newBody.innerHTML;
        const liveFab = document.getElementById('walkinFab');
        if (liveFab) liveFab.innerHTML = newFab.innerHTML;

        const newTotal = doc.querySelector('#walkinCheckoutModal .walkin-success-title');
        const curTotal = document.querySelector('#walkinCheckoutModal .walkin-success-title');
        if (newTotal && curTotal) curTotal.textContent = newTotal.textContent;

        const q = finalUrl.searchParams;
        if (q.get('error')) return { ok: false, message: q.get('error') };
        if (q.get('added')) return { ok: true, message: 'Item added to the walk-in order.' };
        if (q.get('cleared')) return { ok: true, message: 'Walk-in order cleared.' };
        return { ok: true, message: 'Walk-in order updated.' };
    }

    /* Add to Order */
    if (addForm) {
        addForm.addEventListener('submit', function (e) {
            e.preventDefault();
            if (cartBusy) return;
            cartBusy = true;

            const btn = addForm.querySelector('button[type="submit"]');
            const label = btn ? btn.innerHTML : '';
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span> Adding...';
            }

            postCart(addForm).then(function (r) {
                if (r.navigated) return;
                closeModal(productModal);
                if (r.ok) openCart();
                else showToast(r.message, true);
            }).catch(function (err) {
                if (err && err.network) {
                    addForm.submit();   /* normal full-page submit as a safety net */
                } else {
                    showToast('Unable to add the item. Please try again.', true);
                }
            }).finally(function () {
                cartBusy = false;
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = label;
                }
            });
        });
    }

    /* Quantity +/-, remove item, clear order (forms inside the drawer) */
    if (cartEl) {
        cartEl.addEventListener('submit', function (e) {
            const form = e.target;
            if (!form || form.tagName !== 'FORM') return;
            e.preventDefault();
            if (cartBusy) return;
            cartBusy = true;

            const btn = form.querySelector('button[type="submit"]');
            if (btn) btn.disabled = true;

            postCart(form).then(function (r) {
                if (r.navigated) return;
                if (!r.ok) showToast(r.message, true);
            }).catch(function (err) {
                if (err && err.network) {
                    form.submit();  
                } else {
                    showToast('Unable to update the order. Please try again.', true);
                    if (btn) btn.disabled = false;
                }
            }).finally(function () {
                cartBusy = false;
            });
        });
    }

    /* Existing server-rendered toast: preserve its design, just make it visible/closable. */
    document.querySelectorAll('.walkin-toast .toast').forEach(function (toast) {
        toast.classList.add('show');
        const delay = Number(toast.dataset.bsDelay || 3500);
        window.setTimeout(function () { toast.classList.remove('show'); }, delay);
    });
})();
</script>