<?php
session_start();
require_once '../includes/db.php';

$category = $_GET['category'] ?? 'Classic Milktea';

$stmt = $pdo->prepare("
SELECT
    p.*,
    c.name AS category_name
FROM products p
INNER JOIN categories c
    ON p.category_id = c.id
WHERE c.name = ?
AND c.is_active = 1
AND p.is_archived = 0
ORDER BY p.name ASC
");

$stmt->execute([$category]);
$products = $stmt->fetchAll();

$isAjaxRequest = isset($_GET['ajax']) && $_GET['ajax'] === '1';

if ($isAjaxRequest) {
    ?>
    <div class="menu-container" id="menuContainer">
        <h3 class="menu-title">
            <?= htmlspecialchars($category) ?>
        </h3>

        <?php if (empty($products)): ?>
            <div class="text-center py-5">
                <i class="bi bi-cup-straw fs-1 text-secondary"></i>
                <p class="text-muted mt-3">
                    No products available.
                </p>
            </div>
        <?php else: ?>
            <div class="row g-4 pb-5">
                <?php foreach ($products as $prod): ?>
                    <?php $isAvailable = (int)$prod['is_available'] === 1; ?>
                    <div class="col-xl-3 col-lg-4 col-md-4 col-sm-6 col-6">
                        <div class="card product-card h-100 <?= $isAvailable ? '' : 'unavailable' ?>">
                            <div class="product-image-wrap">
                                <img
                                    src="../assets/uploads/products/<?= htmlspecialchars($prod['image'] ?: 'default.jpg') ?>"
                                    class="card-img-top"
                                    alt="<?= htmlspecialchars($prod['name']) ?>"
                                    loading="lazy"
                                >

                                <?php if (!$isAvailable): ?>
                                    <span class="out-of-stock-badge">
                                        <i class="bi bi-x-circle-fill"></i>
                                        Out of Stock
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="card-body d-flex flex-column p-3">
                                <div class="product-category">
                                    <?= htmlspecialchars($prod['category_name']) ?>
                                </div>

                                <div class="product-name">
                                    <?= htmlspecialchars($prod['name']) ?>
                                </div>

                                <div class="product-price my-2">
                                    ₱<?= number_format($prod['price'],2) ?>
                                </div>

                                <?php if ($isAvailable): ?>
                                    <a
                                        href="product-view.php?id=<?= (int)$prod['id'] ?>"
                                        class="btn btn-order btn-sm mt-auto"
                                    >
                                        Order Now
                                    </a>
                                <?php else: ?>
                                    <button
                                        type="button"
                                        class="btn btn-order out-of-stock btn-sm mt-auto"
                                        disabled
                                        aria-disabled="true"
                                    >
                                        Out of Stock
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    exit;
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
?>

<style>
body {
    background: #f8f9fa;
    overflow-x: hidden;
}

/* Binigyan natin ng margin sa taas at ibaba para may breathing room mula navbar hanggang footer */
.menu-layout-wrapper {
    min-height: calc(100vh - 84px);
    margin-top: 18px;
    margin-bottom: 18px;
    overflow: visible;
}

.menu-sidebar-column {
    min-height: calc(100vh - 84px);
}

.sidebar-card {
    position: sticky;
    top: 102px;
    align-self: flex-start;
    height: auto;
    max-height: calc(100vh - 120px);
    overflow-y: auto;
    background: #FDF8F2;
    border: 0;
    border-radius: 0;
    padding: 16px 12px;
    box-shadow: none;
}

.sidebar-card > h5 {
    color: #2C221E;
    font-size: .95rem;
    margin-bottom: 12px !important;
}

.sidebar-link {
    display: flex;
    align-items: center;
    width: 100%;
    padding: 10px 14px;
    margin-bottom: 4px;
    border-radius: 10px;
    color: #4A3525;
    text-decoration: none;
    font-weight: 500;
    font-size: .9rem;
    transition: background .2s ease, color .2s ease;
}

.sidebar-link:hover {
    background: #F0E6D6;
    color: #4A3525;
}

.bg-brown-active {
    background: #4A3525 !important;
    color: #fff !important;
    font-weight: 600;
}

.menu-container {
    background: #fff;
    border-radius: 20px;
    padding: 30px;
    box-shadow: 0 5px 20px rgba(0,0,0,.05);
    min-height: 100%;
    overflow: visible;
}

.menu-title {
    font-weight: 700;
    margin-bottom: 25px;
}

.product-card {
    border: none;
    border-radius: 16px;
    box-shadow: 0 4px 12px rgba(0,0,0,.08);
    transition: .25s;
}

.product-card:hover {
    transform: translateY(-6px);
    box-shadow: 0 12px 25px rgba(0,0,0,.12);
}

.product-card.unavailable:hover {
    transform: none;
    box-shadow: 0 4px 12px rgba(0,0,0,.08);
}

.product-image-wrap {
    position: relative;
}

.product-card img {
    height: 150px;
    width: 100%;
    object-fit: cover;
    display: block;
}

.product-card.unavailable img {
    opacity: .72;
}

.out-of-stock-badge {
    position: absolute;
    top: 10px;
    left: 10px;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 10px;
    border-radius: 50px;
    background: #fbe7e7;
    border: 1px solid #c33131;
    color: #a12e2e;
    font-size: .7rem;
    font-weight: 800;
    line-height: 1;
    z-index: 2;
}

.product-name {
    font-weight: 700;
    font-size: 0.95rem;
    min-height: 40px;
}

.product-category {
    font-size: .8rem;
    color: #888;
}

.product-price {
    font-size: 1rem;
    color: #6f4e37;
    font-weight: 700;
}

.btn-order {
    background: #6f4e37;
    color: white;
    border-radius: 50px;
}

.btn-order:hover {
    background: #55301f;
    color: white;
}

.btn-order.out-of-stock {
    background: #e1ddd9 !important;
    border-color: #e1ddd9 !important;
    color: #766c65 !important;
    cursor: not-allowed;
    pointer-events: none;
}


/* =========================================================
   MENU AJAX LOADING
========================================================= */
.menu-ajax-loading {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(255,255,255,.82);
    backdrop-filter: blur(2px);
    border-radius: 20px;
    z-index: 10;
}

.menu-ajax-loading .spinner-border {
    width: 2rem;
    height: 2rem;
    color: #6F4E37;
}

#menuProductsColumn {
    position: relative;
}

.sidebar-link.is-loading {
    pointer-events: none;
    opacity: .7;
}

@media (max-width: 991.98px) {
    .menu-ajax-loading {
        border-radius: 0;
    }
}

/* =========================================================
   MOBILE / TABLET LAYOUT
   On smaller screens the page scrolls normally and categories
   become a swipeable horizontal row.
========================================================= */
@media (max-width: 991.98px) {

    body {
        overflow: visible;
    }

    .menu-layout-wrapper {
        height: auto;
        margin: 0;
        padding: 0;
        overflow: visible;
    }

    .menu-layout-wrapper > .row {
        height: auto !important;
        margin: 0;
    }

    .menu-layout-wrapper > .row > [class*="col-"] {
        height: auto !important;
        padding: 0;
    }

    .menu-sidebar-column {
        border-right: 0;
        min-height: 0;
    }

    /* Categories: one swipeable row that stays under the navbar */
    .sidebar-card {
        position: sticky;
        top: 58px;
        z-index: 1020;
        display: flex;
        align-items: center;
        gap: 8px;
        height: auto !important;
        padding: 10px 12px;
        border: 1px solid #8B6F5A;
        border-left: 0;
        border-right: 0;
        border-radius: 0;
        background: #FDF8F2;
        box-shadow: none;
        overflow-x: auto !important;
        overflow-y: hidden !important;
        white-space: nowrap;
        scrollbar-width: none;
        -webkit-overflow-scrolling: touch;
    }

    .sidebar-card::-webkit-scrollbar {
        display: none;
    }

    .sidebar-card > h5 {
        position: absolute;
        width: 1px;
        height: 1px;
        margin: -1px;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
    }

    .sidebar-link {
        flex: 0 0 auto;
        margin: 0;
        padding: 9px 16px;
        border: 1px solid #B8A08A;
        border-radius: 10px;
        font-size: .88rem;
        background: #FFFFFF;
        color: #4A3525;
    }

    .sidebar-link.bg-brown-active {
        border-color: #4A3525;
        background: #4A3525 !important;
        color: #FFFFFF !important;
    }

    .menu-container {
        height: auto;
        padding: 16px 12px 24px;
        border-radius: 0;
        box-shadow: none;
        background: transparent;
        overflow: visible;
    }

    .menu-title {
        margin-bottom: 14px;
        font-size: 1.25rem;
    }

    .menu-container .row.g-4 {
        --bs-gutter-x: .75rem;
        --bs-gutter-y: .75rem;
    }

    .product-card:hover {
        transform: none;
    }

    .product-card img {
        height: 120px;
    }

    .product-card .card-body {
        padding: .75rem !important;
    }

    .product-name {
        min-height: 0;
        font-size: .9rem;
        line-height: 1.25;
    }

    .product-category {
        font-size: .72rem;
    }

    .btn-order {
        min-height: 40px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
}
</style>

<div class="container-fluid menu-layout-wrapper">
    <div class="row h-100 justify-content-center">

        <!-- SIDEBAR -->
        <div class="col-lg-3 col-xl-2 h-100 menu-sidebar-column">
            <div class="sidebar-card">
                <h5 class="fw-bold mb-3">
                    Categories
                </h5>

                <?php
                $categories=[
                    'Classic Milktea',
                    'Premium Milktea',
                    'Fruit Tea',
                    'Frappe',
                    'Sip and Snack',
                    'Promo and Bundles'
                ];

                foreach($categories as $cat):
                ?>
                <a href="menu.php?category=<?= urlencode($cat) ?>"
                   class="sidebar-link <?= $category==$cat?'bg-brown-active':'' ?>">
                    <?= htmlspecialchars($cat) ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- MAIN PRODUCTS AREA -->
        <div class="col-lg-9 col-xl-10 h-100" id="menuProductsColumn">
            <div class="menu-container" id="menuContainer">
                <h3 class="menu-title">
                    <?= htmlspecialchars($category) ?>
                </h3>

                <?php if(empty($products)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-cup-straw fs-1 text-secondary"></i>
                        <p class="text-muted mt-3">
                            No products available.
                        </p>
                    </div>
                <?php else: ?>
                    <div class="row g-4 pb-5">
                        <?php foreach($products as $prod): ?>
                        <?php $isAvailable = (int)$prod['is_available'] === 1; ?>
                        <div class="col-xl-3 col-lg-4 col-md-4 col-sm-6 col-6">
                            <div class="card product-card h-100 <?= $isAvailable ? '' : 'unavailable' ?>">
                                <div class="product-image-wrap">
                                    <img
                                        src="../assets/uploads/products/<?= htmlspecialchars($prod['image'] ?: 'default.jpg') ?>"
                                        class="card-img-top"
                                        alt="<?= htmlspecialchars($prod['name']) ?>"
                                    >

                                    <?php if (!$isAvailable): ?>
                                        <span class="out-of-stock-badge">
                                            <i class="bi bi-x-circle-fill"></i>
                                            Out of Stock
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <div class="card-body d-flex flex-column p-3">
                                    <div class="product-category">
                                        <?= htmlspecialchars($prod['category_name']) ?>
                                    </div>

                                    <div class="product-name">
                                        <?= htmlspecialchars($prod['name']) ?>
                                    </div>

                                    <div class="product-price my-2">
                                        ₱<?= number_format($prod['price'],2) ?>
                                    </div>

                                    <?php if ($isAvailable): ?>
                                        <a
                                            href="product-view.php?id=<?= (int)$prod['id'] ?>"
                                            class="btn btn-order btn-sm mt-auto"
                                        >
                                            Order Now
                                        </a>
                                    <?php else: ?>
                                        <button
                                            type="button"
                                            class="btn btn-order out-of-stock btn-sm mt-auto"
                                            disabled
                                            aria-disabled="true"
                                        >
                                            Out of Stock
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            </div>
        </div>

    </div>
</div>

\n<script>
(function () {
    const categoryLinks = document.querySelectorAll('.sidebar-link');
    const productsColumn = document.getElementById('menuProductsColumn');
    const menuContainer = document.getElementById('menuContainer');

    if (!productsColumn || !menuContainer || !categoryLinks.length) {
        return;
    }

    let activeRequest = null;

    function setActiveCategory(clickedLink) {
        categoryLinks.forEach(function (link) {
            link.classList.remove('bg-brown-active');
        });
        clickedLink.classList.add('bg-brown-active');
    }

    function showLoading() {
        let loading = productsColumn.querySelector('.menu-ajax-loading');

        if (!loading) {
            loading = document.createElement('div');
            loading.className = 'menu-ajax-loading';
            loading.innerHTML = '<div class="spinner-border" role="status" aria-label="Loading category"></div>';
            productsColumn.appendChild(loading);
        }

        loading.hidden = false;
    }

    function hideLoading() {
        const loading = productsColumn.querySelector('.menu-ajax-loading');
        if (loading) {
            loading.hidden = true;
        }
    }

    function setLinksLoading(isLoading, exceptLink) {
        categoryLinks.forEach(function (link) {
            if (isLoading) {
                if (link === exceptLink) {
                    link.classList.remove('is-loading');
                } else {
                    link.classList.add('is-loading');
                }
            } else {
                link.classList.remove('is-loading');
            }
        });
    }

    async function loadCategory(link, updateHistory) {
        const categoryUrl = new URL(link.href, window.location.origin);
        const category = categoryUrl.searchParams.get('category') || '';

        if (!category) {
            return;
        }

        if (activeRequest) {
            activeRequest.abort();
        }

        const controller = new AbortController();
        activeRequest = controller;
        showLoading();
        setLinksLoading(true, link);
        setActiveCategory(link);

        try {
            categoryUrl.searchParams.set('ajax', '1');

            const response = await fetch(categoryUrl.toString(), {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'text/html'
                },
                cache: 'no-store',
                signal: controller.signal
            });

            if (!response.ok) {
                throw new Error('Failed to load category.');
            }

            const html = await response.text();

            const temp = document.createElement('div');
            temp.innerHTML = html.trim();
            const newMenuContainer = temp.querySelector('.menu-container');

            if (!newMenuContainer) {
                throw new Error('Invalid category response.');
            }

            const currentMenuContainer = document.getElementById('menuContainer');
            if (!currentMenuContainer) {
                throw new Error('Menu container not found.');
            }

            currentMenuContainer.replaceWith(newMenuContainer);

            if (updateHistory) {
                const cleanUrl = new URL(window.location.href);
                cleanUrl.searchParams.set('category', category);
                cleanUrl.searchParams.delete('ajax');
                window.history.pushState({ category: category }, '', cleanUrl.toString());
            }

            window.scrollTo({ top: 0, behavior: 'smooth' });
        } catch (error) {
            if (error.name !== 'AbortError') {
                console.error('Menu category AJAX error:', error);
                window.location.href = link.href;
            }
        } finally {
            if (activeRequest === controller) {
                activeRequest = null;
                hideLoading();
                setLinksLoading(false);
            }
        }
    }

    categoryLinks.forEach(function (link) {
        link.addEventListener('click', function (event) {
            event.preventDefault();
            loadCategory(link, true);
        });
    });

    window.addEventListener('popstate', function () {
        const currentUrl = new URL(window.location.href);
        const currentCategory = currentUrl.searchParams.get('category') || 'Classic Milktea';
        const targetLink = Array.from(categoryLinks).find(function (link) {
            const linkUrl = new URL(link.href, window.location.origin);
            return (linkUrl.searchParams.get('category') || '') === currentCategory;
        });

        if (targetLink) {
            loadCategory(targetLink, false);
        }
    });
})();
</script>

<?php require_once '../includes/footer.php'; ?>
