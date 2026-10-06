<?php
session_start();
require_once '../includes/db.php';

date_default_timezone_set('Asia/Manila');

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
/* =========================================================
   LOCALITEA LANDING PAGE — VISUAL REDESIGN ONLY
   PHP, ordering, promotion and slider functionality preserved.
   ========================================================= */

body{
    background:#fbf8f4;
    color:#2c221e;
    font-size:.95rem;
    overflow-x:hidden;
}

.section-title{
    text-align:center;
    color:#2d1e15;
    font-size:1.65rem;
    font-weight:900;
    letter-spacing:-.3px;
    margin:0;
}

.section-subtitle{
    text-align:center;
    color:#7a685c;
    font-size:.86rem;
    margin:.35rem auto 0;
}

.title-line{
    width:58px;
    height:3px;
    background:#6F4E37;
    margin:12px auto 28px;
    border-radius:999px;
}

/* ===============================
   HERO — TEXT LEFT / PROMOTION RIGHT
   Clean, cardless landing-page hero.
   Promotion image remains dynamic.
   =============================== */

.hero-section{
    width:100%;
    padding:1.15rem .75rem 1.55rem;
}

.hero-section > .row{
    position:relative;
    max-width:1200px;
    min-height:410px;
    margin:0 auto;
    padding:28px 12px;
    background:transparent;
    border:0;
    border-radius:0;
    box-shadow:none;
    --bs-gutter-x:2.5rem;
}

.hero-section > .row > *{
    position:relative;
    z-index:1;
}

.hero-copy{
    max-width:540px;
    padding:15px 5px;
}

.hero-kicker{
    display:inline-flex;
    align-items:center;
    gap:7px;
    padding:6px 12px;
    margin-bottom:13px;
    border:1px solid #CBB7A3;
    border-radius:999px;
    background:#F3E9DE;
    color:#4A3525;
    font-size:.66rem;
    font-weight:800;
    letter-spacing:.55px;
    text-transform:uppercase;
}

.hero-title{
    font-size:3rem;
    font-weight:900;
    line-height:1;
    letter-spacing:-1px;
    color:#2D1E15;
    margin:0 0 1rem;
}

.hero-text{
    max-width:510px;
    color:#665449;
    font-size:.93rem;
    line-height:1.65;
    font-weight:500;
    margin-bottom:1.25rem !important;
}

.hero-section .btn{
    background:#332317 !important;
    border:1.5px solid #24170F !important;
    color:#fff !important;
    font-weight:800;
    padding:.55rem 1.25rem !important;
    box-shadow:0 7px 15px rgba(36,23,15,.14) !important;
    transition:transform .2s ease, box-shadow .2s ease, background .2s ease;
}

.hero-section .btn:hover{
    background:#24170F !important;
    transform:translateY(-2px);
    box-shadow:0 10px 19px rgba(36,23,15,.2) !important;
}

.hero-promo-image-wrap{
    position:relative;
    width:100%;
    max-width:620px;
    height:325px;
    margin:0 auto;
    overflow:hidden;
    background:#F8F2EA;
    border:0;
    border-radius:18px;
    box-shadow:none;
}

.hero-promo-image{
    width:100%;
    height:100%;
    object-fit:cover;
    object-position:center;
    display:block;
}

/* Hero product image — separate from promotions. */
.hero-visual{
    position:relative;
    width:min(100%, 520px);
    height:440px;
    margin:0 auto;
    overflow:hidden;
    display:flex;
    align-items:center;
    justify-content:center;
    background:transparent;
}

.hero-visual::before{
    content:"";
    position:absolute;
    width:360px;
    height:360px;
    border-radius:50%;
    background:#EAD8C6;
    opacity:.7;
}

.hero-product-image{
    position:relative;
    z-index:2;
    width:92%;
    height:92%;
    object-fit:contain;
    object-position:center;
    display:block;
    filter:drop-shadow(0 22px 24px rgba(74,53,37,.18));
    mix-blend-mode:multiply;
    transition:transform .3s ease;
}

.hero-visual:hover .hero-product-image{
    transform:scale(1.03);
}

.hero-steam{
    position:absolute;
    width:5px;
    height:78px;
    border-radius:999px;
    background:rgba(255,255,255,.72);
    top:80px;
}

.steam-one{ left:43%; transform:rotate(9deg); }
.steam-two{ left:50%; transform:rotate(-4deg); height:92px; top:63px; }
.steam-three{ left:57%; transform:rotate(-11deg); height:70px; top:86px; }

/* ===============================
   HERO RESPONSIVE
   =============================== */

