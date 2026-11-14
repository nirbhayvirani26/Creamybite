/* ============================================================
   Creamy Bite – "Print / Save as PDF" inside the home-screen app

   iOS gives a website added to the home screen no print window: every
   "Print / Save as PDF" button calls window.print(), and in that mode iOS
   silently ignores it. Opening the page in Safari instead does not help
   either — the home-screen app keeps its own login, so Safari would show
   the sign-in page.

   So, only when running as the home-screen app:
     · window.print() is replaced by a PDF made on the phone, from the
       page's own print layout (its @media print rules are applied to a
       copy of the page, so toolbars and buttons are left out exactly as
       when printing).
     · When it is ready, "Save / Share" opens the iPhone share sheet:
       Save to Files, Print, WhatsApp, Mail… A second tap is needed
       because iOS only allows sharing straight after a tap.
     · Admin links that open a new tab (invoice preview, delivery note)
       open in the same app window instead, where the login is.

   In a normal browser none of this runs and printing is unchanged.
   The PDF library (html2pdf.js) is only downloaded when first needed.
   ============================================================ */
(function () {
    'use strict';

    var standalone = window.navigator.standalone === true
        || (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches);
    if (!standalone) { return; }
    // The hidden copy used to make a PDF loads this script too; it must
    // leave that copy alone.
    if (window.top !== window || /[?&]cbpdf=1\b/.test(location.search)) { return; }

    var LIB = 'https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js';
    var busy = false;

    // ── New-tab admin links stay inside the app ───────────────
    document.addEventListener('click', function (e) {
        var a = e.target.closest && e.target.closest('a[target="_blank"]');
        if (!a || !a.href) { return; }
        var url;
        try { url = new URL(a.href, location.href); } catch (err) { return; }
        if (url.origin === location.origin && url.pathname.indexOf('/admin/') !== -1) {
            e.preventDefault();
            location.href = url.href;
        }
    }, true);

    // ── Overlay ───────────────────────────────────────────────
    var box = null;
    function overlay(state, text, file) {
        if (!box) {
            box = document.createElement('div');
            box.className = 'cb-pdf-overlay';
            box.setAttribute('role', 'dialog');
            box.setAttribute('aria-modal', 'true');
            document.body.appendChild(box);
        }
        box.innerHTML = '';
        var card = document.createElement('div');
        card.className = 'cb-pdf-card';
        var msg = document.createElement('p');
        msg.className = 'cb-pdf-msg';
        msg.textContent = text;
        if (state === 'busy') {
            var spin = document.createElement('span');
            spin.className = 'cb-pdf-spin';
            card.appendChild(spin);
        }
        card.appendChild(msg);

        if (state === 'ready') {
            var share = button('Save / Share PDF', 'cb-pdf-btn is-main');
            share.addEventListener('click', function () { sharePdf(file); });
            card.appendChild(share);

            var open = document.createElement('a');
            open.className = 'cb-pdf-btn';
            open.textContent = 'Download';
            open.href = URL.createObjectURL(file);
            open.download = file.name;
            card.appendChild(open);
        }
        if (state !== 'busy') {
            var close = button('Close', 'cb-pdf-btn is-quiet');
            close.addEventListener('click', hide);
            card.appendChild(close);
        }
        box.appendChild(card);
        box.classList.add('is-open');
    }
    function button(label, cls) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = cls;
        b.textContent = label;
        return b;
    }
    function hide() { if (box) { box.classList.remove('is-open'); } busy = false; }

    function sharePdf(file) {
        if (navigator.canShare && navigator.canShare({ files: [file] })) {
            navigator.share({ files: [file], title: file.name }).catch(function (err) {
                if (err && err.name !== 'AbortError') {
                    overlay('ready', 'Could not open the share sheet — use Download instead.', file);
                }
            });
        } else {
            overlay('ready', 'Sharing is not available here — use Download.', file);
        }
    }

    // ── Make the PDF from a hidden copy of the page at A4 width ──
    //
    // Capturing the visible page gave the PHONE layout (narrow columns,
    // mobile card styles), because media queries follow the phone's width.
    // A hidden iframe loading the same address is laid out at A4 width
    // instead, shares the app's login (same cookies), and its own print
    // styles are switched on before the PDF library draws it.

    var A4_PX = 794;              // 210mm at 96dpi

    function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

    function withTimeout(promise, ms, message) {
        return Promise.race([promise, wait(ms).then(function () { throw new Error(message); })]);
    }

    function loadFrame(width) {
        return new Promise(function (resolve, reject) {
            var f = document.createElement('iframe');
            f.className = 'cb-pdf-frame';
            f.setAttribute('aria-hidden', 'true');
            f.setAttribute('tabindex', '-1');
            f.width = String(width);
            f.height = '1123';
            f.onload = function () { resolve(f); };
            f.onerror = function () { reject(new Error('Could not open a copy of this page.')); };
            var u = new URL(location.href);
            u.searchParams.set('cbpdf', '1');
            f.src = u.href;
            document.body.appendChild(f);
        });
    }

    /** Switch a document over to its print layout. */
    function emulatePrint(doc) {
        var css = '';
        Array.prototype.forEach.call(doc.styleSheets, function (sheet) {
            var rules;
            try { rules = sheet.cssRules; } catch (err) { return; }      // other sites' sheets
            if (!rules) { return; }
            var media = sheet.media ? sheet.media.mediaText : '';
            if (/\bprint\b/i.test(media) && !/\b(screen|all)\b/i.test(media)) {
                // A whole stylesheet meant only for printing.
                Array.prototype.forEach.call(rules, function (r) { css += r.cssText + '\n'; });
                return;
            }
            Array.prototype.forEach.call(rules, function (r) {
                if (r.type === 4 /* MEDIA */ && /\bprint\b/i.test(r.media.mediaText)) {
                    Array.prototype.forEach.call(r.cssRules, function (inner) { css += inner.cssText + '\n'; });
                }
            });
        });
        // Stylesheets that apply to screens only are switched off, as a
        // printer would ignore them.
        Array.prototype.forEach.call(doc.querySelectorAll('link[rel="stylesheet"][media], style[media]'), function (el) {
            if (/\bscreen\b/i.test(el.media) && !/\bprint\b/i.test(el.media)) { el.disabled = true; }
        });
        var st = doc.createElement('style');
        st.textContent = css;
        doc.head.appendChild(st);
        ['.cb-app-refresh', '.cb-pdf-overlay'].forEach(function (sel) {
            Array.prototype.forEach.call(doc.querySelectorAll(sel), function (el) { el.remove(); });
        });
    }

    function loadLibInto(win) {
        return new Promise(function (resolve, reject) {
            if (win.html2pdf) { resolve(); return; }
            var s = win.document.createElement('script');
            s.src = LIB;
            s.onload = function () { resolve(); };
            s.onerror = function () { reject(new Error('Could not load the PDF maker. Check the internet connection.')); };
            win.document.head.appendChild(s);
        });
    }

    /** Wait for the copy's pictures and fonts, so none are missing from the PDF. */
    function settle(doc) {
        var imgs = Array.prototype.map.call(doc.images, function (img) {
            return img.complete ? null : new Promise(function (r) { img.onload = img.onerror = r; });
        }).filter(Boolean);
        var fonts = doc.fonts && doc.fonts.ready ? doc.fonts.ready : Promise.resolve();
        return Promise.all(imgs.concat([fonts])).then(function () { return wait(400); });
    }

    function fileName() {
        var t = (document.title || 'Creamy Bite').replace(/[–—]/g, '-').replace(/[^\w\- ]+/g, '').trim();
        return (t || 'Creamy Bite').replace(/\s+/g, ' ') + '.pdf';
    }

    function makePdf() {
        if (busy) { return; }
        busy = true;
        overlay('busy', 'Making the PDF…');

        // The catalogue's sheets are already exact A4 pages: no margin, and
        // the copy is exactly A4 wide so each sheet fills one page.
        var sheets = document.querySelector('.cbbr-sheet') !== null;
        var frame = null;

        withTimeout(loadFrame(sheets ? A4_PX : 900), 30000, 'The page took too long to load. Try again.')
            .then(function (f) {
                frame = f;
                var win = f.contentWindow, doc = f.contentDocument;
                emulatePrint(doc);
                if (sheets) {
                    // The catalogue shrinks its preview to fit small screens
                    // (zoom 0.46 under 820px). The A4-wide copy is under that,
                    // so undo it, or every sheet comes out a fraction of a page.
                    var fix = doc.createElement('style');
                    // A hair under 297mm, so rounding can never spill a sheet
                    // onto a second, nearly empty page.
                    fix.textContent = '.cbbr-doc{zoom:1 !important;padding:0 !important;margin:0 !important;gap:0 !important}'
                                    + '.cbbr-sheet{margin:0 !important;box-shadow:none !important;height:296.4mm !important}';
                    doc.head.appendChild(fix);
                }
                return withTimeout(loadLibInto(win), 30000,
                                   'Could not load the PDF maker. Check the internet connection and try again.')
                    .then(function () { return settle(doc); })
                    .then(function () {
                        // Built with the copy's own JSON: the library runs in that
                        // window and rejects arrays made in this one ("Invalid
                        // margin array"), because instanceof Array fails across windows.
                        var opts = win.JSON.parse(JSON.stringify({
                            margin: sheets ? 0 : [8, 8, 8, 8],
                            filename: fileName(),
                            image: { type: 'jpeg', quality: 0.92 },
                            html2canvas: { scale: 2, useCORS: true, backgroundColor: '#ffffff' },
                            jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
                            // Plain A4 slicing: the catalogue's sheets are exactly
                            // A4 tall, so they fall one to a page.
                            pagebreak: { mode: ['legacy'] }
                        }));
                        if (!sheets) {
                            return win.html2pdf().set(opts).from(doc.body).outputPdf('blob');
                        }
                        // Catalogue: each A4 sheet drawn on its own page, so a
                        // sheet can never be split or drift across a page edge.
                        var list = Array.prototype.slice.call(doc.querySelectorAll('.cbbr-sheet'));
                        var job = win.html2pdf().set(opts).from(list[0]).toPdf();
                        list.slice(1).forEach(function (sheet) {
                            job = job.get('pdf').then(function (pdf) { pdf.addPage(); })
                                     .from(sheet).toContainer().toCanvas().toPdf();
                        });
                        return job.outputPdf('blob');
                    });
            })
            .then(function (blob) {
                var file = new File([blob], fileName(), { type: 'application/pdf' });
                overlay('ready', 'Your PDF is ready.', file);
            })
            .catch(function (err) {
                overlay('error', (err && err.message) || 'Could not make the PDF.');
            })
            .then(function () { if (frame) { frame.remove(); } });
    }

    // Every "Print / Save as PDF" button calls window.print().
    window.print = makePdf;
})();
