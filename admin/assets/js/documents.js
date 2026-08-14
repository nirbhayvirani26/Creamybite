// Documents & SOPs — upload, delete.
// CSRF is attached automatically by admin/_csrf_js.php's fetch wrapper.
(function () {
    'use strict';

    var form  = document.getElementById('docForm');
    var saved = document.getElementById('docSaved');
    var btn   = document.getElementById('docSaveBtn');

    function showErr(field, msg) {
        var el = document.querySelector('[data-err="' + field + '"]');
        if (!el) { return; }
        el.textContent = msg;
        el.hidden = false;
    }
    function clearErrs() {
        document.querySelectorAll('.cbdoc-err').forEach(function (e) { e.hidden = true; });
        if (saved) { saved.hidden = true; }
    }

    // The category hint, so the owner can see what belongs where without
    // guessing from the name alone.
    var hints = {
        'Food Safety': 'HACCP, temperature control, cleaning, pest control',
        'Allergens':   'The 14 declarable allergens, labelling, cross-contact',
        'Production':  'Recipes, batch records, mix and freeze procedures',
        'Warehouse':   'Cold store, stock rotation, goods in and out',
        'Delivery':    'Vehicle temperatures, driver checks, cold chain',
        'Suppliers':   'Approved suppliers, specifications, certificates',
        'Staff':       'Training, fitness to work, hygiene rules',
        'Compliance':  'Registrations, insurance, audits, inspection reports',
        'General':     'Anything else'
    };
    var catSel  = document.getElementById('dCat');
    var catHint = document.getElementById('dCatHint');
    function paintHint() {
        if (catSel && catHint) { catHint.textContent = hints[catSel.value] || ''; }
    }
    if (catSel) { catSel.addEventListener('change', paintHint); paintHint(); }

    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            clearErrs();

            var file = document.getElementById('dFile');
            if (file && file.files.length === 0) {
                showErr('file', 'Choose a file first.');
                return;
            }

            btn.disabled = true;
            var label = btn.innerHTML;
            btn.textContent = 'Uploading…';

            fetch('handlers/document_handler.php', { method: 'POST', body: new FormData(form) })
                .then(function (res) {
                    // A failed upload can answer with HTML rather than JSON —
                    // read text first so the error says something useful.
                    return res.text().then(function (body) {
                        var data = null;
                        try { data = JSON.parse(body); } catch (err) { data = null; }
                        return { ok: res.ok, status: res.status, data: data, raw: body };
                    });
                })
                .then(function (r) {
                    btn.disabled = false;
                    btn.innerHTML = label;

                    if (!r.data) {
                        showErr('file', r.status === 413
                            ? 'That file is too large for the server.'
                            : 'The upload failed and the server did not explain why.');
                        return;
                    }
                    if (!r.data.success) {
                        showErr('file', r.data.message || 'The upload failed.');
                        return;
                    }
                    if (saved) { saved.textContent = 'Saved.'; saved.hidden = false; }
                    window.location.reload();
                })
                .catch(function () {
                    btn.disabled = false;
                    btn.innerHTML = label;
                    showErr('file', 'Could not reach the server. Check your connection and try again.');
                });
        });
    }

    document.querySelectorAll('.cbdoc-del').forEach(function (b) {
        b.addEventListener('click', async function () {
            var id    = b.getAttribute('data-id');
            var title = b.getAttribute('data-title') || 'this document';
            var ok = (typeof cbConfirm === 'function')
                ? await cbConfirm('Delete "' + title + '"? The file is removed from the server and cannot be recovered.',
                                  { title: 'Delete document?', tone: 'danger', okText: 'Delete' })
                : window.confirm('Delete "' + title + '"?');
            if (!ok) { return; }

            var body = new FormData();
            body.append('action', 'delete');
            body.append('id', id);
            fetch('handlers/document_handler.php', { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d && d.success) { window.location.reload(); }
                    else { window.alert((d && d.message) || 'Could not delete it.'); }
                })
                .catch(function () { window.alert('Could not reach the server.'); });
        });
    });
})();
