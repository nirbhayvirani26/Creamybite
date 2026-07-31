<?php
// ============================================================
//  Sweet Scoops – Admin Login
//  URL: /Orders/admin/login.php
// ============================================================
session_start();
require_once __DIR__ . '/../config.php';

// Already logged in → redirect
if (!empty($_SESSION['admin_logged_in'])) {
    header('Location: index.php'); exit;
}

// ── Throttle repeated failures ───────────────────────────────
// There is a single shared admin password, so an unthrottled form is a
// straightforward brute-force target. Five failures locks this browser out
// for 15 minutes.
const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCKOUT_SECS = 900;

$attempts  = (int)($_SESSION['admin_login_attempts'] ?? 0);
$lockedTil = (int)($_SESSION['admin_login_locked_until'] ?? 0);

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($lockedTil > time()) {
        $error = 'Too many failed attempts. Try again in '
               . (int)ceil(($lockedTil - time()) / 60) . ' minute(s).';
    } else {
        $u = trim($_POST['username'] ?? '');
        $p = trim($_POST['password'] ?? '');

        // hash_equals is constant-time, so a wrong password cannot be
        // narrowed down by timing how long the comparison takes.
        $ok = hash_equals(ADMIN_USERNAME, $u) & hash_equals(ADMIN_PASSWORD, $p);

        if ($ok) {
            // New session id on privilege change, so a session id planted
            // before login cannot be reused afterwards (session fixation).
            session_regenerate_id(true);
            $_SESSION['admin_logged_in'] = true;
            unset($_SESSION['admin_login_attempts'], $_SESSION['admin_login_locked_until']);
            header('Location: index.php'); exit;
        }

        $attempts++;
        $_SESSION['admin_login_attempts'] = $attempts;
        if ($attempts >= LOGIN_MAX_ATTEMPTS) {
            $_SESSION['admin_login_locked_until'] = time() + LOGIN_LOCKOUT_SECS;
            $_SESSION['admin_login_attempts']     = 0;
            $error = 'Too many failed attempts. Locked for 15 minutes.';
        } else {
            $error = 'Incorrect username or password.';
        }
        error_log('Admin login failed from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login – <?= SHOP_NAME ?></title>
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="setup.css">
    <link rel="stylesheet" href="../responsive.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="../modal.css">
<script src="../modal.js" defer></script>
</head>
<body>
<header class="navbar">
    <div class="container nav-container">
        <a href="../index.php" class="logo">
            <img src="../assets/images/logo.png" alt="<?= SHOP_NAME ?>" class="logo-img lg-logo" >
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
        <div class="alert alert-danger lg-alert" >
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
            <div class="form-group lg-head" >
                <label for="password" class="form-label">Password</label>
                <div class="lg-field">
                    <input type="password" id="password" name="password" class="form-control"
                        placeholder="••••••••" required autocomplete="current-password"
                        class="lg-input-pad">
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
            <button type="submit" class="btn-primary lg-submit" >
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
