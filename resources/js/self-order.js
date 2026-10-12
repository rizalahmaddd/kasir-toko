/**
 * Halaman pesanan QR pelanggan (resources/views/self-order/*.blade.php).
 *
 * Keranjang hidup di browser dan dihitung dengan cart-math.js yang sama dengan kasir; total yang ditagihkan
 * tetap dari server (/quote dan /order). Bundel terpisah dari app.js supaya HP pelanggan tidak ikut memuat
 * kode kasir; ikon ditulis inline di Blade karena icons.js membawa seluruh pustaka Lucide.
 */

import './echo';
import { calculate, nearExpiryDiscount, tierPrice } from './cart-math';

try {
    const storedTheme = localStorage.getItem('theme');
    if (storedTheme === 'light' || storedTheme === 'dark') {
        document.documentElement.classList.remove('light', 'dark');
        document.documentElement.classList.add(storedTheme);
    } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
        document.documentElement.classList.remove('light', 'dark');
        document.documentElement.classList.add('dark');
    }
} catch (e) {}

const rupiahFormat = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 });
const rupiah = (value) => `Rp${rupiahFormat.format(Math.round(Number(value) || 0))}`;

// crypto.randomUUID hanya ada di secure context; HP di jaringan lokal http:// tidak punya.
function uuid() {
    if (window.crypto?.randomUUID) {
        try {
            return window.crypto.randomUUID();
        } catch {
            // jatuh ke cara manual di bawah
        }
    }

    const bytes = window.crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function postJson(url, body) {
    const response = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf() },
        body: JSON.stringify(body),
    });

    return { response, data: await response.json().catch(() => null) };
}

function readStorage(key) {
    try {
        return JSON.parse(localStorage.getItem(key) || 'null');
    } catch {
        return null;
    }
}

function writeStorage(key, value) {
    try {
        if (value === null) {
            localStorage.removeItem(key);
        } else {
            localStorage.setItem(key, JSON.stringify(value));
        }
    } catch {
        // mode privat / storage penuh: halaman tetap jalan, hanya tidak bertahan saat dimuat ulang
    }
}

const modifierKey = (modifiers) => (modifiers || []).map((m) => m.id).sort((a, b) => a - b).join('-');
const modifiersTotal = (line) => (line.modifiers || []).reduce((sum, m) => sum + (Number(m.price) || 0), 0);

