<?php
// ============================================================
//  Creamy Bite – Customer review submission
//
//  Takes the form on the home page reviews section and stores the review
//  UNAPPROVED. Nothing a stranger types appears on the site until someone
//  publishes it in Admin → Reviews. A review box that publishes on submit is
//  a review box full of spam by the end of the week.
//
//  Redirects back to the home page rather than rendering anything of its
//  own, so a refresh cannot re-submit the same review (post/redirect/get).
// ============================================================
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';

$back = SITE_BASE . '/index.php';

function cbReviewBack(string $base, string $status, array $keep = []): void
{
    // Keep what they typed so a validation error does not wipe the box.
    $_SESSION['review_form'] = $keep;
    $_SESSION['review_status'] = $status;
    header('Location: ' . $base . '#reviews');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $back);
    exit;
}

$name     = trim($_POST['customer_name'] ?? '');
$location = trim($_POST['location']      ?? '');
$body     = trim($_POST['body']          ?? '');
$product  = trim($_POST['product_name']  ?? '');
$rating   = max(1, min(5, (int)($_POST['rating'] ?? 5)));
$trap     = trim($_POST['website']       ?? '');

$keep = ['customer_name' => $name, 'location' => $location, 'body' => $body,
         'product_name' => $product, 'rating' => $rating];

// The honeypot below catches a bot filling the form in. The token catches
// something the honeypot cannot: another site posting this form from a real
// customer's browser, with the hidden field correctly left empty because no
// one ever saw the form at all. Reviews land unapproved either way, so the
// damage is a moderation queue nobody can get to the bottom of rather than
// spam on the page — but that is enough to make a genuine review invisible
// among the noise.
//
// Refused the same way every other problem on this form is: straight back to
// the reviews section with what they typed still in the box.
if (!csrfValid()) {
    error_log('Review submission refused: no valid token, from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    cbReviewBack($back, 'That page had been open a while. Please check your review below and send it again.', $keep);
}

if ($name === '' || $body === '') {
    cbReviewBack($back, 'Please give your name and tell us what you thought.', $keep);
}
if (mb_strlen($body) < 10) {
    cbReviewBack($back, 'Could you say a little more? Even one sentence helps.', $keep);
}
if (mb_strlen($body) > 2000) {
    cbReviewBack($back, 'That is a bit long for this box — please keep it under 2000 characters.', $keep);
}

// One review per visitor per five minutes. Stops a bored someone filling the
// moderation queue faster than it can be read.
$lastAt = (int)($_SESSION['review_last_at'] ?? 0);
if ($lastAt > 0 && (time() - $lastAt) < 300) {
    cbReviewBack($back, 'Thanks — we have already got your review. Give it a few minutes before sending another.', []);
}

// The honeypot is hidden from people and filled in by most bots. Accepted
// silently rather than rejected: a bot that is told it failed learns to work
// around the trap, whereas one that is thanked goes away.
if ($trap === '') {
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO testimonials (customer_name, location, rating, body, product_name, source, approved, submitter_ip)
             VALUES (:n, :l, :r, :b, :p, 'website', 0, :ip)"
        );
        $stmt->execute([
            'n'  => mb_substr($name, 0, 120),
            'l'  => mb_substr($location, 0, 120),
            'r'  => $rating,
            'b'  => $body,
            'p'  => mb_substr($product, 0, 150),
            'ip' => mb_substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
        ]);
    } catch (PDOException $e) {
        error_log('Review insert failed: ' . $e->getMessage());
        cbReviewBack($back, 'Something went wrong saving that. Please try again, or email us.', $keep);
    }
}

$_SESSION['review_last_at'] = time();
cbReviewBack($back, 'THANKS', []);
