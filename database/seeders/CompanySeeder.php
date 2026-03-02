<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Company;
use App\Models\User;

class CompanySeeder extends Seeder
{
    public function run(): void
    {
        // ── Company A: Grand Central (Retail) ──
        $userA = User::firstOrCreate(
            ['email' => 'company1@mail.com'],
            ['name' => 'Grand Central', 'password' => bcrypt('password')]
        );
        if ($userA) {
            $userA->assignRole('owner');
        }
        $companyA = Company::updateOrCreate(
            ['gst_number' => '27AAAAA7777A1Z5'],
            [
                'user_id'             => $userA->id,
                'company_name'         => 'Grand Central Retail Ltd',
                'pan_number'           => 'AAAAA7777A',
                'state'                => 'Maharashtra',
                'state_code'           => '27',
                'address'              => 'Shop No. 1, Grand Central Mall, LBS Marg',
                'city'                 => 'Mumbai',
                'pincode'              => '400086',
                'country'              => 'India',
                'email'                => 'accounts@grandcentral.in',
                'is_active'            => true,
            ]
        );

        $this->command?->info("▶ Seeding Retail: {$companyA->company_name}");
        (new NarrationHeadSeeder())->run($companyA); // Uses defaults
//        (new NarrationRuleSeeder())->run($companyA); // Uses defaults

        // ── Company B: Horizon Tech (SaaS) ──
        $userB = User::firstOrCreate(
            ['email' => 'company2@mail.com'],
            ['name' => 'Horizon Tech', 'password' => bcrypt('password')]
        );
        if ($userB) {
            $userB->assignRole('owner');
        }

        $companyB = Company::updateOrCreate(
            ['gst_number' => '29BBBBB8888B1Z6'],
            [
                'user_id'              => $userB->id,
                'company_name'         => 'Horizon Tech Solutions Pvt Ltd',
                'pan_number'           => 'BBBBB8888B',
                'state'                => 'Karnataka',
                'state_code'           => '29',
                'address'              => '4th Floor, Prestige Tech Park, ORR',
                'city'                 => 'Bengaluru',
                'pincode'              => '560103',
                'country'              => 'India',
                'email'                => 'finance@horizontech.in',
                'is_active'            => true,
            ]
        );

        $this->command?->info("▶ Seeding SaaS: {$companyB->company_name}");
        (new NarrationHeadSeeder())->run($companyB, $this->horizonTechHeads());
//        (new NarrationRuleSeeder())->run($companyB, $this->horizonTechRules());
    }

