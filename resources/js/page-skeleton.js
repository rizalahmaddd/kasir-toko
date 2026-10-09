// Each visited page is traced into a text-free skeleton (same cards, grids, rows, control sizes) and cached
// per route, then shown on the next wire:navigate there while the server responds. Unvisited routes use a
// preset by page type; tables get skeleton rows while a filter, search, sort, tab or page change loads.

const STORAGE_PREFIX = 'page-skeleton:';
const MAX_ENTRIES = 60;
const SHOW_DELAY_MS = 90;
const REFRESH_DELAY_MS = 150;
const MAX_NODES = 1800;
const MAX_TABLE_ROWS = 7;

const buildId = (() => {
    const script = document.querySelector('script[src*="/build/assets/app-"]');
    return script ? script.src.split('/').pop() : 'dev';
})();

const COLOR_CLASS = /^(?:[a-z-]+:)*(?:bg|text|border|ring|from|via|to|shadow|outline|decoration|fill|stroke|divide)-(?:emerald|amber|rose|sky|indigo|purple|red|green|blue|yellow|orange|teal|cyan|violet|fuchsia|pink|lime|white|black)(?:-|\/|$)/;
const DROPPED_CLASS = /^(?:(?:hover|focus|focus-visible|active|group-hover|disabled|peer-[a-z]+):|animate-|transition|duration-|ease-|cursor-|shadow|ring-|backdrop-|select-|truncate$|line-clamp)/;
const SOLID_LEAF = new Set(['INPUT', 'SELECT', 'TEXTAREA', 'IMG', 'SVG', 'CANVAS', 'VIDEO', 'IFRAME', 'PROGRESS']);
const SKIPPED = new Set(['SCRIPT', 'STYLE', 'TEMPLATE', 'NOSCRIPT', 'LINK', 'META']);

let pendingTimer = null;
let skeletonShown = false;

function viewportBucket() {
    return window.matchMedia('(min-width: 768px)').matches ? 'd' : 'm';
}

function routeKey(url) {
    const path = new URL(url, window.location.origin).pathname
        .split('/')
        .map((segment) => (/^\d+$/.test(segment) || /^[0-9A-HJKMNP-TV-Z]{26}$/i.test(segment) ? ':id' : segment))
        .join('/');

    return `${STORAGE_PREFIX}${buildId}:${viewportBucket()}:${path.replace(/\/$/, '') || '/'}`;
}

function presetType(url) {
    const path = new URL(url, window.location.origin).pathname.replace(/^\/|\/$/g, '');

    if (path.split('/').some((segment) => /^\d+$/.test(segment))) return 'detail';
    if (path === '' || path === 'dashboard' || path === 'kepegawaian' || path.endsWith('/ringkasan')) return 'dashboard';
    if (/^(laporan|kepegawaian\/laporan|akuntansi\/(buku-besar|laba-rugi|neraca|neraca-saldo))/.test(path)) return 'report';
    if (/^(profile|pengaturan\/(perusahaan|fitur|peran-izin))/.test(path)) return 'form';

    return 'list';
}

function readCache(key) {
    try {
        const raw = localStorage.getItem(key);
        return raw ? JSON.parse(raw).html : null;
    } catch (e) {
        return null;
    }
}

function writeCache(key, html) {
    try {
        localStorage.setItem(key, JSON.stringify({ html, t: Date.now() }));
        pruneCache();
    } catch (e) {
        pruneCache(true);
    }
}

function pruneCache(aggressive = false) {
    try {
        const entries = Object.keys(localStorage)
            .filter((k) => k.startsWith(STORAGE_PREFIX))
            .map((k) => {
                let t = 0;
                try {
                    t = JSON.parse(localStorage.getItem(k)).t || 0;
                } catch (e) {}
                return { k, t, stale: !k.startsWith(`${STORAGE_PREFIX}${buildId}:`) };
            })
            .sort((a, b) => b.t - a.t);

        const limit = aggressive ? Math.floor(MAX_ENTRIES / 2) : MAX_ENTRIES;
        entries.forEach((entry, index) => {
            if (entry.stale || index >= limit) localStorage.removeItem(entry.k);
        });
    } catch (e) {}
}

function keptClasses(el) {
    const kept = [];
    let lostBorder = false;
    let lostFill = false;

    for (const c of el.classList) {
        if (COLOR_CLASS.test(c)) {
            const base = c.split(':').pop();
            if (c === base && base.startsWith('border-')) lostBorder = true;
            if (c === base && base.startsWith('bg-')) lostFill = true;
            continue;
        }
        if (!DROPPED_CLASS.test(c) && !c.startsWith('sk')) kept.push(c);
    }

    // Colored borders fall back to Tailwind's light default once the color is gone; keep them on theme.
    if (lostBorder) kept.push('border-slate-800');

    return { classes: kept.join(' '), lostFill };
}

