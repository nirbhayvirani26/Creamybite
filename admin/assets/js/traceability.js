// Traceability — linking a production batch to one line of one order.
// CSRF rides along automatically via admin/_csrf_js.php's fetch wrapper.
(function () {
    'use strict';

    // ── Remember which orders are open ───────────────────────
    // Linking a batch reloads the page; without this every order would
    // fold shut again and the one being worked on would have to be found.
    (function () {
        var KEY = 'cbtrOpenOrders', open = [];
        try { open = JSON.parse(sessionStorage.getItem(KEY) || '[]') || []; } catch (e) { open = []; }
        document.querySelectorAll('details.cbtr-order[data-order-id]').forEach(function (d) {
            var id = d.getAttribute('data-order-id');
            if (open.indexOf(id) !== -1) { d.open = true; }
            d.addEventListener('toggle', function () {
                var i = open.indexOf(id);
                if (d.open && i === -1) { open.push(id); }
                if (!d.open && i !== -1) { open.splice(i, 1); }
                try { sessionStorage.setItem(KEY, JSON.stringify(open.slice(-50))); } catch (e) {}
            });
        });
        var all = document.getElementById('cbtrToggleAll');
        if (all) {
            all.addEventListener('click', function () {
                var list = document.querySelectorAll('details.cbtr-order');
                var anyClosed = Array.prototype.some.call(list, function (d) { return !d.open; });
                list.forEach(function (d) { d.open = anyClosed; });
                all.textContent = anyClosed ? 'Collapse all' : 'Expand all';
            });
        }
    })();

    var modal = document.getElementById('cbtrModal');
    if (!modal) { return; }

    var $ = function (id) { return document.getElementById(id); };
    var runSel = $('cbtrRun'), qtyEl = $('cbtrQty'), notesEl = $('cbtrNotes');
    var msgEl  = $('cbtrMsg'), hintEl = $('cbtrHint'), itemEl = $('cbtrItem');
    var saveBtn = $('cbtrSave');
    var current = null;

    function say(text, kind) {
        msgEl.textContent = text || '';
        msgEl.className = 'cbtr-modal-msg' + (kind ? ' is-' + kind : '');
    }

    function close() {
        modal.hidden = true;
        current = null;
        say('');
    }

    // ── Open, and load the batches that could have supplied this line ──
    document.querySelectorAll('.cbtr-assign').forEach(function (btn) {
        btn.addEventListener('click', function () {
            current = {
                order:   btn.getAttribute('data-order'),
                key:     btn.getAttribute('data-key'),
                product: btn.getAttribute('data-product'),
                variant: btn.getAttribute('data-variant') || 0
            };
            itemEl.textContent = btn.getAttribute('data-label') || '';
            var short = parseInt(btn.getAttribute('data-short'), 10) || 1;
            current.short = short;
            qtyEl.value = short;
            qtyEl.max = short;
            hintEl.textContent = short + ' still to account for on this line.';
            notesEl.value = '';
            say('');
            runSel.innerHTML = '<option value="">Loading…</option>';
            modal.hidden = false;
            setTimeout(function () { runSel.focus(); }, 50);

            var body = new FormData();
            body.append('action', 'runs_for');
            body.append('product_id', current.product);
            body.append('variant_id', current.variant);

            fetch('handlers/traceability_handler.php', { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    // The server only sends batches with good output still
                    // unassigned, so a fully used batch never appears here.
                    if (!d || !d.success || !d.runs || !d.runs.length) {
                        runSel.innerHTML = '<option value="">No batches with tubs left to assign</option>';
                        say('Every batch of this flavour is either fully assigned or not recorded yet. '
                          + 'Record a new production run on the Production page, then come back.', 'warn');
                        return;
                    }
                    runSel.innerHTML = '';
                    d.runs.forEach(function (r) {
                        var o = document.createElement('option');
                        o.value = r.id;
                        o.setAttribute('data-left', r.remaining);
                        var made = r.produced_on ? new Date(r.produced_on).toLocaleDateString('en-GB',
                                     { day: 'numeric', month: 'short', year: 'numeric' }) : '';
                        o.textContent = (r.external_batch || r.batch_code)
                                      + (r.variant_name ? '  ' + r.variant_name : '')
                                      + (made ? '  · made ' + made : '')
                                      + '  · ' + r.remaining + ' of ' + r.output_qty + ' left';
                        runSel.appendChild(o);
                    });
                    fitQty();
                })
                .catch(function () {
                    runSel.innerHTML = '<option value="">Could not load batches</option>';
                    say('Could not reach the server.', 'bad');
                });
        });
    });

    // ── Keep the quantity inside what the line and batch allow ──
    // The most that can be linked is the smaller of what this line still
    // needs and what the chosen batch still has.
    function fitQty() {
        if (!current) { return; }
        var opt  = runSel.selectedOptions[0];
        var left = opt ? parseInt(opt.getAttribute('data-left'), 10) : NaN;
        var max  = isNaN(left) ? current.short : Math.min(current.short, left);
        qtyEl.max = max;
        if ((parseInt(qtyEl.value, 10) || 0) > max) { qtyEl.value = max; }
        hintEl.textContent = current.short + ' still to account for on this line'
            + (isNaN(left) ? '.' : '; ' + left + ' left in this batch. Up to ' + max + ' can be linked.');
    }
    runSel.addEventListener('change', fitQty);

    // ── Save the link ────────────────────────────────────────
    saveBtn.addEventListener('click', function () {
        if (!current) { return; }
        var runId = parseInt(runSel.value, 10) || 0;
        var qty   = parseInt(qtyEl.value, 10) || 0;
        if (!runId) { say('Choose which batch this came from.', 'warn'); return; }
        if (qty <= 0) { say('How many tubs came from that batch?', 'warn'); return; }
        var max = parseInt(qtyEl.max, 10);
        if (!isNaN(max) && qty > max) { say('Up to ' + max + ' can be linked from this batch to this line.', 'warn'); return; }

        saveBtn.disabled = true;
        say('Saving…');

        var body = new FormData();
        body.append('action', 'assign');
        body.append('order_id', current.order);
        body.append('cart_key', current.key);
        body.append('run_id', runId);
        body.append('qty', qty);
        body.append('notes', notesEl.value);

        fetch('handlers/traceability_handler.php', { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                saveBtn.disabled = false;
                if (d && d.success) { location.reload(); return; }
                say((d && d.message) || 'Could not save that link.', 'bad');
            })
            .catch(function () { saveBtn.disabled = false; say('Could not reach the server.', 'bad'); });
    });

    // ── Remove a link ────────────────────────────────────────
    document.querySelectorAll('[data-unassign]').forEach(function (x) {
        x.addEventListener('click', function () {
            var id = x.getAttribute('data-unassign');
            x.disabled = true;
            var body = new FormData();
            body.append('action', 'unassign');
            body.append('id', id);
            fetch('handlers/traceability_handler.php', { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d && d.success) { location.reload(); return; }
                    x.disabled = false;
                })
                .catch(function () { x.disabled = false; });
        });
    });

    $('cbtrClose').addEventListener('click', close);
    $('cbtrCancel').addEventListener('click', close);
    modal.addEventListener('click', function (e) { if (e.target === modal) { close(); } });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden) { close(); }
    });
})();