    private function horizonTechHeads(): array
    {
        return [
            ['name' => 'Revenue', 'slug' => 'revenue', 'type' => 'credit', 'color' => '#22c55e', 'icon' => 'currency-dollar', 'sub_heads' => [
                ['name' => 'SaaS Subscription', 'slug' => 'saas_subscription', 'ledger_code' => '4001'],
                ['name' => 'Consulting Fees', 'slug' => 'consulting_fees', 'ledger_code' => '4002'],
                ['name' => 'License Revenue', 'slug' => 'license_revenue', 'ledger_code' => '4003'],
            ]],
            ['name' => 'Advance Payment by Client', 'slug' => 'advance_payment', 'type' => 'credit', 'color' => '#059669', 'icon' => 'user-plus', 'sub_heads' => [
                ['name' => 'Project Advance', 'slug' => 'project_advance', 'ledger_code' => '2201', 'requires_party' => true],
            ]],
            ['name' => 'Investor Funding', 'slug' => 'investor_funding', 'type' => 'credit', 'color' => '#3b82f6', 'icon' => 'chart-bar', 'sub_heads' => [
                ['name' => 'Angel / Seed Round', 'slug' => 'seed_round', 'ledger_code' => '3001', 'requires_party' => true],
                ['name' => 'Venture Capital', 'slug' => 'vc_round', 'ledger_code' => '3002', 'requires_party' => true],
            ]],
            ['name' => 'Suspense', 'slug' => 'suspense_credit', 'type' => 'credit', 'color' => '#6b7280', 'icon' => 'question-mark-circle', 'sub_heads' => [
                ['name' => 'Unidentified Credit', 'slug' => 'unidentified_cr', 'ledger_code' => '9001'],
            ]],
            ['name' => 'Salary & Payroll', 'slug' => 'salary_debit', 'type' => 'debit', 'color' => '#f87171', 'icon' => 'users', 'sub_heads' => [
                ['name' => 'Engineering Staff', 'slug' => 'eng_salary', 'ledger_code' => '6101', 'requires_party' => true],
                ['name' => 'Non-Tech Staff', 'slug' => 'nontech_salary', 'ledger_code' => '6102', 'requires_party' => true],
                ['name' => 'Contractor Payments', 'slug' => 'contractor_pay', 'ledger_code' => '6103', 'requires_party' => true],
            ]],
            ['name' => 'Cloud & SaaS Tools', 'slug' => 'cloud_tools_debit', 'type' => 'debit', 'color' => '#0ea5e9', 'icon' => 'server-stack', 'sub_heads' => [
                ['name' => 'AWS / Cloud Hosting', 'slug' => 'cloud_hosting', 'ledger_code' => '6201'],
                ['name' => 'SaaS Subscriptions', 'slug' => 'saas_tools', 'ledger_code' => '6202'],
                ['name' => 'Domain & SSL', 'slug' => 'domain_ssl', 'ledger_code' => '6203'],
            ]],
            ['name' => 'Marketing & Ads', 'slug' => 'marketing_debit', 'type' => 'debit', 'color' => '#f59e0b', 'icon' => 'megaphone', 'sub_heads' => [
                ['name' => 'Google Ads', 'slug' => 'google_ads', 'ledger_code' => '6301'],
                ['name' => 'LinkedIn Ads', 'slug' => 'linkedin_ads', 'ledger_code' => '6302'],
                ['name' => 'Content / SEO', 'slug' => 'content_seo', 'ledger_code' => '6303'],
            ]],
            ['name' => 'Vendor Payments', 'slug' => 'vendor_debit', 'type' => 'debit', 'color' => '#b91c1c', 'icon' => 'truck', 'sub_heads' => [
                ['name' => 'Software Vendor', 'slug' => 'software_vendor', 'ledger_code' => '6401', 'requires_party' => true],
                ['name' => 'Agency Payment', 'slug' => 'agency_payment', 'ledger_code' => '6402', 'requires_party' => true],
            ]],
            ['name' => 'Office & Admin', 'slug' => 'office_debit', 'type' => 'debit', 'color' => '#ef4444', 'icon' => 'shopping-cart', 'sub_heads' => [
                ['name' => 'Office Rent', 'slug' => 'office_rent', 'ledger_code' => '6501'],
                ['name' => 'Utilities', 'slug' => 'utilities', 'ledger_code' => '6502'],
                ['name' => 'Office Supplies', 'slug' => 'office_supply', 'ledger_code' => '6503'],
            ]],
            ['name' => 'Travel & Conveyance', 'slug' => 'travel_debit', 'type' => 'debit', 'color' => '#2563eb', 'icon' => 'ticket', 'sub_heads' => [
                ['name' => 'Flight & Train', 'slug' => 'flight_train', 'ledger_code' => '6601'],
                ['name' => 'Cab / Taxi', 'slug' => 'taxi_ride', 'ledger_code' => '6602'],
                ['name' => 'Hotel Stay', 'slug' => 'hotel_stay', 'ledger_code' => '6603'],
            ]],
            ['name' => 'Legal & Professional', 'slug' => 'legal_debit', 'type' => 'debit', 'color' => '#1e293b', 'icon' => 'scale', 'sub_heads' => [
                ['name' => 'CA / Audit Fees', 'slug' => 'audit_fees', 'ledger_code' => '6701'],
                ['name' => 'Legal Counsel', 'slug' => 'legal_counsel', 'ledger_code' => '6702'],
                ['name' => 'IP & Patent Fees', 'slug' => 'ip_fees', 'ledger_code' => '6703'],
            ]],
            ['name' => 'Miscellaneous', 'slug' => 'misc_debit', 'type' => 'debit', 'color' => '#9ca3af', 'icon' => 'ellipsis-horizontal', 'sub_heads' => [
                ['name' => 'Bank Charges', 'slug' => 'bank_charges', 'ledger_code' => '6902'],
                ['name' => 'Other Misc', 'slug' => 'misc_other', 'ledger_code' => '6901'],
            ]],
        ];
    }

