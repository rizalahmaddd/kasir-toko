<x-legal-layout title="Penghapusan Akun & Data">
    <div class="space-y-8 bg-white dark:bg-slate-900 rounded-2xl p-6 sm:p-10 border border-slate-200 dark:border-slate-800 shadow-sm">
        <div class="border-b border-slate-200 dark:border-slate-800 pb-6">
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-rose-50 dark:bg-rose-950/50 text-rose-600 dark:text-rose-400 border border-rose-200 dark:border-rose-800 mb-3">
                Kepatuhan Google Play Store &amp; Privasi Pengguna
            </span>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 dark:text-white tracking-tight">
                Permohonan Penghapusan Akun &amp; Data Toko
            </h1>
            <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-2">
                Halaman resmi transparansi keamanan data dan hak penghapusan akun aplikasi {{ \App\Support\Branding::appName() }}
            </p>
        </div>

        <div class="prose prose-slate dark:prose-invert max-w-none text-sm leading-relaxed space-y-6 text-slate-700 dark:text-slate-300">
            <div class="bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800/80 rounded-xl p-4 text-amber-800 dark:text-amber-300 text-xs sm:text-sm">
                <strong>Perhatian:</strong> Menghapus akun bersifat <strong>permanen</strong>. Data profil login, data toko (jika Anda adalah pemilik tunggal), riwayat transaksi, dan sesi perangkat akan dihapus atau dinonaktifkan secara permanen dan tidak dapat dipulihkan.
            </div>

            <section>
                <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-2">Metode 1: Hapus Akun Langsung Melalui Aplikasi Mobile</h2>
                <p>Anda dapat menghapus akun Anda secara mandiri dalam hitungan detik:</p>
                <ol class="list-decimal pl-5 space-y-2 mt-2">
                    <li>Buka aplikasi <strong>Kasir Toko (Web POS Mobile)</strong> di perangkat Anda.</li>
                    <li>Pastikan Anda sudah dalam keadaan masuk (login).</li>
                    <li>Buka tab <strong>Akun / Pengaturan</strong> di pojok kanan bawah.</li>
                    <li>Gulir ke bawah hingga bagian <em>"Zona Bahaya"</em> dan pilih <strong>"Hapus Akun Saya"</strong>.</li>
                    <li>Baca dialog peringatan permanen dan konfirmasikan penghapusan. Sistem akan menghapus data akun Anda dan mengeluarkan Anda dari aplikasi seketika.</li>
                </ol>
            </section>

            <section>
                <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-2">Metode 2: Permohonan Melalui Dukungan Pelanggan (Web Request)</h2>
                <p>
                    Jika perangkat Anda hilang atau Anda tidak dapat mengakses aplikasi mobile, Anda dapat mengajukan permohonan penghapusan akun secara manual dengan menghubungi tim teknis kami:
                </p>
                <div class="mt-4 p-5 rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-950/60 space-y-3">
                    <p class="font-semibold text-slate-900 dark:text-white">Format Permohonan Penghapusan Akun:</p>
                    <ul class="list-disc pl-5 space-y-1 text-xs sm:text-sm">
                        <li><strong>Subjek:</strong> Permohonan Penghapusan Akun - {{ \App\Support\Branding::appName() }}</li>
                        <li><strong>Email Akun Terdaftar:</strong> (Cantumkan email yang Anda gunakan untuk mendaftar)</li>
                        <li><strong>Nama Toko:</strong> (Nama toko terdaftar)</li>
                    </ul>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Kirimkan permohonan ke saluran dukungan resmi pengembang: <strong>support@{{ request()->getHost() }}</strong>. Tim kami akan memverifikasi kepemilikan akun dan memproses penghapusan data dalam 3x24 jam kerja.
                    </p>
                </div>
            </section>

            <section>
                <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-2">Data Apa Saja yang Dihapus?</h2>
                <ul class="list-disc pl-5 space-y-1.5">
                    <li>Profil akun pengguna (Nama, Email, Avatar, Password, Google ID / Apple ID).</li>
                    <li>Semua token autentikasi API dan sesi aktif di seluruh perangkat.</li>
                    <li>Riwayat akses dan preferensi akun.</li>
                </ul>
            </section>
        </div>
    </div>
</x-legal-layout>
