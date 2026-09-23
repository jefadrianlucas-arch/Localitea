<?php

require_once '../includes/db.php';

$name = "Staff User";
$email = "staff@localmilktea.com";
$password = "staff123";
$role = "staff";

$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare("
    INSERT INTO users
    (name, email, phone, password, role)
    VALUES (?, ?, ?, ?, ?)
");

$stmt->execute([
    $name,
    $email,
    '',
    $hashedPassword,
    $role
]);

echo "Staff account created successfully.";