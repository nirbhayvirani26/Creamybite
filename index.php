<?php
// ============================================================
//  Creamy Bite – Home / Landing Page
// ============================================================
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/flavour_colours.php';

// Load a few featured products for the flavour showcase
$featured = [];
try {
    // The home page is public, so never feature a trade-only product here.
    $stmt = $pdo->query("SELECT * FROM products WHERE available = 1 AND trade_only = 0 ORDER BY id ASC LIMIT 6");
    $featured = $stmt->fetchAll();
} catch (PDOException $e) { }

// Home page banner slides.
//
// Only live ones: active, and inside their date window if they have been
// given one. The window is checked in SQL rather than in PHP so a promotion
// that ended yesterday is gone the moment the page is loaded, without anyone
// having to switch it off.
$banners = [];
try {
    $banners = $pdo->query(
        "SELECT * FROM banners
          WHERE active = 1
            AND (starts_on IS NULL OR starts_on <= CURDATE())
            AND (ends_on   IS NULL OR ends_on   >= CURDATE())
       ORDER BY sort_order ASC, id ASC"
    )->fetchAll();
} catch (PDOException $e) {
    // Table arrives with the migration. The hero below stands in until then,
    // so the home page never loses its top section.
}

// Customer reviews for the testimonials strip.
//
// Approved only, and the section hides itself entirely when there are none —
// an empty "What our customers say" heading looks worse than no section at
// all. Nothing is seeded: inventing testimonials to fill the space is banned
// under the Digital Markets, Competition and Consumers Act 2024, so this
// stays empty until real ones are approved in the admin panel.
$testimonials = [];
$reviewsTableOk = true;
try {
    $testimonials = $pdo->query(
        "SELECT * FROM testimonials WHERE approved = 1
         ORDER BY featured DESC, sort_order ASC, created_at DESC LIMIT 6"
    )->fetchAll();
} catch (PDOException $e) {
    // Table does not exist until the migration has run. Hide the section
    // rather than taking the home page down over a missing table.
    $reviewsTableOk = false;
}

