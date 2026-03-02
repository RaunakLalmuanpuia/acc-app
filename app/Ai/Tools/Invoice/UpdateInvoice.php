<?php

namespace App\Ai\Tools\Invoice;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Update invoice meta fields (dates, notes, terms) or record a payment.
 * Line-item editing on a confirmed invoice requires cancelling and re-issuing
 * a new invoice, so this tool does not allow line item changes post-confirmation.
 * Drafts can be freely updated.
 */
class UpdateInvoice implements Tool
{
    public function __construct(protected User $user) {}

    public function description(): Stringable|string
    {
        return <<<'TEXT'
        Update an invoice's metadata (due date, payment terms, notes, terms & conditions)
        or record a payment against it. For drafts, you may also update invoice_date.
        Line items cannot be edited on confirmed invoices — cancel and re-issue instead.
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

        // FIX: Explicitly reject ambiguous calls that provide both payment fields.
        //      Previously mark_as_paid silently won, causing record_payment to be ignored
        //      with no feedback to the agent or user.
        if (! empty($request['mark_as_paid']) && ! empty($request['record_payment'])) {
            return json_encode([
                'success' => false,
                'message' => 'Provide either mark_as_paid or record_payment in a single call, not both.',
            ]);
        }

        // --- Mark as fully paid ---
        if (! empty($request['mark_as_paid'])) {
            if ($invoice->status === 'paid') {
                return json_encode(['success' => false, 'message' => 'Invoice is already marked as paid.']);
            }
            if ($invoice->status === 'draft') {
                return json_encode(['success' => false, 'message' => 'Cannot mark a draft invoice as paid. Confirm it first.']);
            }
            if ($invoice->status === 'cancelled') {
                return json_encode(['success' => false, 'message' => 'Cannot mark a cancelled invoice as paid.']);
            }

            $invoice->markAsPaid();

            return json_encode([
                'success'     => true,
                'message'     => "Invoice {$invoice->invoice_number} marked as fully paid.",
                'amount_paid' => (float) $invoice->total_amount,
                'amount_due'  => 0,
                'status'      => 'paid',
            ]);
        }

        // --- Record a payment ---
        if (! empty($request['record_payment'])) {
            $amount = (float) $request['record_payment'];

            if ($amount <= 0) {
                return json_encode(['success' => false, 'message' => 'Payment amount must be greater than zero.']);
            }

            if ($invoice->status === 'draft') {
                return json_encode(['success' => false, 'message' => 'Cannot record a payment against a draft invoice. Confirm it first.']);
            }

            if ($invoice->status === 'cancelled') {
                return json_encode(['success' => false, 'message' => 'Cannot record a payment against a cancelled invoice.']);
            }

            if ($invoice->status === 'paid') {
                return json_encode(['success' => false, 'message' => 'Invoice is already fully paid.']);
            }

            if ($amount > (float) $invoice->amount_due) {
                return json_encode([
                    'success' => false,
                    'message' => "Payment (₹{$amount}) exceeds the outstanding amount due (₹{$invoice->amount_due}). "
                        . "Use mark_as_paid to settle the full balance, or enter a smaller amount.",
                ]);
            }

            $invoice->recordPayment($amount);
            $invoice->refresh();

            return json_encode([
                'success'     => true,
                'message'     => "Payment of ₹{$amount} recorded against invoice {$invoice->invoice_number}.",
                'amount_paid' => (float) $invoice->amount_paid,
                'amount_due'  => (float) $invoice->amount_due,
                'status'      => $invoice->status,
            ]);
        }

        // --- Update metadata ---
        $updatable = [];

        if (! empty($request['due_date'])) {
            $updatable['due_date'] = $request['due_date'];
        }

        if (! empty($request['payment_terms'])) {
            $updatable['payment_terms'] = $request['payment_terms'];
        }

        if (isset($request['notes'])) {
            $updatable['notes'] = $request['notes'];
        }

        if (isset($request['terms_and_conditions'])) {
            $updatable['terms_and_conditions'] = $request['terms_and_conditions'];
        }

        // invoice_date only changeable on drafts
        if (! empty($request['invoice_date'])) {
            if ($invoice->status !== 'draft') {
                return json_encode([
                    'success' => false,
                    'message' => 'Invoice date can only be changed on draft invoices.',
                ]);
            }
            $updatable['invoice_date'] = $request['invoice_date'];
        }

        if (empty($updatable)) {
            return json_encode(['success' => false, 'message' => 'No valid fields provided to update. Provide at least one of: due_date, payment_terms, notes, terms_and_conditions, invoice_date (drafts only), mark_as_paid, or record_payment.']);
        }

        $invoice->update($updatable);

        return json_encode([
            'success' => true,
            'message' => 'Invoice updated successfully.',
            'updated' => array_keys($updatable),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'invoice_ref' => $schema->string()
                ->required()
                ->description('Invoice number (e.g. INV-20240101-00042) or numeric ID.'),

            'mark_as_paid' => $schema->boolean()
                ->description('Set to true to mark the invoice as fully paid in one step. Cannot be combined with record_payment.'),

            'record_payment' => $schema->number()->min(0.01)
                ->description('Record a specific partial or full payment amount (₹). Cannot be combined with mark_as_paid.'),

            'due_date' => $schema->string()
                ->description('New due date (YYYY-MM-DD).'),

            'invoice_date' => $schema->string()
                ->description('New invoice date (YYYY-MM-DD). Only allowed on draft invoices.'),

            'payment_terms' => $schema->string()
                ->description('e.g. Net 30, Immediate.'),

            'notes' => $schema->string()
                ->description('Notes shown on the invoice.'),

            'terms_and_conditions' => $schema->string()
                ->description('Terms and conditions shown on the invoice.'),
        ];
    }
}
