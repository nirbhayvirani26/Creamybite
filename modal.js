/* ============================================================
   Creamy Bite – in-page dialogs
   Replaces native alert() / confirm() / prompt().

   Two ways to use it:

   1. Declaratively — no JS needed at the call site. Put the attribute on a
      link, button or form and it is intercepted automatically:

        <a href="delete.php?id=7" data-confirm="Delete this product?">Delete</a>
        <form method="POST" data-confirm="Void this invoice?"> … </form>
        <button data-confirm="Reset prices?" data-confirm-tone="danger">Reset</button>

      Optional attributes:
        data-confirm-title  heading (defaults to "Are you sure?")
        data-confirm-ok     confirm button label (defaults to "Confirm")
        data-confirm-tone   info | warn | danger | success (defaults to warn)

   2. Programmatically, returning Promises:

        if (await cbConfirm('Delete this?', {tone:'danger'})) { … }
        await cbAlert('Saved.', {tone:'success'});
        const reason = await cbPrompt('Why?', 'Out of stock');

   Why not native dialogs: they cannot be styled, they look like an OS error
   box rather than part of the shop, and on some mobile browsers they can be
   suppressed entirely — which would silently skip a confirmation step.
   ============================================================ */
(function () {
    'use strict';

    var openCount = 0;

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    var ICONS = {
        info:    '<i class="fa-solid fa-circle-info"></i>',
        warn:    '<i class="fa-solid fa-triangle-exclamation"></i>',
        danger:  '<i class="fa-solid fa-trash-can"></i>',
        success: '<i class="fa-solid fa-circle-check"></i>'
    };

    /**
     * Core dialog. Returns a Promise resolving to:
     *   confirm -> true / false
     *   alert   -> true
     *   prompt  -> entered string, or null if cancelled
     */
    function openDialog(opts) {
        return new Promise(function (resolve) {
            var tone   = opts.tone || 'warn';
            var isPrompt = opts.kind === 'prompt';
            var showCancel = opts.kind !== 'alert';

            var backdrop = document.createElement('div');
            backdrop.className = 'cbm-backdrop';
            backdrop.setAttribute('role', 'dialog');
            backdrop.setAttribute('aria-modal', 'true');

            backdrop.innerHTML =
                '<div class="cbm">' +
                    '<div class="cbm-head">' +
                        '<div class="cbm-icon ' + tone + '">' + (ICONS[tone] || ICONS.warn) + '</div>' +
                        '<div>' +
                            '<h2 class="cbm-title">' + esc(opts.title) + '</h2>' +
                            (opts.message ? '<div class="cbm-body">' + esc(opts.message) + '</div>' : '') +
                        '</div>' +
                    '</div>' +
                    (isPrompt
                        ? '<div class="cbm-field"><input class="cbm-input" type="text" value="' + esc(opts.value || '') + '"></div>'
                        : '') +
                    '<div class="cbm-actions">' +
                        (showCancel ? '<button type="button" class="cbm-btn ghost" data-act="cancel">' + esc(opts.cancelText || 'Cancel') + '</button>' : '') +
                        '<button type="button" class="cbm-btn ' + (tone === 'danger' ? 'danger' : 'primary') + '" data-act="ok">' +
                            esc(opts.okText || (opts.kind === 'alert' ? 'OK' : 'Confirm')) +
                        '</button>' +
                    '</div>' +
                '</div>';

            document.body.appendChild(backdrop);
            openCount++;
            document.body.style.overflow = 'hidden';

            var input = backdrop.querySelector('.cbm-input');
            var okBtn = backdrop.querySelector('[data-act="ok"]');

            // Remember what had focus so it can be restored — otherwise a
            // keyboard user is dumped back at the top of the document.
            var previouslyFocused = document.activeElement;

            requestAnimationFrame(function () {
                backdrop.classList.add('is-open');
                (input || okBtn).focus();
                if (input) input.select();
            });

            var settled = false;
            function close(result) {
                if (settled) return;
                settled = true;

                backdrop.classList.remove('is-open');
                document.removeEventListener('keydown', onKey, true);

                setTimeout(function () {
                    backdrop.remove();
                    openCount = Math.max(0, openCount - 1);
                    if (openCount === 0) document.body.style.overflow = '';
                    if (previouslyFocused && previouslyFocused.focus) previouslyFocused.focus();
                    resolve(result);
                }, 180);
            }

            function onKey(e) {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    close(isPrompt ? null : false);
                } else if (e.key === 'Enter' && (!input || document.activeElement === input)) {
                    e.preventDefault();
                    close(isPrompt ? input.value : true);
                } else if (e.key === 'Tab') {
                    // Keep focus inside the dialog.
                    var f = backdrop.querySelectorAll('button, input');
                    if (!f.length) return;
                    var first = f[0], last = f[f.length - 1];
                    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
                }
            }
            document.addEventListener('keydown', onKey, true);

            backdrop.addEventListener('click', function (e) {
                if (e.target === backdrop) close(isPrompt ? null : false);   // click outside
                var act = e.target.closest && e.target.closest('[data-act]');
                if (!act) return;
                if (act.dataset.act === 'ok') close(isPrompt ? input.value : true);
                else close(isPrompt ? null : false);
            });
        });
    }

    window.cbConfirm = function (message, o) {
        o = o || {};
        return openDialog({
            kind: 'confirm', message: message,
            title: o.title || 'Are you sure?',
            tone: o.tone || 'warn', okText: o.okText, cancelText: o.cancelText
        });
    };

    window.cbAlert = function (message, o) {
        o = o || {};
        return openDialog({
            kind: 'alert', message: message,
            title: o.title || 'Heads up',
            tone: o.tone || 'info', okText: o.okText || 'OK'
        });
    };

    window.cbPrompt = function (message, defaultValue, o) {
        o = o || {};
        return openDialog({
            kind: 'prompt', message: message, value: defaultValue,
            title: o.title || 'Please confirm',
            tone: o.tone || 'info', okText: o.okText || 'Continue'
        });
    };

    /* ── Declarative data-confirm ─────────────────────────────
       Intercepts links, buttons and form submits so existing markup needs
       only an attribute swapped, not a rewrite. */
    function ask(el) {
        return cbConfirm(el.dataset.confirm, {
            title:  el.dataset.confirmTitle || 'Are you sure?',
            tone:   el.dataset.confirmTone  || 'warn',
            okText: el.dataset.confirmOk    || 'Confirm'
        });
    }

    document.addEventListener('click', function (e) {
        var el = e.target.closest('[data-confirm]');
        if (!el || el.tagName === 'FORM' || el.dataset.cbApproved === '1') return;

        e.preventDefault();
        e.stopPropagation();

        ask(el).then(function (ok) {
            if (!ok) return;
            // Mark as approved and replay, so the element's own behaviour
            // (navigation, submit, its own handler) proceeds normally.
            el.dataset.cbApproved = '1';
            if (el.tagName === 'A' && el.href) {
                if (el.target === '_blank') window.open(el.href, '_blank');
                else window.location.href = el.href;
            } else {
                el.click();
            }
            delete el.dataset.cbApproved;
        });
    }, true);

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.matches('[data-confirm]') || form.dataset.cbApproved === '1') return;

        e.preventDefault();
        ask(form).then(function (ok) {
            if (!ok) return;
            form.dataset.cbApproved = '1';
            form.submit();
        });
    }, true);
})();
