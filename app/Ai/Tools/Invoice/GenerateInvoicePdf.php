<?php

namespace App\Ai\Tools\Invoice;

use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoicePdfService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GenerateInvoicePdf implements Tool
{
    public function __construct(protected User $user) {}

    public function description(): Stringable|string
    {
        return <<<'TEXT'
        Generate a fresh PDF for an invoice and return a new download URL.
        Always call this tool to get a PDF link — never reuse a URL from earlier in
        the conversation. Signed URLs expire after 60 minutes and are invoice-specific.
        Works on both draft invoices (watermarked preview) and confirmed invoices (final copy).
        TEXT;
    }

    public function handle(Request $request): Stringable|string
    {
        $company = $this->user->companies()->first();

        if (! $company) {
            return json_encode(['success' => false, 'message' => 'No company profile found.']);
        }

        $ref     = $request['invoice_ref'];
        $invoice = Invoice::forCompany($company->id)
            ->resolveByRef($ref)
            ->with('lineItems')
            ->first();

        if (! $invoice) {
            return json_encode(['success' => false, 'message' => "Invoice '{$ref}' not found."]);
        }

        try {
            $isDraft = $invoice->status === 'draft';
            $pdf     = app(InvoicePdfService::class)->generate($invoice);

            return json_encode([
                'success'        => true,
                'message'        => $isDraft
                    ? 'Draft preview generated (watermarked). Confirm the invoice to issue the final copy.'
                    : 'PDF generated successfully.',
                'is_draft'       => $isDraft,
                'invoice_number' => $invoice->invoice_number,
                'download_url'   => $pdf['url'],
                'expires_in'     => '60 minutes',
            ]);

        } catch (\Throwable $e) {
            return json_encode([
                'success' => false,
                'message' => 'PDF generation failed: ' . $e->getMessage(),
            ]);
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'invoice_ref' => $schema->string()
                ->required()
                ->description(
                    'Invoice number (e.g. INV-20240101-00042), DRAFT- reference, or numeric invoice ID. '
                    . 'Always prefer the invoice number over a numeric ID when you have it.'
                ),
        ];
    }
}
