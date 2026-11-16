<?php

require_once __DIR__ . '/promotion-engine.php';

function walkinPromotionImagePath(?string $image): string
{
    $image = trim((string)$image);
    if ($image === '' || $image === 'default-product.png' || $image === 'default.jpg') {
        return '../assets/images/default-product.png';
    }

    $image = ltrim(str_replace('\\', '/', $image), '/');
    if (strpos($image, 'assets/uploads/promotions/') === 0) {
        return '../' . $image;
    }
    if (strpos($image, 'uploads/promotions/') === 0) {
        return '../assets/' . $image;
    }

    return '../assets/uploads/promotions/' . $image;
}

function walkinBuildPromotionCartLines(
    PDO $pdo,
    array $promotion
): array {
    $ruleType = (string)($promotion['rule_type'] ?? '');
    $ruleItems = is_array($promotion['rule_items'] ?? null)
        ? $promotion['rule_items']
        : [];
    $itemsToAdd = [];

    if (in_array($ruleType, ['bogo', 'buy_x_get_y'], true)) {
        $buyItem = null;
        $getItem = null;

        foreach ($ruleItems as $ruleItem) {
            if (($ruleItem['role'] ?? '') === 'buy') {
                $buyItem = $ruleItem;
            } elseif (($ruleItem['role'] ?? '') === 'get') {
                $getItem = $ruleItem;
            }
        }

        if (!$buyItem || !$getItem) {
            throw new RuntimeException('This promotion is missing an available buy or reward item.');
        }

        $itemsToAdd[] = $buyItem;
    } elseif ($ruleType === 'bundle') {
        foreach ($ruleItems as $ruleItem) {
            if (($ruleItem['role'] ?? '') === 'bundle') {
                $itemsToAdd[] = $ruleItem;
            }
        }

        if (!$itemsToAdd) {
            throw new RuntimeException('This bundle promotion has no configured items.');
        }
    } elseif (in_array($ruleType, ['percentage', 'fixed'], true)) {
        if (!$ruleItems) {
            throw new RuntimeException('This promotion has no available qualifying product.');
        }

        $itemsToAdd[] = $ruleItems[0];
    } else {
        throw new RuntimeException('This promotion type is not supported for walk-in orders.');
    }

    $productsToValidate = $itemsToAdd;
    if (in_array($ruleType, ['bogo', 'buy_x_get_y'], true)) {
        $productsToValidate[] = $getItem;
    }

    $productStmt = $pdo->prepare("
        SELECT id, name, image, price, regular_price, grande_price,
               is_available, is_archived
        FROM products
        WHERE id = ?
        LIMIT 1
    ");
    $bundleConfigStmt = $pdo->prepare("
        SELECT 1
        FROM menu_bundle_options
        WHERE product_id = ?
          AND (size = ? OR size = '')
        LIMIT 1
    ");

    $validatedProducts = [];
    foreach ($productsToValidate as $ruleItem) {
        $productId = (int)($ruleItem['product_id'] ?? 0);
        $size = localiteaPromotionSizeLabel($ruleItem['size'] ?? null);
        $bundleConfigStmt->execute([$productId, $size]);
        if ($bundleConfigStmt->fetchColumn()) {
            throw new RuntimeException(
                'This promotion includes a product that requires flavor selections, which are not supported in walk-in promotions yet.'
            );
        }

        $productStmt->execute([$productId]);
        $product = $productStmt->fetch(PDO::FETCH_ASSOC);

        if (
            !$product ||
            (int)$product['is_available'] !== 1 ||
            (int)$product['is_archived'] !== 0
        ) {
            throw new RuntimeException('A product in this promotion is no longer available.');
        }

        $sizePrice = $size === 'Regular'
            ? (float)($product['regular_price'] ?? 0)
            : ($size === 'Grande'
                ? (float)($product['grande_price'] ?? 0)
                : (float)($product['price'] ?? 0));
        if (!is_finite($sizePrice) || $sizePrice <= 0) {
            throw new RuntimeException('A product in this promotion has no valid price for its configured size.');
        }

        $validatedProducts[$productId] = $product;
    }

    $lines = [];

    foreach ($itemsToAdd as $ruleItem) {
        $productId = (int)($ruleItem['product_id'] ?? 0);
        $product = $validatedProducts[$productId] ?? null;
        if (!$product) {
            throw new RuntimeException('A product in this promotion could not be verified.');
        }

        $size = localiteaPromotionSizeLabel($ruleItem['size'] ?? null);
        if ($size === 'Regular') {
            $unitPrice = (float)($product['regular_price'] ?? 0);
        } elseif ($size === 'Grande') {
            $unitPrice = (float)($product['grande_price'] ?? 0);
        } else {
            $unitPrice = (float)($product['price'] ?? 0);
        }

        if (!is_finite($unitPrice) || $unitPrice <= 0) {
            throw new RuntimeException('A product in this promotion has no valid price for its configured size.');
        }

        $quantity = max(1, (int)($ruleItem['quantity'] ?? 1));
        if ($ruleType === 'bogo' && ($ruleItem['role'] ?? '') === 'buy') {
            $quantity = 1;
        }
        if ($quantity > 50) {
            throw new RuntimeException('A promotion requires more items than the walk-in order limit allows.');
        }

        $slotId = $ruleType === 'bundle'
            ? (int)($ruleItem['item_id'] ?? 0)
            : 0;
        if ($ruleType === 'bundle' && $slotId <= 0) {
            throw new RuntimeException('A bundle promotion contains an invalid item slot.');
        }

        $role = (string)($ruleItem['role'] ?? 'qualifying');
        $key = md5(implode('|', [
            'promotion',
            (int)$promotion['id'],
            $role,
            $slotId,
            $productId,
            $size,
        ]));

        $lines[] = [
            'key' => $key,
            'item' => [
                'product_id' => $productId,
                'name' => (string)$product['name'],
                'image' => (string)($product['image'] ?? ''),
                'size' => $size,
                'addons' => [],
                'sugar_level' => '50%',
                'discount_type' => 'none',
                'discount_id_name' => '',
                'discount_id_number' => '',
                'price' => round($unitPrice, 2),
                'quantity' => $quantity,
                'promotion_source_id' => (int)$promotion['id'],
                'promotion_source_role' => $role,
                'promotion_slot_id' => $slotId,
                'is_free' => false,
            ],
        ];
    }

    return $lines;
}

function walkinAddPromotionToCart(
    PDO $pdo,
    array &$cart,
    int $promotionId
): void {
    if ($promotionId <= 0) {
        throw new RuntimeException('Select a valid promotion.');
    }

    $promotion = null;
    foreach (localiteaGetActivePromotionDefinitions($pdo) as $activePromotion) {
        if ((int)$activePromotion['id'] === $promotionId) {
            $promotion = $activePromotion;
            break;
        }
    }

    if (!$promotion) {
        throw new RuntimeException('This promotion is no longer active.');
    }

    foreach ($cart as $cartItem) {
        $existingPromotionId = (int)($cartItem['promotion_source_id'] ?? 0);
        if ($existingPromotionId > 0 && $existingPromotionId !== $promotionId) {
            throw new RuntimeException('Only one promotion can be selected per walk-in order. Clear the current order to choose another.');
        }
    }

    $newLines = walkinBuildPromotionCartLines($pdo, $promotion);

    foreach ($newLines as $line) {
        $existingQuantity = (int)($cart[$line['key']]['quantity'] ?? 0);
        if ($existingQuantity + (int)$line['item']['quantity'] > 50) {
            throw new RuntimeException('This promotion would exceed the 50-item walk-in quantity limit.');
        }
    }

    foreach ($newLines as $line) {
        $key = $line['key'];
        if (isset($cart[$key])) {
            $cart[$key]['quantity'] += (int)$line['item']['quantity'];
        } else {
            $cart[$key] = $line['item'];
        }
    }

    $_SESSION['selected_promotion_id'] = $promotionId;
}

function walkinCalculatePricing(
    PDO $pdo,
    array $cart,
    float $subtotal,
    float $discountEligibleBase,
    array $discountTypes,
    array $discountRates
): array {
    if (count($discountTypes) > 1) {
        throw new RuntimeException('Only one discount type is allowed per walk-in order.');
    }

    $selectedDiscountType = isset($discountTypes['pwd'])
        ? 'pwd'
        : (isset($discountTypes['senior']) ? 'senior' : 'none');
    $selectedDiscountRate = $selectedDiscountType === 'none'
        ? 0.0
        : (float)($discountRates[$selectedDiscountType] ?? 0);
    $customerDiscount = round(
        $discountEligibleBase * ($selectedDiscountRate / 100),
        2
    );

    $candidatePromotion = localiteaApplyBestPromotion(
        $pdo,
        $cart,
        $subtotal
    );
    $promotionDiscount = round(
        (float)($candidatePromotion['discount'] ?? 0),
        2
    );
    $rewardItems = is_array($candidatePromotion['reward_items'] ?? null)
        ? $candidatePromotion['reward_items']
        : [];
    $addedRewardValue = round(
        (float)($candidatePromotion['added_reward_value'] ?? 0),
        2
    );

    $discountType = 'none';
    $discountRate = 0.0;
    $discountAmount = 0.0;
    $appliedPromotion = $candidatePromotion;

    if (
        $selectedDiscountType !== 'none' &&
        $customerDiscount > $promotionDiscount
    ) {
        $discountType = $selectedDiscountType;
        $discountRate = $selectedDiscountRate;
        $discountAmount = $customerDiscount;
        $promotionDiscount = 0.0;
        $rewardItems = [];
        $addedRewardValue = 0.0;
        $appliedPromotion = [];
    } elseif ($promotionDiscount <= 0) {
        $appliedPromotion = [];
    }

    $displaySubtotal = round($subtotal + $addedRewardValue, 2);

    return [
        'promotion' => $appliedPromotion,
        'candidate_promotion' => $candidatePromotion,
        'promotion_discount' => $promotionDiscount,
        'promotion_reward_items' => $rewardItems,
        'promotion_added_reward_value' => $addedRewardValue,
        'subtotal' => $displaySubtotal,
        'discount_type' => $discountType,
        'discount_rate' => $discountRate,
        'discount_amount' => $discountAmount,
        'total' => round(max(0, $displaySubtotal - $promotionDiscount - $discountAmount), 2),
    ];
}

function walkinRenderPromotions(
    array $promotions,
    string $csrfToken,
    string $category
): void {
    $ruleLabels = [
        'bogo' => 'Buy 1, Get 1 Free',
        'buy_x_get_y' => 'Buy X, Get Y Free',
        'bundle' => 'Bundle Deal',
        'percentage' => 'Percentage Discount',
        'fixed' => 'Fixed Amount Discount',
    ];
    ?>
    <div class="walkin-product-content">
        <div class="walkin-section-heading">
            <div>
                <div class="walkin-eyebrow">Menu</div>
                <h2>Active Promotions</h2>
            </div>
            <span class="walkin-item-count"><?= count($promotions) ?> deal<?= count($promotions) === 1 ? '' : 's' ?></span>
        </div>

        <?php if (!$promotions): ?>
            <div class="walkin-empty-state">
                <i class="bi bi-tag"></i>
                <h5>No active promotions</h5>
                <p>There are no available promotions today.</p>
            </div>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($promotions as $promotion): ?>
                    <?php
                    $ruleType = (string)($promotion['rule_type'] ?? '');
                    $ruleItems = is_array($promotion['rule_items'] ?? null)
                        ? $promotion['rule_items']
                        : [];
                    $summary = $ruleLabels[$ruleType] ?? 'Promotion';
                    if ($ruleType === 'percentage') {
                        $summary = number_format((float)$promotion['discount_value'], 0) . '% off';
                    } elseif ($ruleType === 'fixed') {
                        $summary = '₱' . number_format((float)$promotion['discount_value'], 2) . ' off';
                    } elseif ($ruleType === 'bundle') {
                        $summary = 'Bundle price ₱' . number_format((float)$promotion['bundle_price'], 2);
                    } elseif ($ruleType === 'buy_x_get_y') {
                        $buyItem = null;
                        $getItem = null;
                        foreach ($ruleItems as $ruleItem) {
                            if (($ruleItem['role'] ?? '') === 'buy') {
                                $buyItem = $ruleItem;
                            } elseif (($ruleItem['role'] ?? '') === 'get') {
                                $getItem = $ruleItem;
                            }
                        }
                        if ($buyItem && $getItem) {
                            $summary = 'Buy ' . max(1, (int)($buyItem['quantity'] ?? 1))
                                . ', get ' . max(1, (int)($getItem['quantity'] ?? 1)) . ' free';
                        }
                    }

                    $itemsText = [];
                    foreach ($ruleItems as $ruleItem) {
                        $itemLabel = (string)($ruleItem['product_name'] ?? 'Product');
                        $itemSize = localiteaPromotionSizeLabel($ruleItem['size'] ?? null);
                        if ($itemSize !== '') {
                            $itemLabel .= ' (' . $itemSize . ')';
                        }
                        if (in_array($ruleType, ['bogo', 'buy_x_get_y'], true)) {
                            $itemLabel = ucfirst((string)$ruleItem['role']) . ': ' . $itemLabel;
                        }
                        $itemsText[] = $itemLabel;
                    }
                    ?>
                    <div class="col-12 col-md-6 col-xl-4">
                        <form method="POST" class="h-100">
                            <input type="hidden" name="action" value="add_promotion">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="promotion_id" value="<?= (int)$promotion['id'] ?>">
                            <input type="hidden" name="category" value="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>">
                            <button type="submit" class="walkin-promotion-card">
                                <span class="walkin-promotion-image-wrap">
                                    <img
                                        src="<?= htmlspecialchars(walkinPromotionImagePath($promotion['image'] ?? null), ENT_QUOTES, 'UTF-8') ?>"
                                        alt="<?= htmlspecialchars((string)$promotion['title'], ENT_QUOTES, 'UTF-8') ?>"
                                        onerror="this.onerror=null;this.src='../assets/images/default-product.png';"
                                    >
                                    <span class="walkin-promotion-type">
                                        <?= htmlspecialchars($ruleLabels[$ruleType] ?? 'Promotion') ?>
                                    </span>
                                </span>
                                <span class="walkin-promotion-card-body">
                                    <span class="walkin-promotion-title"><?= htmlspecialchars((string)$promotion['title']) ?></span>
                                    <span class="walkin-promotion-summary"><?= htmlspecialchars($summary) ?></span>
                                    <?php if (trim((string)($promotion['description'] ?? '')) !== ''): ?>
                                        <span class="walkin-promotion-description"><?= nl2br(htmlspecialchars((string)$promotion['description'])) ?></span>
                                    <?php endif; ?>
                                    <?php if ($itemsText): ?>
                                        <span class="walkin-promotion-items"><?= htmlspecialchars(implode(' · ', $itemsText)) ?></span>
                                    <?php endif; ?>
                                    <span class="walkin-promotion-action">
                                        <i class="bi bi-tag-fill me-1"></i> Add Promotion to Order
                                    </span>
                                </span>
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="walkin-promotion-note">
            Only one promotion is selected per order. PWD/Senior discounts do not stack with promotions; the larger discount is applied.
        </div>
    </div>
    <?php
}
