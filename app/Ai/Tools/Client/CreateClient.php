<?php

namespace App\Ai\Tools\Client;

use App\Models\User;
use App\Services\ClientService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class CreateClient implements Tool
{
    protected ClientService $service;

    public function __construct(protected User $user)
    {
        $this->service = new ClientService($user);
    }

    public function description(): Stringable|string
    {
        return 'Create a new client/customer. Name, state, and state_code are required. '
            . 'Returns the new client\'s ID and name.';
    }

    public function handle(Request $request): Stringable|string
    {
        $company = $this->service->getCompany();

        if (! $company) {
            return json_encode(['success' => false, 'message' => 'No company profile found. Please set up your company first.']);
        }

        return json_encode($this->service->create($company, $request->toArray()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name'          => $schema->string()->required(),
            'state'         => $schema->string()->required(),
            'state_code'    => $schema->string(),
            'gst_number'    => $schema->string(),
            'pan_number'    => $schema->string(),
            'gst_type'      => $schema->string()->enum(['regular', 'composition', 'unregistered', 'sez', 'overseas']),
            'email'         => $schema->string(),
            'phone'         => $schema->string()->required(),
            'address'       => $schema->string(),
            'city'          => $schema->string(),
            'pincode'       => $schema->string(),
            'country'       => $schema->string(),
            'currency'      => $schema->string(),
            'payment_terms' => $schema->integer(),
            'credit_limit'  => $schema->number(),
            'notes'         => $schema->string(),
        ];
    }
}
