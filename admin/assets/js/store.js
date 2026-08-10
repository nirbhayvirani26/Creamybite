/* ============================================================
 *  Creamy Bite – Delivery & Offers (admin/store.php)
 *
 *  A separate file rather than a <script> block on the page, deliberately.
 *  admin/index.php carries ~60k of inline JavaScript where one bad line takes
 *  out every function below it; keeping this apart means it can be parse
 *  checked on its own and a mistake here cannot reach any other admin page.
 *
 *  WHAT IT DOES NOT DO
 *  -------------------
 *  It does not decide whether anything is valid. Every rule — no minus money,
 *  the radius against the free distance, a buy quantity of at least one — is
 *  enforced in admin/handlers/store_handler.php. This file shows the sentence
 *  the server sends back, underneath the box the server names. Checking here
 *  as well would mean two sets of rules to keep in step, and the browser's set
 *  can be switched off by anyone who cares to.
 *
 *  The CSRF token is attached by admin/_csrf_js.php, which wraps window.fetch.
 *  Nothing here needs to know the token exists.
 * ============================================================ */

(function () {
    'use strict';

    var HANDLER = 'handlers/store_handler.php';

    /* ── Little helpers ──────────────────────────────────── */

    function $(id) { return document.getElementById(id); }

    function money(n) { return '£' + (Math.round(n * 100) / 100).toFixed(2); }

    /** Read a number out of a box the way the server does — "£1.99" is 1.99. */
    function num(raw) {
        var t = String(raw == null ? '' : raw).replace(/[£,\s]/g, '').trim();
        if (t === '' || isNaN(Number(t))) { return null; }
        return Number(t);
    }

    /** POST to the handler. Always resolves; a dead network becomes a message. */
    function send(fields) {
        var body = new URLSearchParams();
        Object.keys(fields).forEach(function (k) {
            if (fields[k] !== null && fields[k] !== undefined) { body.append(k, fields[k]); }
        });
        return fetch(HANDLER, { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .catch(function () {
                return { success: false, message: 'Could not reach the server. Check your connection and try again.' };
            });
    }

    /* ── Showing what came back ──────────────────────────── */

    var flash = $('cbstFlash');
    var flashText = $('cbstFlashText');
    var flashTimer = null;

    function say(message, tone) {
        if (!flash) { return; }
        flashText.textContent = message;
        flash.dataset.tone = tone || 'ok';
        flash.hidden = false;
        if (flashTimer) { clearTimeout(flashTimer); }
        // Errors stay put — a message that vanishes before it is read is worse
        // than none. Only the cheerful ones time out.
        if ((tone || 'ok') === 'ok') {
            flashTimer = setTimeout(function () { flash.hidden = true; }, 4000);
        }
        flash.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    /** Wipe every error sentence inside a container. */
    function clearErrors(root) {
        (root || document).querySelectorAll('.cbst-err').forEach(function (p) {
            p.textContent = '';
            p.hidden = true;
        });
        (root || document).querySelectorAll('.cbst-input.is-wrong').forEach(function (i) {
            i.classList.remove('is-wrong');
        });
    }

    /**
     * Put each sentence under the box it belongs to.
     *
     * Anything the page has no box for is returned, so the caller can put it in
     * the headline instead of dropping it silently — a refusal nobody can see
     * looks exactly like a save that did nothing.
     */
    function showErrors(root, errors) {
        var homeless = [];
        Object.keys(errors || {}).forEach(function (field) {
            var slots = (root || document).querySelectorAll('.cbst-err[data-err="' + field + '"]');
            var shown = false;
            slots.forEach(function (slot) {
                slot.textContent = errors[field];
                slot.hidden = false;
                // Mark the box itself, but only the one actually on screen.
                var group = slot.closest('.cbst-field, .cbst-row-item');
                if (group && group.offsetParent !== null) {
                    var box = group.querySelector('.cbst-input');
                    if (box) { box.classList.add('is-wrong'); }
                    shown = true;
                }
            });
            if (!shown && slots.length === 0) { homeless.push(errors[field]); }
        });
        return homeless;
    }

    /** Scroll to the first thing that is wrong, so it cannot be missed. */
    function goToFirstError(root) {
        var first = (root || document).querySelector('.cbst-err:not([hidden])');
        if (first) { first.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
    }


    /* ════════════════════════════════════════════════════════
     *  0.  Are you taking orders?
     *
     *  Two switches, delivery and collection, flipped by hand. One press does
     *  it — there is no confirm box in the way, because the owner reaching for
     *  this has a queue in front of them, and the answer to a mis-press is the
     *  same button again.
     *
     *  NONE OF THE WORDING LIVES HERE. Every sentence a switch can show was
     *  written once in admin/store.php and put on the card as a data-
     *  attribute; this file only chooses which of them to display. A second
     *  copy in JavaScript is a second copy to keep in step, and the one that
     *  drifts is always the one nobody is looking at.
     *
     *  THE PAGE IS FETCHED AGAIN AFTERWARDS, and that is not laziness. The
     *  line a customer actually reads comes from cbOrderingClosedNote(), which
     *  works off a settings row read once per request and held for that
     *  request — so this request cannot see what it just wrote, and neither
     *  can anything else until the next one. The card is repainted from the
     *  server's answer straight away so the owner is never left wondering, and
     *  the reload a moment later brings the customer-facing preview and the
     *  "last change" line back into line with the shop. Anything the owner had
     *  typed and not saved is carried across it.
     * ════════════════════════════════════════════════════════ */

    var channels = $('cbstChannels');
    var shutAll  = $('cbstShutAll');

    function cardFor(channel) {
        // Only ever our own two words, but this is built into a selector, so
        // it is checked rather than trusted.
        if (!channels || !/^[a-z]+$/.test(String(channel))) { return null; }
        return channels.querySelector('.cbst-channel[data-channel="' + channel + '"]');
    }

    /**
     * What the owner has typed into a closed-message box but not yet saved.
     *
     * Compared against the value the server rendered, which is recorded on
     * every box below before anything can change it.
     */
    function unsavedNotes() {
        var out = {};
        if (!channels) { return out; }
        channels.querySelectorAll('.cbst-channel').forEach(function (card) {
            var box = card.querySelector('[data-note]');
            if (box && box.value !== box.dataset.saved) {
                out[card.dataset.channel] = box.value;
            }
        });
        return out;
    }

    if (channels) {
        channels.querySelectorAll('[data-note]').forEach(function (box) {
            box.dataset.saved = box.value;
        });
    }

    /* ── Carrying a confirmation over the reload ─────────── */

    var CARRY = 'cbstCarry';

    function carry(payload) {
        payload.at = Date.now();
        try {
            sessionStorage.setItem(CARRY, JSON.stringify(payload));
        } catch (err) {
            // Storage refused (private browsing, or it is full). The switch is
            // still saved and the reload still shows the right state — only the
            // sentence about it is lost, so there is nothing to report.
        }
    }

    (function restoreCarried() {
        var raw;
        try {
            raw = sessionStorage.getItem(CARRY);
            if (raw !== null) { sessionStorage.removeItem(CARRY); }
        } catch (err) {
            return;
        }
        if (!raw) { return; }

        var payload;
        try { payload = JSON.parse(raw); } catch (err) { return; }
        if (!payload || typeof payload !== 'object') { return; }

        // Only a confirmation from the reload we just asked for. Without this,
        // a save followed by wandering off somewhere else would put a stale
        // "Delivery orders are closed" on screen the next time this page was
        // opened, describing something that happened hours ago.
        if (!(Date.now() - Number(payload.at) < 30000)) { return; }

        Object.keys(payload.notes || {}).forEach(function (channel) {
            var card = cardFor(channel);
            var box  = card ? card.querySelector('[data-note]') : null;
            if (box) { box.value = String(payload.notes[channel]); }
        });

        if (payload.message) { say(payload.message, payload.tone || 'ok'); }
    })();

    /* ── Showing a channel's state ───────────────────────── */

    function paintChannel(card, isOpen) {
        if (!card) { return; }
        var d = card.dataset;

        card.classList.toggle('is-open', isOpen);
        card.classList.toggle('is-shut', !isOpen);

        var dot = card.querySelector('[data-state-dot]');
        if (dot) { dot.className = 'fa-solid ' + (isOpen ? 'fa-circle-check' : 'fa-circle-xmark'); }

        var line = card.querySelector('[data-state-line]');
        if (line) { line.textContent = isOpen ? d.openState : d.shutState; }

        var blurb = card.querySelector('[data-state-blurb]');
        if (blurb) { blurb.textContent = isOpen ? d.openBlurb : d.shutBlurb; }

        // The button always offers the opposite of where the channel is now:
        // open, and it is the one that stops it.
        var icon = card.querySelector('[data-state-icon]');
        if (icon) { icon.className = 'fa-solid ' + (isOpen ? 'fa-toggle-on' : 'fa-toggle-off'); }

        var label = card.querySelector('[data-state-btn]');
        if (label) { label.textContent = isOpen ? d.shutBtn : d.openBtn; }

        // "What a customer is reading right now" is only true while the channel
        // is shut, and its sentence is written by the server. Hiding it here
        // rather than rewriting it means this file never has to guess what that
        // sentence would be; the reload brings back the real one.
        var seen = card.querySelector('.cbst-channel-seen');
        if (seen) { seen.hidden = isOpen; }
    }

    /* ── Pressing a switch, or saving its message ────────── */

    if (channels) {
        channels.addEventListener('click', function (e) {
            var button = e.target.closest('[data-act]');
            if (!button) { return; }

            var act = button.dataset.act;
            if (act !== 'channel-toggle' && act !== 'channel-note') { return; }

            var card = button.closest('.cbst-channel');
            if (!card) { return; }

            var box = card.querySelector('[data-note]');
            clearErrors(card);

            // The switch asks for the opposite of what is on screen. The
            // message button asks for "keep", which tells the handler to read
            // the state out of the database and leave it alone — so saving
            // wording can never move a switch, however out of date this page is.
            var open = (act === 'channel-toggle')
                ? (card.classList.contains('is-open') ? '0' : '1')
                : 'keep';

            var buttons = card.querySelectorAll('button');
            buttons.forEach(function (b) { b.disabled = true; });

            send({
                action:  'set_channel_open',
                channel: card.dataset.channel,
                open:    open,
                note:    box ? box.value : ''
            }).then(function (res) {

                if (res && res.success) {
                    // Both cards, from the row the handler read back after
                    // writing — not from what this page assumed it asked for.
                    paintChannel(cardFor('delivery'),   Number(res.delivery_open) === 1);
                    paintChannel(cardFor('collection'), Number(res.collection_open) === 1);
                    if (shutAll) { shutAll.hidden = !!res.any_open; }

                    // The note as it is now STORED, trimmed ends and all, so the
                    // box shows what a customer would get rather than what was
                    // typed at it.
                    if (box) {
                        box.value = (res.note === null || res.note === undefined) ? '' : String(res.note);
                        box.dataset.saved = box.value;
                    }

                    carry({ message: res.message || 'Saved.', tone: 'ok', notes: unsavedNotes() });
                    // Left disabled on purpose until the page comes back — a
                    // second press while the first is still landing would ask
                    // for the opposite of a state that is already changing.
                    setTimeout(function () { window.location.reload(); }, 900);
                    return;
                }

                buttons.forEach(function (b) { b.disabled = false; });

                var homeless = showErrors(card, (res && res.errors) || {});
                var headline = (res && res.message) || 'That could not be changed.';
                if (homeless.length) { headline = homeless.join(' '); }
                say(headline, 'bad');
                goToFirstError(card);
            });
        });
    }


    /* ════════════════════════════════════════════════════════
     *  1 & 3.  Delivery settings and the basket message
     *
     *  One row in the database, so one save. Section 3 writes what it has into
     *  the two hidden boxes on section 1's form and then posts that form.
     * ════════════════════════════════════════════════════════ */

    var settingsForm = $('cbstSettingsForm');
    var msgOn        = $('cbstMsgOn');
    var msgText      = $('cbstMsgText');
    var msgCount     = $('cbstMsgCount');
    var msgStrip     = $('cbstMsgStrip');
    var msgStripText = $('cbstMsgStripText');
    var msgOff       = $('cbstMsgOff');
    var hiddenMsg    = $('f_cart_message');
    var hiddenMsgOn  = $('f_cart_message_active');

    /** Keep the preview, the counter and the hidden boxes in step. */
    function refreshMessage() {
        if (!msgText || !msgOn) { return; }
        var text = msgText.value.trim();
        var on   = msgOn.checked;

        if (msgCount) { msgCount.textContent = String(msgText.value.length); }
        if (hiddenMsg) { hiddenMsg.value = msgText.value; }
        // Empty and switched on shows the customer an empty box, so an empty
        // message counts as off here exactly as it does in store_settings.php.
        if (hiddenMsgOn) { hiddenMsgOn.value = (on && text !== '') ? '1' : ''; }

        var visible = on && text !== '';
        if (msgStrip) { msgStrip.hidden = !visible; }
        if (msgOff) { msgOff.hidden = visible; }
        if (msgStripText) { msgStripText.textContent = text; }
    }

    if (msgText) { msgText.addEventListener('input', refreshMessage); }
    if (msgOn) { msgOn.addEventListener('change', refreshMessage); }
    refreshMessage();

    function saveSettings() {
        clearErrors(settingsForm);
        clearErrors($('s-message'));
        refreshMessage();

        var button = $('cbstSaveSettings');
        var msgButton = $('cbstSaveMessage');
        if (button) { button.disabled = true; }
        if (msgButton) { msgButton.disabled = true; }

        var payload = { action: 'save_settings' };
        new FormData(settingsForm).forEach(function (v, k) { payload[k] = v; });

        return send(payload).then(function (res) {
            if (button) { button.disabled = false; }
            if (msgButton) { msgButton.disabled = false; }

            if (res && res.success) {
                say(res.message || 'Saved.', 'ok');
                // Reload rather than trust the screen. The settings row is read
                // once per request and cached, so the "what a customer pays"
                // panel further down is still showing the OLD figures until the
                // page is fetched again — and a preview that disagrees with the
                // shop is the one thing this page must never do.
                setTimeout(function () { window.location.reload(); }, 700);
                return;
            }

            // The message box lives in section 3, so its error has to be put
            // there rather than in the delivery form.
            var errors = (res && res.errors) || {};
            var homeless = showErrors(document, errors);
            var headline = (res && res.message) || 'That could not be saved.';
            if (homeless.length) { headline = homeless.join(' '); }
            say(headline, 'bad');
            goToFirstError(document);
        });
    }

    if (settingsForm) {
        settingsForm.addEventListener('submit', function (e) {
            e.preventDefault();
            saveSettings();
        });
    }
    if ($('cbstSaveMessage')) {
        $('cbstSaveMessage').addEventListener('click', function () { saveSettings(); });
    }


    /* ════════════════════════════════════════════════════════
     *  2.  Offers
     * ════════════════════════════════════════════════════════ */

    var sheet     = $('cbstOfferSheet');
    var offerForm = $('cbstOfferForm');
    var heading   = $('cbstOfferHeading');
    var typeBox   = $('o_type');
    var scopeBox  = $('o_scope');

    /* ── Which boxes belong to the chosen kind of offer ──── */

    function refreshVisibleFields() {
        if (!offerForm) { return; }
        var type  = typeBox ? typeBox.value : 'bogo';
        var scope = scopeBox ? scopeBox.value : 'all';

        offerForm.querySelectorAll('[data-when]').forEach(function (el) {
            el.hidden = el.dataset.when !== type;
        });
        offerForm.querySelectorAll('[data-when-not]').forEach(function (el) {
            el.hidden = el.dataset.whenNot === type;
        });
        offerForm.querySelectorAll('[data-when-scope]').forEach(function (el) {
            el.hidden = el.dataset.whenScope !== scope;
        });

        // The spend threshold is optional everywhere except the free gift,
        // where it IS the offer, so the wording under it changes rather than
        // the box appearing and disappearing.
        var hint = $('o_min_hint');
        if (hint) {
            hint.textContent = (type === 'free_item_over')
                ? 'Required for this kind of offer — the gift is earned at this amount.'
                : 'Leave empty and the offer applies to any basket.';
        }
    }

    /* ── Saying the offer back in words ──────────────────── */

    function restate() {
        var out = $('cbstRestateText');
        if (!out || !offerForm) { return; }

        var type  = typeBox ? typeBox.value : 'bogo';
        var scope = scopeBox ? scopeBox.value : 'all';
        var words;

        switch (type) {
            case 'bogo':
                words = 'A customer who buys one gets a second one free.';
                break;
            case 'buy_x_get_y':
                var buy = num($('o_buy_qty').value);
                var get = num($('o_get_qty').value);
                if (buy === null || get === null || buy < 1 || get < 1) {
                    words = 'Fill in both quantities to see how this reads.';
                } else {
                    words = 'A customer who buys ' + buy + ' gets ' + get + ' free'
                          + ' — so they pay for ' + buy + ' and take home ' + (buy + get) + '.';
                }
                break;
            case 'percent_off':
                var pc = num($('o_amount_pc').value);
                words = (pc === null)
                    ? 'Fill in the percentage to see how this reads.'
                    : pc + '% comes off the price.';
                break;
            case 'fixed_off':
                var fx = num($('o_amount_fx').value);
                words = (fx === null)
                    ? 'Fill in the amount to see how this reads.'
                    : money(fx) + ' comes off the basket.';
                break;
            case 'free_delivery':
                words = 'Delivery is free, however far away they are inside your delivery area.';
                break;
            case 'free_item_over':
                var gift = $('o_badge_gift').value.trim();
                words = 'They get a free ' + (gift === '' ? 'gift' : gift) + ' added to the order.';
                break;
            default:
                words = '';
        }

        // What it is on.
        if (type !== 'free_delivery' && type !== 'free_item_over') {
            if (scope === 'category') {
                var cat = $('o_category').value;
                words += cat === ''
                    ? ' You still need to choose a category.'
                    : ' This applies to anything in ' + cat + '.';
            } else if (scope === 'product') {
                var sel = $('o_product');
                var pname = sel.value === '' ? '' : sel.options[sel.selectedIndex].textContent;
                words += pname === ''
                    ? ' You still need to choose a product.'
                    : ' This applies to ' + pname + ' only.';
            } else {
                words += ' It applies to everything in the shop.';
            }
        }

        // The threshold.
        var min = num($('o_min_spend').value);
        if (min !== null && min > 0) {
            words += ' Nothing happens until the basket reaches ' + money(min) + '.';
        }

        // Who.
        var who = $('o_applies_to').value;
        if (who === 'trade') {
            words += ' Trade customers only.';
        } else if (who === 'both') {
            words += ' Shop customers and trade customers both get it.';
        }

        // When.
        var from = $('o_starts_on').value;
        var to   = $('o_ends_on').value;
        if (from && to) {
            words += ' It runs from ' + from + ' to ' + to + '.';
        } else if (from) {
            words += ' It starts on ' + from + '.';
        } else if (to) {
            words += ' It finishes on ' + to + '.';
        }

        if (!$('o_active').checked) {
            words += ' It is saved switched off, so nothing happens until you switch it on.';
        }

        out.textContent = words;
    }

    function refreshSheet() {
        refreshVisibleFields();
        restate();
    }

    if (offerForm) {
        // One listener for the whole form rather than one per box — a field
        // added later is covered without anybody remembering to wire it.
        offerForm.addEventListener('input', refreshSheet);
        offerForm.addEventListener('change', refreshSheet);
    }

    /* ── Opening and closing ─────────────────────────────── */

    function openSheet(offer) {
        if (!sheet) { return; }
        clearErrors(offerForm);

        var o = offer || {};
        heading.textContent = o.id ? 'Edit this offer' : 'Add an offer';

        $('o_id').value           = o.id || '';
        $('o_name').value         = o.name || '';
        typeBox.value             = o.type || 'bogo';
        scopeBox.value            = o.scope || 'all';
        $('o_min_spend').value    = o.min_spend || '';
        $('o_applies_to').value   = o.applies_to || 'retail';
        $('o_cart_message').value = o.cart_message || '';
        $('o_starts_on').value    = o.starts_on || '';
        $('o_ends_on').value      = o.ends_on || '';
        $('o_active').checked     = o.id ? Number(o.active) === 1 : true;

        // buy/get and the two money boxes keep their sensible starting values
        // on a NEW offer, and take the stored ones on an edit.
        $('o_buy_qty').value  = o.id ? (o.buy_qty || '') : '2';
        $('o_get_qty').value  = o.id ? (o.get_qty || '') : '1';
        $('o_amount_pc').value = (o.type === 'percent_off' && o.amount) ? o.amount : (o.id ? '' : '10');
        $('o_amount_fx').value = (o.type === 'fixed_off' && o.amount) ? o.amount : (o.id ? '' : '5.00');

        // badge_text is the gift's name on one kind of offer and a little
        // product flash on all the others, so it goes into whichever box is
        // about to be on screen.
        if (o.type === 'free_item_over') {
            $('o_badge_gift').value  = o.badge_text || '';
            $('o_badge_text').value  = '';
        } else {
            $('o_badge_gift').value  = '';
            $('o_badge_text').value  = o.badge_text || '';
        }

        // The two scope dropdowns are separate boxes feeding one column.
        $('o_category').value = (o.scope === 'category') ? (o.scope_value || '') : '';
        $('o_product').value  = (o.scope === 'product') ? (o.scope_value || '') : '';

        refreshSheet();
        sheet.hidden = false;
        document.body.classList.add('cbst-locked');
        $('o_name').focus();
    }

    function closeSheet() {
        if (!sheet) { return; }
        sheet.hidden = true;
        document.body.classList.remove('cbst-locked');
    }

    if ($('cbstNewOffer')) { $('cbstNewOffer').addEventListener('click', function () { openSheet(null); }); }
    if ($('cbstOfferClose')) { $('cbstOfferClose').addEventListener('click', closeSheet); }
    if ($('cbstOfferCancel')) { $('cbstOfferCancel').addEventListener('click', closeSheet); }
    if (sheet) {
        sheet.addEventListener('click', function (e) {
            if (e.target === sheet) { closeSheet(); }
        });
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && sheet && !sheet.hidden) { closeSheet(); }
    });

    /* ── Saving an offer ─────────────────────────────────── */

    if (offerForm) {
        offerForm.addEventListener('submit', function (e) {
            e.preventDefault();
            clearErrors(offerForm);

            var id   = $('o_id').value;
            var type = typeBox.value;
            var scope = scopeBox.value;

            var payload = {
                action:       id ? 'offer_update' : 'offer_add',
                name:         $('o_name').value,
                type:         type,
                scope:        scope,
                applies_to:   $('o_applies_to').value,
                min_spend:    $('o_min_spend').value,
                cart_message: $('o_cart_message').value,
                starts_on:    $('o_starts_on').value,
                ends_on:      $('o_ends_on').value
            };
            if (id) { payload.id = id; }
            if ($('o_active').checked) { payload.active = '1'; }

            // One column, chosen from whichever dropdown is on screen.
            if (scope === 'category') {
                payload.scope_value = $('o_category').value;
            } else if (scope === 'product') {
                payload.scope_value = $('o_product').value;
            }

            // The handler wants one `amount`; the form has a percentage box and
            // a money box so each can be labelled properly.
            if (type === 'percent_off') {
                payload.amount = $('o_amount_pc').value;
            } else if (type === 'fixed_off') {
                payload.amount = $('o_amount_fx').value;
            }

            if (type === 'buy_x_get_y') {
                payload.buy_qty = $('o_buy_qty').value;
                payload.get_qty = $('o_get_qty').value;
            }

            payload.badge_text = (type === 'free_item_over')
                ? $('o_badge_gift').value
                : $('o_badge_text').value;

            var button = $('cbstOfferSave');
            button.disabled = true;

            send(payload).then(function (res) {
                button.disabled = false;

                if (res && res.success) {
                    closeSheet();
                    say(res.message || 'Offer saved.', 'ok');
                    setTimeout(function () { window.location.reload(); }, 500);
                    return;
                }

                var homeless = showErrors(offerForm, (res && res.errors) || {});
                var headline = (res && res.message) || 'That offer could not be saved.';
                if (homeless.length) { headline = homeless.join(' '); }

                var top = offerForm.querySelector('.cbst-err[data-err="_form"]');
                if (top) {
                    top.textContent = headline;
                    top.hidden = false;
                }
                goToFirstError(offerForm);
            });
        });
    }

    /* ── The buttons on each offer in the list ───────────── */

    var list = $('cbstOfferList');

    /** Every offer id in the order they currently appear. */
    function currentOrder() {
        return Array.prototype.map.call(
            list.querySelectorAll('.cbst-offer'),
            function (card) { return card.dataset.offerId; }
        );
    }

    if (list) {
        list.addEventListener('click', function (e) {
            var button = e.target.closest('[data-act]');
            if (!button) { return; }

            var card = button.closest('.cbst-offer');
            if (!card) { return; }
            var id  = card.dataset.offerId;
            var act = button.dataset.act;

            if (act === 'edit') {
                var holder = card.querySelector('.cbst-offer-data');
                var data = null;
                try {
                    data = JSON.parse(holder.textContent);
                } catch (err) {
                    say('That offer could not be opened. Reload the page and try again.', 'bad');
                    return;
                }
                openSheet(data);
                return;
            }

            if (act === 'toggle') {
                button.disabled = true;
                send({ action: 'offer_toggle', id: id }).then(function (res) {
                    button.disabled = false;
                    if (res && res.success) {
                        say(res.message || 'Done.', 'ok');
                        setTimeout(function () { window.location.reload(); }, 500);
                    } else {
                        say((res && res.message) || 'That could not be changed.', 'bad');
                    }
                });
                return;
            }

            if (act === 'delete') {
                var name = card.querySelector('.cbst-offer-name');
                cbConfirm(
                    'Delete “' + (name ? name.textContent : 'this offer') + '”? '
                    + 'It stops applying to baskets straight away and cannot be brought back.',
                    { title: 'Delete this offer?', okText: 'Delete it', tone: 'warn' }
                ).then(function (yes) {
                    if (!yes) { return; }
                    send({ action: 'offer_delete', id: id }).then(function (res) {
                        if (res && res.success) {
                            say(res.message || 'Offer deleted.', 'ok');
                            setTimeout(function () { window.location.reload(); }, 500);
                        } else {
                            say((res && res.message) || 'That could not be deleted.', 'bad');
                        }
                    });
                });
                return;
            }

            if (act === 'up' || act === 'down') {
                var ids = currentOrder();
                var at  = ids.indexOf(id);
                var to  = act === 'up' ? at - 1 : at + 1;
                if (at < 0 || to < 0 || to >= ids.length) { return; }

                ids.splice(to, 0, ids.splice(at, 1)[0]);

                var body = new URLSearchParams();
                body.append('action', 'offer_reorder');
                ids.forEach(function (each) { body.append('ids[]', each); });

                button.disabled = true;
                fetch(HANDLER, { method: 'POST', body: body })
                    .then(function (r) { return r.json(); })
                    .catch(function () {
                        return { success: false, message: 'Could not reach the server.' };
                    })
                    .then(function (res) {
                        if (res && res.success) {
                            window.location.reload();
                        } else {
                            button.disabled = false;
                            say((res && res.message) || 'The order could not be changed.', 'bad');
                        }
                    });
            }
        });
    }
})();
