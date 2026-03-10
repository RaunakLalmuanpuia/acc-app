<?php

namespace App\Http\Requests\Banking;

use Illuminate\Foundation\Http\FormRequest;

class EmailIngestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'bank_account_id' => ['required', 'integer', 'exists:bank_accounts,id'],
            'email_subject'   => ['nullable', 'string', 'max:500'],
            'email_body'      => ['required', 'string', 'min:10', 'max:10000'],
        ];
    }

    public function messages(): array
    {
        return [
            'email_body.required' => 'Please paste the email content.',
            'email_body.min'      => 'The email content seems too short to be a bank alert.',
        ];
    }

    /**
     * Build a single combined string the parser agent will receive.
     * Format: Subject (if given) + blank line + Body
     */
    public function buildRawEmail(): string
    {
        $parts = [];

        if ($subject = trim($this->input('email_subject', ''))) {
            $parts[] = "Subject: {$subject}";
        }

        $parts[] = trim($this->input('email_body'));

        return implode("\n\n", $parts);
    }
}
