<?php

// Use Railway MySQL variables when deployed.
// Fall back to XAMPP settings when running locally.

/**
 * Read an environment variable from every place PHP can expose it.
 * Some PHP setups (for example PHP-FPM) do not fill getenv(), but do
 * fill $_ENV or $_SERVER.
 */
function envValue(string $key, string $default = ''): string
{
    $value = getenv($key);

    if ($value === false || $value === '') {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? '';
    }

    return ($value !== '' && $value !== null) ? (string) $value : $default;
}

$host = envValue('MYSQLHOST', 'localhost');
$port = envValue('MYSQLPORT', '3307');
$db   = envValue('MYSQLDATABASE', 'milktea_db');
$user = envValue('MYSQLUSER', 'root');
$pass = envValue('MYSQLPASSWORD', '');
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {

    // Always keep the real error in the server log (Railway > Logs).
    error_log('Database connection failed: ' . $e->getMessage());

    http_response_code(500);

    // On your own computer (XAMPP) show the real error to help debugging.
    // On the live site never show it to visitors.
    if ($host === 'localhost' || $host === '127.0.0.1') {
        die('Database connection failed: ' . $e->getMessage());
    }

    die('Service temporarily unavailable. Please try again in a few minutes.');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>