<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\InventoryItem;
use App\Models\InvoiceLineItem;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class InvoiceAgentService
{
    public function __construct(private readonly int $companyId) {}

    // ── Client ────────────────────────────────────────────────────────────

    public function findClients(string $query): array
    {
        $clients = Client::query()
            ->where('company_id', $this->companyId)
            ->where('is_active', true)
            ->where(function ($q) use ($query) {
                if (is_numeric($query)) {
                    $q->where('id', (int) $query);
                    return;
                }

                $tokens = array_filter(
                    preg_split('/[\s\-_]+/', strtolower(preg_replace("/[\'\"]/", '', $query))),
                    fn($t) => strlen($t) >= 2
                );

                if (empty($tokens)) {
                    $q->whereRaw('1 = 0');
                    return;
                }

                foreach ($tokens as $token) {
                    $q->where(function ($inner) use ($token) {
                        $inner->whereRaw("LOWER(REPLACE(name, \"'\", '')) LIKE ?", ["%{$token}%"])
                            ->orWhereRaw("LOWER(REPLACE(email, \"'\", '')) LIKE ?", ["%{$token}%"]);
                    });
                }
            })
            ->select('id', 'name', 'email', 'gst_number', 'address', 'state', 'state_code', 'currency', 'payment_terms')
            ->limit(10)
            ->get();

        return $clients->map(fn (Client $c) => [
            'id'            => $c->id,
            'name'          => $c->name,
            'email'         => $c->email,
            'gst_number'    => $c->gst_number,
            'address'       => $c->address,
            'state'         => $c->state,
            'state_code'    => $c->state_code,
            'currency'      => $c->currency,
            'payment_terms' => $c->payment_terms,
        ])->toArray();
    }

    // ── Inventory ─────────────────────────────────────────────────────────

    public function findInventoryItems(string $query): array
    {
        $items = InventoryItem::query()
            ->where('company_id', $this->companyId)
            ->where('is_active', true)
            ->where(function ($q) use ($query) {
                if (is_numeric($query)) {
                    $q->where('id', (int) $query);
                    return;
                }

                $tokens = array_filter(
                    preg_split('/[\s\-_]+/', strtolower(preg_replace("/[\'\"]/", '', $query))),
                    fn($t) => strlen($t) >= 2
                );

                if (empty($tokens)) {
                    $q->whereRaw('1 = 0');
                    return;
                }

                foreach ($tokens as $token) {
                    $q->where(function ($inner) use ($token) {
                        $inner->whereRaw("LOWER(REPLACE(name, \"'\", '')) LIKE ?", ["%{$token}%"])
                            ->orWhereRaw("LOWER(REPLACE(sku, \"'\", '')) LIKE ?", ["%{$token}%"])
                            ->orWhereRaw('LOWER(hsn_code) LIKE ?', ["%{$token}%"])
                            ->orWhereRaw("LOWER(REPLACE(description, \"'\", '')) LIKE ?", ["%{$token}%"]);
                    });
                }
            })
            ->select('id', 'name', 'sku', 'description', 'unit', 'hsn_code', 'gst_rate', 'rate')
            ->limit(15)
            ->get();

        return $items->map(fn (InventoryItem $i) => [
            'id'          => $i->id,
            'name'        => $i->name,
            'sku'         => $i->sku,
            'description' => $i->description,
            'unit'        => $i->unit,
            'hsn_code'    => $i->hsn_code,
            'gst_rate'    => (float) $i->gst_rate,
            'rate'        => (float) $i->rate,
        ])->toArray();
    }

    // ── Invoice ─────────────────────────────────────────────────────────

    /**
     * Search invoices by invoice number, client name, status, date range, or amount.
     */
    public function searchInvoices(
        ?string $query = null,
        ?string $status = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $dueDateFrom = null,
        ?string $dueDateTo = null,
        ?float  $amountMin = null,
        ?float  $amountMax = null,
        int     $limit = 15,
    ): array {
        $normalise   = fn(?string $v) => ($v !== null && trim($v) !== '' && strtolower(trim($v)) !== 'null')
            ? trim($v)
            : null;

        $query       = $normalise($query);
        $status      = $normalise($status);
        $dateFrom    = $normalise($dateFrom);
        $dateTo      = $normalise($dateTo);
        $dueDateFrom = $normalise($dueDateFrom);
        $dueDateTo   = $normalise($dueDateTo);

        $invoices = Invoice::query()
            ->where('company_id', $this->companyId)
            ->when($status,      fn($q) => $q->where('status', $status))
            ->when($dateFrom,    fn($q) => $q->whereDate('invoice_date', '>=', $dateFrom))
            ->when($dateTo,      fn($q) => $q->whereDate('invoice_date', '<=', $dateTo))
            ->when($dueDateFrom, fn($q) => $q->whereDate('due_date', '>=', $dueDateFrom))
            ->when($dueDateTo,   fn($q) => $q->whereDate('due_date', '<=', $dueDateTo))
            ->when($amountMin !== null, fn($q) => $q->where('total_amount', '>=', $amountMin))
            ->when($amountMax !== null, fn($q) => $q->where('total_amount', '<=', $amountMax))
            ->when(
                function () use ($query) {
                    if ($query === null) return false;
                    $tokens = array_filter(
                        preg_split('/[\s\-_]+/', strtolower(preg_replace("/[\'\"]/", '', $query))),
                        fn($t) => strlen($t) >= 2
                    );
                    return !empty($tokens);
                },
                function ($q) use ($query) {
                    $tokens = array_filter(
                        preg_split('/[\s\-_]+/', strtolower(preg_replace("/[\'\"]/", '', $query))),
                        fn($t) => strlen($t) >= 2
                    );
                    $q->where(function ($outer) use ($tokens) {
                        foreach ($tokens as $token) {
                            $outer->where(function ($inner) use ($token) {
                                $inner->whereRaw("LOWER(REPLACE(invoice_number, \"'\", '')) LIKE ?", ["%{$token}%"])
                                    ->orWhereRaw("LOWER(REPLACE(client_name, \"'\", '')) LIKE ?", ["%{$token}%"])
                                    ->orWhereRaw("LOWER(REPLACE(client_email, \"'\", '')) LIKE ?", ["%{$token}%"]);
                            });
                        }
                    });
                }
            )
            ->orderByDesc('invoice_date')
            ->limit($limit)
            ->get();

        $invoices->load('lineItems');

        return $invoices->map(fn(Invoice $i) => $this->formatInvoice($i))->toArray();
    }

    // ── Invoice CRUD ──────────────────────────────────────────────────────

    /**
     * Return open drafts for this company.
     * Optionally filter by exact invoice_number or client_name fragment.
     */
    public function getActiveDrafts(
        ?string $invoiceNumber = null,
        ?string $clientName = null,
    ): array {
        return Invoice::where('company_id', $this->companyId)
            ->where('status', 'draft')
            ->when($invoiceNumber, fn($q) => $q->where('invoice_number', $invoiceNumber))
            ->when($clientName,    fn($q) => $q->where('client_name', 'like', "%{$clientName}%"))
            ->with('lineItems')
            ->latest()
            ->get()
            ->map(fn (Invoice $i) => $this->formatInvoice($i))
            ->toArray();
    }

    /**
     * Create a new draft invoice snapshotting company + client details.
     *
     * IDEMPOTENCY: If $forceNew is false and a draft already exists for this
     * client, return it instead of creating a duplicate.
     * If $forceNew is true, always create a fresh invoice regardless.
     */
    public function createDraftInvoice(
        int     $clientId,
        string  $invoiceDate,
        ?string $dueDate = null,
        ?string $paymentTerms = null,
        ?string $notes = null,
        ?string $termsAndConditions = null,
        string  $invoiceType = 'tax_invoice',
        string  $currency = 'INR',
        bool    $forceNew = false,          // ← true = always create fresh
    ): array {
        // ── Idempotency guard (skipped when forceNew) ─────────────────────
        if (!$forceNew) {
            $existing = Invoice::where('company_id', $this->companyId)
                ->where('client_id', $clientId)
                ->where('status', 'draft')
                ->with('lineItems')
                ->latest()
                ->first();

            if ($existing) {
                return array_merge($this->formatInvoice($existing), [
                    '_resumed' => true,
                    '_message' => "Resumed existing draft {$existing->invoice_number} (id={$existing->id}) — no duplicate created.",
                ]);
            }
        }

        // ── Create fresh invoice ──────────────────────────────────────────
        $company = \App\Models\Company::findOrFail($this->companyId);
        $client  = Client::where('company_id', $this->companyId)->findOrFail($clientId);

        $invoice = Invoice::create([
            'invoice_number' => Invoice::generateNumber(),
            'company_id'     => $company->id,
            'client_id'      => $client->id,

            // Company snapshot
            'company_name'       => $company->company_name,
            'company_gst_number' => $company->gst_number ?? null,
            'company_state'      => $company->state,
            'company_state_code' => $company->state_code,

            // Client snapshot
            'client_name'       => $client->name,
            'client_email'      => $client->email,
            'client_address'    => $client->address,
            'client_gst_number' => $client->gst_number,
            'client_state'      => $client->state,
            'client_state_code' => $client->state_code,

            'invoice_date'         => $invoiceDate,
            'due_date'             => $dueDate,
            'currency'             => $currency,
            'invoice_type'         => $invoiceType,
            'status'               => 'draft',
            'payment_terms'        => $paymentTerms ?? $client->payment_terms,
            'notes'                => $notes,
            'terms_and_conditions' => $termsAndConditions,
        ]);

        $invoice->update(['supply_type' => $invoice->determineSupplyType()]);

        return $this->formatInvoice($invoice->fresh());
    }

    /**
     * Add a line item to a draft invoice and recalculate all totals.
     */
    public function addLineItem(
        int     $invoiceId,
        float   $quantity,
        float   $rate,
        ?int    $inventoryItemId = null,
        ?string $description = null,
        ?string $hsnCode = null,
        ?string $unit = null,
        float   $discountPercent = 0,
        ?float  $gstRate = null,
    ): array {
        $invoice = $this->findDraftInvoice($invoiceId);

        if ($inventoryItemId) {
            $item        = InventoryItem::where('company_id', $this->companyId)->findOrFail($inventoryItemId);
            $description = $description ?? $item->name;
            $hsnCode     = $hsnCode     ?? $item->hsn_code;
            $unit        = $unit        ?? $item->unit;
            $gstRate     = $gstRate     ?? (float) $item->gst_rate;
        }

        $sortOrder = $invoice->lineItems()->max('sort_order') + 1;

        $lineItem = new InvoiceLineItem([
            'invoice_id'        => $invoice->id,
            'inventory_item_id' => $inventoryItemId,
            'description'       => $description ?? 'Item',
            'hsn_code'          => $hsnCode,
            'unit'              => $unit,
            'quantity'          => $quantity,
            'rate'              => $rate,
            'discount_percent'  => $discountPercent,
            'gst_rate'          => $gstRate ?? 18.0,
            'sort_order'        => $sortOrder,
        ]);

        $lineItem->calculateAmounts($invoice->supply_type);
        $lineItem->save();

        $invoice->recalculateTotals();

        return $this->formatInvoice($invoice->fresh());
    }

    /**
     * Remove a line item by ID and recalculate totals.
     */
    public function removeLineItem(int $invoiceId, int $lineItemId): array
    {
        $invoice  = $this->findDraftInvoice($invoiceId);
        $lineItem = InvoiceLineItem::where('invoice_id', $invoice->id)->findOrFail($lineItemId);
        $lineItem->delete();

        $invoice->recalculateTotals();

        return $this->formatInvoice($invoice->fresh());
    }

    /**
     * Return full invoice + line items for the AI to read.
     */
    public function getInvoice(int $invoiceId): array
    {
        $invoice = Invoice::where('company_id', $this->companyId)
            ->with('lineItems')
            ->findOrFail($invoiceId);

        return $this->formatInvoice($invoice);
    }

    /**
     * Move the invoice out of draft. PDF must exist first.
     */
    public function finalizeInvoice(int $invoiceId, string $status = 'sent'): array
    {
        $invoice = $this->findDraftInvoice($invoiceId);

        $allowed = ['sent', 'cancelled', 'void'];
        if (! in_array($status, $allowed, true)) {
            throw new \InvalidArgumentException("Status must be one of: " . implode(', ', $allowed));
        }

        if (! $invoice->pdf_path) {
            throw new \RuntimeException("Generate the PDF before finalizing the invoice.");
        }

        $invoice->update(['status' => $status]);

        return $this->formatInvoice($invoice->fresh());
    }

    // ── PDF ───────────────────────────────────────────────────────────────

    /**
     * Render the invoice to PDF, store it, persist the path, and return a
     * signed download URL so the chat UI can render a clickable link.
     */
    public function generatePdf(int $invoiceId): array
    {
        $invoice = Invoice::where('company_id', $this->companyId)
            ->with('lineItems')
            ->findOrFail($invoiceId);

        if ($invoice->status !== 'draft') {
            throw new \RuntimeException(
                "Invoice {$invoice->invoice_number} is {$invoice->status} and cannot be modified."
            );
        }

        $pdf  = Pdf::loadView('invoices.pdf', ['invoice' => $invoice])
            ->setPaper('a4', 'portrait');

        $path = "invoices/{$invoice->invoice_number}.pdf";
        Storage::disk('local')->put($path, $pdf->output());

        $invoice->update(['pdf_path' => $path]);

        $downloadUrl = route('invoices.pdf.download', [
            'invoice' => $invoice->id,
        ]);

        return [
            'invoice_id'     => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'pdf_path'       => $path,
            'download_url'   => $downloadUrl,
            'message'        => "PDF ready. Share this link with the client: {$downloadUrl}",
        ];
    }

    // ── Private helpers ───────────────────────────────────────────────────

    private function findDraftInvoice(int $invoiceId): Invoice
    {
        $invoice = Invoice::where('company_id', $this->companyId)
            ->with('lineItems')
            ->findOrFail($invoiceId);

        if ($invoice->status !== 'draft') {
            throw new \RuntimeException(
                "Invoice #{$invoice->invoice_number} is already {$invoice->status} and cannot be modified."
            );
        }

        return $invoice;
    }

    private function formatInvoice(Invoice $invoice): array
    {
        return [
            'id'             => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'status'         => $invoice->status,
            'supply_type'    => $invoice->supply_type,
            'invoice_type'   => $invoice->invoice_type,
            'company_name'   => $invoice->company_name,
            'client_name'    => $invoice->client_name,
            'client_email'   => $invoice->client_email,
            'invoice_date'   => $invoice->invoice_date?->toDateString(),
            'due_date'       => $invoice->due_date?->toDateString(),
            'currency'       => $invoice->currency,
            'payment_terms'  => $invoice->payment_terms,
            'notes'          => $invoice->notes,
            'line_items'     => $invoice->lineItems->map(fn (InvoiceLineItem $li) => [
                'id'               => $li->id,
                'description'      => $li->description,
                'hsn_code'         => $li->hsn_code,
                'unit'             => $li->unit,
                'quantity'         => (float) $li->quantity,
                'rate'             => (float) $li->rate,
                'discount_percent' => (float) $li->discount_percent,
                'amount'           => (float) $li->amount,
                'gst_rate'         => (float) $li->gst_rate,
                'cgst_amount'      => (float) $li->cgst_amount,
                'sgst_amount'      => (float) $li->sgst_amount,
                'igst_amount'      => (float) $li->igst_amount,
                'total_amount'     => (float) $li->total_amount,
            ])->toArray(),
            'subtotal'        => (float) $invoice->subtotal,
            'discount_amount' => (float) $invoice->discount_amount,
            'taxable_amount'  => (float) $invoice->taxable_amount,
            'cgst_amount'     => (float) $invoice->cgst_amount,
            'sgst_amount'     => (float) $invoice->sgst_amount,
            'igst_amount'     => (float) $invoice->igst_amount,
            'gst_amount'      => (float) $invoice->gst_amount,
            'total_amount'    => (float) $invoice->total_amount,
            'amount_paid'     => (float) $invoice->amount_paid,
            'amount_due'      => (float) $invoice->amount_due,
            'pdf_path'        => $invoice->pdf_path,
        ];
    }
}