    private function horizonTechRules(): array
    {
        return [
            ['match' => 'subscription', 'type' => 'credit', 'sub' => 'saas_subscription', 'priority' => 1, 'note_template' => 'SaaS Subscription Revenue'],
            ['match' => 'consulting', 'type' => 'credit', 'sub' => 'consulting_fees', 'priority' => 1, 'note_template' => 'Professional Consulting Income'],
            ['match' => 'advance', 'type' => 'credit', 'sub' => 'project_advance', 'priority' => 1, 'note_template' => 'Client Project Advance'],
            ['match' => 'refund', 'type' => 'credit', 'sub' => 'unidentified_cr', 'priority' => 5, 'note_template' => 'Incoming Refund/Suspense'],
            ['match' => 'salary', 'type' => 'debit', 'sub' => 'eng_salary', 'priority' => 1, 'note_template' => 'Engineering Staff Salary'],
            ['match' => 'payroll', 'type' => 'debit', 'sub' => 'eng_salary', 'priority' => 2, 'note_template' => 'Monthly Payroll Process'],
            ['match' => 'contractor', 'type' => 'debit', 'sub' => 'contractor_pay', 'priority' => 1, 'note_template' => 'Gig/Contractor Payout'],
            ['match' => 'aws', 'type' => 'debit', 'sub' => 'cloud_hosting', 'priority' => 1, 'note_template' => 'AWS Infrastructure Charges'],
            ['match' => 'amazon web', 'type' => 'debit', 'sub' => 'cloud_hosting', 'priority' => 1, 'note_template' => 'Amazon Web Services Bill'],
            ['match' => 'digitalocean', 'type' => 'debit', 'sub' => 'cloud_hosting', 'priority' => 1, 'note_template' => 'Cloud Hosting: DigitalOcean'],
            ['match' => 'github', 'type' => 'debit', 'sub' => 'saas_tools', 'priority' => 1, 'note_template' => 'Github Organization Subscription'],
            ['match' => 'jira', 'type' => 'debit', 'sub' => 'saas_tools', 'priority' => 1, 'note_template' => 'Atlassian Jira/Confluence SaaS'],
            ['match' => 'slack', 'type' => 'debit', 'sub' => 'saas_tools', 'priority' => 1, 'note_template' => 'Slack Communication Tool'],
            ['match' => 'notion', 'type' => 'debit', 'sub' => 'saas_tools', 'priority' => 1, 'note_template' => 'Notion Workspace Subscription'],
            ['match' => 'google ads', 'type' => 'debit', 'sub' => 'google_ads', 'priority' => 1, 'note_template' => 'Google Search Ad Spend'],
            ['match' => 'linkedin', 'type' => 'debit', 'sub' => 'linkedin_ads', 'priority' => 1, 'note_template' => 'LinkedIn Marketing Spend'],
            ['match' => 'indigo', 'type' => 'debit', 'sub' => 'flight_train', 'priority' => 1, 'note_template' => 'Business Travel: IndiGo'],
            ['match' => 'irctc', 'type' => 'debit', 'sub' => 'flight_train', 'priority' => 1, 'note_template' => 'Train Ticket Booking'],
            ['match' => 'makemytrip', 'type' => 'debit', 'sub' => 'flight_train', 'priority' => 1, 'note_template' => 'Travel Agent Booking'],
            ['match' => 'uber', 'type' => 'debit', 'sub' => 'taxi_ride', 'priority' => 1, 'note_template' => 'Local Commute: Uber'],
            ['match' => 'ola', 'type' => 'debit', 'sub' => 'taxi_ride', 'priority' => 1, 'note_template' => 'Local Commute: Ola'],
            ['match' => 'audit fee', 'type' => 'debit', 'sub' => 'audit_fees', 'priority' => 1, 'note_template' => 'Annual Audit/CA Fees'],
            ['match' => 'legal', 'type' => 'debit', 'sub' => 'legal_counsel', 'priority' => 1, 'note_template' => 'Legal Advisory Services'],
            ['match' => 'bank charges', 'type' => 'debit', 'sub' => 'bank_charges', 'priority' => 1, 'note_template' => 'Bank Service Charges'],
        ];
    }
}
