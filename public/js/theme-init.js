// Loaded synchronously in <head> so the saved theme is applied before the first paint.
(function () {
    try {
        var theme = localStorage.getItem('theme');
        if (theme === 'light' || theme === 'dark') {
            document.documentElement.classList.add(theme);
            document.documentElement.classList.remove(theme === 'light' ? 'dark' : 'light');
            document.cookie = 'theme=' + theme + '; path=/; max-age=31536000; SameSite=Lax';
        }
    } catch (e) {}
})();
