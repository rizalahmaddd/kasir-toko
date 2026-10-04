<x-legal-layout title="Ketentuan Layanan & EULA">
    <div class="space-y-8 bg-white dark:bg-slate-900 rounded-2xl p-6 sm:p-10 border border-slate-200 dark:border-slate-800 shadow-sm">
        <div class="border-b border-slate-200 dark:border-slate-800 pb-6">
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800 mb-3">
                Dokumen Resmi
            </span>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 dark:text-white tracking-tight">
                Ketentuan Layanan &amp; Perjanjian Lisensi (EULA)
            </h1>
            <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-2">
                Terakhir diperbarui: {{ date("d F Y") }} &bull; Berlaku untuk seluruh pengguna aplikasi {{ \App\Support\Branding::appName() }}
            </p>
        </div>

        <div class="prose prose-slate dark:prose-invert max-w-none text-sm leading-relaxed space-y-6 text-slate-700 dark:text-slate-300">
            <section>
                <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-2">1. Ketentuan Umum</h2>
                <p>
                    Dengan mendaftar, mengunduh, atau menggunakan aplikasi dan layanan <strong>{{ \App\Support\Branding::appName() }}</strong>, Anda menyatakan telah membaca, memahami, dan menyetujui untuk terikat oleh Ketentuan Layanan ini. Jika Anda tidak menyetujui ketentuan ini, Anda dipersilakan untuk tidak menggunakan layanan kami.
                </p>
            </section>

            <section>
                <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-2">2. Lisensi Penggunaan</h2>
                <p>
                    Kami memberikan Anda hak dan lisensi terbatas, non-eksklusif, dan tidak dapat dipindahtangankan untuk memasang dan menggunakan aplikasi ini pada perangkat milik Anda semata-mata untuk operasional bisnis ritel, toko, atau kafe Anda sendiri sesuai ketentuan ini.
                </p>
            </section>

            <section>
                <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-2">3. Tanggung Jawab Akun &amp; Transaksi</h2>
                <ul class="list-disc pl-5 space-y-1.5">
                    <li>Anda bertanggung jawab penuh untuk menjaga kerahasiaan kata sandi, token autentikasi, dan hak akses staf kasir pada toko Anda.</li>
                    <li>Segala transaksi penjualan, pencatatan kasbon/piutang, dan penyesuaian stok yang dicatatkan melalui akun Anda adalah tanggung jawab penuh Anda sebagai pengelola toko.</li>
                    <li>Aplikasi tidak bertanggung jawab atas perselisihan transaksi fisik antara toko Anda dan pelanggan akhir Anda.</li>
                </ul>
            </section>

            <section>
                <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-2">4. Pembatasan Penggunaan</h2>
                <p>Pengguna dilarang keras:</p>
                <ul class="list-disc pl-5 space-y-1.5 mt-2">
                    <li>Melakukan dekompilasi, reverse-engineering, atau membongkar source code aplikasi selain yang diizinkan oleh lisensi open-source terkait.</li>
                    <li>Menggunakan platform untuk transaksi barang/jasa ilegal atau melanggar hukum di yurisdiksi Republik Indonesia.</li>
                    <li>Mencoba mengganggu stabilitas server atau sistem proteksi multi-tenancy kami.</li>
                </ul>
            </section>

            <section>
                <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-2">5. Penghentian Layanan</h2>
                <p>
                    Kami berhak menangguhkan atau menghentikan akses Anda jika ditemukan pelanggaran terhadap ketentuan ini. Anda juga berhak menghapus akun Anda kapan saja melalui fitur yang disediakan di dalam aplikasi.
                </p>
            </section>
        </div>
    </div>
</x-legal-layout>
