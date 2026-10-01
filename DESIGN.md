# DESIGN.md

Sumber arah desain untuk semua layar di aplikasi turunan starter ini. Layar baru mengikuti dial dan aturan di sini, bukan diputuskan ulang per layar. Kalau project turunan punya karakter berbeda (mis. aplikasi publik, bukan back-office), ubah dokumen ini dulu sebelum membangun UI.

## Design Read

Dibaca sebagai: **alat kerja back-office** untuk staf internal yang memantau dan mengubah data setiap hari. Bukan halaman pemasaran, bukan dashboard analitik pasif.

**Dial: ENERGY 2 / RHYTHM 2 / MOTION 2**
- ENERGY 2: setara Stripe/Vercel. Rapi dan percaya diri, tidak sepolos GOV.UK, tidak seramai portfolio agency.
- RHYTHM 2: konsisten dengan beberapa variasi yang disengaja (panel fokal per layar boleh beda treatment dari kartu di sekitarnya), bukan grid seragam total.
- MOTION 2: transisi & feedback fungsional saja (buka/tutup modal, loading, progress), tanpa animasi dekoratif yang berulang terus.

## Palet Warna

| Peran | Warna | Dipakai untuk |
|---|---|---|
| Netral (dasar UI) | Slate (50–950) | latar & teks dasar, tidak dihitung sebagai warna inti |
| **Brand / inti** | Emerald | logo, CTA utama, status "aman/normal/aktif" |
| **Aksen** | Amber | sinyal "perhatian/mendekati batas", badge peringatan non-kritis |
| **Fungsional: kritis** | Rose | hanya kondisi gagal/terblokir yang benar-benar terjadi, tidak pernah dekoratif |
| **Fungsional: informasi** | Sky | badge status netral-informatif (mis. "Draft", "Menunggu") bila warna lain tidak tepat maknanya |

Tidak ada indigo, ungu, atau gradient pelangi sebagai dekorasi. **Gradient** dibatasi ke mark logo (`from-emerald-600 to-emerald-400`) dan panel fokal per layar (gradient monokrom gelap `slate-900 → slate-800`). Semua permukaan lain flat.

## Tipografi

- **Inter** (sans), karena keterbacaannya tinggi pada tabel/angka padat di ukuran kecil (10–13px). Angka mata uang/kuantitas boleh `font-mono` di tabel untuk alignment, bukan sebagai gaya heading.
- Heading sentence case Bahasa Indonesia natural. `UPPERCASE` hanya untuk label status pendek yang berfungsi sebagai badge.

## Ikon

Lucide. Setiap ikon dipilih karena makna fungsionalnya terhadap entitas (users = pelanggan, shield = hak akses, scroll-text = log), bukan dekorasi (tidak ada sparkle/lightning/robot). Modul baru memetakan satu ikon per entitas dan memakainya konsisten di sidebar, empty state, dan hasil pencarian.

## Elevasi & Radius

- **Radius**: `rounded-lg` (input, tombol kecil, badge), `rounded-xl` (kartu tabel/list), `rounded-2xl` (panel fokal, modal).
- **Shadow** hanya untuk (a) satu panel fokal per layar dan (b) modal di atas backdrop. Kartu KPI, baris tabel, dan kartu list tetap flat (`border` saja).

## Satu Titik Fokus per Layar

Setiap layar punya satu elemen yang jelas paling penting, sisanya flat. Contoh: Dashboard memakai panel sambutan, Pengaturan Perusahaan memakai pratinjau kop surat. Sebelum membangun layar baru, tentukan dulu elemen fokalnya (biasanya ringkasan konsekuensi dari aksi yang sedang dilakukan user), baru susun sisanya.

## Animasi

Semua animasi fungsional dan punya kondisi berhenti. Transisi modal dan progress bar selalu boleh. Dilarang animasi dekoratif tanpa henti (bounce/ping/pulse infinite) pada elemen yang tidak merepresentasikan state nyata.

## Copywriting

- CTA spesifik ke aksi & hasilnya (`"Simpan Pelanggan"`, `"Cetak Kartu"`), bukan `"Submit"` generik.
- Tidak ada klaim yang tidak bisa dibuktikan sistem: tidak ada badge "Live"/"Online"/"Sistem Normal" tanpa pengecekan nyata, tidak ada angka tanpa sumber, tidak ada stempel/hash verifikasi palsu di dokumen cetak.
- Label status mencerminkan state nyata di database (`AKTIF`, `SELESAI`, `DIBATALKAN`, `MENUNGGU`), bukan kata sifat pemasaran.

## Kontras & Aksesibilitas

- Teks di atas `bg-slate-900`/`bg-slate-950` minimal `text-slate-400` untuk teks kecil (`text-slate-500` gagal 4.5:1).
- Target tap/klik minimum 44×44px, termasuk ikon aksi di baris tabel.
- Semua elemen interaktif bisa dijangkau keyboard (Tab/Enter/Escape) dengan fokus yang terlihat, termasuk di modal Livewire.
- Setiap tabel/list wajib punya empty state, loading state, dan error state.

## Tema

Default gelap, dengan toggle terang/gelap (`<x-theme-toggle>`, disimpan di cookie + localStorage). Dokumen cetak (`x-layouts.print`) selalu putih/terang karena dibaca sebagai hasil cetak.

## Standar Komponen Blade UI

Wajib memakai komponen di `resources/views/components/`:
1. **Modal**: `<x-record-form-modal name="..." :title="..." subtitle="..." icon="..." max-width="...">` untuk formulir, `<x-confirm-delete-modal>` untuk konfirmasi hapus, `<x-modal>` untuk kontainer umum, disusun dengan `<x-modal-header>` + `<x-modal-actions>`. Dilarang membuat backdrop/overlay manual.
2. **Select**: `<x-select id="..." wire:model="...">`. Dilarang `<select>` HTML mentah.
3. **Input**: `<x-input-label>`, `<x-text-input>`, `<x-textarea>`, `<x-file-input>`, `<x-search-input>`, `<x-checkbox>`, `<x-checkbox-card label description>`, `<x-input-error>`.
4. **Tombol**: `<x-primary-button>`, `<x-secondary-button>`, `<x-danger-button>` (`size="xs|sm|md"`); `<x-icon-button icon label>` untuk aksi ikon; `<x-text-button>` untuk aksi teks; `<x-tab-button :active>` di dalam `<x-segmented>` untuk tab/saringan. Label proses pakai `<x-loading-label target loading>`.
5. **Badge, Tabel & Empty State**: `<x-badge>` / `<x-status-badge>`, `<x-table>` (`th sortable`, `tr`, `td`), `<x-empty-state>`.
6. **Dashboard**: `<x-dashboard.stat>`, `<x-dashboard.panel>`, `<x-dashboard.shortcuts>`, `<x-dashboard.activity-feed>`, `<x-dashboard.action-list>`, `<x-bar-chart>`.
7. **Dokumen cetak**: `<x-layouts.print>` + `<x-print-letterhead title number date>` (lihat `resources/views/print/customer.blade.php`).
