<?php
require_once '../includes/db.php';

// Siguraduhing admin ang naka-login (optional check)
// if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') { header("Location: ../auth/login.php"); exit; }

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $category = trim($_POST['category']);
    $price = trim($_POST['price']);
    $status = 'active';

    // Image Upload Handling
    $imageName = 'default.jpg';
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['image']['tmp_name'];
        $fileName = $_FILES['image']['name'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        
        // Payagang mga image formats lamang
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
        if (in_array($fileExtension, $allowedExtensions)) {
            $newFileName = md5(time() . $fileName) . '.' . $fileExtension;
            $uploadFileDir = '../assets/uploads/products/';
            
            // Siguraduhing may folder
            if (!is_dir($uploadFileDir)) {
                mkdir($uploadFileDir, 0755, true);
            }
            
            $dest_path = $uploadFileDir . $newFileName;
            if(move_uploaded_file($fileTmpPath, $dest_path)) {
                $imageName = $newFileName;
            }
        }
    }

    // Insert sa Database
    $stmt = $pdo->prepare("INSERT INTO products (name, category, price, image, status) VALUES (?, ?, ?, ?, ?)");
    if ($stmt->execute([$name, $category, $price, $imageName, $status])) {
        $success = "Product added successfully!";
    } else {
        $error = "Failed to add product.";
    }
}

require_once '../includes/header.php';
?>

<style>
    .btn-brown {
        background-color: #4A3525;
        border-color: #4A3525;
        color: #ffffff;
    }
    .btn-brown:hover {
        background-color: #332317;
        border-color: #332317;
        color: #ffffff;
    }
</style>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card shadow-sm border rounded-4 p-4 bg-white">
                <h4 class="fw-bold mb-3 text-center" style="letter-spacing: 0.5px;">Add New Product</h4>

                <?php if ($error): ?>
                    <div class="alert alert-danger py-2 small"><?= $error ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success py-2 small"><?= $success ?></div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="form-label text-muted small">Product Name</label>
                        <input type="text" name="name" class="form-control form-control-sm" placeholder="e.g. Choco Mousse" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-muted small">Category</label>
                        <select name="category" class="form-select form-select-sm" required>
                            <option value="Classic Milktea">Classic Milktea</option>
                            <option value="Premium Milktea">Premium Milktea</option>
                            <option value="Fruit Tea">Fruit Tea</option>
                            <option value="Frappe">Frappe</option>
                            <option value="Sip and Snack">Sip and Snack</option>
                            <option value="Promo and Bundles">Promo and Bundles</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-muted small">Price (₱)</label>
                        <input type="number" step="0.01" name="price" class="form-control form-control-sm" placeholder="29.00" required>
                    </div>

                    <div class="mb-4">
                        <label class="form-label text-muted small">Product Image</label>
                        <input type="file" name="image" class="form-control form-control-sm" accept="image/*">
                    </div>

                    <button type="submit" class="btn btn-brown w-100 py-2 fw-bold">Save Product</button>
                </form>
                
                <div class="text-center mt-3">
                    <a href="index.php" class="text-decoration-none text-muted small">← Back to Admin Dashboard</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>