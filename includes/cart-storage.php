<?php
/*
 * Saves a logged-in customer's cart in the database so it survives
 * logout/login (the session cart is wiped on logout).
 *
 * Place this file in the includes/ folder (next to db.php).
 * It creates its own table the first time it runs.
 */

function ensureSavedCartTable(PDO $pdo): void
{
    if (!empty($_SESSION['saved_cart_table_ready'])) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS customer_saved_carts (
            customer_id INT UNSIGNED NOT NULL PRIMARY KEY,
            cart_json LONGTEXT NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $_SESSION['saved_cart_table_ready'] = true;
}

/* Writes the current session cart to the database (customers only). */
function saveCustomerCart(PDO $pdo): void
{
    if (
        ($_SESSION['user_role'] ?? '') !== 'customer' ||
        empty($_SESSION['user_id'])
    ) {
        return;
    }

    try {
        ensureSavedCartTable($pdo);

        $customerId = (int)$_SESSION['user_id'];
        $cart = (isset($_SESSION['cart']) && is_array($_SESSION['cart']))
            ? $_SESSION['cart']
            : [];

        $json = $cart ? json_encode($cart, JSON_UNESCAPED_UNICODE) : '';
        $hash = md5($json);

        /* Nothing changed since the last save: skip the database write. */
        if (($_SESSION['saved_cart_hash'] ?? null) === $hash) {
            return;
        }

        if ($json === '' || $json === false) {
            $pdo->prepare('DELETE FROM customer_saved_carts WHERE customer_id = ?')
                ->execute([$customerId]);
            $hash = md5('');
        } else {
            $pdo->prepare("
                INSERT INTO customer_saved_carts (customer_id, cart_json)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE cart_json = VALUES(cart_json)
            ")->execute([$customerId, $json]);
        }

        $_SESSION['saved_cart_hash'] = $hash;
    } catch (Throwable $e) {
        error_log('saveCustomerCart failed: ' . $e->getMessage());
    }
}

/* Restores the saved cart into the session (call right after login). */
function loadCustomerCart(PDO $pdo, int $customerId): void
{
    try {
        ensureSavedCartTable($pdo);

        $stmt = $pdo->prepare(
            'SELECT cart_json FROM customer_saved_carts WHERE customer_id = ? LIMIT 1'
        );
        $stmt->execute([$customerId]);
        $json = $stmt->fetchColumn();

        if (!is_string($json) || $json === '') {
            return;
        }

        $saved = json_decode($json, true);
        if (!is_array($saved)) {
            return;
        }

        if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
            $_SESSION['cart'] = [];
        }

        foreach ($saved as $cartKey => $line) {
            if (is_array($line) && !isset($_SESSION['cart'][$cartKey])) {
                $_SESSION['cart'][$cartKey] = $line;
            }
        }

        $_SESSION['saved_cart_hash'] = md5((string)json_encode(
            $_SESSION['cart'],
            JSON_UNESCAPED_UNICODE
        ));
    } catch (Throwable $e) {
        error_log('loadCustomerCart failed: ' . $e->getMessage());
    }
}