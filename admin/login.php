<?php
// ============================================================
//  Sweet Scoops – Admin Login
//  URL: /Orders/admin/login.php
// ============================================================
session_start();
require_once '../config.php';

// Already logged in → redirect
if (!empty($_SESSION['admin_logged_in'])) {
    header('Location: index.php'); exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim($_POST['username'] ?? '');
    $p = trim($_POST['password'] ?? '');
    if ($u === ADMIN_USERNAME && $p === ADMIN_PASSWORD) {
        $_SESSION['admin_logged_in'] = true;
        header('Location: index.php'); exit;
    }
    $error = 'Incorrect username or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login – <?= SHOP_NAME ?></title>
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="../responsive.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<header class="navbar">
    <div class="container nav-container">
        <a href="../index.php" class="logo">
            <img src="../assets/images/logo.png" alt="<?= SHOP_NAME ?>" class="logo-img" style="max-height:46px; width:auto;">
        </a>
        <nav><ul class="nav-links">
            <li><a href="../index.php"><i class="fa-solid fa-arrow-left"></i> Back to Shop</a></li>
        </ul></nav>
    </div>
</header>

<div class="login-page">
    <div class="glass-panel login-card">
        <div class="login-icon">🛡️</div>
        <h1 class="login-title">Admin Panel</h1>
        <p class="login-subtitle">Sign in to manage orders and products</p>

        <?php if ($error): ?>
        <div class="alert alert-danger" style="margin-bottom:20px; text-align:left;">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <form action="login.php" method="POST" class="login-form">
            <div class="form-group">
                <label for="username" class="form-label">Username</label>
                <input type="text" id="username" name="username" class="form-control"
                    placeholder="admin" required autocomplete="username">
            </div>
            <div class="form-group" style="margin-bottom:28px;">
                <label for="password" class="form-label">Password</label>
                <div style="position:relative;">
                    <input type="password" id="password" name="password" class="form-control"
                        placeholder="••••••••" required autocomplete="current-password"
                        style="padding-right:46px;">
                    <button type="button" id="togglePwd"
                        onclick="togglePassword()"
                        aria-label="Toggle password visibility"
                        style="position:absolute; right:12px; top:50%; transform:translateY(-50%);
                               background:none; border:none; cursor:pointer;
                               color:var(--text-muted); font-size:16px; padding:4px;
                               display:flex; align-items:center; transition:color 0.2s;">
                        <i class="fa-solid fa-eye" id="eyeIcon"></i>
                    </button>
                </div>
            </div>
            <button type="submit" class="btn-primary" style="width:100%; justify-content:center; font-size:15px; padding:14px;">
                <i class="fa-solid fa-right-to-bracket"></i> Sign In
            </button>
        </form>
    </div>
</div>

<footer class="footer">
    <div class="container" style="text-align:center;">
        <span class="footer-copy">© <?= date('Y') ?> <?= SHOP_NAME ?> Admin Panel</span>
    </div>
</footer>

<script>
function togglePassword() {
    const input = document.getElementById('password');
    const icon  = document.getElementById('eyeIcon');
    const btn   = document.getElementById('togglePwd');
    if (input.type === 'password') {
        input.type = 'text';
        icon.className  = 'fa-solid fa-eye-slash';
        btn.style.color = 'var(--color-primary)';
    } else {
        input.type = 'password';
        icon.className  = 'fa-solid fa-eye';
        btn.style.color = 'var(--text-muted)';
    }
}
</script>
</body>
</html>