// Message from review_submit.php, read once then cleared so a refresh does
// not show "thanks" again.
$reviewStatus = $_SESSION['review_status'] ?? '';
$reviewKeep   = $_SESSION['review_form']   ?? [];
unset($_SESSION['review_status'], $_SESSION['review_form']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Creamy Bite – Handcrafted Ice Cream &amp; Cocoa Drinks</title>
    <meta name="description" content="Creamy Bite serves handcrafted ice cream and rich cocoa drinks made fresh daily with the finest ingredients. Discover our story and explore our flavours.">
<?php $cbSeoLocalBusiness = true; ?>
<?php require __DIR__ . '/includes/seo_head.php'; ?>
    <link rel="stylesheet" href="<?= cbAsset('assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('assets/css/responsive.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('assets/css/animations.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('assets/css/components.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('assets/css/modal.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

<!-- ══ Navbar ══════════════════════════════════════════════ -->
<?php
$cbNavActive = 'home';
ob_start(); ?>
<a href="pages/order.php" class="btn-primary cbhm-btn-nav">
    <i class="fa-solid fa-bolt"></i> Order Now
</a>
<?php $cbNavRight = ob_get_clean();
ob_start(); ?>
<a href="pages/order.php" class="btn-primary cbhm-btn-drawer">
    <i class="fa-solid fa-bolt"></i> Order Now
</a>
<?php $cbNavDrawerRight = ob_get_clean();
require __DIR__ . '/includes/site_header.php';
?>

<!-- ══ Hero ════════════════════════════════════════════════ -->
<?php if (!empty($banners)): ?>
<!-- ══ Banner slider ════════════════════════════════════════ -->
<?php
// Replaces the hero rather than sitting above it. Two full-height panels
// stacked would push everything below the fold, and a banner slider is the
// hero on a shop that has one. With no banners set up the hero below runs as
// it always has, so this can be switched on and off from the admin without
// ever leaving the page headless.
//
// Built on the same scroll-snap track as the reviews slider: it works by
// swipe and keyboard with no JavaScript at all, and the arrows, dots and
// auto-advance are layered on top.
?>
<section class="cbbn" id="homeBanner" aria-roledescription="carousel" aria-label="Offers">
    <div class="cbbn-track" id="cbbnTrack" tabindex="0">
        <?php foreach ($banners as $i => $b): ?>
        <?php
            $hasLink = trim((string)$b['link_url']) !== '';
            $tag     = $hasLink && trim((string)$b['link_text']) === '' ? 'a' : 'div';
        ?>
        <<?= $tag ?> class="cbbn-slide"
            <?= $tag === 'a' ? 'href="' . htmlspecialchars($b['link_url']) . '"' : '' ?>
            role="group" aria-roledescription="slide"
            aria-label="<?= $i + 1 ?> of <?= count($banners) ?>">
            <img class="cbbn-img"
                 src="<?= SITE_BASE ?>/assets/images/banners/<?= htmlspecialchars($b['image']) ?>"
                 alt="<?= htmlspecialchars($b['headline'] ?: 'Creamy Bite offer') ?>"
                 <?= $i === 0 ? '' : 'loading="lazy"' ?>>
            <?php if (trim((string)$b['headline']) !== '' || trim((string)$b['subtext']) !== ''): ?>
            <?php // Defensive default: a live database that has not run the
                  // migration yet has no text_position column at all. ?>
            <div class="cbbn-caption<?= ($b['text_position'] ?? 'left') === 'right' ? ' cbbn-caption--right' : '' ?>">
                <?php if (trim((string)$b['headline']) !== ''): ?>
                <h2 class="cbbn-headline"><?= htmlspecialchars($b['headline']) ?></h2>
                <?php endif; ?>
                <?php if (trim((string)$b['subtext']) !== ''): ?>
                <p class="cbbn-sub"><?= htmlspecialchars($b['subtext']) ?></p>
                <?php endif; ?>
                <?php if ($hasLink && trim((string)$b['link_text']) !== ''): ?>
                <a class="btn-cta-white cbbn-cta" href="<?= htmlspecialchars($b['link_url']) ?>">
                    <?= htmlspecialchars($b['link_text']) ?>
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </<?= $tag ?>>
        <?php endforeach; ?>
    </div>

    <?php if (count($banners) > 1): ?>
    <button type="button" class="cbbn-nav cbbn-prev" id="cbbnPrev" aria-label="Previous slide" hidden>
        <i class="fa-solid fa-chevron-left"></i>
    </button>
    <button type="button" class="cbbn-nav cbbn-next" id="cbbnNext" aria-label="Next slide" hidden>
        <i class="fa-solid fa-chevron-right"></i>
    </button>
    <div class="cbbn-dots" id="cbbnDots" hidden></div>
    <?php endif; ?>
</section>

<?php if (count($banners) > 1): ?>
<script>
// Banner slider. The track already scroll-snaps in CSS, so this only adds the
// arrows, the dots and the auto-advance — with the script blocked it is still
// a swipeable strip rather than a broken box.
(function () {
    const track = document.getElementById('cbbnTrack');
    const prev  = document.getElementById('cbbnPrev');
    const next  = document.getElementById('cbbnNext');
    const dots  = document.getElementById('cbbnDots');
    if (!track || !prev || !next || !dots) return;

    const slides = [...track.querySelectorAll('.cbbn-slide')];
    if (slides.length < 2) return;

    let timer = null;
    const DELAY = 6000;

    function page() {
        return track.clientWidth > 0 ? Math.round(track.scrollLeft / track.clientWidth) : 0;
    }

    function go(i, smooth) {
        // Wraps at both ends so the arrows never dead-end on a carousel.
        const n = slides.length;
        const target = ((i % n) + n) % n;
        track.scrollTo({ left: target * track.clientWidth, behavior: smooth === false ? 'auto' : 'smooth' });
    }

    function sync() {
        const p = page();
        [...dots.children].forEach((d, i) => {
            d.classList.toggle('is-active', i === p);
            d.setAttribute('aria-current', i === p ? 'true' : 'false');
        });
    }

    slides.forEach((_, i) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'cbbn-dot';
        b.setAttribute('aria-label', 'Go to slide ' + (i + 1));
        b.addEventListener('click', () => { go(i); restart(); });
        dots.appendChild(b);
    });
    prev.hidden = next.hidden = dots.hidden = false;

    prev.addEventListener('click', () => { go(page() - 1); restart(); });
    next.addEventListener('click', () => { go(page() + 1); restart(); });

    let settle;
    track.addEventListener('scroll', () => { clearTimeout(settle); settle = setTimeout(sync, 90); }, { passive: true });
    window.addEventListener('resize', () => { clearTimeout(settle); settle = setTimeout(sync, 150); });

    // ── Auto-advance ────────────────────────────────────────
    //
    // Paused on hover, on keyboard focus, and whenever the tab is in the
    // background — a carousel that keeps cycling while nobody is looking is
    // just wasted work, and one that moves while someone is reading it is
    // worse than one that does not move at all.
    function start() {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        stop();
        timer = setInterval(() => { if (!document.hidden) go(page() + 1); }, DELAY);
    }
    function stop()    { if (timer) { clearInterval(timer); timer = null; } }
    function restart() { stop(); start(); }

    const box = document.getElementById('homeBanner');
    box.addEventListener('mouseenter', stop);
    box.addEventListener('mouseleave', start);
    box.addEventListener('focusin',  stop);
    box.addEventListener('focusout', start);
    document.addEventListener('visibilitychange', () => document.hidden ? stop() : start());

    // Left/right arrow keys when the strip has focus.
    track.addEventListener('keydown', e => {
        if (e.key === 'ArrowRight') { e.preventDefault(); go(page() + 1); restart(); }
        if (e.key === 'ArrowLeft')  { e.preventDefault(); go(page() - 1); restart(); }
    });

    sync();
    start();
})();
</script>
<?php endif; ?>
<?php else: ?>
<section class="landing-hero">
    <?php // Decorative scoops, cones and falling sprinkles. Purely cosmetic —
          // hidden for reduced-motion users via animations.css, and painted
          // behind the text (z-index 0) so they never cover content. ?>
    <span class="cb-hero-float cb-hero-float--lg cb-hero-float--bob cb-hero-float--p1" aria-hidden="true"><i class="fa-solid fa-ice-cream"></i></span>
    <span class="cb-hero-float cb-hero-float--bob-2 cb-hero-float--p2" aria-hidden="true"><i class="fa-solid fa-bowl-food"></i></span>
    <span class="cb-hero-float cb-hero-float--sm cb-hero-float--bob cb-hero-float--p3" aria-hidden="true"><i class="fa-solid fa-snowflake"></i></span>
    <span class="cb-hero-float cb-hero-float--sm cb-hero-float--drip cb-hero-float--p4" aria-hidden="true"><i class="fa-solid fa-cookie-bite"></i></span>
    <span class="cb-hero-float cb-hero-float--drip-2 cb-hero-float--p5" aria-hidden="true"><i class="fa-solid fa-mug-hot"></i></span>
    <?php
    // Falling sprinkles — positions, colours, drift and timing vary per
    // dot so the loop reads as confetti rather than a metronome.
    $sprinkles = [
        ['left' => '8%',  'class' => 'cb-sprinkle--cocoa',   'drift' => '18px',  'fall' => '5.6s', 'delay' => '0.2s'],
        ['left' => '16%', 'class' => 'cb-sprinkle--gold',    'drift' => '-22px', 'fall' => '6.4s', 'delay' => '1.1s'],
        ['left' => '24%', 'class' => 'cb-sprinkle--mint',    'drift' => '14px',  'fall' => '5.1s', 'delay' => '2.3s'],
        ['left' => '33%', 'class' => 'cb-sprinkle--rose',    'drift' => '-16px', 'fall' => '6.9s', 'delay' => '0.8s'],
        ['left' => '42%', 'class' => 'cb-sprinkle--amber',   'drift' => '24px',  'fall' => '5.9s', 'delay' => '3.0s'],
        ['left' => '51%', 'class' => 'cb-sprinkle--vanilla', 'drift' => '-18px', 'fall' => '6.2s', 'delay' => '1.7s'],
        ['left' => '58%', 'class' => 'cb-sprinkle--cocoa',   'drift' => '20px',  'fall' => '5.4s', 'delay' => '2.8s'],
        ['left' => '67%', 'class' => 'cb-sprinkle--gold',    'drift' => '-24px', 'fall' => '6.7s', 'delay' => '0.5s'],
        ['left' => '76%', 'class' => 'cb-sprinkle--mint',    'drift' => '16px',  'fall' => '5.3s', 'delay' => '3.6s'],
        ['left' => '84%', 'class' => 'cb-sprinkle--rose',    'drift' => '-14px', 'fall' => '6.5s', 'delay' => '1.4s'],
        ['left' => '92%', 'class' => 'cb-sprinkle--amber',   'drift' => '22px',  'fall' => '5.8s', 'delay' => '2.1s'],
    ];
    foreach ($sprinkles as $s): ?>
    <span class="cb-sprinkle <?= $s['class'] ?>" aria-hidden="true"
          style="left: <?= $s['left'] ?>; --cb-drift: <?= $s['drift'] ?>; --cb-fall: <?= $s['fall'] ?>; --cb-delay: <?= $s['delay'] ?>"></span>
    <?php endforeach; ?>
    <div class="container landing-hero-inner">
        <div class="landing-eyebrow"><i class="fa-solid fa-wand-magic-sparkles cb-eyebrow-icon"></i> Freshly Made Every Day</div>
        <h1 class="landing-title">Every Scoop is a<br>Sweet Adventure!</h1>
        <p class="landing-subtitle">
            At Creamy Bite, we handcraft every flavour with the finest ingredients.
            From classic vanilla scoops to rich Belgian cocoa drinks — pure joy in every bite.
        </p>
        <div class="landing-cta-group">
            <a href="pages/order.php" class="btn-hero-primary">
                <i class="fa-solid fa-ice-cream"></i> Explore Our Menu
            </a>
            <a href="pages/about.php" class="btn-hero-secondary">
                Our Story
            </a>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ══ Stats Strip ═════════════════════════════════════════ -->
