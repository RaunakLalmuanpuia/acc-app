<?php

namespace App\Services;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class InvoicePdfService
{
    /**
     * Render, store, and return a download URL for an invoice PDF.
     *
     * Drafts    → invoices/{company_id}/draft-{id}-{timestamp}.pdf  (unique per generation)
     * Confirmed → invoices/{company_id}/{invoice_number}.pdf         (stable, overwritten on regenerate)
     *
     * @return array{path: string, url: string}
     *
     * @throws \RuntimeException if PDF rendering or storage fails
     */
    public function generate(Invoice $invoice): array
    {
        $invoice->loadMissing('lineItems');

        $isDraft = $invoice->status === 'draft';

        $filename = $isDraft
            ? "invoices/{$invoice->company_id}/draft-{$invoice->id}-" . time() . ".pdf"
            : "invoices/{$invoice->company_id}/{$invoice->invoice_number}.pdf";

        // Render the PDF first — before touching the filesystem.
        // FIX: Previously deleteStaleDrafts() ran BEFORE Storage::put(), so if PDF
        //      rendering threw an exception after the delete, the old file was gone
        //      and no new file existed. Write-then-delete is the safe order.
        $pdfOutput = Pdf::loadView('invoices.pdf', [
            'invoice' => $invoice,
            'isDraft' => $isDraft,
        ])->setPaper('a4', 'portrait')->output();

        // Write the new file first
        Storage::disk('local')->put($filename, $pdfOutput);

        // FIX: For drafts, delete stale files AFTER the new one is safely written.
        //      This ensures there is always at least one valid PDF on disk.
        if ($isDraft) {
            $this->deleteStaleDraftFiles($invoice, exclude: $filename);
        }

        // FIX: Always persist pdf_path, including for drafts.
        //      Previously drafts never updated pdf_path, so has_pdf was always false
        //      for drafts in list/detail responses, even after a preview was generated.
        //      updateQuietly avoids triggering the activity log on every PDF regeneration.
        if ($invoice->pdf_path !== $filename) {
            $invoice->updateQuietly(['pdf_path' => $filename]);
        }

        $url = $this->buildUrl($filename);

        return ['path' => $filename, 'url' => $url];
    }

    /**
     * Build a download URL using an encrypted token so the raw storage path
     * is never exposed in the URL. This must match InvoiceDownloadController
     * which decrypts the token to recover the path.
     *
     * FIX: Extracted into its own method so any future change to the URL strategy
     *      (e.g. switching to S3 pre-signed URLs) is a single edit here, and all
     *      callers — GetInvoices, ConfirmInvoice, GenerateInvoicePdf — stay in sync
     *      automatically because they all go through InvoicePdfService.
     */
    public function buildUrl(string $storagePath): string
    {
        $token = encrypt($storagePath);
        return route('invoices.download', ['token' => $token]);
    }

    /**
     * Delete all previously generated draft PDF files for this invoice,
     * excluding the file we just wrote.
     *
     * FIX: Renamed from deleteStaleDrafts() to deleteStaleDraftFiles() to avoid
     *      confusion with the Invoice::scopeStaleDrafts() model scope (which operates
     *      on draft Invoice records, not PDF files).
     */
    private function deleteStaleDraftFiles(Invoice $invoice, string $exclude): void
    {
        $directory = "invoices/{$invoice->company_id}";
        $prefix    = "draft-{$invoice->id}-";

        // Guard: directory may not exist yet on first generation
        if (! Storage::disk('local')->directoryExists($directory)) {
            return;
        }

        foreach (Storage::disk('local')->files($directory) as $file) {
            if ($file !== $exclude && str_contains(basename($file), $prefix)) {
                Storage::disk('local')->delete($file);
            }
        }
    }
}
