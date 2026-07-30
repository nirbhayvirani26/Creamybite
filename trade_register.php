<?php
// ============================================================
// Creamy Bite – Trade B2B Account Registration
// URL: /trade_register or /trade_register.php
// ============================================================
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$successMsg = '';
$errorMsg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $businessName = trim($_POST['business_name'] ?? '');
    $contactName  = trim($_POST['contact_name'] ?? '');
    $email        = strtolower(trim($_POST['email'] ?? ''));
    $password     = $_POST['password'] ?? '';
    $phone        = trim($_POST['phone'] ?? '');
    $address      = trim($_POST['address'] ?? '');
    $postcode     = strtoupper(trim($_POST['postcode'] ?? ''));
    $vatNumber    = trim($_POST['vat_number'] ?? '');

    if (empty($businessName) || empty($contactName) || empty($email) || empty($password) || empty($phone) || empty($address) || empty($postcode)) {
        $errorMsg = 'Please fill in all required fields marked with *';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errorMsg = 'Please enter a valid email address';
    } elseif (strlen($password) < 6) {
        $errorMsg = 'Password must be at least 6 characters long';
    } else {
        try {
            // Check if email already registered
            $check = $pdo->prepare("SELECT COUNT(*) FROM trade_users WHERE email = :email");
            $check->execute(['email' => $email]);
            if ((int)$check->fetchColumn() > 0) {
                $errorMsg = 'An account with this email address already exists. Please login or use a different email.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                try {
                    $stmt = $pdo->prepare("INSERT INTO trade_users (business_name, contact_name, email, password, raw_password, phone, address, postcode, vat_number, status) VALUES (:bname, :cname, :email, :pass, :rpass, :phone, :address, :postcode, :vat, 'pending')");
                    $stmt->execute([
                        'bname'    => $businessName,
                        'cname'    => $contactName,
                        'email'    => $email,
                        'pass'     => $hash,
                        'rpass'    => $password,
                        'phone'    => $phone,
                        'address'  => $address,
                        'postcode' => $postcode,
                        'vat'      => $vatNumber,
                    ]);
                } catch (PDOException $e) {
                    // Fallback if raw_password column not created yet
                    $stmt = $pdo->prepare("INSERT INTO trade_users (business_name, contact_name, email, password, phone, address, postcode, vat_number, status) VALUES (:bname, :cname, :email, :pass, :phone, :address, :postcode, :vat, 'pending')");
                    $stmt->execute([
                        'bname'    => $businessName,
                        'cname'    => $contactName,
                        'email'    => $email,
                        'pass'     => $hash,
                        'phone'    => $phone,
                        'address'  => $address,
                        'postcode' => $postcode,
                        'vat'      => $vatNumber,
                    ]);
                }
                $successMsg = '🎉 Thank you! Your Trade Account application for <strong>' . htmlspecialchars($businessName) . '</strong> has been submitted. Our team will review and activate your account shortly.';
            }
        } catch (PDOException $e) {
            $errorMsg = 'Registration failed: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trade Account Application – <?= SHOP_NAME ?></title>
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
            <a href="trade_login.php" class="btn-secondary" style="padding:8px 16px; font-size:13px;">
                <i class="fa-solid fa-right-to-bracket"></i> Trade Login
            </a>
            <button class="nav-hamburger" id="navHamburger" aria-label="Open menu"><span></span><span></span><span></span></button>
        </div>
    </div>
</header>

<main style="padding:60px 0; background:var(--bg-main); min-height:80vh;">
    <div class="container" style="max-width:650px;">
        <div class="glass-panel" style="padding:40px 32px; border-radius:var(--radius-lg); box-shadow:var(--shadow-lg);">
            <div style="text-align:center; margin-bottom:28px;">
                <span class="section-label">B2B Wholesale</span>
                <h1 style="font-size:28px; margin:8px 0 10px;">Apply for a Trade Account 🏪</h1>
                <p style="color:var(--text-secondary); font-size:14px; margin:0;">
                    Get wholesale pricing, bulk tubs, and direct trade ordering for your shop, cafe, or venue.
                </p>
            </div>

            <?php if ($successMsg): ?>
                <div class="alert alert-success" style="background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; padding:18px; border-radius:12px; font-size:14px; line-height:1.6; margin-bottom:24px;">
                    <i class="fa-solid fa-circle-check" style="font-size:18px; vertical-align:-2px; margin-right:6px;"></i>
                    <?= $successMsg ?>
                </div>
                <div style="text-align:center; margin-top:20px;">
                    <a href="trade_login.php" class="btn-primary" style="padding:12px 28px; font-size:14px;">Proceed to Trade Login</a>
                </div>
            <?php else: ?>

                <?php if ($errorMsg): ?>
                    <div class="alert alert-danger" style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:14px; border-radius:10px; font-size:13px; margin-bottom:20px;">
                        <i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($errorMsg) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="trade_register.php" style="display:flex; flex-direction:column; gap:16px;">
                    <div class="form-row" style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                        <div class="form-group">
                            <label class="form-label" style="font-weight:700; font-size:13px;">Business / Store Name *</label>
                            <input type="text" name="business_name" class="form-control" placeholder="e.g. Sunny Supermarket" required value="<?= htmlspecialchars($_POST['business_name'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" style="font-weight:700; font-size:13px;">Contact Person Name *</label>
                            <input type="text" name="contact_name" class="form-control" placeholder="e.g. John Smith" required value="<?= htmlspecialchars($_POST['contact_name'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="form-row" style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                        <div class="form-group">
                            <label class="form-label" style="font-weight:700; font-size:13px;">Work Email Address *</label>
                            <input type="email" name="email" class="form-control" placeholder="orders@yourstore.com" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" style="font-weight:700; font-size:13px;">Phone / Mobile *</label>
                            <input type="tel" name="phone" class="form-control" placeholder="07123 456789" required value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" style="font-weight:700; font-size:13px;">Account Password *</label>
                        <input type="password" name="password" class="form-control" placeholder="Create a secure password (min 6 chars)" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label" style="font-weight:700; font-size:13px;">Store Delivery Address *</label>
                        <textarea name="address" class="form-control" rows="2" placeholder="Full store / business address" required><?= htmlspecialchars($_POST['address'] ?? '') ?></textarea>
                    </div>

                    <div class="form-row" style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                        <div class="form-group">
                            <label class="form-label" style="font-weight:700; font-size:13px;">Postcode *</label>
                            <input type="text" name="postcode" class="form-control" placeholder="e.g. HA1 2SP" required style="text-transform:uppercase;" value="<?= htmlspecialchars($_POST['postcode'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" style="font-weight:700; font-size:13px;">VAT / Company Reg No. (Optional)</label>
                            <input type="text" name="vat_number" class="form-control" placeholder="GB123456789" value="<?= htmlspecialchars($_POST['vat_number'] ?? '') ?>">
                        </div>
                    </div>

                    <button type="submit" class="btn-primary" style="margin-top:10px; width:100%; justify-content:center; padding:14px; font-size:15px;">
                        <i class="fa-solid fa-paper-plane"></i> Submit Trade Application
                    </button>

                    <div style="text-align:center; font-size:13px; margin-top:12px; color:var(--text-secondary);">
                        Already have an approved trade account? <a href="trade_login.php" style="color:var(--color-primary); font-weight:700;">Login Here</a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</main>

<!-- Footer -->
<footer class="footer">
    <div class="container" style="text-align:center; font-size:13px; color:var(--text-muted);">
        &copy; <?= date('Y') ?> CreamyBite.com — Wholesale Trade Portal
    </div>
</footer>

</body>
</html>