@media (max-width:991.98px){
    .hero-section{
        padding-top:.9rem;
    }

    .hero-section > .row{
        min-height:370px;
        --bs-gutter-x:1.5rem;
    }

    .hero-copy{
        padding:8px 2px;
    }

    .hero-title{
        font-size:2.45rem;
    }

    .hero-promo-image-wrap{
        height:275px;
    }
}

@media (max-width:767.98px){
    .hero-section{
        padding:.65rem .65rem 1.1rem;
    }

    .hero-section > .row{
        --bs-gutter-x:0;
        padding:5px 4px;
    }

    .hero-copy{
        max-width:100%;
        padding:8px 4px 15px;
        text-align:center;
    }

    .hero-kicker{
        font-size:.58rem;
        padding:5px 9px;
    }

    .hero-title{
        font-size:1.9rem;
        letter-spacing:-.5px;
    }

    .hero-text{
        max-width:none;
        font-size:.81rem;
        line-height:1.55;
    }

    .hero-promo-image-wrap{
        max-width:100%;
        height:200px;
        border-radius:15px;
    }
}

@media (max-width:399.98px){
    .hero-section{
        padding:.55rem .45rem .95rem;
    }

    .hero-title{
        font-size:1.6rem;
    }

    .hero-text{
        font-size:.75rem;
    }

    .hero-promo-image-wrap{
        height:170px;
        border-radius:13px;
    }
}

/* ===============================
   SECTION SHELLS
   =============================== */

.landing-section{
    margin:0 auto 1.6rem;
    padding:30px 28px 34px;
    background:#fff;
    border:1px solid #E3D6C8;
    border-radius:28px;
    box-shadow:0 8px 24px rgba(74,53,37,.06);
}

.promo-section{
    background:#F1E5D8;
    border-color:#D8C5B2;
}

/* ===============================
   BEST SELLERS
   =============================== */

.best-seller-grid{
    row-gap:18px;
}

.product-card{
    position:relative;
    border:1px solid #E6E0DA;
    border-radius:16px;
    overflow:hidden;
    background:#fff;
    box-shadow:0 5px 14px rgba(74,53,37,.06);
    transition:transform .22s ease, box-shadow .22s ease, border-color .22s ease;
    height:100%;
}

.product-card:hover{
    transform:translateY(-6px);
    border-color:#CDBBAA;
    box-shadow:0 10px 20px rgba(74,53,37,.11);
}

.product-image-wrap{
    position:relative;
    width:100%;
    height:150px;
    flex:0 0 150px;
    overflow:hidden;
    background:#fff;
}

.product-image-wrap img{
    width:100%;
    height:100%;
    object-fit:contain;
    object-position:center;
    display:block;
    padding:6px;
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
    font-size:.57rem;
    font-weight:800;
    letter-spacing:.35px;
    box-shadow:0 4px 9px rgba(36,23,15,.15);
}

.product-card .card-body{
    padding:12px 11px 13px !important;
    background:#fff;
}

.product-name{
    color:#332317;
    font-size:.86rem;
    font-weight:800;
    line-height:1.25;
}

.product-price{
    color:#6F4E37;
    font-size:.9rem;
    font-weight:900;
    margin-top:5px !important;
}

.order-btn{
    align-self:center;
    border-radius:999px;
    background:#332317 !important;
    border:1.5px solid #24170F !important;
    color:#fff !important;
    font-size:.7rem;
    font-weight:700;
    padding:.32rem .9rem;
    margin-top:7px !important;
    box-shadow:0 4px 9px rgba(36,23,15,.13);
    transition:transform .18s ease, background .18s ease, box-shadow .18s ease;
}

.order-btn:hover{
    background:#24170F !important;
    transform:translateY(-1px);
    box-shadow:0 7px 14px rgba(36,23,15,.18);
}

.order-disabled{
    background:#E1DDD9 !important;
    border-color:#E1DDD9 !important;
    color:#766C65 !important;
    cursor:not-allowed;
    box-shadow:none !important;
}

/* ===============================
   PROMOTION — CLEAN IMAGE-FOCUSED DESIGN
=============================== */
.promotion-layout{
    display:block;
    max-width:760px;
    margin:0 auto;
}

.promotion-slider{
    position:relative;
    width:100%;
    max-width:650px;
    margin:0 auto;
    padding:0 48px 38px;
}

.promotion-slides{position:relative;width:100%;}
.promotion-slide{display:none;}
.promotion-slide.is-active{display:block;}

.promotion-showcase{text-align:center;}

.promotion-image-wrap{
    position:relative;
    width:100%;
    max-width:540px;
    height:320px;
    margin:0 auto 18px;
    background:transparent;
    border:0;
    border-radius:0;
    overflow:visible;
    box-shadow:none;
}

