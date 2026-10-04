import { createIcons, icons } from 'lucide';

function isStale(el) {
    return el.tagName !== 'svg' || !el.classList.contains(`lucide-${el.getAttribute('data-lucide')}`);
}

function renderIcon(el) {
    // Classes added by Alpine bindings must not be baked into the copied attributes,
    // otherwise Alpine treats them as static on the new svg and never removes them.
    el._x_undoAddedClasses?.();
    el._x_undoAddedStyles?.();

    const placeholder = el.cloneNode(false);
    placeholder.classList.remove(...[...placeholder.classList].filter((name) => name === 'lucide' || name.startsWith('lucide-')));

    const holder = document.createElement('div');
    holder.append(placeholder);
    createIcons({ icons, root: holder });

    if (holder.firstElementChild !== placeholder) {
        el.replaceWith(holder.firstElementChild);
    }
}

function renderWithin(root) {
    if (root.matches?.('[data-lucide]') && isStale(root)) {
        renderIcon(root);
    }
    root.querySelectorAll?.('[data-lucide]:not(svg)').forEach(renderIcon);
}

function renderIcons() {
    renderWithin(document.body ?? document.documentElement);
}

// Mutation callbacks run as microtasks, so icons are drawn before the browser paints
// swapped pages, morphed components, and x-if/x-for/teleport content.
new MutationObserver((mutations) => {
    for (const mutation of mutations) {
        if (mutation.type === 'attributes') {
            if (mutation.target.isConnected && mutation.target.hasAttribute('data-lucide') && isStale(mutation.target)) {
                renderIcon(mutation.target);
            }
            continue;
        }

        mutation.addedNodes.forEach((node) => {
            if (node.nodeType === Node.ELEMENT_NODE && node.isConnected) {
                renderWithin(node);
            }
        });
    }
}).observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['data-lucide'] });

window.renderIcons = renderIcons;
window.addEventListener('render-icons', renderIcons);
renderIcons();