<?php // Trust badges — real product claims (palm-oil-free is printed on the
      // tub itself), not generic marketing copy. ?>
<div class="hero-stats">
    <div class="container cbhm-stats-row">
        <div class="hero-stat">
            <span class="hero-stat-icon"><i class="fa-solid fa-egg" aria-hidden="true"></i></span>
            <div class="hero-stat-value">Eggless</div>
            <div class="hero-stat-label">No eggs, ever</div>
        </div>
        <div class="hero-stat">
            <span class="hero-stat-icon"><i class="fa-solid fa-seedling" aria-hidden="true"></i></span>
            <div class="hero-stat-value">Vegetarian</div>
            <div class="hero-stat-label">100% vegetarian recipe</div>
        </div>
        <div class="hero-stat">
            <span class="hero-stat-icon"><i class="fa-solid fa-ice-cream" aria-hidden="true"></i></span>
            <div class="hero-stat-value">10+ Flavours</div>
            <div class="hero-stat-label">Something for every mood</div>
        </div>
        <div class="hero-stat">
            <span class="hero-stat-icon"><i class="fa-solid fa-leaf" aria-hidden="true"></i></span>
            <div class="hero-stat-value">100% Fresh</div>
            <div class="hero-stat-label">Quality ingredients only</div>
        </div>
        <div class="hero-stat">
            <span class="hero-stat-icon"><i class="fa-solid fa-tree" aria-hidden="true"></i></span>
            <div class="hero-stat-value">Palm Oil Free</div>
            <div class="hero-stat-label">Better for you and the planet</div>
        </div>
    </div>