.promotion-image-wrap img{
    width:100%;
    height:100%;
    object-fit:contain;
    object-position:center;
    display:block;
    background:transparent;
    padding:0;
    filter:drop-shadow(0 10px 18px rgba(74,53,37,.12));
}

.promotion-content{
    max-width:540px;
    margin:0 auto;
}

.promotion-badge{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    width:fit-content;
    margin:0 auto 8px;
    padding:5px 11px;
    border-radius:999px;
    background:#332317;
    border:1px solid #24170F;
    color:#fff;
    font-size:.62rem;
    font-weight:800;
    letter-spacing:.3px;
}

.promotion-title{
    color:#332317;
    font-size:1.08rem;
    font-weight:900;
    line-height:1.25;
    margin-bottom:5px;
}

.promotion-description{
    color:#5D4A3E;
    font-size:.79rem;
    line-height:1.5;
    max-width:480px;
    margin:0 auto 6px;
}

.promotion-validity{
    color:#7A685C;
    font-size:.7rem;
    margin-bottom:11px;
}

.promotion-order-btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    background:#332317 !important;
    border:1.5px solid #24170F !important;
    color:#fff !important;
    border-radius:999px;
    font-weight:700;
    font-size:.75rem;
    padding:.42rem 1.15rem;
    box-shadow:0 5px 12px rgba(36,23,15,.14);
    transition:.2s ease;
}

.promotion-order-btn:hover{
    background:#24170F !important;
    color:#fff !important;
    transform:translateY(-2px);
}

.promotion-slider-btn{
    position:absolute;
    top:160px;
    transform:translateY(-50%);
    width:38px;
    height:38px;
    border:1px solid #6F4E37;
    border-radius:50%;
    background:#fff;
    color:#4A3525;
    display:flex;
    align-items:center;
    justify-content:center;
    box-shadow:0 4px 12px rgba(74,53,37,.12);
    transition:.2s ease;
    z-index:3;
}

.promotion-slider-btn:hover{
    background:#332317;
    border-color:#332317;
    color:#fff;
}

.promotion-slider-prev{left:0;}
.promotion-slider-next{right:0;}

.promotion-slider-dots{
    position:absolute;
    left:0;
    right:0;
    bottom:3px;
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
    background:#D8C5B2;
    transition:.2s ease;
}

.promotion-slider-dot.is-active{
    width:10px;
    height:10px;
    background:#332317;
}

.promo-empty{
    color:#6c757d;
    font-size:.82rem;
}

@media (max-width:767.98px){
    .promotion-layout{max-width:100%;}
    .promotion-slider{max-width:100%;padding-left:38px;padding-right:38px;}
    .promotion-image-wrap{height:235px;margin-bottom:14px;}
    .promotion-slider-btn{top:117px;width:32px;height:32px;}
    .promotion-title{font-size:.96rem;}
    .promotion-description{font-size:.7rem;}
    .promotion-validity{font-size:.64rem;}
}

@media (max-width:399.98px){
    .promotion-slider{padding-left:32px;padding-right:32px;}
    .promotion-image-wrap{height:195px;}
    .promotion-slider-btn{top:97px;width:30px;height:30px;font-size:.8rem;}
}

/* ===============================
   RESPONSIVE
   =============================== */

@media (max-width:991.98px){
    .hero-section{
        padding-top:.8rem;
    }

    .hero-promo-image-wrap{
        height:235px;
    }

    .promotion-layout{
        grid-template-columns:1fr;
        max-width:650px;
    }

    .promo-side-copy{
        text-align:center;
        align-items:center;
        padding:0 18px 10px;
    }

    .promo-side-rule{
        margin-left:auto;
        margin-right:auto;
    }
}

@media (max-width:767.98px){
    .hero-section{
        padding:.65rem .65rem 1.2rem;
    }

    .hero-section{
        padding:.65rem .5rem 1rem;
    }

    .hero-promo-image-wrap{
        height:180px;
    }

    .landing-section{
        margin-bottom:1.1rem;
        padding:23px 12px 27px;
        border-radius:22px;
    }

    .section-title{
        font-size:1.3rem;
    }

    .section-subtitle{
        font-size:.75rem;
    }

    .title-line{
        margin-bottom:19px;
    }

    .best-seller-grid{
        --bs-gutter-x:.65rem;
        --bs-gutter-y:.65rem;
    }

    .product-image-wrap{
        height:105px;
        flex-basis:105px;
    }

    .product-image-wrap::after{
        top:8px;
        left:8px;
        padding:4px 7px;
        font-size:.48rem;
    }

    .product-card .card-body{
        padding:10px 7px 11px !important;
    }

    .product-name{
        font-size:.77rem;
    }

    .product-price{
        font-size:.81rem;
    }

    .order-btn{
        font-size:.66rem;
        padding:.3rem .78rem;
    }

    .promotion-slider{
        padding:0 38px 33px;
    }

    .promotion-image-wrap{
        height:190px;
    }

    .promotion-slider-btn{
        width:32px;
        height:32px;
        font-size:.78rem;
    }

    .promo-side-copy h3{
        font-size:1.35rem;
    }

    .promo-side-copy p{
        font-size:.77rem;
    }
}

