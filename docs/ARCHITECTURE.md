# Arsitektur & Panduan Teknis Kasir Toko

Dokumentasi teknis mendalam mengenai arsitektur internal, isolasi multi-tenant, alur pembayaran langganan, keamanan, dan peta komponen sistem **Kasir Toko (Web POS)**.

---

## 1. Peta Arsitektur & Komponen

| Bagian | Lokasi Utama | Keterangan |
|---|---|---|
| Menu sidebar, flyout, bottom nav mobile, pencarian menu | `app/Support/Navigation.php` | Konfigurasi navigasi terpusat dengan filter izin Spatie |
| Daftar modul & sakelar fitur | `app/Support/Features.php` | Fitur modular per toko yang bisa diaktif/nonaktifkan |
| Peran & Izin bawaan | `database/seeders/PermissionSeeder.php` | Matriks izin Spatie per peran (superadmin, admin, kasir, staff) |
| Gate lintas modul | `app/Providers/AppServiceProvider.php` | Gate perizinan (`view-master-data`, `pos.discount`, dll.) |
| Plumbing CRUD Livewire | `app/Livewire/Concerns/WithCrudActions.php`, `WithDataTable.php` | Trait pencarian, sorting, paginasi, modal form, konfirmasi hapus, ekspor |
| Refresh realtime per komponen | `app/Livewire/Concerns/WithRealtimeRefresh.php` + `app/Events/Concerns/BroadcastsToDashboard.php` | Sinkronisasi via Laravel Reverb WebSocket per tenant |
| Audit trail | Trait `app/Models/Concerns/Auditable.php`, label di `app/Support/Audit/AuditTrail.php` | Pencatatan otomatis perubahan model bisnis |
| Pencarian global (⌘K / Ctrl+K) | `resources/views/livewire/layout/global-search.blade.php` | Live search produk, pelanggan, dan menu cepat |
| Generator OpenAPI | `app/Support/OpenApi/` | Atribut PHP 8 `#[ApiTag]`, `#[ApiQuery]`, `#[ApiResponse]` di controller |
| Nomor dokumen otomatis | `app/Services/DocumentNumberGenerator.php` | Penomoran transaksi/shift bebas race condition per toko |
| Checkout, pembatalan, pelunasan kasbon | `app/Services/Pos/SaleService.php` | Logic inti checkout POS, void transaksi, dan cicilan kasbon |
| Rumus kalkulasi keranjang | `app/Services/Pos/CartCalculator.php` & `resources/js/pos.js` | Rumus kalkulasi identik di PHP dan JavaScript (diskon, pajak, subtotal) |
| Shift kasir & mutasi stok | `app/Services/Pos/ShiftService.php`, `app/Services/Pos/StockService.php` | Rekap kas laci, kartu stok, dan pergerakan persediaan |
| Layar kasir POS | `app/Livewire/Pos/Cashier.php`, `resources/views/livewire/pos/` | Antarmuka kasir responsif untuk layar sentuh dan desktop |
| Pengaturan kasir | `app/Support/PosSettings.php` | Konfigurasi setting kasir berbasis prefix `pos.*` di tabel settings |
| Konversi QRIS statis ke dinamis | `app/Support/Qris.php` | Parsing string QRIS EMVCo, penambahan tag nominal 54, dan kalkulasi CRC16 |
| Layar pelanggan (Customer Display) | `app/Http/Controllers/CustomerDisplayController.php`, `resources/js/customer-display.js` | Layar hadap pembeli dengan pairing kode QR dan push WebSocket Reverb |
| Template cetak struk thermal | `resources/views/print/receipt.blade.php` | Format cetak struk 58mm/80mm ESC/POS kompatibel |
| Import massal produk | `app/Services/ProductImportService.php` | Parsing spreadsheet Excel/CSV, auto-kategori, SKU generator, proteksi batas paket |
| Integrasi SumoPod QRIS | `app/Services/SumoPodPaymentService.php`, `app/Http/Controllers/Api/SumoPodWebhookController.php` | Otomasi tagihan dan webhook pembayaran langganan SaaS |
| Scheduler pengingat masa aktif | `app/Console/Commands/CheckSubscriptionExpirations.php` | Pengingat email H-7, H-3, H-1, H-0 otomatis setiap pagi |

