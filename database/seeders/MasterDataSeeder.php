<?php

namespace Database\Seeders;

use App\Models\Customer;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Data demo untuk lingkungan pengembangan, bukan data produksi nyata. Event model dimatikan
 * supaya pengisian massal tidak mengirim notifikasi "pelanggan baru" ke setiap admin.
 */
class MasterDataSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $customers = [
            'CUST-0001' => ['name' => 'PT Sinar Nusantara', 'type' => 'Distributor', 'contact_person' => 'Budi Santoso', 'payment_term_days' => 30],
            'CUST-0002' => ['name' => 'CV Maju Bersama', 'type' => 'Retail', 'contact_person' => 'Rina Wijaya', 'payment_term_days' => 14],
            'CUST-0003' => ['name' => 'PT Kencana Abadi', 'type' => 'Korporat', 'contact_person' => 'Andi Pratama', 'payment_term_days' => 45],
            'CUST-0004' => ['name' => 'Toko Sumber Rejeki', 'type' => 'Retail', 'contact_person' => 'Siti Aminah', 'payment_term_days' => 0],
        ];

        foreach ($customers as $code => $attributes) {
            Customer::firstOrCreate(['code' => $code], $attributes);
        }

        Customer::factory()->count(20)->create();
    }
}
