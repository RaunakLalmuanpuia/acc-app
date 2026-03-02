<?php

namespace App\Ai\Tools\Client;

use App\Models\User;
use App\Services\ClientService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class UpdateClient implements Tool
{
    protected ClientService $service;

    public function __construct(protected User $user)
    {
        $this->service = new ClientService($user);
    }

    public function description(): Stringable|string
    {
        return 'Update one or more fields on an existing client. '
            . 'Requires client_id; only the fields you pass are changed.';
    }

    public function handle(Request $request): Stringable|string
    {
        $company = $this->service->getCompany();

        if (! $company) {
            return json_encode(['success' => false, 'message' => 'No company profile found.']);
        }

        $client = $company->clients()->find($request['client_id']);

        if (! $client) {
            return json_encode(['success' => false, 'message' => 'Client not found.']);
        }

        return json_encode($this->service->update($client, $request->toArray()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'client_id'     => $schema->integer()->required(),
            'name'          => $schema->string(),
            'email'         => $schema->string(),
            'phone'         => $schema->string(),
            'gst_number'    => $schema->string(),
            'pan_number'    => $schema->string(),
            'gst_type'      => $schema->string()->enum(['regular', 'composition', 'unregistered', 'sez', 'overseas']),
            'address'       => $schema->string(),
            'city'          => $schema->string(),
            'state'         => $schema->string(),
            'state_code'    => $schema->string(),
            'pincode'       => $schema->string(),
            'country'       => $schema->string(),
            'currency'      => $schema->string(),
            'payment_terms' => $schema->integer(),
            'credit_limit'  => $schema->number(),
            'notes'         => $schema->string(),
            'is_active'     => $schema->boolean(),
        ];
    }
}