function selfOrderMenu(config) {
    return {
        config,
        view: 'menu',
        loading: true,
        loadError: false,
        open: config.open,
        closedMessage: config.closedMessage,
        quotaMessage: null,
        quotaTimer: null,
        categories: [],
        products: [],
        search: '',
        categoryId: null,
        cart: { uuid: uuid(), items: [] },
        picker: null,
        form: { name: '', phone: '', method: 'cashier', notes: '', website: '' },
        captcha: { token: '', widgetId: null },
        submitting: false,
        error: '',
        activeOrder: null,
        rupiah,

        async init() {
            const saved = readStorage(`${config.storageKey}:cart`);
            if (saved && Array.isArray(saved.items)) {
                this.cart = { uuid: saved.uuid || uuid(), items: saved.items };
                this.form = { ...this.form, ...(saved.form || {}), website: '' };
            }
            this.$watch('cart', () => this.persist());
            this.$watch('form', () => this.persist());

            await Promise.all([this.loadMenu(), this.checkActiveOrder()]);
        },

        persist() {
            writeStorage(`${config.storageKey}:cart`, { uuid: this.cart.uuid, items: this.cart.items, form: { name: this.form.name, phone: this.form.phone, method: this.form.method } });
        },

        async loadMenu(silent = false) {
            this.loading = !silent;
            this.loadError = false;
            try {
                const response = await fetch(config.menuUrl, { cache: 'no-store', headers: { Accept: 'application/json' } });
                if (!response.ok) {
                    throw new Error(String(response.status));
                }
                const data = await response.json();
                this.categories = data.categories;
                this.products = data.products;
                this.open = data.open;
                this.closedMessage = data.closed_message;
                this.quotaMessage = data.quota_message || null;
                this.watchQuota();
                this.reconcileCart();
            } catch {
                this.loadError = !silent;
            } finally {
                this.loading = false;
            }
        },

        // Selama kuota penuh, cek ulang berkala supaya tombol kirim aktif lagi begitu kasir memproses pesanan.
        watchQuota() {
            if (this.quotaMessage && this.quotaTimer === null) {
                this.quotaTimer = setInterval(() => document.hidden || this.loadMenu(true), 15000);
            } else if (!this.quotaMessage && this.quotaTimer !== null) {
                clearInterval(this.quotaTimer);
                this.quotaTimer = null;
            }
        },

        // Menu yang sudah tidak dijual atau habis dikeluarkan dari keranjang lama, harga disegarkan.
        reconcileCart() {
            const byId = {};
            this.products.forEach((p) => [p, ...(p.variants || [])].forEach((item) => (byId[item.id] = item)));
            const removed = [];
            this.cart.items = this.cart.items.filter((line) => {
                const product = byId[line.product_id];
                if (!product || product.sold_out) {
                    removed.push(line.name);
                    return false;
                }
                line.price = product.price;
                line.tiers = product.tiers || [];
                line.near_expiry = product.near_expiry || 0;
                line.max = product.max;
                return true;
            });
            if (removed.length) {
                this.error = `Dikeluarkan dari keranjang karena sudah habis: ${removed.join(', ')}.`;
            }
        },

        async checkActiveOrder() {
            const token = readStorage(`${config.storageKey}:order`);
            if (typeof token !== 'string') {
                return;
            }
            try {
                const response = await fetch(config.stateUrlTemplate.replace('__TOKEN__', token), { cache: 'no-store', headers: { Accept: 'application/json' } });
                if (!response.ok) {
                    writeStorage(`${config.storageKey}:order`, null);
                    return;
                }
                const state = await response.json();
                if (['pending', 'in_kitchen', 'ready'].includes(state.status)) {
                    this.activeOrder = { ...state, url: config.statusUrlTemplate.replace('__TOKEN__', token) };
                } else {
                    writeStorage(`${config.storageKey}:order`, null);
                }
            } catch {
                // tanpa jaringan: tautan status tidak ditampilkan, menu tetap bisa dicoba dimuat ulang
            }
        },

        get visibleProducts() {
            const term = this.search.trim().toLowerCase();

            return this.products.filter((p) => (this.categoryId === null || p.category_id === this.categoryId) && (term === '' || p.name.toLowerCase().includes(term)));
        },

        displayPrice(product) {
            if (product.variants?.length) {
                return `mulai ${rupiah(Math.min(...product.variants.map((v) => v.price)))}`;
            }

            return rupiah(product.price);
        },

        isSoldOut(product) {
            return product.variants?.length ? product.variants.every((v) => v.sold_out) : product.sold_out;
        },

        qtyOf(productId) {
            return this.cart.items.filter((l) => l.product_id === productId).reduce((sum, l) => sum + l.quantity, 0);
        },

        qtyInFamily(product) {
            return [product, ...(product.variants || [])].reduce((sum, p) => sum + this.qtyOf(p.id), 0);
        },

        choose(product) {
            if (this.isSoldOut(product)) {
                return;
            }
            if (product.variants?.length || product.modifier_groups?.length) {
                this.picker = { product, variant: product.variants?.length ? null : product, chosen: [], note: '', error: '' };
                this.view = 'options';
                window.scrollTo(0, 0);
                return;
            }
            this.addLine(product, [], '');
        },

        pickerGroups() {
            return this.picker?.variant?.modifier_groups || [];
        },

        pickVariant(variant) {
            if (variant.sold_out) {
                return;
            }
            this.picker.variant = variant;
            this.picker.chosen = [];
        },

        isChosen(modifier) {
            return this.picker.chosen.some((m) => m.id === modifier.id);
        },

        toggleModifier(group, modifier) {
            const picked = this.picker.chosen.filter((m) => m.group_id === group.id);
            if (this.isChosen(modifier)) {
                this.picker.chosen = this.picker.chosen.filter((m) => m.id !== modifier.id);
            } else if (group.max === 1) {
                this.picker.chosen = [...this.picker.chosen.filter((m) => m.group_id !== group.id), { ...modifier, group_id: group.id }];
            } else if (group.max === null || picked.length < group.max) {
                this.picker.chosen = [...this.picker.chosen, { ...modifier, group_id: group.id }];
            }
        },

        get pickerPrice() {
            if (!this.picker?.variant) {
                return null;
            }

            return this.picker.variant.price + this.picker.chosen.reduce((sum, m) => sum + m.price, 0);
        },

        confirmPicker() {
            if (!this.picker.variant) {
                this.picker.error = 'Pilih salah satu varian dulu.';
                return;
            }
            const missing = this.pickerGroups().find((group) => this.picker.chosen.filter((m) => m.group_id === group.id).length < group.min);
            if (missing) {
                this.picker.error = `Lengkapi pilihan ${missing.name} (${missing.rule}).`;
                return;
            }
            const product = this.picker.variant;
            const name = product.variant ? `${this.picker.product.name} (${product.variant})` : product.name;
            if (this.addLine({ ...product, name }, this.picker.chosen.map((m) => ({ id: m.id, name: m.name, price: m.price })), this.picker.note.trim().slice(0, 150))) {
                this.picker = null;
                this.view = 'menu';
            }
        },

        addLine(product, modifiers, note) {
            if (product.max !== null && this.qtyOf(product.id) + 1 > product.max) {
                const message = `Stok ${product.name} tinggal ${product.max}.`;
                if (this.picker) {
                    this.picker.error = message;
                } else {
                    this.error = message;
                }
                return false;
            }
            const key = modifierKey(modifiers);
            const line = this.cart.items.find((l) => l.product_id === product.id && modifierKey(l.modifiers) === key && (l.note || '') === note);
            if (line) {
                line.quantity += 1;
            } else if (this.cart.items.length >= config.maxLines) {
                this.error = `Satu pesanan maksimal ${config.maxLines} baris menu.`;
                return false;
            } else {
                this.cart.items.push({ key: uuid(), product_id: product.id, name: product.name, price: product.price, quantity: 1, modifiers, note, tiers: product.tiers || [], near_expiry: product.near_expiry || 0, max: product.max });
            }
            this.error = '';
            navigator.vibrate?.(12);

            return true;
        },

        increment(line) {
            if (line.max !== null && this.qtyOf(line.product_id) + 1 > line.max) {
                this.error = `Stok ${line.name} tinggal ${line.max}.`;
                return;
            }
            if (line.quantity < 99) {
                line.quantity += 1;
            }
        },

        decrement(line) {
            if (line.quantity <= 1) {
                this.cart.items = this.cart.items.filter((l) => l.key !== line.key);
                if (!this.cart.items.length) {
                    this.view = 'menu';
                }
                return;
            }
            line.quantity -= 1;
        },

        // Baris polos (tanpa pilihan/catatan) produk ini, untuk stepper langsung di daftar menu.
        plainLine(product) {
            return this.cart.items.find((l) => l.product_id === product.id && !l.modifiers.length && !l.note);
        },

        linePrice(line) {
            const qty = this.cart.items.filter((l) => l.product_id === line.product_id).reduce((sum, l) => sum + l.quantity, 0);

            return tierPrice(line.price, line.tiers, qty);
        },

        get autoDiscounts() {
            const left = {};
            const result = {};
            for (const line of this.cart.items) {
                if (!(line.near_expiry > 0)) {
                    result[line.key] = 0;
                    continue;
                }
                left[line.product_id] ??= Number(line.near_expiry);
                result[line.key] = nearExpiryDiscount(this.linePrice(line), line.quantity, left[line.product_id], config.nearExpiryPercent || 0);
                left[line.product_id] = Math.max(0, left[line.product_id] - line.quantity);
            }

            return result;
        },

        get totals() {
            const auto = this.autoDiscounts;

            return calculate(this.cart.items.map((line) => ({ price: this.linePrice(line), modifiers: modifiersTotal(line), quantity: line.quantity, discount: auto[line.key] || 0 })), null, 0, config.taxRate, config.serviceRate);
        },

        lineTotal(line) {
            return Math.round((this.linePrice(line) + modifiersTotal(line)) * line.quantity) - (this.autoDiscounts[line.key] || 0);
        },

        get itemCount() {
            return this.cart.items.reduce((sum, line) => sum + line.quantity, 0);
        },

        openCart() {
            if (!this.cart.items.length) {
                return;
            }
            this.view = 'cart';
            this.error = '';
            window.scrollTo(0, 0);
            this.$nextTick(() => this.renderCaptcha());
        },

        backToMenu() {
            this.view = 'menu';
            this.picker = null;
        },

        renderCaptcha() {
            if (!config.captchaSiteKey || this.captcha.widgetId !== null || !this.$refs.captcha) {
                return;
            }
            const wait = setInterval(() => {
                if (!window.turnstile) {
                    return;
                }
                clearInterval(wait);
                this.captcha.widgetId = window.turnstile.render(this.$refs.captcha, {
                    sitekey: config.captchaSiteKey,
                    language: 'id',
                    callback: (token) => (this.captcha.token = token),
                    'expired-callback': () => (this.captcha.token = ''),
                });
            }, 100);
        },

        async submit() {
            if (this.submitting) {
                return;
            }
            if (this.form.name.trim().length < 2) {
                this.error = 'Isi nama pemesan supaya kasir bisa memanggil Anda.';
                this.$refs.name?.focus();
                return;
            }
            if (config.captchaSiteKey && !this.captcha.token) {
                this.error = 'Selesaikan verifikasi keamanan di bawah dulu.';
                return;
            }

            this.submitting = true;
            this.error = '';

            let result;
            try {
                result = await postJson(config.orderUrl, {
                    client_uuid: this.cart.uuid,
                    customer_name: this.form.name.trim(),
                    customer_phone: this.form.phone.trim() || null,
                    payment_method: config.qris ? this.form.method : 'cashier',
                    notes: this.form.notes.trim() || null,
                    website: this.form.website,
                    captcha_token: this.captcha.token || null,
                    items: this.cart.items.map((l) => ({ product_id: l.product_id, quantity: l.quantity, note: l.note || null, modifiers: l.modifiers.map((m) => m.id) })),
                });
            } catch {
                this.submitting = false;
                this.error = 'Koneksi terputus. Periksa jaringan lalu tekan Kirim Pesanan lagi; pesanan tidak akan terkirim dua kali.';
                return;
            }

            const { response, data } = result;

            if (response.ok && data?.ok) {
                writeStorage(`${config.storageKey}:order`, data.session_token);
                writeStorage(`${config.storageKey}:cart`, { uuid: uuid(), items: [], form: { name: this.form.name, phone: this.form.phone, method: this.form.method } });
                window.location.href = data.status_url;
                return;
            }

            this.submitting = false;
            if (this.captcha.widgetId !== null) {
                window.turnstile?.reset(this.captcha.widgetId);
                this.captcha.token = '';
            }

            if (response.status === 419) {
                this.error = 'Halaman terlalu lama terbuka. Muat ulang halaman; keranjang Anda tetap tersimpan.';
                return;
            }
            if (response.status === 429) {
                this.error = 'Terlalu banyak percobaan. Tunggu sebentar lalu coba lagi.';
                return;
            }

            this.error = data?.message || 'Pesanan belum terkirim. Coba lagi sebentar.';

            if (data?.reason === 'device') {
                this.error = 'Halaman perlu dimuat ulang sebelum memesan. Keranjang Anda tetap tersimpan.';
                return;
            }

            if (data?.reason === 'quota') {
                this.error = '';
                this.quotaMessage = data.message;
                this.watchQuota();
                return;
            }

            if (['unavailable', 'insufficient_stock', 'closed'].includes(data?.reason)) {
                await this.loadMenu();
                this.error = data.message;
                if (!this.cart.items.length) {
                    this.view = 'menu';
                }
            }
        },
    };
}