</div>

<!-- ══ Ice Cream Flavours Showcase ════════════════════════ -->
<section class="flavours-section">
    <div class="container">
        <div class="section-header">
            <span class="section-label">Our Flavours</span>
            <?php // The page's one h1. It used to live in the hero, but the hero is the
                  // ELSE branch of the banner slider — switching banners on removed the
                  // only h1 from the most important page on the site. Here it survives
                  // either way. .section-title is styled by class, so the tag change is
                  // invisible. ?>
            <h1 class="section-title">Handcrafted Ice Cream for Every Mood</h1>
            <div class="cb-drip-line" aria-hidden="true"><span></span><span></span><span></span></div>
            <p class="section-subtitle">From indulgent chocolate to refreshing sorbets — handcrafted with love for every craving.</p>
        </div>
        <div class="flavour-cards-grid">
            <?php foreach ($featured as $p):
                $imgSrc = !empty($p['image']) ? 'assets/images/products/' . htmlspecialchars($p['image']) : '';
                $cbFlavourClass = cbFlavourCardClass($p['name']);
            ?>
            <div class="flavour-card<?= $cbFlavourClass !== '' ? ' ' . $cbFlavourClass : '' ?>">
                <div class="flavour-card-img">
                    <?php if ($imgSrc): ?>
                    <img src="<?= $imgSrc ?>" alt="<?= htmlspecialchars($p['name']) ?>" loading="lazy">
                    <?php else:
                        // products.emoji is owner-entered data, so whatever is stored
                        // still prints as-is. Only the built-in fallback becomes an
                        // icon, matching the placeholder cards further down.
                        $cbFlavourMark = trim((string)($p['emoji'] ?? ''));
                    ?>
                        <?php if ($cbFlavourMark !== ''): ?>
                        <?= htmlspecialchars($cbFlavourMark) ?>
                        <?php else: ?>
                        <i class="fa-solid fa-ice-cream" aria-hidden="true"></i>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div class="flavour-card-body">
                    <div class="flavour-card-name"><?= htmlspecialchars($p['name']) ?></div>
                    <p class="flavour-card-desc"><?= htmlspecialchars($p['description']) ?></p>
                </div>
            </div>
            <?php endforeach; ?>

            <?php if (empty($featured)): ?>
            <!-- Placeholder flavour cards if no products yet -->
            <?php
            $placeholders = [
                ['fa-ice-cream','Classic Vanilla','Rich, creamy vanilla made with real vanilla beans and fresh dairy.'],
                ['fa-cookie-bite','Dark Chocolate','Indulgent Belgian dark chocolate with a velvety smooth texture.'],
                ['fa-apple-whole','Strawberry Dream','Fresh-picked strawberries blended into a light fruity ice cream.'],
                ['fa-lemon','Mango Sorbet','Dairy-free tropical mango sorbet made with 100% real Alphonso mangoes.'],
                ['fa-rainbow','Rainbow Sherbet','A vibrant swirl of raspberry, lime, and orange sherbet.'],
                ['fa-mug-hot','Cocoa Classic','Rich Belgian cocoa blended to perfection — smooth and creamy.'],
            ];
            foreach ($placeholders as $ph): ?>
            <div class="flavour-card">
                <div class="flavour-card-img"><i class="fa-solid <?= $ph[0] ?>"></i></div>
                <div class="flavour-card-body">
                    <div class="flavour-card-name"><?= $ph[1] ?></div>
                    <p class="flavour-card-desc"><?= $ph[2] ?></p>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="cbhm-flavours-cta">
            <a href="pages/order.php" class="btn-primary cbhm-btn-wide">
                <i class="fa-solid fa-arrow-right"></i> See Full Menu & Order
            </a>
        </div>
    </div>