@media (max-width:399.98px){
    .hero-section{
        padding:.55rem .35rem .85rem;
    }

    .hero-promo-image-wrap{
        height:150px;
    }

    .landing-section{
        padding-left:8px;
        padding-right:8px;
    }

    .section-title{
        font-size:1.14rem;
    }

    .product-image-wrap{
        height:90px;
        flex-basis:90px;
    }

    .product-name{
        font-size:.68rem;
    }

    .product-price{
        font-size:.72rem;
    }

    .order-btn{
        font-size:.61rem;
        padding:.22rem .62rem;
    }

    .promotion-slider{
        padding-left:33px;
        padding-right:33px;
    }

    .promotion-image-wrap{
        height:160px;
    }
}

/* =========================================================
   FINAL LANDING SIZE TUNING
   - Larger hero
   - Best-seller cards sized closer to the reference
   - White product image/card background
   ========================================================= */

.hero-section{
    padding-top:1.7rem;
    padding-bottom:2.2rem;
}

.hero-section > .row{
    max-width:1250px;
    min-height:540px;
    padding:38px 18px;
    --bs-gutter-x:3.2rem;
}

.hero-copy{
    max-width:570px;
    padding:22px 8px;
}

.hero-title{
    font-size:3.35rem;
    line-height:1.02;
    margin-bottom:1.15rem;
}

.hero-text{
    max-width:530px;
    font-size:.98rem;
    line-height:1.7;
}

.hero-promo-image-wrap{
    max-width:620px;
    height:440px;
    border-radius:22px;
}


/* Keep the promotion's beige/brown section background compact.
   This changes the surrounding section only, not the promotion card itself. */
.promo-section{
    padding:20px 22px 22px;
    max-width:1180px;
}

.promo-section .section-title{
    margin-bottom:.25rem;
}

.promo-section .title-line{
    margin-bottom:16px;
}

.promo-section .promotion-layout{
    max-width:860px;
    gap:20px;
}

@media (max-width:767.98px){
    .promo-section{
        padding:18px 12px 20px;
    }

    .promo-section .title-line{
        margin-bottom:12px;
    }
}

/* Best seller cards — 4 across on desktop, reference-style card sizing */
.best-seller-grid{
    --bs-gutter-x:1rem;
    --bs-gutter-y:1rem;
    row-gap:20px;
}

.product-card{
    border-radius:18px;
    background:#fff;
    box-shadow:0 6px 16px rgba(74,53,37,.07);
}

.product-image-wrap{
    height:220px;
    flex-basis:220px;
    background:#fff;
    padding:8px;
}

.product-image-wrap img{
    padding:4px;
}

.product-image-wrap::after{
    top:13px;
    left:13px;
    padding:5px 9px;
    font-size:.58rem;
}

.product-card .card-body{
    padding:15px 15px 16px !important;
    background:#fff;
}

.product-name{
    font-size:.96rem;
    line-height:1.3;
}

.product-price{
    font-size:1rem;
    margin-top:5px !important;
}

.order-btn{
    font-size:.72rem;
    padding:.38rem 1rem;
    margin-top:8px !important;
}

@media (max-width:991.98px){
    .hero-section{
        padding-top:1.25rem;
        padding-bottom:1.7rem;
    }

    .hero-section > .row{
        min-height:470px;
        padding:28px 12px;
        --bs-gutter-x:1.8rem;
    }

    .hero-copy{
        padding:14px 4px;
    }

    .hero-title{
        font-size:2.7rem;
    }

    .hero-promo-image-wrap{
        height:355px;
    }

    .hero-visual{
        height:355px;
    }

    .product-image-wrap{
        height:185px;
        flex-basis:185px;
    }
}

