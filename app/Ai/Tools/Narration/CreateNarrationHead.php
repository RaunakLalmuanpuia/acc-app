<?php

namespace App\Ai\Tools\Narration;

use App\Services\NarrationService;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class CreateNarrationHead implements Tool
{
    protected NarrationService $service;

    public function __construct(protected User $user)
    {
        $this->service = new NarrationService($user);
    }

    public function description(): Stringable|string
    {
        return 'Create a new narration head (transaction category) for the company. Requires a name and transaction type.';
    }

    public function handle(Request $request): Stringable|string
    {
        if (empty($request['name'])) {
            return json_encode(['success' => false, 'message' => "Field 'name' is required."]);
        }

        if (empty($request['type'])) {
            return json_encode(['success' => false, 'message' => "Field 'type' is required (debit, credit, or both)."]);
        }

        if (! in_array($request['type'], ['debit', 'credit', 'both'])) {
            return json_encode(['success' => false, 'message' => "Invalid type. Must be 'debit', 'credit', or 'both'."]);
        }

        // ✅ Explicitly map — never cast Request to array directly
        $data = [
            'name'        => $request['name'],
            'type'        => $request['type'],
            'description' => $request['description'] ?? null,
            'color'       => $request['color'] ?? null,
            'icon'        => $request['icon'] ?? null,
            'sort_order'  => $request['sort_order'] ?? 0,
            'is_active'   => $request['is_active'] ?? true,
        ];

        $head = $this->service->createHead($data);

        return json_encode([
            'success' => true,
            'message' => "Narration head '{$head->name}' created successfully.",
            'head'    => $this->service->formatHead($head),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name'        => $schema->string()->description('Display name of the narration head (required)'),
            'type'        => $schema->string()->enum(['debit', 'credit', 'both'])->description('Transaction type this head applies to (required)'),
            'description' => $schema->string()->description('Optional description'),
            'color'       => $schema->string()->description('Optional hex color code for UI display'),
            'icon'        => $schema->string()->description('Optional icon identifier'),
            'sort_order'  => $schema->integer()->description('Display order (lower = higher up, default: 0)'),
            'is_active'   => $schema->boolean()->description('Whether the head is active (default: true)'),
        ];
    }
}
