<?php
/**
 * Localitea promotion engine.
 *
 * Supported promotion types:
 * - bogo: buy 1, get 1 free of the same product
 * - buy_x_get_y: buy X of product A, get Y of product B free
 * - bundle: selected products together for a fixed bundle price
 * - percentage: percentage off one product
 * - fixed: fixed peso amount off one product
 *
 * Important:
 * Buy/Get promotions can use a customer-customized GET line from the
 * session cart. The drink base is covered by the promotion, while selected
 * add-ons remain payable. The same rule is applied to generated legacy reward
 * lines.
 */

function localiteaNormalizePromotionSize(?string $size): ?string
{
    $size = strtolower(trim((string)$size));

    return in_array($size, ['regular', 'grande'], true)
        ? $size
        : null;
}

function localiteaPromotionSizeLabel(?string $size): string
{
    $size = localiteaNormalizePromotionSize($size);

    return $size === 'regular'
        ? 'Regular'
        : ($size === 'grande' ? 'Grande' : '');
}

/**
 * Return the stored cart line's base price and add-on price separately.
 *
 * Buy/Get promotions make only the drink itself free. Add-ons selected on
 * the free Get item remain payable. The cart line still keeps its normal
 * full unit price (base + add-ons), so this helper is used only when the
 * promotion discount is calculated.
 */
