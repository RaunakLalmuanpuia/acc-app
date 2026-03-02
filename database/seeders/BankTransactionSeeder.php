<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Models\Company;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\NarrationSubHead;

class BankTransactionSeeder extends Seeder
{
    public function run(): void
    {
        // Fixed: We load narrationHeads and then their subHeads
        // because company doesn't have a direct relation to subheads.
        Company::with([
            'bankAccounts',
            'invoices.client',
            'narrationHeads.subHeads'
        ])->get()->each(function ($company) {

            // Flatten all subheads for this company into one collection
            $subHeads = $company->narrationHeads->flatMap->subHeads;

            foreach ($company->bankAccounts as $account) {
                $this->seedAccountTransactions($company, $account, $subHeads);

                $this->command?->info(
                    "  ✓ Transactions seeded for: {$account->account_number}"
                );
            }
        });
    }

    private function seedAccountTransactions(
        Company $company,
        BankAccount $account,
        $subHeads
    ): void {
        $balance   = $account->opening_balance ?? 0;
        $startDate = Carbon::parse('2026-01-01');
        $batchId   = 'seed_' . now()->format('YmdHis');
        $invoices  = $company->invoices;

        for ($i = 0; $i < 80; $i++) {
            $date   = $startDate->copy()->addDays(rand(0, 50)); // Scaled to current year
            $type   = rand(1, 100) <= 45 ? 'credit' : 'debit';
            $amount = rand(1000, 150000);

            $invoice      = null;
            $isReconciled = false;

            // Invoice reconciliation logic
            if ($type === 'credit' && rand(1, 100) <= 35 && $invoices->isNotEmpty()) {
                $invoice      = $invoices->random();
                $amount       = $invoice->total_amount;
                $isReconciled = true;
            }

            $balance = ($type === 'credit') ? $balance + $amount : $balance - $amount;

            // Match subhead based on type (looking at parent head type)
            $subHead = $this->matchNarration($subHeads, $type);

            $rawNarration = $this->generateNarration(
                $subHead?->name ?? 'Transaction',
                $type,
                $invoice?->client?->name
            );

            $dedupHash = BankTransaction::makeDedupHash(
                $date->toDateString(),
                (float)$amount,
                $type,
                $account->account_number
            );

            BankTransaction::create([
                'bank_account_id'       => $account->id,
                'transaction_date'      => $date,
                'bank_reference'        => strtoupper(Str::random(12)),
                'raw_narration'         => $rawNarration,
                'type'                  => $type,
                'amount'                => $amount,
                'balance_after'         => $balance,

                'narration_head_id'     => $subHead?->narration_head_id,
                'narration_sub_head_id' => $subHead?->id,
                'narration_note'        => $subHead?->name,
                'party_name'            => $invoice?->client?->name,
                'party_reference'       => $invoice?->invoice_number,

                'narration_source'      => rand(1, 100) <= 50 ? 'rule_based' : 'ai_suggested',
                'ai_confidence'         => rand(60, 98),
                'ai_suggestions'        => [
                    'suggested_sub_head' => $subHead?->slug,
                    'confidence_reason'  => 'Keyword match detected'
                ],

                'review_status'         => rand(1, 100) <= 70 ? 'reviewed' : 'pending',
                'is_reconciled'         => $isReconciled,
                'reconciled_invoice_id' => $invoice?->id,
                'reconciled_at'         => $isReconciled ? $date : null,

                'dedup_hash'            => $dedupHash,
                'is_duplicate'          => false,
                'import_source'         => 'seeder',
                'import_batch_id'       => $batchId,
            ]);

            // Duplicate logic
            if (rand(1, 100) <= 5) {
                BankTransaction::create([
                    'bank_account_id'  => $account->id,
                    'transaction_date' => $date,
                    'bank_reference'   => 'DUP-' . strtoupper(Str::random(6)),
                    'raw_narration'    => $rawNarration,
                    'type'             => $type,
                    'amount'           => $amount,
                    'balance_after'    => $balance,
                    'dedup_hash'       => $dedupHash,
                    'is_duplicate'     => true,
                    'review_status'    => 'flagged',
                    'import_source'    => 'seeder',
                    'import_batch_id'  => $batchId,
                ]);
            }
        }

        $account->update(['current_balance' => $balance]);
    }

    private function matchNarration($subHeads, string $type): ?NarrationSubHead
    {
        // Filters by the type defined in the parent NarrationHead
        $filtered = $subHeads->filter(fn($sh) => $sh->narrationHead->type === $type);

        return $filtered->isNotEmpty() ? $filtered->random() : null;
    }

    private function generateNarration(string $subHead, string $type, ?string $partyName = null): string
    {
        $lower = strtolower($subHead);
        $utr = strtoupper(Str::random(3)) . rand(100000000000, 999999999999);
        $upiRef = rand(100000000000, 999999999999);
        $vpa = ($partyName ? Str::slug($partyName) : 'user') . '@' . collect(['okicici', 'oksbi', 'ybl'])->random();
        $name = $partyName ?? 'Generic Vendor';

        return match (true) {
            str_contains($lower, 'salary') => "NEFT-{$utr}-SALARY-PAY",
            str_contains($lower, 'rent')   => "IMPS-{$utr}-OFFICE-RENT",
            str_contains($lower, 'aws')    => "UPI-{$upiRef}-AWS-SERVICES",
            str_contains($lower, 'tax')    => "GST-PYMT-{$utr}",
            $type === 'credit'             => "UPI/CR/{$upiRef}/{$vpa}/{$name}",
            default                        => "UPI/DR/{$upiRef}/{$vpa}/{$name}",
        };
    }
}
