<?php

namespace App\Http\Requests\Banking;

use Illuminate\Foundation\Http\FormRequest;

class StatementUploadRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'bank_account_id' => ['required', 'integer', 'exists:bank_accounts,id'],
            'statement'       => [
                'required',
                'file',
                'max:20480',                          // 20 MB max
                'mimes:pdf,csv,xlsx,xls,jpg,jpeg,png',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'statement.mimes' =>  'Please upload a PDF, CSV, Excel (.xlsx/.xls), or Image (.jpg/.png) bank statement.',
            'statement.max'   => 'The statement file must not be larger than 20MB.',
        ];
    }
}
