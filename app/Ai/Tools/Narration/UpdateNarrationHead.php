<?php

namespace App\Ai\Tools\Narration;

use App\Services\NarrationService;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class UpdateNarrationHead implements Tool
{
    protected NarrationService $service;

    public function __construct(protected User $user)
    {
        $this->service = new NarrationService($user);
    }

    public function description(): Stringable|string
    {
        return 'Update an existing narration head by its ID. Only provided fields will be changed. System heads cannot be modified.';
    }

    public function handle(Request $request): Stringable|string
    {
        if (empty($request['head_id'])) {
            return json_encode(['success' => false, 'message' => "Field 'head_id' is required."]);
        }

        $head = $this->service->findHead((int) $request['head_id']);

        if (! $head) {
            return json_encode(['success' => false, 'message' => 'Narration head not found or does not belong to your company.']);
        }

        if ($head->is_system) {
            return json_encode(['success' => false, 'message' => "System heads cannot be modified."]);
        }

        $data = array_filter([
            'name'        => $request['name'] ?? null,
            'type'        => $request['type'] ?? null,
            'description' => $request['description'] ?? null,
            'color'       => $request['color'] ?? null,
            'icon'        => $request['icon'] ?? null,
            'sort_order'  => $request['sort_order'] ?? null,
            'is_active'   => $request['is_active'] ?? null,
        ], fn($v) => $v !== null);

        if (empty($data)) {
            return json_encode(['success' => false, 'message' => 'No fields provided to update.']);
        }

        $this->service->updateHead($head, $data);

        return json_encode([
            'success' => true,
            'message' => "Narration head '{$head->fresh()->name}' updated successfully.",
            'head'    => $this->service->formatHead($head->fresh()),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'head_id'     => $schema->integer()->description('ID of the narration head to update (required)'),
            'name'        => $schema->string()->description('New display name'),
            'type'        => $schema->string()->enum(['debit', 'credit', 'both'])->description('Transaction type'),
            'description' => $schema->string()->description('Description'),
            'color'       => $schema->string()->description('Hex color code'),
            'icon'        => $schema->string()->description('Icon identifier'),
            'sort_order'  => $schema->integer()->description('Display order'),
            'is_active'   => $schema->boolean()->description('Active status'),
        ];
    }
}
