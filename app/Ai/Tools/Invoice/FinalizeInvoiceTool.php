<?php

namespace App\Ai\Tools\Invoice;

use App\Services\InvoiceAgentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class FinalizeInvoiceTool implements Tool
{
    public function __construct(private readonly int $companyId) {}

    public function description(): string
    {
        return 'Move a draft invoice to a final status (sent, cancelled, or void). The PDF must be generated first. Use "sent" for normal invoices ready to be dispatched to the client.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'invoice_number' => $schema->string()
                ->description('Preferred: invoice number e.g. INV-20260311-57474. Use this instead of invoice_id.'),

            'invoice_id' => $schema->integer()
                ->description('The invoice_id to finalize.')
                ->required(),

            'status' => $schema->string()
                ->description('Target status: "sent" (default), "cancelled", or "void".')
                ->enum(['sent', 'cancelled', 'void']),
        ];
    }

    public function handle(Request $request): string
    {
        try {
            $service   = new InvoiceAgentService($this->companyId);
            $invoiceId = $this->resolveInvoiceId($request);
            $invoice   = $service->finalizeInvoice(
                invoiceId: $invoiceId,
                status:    $request['status'] ?? 'sent',
            );

            return json_encode([
                'success'        => true,
                'invoice_number' => $invoice['invoice_number'],
                'status'         => $invoice['status'],
                'total_amount'   => $invoice['total_amount'],
                'pdf_path'       => $invoice['pdf_path'],
                'message'        => "Invoice {$invoice['invoice_number']} is now {$invoice['status']}.",
            ]);
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
