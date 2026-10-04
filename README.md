# Kasir Toko (Web POS)

[![PHP](https://img.shields.io/badge/PHP-8.3-777BB4?logo=php&logoColor=white)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-11.x-FF2D20?logo=laravel&logoColor=white)](https://laravel.com)
[![Livewire](https://img.shields.io/badge/Livewire-3.x-FB70A9?logo=livewire&logoColor=white)](https://livewire.laravel.com)
[![Tailwind CSS](https://img.shields.io/badge/Tailwind_CSS-3.x-06B6D4?logo=tailwindcss&logoColor=white)](https://tailwindcss.com)
[![Multi-Tenant](https://img.shields.io/badge/Multi--Tenant-SaaS-8A2BE2)](docs/ARCHITECTURE.md#2-arsitektur-multi-tenant-saas)
[![Tests](https://img.shields.io/badge/Tests-472_passed-success?logo=pest&logoColor=white)](#-dokumentasi-teknis--mobile)

Aplikasi kasir (Point of Sale) modern berbasis web, dirancang responsif untuk tablet, smartphone, maupun komputer kasir. Siap digunakan langsung sebagai **SaaS Multi-Tenant** (isolasi data ketat per toko) maupun instalasi toko mandiri (*single-tenant*).

---

## ✨ Fitur Utama

- 🛒 **Layar Kasir Cepat**: Scan barcode kamera/USB, kuantitas desimal, diskon, pajak, dan pintasan keyboard.
- 💳 **Pembayaran Lengkap**: Tunai, QRIS dinamis otomatis (nominal pas), transfer, kartu, split payment, dan kasbon.
- 📺 **Layar Pelanggan**: Customer display realtime via WebSocket Reverb untuk tablet/monitor hadap pembeli.
- 🏢 **Multi-Tenant SaaS**: Isolasi data otomatis, 10 preset industri, dan langganan otomatis via SumoPod QRIS.
- 📦 **Inventori & Laporan**: HPP rata-rata, mutasi stok, stok opname, impor massal Excel, dan ekspor ZIP CSV toko.
- 🔐 **Autentikasi & Siap Rilis**: Login Google & Apple, cetak struk thermal 58/80mm, serta kirim struk ke WhatsApp.

---

## ⚡ Instalasi Cepat

```bash
git clone https://github.com/rizalahmaddd/kasir-toko.git && cd kasir-toko
composer run setup
composer run dev
```

> Buka `http://localhost:8000`. Akun demo seeder: `owner@demo.com` (password: `password`).

---

## 📖 Dokumentasi Teknis & Mobile

- 📚 **Arsitektur & SaaS Guide**: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) *(panduan isolasi tenant, middleware, webhook, dan keamanan)*
- 📱 **Aplikasi Mobile (Flutter)**: [Kasir Toko Mobile](https://github.com/rizalahmaddd/kasir-toko-mobile)
- 🧪 **Pengujian Kode**: `php artisan test --compact` *(472 unit & feature tests Pest)*

---

## ☕ Dukung & Donasi

Jika proyek ini bermanfaat bagi Anda, dukung pengembangan proyek ini melalui **QRIS**:

<p align="center">
  <img src="docs/qris.png" width="240" alt="QRIS Donasi - RZ Printing" />
  <br>
  <em>Scan QRIS menggunakan BCA, Mandiri, BRI, GoPay, OVO, DANA, ShopeePay, atau mobile banking lainnya.</em>
</p>

---

## 📬 Kontak

Dikembangkan oleh **rizalahmaddd**:
- **WhatsApp**: [+62 857-7777-5477](https://wa.me/6285777775477)
- **GitHub**: [@rizalahmaddd](https://github.com/rizalahmaddd)
- **Lokasi**: Kota Malang, Jawa Timur, Indonesia
