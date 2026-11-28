/* CRAD Research Hub: opens step details and step forms in a pop-up on the same page. */
(function () {
    var hub = document.getElementById('researchHub');
    var modal = document.getElementById('rhModal');
    if (!hub || !modal) return;
    var body = document.getElementById('rhModalBody');
    var titleEl = document.getElementById('rhModalTitle');
    var noEl = document.getElementById('rhModalNo');
    var popLink = document.getElementById('rhModalPop');
    var usedFrame = false;
    var lastFocus = null;

    // Styles put into the page shown inside the pop-up so only its content shows.
    var EMBED_CSS = [
        '.sms-navbar,.sms-sidebar,.sms-footer,.breadcrumb,nav[aria-label="breadcrumb"],.module-page-banner,.sms-page-header,.sidebar-backdrop{display:none!important}',
        'body{padding-top:0!important;background:transparent!important}',
        '.sms-wrapper,.sms-content{margin:0!important;padding:0!important;min-height:0!important}',
        '.sms-main{padding:12px 16px 24px!important;margin:0!important;max-width:none!important}'
    ].join('\n');

    function embed(frame) {
        try {
            var doc = frame.contentDocument;
            if (!doc || !doc.head) return;
            if (!doc.getElementById('rhEmbedCss')) {
                var st = doc.createElement('style');
                st.id = 'rhEmbedCss';
                st.textContent = EMBED_CSS;
                doc.head.appendChild(st);
            }
            doc.documentElement.classList.add('sms-embed');
            // Links that leave the page open in the full window instead of the pop-up.
            doc.querySelectorAll('a[target="_blank"]').forEach(function (a) { a.rel = 'noopener'; });
        } catch (e) { /* other origin: show as is */ }
    }

    function open(kind, opts) {
        lastFocus = document.activeElement;
        titleEl.textContent = opts.title || '';
        noEl.textContent = opts.no || '';
        noEl.hidden = !opts.no;
        body.innerHTML = '';
        modal.classList.toggle('rh-modal--frame', kind === 'frame');
        if (kind === 'frame') {
            usedFrame = true;
            popLink.hidden = false;
            popLink.href = opts.src;
            var loading = document.createElement('div');
            loading.className = 'rh-modal-loading';
            loading.textContent = 'Loading…';
            var frame = document.createElement('iframe');
            frame.className = 'rh-frame';
            frame.title = opts.title || 'Step';
            frame.style.visibility = 'hidden';
            frame.addEventListener('load', function () {
                embed(frame);
                loading.remove();
                frame.style.visibility = '';
            });
            frame.src = opts.src;
            body.appendChild(loading);
            body.appendChild(frame);
        } else {
            popLink.hidden = true;
            body.appendChild(opts.content);
        }
        modal.hidden = false;
        document.body.classList.add('rh-modal-open');
        modal.querySelector('.rh-modal-x').focus();
    }

    function close() {
        modal.hidden = true;
        body.innerHTML = '';
        document.body.classList.remove('rh-modal-open');
        if (usedFrame) {
            // Something may have been uploaded or submitted: refresh the statuses.
            window.location.reload();
            return;
        }
        if (lastFocus) lastFocus.focus();
    }

    hub.addEventListener('click', function (ev) {
        var link = ev.target.closest('[data-rh-href]');
        if (link) {
            ev.preventDefault();
            window.open(link.getAttribute('data-rh-href'), '_blank', 'noopener');
            return;
        }
        var btn = ev.target.closest('[data-rh-open]');
        if (btn) {
            ev.preventDefault();
            if (btn.getAttribute('data-rh-open') === 'frame') {
                open('frame', {
                    src: btn.getAttribute('data-rh-src'),
                    title: btn.getAttribute('data-rh-title'),
                    no: btn.getAttribute('data-rh-no')
                });
            } else {
                var card = document.getElementById('rh-step-' + btn.getAttribute('data-rh-step'));
                var tpl = card && card.querySelector('template.rh-details-tpl');
                if (!tpl) return;
                var wrap = document.createElement('div');
                wrap.className = 'rh-details';
                wrap.appendChild(tpl.content.cloneNode(true));
                var head = card.querySelector('h3');
                open('details', { title: head ? head.textContent : 'Details', content: wrap });
            }
            return;
        }
        if (ev.target.closest('[data-rh-close]')) {
            close();
        }
    });

    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && !modal.hidden) close();
    });

    // Open a step straight from a link such as research-workspace.php#open=chapters
    var m = /open=([a-z]+)/.exec(window.location.hash || '');
    if (m) {
        var card = document.getElementById('rh-step-' + m[1]);
        var act = card && (card.querySelector('[data-rh-open="frame"]') || card.querySelector('[data-rh-href]') || card.querySelector('[data-rh-open="details"]'));
        if (act) act.click();
    }
})();
