<?php

namespace App\Actions\Banking;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Services\Banking\NarrationPipelineService;
use App\Services\Banking\StatementParserService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ProcessStatementAction
{
    public function __construct(
        private StatementParserService   $parser,
        private NarrationPipelineService $pipeline,
    ) {}

    /**
     * Parse and ingest a bank statement file.
     *
     * @return array{
     *   batch_id: string,
     *   total: int,
     *   imported: int,
     *   duplicates: int,
     *   failed: int,
     *   transactions: array
     * }
     */
    public function execute(UploadedFile $file, BankAccount $account): array
    {
        // 1. Parse file → collection of ParsedTransactionDTOs
        $dtos = $this->parser->parse($file);

        if ($dtos->isEmpty()) {
            return [
                'batch_id'     => null,
                'total'        => 0,
                'imported'     => 0,
                'duplicates'   => 0,
                'failed'       => 0,
                'transactions' => [],
                'message'      => 'No transactions found in the uploaded file.',
            ];
        }

        $batchId = (string) Str::uuid();
        $results = [
            'batch_id'     => $batchId,
            'total'        => $dtos->count(),
            'imported'     => 0,
            'duplicates'   => 0,
            'failed'       => 0,
            'transactions' => [],
        ];

        // 2. Process each row through the narration pipeline
        foreach ($dtos as $dto) {
            try {
                $transaction = DB::transaction(function () use ($dto, $account, $batchId) {
                    $t = $this->pipeline->process($dto, $account);

                    // Tag every transaction with the batch so the upload can be tracked
                    $t->update([
                        'import_batch_id' => $batchId,
                        'import_source'   => 'statement',
                    ]);

                    return $t;
                });

                if ($transaction->is_duplicate) {
                    $results['duplicates']++;
                } else {
                    $results['imported']++;
                }

                $results['transactions'][] = $this->summarise($transaction);

            } catch (Throwable $e) {
                $results['failed']++;
                $results['transactions'][] = [
                    'status'       => 'failed',
                    'raw_narration' => $dto->rawNarration,
                    'amount'       => $dto->amount,
                    'date'         => $dto->transactionDate->toDateString(),
                    'error'        => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    private function summarise(BankTransaction $t): array
    {
        return [
            'status'                => $t->is_duplicate ? 'duplicate' : 'imported',
            'id'                    => $t->id,
            'transaction_date'      => $t->transaction_date->toDateString(),
            'type'                  => $t->type,
            'amount'                => $t->amount,
            'raw_narration'         => $t->raw_narration,
            'narration_head_id'     => $t->narration_head_id,
            'narration_sub_head_id' => $t->narration_sub_head_id,
            'narration_note'        => $t->narration_note,
            'narration_source'      => $t->narration_source,
            'ai_confidence'         => $t->ai_confidence,
            'review_status'         => $t->review_status,
            'is_duplicate'          => $t->is_duplicate,
        ];
    }
}