function isHidden(el, style) {
    return style.display === 'none'
        || style.visibility === 'hidden'
        || style.position === 'fixed'
        || el.hasAttribute('x-cloak')
        || el.getAttribute('aria-hidden') === 'true' && el.tagName !== 'svg'
        || el.hasAttribute('data-skeleton-skip');
}

function hasVisibleBorder(style) {
    return parseFloat(style.borderTopWidth) > 0 && !/rgba\(\s*\d+,\s*\d+,\s*\d+,\s*0\s*\)/.test(style.borderTopColor);
}

function hasOwnBackground(style) {
    const bg = style.backgroundColor;
    return bg && bg !== 'transparent' && !/rgba\(\s*\d+,\s*\d+,\s*\d+,\s*0\s*\)/.test(bg);
}

function leafBlock(rect, style, inline) {
    const block = document.createElement(inline ? 'span' : 'div');
    block.className = 'sk';
    block.style.width = `${Math.round(rect.width)}px`;
    block.style.maxWidth = '100%';
    block.style.height = `${Math.round(rect.height)}px`;
    block.style.borderRadius = style.borderRadius && style.borderRadius !== '0px' ? style.borderRadius : '0.375rem';
    if (inline) block.style.display = 'inline-block';
    block.style.verticalAlign = 'middle';
    return block;
}

function textBars(textNode) {
    const range = document.createRange();
    range.selectNodeContents(textNode);
    const fragment = document.createDocumentFragment();

    Array.from(range.getClientRects())
        .filter((r) => r.width > 1 && r.height > 1)
        .forEach((r, index) => {
            if (index > 0) fragment.appendChild(document.createTextNode(' '));
            const bar = document.createElement('span');
            bar.className = 'sk sk-text';
            bar.style.width = `${Math.round(Math.max(12, r.width * 0.92, r.height * 1.3))}px`;
            fragment.appendChild(bar);
        });

    return fragment;
}

function trace(root) {
    const limitBottom = root.getBoundingClientRect().top + window.innerHeight * 1.4;
    let budget = MAX_NODES;

    const walk = (el) => {
        if (budget-- <= 0 || SKIPPED.has(el.tagName)) return null;

        const style = getComputedStyle(el);
        if (isHidden(el, style)) return null;

        const rect = el.getBoundingClientRect();
        if (rect.top > limitBottom) return null;
        if (rect.width === 0 && rect.height === 0 && style.display !== 'contents') return null;

        const inline = style.display.startsWith('inline');
        const tag = el.tagName.toUpperCase();
        const text = el.textContent.trim();

        // Icons pinned inside inputs (search glass, currency suffix) are covered by the input block.
        if (style.position === 'absolute' && rect.width <= 40 && rect.height <= 40) return null;

        // Filled or outlined buttons read as one block; bare ones (inactive tabs, text links) keep icon + label.
        const isButton = tag === 'BUTTON' || el.classList.contains('select-trigger');
        if (SOLID_LEAF.has(tag) || (isButton && (hasOwnBackground(style) || hasVisibleBorder(style)))) {
            return leafBlock(rect, style, inline);
        }

        // Small chips and icon tiles (badges, status pills, avatar circles) read as one solid block.
        if (hasOwnBackground(style) && rect.height <= 30 && rect.width <= 180 && !el.querySelector('table, input, select')) {
            return leafBlock(rect, style, inline);
        }
        if (rect.width <= 56 && rect.height <= 56 && text.length <= 3 && el.children.length <= 1 && (hasOwnBackground(style) || el.querySelector('svg'))) {
            return leafBlock(rect, style, inline);
        }

        const cloneTag = ['A', 'BUTTON', 'LABEL', 'FORM', 'FIELDSET', 'LEGEND', 'DETAILS', 'SUMMARY'].includes(tag)
            ? (inline ? 'span' : 'div')
            : tag.toLowerCase();
        const clone = document.createElement(cloneTag);
        const { classes, lostFill } = keptClasses(el);
        // Chart bars, progress fills and status dots have no text; keep them visible as skeleton blocks.
        clone.className = lostFill && !text ? `${classes} sk`.trim() : classes;
        if (el.getAttribute('style')) {
            const width = el.style.width;
            const gridColumns = el.style.gridTemplateColumns;
            if (width) clone.style.width = width;
            if (gridColumns) clone.style.gridTemplateColumns = gridColumns;
        }
        if (el.hasAttribute('colspan')) clone.setAttribute('colspan', el.getAttribute('colspan'));
        if (el.hasAttribute('data-label')) clone.setAttribute('data-label', '');

        let rowCount = 0;
        for (const child of el.childNodes) {
            if (child.nodeType === Node.TEXT_NODE) {
                if (child.textContent.trim()) clone.appendChild(textBars(child));
                continue;
            }
            if (child.nodeType !== Node.ELEMENT_NODE) continue;
            if (child.tagName === 'TR' && el.tagName === 'TBODY' && ++rowCount > MAX_TABLE_ROWS) break;

            const traced = walk(child);
            if (traced) clone.appendChild(traced);
        }

        return clone;
    };

    const wrapper = document.createElement('div');
    for (const child of root.children) {
        if (child.classList.contains('sk-page')) continue;
        const traced = walk(child);
        if (traced) wrapper.appendChild(traced);
    }

    return budget > 0 || wrapper.childElementCount ? wrapper.innerHTML : null;
}

