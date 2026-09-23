<?php
session_start();

require_once '../includes/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {

        $error = "Please enter your email and password.";

    } else {

        $stmt = $pdo->prepare("
            SELECT id, name, email, password, role
            FROM users
            WHERE email = ?
            AND role = 'staff'
            LIMIT 1
        ");

        $stmt->execute([$email]);

        $staff = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($staff && password_verify($password, $staff['password'])) {

            // Regenerate session ID for security
            session_regenerate_id(true);

            $_SESSION['user_id'] = $staff['id'];
            $_SESSION['user_name'] = $staff['name'];
            $_SESSION['user_email'] = $staff['email'];
            $_SESSION['user_role'] = $staff['role'];

            header("Location: ../staff/orders.php");
            exit;

        } else {

            $error = "Invalid staff email or password.";

        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Staff Login | Local Milktea House</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <style>

        body {
            background-color: #FDFBF7;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .login-container {
            width: 100%;
            max-width: 420px;
            padding: 20px;
        }

        .login-card {
            background: #ffffff;
            border: 1px solid #E6DEC9;
            border-radius: 18px;
            padding: 35px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.08);
        }

        .logo-icon {
            width: 65px;
            height: 65px;
            background: #4A3525;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: auto;
            font-size: 28px;
        }

        .login-title {
            color: #4A3525;
            font-weight: 700;
        }

        .form-control {
            border: 1px solid #E6DEC9;
            border-radius: 9px;
            padding: 11px 13px;
        }

        .form-control:focus {
            border-color: #4A3525;
            box-shadow: 0 0 0 0.15rem rgba(74, 53, 37, 0.15);
        }

        .btn-brown {
            background-color: #4A3525;
            border-color: #4A3525;
            color: white;
            border-radius: 9px;
            padding: 11px;
            font-weight: 600;
        }

        .btn-brown:hover {
            background-color: #332317;
            border-color: #332317;
            color: white;
        }

        .staff-label {
            display: inline-block;
            background: #F1E8DC;
            color: #4A3525;
            border-radius: 20px;
            padding: 5px 12px;
            font-size: 12px;
            font-weight: 600;
        }

    </style>

</head>

<body>

<div class="login-container">

    <div class="login-card">

        <!-- LOGO -->

        <div class="text-center mb-4">

            <div class="logo-icon mb-3">
                <i class="bi bi-person-badge-fill"></i>
            </div>

            <span class="staff-label">
                STAFF PORTAL
            </span>

            <h3 class="login-title mt-3 mb-1">
                Staff Login
            </h3>

            <p class="text-muted small mb-0">
                Local Milktea House
            </p>

        </div>


        <!-- ERROR -->

        <?php if ($error): ?>

            <div class="alert alert-danger small">
                <i class="bi bi-exclamation-circle"></i>
                <?= htmlspecialchars($error) ?>
            </div>

        <?php endif; ?>


        <!-- LOGIN FORM -->

        <form method="POST">

            <div class="mb-3">

                <label class="form-label fw-semibold">
                    Email Address
                </label>

                <div class="input-group">

                    <span class="input-group-text bg-white">
                        <i class="bi bi-envelope"></i>
                    </span>

                    <input
                        type="email"
                        name="email"
                        class="form-control"
                        placeholder="Enter staff email"
                        required
                        autocomplete="email"
                    >

                </div>

            </div>


            <div class="mb-4">

                <label class="form-label fw-semibold">
                    Password
                </label>

                <div class="input-group">

                    <span class="input-group-text bg-white">
                        <i class="bi bi-lock"></i>
                    </span>

                    <input
                        type="password"
                        name="password"
                        id="password"
                        class="form-control"
                        placeholder="Enter password"
                        required
                    >

                    <button
                        type="button"
                        class="btn btn-outline-secondary"
                        onclick="togglePassword()"
                    >
                        <i
                            class="bi bi-eye"
                            id="passwordIcon"
                        ></i>
                    </button>

                </div>

            </div>


            <button
                type="submit"
                class="btn btn-brown w-100"
            >

                <i class="bi bi-box-arrow-in-right"></i>

                Login as Staff

            </button>

        </form>


        <div class="text-center mt-4">

            <a
                href="login.php"
                class="text-decoration-none small"
                style="color:#4A3525;"
            >
                <i class="bi bi-arrow-left"></i>
                Back to Main Login
            </a>

        </div>

    </div>

</div>


<script>

function togglePassword() {

    const password =
        document.getElementById('password');

    const icon =
        document.getElementById('passwordIcon');

    if (password.type === 'password') {

        password.type = 'text';

        icon.classList.remove('bi-eye');

        icon.classList.add('bi-eye-slash');

    } else {

        password.type = 'password';

        icon.classList.remove('bi-eye-slash');

        icon.classList.add('bi-eye');

    }
}

</script>

</body>

</html>