</section>

<!-- ══ Our Story ════════════════════════════════════════════ -->
<section class="story-section">
    <div class="container">
        <div class="story-grid">
            <div class="story-img-block">
                <img src="assets/images/story_artisan.jpg" alt="Creamy Bite Handcrafted Ice Cream Story" loading="lazy">
            </div>
            <div class="story-content">
                <span class="section-label">Our Story</span>
                <h2>Born from a Love of<br>Real Flavours</h2>
                <p>
                    Creamy Bite was born from a simple belief — that ice cream should be something special.
                    Not mass-produced, not full of artificial flavours, but handcrafted with care, using
                    the finest ingredients we can find.
                </p>
                <p>
                    Every scoop, every cocoa drink, every flavour we create starts the same way:
                    with real ingredients, proper techniques, and a genuine passion for making
                    people smile. We churn our ice cream slowly, blend our cocoas carefully,
                    and never take shortcuts with quality.
                </p>
                <p>
                    Whether you're treating yourself or sharing with family, we want every
                    bite to bring a smile to your face.
                </p>
                <div class="story-highlights">
                    <div class="story-highlight">
                        <span class="story-highlight-icon"><i class="fa-solid fa-seedling"></i></span>
                        <div>
                            <strong>100% Natural</strong>
                            <div class="cbhm-highlight-sub">Real ingredients only</div>
                        </div>
                    </div>
                    <div class="story-highlight">
                        <span class="story-highlight-icon"><i class="fa-solid fa-mug-hot"></i></span>
                        <div>
                            <strong>Fresh Daily</strong>
                            <div class="cbhm-highlight-sub">Handcrafted in-house</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ══ Reviews ══════════════════════════════════════════════ -->