---

## 2. Arsitektur Multi-Tenant (SaaS)

Kasir Toko menggunakan pendekatan **Single Database, Shared Schema with Tenant Column Isolation**. Semua entitas bisnis terhubung dengan kolom `tenant_id`.

```
                  ┌──────────────────────────────┐
                  │       Incoming Request       │
                  └──────────────┬───────────────┘
                                 │
                   [ IdentifyTenant Middleware ]
                                 │
       ┌─────────────────────────┴─────────────────────────┐
       ▼                                                   ▼
┌──────────────────────────────┐        ┌──────────────────────────────────┐
│ Web Session / Sanctum Token  │        │   Unauthenticated / Guest Route  │
└──────────────┬───────────────┘        └──────────────────┬───────────────┘
               │                                           │
  CurrentTenant::set($tenant)                              │
  TenantScope::apply() active                              │
               │                                           │
               ▼                                           ▼
┌──────────────────────────────┐        ┌──────────────────────────────────┐
│ Query scoped to `tenant_id`  │        │ Query unscoped (Platform Panel / │
│ Cross-tenant access: 404     │        │ Landing / Public Pages)          │
└──────────────────────────────┘        └──────────────────────────────────┘
```

### Mekanisme Isolasi Data

| Komponen | Implementasi | Perilaku & Catatan |
|---|---|---|
| **Tenant Aktif** | `app/Support/CurrentTenant.php` | Diset oleh middleware `IdentifyTenant` dari user terautentikasi sebelum route model binding dijalankan (`prependToPriorityList` di `bootstrap/app.php`). Mengakses ID milik toko lain otomatis menghasilkan response `404 Not Found`. |
| **Global Scope** | Trait `BelongsToTenant` + `app/Models/Scopes/TenantScope.php` | Semua query Eloquent otomatis difilter `WHERE tenant_id = ?` dan `tenant_id` otomatis diisikan saat model dibuat. |
| **Validasi Form** | `app/Support/TenantRule.php` | Validasi `unique` atau `exists` wajib menggunakan helper ini karena `Rule::unique()` bawaan Laravel mengabaikan global scope. |
| **Peran & Izin** | Spatie Teams (`config/permission.php`) | Menggunakan kolom `tenant_id` sebagai team ID. Model `Role` tidak menggunakan global scope karena izin Spatie dicache global; pemfilteran peran dilakukan manual pada query tampilan. |
| **Pengaturan Toko** | `app/Models/Setting.php` | Baris dengan `tenant_id` kosong berfungsi sebagai nilai bawaan sistem (platform default), yang ditimpa oleh konfigurasi khusus masing-masing toko. Cache disimpan per tenant. |
| **Penyimpanan Berkas** | `CurrentTenant::storagePath()` | Logo toko, foto produk, dan banner disimpan terisolasi di direktori `storage/app/public/tenants/{tenant_id}/`. |
| **Broadcasting Realtime** | Channel `tenant.{id}.*` | Event Reverb dipancarkan secara privat per channel tenant agar data transaksi tidak bocor antartoko. |

> [!WARNING]
> Query yang menggunakan `DB::table(...)` atau raw SQL **tidak melewati Eloquent Global Scope**. Pengembang wajib menambahkan klausa `->where('tenant_id', CurrentTenant::id())` secara eksplisit jika menggunakan query builder mentah.

---

## 3. Alur Siklus Toko & Onboarding

### Siklus Toko
1. **Pendaftaran (`/daftar` atau `POST /api/v1/auth/register`)**:
   Membuat entitas tenant baru, menyalin peran bawaan (superadmin, admin, kasir, staff), menetapkan akun pemilik pertama, dan memberikan masa uji coba (bawaan: 14 hari).
2. **Onboarding Wizard (`/persiapan-toko`)**:
   Pemilik toko memilih salah satu dari 10 preset jenis toko (Warung, Kafe, Restoran, Fashion, Toko Bangunan, Minimarket, Konter HP/Pulsa, Apotek, Bakery, atau Toko Umum). Wizard membuatkan kategori awal, produk sampel opsional, dan pengaturan kasir khusus industri.
