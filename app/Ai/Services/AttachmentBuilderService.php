<?php

namespace App\Ai\Services;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;

/**
 * AttachmentBuilderService
 *
 * Converts validated UploadedFile instances from the HTTP request into the
 * typed attachment objects expected by the Laravel AI SDK (Image or Document).
 *
 * Responsibilities:
 *  - Detect MIME type and dispatch to the correct SDK class.
 *  - Skip malformed / unreadable files with a warning log rather than failing
 *    the entire request.
 *  - Keep the controller free of attachment-handling boilerplate.
 */
class AttachmentBuilderService
{
    /**
     * Build the attachment list from an HTTP request.
     *
     * Supported MIME types: pdf, csv, xlsx, xls, docx, doc, txt, png, jpg, jpeg, webp.
     * Up to 5 files enforced upstream by the controller validator.
     *
     * @param  Request                  $request
     * @return array<int, Image|Document>
     */
    public function fromRequest(Request $request): array
    {
        $attachments = [];

        foreach ($request->file('attachments', []) as $file) {
            $attachment = $this->buildOne($file);

            if ($attachment !== null) {
                $attachments[] = $attachment;
            }
        }

        return $attachments;
    }

    // ── Private ────────────────────────────────────────────────────────────────

    /**
     * Convert a single UploadedFile into an SDK attachment.
     * Returns null and logs a warning if the file cannot be processed,
     * allowing other attachments in the same request to succeed.
     */
    private function buildOne(UploadedFile $file): Image|Document|null
    {
        try {
            $mime = $file->getMimeType();

            return str_starts_with($mime, 'image/')
                ? Image::fromUpload($file)
                : Document::fromUpload($file);

        } catch (\Throwable $e) {
            Log::warning('[AttachmentBuilderService] Failed to process attachment', [
                'filename' => $file->getClientOriginalName(),
                'mime'     => $file->getMimeType(),
                'error'    => $e->getMessage(),
            ]);

            return null;
        }
    }
}