@media (max-width:767.98px){
    .hero-section{
        padding:1rem .55rem 1.3rem;
    }

    .hero-section > .row{
        min-height:0;
        padding:8px 4px 16px;
    }

    .hero-copy{
        padding:12px 4px 20px;
    }

    .hero-title{
        font-size:2rem;
    }

    .hero-text{
        font-size:.82rem;
    }

    .hero-promo-image-wrap{
        height:240px;
        border-radius:17px;
    }

    .hero-visual{
        height:270px;
        border-radius:22px;
    }

    .hero-cup{
        width:145px;
        height:125px;
        border-width:6px;
    }

    .hero-cup i{
        font-size:3rem;
        margin-bottom:7px;
    }

    .hero-cup span{
        font-size:.58rem;
        letter-spacing:1.5px;
    }

    .best-seller-grid{
        --bs-gutter-x:.7rem;
        --bs-gutter-y:.7rem;
    }

    .product-image-wrap{
        height:125px;
        flex-basis:125px;
    }

    .product-card .card-body{
        padding:10px 8px 12px !important;
    }

    .product-name{
        font-size:.78rem;
    }

    .product-price{
        font-size:.82rem;
    }

    .order-btn{
        font-size:.64rem;
        padding:.28rem .7rem;
    }
}

@media (max-width:399.98px){
    .hero-section{
        padding:.8rem .35rem 1.1rem;
    }

    .hero-title{
        font-size:1.7rem;
    }

    .hero-promo-image-wrap{
        height:195px;
    }

    .hero-visual{
        height:225px;
    }

    .product-image-wrap{
        height:105px;
        flex-basis:105px;
    }
}



/* =========================================================
   PROMOTIONS — HORIZONTAL CAROUSEL
   3 cards visible on desktop, 2 on tablet, 1 on mobile.
   Cards slide one position at a time.
========================================================= */
#promotions{
    background:#fff !important;
    border:1px solid #E3D6C8 !important;
    border-radius:28px !important;
    padding:28px 24px 30px !important;
    box-shadow:0 8px 24px rgba(74,53,37,.07) !important;
}

#promotions .section-title{
    color:#2D1E15;
    font-size:1.55rem;
    font-weight:900;
}

#promotions .promo-carousel{
    position:relative;
    width:100%;
    max-width:1100px;
    margin:0 auto;
    padding:0 48px 38px;
}

#promotions .promotion-viewport{
    width:100%;
    overflow:hidden;
    border-radius:20px;
}

#promotions .promotion-track{
    --promo-gap:16px;
    display:flex;
    gap:var(--promo-gap);
    width:100%;
    transition:transform .55s cubic-bezier(.22,.61,.36,1);
    will-change:transform;
}

#promotions .promotion-card{
    position:relative;
    flex:0 0 calc((100% - (var(--promo-gap) * 2)) / 3);
    min-width:0;
    border:1.5px solid #D8C8B8;
    border-radius:20px;
    overflow:hidden;
    background:#fff;
    box-shadow:0 7px 18px rgba(74,53,37,.08);
    transition:transform .22s ease, box-shadow .22s ease, border-color .22s ease;
    display:flex;
    flex-direction:column;
}

#promotions .promotion-card:hover{
    transform:translateY(-5px);
    border-color:#6F4E37;
    box-shadow:0 14px 28px rgba(74,53,37,.14);
}

#promotions .promotion-image-wrap{
    position:relative;
    width:100%;
    height:185px;
    flex:0 0 185px;
    background:#fff;
    overflow:hidden;
}

#promotions .promotion-image-wrap::after{
    content:"PROMO";
    position:absolute;
    top:12px;
    left:12px;
    padding:5px 10px;
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

#promotions .promotion-image-wrap img{
    width:100%;
    height:100%;
    object-fit:contain;
    object-position:center;
    display:block;
    padding:7px;
    background:#fff;
    transition:transform .25s ease;
}

#promotions .promotion-card:hover .promotion-image-wrap img{
    transform:scale(1.04);
}

#promotions .promotion-card .card-body{
    flex:1 1 auto;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:flex-start;
    text-align:center !important;
    padding:14px 12px 16px !important;
    background:#fff;
}

#promotions .promotion-badge{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    width:fit-content;
    margin:0 auto 7px;
    padding:4px 9px;
    border-radius:999px;
    background:#F4ECE4;
    border:1px solid #CDB9A5;
    color:#5A3D2B;
    font-size:.58rem;
    font-weight:800;
}

#promotions .promotion-card .product-name{
    width:100%;
    font-size:.92rem;
    font-weight:900;
    line-height:1.22;
    color:#332317;
    margin-bottom:5px;
    overflow-wrap:anywhere;
}

