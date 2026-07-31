/* ============================================================
   Creamy Bite – Motion behaviours
   Loaded at the end of <body> on every public page.

   Safety rule followed throughout: the page must be fully usable if this
   file never runs. Reveal-on-scroll is only switched on after we confirm
   IntersectionObserver exists, and the <html> class that hides content is
   added by THIS script — so a JS failure leaves everything visible.
   ============================================================ */
(function () {
    'use strict';

    var reduced = window.matchMedia &&
                  window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ── Sticky header state ─────────────────────────────── */
    var header = document.querySelector('.navbar');
    var progress = null;

    if (!reduced) {
        progress = document.createElement('div');
        progress.className = 'cb-progress';
        document.body.appendChild(progress);
    }

    var ticking = false;
    function onScroll() {
        if (ticking) return;
        ticking = true;
        requestAnimationFrame(function () {
            var y = window.scrollY || document.documentElement.scrollTop;

            if (header) header.classList.toggle('cb-scrolled', y > 30);

            if (progress) {
                var h = document.documentElement.scrollHeight - window.innerHeight;
                progress.style.width = (h > 0 ? (y / h) * 100 : 0) + '%';
            }

            if (topBtn) topBtn.classList.toggle('is-shown', y > 480);

            ticking = false;
        });
    }

    /* ── Back to top ─────────────────────────────────────── */
    var topBtn = document.createElement('button');
    topBtn.className = 'cb-top';
    topBtn.type = 'button';
    topBtn.setAttribute('aria-label', 'Back to top');
    topBtn.innerHTML = '<i class="fa-solid fa-arrow-up"></i>';
    topBtn.addEventListener('click', function () {
        window.scrollTo({ top: 0, behavior: reduced ? 'auto' : 'smooth' });
    });
    document.body.appendChild(topBtn);

    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();

    /* ── Reveal on scroll ────────────────────────────────── */
    // Auto-tag the obvious content blocks so pages get the effect without
    // every template having to be edited. Anything already tagged is kept.
    var AUTO = [
        '.product-card', '.flavour-card', '.gallery-item', '.feature-card',
        '.about-card', '.info-card', '.step-card', '.testimonial-card',
        '.category-card', '.section-header', '.hero-stat'
    ];

    if ('IntersectionObserver' in window && !reduced) {
        // Only now is it safe to hide things — the observer will reveal them.
        document.documentElement.classList.add('cb-motion');

        AUTO.forEach(function (sel) {
            document.querySelectorAll(sel).forEach(function (el) {
                if (!el.classList.contains('reveal') &&
                    !el.classList.contains('reveal-left') &&
                    !el.classList.contains('reveal-right') &&
                    !el.classList.contains('reveal-zoom')) {
                    el.classList.add('reveal');
                }
            });
        });

        var targets = document.querySelectorAll('.reveal, .reveal-left, .reveal-right, .reveal-zoom, .stagger');

        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                entry.target.classList.add('is-visible');
                io.unobserve(entry.target);   // reveal once, then stop watching
            });
        }, { rootMargin: '0px 0px -8% 0px', threshold: 0.06 });

        targets.forEach(function (el) {
            // Anything already on screen at load reveals immediately, so the
            // first viewport never sits blank waiting for a scroll.
            var top = el.getBoundingClientRect().top;
            if (top < window.innerHeight * 0.95) {
                el.classList.add('is-visible');
            } else {
                io.observe(el);
            }
        });

        // ── Fallbacks. Hidden content is a far worse bug than a missed
        //    animation, so there are two independent nets under the observer.

        // 1. A cheap bounds check on every scroll. If the observer is not
        //    firing — an ancestor with overflow, a custom scroll container,
        //    a browser quirk — this still reveals content as it comes into
        //    view. rAF-throttled, and it stops once everything is revealed.
        var pending = Array.prototype.slice.call(targets);
        var checking = false;
        function sweep() {
            if (checking) return;
            checking = true;
            requestAnimationFrame(function () {
                var limit = window.innerHeight * 1.1;
                pending = pending.filter(function (el) {
                    if (el.classList.contains('is-visible')) return false;
                    if (el.getBoundingClientRect().top < limit) {
                        el.classList.add('is-visible');
                        return false;
                    }
                    return true;
                });
                if (!pending.length) {
                    window.removeEventListener('scroll', sweep);
                    window.removeEventListener('resize', sweep);
                }
                checking = false;
            });
        }
        window.addEventListener('scroll', sweep, { passive: true });
        window.addEventListener('resize', sweep, { passive: true });

        // 2. Hard backstop. After 6 seconds reveal whatever is still hidden,
        //    wherever it sits on the page. Past that point the animation is
        //    not worth the risk of content never appearing at all.
        setTimeout(function () {
            document.querySelectorAll('.reveal, .reveal-left, .reveal-right, .reveal-zoom')
                .forEach(function (el) { el.classList.add('is-visible'); });
        }, 6000);
    }

    /* ── Image fade-in ───────────────────────────────────── */
    // The placeholder class sets opacity:0, so it must ALWAYS be removed
    // again. load and error cover the normal cases; the timeout covers a
    // lazy image whose load event never arrives, which would otherwise leave
    // a permanently blank picture.
    document.querySelectorAll('img').forEach(function (img) {
        if (img.complete && img.naturalWidth > 0) return;

        img.classList.add('cb-img-loading');
        var clear = function () { img.classList.remove('cb-img-loading'); };

        img.addEventListener('load', clear, { once: true });
        img.addEventListener('error', clear, { once: true });
        setTimeout(clear, 6000);
    });

    /* ── Toasts ──────────────────────────────────────────── */
    var toastWrap = null;
    window.cbToast = function (message, type) {
        if (!toastWrap) {
            toastWrap = document.createElement('div');
            toastWrap.className = 'cb-toast-wrap';
            document.body.appendChild(toastWrap);
        }
        var t = document.createElement('div');
        t.className = 'cb-toast' + (type ? ' ' + type : '');
        t.textContent = message;
        toastWrap.appendChild(t);
        setTimeout(function () {
            t.classList.add('is-leaving');
            setTimeout(function () { t.remove(); }, 300);
        }, 2600);
    };

    /* ── Cart badge bump ─────────────────────────────────── */
    // Watches the badge's own text rather than hooking every add-to-cart
    // call site, so it works no matter which page changed it.
    var badge = document.getElementById('cartBadge');
    if (badge && 'MutationObserver' in window && !reduced) {
        var last = badge.textContent;
        new MutationObserver(function () {
            if (badge.textContent === last) return;
            last = badge.textContent;
            badge.classList.remove('cb-bump');
            void badge.offsetWidth;          // restart the animation
            badge.classList.add('cb-bump');
        }).observe(badge, { childList: true, characterData: true, subtree: true });
    }

    /* ── Fly-to-cart ─────────────────────────────────────── */
    // Call from an add-to-cart handler: cbFlyToCart(buttonEl, '🍦')
    window.cbFlyToCart = function (fromEl, emoji) {
        if (reduced || !fromEl || !badge) return;
        var a = fromEl.getBoundingClientRect();
        var b = badge.getBoundingClientRect();

        var ghost = document.createElement('div');
        ghost.className = 'cb-fly';
        ghost.textContent = emoji || '🍦';
        ghost.style.left = a.left + a.width / 2 - 13 + 'px';
        ghost.style.top  = a.top  + a.height / 2 - 13 + 'px';
        document.body.appendChild(ghost);

        requestAnimationFrame(function () {
            ghost.style.transform = 'translate(' +
                (b.left - a.left - a.width / 2 + b.width / 2) + 'px,' +
                (b.top  - a.top  - a.height / 2 + b.height / 2) + 'px) scale(.4)';
            ghost.style.opacity = '0';
        });
        setTimeout(function () { ghost.remove(); }, 760);
    };

    /* ── Button busy state ───────────────────────────────── */
    // Any form marked data-busy shows a spinner on submit, which also stops
    // impatient double-clicks creating two orders.
    document.querySelectorAll('form[data-busy]').forEach(function (form) {
        form.addEventListener('submit', function () {
            var btn = form.querySelector('[type=submit]');
            if (btn && !btn.classList.contains('btn-loading')) {
                btn.classList.add('btn-loading');
                btn.insertAdjacentHTML('afterbegin', '<i class="fa-solid fa-circle-notch"></i> ');
            }
        });
    });
})();