<?php if ($reviewsTableOk):
    $cbAvg = $testimonials
        ? array_sum(array_map(static fn($r) => (int)$r['rating'], $testimonials)) / count($testimonials)
        : 0;
?>
<section class="cbrev-section" id="reviews">
    <div class="container">
        <div class="cbrev-head">
            <span class="section-label">Kind Words</span>
            <h2 class="section-title">What Our Customers Say</h2>
            <?php if (!empty($testimonials)): ?>
            <div class="cbrev-summary">
                <div class="cbrev-stars" aria-label="<?= number_format($cbAvg, 1) ?> out of 5">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <i class="fa-<?= $i <= round($cbAvg) ? 'solid' : 'regular' ?> fa-star"></i>
                    <?php endfor; ?>
                </div>
                <span class="cbrev-summary-text">
                    <strong><?= number_format($cbAvg, 1) ?></strong>
                    from <?= count($testimonials) ?> review<?= count($testimonials) === 1 ? '' : 's' ?>
                </span>
            </div>
        </div>

        <?php // A slider rather than a grid, so a growing pile of reviews does not
              // push the rest of the home page further and further down.
              //
              // It is a scroll-snapping row first and a slider second: with
              // JavaScript off, or if the script fails, it is still a normal
              // horizontally scrollable strip that works by swipe and by
              // keyboard. The arrows and dots are added on top of that, and
              // hide themselves when everything already fits on screen. ?>
        <div class="cbrev-slider" id="cbrevSlider">
            <button type="button" class="cbrev-nav cbrev-nav-prev" id="cbrevPrev"
                    aria-label="Previous reviews" hidden>
                <i class="fa-solid fa-chevron-left"></i>
            </button>

            <div class="cbrev-track" id="cbrevTrack" tabindex="0" role="group" aria-label="Customer reviews">
            <?php foreach ($testimonials as $r): ?>
            <article class="cbrev-card">
                <div class="cbrev-stars cbrev-stars-sm" aria-label="<?= (int)$r['rating'] ?> out of 5">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <i class="fa-<?= $i <= (int)$r['rating'] ? 'solid' : 'regular' ?> fa-star"></i>
                    <?php endfor; ?>
                </div>
                <blockquote class="cbrev-body"><?= nl2br(htmlspecialchars($r['body'])) ?></blockquote>
                <footer class="cbrev-meta">
                    <strong><?= htmlspecialchars($r['customer_name']) ?></strong>
                    <?php
                        // Town and flavour are both optional, so build the byline
                        // from whatever is actually there rather than printing
                        // stray separators around empty values.
                        $bits = [];
                        if (trim((string)$r['location']) !== '')     { $bits[] = $r['location']; }
                        if (trim((string)$r['product_name']) !== '') { $bits[] = 'on ' . $r['product_name']; }
                    ?>
                    <?php if ($bits): ?>
                    <span class="cbrev-sub"><?= htmlspecialchars(implode(' · ', $bits)) ?></span>
                    <?php endif; ?>
                </footer>
            </article>
            <?php endforeach; ?>
            </div>

            <button type="button" class="cbrev-nav cbrev-nav-next" id="cbrevNext"
                    aria-label="More reviews" hidden>
                <i class="fa-solid fa-chevron-right"></i>
            </button>
        </div>

        <div class="cbrev-dots" id="cbrevDots" hidden></div>
        <?php else: ?>
        <?php // No reviews yet. The section still appears, because otherwise the
              // "write a review" button would be hidden too and nobody could
              // ever leave the first one. Nothing is invented to fill it. ?>
        <p class="cbrev-none">
            No reviews yet — we would rather show you an empty space than reviews
            we wrote ourselves. If you have ordered from us, yours would be the first.
        </p>
        <?php endif; ?>

        <!-- ── Leave a review ─────────────────────────────── -->
        <?php if ($reviewStatus === 'THANKS'): ?>
        <div class="cbrev-thanks">
            <i class="fa-solid fa-circle-check"></i>
            <span>
                <strong>Thank you — that means a lot.</strong>
                We read every one. It will appear here once we have checked it over.
            </span>
        </div>
        <?php else: ?>

        <?php if ($reviewStatus !== ''): ?>
        <div class="cbrev-error">
            <i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($reviewStatus) ?>
        </div>
        <?php endif; ?>

        <?php // <details> is a working expander with no JavaScript at all, so
              // the form still opens if a script fails to load. It starts open
              // when there is a message to show, so an error is never hidden
              // behind a closed panel. ?>
        <details class="cbrev-write" <?= $reviewStatus !== '' ? 'open' : '' ?>>
            <summary class="cbrev-write-toggle">
                <i class="fa-solid fa-pen"></i> Write a review
            </summary>

            <form method="post" action="<?= SITE_BASE ?>/review_submit.php" class="cbrev-form">
                <div class="cbrev-form-row">
                    <div class="form-group">
                        <label class="form-label" for="revName">Your name *</label>
                        <input type="text" id="revName" name="customer_name" class="form-control"
                               required maxlength="120"
                               value="<?= htmlspecialchars($reviewKeep['customer_name'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="revLoc">Your town <span class="cbrev-opt">(optional)</span></label>
                        <input type="text" id="revLoc" name="location" class="form-control"
                               maxlength="120" placeholder="e.g. Harrow"
                               value="<?= htmlspecialchars($reviewKeep['location'] ?? '') ?>">
                    </div>
                </div>

                <div class="cbrev-form-row">
                    <div class="form-group">
                        <label class="form-label" for="revFlav">Which flavour <span class="cbrev-opt">(optional)</span></label>
                        <input type="text" id="revFlav" name="product_name" class="form-control"
                               maxlength="150"
                               value="<?= htmlspecialchars($reviewKeep['product_name'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="revRating">Rating</label>
                        <select id="revRating" name="rating" class="form-control">
                            <?php foreach ([5 => 'Excellent', 4 => 'Good', 3 => 'Okay', 2 => 'Poor', 1 => 'Bad'] as $v => $lbl): ?>
                            <option value="<?= $v ?>" <?= (int)($reviewKeep['rating'] ?? 5) === $v ? 'selected' : '' ?>>
                                <?= str_repeat('★', $v) . str_repeat('☆', 5 - $v) ?> — <?= $lbl ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="revBody">Your review *</label>
                    <textarea id="revBody" name="body" class="form-control" rows="4" required
                              maxlength="2000"
                              placeholder="What did you order, and how was it?"><?= htmlspecialchars($reviewKeep['body'] ?? '') ?></textarea>
                </div>

                <?php // Honeypot — hidden from people, filled in by bots. The hidden
                      // attribute plus tabindex keeps it away from keyboards and
                      // screen readers too, not just off-screen. ?>
                <div hidden aria-hidden="true">
                    <label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                </div>

                <button type="submit" class="btn-primary cbrev-submit">
                    <i class="fa-solid fa-paper-plane"></i> Send review
                </button>
                <p class="cbrev-note">
                    Reviews are checked before they appear. We publish your name and
                    town — never your email address or order details.
                </p>
            </form>
        </details>
        <?php endif; ?>
    </div>
