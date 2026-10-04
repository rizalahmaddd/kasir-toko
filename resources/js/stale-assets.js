// Vite fingerprints file names, but Livewire only reloads when a tracked asset's query string changes.
// After a rebuild the old and new bundles would otherwise run side by side on the same page.
function assetKey(url) {
    const { pathname } = new URL(url, window.location.href);

    return pathname.replace(/-[\w-]{8}(\.\w+)$/, '$1');
}

document.addEventListener('livewire:navigated', () => {
    const seen = new Map();

    for (const el of document.head.querySelectorAll('link[rel="stylesheet"][data-navigate-track], script[src][data-navigate-track]')) {
        const url = el.href || el.src;
        const key = assetKey(url);

        if (seen.has(key) && seen.get(key) !== url) {
            window.location.reload();

            return;
        }

        seen.set(key, url);
    }
});
