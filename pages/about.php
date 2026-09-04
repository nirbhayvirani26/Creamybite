<?php
// ============================================================
//  Creamy Bite – About Us Page
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/mailer.php';

// ── Ensure inquiries table exists (auto-create) ───────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `inquiries` (
        `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `name`       VARCHAR(120) NOT NULL,
        `email`      VARCHAR(180) NOT NULL,
        `phone`      VARCHAR(30)  NOT NULL DEFAULT '',
        `message`    TEXT         NOT NULL,
        `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `is_read`    TINYINT(1)   NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) { /* table already exists or DB issue */ }

$formSuccess = false;
$formError   = '';

// Handle contact form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['contact_submit'])) {
    $fname   = trim($_POST['contact_name']    ?? '');
    $femail  = trim($_POST['contact_email']   ?? '');
    $fphone  = trim($_POST['contact_phone']   ?? '');
    $fmsg    = trim($_POST['contact_message'] ?? '');

    // Bot protection, matching the checkout form: a honeypot field a human
    // never sees, plus a minimum time on the page. This form writes to the
    // database AND sends mail on every submit, so it was a free spam relay.
    // The token is the third guard alongside those two, and catches what
    // neither can: a post made from another site in a real visitor's browser,
    // where the honeypot is genuinely empty and the page really was open for
    // a while, because a person did load it — just not this form. Every send
    // costs an email out of the shop's mailbox, so a relay left open is a
    // reputation problem for the address as much as a full inbox.
    if (!csrfValid()) {
        error_log('Contact form refused: no valid token, from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $formError = 'That page had been open a while. Please check your message below and send it again.';
    } elseif (!empty($_POST['website'])) {
        $formError = 'Your message could not be sent. Please try again.';
    } elseif (($t = (int)($_POST['form_loaded_at'] ?? 0)) > 0 && (time() - $t) < 3) {
        $formError = 'Please take a moment before sending.';
    }
    elseif (strlen($fname) < 2)           { $formError = 'Please enter your name.'; }
    elseif (!filter_var($femail, FILTER_VALIDATE_EMAIL)) { $formError = 'Please enter a valid email address.'; }
    elseif (strlen($fphone) < 7)          { $formError = 'Please enter your phone number.'; }
    elseif (strlen($fmsg) < 10)           { $formError = 'Please enter a message (at least 10 characters).'; }
    else {
        // Save to database
        try {
            $ins = $pdo->prepare("INSERT INTO inquiries (name, email, phone, message) VALUES (:n, :e, :p, :m)");
            $ins->execute(['n' => $fname, 'e' => $femail, 'p' => $fphone, 'm' => $fmsg]);
        } catch (PDOException $e) { /* ignore DB save failure; email still goes */ }

        // Send email via PHPMailer
        $subject = 'Enquiry from ' . $fname . ' – Creamy Bite Website';
        $body    = "
            <h2 style='font-family:sans-serif;color:#ff4d6d;'>New Enquiry – Creamy Bite</h2>
            <table style='font-family:sans-serif;font-size:14px;width:100%;'>
                <tr><td style='padding:6px 0;color:#6b4c5e;font-weight:600;'>Name:</td><td>" . htmlspecialchars($fname) . "</td></tr>
                <tr><td style='padding:6px 0;color:#6b4c5e;font-weight:600;'>Email:</td><td>" . htmlspecialchars($femail) . "</td></tr>
                <tr><td style='padding:6px 0;color:#6b4c5e;font-weight:600;'>Phone:</td><td>" . htmlspecialchars($fphone) . "</td></tr>
                <tr><td style='padding:6px 0;color:#6b4c5e;font-weight:600;'>Message:</td><td>" . nl2br(htmlspecialchars($fmsg)) . "</td></tr>
            </table>
        ";
        try {
            // The enquirer's address as Reply-To, so answering them is one
            // press of Reply rather than copying the address out of the body.
            sendGenericEmail(SHOP_EMAIL, $subject, $body, $femail, $fname);
            $formSuccess = true;
        } catch (Exception $e) {
            // Email failed but DB save succeeded – still show success
            $formSuccess = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About Us – <?= SHOP_NAME ?></title>
<?php require __DIR__ . '/../includes/seo_head.php'; ?>
    <meta name="description" content="Learn the story behind <?= SHOP_NAME ?> — our passion for handcrafted ice cream and cocoa drinks. Contact us, find us on the map, and follow us on social media.">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/responsive.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/animations.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/components.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/modal.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="about-page">

<!-- ══ Navbar ══════════════════════════════════════════════ -->
<?php
$cbNavActive = 'about';
ob_start(); ?>
<a href="<?= cbUrl('order') ?>" class="btn-primary cbab-nav-order-btn">
    <i class="fa-solid fa-bolt"></i> Order Now
</a>
<?php $cbNavRight = ob_get_clean();
ob_start(); ?>
<a href="<?= cbUrl('order') ?>" class="btn-primary cbab-drawer-order-btn">
    <i class="fa-solid fa-bolt"></i> Order Now
</a>
<?php $cbNavDrawerRight = ob_get_clean();
require __DIR__ . '/../includes/site_header.php';
?>

<!-- ══ About Hero ══════════════════════════════════════════ -->
<section class="about-hero">
    <div class="container cbab-hero-inner">
        <div class="about-hero-eyebrow"><i class="fa-solid fa-ice-cream"></i> Who We Are</div>
        <h1>About Creamy Bite</h1>
        <p>Passionate about flavour, obsessed with quality, and devoted to making every bite a moment worth remembering.</p>
    </div>
</section>

<!-- ══ Our Story ═══════════════════════════════════════════ -->
<section class="about-story-section">
    <div class="container">
        <div class="about-story-grid">
            <div class="about-story-img">
                <img src="<?= cbUrl('assets/images/about_story.jpg') ?>" alt="About Creamy Bite Artisanal Ice Cream" loading="lazy">
            </div>
            <div class="about-story-content">
                <span class="section-label">Our Story</span>
                <h2>Made with Love,<br>Served with Joy</h2>
                <p>
                    Creamy Bite started from a simple love of real, honest ice cream — the kind
                    that brings you back to summer days and childhood memories. We believed the
                    world needed more flavour and fewer shortcuts.
                </p>
                <p>
                    Everything we make begins with quality: fresh dairy, real fruits, natural
                    cocoa, and genuine passion. No artificial shortcuts, no mass production.
                    Just small-batch, handcrafted goodness made for you.
                </p>
                <p>
                    Whether it's a classic vanilla scoop on a sunny afternoon or a warming
                    Belgian cocoa drink on a winter evening — we're here to make every moment
                    a little sweeter.
                </p>
                <div class="story-highlights cbab-story-highlights">
                    <div class="story-highlight">
                        <span class="story-highlight-icon"><i class="fa-solid fa-leaf"></i></span>
                        <span class="story-highlight-text">Natural Ingredients</span>
                    </div>
                    <div class="story-highlight">
                        <span class="story-highlight-icon"><i class="fa-solid fa-heart"></i></span>
                        <span class="story-highlight-text">Handcrafted Daily</span>
                    </div>
                    <div class="story-highlight">
                        <?php // Font Awesome free has no country flags, so this is a generic
                              // pennant rather than a Union Jack. The nationality is carried by
                              // the label beside it, which is also the accessible name. ?>
                        <span class="story-highlight-icon"><i class="fa-solid fa-flag" aria-hidden="true"></i></span>
                        <span class="story-highlight-text">Based in the UK</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ══ Contact & Map ════════════════════════════════════════ -->
<section class="contact-map-section">
    <div class="container">
        <div class="section-header cbab-contact-header">
            <span class="section-label">Get In Touch</span>
            <h2 class="section-title">We'd Love to Hear from You</h2>
            <p class="section-subtitle">Have a question, a special request, or just want to say hello? Drop us a message below.</p>
        </div>

        <div class="contact-grid">
            <!-- Contact Form -->
            <div>
                <?php if ($formSuccess): ?>
                <div class="alert alert-success cbab-alert-sent">
                    <i class="fa-solid fa-circle-check"></i>
                    <div>
                        <strong>Message sent!</strong><br>
                        Thank you for getting in touch. We'll get back to you as soon as possible.
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($formError): ?>
                <div class="alert alert-danger cbab-alert-error">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <div><?= htmlspecialchars($formError) ?></div>
                </div>
                <?php endif; ?>

                <div class="glass-panel cbab-contact-panel">
                    <h3 class="cbab-panel-title">
                        <i class="fa-solid fa-envelope"></i> Send an Enquiry
                    </h3>
                    <form action="<?= cbUrl('about') ?>" method="POST" id="contactForm">
                        <?= csrfField() ?>
                        <?php /* Honeypot: hidden from people, filled in by bots. */ ?>
                        <div class="cbab-honeypot" aria-hidden="true">
                            <label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                        </div>
                        <input type="hidden" name="form_loaded_at" value="<?= time() ?>">
                        <input type="hidden" name="contact_submit" value="1">

                        <div class="form-group">
                            <label for="contact_name" class="form-label">Your Name *</label>
                            <input type="text" id="contact_name" name="contact_name" class="form-control"
                                placeholder="e.g. Jane Smith" required
                                value="<?= htmlspecialchars($_POST['contact_name'] ?? '') ?>">
                        </div>

                        <div class="form-group">
                            <label for="contact_email" class="form-label">Email Address *</label>
                            <input type="email" id="contact_email" name="contact_email" class="form-control"
                                placeholder="you@example.com" required
                                value="<?= htmlspecialchars($_POST['contact_email'] ?? '') ?>">
                        </div>

                        <div class="form-group">
                            <label for="contact_phone" class="form-label">Phone Number *</label>
                            <input type="tel" id="contact_phone" name="contact_phone" class="form-control"
                                placeholder="e.g. +44 7700 900 123" required
                                value="<?= htmlspecialchars($_POST['contact_phone'] ?? '') ?>">
                        </div>

                        <div class="form-group">
                            <label for="contact_message" class="form-label">Your Message *</label>
                            <textarea id="contact_message" name="contact_message" class="form-control" rows="5"
                                placeholder="Tell us what you'd like to know, order, or arrange…" required><?= htmlspecialchars($_POST['contact_message'] ?? '') ?></textarea>
                        </div>

                        <button type="submit" class="btn-primary cbab-submit-btn">
                            <i class="fa-solid fa-paper-plane"></i> Send Message
                        </button>
                    </form>
                </div>
            </div>

            <!-- Map & Address -->
            <div>
                <div class="glass-panel cbab-find-panel">
                    <h3 class="cbab-panel-title-sm">
                        <i class="fa-solid fa-location-dot"></i> Find Us
                    </h3>
                    <p class="cbab-address">
                        <strong>Creamy Bite</strong><br>
                        Unit E5 Phoenix Business Centre<br>
                        HA1 2SP<br>
                        United Kingdom
                    </p>
                    <div class="alert alert-info cbab-hours-alert">
                        <i class="fa-solid fa-clock"></i>
                        <span><strong>Open:</strong> Every day: 11 AM – 8 PM</span>
                    </div>
                </div>

                <!-- Google Map Embed – Creamy Bite, Unit E5 Phoenix Business Centre, HA1 2SP -->
                <div class="map-container">
                    <iframe
                        src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d2479.7282738316826!2d-0.3380253232421889!3d51.57290517180947!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x487613c3e826b3c5%3A0x9f44e8a9cceacc1a!2sPhoenix%20Business%20Centre%2C%20Harrow%20HA1%202SP!5e0!3m2!1sen!2suk!4v1750514400000!5m2!1sen!2suk"
                        allowfullscreen="" loading="lazy"
                        referrerpolicy="no-referrer-when-downgrade"
                        title="Creamy Bite – Unit E5 Phoenix Business Centre HA1 2SP">
                    </iframe>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ══ Social Links ════════════════════════════════════════ -->
<section class="social-links-section">
    <div class="container cbab-social-inner">
        <span class="section-label">Follow Us</span>
        <h2 class="section-title cbab-social-title">Find Us on Social Media</h2>
        <p class="section-subtitle">Follow along for the latest flavours, behind-the-scenes moments, and sweet inspiration!</p>

        <div class="social-buttons-grid">
            <?php
            // Driven by the SHOP_* constants rather than four hand-written
            // URLs, which had already drifted: the TikTok button was href="#"
            // and took anyone who pressed it nowhere. A network with no URL
            // set is not rendered at all, so there can never be a button here
            // that does not go anywhere.
            $socials = [
                ['url' => SHOP_INSTAGRAM, 'class' => 'instagram', 'icon' => 'instagram', 'label' => 'Instagram', 'text' => 'Follow on Instagram'],
                ['url' => SHOP_WHATSAPP,  'class' => 'whatsapp',  'icon' => 'whatsapp',  'label' => 'WhatsApp',  'text' => 'Chat on WhatsApp'],
                ['url' => SHOP_TIKTOK,    'class' => 'tiktok',    'icon' => 'tiktok',    'label' => 'TikTok',    'text' => 'Follow on TikTok'],
                ['url' => SHOP_FACEBOOK,  'class' => 'facebook',  'icon' => 'facebook',  'label' => 'Facebook',  'text' => 'Like on Facebook'],
            ];
            foreach ($socials as $s):
                if (trim((string)$s['url']) === '') continue;
            ?>
            <a href="<?= htmlspecialchars($s['url']) ?>" class="social-btn social-btn-<?= $s['class'] ?>" target="_blank" rel="noopener noreferrer" aria-label="<?= $s['label'] ?>">
                <i class="fa-brands fa-<?= $s['icon'] ?> cbab-social-icon"></i>
                <?= $s['text'] ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ══ Footer ══════════════════════════════════════════════ -->
<?php // One shared footer — it used to be copied into five pages, so adding a
      // link meant editing all five and hoping none were missed. ?>
<?php require __DIR__ . '/../includes/site_footer.php'; ?>

<script src="<?= cbAsset('../assets/js/modal.js') ?>" defer></script>
<script src="<?= cbAsset('../assets/js/animations.js') ?>" defer></script>
</body>
</html>
