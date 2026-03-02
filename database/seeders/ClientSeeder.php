<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Company;
use App\Models\Client;

class ClientSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->clientDefinitions() as $gstNumber => $clients) {
            $company = Company::where('gst_number', $gstNumber)->firstOrFail();

            foreach ($clients as $data) {
                Client::updateOrCreate(
                    ['company_id' => $company->id, 'email' => $data['email']],
                    array_merge($data, ['company_id' => $company->id])
                );
            }

            $this->command?->info("  ✓ Clients seeded for: {$company->company_name}");
        }
    }

    private function clientDefinitions(): array
    {
        return [
            // ── Grand Central Retail Ltd ───────────────────────────────────
            '27AAAAA7777A1Z5' => [
                [
                    'name'          => 'Infosys Guest House',
                    'email'         => 'admin@infosys-gh.com',
                    'phone'         => '+91 80 4116 7000',
                    'gst_number'    => '29AAAAA5678A1Z5',
                    'gst_type'      => 'regular',
                    'address'       => 'Electronic City, Phase 1',
                    'city'          => 'Bangalore',
                    'state'         => 'Karnataka',
                    'state_code'    => '29',
                    'pincode'       => '560100',
                    'payment_terms' => 'Net 30',
                    'is_active'     => true,
                ],
                [
                    'name'          => 'Marriott Hotel Supplies',
                    'email'         => 'purchase@marriott.com',
                    'phone'         => '+91 22 6693 3000',
                    'gst_number'    => '27BBBBB1234B1Z6',
                    'gst_type'      => 'regular',
                    'address'       => 'Juhu Tara Road',
                    'city'          => 'Mumbai',
                    'state'         => 'Maharashtra',
                    'state_code'    => '27',
                    'pincode'       => '400049',
                    'payment_terms' => 'Net 30',
                    'is_active'     => true,
                ],
                [
                    'name'          => 'Urban Clap Services',
                    'email'         => 'partners@urbanclap.com',
                    'phone'         => '+91 124 6700 100',
                    'gst_number'    => '06CCCCC9876C1Z7',
                    'gst_type'      => 'regular',
                    'address'       => 'Udyog Vihar, Phase IV',
                    'city'          => 'Gurgaon',
                    'state'         => 'Haryana',
                    'state_code'    => '06',
                    'pincode'       => '122015',
                    'payment_terms' => 'Net 15',
                    'is_active'     => true,
                ],
            ],

            // ── Horizon Tech Solutions Pvt Ltd ────────────────────────────
            '29BBBBB8888B1Z6' => [
                [
                    'name'          => 'Wipro Technologies',
                    'email'         => 'procurement@wipro.com',
                    'phone'         => '+91 80 2844 0011',
                    'gst_number'    => '29DDDDD1111D1Z8',
                    'gst_type'      => 'regular',
                    'address'       => 'Sarjapur Road, Doddakannelli',
                    'city'          => 'Bengaluru',
                    'state'         => 'Karnataka',
                    'state_code'    => '29',
                    'pincode'       => '560035',
                    'payment_terms' => 'Net 30',
                    'is_active'     => true,
                ],
                [
                    'name'          => 'Razorpay Software Pvt Ltd',
                    'email'         => 'billing@razorpay.com',
                    'phone'         => '+91 80 4709 5555',
                    'gst_number'    => '29EEEEE2222E1Z9',
                    'gst_type'      => 'regular',
                    'address'       => 'SJR Cyber, 22 Laskar Hosur Road',
                    'city'          => 'Bengaluru',
                    'state'         => 'Karnataka',
                    'state_code'    => '29',
                    'pincode'       => '560030',
                    'payment_terms' => 'Net 15',
                    'is_active'     => true,
                ],
                [
                    'name'          => 'Swiggy (Bundl Technologies)',
                    'email'         => 'enterprise@swiggy.in',
                    'phone'         => '+91 80 6777 0000',
                    'gst_number'    => '29FFFFF3333F1Z0',
                    'gst_type'      => 'regular',
                    'address'       => '5th Floor, Tower D, IBC Knowledge Park',
                    'city'          => 'Bengaluru',
                    'state'         => 'Karnataka',
                    'state_code'    => '29',
                    'pincode'       => '560029',
                    'payment_terms' => 'Net 30',
                    'is_active'     => true,
                ],
            ],
        ];
    }
}
