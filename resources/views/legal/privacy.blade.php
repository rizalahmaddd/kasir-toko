<x-legal-layout title="Kebijakan Privasi">
    <div class="space-y-8 bg-white dark:bg-slate-900 rounded-2xl p-6 sm:p-10 border border-slate-200 dark:border-slate-800 shadow-sm">
        <div class="border-b border-slate-200 dark:border-slate-800 pb-6">
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800 mb-3">
                Dokumen Resmi
            </span>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 dark:text-white tracking-tight">
                Kebijakan Privasi (Privacy Policy)
            </h1>
            <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-2">
                Terakhir diperbarui: {{ date("d F Y") }} &bull; Berlaku untuk Layanan Web POS dan Aplikasi Kasir Mobile
            </p>
        </div>

        <div class="prose prose-slate dark:prose-invert max-w-none text-sm leading-relaxed space-y-6 text-slate-700 dark:text-slate-300">
            <section>
                <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-2">1. Pendahuluan</h2>
                <p>
                    Kami di <strong>{{ \App\Support\Branding::companyName() }}</strong> pengembang aplikasi <strong>{{ \App\Support\Branding::appName() }}</strong> berkomitmen untuk melindungi dan menghormati privasi Anda. Kebijakan Privasi ini menjelaskan bagaimana kami mengumpulkan, menggunakan, menyimpan, dan memproses data pribadi dan data operasional bisnis Anda saat menggunakan platform Web POS maupun Aplikasi Mobile (Android/iOS).
                </p>
            </section>

            <section>
                <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-2">2. Data yang Kami Kumpulkan</h2>
                <p>Kami mengumpulkan informasi berikut untuk mendukung fungsi utama sistem kasir (Point of Sale):</p>
                <ul class="list-disc pl-5 space-y-1.5 mt-2">
                    <li><strong>Informasi Akun:</strong> Nama pengguna, alamat email, nomor telepon/WhatsApp, dan nama toko/unit bisnis saat pendaftaran atau masuk dengan Google/Apple.</li>
                    <li><strong>Data Transaksi &amp; Operasional:</strong> Data katalog produk, riwayat transaksi penjualan, catatan kasir (shift kas), data pelanggan toko, dan catatan piutang.</li>
                    <li><strong>Foto dan Media:</strong> Gambar produk atau logo toko yang Anda unggah secara sukarela untuk ditampilkan pada katalog dan struk belanja.</li>
                </ul>
            </section>

            <section>
                <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-2">3. Penggunaan Izin Perangkat (Device Permissions)</h2>
                <p>Aplikasi mobile kami membutuhkan izin spesifik yang murni digunakan untuk menunjang aktivitas operasional kasir:</p>
                <ul class="list-disc pl-5 space-y-1.5 mt-2">
                    <li><strong>Kamera (<code class="text-xs bg-slate-100 dark:bg-slate-800 px-1 py-0.5 rounded">android.permission.CAMERA</code>):</strong> Digunakan secara eksklusif untuk memindai kode barcode atau QR produk dan QRIS transaksi saat kasir melayani pembeli. Kamera tidak aktif di latar belakang.</li>
                    <li><strong>Bluetooth (<code class="text-xs bg-slate-100 dark:bg-slate-800 px-1 py-0.5 rounded">BLUETOOTH_CONNECT</code> / <code class="text-xs bg-slate-100 dark:bg-slate-800 px-1 py-0.5 rounded">BLUETOOTH_SCAN</code>):</strong> Digunakan untuk memindai dan menghubungkan perangkat HP/tablet kasir dengan printer struk thermal bluetooth. Izin ini diatur dengan flag perlindungan agar tidak memicu pelacakan lokasi.</li>
                    <li><strong>Akses Internet &amp; Jaringan:</strong> Digunakan untuk menyinkronkan data transaksi antara aplikasi mobile dan server cloud secara real-time maupun saat memulihkan koneksi offline.</li>
                </ul>
            </section>

            <section>
                <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-2">4. Keamanan dan Penyimpanan Data</h2>
                <p>
                    Semua transmisi data antara perangkat aplikasi dan server kami dilindungi dengan enkripsi standar industri (SSL/TLS HTTPS). Basis data toko Anda diisolasi per tenant (Multi-tenant Architecture) guna mencegah akses data lintas pengguna yang tidak berwenang.
                </p>
            </section>

            <section>
                <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-2">5. Hak Pengguna &amp; Penghapusan Akun (Data Deletion)</h2>
                <p>
                    Anda memiliki hak penuh untuk memperbarui, mengunduh, atau menghapus akun dan data toko Anda kapan saja. Kami menyediakan fitur <strong>"Hapus Akun Saya"</strong> langsung di dalam aplikasi (Menu Pengaturan Akun) dan melalui halaman permohonan web di <a href="{{ route("legal.delete-account") }}" class="text-emerald-600 dark:text-emerald-400 underline font-semibold">{{ route("legal.delete-account") }}</a>.
                </p>
            </section>

            <section>
                <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-2">6. Kontak Kami</h2>
                <p>
                    Jika Anda memiliki pertanyaan mengenai Kebijakan Privasi ini, silakan hubungi tim kami melalui email atau saluran bantuan resmi yang tertera di sistem.
                </p>
            </section>
        </div>
    </div>
</x-legal-layout>
