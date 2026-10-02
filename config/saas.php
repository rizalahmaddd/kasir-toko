<?php

return [

    /*
    | Lama masa uji coba toko baru sejak mendaftar.
    */
    'trial_days' => (int) env('SAAS_TRIAL_DAYS', 14),

    /*
    | Paket langganan. Batas null berarti tidak dibatasi. Harga dan tagihan diatur di luar
    | aplikasi; admin platform memperpanjang masa aktif dari panel Platform.
    */
    'plans' => [
        'trial' => ['label' => 'Uji Coba', 'max_users' => 3, 'max_products' => 100],
        'basic' => ['label' => 'Basic', 'max_users' => 3, 'max_products' => 1000],
        'pro' => ['label' => 'Pro', 'max_users' => null, 'max_products' => null],
    ],

];
