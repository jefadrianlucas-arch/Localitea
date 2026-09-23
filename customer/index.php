<?php
session_start();
require_once '../includes/db.php';

date_default_timezone_set('Asia/Manila');


/* =========================================================
   START ORDER FROM PROMOTION
   ---------------------------------------------------------
   Clicking a promotion should NOT add an item directly to
   the cart. The customer must first customize the qualifying
   product (size, add-ons, sugar level, quantity).

   The selected promotion ID is passed to product-view.php.
   add-to-cart.php will tag the customized cart line so the
   promotion engine can apply the selected promotion.
========================================================= */
if (isset($_GET['order_promotion'])) {

    $promotionId = (int)$_GET['order_promotion'];

    if ($promotionId > 0) {

        $promotionStmt = $pdo->prepare("
            SELECT
                p.id,
                r.id AS rule_id,
                r.rule_type,
                r.buy_quantity
            FROM promotions p
            INNER JOIN promotion_rules r
                ON r.promotion_id = p.id
            WHERE p.id = ?
              AND p.is_active = 1
              AND p.is_archived = 0
              AND p.start_date <= CURDATE()
              AND p.end_date >= CURDATE()
            LIMIT 1
        ");

        $promotionStmt->execute([$promotionId]);
        $promotionRule = $promotionStmt->fetch(PDO::FETCH_ASSOC);

        if ($promotionRule) {

            /*
             * Find the primary product the customer must customize.
             *
             * BOGO / Buy X Get Y:
             *   customize the BUY product.
             *
             * Percentage / Fixed:
             *   customize the qualifying product.
             *
             * Bundle:
             *   start with the first bundle product.
             *   (The same product customization page is used so the
             *   customer can still choose its options.)
             */
            $role = 'buy';

            if (
                $promotionRule['rule_type'] === 'percentage' ||
                $promotionRule['rule_type'] === 'fixed'
            ) {
                $role = 'qualifying';
            } elseif ($promotionRule['rule_type'] === 'bundle') {
                $role = 'bundle';
            }

            $itemStmt = $pdo->prepare("
                SELECT
                    pri.product_id,
                    pri.quantity,
                    pri.size,
                    p.is_available,
                    p.is_archived
                FROM promotion_rule_items pri
                INNER JOIN products p
                    ON p.id = pri.product_id
                WHERE pri.rule_id = ?
                  AND pri.role = ?
                  AND p.is_available = 1
                  AND p.is_archived = 0
                ORDER BY pri.id ASC
                LIMIT 1
            ");

            $itemStmt->execute([
                (int)$promotionRule['rule_id'],
                $role
            ]);

            $promotionItem = $itemStmt->fetch(PDO::FETCH_ASSOC);

            if ($promotionItem) {

                /*
                 * Remove previously selected promotion lines so a new
                 * promotion cannot accidentally combine with the old one.
                 */
                if (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
                    foreach ($_SESSION['cart'] as $cartKey => $existingCartItem) {
                        if (
                            array_key_exists('promotion_source_id', $existingCartItem) ||
                            array_key_exists('promotion_source_role', $existingCartItem)
                        ) {
                            unset($_SESSION['cart'][$cartKey]);
                        }
                    }
                }

                /*
                 * Remember the promotion the customer explicitly selected.
                 */
                $_SESSION['selected_promotion_id'] = $promotionId;

                $targetProductId = (int)$promotionItem['product_id'];

                /*
                 * For Buy X Get Y, start with the minimum BUY quantity.
                 * For BOGO the quantity is 1.
                 * Other promotion types start at 1.
                 */
                $promotionQuantity = 1;

                if ($promotionRule['rule_type'] === 'buy_x_get_y') {
                    $promotionQuantity = max(
                        1,
                        (int)$promotionRule['buy_quantity']
                    );
                } elseif ($promotionRule['rule_type'] === 'bundle') {
                    $promotionQuantity = max(
                        1,
                        (int)($promotionItem['quantity'] ?? 1)
                    );
                }

                header(
                    "Location: product-view.php?id=" .
                    $targetProductId .
                    "&promotion_id=" .
                    $promotionId .
                    "&promotion_role=" .
                    urlencode($role) .
                    "&promotion_quantity=" .
                    $promotionQuantity
                );
                exit;
            }
        }
    }

    header("Location: index.php");
    exit;
}

if (isset($_GET['guest'])) {
    $_SESSION['user_id'] = null;
    $_SESSION['user_name'] = 'Guest Customer';
    $_SESSION['user_role'] = 'guest';
    header("Location: index.php");
    exit;
}

/* =========================================================
   BEST SELLERS
========================================================= */
$stmt = $pdo->query("\n    SELECT *\n    FROM products\n    WHERE is_available = 1\n      AND is_archived = 0\n      AND is_bestseller = 1\n    ORDER BY sort_order ASC, id ASC\n    LIMIT 4\n");
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================================================
   ACTIVE PROMOTIONS
   Promotions appear on the customer home page only when they
   are active, not archived, and currently within their dates.
========================================================= */
$promoStmt = $pdo->prepare("\n    SELECT\n        id,\n        title,\n        description,\n        image,\n        start_date,\n        end_date\n    FROM promotions\n    WHERE is_active = 1\n      AND is_archived = 0\n      AND start_date <= CURDATE()\n      AND end_date >= CURDATE()\n    ORDER BY created_at DESC, id DESC\n    LIMIT 4\n");
$promoStmt->execute();
$promotions = $promoStmt->fetchAll(PDO::FETCH_ASSOC);

/* First active promotion is used for the hero image. */
$heroPromotion = $promotions[0] ?? null;

function customerImagePath(?string $image): string
{
    $image = trim((string)$image);

    if ($image === '') {
        return '../assets/uploads/products/default-product.png';
    }

    // Support either a filename or a stored relative upload path.
    $image = str_replace('\\', '/', $image);
    $image = ltrim($image, '/');

    if (strpos($image, 'assets/uploads/products/') === 0) {
        return '../' . $image;
    }

    if (strpos($image, 'uploads/products/') === 0) {
        return '../assets/' . $image;
    }

    return '../assets/uploads/products/' . $image;
}

function customerPromotionImagePath(?string $image): string
{
    $image = trim((string)$image);

    if ($image === '') {
        return '../assets/uploads/products/default-product.png';
    }

    $image = str_replace('\\', '/', $image);
    $image = ltrim($image, '/');

    if (strpos($image, 'assets/uploads/promotions/') === 0) {
        return '../' . $image;
    }

    if (strpos($image, 'uploads/promotions/') === 0) {
        return '../assets/' . $image;
    }

    return '../assets/uploads/promotions/' . $image;
}

function customerPromotionDateRange(string $startDate, string $endDate): string
{
    $start = DateTime::createFromFormat('!Y-m-d', $startDate);
    $end = DateTime::createFromFormat('!Y-m-d', $endDate);

    if (!$start || !$end) {
        return '';
    }

    if ($start->format('Y-m-d') === $end->format('Y-m-d')) {
        return $start->format('M j, Y');
    }

    return $start->format('M j') . ' – ' . $end->format('M j, Y');
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
?>

<style>
body{
    background:#fbf8f4;
    color:#2c221e;
    font-size:0.95rem;
    overflow-x:hidden;
}

.section-title{
    font-weight:700;
    text-align:center;
    font-size:1.4rem;
    margin-bottom:.2rem;
}

.title-line{
    width:45px;
    height:2px;
    background:#6F4E37;
    margin:0 auto 20px;
    border-radius:50px;
}

/* ===============================
   HERO
=============================== */
.hero-section{
    width:100%;
    padding:1rem .75rem 1.25rem;
    margin-top:.35rem;
}

.hero-section > .row{
    background:#fff;
    border:2px solid #6F4E37;
    border-radius:24px;
    padding:26px;
    box-shadow:0 10px 28px rgba(74,53,37,.06);
}

.hero-title{
    font-size:2rem;
    font-weight:800;
    line-height:1.15;
    color:#2c221e;
}

.hero-text{
    max-width:560px;
    color:#6c757d;
    font-size:.9rem;
    line-height:1.55;
}

#promotions{
    scroll-margin-top:94px;
}

.hero-promo-image-wrap{
    width:100%;
    max-width:560px;
    height:210px;
    margin:0 auto;
    border-radius:16px;
    overflow:hidden;
    background:#f3eee8;
    border:2px solid #6F4E37;
    box-shadow:0 4px 14px rgba(0,0,0,.08);
}

.hero-promo-image{
    width:100%;
    height:100%;
    object-fit:cover;
    object-position:center;
    display:block;
}

/* ===============================
   PRODUCT CARDS
=============================== */
.product-card{
    border:1px solid #6F4E37;
    border-radius:14px;
    overflow:hidden;
    transition:.25s;
    box-shadow:0 3px 10px rgba(0,0,0,.06);
    background:#fff;
    height:100%;
    display:flex;
    flex-direction:column;
}

.product-card:hover{
    transform:translateY(-4px);
    box-shadow:0 8px 18px rgba(0,0,0,.1);
}

.product-image-wrap{
    position:relative;
    width:100%;
    height:140px;
    flex:0 0 140px;
    background:#f3eee8;
    overflow:hidden;
}

.product-image-wrap img{
    width:100%;
    height:100%;
    object-fit:contain;
    object-position:center;
    display:block;
}

.product-card .card-body{
    flex:1 1 auto;
    display:flex;
    flex-direction:column;
    justify-content:center;
    padding:10px 8px !important;
}

.product-name{
    font-size:.82rem;
    font-weight:700;
    min-height:0;
    line-height:1.2;
    color:#333;
    overflow-wrap:anywhere;
}

.product-price{
    color:#6F4E37;
    font-size:.85rem;
    font-weight:700;
    margin-top:5px !important;
}

.order-btn{
    border-radius:50px;
    font-size:.75rem;
    padding:.25rem .8rem;
    margin-top:7px !important;
    align-self:center;
    white-space:nowrap;
}

.order-disabled{
    background:#E1DDD9 !important;
    border-color:#E1DDD9 !important;
    color:#766C65 !important;
    cursor:not-allowed;
    box-shadow:none !important;
}

/* ===============================
   PROMOTIONS
=============================== */
.promotion-card{
    border-color:#6F4E37;
}

.promotion-image-wrap{
    position:relative;
    width:100%;
    height:150px;
    flex:0 0 150px;
    background:#f3eee8;
    overflow:hidden;
}

.promotion-image-wrap img{
    width:100%;
    height:100%;
    object-fit:cover;
    object-position:center;
    display:block;
}

.promotion-badge{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    width:fit-content;
    margin:0 auto 7px;
    padding:4px 9px;
    border-radius:50px;
    background:#f3ece5;
    border:1px solid #6F4E37;
    color:#5a3d2b;
    font-size:.66rem;
    font-weight:800;
}

.promotion-description{
    color:#756960;
    font-size:.74rem;
    line-height:1.4;
    margin-bottom:4px;
}

.promotion-validity{
    color:#8a7a70;
    font-size:.68rem;
    margin-bottom:8px;
}

.promo-empty{
    color:#6c757d;
    font-size:.82rem;
}


/* ===============================
   PROMOTION SLIDER
=============================== */
.promotion-slider{
    position:relative;
    max-width:320px;
    margin:0 auto;
    padding:0 42px 34px;
}

.promotion-slides{
    position:relative;
    width:100%;
}

.promotion-slide{
    display:none;
}

.promotion-slide.is-active{
    display:block;
}

.promotion-slider .promotion-card{
    width:100%;
}

.promotion-slider-btn{
    position:absolute;
    top:50%;
    transform:translateY(-50%);
    width:36px;
    height:36px;
    border:2px solid #4A3525;
    border-radius:50%;
    background:#4A3525;
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    box-shadow:0 4px 10px rgba(74,53,37,.18);
    transition:.2s ease;
    z-index:3;
}

.promotion-slider-btn:hover{
    background:#332317;
    border-color:#332317;
    color:#fff;
}

.promotion-slider-prev{ left:0; }
.promotion-slider-next{ right:0; }

.promotion-slider-dots{
    position:absolute;
    left:0;
    right:0;
    bottom:4px;
    display:flex;
    justify-content:center;
    align-items:center;
    gap:7px;
}

.promotion-slider-dot{
    width:8px;
    height:8px;
    padding:0;
    border:1px solid #6F4E37;
    border-radius:50%;
    background:#E8DFD4;
    transition:.2s ease;
}

.promotion-slider-dot.is-active{
    width:10px;
    height:10px;
    background:#4A3525;
}

@media (max-width:767.98px){
    .promotion-slider{
        max-width:290px;
        padding-left:38px;
        padding-right:38px;
    }
    .promotion-slider-btn{
        width:32px;
        height:32px;
    }
}

@media (max-width:399.98px){
    .promotion-slider{
        max-width:280px;
        padding-left:34px;
        padding-right:34px;
    }
    .promotion-slider-btn{
        width:30px;
        height:30px;
        font-size:.8rem;
    }
}

/* ===============================
   LARGE TABLETS / SMALL LAPTOPS
=============================== */
@media (max-width:991.98px){
    .hero-section{
        padding-left:.75rem;
        padding-right:.75rem;
    }

    .hero-section > .row{
        padding:22px;
    }

    .hero-promo-image-wrap{
        max-width:460px;
        height:190px;
    }

    .product-image-wrap{
        height:125px;
        flex-basis:125px;
    }

    .promotion-image-wrap{
        height:135px;
        flex-basis:135px;
    }
}

/* ===============================
   TABLETS / MOBILE
=============================== */
@media (max-width:767.98px){
    .hero-section{
        padding:.55rem .65rem 1rem;
        margin-top:.2rem;
    }

    .hero-section > .row{
        padding:18px;
        border-radius:20px;
        --bs-gutter-x:0;
    }

    .hero-title{
        font-size:1.55rem;
        margin-bottom:.55rem;
    }

    .hero-text{
        max-width:none;
        font-size:.82rem;
        line-height:1.5;
        margin-bottom:.8rem !important;
    }

    .hero-section .btn{
        font-size:.78rem !important;
        padding:.42rem 1rem !important;
    }

    .hero-promo-image-wrap{
        max-width:100%;
        height:165px;
        margin-top:.1rem;
        border-radius:13px;
    }

    .container.my-4{
        margin-top:1rem !important;
        margin-bottom:1rem !important;
    }

    .section-title{
        font-size:1.2rem;
    }

    .title-line{
        margin-bottom:14px;
    }

    .row.g-3.justify-content-center{
        --bs-gutter-x:.65rem;
        --bs-gutter-y:.65rem;
    }

    .product-image-wrap{
        height:110px;
        flex-basis:110px;
    }

    .product-card .card-body{
        padding:8px 6px !important;
    }

    .product-name{
        font-size:.74rem;
    }

    .product-price{
        font-size:.78rem;
        margin-top:4px !important;
    }

    .order-btn{
        font-size:.68rem;
        padding:.25rem .7rem;
        margin-top:5px !important;
    }

    .promotion-image-wrap{
        height:115px;
        flex-basis:115px;
    }

    .promotion-card .card-body{
        padding:9px 7px !important;
    }

    .promotion-description{
        font-size:.68rem;
        line-height:1.3;
    }

    .promotion-validity{
        font-size:.64rem;
        margin-bottom:6px;
    }
}

/* ===============================
   SMALL PHONES
=============================== */
@media (max-width:399.98px){
    .hero-section{
        padding-left:.5rem;
        padding-right:.5rem;
    }

    .hero-section > .row{
        padding:15px 14px;
        border-radius:18px;
    }

    .hero-title{
        font-size:1.38rem;
    }

    .hero-text{
        font-size:.76rem;
    }

    .hero-promo-image-wrap{
        height:145px;
        border-radius:11px;
    }

    .section-title{
        font-size:1.08rem;
    }

    .row.g-3.justify-content-center{
        --bs-gutter-x:.5rem;
        --bs-gutter-y:.55rem;
    }

    .product-image-wrap{
        height:96px;
        flex-basis:96px;
    }

    .product-name{
        font-size:.68rem;
    }

    .product-price{
        font-size:.72rem;
    }

    .order-btn{
        font-size:.62rem;
        padding:.22rem .62rem;
    }

    .promotion-image-wrap{
        height:100px;
        flex-basis:100px;
    }

    .promotion-badge{
        font-size:.58rem;
        padding:3px 7px;
        margin-bottom:5px;
    }

    .promotion-description{
        font-size:.62rem;
    }

    .promotion-validity{
        font-size:.59rem;
    }
}


/* =========================================================
   LANDING PAGE VISUAL REFRESH — LOOK ONLY
   No PHP, ordering, promotion, or JavaScript behavior changed.
========================================================= */
.hero-section{
    padding:1.1rem .75rem 1.5rem;
}

.hero-section > .row{
    position:relative;
    overflow:hidden;
    background:#C4A484;
    border:2px solid #4A3525;
    border-radius:30px;
    padding:38px 38px;
    box-shadow:0 14px 34px rgba(74,53,37,.14);
}

.hero-section > .row::before,
.hero-section > .row::after{
    content:"";
    position:absolute;
    border-radius:50%;
    pointer-events:none;
    z-index:0;
}

.hero-section > .row::before{
    width:240px;
    height:240px;
    left:-95px;
    bottom:-135px;
    background:rgba(255,255,255,.14);
}

.hero-section > .row::after{
    width:290px;
    height:290px;
    right:-150px;
    top:-155px;
    background:rgba(255,255,255,.11);
}

.hero-section > .row > *{
    position:relative;
    z-index:1;
}

.hero-title{
    font-size:2.55rem;
    font-weight:900;
    line-height:1.02;
    letter-spacing:-.8px;
    color:#24170F;
    margin-bottom:.9rem;
    text-wrap:balance;
}

.hero-text{
    max-width:590px;
    color:#4B382B;
    font-size:.95rem;
    line-height:1.65;
    font-weight:500;
}

.hero-section .btn{
    background:#332317 !important;
    border:2px solid #24170F !important;
    color:#fff !important;
    font-weight:700;
    box-shadow:0 7px 16px rgba(36,23,15,.18) !important;
    transition:transform .2s ease, box-shadow .2s ease, background .2s ease;
}

.hero-section .btn:hover{
    background:#24170F !important;
    transform:translateY(-2px);
    box-shadow:0 10px 20px rgba(36,23,15,.22) !important;
}

.hero-promo-image-wrap{
    max-width:600px;
    height:245px;
    border:3px solid #4A3525;
    border-radius:22px;
    background:#F5EBDD;
    box-shadow:0 8px 22px rgba(74,53,37,.16);
}

.hero-promo-image{
    object-fit:cover;
}

/* Section containers */
.container.my-4:not(#promotions){
    background:#fff;
    border:1px solid #E3D6C8;
    border-radius:28px;
    padding:28px 24px 30px;
    box-shadow:0 8px 24px rgba(74,53,37,.07);
}

#promotions{
    background:#F1E5D8;
    border:1px solid #D8C5B2;
    border-radius:28px;
    padding:28px 24px 34px;
    box-shadow:0 8px 24px rgba(74,53,37,.07);
}

.section-title{
    color:#2D1E15;
    font-size:1.55rem;
    font-weight:900;
    letter-spacing:-.2px;
    margin-bottom:.45rem;
}

.title-line{
    width:58px;
    height:3px;
    background:#6F4E37;
    margin:0 auto 24px;
    border-radius:999px;
}

/* Best seller cards */
.product-card{
    border:1.5px solid #BFA993;
    border-radius:20px;
    background:#fff;
    box-shadow:0 7px 18px rgba(74,53,37,.08);
    transition:transform .22s ease, box-shadow .22s ease, border-color .22s ease;
}

.product-card:hover{
    transform:translateY(-6px);
    border-color:#6F4E37;
    box-shadow:0 14px 28px rgba(74,53,37,.15);
}

.product-image-wrap{
    height:170px;
    flex-basis:170px;
    background:linear-gradient(135deg,#F4E9DD 0%,#EAD8C7 100%);
}

.product-image-wrap img{
    padding:8px;
    transition:transform .25s ease;
}

.product-card:hover .product-image-wrap img{
    transform:scale(1.05);
}

.product-image-wrap::after{
    content:"BEST SELLER";
    position:absolute;
    top:12px;
    left:12px;
    padding:5px 9px;
    border-radius:999px;
    background:#332317;
    border:1px solid #24170F;
    color:#fff;
    font-size:.58rem;
    font-weight:800;
    letter-spacing:.35px;
    box-shadow:0 4px 9px rgba(36,23,15,.16);
    z-index:2;
}

.product-card .card-body{
    padding:15px 12px 16px !important;
    background:#fff;
}

.product-name{
    font-size:.9rem;
    font-weight:800;
    color:#332317;
}

.product-price{
    color:#6F4E37;
    font-size:.95rem;
    font-weight:900;
    margin-top:6px !important;
}

.order-btn{
    background:#332317 !important;
    border:1.5px solid #24170F !important;
    color:#fff !important;
    border-radius:999px;
    font-weight:700;
    padding:.38rem 1rem;
    box-shadow:0 4px 9px rgba(36,23,15,.13);
    transition:transform .18s ease, background .18s ease, box-shadow .18s ease;
}

.order-btn:hover{
    background:#24170F !important;
    transform:translateY(-1px);
    box-shadow:0 7px 14px rgba(36,23,15,.18);
}

/* Promotion slider */
.promotion-slider{
    max-width:500px;
    padding:0 48px 38px;
}

.promotion-card{
    border-color:#B99B7E;
    box-shadow:0 10px 24px rgba(74,53,37,.11);
}

.promotion-image-wrap{
    height:230px;
    flex-basis:230px;
    background:linear-gradient(135deg,#E7D4C0 0%,#F7EFE7 100%);
}

.promotion-image-wrap img{
    object-fit:cover;
}

.promotion-card .card-body{
    padding:16px 14px 18px !important;
}

.promotion-badge{
    background:#332317;
    border:1px solid #24170F;
    color:#fff;
    padding:5px 10px;
    font-size:.62rem;
}

.promotion-description{
    color:#5D4A3E;
    font-size:.78rem;
}

.promotion-validity{
    color:#7A685C;
    font-size:.7rem;
}

.promotion-slider-btn{
    width:40px;
    height:40px;
    background:#332317;
    border-color:#24170F;
    box-shadow:0 7px 14px rgba(74,53,37,.18);
}

.promotion-slider-btn:hover{
    background:#24170F;
    border-color:#24170F;
}

.promotion-slider-dot{
    width:9px;
    height:9px;
    background:#D6C2AE;
}

.promotion-slider-dot.is-active{
    width:11px;
    height:11px;
    background:#332317;
}

.promo-empty{
    background:rgba(255,255,255,.55);
    border:1px dashed #B99B7E;
    border-radius:18px;
    padding:18px;
}

@media (max-width:991.98px){
    .hero-section > .row{
        padding:30px 26px;
        border-radius:25px;
    }

    .hero-title{
        font-size:2.15rem;
    }

    .hero-promo-image-wrap{
        height:215px;
    }

    .product-image-wrap{
        height:145px;
        flex-basis:145px;
    }
}

@media (max-width:767.98px){
    .hero-section > .row{
        padding:24px 18px;
        border-radius:22px;
    }

    .hero-title{
        font-size:1.75rem;
        letter-spacing:-.4px;
    }

    .hero-text{
        font-size:.84rem;
    }

    .hero-promo-image-wrap{
        height:175px;
        border-radius:17px;
    }

    .container.my-4:not(#promotions),
    #promotions{
        border-radius:22px;
        padding:22px 12px 26px;
    }

    .section-title{
        font-size:1.3rem;
    }

    .title-line{
        margin-bottom:18px;
    }

    .product-image-wrap{
        height:118px;
        flex-basis:118px;
    }

    .product-image-wrap::after{
        top:8px;
        left:8px;
        padding:4px 7px;
        font-size:.5rem;
    }

    .product-card .card-body{
        padding:11px 7px 13px !important;
    }

    .product-name{
        font-size:.78rem;
    }

    .product-price{
        font-size:.82rem;
    }

    .order-btn{
        font-size:.68rem;
        padding:.3rem .8rem;
    }

    .promotion-slider{
        max-width:390px;
        padding-left:39px;
        padding-right:39px;
    }

    .promotion-image-wrap{
        height:185px;
        flex-basis:185px;
    }
}

@media (max-width:399.98px){
    .hero-section > .row{
        padding:20px 13px;
    }

    .hero-title{
        font-size:1.48rem;
    }

    .hero-text{
        font-size:.77rem;
    }

    .hero-promo-image-wrap{
        height:150px;
    }

    .container.my-4:not(#promotions),
    #promotions{
        padding-left:8px;
        padding-right:8px;
    }

    .product-image-wrap{
        height:100px;
        flex-basis:100px;
    }

    .promotion-slider{
        max-width:320px;
        padding-left:34px;
        padding-right:34px;
    }

    .promotion-image-wrap{
        height:155px;
        flex-basis:155px;
    }
}

</style>

<!-- HERO -->
<div class="container hero-section">
    <div class="row align-items-center g-3">
        <div class="col-lg-6">
            <h1 class="hero-title">
                LET COFFEE<br>
                CONNECT US
            </h1>
            <p class="hero-text mt-2 mb-3">
                Here at Local Milktea House, every cup is made to brighten your day.
                Since opening in 2020 on Nicolas Virata Street, we've been serving
                affordable and refreshing milk tea.
            </p>
            <a href="menu.php" class="btn btn-dark px-4 py-1.5 rounded-pill shadow-sm" style="font-size: 0.85rem;">
                Order Now
            </a>
        </div>

        <div class="col-lg-6 text-center">
            <?php if ($heroPromotion): ?>
                <div class="hero-promo-image-wrap" title="<?= htmlspecialchars($heroPromotion['title']) ?>">
                    <img
                        src="<?= htmlspecialchars(customerPromotionImagePath($heroPromotion['image'])) ?>"
                        class="hero-promo-image"
                        alt="<?= htmlspecialchars($heroPromotion['title']) ?>"
                        onerror="this.onerror=null;this.src='../assets/uploads/products/default-product.png';"
                    >
                </div>
            <?php else: ?>
                <div class="hero-promo-image-wrap">
                    <img
                        src="../assets/uploads/products/default-product.png"
                        class="hero-promo-image"
                        alt="Local Milktea House"
                    >
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- BEST SELLERS -->
<div class="container my-4">
    <h2 class="section-title">Best Sellers</h2>
    <div class="title-line"></div>

    <div class="row g-3 justify-content-center">
        <?php if(empty($products)): ?>
            <div class="text-center text-muted py-3 small">
                <p>No featured products available at the moment.</p>
            </div>
        <?php else: ?>
            <?php foreach($products as $prod): ?>
            <div class="col-xl-3 col-lg-3 col-md-4 col-sm-6 col-6">
                <div class="card product-card h-100">
                    <div class="product-image-wrap">
                        <img
                            src="<?= htmlspecialchars(customerImagePath($prod['image'])) ?>"
                            alt="<?= htmlspecialchars($prod['name']) ?>"
                            onerror="this.onerror=null;this.src='../assets/uploads/products/default-product.png';"
                        >
                    </div>
                    <div class="card-body text-center d-flex flex-column">
                        <div class="product-name">
                            <?= htmlspecialchars($prod['name']) ?>
                        </div>
                        <div class="product-price mt-1">
                            ₱<?= number_format((float)$prod['price'],2) ?>
                        </div>
                        <a href="product-view.php?id=<?= (int)$prod['id'] ?>" class="btn btn-dark order-btn mt-auto mx-auto">
                            Order
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- PROMO FEATURED -->
<div class="container my-4 mb-5" id="promotions">
    <h2 class="section-title">Promo Featured</h2>
    <div class="title-line"></div>

    <?php if(empty($promotions)): ?>
        <div class="text-center py-3 promo-empty">
            <p class="mb-0">No active promotions available at the moment.</p>
        </div>
    <?php else: ?>
        <div class="promotion-slider" id="promotionSlider">
            <div class="promotion-slides">
                <?php foreach($promotions as $promotionIndex => $promotion): ?>
                    <div class="promotion-slide <?= $promotionIndex === 0 ? 'is-active' : '' ?>">
                        <div class="card product-card promotion-card">
                            <div class="promotion-image-wrap">
                                <img
                                    src="<?= htmlspecialchars(customerPromotionImagePath($promotion['image'])) ?>"
                                    alt="<?= htmlspecialchars($promotion['title']) ?>"
                                    onerror="this.onerror=null;this.src='../assets/uploads/products/default-product.png';"
                                >
                            </div>

                            <div class="card-body text-center d-flex flex-column">
                                <div class="promotion-badge">
                                    Special Offer
                                </div>

                                <div class="product-name">
                                    <?= htmlspecialchars($promotion['title']) ?>
                                </div>

                                <?php if (trim((string)$promotion['description']) !== ''): ?>
                                    <div class="promotion-description mt-1">
                                        <?= htmlspecialchars($promotion['description']) ?>
                                    </div>
                                <?php endif; ?>

                                <?php $dateRange = customerPromotionDateRange($promotion['start_date'], $promotion['end_date']); ?>
                                <?php if ($dateRange !== ''): ?>
                                    <div class="promotion-validity">
                                        <i class="bi bi-calendar3 me-1"></i><?= htmlspecialchars($dateRange) ?>
                                    </div>
                                <?php endif; ?>

                                <a href="index.php?order_promotion=<?= (int)$promotion['id'] ?>" class="btn btn-dark order-btn mt-auto mx-auto">
                                    Order Now
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if (count($promotions) > 1): ?>
                <button type="button" class="promotion-slider-btn promotion-slider-prev" id="promotionPrev" aria-label="Previous promotion">
                    <i class="bi bi-chevron-left"></i>
                </button>
                <button type="button" class="promotion-slider-btn promotion-slider-next" id="promotionNext" aria-label="Next promotion">
                    <i class="bi bi-chevron-right"></i>
                </button>

                <div class="promotion-slider-dots" id="promotionDots" aria-label="Promotion navigation">
                    <?php foreach($promotions as $promotionIndex => $promotion): ?>
                        <button
                            type="button"
                            class="promotion-slider-dot <?= $promotionIndex === 0 ? 'is-active' : '' ?>"
                            data-slide="<?= $promotionIndex ?>"
                            aria-label="Show promotion <?= $promotionIndex + 1 ?>"
                            aria-current="<?= $promotionIndex === 0 ? 'true' : 'false' ?>"
                        ></button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>


<script>
(function () {
    const slider = document.getElementById('promotionSlider');
    if (!slider) return;

    const slides = Array.from(slider.querySelectorAll('.promotion-slide'));
    const dots = Array.from(slider.querySelectorAll('.promotion-slider-dot'));
    const prevButton = document.getElementById('promotionPrev');
    const nextButton = document.getElementById('promotionNext');

    if (slides.length <= 1) return;

    let currentIndex = 0;
    let autoPlayTimer = null;

    function showSlide(index) {
        currentIndex = (index + slides.length) % slides.length;

        slides.forEach(function (slide, i) {
            slide.classList.toggle('is-active', i === currentIndex);
        });

        dots.forEach(function (dot, i) {
            const active = i === currentIndex;
            dot.classList.toggle('is-active', active);
            dot.setAttribute('aria-current', active ? 'true' : 'false');
        });
    }

    function startAutoPlay() {
        stopAutoPlay();
        autoPlayTimer = setInterval(function () {
            showSlide(currentIndex + 1);
        }, 5000);
    }

    function stopAutoPlay() {
        if (autoPlayTimer) {
            clearInterval(autoPlayTimer);
            autoPlayTimer = null;
        }
    }

    if (prevButton) {
        prevButton.addEventListener('click', function () {
            showSlide(currentIndex - 1);
            startAutoPlay();
        });
    }

    if (nextButton) {
        nextButton.addEventListener('click', function () {
            showSlide(currentIndex + 1);
            startAutoPlay();
        });
    }

    dots.forEach(function (dot) {
        dot.addEventListener('click', function () {
            showSlide(Number(dot.dataset.slide) || 0);
            startAutoPlay();
        });
    });

    slider.addEventListener('mouseenter', stopAutoPlay);
    slider.addEventListener('mouseleave', startAutoPlay);
    slider.addEventListener('focusin', stopAutoPlay);
    slider.addEventListener('focusout', function () {
        setTimeout(function () {
            if (!slider.contains(document.activeElement)) {
                startAutoPlay();
            }
        }, 0);
    });

    showSlide(0);
    startAutoPlay();
})();
</script>

<?php require_once '../includes/footer.php'; ?>
