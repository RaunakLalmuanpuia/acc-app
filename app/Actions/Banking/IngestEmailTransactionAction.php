<?php

namespace App\Actions\Banking;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Services\Banking\NarrationPipelineService;
use Illuminate\Support\Facades\DB;

class IngestEmailTransactionAction
{
    public function __construct(private NarrationPipelineService $pipeline) {}

    public function execute(string $rawEmail, BankAccount $account): BankTransaction
    {
        // import_source = 'email' is set inside processFromEmail() → process().
        // If the same transaction already exists as 'sms', it is upgraded in-place.
        return DB::transaction(fn () => $this->pipeline->processFromEmail($rawEmail, $account));
    }
}
