<?php

namespace App\Http\Requests\Banking;

use Illuminate\Foundation\Http\FormRequest;

class NarrationReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Narration fields (existing)
            'narration_head_id'     => ['required', 'integer', 'exists:narration_heads,id'],
            'narration_sub_head_id' => ['nullable', 'integer', 'exists:narration_sub_heads,id'],
            'party_name'            => ['nullable', 'string', 'max:255'],
            'narration_note'        => ['nullable', 'string', 'max:500'],
            'save_as_rule'          => ['boolean'],

            // Reconciliation fields (new, all optional)
            'invoice_id'     => ['nullable', 'integer', 'exists:invoices,id'],
            'invoice_number' => ['nullable', 'string', 'max:100'],
            'unreconcile'    => ['boolean'],
        ];
    }
}