</section>

<?php if (!empty($testimonials)): ?>
<script>
// Review slider.
//
// The track is already a scroll-snapping row in CSS, so everything here is an
// enhancement on top of something that works without it. If this script never
// runs, the arrows and dots stay hidden (they ship with the `hidden`
// attribute) and the strip still scrolls by swipe, trackpad and keyboard —
// which is the behaviour most people on a phone will use anyway.
(function () {
    const track = document.getElementById('cbrevTrack');
    const prev  = document.getElementById('cbrevPrev');
    const next  = document.getElementById('cbrevNext');
    const dots  = document.getElementById('cbrevDots');
    if (!track || !prev || !next || !dots) return;

    const cards = [...track.querySelectorAll('.cbrev-card')];
    if (!cards.length) return;

    // How far one press moves: the width of a card plus the gap between two.
    // Measured rather than hardcoded, because the card width comes from the
    // CSS grid and changes at every breakpoint.
    function step() {
        if (cards.length < 2) return track.clientWidth;
        return cards[1].offsetLeft - cards[0].offsetLeft;
    }

    // Which cards fit at once — decides how many dots there are.
    function perView() {
        return Math.max(1, Math.round(track.clientWidth / step()));
    }

    function pageCount() {
        return Math.max(1, Math.ceil(cards.length / perView()));
    }

    function currentPage() {
        const pw = perView() * step();
        return pw > 0 ? Math.round(track.scrollLeft / pw) : 0;
    }

    function buildDots() {
        const n = pageCount();
        // One page means nothing to page through: no dots, no arrows.
        const scrollable = track.scrollWidth - track.clientWidth > 4;
        dots.hidden = !scrollable || n < 2;
        prev.hidden = next.hidden = !scrollable;
        if (dots.hidden) { dots.innerHTML = ''; return; }

        dots.innerHTML = '';
        for (let i = 0; i < n; i++) {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'cbrev-dot';
            b.setAttribute('aria-label', 'Go to review page ' + (i + 1));
            b.addEventListener('click', () => {
                track.scrollTo({ left: i * perView() * step(), behavior: 'smooth' });
            });
            dots.appendChild(b);
        }
        syncState();
    }

    function syncState() {
        const page = currentPage();
        [...dots.children].forEach((d, i) => d.classList.toggle('is-active', i === page));
        // Disable rather than hide at the ends, so the row does not reflow
        // sideways every time you reach the first or last card.
        prev.disabled = track.scrollLeft <= 2;
        next.disabled = track.scrollLeft >= track.scrollWidth - track.clientWidth - 2;
    }

    prev.addEventListener('click', () => track.scrollBy({ left: -perView() * step(), behavior: 'smooth' }));
    next.addEventListener('click', () => track.scrollBy({ left:  perView() * step(), behavior: 'smooth' }));

    // scroll fires continuously while smooth-scrolling; settle before syncing.
    let t;
    track.addEventListener('scroll', () => { clearTimeout(t); t = setTimeout(syncState, 90); }, { passive: true });
    window.addEventListener('resize', () => { clearTimeout(t); t = setTimeout(buildDots, 150); });

    buildDots();
})();
</script>
<?php endif; ?>
<?php endif; ?>

