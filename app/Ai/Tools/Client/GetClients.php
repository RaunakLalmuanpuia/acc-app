<?php

namespace App\Ai\Tools\Client;

use App\Models\User;
use App\Services\ClientService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetClients implements Tool
{
    protected ClientService $service;

    public function __construct(protected User $user)
    {
        $this->service = new ClientService($user);
    }

    public function description(): Stringable|string
    {
        return 'List clients for the company. Supports optional text search (name, email, phone, city), '
            . 'filtering by active status, and pagination (max 50 per page).';
    }

    public function handle(Request $request): Stringable|string
    {
        $company = $this->service->getCompany();

        if (! $company) {
            return json_encode(['success' => false, 'message' => 'No company profile found.']);
        }

        return json_encode($this->service->list(
            company:  $company,
            search:   $request['search']    ?? null,
            isActive: isset($request['is_active']) ? (bool) $request['is_active'] : null,
            page:     max((int) ($request['page']     ?? 1), 1),
            perPage:  min((int) ($request['per_page'] ?? 20), 50),
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'search'    => $schema->string(),
            'is_active' => $schema->boolean(),
            'page'      => $schema->integer()->min(1),
            'per_page'  => $schema->integer()->min(1)->max(15),
        ];
    }
}
