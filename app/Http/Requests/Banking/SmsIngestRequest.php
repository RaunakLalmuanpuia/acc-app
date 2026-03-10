<?php

namespace App\Http\Requests\Banking;

use Illuminate\Foundation\Http\FormRequest;

class SmsIngestRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'raw_sms'         => ['required', 'string', 'min:10', 'max:1000'],
            'bank_account_id' => ['required', 'integer', 'exists:bank_accounts,id'],
        ];
    }
}
