<?php

namespace App\Ai\Tools\Narration;

use App\Services\NarrationService;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetNarrationHeads implements Tool
{
    protected NarrationService $service;

    public function __construct(protected User $user)
    {
        $this->service = new NarrationService($user);
    }

    public function description(): Stringable|string
    {
        return 'List all narration heads (transaction categories) with their sub-heads. '
            . 'Call with NO arguments to get every head. '
            . 'Only pass "type" when the user explicitly asks to filter by debit/credit/both. '
            . 'Never pass any other filters unless the user specifically requests them.';
    }

    public function handle(Request $request): Stringable|string
    {
        $filters = [];

        // Only apply type filter when the user explicitly asks for it
        if (! empty($request['type'])) {
            $filters['type'] = $request['type'];
        }

        // active_only is intentionally NOT read from the request here.
        // We always return all heads (active and inactive) so nothing is silently hidden.

        $heads = $this->service->getHeads($filters);

        if ($heads->isEmpty()) {
            return json_encode([
                'count'   => 0,
                'heads'   => [],
                'message' => 'No narration heads found.',
            ]);
        }

        return json_encode([
            'count' => $heads->count(),
            'heads' => $heads->map(fn($h) => $this->service->formatHead($h, true))->values()->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            // Only one optional param — type filter.
            // active_only is intentionally removed to prevent the AI from auto-applying it.
            'type' => $schema->string()->enum(['debit', 'credit', 'both'])
                ->description('Only pass this when the user explicitly asks to filter by transaction type.'),
        ];
    }
}