#promotions .promotion-description{
    width:100%;
    color:#6B5A50;
    font-size:.72rem;
    line-height:1.4;
    margin:0 0 6px;
    display:-webkit-box;
    -webkit-box-orient:vertical;
    -webkit-line-clamp:2;
    overflow:hidden;
}

#promotions .promotion-validity{
    color:#8A7A70;
    font-size:.64rem;
    line-height:1.25;
    margin-top:auto;
    margin-bottom:8px;
}

#promotions .order-btn{
    margin:0 auto !important;
    font-size:.7rem;
    padding:.34rem .92rem;
    align-self:center;
}

#promotions .promotion-slider-btn{
    position:absolute;
    top:50%;
    transform:translateY(-50%);
    width:38px;
    height:38px;
    padding:0;
    border:1px solid #6F4E37;
    border-radius:50%;
    background:#fff;
    color:#4A3525;
    display:flex;
    align-items:center;
    justify-content:center;
    box-shadow:0 4px 12px rgba(74,53,37,.12);
    transition:.2s ease;
    z-index:3;
}

#promotions .promotion-slider-btn:hover{
    background:#332317;
    border-color:#332317;
    color:#fff;
}

#promotions .promotion-slider-btn:focus-visible{
    outline:2px solid #6F4E37;
    outline-offset:2px;
}

#promotions .promotion-slider-btn:disabled{
    opacity:.28;
    cursor:not-allowed;
    pointer-events:none;
}

#promotions .promotion-slider-prev{left:2px;}
#promotions .promotion-slider-next{right:2px;}

#promotions .promotion-slider-dots{
    position:absolute;
    left:0;
    right:0;
    bottom:0;
    display:flex;
    justify-content:center;
    align-items:center;
    gap:8px;
}

#promotions .promotion-slider-dot{
    width:9px;
    height:9px;
    padding:0;
    border:1px solid #6F4E37;
    border-radius:50%;
    background:#D8C5B2;
    transition:.2s ease;
    cursor:pointer;
}

#promotions .promotion-slider-dot:hover{
    transform:scale(1.12);
}

#promotions .promotion-slider-dot.is-active{
    width:11px;
    height:11px;
    background:#332317;
}

#promotions .promo-empty{
    background:#fff;
    border:1px dashed #D8C8B8;
    border-radius:16px;
    color:#6c757d;
    padding:18px;
}

/* Hide carousel controls when there is nothing to slide. */
#promotions .promo-carousel.no-navigation{
    padding-left:0;
    padding-right:0;
}

/* ===============================
   PROMOTION CAROUSEL RESPONSIVE
================================ */
@media (max-width:991.98px){
    #promotions{
        padding:24px 18px 28px !important;
        border-radius:24px !important;
    }

    #promotions .promo-carousel{
        padding-left:38px;
        padding-right:38px;
    }

    #promotions .promotion-track{
        --promo-gap:14px;
    }

    #promotions .promotion-card{
        flex-basis:calc((100% - var(--promo-gap)) / 2);
    }

    #promotions .promotion-image-wrap{
        height:155px;
        flex-basis:155px;
    }

    #promotions .promotion-slider-btn{
        width:34px;
        height:34px;
    }

    #promotions .promotion-slider-prev{left:1px;}
    #promotions .promotion-slider-next{right:1px;}
}

@media (max-width:767.98px){
    #promotions{
        padding:22px 12px 26px !important;
        border-radius:22px !important;
    }

    #promotions .promo-carousel{
        padding:0 36px 33px;
    }

    #promotions .promotion-track{
        --promo-gap:12px;
    }

    #promotions .promotion-card{
        flex-basis:100%;
    }

    #promotions .promotion-image-wrap{
        height:185px;
        flex-basis:185px;
    }

    #promotions .promotion-card .card-body{
        padding:12px 10px 14px !important;
    }

    #promotions .promotion-badge{
        font-size:.53rem;
        padding:3px 8px;
        margin-bottom:5px;
    }

    #promotions .promotion-card .product-name{
        font-size:.78rem;
    }

    #promotions .promotion-description{
        font-size:.64rem;
        line-height:1.35;
    }

    #promotions .promotion-validity{
        font-size:.58rem;
    }

    #promotions .order-btn{
        font-size:.63rem;
        padding:.27rem .72rem;
    }

    #promotions .promotion-slider-btn{
        width:31px;
        height:31px;
        font-size:.78rem;
    }

    #promotions .promotion-slider-prev{left:0;}
    #promotions .promotion-slider-next{right:0;}
}

