{{--
    Toggle dark/light mode button for the header.
    Adheres to DESIGN.md: min 44x44px target, keyboard accessible, smooth micro-animation.
--}}
<div
    x-data="{
        theme: (function() {
            try {
                return localStorage.getItem('theme') === 'light' ? 'light' : 'dark';
            } catch {
                return 'dark';
            }
        })(),
        toggle() {
            if (typeof window.toggleTheme === 'function') {
                this.theme = window.toggleTheme();
            } else {
                this.theme = this.theme === 'dark' ? 'light' : 'dark';
                try {
                    localStorage.setItem('theme', this.theme);
                } catch {}
                if (this.theme === 'light') {
                    document.documentElement.classList.add('light');
                    document.documentElement.classList.remove('dark');
                } else {
                    document.documentElement.classList.add('dark');
                    document.documentElement.classList.remove('light');
                }
            }
        }
    }"
    @theme-changed.window="theme = $event.detail.theme"
    class="relative inline-flex items-center"
>
    <button
        type="button"
        @click="toggle()"
        :aria-label="theme === 'dark' ? '{{ __('Beralih ke mode terang') }}' : '{{ __('Beralih ke mode gelap') }}'"
        :title="theme === 'dark' ? '{{ __('Mode Terang') }}' : '{{ __('Mode Gelap') }}'"
        class="inline-flex items-center justify-center min-w-[44px] min-h-[44px] rounded-lg text-slate-400 hover:text-slate-100 hover:bg-slate-800/60 focus:outline-none focus:ring-2 focus:ring-emerald-500/50 transition-colors"
    >
        {{-- Sun icon for dark mode (click turns into light mode) --}}
        <span x-show="theme === 'dark'" class="inline-flex items-center justify-center text-amber-400 hover:text-amber-300 transition-transform duration-200 hover:rotate-12">
            <i data-lucide="sun" class="w-5 h-5"></i>
        </span>

        {{-- Moon icon for light mode (click turns into dark mode) --}}
        <span x-show="theme === 'light'" style="display: none;" class="inline-flex items-center justify-center text-slate-600 hover:text-slate-900 transition-transform duration-200 hover:-rotate-12">
            <i data-lucide="moon" class="w-5 h-5"></i>
        </span>
    </button>
</div>
