<?php

namespace App\Ai\Tools\Company;

use App\Services\CompanyService;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class CreateCompany implements Tool
{
    protected CompanyService $service;

    public function __construct(protected User $user)
    {
        $this->service = new CompanyService($user);
    }

    public function description(): Stringable|string
    {
        return 'Create a new company profile for the user. Only allowed if the user does not already have a company. Requires at minimum a company name.';
    }

    public function handle(Request $request): Stringable|string
    {
        if ($this->service->hasCompany()) {
            return json_encode([
                'success' => false,
                'message' => 'You already have a company profile. Use update_company to make changes.',
            ]);
        }

        $required = ['company_name'];
        foreach ($required as $field) {
            if (empty($request[$field])) {
                return json_encode([
                    'success' => false,
                    'message' => "The field '{$field}' is required to create a company.",
                ]);
            }
        }

        $data = array_filter([
            // --- Company fields ---
            'company_name'        => $request['company_name'],
            'gst_number'          => $request['gst_number'] ?? null,
            'pan_number'          => $request['pan_number'] ?? null,
            'state'               => $request['state'] ?? null,
            'state_code'          => $request['state_code'] ?? null,
            'address'             => $request['address'] ?? null,
            'city'                => $request['city'] ?? null,
            'pincode'             => $request['pincode'] ?? null,
            'country'             => $request['country'] ?? 'India',
            'email'               => $request['email'] ?? null,
            'phone'               => $request['phone'] ?? null,
            'website'             => $request['website'] ?? null,
            'invoice_footer_note' => $request['invoice_footer_note'] ?? null,

            // --- Bank account fields (mapped to bank_accounts table columns) ---
            'account_name'        => $request['account_name'] ?? null,
            'bank_name'           => $request['bank_name'] ?? null,
            'account_number'      => $request['account_number'] ?? null,
            'ifsc_code'           => $request['ifsc_code'] ?? null,
            'branch'              => $request['branch'] ?? null,
            'account_type'        => $request['account_type'] ?? 'current',
            'currency'            => $request['currency'] ?? 'INR',
            'opening_balance'     => $request['opening_balance'] ?? null,
            'opening_balance_date'=> $request['opening_balance_date'] ?? null,
        ], fn($v) => $v !== null);

        $company = $this->service->createCompany($data);

        return json_encode([
            'success' => true,
            'message' => 'Company profile created successfully.',
            'company' => $this->service->formatCompany($company),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            // Company fields
            'company_name'         => $schema->string()->description('Legal name of the company (required)'),
            'gst_number'           => $schema->string()->description('GST registration number'),
            'pan_number'           => $schema->string()->description('PAN number'),
            'state'                => $schema->string()->description('State name'),
            'state_code'           => $schema->string()->description('2-digit state code for GST'),
            'address'              => $schema->string()->description('Street address'),
            'city'                 => $schema->string()->description('City'),
            'pincode'              => $schema->string()->description('PIN code'),
            'country'              => $schema->string()->description('Country (defaults to India)'),
            'email'                => $schema->string()->description('Business email'),
            'phone'                => $schema->string()->description('Business phone'),
            'website'              => $schema->string()->description('Business website'),
            'invoice_footer_note'  => $schema->string()->description('Footer note shown on invoices'),

            // Bank account fields
            'account_name'         => $schema->string()->description('Name on the bank account'),
            'bank_name'            => $schema->string()->description('Name of the bank'),
            'account_number'       => $schema->string()->description('Bank account number'),
            'ifsc_code'            => $schema->string()->description('Bank IFSC code'),
            'branch'               => $schema->string()->description('Bank branch name'),
            'account_type'         => $schema->string()->description('Account type: current, savings (default: current)'),
            'currency'             => $schema->string()->description('Currency code (default: INR)'),
            'opening_balance'      => $schema->number()->description('Opening balance amount'),
            'opening_balance_date' => $schema->string()->description('Opening balance date (YYYY-MM-DD)'),
        ];
    }
}
