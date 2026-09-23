<?php
require_once '../includes/db.php';

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

$stmt = $pdo->prepare("
    SELECT products.*, categories.name AS category 
    FROM products 
    JOIN categories ON products.category_id = categories.id 
    WHERE products.id = ?
");
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) {
    header("Location: menu.php");
    exit;
}

$isAvailable = (int)($product['is_available'] ?? 0) === 1;

require_once '../includes/header.php';
require_once '../includes/navbar.php';

// Size options are based on the product's actual configured prices.
$regularPrice = (float)($product['regular_price'] ?? 0);
$grandePrice = (float)($product['grande_price'] ?? 0);
$hasSize = $regularPrice > 0 || $grandePrice > 0;

/*
 * Optional promotion context.
 * When the customer clicked "Order Now" on a promotion, the homepage
 * sends the promotion ID here instead of adding the product directly.
 */
$promotionId = isset($_GET['promotion_id']) ? (int)$_GET['promotion_id'] : 0;
$promotionRole = trim((string)($_GET['promotion_role'] ?? 'buy'));
$promotionQuantity = max(
    1,
    (int)($_GET['promotion_quantity'] ?? 1)
);

$promotion = null;

if ($promotionId > 0) {

    $promotionStmt = $pdo->prepare("
        SELECT
            p.id,
            p.title,
            p.description,
            r.rule_type,
            r.buy_quantity,
            r.get_quantity,
            pri.role,
            pri.size AS promotion_size
        FROM promotions p
        INNER JOIN promotion_rules r
            ON r.promotion_id = p.id
        INNER JOIN promotion_rule_items pri
            ON pri.rule_id = r.id
        WHERE p.id = ?
          AND pri.product_id = ?
          AND pri.role = ?
          AND p.is_active = 1
          AND p.is_archived = 0
          AND p.start_date <= CURDATE()
          AND p.end_date >= CURDATE()
        LIMIT 1
    ");

    $promotionStmt->execute([
        $promotionId,
        $product['id'],
        $promotionRole
    ]);

    $promotion = $promotionStmt->fetch(PDO::FETCH_ASSOC);

    /*
     * For Buy X Get Y, make sure the minimum BUY quantity from the
     * database is respected even if the URL was manually changed.
     */
    if (
        $promotion &&
        $promotion['rule_type'] === 'buy_x_get_y'
    ) {
        $promotionQuantity = max(
            $promotionQuantity,
            (int)$promotion['buy_quantity']
        );
    }

    if (
        $promotion &&
        $promotion['rule_type'] === 'bundle'
    ) {
        $promotionQuantity = max(
            $promotionQuantity,
            1
        );
    }
}

$isPromotionGet =
    $promotion !== null &&
    $promotionRole === 'get';

$isPromotionBuy =
    $promotion !== null &&
    $promotionRole === 'buy';

$promotionConfiguredSize = strtolower(
    trim((string)($promotion['promotion_size'] ?? ''))
);

/*
 * A GET/free item keeps the configured promotion size, while sugar level
 * and add-ons are intentionally left fully customizable.
 */
$fixedPromotionSize = in_array(
    $promotionConfiguredSize,
    ['regular', 'grande'],
    true
) ? ucfirst($promotionConfiguredSize) : '';

// Kunin lang ang add-ons na naka-assign sa product at active sa database.
$addonStmt = $pdo->prepare("
    SELECT
        a.id,
        a.name,
        a.price
    FROM product_addons pa
    INNER JOIN addons a
        ON pa.addon_id = a.id
    WHERE pa.product_id = ?
      AND a.is_available = 1
      AND a.is_archived = 0
    ORDER BY pa.sort_order ASC, a.name ASC
");
$addonStmt->execute([$product['id']]);
$addons = $addonStmt->fetchAll();
?>

<style>
    body {
        background-color: #FDFBF7;
    }
    .btn-brown {
        background-color: #332317;
        border: 1.5px solid #24170F;
        border-color: #24170F;
        color: #ffffff;
        border-radius: 50px;
        padding: 0.6rem 1.2rem;
        font-weight: 600;
        letter-spacing: 0.5px;
        transition: all 0.2s ease-in-out;
        box-shadow: 0 2px 6px rgba(74, 53, 37, 0.2);
    }
    .btn-brown:hover {
        background-color: #24170F;
        border-color: #1A100B;
        color: #ffffff;
        transform: translateY(-1px);
        box-shadow: 0 4px 10px rgba(74, 53, 37, 0.3);
    }
    .btn-brown.out-of-stock {
        background-color: #E1DDD9 !important;
        border-color: #E1DDD9 !important;
        color: #766C65 !important;
        cursor: not-allowed;
        pointer-events: none;
        box-shadow: none;
    }
    .product-image-wrap {
        position: relative;
        display: inline-block;
    }
    .product-image-wrap img.product-image-unavailable {
        opacity: .72;
    }
    .out-of-stock-badge {
        position: absolute;
        top: 12px;
        left: 12px;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 6px 10px;
        border-radius: 50px;
        background: #FBE7E7;
        border: 1px solid #C33131;
        color: #A12E2E;
        font-size: .7rem;
        font-weight: 800;
        line-height: 1;
        z-index: 2;
    }
    .out-of-stock-message {
        background: #FBE7E7;
        border: 1px solid #E7B8B8;
        color: #A12E2E;
        border-radius: 10px;
        padding: 9px 12px;
        font-size: .82rem;
        font-weight: 700;
        margin-bottom: 18px;
    }
    .custom-box {
        background-color: #ffffff;
        border: 2px solid #4A3525;
        border-radius: 16px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.03);
    }

    .customize-order-box {
        background-color: #C4A484;
    }

    .qty-btn {
        cursor: pointer;
        font-size: 1.25rem;
        user-select: none;
        color: #332317;
        transition: color 0.2s;
    }
    .qty-btn:hover {
        color: #24170F;
    }
    .form-check-input {
        border-color: #4A3525;
    }
    .form-check-input:checked {
        background-color: #332317;
        border-color: #24170F;
    }
</style>

<div class="container py-5">
    <form action="add-to-cart.php" method="POST" data-ajax-form="true" data-ajax-loading-text="Adding to cart...">
        <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
        <?php if ($promotion): ?>
            <input type="hidden" name="promotion_id" value="<?= (int)$promotion['id'] ?>">
            <input type="hidden" name="promotion_role" value="<?= htmlspecialchars($promotion['role']) ?>">
            <?php if ($fixedPromotionSize !== ''): ?>
                <input type="hidden" name="size" value="<?= htmlspecialchars($fixedPromotionSize) ?>" class="fixed-promotion-size">
            <?php endif; ?>
        <?php endif; ?>
        
        <div class="row justify-content-center align-items-start g-4">
            
            <!-- Kaliwang Bahagi: Larawan, Pangalan, Price at Counter -->
            <div class="col-md-4 text-center">
                <div class="custom-box p-4">
                    <div class="product-image-wrap mb-3">
                        <img src="../assets/uploads/products/<?= htmlspecialchars($product['image'] ?: 'default.jpg') ?>"
                             class="img-fluid rounded-3 shadow-sm <?= !$isAvailable ? 'product-image-unavailable' : '' ?>"
                             style="max-height: 200px; object-fit: cover;"
                             alt="<?= htmlspecialchars($product['name']) ?>">

                        <?php if (!$isAvailable): ?>
                            <span class="out-of-stock-badge">
                                <i class="bi bi-x-circle-fill"></i>
                                Out of Stock
                            </span>
                        <?php endif; ?>
                    </div>
                    
                    <h4 class="fw-bold mb-1" style="color: #2c221e;"><?= htmlspecialchars($product['name']) ?></h4>
                    
                    <!-- Dynamic Price Display -->
                    <div class="fs-4 fw-bold mb-3" style="color: #6f4e37;">
                        <span id="price-prefix" <?= $isPromotionGet ? 'style="display:none;"' : '' ?>>₱</span><span id="display-price">
                            <?php 
                                // For a free Get item, only selected add-ons are payable.
                                echo $isPromotionGet
                                    ? 'FREE'
                                    : number_format(
                                        $hasSize
                                            ? ($regularPrice > 0 ? $regularPrice : $grandePrice)
                                            : $product['price'],
                                        2
                                    ); 
                            ?>
                        </span>
                    </div>

                    <?php if ($isPromotionGet): ?>
                        <div class="small text-muted mb-3">
                            Free drink base price. Selected add-ons are charged separately.
                        </div>
                    <?php endif; ?>
                    
                    <!-- Quantity Counter (- 1 +) -->
                    <?php if (!$isAvailable): ?>
                        <div class="out-of-stock-message">
                            <i class="bi bi-exclamation-circle-fill me-1"></i>
                            This product is currently unavailable and cannot be ordered.
                        </div>
                    <?php endif; ?>

                    <div class="d-flex justify-content-between align-items-center mb-4 px-2 py-2 rounded-3 bg-light border">
                        <span class="text-muted small fw-semibold">Quantity</span>
                        <div class="d-flex align-items-center gap-3">
                            <span class="qty-btn <?= (!$isAvailable || $isPromotionGet) ? 'pe-none opacity-50' : '' ?>"
                                  <?= $isAvailable && !$isPromotionGet ? 'onclick="updateQty(-1)"' : '' ?>>
                                <i class="bi bi-dash-circle-fill"></i>
                            </span>
                            <span id="qty-text" class="fw-bold fs-5" style="color: #2c221e;"><?= $promotion ? $promotionQuantity : 1 ?></span>
                            <span class="qty-btn <?= (!$isAvailable || $isPromotionGet) ? 'pe-none opacity-50' : '' ?>"
                                  <?= $isAvailable && !$isPromotionGet ? 'onclick="updateQty(1)"' : '' ?>>
                                <i class="bi bi-plus-circle-fill"></i>
                            </span>
                            <input type="hidden" name="quantity" id="input-qty" value="<?= $promotion ? $promotionQuantity : 1 ?>">
                        </div>
                    </div>

                    <?php if ($isAvailable): ?>
                        <button type="submit" class="btn btn-brown w-100"><?= $isPromotionGet ? 'Add Free Item' : (($promotion && in_array($promotion['rule_type'], ['bogo', 'buy_x_get_y'], true)) ? 'Continue to Free Item' : (($promotion && $promotion['rule_type'] === 'bundle') ? 'Add Bundle Item' : 'Add to Order')) ?></button>
                    <?php else: ?>
                        <button type="button" class="btn btn-brown out-of-stock w-100" disabled aria-disabled="true">
                            Out of Stock
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Kanang Bahagi: Customize your order Panel -->
            <div class="col-md-7">
                <div class="custom-box customize-order-box p-4">
                    <h4 class="fw-bold mb-4" style="color: #2c221e;">Customize your order</h4>
                    <?php if ($promotion): ?>
                        <div class="mb-4 p-3 rounded-3" style="background:#F7F0E8; border:1.5px solid #4A3525;">
                            <div class="fw-bold mb-1" style="color:#4A3525;">
                                <i class="bi bi-tag-fill me-1"></i>
                                <?= htmlspecialchars($promotion['title']) ?>
                            </div>
                            <div class="small text-muted">
                                <?php if (in_array($promotion['rule_type'], ['bogo', 'buy_x_get_y'], true) && $isPromotionGet): ?>
                                    Customize your free drink below. You can choose its sugar level and add-ons separately from the item you buy.
                                <?php elseif ($promotion['rule_type'] === 'bogo'): ?>
                                    Customize the item you are buying below. After this, you will customize the free drink separately.
                                <?php elseif ($promotion['rule_type'] === 'buy_x_get_y'): ?>
                                    Customize the item you are buying below. After this, you will customize the free Get item separately.
                                <?php elseif ($promotion['rule_type'] === 'bundle'): ?>
                                    Customize this bundle item below. After this, the next bundle item will be shown for customization.
                                <?php else: ?>
                                    Customize the product below to apply this promotion.
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($hasSize): ?>
                    <!-- SIZE SECTION -->
                    <div class="mb-4">
                        <h6 class="fw-bold text-dark mb-2">Size</h6>
                        <div class="row g-2">
                            <?php if ($regularPrice > 0): ?>
                            <div class="col-6">
                                <div class="form-check">
                                    <input
                                        class="form-check-input size-radio"
                                        type="radio"
                                        name="size"
                                        id="size1"
                                        value="Regular"
                                        data-price="<?= $regularPrice ?>"
                                        <?= ($fixedPromotionSize === 'Regular' || ($fixedPromotionSize === '' && $grandePrice <= 0)) ? 'checked' : '' ?>
                                        required
                                        onchange="calculateTotal()"
                                        <?= ($isPromotionGet && $fixedPromotionSize !== '' && $fixedPromotionSize !== 'Regular') || (!$isAvailable) ? 'disabled' : '' ?>
                                    >
                                    <label class="form-check-label text-dark" for="size1">
                                        Regular (₱<?= number_format($regularPrice, 2) ?>)
                                    </label>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if ($grandePrice > 0): ?>
                            <div class="col-6">
                                <div class="form-check">
                                    <input
                                        class="form-check-input size-radio"
                                        type="radio"
                                        name="size"
                                        id="size2"
                                        value="Grande"
                                        data-price="<?= $grandePrice ?>"
                                        <?= ($fixedPromotionSize === 'Grande' || ($fixedPromotionSize === '' && $regularPrice <= 0)) ? 'checked' : '' ?>
                                        required
                                        onchange="calculateTotal()"
                                        <?= ($isPromotionGet && $fixedPromotionSize !== '' && $fixedPromotionSize !== 'Grande') || (!$isAvailable) ? 'disabled' : '' ?>
                                    >
                                    <label class="form-check-label text-dark" for="size2">
                                        Grande (₱<?= number_format($grandePrice, 2) ?>)
                                    </label>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if ($fixedPromotionSize !== ''): ?>
                                <div class="col-12">
                                    <div class="small text-muted mt-1">
                                        <i class="bi bi-lock-fill me-1"></i>
                                        Promotion size: <strong><?= htmlspecialchars($fixedPromotionSize) ?></strong>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- ADD-ONS SECTION -->
                    <div class="mb-4">
                        <h6 class="fw-bold text-dark mb-2">Add-ons</h6>
                        <div class="row g-2">
                            <?php if (!empty($addons)): ?>
                                <?php foreach ($addons as $addon): ?>
                                <div class="col-6">
                                    <div class="form-check">
                                        <input class="form-check-input addon-checkbox"
                                               type="checkbox"
                                               name="addons[]"
                                               value="<?= htmlspecialchars($addon['name']) ?>"
                                               data-price="<?= htmlspecialchars($addon['price']) ?>"
                                               id="addon_<?= (int)$addon['id'] ?>"
                                               onchange="calculateTotal()"
                                               <?= !$isAvailable ? 'disabled' : '' ?>>
                                        <label class="form-check-label small text-dark" for="addon_<?= (int)$addon['id'] ?>">
                                            <?= htmlspecialchars($addon['name']) ?>
                                            <span class="text-muted">(₱<?= number_format((float)$addon['price'], 2) ?>)</span>
                                        </label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="col-12">
                                    <div class="text-muted small">No add-ons available for this product.</div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- SUGAR-LEVEL SECTION -->
                    <div>
                        <h6 class="fw-bold text-dark mb-2">Sugar-Level</h6>
                        <div class="d-flex flex-wrap gap-3">
                            <?php $sugars = ['0%', '25%', '50%', '75%', '100%'];
                            foreach ($sugars as $i => $sugar): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="sugar_level" id="sugar_<?= $i ?>" value="<?= $sugar ?>" required <?= !$isAvailable ? 'disabled' : '' ?>>
                                <label class="form-check-label small text-dark" for="sugar_<?= $i ?>"><?= $sugar ?></label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                </div>
            </div>

        </div>
    </form>
</div>

<!-- JavaScript para sa tamang pagkalkula ng Presyo (Classic vs Fixed Price) -->
<script>
let currentQty = <?= $promotion ? $promotionQuantity : 1 ?>;
let isPromotionGet = <?= $isPromotionGet ? 'true' : 'false' ?>;
let fixedPromotionQuantity = <?= $promotion ? $promotionQuantity : 0 ?>;
let hasSizeOption = <?= $hasSize ? 'true' : 'false' ?>;
let baseProductPrice = <?= $product['price'] ?>;

function updateQty(change) {
    if (isPromotionGet) {
        return;
    }

    currentQty += change;

    let minimumQty = <?= $promotion ? $promotionQuantity : 1 ?>;

    if (currentQty < minimumQty) {
        currentQty = minimumQty;
    }
    
    document.getElementById('qty-text').innerText = currentQty;
    document.getElementById('input-qty').value = currentQty;
    calculateTotal();
}

function calculateTotal() {
    let itemPrice = baseProductPrice;

    if (hasSizeOption) {
        let selectedSize = document.querySelector('.size-radio:checked');
        if (selectedSize) {
            itemPrice = parseFloat(selectedSize.getAttribute('data-price')) || 29;
        }
    }

    let addonsTotal = 0;
    let selectedAddons = document.querySelectorAll('.addon-checkbox:checked');
    selectedAddons.forEach(function(addon) {
        addonsTotal += parseFloat(addon.getAttribute('data-price')) || 0;
    });

    let totalPrice = (itemPrice + addonsTotal) * currentQty;

    const displayPrice = document.getElementById('display-price');
    const pricePrefix = document.getElementById('price-prefix');

    if (isPromotionGet) {
        // The drink base is free, but every selected add-on is chargeable.
        // Show FREE only when there are no paid add-ons selected.
        if (addonsTotal > 0) {
            if (pricePrefix) {
                pricePrefix.style.display = 'inline';
            }
            displayPrice.innerText = (addonsTotal * currentQty).toFixed(2);
        } else {
            if (pricePrefix) {
                pricePrefix.style.display = 'none';
            }
            displayPrice.innerText = 'FREE';
        }
        return;
    }

    if (pricePrefix) {
        pricePrefix.style.display = 'inline';
    }
    displayPrice.innerText = totalPrice.toFixed(2);
}
</script>

<?php require_once '../includes/footer.php'; ?>