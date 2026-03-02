<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Company;
use App\Models\InventoryItem;

class InventoryItemSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->itemDefinitions() as $gstNumber => $items) {
            $company = Company::where('gst_number', $gstNumber)->firstOrFail();

            foreach ($items as $item) {
                InventoryItem::updateOrCreate(
                    ['company_id' => $company->id, 'name' => $item['name']],
                    array_merge($item, ['company_id' => $company->id])
                );
            }

            $this->command?->info("  ✓ Inventory items seeded for: {$company->company_name}");
        }
    }

    private function itemDefinitions(): array
    {
        return [
            // ── Grand Central Retail Ltd (physical goods) ──────────────────
            '27AAAAA7777A1Z5' => [
                // Electronics
                ['name' => 'Samsung 55" 4K Smart TV',       'category' => 'Electronics', 'hsn_code' => '8528', 'unit' => 'Unit',  'rate' => 54999.00, 'gst_rate' => 18.00],
                ['name' => 'Philips Mixer Grinder 750W',     'category' => 'Electronics', 'hsn_code' => '8509', 'unit' => 'Unit',  'rate' => 3400.00,  'gst_rate' => 18.00],

                // Grocery / FMCG
                ['name' => 'Royal Basmati Rice (5kg Bag)',   'category' => 'Grocery',     'hsn_code' => '1006', 'unit' => 'Bag',   'rate' => 850.00,   'gst_rate' => 5.00],
                ['name' => 'Fortune Sunflower Oil (1L)',     'category' => 'Grocery',     'hsn_code' => '1512', 'unit' => 'Pouch', 'rate' => 145.00,   'gst_rate' => 5.00],

                // Apparel
                ['name' => "Levi's Men's Denim Jeans",      'category' => 'Apparel',     'hsn_code' => '6203', 'unit' => 'Pair',  'rate' => 2499.00,  'gst_rate' => 12.00],
                ['name' => 'Cotton Polo T-Shirt (Bulk)',     'category' => 'Apparel',     'hsn_code' => '6105', 'unit' => 'Piece', 'rate' => 450.00,   'gst_rate' => 12.00],

                // Home & Living
                ['name' => 'Prestige Pressure Cooker (5L)', 'category' => 'Home',        'hsn_code' => '7615', 'unit' => 'Unit',  'rate' => 1800.00,  'gst_rate' => 12.00],
            ],

            // ── Horizon Tech Solutions Pvt Ltd (software/services) ─────────
            '29BBBBB8888B1Z6' => [
                // SaaS Products
                ['name' => 'HorizonHR – Monthly Subscription',   'category' => 'SaaS',     'hsn_code' => '998314', 'unit' => 'License', 'rate' => 4999.00,  'gst_rate' => 18.00],
                ['name' => 'HorizonHR – Annual Subscription',    'category' => 'SaaS',     'hsn_code' => '998314', 'unit' => 'License', 'rate' => 49999.00, 'gst_rate' => 18.00],
                ['name' => 'HorizonPay – Payroll Module Add-on', 'category' => 'SaaS',     'hsn_code' => '998314', 'unit' => 'License', 'rate' => 1999.00,  'gst_rate' => 18.00],

                // Professional Services
                ['name' => 'Cloud Migration Consulting',         'category' => 'Services', 'hsn_code' => '998313', 'unit' => 'Hour',    'rate' => 3500.00,  'gst_rate' => 18.00],
                ['name' => 'Custom Development (per sprint)',    'category' => 'Services', 'hsn_code' => '998313', 'unit' => 'Sprint',  'rate' => 75000.00, 'gst_rate' => 18.00],
                ['name' => 'API Integration Package',           'category' => 'Services', 'hsn_code' => '998313', 'unit' => 'Package', 'rate' => 25000.00, 'gst_rate' => 18.00],

                // Support
                ['name' => 'Priority Support – Annual',         'category' => 'Support',  'hsn_code' => '998314', 'unit' => 'Year',    'rate' => 12000.00, 'gst_rate' => 18.00],
            ],
        ];
    }
}
