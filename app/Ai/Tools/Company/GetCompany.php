<?php

namespace App\Ai\Tools\Company;

use App\Services\CompanyService;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetCompany implements Tool
{
    protected CompanyService $service;

    public function __construct(protected User $user)
    {
        $this->service = new CompanyService($user);
    }

    public function description(): Stringable|string
    {
        return 'Retrieve the current user\'s company profile including name, GST number, PAN, address, bank details, and contact information.';
    }

    public function handle(Request $request): Stringable|string
    {
        $company = $this->service->getCompany();

        if (! $company) {
            return json_encode([
                'found'   => false,
                'message' => 'No company profile found. You can create one by providing the necessary details.',
            ]);
        }

        return json_encode([
            'found'   => true,
            'company' => $this->service->formatCompany($company),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
