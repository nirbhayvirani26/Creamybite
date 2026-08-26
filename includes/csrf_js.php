<?php
// ============================================================
//  Creamy Bite – CSRF for JavaScript
//
//  Include inside <head> on any page that makes fetch() calls — customer
//  side or admin.
//
//  Rather than editing every call site (and remembering forever after), this
//  wraps window.fetch once and attaches the token to every same-origin
//  request. A future fetch() added anywhere on the page is covered without
//  the author having to know CSRF exists. That property is the whole point:
//  the protection cannot be forgotten by the next person to add a button.
//
//  The token goes on the X-CSRF-Token header, which csrfValid() accepts, so
//  even a bare fetch('handler.php?action=remove') with no body is protected.
//  Form bodies also get a csrf_token field for handlers that read $_POST.
//
//  This started life as admin/_csrf_js.php, covering the admin panel only,
//  while the customer side — the basket, promo codes, the card payment — had
//  no protection at all. It lives here now so both halves of the site share
//  one copy and cannot drift apart. admin/_csrf_js.php is kept as a one-line
//  forwarder so the eight admin pages that include it by that name carry on
//  working.
// ============================================================
require_once __DIR__ . '/csrf.php';

// Emit at most once per request.
//
// The admin includes this with `include`, not `include_once`, and a page that
// pulled it in twice would run `const CSRF_TOKEN = ...` twice — which is a
// SyntaxError in JavaScript, and one that kills the entire script block it
// lands in, taking every button on the page with it. Guarding here means no
// caller has to think about it.
if (defined('CB_CSRF_JS_EMITTED')) {
    return;
}
define('CB_CSRF_JS_EMITTED', true);
?>
<script>
const CSRF_TOKEN = <?= json_encode(csrfToken()) ?>;
(function () {
    var nativeFetch = window.fetch;
    if (!nativeFetch) return;

    window.fetch = function (input, init) {
        init = init || {};

        var url = (typeof input === 'string') ? input : (input && input.url) || '';
        var isAbsolute = /^https?:\/\//i.test(url);
        var sameOrigin = !isAbsolute || url.indexOf(location.origin) === 0;

        if (sameOrigin) {
            // Header first: works for GET requests and bodyless calls too.
            var headers = new Headers(init.headers || (typeof input === 'object' && input.headers) || {});
            headers.set('X-CSRF-Token', CSRF_TOKEN);
            init.headers = headers;

            // Also fold it into the body, for handlers that read $_POST.
            if (init.body instanceof FormData) {
                if (!init.body.has('csrf_token')) init.body.append('csrf_token', CSRF_TOKEN);
            } else if (init.body instanceof URLSearchParams) {
                if (!init.body.has('csrf_token')) init.body.append('csrf_token', CSRF_TOKEN);
            } else if (typeof init.body === 'string' && !/(^|&)csrf_token=/.test(init.body)) {
                init.body += (init.body.length ? '&' : '') + 'csrf_token=' + encodeURIComponent(CSRF_TOKEN);
            }
        }

        return nativeFetch.call(this, input, init);
    };
})();
</script>
