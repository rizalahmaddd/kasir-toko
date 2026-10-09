<?php

return [

    /*
    | Lama masa uji coba toko baru sejak mendaftar.
    */
    'trial_days' => (int) env('SAAS_TRIAL_DAYS', 14),

    /*
    | Versi minimum aplikasi mobile untuk toko dengan lebih dari satu outlet. Aplikasi yang lebih
    | lama tidak mengirim header X-Outlet-Id, jadi hanya bisa bekerja di satu outlet bawaan.
    */
    'min_multi_outlet_app_version' => env('SAAS_MIN_MULTI_OUTLET_APP_VERSION', '1.1.0'),

    /*
    | Paket langganan bawaan sebelum diubah dari Platform -> Pengaturan Layanan. Batas null
    | berarti tidak dibatasi (max_outlets minimal 1); harga per bulan (Rupiah) hanya informasi untuk toko, pembayaran
    | tetap dicatat manual oleh admin platform.
    */
    'plans' => [
        'trial' => ['label' => 'Uji Coba Pro', 'price' => null, 'max_users' => null, 'max_products' => null, 'max_outlets' => 1],
        'free' => ['label' => 'Gratis', 'price' => 0, 'max_users' => null, 'max_products' => null, 'max_outlets' => 1],
        'pro' => ['label' => 'Pro', 'price' => 20000, 'yearly_price' => 199000, 'max_users' => null, 'max_products' => null, 'max_outlets' => 5],
        'lifetime' => ['label' => 'Lifetime (Permanen)', 'price' => 499000, 'yearly_price' => null, 'max_users' => null, 'max_products' => null, 'max_outlets' => 5],
    ],

];
