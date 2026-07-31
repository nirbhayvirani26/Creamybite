<?php
// ============================================================
// Creamy Bite – Trade B2B Account Registration
// URL: /trade_register or /trade_register.php
// ============================================================
session_start();
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';

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
                    // NOTE — raw_password stores the password in plain text on
                    // purpose, so the shop can read it back to a partner over
                    // the phone. This is a deliberate, accepted trade-off, not
                    // an oversight: do not "fix" it without asking.
                    //
                    // What it costs: anyone who reads this table — a backup, a
                    // SQL injection, a leaked hosting login — gets working
                    // passwords, and partners commonly reuse them elsewhere.
                    // If that ever stops being acceptable, drop the column and
                    // replace the admin display with a "send reset link" button.
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
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/responsive.css">
    <link rel="stylesheet" href="assets/css/animations.css">
    <link rel="stylesheet" href="assets/css/components.css">
    <link rel="stylesheet" href="assets/css/modal.css">
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
            <a href="trade_login.php" class="btn-secondary cbtr-nav-login-btn">
                <i class="fa-solid fa-right-to-bracket"></i> Trade Login
            </a>
            <button class="nav-hamburger" id="navHamburger" aria-label="Open menu"><span></span><span></span><span></span></button>
        </div>
    </div>
</header>

<main class="cbtr-main">
    <div class="container cbtr-container">
        <div class="glass-panel cbtr-panel">
            <div class="cbtr-panel-head">
                <span class="section-label">B2B Wholesale</span>
                <h1 class="cbtr-title">Apply for a Trade Account 🏪</h1>
                <p class="cbtr-subtitle">
                    Get wholesale pricing, bulk tubs, and direct trade ordering for your shop, cafe, or venue.
                </p>
            </div>

            <?php if ($successMsg): ?>
                <div class="alert alert-success cbtr-alert-success">
                    <i class="fa-solid fa-circle-check cbtr-alert-icon"></i>
                    <?= $successMsg ?>
                </div>
                <div class="cbtr-success-actions">
                    <a href="trade_login.php" class="btn-primary cbtr-login-btn">Proceed to Trade Login</a>
                </div>
            <?php else: ?>

                <?php if ($errorMsg): ?>
                    <div class="alert alert-danger cbtr-alert-error">
                        <i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($errorMsg) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="trade_register.php" class="cbtr-form">
                    <div class="form-row cbtr-form-row">
                        <div class="form-group">
                            <label class="form-label cbtr-field-label">Business / Store Name *</label>
                            <input type="text" name="business_name" class="form-control" placeholder="e.g. Sunny Supermarket" required value="<?= htmlspecialchars($_POST['business_name'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label cbtr-field-label">Contact Person Name *</label>
                            <input type="text" name="contact_name" class="form-control" placeholder="e.g. John Smith" required value="<?= htmlspecialchars($_POST['contact_name'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="form-row cbtr-form-row">
                        <div class="form-group">
                            <label class="form-label cbtr-field-label">Work Email Address *</label>
                            <input type="email" name="email" class="form-control" placeholder="orders@yourstore.com" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label cbtr-field-label">Phone / Mobile *</label>
                            <input type="tel" name="phone" class="form-control" placeholder="07123 456789" required value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label cbtr-field-label">Account Password *</label>
                        <input type="password" name="password" class="form-control" placeholder="Create a secure password (min 6 chars)" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label cbtr-field-label">Store Delivery Address *</label>
                        <textarea name="address" class="form-control" rows="2" placeholder="Full store / business address" required><?= htmlspecialchars($_POST['address'] ?? '') ?></textarea>
                    </div>

                    <div class="form-row cbtr-form-row">
                        <div class="form-group">
                            <label class="form-label cbtr-field-label">Postcode *</label>
                            <input type="text" name="postcode" class="form-control cbtr-input-uppercase" placeholder="e.g. HA1 2SP" required value="<?= htmlspecialchars($_POST['postcode'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label cbtr-field-label">VAT / Company Reg No. (Optional)</label>
                            <input type="text" name="vat_number" class="form-control" placeholder="GB123456789" value="<?= htmlspecialchars($_POST['vat_number'] ?? '') ?>">
                        </div>
                    </div>

                    <button type="submit" class="btn-primary cbtr-submit-btn">
                        <i class="fa-solid fa-paper-plane"></i> Submit Trade Application
                    </button>

                    <div class="cbtr-form-footer">
                        Already have an approved trade account? <a href="trade_login.php" class="cbtr-login-link">Login Here</a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</main>

<!-- Footer -->
<footer class="footer">
    <div class="container cbtr-footer-inner">
        &copy; <?= date('Y') ?> CreamyBite.com — Wholesale Trade Portal
    </div>
</footer>
<script src="assets/js/modal.js" defer></script>
<script src="assets/js/animations.js" defer></script>

</body>
</html>