<!-- ══ CTA Banner ═══════════════════════════════════════════ -->
<section class="landing-cta-section">
    <div class="container cbhm-cta-inner">
        <span class="section-label cbhm-label-on-dark">Treat Yourself Today</span>
        <h2>Ready for a Scoop of Happiness?</h2>
        <p>Browse our menu, pick your favourites, and get them delivered fresh to your doorstep.</p>
        <a href="pages/order.php" class="btn-cta-white">
            <i class="fa-solid fa-bag-shopping"></i> Order Online Now
        </a>
    </div>
</section>

<!-- ══ B2B Trade Callout ═════════════════════════════════════ -->
<section class="cbhm-trade-band">
    <div class="container cbhm-trade-band-inner">
        <div>
            <span class="cbhm-trade-pill"><i class="fa-solid fa-store"></i> B2B Wholesale</span>
            <h3 class="cbhm-trade-heading">Are you a Retailer, Shop, or Cafe Owner?</h3>
            <p class="cbhm-trade-copy">
                Sell Creamy Bite in your store! Get discounted wholesale pricing, bulk ice cream tubs, and dedicated trade support.
            </p>
        </div>
        <div class="cbhm-trade-actions">
            <a href="pages/trade_register.php" class="btn-primary cbhm-btn-trade">
                <i class="fa-solid fa-store"></i> Apply for Trade Account
            </a>
            <a href="pages/trade_login.php" class="btn-secondary cbhm-btn-trade-alt">
                <i class="fa-solid fa-right-to-bracket"></i> Trade Login
            </a>
        </div>
    </div>
</section>

<!-- ══ Footer ══════════════════════════════════════════════ -->
<?php // One shared footer — it used to be copied into five pages, so adding a
      // link meant editing all five and hoping none were missed. ?>
<?php require __DIR__ . '/includes/site_footer.php'; ?>

<script src="<?= cbAsset('assets/js/modal.js') ?>" defer></script>
<script src="<?= cbAsset('assets/js/animations.js') ?>" defer></script>
</body>
</html>
