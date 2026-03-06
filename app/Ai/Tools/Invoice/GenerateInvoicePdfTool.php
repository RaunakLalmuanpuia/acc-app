<?php

namespace App\Ai\Tools\Invoice;
use App\Services\InvoiceAgentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class GenerateInvoicePdfTool implements Tool
{
    public function __construct(private readonly int $companyId) {}

    public function description(): string
    {
        return 'Render and save the invoice PDF to storage. Always call this before finalizing. The PDF path is persisted on the invoice record. Call GetInvoice first to verify line items and totals look correct before generating.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'invoice_id' => $schema->integer()
                ->description('The invoice_id to generate the PDF for.')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        try {
            $service = new InvoiceAgentService($this->companyId);
            $result  = $service->generatePdf((int) $request['invoice_id']);

            return json_encode(['success' => true, ...$result]);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }
}
