<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Company;
use App\Models\NarrationHead;
use App\Models\NarrationSubHead;

class NarrationHeadSeeder extends Seeder
{
    public function run(?Company $company = null, ?array $definitions = null): void
    {
        $heads = $definitions ?? $this->defaultHeadDefinitions();

        foreach ($heads as $index => $headData) {
            $subHeadsData = $headData['sub_heads'];
            unset($headData['sub_heads']);

            $head = NarrationHead::updateOrCreate(
                ['slug' => $headData['slug'], 'company_id' => $company?->id],
                array_merge($headData, [
                    'company_id' => $company?->id,
                    'sort_order' => $index,
                    'is_active'  => true,
                    'is_system'  => true,
                ])
            );

            foreach ($subHeadsData as $sIndex => $sub) {
                NarrationSubHead::updateOrCreate(
                    ['narration_head_id' => $head->id, 'slug' => $sub['slug']],
                    array_merge($sub, [
                        'narration_head_id'  => $head->id,
                        'sort_order'         => $sIndex,
                        'is_active'          => true,
                        'is_system'          => true,
                        'requires_reference' => $sub['requires_reference'] ?? false,
                        'requires_party'     => $sub['requires_party'] ?? false,
                    ])
                );
            }
        }
    }

    public function defaultHeadDefinitions(): array
    {
        return [
            ['name' => 'Revenue', 'slug' => 'revenue', 'type' => 'credit', 'color' => '#22c55e', 'icon' => 'currency-dollar', 'sub_heads' => [
                ['name' => 'Product Sales', 'slug' => 'product_sales', 'ledger_code' => '4001'],
                ['name' => 'Service Fees', 'slug' => 'service_fees', 'ledger_code' => '4002'],
            ]],
            ['name' => 'Loan', 'slug' => 'loan_credit', 'type' => 'credit', 'color' => '#10b981', 'icon' => 'banknotes', 'sub_heads' => [
                ['name' => 'Bank Loan Disbursal', 'slug' => 'bank_loan', 'ledger_code' => '2101', 'requires_reference' => true],
                ['name' => 'Unsecured Loan', 'slug' => 'unsecured_loan', 'ledger_code' => '2102', 'requires_party' => true],
            ]],
            ['name' => 'Advance Payment by Client', 'slug' => 'advance_payment', 'type' => 'credit', 'color' => '#059669', 'icon' => 'user-plus', 'sub_heads' => [
                ['name' => 'Project Advance', 'slug' => 'project_advance', 'ledger_code' => '2201', 'requires_party' => true],
            ]],
            ['name' => 'Suspense', 'slug' => 'suspense_credit', 'type' => 'credit', 'color' => '#6b7280', 'icon' => 'question-mark-circle', 'sub_heads' => [
                ['name' => 'Unidentified Credit', 'slug' => 'unidentified_cr', 'ledger_code' => '9001'],
            ]],
            ['name' => 'Investment', 'slug' => 'investment_credit', 'type' => 'credit', 'color' => '#3b82f6', 'icon' => 'chart-bar', 'sub_heads' => [
                ['name' => 'Capital Infusion', 'slug' => 'capital_infusion', 'ledger_code' => '3001'],
                ['name' => 'FD Liquidation', 'slug' => 'fd_liquidation', 'ledger_code' => '1201'],
            ]],
            ['name' => 'Government Grant', 'slug' => 'gov_grant', 'type' => 'credit', 'color' => '#8b5cf6', 'icon' => 'building-library', 'sub_heads' => [
                ['name' => 'Subsidy Received', 'slug' => 'subsidy', 'ledger_code' => '4301'],
            ]],
            ['name' => 'Corporate CSR Grant', 'slug' => 'csr_grant', 'type' => 'credit', 'color' => '#d946ef', 'icon' => 'heart', 'sub_heads' => [
                ['name' => 'CSR Project Funding', 'slug' => 'csr_funding', 'ledger_code' => '4303'],
            ]],
            ['name' => 'Transaction Reversal', 'slug' => 'reversal_credit', 'type' => 'credit', 'color' => '#f43f5e', 'icon' => 'arrow-path', 'sub_heads' => [
                ['name' => 'Refund Received', 'slug' => 'refund_cr', 'ledger_code' => '4902'],
            ]],
            ['name' => 'Expense', 'slug' => 'general_expense', 'type' => 'debit', 'color' => '#ef4444', 'icon' => 'shopping-cart', 'sub_heads' => [
                ['name' => 'General Office Expense', 'slug' => 'office_exp', 'ledger_code' => '6001'],
            ]],
            ['name' => 'Salary', 'slug' => 'salary_debit', 'type' => 'debit', 'color' => '#f87171', 'icon' => 'users', 'sub_heads' => [
                ['name' => 'Staff Salary', 'slug' => 'net_salary', 'ledger_code' => '6101', 'requires_party' => true],
            ]],
            ['name' => 'Vendor Payments', 'slug' => 'vendor_debit', 'type' => 'debit', 'color' => '#b91c1c', 'icon' => 'truck', 'sub_heads' => [
                ['name' => 'Supplier Payment', 'slug' => 'supplier_pay', 'ledger_code' => '6201', 'requires_party' => true],
            ]],
            ['name' => 'F&B', 'slug' => 'fnb_debit', 'type' => 'debit', 'color' => '#f97316', 'icon' => 'cake', 'sub_heads' => [
                ['name' => 'Food & Catering', 'slug' => 'food_catering', 'ledger_code' => '6301'],
                ['name' => 'Pantry Expenses', 'slug' => 'pantry_exp', 'ledger_code' => '6302'],
            ]],
            ['name' => 'Hardware & Tools', 'slug' => 'hardware_debit', 'type' => 'debit', 'color' => '#4b5563', 'icon' => 'wrench', 'sub_heads' => [
                ['name' => 'Consumable Tools', 'slug' => 'small_tools', 'ledger_code' => '6402'],
            ]],
            ['name' => 'Inventory', 'slug' => 'inventory_debit', 'type' => 'debit', 'color' => '#1e3a8a', 'icon' => 'archive-box', 'sub_heads' => [
                ['name' => 'Direct Material Purchase', 'slug' => 'stock_purchase', 'ledger_code' => '5001'],
            ]],
            ['name' => 'Tickets and Hotels', 'slug' => 'travel_debit', 'type' => 'debit', 'color' => '#2563eb', 'icon' => 'ticket', 'sub_heads' => [
                ['name' => 'Travel Booking', 'slug' => 'travel_booking', 'ledger_code' => '6501'],
            ]],
            ['name' => 'Loan Payment/EMI', 'slug' => 'emi_debit', 'type' => 'debit', 'color' => '#065f46', 'icon' => 'credit-card', 'sub_heads' => [
                ['name' => 'Bank EMI', 'slug' => 'bank_emi', 'ledger_code' => '2103'],
            ]],
            ['name' => 'Loans & Advances', 'slug' => 'loans_advances_debit', 'type' => 'debit', 'color' => '#0f766e', 'icon' => 'hand-raised', 'sub_heads' => [
                ['name' => 'Employee Advance', 'slug' => 'emp_advance', 'ledger_code' => '1301', 'requires_party' => true],
                ['name' => 'Security Deposits', 'slug' => 'sec_deposit', 'ledger_code' => '1302'],
            ]],
            ['name' => 'Conveyance', 'slug' => 'conveyance_debit', 'type' => 'debit', 'color' => '#ea580c', 'icon' => 'map-pin', 'sub_heads' => [
                ['name' => 'Uber / Taxi', 'slug' => 'taxi_ride', 'ledger_code' => '6601'],
                ['name' => 'Fuel/Petrol', 'slug' => 'fuel', 'ledger_code' => '6602'],
            ]],
            ['name' => 'Investment', 'slug' => 'investment_debit', 'type' => 'debit', 'color' => '#3b82f6', 'icon' => 'presentation-chart-line', 'sub_heads' => [
                ['name' => 'Mutual Funds/Stocks', 'slug' => 'market_invest', 'ledger_code' => '1202'],
            ]],
            ['name' => 'Legal/CA', 'slug' => 'legal_debit', 'type' => 'debit', 'color' => '#1e293b', 'icon' => 'scale', 'sub_heads' => [
                ['name' => 'Professional Fees', 'slug' => 'prof_fees', 'ledger_code' => '6801'],
            ]],
            ['name' => 'Capitalized Investment', 'slug' => 'capitalized_debit', 'type' => 'debit', 'color' => '#7c3aed', 'icon' => 'building-office', 'sub_heads' => [
                ['name' => 'Fixed Asset Purchase', 'slug' => 'fixed_asset', 'ledger_code' => '1001'],
                ['name' => 'Machinery', 'slug' => 'machinery', 'ledger_code' => '1002'],
            ]],
            ['name' => 'Reimbursement', 'slug' => 'reimbursement_debit', 'type' => 'debit', 'color' => '#0891b2', 'icon' => 'receipt-refund', 'sub_heads' => [
                ['name' => 'Employee Reimbursement', 'slug' => 'emp_reimburse', 'ledger_code' => '6104', 'requires_party' => true],
            ]],
            ['name' => 'Miscellaneous', 'slug' => 'misc_debit', 'type' => 'debit', 'color' => '#9ca3af', 'icon' => 'ellipsis-horizontal', 'sub_heads' => [
                ['name' => 'Bank Charges', 'slug' => 'bank_charges', 'ledger_code' => '6902'],
                ['name' => 'Other Misc', 'slug' => 'misc_other', 'ledger_code' => '6901'],
            ]],
        ];
    }
}