function snapshotCurrentPage() {
    const main = document.querySelector('main');
    if (!main || main.classList.contains('sk-loading')) return;

    const html = trace(main);
    if (html && html.length < 400000) writeCache(routeKey(window.location.href), html);
}

function scheduleSnapshot() {
    const run = () => setTimeout(snapshotCurrentPage, 250);
    if ('requestIdleCallback' in window) {
        requestIdleCallback(run, { timeout: 1500 });
    } else {
        run();
    }
}

function presetHtml(type) {
    const template = document.querySelector(`template[data-skeleton-preset="${type}"]`)
        || document.querySelector('template[data-skeleton-preset="list"]');
    return template ? template.innerHTML : '';
}

function showSkeleton(url) {
    const main = document.querySelector('main');
    if (!main) return;

    // Redirect setelah simpan bisa memicu ini sesudah halaman tujuan selesai dimuat; skeleton itu tidak akan pernah dilepas.
    if (new URL(url, window.location.origin).pathname === window.location.pathname) return;

    main.querySelector(':scope > .sk-page')?.remove();

    const page = document.createElement('div');
    page.className = 'sk-page';
    page.setAttribute('aria-hidden', 'true');
    page.innerHTML = readCache(routeKey(url)) || presetHtml(presetType(url));

    main.appendChild(page);
    main.classList.add('sk-loading');
    main.setAttribute('aria-busy', 'true');
    main.closest('.custom-scrollbar')?.scrollTo({ top: 0 });
    window.scrollTo({ top: 0 });

    document.querySelector('header h2')?.classList.add('sk-title');
    skeletonShown = true;
}

function startNavigation(url, fromHistory) {
    clearTimeout(pendingTimer);
    if (fromHistory) return;

    pendingTimer = setTimeout(() => showSkeleton(url), SHOW_DELAY_MS);
}

function finishNavigation() {
    clearTimeout(pendingTimer);

    if (skeletonShown) {
        const main = document.querySelector('main');
        main?.classList.add('sk-reveal');
        main?.addEventListener('animationend', () => main.classList.remove('sk-reveal'), { once: true });
        skeletonShown = false;
    }

    scheduleSnapshot();
}

document.addEventListener('livewire:navigate', (event) => {
    startNavigation(event.detail.url, event.detail.history && event.detail.cached);
});

document.addEventListener('livewire:navigated', finishNavigation);

document.addEventListener('livewire:init', () => {
    // A $this->redirect(..., navigate: true) after a save skips the livewire:navigate event.
    window.Livewire.hook('commit', ({ succeed }) => {
        succeed(({ effects }) => {
            if (effects?.redirect && effects.redirectUsingNavigate) startNavigation(effects.redirect, false);
        });
    });

    const REFRESH_PROPERTY = /^(search|\w*Search|\w*Filter|filter\w*|perPage|page|paginators\.\w+|sort\w*|tab|activeTab|from|to|asOf|attendanceYear|attendanceMonth|subjectType|causerId|logName|event|itemId|warehouseId|account|quickStatus)$/;
    const REFRESH_METHOD = /^(gotoPage|nextPage|previousPage|setPage|resetPage|sortBy|setTab|resetFilters|preset\w+|show(Receivables|Payables)|backTo(List|Overview))$/;

    const isRefresh = (commit) => {
        const updated = Object.keys(commit.updates || {}).some((key) => REFRESH_PROPERTY.test(key));
        const called = (commit.calls || []).some((call) => REFRESH_METHOD.test(call.method)
            || (call.method === '$set' && REFRESH_PROPERTY.test(String(call.params?.[0] ?? ''))));
        return updated || called;
    };

    window.Livewire.hook('commit', ({ component, commit, respond }) => {
        if (!isRefresh(commit)) return;

        const tables = Array.from(component.el.querySelectorAll('table'))
            .filter((table) => !table.closest('[role="dialog"], .fixed'));
        if (!tables.length) return;

        const timer = setTimeout(() => tables.forEach((t) => t.classList.add('sk-refreshing')), REFRESH_DELAY_MS);
        respond(() => {
            clearTimeout(timer);
            tables.forEach((t) => t.classList.remove('sk-refreshing'));
        });
    });
});

if (document.readyState === 'complete') {
    scheduleSnapshot();
} else {
    window.addEventListener('load', scheduleSnapshot, { once: true });
}
