/**
 * Layar pelanggan (resources/views/display/show.blade.php).
 *
 * Keadaan datang dari tiga jalur, dipakai yang paling baru menurut seq: BroadcastChannel untuk
 * jendela di perangkat yang sama dengan kasir, sinyal Reverb untuk perangkat lain, dan polling
 * endpoint state sebagai cadangan kalau Reverb tidak tersambung.
 */

import { quantity, rupiah } from './pos';

const POLL_FAST = 2500;
const POLL_SLOW = 20000;

function customerDisplay(config) {
    return {
        config,
        state: { stage: 'idle', seq: 0 },
        doneExpired: false,
        unpaired: false,
        fetchFailed: false,
        socketConnected: false,
        clock: '',
        dateString: '',
        slide: 0,
        controlsVisible: true,
        isFullscreen: false,
        lastItemCount: 0,
        flashIndex: -1,
        timers: {},

        rupiah,
        quantity,

        init() {
            this.tickClock();
            this.timers.clock = setInterval(() => this.tickClock(), 1000);

            if ('BroadcastChannel' in window) {
                this.channel = new BroadcastChannel(`pos-display-${this.config.userId}`);
                this.channel.onmessage = (event) => {
                    if (typeof event.data === 'object') {
                        if (event.data.type === 'theme') {
                            this.setTheme(event.data.theme);
                            return;
                        }
                        this.apply(event.data);
                    }
                };
                this.channel.postMessage('hello');
            }

            window.addEventListener('storage', (e) => {
                if (e.key === 'theme' && e.newValue) {
                    this.setTheme(e.newValue);
                }
            });

            window.addEventListener('theme-changed', (e) => {
                if (e.detail?.theme) {
                    this.setTheme(e.detail.theme);
                }
            });

            this.listenToReverb();
            this.poll();

            if (this.config.slides.length > 1) {
                this.timers.slides = setInterval(() => {
                    this.slide = (this.slide + 1) % this.config.slides.length;
                }, this.config.slideSeconds * 1000);
            }

            this.keepAwake();
            document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && this.keepAwake());
            document.addEventListener('fullscreenchange', () => (this.isFullscreen = !!document.fullscreenElement));
            this.showControls();
        },

        listenToReverb() {
            const echo = window.Echo;
            if (!echo) {
                return;
            }
            echo.channel(`display.${this.config.key}`).listen('.display.updated', (event) => {
                if (event.seq > this.state.seq) {
                    this.fetchState();
                }
            });
            const connection = echo.connector?.pusher?.connection;
            if (connection) {
                this.socketConnected = connection.state === 'connected';
                connection.bind('state_change', (states) => (this.socketConnected = states.current === 'connected'));
            }
        },

        async poll() {
            await this.fetchState();
            if (!this.unpaired) {
                this.timers.poll = setTimeout(() => this.poll(), this.socketConnected ? POLL_SLOW : POLL_FAST);
            }
        },

        async fetchState() {
            try {
                const response = await fetch(this.config.stateUrl, { cache: 'no-store', headers: { Accept: 'application/json' } });
                if (response.status === 404) {
                    this.unpaired = true;
                    return;
                }
                if (!response.ok) {
                    throw new Error(String(response.status));
                }
                this.fetchFailed = false;
                this.apply(await response.json());
            } catch {
                this.fetchFailed = true;
            }
        },

        apply(next) {
            if (!next || typeof next.seq !== 'number' || next.seq < this.state.seq) {
                return;
            }

            if (next.theme && next.theme !== this.config.theme) {
                this.setTheme(next.theme);
            }

            const count = next.items?.length ?? 0;
            if (count > this.lastItemCount || (count && JSON.stringify(next.items?.[count - 1]) !== JSON.stringify(this.state.items?.[count - 1]))) {
                this.flashIndex = count - 1;
                clearTimeout(this.timers.flash);
                this.timers.flash = setTimeout(() => (this.flashIndex = -1), 1600);
                this.$nextTick(() => this.$refs.itemList?.scrollTo({ top: this.$refs.itemList.scrollHeight, behavior: 'smooth' }));
            }
            this.lastItemCount = count;

            if (next.stage === 'done' && this.state.stage !== 'done') {
                this.doneExpired = false;
                clearTimeout(this.timers.done);
                this.timers.done = setTimeout(() => (this.doneExpired = true), this.config.thankYouSeconds * 1000);
            }

            this.state = next;
        },

        setTheme(theme) {
            if (!theme) return;
            this.config.theme = theme;
            const root = document.documentElement;
            if (theme === 'light') {
                root.classList.add('light');
                root.classList.remove('dark');
            } else {
                root.classList.add('dark');
                root.classList.remove('light');
            }
        },

        get stage() {
            return this.state.stage === 'done' && this.doneExpired ? 'idle' : this.state.stage;
        },

        get showList() {
            return this.config.showItems && (this.state.items?.length ?? 0) > 0;
        },

        get qrisSrc() {
            return `${this.config.qrisUrl}?amount=${this.state.qris?.amount ?? 0}`;
        },

        tickClock() {
            const now = new Date();
            this.clock = new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' }).format(now);
            this.dateString = new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'short', year: 'numeric' }).format(now);
        },

        async keepAwake() {
            try {
                this.wakeLock = await navigator.wakeLock?.request('screen');
            } catch {
                // perangkat/izin tidak mendukung; layar mengikuti pengaturan sleep perangkat
            }
        },

        showControls() {
            this.controlsVisible = true;
            clearTimeout(this.timers.controls);
            this.timers.controls = setTimeout(() => (this.controlsVisible = false), 4000);
        },

        async toggleFullscreen() {
            try {
                if (document.fullscreenElement) {
                    await document.exitFullscreen();
                } else {
                    await document.documentElement.requestFullscreen();
                }
            } catch {
                // iOS Safari tidak mendukung fullscreen untuk halaman biasa
            }
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('customerDisplay', customerDisplay);
});
