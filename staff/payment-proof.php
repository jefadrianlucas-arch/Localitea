<?php
/* =========================================================
   PAYMENT PROOF VIEWER (staff + admin only)

   Reads a GCash screenshot from assets/uploads/receipts/ with PHP
   and sends it to the browser, so the image does not depend on
   Apache file permissions or .htaccess rules.

   Usage:  payment-proof.php?f=gcash_2026....png
   Check:  payment-proof.php?f=gcash_2026....png&debug=1
========================================================= */
session_start();

$role = $_SESSION['user_role'] ?? '';

if (!isset($_SESSION['user_id']) || !in_array($role, ['staff', 'admin'], true)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not allowed.';
    exit;
}

/* Release the session lock; this script only reads a file. */
session_write_close();

$debug = ($_GET['debug'] ?? '') === '1';

/* Only the bare file name is accepted: no folders, no "..". */
$name = basename(str_replace('\\', '/', (string)($_GET['f'] ?? '')));

if (!preg_match('/\A[A-Za-z0-9._-]+\.(?:png|jpe?g)\z/i', $name)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid file name.';
    exit;
}

/* Look in the project's receipts folder first, then the web root's. */
$candidateDirs = array_unique(array_filter([
    realpath(dirname(__DIR__) . '/assets/uploads/receipts'),
    !empty($_SERVER['DOCUMENT_ROOT'])
        ? realpath($_SERVER['DOCUMENT_ROOT'] . '/assets/uploads/receipts')
        : false,
]));

$receiptsDir = $candidateDirs[0] ?? false;
$file = false;
$isInside = false;

foreach ($candidateDirs as $dir) {
    $candidate = realpath($dir . DIRECTORY_SEPARATOR . $name);

    /* The resolved file must really sit inside that receipts folder. */
    if ($candidate !== false && strpos($candidate, $dir . DIRECTORY_SEPARATOR) === 0) {
        $file = $candidate;
        $receiptsDir = $dir;
        $isInside = true;
        break;
    }
}

if ($debug) {
    header('Content-Type: text/plain; charset=utf-8');

    $expected = dirname(__DIR__) . '/assets/uploads/receipts';
    echo "Receipts folder (expected): {$expected}\n";
    echo 'Receipts folder exists:     ' . ($receiptsDir ? "yes ({$receiptsDir})" : 'NO') . "\n";
    echo 'File requested:             ' . $name . "\n";
    echo 'File found:                 ' . ($isInside ? 'yes' : 'NO') . "\n";

    if ($isInside) {
        echo 'File readable by PHP:       ' . (is_readable($file) ? 'yes' : 'NO') . "\n";
        echo 'File permissions:           ' . substr(sprintf('%o', fileperms($file)), -4) . "\n";
        echo 'File size:                  ' . filesize($file) . " bytes\n";
    }

    if ($receiptsDir) {
        $names = array_values(array_filter(
            scandir($receiptsDir) ?: [],
            static fn($n) => $n !== '.' && $n !== '..'
        ));
        echo 'Files in folder:            ' . count($names) . "\n";
        echo "Newest names:\n";
        rsort($names);
        foreach (array_slice($names, 0, 8) as $n) {
            echo "  - {$n}\n";
        }
    }

    exit;
}

if (!$isInside || !is_readable($file)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Payment proof not found.';
    exit;
}

$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$mime = $ext === 'png' ? 'image/png' : 'image/jpeg';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($file));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');

readfile($file);
exit;