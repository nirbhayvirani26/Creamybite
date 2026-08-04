<?php
// ============================================================
//  Sweet Scoops – Admin Login
//  URL: /Orders/admin/login.php
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/config.php';

/**
 * Where to go once the password checks out.
 *
 * The guard stores the page you were originally after, so a one-off admin URL
 * (a migration runner, a report) survives the login detour instead of quietly
 * turning into "you are now on the dashboard". Anything that is not a plain
 * same-site path is discarded rather than trusted.
 */
function adminLoginDestination(): string
{
    $wanted = $_SESSION['admin_redirect_after_login'] ?? '';
    unset($_SESSION['admin_redirect_after_login']);

    if (!is_string($wanted) || $wanted === '' || $wanted[0] !== '/' || str_starts_with($wanted, '//')) {
        return 'index.php';
    }
    // Never bounce straight back to the login form.
    if (str_contains($wanted, 'login.php') || str_contains($wanted, 'logout')) {
        return 'index.php';
    }
    return $wanted;
}

// Already logged in → redirect
if (!empty($_SESSION['admin_logged_in'])) {
    header('Location: ' . adminLoginDestination()); exit;
}

// ── Throttle repeated failures ───────────────────────────────
// There is a single shared admin password, so an unthrottled form is a
// straightforward brute-force target. Five failures locks this browser out
// for 15 minutes.
const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCKOUT_SECS = 900;

$attempts  = (int)($_SESSION['admin_login_attempts'] ?? 0);
$lockedTil = (int)($_SESSION['admin_login_locked_until'] ?? 0);

// A server with no .env has ADMIN_USERNAME and ADMIN_PASSWORD as empty
// strings, and hash_equals('', '') is TRUE — so posting a blank username and
// a blank password would have logged anyone straight into the admin panel.
// The form's `required` attributes are no defence: a POST does not have to
// come from the form.
//
// This is the one credential that fails OPEN rather than closed when it is
// missing (no database password simply refuses to connect, no Stripe key
// simply disables card payments), so it is checked explicitly and the login
// is refused outright until the server has real credentials.
$adminCredsConfigured = ADMIN_USERNAME !== '' && ADMIN_PASSWORD !== '';

$error = '';
if (!$adminCredsConfigured) {
    // Says which of the two situations it is, because "it will not let me in"
    // is the same symptom for a missing file and a half-filled one, and the
    // health check that would normally answer this is itself behind the login.
    //
    // Naming the missing keys is safe: no value is shown, and an unconfigured
    // panel can no longer be entered by anyone, so this tells an attacker
    // nothing they could use.
    require_once __DIR__ . '/../includes/env.php';
    $error = cbEnvLoaded()
        ? 'This server has a .env file, but ADMIN_USERNAME and ADMIN_PASSWORD are empty in it. '
          . 'Fill both in, then restart PHP in hPanel.'
        : 'This server has no .env file, so it has no admin password to check against. '
          . 'Copy .env.example to .env in the site root, fill it in, then restart PHP in hPanel.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $adminCredsConfigured) {

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
            // Read (and clear) the destination while it is still needed; the
            // regeneration below only swaps the session id, so the order here
            // is for clarity rather than correctness.
            $dest = adminLoginDestination();
            session_regenerate_id(true);
            $_SESSION['admin_logged_in'] = true;
            unset($_SESSION['admin_login_attempts'], $_SESSION['admin_login_locked_until']);
            header('Location: ' . $dest); exit;
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
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="assets/css/setup.css">
    <link rel="stylesheet" href="../assets/css/responsive.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="../assets/css/modal.css">
<script src="../assets/js/modal.js" defer></script>
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
                    <!-- One class attribute, not two: a second one is ignored
                         outright, which is why the padding that keeps typed
                         text clear of the eye button was never applied. -->
                    <input type="password" id="password" name="password"
                        class="form-control lg-input-pad"
                        placeholder="••••••••" required autocomplete="current-password">
                    <button type="button" id="togglePwd"
                        onclick="togglePassword()"
                        aria-label="Toggle password visibility"
                        class="lg-pwd-toggle">
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
    <div class="container lg-footer-inner">
        <span class="footer-copy">© <?= date('Y') ?> <?= SHOP_NAME ?> Admin Panel</span>
    </div>
</footer>

<script>
function togglePassword() {
    const input = document.getElementById('password');
    const icon  = document.getElementById('eyeIcon');
    const btn   = document.getElementById('togglePwd');
    const showing = (input.type === 'password');
    input.type     = showing ? 'text' : 'password';
    icon.className = showing ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
    // The lit state is a class, so its colour stays with the rest of the
    // button's styling instead of being written here.
    btn.classList.toggle('is-showing', showing);
}
</script>
</body>
</html>
