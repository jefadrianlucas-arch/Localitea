<?php
require_once '../includes/db.php';

if (
    !isset($_SESSION['user_role']) ||
    !in_array($_SESSION['user_role'], ['admin'])
) {
    header("Location: ../auth/login.php");
    exit;
}

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
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once '../includes/header.php';
?>

<div class="d-flex">

    <?php require_once 'sidebar.php'; ?>

    <div class="flex-grow-1 p-4 admin-shell-main">

        <h2 class="fw-bold mb-1">
            <?= htmlspecialchars($category) ?>
        </h2>

        <p class="text-muted mb-4">
            Products under this category
        </p>

        <?php if (empty($products)): ?>

            <div class="alert alert-info">
                No products found in this category.
            </div>

        <?php else: ?>

            <div class="row g-3">

                <?php foreach ($products as $product): ?>

                    <?php
                    $regularPrice = $product['regular_price'] ?? $product['price'];
                    $grandePrice = $product['grande_price'] ?? $product['price'];
                    ?>

                    <div class="col-xl-3 col-lg-4 col-md-6">

                        <div class="card h-100 shadow-sm border-0">

                            <img
                                src="../assets/uploads/products/<?= htmlspecialchars($product['image'] ?: 'default.jpg') ?>"
                                class="card-img-top"
                                style="height:180px; object-fit:cover;"
                                alt="<?= htmlspecialchars($product['name']) ?>"
                            >

                            <div class="card-body">

                                <small class="text-muted">
                                    <?= htmlspecialchars($product['category_name']) ?>
                                </small>

                                <h5 class="fw-bold mt-1">
                                    <?= htmlspecialchars($product['name']) ?>
                                </h5>

                                <div class="mt-2">
                                    <div>
                                        <strong>Regular:</strong>
                                        ₱<?= number_format((float)$regularPrice, 2) ?>
                                    </div>

                                    <div>
                                        <strong>Grande:</strong>
                                        ₱<?= number_format((float)$grandePrice, 2) ?>
                                    </div>
                                </div>

                                <div class="mt-3">

                                    <?php if ($product['is_available']): ?>

                                        <span class="badge bg-success">
                                            Available
                                        </span>

                                    <?php else: ?>

                                        <span class="badge bg-danger">
                                            Out of Stock
                                        </span>

                                    <?php endif; ?>

                                </div>

                            </div>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </div>

</div>

<?php require_once '../includes/footer.php'; ?>