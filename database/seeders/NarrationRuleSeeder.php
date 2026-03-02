<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Company;
use App\Models\NarrationRule;
use App\Models\NarrationSubHead;

class NarrationRuleSeeder extends Seeder
{
    public function run(?Company $company = null, ?array $definitions = null): void
    {
        if (!$company) return;

        // Fetch all sub-heads for this company to avoid N+1 queries
        $subHeadCache = NarrationSubHead::whereHas('narrationHead', function ($q) use ($company) {
            $q->where('company_id', $company->id);
        })->get()->keyBy('slug');

        $rules = $definitions ?? $this->defaultRuleDefinitions();

        foreach ($rules as $rule) {
            $subHead = $subHeadCache->get($rule['sub']);

            if (!$subHead) {
                $this->command?->warn("  ⚠ Missing [{$rule['sub']}] for {$company->company_name}");
                continue;
            }

            NarrationRule::updateOrCreate(
                [
                    'match_type'        => 'contains',
                    'match_value'       => $rule['match'],
                    'transaction_type'  => $rule['type'],
                    'narration_head_id' => $subHead->narration_head_id,
                    'company_id'        => $company->id,
                ],
                [
                    'narration_sub_head_id' => $subHead->id,
                    'priority'              => $rule['priority'] ?? 1,
                    'note_template'         => $rule['note_template'] ?? null,
                    'is_active'             => true,
                ]
            );
        }
    }

    public function defaultRuleDefinitions(): array
    {
        return [
            ['match' => 'salary credit', 'type' => 'credit', 'sub' => 'service_fees', 'priority' => 1, 'note_template' => 'Professional Fee Inward'],
            ['match' => 'neft cr', 'type' => 'credit', 'sub' => 'product_sales', 'priority' => 2, 'note_template' => 'Sales Remittance'],
            ['match' => 'loan disbursed', 'type' => 'credit', 'sub' => 'bank_loan', 'priority' => 1, 'note_template' => 'Loan Payout Receipt'],
            ['match' => 'advance', 'type' => 'credit', 'sub' => 'project_advance', 'priority' => 1, 'note_template' => 'Customer Project Advance'],
            ['match' => 'grant', 'type' => 'credit', 'sub' => 'subsidy', 'priority' => 1, 'note_template' => 'Govt Subsidy Credit'],
            ['match' => 'refund', 'type' => 'credit', 'sub' => 'refund_cr', 'priority' => 1, 'note_template' => 'Transaction Reversal/Refund'],
            ['match' => 'uber', 'type' => 'debit', 'sub' => 'taxi_ride', 'priority' => 1, 'note_template' => 'Uber Trip Expenses'],
            ['match' => 'ola', 'type' => 'debit', 'sub' => 'taxi_ride', 'priority' => 1, 'note_template' => 'Ola Trip Expenses'],
            ['match' => 'rapido', 'type' => 'debit', 'sub' => 'taxi_ride', 'priority' => 1, 'note_template' => 'Local Delivery/Rapido'],
            ['match' => 'shell', 'type' => 'debit', 'sub' => 'fuel', 'priority' => 1, 'note_template' => 'Fuel Refill: Shell'],
            ['match' => 'petrol', 'type' => 'debit', 'sub' => 'fuel', 'priority' => 2, 'note_template' => 'General Fuel Spend'],
            ['match' => 'swiggy', 'type' => 'debit', 'sub' => 'pantry_exp', 'priority' => 1, 'note_template' => 'Pantry: Swiggy Order'],
            ['match' => 'zomato', 'type' => 'debit', 'sub' => 'pantry_exp', 'priority' => 1, 'note_template' => 'Pantry: Zomato Order'],
            ['match' => 'starbucks', 'type' => 'debit', 'sub' => 'food_catering', 'priority' => 1, 'note_template' => 'Business Meeting Refreshments'],
            ['match' => 'salary', 'type' => 'debit', 'sub' => 'net_salary', 'priority' => 1, 'note_template' => 'Monthly Staff Salary Payout'],
            ['match' => 'payroll', 'type' => 'debit', 'sub' => 'net_salary', 'priority' => 2, 'note_template' => 'Bulk Payroll Transfer'],
            ['match' => 'vendor', 'type' => 'debit', 'sub' => 'supplier_pay', 'priority' => 5, 'note_template' => 'General Vendor Payout'],
            ['match' => 'indigo', 'type' => 'debit', 'sub' => 'travel_booking', 'priority' => 1, 'note_template' => 'Indigo Flight Booking'],
            ['match' => 'airasia', 'type' => 'debit', 'sub' => 'travel_booking', 'priority' => 1, 'note_template' => 'AirAsia Flight Booking'],
            ['match' => 'makemytrip', 'type' => 'debit', 'sub' => 'travel_booking', 'priority' => 1, 'note_template' => 'Travel Agent Booking (MMT)'],
            ['match' => 'amazon', 'type' => 'debit', 'sub' => 'stock_purchase', 'priority' => 3, 'note_template' => 'Amazon Business Purchase'],
            ['match' => 'flipkart', 'type' => 'debit', 'sub' => 'stock_purchase', 'priority' => 3, 'note_template' => 'Flipkart Business Purchase'],
            ['match' => 'emi', 'type' => 'debit', 'sub' => 'bank_emi', 'priority' => 1, 'note_template' => 'Monthly Loan Installment'],
            ['match' => 'bank charges', 'type' => 'debit', 'sub' => 'bank_charges', 'priority' => 1, 'note_template' => 'Bank Service Fees'],
            ['match' => 'audit fee', 'type' => 'debit', 'sub' => 'prof_fees', 'priority' => 1, 'note_template' => 'Statutory Audit Fees'],
            ['match' => 'legal', 'type' => 'debit', 'sub' => 'prof_fees', 'priority' => 1, 'note_template' => 'Legal Advisory Fee'],
        ];
    }
}