3. **Pemberlakuan Batas Paket**:
   Middleware `EnsureProTenant` (`pro`) membatasi menu eksklusif (Piutang, Ekspor Data, Laporan Lanjutan, Layar Pelanggan) hanya untuk paket Pro dan Lifetime. Toko pada paket Free tetap dapat beroperasi tanpa batas waktu dengan batas jumlah produk dan staf.
4. **Masa Tenggang & Pemblokiran**:
   Jika masa uji coba atau langganan Pro habis, toko masuk ke masa tenggang (*grace period*). Jika tetap tidak diperpanjang, akses web dialihkan ke `/langganan` dan API mengembalikan response `402 Payment Required` dengan alasan serta instruksi perpanjangan.

---

## 4. Integrasi Pembayaran Langganan SumoPod (QRIS Dinamis)

Kasir Toko terintegrasi dengan Payment Gateway SumoPod untuk perpanjangan paket SaaS otomatis:

```
[ Pemilik Toko ]
      │ Pilih Paket (Bulanan/Tahunan/Lifetime)
      ▼
[ Checkout SumoPod ] ──── Request QRIS ───► [ SumoPod API ]
      │                                          │ Mengembalikan QRIS Dinamis
      │◄── Tampilkan QRIS & Status Pending ──────┘
      │
[ Pembayaran via Mobile Banking / E-Wallet ]
      │
      ▼
[ SumoPod Server ] ──── Webhook Notification ───► [ Kasir Toko Webhook Endpoint ]
                                                          │
                                                    1. Validasi Svix Signature
                                                    2. Anti Replay Attack (5m)
                                                    3. Verifikasi Nominal
                                                    4. Perpanjang Masa Aktif
                                                    5. Kirim Notifikasi Realtime
```

- **Keamanan Webhook**:
  - Menggunakan standar Svix HMAC-SHA256 (`webhook-id`, `webhook-timestamp`, `webhook-signature`).
  - Verifikasi toleransi timestamp maksimum 5 menit untuk menangkal *replay attacks*.
  - Rate limiting 60 request/menit per endpoint.
- **Akumulasi Masa Aktif**:
  Upgrade paket dari masa trial yang belum habis otomatis mengonversi sisa hari trial menjadi bonus perpanjangan masa aktif Pro baru.

---

## 5. Keamanan & Hardening Production

| Layer | Konfigurasi | Penjelasan |
|---|---|---|
| **Content Security Policy (CSP)** | `App\Http\Middleware\SecurityHeaders` | Membatasi eksekusi skrip pihak ketiga. Mengizinkan Alpine.js (`'unsafe-eval'`) dan Livewire navigasi tanpa nonce dinamis. |
| **Anti-Bot & Brute Force** | Cloudflare Turnstile & Rate Limiting | Melindungi pendaftaran publik di `/daftar`. Endpoint login memiliki cooldown OTP dan pembatasan percobaan per IP. |
| **Sanctum Token Lifetime** | `SANCTUM_IDLE_DAYS` (default 30 hari) | Token API mobile yang tidak aktif selama periode tertentu otomatis dinonaktifkan dan dihapus oleh background scheduler. |
| **Isolasi Reverse Proxy** | `trustProxies('*')` | Terkonfigurasi untuk integrasi Cloudflare. Pastikan port server backend hanya dapat diakses oleh IP range Cloudflare melalui firewall UFW / Security Group. |

---

## 6. Integrasi Mobile & Ekosistem API

- Endpoint RESTful API v1 terdokumentasi lengkap dan dapat diekspor ke spesifikasi OpenAPI via perintah:
  ```bash
  php artisan api:docs --output=storage/app/openapi.json
  ```
- Aplikasi mobile Flutter ([`kasir-toko-mobile`](https://github.com/rizalahmaddd/kasir-toko-mobile)) memanfaatkan:
  - Header `Authorization: Bearer <sanctum_token>`
  - Fallback offline dengan caching katalog lokal
  - Pencetakan struk langsung ke printer Bluetooth ESC/POS 58mm/80mm
  - Social login menggunakan Google ID Token & Apple Identity Token yang diverifikasi di backend.
