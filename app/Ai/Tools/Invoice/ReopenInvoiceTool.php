<?php

namespace App\Ai\Tools\Invoice;

use App\Services\InvoiceAgentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ReopenInvoiceTool implements Tool
{
    public function __construct(private readonly int $companyId) {}

    public function description(): string
    {
        return 'Reopen a sent invoice for editing by setting it back to draft status. '
            . 'Call this before making any edits to a sent invoice. '
            . 'The invoice number stays the same. Generating a new PDF will mark it as sent again.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'invoice_number' => $schema->string()
                ->description('Invoice number e.g. INV-20260321-12345. Preferred over invoice_id.'),

            'invoice_id' => $schema->integer()
                ->description('The invoice_id to reopen.')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        try {
            $service   = new InvoiceAgentService($this->companyId);
            $invoiceId = $this->resolveInvoiceId($request);
            $result    = $service->reopenInvoice($invoiceId);

            return json_encode(['success' => true, ...$result]);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }

    private function resolveInvoiceId(Request $request): int
    {
        if (!empty($request['invoice_number'])) {
            $invoice = \App\Models\Invoice::where('company_id', $this->companyId)
                ->where('invoice_number', trim($request['invoice_number']))
                ->firstOrFail();
            return $invoice->id;
        }

        return (int) $request['invoice_id'];
    }
}
