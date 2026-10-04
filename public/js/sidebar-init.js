// The sidebar is re-rendered on every wire:navigate; setting its width class here (not in Alpine init)
// keeps it from animating from the default width and shifting the page on each menu change.
(function () {
    function applySidebarState() {
        var collapsed = window.innerWidth < 1280;
        try {
            var pref = localStorage.getItem('sidebar-collapsed');
            if (pref !== null) collapsed = pref === 'true';
        } catch (e) {}
        document.documentElement.classList.toggle('sidebar-collapsed', collapsed);
    }

    applySidebarState();

    // wire:navigate copies the server-rendered <html> attributes, which drops this class.
    if (!window.__sidebarStateHooked) {
        window.__sidebarStateHooked = true;
        document.addEventListener('livewire:navigating', function (e) {
            e.detail.onSwap(applySidebarState);
        });
    }
})();
