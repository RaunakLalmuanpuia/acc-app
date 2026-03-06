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
            $service = new InvoiceAgentService($this->companyId);
            $invoice = $service->finalizeInvoice(
                invoiceId: (int) $request['invoice_id'],
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
}
