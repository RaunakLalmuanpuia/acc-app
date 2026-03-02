<?php

namespace App\Ai\Tools\Narration;

use App\Services\NarrationService;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetNarrationSubHeads implements Tool
{
    protected NarrationService $service;

    public function __construct(protected User $user)
    {
        $this->service = new NarrationService($user);
    }

    public function description(): Stringable|string
    {
        return 'List all sub-heads (sub-categories) under a specific narration head. Requires the parent head ID.';
    }

    public function handle(Request $request): Stringable|string
    {
        if (empty($request['head_id'])) {
            return json_encode(['success' => false, 'message' => "Field 'head_id' is required."]);
        }

        $filters = [];
        if (isset($request['active_only'])) {
            $filters['active_only'] = (bool) $request['active_only'];
        }

        $subHeads = $this->service->getSubHeads((int) $request['head_id'], $filters);

        if ($subHeads === null) {
            return json_encode(['success' => false, 'message' => 'Narration head not found or does not belong to your company.']);
        }

        return json_encode([
            'head_id'   => (int) $request['head_id'],
            'count'     => $subHeads->count(),
            'sub_heads' => $subHeads->map(fn($s) => $this->service->formatSubHead($s))->values()->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'head_id'     => $schema->integer()->description('ID of the parent narration head (required)'),
            'active_only' => $schema->boolean()->description('Return only active sub-heads (default: false)'),
        ];
    }
}
