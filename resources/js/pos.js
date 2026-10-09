/**
 * Keranjang layar kasir (resources/views/livewire/pos/cashier.blade.php).
 *
 * Keranjang hidup di browser supaya tombol +/- instan dan isinya tidak hilang saat halaman
 * termuat ulang atau koneksi putus. Rumus total ada di cart-math.js (sama dengan CartCalculator di server);
 * server menolak checkout kalau totalnya berbeda.
 */

import { calculate, lineSignature, nearExpiryDiscount, tierPrice } from './cart-math';

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

function emptyCart(orderType = null) {
    return { uuid: uuid(), items: [], customer: null, discountType: null, discountValue: 0, note: '', prescription: null, prescriptionDraft: null, orderType, table: '', kitchenSent: {}, customerOrder: null };
}

function modifiersTotal(line) {
    return (line.modifiers || []).reduce((sum, m) => sum + (Number(m.price) || 0), 0);
}

function modifierKey(modifiers) {
    return (modifiers || []).map((m) => Number(m.id)).sort((a, b) => a - b).join('-');
}

// Jumlah dalam satuan dasar produk; stok selalu dihitung dalam satuan ini.
function baseQuantity(line) {
    return Number(line.quantity) * (Number(line.factor) || 1);
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
        cart: emptyCart(config.orderType?.enabled ? 'dine_in' : null),
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

        modPick: null,
        variantPick: null,
        serialPick: null,

        rx: { query: '', results: [], loading: false, tab: 'saved', draft: { doctor_name: '', patient_name: '', patient_age: '', doctor_sip: '', clinic_name: '' }, error: '' },

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

            if (this.config.orderToLoad) {
                this.loadOrder(this.config.orderToLoad);
            } else if (this.cart.items.length) {
                this.syncCart(true);
            }
        },

        newCart() {
            return emptyCart(this.config.orderType?.enabled ? 'dine_in' : null);
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
                note: [this.modifierNames(line), line.note].filter(Boolean).join(' · ') || null,
            }));
            const base = {
                customer: this.cart.customer?.name ?? null,
                items,
                count: this.itemCount,
                lines: items.length,
                subtotal: this.subtotal,
                discount: this.discountAmount,
                service: this.serviceAmount,
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
            const outletId = this.config.outletId || 0;
            return `pos-cart:${this.config.userId}:${outletId}`;
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
                let saved = JSON.parse(localStorage.getItem(this.storageKey()) || 'null');
                if (!saved) {
                    const legacyKey = `pos-cart:${this.config.userId}`;
                    const legacy = JSON.parse(localStorage.getItem(legacyKey) || 'null');
                    if (legacy && Array.isArray(legacy.items) && legacy.items.length) {
                        saved = legacy;
                        localStorage.removeItem(legacyKey);
                    }
                }
                if (saved && Array.isArray(saved.items)) {
                    this.cart = { ...this.newCart(), ...saved, uuid: saved.uuid || uuid() };
                }
            } catch {
                this.cart = this.newCart();
            }
        },

        // Perhitungan (cart-math.js, sama dengan CartCalculator)

        // Harga satuan efektif: satuan jual memakai harganya sendiri, satuan dasar bisa turun ke harga grosir.
        linePrice(line) {
            if (line.unit_id || !line.tiers?.length) {
                return line.price;
            }
            const qty = this.cart.items.filter((l) => l.product_id === line.product_id && !l.unit_id).reduce((sum, l) => sum + Number(l.quantity), 0);
            return tierPrice(line.price, line.tiers, qty);
        },

        // Potongan ED dekat per baris (key => rupiah): unit dari batch hampir kedaluwarsa dibagi berurutan antarbaris produk yang sama.
        get autoDiscounts() {
            const percent = this.config.nearExpiryPercent || 0;
            const left = {};
            const result = {};
            for (const line of this.cart.items) {
                if (line.unit_id || !(line.near_expiry > 0)) {
                    result[line.key] = 0;
                    continue;
                }
                left[line.product_id] ??= Number(line.near_expiry);
                result[line.key] = nearExpiryDiscount(this.linePrice(line), line.quantity, left[line.product_id], percent);
                left[line.product_id] = Math.max(0, Math.round((left[line.product_id] - Number(line.quantity)) * 1000) / 1000);
            }
            return result;
        },

        get totals() {
            const auto = this.autoDiscounts;
            const lines = this.cart.items.map((line) => ({ price: this.linePrice(line), modifiers: modifiersTotal(line), quantity: line.quantity, discount: (line.discount || 0) + (auto[line.key] || 0) }));
            return calculate(lines, this.cart.discountType, this.cart.discountValue, this.config.taxRate, this.serviceRate);
        },

        get serviceRate() {
            const service = this.config.serviceCharge;
            if (!service || service.rate <= 0) {
                return 0;
            }
            return service.dineInOnly && this.cart.orderType !== 'dine_in' ? 0 : service.rate;
        },

        lineGross(line) {
            return Math.round((this.linePrice(line) + modifiersTotal(line)) * line.quantity);
        },

        lineDiscount(line) {
            return Math.max(0, Math.min(Math.round((line.discount || 0) + (this.autoDiscounts[line.key] || 0)), this.lineGross(line)));
        },

        lineTotal(line) {
            return this.lineGross(line) - this.lineDiscount(line);
        },

        lineTiered(line) {
            return !line.unit_id && this.linePrice(line) < line.price;
        },

        modifierNames(line) {
            return (line.modifiers || []).map((m) => m.name).join(', ');
        },

        get subtotal() {
            return this.totals.subtotal;
        },

        get discountAmount() {
            return this.totals.discountAmount;
        },

        get serviceAmount() {
            return this.totals.serviceAmount;
        },

        get taxAmount() {
            return this.totals.taxAmount;
        },

        get total() {
            return this.totals.total;
        },

        // Yang masih harus dibayar di kasir: total dikurangi uang muka pesanan yang sedang dilunasi.
        get amountDue() {
            return Math.max(0, this.total - (this.cart.customerOrder?.deposit || 0));
        },

        get itemCount() {
            return this.cart.items.reduce((sum, line) => sum + Number(line.quantity), 0);
        },

        qtyInCart(productId) {
            return this.cart.items.filter((line) => line.product_id === productId).reduce((sum, line) => sum + Number(line.quantity), 0);
        },

        get needsPrescription() {
            return this.config.prescription?.enabled && this.cart.items.some((line) => line.rx);
        },

        rupiah,
        quantity,

        // Keranjang

        stockAllows(line, newQuantity, product = null, factor = null) {
            const track = product ? product.track : line.track;
            const stock = product ? product.stock : line.stock;
            if (!track || this.config.allowNegative || stock === null) {
                return true;
            }
            const productId = product ? product.id : line.product_id;
            const others = this.cart.items.filter((l) => l.product_id === productId && l !== line).reduce((sum, l) => sum + baseQuantity(l), 0);
            if (others + newQuantity * (factor ?? (Number(line?.factor) || 1)) <= stock + 0.0001) {
                return true;
            }
            const name = product ? product.name : line.name;
            notify(stock <= 0 ? `Stok ${name} habis.` : `Stok ${name} tinggal ${quantity(stock)}.`, 'error');
            return false;
        },

        unitOf(product, unitId) {
            return (product.units || []).find((u) => u.id === unitId) ?? null;
        },

        // Produk dengan grup pilihan tambahan membuka pemilih dulu; add() dipanggil lagi dengan pilihannya.
        add(product, unitId = undefined, modifiers = null, serials = null) {
            if (this.config.variants && product.variants?.length) {
                this.variantPick = { product };
                openModal('pos-variants');
                return;
            }
            if (this.config.serials && product.serial && serials === null) {
                this.openSerialPicker(product, unitId, product.serial_code ? [product.serial_code] : []);
                return;
            }
            const groups = this.config.modifiers ? product.modifier_groups || [] : [];
            if (groups.length && modifiers === null) {
                this.openModifierPicker(product, unitId);
                return;
            }
            modifiers = modifiers || [];
            const units = this.config.multiUnit ? product.units || [] : [];
            const unit = unitId !== undefined ? this.unitOf(product, unitId ?? product.unit_id) : (units.find((u) => u.default) ?? this.unitOf(product, product.unit_id));
            const factor = unit ? unit.factor : 1;
            const key = modifierKey(modifiers);
            const line = serials ? null : this.cart.items.find((l) => l.product_id === product.id && (l.unit_id ?? null) === (unit?.id ?? null) && modifierKey(l.modifiers) === key && !l.note && !l.discount);
            const current = line ? Number(line.quantity) : 0;
            const added = serials ? serials.length : 1;

            if (!this.stockAllows(line, current + added, product, factor)) {
                return;
            }

            if (this.config.allowNegative && product.track && (product.stock === null || product.stock <= 0 || (current + 1) * factor > product.stock)) {
                const curStock = product.stock !== null ? quantity(product.stock) : '0';
                notify(`Stok ${product.name} habis/minus (${curStock}). Tetap ditambahkan ke keranjang.`, 'warning');
            }

            if (line) {
                line.quantity = current + 1;
                line.price = unit ? unit.price : product.price;
                line.tiers = this.config.tieredPrice ? product.tiers || [] : [];
                line.near_expiry = product.near_expiry || 0;
                line.stock = product.stock;
            } else {
                this.cart.items.push({
                    key: uuid(),
                    product_id: product.id,
                    name: product.name,
                    sku: product.sku,
                    unit: unit ? unit.name : product.unit,
                    base_unit: product.unit,
                    base_price: product.price,
                    unit_id: unit?.id ?? null,
                    factor,
                    units,
                    price: unit ? unit.price : product.price,
                    quantity: added,
                    discount: 0,
                    note: '',
                    serial: !!product.serial,
                    serials: serials || [],
                    track: product.track,
                    stock: product.stock,
                    rx: !!product.rx,
                    tiers: this.config.tieredPrice ? product.tiers || [] : [],
                    near_expiry: product.near_expiry || 0,
                    modifiers,
                    modifier_groups: groups,
                });
            }

            navigator.vibrate?.(12);
        },

        pickVariant(child) {
            closeModal('pos-variants');
            this.variantPick = null;
            this.add(child);
        },

        async openSerialPicker(product, unitId = undefined, preset = [], line = null) {
            this.serialPick = { product, unitId, lineKey: line?.key ?? null, options: [], selected: [...(line?.serials || preset)], manual: '', loading: true, error: '' };
            openModal('pos-serials');
            try {
                const taken = this.cart.items.filter((l) => l.product_id === product.id && l.key !== line?.key).flatMap((l) => l.serials || []);
                const options = await this.$wire.availableSerials(product.id ?? product.product_id, '');
                this.serialPick.options = options.filter((s) => !taken.includes(s));
            } finally {
                if (this.serialPick) {
                    this.serialPick.loading = false;
                }
            }
        },

        toggleSerial(serial) {
            const list = this.serialPick.selected;
            const index = list.indexOf(serial);
            index >= 0 ? list.splice(index, 1) : list.push(serial);
        },

        addManualSerial() {
            const value = this.serialPick.manual.trim().toUpperCase();
            if (value && !this.serialPick.selected.includes(value)) {
                this.serialPick.selected.push(value);
            }
            this.serialPick.manual = '';
        },

        confirmSerials() {
            const pick = this.serialPick;
            if (!pick.selected.length) {
                pick.error = 'Pilih atau scan minimal satu nomor seri.';
                return;
            }
            closeModal('pos-serials');
            if (pick.lineKey) {
                const line = this.cart.items.find((l) => l.key === pick.lineKey);
                if (line && this.stockAllows(line, pick.selected.length)) {
                    line.serials = [...pick.selected];
                    line.quantity = pick.selected.length;
                }
            } else {
                this.add(pick.product, pick.unitId, null, [...pick.selected]);
            }
            this.serialPick = null;
        },

        editSerials(line) {
            closeModal('pos-line');
            this.openSerialPicker({ id: line.product_id, name: line.name }, line.unit_id ?? undefined, [], line);
        },

        async loadOrder(orderId) {
            const order = await this.$wire.orderCart(orderId);
            if (!order) {
                notify('Pesanan tidak ditemukan atau sudah selesai.', 'error');
                return;
            }
            if (this.cart.items.length && !this.cart.customerOrder) {
                await this.holdCurrent(this.cart.customer?.name || 'Sebelum pelunasan');
            }
            this.cart = this.newCart();
            for (const item of order.items) {
                if (!item.product) {
                    continue;
                }
                this.cart.items.push({
                    key: uuid(), product_id: item.product.id, name: item.product.name, sku: item.product.sku, unit: item.product.unit, base_unit: item.product.unit,
                    base_price: item.product.price, unit_id: null, factor: 1, units: [], price: item.product.price, quantity: item.quantity, discount: 0, note: item.note || '',
                    track: item.product.track, stock: item.product.stock, rx: !!item.product.rx, tiers: item.product.tiers || [], modifiers: [], modifier_groups: [],
                    serial: !!item.product.serial, serials: [],
                });
            }
            this.cart.customer = order.customer ? { id: order.customer.id, name: order.customer.name } : null;
            this.cart.customerOrder = { id: order.customer_order_id, number: order.number, deposit: order.deposit };
            window.history.replaceState({}, '', window.location.pathname);
            if (order.skipped.length) {
                notify(`Tidak dimuat (bukan produk aktif): ${order.skipped.join(', ')}. Tambahkan manual bila perlu.`, 'error');
            } else {
                notify(`Pesanan ${order.number} dimuat. DP ${rupiah(order.deposit)} dipotong dari total.`);
            }
        },

        clearCustomerOrder() {
            this.cart.customerOrder = null;
        },

        openModifierPicker(product, unitId = undefined, line = null) {
            const groups = product.modifier_groups || [];
            const selected = Object.fromEntries(groups.map((g) => [g.id, []]));
            if (line) {
                (line.modifiers || []).forEach((m) => {
                    const group = groups.find((g) => g.modifiers.some((x) => x.id === m.id));
                    if (group) {
                        selected[group.id].push(m.id);
                    }
                });
            } else {
                groups.filter((g) => g.min >= 1 && g.max === 1 && g.modifiers.length).forEach((g) => (selected[g.id] = [g.modifiers[0].id]));
            }
            this.modPick = { product, unitId, lineKey: line?.key ?? null, groups, selected, error: '' };
            openModal('pos-modifiers');
        },

        isModifierPicked(group, modifier) {
            return this.modPick?.selected[group.id]?.includes(modifier.id) ?? false;
        },

        toggleModifier(group, modifier) {
            const list = this.modPick.selected[group.id];
            const index = list.indexOf(modifier.id);
            if (index >= 0) {
                list.splice(index, 1);
            } else if (group.max === 1) {
                this.modPick.selected[group.id] = [modifier.id];
            } else if (group.max === null || list.length < group.max) {
                list.push(modifier.id);
            } else {
                this.modPick.error = `${group.name}: ${group.rule.toLowerCase()}.`;
                return;
            }
            this.modPick.error = '';
        },

        get modPickExtra() {
            if (!this.modPick) {
                return 0;
            }
            return this.modPick.groups.reduce((sum, g) => sum + g.modifiers.filter((m) => this.modPick.selected[g.id].includes(m.id)).reduce((s, m) => s + m.price, 0), 0);
        },

        confirmModifiers() {
            const pick = this.modPick;
            for (const group of pick.groups) {
                const count = pick.selected[group.id].length;
                if (count < group.min || (group.max !== null && count > group.max)) {
                    pick.error = `Pilih ${group.name} (${group.rule.toLowerCase()}).`;
                    return;
                }
            }
            const modifiers = pick.groups.flatMap((g) => g.modifiers.filter((m) => pick.selected[g.id].includes(m.id)).map((m) => ({ id: m.id, name: m.name, price: m.price, group: g.name })));
            closeModal('pos-modifiers');
            if (pick.lineKey) {
                const line = this.cart.items.find((l) => l.key === pick.lineKey);
                if (line) {
                    line.modifiers = modifiers;
                }
            } else {
                this.add(pick.product, pick.unitId, modifiers);
            }
            this.modPick = null;
        },

        editModifiers() {
            const line = this.cart.items.find((l) => l.key === this.editing?.key);
            closeModal('pos-line');
            if (line) {
                this.openModifierPicker({ id: line.product_id, name: line.name, price: line.price, modifier_groups: line.modifier_groups || [] }, line.unit_id ?? undefined, line);
            }
        },

        // Ganti satuan baris: harga & faktor ikut satuan baru, stok dicek ulang dalam satuan dasar.
        setLineUnit(line, unitId) {
            const unit = (line.units || []).find((u) => u.id === unitId) ?? null;
            line.unit_id = unit?.id ?? null;
            line.unit = unit ? unit.name : line.base_unit || line.unit;
            line.factor = unit ? unit.factor : 1;
            line.price = unit ? unit.price : line.base_price ?? line.price;
        },

        increment(line) {
            if (line.serial && this.config.serials) {
                this.editSerials(line);
                return;
            }
            const next = Number(line.quantity) + 1;
            if (this.stockAllows(line, next)) {
                if (this.config.allowNegative && line.track && (line.stock === null || line.stock <= 0 || next * (Number(line.factor) || 1) > line.stock)) {
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
            if (line.serial && line.serials?.length) {
                line.serials = line.serials.slice(0, next);
            }
            line.quantity = Math.round(next * 1000) / 1000;
        },

        remove(line) {
            this.cart.items = this.cart.items.filter((l) => l.key !== line.key);
        },

        clearCart() {
            this.cart = this.newCart();
            closeModal('pos-clear');
            this.cartOpen = false;
        },

        editLine(line) {
            this.editing = {
                key: line.key,
                name: line.name,
                unit: line.unit,
                unit_id: line.unit_id ?? null,
                units: line.units || [],
                base_unit: line.base_unit || line.unit,
                base_price: line.base_price ?? line.price,
                price: line.price,
                modifiers: this.modifierNames(line),
                hasModifierGroups: this.config.modifiers && (line.modifier_groups || []).length > 0,
                serial: !!line.serial && this.config.serials,
                serials: (line.serials || []).join(', '),
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
            const unit = this.editing.units.find((u) => u.id === this.editing.unit_id) ?? null;
            if (!this.stockAllows(line, qty, null, unit ? unit.factor : 1)) {
                this.editing.error = 'Jumlah melebihi stok yang tersedia.';
                return;
            }

            const discount = parseInt(String(this.editing.discount).replace(/\D/g, ''), 10) || 0;
            if (discount > 0 && !this.config.canDiscount) {
                this.editing.error = 'Akun Anda tidak punya izin memberi diskon.';
                return;
            }
            if (discount > Math.round(((unit ? unit.price : this.editing.base_price) + modifiersTotal(line)) * qty)) {
                this.editing.error = 'Diskon tidak boleh melebihi harga barang.';
                return;
            }

            if ((line.unit_id ?? null) !== (unit?.id ?? null)) {
                this.setLineUnit(line, unit?.id ?? null);
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
                line.units = this.config.multiUnit ? product.units || [] : [];
                line.tiers = this.config.tieredPrice ? product.tiers || [] : [];
                line.near_expiry = product.near_expiry || 0;
                line.modifier_groups = this.config.modifiers ? product.modifier_groups || [] : [];
                line.base_unit = product.unit;
                line.base_price = product.price;
                let unit = line.unit_id ? this.unitOf({ units: line.units }, line.unit_id) : null;
                if (line.unit_id && !unit) {
                    repriced.push(`${product.name} (satuan ${line.unit} dihapus)`);
                    this.setLineUnit(line, null);
                }
                const price = unit ? unit.price : product.price;
                if (price !== line.price) {
                    repriced.push(product.name);
                    line.price = price;
                }
                line.name = product.name;
                line.stock = product.stock;
                line.track = product.track;
                line.rx = !!product.rx;
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
            if (product?.blocked) {
                notify(product.blocked, 'error');
                return true;
            }
            if (product) {
                this.add(product, product.unit_id ?? undefined);
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
            this.cart.customer = customer ? { id: customer.id, name: customer.name, phone: customer.phone, due: customer.due, credit_limit: customer.credit_limit ?? null } : null;
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

        // Resep

        async openPrescription() {
            this.rx.error = '';
            this.rx.tab = this.config.prescription?.canView ? 'saved' : 'draft';
            openModal('pos-prescription');
            if (this.rx.tab === 'saved') {
                await this.searchPrescriptions();
            }
        },

        async searchPrescriptions() {
            this.rx.loading = true;
            try {
                this.rx.results = await this.$wire.searchPrescriptions(this.rx.query);
            } finally {
                this.rx.loading = false;
            }
        },

        selectPrescription(prescription) {
            if (this.config.prescription.mode === 'strict' && !prescription.verified) {
                this.rx.error = `Resep ${prescription.number} belum diverifikasi apoteker.`;
                return;
            }
            this.cart.prescription = { id: prescription.id, number: prescription.number, patient: prescription.patient };
            this.cart.prescriptionDraft = null;
            closeModal('pos-prescription');
        },

        saveDraftPrescription() {
            const draft = { ...this.rx.draft, doctor_name: this.rx.draft.doctor_name.trim(), patient_name: this.rx.draft.patient_name.trim() };
            if (!draft.doctor_name || !draft.patient_name) {
                this.rx.error = 'Isi nama dokter dan nama pasien.';
                return;
            }
            draft.patient_age = parseInt(draft.patient_age, 10) || null;
            this.cart.prescriptionDraft = draft;
            this.cart.prescription = null;
            closeModal('pos-prescription');
        },

        clearPrescription() {
            this.cart.prescription = null;
            this.cart.prescriptionDraft = null;
        },

        get canDraftPrescription() {
            return this.config.prescription?.mode === 'warn' || this.config.prescription?.canVerify;
        },

        // Transaksi tertunda

        openHold() {
            if (!this.cart.items.length) {
                notify('Keranjang masih kosong.', 'error');
                return;
            }
            this.holdLabel = this.cart.table ? `Meja ${this.cart.table}` : this.cart.customer?.name || '';
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
                this.cart = this.newCart();
                this.cartOpen = false;
                closeModal('pos-hold');
                notify(result.merged ? `Pesanan digabung ke open bill ${result.label}.` : 'Transaksi ditunda. Buka lagi dari tombol Tertunda.');
                if (result.ticket_url) {
                    this.printKitchenTicket(result.ticket_url);
                }
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
            this.cart = { ...this.newCart(), ...cart, uuid: uuid() };
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
            if (this.needsPrescription && !this.cart.prescription && !this.cart.prescriptionDraft) {
                notify('Keranjang berisi obat wajib resep. Tautkan resepnya dulu.', 'error');
                this.openPrescription();
                return;
            }
            const missingSerial = this.config.serials && this.cart.items.find((l) => l.serial && (l.serials || []).length !== Number(l.quantity));
            if (missingSerial) {
                notify(`Pilih nomor seri ${missingSerial.name} sebanyak jumlahnya.`, 'error');
                this.editSerials(missingSerial);
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
            return Math.max(0, this.amountDue - this.payLinesTotal);
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
            return this.payNonCash > this.amountDue ? 0 : Math.max(0, this.payTotal - this.amountDue);
        },

        get payShortfall() {
            return Math.max(0, this.amountDue - this.payTotal);
        },

        get payProblem() {
            if (!this.pay) {
                return '';
            }
            if (this.payNonCash > this.amountDue) {
                return 'Pembayaran non-tunai melebihi total. Non-tunai tidak punya kembalian.';
            }
            if (this.payShortfall > 0 && !this.pay.credit) {
                return `Kurang ${rupiah(this.payShortfall)}`;
            }
            if (this.pay.credit && !this.cart.customer) {
                return 'Pilih pelanggan untuk mencatat kasbon.';
            }
            const limit = this.cart.customer?.credit_limit;
            if (this.payShortfall > 0 && limit !== null && limit !== undefined && (this.cart.customer.due || 0) + this.payShortfall > limit) {
                return `Melewati batas kasbon ${this.cart.customer.name} (sisa ${rupiah(Math.max(0, limit - (this.cart.customer.due || 0)))}).`;
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
                items: this.cart.items.map((l) => ({
                    product_id: l.product_id,
                    unit_id: l.unit_id ?? null,
                    quantity: l.quantity,
                    price: this.linePrice(l),
                    discount: l.discount || 0,
                    auto_discount: this.autoDiscounts[l.key] || 0,
                    note: l.note || null,
                    modifiers: (l.modifiers || []).map((m) => ({ id: m.id, name: m.name, price: m.price })),
                    serials: l.serial ? l.serials || [] : undefined,
                })),
                customer_order_id: this.cart.customerOrder?.id ?? null,
                order_type: this.config.orderType?.enabled ? this.cart.orderType : null,
                table_label: this.config.orderType?.enabled && this.cart.orderType === 'dine_in' ? this.cart.table || null : null,
                kitchen_sent: this.cart.kitchenSent || {},
                prescription_id: this.cart.prescription?.id ?? null,
                prescription: !this.cart.prescription && this.cart.prescriptionDraft ? this.cart.prescriptionDraft : null,
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
                this.cart = this.newCart();
                this.cartOpen = false;
                this.$wire.$refresh();
                if (this.config.autoPrint && !window.matchMedia('(pointer: coarse)').matches) {
                    this.printReceipt();
                }
                (data.sale.kitchen_ticket_urls || []).forEach((url) => this.printKitchenTicket(url));
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
                    this.cart.items.filter((l) => l.product_id === Number(id) && !l.unit_id).forEach((l) => {
                        l.price = info.base_price ?? info.price;
                        l.tiers = info.tiers ?? l.tiers;
                    });
                });
                Object.entries(context.modifier_prices || {}).forEach(([id, info]) => {
                    this.cart.items.forEach((l) => (l.modifiers || []).filter((m) => m.id === Number(id)).forEach((m) => (m.price = info.price)));
                });
                Object.entries(context.unit_prices || {}).forEach(([id, info]) => {
                    this.cart.items.filter((l) => l.unit_id === Number(id)).forEach((l) => (l.price = info.price));
                });
                this.pay.lines = [];
                this.pay.amount = '';
            } else if (['serial_unavailable', 'serial_required'].includes(reason)) {
                const line = this.cart.items.find((l) => l.serial && (context.product_id ? l.product_id === context.product_id : (l.serials || []).some((s) => (context.serials || []).includes(s))));
                closeModal('pos-payment');
                if (line) {
                    line.serials = (line.serials || []).filter((s) => !(context.serials || []).includes(s));
                    this.editSerials(line);
                }
            } else if (reason === 'near_expiry_changed') {
                Object.entries(context.near_expiry || {}).forEach(([id, info]) => {
                    this.cart.items.filter((l) => l.product_id === Number(id)).forEach((l) => (l.near_expiry = info.quantity));
                });
                this.config.nearExpiryPercent = Object.values(context.near_expiry || {})[0]?.percent ?? this.config.nearExpiryPercent;
                this.pay.lines = [];
                this.pay.amount = '';
            } else if (reason === 'order_closed') {
                this.cart.customerOrder = null;
            } else if (reason === 'modifier_unavailable') {
                const ids = (context.modifier_ids || []).map(Number);
                this.cart.items.forEach((l) => (l.modifiers = (l.modifiers || []).filter((m) => !ids.includes(m.id))));
                this.pay.lines = [];
                this.pay.amount = '';
            } else if (reason === 'unit_unavailable') {
                const ids = (context.unit_ids || []).map(Number);
                this.cart.items.filter((l) => ids.includes(l.unit_id)).forEach((l) => this.setLineUnit(l, null));
                this.pay.lines = [];
                this.pay.amount = '';
            } else if (['prescription_required', 'prescription_unverified', 'prescription_exceeded', 'prescription_invalid'].includes(reason)) {
                if (reason === 'prescription_invalid') {
                    this.cart.prescription = null;
                }
                closeModal('pos-payment');
                this.openPrescription();
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
            this.printUrl(this.pay.result.receipt_url, 'pos-print-frame');
        },

        // Tiket dapur dicetak otomatis hanya bila cetak otomatis aktif; selain itu cukup diberi tahu.
        printKitchenTicket(url) {
            if (this.config.autoPrint && !window.matchMedia('(pointer: coarse)').matches) {
                setTimeout(() => this.printUrl(url, 'pos-kitchen-frame'), 600);
            } else {
                notify('Tiket dapur terkirim ke Layar Dapur.');
            }
        },

        printUrl(baseUrl, frameId) {
            const url = `${baseUrl}?print=1`;
            if (window.matchMedia('(pointer: coarse)').matches) {
                window.open(url, '_blank');
                return;
            }
            document.getElementById(frameId)?.remove();
            const frame = document.createElement('iframe');
            frame.id = frameId;
            frame.style.cssText = 'position:fixed;width:0;height:0;border:0;right:0;bottom:0;';
            frame.src = `${baseUrl}?embed=1`;
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
