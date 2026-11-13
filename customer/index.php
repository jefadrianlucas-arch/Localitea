<?php
// TEMPORARY DEBUG FILE - delete it from your repo after testing.
// Shows whether PHP can see the database variables (never prints values).

header('Content-Type: text/plain');

$keys = ['MYSQLHOST', 'MYSQLPORT', 'MYSQLDATABASE', 'MYSQLUSER', 'MYSQLPASSWORD'];

echo "PHP version: " . PHP_VERSION . "\n";
echo "SAPI: " . PHP_SAPI . "\n\n";

foreach ($keys as $key) {
    $viaGetenv = getenv($key);
    $viaEnv    = $_ENV[$key] ?? null;
    $viaServer = $_SERVER[$key] ?? null;

    printf(
        "%-14s getenv: %-8s \$_ENV: %-8s \$_SERVER: %-8s\n",
        $key,
        ($viaGetenv !== false && $viaGetenv !== '') ? 'SET' : 'missing',
        ($viaEnv !== null && $viaEnv !== '') ? 'SET' : 'missing',
        ($viaServer !== null && $viaServer !== '') ? 'SET' : 'missing'
    );
}

echo "\nVariable NAMES PHP can see that contain MYSQL or RAILWAY:\n";

$names = array_unique(array_merge(
    array_keys(getenv()),
    array_keys($_ENV),
    array_keys($_SERVER)
));

sort($names);

foreach ($names as $name) {
    if (stripos($name, 'MYSQL') !== false || stripos($name, 'RAILWAY') !== false) {
        echo " - " . $name . "\n";
    }
}