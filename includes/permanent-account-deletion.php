<?php

function permanentlyDeleteArchivedCustomer(PDO $pdo, int $customerId): void
{
    if ($customerId <= 0) {
        throw new InvalidArgumentException('Invalid customer account.');
    }

    $pdo->beginTransaction();

    try {
        $customerStmt = $pdo->prepare("
            SELECT id
            FROM customers
            WHERE id = ?
              AND is_archived = 1
            LIMIT 1
            FOR UPDATE
        ");
        $customerStmt->execute([$customerId]);

        if (!$customerStmt->fetchColumn()) {
            throw new RuntimeException('Archived customer account not found.');
        }

        $pdo->prepare("
            UPDATE orders
            SET customer_id = NULL
            WHERE customer_id = ?
        ")->execute([$customerId]);

        $pdo->prepare("
            UPDATE order_feedback
            SET customer_id = NULL
            WHERE customer_id = ?
        ")->execute([$customerId]);

        $pdo->prepare("
            DELETE FROM notifications
            WHERE recipient_role = 'customer'
              AND recipient_id = ?
        ")->execute([$customerId]);

        $savedCartTableStmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = 'customer_saved_carts'
            LIMIT 1
        ");
        $savedCartTableStmt->execute();

        if ($savedCartTableStmt->fetchColumn()) {
            $pdo->prepare("
                DELETE FROM customer_saved_carts
                WHERE customer_id = ?
            ")->execute([$customerId]);
        }

        $deleteStmt = $pdo->prepare("
            DELETE FROM customers
            WHERE id = ?
              AND is_archived = 1
        ");
        $deleteStmt->execute([$customerId]);

        if ($deleteStmt->rowCount() !== 1) {
            throw new RuntimeException('Archived customer account could not be deleted.');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function permanentlyDeleteArchivedStaff(PDO $pdo, int $staffId): void
{
    if ($staffId <= 0) {
        throw new InvalidArgumentException('Invalid staff account.');
    }

    $pdo->beginTransaction();

    try {
        $staffStmt = $pdo->prepare("
            SELECT role
            FROM users
            WHERE id = ?
              AND is_archived = 1
            LIMIT 1
            FOR UPDATE
        ");
        $staffStmt->execute([$staffId]);
        $role = $staffStmt->fetchColumn();

        if ($role !== 'staff') {
            throw new RuntimeException('Archived staff account not found.');
        }

        $pdo->prepare("
            UPDATE payments
            SET verified_by_staff_id = NULL
            WHERE verified_by_staff_id = ?
        ")->execute([$staffId]);

        $pdo->prepare("
            DELETE FROM notifications
            WHERE recipient_role = 'staff'
              AND recipient_id = ?
        ")->execute([$staffId]);

        $deleteStmt = $pdo->prepare("
            DELETE FROM users
            WHERE id = ?
              AND role = 'staff'
              AND is_archived = 1
        ");
        $deleteStmt->execute([$staffId]);

        if ($deleteStmt->rowCount() !== 1) {
            throw new RuntimeException('Archived staff account could not be deleted.');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