@media (max-width:399.98px){
    #promotions{
        padding-left:8px !important;
        padding-right:8px !important;
    }

    #promotions .promo-carousel{
        padding-left:31px;
        padding-right:31px;
    }

    #promotions .promotion-image-wrap{
        height:165px;
        flex-basis:165px;
    }

    #promotions .promotion-card .product-name{
        font-size:.72rem;
    }

    #promotions .promotion-description{
        font-size:.59rem;
    }

    #promotions .promotion-slider-btn{
        width:29px;
        height:29px;
        font-size:.72rem;
    }
}
</style>

<!-- HERO -->
<div class="container hero-section">
    <div class="row align-items-center">
        <div class="col-lg-6">
            <div class="hero-copy">
                <div class="hero-kicker">
                    <i class="bi bi-cup-hot-fill"></i>
                    Local Milktea House
                </div>

                <h1 class="hero-title">
                    LET COFFEE<br>
                    CONNECT US
                </h1>

                <p class="hero-text">
                    Here at Local Milktea House, every cup is made to brighten your day.
                    Since opening in 2020 on Nicolas Virata Street, we've been serving
                    affordable and refreshing milk tea.
                </p>

                <a href="menu.php" class="btn btn-dark rounded-pill">
                    Order Now <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>
        </div>

        <div class="col-lg-6 text-center">
            <div class="hero-visual">
                <img
                    src="../assets/images/cafe.png"
                    alt="Local Milktea House"
                    class="hero-product-image"
                    onerror="this.style.display='none';"
                >
            </div>
        </div>
    </div>
</div>

<!-- BEST SELLERS -->
<div class="container landing-section">
    <h2 class="section-title">Our Best Sellers</h2>
    <p class="section-subtitle">Customer favorites, made fresh for you.</p>
    <div class="title-line"></div>

    <div class="row g-3 justify-content-center best-seller-grid">
        <?php if(empty($products)): ?>
            <div class="text-center text-muted py-3 small">
                <p>No featured products available at the moment.</p>
            </div>
        <?php else: ?>
            <?php foreach($products as $prod): ?>
            <div class="col-xl-3 col-lg-3 col-md-6 col-sm-6 col-6">
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

