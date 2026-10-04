/**
 * Keranjang layar kasir (resources/views/livewire/pos/cashier.blade.php).
 *
 * Keranjang hidup di browser supaya tombol +/- instan dan isinya tidak hilang saat halaman
 * termuat ulang atau koneksi putus. Rumus total harus sama dengan App\Services\Pos\CartCalculator;
 * server menolak checkout kalau totalnya berbeda.
 */

const rupiahFormat = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 });
const quantityFormat = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 3 });

export const rupiah = (value) => `Rp${rupiahFormat.format(Math.round(Number(value) || 0))}`;
export const quantity = (value) => quantityFormat.format(Number(value) || 0);

// crypto.randomUUID hanya ada di secure context; tablet yang membuka http://192.168.x.x tidak punya.
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

function emptyCart() {
    return { uuid: uuid(), items: [], customer: null, discountType: null, discountValue: 0, note: '' };
}

function notify(message, type = 'success') {
    window.dispatchEvent(new CustomEvent('notify', { detail: { message, type } }));
}

function openModal(name) {
    window.dispatchEvent(new CustomEvent('open-modal', { detail: name }));
}

function closeModal(name) {
    window.dispatchEvent(new CustomEvent('close-modal', { detail: name }));
}

function posCashier(config) {
    return {
        config,
        cart: emptyCart(),
        cartOpen: false,
        online: navigator.onLine,

        editing: null,
        discountForm: { type: 'amount', value: '' },

        customerQuery: '',
        customerResults: [],
        customerLoading: false,
        customerForm: { open: false, name: '', phone: '', error: '', saving: false },

        heldOrders: [],
        heldLoading: false,
        holdLabel: '',
        holding: false,

        pay: null,
        payOpen: false,
        qrisView: { amount: 0, svg: '', merchant: '', loading: false },

        display: { link: null, loading: false, channel: null, pushTimer: null, lastPushed: '' },

        scan: { buffer: '', last: 0 },
        camera: { supported: false, stream: null, active: false, error: '' },

        init() {
            this.restore();
            this.$watch('cart', () => this.persist());

            this.camera.supported = 'BarcodeDetector' in window && !!navigator.mediaDevices?.getUserMedia;

            window.addEventListener('modal-toggled', (event) => {
                if (event.detail.name === 'pos-payment') {
                    this.payOpen = event.detail.show;
                }
            });

            if (this.config.display.enabled) {
                if ('BroadcastChannel' in window) {
                    this.display.channel = new BroadcastChannel(`pos-display-${this.config.userId}`);
                    // Layar di jendela lain yang baru dibuka minta keadaan terakhir.
                    this.display.channel.onmessage = (event) => event.data === 'hello' && this.publishDisplay(true);
                }
                this.$watch('displaySnapshot', () => this.publishDisplay());
                this.publishDisplay(true);

                window.addEventListener('theme-changed', (event) => {
                    const theme = event.detail?.theme;
                    if (theme && this.display.channel) {
                        this.display.channel.postMessage({ type: 'theme', theme });
                    }
                });
            }

            this.$watch('qrisAmount', (amount) => this.loadQris(amount));

            window.addEventListener('online', () => (this.online = true));
            window.addEventListener('offline', () => (this.online = false));

            if (this.cart.items.length) {
                this.syncCart(true);
            }
        },

        destroy() {
            this.stopCamera();
            this.display.channel?.close();
        },

        // Layar pelanggan

        get qrisAmount() {
            return this.payOpen && this.pay && !this.pay.result && this.pay.method === 'qris' ? this.payCurrent : 0;
        },

        displayState() {
            const items = this.cart.items.map((line) => ({
                name: line.name,
                qty: Number(line.quantity),
                unit: line.unit,
                price: line.price,
                discount: this.lineDiscount(line),
                total: this.lineTotal(line),
                note: line.note || null,
            }));
            const base = {
                customer: this.cart.customer?.name ?? null,
                items,
                count: this.itemCount,
                lines: items.length,
                subtotal: this.subtotal,
                discount: this.discountAmount,
                tax: this.taxAmount,
                taxLabel: this.config.taxRate > 0 ? `${this.config.taxLabel} ${quantity(this.config.taxRate)}%` : null,
                total: this.total,
            };

            const currentTheme = document.documentElement.classList.contains('light') ? 'light' : 'dark';
            if (this.pay?.result) {
                const r = this.pay.result;
                return { theme: currentTheme, stage: 'done', done: { number: r.number, total: r.total, change: r.change, due: r.due } };
            }
            if (this.payOpen && this.pay) {
                if (this.qrisAmount > 0 && this.config.qris) {
                    return { ...base, theme: currentTheme, stage: 'qris', qris: { amount: this.qrisAmount } };
                }
                return {
                    ...base,
                    theme: currentTheme,
                    stage: 'payment',
                    payment: { method: this.pay.method, received: this.payTotal, change: this.payChange, shortfall: this.payShortfall, credit: this.pay.credit },
                };
            }
            return this.cart.items.length ? { ...base, theme: currentTheme, stage: 'cart' } : { theme: currentTheme, stage: 'idle' };
        },

        get displaySnapshot() {
            return JSON.stringify(this.displayState());
        },

        publishDisplay(force = false) {
            if (!this.config.display.enabled) {
                return;
            }
            const snapshot = this.displaySnapshot;
            const state = { ...JSON.parse(snapshot), seq: Date.now() };

            this.display.channel?.postMessage(state);

            if (!this.config.display.linked || (!force && snapshot === this.display.lastPushed)) {
                return;
            }

            clearTimeout(this.display.pushTimer);
            this.display.pushTimer = setTimeout(() => {
                this.display.lastPushed = snapshot;
                fetch(this.config.display.pushUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    },
                    body: JSON.stringify({ ...JSON.parse(snapshot), seq: Date.now() }),
                    keepalive: true,
                }).catch(() => {
                    this.display.lastPushed = '';
                });
            }, 250);
        },

        async openDisplayPanel() {
            openModal('pos-display');
            if (!this.display.link) {
                await this.loadDisplayLink(false);
            }
        },

        async loadDisplayLink(rotate) {
            this.display.loading = true;
            try {
                this.display.link = await this.$wire.displayLink(rotate);
                if (this.display.link) {
                    this.config.display.linked = true;
                    this.publishDisplay(true);
                    if (rotate) {
                        notify('Kode layar diganti. Layar lama sudah terputus; pindai kode baru.');
                    }
                }
            } finally {
                this.display.loading = false;
            }
        },

        async openDisplayWindow() {
            if (!this.display.link) {
                await this.loadDisplayLink(false);
            }
            if (this.display.link) {
                window.open(this.display.link.url, 'customer-display', 'popup,width=1280,height=800');
                closeModal('pos-display');
            }
        },

        async copyDisplayLink() {
            try {
                await navigator.clipboard.writeText(this.display.link.url);
                notify('Tautan layar pelanggan disalin.');
            } catch {
                notify('Tidak bisa menyalin otomatis. Pilih tautannya lalu salin manual.', 'error');
            }
        },

        async loadQris(amount) {
            if (!this.config.qris || amount <= 0) {
                return;
            }
            if (this.qrisView.amount === amount && this.qrisView.svg) {
                return;
            }
            this.qrisView.loading = true;
            this.qrisView.amount = amount;
            clearTimeout(this.qrisView.timer);
            this.qrisView.timer = setTimeout(async () => {
                try {
                    const result = await this.$wire.qrisSvg(amount);
                    if (this.qrisView.amount === amount && result) {
                        this.qrisView.svg = result.svg;
                        this.qrisView.merchant = result.merchant;
                    }
                } finally {
                    this.qrisView.loading = false;
                }
            }, 250);
        },

        // Penyimpanan lokal

        storageKey() {
            return `pos-cart:${this.config.userId}`;
        },

        persist() {
            try {
                localStorage.setItem(this.storageKey(), JSON.stringify(this.cart));
            } catch {
                // mode privat / storage penuh: keranjang tetap jalan, hanya tidak bertahan saat reload
            }
        },

        restore() {
            try {
                const saved = JSON.parse(localStorage.getItem(this.storageKey()) || 'null');
                if (saved && Array.isArray(saved.items)) {
                    this.cart = { ...emptyCart(), ...saved, uuid: saved.uuid || uuid() };
                }
            } catch {
                this.cart = emptyCart();
            }
        },

        // Perhitungan (sama dengan CartCalculator)

        lineGross(line) {
            return Math.round(line.price * line.quantity);
        },

        lineDiscount(line) {
            return Math.max(0, Math.min(Math.round(line.discount || 0), this.lineGross(line)));
        },

        lineTotal(line) {
            return this.lineGross(line) - this.lineDiscount(line);
        },

        get subtotal() {
            return this.cart.items.reduce((sum, line) => sum + this.lineTotal(line), 0);
        },

        get discountAmount() {
            const value = Number(this.cart.discountValue) || 0;
            if (this.cart.discountType === 'percent') {
                return Math.round((this.subtotal * Math.max(0, Math.min(100, value))) / 100);
            }
            if (this.cart.discountType === 'amount') {
                return Math.max(0, Math.min(Math.round(value), this.subtotal));
            }
            return 0;
        },

        get taxAmount() {
            return Math.round(((this.subtotal - this.discountAmount) * this.config.taxRate) / 100);
        },

        get total() {
            return this.subtotal - this.discountAmount + this.taxAmount;
        },

        get itemCount() {
            return this.cart.items.reduce((sum, line) => sum + Number(line.quantity), 0);
        },

        qtyInCart(productId) {
            return this.cart.items.filter((line) => line.product_id === productId).reduce((sum, line) => sum + Number(line.quantity), 0);
        },

        rupiah,
        quantity,

        // Keranjang

        stockAllows(line, newQuantity, product = null) {
            const track = product ? product.track : line.track;
            const stock = product ? product.stock : line.stock;
            if (!track || this.config.allowNegative || stock === null) {
                return true;
            }
            const productId = product ? product.id : line.product_id;
            const others = this.cart.items.filter((l) => l.product_id === productId && l !== line).reduce((sum, l) => sum + Number(l.quantity), 0);
            if (others + newQuantity <= stock + 0.0001) {
                return true;
            }
            const name = product ? product.name : line.name;
            notify(stock <= 0 ? `Stok ${name} habis.` : `Stok ${name} tinggal ${quantity(stock)}.`, 'error');
            return false;
        },

        add(product) {
            const line = this.cart.items.find((l) => l.product_id === product.id && !l.note && !l.discount);
            const current = line ? Number(line.quantity) : 0;

            if (!this.stockAllows(line, current + 1, product)) {
                return;
            }

            if (this.config.allowNegative && product.track && (product.stock === null || product.stock <= 0 || (current + 1) > product.stock)) {
                const curStock = product.stock !== null ? quantity(product.stock) : '0';
                notify(`Stok ${product.name} habis/minus (${curStock}). Tetap ditambahkan ke keranjang.`, 'warning');
            }

            if (line) {
                line.quantity = current + 1;
                line.price = product.price;
                line.stock = product.stock;
            } else {
                this.cart.items.push({
                    key: uuid(),
                    product_id: product.id,
                    name: product.name,
                    sku: product.sku,
                    unit: product.unit,
                    price: product.price,
                    quantity: 1,
                    discount: 0,
                    note: '',
                    track: product.track,
                    stock: product.stock,
                });
            }

            navigator.vibrate?.(12);
        },

        increment(line) {
            const next = Number(line.quantity) + 1;
            if (this.stockAllows(line, next)) {
                if (this.config.allowNegative && line.track && (line.stock === null || line.stock <= 0 || next > line.stock)) {
                    const curStock = line.stock !== null ? quantity(line.stock) : '0';
                    notify(`Stok ${line.name} habis/minus (${curStock}).`, 'warning');
                }
                line.quantity = next;
            }
        },

        decrement(line) {
            const next = Number(line.quantity) - 1;
            if (next <= 0) {
                this.remove(line);
                return;
            }
            line.quantity = Math.round(next * 1000) / 1000;
        },

        remove(line) {
            this.cart.items = this.cart.items.filter((l) => l.key !== line.key);
        },

        clearCart() {
            this.cart = emptyCart();
            closeModal('pos-clear');
            this.cartOpen = false;
        },

        editLine(line) {
            this.editing = {
                key: line.key,
                name: line.name,
                unit: line.unit,
                price: line.price,
                quantity: String(line.quantity).replace('.', ','),
                discount: line.discount ? String(line.discount) : '',
                note: line.note || '',
                error: '',
            };
            openModal('pos-line');
        },

        saveLine() {
            const line = this.cart.items.find((l) => l.key === this.editing.key);
            if (!line) {
                closeModal('pos-line');
                return;
            }

            const qty = Math.round(parseFloat(String(this.editing.quantity).replace(',', '.')) * 1000) / 1000;
            if (!(qty > 0)) {
                this.editing.error = 'Jumlah harus lebih dari 0.';
                return;
            }
            if (!this.stockAllows(line, qty)) {
                this.editing.error = 'Jumlah melebihi stok yang tersedia.';
                return;
            }

            const discount = parseInt(String(this.editing.discount).replace(/\D/g, ''), 10) || 0;
            if (discount > 0 && !this.config.canDiscount) {
                this.editing.error = 'Akun Anda tidak punya izin memberi diskon.';
                return;
            }
            if (discount > Math.round(line.price * qty)) {
                this.editing.error = 'Diskon tidak boleh melebihi harga barang.';
                return;
            }

            line.quantity = qty;
            line.discount = discount;
            line.note = this.editing.note.trim().slice(0, 150);
            closeModal('pos-line');
        },

        removeEditing() {
            const line = this.cart.items.find((l) => l.key === this.editing.key);
            if (line) {
                this.remove(line);
            }
            closeModal('pos-line');
        },

        openDiscount() {
            if (!this.config.canDiscount) {
                notify('Akun Anda tidak punya izin memberi diskon.', 'error');
                return;
            }
            this.discountForm = {
                type: this.cart.discountType || 'amount',
                value: this.cart.discountValue ? String(this.cart.discountValue) : '',
                error: '',
            };
            openModal('pos-discount');
        },

        saveDiscount() {
            const value = parseFloat(String(this.discountForm.value).replace(',', '.')) || 0;
            if (value < 0 || (this.discountForm.type === 'percent' && value > 100)) {
                this.discountForm.error = 'Diskon persen antara 0 sampai 100.';
                return;
            }
            if (this.discountForm.type === 'amount' && value > this.subtotal) {
                this.discountForm.error = 'Diskon tidak boleh melebihi subtotal.';
                return;
            }
            this.cart.discountType = value > 0 ? this.discountForm.type : null;
            this.cart.discountValue = value;
            closeModal('pos-discount');
        },

        removeDiscount() {
            this.cart.discountType = null;
            this.cart.discountValue = 0;
            closeModal('pos-discount');
        },

        // Sinkronisasi harga & stok dengan server

        async syncCart(silent = false) {
            const ids = [...new Set(this.cart.items.map((l) => l.product_id))];
            if (!ids.length) {
                return;
            }

            let fresh;
            try {
                fresh = await this.$wire.syncProducts(ids);
            } catch {
                return;
            }

            const removed = [];
            const repriced = [];
            this.cart.items = this.cart.items.filter((line) => {
                const product = fresh[line.product_id];
                if (!product) {
                    removed.push(line.name);
                    return false;
                }
                if (product.price !== line.price) {
                    repriced.push(product.name);
                    line.price = product.price;
                }
                line.name = product.name;
                line.stock = product.stock;
                line.track = product.track;
                return true;
            });

            if (removed.length) {
                notify(`Dikeluarkan dari keranjang (tidak dijual lagi): ${removed.join(', ')}.`, 'error');
            }
            if (repriced.length) {
                notify(`Harga diperbarui: ${repriced.join(', ')}.`, 'error');
            } else if (!silent && !removed.length) {
                notify('Harga dan stok keranjang sudah yang terbaru.');
            }
        },

        // Pencarian & scanner

        async lookup(code) {
            code = String(code || '').trim();
            if (!code) {
                return false;
            }
            const product = await this.$wire.findByCode(code);
            if (product) {
                this.add(product);
                notify(`${product.name} ditambahkan.`);
                return true;
            }
            return false;
        },

        async submitSearch(input) {
            const term = input.value.trim();
            if (!term) {
                return;
            }

            if (await this.lookup(term)) {
                input.value = '';
                this.$wire.set('search', '');
                return;
            }

            const tiles = this.$root.querySelectorAll('[data-product]');
            if (tiles.length === 1) {
                this.add(JSON.parse(tiles[0].dataset.product));
                input.value = '';
                this.$wire.set('search', '');
                return;
            }

            if (tiles.length === 0) {
                notify(`Produk "${term}" tidak ditemukan.`, 'error');
            }
        },

        focusSearch() {
            this.$refs.search?.focus();
            this.$refs.search?.select();
        },

        onKeydown(event) {
            const target = event.target;
            const typing = target.closest?.('input, textarea, select, [contenteditable="true"]');
            const modalOpen = document.body.classList.contains('overflow-y-hidden');

            if (event.key === 'F2' || (event.key === '/' && !typing && !modalOpen)) {
                event.preventDefault();
                this.focusSearch();
                return;
            }

            if ((event.key === 'F9' || (event.key === 'Enter' && (event.ctrlKey || event.metaKey))) && !modalOpen) {
                event.preventDefault();
                this.openPay();
                return;
            }

            if (typing || modalOpen || event.ctrlKey || event.metaKey || event.altKey) {
                return;
            }

            // Scanner barcode mengetik sangat cepat lalu Enter; ketikan manual jauh lebih lambat.
            const now = performance.now();
            if (event.key === 'Enter') {
                const code = this.scan.buffer;
                this.scan.buffer = '';
                if (code.length >= 3) {
                    event.preventDefault();
                    this.lookup(code).then((found) => found || notify(`Barcode ${code} tidak terdaftar.`, 'error'));
                }
                return;
            }

            if (event.key.length === 1) {
                if (now - this.scan.last > 60) {
                    this.scan.buffer = '';
                }
                this.scan.buffer += event.key;
                this.scan.last = now;
            }
        },

        async startCamera() {
            this.camera.error = '';
            openModal('pos-camera');

            try {
                this.camera.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
            } catch {
                this.camera.error = 'Kamera tidak bisa dibuka. Izinkan akses kamera di browser, atau pakai scanner/ketik kodenya.';
                return;
            }

            const video = this.$refs.cameraVideo;
            video.srcObject = this.camera.stream;
            await video.play().catch(() => {});
            this.camera.active = true;

            const detector = new window.BarcodeDetector();
            const tick = async () => {
                if (!this.camera.active) {
                    return;
                }
                if (!video.isConnected || video.offsetParent === null) {
                    this.stopCamera();
                    return;
                }
                try {
                    const codes = await detector.detect(video);
                    if (codes.length) {
                        const code = codes[0].rawValue;
                        this.stopCamera();
                        closeModal('pos-camera');
                        if (!(await this.lookup(code))) {
                            notify(`Barcode ${code} tidak terdaftar.`, 'error');
                        }
                        return;
                    }
                } catch {
                    // frame belum siap
                }
                setTimeout(tick, 200);
            };
            tick();
        },

        stopCamera() {
            this.camera.active = false;
            this.camera.stream?.getTracks().forEach((track) => track.stop());
            this.camera.stream = null;
        },

        // Pelanggan

        async openCustomers() {
            this.customerQuery = '';
            this.customerForm = { open: false, name: '', phone: '', error: '', saving: false };
            openModal('pos-customer');
            await this.searchCustomers();
        },

        async searchCustomers() {
            this.customerLoading = true;
            try {
                this.customerResults = await this.$wire.searchCustomers(this.customerQuery);
            } finally {
                this.customerLoading = false;
            }
        },

        selectCustomer(customer) {
            this.cart.customer = customer ? { id: customer.id, name: customer.name, phone: customer.phone, due: customer.due } : null;
            closeModal('pos-customer');
        },

        async createCustomer() {
            this.customerForm.saving = true;
            this.customerForm.error = '';
            try {
                const result = await this.$wire.quickAddCustomer(this.customerForm.name, this.customerForm.phone);
                if (!result.ok) {
                    this.customerForm.error = result.message;
                    return;
                }
                this.selectCustomer(result.customer);
                notify(`Pelanggan ${result.customer.name} ditambahkan.`);
            } finally {
                this.customerForm.saving = false;
            }
        },

        // Transaksi tertunda

        openHold() {
            if (!this.cart.items.length) {
                notify('Keranjang masih kosong.', 'error');
                return;
            }
            this.holdLabel = this.cart.customer?.name || '';
            openModal('pos-hold');
        },

        async holdCurrent(label = this.holdLabel) {
            this.holding = true;
            try {
                const result = await this.$wire.holdOrder({ ...this.cart, total: this.total }, label);
                if (!result.ok) {
                    notify(result.message, 'error');
                    return false;
                }
                this.cart = emptyCart();
                this.cartOpen = false;
                closeModal('pos-hold');
                notify('Transaksi ditunda. Buka lagi dari tombol Tertunda.');
                this.$wire.$refresh();
                return true;
            } finally {
                this.holding = false;
            }
        },

        async openHeld() {
            openModal('pos-held');
            this.heldLoading = true;
            try {
                this.heldOrders = await this.$wire.heldOrders();
            } finally {
                this.heldLoading = false;
            }
        },

        async resumeHeld(order) {
            if (this.cart.items.length && !(await this.holdCurrent(this.cart.customer?.name || ''))) {
                return;
            }
            const cart = await this.$wire.resumeHeldOrder(order.id);
            closeModal('pos-held');
            if (!cart) {
                notify('Transaksi tertunda ini sudah dibuka di perangkat lain.', 'error');
                this.$wire.$refresh();
                return;
            }
            this.cart = { ...emptyCart(), ...cart, uuid: uuid() };
            delete this.cart.total;
            this.$wire.$refresh();
            await this.syncCart(true);
            notify(`${order.label} dilanjutkan.`);
        },

        async deleteHeld(order) {
            await this.$wire.deleteHeldOrder(order.id);
            this.heldOrders = this.heldOrders.filter((o) => o.id !== order.id);
            this.$wire.$refresh();
        },

        // Pembayaran

        openPay() {
            if (!this.cart.items.length) {
                notify('Keranjang masih kosong.', 'error');
                return;
            }
            if (!this.config.hasShift) {
                openModal('open-shift');
                return;
            }
            this.pay = { method: 'cash', amount: '', reference: '', lines: [], credit: false, submitting: false, error: '', errorAction: null, result: null };
            openModal('pos-payment');
        },

        get payLinesTotal() {
            return this.pay ? this.pay.lines.reduce((sum, line) => sum + line.amount, 0) : 0;
        },

        get payCurrent() {
            return this.pay ? parseInt(String(this.pay.amount).replace(/\D/g, ''), 10) || 0 : 0;
        },

        get payRemaining() {
            return Math.max(0, this.total - this.payLinesTotal);
        },

        get payNonCash() {
            if (!this.pay) {
                return 0;
            }
            const lines = this.pay.lines.filter((l) => l.method !== 'cash').reduce((sum, l) => sum + l.amount, 0);
            return lines + (this.pay.method !== 'cash' ? this.payCurrent : 0);
        },

        get payTotal() {
            return this.payLinesTotal + this.payCurrent;
        },

        get payChange() {
            return this.payNonCash > this.total ? 0 : Math.max(0, this.payTotal - this.total);
        },

        get payShortfall() {
            return Math.max(0, this.total - this.payTotal);
        },

        get payProblem() {
            if (!this.pay) {
                return '';
            }
            if (this.payNonCash > this.total) {
                return 'Pembayaran non-tunai melebihi total. Non-tunai tidak punya kembalian.';
            }
            if (this.payShortfall > 0 && !this.pay.credit) {
                return `Kurang ${rupiah(this.payShortfall)}`;
            }
            if (this.pay.credit && !this.cart.customer) {
                return 'Pilih pelanggan untuk mencatat kasbon.';
            }
            return '';
        },

        get cashSuggestions() {
            const due = this.payRemaining;
            if (due <= 0) {
                return [];
            }
            const values = new Set([due]);
            [5000, 10000, 20000, 50000, 100000].forEach((step) => {
                const next = Math.ceil(due / step) * step;
                if (next > due) {
                    values.add(next);
                }
            });
            this.config.quickCash.forEach((value) => value > due && values.add(value));
            return [...values].sort((a, b) => a - b).slice(0, 6);
        },

        selectMethod(method) {
            this.pay.method = method;
            this.pay.error = '';
            this.pay.amount = method === 'cash' ? '' : String(this.payRemaining);
            if (method === 'cash') {
                this.pay.reference = '';
            }
        },

        keypad(key) {
            let value = String(this.pay.amount).replace(/\D/g, '');
            if (key === 'back') {
                value = value.slice(0, -1);
            } else if (key === 'clear') {
                value = '';
            } else {
                value = (value + key).replace(/^0+(?=\d)/, '').slice(0, 12);
            }
            this.pay.amount = value;
            this.pay.error = '';
        },

        setAmount(value) {
            this.pay.amount = String(value);
            this.pay.error = '';
        },

        addSplit() {
            const amount = this.payCurrent;
            if (amount <= 0) {
                this.pay.error = 'Isi nominal untuk metode ini dulu.';
                return;
            }
            if (amount >= this.payRemaining) {
                this.pay.error = 'Nominal sudah menutup total; langsung tekan Bayar.';
                return;
            }
            this.pay.lines.push({ method: this.pay.method, amount, reference: this.pay.reference.trim() });
            this.pay.amount = '';
            this.pay.reference = '';
            this.pay.method = 'cash';
            this.pay.error = '';
        },

        removeSplit(index) {
            this.pay.lines.splice(index, 1);
        },

        methodLabel(value) {
            return this.config.methods.find((m) => m.value === value)?.label ?? value;
        },

        toggleCredit() {
            if (!this.config.allowCredit) {
                return;
            }
            this.pay.credit = !this.pay.credit;
            if (this.pay.credit && !this.cart.customer) {
                this.openCustomers();
            }
        },

        async submitPayment() {
            if (!this.pay || this.pay.submitting || this.pay.result) {
                return;
            }
            if (this.payProblem) {
                this.pay.error = this.payProblem;
                return;
            }

            const payments = this.pay.lines.map((l) => ({ method: l.method, amount: l.amount, reference: l.reference || null }));
            if (this.payCurrent > 0) {
                payments.push({ method: this.pay.method, amount: this.payCurrent, reference: this.pay.reference.trim() || null });
            }

            const payload = {
                client_uuid: this.cart.uuid,
                customer_id: this.cart.customer?.id ?? null,
                items: this.cart.items.map((l) => ({ product_id: l.product_id, quantity: l.quantity, price: l.price, discount: l.discount || 0, note: l.note || null })),
                discount_type: this.cart.discountType,
                discount_value: this.cart.discountValue || 0,
                payments,
                note: this.cart.note || null,
                expected_total: this.total,
            };

            this.pay.submitting = true;
            this.pay.error = '';
            this.pay.errorAction = null;

            let response;
            let data = null;
            try {
                const controller = new AbortController();
                const timer = setTimeout(() => controller.abort(), 25000);
                response = await fetch(this.config.checkoutUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    },
                    body: JSON.stringify(payload),
                    signal: controller.signal,
                });
                clearTimeout(timer);
                data = await response.json().catch(() => null);
            } catch {
                this.pay.submitting = false;
                this.pay.error = 'Koneksi ke server terputus. Periksa jaringan lalu tekan Bayar lagi; transaksi tidak akan tercatat dua kali.';
                return;
            }

            this.pay.submitting = false;

            if (response.ok && data?.ok) {
                this.pay.result = data.sale;
                notify(`Transaksi ${data.sale.number} tersimpan.`);
                this.cart = emptyCart();
                this.cartOpen = false;
                this.$wire.$refresh();
                if (this.config.autoPrint && !window.matchMedia('(pointer: coarse)').matches) {
                    this.printReceipt();
                }
                return;
            }

            if (response.status === 419) {
                this.pay.error = 'Sesi login habis. Muat ulang halaman; keranjang tetap tersimpan di perangkat ini.';
                this.pay.errorAction = 'reload';
                return;
            }

            if (response.status === 401) {
                this.pay.error = 'Anda sudah keluar. Login lagi; keranjang tetap tersimpan di perangkat ini.';
                this.pay.errorAction = 'reload';
                return;
            }

            if (response.status === 429) {
                this.pay.error = 'Terlalu banyak percobaan. Tunggu sebentar lalu coba lagi.';
                return;
            }

            if (response.status !== 422 || !data) {
                this.pay.error = data?.message && response.status < 500 ? data.message : 'Server sedang bermasalah. Coba lagi sebentar; transaksi tidak akan tercatat dua kali.';
                return;
            }

            this.pay.error = data.message;
            this.applyRejection(data.reason, data.context || {});
        },

        applyRejection(reason, context) {
            if (reason === 'price_changed') {
                Object.entries(context.prices || {}).forEach(([id, info]) => {
                    this.cart.items.filter((l) => l.product_id === Number(id)).forEach((l) => (l.price = info.price));
                });
                this.pay.lines = [];
                this.pay.amount = '';
            } else if (reason === 'unavailable') {
                const ids = (context.product_ids || []).map(Number);
                this.cart.items = this.cart.items.filter((l) => !ids.includes(l.product_id));
                this.pay.lines = [];
                this.pay.amount = '';
                if (!this.cart.items.length) {
                    closeModal('pos-payment');
                }
            } else if (reason === 'insufficient_stock') {
                Object.entries(context.stock || {}).forEach(([id, stock]) => {
                    this.cart.items.filter((l) => l.product_id === Number(id)).forEach((l) => (l.stock = stock));
                });
            } else if (reason === 'no_shift') {
                this.config.hasShift = false;
                closeModal('pos-payment');
                this.$wire.$refresh();
                openModal('open-shift');
            } else if (reason === 'credit_needs_customer') {
                this.openCustomers();
            }
        },

        printReceipt() {
            const url = `${this.pay.result.receipt_url}?print=1`;
            if (window.matchMedia('(pointer: coarse)').matches) {
                window.open(url, '_blank');
                return;
            }
            document.getElementById('pos-print-frame')?.remove();
            const frame = document.createElement('iframe');
            frame.id = 'pos-print-frame';
            frame.style.cssText = 'position:fixed;width:0;height:0;border:0;right:0;bottom:0;';
            frame.src = `${this.pay.result.receipt_url}?embed=1`;
            frame.onload = () => {
                try {
                    frame.contentWindow.focus();
                    frame.contentWindow.print();
                } catch {
                    window.open(url, '_blank');
                }
            };
            document.body.appendChild(frame);
        },

        newTransaction() {
            this.pay = null;
            closeModal('pos-payment');
            this.$nextTick(() => {
                if (!window.matchMedia('(pointer: coarse)').matches) {
                    this.focusSearch();
                }
            });
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('posCashier', posCashier);
});
