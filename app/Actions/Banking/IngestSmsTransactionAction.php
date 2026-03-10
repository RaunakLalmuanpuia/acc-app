<?php

namespace App\Actions\Banking;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Services\Banking\NarrationPipelineService;
use Illuminate\Support\Facades\DB;

class IngestSmsTransactionAction
{
    public function __construct(private NarrationPipelineService $pipeline) {}

    public function execute(string $rawSms, BankAccount $account): BankTransaction
    {
        return DB::transaction(fn () => $this->pipeline->processFromSms($rawSms, $account));
    }
}
