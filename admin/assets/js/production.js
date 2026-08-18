// Production — the size picker, the yield hint, saving, editing, stock.
// CSRF rides along automatically via admin/_csrf_js.php's fetch wrapper.
(function () {
    'use strict';

    var form   = document.getElementById('prForm');
    if (!form) { return; }

    var $      = function (id) { return document.getElementById(id); };
    var errEl  = $('prErr');
    var okEl   = $('prSaved');
    var btn    = $('prSaveBtn');
    var cancel = $('prCancelBtn');

    function say(el, msg) { if (el) { el.textContent = msg; el.hidden = false; } }
    function clear() { [errEl, okEl].forEach(function (e) { if (e) { e.hidden = true; } }); }

    // ── Sizes follow the chosen flavour ──────────────────────
    var prod = $('prProduct'), variant = $('prVariant');
    function fillSizes(keep) {
        var id = parseInt(prod.value, 10);
        variant.innerHTML = '<option value="">Whole batch / no size</option>';
        var p = (CBPR_CATALOGUE || []).find(function (x) { return x.id === id; });
        if (!p) { return; }
        p.variants.forEach(function (v) {
            var o = document.createElement('option');
            o.value = v.id;
            o.textContent = v.name + (v.case_qty ? '  (' + v.case_qty + ' per case)' : '');
            variant.appendChild(o);
        });
        if (keep) { variant.value = keep; }
    }
    prod.addEventListener('change', function () { fillSizes(null); suggestBatch(false); });

    // ── The batch number that goes on the tub ────────────────
    // Suggested from the flavour and the date, never imposed: once someone has
    // typed their own it is left alone, because a code already printed on a tub
    // outranks anything worked out here. The Suggest button overrides that.
    var ext = $('prExternal'), extBtn = $('prExternalRefresh'), dateEl = $('prDate');
    var extTouched = false;
    if (ext) {
        ext.addEventListener('input', function () { extTouched = true; });
    }

    function suggestBatch(force) {
        if (!ext || !prod || !prod.value) { return; }
        if (!force && extTouched && ext.value.trim() !== '') { return; }

        var body = new FormData();
        body.append('action', 'suggest_batch');
        body.append('product_id', prod.value);
        body.append('produced_on', dateEl ? dateEl.value : '');

        fetch('handlers/production_handler.php', { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.success && d.external_batch) {
                    ext.value = d.external_batch;
                    if (force) { extTouched = false; }
                }
            })
            .catch(function () { /* a suggestion failing must never block the form */ });
    }

    if (dateEl) { dateEl.addEventListener('change', function () { suggestBatch(false); }); }
    if (extBtn) { extBtn.addEventListener('click', function () { suggestBatch(true); }); }

    // ── Yield, as it is typed ────────────────────────────────
    var planned = $('prPlanned'), output = $('prOutput'), hint = $('prYieldHint');
    function paintYield() {
        var p = parseInt(planned.value, 10) || 0;
        var o = parseInt(output.value, 10) || 0;
        if (p <= 0 || o <= 0) { hint.textContent = ''; return; }
        var y = Math.round((o / p) * 1000) / 10;
        hint.textContent = y + '% of plan';
        hint.className = 'cbpr-hint' + (y < 85 ? ' is-low' : '');
    }
    [planned, output].forEach(function (el) { el.addEventListener('input', paintYield); });

    // ── Save ─────────────────────────────────────────────────
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        clear();
        btn.disabled = true;
        var label = btn.innerHTML;
        btn.textContent = 'Saving…';

        fetch('handlers/production_handler.php', { method: 'POST', body: new FormData(form) })
            .then(function (res) {
                return res.text().then(function (body) {
                    var d = null;
                    try { d = JSON.parse(body); } catch (err) { d = null; }
                    return { status: res.status, data: d };
                });
            })
            .then(function (r) {
                btn.disabled = false;
                btn.innerHTML = label;
                if (!r.data)          { say(errEl, 'The server did not answer properly. Please try again.'); return; }
                if (!r.data.success)  { say(errEl, r.data.message || 'Could not save.'); return; }
                say(okEl, r.data.message || 'Saved.');
                window.location.reload();
            })
            .catch(function () {
                btn.disabled = false;
                btn.innerHTML = label;
                say(errEl, 'Could not reach the server.');
            });
    });

    // ── Edit: fill the form from the run ─────────────────────
    document.querySelectorAll('.cbpr-edit').forEach(function (b) {
        b.addEventListener('click', function () {
            var art = b.closest('.cbpr-run');
            var r;
            try { r = JSON.parse(art.getAttribute('data-run')); } catch (e) { return; }

            form.querySelector('[name="action"]').value = 'update';
            $('prId').value = r.id;
            if (ext) { ext.value = r.external_batch || ''; extTouched = true; }
            prod.value = r.product_id || '';
            fillSizes(r.variant_id || null);

            var set = function (id, v) { var el = $(id); if (el) { el.value = (v === null || v === undefined) ? '' : v; } };
            set('prDate', r.produced_on);
            set('prStatus', r.status);
            set('prPlanned', r.planned_qty);
            set('prOutput', r.output_qty);
            set('prReject', r.reject_qty);
            set('prUnit', r.unit_label);
            set('prBB', r.best_before);
            set('prStart', (r.started_at || '').slice(0, 5));
            set('prFinish', (r.finished_at || '').slice(0, 5));
            set('prOperator', r.operator);
            set('prMix', r.mix_temp_c);
            set('prPastT', r.pasteurise_temp_c);
            set('prPastM', r.pasteurise_mins);
            set('prMaterials', r.materials_used);
            set('prChanges', r.changes_made);
            set('prProblems', r.problems);
            set('prNotes', r.notes);

            paintYield();
            btn.innerHTML = 'Save changes to ' + r.batch_code;
            cancel.hidden = false;
            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });

    if (cancel) {
        cancel.addEventListener('click', function () { window.location.reload(); });
    }

    // ── Put the output into stock ────────────────────────────
    document.querySelectorAll('.cbpr-stock').forEach(function (b) {
        b.addEventListener('click', async function () {
            var qty = b.getAttribute('data-qty'), name = b.getAttribute('data-name');
            var ok = (typeof cbConfirm === 'function')
                ? await cbConfirm('Add ' + qty + ' to the stock of ' + name + '? This can only be done once for a run.',
                                  { title: 'Add to stock?', okText: 'Add to stock' })
                : window.confirm('Add ' + qty + ' to ' + name + '?');
            if (!ok) { return; }

            var body = new FormData();
            body.append('action', 'add_to_stock');
            body.append('id', b.getAttribute('data-id'));
            fetch('handlers/production_handler.php', { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d && d.success) { window.location.reload(); }
                    else { window.alert((d && d.message) || 'Could not add it to stock.'); }
                })
                .catch(function () { window.alert('Could not reach the server.'); });
        });
    });

    // ── Delete ───────────────────────────────────────────────
    document.querySelectorAll('.cbpr-del').forEach(function (b) {
        b.addEventListener('click', async function () {
            var code = b.getAttribute('data-code');
            var ok = (typeof cbConfirm === 'function')
                ? await cbConfirm('Delete batch ' + code + '? The record cannot be recovered.',
                                  { title: 'Delete batch record?', tone: 'danger', okText: 'Delete' })
                : window.confirm('Delete ' + code + '?');
            if (!ok) { return; }

            var body = new FormData();
            body.append('action', 'delete');
            body.append('id', b.getAttribute('data-id'));
            fetch('handlers/production_handler.php', { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d && d.success) { window.location.reload(); }
                    else { window.alert((d && d.message) || 'Could not delete it.'); }
                })
                .catch(function () { window.alert('Could not reach the server.'); });
        });
    });
})();
