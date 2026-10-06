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
    /* =========================================================
       PRODUCT VIEW — LocaliTea
       Palette: espresso #2C221E · roast #4A3525 · mocha #6F4E37
                oat #F3EADF · foam #FBF7F1 · line #E6DACB
    ========================================================= */
    body {
        background-color: #FBF7F1;
    }

    .pv-page {
        max-width: 1000px;
        padding-bottom: 1.25rem;
    }

    .pv-back {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        margin-bottom: 10px;
        color: #6F4E37;
        font-size: .82rem;
        font-weight: 600;
        text-decoration: none;
    }
    .pv-back:hover { color: #2C221E; }

    .pv-layout {
        display: grid;
        grid-template-columns: minmax(270px, 320px) minmax(0, 1fr);
        gap: 20px;
        align-items: start;
    }

    /* ---------- Left: product summary ---------- */
    .pv-summary {
        position: sticky;
        top: 84px;
        background: #ffffff;
        border: 1px solid #E6DACB;
        border-radius: 20px;
        box-shadow: 0 10px 26px rgba(74, 53, 37, .07);
        overflow: hidden;
    }

    .pv-media {
        padding: 12px 12px 0;
    }

    .product-image-wrap {
        position: relative;
        display: block;
        border-radius: 14px;
        overflow: hidden;
        background: #ffffff;
        border: 1px solid #EFE5D9;
    }
    .product-image-wrap img {
        display: block;
        width: 100%;
        height: clamp(140px, 25vh, 210px);
        object-fit: contain;
    }
    .product-image-wrap img.product-image-unavailable {
        opacity: .6;
        filter: grayscale(.35);
    }

    .out-of-stock-badge {
        position: absolute;
        top: 12px;
        left: 12px;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 6px 11px;
        border-radius: 50px;
        background: #FBE7E7;
        border: 1px solid #C33131;
        color: #A12E2E;
        font-size: .72rem;
        font-weight: 800;
        line-height: 1;
        z-index: 2;
    }

    .pv-info {
        padding: 12px 16px 2px;
    }
    .pv-category {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        margin-bottom: 3px;
        color: #8A7A6C;
        font-size: .75rem;
        font-weight: 600;
    }
    .pv-name {
        margin: 0;
        color: #2C221E;
        font-size: 1.2rem;
        font-weight: 800;
        line-height: 1.2;
    }
    .pv-price {
        margin-top: 6px;
        color: #6F4E37;
        font-size: 1.35rem;
        font-weight: 800;
        line-height: 1;
        font-variant-numeric: tabular-nums;
    }
    .pv-price-note {
        margin-top: 8px;
        padding: 7px 10px;
        border-radius: 10px;
        background: #F7F0E8;
        color: #6D5B4C;
        font-size: .75rem;
        line-height: 1.4;
    }

    .out-of-stock-message {
        background: #FBE7E7;
        border: 1px solid #E7B8B8;
        color: #A12E2E;
        border-radius: 10px;
        padding: 7px 10px;
        font-size: .76rem;
        font-weight: 700;
        margin-top: 8px;
    }

    /* Quantity + main action */
    .pv-buy {
        display: flex;
        flex-direction: column;
        gap: 10px;
        padding: 12px 16px 16px;
    }
    .pv-qty-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
    }
    .pv-qty-label {
        color: #6D5B4C;
        font-size: .8rem;
        font-weight: 600;
    }
    .pv-qty {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 4px;
        border-radius: 50px;
        background: #F3EADF;
    }
    .qty-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 28px;
        height: 28px;
        padding: 0;
        border: 0;
        border-radius: 50%;
        background: #ffffff;
        color: #332317;
        font-size: .85rem;
        cursor: pointer;
        user-select: none;
        box-shadow: 0 1px 3px rgba(74, 53, 37, .15);
    }
    .qty-btn:hover { background: #332317; color: #ffffff; }
    .qty-btn:disabled {
        opacity: .4;
        cursor: not-allowed;
        background: #ffffff;
        color: #332317;
    }
    #qty-text {
        min-width: 32px;
        text-align: center;
        color: #2C221E;
        font-size: .95rem;
        font-weight: 800;
        font-variant-numeric: tabular-nums;
    }

    .btn-brown {
        background-color: #332317;
        border: 1.5px solid #24170F;
        color: #ffffff;
        border-radius: 50px;
        padding: .6rem 1.1rem;
        font-size: .92rem;
        font-weight: 700;
        letter-spacing: .3px;
        box-shadow: 0 5px 14px rgba(51, 35, 23, .2);
    }
    .btn-brown:hover,
    .btn-brown:focus-visible {
        background-color: #24170F;
        border-color: #1A100B;
        color: #ffffff;
    }
    .btn-brown.out-of-stock {
        background-color: #E1DDD9 !important;
        border-color: #E1DDD9 !important;
        color: #766C65 !important;
        cursor: not-allowed;
        pointer-events: none;
        box-shadow: none;
    }

    /* ---------- Right: customize panel ---------- */
    .pv-options {
        background: #ffffff;
        border: 1px solid #E6DACB;
        border-radius: 20px;
        padding: 16px 22px 18px;
        box-shadow: 0 10px 26px rgba(74, 53, 37, .05);
    }
    .pv-options-title {
        margin: 0;
        color: #2C221E;
        font-size: 1.1rem;
        font-weight: 800;
    }

    .pv-promo {
        display: flex;
        gap: 10px;
        margin: 10px 0 0;
        padding: 10px 12px;
        border-radius: 12px;
        background: #F7F0E8;
        border-left: 5px solid #4A3525;
    }
    .pv-promo-icon {
        flex: 0 0 auto;
        color: #4A3525;
        font-size: 1rem;
        line-height: 1.3;
    }
    .pv-promo-title {
        color: #4A3525;
        font-size: .88rem;
        font-weight: 800;
        margin-bottom: 1px;
    }
    .pv-promo-text {
        color: #6D5B4C;
        font-size: .76rem;
        line-height: 1.4;
    }

    .pv-group {
        padding: 11px 0;
        border-top: 1px solid #EFE5D9;
    }
    .pv-group:first-of-type { margin-top: 8px; }
    .pv-group:last-child { padding-bottom: 0; }
    .pv-group-head {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        gap: 10px;
        margin-bottom: 7px;
    }
    .pv-group-title {
        margin: 0;
        color: #2C221E;
        font-size: .9rem;
        font-weight: 800;
    }
    .pv-group-hint {
        color: #8A7A6C;
        font-size: .7rem;
        font-weight: 600;
    }

    /* Choice controls: the real radio / checkbox stays in the DOM,
       covers the tile and is invisible, so forms and JS work as before. */
    .pv-choices {
        display: grid;
        gap: 7px;
    }
    .pv-choices.is-two    { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .pv-choices.is-addons { grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); }
    .pv-choices.is-sugar  { grid-template-columns: repeat(5, minmax(0, 1fr)); }
    .pv-choices.is-flex  { display: flex; flex-wrap: wrap; }

    .pv-choice {
        position: relative;
        padding: 0;
        margin: 0;
        min-height: 0;
    }
    .pv-choice .form-check-input {
        position: absolute;
        inset: 0;
        z-index: 1;
        width: 100%;
        height: 100%;
        margin: 0;
        float: none;
        opacity: 0;
        cursor: pointer;
    }
    .pv-choice .form-check-input:disabled { cursor: not-allowed; }

    .pv-choice .form-check-label {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        width: 100%;
        height: 100%;
        padding: 7px 11px;
        border: 1.5px solid #E0D2C2;
        border-radius: 11px;
        background: #ffffff;
        color: #2C221E;
        font-size: .8rem;
        font-weight: 600;
        line-height: 1.25;
    }
    .pv-choice:hover .form-check-input:not(:disabled) + .form-check-label {
        border-color: #B8A08A;
        background: #FDF9F4;
    }
    .pv-choice .form-check-input:focus-visible + .form-check-label {
        outline: 3px solid rgba(111, 78, 55, .35);
        outline-offset: 2px;
    }
    .pv-choice .form-check-input:disabled + .form-check-label {
        opacity: .5;
    }

    /* Single choice (size, sugar, discount) = filled when selected */
    .pv-choice .form-check-input[type="radio"]:checked + .form-check-label {
        background: #332317;
        border-color: #332317;
        color: #ffffff;
    }
    .pv-choice .form-check-input[type="radio"]:checked + .form-check-label .pv-choice-meta {
        color: #E9D9C6;
    }

    .pv-choice--size .form-check-label { padding: 9px 13px; }
    .pv-choice--size .pv-choice-name { font-size: .88rem; font-weight: 800; }

    /* Sugar + discount pills */
    .pv-choice--pill .form-check-label {
        justify-content: center;
        border-radius: 50px;
        padding: 6px 12px;
        text-align: center;
    }

    .pv-choice-meta {
        color: #8A7A6C;
        font-size: .74rem;
        font-weight: 600;
        white-space: nowrap;
    }

    /* Multi choice (add-ons) = checkbox mark, outlined tile */
    .pv-choice--addon .form-check-label {
        justify-content: flex-start;
        gap: 10px;
    }
    .pv-choice--addon .form-check-label::before {
        content: "";
        flex: 0 0 17px;
        width: 17px;
        height: 17px;
        border: 1.5px solid #B8A08A;
        border-radius: 5px;
        background: #ffffff center / 11px no-repeat;
    }
    .pv-choice--addon .pv-choice-meta { margin-left: auto; }
    .pv-choice--addon .form-check-input:checked + .form-check-label {
        border-color: #332317;
        background: #F7F0E8;
    }
    .pv-choice--addon .form-check-input:checked + .form-check-label::before {
        border-color: #332317;
        background-color: #332317;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Cpath fill='none' stroke='%23fff' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round' d='M3.5 8.5l3 3 6-7'/%3E%3C/svg%3E");
    }

    .pv-empty {
        padding: 8px 12px;
        border: 1.5px dashed #E0D2C2;
        border-radius: 11px;
        color: #8A7A6C;
        font-size: .78rem;
    }
    .pv-note {
        margin-top: 7px;
        color: #8A7A6C;
        font-size: .72rem;
        line-height: 1.4;
    }
    .pv-note strong { color: #4A3525; }

    @media (prefers-reduced-motion: no-preference) {
        .pv-choice .form-check-label,
        .qty-btn,
        .btn-brown {
            transition: background-color .15s ease, border-color .15s ease, color .15s ease;
        }
    }

    /* ---------- Tablet / mobile ---------- */
    @media (max-width: 767.98px) {
        .pv-page { padding-bottom: 6rem; }

        .pv-layout {
            grid-template-columns: minmax(0, 1fr);
            gap: 14px;
        }
        .pv-summary { position: static; }
        .product-image-wrap img { height: 170px; }
        .pv-options { padding: 14px 16px 16px; }

        /* The add-to-cart bar stays reachable while the customer scrolls the options. */
        .pv-buy {
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 1030;
            flex-direction: row;
            align-items: center;
            gap: 12px;
            padding: 12px 16px calc(12px + env(safe-area-inset-bottom, 0px));
            background: #ffffff;
            border-top: 1px solid #E6DACB;
            box-shadow: 0 -8px 24px rgba(74, 53, 37, .12);
        }
        .pv-qty-row { flex: 0 0 auto; }
        .pv-qty-label { display: none; }
        .pv-buy .btn-brown { flex: 1 1 auto; width: auto; }
    }

    @media (max-width: 420px) {
        .pv-choices.is-addons { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .pv-choices.is-sugar { gap: 5px; }
        .pv-choice--pill .form-check-label { padding: 6px 4px; }
    }
</style>

<div class="container pv-page py-3">
    <a href="menu.php" class="pv-back">
        <i class="bi bi-arrow-left"></i> Back to menu
    </a>

    <form action="add-to-cart.php" method="POST" data-ajax-form="true" data-ajax-loading-text="Adding to cart...">
        <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
        <?php if ($promotion): ?>
            <input type="hidden" name="promotion_id" value="<?= (int)$promotion['id'] ?>">
            <input type="hidden" name="promotion_role" value="<?= htmlspecialchars($promotion['role']) ?>">
            <?php if ($fixedPromotionSize !== ''): ?>
                <input type="hidden" name="size" value="<?= htmlspecialchars($fixedPromotionSize) ?>" class="fixed-promotion-size">
            <?php endif; ?>
        <?php endif; ?>

        <div class="pv-layout">

            <!-- Kaliwang Bahagi: Larawan, Pangalan, Price at Counter -->
            <div class="pv-summary">
                <div class="pv-media">
                    <div class="product-image-wrap">
                        <img src="../assets/uploads/products/<?= htmlspecialchars($product['image'] ?: 'default.jpg') ?>"
                             class="<?= !$isAvailable ? 'product-image-unavailable' : '' ?>"
                             alt="<?= htmlspecialchars($product['name']) ?>">

                        <?php if (!$isAvailable): ?>
                            <span class="out-of-stock-badge">
                                <i class="bi bi-x-circle-fill"></i>
                                Out of Stock
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="pv-info">
                    <div class="pv-category">
                        <i class="bi bi-cup-straw"></i>
                        <?= htmlspecialchars($product['category']) ?>
                    </div>

                    <h1 class="pv-name"><?= htmlspecialchars($product['name']) ?></h1>

                    <!-- Dynamic Price Display -->
                    <div class="pv-price">
                        <span id="price-prefix" <?= $isPromotionGet ? 'style="display:none;"' : '' ?>>₱</span><span id="display-price"><?php
                                // For a free Get item, only selected add-ons are payable.
                                echo $isPromotionGet
                                    ? 'FREE'
                                    : number_format(
                                        $hasSize
                                            ? ($regularPrice > 0 ? $regularPrice : $grandePrice)
                                            : $product['price'],
                                        2
                                    );
                            ?></span>
                    </div>

                    <?php if ($isPromotionGet): ?>
                        <div class="pv-price-note">
                            Free drink base price. Selected add-ons are charged separately.
                        </div>
                    <?php endif; ?>

                    <?php if (!$isAvailable): ?>
                        <div class="out-of-stock-message">
                            <i class="bi bi-exclamation-circle-fill me-1"></i>
                            This product is currently unavailable and cannot be ordered.
                        </div>
                    <?php endif; ?>
                </div>

                <div class="pv-buy">
                    <!-- Quantity Counter (- 1 +) -->
                    <div class="pv-qty-row">
                        <span class="pv-qty-label">Quantity</span>
                        <div class="pv-qty">
                            <button type="button"
                                    class="qty-btn"
                                    aria-label="Decrease quantity"
                                    <?= (!$isAvailable || $isPromotionGet) ? 'disabled' : 'onclick="updateQty(-1)"' ?>>
                                <i class="bi bi-dash-lg"></i>
                            </button>
                            <span id="qty-text"><?= $promotion ? $promotionQuantity : 1 ?></span>
                            <button type="button"
                                    class="qty-btn"
                                    aria-label="Increase quantity"
                                    <?= (!$isAvailable || $isPromotionGet) ? 'disabled' : 'onclick="updateQty(1)"' ?>>
                                <i class="bi bi-plus-lg"></i>
                            </button>
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
            <section class="pv-options">
                <h2 class="pv-options-title">Customize your order</h2>

                <?php if ($promotion): ?>
                    <div class="pv-promo">
                        <i class="bi bi-tag-fill pv-promo-icon"></i>
                        <div>
                            <div class="pv-promo-title"><?= htmlspecialchars($promotion['title']) ?></div>
                            <div class="pv-promo-text">
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
                    </div>
                <?php endif; ?>

                <?php if ($hasSize): ?>
                <!-- SIZE SECTION -->
                <div class="pv-group">
                    <div class="pv-group-head">
                        <h3 class="pv-group-title">Size</h3>
                        <span class="pv-group-hint">Required</span>
                    </div>
                    <div class="pv-choices is-two">
                        <?php if ($regularPrice > 0): ?>
                        <div class="form-check pv-choice pv-choice--size">
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
                            <label class="form-check-label" for="size1">
                                <span class="pv-choice-name">Regular</span>
                                <span class="pv-choice-meta">₱<?= number_format($regularPrice, 2) ?></span>
                            </label>
                        </div>
                        <?php endif; ?>

                        <?php if ($grandePrice > 0): ?>
                        <div class="form-check pv-choice pv-choice--size">
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
                            <label class="form-check-label" for="size2">
                                <span class="pv-choice-name">Grande</span>
                                <span class="pv-choice-meta">₱<?= number_format($grandePrice, 2) ?></span>
                            </label>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($fixedPromotionSize !== ''): ?>
                        <div class="pv-note">
                            <i class="bi bi-lock-fill me-1"></i>
                            Promotion size: <strong><?= htmlspecialchars($fixedPromotionSize) ?></strong>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- ADD-ONS SECTION -->
                <div class="pv-group">
                    <div class="pv-group-head">
                        <h3 class="pv-group-title">Add-ons</h3>
                        <span class="pv-group-hint">Optional</span>
                    </div>
                    <?php if (!empty($addons)): ?>
                        <div class="pv-choices is-addons">
                            <?php foreach ($addons as $addon): ?>
                            <div class="form-check pv-choice pv-choice--addon">
                                <input class="form-check-input addon-checkbox"
                                       type="checkbox"
                                       name="addons[]"
                                       value="<?= htmlspecialchars($addon['name']) ?>"
                                       data-price="<?= htmlspecialchars($addon['price']) ?>"
                                       id="addon_<?= (int)$addon['id'] ?>"
                                       onchange="calculateTotal()"
                                       <?= !$isAvailable ? 'disabled' : '' ?>>
                                <label class="form-check-label" for="addon_<?= (int)$addon['id'] ?>">
                                    <?= htmlspecialchars($addon['name']) ?>
                                    <span class="pv-choice-meta">+₱<?= number_format((float)$addon['price'], 2) ?></span>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="pv-empty">No add-ons available for this product.</div>
                    <?php endif; ?>
                </div>

                <!-- SUGAR-LEVEL SECTION -->
                <div class="pv-group">
                    <div class="pv-group-head">
                        <h3 class="pv-group-title">Sugar level</h3>
                        <span class="pv-group-hint">Required</span>
                    </div>
                    <div class="pv-choices is-sugar">
                        <?php $sugars = ['0%', '25%', '50%', '75%', '100%'];
                        foreach ($sugars as $i => $sugar): ?>
                        <div class="form-check pv-choice pv-choice--pill">
                            <input class="form-check-input" type="radio" name="sugar_level" id="sugar_<?= $i ?>" value="<?= $sugar ?>" required <?= !$isAvailable ? 'disabled' : '' ?>>
                            <label class="form-check-label" for="sugar_<?= $i ?>"><?= $sugar ?></label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- DISCOUNT TYPE SECTION -->
                <div class="pv-group">
                    <div class="pv-group-head">
                        <h3 class="pv-group-title">Discount</h3>
                        <span class="pv-group-hint">Optional</span>
                    </div>

                    <div class="pv-choices is-flex">

                        <!-- No Discount -->
                        <div class="form-check pv-choice pv-choice--pill">
                            <input
                                class="form-check-input"
                                type="radio"
                                name="discount_type"
                                id="discount_none"
                                value="none"
                                checked
                                <?= !$isAvailable ? 'disabled' : '' ?>
                            >
                            <label class="form-check-label" for="discount_none">None</label>
                        </div>

                        <!-- PWD -->
                        <div class="form-check pv-choice pv-choice--pill">
                            <input
                                class="form-check-input"
                                type="radio"
                                name="discount_type"
                                id="discount_pwd"
                                value="pwd"
                                <?= !$isAvailable ? 'disabled' : '' ?>
                            >
                            <label class="form-check-label" for="discount_pwd">PWD (20%)</label>
                        </div>

                        <!-- Senior Citizen -->
                        <div class="form-check pv-choice pv-choice--pill">
                            <input
                                class="form-check-input"
                                type="radio"
                                name="discount_type"
                                id="discount_senior"
                                value="senior"
                                <?= !$isAvailable ? 'disabled' : '' ?>
                            >
                            <label class="form-check-label" for="discount_senior">Senior Citizen (20%)</label>
                        </div>

                    </div>

                    <div class="pv-note">
                        Discount is optional. Valid identification must be presented upon pick-up.
                    </div>
                </div>
            </section>

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

<style>
/* =========================================================
   LOCALITEA TOAST NOTIFICATION
   Matches the Admin Orders notification style.
========================================================= */
.localitea-toast-wrap {
    position: fixed;
    top: 88px;
    right: 24px;
    z-index: 2000;
    width: min(420px, calc(100vw - 32px));
    pointer-events: none;
}

.localitea-toast {
    position: relative;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 13px 14px;
    background: #ffffff;
    border: 2px solid #6F4E37;
    border-left: 6px solid #4A8B5A;
    border-radius: 12px;
    box-shadow: 0 10px 28px rgba(44,34,30,.18);
    color: #2C221E;
    pointer-events: auto;
    overflow: hidden;
    animation: localiteaToastIn .22s ease-out;
}

.localitea-toast-error {
    border-left-color: #A33A3A;
}

.localitea-toast-error .localitea-toast-icon {
    color: #8E2F2F;
    background: #FCE3E3;
}

.localitea-toast-error .localitea-toast-progress {
    background: #A33A3A;
}

.localitea-toast-icon {
    flex: 0 0 30px;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #EAF6EE;
    color: #2F6E3E;
    font-size: 15px;
    margin-top: 1px;
}

.localitea-toast-message {
    flex: 1;
    padding-top: 3px;
    font-size: .9rem;
    line-height: 1.45;
    font-weight: 700;
}

.localitea-toast-close {
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

.localitea-toast-close:hover {
    background: #F0E6D6;
    color: #2C221E;
}

.localitea-toast-progress {
    position: absolute;
    left: 0;
    bottom: 0;
    height: 3px;
    width: 100%;
    background: #4A8B5A;
    transform-origin: left center;
    animation: localiteaToastProgress 3.5s linear forwards;
}

.localitea-toast.is-closing {
    animation: localiteaToastOut .18s ease-in forwards;
}

@keyframes localiteaToastIn {
    from { opacity: 0; transform: translateY(-8px) translateX(8px); }
    to { opacity: 1; transform: translateY(0) translateX(0); }
}

@keyframes localiteaToastOut {
    from { opacity: 1; transform: translateY(0) translateX(0); }
    to { opacity: 0; transform: translateY(-6px) translateX(8px); }
}

@keyframes localiteaToastProgress {
    from { transform: scaleX(1); }
    to { transform: scaleX(0); }
}

@media (max-width: 576px) {
    .localitea-toast-wrap {
        top: 78px;
        right: 16px;
        width: calc(100vw - 32px);
    }
}
</style>

<script>
/*
 * =========================================================
 * ADD TO CART
 * =========================================================
 * Submit the product form through AJAX so the customer can:
 * 1. Add the item to the session cart.
 * 2. See "Added to cart."
 * 3. Return to the appropriate next page.
 *
 * The capture-phase listener prevents the generic AJAX form
 * handler from submitting the same form a second time.
 */
function showLocaliteaToast(message, type) {

    type = type || 'success';

    var existingWrap =
        document.querySelector('.localitea-toast-wrap');

    if (existingWrap) {
        existingWrap.remove();
    }

    var wrap = document.createElement('div');
    wrap.className = 'localitea-toast-wrap';
    wrap.setAttribute('aria-live', 'polite');
    wrap.setAttribute('aria-atomic', 'true');

    var toast = document.createElement('div');
    toast.className = 'localitea-toast' +
        (type === 'error' ? ' localitea-toast-error' : '');
    toast.setAttribute('role', 'status');

    var icon = document.createElement('span');
    icon.className = 'localitea-toast-icon';
    icon.innerHTML =
        type === 'error'
            ? '<i class="bi bi-x-circle"></i>'
            : '<i class="bi bi-check2-all"></i>';

    var messageEl = document.createElement('span');
    messageEl.className = 'localitea-toast-message';
    messageEl.textContent = message;

    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'localitea-toast-close';
    close.setAttribute('aria-label', 'Close notification');
    close.innerHTML = '<i class="bi bi-x-lg"></i>';

    var progress = document.createElement('span');
    progress.className = 'localitea-toast-progress';
    progress.setAttribute('aria-hidden', 'true');

    toast.appendChild(icon);
    toast.appendChild(messageEl);
    toast.appendChild(close);
    toast.appendChild(progress);
    wrap.appendChild(toast);
    document.body.appendChild(wrap);

    var timer;

    function closeToast() {
        clearTimeout(timer);

        if (!document.body.contains(toast)) {
            return;
        }

        toast.classList.add('is-closing');

        setTimeout(function () {
            if (wrap && wrap.parentNode) {
                wrap.parentNode.removeChild(wrap);
            }
        }, 190);
    }

    close.addEventListener('click', closeToast);
    timer = setTimeout(closeToast, 3500);
}


document.addEventListener('DOMContentLoaded', function () {

    const addToCartForm =
        document.querySelector(
            'form[data-ajax-form="true"][action="add-to-cart.php"]'
        );

    if (!addToCartForm) {
        return;
    }

    let addingToCart = false;

    addToCartForm.addEventListener(
        'submit',
        async function (event) {

            event.preventDefault();
            event.stopImmediatePropagation();

            if (addingToCart) {
                return;
            }

            addingToCart = true;

            const submitButton =
                addToCartForm.querySelector('button[type="submit"]');

            const originalButtonHTML =
                submitButton
                    ? submitButton.innerHTML
                    : '';

            if (submitButton) {
                submitButton.disabled = true;
                submitButton.innerHTML =
                    '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Adding...';
            }

            try {

                const formData =
                    new FormData(addToCartForm);

                formData.set('ajax', '1');

                const response =
                    await fetch(
                        addToCartForm.action ||
                        'add-to-cart.php',
                        {
                            method: 'POST',
                            body: formData,
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest',
                                'Accept': 'application/json'
                            },
                            cache: 'no-store',
                            credentials: 'same-origin'
                        }
                    );

                const contentType =
                    response.headers.get('content-type') || '';

                let data;

                if (contentType.includes('application/json')) {
                    data = await response.json();
                } else {
                    const responseText =
                        await response.text();

                    throw new Error(
                        responseText ||
                        'Unable to add the item to your cart.'
                    );
                }

                /*
                 * Normal item additions return success=true.
                 * Promotion BUY/BUNDLE steps may redirect after the
                 * item has already been stored, so those redirects are
                 * accepted when no error parameter is present.
                 */
                const hasSuccessfulAdd =
                    data &&
                    (
                        data.success === true ||
                        (
                            data.success === false &&
                            data.redirect &&
                            !/[?&]error=/.test(data.redirect)
                        )
                    );

                if (!response.ok || !hasSuccessfulAdd) {

                    throw new Error(
                        data && data.message
                            ? data.message
                            : 'Unable to add the item to your cart.'
                    );
                }

                // The success toast is displayed on menu.php after the redirect.
                window.location.href =
                    data.redirect || 'menu.php';

            } catch (error) {

                console.error(
                    'Add to cart failed:',
                    error
                );

                addingToCart = false;

                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.innerHTML =
                        originalButtonHTML;
                }

                showLocaliteaToast(
                    error && error.message
                        ? error.message
                        : 'Unable to add the item to your cart.',
                    'error'
                );
            }

        },
        true
    );

});
</script>

<?php require_once '../includes/footer.php'; ?>