const STEPS = ['pending', 'in_kitchen', 'ready', 'served'];

function selfOrderStatus(config) {
    return {
        config,
        state: config.state,
        socketConnected: false,
        fetchFailed: false,
        alertsOn: false,
        cancelling: false,
        confirmCancel: false,
        error: '',
        audio: null,
        rupiah,

        init() {
            this.alertsOn = readStorage('self-order:alerts') === true;
            this.listen();
            this.poll();
            document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && this.fetchState());
        },

        listen() {
            const echo = window.Echo;
            if (!echo) {
                return;
            }
            echo.channel(`self-order.${config.sessionToken}`).listen('.self-order.updated', () => this.fetchState());
            const connection = echo.connector?.pusher?.connection;
            if (connection) {
                this.socketConnected = connection.state === 'connected';
                connection.bind('state_change', (states) => (this.socketConnected = states.current === 'connected'));
            }
        },

        // Polling hanya cadangan: 5 detik selama websocket putus, jarang bila tersambung.
        async poll() {
            if (!this.finished) {
                await this.fetchState();
                setTimeout(() => this.poll(), this.socketConnected ? 30000 : 5000);
            }
        },

        async fetchState() {
            try {
                const response = await fetch(config.stateUrl, { cache: 'no-store', headers: { Accept: 'application/json' } });
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
            const becameReady = next.status === 'ready' && this.state.status !== 'ready';
            this.state = next;
            if (becameReady) {
                this.alert();
            }
        },

        get finished() {
            return ['served', 'cancelled', 'expired'].includes(this.state.status);
        },

        get stopped() {
            return ['cancelled', 'expired'].includes(this.state.status);
        },

        get steps() {
            const current = STEPS.indexOf(this.state.status);
            const pickup = this.state.table ? 'Diantar ke meja' : 'Siap diambil';

            return [
                { label: 'Menunggu kasir', done: current > 0, active: current === 0 },
                { label: 'Disiapkan', done: current > 1, active: current === 1 },
                { label: pickup, done: current > 2, active: current === 2 },
            ];
        },

        get headline() {
            const table = this.state.table;

            return {
                pending: this.state.payment_status === 'rejected' ? 'Pembayaran belum ditemukan' : 'Menunggu konfirmasi kasir',
                in_kitchen: 'Pesanan sedang disiapkan',
                ready: table ? 'Pesanan siap, segera diantar' : 'Pesanan siap diambil',
                served: this.state.payment_status === 'paid' ? 'Pesanan selesai' : 'Pesanan sudah diantar',
                cancelled: 'Pesanan dibatalkan',
                expired: 'Pesanan kedaluwarsa',
            }[this.state.status];
        },

        get detail() {
            if (this.state.payment_status === 'rejected' && !this.stopped) {
                return 'Kasir tidak menemukan pembayaran QRIS Anda. Tunjukkan bukti transfer ke kasir, atau bayar langsung di kasir.';
            }

            if (this.state.status === 'served' && this.state.payment_status !== 'paid') {
                return 'Selamat menikmati. Pembayaran dilakukan di kasir setelah selesai.';
            }

            return {
                pending: this.state.show_qris ? 'Bayar dengan QRIS di bawah, lalu tunggu kasir memeriksanya.' : 'Kasir akan memeriksa pesanan Anda sebentar lagi.',
                in_kitchen: 'Halaman ini berubah sendiri saat pesanan siap.',
                ready: this.state.table ? `Tunggu di Meja ${this.state.table}.` : `Ambil di kasir dengan nomor antrean ${this.state.queue_number}.`,
                served: 'Terima kasih sudah memesan.',
                cancelled: this.state.cancel_reason || 'Silakan pesan lagi atau hubungi kasir.',
                expired: 'Kasir belum sempat menanggapi. Silakan pesan lagi atau langsung ke kasir.',
            }[this.state.status];
        },

        // Audio di HP baru boleh berbunyi setelah satu ketukan pengguna, jadi konteksnya dibuka di sini.
        enableAlerts() {
            try {
                const Context = window.AudioContext || window.webkitAudioContext;
                this.audio = this.audio || (Context ? new Context() : null);
                this.audio?.resume();
            } catch {
                this.audio = null;
            }
            this.alertsOn = true;
            writeStorage('self-order:alerts', true);
            this.beep(1);
        },

        alert() {
            // navigator.vibrate tidak ada di iOS Safari; di sana hanya bunyi.
            navigator.vibrate?.([300, 150, 300, 150, 300]);
            this.beep(3);
        },

        beep(times) {
            if (!this.audio) {
                return;
            }
            for (let i = 0; i < times; i++) {
                const start = this.audio.currentTime + i * 0.35;
                const oscillator = this.audio.createOscillator();
                const gain = this.audio.createGain();
                oscillator.frequency.value = 880;
                gain.gain.setValueAtTime(0.25, start);
                gain.gain.exponentialRampToValueAtTime(0.001, start + 0.25);
                oscillator.connect(gain).connect(this.audio.destination);
                oscillator.start(start);
                oscillator.stop(start + 0.25);
            }
        },

        async cancel() {
            this.cancelling = true;
            this.error = '';
            try {
                const { response, data } = await postJson(config.cancelUrl, {});
                if (response.ok && data?.ok) {
                    this.state = data.state;
                    this.confirmCancel = false;
                } else {
                    this.error = data?.message || 'Pesanan belum bisa dibatalkan. Coba lagi atau hubungi kasir.';
                    this.fetchState();
                }
            } catch {
                this.error = 'Koneksi terputus. Coba lagi.';
            } finally {
                this.cancelling = false;
            }
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('selfOrderMenu', selfOrderMenu);
    window.Alpine.data('selfOrderStatus', selfOrderStatus);
});
