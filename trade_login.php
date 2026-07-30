<?php
// ============================================================
// Creamy Bite – Trade B2B Login
// URL: /trade_login or /trade_login.php
// ============================================================
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$errorMsg = '';

// If already logged in, redirect to order page
if (!empty($_SESSION['trade_user'])) {
    header('Location: order.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $errorMsg = 'Please enter both email and password';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT * FROM trade_users WHERE email = :email");
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                if ($user['status'] === 'pending') {
                    $errorMsg = '⏳ Your trade application for <strong>' . htmlspecialchars($user['business_name']) . '</strong> is currently pending approval by our admin team.';
                } elseif ($user['status'] === 'rejected') {
                    $errorMsg = '❌ Your trade account application was not approved. Please contact us for support.';
                } elseif ($user['status'] === 'approved') {
                    $_SESSION['trade_user'] = [
                        'id'            => $user['id'],
                        'business_name' => $user['business_name'],
                        'contact_name'  => $user['contact_name'],
                        'email'         => $user['email'],
                        'phone'         => $user['phone'],
                        'address'       => $user['address'],
                        'postcode'      => $user['postcode'],
                    ];
                    header('Location: order.php');
                    exit;
                }
            } else {
                $errorMsg = 'Invalid email or password';
            }
        } catch (PDOException $e) {
            $errorMsg = 'Login failed: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trade Account Login – <?= SHOP_NAME ?></title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="responsive.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="trade-page">

<!-- Navbar -->
<header class="navbar">
    <div class="container nav-container-centered">
        <nav class="nav-left">
            <ul class="nav-links">
                <li><a href="index.php">Home</a></li>
                <li><a href="order.php">Order</a></li>
                <li><a href="gallery.php">Gallery</a></li>
                <li><a href="about.php">About Us</a></li>
            </ul>
        </nav>
        <a href="index.php" class="logo logo-center">
            <img src="assets/images/logo.png" alt="<?= SHOP_NAME ?>" class="logo-img">
        </a>
        <div class="nav-actions nav-right">
            <a href="trade_register.php" class="btn-secondary" style="padding:8px 16px; font-size:13px;">
                <i class="fa-solid fa-user-plus"></i> Apply for Trade
            </a>
            <button class="nav-hamburger" id="navHamburger" aria-label="Open menu"><span></span><span></span><span></span></button>
        </div>
    </div>
</header>

<main style="padding:60px 0; background:var(--bg-main); min-height:80vh; display:flex; align-items:center;">
    <div class="container" style="max-width:440px;">
        <div class="glass-panel" style="padding:40px 32px; border-radius:var(--radius-lg); box-shadow:var(--shadow-lg);">
            <div style="text-align:center; margin-bottom:28px;">
                <span class="section-label">B2B Portal</span>
                <h1 style="font-size:26px; margin:8px 0 10px;">Trade Partner Login 🏪</h1>
                <p style="color:var(--text-secondary); font-size:13px; margin:0;">
                    Log in with your approved trade email to access wholesale pricing.
                </p>
            </div>

            <?php if ($errorMsg): ?>
                <div class="alert alert-danger" style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:14px; border-radius:10px; font-size:13px; margin-bottom:20px; line-height:1.5;">
                    <?= $errorMsg ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="trade_login.php" style="display:flex; flex-direction:column; gap:16px;">
                <div class="form-group">
                    <label class="form-label" style="font-weight:700; font-size:13px;">Registered Email Address</label>
                    <input type="email" name="email" class="form-control" placeholder="orders@yourstore.com" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label class="form-label" style="font-weight:700; font-size:13px;">Password</label>
                    <input type="password" name="password" class="form-control" placeholder="Enter your password" required>
                </div>

                <button type="submit" class="btn-primary" style="margin-top:10px; width:100%; justify-content:center; padding:14px; font-size:15px;">
                    <i class="fa-solid fa-right-to-bracket"></i> Login to Wholesale Menu
                </button>

                <div style="text-align:center; font-size:13px; margin-top:14px; color:var(--text-secondary);">
                    Don't have a Trade Account yet? <a href="trade_register.php" style="color:var(--color-primary); font-weight:700;">Apply Here</a>
                </div>
            </form>
        </div>
    </div>
</main>

<!-- Footer -->
<footer class="footer">
    <div class="container" style="text-align:center; font-size:13px; color:var(--text-muted);">
        &copy; <?= date('Y') ?> CreamyBite.com — B2B Wholesale Login
    </div>
</footer>

</body>
</html>
