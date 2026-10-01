# Kasir Toko (Web POS)

Aplikasi kasir berbasis web untuk toko, dirancang untuk tablet dan ponsel tapi tetap nyaman di laptop. Dibangun di atas [JagoDev Laravel Starter](https://github.com/rizalahmaddd/jagodev-laravel-starter), jadi auth, peran & izin, audit trail, sakelar fitur, backup, dan tema gelap/terang sudah tersedia.

## Fitur Utama

- **Layar kasir** (`/kasir`): katalog produk dengan filter kategori, pencarian nama/SKU, scan barcode lewat scanner USB/Bluetooth (tanpa perlu klik kolom pencarian) atau kamera ponsel (browser yang mendukung `BarcodeDetector`), keranjang dengan jumlah desimal untuk barang timbangan, catatan & diskon per barang, diskon transaksi (Rp/%), dan pajak opsional.
- **Pembayaran**: tunai (saran nominal & kembalian), QRIS, transfer, kartu, bayar campuran (split), dan kasbon atas nama pelanggan. Papan angka di layar sentuh, pintasan keyboard di laptop (`F2` cari, `F9` bayar, `Enter` selesai).
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

## Akun Demo

`php artisan migrate:fresh --seed` membuat katalog toko contoh, riwayat penjualan 6 hari, dan akun berikut (password `password`):

| Peran | Login | Akses |
|---|---|---|
| Superadmin | `superadmin` | Semua, termasuk sakelar fitur & backup |
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

   Perintah ini menginstal dependency, membuat `.env` + `APP_KEY`, lalu menjalankan `php artisan app:install`. Installer ini menjalankan migrasi, membuat peran & izin, mengisi nama aplikasi/perusahaan, dan membuat akun superadmin pertama. Terakhir, aset frontend dibuild. Kalau pertanyaan installer tidak muncul (terminal non-interaktif), jalankan `php artisan app:install` sendiri setelahnya.

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

1. **Izin**: tambah grup di `PermissionSeeder::PERMISSION_GROUPS` dan isi `DEFAULT_ROLE_PERMISSIONS`. Halaman Peran & Perizinan membacanya otomatis.
2. **Sakelar fitur**: tambah modul/fitur di `Features::MODULES` beserta pola nama route-nya. Route web yang tidak terdaftar selalu terbuka. Route API memakai middleware `feature:modul.fitur`.
3. **Menu**: tambah entri di `Navigation::items()` dengan `route`, `icon`, `feature`, `can`, dan `keywords` untuk pencarian menu. Tandai `mobile => true` kalau layak masuk bottom nav.
4. **Pencarian global**: tambah satu bagian di `$sections` pada `global-search.blade.php`.
5. **Audit trail**: pakai trait `Auditable` di model dan tambah label di `AuditTrail::SUBJECT_LABELS`.
6. **Dokumentasi API** terbentuk otomatis dari FormRequest, Resource, dan atribut `#[ApiTag]`. Cek hasilnya di `/docs/api`.
7. Jalankan `php artisan test --compact`. `FeatureTogglesTest` gagal kalau ada route yang belum terdaftar di `Features::MODULES`, dan `ApiDocumentationTest` gagal kalau ada endpoint API tanpa sakelar fitur atau belum terdokumentasi.

Untuk tautan ke halaman modul lain, pakai `<x-feature-link :href="...">` supaya tautannya otomatis jadi teks biasa saat fiturnya dimatikan.

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
- Notifikasi dan backup berjalan lewat queue (`QUEUE_CONNECTION=database`), jadi di production perlu worker (`php artisan queue:work`) dan scheduler (`php artisan schedule:run` tiap menit) untuk backup terjadwal.
- Export dokumentasi API ke file: `php artisan api:docs --output=storage/app/openapi.json`.
