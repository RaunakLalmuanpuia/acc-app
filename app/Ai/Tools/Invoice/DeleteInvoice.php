<?php

namespace App\Ai\Tools\Invoice;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class DeleteInvoice implements Tool
{
    public function __construct(protected User $user) {}

    public function description(): Stringable|string
    {
        return <<<'TEXT'
        Cancel an invoice by setting its status to "cancelled" and soft-deleting it.
        Invoices with recorded payments cannot be cancelled — a credit note must be
        issued instead.
        Always confirm with the user before calling this.
        TEXT;
    }

    public function handle(Request $request): Stringable|string
    {
        $company = $this->user->companies()->first();

        if (! $company) {
            return json_encode(['success' => false, 'message' => 'No company profile found.']);
        }

        $ref     = $request['invoice_ref'];
        $invoice = Invoice::forCompany($company->id)->resolveByRef($ref)->first();

        if (! $invoice) {
            return json_encode(['success' => false, 'message' => "Invoice '{$ref}' not found."]);
        }

        // Guard: cannot cancel if payments have been recorded
        if ((float) $invoice->amount_paid > 0) {
            return json_encode([
                'success' => false,
                'message' => "Cannot cancel invoice {$invoice->invoice_number} — it has recorded payments of ₹{$invoice->amount_paid}. "
                    . "Issue a credit note instead.",
            ]);
        }

        if ($invoice->status === 'cancelled') {
            return json_encode([
                'success' => false,
                'message' => "Invoice {$invoice->invoice_number} is already cancelled.",
            ]);
        }

        $invoiceRef = $invoice->invoice_number ?? "(Draft #{$invoice->id})";

        // FIX: Previously both status='cancelled' AND soft-delete were applied, which is
        //      redundant and corrupts audit/reporting queries:
        //      - Soft-deleted rows are hidden from normal queries, so status='cancelled' is invisible.
        //      - withTrashed() queries then show rows with BOTH cancelled status AND deleted_at set.
        //
        //      New behaviour: status-only for confirmed invoices (visible in audit lists,
        //      can be queried with status filter), soft-delete only for true drafts
        //      (abandoned drafts with no invoice number have no audit value).
        if ($invoice->status === 'draft') {
            // Drafts have no invoice number — just soft-delete, no audit trail needed.
            $invoice->delete();
            $message = "Draft invoice {$invoiceRef} has been deleted.";
        } else {
            // Confirmed invoices: status = cancelled keeps them visible in audit history.
            // We do NOT soft-delete confirmed invoices so they remain queryable for reporting.
            $invoice->update(['status' => 'cancelled']);
            $message = "Invoice {$invoiceRef} has been cancelled. It remains in your records for audit purposes.";
        }

        return json_encode([
            'success' => true,
            'message' => $message,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'invoice_ref' => $schema->string()
                ->required()
                ->description('Invoice number (e.g. INV-20240101-00042) or numeric ID to cancel.'),
        ];
    }
}
