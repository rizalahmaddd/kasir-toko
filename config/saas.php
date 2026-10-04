<?php

return [

    /*
    | Lama masa uji coba toko baru sejak mendaftar.
    */
    'trial_days' => (int) env('SAAS_TRIAL_DAYS', 14),

    /*
    | Paket langganan bawaan sebelum diubah dari Platform -> Pengaturan Layanan. Batas null
    | berarti tidak dibatasi; harga per bulan (Rupiah) hanya informasi untuk toko, pembayaran
    | tetap dicatat manual oleh admin platform.
    */
    'plans' => [
        'trial' => ['label' => 'Uji Coba Pro', 'price' => null, 'max_users' => null, 'max_products' => null],
        'free' => ['label' => 'Gratis', 'price' => 0, 'max_users' => null, 'max_products' => null],
        'pro' => ['label' => 'Pro', 'price' => 20000, 'yearly_price' => 199000, 'max_users' => null, 'max_products' => null],
        'lifetime' => ['label' => 'Lifetime (Permanen)', 'price' => 499000, 'yearly_price' => null, 'max_users' => null, 'max_products' => null],
    ],

];
