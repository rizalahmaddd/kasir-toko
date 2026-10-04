// Toolbar of the print and thermal receipt pages. Kept out of inline handlers so the pages work under
// the Content-Security-Policy, which does not allow inline scripts.
(function () {
    var body = document.body;

    function closeDocument() {
        window.close();
        setTimeout(function () {
            if (window.history.length > 1) {
                window.history.back();
            } else if (document.referrer) {
                window.location.href = document.referrer;
            } else {
                window.location.href = body.dataset.fallbackUrl || '/';
            }
        }, 150);
    }

    function setOrientation(mode) {
        var styleEl = document.getElementById('page-orientation-style');
        var container = document.getElementById('paper-container');
        var active = ['bg-slate-800', 'text-slate-100', 'font-semibold', 'shadow-sm'];
        var inactive = ['text-slate-400', 'hover:text-slate-200'];

        if (styleEl) {
            styleEl.textContent = '@page { size: ' + mode + '; margin: 12mm; }';
        }

        if (container) {
            container.classList.toggle('max-w-5xl', mode === 'landscape');
            container.classList.toggle('max-w-3xl', mode !== 'landscape');
        }

        document.querySelectorAll('[data-orientation]').forEach(function (button) {
            var isActive = button.dataset.orientation === mode;
            active.forEach(function (cls) { button.classList.toggle(cls, isActive); });
            inactive.forEach(function (cls) { button.classList.toggle(cls, !isActive); });
        });
    }

    document.addEventListener('click', function (event) {
        var target = event.target.closest('[data-print-action], [data-orientation]');
        if (!target) return;

        if (target.dataset.orientation) {
            setOrientation(target.dataset.orientation);
        } else if (target.dataset.printAction === 'print') {
            window.print();
        } else if (target.dataset.printAction === 'close') {
            closeDocument();
        }
    });

    if (body.dataset.autoPrint === 'true') {
        window.addEventListener('load', function () {
            setTimeout(function () { window.print(); }, 150);
        });
        window.addEventListener('afterprint', closeDocument);
    }
})();
