<?php

namespace App\Ai\Tools\Narration;

use App\Services\NarrationService;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class CreateNarrationSubHead implements Tool
{
    protected NarrationService $service;

    public function __construct(protected User $user)
    {
        $this->service = new NarrationService($user);
    }

    public function description(): Stringable|string
    {
        return 'Create a new sub-head (sub-category) under an existing narration head. Requires the parent head ID and a name.';
    }

    public function handle(Request $request): Stringable|string
    {
        if (empty($request['head_id'])) {
            return json_encode(['success' => false, 'message' => "Field 'head_id' is required."]);
        }

        if (empty($request['name'])) {
            return json_encode(['success' => false, 'message' => "Field 'name' is required."]);
        }

        $head = $this->service->findHead((int) $request['head_id']);

        if (! $head) {
            return json_encode(['success' => false, 'message' => 'Narration head not found or does not belong to your company.']);
        }

        // Explicitly map the request data to a clean array
        $data = [
            'name'               => $request['name'],
            'description'        => $request['description'] ?? null,
            'ledger_code'        => $request['ledger_code'] ?? null,
            'ledger_name'        => $request['ledger_name'] ?? null,
            'requires_reference' => $request['requires_reference'] ?? false,
            'requires_party'     => $request['requires_party'] ?? false,
            'sort_order'         => $request['sort_order'] ?? 0,
            'is_active'          => $request['is_active'] ?? true,
        ];

        $subHead = $this->service->createSubHead($head, $data);

        return json_encode([
            'success'  => true,
            'message'  => "Sub-head '{$subHead->name}' created under '{$head->name}'.",
            'sub_head' => $this->service->formatSubHead($subHead),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'head_id'            => $schema->integer()->description('ID or Name of the parent narration head (required)'),
            'name'               => $schema->string()->description('Display name of the sub-head (required)'),
            'description'        => $schema->string()->description('Optional description'),
            'ledger_code'        => $schema->string()->description('Ledger/account code for accounting integration'),
            'ledger_name'        => $schema->string()->description('Ledger/account name'),
            'requires_reference' => $schema->boolean()->description('Whether a reference number is required for this sub-head'),
            'requires_party'     => $schema->boolean()->description('Whether a party name is required'),
            'sort_order'         => $schema->integer()->description('Display order (default: 0)'),
            'is_active'          => $schema->boolean()->description('Whether the sub-head is active (default: true)'),
        ];
    }
}
