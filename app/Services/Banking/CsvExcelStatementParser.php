<?php

namespace App\Services\Banking;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class CsvExcelStatementParser
{
    public function parse(string $filePath, string $extension): array
    {
        try {
//            dd($extension);
            $extension = strtolower($extension);
            if (in_array($extension, ['xlsx', 'xls', 'xlsm'])) {
                return $this->parseExcel($filePath);
            } elseif (in_array($extension, ['csv', 'tsv'])) {
                return $this->parseCsv($filePath, $extension === 'tsv' ? "\t" : ',');
            } else {
                throw new Exception('Unsupported file type: ' . $extension);
            }

        } catch (Exception $e) {
            Log::error('CSV/Excel parsing failed: ' . $e->getMessage());
            return ['transactions' => [], 'total_deposits' => 0, 'total_withdrawals' => 0, 'error' => 'CSV/Excel Error: ' . $e->getMessage(),];
        }
    }

    // -------------------------------------------------------------------------
    // FILE LOADERS
    // -------------------------------------------------------------------------

    protected function parseExcel($filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $data = $sheet->toArray(null, true, true, true);

        Log::info('Excel loaded: ' . count($data) . ' rows');

//        foreach (array_slice($data, 0, 30, true) as $i => $row) {
//            Log::debug("Raw row {$i}: " . json_encode(array_values($row)));
//        }

        return $this->processTabularData($data);
    }

    protected function parseCsv($filePath, $delimiter = ','): array
    {
        $data = [];
        $rowIndex = 1;

        if (($handle = fopen($filePath, 'r')) !== false) {
            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                $rowData = [];
                foreach ($row as $colIndex => $value) {
                    $rowData[$this->getColumnLetter($colIndex + 1)] = $value;
                }
                $data[$rowIndex] = $rowData;
                $rowIndex++;
            }
            fclose($handle);
        }

        Log::info('CSV loaded: ' . count($data) . ' rows');

//        foreach (array_slice($data, 0, 30, true) as $i => $row) {
//            Log::debug("Raw row {$i}: " . json_encode(array_values($row)));
//        }

