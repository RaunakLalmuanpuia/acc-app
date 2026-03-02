<?php

namespace App\Ai\Tools\Narration;

use App\Services\NarrationService;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class DeleteNarrationHead implements Tool
{
    protected NarrationService $service;

    public function __construct(protected User $user)
    {
        $this->service = new NarrationService($user);
    }

    public function description(): Stringable|string
    {
        return 'Delete a narration head and all its sub-heads by ID. System heads cannot be deleted. This action is irreversible — always confirm with the user before calling.';
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
            return json_encode(['success' => false, 'message' => 'System heads cannot be deleted.']);
        }

        $subHeadCount = $head->subHeads()->count();
        $name         = $head->name;

        $this->service->deleteHead($head);

        return json_encode([
            'success'            => true,
            'message'            => "Narration head '{$name}' and {$subHeadCount} sub-head(s) deleted successfully.",
            'deleted_head'       => $name,
            'deleted_sub_heads'  => $subHeadCount,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'head_id' => $schema->integer()->description('ID of the narration head to delete (required)'),
        ];
    }
}
