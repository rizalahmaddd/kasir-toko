# Kasir Toko (Web POS)

Aplikasi kasir berbasis web untuk toko, dirancang untuk tablet dan ponsel tapi tetap nyaman di laptop. Dibangun di atas [JagoDev Laravel Starter](https://github.com/rizalahmaddd/jagodev-laravel-starter), jadi auth, peran & izin, audit trail, sakelar fitur, backup, dan tema gelap/terang sudah tersedia.

Aplikasi ini **multi-tenant (SaaS)**: banyak toko memakai satu instalasi dan satu database, masing-masing hanya melihat datanya sendiri. Toko baru bisa mendaftar sendiri, punya masa uji coba, dan dikelola dari panel Platform. Instalasi untuk satu toko saja tetap bisa: `app:install` membuat toko pertama tanpa batas waktu. Detailnya di bagian [Multi-tenant (SaaS)](#multi-tenant-saas).

## Fitur Utama

- **Dashboard**: penjualan hari ini dibanding kemarin, status shift aktif/belum dibuka, tombol langsung ke layar kasir, ringkasan transaksi, laba kotor, stok menipis, kasbon, dan grafik penjualan 7 hari. Kasir hanya melihat penjualannya sendiri.
- **Layar kasir** (`/kasir`): katalog produk dengan filter kategori, SKU dan sisa stok di tiap kartu (penanda **Habis** untuk stok kosong), pencarian nama/SKU, scan barcode lewat scanner USB/Bluetooth (tanpa perlu klik kolom pencarian) atau kamera ponsel (browser yang mendukung `BarcodeDetector`), keranjang dengan jumlah desimal untuk barang timbangan, catatan & diskon per barang, diskon transaksi (Rp/%), dan pajak opsional.
- **Pembayaran**: tunai (pilihan pecahan uang & kembalian), QRIS, transfer, kartu, bayar campuran (split), dan kasbon atas nama pelanggan. Papan angka di layar sentuh, pintasan keyboard di laptop (`F2` cari, `F9` bayar, `Enter` selesai).
- **Struk**: cetak thermal 58/80 mm lewat dialog cetak browser, kirim lewat WhatsApp, cetak ulang dari riwayat.
- **Transaksi tertunda**: simpan keranjang sementara lalu lanjutkan nanti.
- **Layar pelanggan** (customer display): layar kedua yang menghadap pembeli, bisa berupa jendela di monitor kedua atau tablet/HP lain yang dipasangkan dengan memindai QR (tanpa login). Menampilkan sambutan + slideshow promo + teks berjalan saat diam, daftar belanja & total saat transaksi, kembalian, dan layar terima kasih. Tema, teks, slideshow, dan durasi diatur di Pengaturan → Layar Pelanggan.
- **QRIS bernominal**: unggah gambar QRIS statis toko di Pengaturan Kasir; saat bayar QRIS aplikasi membuat QR dinamis berisi nominal tagihan (tag 54, CRC dihitung ulang) dan menampilkannya di layar pelanggan dan modal bayar. QRIS statis tidak mengirim notifikasi pembayaran, jadi kasir tetap menekan **Pembayaran QRIS Diterima** setelah uang masuk terlihat di aplikasi merchant.
- **Shift kasir**: modal awal, kas masuk/keluar, rekap uang laci, tutup shift dengan selisih uang dan cetak rekap.
- **Riwayat transaksi**: filter tanggal/status/metode/kasir, detail, pembatalan dengan alasan (stok & uang dikembalikan), ekspor.
- **Piutang (kasbon)**: daftar transaksi belum lunas dan pencatatan pelunasan bertahap.
- **Produk, kategori, stok**: HPP, margin, barcode, foto, batas stok minimum, stok masuk (HPP rata-rata tertimbang), stok keluar, stok opname, kartu stok.
- **Laporan penjualan**: omzet, laba kotor, produk terlaris, per kategori, per kasir, per metode pembayaran.
- **Pengaturan kasir**: metode pembayaran aktif, pajak, izin kasbon, izin stok minus, isi & lebar struk, cetak otomatis.
- **Ekspor data toko** (`/pengaturan/ekspor-data`): pemilik toko mengunduh seluruh data tokonya sebagai ZIP berisi CSV (produk, kategori, pelanggan, pengguna, transaksi beserta item & pembayaran, shift, kas masuk/keluar, mutasi stok, pengaturan), termasuk data yang sudah dihapus. Angka ditulis mentah supaya bisa diolah ulang atau dipakai untuk pindah aplikasi.

### Kasus yang sudah ditangani

| Kejadian | Penanganan |
|---|---|
| Tombol bayar terketuk dua kali / koneksi putus lalu dicoba lagi | Setiap keranjang punya `client_uuid`; request kedua mengembalikan transaksi yang sama, tidak tercatat dobel |
| Halaman termuat ulang, tab tertutup, sesi login habis | Keranjang tersimpan di perangkat (localStorage) dan dipulihkan, harga & stok disinkronkan ulang |
| Harga diubah saat barang sudah di keranjang | Checkout ditolak, keranjang diperbarui ke harga baru, kasir memeriksa lalu bayar lagi |
| Produk dinonaktifkan/dihapus saat di keranjang | Dikeluarkan dari keranjang dengan pemberitahuan |
| Dua kasir menjual stok terakhir bersamaan | Baris produk dikunci saat checkout; stok tidak bisa minus kecuali diizinkan di pengaturan |
| Uang kurang | Ditolak, atau dicatat sebagai kasbon kalau pelanggan dipilih dan kasbon diizinkan |
| Non-tunai melebihi tagihan | Ditolak (non-tunai tidak punya kembalian) |
| Kasir memberi diskon tanpa wewenang | Ditolak di server; tombol diskon hanya muncul untuk izin `pos.discount` |
| Transaksi dibatalkan setelah shift ditutup | Uang tunai yang dikembalikan dicatat sebagai kas keluar di shift pembatal |
| Lupa tutup shift | Peringatan di layar kasir dan dashboard pemilik |
| Printer tidak tersedia | Struk bisa dikirim lewat WhatsApp atau dicetak ulang kapan saja |

## Multi-tenant (SaaS)

### Konsep

| Istilah | Arti |
|---|---|
| Tenant / toko | Satu pelanggan layanan (tabel `tenants`). Semua data bisnis menempel ke toko lewat kolom `tenant_id`. |
| Pemilik toko | Akun berperan `superadmin` di tokonya: semua menu toko, sakelar fitur, ekspor data. Peran ini tidak berlaku di toko lain. |
| Admin platform | Akun tanpa toko (`users.tenant_id` kosong, `is_platform_admin`). Hanya bisa membuka panel Platform: daftar toko dan backup database. |
| Paket | `trial`, `basic`, `pro` di `config/saas.php`, beserta batas jumlah pengguna dan produk (`null` = tanpa batas). |

Username, email, dan nomor HP unik di seluruh layanan, jadi login tidak perlu kode toko. Kode/nama yang dipakai di dalam toko (SKU, barcode, kode pelanggan, nama kategori, nama peran, nomor transaksi & shift) cukup unik per toko, dan penomoran dokumen (`TRX-2026-0001`) berjalan sendiri-sendiri di tiap toko.

### Alur toko

1. **Daftar** di `/daftar` (web) atau `POST /api/v1/auth/register` (aplikasi mobile). Toko dibuat beserta peran bawaan (superadmin, admin, kasir, staff) dan akun pemiliknya, lalu langsung masuk ke [persiapan toko](#persiapan-toko-preset-jenis-toko). Masa uji coba diatur dengan `SAAS_TRIAL_DAYS` (default 14 hari).
2. **Masa aktif** dihitung dari `trial_ends_at` untuk paket `trial` dan `subscription_ends_at` untuk paket berbayar; kosong berarti tanpa batas.
3. **Toko diblokir** kalau dinonaktifkan admin platform atau masa aktifnya habis:
   - web: semua halaman dialihkan ke `/langganan`, aksi Livewire di halaman yang masih terbuka ditolak `402`;
   - API: `402` dengan `reason` (`tenant_suspended`, `trial_expired`, `subscription_expired`); `auth/me` dan logout tetap bisa dipakai supaya aplikasi bisa menampilkan statusnya.
   Data toko tidak dihapus; begitu diaktifkan atau diperpanjang, toko langsung bisa dipakai lagi.
4. **Batas paket** dicek saat menambah produk dan pengguna baru; data yang sudah ada tidak disentuh saat toko turun paket.

### Persiapan toko (preset jenis toko)

Setelah mendaftar, pemilik toko (superadmin) dialihkan ke `/persiapan-toko` sampai memilih jenis toko atau menekan **Lewati, mulai dari kosong**. Kasir, peran lain, dan admin platform tidak pernah dialihkan; toko yang sudah ada sebelum fitur ini dianggap selesai (`tenants.onboarded_at` diisi saat migrasi).

Ada 10 jenis toko: Warung/Kelontong, Minimarket, Kafe, Restoran, Fashion, Toko Bangunan, Konter HP & Pulsa, Apotek, Bakery, dan Lainnya. Tiap preset membuat kategori, produk contoh opsional (stok 0, SKU dari penomoran `PRD`, menu racikan kafe/restoran tanpa lacak stok), pengaturan kasir (pajak PB1 10% untuk kafe/restoran, kasbon, nominal cepat, catatan kaki struk), dan menyalakan/mematikan fitur Piutang serta Layar Pelanggan. Produk contoh dibatasi sisa kuota paket.

| Bagian | Lokasi |
|---|---|
| Daftar jenis toko | `app/Enums/StoreType.php` |
| Isi preset | `app/Support/StorePresets.php` |
| Penerapan (transaksi, aman diulang) | `app/Services/StorePresetApplier.php` |
| Pengalihan pemilik toko | `app/Http/Middleware/EnsureStoreOnboarded.php` |
| Halaman wizard | `resources/views/livewire/pages/onboarding.blade.php` |

Preset bisa diterapkan lagi dari **Pengaturan → Perusahaan → Jenis Toko** selama toko belum punya transaksi penjualan. Kategori dan produk yang namanya sudah ada dilewati, pengaturan kasir ditimpa.

Pembayaran langganan belum otomatis: admin platform mengubah paket dan masa aktif dari panel **Platform → Toko Pelanggan** (`/platform/toko`), termasuk tombol perpanjang 30 hari dan menonaktifkan toko.

### Membuat admin platform

```bash
php artisan app:platform-admin
# atau tanpa interaksi
php artisan app:platform-admin --no-interaction --name="Pengelola" --email=ops@layanan.id --username=ops --password='rahasia-kuat'
```

### Cara isolasi data bekerja (untuk developer)

| Bagian | Lokasi | Catatan |
|---|---|---|
| Tenant aktif per request/job | `app/Support/CurrentTenant.php` | Diisi `IdentifyTenant` dari user yang login (session web atau token Sanctum), ikut tersimpan di payload queue, dan sekaligus mengatur team id Spatie. `run($tenant, fn)` untuk console/seeder. |
| Filter otomatis | trait `app/Models/Concerns/BelongsToTenant.php` + `app/Models/Scopes/TenantScope.php` | Semua query model dibatasi ke tenant aktif dan `tenant_id` diisi saat model dibuat. Tanpa tenant aktif (console, halaman tamu) scope tidak memfilter apa pun. |
| Akses toko & status langganan | `app/Http/Middleware/EnsureTenantAccess.php` | Juga terdaftar sebagai persistent middleware Livewire. Akun tanpa toko hanya boleh membuka `platform.*`. |
| Validasi unique/exists | `app/Support/TenantRule.php` | `Rule::unique()`/`Rule::exists()` bawaan tidak kena global scope; pakai `TenantRule` untuk tabel milik toko. |
| Peran per toko | Spatie teams (`config/permission.php`, kolom `tenant_id`) | Model `Role` sengaja tanpa global scope karena cache izin Spatie memuat peran semua toko; query peran di halaman Peran & Perizinan difilter manual. |
| Pengaturan | `app/Models/Setting.php` | Baris `tenant_id` kosong = nilai bawaan platform (mis. nama aplikasi di halaman login), ditimpa nilai milik toko. Cache per toko. |
| Pembuatan toko | `app/Services/TenantProvisioner.php` | Dipakai pendaftaran, `app:install`, dan seeder. |
| Realtime | channel `tenant.{id}.dashboard` | `BroadcastsToDashboard` dan `WithRealtimeRefresh` memakai channel toko aktif. |
| File unggahan | `CurrentTenant::storagePath()` | Foto produk, logo, dan slide disimpan di `tenants/{id}/...`. |
| Paket & batas | `config/saas.php`, `app/Support/PlanLimits.php`, `Tenant::blockedReason()` | |
| Ekspor data toko | `app/Services/TenantDataExporter.php` | Menolak jalan tanpa tenant aktif. |

Aturan yang wajib diikuti:

- Query lewat `DB::table()` atau raw SQL **tidak** terfilter; tambahkan `where tenant_id` sendiri. Join antartabel aman selama dimulai dari model Eloquent (kolom `tenant_id` di scope ditulis lengkap dengan nama tabel).
- Kode di console, job, atau route tamu yang menyentuh data toko harus mengaktifkan tenant dulu (`app(CurrentTenant::class)->run(...)`), kalau tidak query mengembalikan data semua toko.
- Backup & restore mencakup database semua toko, jadi hanya tersedia untuk admin platform (`/platform/backup`).

### Upgrade dari versi satu toko

`php artisan migrate` memindahkan semua data yang ada ke satu toko baru (nama diambil dari profil perusahaan) dengan paket `pro` tanpa batas waktu, termasuk peran, penomoran dokumen, dan pengaturan. Setelah itu buat akun admin platform dengan `php artisan app:platform-admin`. Backup database dulu sebelum migrasi.

## Akun Demo

`php artisan migrate:fresh --seed` membuat toko **Toko Demo** (paket Pro) berisi katalog contoh, riwayat penjualan 6 hari, logo toko contoh (dari `database/seeders/images/branding/`, bisa diganti di Pengaturan Perusahaan), gambar slideshow layar pelanggan, dan akun berikut (password `password`):

| Peran | Login | Akses |
|---|---|---|
| Admin platform | `platform` | Panel Platform: daftar toko, paket & masa aktif, backup database |
| Superadmin (pemilik toko) | `superadmin` | Semua menu toko, termasuk sakelar fitur & ekspor data |
| Admin (pemilik) | `admin` | Semua menu toko, laporan, pengaturan kasir |
| Kasir | `kasir` | Layar kasir, shift sendiri, transaksi sendiri, pelunasan kasbon |
| Staff (gudang) | `staff` | Lihat produk, kelola stok |

## Catatan Perangkat

- **Scanner barcode**: scanner USB/Bluetooth yang bekerja sebagai keyboard langsung terbaca di layar kasir.
- **Printer thermal**: atur kertas 58/80 mm di Pengaturan Kasir. Di Android, pasang aplikasi print service printer (mis. RawBT) supaya dialog cetak browser bisa memilih printer Bluetooth.
- **Kamera & HTTPS**: scan kamera hanya tersedia di HTTPS atau `localhost`. Saat diakses lewat IP lokal (`http://192.168.x.x`) gunakan scanner atau ketik kodenya.
- **Layar pelanggan di perangkat lain** menerima pembaruan lewat Reverb; kalau Reverb mati, layar tetap jalan dengan polling tiap ~2,5 detik. Jendela di perangkat yang sama dengan kasir sinkron instan tanpa server.
- **Foto produk** disimpan di disk `public`; jalankan `php artisan storage:link` sekali (sudah termasuk di `composer run setup`).

## Stack

Laravel 13, PHP 8.5, Livewire 3 + Volt + Alpine.js, Tailwind CSS, Laravel Reverb, Sanctum, `spatie/laravel-permission`, `spatie/laravel-activitylog`, Pest, Larastan, Pint. Database default SQLite (dev); MySQL/MariaDB juga didukung.

## Instalasi

1. Clone repository ini.
2. Jalankan setup:

   ```bash
   composer run setup
   ```

   Perintah ini menginstal dependency, membuat `.env` + `APP_KEY`, lalu menjalankan `php artisan app:install`. Installer ini menjalankan migrasi, membuat toko pertama (paket Pro, tanpa batas waktu) beserta peran & izinnya, mengisi nama aplikasi/perusahaan, dan membuat akun superadmin toko tersebut. Terakhir, aset frontend dibuild. Kalau pertanyaan installer tidak muncul (terminal non-interaktif), jalankan `php artisan app:install` sendiri setelahnya.

3. Jalankan server web, queue worker, scheduler, Vite, dan Reverb sekaligus:

   ```bash
   composer run dev
   ```

   Buka `http://localhost:8000`. Ganti `REVERB_PORT` di `.env` kalau `8085` bentrok dengan service lain.

Installer juga bisa dijalankan tanpa interaksi (mis. di server):

```bash
php artisan app:install --no-interaction \
  --app-name="Nama Aplikasi" --company="PT Nama Perusahaan" \
  --name="Admin" --email=admin@perusahaan.id --username=admin --password='rahasia-kuat'
```

Tambahkan `--demo` untuk mengisi pelanggan dan katalog produk contoh.

Untuk layanan SaaS, buat juga akun admin platform dengan `php artisan app:platform-admin`. Toko berikutnya mendaftar sendiri di `/daftar`.

Data demo untuk pengembangan: lihat bagian **Akun Demo** di atas. Jangan jalankan seeder demo di production; pakai `app:install`.

## Peta Arsitektur

| Bagian | Lokasi |
|---|---|
| Menu sidebar, flyout, bottom nav mobile, pencarian menu | `app/Support/Navigation.php` |
| Daftar modul & fitur yang bisa dimatikan | `app/Support/Features.php` |
| Daftar izin & izin bawaan per peran | `database/seeders/PermissionSeeder.php` |
| Gate lintas modul (`view-master-data`, dll.) | `app/Providers/AppServiceProvider.php` |
| Plumbing CRUD Livewire (search, sort, paginasi, modal, hapus, ekspor) | `app/Livewire/Concerns/WithCrudActions.php`, `WithDataTable.php` |
| Refresh realtime per komponen | `app/Livewire/Concerns/WithRealtimeRefresh.php` + `app/Events/Concerns/BroadcastsToDashboard.php` |
| Audit trail | trait `app/Models/Concerns/Auditable.php`, label di `app/Support/Audit/AuditTrail.php` |
| Pencarian global (⌘K) | `resources/views/livewire/layout/global-search.blade.php` |
| Generator OpenAPI | `app/Support/OpenApi/` (atribut `#[ApiTag]`, `#[ApiQuery]`, `#[ApiResponse]` di controller) |
| Nomor dokumen otomatis yang aman dari race condition | `app/Services/DocumentNumberGenerator.php` |
| Checkout, pembatalan, pelunasan kasbon | `app/Services/Pos/SaleService.php` (+ `PosCheckoutController` untuk endpoint JSON) |
| Rumus total keranjang (harus sama di PHP & JS) | `app/Services/Pos/CartCalculator.php` dan `resources/js/pos.js` |
| Shift kasir & mutasi stok | `app/Services/Pos/ShiftService.php`, `app/Services/Pos/StockService.php` |
| Layar kasir | `app/Livewire/Pos/Cashier.php`, `resources/views/livewire/pos/`, layout `layouts/pos.blade.php` |
| Pengaturan kasir | `app/Support/PosSettings.php` (key `pos.*` di tabel settings) |
| QRIS statis → dinamis | `app/Support/Qris.php` |
| Layar pelanggan | `app/Http/Controllers/CustomerDisplayController.php`, `resources/views/display/show.blade.php`, `resources/js/customer-display.js`, pengaturan `app/Support/CustomerDisplaySettings.php` |
| Struk thermal | `resources/views/print/receipt.blade.php`, layout `components/layouts/thermal.blade.php` |
| Branding & kop surat | `app/Support/Branding.php`, `resources/views/components/print-letterhead.blade.php` |
| Multi-tenant, paket, panel Platform | lihat [Cara isolasi data bekerja](#cara-isolasi-data-bekerja-untuk-developer); panel di `app/Livewire/Platform/Tenants.php`, route `routes/platform.php` |
| Pendaftaran toko & halaman langganan | `resources/views/livewire/pages/auth/register.blade.php`, `resources/views/livewire/pages/subscription-inactive.blade.php` |

## Menambah Modul Baru

Salin pola modul **Pelanggan**. File yang terlibat:

```
app/Models/Customer.php                                   model (Auditable, event realtime, notifikasi)
database/migrations/*_create_customers_table.php
database/factories/CustomerFactory.php
database/seeders/MasterDataSeeder.php                     data demo
app/Livewire/MasterData/Customers.php                     daftar + form modal (WithCrudActions)
app/Livewire/MasterData/CustomerShow.php                  halaman detail + riwayat perubahan
resources/views/livewire/master-data/customers.blade.php
resources/views/livewire/master-data/customer-show.blade.php
resources/views/print/customer.blade.php                  dokumen cetak
app/Http/Controllers/PrintController.php
routes/master-data.php                                    route web
app/Http/Controllers/Api/V1/MasterData/CustomerController.php
app/Http/Requests/Api/V1/MasterData/CustomerRequest.php
app/Http/Resources/V1/MasterData/CustomerResource.php
routes/api/v1/master-data.php                             route API (middleware feature:...)
app/Events/CustomerChanged.php                            broadcast realtime
app/Listeners/NotifyOfNewCustomer.php
app/Notifications/CustomerCreatedNotification.php
tests/Feature/MasterData/*, tests/Feature/Api/MasterDataApiTest.php
```

Lalu daftarkan modulnya di beberapa registry:

1. **Tenant**: model memakai trait `BelongsToTenant`, migrasi menambah `foreignId('tenant_id')->constrained()`, kolom yang harus unik dibuat unik bersama `tenant_id`, dan validasi memakai `TenantRule::unique()`/`TenantRule::exists()`. Tambahkan datanya ke `TenantDataExporter::datasets()` supaya ikut terekspor, dan tes isolasinya di `tests/Feature/Tenancy/`.
2. **Izin**: tambah grup di `PermissionSeeder::PERMISSION_GROUPS` dan isi `DEFAULT_ROLE_PERMISSIONS`. Halaman Peran & Perizinan membacanya otomatis.
3. **Sakelar fitur**: tambah modul/fitur di `Features::MODULES` beserta pola nama route-nya. Route web yang tidak terdaftar selalu terbuka. Route API memakai middleware `feature:modul.fitur`.
4. **Menu**: tambah entri di `Navigation::items()` dengan `route`, `icon`, `feature`, `can`, dan `keywords` untuk pencarian menu. Tandai `mobile => true` kalau layak masuk bottom nav.
5. **Pencarian global**: tambah satu bagian di `$sections` pada `global-search.blade.php`.
6. **Audit trail**: pakai trait `Auditable` di model dan tambah label di `AuditTrail::SUBJECT_LABELS`.
7. **Dokumentasi API** terbentuk otomatis dari FormRequest, Resource, dan atribut `#[ApiTag]`. Cek hasilnya di `/docs/api`.
8. Jalankan `php artisan test --compact`. `FeatureTogglesTest` gagal kalau ada route yang belum terdaftar di `Features::MODULES`, dan `ApiDocumentationTest` gagal kalau ada endpoint API tanpa sakelar fitur atau belum terdokumentasi.

Untuk tautan ke halaman modul lain, pakai `<x-feature-link :href="...">` supaya tautannya otomatis jadi teks biasa saat fiturnya dimatikan.

## REST API (Aplikasi Mobile)

Semua endpoint ada di `/api/v1`, autentikasi Sanctum Bearer token, dan hak aksesnya sama dengan web (toko, peran, izin, dan sakelar fitur). Dokumentasi interaktif: `/docs/api` (Scalar), spesifikasi mentah: `/api/openapi.json`; keduanya hanya bisa dibuka superadmin yang sedang login. Untuk developer mobile, ekspor ke file dengan `php artisan api:docs --output=storage/app/openapi.json`.

Toko baru mendaftar lewat `auth/register` (atau `/daftar` di web) dan langsung mendapat token pemiliknya. Akun pegawai dibuat di **Pengaturan > Peran & Perizinan > Penugasan Pengguna** (izin `users.manage`); dari sana juga admin bisa mengeluarkan akun dari semua perangkat mobile. Ganti/reset password di web otomatis mencabut sesi mobile.

`auth/me` dan respons login menyertakan `tenant` (nama, paket, `access_ends_at`, `blocked_reason`, `onboarded`, `store_type`). Saat toko diblokir, endpoint lain menjawab `402` dengan `reason`; lihat [Alur toko](#alur-toko).

| Grup | Endpoint |
| --- | --- |
| Akun | `auth/*` (daftar toko, login password/OTP WhatsApp, profil, logout), `onboarding/presets`, `onboarding/apply`, `onboarding/skip`, `dashboard`, `meta`, `search`, `notifications` |
| Master Data | `master-data/customers`, `master-data/categories`, `master-data/products` (+ `products/{id}/image`) |
| Stok | `inventory/stock`, `inventory/stock/summary`, `inventory/movements`, `inventory/adjustments` |
| Kasir | `pos/config`, `pos/categories`, `pos/products`, `pos/products/lookup`, `pos/customers`, `pos/qris`, `pos/checkout`, `pos/shift`, `pos/held-orders` |
| Penjualan | `sales` (+ `void`, `receipt`), `shifts` (+ `sales`, `close`, `cash-movements`), `receivables` (+ `payments`), `print/receipt/{sale}`, `print/shift/{shift}` |
| Laporan | `reports/sales/summary`, `reports/sales/daily`, `reports/sales/products`, `reports/activity-log` |

Penolakan dari layar kasir (stok kurang, harga berubah, shift belum dibuka) dikembalikan `422` dengan `reason` dan `context` supaya aplikasi bisa menanganinya tanpa membaca teks pesan. Pengaturan (profil perusahaan, kasir, peran, fitur, ekspor data) sengaja hanya tersedia di web; backup hanya untuk admin platform.

### Aplikasi mobile

Client Flutter-nya ada di repository terpisah [web-pos-mobile](../web-pos-mobile) (kasir, shift, riwayat, master data, stok, laporan, printer Bluetooth, mode offline, pendaftaran toko).

Belum ada di API (perlu dikerjakan di backend kalau dibutuhkan):

- [ ] Push notification (FCM): endpoint registrasi device token dan pengiriman notifikasi ke perangkat
- [ ] Layar pelanggan dari aplikasi: endpoint setara `pos.display.push` untuk mengirim isi keranjang ke layar yang dipasangkan
- [ ] Ekspor riwayat transaksi/laporan ke file (ekspor seluruh data toko sudah ada di web)
- [ ] Pembayaran langganan otomatis (payment gateway); sekarang masa aktif diperpanjang manual dari panel Platform

## Menjalankan Test & Pemeriksaan Kode

```bash
php artisan test --compact     # Pest
vendor/bin/phpstan analyse     # Larastan
vendor/bin/pint                # format kode
```

Workflow `.github/workflows/tests.yml` menjalankan ketiganya di setiap push ke `main` dan setiap pull request.

## Catatan Lingkungan

- Zona waktu default WIB (`APP_TIMEZONE=Asia/Jakarta`) dan bahasa Indonesia (`APP_LOCALE=id`).
- Broadcasting memakai Reverb (`BROADCAST_CONNECTION=reverb`). Kalau Reverb mati, penyimpanan data tetap berhasil: event memakai `ShouldRescue` dan notifikasi di-queue.
- Notifikasi dan backup berjalan lewat queue (`QUEUE_CONNECTION=database`), jadi di production perlu worker (`php artisan queue:work`) dan scheduler (`php artisan schedule:run` tiap menit) untuk backup terjadwal. Backup mencakup database semua toko dan hanya bisa diatur admin platform.
- Masa uji coba toko baru: `SAAS_TRIAL_DAYS` (default 14). Nama, batas pengguna, dan batas produk tiap paket ada di `config/saas.php`.
- Export dokumentasi API ke file: `php artisan api:docs --output=storage/app/openapi.json`.
