<?php
// ============================================================
// Creamy Bite – Trade B2B Account Registration
// URL: /trade_register or /trade_register.php
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/postcode.php';

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

    // Same token as the login form, for a plainer reason: without it any other
    // site can post this form from a visitor's browser, and every application
    // it files lands in the admin panel looking exactly like a real one. A
    // steady trickle of those is enough to bury the genuine applications a
    // trade partner is waiting on.
    //
    // Soft failure again — the whole form is re-shown with everything they
    // typed still in it, so a lapsed session costs one more click and not a
    // page of details typed a second time.
    if (!csrfValid()) {
        error_log('Trade registration rejected: no valid form token, from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $errorMsg = 'This page was open for a while and the form expired. Please check your details below and send it again.';
    } elseif (empty($businessName) || empty($contactName) || empty($email) || empty($password) || empty($phone) || empty($address) || empty($postcode)) {
        $errorMsg = 'Please fill in all required fields marked with *';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errorMsg = 'Please enter a valid email address';
    } elseif (strlen($password) < 6) {
        $errorMsg = 'Password must be at least 6 characters long';
    } elseif (($postcodeClean = cbUkPostcodeNormalise($postcode)) === null) {
        // The server decides, whatever the browser allowed — the pattern on
        // the input is a convenience, and a form can always be posted without
        // one. A bad postcode here reaches a delivery run and an invoice with
        // nothing downstream to catch it: the distance lookup just answers
        // "not found" and the application sits in the admin panel looking
        // entirely normal.
        $errorMsg = 'Please enter a valid UK postcode, for example HA1 2SP. We deliver within the UK only.';
    } else {
        // Store the printed form, not what was typed. "ha12sp" and "HA1 2SP"
        // are the same address and should not become two different partners.
        $postcode = $postcodeClean;

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
                // The success alert already opens with a fa-circle-check, which is
                // what 🎉 maps to. Adding a second one would print the same mark
                // twice, so the message keeps the words only.
                $successMsg = 'Thank you! Your Trade Account application for <strong>' . htmlspecialchars($businessName) . '</strong> has been submitted. Our team will review and activate your account shortly.';
            }
        } catch (PDOException $e) {
            // Logged, not shown. Printing $e->getMessage() here put the failing
            // SQL and this site's table and column names on a page anyone on
            // the internet can reach, in front of a shop owner who can do
            // nothing with it. What they need is a way to reach a human.
            error_log('Trade registration failed: ' . $e->getMessage());
            $errorMsg = 'We could not save your application just now. Please try again in a moment, or call us on ' . htmlspecialchars(SHOP_PHONE) . '.';
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
<?php require __DIR__ . '/../includes/seo_head.php'; ?>
    <meta name="description" content="Apply for a Creamy Bite trade account to stock handcrafted ice cream and cocoa drinks in your shop, cafe or store — wholesale pricing and bulk tubs for retailers.">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/responsive.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/animations.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/components.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/modal.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="trade-page">

<!-- Navbar -->
<?php
$cbNavActive = '';
$cbNavShowTrade = false; // already on the register page — a trade-account pill would be redundant
ob_start(); ?>
<a href="trade_login.php" class="btn-secondary cbtr-nav-login-btn">
    <i class="fa-solid fa-right-to-bracket"></i> Trade Login
</a>
<?php $cbNavRight = ob_get_clean();
require __DIR__ . '/../includes/site_header.php';
?>

<main class="cbtr-main">
    <div class="container cbtr-container">
        <div class="glass-panel cbtr-panel">
            <div class="cbtr-panel-head">
                <span class="section-label">B2B Wholesale</span>
                <h1 class="cbtr-title">Apply for a Trade Account <i class="fa-solid fa-store cb-title-icon" aria-hidden="true"></i></h1>
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
                    <?= csrfField() ?>
                    <div class="form-row cbtr-form-row">
                        <div class="form-group">
                            <label class="form-label cbtr-field-label" for="tr_business_name">Business / Store Name *</label>
                            <input id="tr_business_name" type="text" name="business_name" class="form-control" placeholder="e.g. Sunny Supermarket" required value="<?= htmlspecialchars($_POST['business_name'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label cbtr-field-label" for="tr_contact_name">Contact Person Name *</label>
                            <input id="tr_contact_name" type="text" name="contact_name" class="form-control" placeholder="e.g. John Smith" required value="<?= htmlspecialchars($_POST['contact_name'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="form-row cbtr-form-row">
                        <div class="form-group">
                            <label class="form-label cbtr-field-label" for="tr_email">Work Email Address *</label>
                            <input id="tr_email" type="email" name="email" class="form-control" placeholder="orders@yourstore.com" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label cbtr-field-label" for="tr_phone">Phone / Mobile *</label>
                            <input id="tr_phone" type="tel" name="phone" class="form-control" placeholder="07123 456789" required value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label cbtr-field-label" for="tr_password">Account Password *</label>
                        <input id="tr_password" type="password" name="password" class="form-control" placeholder="Create a secure password (min 6 chars)" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label cbtr-field-label" for="tr_address">Store Delivery Address *</label>
                        <textarea id="tr_address" name="address" class="form-control" rows="2" placeholder="Full store / business address" required><?= htmlspecialchars($_POST['address'] ?? '') ?></textarea>
                    </div>

                    <div class="form-row cbtr-form-row">
                        <div class="form-group">
                            <label class="form-label cbtr-field-label" for="tr_postcode">Postcode *</label>
                            <?php // pattern is a convenience that catches the typo before the round
                                  // trip; includes/postcode.php is what actually decides, and
                                  // the two are kept in step by cbUkPostcodeHtmlPattern(). ?>
                            <input id="tr_postcode" type="text" name="postcode" class="form-control cbtr-input-uppercase"
                                   placeholder="e.g. HA1 2SP" required
                                   maxlength="8"
                                   autocomplete="postal-code"
                                   pattern="<?= htmlspecialchars(cbUkPostcodeHtmlPattern(), ENT_QUOTES, 'UTF-8') ?>"
                                   title="A UK postcode, for example HA1 2SP"
                                   aria-describedby="tr_postcode_hint"
                                   value="<?= htmlspecialchars($_POST['postcode'] ?? '') ?>">
                            <small id="tr_postcode_hint" class="cbtr-field-hint">UK postcodes only — we do not deliver outside the UK.</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label cbtr-field-label" for="tr_vat_number">VAT / Company Reg No. (Optional)</label>
                            <input id="tr_vat_number" type="text" name="vat_number" class="form-control" placeholder="GB123456789" value="<?= htmlspecialchars($_POST['vat_number'] ?? '') ?>">
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
<script src="<?= cbAsset('../assets/js/modal.js') ?>" defer></script>
<script src="<?= cbAsset('../assets/js/animations.js') ?>" defer></script>

</body>
</html>
