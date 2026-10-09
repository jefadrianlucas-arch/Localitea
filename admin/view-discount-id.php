<?php
/*
|--------------------------------------------------------------------------
| VIEW PWD / SENIOR ID PHOTO (ADMIN ONLY)
|--------------------------------------------------------------------------
|
| Put this file in the same folder as orders.php (admin/).
|
| ID photos live in storage/discount-ids/, which is blocked from direct
| URL access. This script checks that the viewer is a logged-in admin,
| then streams the ID photo of one customer account:
|
|     view-discount-id.php?customer=123
|
*/

session_start();

require_once '../includes/db.php';

if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['user_role'] ?? '') !== 'admin'
) {
    http_response_code(403);
    exit('Forbidden');
}

$customerId = (int)($_GET['customer'] ?? 0);

if ($customerId <= 0) {
    http_response_code(400);
    exit('Invalid customer.');
}

$stmt = $pdo->prepare("
    SELECT discount_id_image
    FROM customers
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$customerId]);

$relativePath = (string)$stmt->fetchColumn();

/* Only files inside storage/discount-ids/ may be served. */
if (strpos($relativePath, 'storage/discount-ids/') !== 0) {
    http_response_code(404);
    exit('No ID photo for this customer.');
}

$fullPath = dirname(__DIR__) . '/storage/discount-ids/' . basename($relativePath);

if (!is_file($fullPath)) {
    http_response_code(404);
    exit('File not found.');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($fullPath);

if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
    http_response_code(415);
    exit('Unsupported file.');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($fullPath));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

readfile($fullPath);
exit;