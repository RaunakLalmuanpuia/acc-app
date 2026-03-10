<?php

namespace App\Services\Banking;

use App\Ai\Agents\Narration\StatementDocumentParserAgent;
use App\DTOs\Banking\ParsedTransactionDTO;
use App\Services\Banking\CsvExcelStatementParser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;


class StatementParserService
{
    /**
     * Parse an uploaded bank statement file into a collection of ParsedTransactionDTOs.
     *
     * @return Collection<ParsedTransactionDTO>
     */

    public function __construct(
        private CsvExcelStatementParser $tabularParser,
    ) {}

    public function parse(UploadedFile $file): Collection
    {
        return match (strtolower($file->getClientOriginalExtension())) {
            'pdf', 'jpg', 'jpeg', 'png', 'webp' => $this->parseVisualDocument($file),
            'csv', 'xlsx', 'xls', 'xlsm', 'tsv'
            => $this->parseTabularFile($file),

            default       => throw ValidationException::withMessages([
                'statement' => 'Unsupported file type. Please upload a PDF, CSV, or Excel file.',
            ]),
        };
    }

    // ── Visual Documents (PDF & Images) ────────────────────────────────────

    /**
     * Pass the document directly to GPT-4o as an attachment.
     * GPT-4o reads the file visually and extracts all rows.
     *
     * @return Collection<ParsedTransactionDTO>
     */
    private function parseVisualDocument(UploadedFile $file): Collection
    {
        $response = StatementDocumentParserAgent::make()->prompt(
            'Extract all transaction rows from this attached bank statement.',
            attachments: [$file],
        );

        // Clean up potential markdown formatting just in case the AI ignores the instruction
        $jsonString = str_replace(['```json', '```'], '', $response->text);

        // Decode the string into an associative array
        $parsed = json_decode(trim($jsonString), true);

        $rows = $parsed['transactions'] ?? [];

        if (empty($rows)) {
            throw ValidationException::withMessages([
                'statement' => 'No transactions could be extracted from the document. Please check the file.',
            ]);
        }

        return collect($rows)->map(fn(array $row) => $this->rowToDto($row));
    }

    // ── CSV / Excel ────────────────────────────────────────────────────────────────

    /**
     * @return Collection<ParsedTransactionDTO>
     */
    private function parseTabularFile(UploadedFile $file): Collection
    {
//        dd($file);
        $result = $this->tabularParser->parse(
            $file->getRealPath(),
            $file->getClientOriginalExtension()
        );

        if (!empty($result['error'])) {
            throw ValidationException::withMessages([
                'statement' => $result['error'],
            ]);
        }

        $transactions = $result['transactions'] ?? [];

        if (empty($transactions)) {
            throw ValidationException::withMessages([
                'statement' => 'No transactions found in the uploaded file.',
            ]);
        }

        return collect($transactions)
            ->map(function ($row) {

                $type = match (strtolower($row['type'] ?? 'paid')) {
                    'received' => 'credit',
                    'paid'     => 'debit',
                    default    => 'debit',
                };

                return ParsedTransactionDTO::fromArray([
                    'raw_narration'    => $row['description'] ?? 'Unknown',
                    'type'             => $type,
                    'amount'           => (float) $row['amount'],
                    'bank_reference'   => '',
                    'party_name'       => null,
                    'transaction_date' => $row['date'],
                    'balance_after'    => null,
                    'bank_name'        => null,
                ]);
            })
            ->filter()
            ->values();
    }

    /**
     * Convert a PDF-parsed row array (already structured by AI) to a DTO.
     */
    private function rowToDto(array $row): ParsedTransactionDTO
    {
        return ParsedTransactionDTO::fromArray([
            'raw_narration'    => $row['raw_narration'] ?? 'Unknown',
            'type'             => $row['type'],
            'amount'           => $row['amount'],
            'bank_reference'   => $row['bank_reference'] ?? '',
            'party_name'       => $row['party_name'] ?? null,
            'transaction_date' => $row['date'],
            'balance_after'    => $row['balance_after'] ?? null,
            'bank_name'        => null,
        ]);
    }

}
