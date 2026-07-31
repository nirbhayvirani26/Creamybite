<?php
// ============================================================
//  Creamy Bite – CSRF for admin JavaScript
//
//  Include inside <head> on every admin page that makes fetch() calls.
//
//  Rather than editing all ~14 call sites (and remembering forever after),
//  this wraps window.fetch once and attaches the token to every same-origin
//  request. A future fetch() added anywhere in the admin is covered without
//  the author having to know CSRF exists.
//
//  The token goes on the X-CSRF-Token header, which csrfValid() accepts, so
//  even a bare fetch('handler.php?action=delete&id=1') with no body is
//  protected. Form bodies also get a csrf_token field for handlers that
//  prefer reading $_POST.
// ============================================================
require_once __DIR__ . '/../includes/csrf.php';
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
