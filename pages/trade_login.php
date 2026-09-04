<?php
// ============================================================
// Creamy Bite – Trade B2B Login
// URL: /trade_login or /trade_login.php
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/trade_cart.php';

$errorMsg = '';

// Where to go after signing in. Only a path on this site is accepted — an
// absolute URL here would turn the login page into an open redirect, handing
// anyone a creamybite.com link that lands on a site they control.
$cbNext = $_GET['next'] ?? $_POST['next'] ?? '';
if ($cbNext === '' || !str_starts_with($cbNext, '/') || str_starts_with($cbNext, '//')) {
    $cbNext = 'order.php';
}

// If already logged in, redirect to order page
if (!empty($_SESSION['trade_user'])) {
    header('Location: ' . $cbNext);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    // The token proves the sign-in was started from this page, not posted by
    // another site the partner happened to have open. Without it, any page on
    // the web can submit this form in their browser and quietly sign them into
    // an account someone else controls — after which the basket they build,
    // the delivery address they type and the order they place all belong to
    // that other account.
    //
    // Deliberately a soft failure, not the hard "Request blocked" page the
    // admin panel shows. The overwhelmingly likely cause here is not an attack
    // but a partner who left the login page open long enough for the session
    // to lapse, and telling a customer their request was blocked when they
    // simply need to type their password again is how a shop loses an order.
    // They land back on the form with everything except the password intact.
    //
    // Logged as well as refused, because the two causes look identical to the
    // customer and completely different to the owner: one lapsed token now and
    // then is a session timing out, a burst of them is someone posting at the
    // form. The health check's log viewer is where that difference shows up.
    if (!csrfValid()) {
        error_log('Trade login rejected: no valid form token, from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $errorMsg = 'Your session timed out while this page was open. Please enter your password again.';
    } elseif (empty($email) || empty($password)) {
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
                    // The alert below prints $errorMsg unescaped, so the icon is
                    // real markup. The sentence still states the outcome on its
                    // own — the icon only decorates it.
                    $errorMsg = '<i class="fa-solid fa-circle-xmark cb-alert-icon" aria-hidden="true"></i>Your trade account application was not approved. Please contact us for support.';
                } elseif ($user['status'] === 'approved') {
                    // New session id the moment the sign-in succeeds, exactly
                    // as the admin login does. Anyone who managed to plant a
                    // known session id in the partner's browser beforehand —
                    // a shared machine in the shop, a link carrying one — is
                    // holding an id that no longer refers to anything, rather
                    // than one that is now signed in as the partner.
                    // The second argument deletes the old session file; the
                    // basket and everything else in $_SESSION carries over.
                    session_regenerate_id(true);

                    $_SESSION['trade_user'] = [
                        'id'            => $user['id'],
                        'business_name' => $user['business_name'],
                        'contact_name'  => $user['contact_name'],
                        'email'         => $user['email'],
                        'phone'         => $user['phone'],
                        'address'       => $user['address'],
                        'postcode'      => $user['postcode'],
                        // Drives whether VAT is added to this partner's orders.
                        'vat_number'    => $user['vat_number'] ?? '',
                    ];
                    // A promo in session is a retail code; trade orders never
                    // take one, so leaving it would show a discount on the
                    // checkout that the server refuses to grant.
                    unset($_SESSION['promo']);

                    // Bring back the basket they left behind, merged with
                    // anything added before logging in, re-priced at wholesale.
                    tradeCartRestore($pdo, (int)$user['id']);
                    header('Location: ' . $cbNext);
                    exit;
                }
            } else {
                $errorMsg = 'Invalid email or password';
            }
        } catch (PDOException $e) {
            // The database's own words go to the log, never to the page. This
            // line used to print $e->getMessage() straight into the alert, so
            // a trade partner hitting a bad moment on the server was shown the
            // failing SQL, the table and column names behind it and the login
            // the site connects with — read by whoever happened to be trying
            // to sign in, and by anyone who could make the query fail on
            // purpose.
            error_log('Trade login failed: ' . $e->getMessage());
            $errorMsg = 'We could not sign you in just now. Please try again in a moment, or call us on ' . htmlspecialchars(SHOP_PHONE) . '.';
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
    <?php // A login form has nothing for search to rank; send searchers to
          // trade_register.php instead. ?>
    <meta name="robots" content="noindex, nofollow">
    <?php require __DIR__ . '/../includes/favicon.php'; ?>
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
$cbNavShowTrade = false; // already on the login page — showing a trade-account pill too would be redundant
ob_start(); ?>
<a href="<?= cbUrl('trade_register') ?>" class="btn-secondary cbtl-nav-apply-btn">
    <i class="fa-solid fa-user-plus"></i> Apply for Trade
</a>
<?php $cbNavRight = ob_get_clean();
require __DIR__ . '/../includes/site_header.php';
?>

<main class="cbtl-login-main">
    <div class="container cbtl-login-container">
        <div class="glass-panel cbtl-login-card">
            <div class="cbtl-login-header">
                <span class="section-label">B2B Portal</span>
                <h1 class="cbtl-login-title">Trade Partner Login <i class="fa-solid fa-store cb-title-icon" aria-hidden="true"></i></h1>
                <p class="cbtl-login-subtitle">
                    Log in with your approved trade email to access wholesale pricing.
                </p>
            </div>

            <?php if ($errorMsg): ?>
                <div class="alert alert-danger cbtl-login-alert">
                    <?= $errorMsg ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="<?= cbUrl('trade_login') ?>" class="cbtl-login-form">
                <?= csrfField() ?>
                <?php // Carried through the POST, or the destination is lost the
                      // moment the form submits and everyone lands on order.php. ?>
                <input type="hidden" name="next" value="<?= htmlspecialchars($cbNext) ?>">
                <div class="form-group">
                    <label class="form-label cbtl-login-label" for="tl_email">Registered Email Address</label>
                    <input id="tl_email" type="email" name="email" class="form-control" placeholder="orders@yourstore.com" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label class="form-label cbtl-login-label" for="tl_password">Password</label>
                    <input id="tl_password" type="password" name="password" class="form-control" placeholder="Enter your password" required>
                </div>

                <button type="submit" class="btn-primary cbtl-login-submit">
                    <i class="fa-solid fa-right-to-bracket"></i> Login to Wholesale Menu
                </button>

                <div class="cbtl-login-signup-note">
                    Don't have a Trade Account yet? <a href="<?= cbUrl('trade_register') ?>" class="cbtl-login-signup-link">Apply Here</a>
                </div>
            </form>
        </div>
    </div>
</main>

<!-- Footer -->
<footer class="footer">
    <div class="container cbtl-footer-note">
        &copy; <?= date('Y') ?> CreamyBite.com — B2B Wholesale Login
    </div>
</footer>
<script src="<?= cbAsset('../assets/js/modal.js') ?>" defer></script>
<script src="<?= cbAsset('../assets/js/animations.js') ?>" defer></script>

</body>
</html>
