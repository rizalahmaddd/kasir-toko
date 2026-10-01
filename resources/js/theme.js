/**
 * Theme switcher management
 * Handles dark / light mode persistence and DOM updates.
 */

export function getPreferredTheme() {
    try {
        const stored = localStorage.getItem('theme');
        if (stored === 'light' || stored === 'dark') {
            return stored;
        }
    } catch {
        // LocalStorage might be disabled in private mode
    }
    return 'dark'; // default: dark mode
}

export function applyTheme(theme) {
    const root = document.documentElement;
    if (theme === 'light') {
        root.classList.add('light');
        root.classList.remove('dark');
    } else {
        root.classList.add('dark');
        root.classList.remove('light');
    }

    try {
        localStorage.setItem('theme', theme);
        document.cookie = `theme=${theme}; path=/; max-age=31536000; SameSite=Lax`;
    } catch {
        // Ignore quota/private mode errors
    }

    window.dispatchEvent(new CustomEvent('theme-changed', { detail: { theme } }));
}

export function toggleTheme() {
    const current = document.documentElement.classList.contains('light') ? 'light' : 'dark';
    const next = current === 'light' ? 'dark' : 'light';
    applyTheme(next);
    return next;
}

window.applyTheme = applyTheme;
window.toggleTheme = toggleTheme;
window.getPreferredTheme = getPreferredTheme;

// Ensure theme is applied on initial load
applyTheme(getPreferredTheme());

// Re-apply theme before and after Livewire SPA navigation
document.addEventListener('livewire:navigating', () => {
    applyTheme(getPreferredTheme());
});

document.addEventListener('livewire:navigated', () => {
    applyTheme(getPreferredTheme());
});

// Protect documentElement during Livewire DOM morphing so theme class is never stripped
document.addEventListener('livewire:init', () => {
    if (window.Livewire && typeof window.Livewire.hook === 'function') {
        window.Livewire.hook('morph.updating', ({ el, toEl }) => {
            if (el === document.documentElement) {
                const theme = getPreferredTheme();
                if (theme === 'light') {
                    toEl.classList.add('light');
                    toEl.classList.remove('dark');
                } else {
                    toEl.classList.add('dark');
                    toEl.classList.remove('light');
                }
            }
        });
    }
});