        return $this->processTabularData($data);
    }

    // -------------------------------------------------------------------------
    // LAYOUT DETECTION & ROUTING
    // -------------------------------------------------------------------------

    protected function processTabularData($data): array
    {
        if (empty($data)) {
            return ['transactions' => [], 'total_deposits' => 0, 'total_withdrawals' => 0, 'error' => 'No data found in file',];
        }

        $layoutType = $this->detectLayoutType($data);
        Log::info('Detected layout type: ' . $layoutType);

        if ($layoutType === 'single_column_statement') {
            return $this->processSingleColumnStatement($data);
        }

        if ($layoutType === 'vertical') {
            return $this->processVerticalLayout($data);
        }

        if ($layoutType === 'single_column') {
            $result = $this->processSingleColumnLayout($data);
            if (!empty($result['transactions'])) {
                return $result;
            }
        }

        // Standard horizontal table
        $headers = $this->detectHeaders($data);

        if (!$headers) {
            $result = $this->processSingleColumnLayout($data);
            if (!empty($result['transactions'])) {
                return $result;
            }

            Log::warning('Could not detect headers automatically');
            return ['transactions' => [], 'total_deposits' => 0, 'total_withdrawals' => 0, 'error' => 'Could not detect transaction columns. Please ensure your file has Date, Description, and Amount columns.',];
        }

        Log::info('Detected headers', $headers);
        $rawTransactions = $this->extractTransactions($data, $headers);
        Log::info('Extracted ' . count($rawTransactions) . ' raw transactions');

        return $this->classifyAndCalculate($rawTransactions);
    }

    /**
     * Classify the overall layout of the spreadsheet data:
     *
     *  single_column_statement  – bank statement with metadata block + tab-delimited
     *                             transactions all packed into column A
     *  vertical                 – clean "Label: Value" rows, one field per row
     *  single_column            – rows are concatenated transaction strings
     *  horizontal               – normal multi-column table
     */
    protected function detectLayoutType($data): string
    {
        $sample = array_slice($data, 0, 30, true);
        $totalNonEmpty = 0;
        $singleColumnRows = 0;
        $multiColumnRows = 0;
        $labelValueRows = 0;
        $metadataRows = 0;
        $tabDelimitedRows = 0;

        $metadataPatterns = ['/account\s*(name|number|no|holder)/i', '/address/i', '/branch/i', '/ifsc|ifs\s*code/i', '/statement\s*(period|date|of\s*account)/i', '/customer\s*(id|name)/i', '/opening\s*balance/i', '/drawing\s*power/i', '/interest\s*rate/i', '/micr|cif\s*no/i', '/^(india|[a-z\s]+-\d{6})$/i',];

        foreach ($sample as $row) {
            $nonEmpty = array_filter($row, fn($v) => trim((string)$v) !== '');
            if (empty($nonEmpty)) {
                continue;
            }

            $totalNonEmpty++;
            $colCount = count($nonEmpty);

            if ($colCount === 1) {
                $singleColumnRows++;
                $val = trim((string)array_values($nonEmpty)[0]);

                if (str_contains($val, "\t")) {
                    $tabDelimitedRows++;
                }

                if (preg_match('/^[^:]{2,40}:\s*.+/', $val)) {
                    $labelValueRows++;
                }

                foreach ($metadataPatterns as $pattern) {
                    if (preg_match($pattern, $val)) {
                        $metadataRows++;
                        break;
                    }
                }
            } else {
                $multiColumnRows++;
            }
        }

        if ($totalNonEmpty === 0) {
            return 'unknown';
        }

        $singleRatio = $singleColumnRows / $totalNonEmpty;

        if ($singleRatio > 0.8) {
            // Tab-delimited rows or a metadata block → full bank statement in column A
            if ($tabDelimitedRows >= 1 || $metadataRows >= 2) {
                return 'single_column_statement';
            }

            // Pure label:value rows → vertical key-value format
            if ($singleColumnRows > 0 && ($labelValueRows / $singleColumnRows) > 0.6) {
                return 'vertical';
            }

            return 'single_column';
        }

        return 'horizontal';
    }

    // -------------------------------------------------------------------------
    // SINGLE-COLUMN STATEMENT
    // Handles bank exports (SBI, HDFC, Axis, etc.) where the entire statement
    // is packed into column A as tab-separated rows.
    // -------------------------------------------------------------------------

    protected function processSingleColumnStatement($data): array
    {
        $headerRowIndex = null;
        $headerFields = null;

        foreach ($data as $rowIndex => $row) {
            $val = trim((string)($row['A'] ?? array_values($row)[0] ?? ''));

            if (empty($val) || !str_contains($val, "\t")) {
                continue;
            }

            $fields = $this->detectTabHeader($val);
            if ($fields !== null) {
                $headerRowIndex = $rowIndex;
                $headerFields = $fields;
                Log::info("Tab-delimited header at row {$rowIndex}: {$val}");
                Log::info('Field index map: ' . json_encode($headerFields));
                break;
            }
        }

        if ($headerRowIndex === null || $headerFields === null) {
            Log::warning('No tab-delimited header found, falling back to concatenated parsing');
            return $this->processSingleColumnLayout($data);
        }

        return $this->extractTabDelimitedTransactions($data, $headerRowIndex, $headerFields);
    }

    /**
     * Check whether a tab-separated string is a transaction header row.
     * Returns a map of field name => tab column index, or null if not a header.
     */
    protected function detectTabHeader(string $line): ?array
    {
        $parts = explode("\t", $line);
        $map = [];

        $dateKeywords = ['date', 'txn date', 'value date', 'trans date', 'posting date'];
        $descKeywords = ['description', 'narration', 'particulars', 'details', 'remarks', 'narrative'];
        $debitKeywords = ['debit', 'withdrawal', 'withdrawals', 'dr', 'paid'];
        $creditKeywords = ['credit', 'deposit', 'deposits', 'cr', 'received'];
        $amountKeywords = ['amount', 'net amount', 'transaction amount'];
        $balanceKeywords = ['balance', 'closing balance', 'available balance', 'running balance'];
        $typeKeywords = ['type', 'dr/cr', 'cr/dr', 'txn type'];
        $refKeywords = ['ref', 'cheque', 'chq', 'reference', 'ref no', 'chq no'];
        $branchKeywords = ['branch', 'branch code'];

        foreach ($parts as $i => $part) {
            $normalized = preg_replace('/\s+/', ' ', strtolower(trim($part)));
            $normalized = preg_replace('/[^a-z0-9 \/]/', '', $normalized);

            if (empty($normalized)) {
                continue;
            }

            if (!isset($map['date']) && $this->matchesAny($normalized, $dateKeywords)) $map['date'] = $i; elseif (!isset($map['description']) && $this->matchesAny($normalized, $descKeywords)) $map['description'] = $i;
            elseif (!isset($map['debit']) && $this->matchesAny($normalized, $debitKeywords)) $map['debit'] = $i;
            elseif (!isset($map['credit']) && $this->matchesAny($normalized, $creditKeywords)) $map['credit'] = $i;
            elseif (!isset($map['amount']) && $this->matchesAny($normalized, $amountKeywords)) $map['amount'] = $i;
            elseif (!isset($map['balance']) && $this->matchesAny($normalized, $balanceKeywords)) $map['balance'] = $i;
            elseif (!isset($map['type']) && $this->matchesAny($normalized, $typeKeywords)) $map['type'] = $i;
            elseif (!isset($map['ref']) && $this->matchesAny($normalized, $refKeywords)) $map['ref'] = $i;
            elseif (!isset($map['branch']) && $this->matchesAny($normalized, $branchKeywords)) $map['branch'] = $i;
        }

        // Debit/credit columns are more specific than a generic amount column
        if (isset($map['debit']) || isset($map['credit'])) {
            unset($map['amount']);
        }

        $hasDate = isset($map['date']);
        $hasDesc = isset($map['description']);
        $hasMoney = isset($map['debit']) || isset($map['credit']) || isset($map['amount']);

        if (!$hasDate || !$hasDesc || !$hasMoney) {
            return null;
        }

        return $map;
    }

    /**
     * Split each data row on \t and extract fields using the index map
     * produced by detectTabHeader().
     */
    protected function extractTabDelimitedTransactions($data, int $headerRowIndex, array $fieldMap): array
    {
        $transactions = [];

        foreach ($data as $rowIndex => $row) {
            if ($rowIndex <= $headerRowIndex) {
                continue;
            }

            $line = (string)($row['A'] ?? array_values($row)[0] ?? '');

            if (empty(trim($line))) {
                continue;
            }

            if (!str_contains($line, "\t")) {
                continue;
            }

            if ($this->isTotalRow(['A' => $line])) {
                continue;
            }

            $parts = explode("\t", $line);

            $get = fn(string $field) => isset($fieldMap[$field]) ? trim($parts[$fieldMap[$field]] ?? '') : null;

            $dateVal = $get('date');
            if (empty($dateVal) || !$this->isValidDate($dateVal)) {
                continue;
            }

            $type = null;
            if (isset($fieldMap['type'])) {
                $t = strtolower($get('type') ?? '');
                if (str_contains($t, 'cr') || str_contains($t, 'credit')) $type = 'received'; elseif (str_contains($t, 'dr') || str_contains($t, 'debit')) $type = 'paid';
            }

            $transactions[] = ['date' => $dateVal, 'description' => $get('description') ?? '', 'debit' => $this->parseAmount($get('debit')), 'credit' => $this->parseAmount($get('credit')), 'amount' => $this->parseAmount($get('amount')), 'balance' => $this->parseAmount($get('balance')), 'type' => $type,];
        }

        Log::info('Tab-delimited extraction: ' . count($transactions) . ' transactions');

        if (empty($transactions)) {
            Log::warning('Tab extraction yielded 0 transactions, falling back to concatenated parsing');
            return $this->processSingleColumnLayout($data);
        }

        return $this->classifyAndCalculate($transactions);
    }

    // -------------------------------------------------------------------------
    // HORIZONTAL LAYOUT (standard multi-column table)
    // -------------------------------------------------------------------------

    protected function detectHeaders($data): ?array
    {
        $dateKeywords = ['date', 'txn date', 'transaction date', 'value date', 'posting date', 'trans date'];
        $descKeywords = ['description', 'particulars', 'narration', 'details', 'remarks', 'transaction', 'narrative'];
        $debitKeywords = ['debit', 'withdrawal', 'withdrawals', 'dr', 'paid', 'payment', 'debit amount'];
        $creditKeywords = ['credit', 'deposit', 'deposits', 'cr', 'received', 'receipt', 'credit amount'];
        $amountKeywords = ['amount', 'value', 'transaction amount', 'net amount'];
        $typeKeywords = ['type', 'dr/cr', 'transaction type', 'txn type', 'cr/dr'];
        $balanceKeywords = ['balance', 'closing balance', 'running balance', 'available balance', 'ledger balance'];

        foreach (array_slice($data, 0, 30, true) as $rowIndex => $row) {
            $found = ['date_col' => null, 'description_col' => null, 'amount_col' => null, 'debit_col' => null, 'credit_col' => null, 'type_col' => null, 'balance_col' => null,];

            foreach ($row as $col => $value) {
                if ($value === null) {
                    continue;
                }

                $normalized = preg_replace('/\s+/', ' ', strtolower(trim((string)$value)));
                $normalized = preg_replace('/[^a-z0-9 \/]/', '', $normalized);

                if (empty($normalized)) {
                    continue;
                }

                if (!$found['date_col'] && $this->matchesAny($normalized, $dateKeywords)) $found['date_col'] = $col; elseif (!$found['description_col'] && $this->matchesAny($normalized, $descKeywords)) $found['description_col'] = $col;
                elseif (!$found['debit_col'] && $this->matchesAny($normalized, $debitKeywords)) $found['debit_col'] = $col;
                elseif (!$found['credit_col'] && $this->matchesAny($normalized, $creditKeywords)) $found['credit_col'] = $col;
                elseif (!$found['amount_col'] && $this->matchesAny($normalized, $amountKeywords)) $found['amount_col'] = $col;
                elseif (!$found['type_col'] && $this->matchesAny($normalized, $typeKeywords)) $found['type_col'] = $col;
                elseif (!$found['balance_col'] && $this->matchesAny($normalized, $balanceKeywords)) $found['balance_col'] = $col;
            }

            if ($found['debit_col'] || $found['credit_col']) {
                $found['amount_col'] = null;
            }

            Log::debug('Header scan row ' . $rowIndex, $found);

            $hasDate = $found['date_col'] !== null;
            $hasDesc = $found['description_col'] !== null;
            $hasMoney = $found['amount_col'] !== null || $found['debit_col'] !== null || $found['credit_col'] !== null;

            if ($hasDate && $hasDesc && $hasMoney) {
                $found['header_row'] = $rowIndex;
                Log::info('Headers detected at row ' . $rowIndex, $found);
                return $found;
            }
        }

        return null;
    }

    protected function matchesAny(string $normalized, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if ($normalized === $keyword || str_contains($normalized, $keyword)) {
                return true;
            }
        }
        return false;
    }

    protected function extractTransactions($data, $headers): array
    {
        $transactions = [];
        $startRow = $headers['header_row'] + 1;

        foreach (array_slice($data, $startRow - 1, null, true) as $rowIndex => $row) {
            if ($this->isEmptyRow($row)) {
                continue;
            }
            if ($this->isTotalRow($row)) {
                continue;
            }

            $date = $row[$headers['date_col']] ?? null;
            $description = $row[$headers['description_col']] ?? null;

            if (empty($date) || !$this->isValidDate($date)) {
                continue;
            }

            $debit = $headers['debit_col'] ? $this->parseAmount($row[$headers['debit_col']] ?? null) : null;
            $credit = $headers['credit_col'] ? $this->parseAmount($row[$headers['credit_col']] ?? null) : null;
            $amount = $headers['amount_col'] ? $this->parseAmount($row[$headers['amount_col']] ?? null) : null;
            $balance = $headers['balance_col'] ? $this->parseAmount($row[$headers['balance_col']] ?? null) : null;

            $type = null;
            if ($headers['type_col']) {
                $typeValue = strtolower(trim($row[$headers['type_col']] ?? ''));
                if (str_contains($typeValue, 'cr') || str_contains($typeValue, 'credit')) $type = 'received'; elseif (str_contains($typeValue, 'dr') || str_contains($typeValue, 'debit')) $type = 'paid';
            }

            $transactions[] = ['date' => $date, 'description' => $description, 'debit' => $debit, 'credit' => $credit, 'amount' => $amount, 'balance' => $balance, 'type' => $type,];
        }

        return $transactions;
    }

    // -------------------------------------------------------------------------
    // VERTICAL LAYOUT  ("Label: Value" rows, one field per row)
    // -------------------------------------------------------------------------

    protected function processVerticalLayout($data): array
    {
        $transactions = [];
        $current = [];

        foreach ($data as $row) {
            $val = trim((string)($row['A'] ?? array_values($row)[0] ?? ''));

            if (empty($val)) {
                if (!empty($current)) {
                    $txn = $this->parseVerticalBlock($current);
                    if ($txn) {
                        $transactions[] = $txn;
                    }
                    $current = [];
                }
                continue;
            }

            $current[] = $val;
        }

        if (!empty($current)) {
            $txn = $this->parseVerticalBlock($current);
            if ($txn) {
                $transactions[] = $txn;
            }
        }

        return $this->classifyAndCalculate($transactions);
    }

    protected function parseVerticalBlock(array $lines): ?array
    {
        $txn = ['date' => null, 'description' => null, 'amount' => null, 'debit' => null, 'credit' => null, 'balance' => null, 'type' => null,];

        foreach ($lines as $line) {
            if (!preg_match('/^([^:]+):\s*(.+)$/', $line, $m)) {
                continue;
            }

            $label = strtolower(trim($m[1]));
            $value = trim($m[2]);

            if (preg_match('/\b(date|txn date|value date)\b/', $label)) $txn['date'] = $value; elseif (preg_match('/\b(description|narration|particulars|details|remarks)\b/', $label)) $txn['description'] = $value;
            elseif (preg_match('/\b(debit|withdrawal|dr)\b/', $label)) $txn['debit'] = $this->parseAmount($value);
            elseif (preg_match('/\b(credit|deposit|cr)\b/', $label)) $txn['credit'] = $this->parseAmount($value);
            elseif (preg_match('/\bamount\b/', $label)) $txn['amount'] = $this->parseAmount($value);
            elseif (preg_match('/\bbalance\b/', $label)) $txn['balance'] = $this->parseAmount($value);
            elseif (preg_match('/\btype\b/', $label)) $txn['type'] = str_contains(strtolower($value), 'cr') ? 'received' : 'paid';
        }

        if (!$txn['date'] && !$txn['description']) {
            return null;
        }

        return $txn;
    }

    // -------------------------------------------------------------------------
    // SINGLE-COLUMN LAYOUT  (concatenated transaction strings, one per row)
    // -------------------------------------------------------------------------

    protected function processSingleColumnLayout($data): array
    {
        $transactions = [];

        foreach ($data as $row) {
            $val = trim((string)($row['A'] ?? array_values($row)[0] ?? ''));

            if (empty($val) || strlen($val) < 10) {
                continue;
            }

            if (!preg_match('/\d{1,2}[-\/\.]\d{1,2}[-\/\.]\d{2,4}|\d{4}[-\/\.]\d{1,2}[-\/\.]\d{1,2}/', $val)) {
                continue;
            }

            $txn = $this->parseConcatenatedRow($val);
            if ($txn) {
                $transactions[] = $txn;
            }
        }

        if (empty($transactions)) {
            return ['transactions' => [], 'total_deposits' => 0, 'total_withdrawals' => 0];
        }

        return $this->classifyAndCalculate($transactions);
    }

    protected function parseConcatenatedRow(string $val): ?array
    {
        $txn = ['date' => null, 'description' => null, 'amount' => null, 'debit' => null, 'credit' => null, 'balance' => null, 'type' => null,];

        if (preg_match('/(\d{1,2}[-\/\.]\d{1,2}[-\/\.]\d{2,4}|\d{4}[-\/\.]\d{1,2}[-\/\.]\d{1,2})/', $val, $dateMatch)) {
            $txn['date'] = $dateMatch[1];
        } else {
            return null;
        }

        if (preg_match('/(?:bal(?:ance)?[\s:]+)([\d,]+\.?\d*)$/i', $val, $balMatch)) {
            $txn['balance'] = $this->parseAmount($balMatch[1]);
            $val = preg_replace('/(?:bal(?:ance)?[\s:]+)([\d,]+\.?\d*)$/i', '', $val);
        }

        if (preg_match('/\b(?:upi|imps|neft|rtgs)\/cr\b/i', $val) || preg_match('/\bcr\b/i', $val)) {
            $txn['type'] = 'received';
        } elseif (preg_match('/\b(?:upi|imps|neft|rtgs)\/dr\b/i', $val) || preg_match('/\bdr\b/i', $val)) {
            $txn['type'] = 'paid';
        }

        preg_match_all('/[+-]?\s*[\d,]+\.\d{2}/', $val, $amountMatches);
        $amounts = array_values(array_filter(array_map(fn($a) => $this->parseAmount(str_replace(' ', '', $a)), $amountMatches[0]), fn($a) => $a !== null && $a > 0));

        if (!empty($amounts)) {
            rsort($amounts);
            $txn['amount'] = $amounts[0];
        }

        $desc = preg_replace('/\d{1,2}[-\/\.]\d{1,2}[-\/\.]\d{2,4}|\d{4}[-\/\.]\d{1,2}[-\/\.]\d{1,2}/', '', $val);
        $desc = preg_replace('/[+-]?\s*[\d,]+\.\d{2}/', '', $desc);
        $desc = preg_replace('/\s+/', ' ', $desc);
        $txn['description'] = trim($desc, " \t\n\r\0\x0B-|,");

        if (!$txn['amount'] && !$txn['debit'] && !$txn['credit']) {
            return null;
        }

        return $txn;
    }

    // -------------------------------------------------------------------------
    // CLASSIFICATION & TOTALS
    // -------------------------------------------------------------------------

    protected function classifyAndCalculate($rawTransactions): array
    {
        $cleanTransactions = [];
        $previousBalance = null;

        foreach ($rawTransactions as $txn) {
            $amount = 0;
            $type = $txn['type'] ?? 'paid';

            // Priority 1: separate debit / credit columns
            if ($txn['debit'] !== null && $txn['credit'] !== null) {
                if ($txn['debit'] > 0) {
                    $amount = $txn['debit'];
                    $type = 'paid';
                } elseif ($txn['credit'] > 0) {
                    $amount = $txn['credit'];
                    $type = 'received';
                }
            } // Priority 2: single amount column
            elseif ($txn['amount'] !== null) {
                $amount = abs($txn['amount']);

                if ($txn['type'] !== null) {
                    $type = $txn['type'];
                } else {
                    $type = $this->guessTypeByKeywords($txn['description'] ?? '');

                    if ($txn['balance'] !== null && $previousBalance !== null) {
                        $diff = $txn['balance'] - $previousBalance;
                        if (abs($diff) > 0.01 && $type === 'paid' && $diff > 0) {
                            $type = 'received';
                        }
                    }
                }
            } // Priority 3: only debit
            elseif ($txn['debit'] !== null && $txn['debit'] > 0) {
                $amount = $txn['debit'];
                $type = 'paid';
            } // Priority 4: only credit
            elseif ($txn['credit'] !== null && $txn['credit'] > 0) {
                $amount = $txn['credit'];
                $type = 'received';
            }

            if ($txn['balance'] !== null) {
                $previousBalance = $txn['balance'];
            }

            if ($amount <= 0) {
                continue;
            }

            $cleanTransactions[] = ['date' => $this->formatDate($txn['date'] ?? ''), 'description' => trim($txn['description'] ?? ''), 'amount' => $amount, 'type' => $type,];
        }

        return $this->calculateTotals($cleanTransactions);
    }

    protected function guessTypeByKeywords($desc): string
    {
        $text = strtolower($desc);

        if (preg_match('/(upi|imps|neft|rtgs)\/cr/i', $text)) return 'received';
        if (preg_match('/(upi|imps|neft|rtgs)\/dr/i', $text)) return 'paid';
        if (stripos($text, 'by transfer') !== false) return 'received';
        if (stripos($text, 'to transfer') !== false) return 'paid';
        if (str_contains($text, ' cr ') || str_contains($text, '/cr/') || str_contains($text, 'credit')) return 'received';
        if (str_contains($text, ' dr ') || str_contains($text, '/dr/') || str_contains($text, 'debit')) return 'paid';

        foreach (['deposit', 'salary', 'refund', 'interest', 'received', 'credited'] as $k) {
            if (str_contains($text, $k)) return 'received';
        }
        foreach (['atm', 'withdrawal', 'purchase', 'payment', 'debited'] as $k) {
            if (str_contains($text, $k)) return 'paid';
        }

        return 'paid';
    }

    // -------------------------------------------------------------------------
    // HELPERS
    // -------------------------------------------------------------------------

    protected function parseAmount($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $cleaned = preg_replace('/[^\d.-]/', '', (string)$value);
        if ($cleaned === '' || $cleaned === '-') {
            return null;
        }
        return (float)$cleaned;
    }

    protected function isEmptyRow($row): bool
    {
        foreach ($row as $value) {
            if (!empty(trim((string)$value))) {
                return false;
            }
        }
        return true;
    }

    protected function isTotalRow($row): bool
    {
        $rowText = strtolower(implode(' ', $row));
        foreach (['total', 'subtotal', 'grand total', 'summary', 'balance forward', 'opening balance'] as $keyword) {
            if (str_contains($rowText, $keyword)) {
                return true;
            }
        }
        return false;
    }

    protected function isValidDate($value): bool
    {
        if (empty($value)) {
            return false;
        }
        foreach (['/^\d{1,2}[-\/\.]\d{1,2}[-\/\.]\d{2,4}$/', '/^\d{4}[-\/\.]\d{1,2}[-\/\.]\d{1,2}$/', '/^\d{1,2}\s+[A-Za-z]{3,}\s+\d{2,4}$/',] as $pattern) {
            if (preg_match($pattern, trim($value))) {
                return true;
            }
        }
        return strtotime($value) !== false;
    }


    protected function formatDate($value): string
    {
        if (empty($value)) {
            return '';
        }

        // Excel numeric date
        if (is_numeric($value)) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject($value))->format('Y-m-d');
        }

        $value = trim($value);

        $formats = ['d/m/Y', 'd/m/y', 'd-m-Y', 'd-m-y', 'Y-m-d', 'Y/m/d',];

        foreach ($formats as $format) {
            try {
                return Carbon::createFromFormat($format, $value)->format('Y-m-d');
            } catch (Exception $e) {
            }
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (Exception $e) {
            \Log::warning("Unparseable date: {$value}");
            return '';
        }
    }

    protected function calculateTotals($transactions): array
    {
        $deposits = $withdrawals = 0;
        foreach ($transactions as $t) {
            $t['type'] === 'received' ? $deposits += $t['amount'] : $withdrawals += $t['amount'];
        }
        return ['transactions' => array_values($transactions), 'total_deposits' => round($deposits, 2), 'total_withdrawals' => round($withdrawals, 2),];
    }

    protected function getColumnLetter($index): string
    {
        $letter = '';
        while ($index > 0) {
            $index--;
            $letter = chr(65 + ($index % 26)) . $letter;
            $index = (int)($index / 26);
        }
        return $letter;
    }
}
