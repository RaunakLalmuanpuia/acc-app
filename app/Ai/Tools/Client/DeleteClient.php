<?php

namespace App\Ai\Tools\Client;

use App\Models\User;
use App\Services\ClientService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class DeleteClient implements Tool
{
    protected ClientService $service;

    public function __construct(protected User $user)
    {
        $this->service = new ClientService($user);
    }

    public function description(): Stringable|string
    {
        return 'Soft-delete a client (invoice history is preserved). '
            . 'Provide client_id (preferred) or a unique name. '
            . 'Warns if unpaid invoices exist — set force=true to override.';
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

        return json_encode($this->service->delete($result, (bool) ($request['force'] ?? false)));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'client_id' => $schema->integer(),
            'name'      => $schema->string(),
            'force'     => $schema->boolean(),
        ];
    }
}
