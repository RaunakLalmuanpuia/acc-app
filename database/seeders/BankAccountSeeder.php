<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Company;
use App\Models\BankAccount;

class BankAccountSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->accountDefinitions() as $gstNumber => $accounts) {
            $company = Company::where('gst_number', $gstNumber)->firstOrFail();

            foreach ($accounts as $account) {
                BankAccount::updateOrCreate(
                    ['account_number' => $account['account_number']],
                    array_merge($account, ['company_id' => $company->id])
                );
            }

            $this->command?->info("  ✓ Bank accounts seeded for: {$company->company_name}");
        }
    }

    private function accountDefinitions(): array
    {
        return [
            // ── Grand Central Retail Ltd ───────────────────────────────────
            '27AAAAA7777A1Z5' => [
                [
                    'account_name'         => 'ICICI Current Account',
                    'bank_name'            => 'ICICI Bank',
                    'account_number'       => '001234567890',
                    'ifsc_code'            => 'ICIC0000012',
                    'branch'               => 'LBS Marg, Mumbai',
                    'account_type'         => 'current',
                    'currency'             => 'INR',
                    'opening_balance'      => 500000.00,
                    'opening_balance_date' => '2026-01-01',
                    'current_balance'      => 500000.00,
                    'is_primary'           => true,
                    'is_active'            => true,
                ],
                [
                    'account_name'         => 'HDFC Savings Account',
                    'bank_name'            => 'HDFC Bank',
                    'account_number'       => '009876543210',
                    'ifsc_code'            => 'HDFC0000456',
                    'branch'               => 'Andheri West, Mumbai',
                    'account_type'         => 'savings',
                    'currency'             => 'INR',
                    'opening_balance'      => 200000.00,
                    'opening_balance_date' => '2026-01-01',
                    'current_balance'      => 200000.00,
                    'is_primary'           => false,
                    'is_active'            => true,
                ],
            ],

            // ── Horizon Tech Solutions Pvt Ltd ────────────────────────────
            '29BBBBB8888B1Z6' => [
                [
                    'account_name'         => 'HDFC Current Account',
                    'bank_name'            => 'HDFC Bank',
                    'account_number'       => '112233445566',
                    'ifsc_code'            => 'HDFC0001234',
                    'branch'               => 'Koramangala, Bengaluru',
                    'account_type'         => 'current',
                    'currency'             => 'INR',
                    'opening_balance'      => 1000000.00,
                    'opening_balance_date' => '2026-01-01',
                    'current_balance'      => 1000000.00,
                    'is_primary'           => true,
                    'is_active'            => true,
                ],
                [
                    'account_name'         => 'Kotak Startup Account',
                    'bank_name'            => 'Kotak Mahindra Bank',
                    'account_number'       => '998877665544',
                    'ifsc_code'            => 'KKBK0000789',
                    'branch'               => 'Indiranagar, Bengaluru',
                    'account_type'         => 'current',
                    'currency'             => 'INR',
                    'opening_balance'      => 750000.00,
                    'opening_balance_date' => '2026-01-01',
                    'current_balance'      => 750000.00,
                    'is_primary'           => false,
                    'is_active'            => true,
                ],
            ],
        ];
    }
}