<!-- PROMO FEATURE -->
<div class="container landing-section promo-section mb-5" id="promotions">
    <h2 class="section-title">Special Promotions</h2>
    <p class="section-subtitle">Don't miss what's brewing at Localitea.</p>
    <div class="title-line"></div>

    <?php if(empty($promotions)): ?>
        <div class="text-center py-3 promo-empty">
            <p class="mb-0">No active promotions available at the moment.</p>
        </div>
    <?php else: ?>
        <div class="promo-carousel<?= count($promotions) <= 3 ? ' no-navigation' : '' ?>" id="promotionCarousel">
            <button
                type="button"
                class="promotion-slider-btn promotion-slider-prev"
                id="promotionPrev"
                aria-label="Previous promotion"
            >
                <i class="bi bi-chevron-left"></i>
            </button>

            <div class="promotion-viewport" id="promotionViewport">
                <div class="promotion-track" id="promotionTrack">
                    <?php foreach($promotions as $promotion): ?>
                        <article class="card promotion-card">
                            <div class="promotion-image-wrap">
                                <img
                                    src="<?= htmlspecialchars(customerPromotionImagePath($promotion['image'])) ?>"
                                    alt="<?= htmlspecialchars($promotion['title']) ?>"
                                    onerror="this.onerror=null;this.src='../assets/uploads/products/default-product.png';"
                                >
                            </div>

                            <div class="card-body text-center d-flex flex-column">
                                <div class="promotion-badge">
                                    SPECIAL OFFER
                                </div>

                                <div class="product-name">
                                    <?= htmlspecialchars($promotion['title']) ?>
                                </div>

                                <?php if (trim((string)$promotion['description']) !== ''): ?>
                                    <div class="promotion-description">
                                        <?= htmlspecialchars($promotion['description']) ?>
                                    </div>
                                <?php endif; ?>

                                <?php $dateRange = customerPromotionDateRange($promotion['start_date'], $promotion['end_date']); ?>
                                <?php if ($dateRange !== ''): ?>
                                    <div class="promotion-validity">
                                        <i class="bi bi-calendar3 me-1"></i><?= htmlspecialchars($dateRange) ?>
                                    </div>
                                <?php endif; ?>

                                <a
                                    href="index.php?order_promotion=<?= (int)$promotion['id'] ?>"
                                    class="btn btn-dark order-btn mt-auto mx-auto"
                                >
                                    Order Now <i class="bi bi-arrow-right ms-1"></i>
                                </a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>

            <button
                type="button"
                class="promotion-slider-btn promotion-slider-next"
                id="promotionNext"
                aria-label="Next promotion"
            >
                <i class="bi bi-chevron-right"></i>
            </button>

            <div class="promotion-slider-dots" id="promotionDots" aria-label="Promotion slides"></div>
        </div>
    <?php endif; ?>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const carousel = document.getElementById('promotionCarousel');
    const viewport = document.getElementById('promotionViewport');
    const track = document.getElementById('promotionTrack');
    const prevBtn = document.getElementById('promotionPrev');
    const nextBtn = document.getElementById('promotionNext');
    const dots = document.getElementById('promotionDots');

    if (!carousel || !viewport || !track) {
        return;
    }

    const cards = Array.from(track.querySelectorAll('.promotion-card'));
    if (!cards.length) {
        return;
    }

    let currentIndex = 0;
    let visibleCount = 3;
    let maxIndex = 0;
    let autoSlideTimer = null;

    function getVisibleCount() {
        if (window.innerWidth <= 767.98) {
            return 1;
        }

        if (window.innerWidth <= 991.98) {
            return 2;
        }

        return 3;
    }

    function getGap() {
        const styles = window.getComputedStyle(track);
        return parseFloat(styles.columnGap || styles.gap || '0') || 0;
    }

    function updateMetrics() {
        visibleCount = Math.min(getVisibleCount(), cards.length);
        maxIndex = Math.max(0, cards.length - visibleCount);

        currentIndex = Math.min(currentIndex, maxIndex);

        carousel.classList.toggle('no-navigation', maxIndex === 0);
        prevBtn.hidden = maxIndex === 0;
        nextBtn.hidden = maxIndex === 0;
        dots.hidden = maxIndex === 0;

        renderDots();
        applyTransform();
    }

    function applyTransform() {
        if (!cards.length || maxIndex === 0) {
            track.style.transform = 'translate3d(0, 0, 0)';
            return;
        }

        const cardWidth = cards[0].getBoundingClientRect().width;
        const step = cardWidth + getGap();
        track.style.transform = 'translate3d(' + (-currentIndex * step) + 'px, 0, 0)';
    }

    function renderDots() {
        dots.innerHTML = '';

        const dotCount = maxIndex + 1;

        for (let i = 0; i < dotCount; i++) {
            const dot = document.createElement('button');
            dot.type = 'button';
            dot.className = 'promotion-slider-dot' + (i === currentIndex ? ' is-active' : '');
            dot.setAttribute('aria-label', 'Show promotion ' + (i + 1));
            dot.setAttribute('aria-current', i === currentIndex ? 'true' : 'false');

            dot.addEventListener('click', function () {
                currentIndex = i;
                applyTransform();
                updateActiveDot();
                restartAutoSlide();
            });

            dots.appendChild(dot);
        }
    }

    function updateActiveDot() {
        dots.querySelectorAll('.promotion-slider-dot').forEach(function (dot, index) {
            const active = index === currentIndex;
            dot.classList.toggle('is-active', active);
            dot.setAttribute('aria-current', active ? 'true' : 'false');
        });
    }

    function goTo(index) {
        if (maxIndex === 0) {
            return;
        }

        currentIndex = Math.max(0, Math.min(index, maxIndex));

        applyTransform();
        updateActiveDot();
        restartAutoSlide();
    }

    function next() {
        if (maxIndex === 0) {
            return;
        }

        goTo(currentIndex >= maxIndex ? 0 : currentIndex + 1);
    }

    function prev() {
        if (maxIndex === 0) {
            return;
        }

        goTo(currentIndex <= 0 ? maxIndex : currentIndex - 1);
    }

    function stopAutoSlide() {
        if (autoSlideTimer) {
            clearInterval(autoSlideTimer);
            autoSlideTimer = null;
        }
    }

    function startAutoSlide() {
        stopAutoSlide();

        if (maxIndex === 0) {
            return;
        }

        autoSlideTimer = setInterval(next, 4500);
    }

    function restartAutoSlide() {
        startAutoSlide();
    }

    prevBtn.addEventListener('click', prev);
    nextBtn.addEventListener('click', next);

    carousel.addEventListener('mouseenter', stopAutoSlide);
    carousel.addEventListener('mouseleave', startAutoSlide);
    carousel.addEventListener('focusin', stopAutoSlide);
    carousel.addEventListener('focusout', function (event) {
        if (!carousel.contains(event.relatedTarget)) {
            startAutoSlide();
        }
    });

    let resizeTimer = null;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
            updateMetrics();
            startAutoSlide();
        }, 120);
    });

    updateMetrics();
    startAutoSlide();
});
</script>

<?php require_once '../includes/footer.php'; ?>