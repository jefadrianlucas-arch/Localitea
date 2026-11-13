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

/* Sign out Staff accounts that were archived while logged in. */
$activeStaffStmt = $pdo->prepare("
    SELECT id
    FROM users
    WHERE id = ?
      AND role = 'staff'
      AND is_archived = 0
    LIMIT 1
");
$activeStaffStmt->execute([(int)$_SESSION['user_id']]);

if (!$activeStaffStmt->fetch()) {
    $_SESSION = [];
    session_destroy();
    header('Location: ../auth/login.php');
    exit;
}

$staff_id = (int)($_SESSION['user_id'] ?? 0);

/* CSRF token, shared with staff/index.php. Every POST form below carries it. */
if (empty($_SESSION['staff_csrf_token'])) {
    $_SESSION['staff_csrf_token'] = bin2hex(random_bytes(32));
}

$staff_csrf_token = $_SESSION['staff_csrf_token'];

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
    'Bundle'
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

/**
 * Re-read every walk-in cart line from the database immediately before order
 * creation. The caller must already have an open transaction. FOR UPDATE
 * prevents product/add-on prices or availability from changing between this
 * validation and the order insert/commit.
 *
 * The cart is refreshed with current names, add-ons, bundle flavor names and
 * prices. If a price changed, the caller must roll back and ask the cashier
 * to review the updated cart before submitting again.
 */
function walkinRevalidateCartForOrder(PDO $pdo, array &$cart): array
{
    if (empty($cart)) {
        throw new RuntimeException('Please add at least one product.');
    }

    $productStmt = $pdo->prepare("
        SELECT id, name, category_id, price, regular_price, grande_price,
               is_available, is_archived
        FROM products
        WHERE id = ?
        LIMIT 1
        FOR UPDATE
    ");

    $addonStmt = $pdo->prepare("
        SELECT a.id, a.name, a.price
        FROM product_addons pa
        INNER JOIN addons a ON a.id = pa.addon_id
        WHERE pa.product_id = ?
          AND a.is_available = 1
          AND a.is_archived = 0
        ORDER BY pa.sort_order ASC, a.name ASC
        FOR UPDATE
    ");

    $bundleConfigStmt = $pdo->prepare("
        SELECT size, cups, category_ids
        FROM menu_bundle_options
        WHERE product_id = ?
        FOR UPDATE
    ");

    $subtotal = 0.00;
    $discountEligibleBase = 0.00;
    $discountTypes = [];
    $discountIdName = '';
    $discountIdNumber = '';
    $lineData = [];
    $priceChanged = false;

    foreach ($cart as $cartKey => &$item) {
        if (!is_array($item)) {
            throw new RuntimeException('The walk-in cart contains an invalid item. Please remove it and add it again.');
        }

        $productId = filter_var(
            $item['product_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if ($productId === false) {
            throw new RuntimeException('A product in the walk-in cart is invalid. Please remove it and add it again.');
        }

        $quantity = filter_var(
            $item['quantity'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if ($quantity === false) {
            throw new RuntimeException('Each walk-in product quantity must be a whole number from 1 to 50. Please correct the quantity.');
        }

        $productStmt->execute([(int)$productId]);
        $product = $productStmt->fetch(PDO::FETCH_ASSOC);
        if (
            !$product ||
            (int)$product['is_available'] !== 1 ||
            (int)$product['is_archived'] !== 0
        ) {
            throw new RuntimeException('One of the products in the walk-in cart is no longer available. Remove it and choose another product.');
        }

        $size = $item['size'] ?? '';
        if (!is_string($size)) {
            throw new RuntimeException('A product in the walk-in cart has an invalid size. Remove it and add it again.');
        }
        $size = trim($size);
        if (!in_array($size, ['', 'Regular', 'Grande'], true)) {
            throw new RuntimeException('A product in the walk-in cart has an invalid size. Remove it and add it again.');
        }

        if ($size === 'Regular') {
            $basePrice = (float)($product['regular_price'] ?? 0);
        } elseif ($size === 'Grande') {
            $basePrice = (float)($product['grande_price'] ?? 0);
        } else {
            $basePrice = (float)($product['price'] ?? 0);
        }
        if (!is_finite($basePrice) || $basePrice <= 0) {
            throw new RuntimeException('The selected size for ' . (string)$product['name'] . ' is no longer available. Remove it and choose another size.');
        }

        $sugarLevel = $item['sugar_level'] ?? '';
        if (
            !is_string($sugarLevel) ||
            !in_array(trim($sugarLevel), ['', '0%', '25%', '50%', '75%', '100%'], true)
        ) {
            throw new RuntimeException('A product in the walk-in cart has an invalid sugar level. Remove it and add it again.');
        }
        $sugarLevel = trim($sugarLevel);

        $discountType = strtolower(trim((string)($item['discount_type'] ?? 'none')));
        if (!in_array($discountType, ['none', 'pwd', 'senior'], true)) {
            throw new RuntimeException('A product in the walk-in cart has an invalid discount type. Remove it and add it again.');
        }

        $rawAddons = $item['addons'] ?? [];
        if ($rawAddons === null || $rawAddons === '') {
            $rawAddons = [];
        }
        if (!is_array($rawAddons)) {
            throw new RuntimeException('A product in the walk-in cart has invalid add-ons. Remove it and add it again.');
        }

        $selectedAddonNames = [];
        foreach ($rawAddons as $addonName) {
            if (!is_string($addonName) || trim($addonName) === '') {
                throw new RuntimeException('A product in the walk-in cart has an invalid add-on selection. Remove it and add it again.');
            }
            $selectedAddonNames[] = trim($addonName);
        }
        if (count($selectedAddonNames) !== count(array_unique($selectedAddonNames))) {
            throw new RuntimeException('A product in the walk-in cart contains a repeated add-on. Remove the repeated selection.');
        }

        $addonPrices = [];
        $addonsTotal = 0.00;
        if ($selectedAddonNames) {
            $addonStmt->execute([(int)$productId]);
            foreach ($addonStmt->fetchAll(PDO::FETCH_ASSOC) as $addon) {
                $addonName = (string)$addon['name'];
                $addonPrice = (float)$addon['price'];
                if (!is_finite($addonPrice) || $addonPrice < 0) {
                    throw new RuntimeException('An add-on has an invalid price. Remove it and add the product again.');
                }
                $addonPrices[$addonName] = $addonPrice;
            }

            foreach ($selectedAddonNames as $addonName) {
                if (!array_key_exists($addonName, $addonPrices)) {
                    throw new RuntimeException('An add-on selected for ' . (string)$product['name'] . ' is no longer available. Remove the product and add it again.');
                }
                $addonsTotal += $addonPrices[$addonName];
            }
        }

        /* Re-check configured bundle flavor selections, not the old cart labels. */
        $rawBundlePicks = $item['bundle_picks'] ?? [];
        if ($rawBundlePicks === null || $rawBundlePicks === '') {
            $rawBundlePicks = [];
        }
        if (!is_array($rawBundlePicks)) {
            throw new RuntimeException('A bundle in the walk-in cart has invalid flavor selections. Remove it and add it again.');
        }

        $bundleConfigStmt->execute([(int)$productId]);
        $bundleConfigs = [];
        foreach ($bundleConfigStmt->fetchAll(PDO::FETCH_ASSOC) as $configRow) {
            $bundleConfigs[(string)$configRow['size']] = $configRow;
        }
        $bundleConfig = $bundleConfigs[$size] ?? ($bundleConfigs[''] ?? null);

        $freshBundlePicks = [];
        $lineAddons = [];
        if ($bundleConfig) {
            $requiredCups = (int)$bundleConfig['cups'];
            $allowedCategoryIds = array_values(array_filter(
                array_map('intval', explode(',', (string)$bundleConfig['category_ids'])),
                static fn(int $id): bool => $id > 0
            ));
            if ($requiredCups <= 0 || !$allowedCategoryIds || count($rawBundlePicks) !== $requiredCups) {
                throw new RuntimeException('A bundle is missing one or more valid flavor selections. Remove it and add it again.');
            }

            $pickIds = [];
            foreach ($rawBundlePicks as $pick) {
                $pickId = is_array($pick)
                    ? filter_var($pick['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                    : false;
                if ($pickId === false) {
                    throw new RuntimeException('A bundle contains an invalid flavor selection. Remove it and add it again.');
                }
                $pickIds[] = (int)$pickId;
            }

            $uniquePickIds = array_values(array_unique($pickIds));
            $pickPlaceholders = implode(',', array_fill(0, count($uniquePickIds), '?'));
            $pickStmt = $pdo->prepare("
                SELECT id, name, category_id, is_available, is_archived
                FROM products
                WHERE id IN ($pickPlaceholders)
                FOR UPDATE
            ");
            $pickStmt->execute($uniquePickIds);
            $pickProducts = [];
            foreach ($pickStmt->fetchAll(PDO::FETCH_ASSOC) as $pickProduct) {
                $pickProducts[(int)$pickProduct['id']] = $pickProduct;
            }

            foreach ($pickIds as $index => $pickId) {
                $pickProduct = $pickProducts[$pickId] ?? null;
                if (
                    !$pickProduct ||
                    (int)$pickProduct['is_available'] !== 1 ||
                    (int)$pickProduct['is_archived'] !== 0 ||
                    !in_array((int)$pickProduct['category_id'], $allowedCategoryIds, true)
                ) {
                    throw new RuntimeException('A selected bundle flavor is no longer available. Remove the bundle and choose its flavors again.');
                }
                $freshBundlePicks[] = [
                    'id' => $pickId,
                    'name' => (string)$pickProduct['name'],
                ];
                $lineAddons[] = 'Cup ' . ($index + 1) . ': ' . (string)$pickProduct['name'];
            }
        } elseif (!empty($rawBundlePicks)) {
            throw new RuntimeException('The bundle configuration has changed. Remove the bundle and add it again.');
        }
        /* Keep the original receipt order: bundle cup selections, then add-ons. */
        $lineAddons = array_merge($lineAddons, $selectedAddonNames);

        $currentUnitPrice = round($basePrice + $addonsTotal, 2);
        $storedUnitPrice = $item['price'] ?? null;
        if (!is_numeric($storedUnitPrice) || !is_finite((float)$storedUnitPrice) || (float)$storedUnitPrice < 0) {
            throw new RuntimeException('A product in the walk-in cart has an invalid saved price. Remove it and add it again.');
        }
        if (abs((float)$storedUnitPrice - $currentUnitPrice) > 0.009) {
            $priceChanged = true;
        }

        /* Refresh the session cart so the cashier sees the current database values. */
        $item['product_id'] = (int)$productId;
        $item['name'] = (string)$product['name'];
        $item['size'] = $size;
        $item['sugar_level'] = $sugarLevel;
        $item['addons'] = $selectedAddonNames;
        $item['bundle_picks'] = $freshBundlePicks;
        $item['discount_type'] = $discountType;
        $item['quantity'] = (int)$quantity;
        $item['price'] = $currentUnitPrice;

        $lineSubtotal = round($currentUnitPrice * (int)$quantity, 2);
        $subtotal += $lineSubtotal;

        if ($discountType !== 'none') {
            $discountTypes[$discountType] = true;
            $thisIdName = trim(preg_replace('/\s+/u', ' ', (string)($item['discount_id_name'] ?? '')));
            $thisIdNumber = strtoupper(trim((string)($item['discount_id_number'] ?? '')));
            if (
                $thisIdName === '' ||
                mb_strlen($thisIdName) > 100 ||
                !preg_match('/^[A-Z0-9][A-Z0-9\-\/ ]{2,29}$/', $thisIdNumber)
            ) {
                throw new RuntimeException('The PWD/Senior ID details in the walk-in cart are incomplete or invalid. Remove the discounted item and enter the details again.');
            }
            if ($discountIdNumber !== '' && (
                $discountIdNumber !== $thisIdNumber ||
                mb_strtolower($discountIdName, 'UTF-8') !== mb_strtolower($thisIdName, 'UTF-8')
            )) {
                throw new RuntimeException('Only one cardholder may receive a discount on a walk-in order. Use the same ID details for every discounted item.');
            }
            $discountIdName = $thisIdName;
            $discountIdNumber = $thisIdNumber;
            $discountEligibleBase += $lineSubtotal;
        }

        $lineData[] = [
            'product_id' => (int)$productId,
            'name' => (string)$product['name'],
            'quantity' => (int)$quantity,
            'unit_price' => $currentUnitPrice,
            'subtotal' => $lineSubtotal,
            'size' => $size,
            'addons' => $lineAddons,
            'sugar_level' => $sugarLevel,
            'discount_type' => $discountType,
        ];
    }
    unset($item);

    if (!$lineData || $subtotal <= 0 || !is_finite($subtotal)) {
        throw new RuntimeException('The walk-in cart contains no valid items. Please review the cart.');
    }
    if (count($discountTypes) > 1) {
        throw new RuntimeException('Please use only one discount type per order: PWD or Senior Citizen.');
    }

    return [
        'price_changed' => $priceChanged,
        'subtotal' => round($subtotal, 2),
        'discount_eligible_base' => round($discountEligibleBase, 2),
        'discount_types' => $discountTypes,
        'discount_id_name' => $discountIdName,
        'discount_id_number' => $discountIdNumber,
        'line_data' => $lineData,
    ];
}

function walkinFmtRate(float $rate): string
{
    return rtrim(rtrim(number_format($rate, 2), '0'), '.') . '%';
}

function walkinRenderProducts(array $products, array $productAddons, string $category, array $productBundles = []): void
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
                    $bundleForProduct = $productBundles[(int)$product['id']] ?? null;
                    ?>
                    <div class="col-6 col-md-4 col-xl-3 col-xxl-2">
                        <div
                            class="walkin-product-card <?= $available ? 'is-clickable' : 'is-unavailable' ?>"
                            <?php if ($available): ?>
                                role="button"
                                tabindex="0"
                                aria-haspopup="dialog"
                                aria-label="Customize <?= htmlspecialchars($product['name'], ENT_QUOTES) ?>"
                                title="Select to customize this product"
                                data-product-id="<?= (int)$product['id'] ?>"
                                data-product-name="<?= htmlspecialchars($product['name'], ENT_QUOTES) ?>"
                                data-price="<?= htmlspecialchars((string)(float)$product['price'], ENT_QUOTES) ?>"
                                data-regular-price="<?= htmlspecialchars((string)(float)($product['regular_price'] ?? 0), ENT_QUOTES) ?>"
                                data-grande-price="<?= htmlspecialchars((string)(float)($product['grande_price'] ?? 0), ENT_QUOTES) ?>"
                                data-addons='<?= htmlspecialchars(json_encode($addonsForProduct, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]', ENT_QUOTES) ?>'
                                <?php if ($bundleForProduct): ?>data-bundle='<?= htmlspecialchars(json_encode($bundleForProduct, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}', ENT_QUOTES) ?>'<?php endif; ?>
                            <?php else: ?>
                                aria-disabled="true"
                            <?php endif; ?>
                        >
                            <div class="walkin-product-image-wrap">
                                <img
                                    src="<?= htmlspecialchars(
                                        (empty($product['image']) || $product['image'] === 'default-product.png' || $product['image'] === 'default.jpg')
                                            ? '../assets/image/default-product.png'
                                            : '../assets/uploads/products/' . $product['image']
                                    ) ?>"
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

    /* Reject requests that did not come from this page. Nothing is changed. */
    if (!hash_equals($staff_csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
        walkinRedirect(walkinBackUrl(
            $returnCategory,
            'error=' . urlencode('Your session has expired. Please refresh the page and try again.')
        ));
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
            $discountIdName = trim(preg_replace('/\s+/u', ' ', (string)($_POST['discount_id_name'] ?? '')));
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
                $existingName = (string)($existing['discount_id_name'] ?? '');
                if (
                    $existingType === $discountType
                    && $existingId === $discountIdNumber
                    && mb_strtolower($existingName, 'UTF-8') !== mb_strtolower($discountIdName, 'UTF-8')
                ) {
                    walkinRedirect(walkinBackUrl($returnCategory, 'error=' . urlencode('ID ' . $existingId . ' is already recorded for ' . $existingName . '. Enter the same name as on the ID.')));
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

        /* Bundle: the cashier must pick a flavor for every cup; verified against the database. */
        $bundlePicks = [];
        $bundlePickKey = '';
        $bundleConfig = null;

        try {
            $bundleCfgStmt = $pdo->prepare("SELECT size, cups, category_ids FROM menu_bundle_options WHERE product_id = ?");
            $bundleCfgStmt->execute([$productId]);
            $bundleCfgRows = [];
            foreach ($bundleCfgStmt->fetchAll(PDO::FETCH_ASSOC) as $cfgRow) {
                $bundleCfgRows[(string)$cfgRow['size']] = $cfgRow;
            }
            $bundleConfig = $bundleCfgRows[$size] ?? ($bundleCfgRows[''] ?? null);
        } catch (Throwable $e) {
            $bundleConfig = null;
        }

        if ($bundleConfig) {
            $requiredCups = (int)$bundleConfig['cups'];
            $allowedCategoryIds = array_values(array_filter(array_map('intval', explode(',', (string)$bundleConfig['category_ids']))));
            $postedPicks = $_POST['bundle_picks'] ?? [];
            if (!is_array($postedPicks)) { $postedPicks = []; }
            $postedPicks = array_values(array_map('intval', $postedPicks));

            if ($requiredCups > 0 && $allowedCategoryIds) {
                if (count($postedPicks) !== $requiredCups || in_array(0, $postedPicks, true)) {
                    walkinRedirect(walkinBackUrl($returnCategory, 'error=' . urlencode('Please choose a flavor for every cup of the bundle.')));
                }

                $uniquePickIds = array_values(array_unique($postedPicks));
                $pickPlaceholders = implode(',', array_fill(0, count($uniquePickIds), '?'));
                $pickStmt = $pdo->prepare("
                    SELECT id, name, category_id, price, regular_price, grande_price, is_available, is_archived
                    FROM products
                    WHERE id IN ($pickPlaceholders)
                ");
                $pickStmt->execute($uniquePickIds);
                $pickProducts = [];
                foreach ($pickStmt->fetchAll(PDO::FETCH_ASSOC) as $pickRow) {
                    $pickProducts[(int)$pickRow['id']] = $pickRow;
                }

                foreach ($postedPicks as $pickId) {
                    $pick = $pickProducts[$pickId] ?? null;
                    $pickSizePrice = max(
                        (float)($pick['price'] ?? 0),
                        (float)($pick['regular_price'] ?? 0),
                        (float)($pick['grande_price'] ?? 0)
                    );

                    if (
                        !$pick ||
                        (int)$pick['is_available'] !== 1 ||
                        (int)$pick['is_archived'] !== 0 ||
                        !in_array((int)$pick['category_id'], $allowedCategoryIds, true) ||
                        $pickSizePrice <= 0
                    ) {
                        walkinRedirect(walkinBackUrl($returnCategory, 'error=' . urlencode('One of the selected flavors is no longer available. Please choose again.')));
                    }

                    $bundlePicks[] = ['id' => $pickId, 'name' => (string)$pick['name']];
                }

                /* The picks describe one bundle; add more bundles one at a time. */
                $quantity = 1;
                $bundlePickIds = array_map(static fn(array $pickItem): int => (int)$pickItem['id'], $bundlePicks);
                sort($bundlePickIds);
                $bundlePickKey = '@' . md5(implode(',', $bundlePickIds));
            }
        }

        $cartKey = md5(
            $productId . '|' . $size . '|' . $sugarLevel . '|' . $discountType . '|' . implode(',', $validAddons) . $bundlePickKey
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
                'bundle_picks' => $bundlePicks,
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
        $paymentMethod = strtolower(trim((string)($_POST['payment_method'] ?? 'cash')));
        $notes = trim((string)($_POST['notes'] ?? ''));

        if ($customerName === '') {
            $customerName = 'Walk-in Customer';
        }

        /* Match the Admin walk-in flow: a phone number is not collected for counter orders. */
        $contactNumber = 'N/A';

        if (!in_array($paymentMethod, ['cash', 'gcash'], true)) {
            $paymentMethod = 'cash';
        }

        /* GCash walk-ins: the full 13-digit GCash reference number is required.
           Spaces and dashes are ignored so it can be typed in groups. */
        $gcashReference = null;
        if ($paymentMethod === 'gcash') {
            $gcashReference = preg_replace('/[\s\-]+/', '', (string)($_POST['gcash_reference'] ?? ''));
            if (!preg_match('/^\d{13}$/', $gcashReference)) {
                walkinRedirect(walkinBackUrl(
                    $returnCategory,
                    'error=' . urlencode('Enter the full 13-digit GCash reference number.') . '&cart=1'
                ));
            }
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

        /*
         * Lock the current product/add-on rows and calculate prices again from
         * MySQL. This transaction remains open through the order insert below,
         * so an admin price/availability change cannot slip between validation
         * and saving the order.
         */
        try {
            $pdo->beginTransaction();
            $cartValidation = walkinRevalidateCartForOrder($pdo, $_SESSION['walkin_cart']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Walk-in cart validation failed: ' . $e->getMessage());
            $validationMessage = $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Unable to validate the walk-in cart. Please review the items and try again.';
            walkinRedirect(walkinBackUrl($returnCategory, 'error=' . urlencode($validationMessage) . '&cart=1'));
        }

        if ($cartValidation['price_changed']) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            walkinRedirect(walkinBackUrl(
                $returnCategory,
                'error=' . urlencode('A product or add-on price has changed. The cart now shows the updated prices. Please review the new total before placing the order again.') . '&cart=1'
            ));
        }

        $subtotal = (float)$cartValidation['subtotal'];
        $discountEligibleBase = (float)$cartValidation['discount_eligible_base'];
        $selectedDiscountTypes = $cartValidation['discount_types'];
        $discountIdName = (string)$cartValidation['discount_id_name'];
        $discountIdNumber = (string)$cartValidation['discount_id_number'];
        $lineData = $cartValidation['line_data'];

        $discountType = isset($selectedDiscountTypes['pwd'])
            ? 'pwd'
            : (isset($selectedDiscountTypes['senior']) ? 'senior' : 'none');

        $discountRate = $discountType === 'none' ? 0.00 : (float)$discountRates[$discountType];
        $discountAmount = round($discountEligibleBase * ($discountRate / 100), 2);
        $totalAmount = round(max(0, $subtotal - $discountAmount), 2);

        try {

            /* One GCash payment can only pay for one order. */
            if ($gcashReference !== null) {
                $duplicateRefStmt = $pdo->prepare("
                    SELECT o.order_number
                    FROM payments p
                    INNER JOIN orders o ON o.id = p.order_id
                    WHERE p.gcash_reference = ?
                    LIMIT 1
                ");
                $duplicateRefStmt->execute([$gcashReference]);
                $duplicateOrder = $duplicateRefStmt->fetchColumn();

                if ($duplicateOrder !== false) {
                    $pdo->rollBack();
                    walkinRedirect(walkinBackUrl(
                        $returnCategory,
                        'error=' . urlencode('This GCash reference number was already used for order ' . $duplicateOrder . '. Check the number and try again.') . '&cart=1'
                    ));
                }
            }

            /* Walk-ins are confirmed face to face at the counter, so they skip Order Queue. */
            $initialStatus = 'confirmed';
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
             * Walk-in payments are received at the counter, so they are
             * recorded as verified. payments.verified_by points to admins.id,
             * so the Staff member is saved in verified_by_staff_id (users.id).
             */
            $pdo->prepare("
                INSERT INTO payments
                (order_id, payment_method, amount, proof_image, gcash_reference, is_verified, verified_at, verified_by, verified_by_staff_id)
                VALUES (?, ?, ?, NULL, ?, 1, NOW(), NULL, ?)
            ")->execute([$orderId, $paymentMethod, $totalAmount, $gcashReference, $staff_id]);

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
                'gcash_reference' => $gcashReference,
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
             * Go straight to the Confirmed tab. index.php shows the
             * walk-in confirmation box with the Print Receipt button.
             */
            walkinRedirect(
                'index.php?status=confirmed&order_id='
                . $orderId
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

/* Bundle products: cups + flavor choices come from menu_bundle_options (same as the customer menu). */
$productBundles = [];
if (!empty($products)) {
    try {
        $bundleCfgStmt = $pdo->prepare("
            SELECT product_id, size, cups, category_ids
            FROM menu_bundle_options
            WHERE product_id IN ($placeholders)
        ");
        $bundleCfgStmt->execute($productIds);
        $bundleCfgRows = $bundleCfgStmt->fetchAll(PDO::FETCH_ASSOC);

        $bundleCategoryIds = [];
        foreach ($bundleCfgRows as $cfgRow) {
            foreach (explode(',', (string)$cfgRow['category_ids']) as $catId) {
                $catId = (int)trim($catId);
                if ($catId > 0) { $bundleCategoryIds[$catId] = true; }
            }
        }

        $flavorsByCategory = [];
        if ($bundleCategoryIds) {
            $catIds = array_keys($bundleCategoryIds);
            $catPlaceholders = implode(',', array_fill(0, count($catIds), '?'));
            $flavorStmt = $pdo->prepare("
                SELECT p.id, p.name, p.category_id, c.name AS category
                FROM products p
                INNER JOIN categories c ON c.id = p.category_id
                WHERE p.category_id IN ($catPlaceholders)
                  AND p.is_available = 1
                  AND p.is_archived = 0
                  AND (p.price > 0 OR p.regular_price > 0 OR p.grande_price > 0)
                ORDER BY c.name ASC, p.name ASC
            ");
            $flavorStmt->execute($catIds);
            foreach ($flavorStmt->fetchAll(PDO::FETCH_ASSOC) as $flavorRow) {
                $flavorsByCategory[(int)$flavorRow['category_id']][] = [
                    'id' => (int)$flavorRow['id'],
                    'name' => (string)$flavorRow['name'],
                    'category' => (string)$flavorRow['category'],
                ];
            }
        }

        foreach ($bundleCfgRows as $cfgRow) {
            $flavorList = [];
            foreach (explode(',', (string)$cfgRow['category_ids']) as $catId) {
                $catId = (int)trim($catId);
                foreach ($flavorsByCategory[$catId] ?? [] as $flavor) {
                    $flavorList[] = $flavor;
                }
            }
            if ((int)$cfgRow['cups'] > 0 && $flavorList) {
                $productBundles[(int)$cfgRow['product_id']][(string)$cfgRow['size']] = [
                    'cups' => (int)$cfgRow['cups'],
                    'flavors' => $flavorList,
                ];
            }
        }
    } catch (Throwable $e) {
        error_log('Walk-in bundle options error: ' . $e->getMessage());
        $productBundles = [];
    }
}

/* AJAX category request: return only the products column. */
if ($isAjaxCategory) {
    walkinRenderProducts($products, $productAddons, $category, $productBundles);
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


$isPartial = (($_GET['partial'] ?? '') === '1');

if (!$isPartial) {
    require_once '../includes/header.php';
    require_once 'sidebar.php';
    require_once 'navbar.php';
}
?>

<style>
body { background: #F8F4EF; }

.walkin-page { min-height: calc(100vh - 70px); padding: 26px 24px 30px; }
/* Staff layout reserves the same desktop sidebar space as the staff dashboard. */
@media (min-width: 992px) { .staff-main.walkin-page { width: calc(100% - 260px); margin-left: 260px; } }
.walkin-order-layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(310px, 350px);
    gap: 16px;
    align-items: start;
}
.walkin-menu-area { min-width: 0; }
.walkin-cart-panel {
    position: sticky;
    top: 16px;
    display: flex;
    flex-direction: column;
    width: 100%;
    min-width: 0;
    height: calc(100vh - 205px);
    min-height: 420px;
    max-height: calc(100vh - 130px);
    background: #FFFFFF;
    border: 1px solid #E2D7CE;
    border-radius: 18px;
    box-shadow: 0 6px 24px rgba(74, 53, 37, .08);
    overflow: hidden;
    z-index: 2;
}
.walkin-cart-content { display: flex; flex: 1 1 auto; flex-direction: column; min-height: 0; overflow: hidden; }
.walkin-cart-body { min-height: 0; }

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
#walkinProductsColumn { transition: opacity .16s ease; }
#walkinProductsColumn.is-loading { opacity: .55; }
#walkinProductsColumn[aria-busy="true"] { cursor: progress; }
.walkin-section-heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 16px; }
.walkin-eyebrow { color: #9A7D62; font-size: .7rem; font-weight: 400; text-transform: uppercase; letter-spacing: .7px; }
.walkin-section-heading h2 { color: #2C221E; font-size: 1.15rem; font-weight: 400; margin: 2px 0 0; }
.walkin-item-count { padding: 6px 10px; border-radius: 50px; background: #F6EEE7; color: #6F4E37; font-size: .75rem; font-weight: 400; }

.walkin-product-card {
    position: relative;
    height: 100%;
    border: 1px solid #E6E0DA;
    border-radius: 18px;
    overflow: hidden;
    background: #FFFFFF;
    display: flex;
    flex-direction: column;
    box-shadow: 0 6px 16px rgba(74, 53, 37, .07);
    transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease;
}
.walkin-product-card.is-clickable:hover {
    transform: translateY(-6px);
    border-color: #CDBBAA;
    box-shadow: 0 10px 20px rgba(74, 53, 37, .11);
}
.walkin-product-card.is-unavailable:hover {
    transform: none;
    border-color: #E6E0DA;
    box-shadow: 0 6px 16px rgba(74, 53, 37, .07);
}

.walkin-product-image-wrap { position: relative; overflow: hidden; background: #FFFFFF; }
.walkin-product-image-wrap img { display: block; width: 100%; height: 130px; object-fit: contain; }
.walkin-product-card.is-unavailable img { opacity: .72; }

.walkin-out-badge {
    position: absolute; left: 8px; top: 8px; padding: 4px 8px; border-radius: 50px;
    background: #FBE7E7; border: 1px solid #C33131; color: #A12E2E; font-size: .62rem; font-weight: 400;
}

.walkin-product-body {
    padding: 11px;
    display: flex;
    flex-direction: column;
    flex: 1;
    background: #F7EFE7;
    border-top: 1px solid #E8D9CA;
    transition: background-color .2s ease;
}
.walkin-product-card.is-clickable:hover .walkin-product-body,
.walkin-product-card.is-clickable:focus-visible .walkin-product-body {
    background: #F1E5D8;
}
.walkin-product-name { color: #2C221E; font-size: .86rem; font-weight: 400; line-height: 1.25; min-height: 34px; }
.walkin-product-price { color: #6F4E37; font-weight: 400; font-size: .92rem; margin: 6px 0 10px; }

.walkin-bundle-picks { display: grid; gap: 8px; }
.walkin-bundle-pick { display: grid; grid-template-columns: 54px minmax(0, 1fr); gap: 8px; align-items: center; }
.walkin-bundle-pick span { font-size: .78rem; font-weight: 700; color: #6F4E37; }
.walkin-product-card.is-clickable { cursor: pointer; }
.walkin-product-card.is-clickable:focus-visible {
    outline: 3px solid #6F4E37;
    outline-offset: 3px;
    box-shadow: 0 0 0 4px rgba(111, 78, 55, .14);
}
.walkin-product-card.is-clickable:active { transform: scale(.99); }

.walkin-empty-state { padding: 56px 20px; text-align: center; color: #7B6D62; }
.walkin-empty-state i { font-size: 2.8rem; color: #B8A08A; }
.walkin-empty-state h5 { color: #4A3525; font-weight: 400; margin: 12px 0 5px; }
.walkin-empty-state p { margin: 0; font-size: .82rem; }

/* ---------- Persistent current-order panel ---------- */
.modal-backdrop { z-index: 1050; }
.walkin-modal { z-index: 1055; }

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
/* Keep desktop notifications clear of the persistent current-order panel. */
@media (min-width: 992px) {
    .walkin-toast { right: 380px; width: min(340px, calc(100vw - 410px)); }
}

/* ---------- Modals ---------- */
.walkin-modal .modal-content { border: 1px solid #DCCEC3; border-radius: 16px; overflow: hidden; }
.walkin-modal .modal-header { background: #FDF8F2; border-bottom: 1px solid #E5DAD1; }
.walkin-modal .modal-title { color: #2C221E; font-size: 1rem; font-weight: 400; }
.walkin-modal .modal-footer { background: #FDF8F2; border-top: 1px solid #E5DAD1; }

/* Keep the product customization dialog on the left and leave the order panel visible. */
@media (min-width: 992px) {
    body.walkin-customizing #walkinProductModal .modal-dialog {
        width: min(500px, calc(100vw - 410px));
        max-width: 500px;
        margin-left: calc((100vw - 390px - min(500px, calc(100vw - 410px))) / 2);
        margin-right: auto;
    }
    body.walkin-customizing .modal-backdrop {
        width: calc(100vw - 390px);
        right: auto;
    }
}

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

/* Keep all five sugar levels on one line, even on narrow phone screens. */
.walkin-sugar-grid {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 6px;
}
.walkin-sugar-grid .walkin-radio-card label {
    min-height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 8px 2px;
    white-space: nowrap;
    font-size: .76rem;
}

/* Add-ons are tucked away until the cashier chooses to expand them. */
.walkin-addon-toggle {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    width: 100%;
    min-height: 58px;
    padding: 10px 12px;
    border: 1px solid #D8C9BD;
    border-radius: 11px;
    background: #FDF8F2;
    color: #4A3525;
    text-align: left;
    cursor: pointer;
}
.walkin-addon-toggle:hover:not(:disabled) { background: #F5EDE6; border-color: #B8A08A; }
.walkin-addon-toggle:focus-visible { outline: 2px solid #6F4E37; outline-offset: 2px; }
.walkin-addon-toggle:disabled { opacity: .8; cursor: not-allowed; }
.walkin-addon-toggle-copy { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
.walkin-addon-toggle .section-label { margin: 0; font-size: .8rem; color: #4A3525; }
.walkin-addon-summary { color: #8A7A6C; font-size: .72rem; line-height: 1.3; overflow-wrap: anywhere; }
.walkin-addon-toggle-end { display: inline-flex; align-items: center; gap: 9px; flex: 0 0 auto; color: #6F4E37; font-size: .76rem; font-weight: 600; }
.walkin-addon-chevron { transition: transform .18s ease; }
.walkin-addon-toggle[aria-expanded="true"] .walkin-addon-chevron { transform: rotate(180deg); }
.walkin-addon-panel { padding-top: 10px; }
.walkin-addon-panel[hidden] { display: none !important; }
.walkin-addon-grid { display: grid; gap: 8px; grid-template-columns: repeat(auto-fit, minmax(165px, 1fr)); }
.walkin-addon-grid .walkin-radio-card label { text-align: left; display: flex; justify-content: space-between; gap: 8px; min-height: 42px; align-items: center; }
.walkin-addon-grid .walkin-radio-card label span { color: #8A7A6C; font-weight: 600; white-space: nowrap; }

/* The customization dialog keeps its header and action area still while options scroll. */
#walkinProductModal .modal-content { display: flex; flex-direction: column; max-height: calc(100vh - 2rem); max-height: calc(100dvh - 2rem); }
#walkinProductModal .modal-header,
#walkinProductModal .modal-footer { flex: 0 0 auto; }
#walkinProductModal form { min-height: 0; overflow: hidden; flex: 1 1 auto; }
#walkinProductModal .modal-body { min-height: 0; flex: 1 1 auto; overflow-y: auto; overscroll-behavior: contain; -webkit-overflow-scrolling: touch; }

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

/* Compact cart control is only shown on tablet/mobile. Desktop keeps the full right-side panel. */
.walkin-mobile-cart-toggle { display: none; }
.walkin-cart-heading { min-width: 0; }
.walkin-mobile-cart-count {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 24px; height: 24px; padding: 0 7px; margin-left: 5px;
    border-radius: 50px; background: #EFE2D5; color: #4A3525; font-size: .72rem; font-weight: 700;
}
.walkin-mobile-cart-total { color: #4A3525; font-size: .98rem; font-weight: 800; white-space: nowrap; }

@media (min-width: 576px) and (max-width: 991.98px) {
    #walkinProductModal .modal-dialog {
        width: min(680px, calc(100vw - 32px));
        max-width: none;
        height: calc(100vh - 32px);
        height: calc(100dvh - 32px);
        margin: 16px auto;
    }
    #walkinProductModal .modal-content { height: 100%; max-height: 100%; }
}
@media (max-width: 991.98px) {
    .staff-main.walkin-page { width: 100%; margin-left: 0; }
    .walkin-page { padding: 18px 12px calc(120px + env(safe-area-inset-bottom)); }
    .walkin-order-layout { grid-template-columns: minmax(0, 1fr); }

    /* Never put the cart after the products on small screens. Dock it above the mobile nav. */
    .walkin-cart-panel {
        position: fixed;
        left: 12px;
        right: 12px;
        bottom: calc(68px + env(safe-area-inset-bottom));
        top: auto;
        width: auto;
        height: 76px;
        min-height: 0;
        max-height: calc(100dvh - 110px - env(safe-area-inset-bottom));
        border-radius: 16px;
        z-index: 1040;
        box-shadow: 0 10px 32px rgba(44, 34, 30, .22);
        transition: height .22s ease, max-height .22s ease;
    }
    .walkin-cart-panel .walkin-cart-head {
        display: block;
        padding: 0;
        border-bottom: 0;
    }
    .walkin-cart-heading { display: none; }
    .walkin-mobile-cart-toggle {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        width: 100%;
        min-height: 75px;
        padding: 12px 15px;
        border: 0;
        background: #FDF8F2;
        color: #2C221E;
        text-align: left;
        cursor: pointer;
    }
    .walkin-mobile-cart-toggle:focus-visible { outline: 3px solid #6F4E37; outline-offset: -4px; }
    .walkin-mobile-cart-info { display: flex; min-width: 0; flex-direction: column; gap: 4px; }
    .walkin-mobile-cart-title { display: flex; align-items: center; flex-wrap: wrap; color: #2C221E; font-size: .88rem; font-weight: 700; line-height: 1.2; }
    .walkin-mobile-cart-hint { color: #8C7B6E; font-size: .7rem; line-height: 1.25; }
    .walkin-mobile-cart-action { display: inline-flex; align-items: center; gap: 10px; flex: 0 0 auto; }
    .walkin-mobile-cart-total { font-size: .95rem; }
    .walkin-mobile-cart-chevron { color: #6F4E37; font-size: .95rem; transition: transform .2s ease; }
    .walkin-cart-content { display: none; height: 0; min-height: 0; }
    .walkin-cart-panel.is-expanded {
        height: min(72vh, 620px);
        height: min(72dvh, 620px);
        max-height: calc(100dvh - 110px - env(safe-area-inset-bottom));
    }
    .walkin-cart-panel.is-expanded .walkin-mobile-cart-toggle { border-bottom: 1px solid #E5DAD1; }
    .walkin-cart-panel.is-expanded .walkin-mobile-cart-chevron { transform: rotate(180deg); }
    .walkin-cart-panel.is-expanded .walkin-cart-content {
        display: flex;
        flex-direction: column;
        height: calc(100% - 76px);
        min-height: 0;
        overflow: hidden;
    }
    .walkin-cart-body { flex: 1 1 auto; min-height: 0; max-height: none; overflow-y: auto; padding: 12px 14px; }
    .walkin-cart-empty { padding: 24px 12px; }
    .walkin-cart-footer { flex: 0 0 auto; padding: 12px 14px 14px; }
    .walkin-total-row { margin-bottom: 4px; }
    .walkin-total-row.grand { margin-top: 6px; padding-top: 7px; }
    .walkin-place-btn, .walkin-clear-btn { min-height: 40px; }
    .walkin-place-btn { margin-top: 8px; }
    .walkin-clear-btn { margin-top: 6px; }
    .walkin-toast { right: 12px; top: 72px; width: min(340px, calc(100vw - 24px)); }
}
@media (max-width: 575.98px) {
    #walkinProductModal .modal-dialog {
        width: calc(100vw - 16px);
        max-width: none;
        height: calc(100vh - 16px);
        height: calc(100dvh - 16px);
        margin: 8px;
    }
    #walkinProductModal .modal-content {
        height: 100%;
        max-height: 100%;
        border-radius: 14px;
    }
    #walkinProductModal .modal-header { padding: 12px 14px; }
    #walkinProductModal .modal-body { padding: 12px 14px; }
    #walkinProductModal .modal-footer {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        padding: 10px 12px calc(10px + env(safe-area-inset-bottom));
    }
    #walkinProductModal .modal-footer .walkin-modal-total {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        width: 100%;
        margin: 0;
    }
    #walkinProductModal .modal-footer .walkin-modal-total small { margin: 0; font-size: .74rem; }
    #walkinProductModal .modal-footer .walkin-place-btn {
        flex: 1 1 100%;
        width: 100%;
        min-height: 44px;
        padding: 0 16px;
    }
    .walkin-sugar-grid { gap: 5px; }
    .walkin-sugar-grid .walkin-radio-card label { min-height: 40px; padding: 7px 1px; font-size: .7rem; }
    .walkin-addon-toggle { min-height: 54px; padding: 9px 10px; }
    .walkin-addon-grid { grid-template-columns: minmax(0, 1fr); }
    .walkin-addon-grid .walkin-radio-card label { min-height: 42px; padding: 9px 10px; }
    .walkin-header h1 { font-size: 1.3rem; }
    .walkin-product-content { padding: 14px 12px; }
    .walkin-product-image-wrap img { height: 105px; }
    .walkin-product-name { font-size: .78rem; min-height: 30px; }
    .walkin-product-price { font-size: .82rem; margin: 5px 0 8px; }
    .walkin-receipt-frame { height: 400px; }
    .walkin-cart-panel { left: 8px; right: 8px; bottom: calc(64px + env(safe-area-inset-bottom)); }
    .walkin-mobile-cart-toggle { padding: 10px 12px; }
    .walkin-mobile-cart-title { font-size: .82rem; }
    .walkin-mobile-cart-total { font-size: .88rem; }
    .walkin-mobile-cart-action { gap: 7px; }
    .walkin-cart-panel.is-expanded { height: min(76vh, 620px); height: min(76dvh, 620px); }
}
</style>

<main class="staff-main walkin-page">
    <div class="walkin-header">
        <h1><i class="bi bi-cart-plus me-1"></i> Walk-in Order</h1>
        
    </div>

    <div class="walkin-order-layout">
        <div class="walkin-menu-area">
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
            <?php walkinRenderProducts($products, $productAddons, $category, $productBundles); ?>
        </div>
    </section>
        </div>

<!-- CURRENT WALK-IN ORDER: ALWAYS VISIBLE -->
<aside class="walkin-cart-panel" id="walkinCartPanel" aria-labelledby="walkinCartTitle">
    <div class="walkin-cart-head">
        <div class="walkin-cart-heading">
            <h3 id="walkinCartTitle"><i class="bi bi-receipt me-1"></i> Current Walk-in Order</h3>
            <p>Separate from the customer's online cart.</p>
        </div>
        <button type="button" class="walkin-mobile-cart-toggle" id="walkinMobileCartToggle" aria-expanded="false" aria-controls="walkinCartContent" aria-label="Expand current walk-in order">
            <span class="walkin-mobile-cart-info">
                <span class="walkin-mobile-cart-title"><i class="bi bi-receipt me-2" aria-hidden="true"></i>Current Order <span class="walkin-mobile-cart-count" id="walkinMobileCartCount"><?= (int)$walkinCartCount ?> item<?= $walkinCartCount === 1 ? '' : 's' ?></span></span>
                <span class="walkin-mobile-cart-hint" id="walkinMobileCartHint">Tap to review items and checkout</span>
            </span>
            <span class="walkin-mobile-cart-action">
                <strong class="walkin-mobile-cart-total" id="walkinMobileCartTotal">₱<?= number_format($walkinTotal, 2) ?></strong>
                <i class="bi bi-chevron-up walkin-mobile-cart-chevron" id="walkinMobileCartChevron" aria-hidden="true"></i>
            </span>
        </button>
    </div>

    <div class="walkin-cart-content" id="walkinCartContent">
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
                    if (!empty($item['bundle_picks']) && is_array($item['bundle_picks'])) {
                        $cupParts = [];
                        foreach ($item['bundle_picks'] as $cupIndex => $cupPick) {
                            $cupParts[] = 'Cup ' . ($cupIndex + 1) . ': ' . trim((string)($cupPick['name'] ?? ''));
                        }
                        $metaParts[] = implode(' · ', $cupParts);
                    }
                    if (!empty($item['addons']) && is_array($item['addons'])) { $metaParts[] = 'Add-ons: ' . implode(', ', $item['addons']); }
                    if (($item['discount_type'] ?? 'none') !== 'none') { $metaParts[] = strtoupper((string)$item['discount_type']) . ' discount'; if (!empty($item['discount_id_number'])) { $metaParts[] = 'ID: ' . $item['discount_id_name'] . ' (' . $item['discount_id_number'] . ')'; } }
                    ?>
                    <div class="walkin-cart-item">
                        <div class="d-flex align-items-start gap-2">
                            <div class="walkin-cart-item-image">
                                <img src="<?= htmlspecialchars(
                                    (empty($item['image']) || $item['image'] === 'default-product.png' || $item['image'] === 'default.jpg')
                                        ? '../assets/image/default-product.png'
                                        : '../assets/uploads/products/' . $item['image']
                                ) ?>" alt="<?= htmlspecialchars($item['name']) ?>">
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
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($staff_csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="cart_key" value="<?= htmlspecialchars((string)$cartKey) ?>">
                                <input type="hidden" name="category" value="<?= htmlspecialchars($category) ?>">
                                <input type="hidden" name="quantity" value="<?= max(1, (int)$item['quantity'] - 1) ?>">
                                <button type="submit" class="walkin-qty-btn" aria-label="Decrease quantity" <?= (int)$item['quantity'] <= 1 ? 'disabled' : '' ?>>−</button>
                            </form>

                            <span class="walkin-qty-value"><?= (int)$item['quantity'] ?></span>

                            <form method="POST" class="walkin-qty-form">
                                <input type="hidden" name="action" value="set_quantity">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($staff_csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="cart_key" value="<?= htmlspecialchars((string)$cartKey) ?>">
                                <input type="hidden" name="category" value="<?= htmlspecialchars($category) ?>">
                                <input type="hidden" name="quantity" value="<?= min(50, (int)$item['quantity'] + 1) ?>">
                                <button type="submit" class="walkin-qty-btn" aria-label="Increase quantity">+</button>
                            </form>

                            <form method="POST" class="walkin-remove-form">
                                <input type="hidden" name="action" value="remove_item">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($staff_csrf_token, ENT_QUOTES, 'UTF-8') ?>">
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
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($staff_csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="category" value="<?= htmlspecialchars($category) ?>">
                <button type="submit" class="btn walkin-clear-btn" <?= empty($_SESSION['walkin_cart']) ? 'disabled' : '' ?> onclick="return confirm('Clear the current walk-in order?');">
                    Clear Order
                </button>
            </form>
        </div>
    </div>
</aside>

    </div>
</main>

<!-- PRODUCT CUSTOMIZATION MODAL -->
<div class="modal fade walkin-modal" id="walkinProductModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <div class="walkin-eyebrow">Customize Item</div>
                    <h5 class="modal-title" id="walkinProductModalTitle">Product</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cancel customization and close"></button>
            </div>

            <form method="POST" id="walkinAddForm">
                <input type="hidden" name="action" value="add_item">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($staff_csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="product_id" id="walkinProductId">
                <input type="hidden" name="category" value="<?= htmlspecialchars($category) ?>">

                <div class="modal-body">
                    <div class="mb-3">
                        <span class="section-label">Size</span>
                        <div class="walkin-radio-grid" id="walkinSizeOptions"></div>
                    </div>

                    <div class="mb-3" id="walkinBundleSection" hidden>
                        <span class="section-label">Flavor per cup</span>
                        <div id="walkinBundlePicks" class="walkin-bundle-picks"></div>
                    </div>

                    <div class="mb-3">
                        <span class="section-label">Sugar Level</span>
                        <div class="walkin-sugar-grid">
                            <?php foreach (['0%', '25%', '50%', '75%', '100%'] as $sugar): ?>
                                <?php $sid = 'walkinSugar' . str_replace('%', '', $sugar); ?>
                                <div class="walkin-radio-card">
                                    <input type="radio" name="sugar_level" value="<?= htmlspecialchars($sugar) ?>" id="<?= $sid ?>" <?= $sugar === '50%' ? 'checked' : '' ?>>
                                    <label for="<?= $sid ?>"><?= htmlspecialchars($sugar) ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="mb-3 walkin-addon-section">
                        <button type="button" class="walkin-addon-toggle" id="walkinAddonToggle" aria-expanded="false" aria-controls="walkinAddonPanel">
                            <span class="walkin-addon-toggle-copy">
                                <span class="section-label">Add-ons (Optional)</span>
                                <span class="walkin-addon-summary" id="walkinAddonSummary">None selected</span>
                            </span>
                            <span class="walkin-addon-toggle-end">
                                <span id="walkinAddonActionLabel">Choose</span>
                                <i class="bi bi-chevron-down walkin-addon-chevron" id="walkinAddonChevron" aria-hidden="true"></i>
                            </span>
                        </button>
                        <div id="walkinAddonPanel" class="walkin-addon-panel" hidden>
                            <div id="walkinAddonOptions" class="walkin-addon-grid"></div>
                            <div id="walkinNoAddons" class="walkin-discount-note">No add-ons are available for this product.</div>
                        </div>
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

                    <div class="mb-1" id="walkinQtyBlock">
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
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($staff_csrf_token, ENT_QUOTES, 'UTF-8') ?>">
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

                    <div class="mb-3" id="walkinGcashReferenceWrap" hidden>
                        <label for="walkinGcashReference" class="section-label">GCash Reference No.</label>
                        <input
                            type="text"
                            class="form-control"
                            name="gcash_reference"
                            id="walkinGcashReference"
                            inputmode="numeric"
                            autocomplete="off"
                            maxlength="20"
                            placeholder="13-digit reference number"
                            pattern="[0-9\s\-]*"
                            disabled
                        >
                        <div class="walkin-discount-note" id="walkinGcashReferenceHelp">
                            Copy it from the shop's GCash payment notification or the customer's GCash receipt.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="walkinNotes" class="section-label">Notes <span class="fw-normal text-muted">(optional)</span></label>
                        <textarea class="form-control" name="notes" id="walkinNotes" rows="2" maxlength="1000" placeholder="Special instructions or counter notes..."></textarea>
                    </div>

                    <div class="walkin-success-box">
                        <div class="walkin-success-title">Total to collect: ₱<?= number_format($walkinTotal, 2) ?></div>
                        <div class="walkin-success-meta">The order goes straight to Confirmed, with a Print Receipt button right after you confirm.</div>
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

<?php if (isset($_GET['error'])): ?>
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
/* walk-in-order build: v5. Add ?debug=1 to the URL to show an on-screen diagnostic box. */
(function () {
    if (new URLSearchParams(window.location.search).get('debug') !== '1') return;
    const box = document.createElement('pre');
    box.style.cssText = 'position:fixed;left:8px;bottom:8px;max-width:560px;max-height:45vh;overflow:auto;margin:0;padding:10px 12px;' +
        'background:#111;color:#7CFC7C;font:12px/1.4 monospace;z-index:99999;border-radius:8px;white-space:pre-wrap;pointer-events:auto';
    function log(t) { box.textContent += t + '\n'; box.scrollTop = box.scrollHeight; }
    document.addEventListener('DOMContentLoaded', function () { document.body.appendChild(box); log('DEBUG v5 - page loaded'); });
    window.addEventListener('error', function (e) { log('JS ERROR: ' + e.message + ' @ ' + String(e.filename || '').split('/').pop() + ':' + e.lineno); });
    window.addEventListener('unhandledrejection', function (e) { log('PROMISE ERROR: ' + (e.reason && e.reason.message || e.reason)); });
    window.addEventListener('click', function (e) {
        const t = e.target;
        log('click -> <' + t.tagName.toLowerCase() + '> ' + String(t.className && t.className.baseVal !== undefined ? t.className.baseVal : t.className).slice(0, 50) + (t.id ? ' #' + t.id : ''));
    }, true);
    setTimeout(function () {
        log('bootstrap: ' + (window.bootstrap ? 'YES v' + (bootstrap.Modal && bootstrap.Modal.VERSION) : 'NO') +
            ' | fallback: ' + !!window.__walkinShim + ' | init ran: ' + !!window.__walkinInitDone);
        log('jQuery: ' + (window.jQuery ? 'v' + jQuery.fn.jquery : 'no') + ' | bootstrap4 plugin: ' + !!(window.jQuery && jQuery.fn.modal && !window.bootstrap));
        ['walkinProductModal', 'walkinCartPanel'].forEach(function (id) {
            const el = document.getElementById(id);
            log(id + ': ' + (el ? 'found, parent=' + el.parentNode.tagName : 'MISSING'));
        });
        const b = document.querySelector('.walkin-product-card.is-clickable[data-product-id]');
        if (b) {
            const r = b.getBoundingClientRect();
            const top = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
            log('Product card covered by: ' + (top === b || b.contains(top) ? 'nothing (OK)' : '<' + top.tagName.toLowerCase() + '> ' + top.className));
        } else { log('No clickable available product card found'); }
    }, 2500);
})();
</script>
<?php
/* Look for a local Bootstrap JS inside ../assets so the page still works offline (no CDN needed). */
$walkinLocalBootstrap = [];
$walkinBsCache = $_SESSION['walkin_bs_cache'] ?? null;
if (is_array($walkinBsCache) && (int)($walkinBsCache['t'] ?? 0) > time() - 3600 && is_array($walkinBsCache['list'] ?? null)) {
    $walkinLocalBootstrap = $walkinBsCache['list'];
} else {
try {
    $assetsRoot = realpath(__DIR__ . '/../assets');
    if ($assetsRoot) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($assetsRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        $it->setMaxDepth(4);
        foreach ($it as $f) {
            $name = $f->getFilename();
            if (preg_match('/^bootstrap.*\.js$/i', $name) && !preg_match('/icons|\.map$/i', $name)) {
                $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($assetsRoot)));
                $walkinLocalBootstrap[] = ['bundle' => stripos($name, 'bundle') !== false, 'url' => '../assets' . $rel];
            }
        }
        usort($walkinLocalBootstrap, static fn($a, $b) => (int)$b['bundle'] <=> (int)$a['bundle']);
        $walkinLocalBootstrap = array_column($walkinLocalBootstrap, 'url');
    }
} catch (Throwable $e) {
    $walkinLocalBootstrap = [];
}
$_SESSION['walkin_bs_cache'] = ['t' => time(), 'list' => $walkinLocalBootstrap];
}
?>
<script>
function walkinInit() {
    /* Keep modal overlays and toast notifications directly under <body>. The cart stays inside the page layout. */
    ['walkinProductModal', 'walkinCheckoutModal'].forEach(function (id) {
        const el = document.getElementById(id);
        if (el && el.parentNode !== document.body) document.body.appendChild(el);
    });
    document.querySelectorAll('.walkin-toast').forEach(function (el) {
        if (el.parentNode !== document.body) document.body.appendChild(el);
    });

    /* Coming back with the Back button restores the page from cache with submit buttons still disabled. */
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return;
        document.querySelectorAll('#walkinAddForm button[type="submit"], #walkinCheckoutForm button[type="submit"]').forEach(function (b) { b.disabled = false; });
    });

    const productsColumn = document.getElementById('walkinProductsColumn');
    const categoriesEl = document.getElementById('walkinCategories');
    const productModal = document.getElementById('walkinProductModal');
    const addForm = document.getElementById('walkinAddForm');
    const productModalTitle = document.getElementById('walkinProductModalTitle');
    const productIdInput = document.getElementById('walkinProductId');
    const sizeOptions = document.getElementById('walkinSizeOptions');
    const addonOptions = document.getElementById('walkinAddonOptions');
    const noAddons = document.getElementById('walkinNoAddons');
    const addonToggle = document.getElementById('walkinAddonToggle');
    const addonPanel = document.getElementById('walkinAddonPanel');
    const addonSummary = document.getElementById('walkinAddonSummary');
    const addonActionLabel = document.getElementById('walkinAddonActionLabel');
    const addonChevron = document.getElementById('walkinAddonChevron');
    let addonsAvailable = false;
    const qtyInput = document.getElementById('walkinQuantity');
    const modalTotal = document.getElementById('walkinModalTotal');

    const cartEl = document.getElementById('walkinCartPanel');
    const checkoutEl = document.getElementById('walkinCheckoutModal');
    const placeBtn = document.getElementById('walkinPlaceBtn');
    const mobileCartToggle = document.getElementById('walkinMobileCartToggle');
    const mobileCartCount = document.getElementById('walkinMobileCartCount');
    const mobileCartTotal = document.getElementById('walkinMobileCartTotal');
    const mobileCartHint = document.getElementById('walkinMobileCartHint');

    function setMobileCartExpanded(expanded) {
        if (!cartEl || !mobileCartToggle) return;
        cartEl.classList.toggle('is-expanded', !!expanded);
        mobileCartToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        mobileCartToggle.setAttribute('aria-label', expanded ? 'Collapse current walk-in order' : 'Expand current walk-in order');
        if (mobileCartHint) mobileCartHint.textContent = expanded ? 'Tap to minimize the order' : 'Tap to review items and checkout';
    }

    function updateMobileCartSummary() {
        if (!cartEl) return;
        const count = Array.from(cartEl.querySelectorAll('.walkin-qty-value')).reduce(function (sum, el) {
            const value = parseInt(el.textContent, 10);
            return sum + (Number.isFinite(value) ? value : 0);
        }, 0);
        const totalEl = cartEl.querySelector('.walkin-total-row.grand strong');
        if (mobileCartCount) mobileCartCount.textContent = count + ' item' + (count === 1 ? '' : 's');
        if (mobileCartTotal) mobileCartTotal.textContent = totalEl ? totalEl.textContent.trim() : '₱0.00';
    }

    if (mobileCartToggle) {
        mobileCartToggle.addEventListener('click', function () {
            setMobileCartExpanded(!cartEl.classList.contains('is-expanded'));
        });
    }

    let baseFallbackPrice = 0;

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    /* ---------- live item total inside the product modal ---------- */
    function clampQty() {
        let q = parseInt(qtyInput.value, 10);
        if (isNaN(q) || q < 1) q = 1;
        if (q > 50) q = 50;
        return q;
    }

    function setAddonPanelExpanded(expanded) {
        if (!addonToggle || !addonPanel || !addonsAvailable) expanded = false;
        if (addonPanel) addonPanel.hidden = !expanded;
        if (addonToggle) addonToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        if (addonActionLabel) addonActionLabel.textContent = !addonsAvailable ? '' : (expanded ? 'Done' : 'Choose');
        if (addonChevron) addonChevron.classList.toggle('is-expanded', !!expanded);
    }

    function updateAddonSummary(selectedAddons, selectedAddonTotal) {
        if (!addonSummary) return;
        if (!addonsAvailable) {
            addonSummary.textContent = 'No add-ons available';
        } else if (!selectedAddons) {
            addonSummary.textContent = 'None selected';
        } else {
            addonSummary.textContent = selectedAddons + ' selected · +₱' + selectedAddonTotal.toFixed(2);
        }
    }

    function updateModalTotal() {
        const sizeInput = addForm.querySelector('input[name="size"]:checked');
        const base = sizeInput ? Number(sizeInput.dataset.price || 0) : baseFallbackPrice;
        let addons = 0;
        let selectedAddonCount = 0;
        addForm.querySelectorAll('input[name="addons[]"]:checked').forEach(function (c) {
            addons += Number(c.dataset.price || 0);
            selectedAddonCount++;
        });
        updateAddonSummary(selectedAddonCount, addons);
        modalTotal.textContent = '₱' + ((base + addons) * clampQty()).toFixed(2);
    }

    function renderSizeOptions(button) {
        const price = Number(button.dataset.price || 0);
        const regular = Number(button.dataset.regularPrice || 0);
        const grande = Number(button.dataset.grandePrice || 0);
        const options = [];

        if (regular > 0) options.push({ value: 'Regular', price: regular });
        if (grande > 0) options.push({ value: 'Grande', price: grande });

        baseFallbackPrice = price;

        if (!options.length) {
            sizeOptions.innerHTML = `
                <div class="walkin-radio-card">
                    <input type="radio" name="size" value="" id="walkinSizeNone" data-price="${price}" checked>
                    <label for="walkinSizeNone">Standard · ₱${price.toFixed(2)}</label>
                </div>`;
            return;
        }

        sizeOptions.innerHTML = options.map(function (option, index) {
            const id = 'walkinSize_' + option.value.toLowerCase();
            return `
                <div class="walkin-radio-card">
                    <input type="radio" name="size" value="${escapeHtml(option.value)}" id="${id}" data-price="${option.price}" ${index === 0 ? 'checked' : ''}>
                    <label for="${id}">${escapeHtml(option.value)} · ₱${option.price.toFixed(2)}</label>
                </div>`;
        }).join('');
    }

    function renderAddons(button) {
        let addons = [];
        try { addons = JSON.parse(button.dataset.addons || '[]'); } catch (e) { addons = []; }

        addonsAvailable = Array.isArray(addons) && addons.length > 0;
        noAddons.style.display = addonsAvailable ? 'none' : 'block';
        addonOptions.innerHTML = addons.map(function (addon) {
            const id = 'walkinAddon_' + addon.id;
            const price = Number(addon.price || 0);
            return `
                <div class="walkin-radio-card">
                    <input type="checkbox" name="addons[]" value="${escapeHtml(addon.name)}" id="${id}" data-price="${price}">
                    <label for="${id}">${escapeHtml(addon.name)} <span>+₱${price.toFixed(2)}</span></label>
                </div>`;
        }).join('');

        if (addonToggle) addonToggle.disabled = !addonsAvailable;
        if (addonChevron) addonChevron.hidden = !addonsAvailable;
        setAddonPanelExpanded(false);
        updateAddonSummary(0, 0);
    }

    if (addonToggle) {
        addonToggle.addEventListener('click', function () {
            setAddonPanelExpanded(addonPanel ? addonPanel.hidden : false);
        });
    }

    /* ---------- Bundle flavor pickers ---------- */
    const bundleSection = document.getElementById('walkinBundleSection');
    const bundlePicksEl = document.getElementById('walkinBundlePicks');
    const qtyBlock = document.getElementById('walkinQtyBlock');
    let activeBundle = null;

    function renderBundlePicks() {
        if (!bundleSection || !bundlePicksEl) return;

        let config = null;
        if (activeBundle) {
            const sizeInput = addForm.querySelector('input[name="size"]:checked');
            const sizeValue = sizeInput ? sizeInput.value : '';
            config = activeBundle[sizeValue] || activeBundle[''] || null;
        }

        if (!config) {
            bundleSection.hidden = true;
            bundlePicksEl.innerHTML = '';
            if (qtyBlock) qtyBlock.hidden = false;
            return;
        }

        const previous = Array.from(bundlePicksEl.querySelectorAll('select')).map(function (sel) { return sel.value; });

        const groups = {};
        config.flavors.forEach(function (flavor) {
            (groups[flavor.category] = groups[flavor.category] || []).push(flavor);
        });
        const optionsHtml = '<option value="">Choose flavor</option>' + Object.keys(groups).map(function (cat) {
            return '<optgroup label="' + escapeHtml(cat) + '">' + groups[cat].map(function (flavor) {
                return '<option value="' + flavor.id + '">' + escapeHtml(flavor.name) + '</option>';
            }).join('') + '</optgroup>';
        }).join('');

        let html = '';
        for (let i = 0; i < config.cups; i++) {
            html += '<div class="walkin-bundle-pick"><span>Cup ' + (i + 1) + '</span>' +
                '<select name="bundle_picks[]" class="form-select form-select-sm" required>' + optionsHtml + '</select></div>';
        }
        bundlePicksEl.innerHTML = html;

        bundlePicksEl.querySelectorAll('select').forEach(function (sel, index) {
            if (previous[index]) sel.value = previous[index];
        });

        bundleSection.hidden = false;
        if (qtyInput) qtyInput.value = '1';
        if (qtyBlock) qtyBlock.hidden = true;
        updateModalTotal();
    }

    if (sizeOptions) {
        sizeOptions.addEventListener('change', renderBundlePicks);
    }

    /* Fill the modal from whichever product card opened it (including AJAX-loaded cards). */
    productModal.addEventListener('show.bs.modal', function (event) {
        document.body.classList.add('walkin-customizing');
        const button = event.relatedTarget;
        if (!button || !button.dataset.productId) return;

        productModalTitle.textContent = button.dataset.productName || 'Product';
        productIdInput.value = button.dataset.productId || '';
        renderSizeOptions(button);
        renderAddons(button);
        try { activeBundle = button.dataset.bundle ? JSON.parse(button.dataset.bundle) : null; } catch (e) { activeBundle = null; }
        if (bundlePicksEl) bundlePicksEl.innerHTML = '';
        qtyInput.value = '1';
        renderBundlePicks();
        document.getElementById('walkinSugar50').checked = true;
        document.getElementById('walkinDiscountNone').checked = true;
        resetDiscountFields();
        updateModalTotal();
    });

    addForm.addEventListener('change', updateModalTotal);

    /* ---------- PWD / Senior ID fields ---------- */
    const discountDetails = document.getElementById('walkinDiscountDetails');
    const discountIdName = document.getElementById('walkinDiscountIdName');
    const discountIdNumber = document.getElementById('walkinDiscountIdNumber');
    /* Details of the discount already used in the cart (one cardholder per order). */
    const activeDiscount = <?= json_encode($walkinActiveDiscount, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    function resetDiscountFields() {
        discountDetails.hidden = true;
        discountIdName.value = '';
        discountIdNumber.value = '';
        discountIdName.required = false;
        discountIdNumber.required = false;
        discountIdName.classList.remove('is-invalid');
        discountIdNumber.classList.remove('is-invalid');
    }

    function syncDiscountFields() {
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
    qtyInput.addEventListener('input', updateModalTotal);
    document.getElementById('walkinQtyMinus').addEventListener('click', function () {
        qtyInput.value = Math.max(1, clampQty() - 1); updateModalTotal();
    });
    document.getElementById('walkinQtyPlus').addEventListener('click', function () {
        qtyInput.value = Math.min(50, clampQty() + 1); updateModalTotal();
    });

    /* GCash reference number: shown and required only for GCash. */
    (function () {
        const form = document.getElementById('walkinCheckoutForm');
        const wrap = document.getElementById('walkinGcashReferenceWrap');
        const input = document.getElementById('walkinGcashReference');
        if (!form || !wrap || !input) return;

        function digitsOnly() {
            return input.value.replace(/[\s-]+/g, '');
        }

        function syncGcashField() {
            const isGcash = !!form.querySelector('input[name="payment_method"][value="gcash"]:checked');
            wrap.hidden = !isGcash;
            input.disabled = !isGcash;
            input.required = isGcash;
            if (!isGcash) {
                input.value = '';
                input.setCustomValidity('');
            }
        }

        function checkReference() {
            if (input.disabled) {
                input.setCustomValidity('');
                return;
            }
            input.setCustomValidity(
                /^\d{13}$/.test(digitsOnly())
                    ? ''
                    : 'Enter the full 13-digit GCash reference number (' + digitsOnly().replace(/\D/g, '').length + ' of 13 digits).'
            );
        }

        form.querySelectorAll('input[name="payment_method"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                syncGcashField();
                if (!input.disabled) input.focus();
            });
        });
        input.addEventListener('input', checkReference);
        syncGcashField();

        /* Runs before the double-submit guard below. */
        form.addEventListener('submit', function (event) {
            checkReference();
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopImmediatePropagation();
                form.reportValidity();
            }
        });
    })();

    /* Prevent double submits at checkout (double orders at the counter). */
    (function () {
        const form = document.getElementById('walkinCheckoutForm');
        if (!form) return;
        form.addEventListener('submit', function () {
            const btn = form.querySelector('button[type="submit"]');
            if (btn) setTimeout(function () { btn.disabled = true; }, 0);
        });
    })();

    /* ---------- persistent current order ---------- */
    /* Open customization by clicking a product card. The current order remains visible at the right. */
    const productModalInstance = bootstrap.Modal.getOrCreateInstance(productModal);

    productModal.addEventListener('hidden.bs.modal', function () {
        document.body.classList.remove('walkin-customizing');
    });

    function openProductCustomization(card) {
        if (!card || !card.matches('.walkin-product-card.is-clickable[data-product-id]')) return;
        productModalInstance.show(card);
    }

    window.addEventListener('click', function (e) {
        const productCard = e.target.closest('.walkin-product-card[data-product-id]');
        if (productCard && !productCard.classList.contains('is-unavailable')) {
            e.preventDefault();
            openProductCustomization(productCard);
        }
    }, true);

    /* Keyboard support for cards marked as buttons. */
    window.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        const productCard = e.target.closest('.walkin-product-card.is-clickable[data-product-id]');
        if (!productCard) return;
        e.preventDefault();
        openProductCustomization(productCard);
    }, true);

    if (checkoutEl && cartEl) {
        cartEl.addEventListener('click', function (e) {
            const btn = e.target.closest('#walkinPlaceBtn');
            if (!btn || btn.disabled) return;
            if (!window.bootstrap) {
                /* Bootstrap JS must be present for the checkout modal. */
                return;
            }

            const showCheckout = function () {
                bootstrap.Modal.getOrCreateInstance(checkoutEl).show();
            };

            showCheckout();
        });
    }

    /* Initialize the compact cart summary for tablet/mobile. */
    updateMobileCartSummary();

    /* Open the mobile cart after a cart validation error or price refresh. */
    const initialCartParams = new URLSearchParams(window.location.search);
    if (initialCartParams.get('cart') === '1') {
        setMobileCartExpanded(true);
    }

    /* ---------- notifications (toast) ---------- */
    function showToast(message, isError) {
        document.querySelectorAll('.walkin-toast').forEach(function (el) { el.remove(); });
        const wrap = document.createElement('div');
        wrap.className = 'walkin-toast';
        wrap.setAttribute('aria-live', 'polite');
        wrap.setAttribute('aria-atomic', 'true');
        wrap.innerHTML =
            '<div class="toast ' + (isError ? 'is-error' : '') + '" role="status" data-bs-delay="3500">' +
                '<div class="toast-header">' +
                    '<i class="bi ' + (isError ? 'bi-exclamation-circle' : 'bi-check-circle') + ' me-2"></i>' +
                    '<strong class="me-auto">Walk-in Order</strong><small>Now</small>' +
                    '<button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>' +
                '</div>' +
                '<div class="toast-body"></div>' +
            '</div>';
        wrap.querySelector('.toast-body').textContent = message;
        document.body.appendChild(wrap);
        const toastEl = wrap.querySelector('.toast');
        if (window.bootstrap && bootstrap.Toast) {
            toastEl.addEventListener('hidden.bs.toast', function () { wrap.remove(); });
            bootstrap.Toast.getOrCreateInstance(toastEl, { autohide: true, delay: 3500 }).show();
        }
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
        const newCartContent = doc.querySelector('#walkinCartPanel .walkin-cart-content');
        if (!newCartContent) {
            /* e.g. the session expired and the server sent the login page: just follow it. */
            window.location.href = finalUrl.href;
            return { navigated: true };
        }

        cartEl.querySelector('.walkin-cart-content').innerHTML = newCartContent.innerHTML;
        updateMobileCartSummary();

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
    addForm.addEventListener('submit', function (e) {
        e.preventDefault();
        if (cartBusy) return;
        cartBusy = true;

        const btn = addForm.querySelector('button[type="submit"]');
        const label = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span> Adding...';

        postCart(addForm).then(function (r) {
            if (r.navigated) return;
            const after = function () {
                if (!r.ok) showToast(r.message, true);
            };
            if (productModal.classList.contains('show')) {
                productModal.addEventListener('hidden.bs.modal', after, { once: true });
                productModalInstance.hide();
            } else {
                after();
            }
        }).catch(function (err) {
            if (err && err.network) {
                addForm.submit();   /* normal full-page submit as a safety net */
            } else {
                showToast('Unable to add the item. Please try again.', true);
            }
        }).finally(function () {
            cartBusy = false;
            btn.disabled = false;
            btn.innerHTML = label;
        });
    });

    /* Quantity +/-, remove item, and clear order (AJAX updates the persistent panel). */
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
                    form.submit();   /* normal full-page submit as a safety net */
                } else {
                    showToast('Unable to update the order. Please try again.', true);
                    if (btn) btn.disabled = false;
                }
            }).finally(function () {
                cartBusy = false;
            });
        });
    }

    const params = new URLSearchParams(window.location.search);
    /* Clean one-time flags so a refresh doesn't repeat them. */
    ['cart', 'added', 'updated', 'cleared', 'error', 'placed'].forEach(function (key) { params.delete(key); });
    const cleanQuery = params.toString();
    window.history.replaceState({}, '', window.location.pathname + (cleanQuery ? '?' + cleanQuery : ''));

    /* ---------- AJAX category navigation ---------- */
    /* Fetch only the product markup; keep the page, cart and open layout in place. */
    let categoryRequestController = null;
    let categoryRequestId = 0;

    function setActiveCategory(url) {
        if (!categoriesEl) return;
        const selectedCategory = url.searchParams.get('category') || 'Classic Milktea';
        categoriesEl.querySelectorAll('.walkin-category-link').forEach(function (link) {
            const linkUrl = new URL(link.href, window.location.href);
            const active = (linkUrl.searchParams.get('category') || 'Classic Milktea') === selectedCategory;
            link.classList.toggle('active', active);
            if (active) link.setAttribute('aria-current', 'page');
            else link.removeAttribute('aria-current');
        });
    }

    async function loadCategory(url, addHistoryEntry) {
        if (!productsColumn) return;
        const displayUrl = new URL(url.href || url, window.location.href);
        displayUrl.searchParams.delete('ajax');

        const requestUrl = new URL(displayUrl.href);
        requestUrl.searchParams.set('ajax', '1');

        if (categoryRequestController) categoryRequestController.abort();
        categoryRequestController = new AbortController();
        const requestId = ++categoryRequestId;

        productsColumn.classList.add('is-loading');
        productsColumn.setAttribute('aria-busy', 'true');

        try {
            const response = await fetch(requestUrl.href, {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'text/html'
                },
                credentials: 'same-origin',
                signal: categoryRequestController.signal
            });
            if (!response.ok) throw new Error('Category request failed: ' + response.status);

            const html = await response.text();
            if (requestId !== categoryRequestId) return;

            const parsed = new DOMParser().parseFromString(html, 'text/html');
            const productContent = parsed.querySelector('.walkin-product-content');
            if (!productContent) throw new Error('The products response was incomplete.');

            productsColumn.innerHTML = productContent.outerHTML;
            setActiveCategory(displayUrl);
            const selectedCategory = displayUrl.searchParams.get('category') || 'Classic Milktea';
            document.querySelectorAll('input[name="category"]').forEach(function (input) {
                input.value = selectedCategory;
            });

            if (addHistoryEntry) {
                window.history.pushState({}, '', displayUrl.pathname + displayUrl.search + displayUrl.hash);
            }
        } catch (error) {
            if (error.name !== 'AbortError' && requestId === categoryRequestId) {
                console.error('[walk-in] Could not load category:', error);
                showToast('Unable to load products for this category. Please try again.', true);
            }
        } finally {
            if (requestId === categoryRequestId) {
                productsColumn.classList.remove('is-loading');
                productsColumn.removeAttribute('aria-busy');
                categoryRequestController = null;
            }
        }
    }

    if (categoriesEl) {
        categoriesEl.addEventListener('click', function (event) {
            const link = event.target.closest('.walkin-category-link');
            if (!link || !categoriesEl.contains(link)) return;
            if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;

            event.preventDefault();
            const targetUrl = new URL(link.href, window.location.href);
            const currentCategory = new URL(window.location.href).searchParams.get('category') || 'Classic Milktea';
            const targetCategory = targetUrl.searchParams.get('category') || 'Classic Milktea';
            if (targetCategory === currentCategory && !productsColumn.classList.contains('is-loading')) return;
            loadCategory(targetUrl, true);
        });

        /* Browser Back/Forward changes the category without reloading the whole page. */
        window.addEventListener('popstate', function () {
            loadCategory(new URL(window.location.href), false);
        });
    }

    document.querySelectorAll('.walkin-toast .toast').forEach(function (toastElement) {
        if (window.bootstrap && bootstrap.Toast) {
            bootstrap.Toast.getOrCreateInstance(toastElement, {
                autohide: true,
                delay: Number(toastElement.dataset.bsDelay || 3500)
            }).show();
        }
    });

    window.__walkinInitDone = true;
}

/* Make sure Bootstrap 5 is really available, then start the page.
   Order: already loaded (even with defer) -> local copy in ../assets -> CDN -> built-in minimal fallback. */
(function () {
    const LOCAL = <?= json_encode($walkinLocalBootstrap, JSON_UNESCAPED_SLASHES) ?>;
    const CDN = 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js';

    function ready() { return !!(window.bootstrap && bootstrap.Modal && bootstrap.Toast); }

    function loadScript(src, done) {
        const s = document.createElement('script');
        s.src = src;
        s.onload = function () { done(true); };
        s.onerror = function () { done(false); };
        document.head.appendChild(s);
    }

    function tryList(list) {
        if (ready()) { walkinInit(); return; }
        if (!list.length) { installFallback(); walkinInit(); return; }
        loadScript(list.shift(), function () { tryList(list); });
    }

    /* Tiny stand-in for bootstrap.Modal / Offcanvas / Toast, used only if no real Bootstrap JS can be loaded. */
    function installFallback() {
        console.warn('[walk-in] Bootstrap JS could not be loaded - using the built-in fallback.');
        window.__walkinShim = true;
        const cache = new WeakMap();

        function fire(el, name, rel) {
            const ev = new Event(name, { bubbles: true });
            ev.relatedTarget = rel || null;
            el.dispatchEvent(ev);
        }

        function Overlay(el, kind) {
            this.el = el; this.kind = kind; this.open = false; this.backdrop = null;
        }
        Overlay.prototype.show = function (rel) {
            if (this.open) return;
            const self = this, el = this.el;
            fire(el, 'show.bs.' + this.kind, rel);
            this.backdrop = document.createElement('div');
            this.backdrop.className = (this.kind === 'modal' ? 'modal-backdrop' : 'offcanvas-backdrop') + ' fade show';
            document.body.appendChild(this.backdrop);
            document.body.style.overflow = 'hidden';
            if (this.kind === 'modal') {
                el.style.display = 'block';
                el.removeAttribute('aria-hidden');
                el.setAttribute('aria-modal', 'true');
                el.onclick = function (e) { if (e.target === el) self.hide(); };
            } else {
                el.style.visibility = 'visible';
                this.backdrop.onclick = function () { self.hide(); };
            }
            void el.offsetWidth;
            el.classList.add('show');
            this.open = true;
            fire(el, 'shown.bs.' + this.kind, rel);
        };
        Overlay.prototype.hide = function () {
            if (!this.open) return;
            const el = this.el;
            fire(el, 'hide.bs.' + this.kind);
            el.classList.remove('show');
            if (this.kind === 'modal') {
                el.style.display = 'none';
                el.setAttribute('aria-hidden', 'true');
                el.removeAttribute('aria-modal');
            } else {
                el.style.visibility = '';
            }
            if (this.backdrop) { this.backdrop.remove(); this.backdrop = null; }
            document.body.style.overflow = '';
            this.open = false;
            fire(el, 'hidden.bs.' + this.kind);
        };
        Overlay.prototype.toggle = function () { this.open ? this.hide() : this.show(); };

        function Toast(el, opts) { this.el = el; this.opts = opts || {}; }
        Toast.prototype.show = function () {
            const el = this.el, delay = this.opts.delay || 3500, self = this;
            el.classList.add('show');
            if (this.opts.autohide !== false) setTimeout(function () { self.hide(); }, delay);
        };
        Toast.prototype.hide = function () { this.el.classList.remove('show'); };

        function make(Ctor, kind) {
            return {
                getOrCreateInstance: function (el, opts) {
                    if (!cache.has(el)) cache.set(el, kind ? new Ctor(el, kind) : new Ctor(el, opts));
                    return cache.get(el);
                }
            };
        }
        window.bootstrap = {
            Modal: make(Overlay, 'modal'),
            Offcanvas: make(Overlay, 'offcanvas'),
            Toast: make(Toast, null)
        };

        document.addEventListener('click', function (e) {
            const btn = e.target.closest('[data-bs-dismiss]');
            if (!btn) return;
            const type = btn.getAttribute('data-bs-dismiss');
            const sel = type === 'modal' ? '.modal' : (type === 'offcanvas' ? '.offcanvas' : '.toast');
            const target = btn.closest(sel);
            if (!target) return;
            e.preventDefault();
            bootstrap[type === 'modal' ? 'Modal' : (type === 'offcanvas' ? 'Offcanvas' : 'Toast')].getOrCreateInstance(target).hide();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            document.querySelectorAll('.modal.show, .offcanvas.show').forEach(function (el) {
                const k = el.classList.contains('modal') ? 'Modal' : 'Offcanvas';
                bootstrap[k].getOrCreateInstance(el).hide();
            });
        });
    }

    function start() {
        /* No other script on this page loads Bootstrap JS, so load it immediately. */
        tryList(LOCAL.concat([CDN]));
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();
</script>