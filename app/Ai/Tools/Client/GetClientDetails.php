<?php

namespace App\Ai\Tools\Client;

use App\Models\User;
use App\Services\ClientService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetClientDetails implements Tool
{
    protected ClientService $service;

    public function __construct(protected User $user)
    {
        $this->service = new ClientService($user);
    }

    public function description(): Stringable|string
    {
        return 'Get full details for a specific client: contact info, GST/PAN, payment terms, '
            . 'outstanding balance, and the 5 most recent invoices. '
            . 'Provide client_id (preferred) or a unique name fragment.';
    }

    public function handle(Request $request): Stringable|string
    {
        $company = $this->service->getCompany();

        if (! $company) {
            return json_encode(['success' => false, 'message' => 'No company profile found.']);
        }

        $result = $this->service->resolveClient(
            $company,
            $request['client_id'] ?? null,
            $request['name']      ?? null,
        );

        if (is_array($result)) {
            return json_encode($result); // error payload
        }

        return json_encode($this->service->detail($result));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'client_id' => $schema->integer(),
            'name'      => $schema->string(),
        ];
    }
}