function localiteaCalculateItemPricing(PDO $pdo, array $item): array
{
    $productId = (int)($item['product_id'] ?? 0);
    $fallbackPrice = max(0.0, (float)($item['price'] ?? 0));

    if ($productId <= 0) {
        return [
            'base_unit_price' => $fallbackPrice,
            'addons_unit_price' => 0.0,
            'full_unit_price' => $fallbackPrice,
        ];
    }

    $stmt = $pdo->prepare("
        SELECT
            price,
            regular_price,
            grande_price
        FROM products
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    $basePrice = $fallbackPrice;

    if ($product) {
        $basePrice = (float)($product['price'] ?? 0);
        $size = localiteaNormalizePromotionSize($item['size'] ?? null);

        if (
            $size === 'regular' &&
            (float)($product['regular_price'] ?? 0) > 0
        ) {
            $basePrice = (float)$product['regular_price'];
        } elseif (
            $size === 'grande' &&
            (float)($product['grande_price'] ?? 0) > 0
        ) {
            $basePrice = (float)$product['grande_price'];
        }
    }

    $addonsUnitPrice = 0.0;
    $addons = $item['addons'] ?? [];

    if (!is_array($addons)) {
        $addons = [$addons];
    }

    $addons = array_values(array_filter(
        array_map('trim', $addons),
        static fn($addon): bool => $addon !== ''
    ));

    if ($productId > 0 && !empty($addons)) {
        $placeholders = implode(',', array_fill(0, count($addons), '?'));
        $addonStmt = $pdo->prepare("
            SELECT COALESCE(SUM(a.price), 0)
            FROM addons a
            INNER JOIN product_addons pa
                ON pa.addon_id = a.id
            WHERE pa.product_id = ?
              AND a.name IN ($placeholders)
              AND a.is_available = 1
              AND a.is_archived = 0
        ");
        $addonStmt->execute(array_merge([$productId], $addons));
        $addonsUnitPrice = (float)$addonStmt->fetchColumn();
    }

    $basePrice = max(0.0, $basePrice);
    $addonsUnitPrice = max(0.0, $addonsUnitPrice);

    return [
        'base_unit_price' => round($basePrice, 2),
        'addons_unit_price' => round($addonsUnitPrice, 2),
        'full_unit_price' => round($basePrice + $addonsUnitPrice, 2),
    ];
}

function localiteaGetActivePromotionDefinitions(PDO $pdo, ?string $date = null): array
{
    $date = $date ?: date('Y-m-d');

    $stmt = $pdo->prepare("
        SELECT
            p.id,
            p.title,
            p.description,
            p.start_date,
            p.end_date,
            r.id AS rule_id,
            r.rule_type,
            r.buy_quantity,
            r.get_quantity,
            r.discount_value,
            r.bundle_price
        FROM promotions p
        INNER JOIN promotion_rules r
            ON r.promotion_id = p.id
        WHERE p.is_active = 1
          AND p.is_archived = 0
          AND p.start_date <= ?
          AND p.end_date >= ?
        ORDER BY p.created_at DESC, p.id DESC
    ");

    $stmt->execute([$date, $date]);
    $promotions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$promotions) {
        return [];
    }

    $ruleIds = array_map(
        static fn(array $promotion): int => (int)$promotion['rule_id'],
        $promotions
    );

    $placeholders = implode(
        ',',
        array_fill(0, count($ruleIds), '?')
    );

    /*
     * Only products that are still active and available can qualify.
     * This prevents a promotion from applying to an archived or
     * currently unavailable product.
     */
    $itemStmt = $pdo->prepare("
        SELECT
            pri.rule_id,
            pri.product_id,
            pri.role,
            pri.quantity,
            pri.size,
            p.name AS product_name
        FROM promotion_rule_items pri
        INNER JOIN products p
            ON p.id = pri.product_id
        WHERE pri.rule_id IN ({$placeholders})
          AND p.is_archived = 0
          AND p.is_available = 1
        ORDER BY pri.rule_id ASC, pri.id ASC
    ");

    $itemStmt->execute($ruleIds);

    $itemsByRule = [];

    foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $itemsByRule[(int)$item['rule_id']][] = $item;
    }

    foreach ($promotions as &$promotion) {
        $promotion['rule_items'] =
            $itemsByRule[(int)$promotion['rule_id']] ?? [];
    }

    unset($promotion);

    return $promotions;
}

function localiteaBuildCartProductSummary(array $cart): array
{
    $summary = [];

    foreach ($cart as $item) {
        $productId = (int)($item['product_id'] ?? 0);
        $quantity = (int)($item['quantity'] ?? 0);
        $price = (float)($item['price'] ?? 0);

        if (
            $productId <= 0 ||
            $quantity <= 0 ||
            $price < 0
        ) {
            continue;
        }

        $size = localiteaNormalizePromotionSize(
            $item['size'] ?? null
        );
        $sizeKey = $size ?? 'any';
        $lineSubtotal = $price * $quantity;

        if (!isset($summary[$productId])) {
            $summary[$productId] = [
                'quantity' => 0,
                'subtotal' => 0.0,
                'unit_price' => $price,
                'min_unit_price' => $price,
                'sizes' => [],
            ];
        }

        if (!isset($summary[$productId]['sizes'][$sizeKey])) {
            $summary[$productId]['sizes'][$sizeKey] = [
                'quantity' => 0,
                'subtotal' => 0.0,
                'unit_price' => $price,
                'min_unit_price' => $price,
            ];
        }

        $summary[$productId]['quantity'] += $quantity;
        $summary[$productId]['subtotal'] += $lineSubtotal;
        $summary[$productId]['min_unit_price'] = min(
            (float)$summary[$productId]['min_unit_price'],
            $price
        );

        $summary[$productId]['sizes'][$sizeKey]['quantity'] += $quantity;
        $summary[$productId]['sizes'][$sizeKey]['subtotal'] += $lineSubtotal;
        $summary[$productId]['sizes'][$sizeKey]['min_unit_price'] = min(
            (float)$summary[$productId]['sizes'][$sizeKey]['min_unit_price'],
            $price
        );
    }

    foreach ($summary as &$product) {
        $product['subtotal'] = round(
            (float)$product['subtotal'],
            2
        );

        $product['unit_price'] = round(
            $product['subtotal'] /
                max(1, $product['quantity']),
            2
        );

        $product['min_unit_price'] = round(
            (float)$product['min_unit_price'],
            2
        );

        foreach ($product['sizes'] as &$sizeSummary) {
            $sizeSummary['subtotal'] = round(
                (float)$sizeSummary['subtotal'],
                2
            );

            $sizeSummary['unit_price'] = round(
                $sizeSummary['subtotal'] /
                    max(1, $sizeSummary['quantity']),
                2
            );

            $sizeSummary['min_unit_price'] = round(
                (float)$sizeSummary['min_unit_price'],
                2
            );
        }

        unset($sizeSummary);
    }

    unset($product);

    return $summary;
}

function localiteaGetPromotionCartSummary(
    array $cartSummary,
    int $productId,
    ?string $size = null
): ?array {
    if (!isset($cartSummary[$productId])) {
        return null;
    }

    $size = localiteaNormalizePromotionSize($size);

    if ($size === null) {
        return $cartSummary[$productId];
    }

    return $cartSummary[$productId]['sizes'][$size] ?? null;
}

/**
 * Find cart lines for a product, cheapest first.
 * Keys are preserved because checkout needs to know which original
 * cart quantities became free.
 */
function localiteaGetProductCartLines(
    array $cart,
    int $productId,
    ?string $size = null
): array {
    $lines = [];
    $size = localiteaNormalizePromotionSize($size);

    foreach ($cart as $key => $item) {
        if ((int)($item['product_id'] ?? 0) !== $productId) {
            continue;
        }

        $quantity = (int)($item['quantity'] ?? 0);
        $price = (float)($item['price'] ?? 0);

        if ($quantity <= 0 || $price < 0) {
            continue;
        }

        $itemSize = localiteaNormalizePromotionSize(
            $item['size'] ?? null
        );

        if ($size !== null && $itemSize !== $size) {
            continue;
        }

        $lines[] = [
            'key' => (string)$key,
            'item' => $item,
            'quantity' => $quantity,
            'price' => $price,
        ];
    }

    usort(
        $lines,
        static function (array $a, array $b): int {
            return $a['price'] <=> $b['price'];
        }
    );

    return $lines;
}

/**
 * Create a free reward line from an existing cart line.
 * Its normal price is retained so the promotion engine can calculate
 * the promotion value and the order can keep a consistent subtotal.
 */
function localiteaBuildFreeRewardFromCartLine(
    array $cartLine,
    int $quantity
): array {
    $item = $cartLine['item'];

    $reward = $item;
    $reward['product_id'] = (int)($item['product_id'] ?? 0);
    $reward['name'] = trim((string)($item['name'] ?? ''));
    $reward['price'] = max(0, (float)($item['price'] ?? 0));
    $reward['quantity'] = max(1, $quantity);
    $reward['subtotal'] = round(
        $reward['price'] * $reward['quantity'],
        2
    );

    if (array_key_exists('size', $item)) {
        $reward['size'] = localiteaPromotionSizeLabel(
            $item['size'] ?? null
        );
    }

    $reward['is_free'] = true;
    $reward['source_cart_key'] = (string)$cartLine['key'];

    return $reward;
}

/**
 * Build a free reward from a paid cart line while forcing the reward to use
 * the promotion's configured Get size. The customer's sugar level and
 * add-ons are preserved. The drink base is free, while any selected add-ons
 * remain payable.
 */
function localiteaBuildFreeRewardAtSize(
    PDO $pdo,
    array $cartLine,
    int $quantity,
    ?string $targetSize
): ?array {
    $targetSize = localiteaNormalizePromotionSize($targetSize);

    if ($targetSize === null) {
        return localiteaBuildFreeRewardFromCartLine(
            $cartLine,
            $quantity
        );
    }

    $item = $cartLine['item'];
    $productId = (int)($item['product_id'] ?? 0);

    if ($productId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT
            price,
            regular_price,
            grande_price,
            is_available,
            is_archived
        FROM products
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([$productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        return null;
    }

    if (
        (int)$product['is_available'] !== 1 ||
        (int)$product['is_archived'] !== 0
    ) {
        return null;
    }

    $price = $targetSize === 'regular'
        ? (float)($product['regular_price'] ?? 0)
        : (float)($product['grande_price'] ?? 0);

    if ($price <= 0) {
        return null;
    }

    $reward = localiteaBuildFreeRewardFromCartLine(
        $cartLine,
        $quantity
    );

    /*
     * The cart-line price normally already includes its add-ons.
     * When changing only the reward size, rebuild the total using the
     * configured target-size base price plus the same valid add-ons.
     */
    $addonsTotal = 0.0;
    $addons = $item['addons'] ?? [];

    if (is_array($addons) && $addons) {
        $placeholders = implode(',', array_fill(0, count($addons), '?'));
        $addonStmt = $pdo->prepare("
            SELECT COALESCE(SUM(a.price), 0)
            FROM addons a
            INNER JOIN product_addons pa
                ON pa.addon_id = a.id
            WHERE pa.product_id = ?
              AND a.name IN ($placeholders)
              AND a.is_available = 1
              AND a.is_archived = 0
        " );
        $addonStmt->execute(array_merge([$productId], $addons));
        $addonsTotal = (float)$addonStmt->fetchColumn();
    }

    $price = $price + $addonsTotal;

    $reward['size'] = localiteaPromotionSizeLabel($targetSize);
    $reward['price'] = round($price, 2);
    $reward['subtotal'] = round(
        $price * max(1, $quantity),
        2
    );
    $reward['base_subtotal'] = round(
        ((float)($price - $addonsTotal)) * max(1, $quantity),
        2
    );
    $reward['addons_subtotal'] = round(
        $addonsTotal * max(1, $quantity),
        2
    );

    return $reward;
}

/**
 * Build a free reward line for a product that is not already in the cart.
 * The requested promotion size is used when provided; otherwise the first
 * enabled product size is used as the default reward size.
 */
function localiteaBuildDefaultFreeReward(
    PDO $pdo,
    int $productId,
    int $quantity,
    ?string $requestedSize = null
): ?array {
    $stmt = $pdo->prepare("
        SELECT
            id,
            name,
            price,
            regular_price,
            grande_price,
            is_available,
            is_archived
        FROM products
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([$productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        return null;
    }

    if (
        (int)$product['is_available'] !== 1 ||
        (int)$product['is_archived'] !== 0
    ) {
        return null;
    }

    $requestedSize = localiteaNormalizePromotionSize($requestedSize);
    $size = '';
    $price = (float)$product['price'];

    if (
        $requestedSize === 'regular' &&
        (float)($product['regular_price'] ?? 0) > 0
    ) {
        $size = 'Regular';
        $price = (float)$product['regular_price'];
    } elseif (
        $requestedSize === 'grande' &&
        (float)($product['grande_price'] ?? 0) > 0
    ) {
        $size = 'Grande';
        $price = (float)$product['grande_price'];
    } elseif ($requestedSize !== null) {
        return null;
    } elseif ((float)($product['regular_price'] ?? 0) > 0) {
        $size = 'Regular';
        $price = (float)$product['regular_price'];
    } elseif ((float)($product['grande_price'] ?? 0) > 0) {
        $size = 'Grande';
        $price = (float)$product['grande_price'];
    }

    $quantity = max(1, $quantity);

    return [
        'product_id' => (int)$product['id'],
        'name' => trim((string)$product['name']),
        'price' => round(max(0, $price), 2),
        'quantity' => $quantity,
        'subtotal' => round($price * $quantity, 2),
        'base_subtotal' => round($price * $quantity, 2),
        'addons_subtotal' => 0.0,
        'size' => $size,
        'sugar_level' => '',
        'addons' => [],
        'is_free' => true,
        'source_cart_key' => null,
    ];
}

/**
 * Evaluate one promotion and return both its savings and any free items.
 */
function localiteaEvaluatePromotion(
    PDO $pdo,
    array $promotion,
    array $cart,
    array $cartSummary
): array {
    $result = [
        'discount' => 0.0,
        'reward_items' => [],
        'free_allocations' => [],
        'added_reward_value' => 0.0,
        'message' => null,
    ];

    $ruleType = $promotion['rule_type'] ?? '';
    $items = $promotion['rule_items'] ?? [];

    if (!$items) {
        return $result;
    }

    /*
     * ---------------------------------------------------------------
     * BUY 1 TAKE 1 / BUY X GET Y
     * ---------------------------------------------------------------
     */
    if (
        $ruleType === 'bogo' ||
        $ruleType === 'buy_x_get_y'
    ) {
        $buyItem = null;
        $getItem = null;

        foreach ($items as $item) {
            if (($item['role'] ?? '') === 'buy') {
                $buyItem = $item;
            }

            if (($item['role'] ?? '') === 'get') {
                $getItem = $item;
            }
        }

        if (!$buyItem || !$getItem) {
            return $result;
        }

        $buyProductId = (int)$buyItem['product_id'];
        $getProductId = (int)$getItem['product_id'];
        $buySize = localiteaNormalizePromotionSize(
            $buyItem['size'] ?? null
        );
        $getSize = localiteaNormalizePromotionSize(
            $getItem['size'] ?? null
        );

        $buyQty = $ruleType === 'bogo'
            ? 1
            : max(1, (int)$buyItem['quantity']);

        $getQty = $ruleType === 'bogo'
            ? 1
            : max(1, (int)$getItem['quantity']);

        /*
         * BOGO is strictly same-product + same-size.
         * Different sizes belong under Buy X Get Y instead.
         */
        if (
            $ruleType === 'bogo' &&
            (
                $buyProductId !== $getProductId ||
                $buySize !== $getSize
            )
        ) {
            return $result;
        }

        /*
         * Qualification is always based on the configured BUY size.
         *
         * Example:
         * Buy 1 Regular -> Get 1 Grande
         *
         * The customer must place the paid Regular item. The promotion
         * engine creates the free Grande item separately below.
         */
        /*
         * Qualification must count the BUY side only. A customized GET
         * line is also stored in the session cart, so using the generic
         * product summary here would incorrectly count the free item as
         * another paid BUY unit for same-product BOGO promotions.
         */
        $promotionId = (int)($promotion['id'] ?? 0);
        $availableBuy = 0;
        $hasTaggedBuyLines = false;

        foreach ($cart as $cartKey => $cartItem) {
            if ((int)($cartItem['product_id'] ?? 0) !== $buyProductId) {
                continue;
            }

            $cartItemSize = localiteaNormalizePromotionSize(
                $cartItem['size'] ?? null
            );

            if ($buySize !== null && $cartItemSize !== $buySize) {
                continue;
            }

            $sourceId = (int)($cartItem['promotion_source_id'] ?? 0);
            $sourceRole = (string)($cartItem['promotion_source_role'] ?? '');

            if ($promotionId > 0 && $sourceId === $promotionId && $sourceRole === 'buy') {
                $hasTaggedBuyLines = true;
                $availableBuy += max(0, (int)($cartItem['quantity'] ?? 0));
            }
        }

        /* Backward-compatible fallback for normal cart orders. */
        if (!$hasTaggedBuyLines) {
            foreach ($cart as $cartItem) {
                if ((int)($cartItem['product_id'] ?? 0) !== $buyProductId) {
                    continue;
                }

                $cartItemSize = localiteaNormalizePromotionSize(
                    $cartItem['size'] ?? null
                );

                if ($buySize !== null && $cartItemSize !== $buySize) {
                    continue;
                }

                $sourceId = (int)($cartItem['promotion_source_id'] ?? 0);
                $sourceRole = (string)($cartItem['promotion_source_role'] ?? '');

                /* Never count a customized GET/free line as BUY quantity. */
                if ($promotionId > 0 && $sourceId === $promotionId && $sourceRole === 'get') {
                    continue;
                }

                $availableBuy += max(0, (int)($cartItem['quantity'] ?? 0));
            }
        }

        if ($availableBuy < $buyQty) {
            return $result;
        }

        $sets = intdiv(
            $availableBuy,
            $buyQty
        );

        $desiredFreeUnits = $sets * $getQty;

        if ($desiredFreeUnits <= 0) {
            return $result;
        }

        /*
         * Same-product promotions have two possible behaviors:
         *
         * 1. Same size (e.g. Regular -> Regular): reuse the paid line
         *    as the free-item template.
         *
         * 2. Different sizes (e.g. Regular -> Grande): the customer still
         *    buys the configured BUY size. The free item is created from
         *    that paid line, but its size and price are changed to the
         *    configured GET size. Sugar level and add-ons are preserved.
         */
        if ($buyProductId === $getProductId) {
            /*
             * A GET line created by the customer is now the authoritative
             * free-item customization. It is already present in the cart,
             * so mark its quantity as free and use its own sugar/add-ons.
             */
            $customGetLines = [];
            foreach ($cart as $cartKey => $cartItem) {
                if (
                    (int)($cartItem['product_id'] ?? 0) !== $getProductId ||
                    (int)($cartItem['promotion_source_id'] ?? 0) !== $promotionId ||
                    ($cartItem['promotion_source_role'] ?? '') !== 'get'
                ) {
                    continue;
                }

                $cartItemSize = localiteaNormalizePromotionSize(
                    $cartItem['size'] ?? null
                );

                if ($getSize !== null && $cartItemSize !== $getSize) {
                    continue;
                }

                $customGetLines[] = [
                    'key' => (string)$cartKey,
                    'item' => $cartItem,
                    'quantity' => max(0, (int)($cartItem['quantity'] ?? 0)),
                    'price' => max(0, (float)($cartItem['price'] ?? 0)),
                ];
            }

            if ($customGetLines) {
                $remainingFree = $desiredFreeUnits;

                foreach ($customGetLines as $getLine) {
                    if ($remainingFree <= 0) {
                        break;
                    }

                    $freeQty = min($getLine['quantity'], $remainingFree);
                    if ($freeQty <= 0) {
                        continue;
                    }

                    $reward = localiteaBuildFreeRewardFromCartLine(
                        $getLine,
                        $freeQty
                    );

                    $pricing = localiteaCalculateItemPricing(
                        $pdo,
                        $reward
                    );
                    $freeBaseSubtotal = round(
                        $pricing['base_unit_price'] * $freeQty,
                        2
                    );
                    $freeAddonSubtotal = round(
                        $pricing['addons_unit_price'] * $freeQty,
                        2
                    );

                    $reward['base_subtotal'] = $freeBaseSubtotal;
                    $reward['addons_subtotal'] = $freeAddonSubtotal;
                    $result['reward_items'][] = $reward;
                    $result['free_allocations'][$getLine['key']] =
                        ($result['free_allocations'][$getLine['key']] ?? 0) + $freeQty;
                    $result['discount'] += $freeBaseSubtotal;
                    $remainingFree -= $freeQty;
                }

                if ($remainingFree <= 0) {
                    $result['discount'] = round($result['discount'], 2);
                    return $result;
                }
            }

            /*
             * Legacy fallback: older carts may only contain the BUY line.
             * In that case the free item is generated from the BUY
             * customization, preserving the old behavior.
             */
            if (
                $buySize !== null &&
                $getSize !== null &&
                $buySize !== $getSize
            ) {
                $buyLines = localiteaGetProductCartLines(
                    $cart,
                    $buyProductId,
                    $buySize
                );

                if (!$buyLines) {
                    return $result;
                }

                $templateLine = null;

                foreach ($buyLines as $candidateLine) {
                    $candidateItem = $candidateLine['item'];

                    if (
                        (int)($candidateItem['promotion_source_id'] ?? 0) === $promotionId &&
                        ($candidateItem['promotion_source_role'] ?? '') === 'buy'
                    ) {
                        $templateLine = $candidateLine;
                        break;
                    }
                }

                $templateLine = $templateLine ?? $buyLines[0];

                $reward = localiteaBuildFreeRewardAtSize(
                    $pdo,
                    $templateLine,
                    $desiredFreeUnits,
                    $getSize
                );

                if (!$reward) {
                    return $result;
                }

                $pricing = localiteaCalculateItemPricing(
                    $pdo,
                    $reward
                );
                $baseSubtotal = round(
                    $pricing['base_unit_price'] * $desiredFreeUnits,
                    2
                );
                $reward['base_subtotal'] = $baseSubtotal;
                $reward['addons_subtotal'] = round(
                    $pricing['addons_unit_price'] * $desiredFreeUnits,
                    2
                );
                $result['reward_items'][] = $reward;
                $result['discount'] = $baseSubtotal;
                $result['added_reward_value'] = round(
                    (float)$reward['subtotal'],
                    2
                );

                return $result;
            }

            $buyLines = localiteaGetProductCartLines(
                $cart,
                $buyProductId,
                $buySize
            );

            if (!$buyLines) {
                return $result;
            }

            $templateLine = null;

            foreach ($buyLines as $candidateLine) {
                $candidateItem = $candidateLine['item'];

                if (
                    (int)($candidateItem['promotion_source_id'] ?? 0) === $promotionId &&
                    ($candidateItem['promotion_source_role'] ?? '') === 'buy'
                ) {
                    $templateLine = $candidateLine;
                    break;
                }
            }

            $templateLine = $templateLine ?? $buyLines[0];

            $reward = localiteaBuildFreeRewardFromCartLine(
                $templateLine,
                $desiredFreeUnits
            );

            $pricing = localiteaCalculateItemPricing(
                $pdo,
                $reward
            );
            $baseSubtotal = round(
                $pricing['base_unit_price'] * $desiredFreeUnits,
                2
            );
            $reward['base_subtotal'] = $baseSubtotal;
            $reward['addons_subtotal'] = round(
                $pricing['addons_unit_price'] * $desiredFreeUnits,
                2
            );
            $result['reward_items'][] = $reward;
            $result['discount'] = $baseSubtotal;
            $result['added_reward_value'] = round(
                (float)$reward['subtotal'],
                2
            );

            return $result;
        }

        /*
         * Different buy/get products:
         * - existing Get items can become free;
         * - missing Get items are added automatically as free items.
         *
         * We allocate the free units to the cheapest matching cart
         * lines first so a higher-priced Get item is not accidentally
         * chosen over a cheaper one.
         */
        $remainingFree = $desiredFreeUnits;

        $getLines = [];
        foreach ($cart as $cartKey => $cartItem) {
            if (
                (int)($cartItem['product_id'] ?? 0) !== $getProductId ||
                (int)($cartItem['promotion_source_id'] ?? 0) !== $promotionId ||
                ($cartItem['promotion_source_role'] ?? '') !== 'get'
            ) {
                continue;
            }

            $cartItemSize = localiteaNormalizePromotionSize(
                $cartItem['size'] ?? null
            );

            if ($getSize !== null && $cartItemSize !== $getSize) {
                continue;
            }

            $getLines[] = [
                'key' => (string)$cartKey,
                'item' => $cartItem,
                'quantity' => max(0, (int)($cartItem['quantity'] ?? 0)),
                'price' => max(0, (float)($cartItem['price'] ?? 0)),
            ];
        }

        foreach ($getLines as $cartLine) {
            if ($remainingFree <= 0) {
                break;
            }

            $freeQty = min(
                $cartLine['quantity'],
                $remainingFree
            );

            if ($freeQty <= 0) {
                continue;
            }

            $reward = localiteaBuildFreeRewardFromCartLine(
                $cartLine,
                $freeQty
            );

            $pricing = localiteaCalculateItemPricing(
                $pdo,
                $reward
            );
            $freeBaseSubtotal = round(
                $pricing['base_unit_price'] * $freeQty,
                2
            );
            $freeAddonSubtotal = round(
                $pricing['addons_unit_price'] * $freeQty,
                2
            );

            $reward['base_subtotal'] = $freeBaseSubtotal;
            $reward['addons_subtotal'] = $freeAddonSubtotal;
            $result['reward_items'][] = $reward;

            $result['free_allocations'][$cartLine['key']] =
                ($result['free_allocations'][$cartLine['key']] ?? 0) +
                $freeQty;

            $result['discount'] += $freeBaseSubtotal;
            $remainingFree -= $freeQty;
        }

        /*
         * Any reward quantity not already present in the cart must be
         * added automatically.
         */
        if ($remainingFree > 0) {
            $defaultReward = localiteaBuildDefaultFreeReward(
                $pdo,
                $getProductId,
                $remainingFree,
                $getSize
            );

            if (!$defaultReward) {
                /* The promotion cannot be fulfilled safely. */
                return [
                    'discount' => 0.0,
                    'reward_items' => [],
                    'free_allocations' => [],
                    'added_reward_value' => 0.0,
                    'message' => null,
                ];
            }

            $defaultBaseSubtotal = round(
                (float)($defaultReward['base_subtotal'] ??
                    $defaultReward['subtotal'] ?? 0),
                2
            );

            $result['reward_items'][] = $defaultReward;
            $result['discount'] +=
                $defaultBaseSubtotal;
            $result['added_reward_value'] +=
                (float)$defaultReward['subtotal'];
        }

        $result['discount'] = round(
            $result['discount'],
            2
        );

        $result['added_reward_value'] = round(
            $result['added_reward_value'],
            2
        );

        return $result;
    }

    /*
     * ---------------------------------------------------------------
     * BUNDLE
     * ---------------------------------------------------------------
     */
    if ($ruleType === 'bundle') {
        $sets = null;
        $normalBundlePrice = 0.0;

        /*
         * Bundle discount is based only on the configured drink size prices.
         * Add-ons are extras and must remain fully chargeable.
         */
        $bundleProductPriceStmt = $pdo->prepare("
            SELECT price, regular_price, grande_price
            FROM products
            WHERE id = ?
              AND is_archived = 0
            LIMIT 1
        ");

        foreach ($items as $item) {
            $productId = (int)$item['product_id'];
            $requiredQty = max(
                1,
                (int)$item['quantity']
            );
            $size = localiteaNormalizePromotionSize(
                $item['size'] ?? null
            );

            $itemSummary = localiteaGetPromotionCartSummary(
                $cartSummary,
                $productId,
                $size
            );

            $availableQty = (int)(
                $itemSummary['quantity'] ?? 0
            );

            if ($availableQty < $requiredQty) {
                return $result;
            }

            $possibleSets = intdiv(
                $availableQty,
                $requiredQty
            );

            $sets = $sets === null
                ? $possibleSets
                : min($sets, $possibleSets);

            $bundleProductPriceStmt->execute([$productId]);
            $bundleProduct = $bundleProductPriceStmt->fetch(PDO::FETCH_ASSOC);

            if (!$bundleProduct) {
                return $result;
            }

            $baseSizePrice = (float)($bundleProduct['price'] ?? 0);

            if ($size === 'regular') {
                $baseSizePrice = (float)($bundleProduct['regular_price'] ?? 0);
            } elseif ($size === 'grande') {
                $baseSizePrice = (float)($bundleProduct['grande_price'] ?? 0);
            }

            if ($baseSizePrice <= 0) {
                return $result;
            }

            $normalBundlePrice +=
                $baseSizePrice * $requiredQty;
        }

        $bundlePrice = (float)(
            $promotion['bundle_price'] ?? 0
        );

        if (
            $sets === null ||
            $sets <= 0 ||
            $bundlePrice <= 0
        ) {
            return $result;
        }

        $discountPerSet = max(
            0.0,
            $normalBundlePrice - $bundlePrice
        );

        $result['discount'] = round(
            $sets * $discountPerSet,
            2
        );

        return $result;
    }

    /*
     * ---------------------------------------------------------------
     * PERCENTAGE
     * ---------------------------------------------------------------
     */
    if ($ruleType === 'percentage') {
        $item = $items[0] ?? null;

        if (!$item) {
            return $result;
        }

        $productId = (int)$item['product_id'];
        $size = localiteaNormalizePromotionSize(
            $item['size'] ?? null
        );
        $productSummary = localiteaGetPromotionCartSummary(
            $cartSummary,
            $productId,
            $size
        );
        $productSubtotal = (float)(
            $productSummary['subtotal'] ?? 0
        );

        $percentage = max(
            0.0,
            min(
                100.0,
                (float)($promotion['discount_value'] ?? 0)
            )
        );

        if ($productSubtotal <= 0 || $percentage <= 0) {
            return $result;
        }

        $result['discount'] = round(
            $productSubtotal * $percentage / 100,
            2
        );

        return $result;
    }

    /*
     * ---------------------------------------------------------------
     * FIXED
     * ---------------------------------------------------------------
     */
    if ($ruleType === 'fixed') {
        $item = $items[0] ?? null;

        if (!$item) {
            return $result;
        }

        $productId = (int)$item['product_id'];
        $size = localiteaNormalizePromotionSize(
            $item['size'] ?? null
        );
        $productSummary = localiteaGetPromotionCartSummary(
            $cartSummary,
            $productId,
            $size
        );
        $productSubtotal = (float)(
            $productSummary['subtotal'] ?? 0
        );

        $discount = max(
            0.0,
            (float)($promotion['discount_value'] ?? 0)
        );

        if ($productSubtotal <= 0 || $discount <= 0) {
            return $result;
        }

        $result['discount'] = round(
            min($productSubtotal, $discount),
            2
        );

        return $result;
    }

    return $result;
}

/**
 * Backward-compatible discount-only helper.
 *
 * Existing code that used the old helper can still call it. Full
 * checkout uses localiteaEvaluatePromotion() through
 * localiteaApplyBestPromotion().
 */
function localiteaPromotionDiscount(
    array $promotion,
    array $cartSummary
): float {
    $ruleType = $promotion['rule_type'] ?? '';
    $items = $promotion['rule_items'] ?? [];

    if (!$items) {
        return 0.0;
    }

    if (
        $ruleType === 'bogo' ||
        $ruleType === 'buy_x_get_y'
    ) {
        $buyItem = null;
        $getItem = null;

        foreach ($items as $item) {
            if (($item['role'] ?? '') === 'buy') {
                $buyItem = $item;
            }

            if (($item['role'] ?? '') === 'get') {
                $getItem = $item;
            }
        }

        if (!$buyItem || !$getItem) {
            return 0.0;
        }

        $buyProductId = (int)$buyItem['product_id'];
        $getProductId = (int)$getItem['product_id'];
        $buySize = localiteaNormalizePromotionSize(
            $buyItem['size'] ?? null
        );
        $getSize = localiteaNormalizePromotionSize(
            $getItem['size'] ?? null
        );

        $buyQty = $ruleType === 'bogo'
            ? 1
            : max(1, (int)$buyItem['quantity']);

        $getQty = $ruleType === 'bogo'
            ? 1
            : max(1, (int)$getItem['quantity']);

        /* BOGO must always use the same product and same size. */
        if (
            $ruleType === 'bogo' &&
            (
                $buyProductId !== $getProductId ||
                $buySize !== $getSize
            )
        ) {
            return 0.0;
        }

        /* Qualification always uses the configured BUY size. */
        $buySummary = localiteaGetPromotionCartSummary(
            $cartSummary,
            $buyProductId,
            $buySize
        );

        $getSummary = localiteaGetPromotionCartSummary(
            $cartSummary,
            $getProductId,
            $getSize
        );

        $availableBuy = (int)(
            $buySummary['quantity'] ?? 0
        );

        $availableGet = (int)(
            $getSummary['quantity'] ?? 0
        );

        if ($availableBuy < $buyQty) {
            return 0.0;
        }

        $sets = intdiv($availableBuy, $buyQty);
        $freeUnits = $sets * $getQty;

        if ($buyProductId !== $getProductId) {
            $freeUnits = min(
                $availableGet,
                $freeUnits
            );
        }

        return round(
            $freeUnits * (float)(
                $getSummary['min_unit_price'] ?? 0
            ),
            2
        );
    }

    if ($ruleType === 'bundle') {
        $sets = null;
        $normalBundlePrice = 0.0;

        foreach ($items as $item) {
            $productId = (int)$item['product_id'];
            $requiredQty = max(
                1,
                (int)$item['quantity']
            );
            $size = localiteaNormalizePromotionSize(
                $item['size'] ?? null
            );

            $itemSummary = localiteaGetPromotionCartSummary(
                $cartSummary,
                $productId,
                $size
            );

            $availableQty = (int)(
                $itemSummary['quantity'] ?? 0
            );

            if ($availableQty < $requiredQty) {
                return 0.0;
            }

            $possibleSets = intdiv(
                $availableQty,
                $requiredQty
            );

            $sets = $sets === null
                ? $possibleSets
                : min($sets, $possibleSets);

            $normalBundlePrice +=
                (float)(
                    $itemSummary['unit_price'] ?? 0
                ) * $requiredQty;
        }

        $bundlePrice = (float)(
            $promotion['bundle_price'] ?? 0
        );

        if ($sets === null || $sets <= 0 || $bundlePrice <= 0) {
            return 0.0;
        }

        return round(
            $sets * max(
                0.0,
                $normalBundlePrice - $bundlePrice
            ),
            2
        );
    }

    if ($ruleType === 'percentage') {
        $item = $items[0] ?? null;

        if (!$item) {
            return 0.0;
        }

        $productId = (int)$item['product_id'];
        $size = localiteaNormalizePromotionSize(
            $item['size'] ?? null
        );
        $productSummary = localiteaGetPromotionCartSummary(
            $cartSummary,
            $productId,
            $size
        );
        $productSubtotal = (float)(
            $productSummary['subtotal'] ?? 0
        );

        $percentage = max(
            0,
            min(
                100,
                (float)($promotion['discount_value'] ?? 0)
            )
        );

        return round(
            $productSubtotal * $percentage / 100,
            2
        );
    }

    if ($ruleType === 'fixed') {
        $item = $items[0] ?? null;

        if (!$item) {
            return 0.0;
        }

        $productId = (int)$item['product_id'];
        $size = localiteaNormalizePromotionSize(
            $item['size'] ?? null
        );
        $productSummary = localiteaGetPromotionCartSummary(
            $cartSummary,
            $productId,
            $size
        );
        $productSubtotal = (float)(
            $productSummary['subtotal'] ?? 0
        );

        $discount = max(
            0,
            (float)($promotion['discount_value'] ?? 0)
        );

        return round(
            min($productSubtotal, $discount),
            2
        );
    }

    return 0.0;
}

function localiteaGetRequestedPromotionId(array $cart): ?int
{
    /*
     * A promotion order started from Promo Featured stores the exact
     * promotion ID in the session. Use it only while the cart still
     * contains a line tagged with that same promotion. This prevents
     * an old promotion from winning just because its generated cart
     * line was left behind.
     */
    $selectedPromotionId = (int)($_SESSION['selected_promotion_id'] ?? 0);

    if ($selectedPromotionId > 0) {
        foreach ($cart as $item) {
            if ((int)($item['promotion_source_id'] ?? 0) === $selectedPromotionId) {
                return $selectedPromotionId;
            }
        }
    }

    /*
     * Backward-compatible fallback for carts created before the selected
     * promotion session key was introduced. Only use a promotion when all
     * promotion-tagged cart lines point to the same promotion.
     */
    $sourceIds = [];

    foreach ($cart as $item) {
        $promotionId = (int)($item['promotion_source_id'] ?? 0);

        if ($promotionId > 0) {
            $sourceIds[$promotionId] = true;
        }
    }

    if (count($sourceIds) === 1) {
        return (int)array_key_first($sourceIds);
    }

    return null;
}

function localiteaApplyBestPromotion(
    PDO $pdo,
    array $cart,
    float $subtotal
): array {
    $result = [
        'promotion_id' => null,
        'promotion_title' => null,
        'rule_type' => null,
        'discount' => 0.0,
        'message' => null,
        'snapshot' => null,
        'reward_items' => [],
        'free_allocations' => [],
        'added_reward_value' => 0.0,
    ];

    if ($subtotal <= 0 || !$cart) {
        return $result;
    }

    $cartSummary = localiteaBuildCartProductSummary($cart);

    if (!$cartSummary) {
        return $result;
    }

    $bestPromotion = null;
    $bestEvaluation = null;
    $bestDiscount = 0.0;

    $promotionDefinitions = localiteaGetActivePromotionDefinitions($pdo);

    /*
     * When the customer entered the cart through a specific Promo Featured
     * button, evaluate only that promotion. This prevents an older active
     * promotion on the same product from replacing the promotion the
     * customer explicitly selected.
     */
    $requestedPromotionId = localiteaGetRequestedPromotionId($cart);

    if ($requestedPromotionId !== null) {
        $promotionDefinitions = array_values(array_filter(
            $promotionDefinitions,
            static function (array $promotion) use ($requestedPromotionId): bool {
                return (int)$promotion['id'] === $requestedPromotionId;
            }
        ));
    }

    foreach ($promotionDefinitions as $promotion) {
        $evaluation = localiteaEvaluatePromotion(
            $pdo,
            $promotion,
            $cart,
            $cartSummary
        );

        $discount = min(
            max(0.0, $subtotal + (float)$evaluation['added_reward_value']),
            max(0.0, (float)$evaluation['discount'])
        );

        if ($discount > $bestDiscount) {
            $bestDiscount = $discount;
            $bestPromotion = $promotion;
            $bestEvaluation = $evaluation;
        }
    }

    if (
        !$bestPromotion ||
        !$bestEvaluation ||
        $bestDiscount <= 0
    ) {
        return $result;
    }

    $rewardItems = $bestEvaluation['reward_items'] ?? [];

    /*
     * Recalculate reward value from the returned reward lines so the
     * snapshot, subtotal, and order item handling stay consistent.
     */
    $rewardValue = 0.0;

    foreach ($rewardItems as $rewardItem) {
        $rewardValue += round(
            (float)($rewardItem['price'] ?? 0) *
            (int)($rewardItem['quantity'] ?? 0),
            2
        );
    }

    $result['promotion_id'] = (int)$bestPromotion['id'];
    $result['promotion_title'] =
        (string)$bestPromotion['title'];
    $result['rule_type'] =
        (string)$bestPromotion['rule_type'];
    $result['discount'] = round(
        min(
            $bestDiscount,
            $subtotal + (float)$bestEvaluation['added_reward_value']
        ),
        2
    );
    $result['message'] =
        (string)$bestPromotion['title'];
    $result['reward_items'] = $rewardItems;
    $result['free_allocations'] =
        $bestEvaluation['free_allocations'] ?? [];
    $result['added_reward_value'] = round(
        max(0.0, (float)$bestEvaluation['added_reward_value']),
        2
    );

    $result['snapshot'] = json_encode(
        [
            'title' => $bestPromotion['title'],
            'description' => $bestPromotion['description'],
            'start_date' => $bestPromotion['start_date'],
            'end_date' => $bestPromotion['end_date'],
            'rule_type' => $bestPromotion['rule_type'],
            'buy_quantity' => (int)$bestPromotion['buy_quantity'],
            'get_quantity' => (int)$bestPromotion['get_quantity'],
            'discount_value' => $bestPromotion['discount_value'],
            'bundle_price' => $bestPromotion['bundle_price'],
            'rule_items' => $bestPromotion['rule_items'],
            'discount_amount' => $result['discount'],
            'added_reward_value' => $result['added_reward_value'],
            'reward_items' => $rewardItems,
        ],
        JSON_UNESCAPED_UNICODE
    );

    return $result;
}
