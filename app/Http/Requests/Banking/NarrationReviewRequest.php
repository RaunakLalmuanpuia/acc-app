<?php

namespace App\Http\Requests\Banking;

use Illuminate\Foundation\Http\FormRequest;

class NarrationReviewRequest extends FormRequest
{
    public function rules(): array
    {
        return match ($this->route('action')) {
            'correct' => [
                'narration_head_id'     => ['required', 'integer', 'exists:narration_heads,id'], // Compulsory
                'narration_sub_head_id' => ['nullable', 'integer', 'exists:narration_sub_heads,id'],
                'narration_note'        => ['nullable', 'string', 'max:255'],
                'party_name'            => ['nullable', 'string', 'max:100'],
                'save_as_rule'          => ['boolean'],
            ],
            default => [],
        };
    }
}
