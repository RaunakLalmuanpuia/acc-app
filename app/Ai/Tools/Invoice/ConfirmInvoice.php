<?php

namespace App\Ai\Tools\Invoice;

use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoicePdfService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Finalises a draft invoice: assigns an invoice number, sets status to 'sent',
 * and immediately generates + stores the confirmed PDF.
 * Only call this after the user has explicitly confirmed the draft summary.
 */
class ConfirmInvoice implements Tool
{
    public function __construct(protected User $user) {}

    public function description(): Stringable|string
    {
        return <<<'TEXT'
        Finalise a draft invoice. Assigns an invoice number, changes status to "sent",
        and generates the final PDF. Only call this AFTER the user has explicitly confirmed
        the invoice summary. Never call this with a draft_ref from a previous invoice in
        the conversation — always use the draft_ref returned by the most recent
        create_invoice_draft call.
        TEXT;
    }

    public function handle(Request $request): Stringable|string
    {
        $company = $this->user->companies()->first();

        if (! $company) {
            return json_encode(['success' => false, 'message' => 'No company profile found.']);
        }

        $ref = $request['draft_ref'];

        // FIX 1: Wrap the number assignment in a transaction with a SELECT ... FOR UPDATE
        //         lock to prevent two simultaneous confirmations assigning different invoice
        //         numbers to the same draft, or both succeeding concurrently.
        $invoice       = null;
        $invoiceNumber = null;

        try {
            DB::transaction(function () use ($company, $ref, &$invoice, &$invoiceNumber) {

                // Lock the row so a concurrent request waits rather than double-confirms.
                $invoice = Invoice::where('company_id', $company->id)
                    ->resolveByRef($ref)
                    ->where('status', 'draft')
                    ->lockForUpdate()
                    ->first();

                if (! $invoice) {
                    // Throw so the transaction rolls back cleanly and we handle below.
                    throw new \RuntimeException("__NOT_FOUND__");
                }

                // Generate a unique invoice number (generateNumber() retries on collision).
                $invoiceNumber = Invoice::generateNumber();

                $invoice->update([
                    'invoice_number' => $invoiceNumber,
                    'status'         => 'sent',
                ]);
            });

        } catch (\RuntimeException $e) {
            if ($e->getMessage() === '__NOT_FOUND__') {
                return json_encode([
                    'success' => false,
                    'message' => "Draft invoice '{$ref}' not found or already confirmed. "
                        . "If you are creating a second invoice, please use the new draft_ref from the most recent create_invoice_draft call.",
                ]);
            }
            throw $e;
        }

        // FIX 2: PDF generation is intentionally OUTSIDE the transaction.
        //         The invoice is already confirmed at this point. If the PDF fails,
        //         we do not roll back the confirmed invoice — we just report the error
        //         and instruct the user to call generate_invoice_pdf to retry.
        try {
            $invoice->refresh()->load('lineItems');
            $pdf = app(InvoicePdfService::class)->generate($invoice);

            return json_encode([
                'success'        => true,
                'message'        => 'Invoice confirmed, issued, and PDF generated.',
                'invoice_number' => $invoiceNumber,
                'invoice_id'     => $invoice->id,
                'client'         => $invoice->client_name,
                'total_amount'   => (float) $invoice->total_amount,
                'status'         => 'sent',
                'pdf_url'        => $pdf['url'],
                'pdf_expires_in' => '60 minutes',
            ]);

        } catch (\Throwable $e) {
            return json_encode([
                'success'        => true,
                'message'        => 'Invoice confirmed and issued. PDF generation failed — call generate_invoice_pdf with the invoice_number below to retry.',
                'invoice_number' => $invoiceNumber,
                'invoice_id'     => $invoice->id,
                'client_name'    => $invoice->client_name,      // ← already present
                'client_email'   => $invoice->client_email,     // ← add this
                'client_gst'     => $invoice->client_gst_number,// ← add this
                'total_amount'   => (float) $invoice->total_amount,
                'status'         => 'sent',
                'pdf_error'      => $e->getMessage(),
            ]);
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'draft_ref' => $schema->string()
                ->required()
                ->description(
                    'The DRAFT- reference returned by create_invoice_draft (e.g. DRAFT-699EC4B50B017) or the numeric invoice ID. '
                    . 'CRITICAL: Always use the draft_ref from the CURRENT invoice being confirmed — never reuse a draft_ref from an earlier invoice in this conversation.'
                ),
        ];
    }
}
