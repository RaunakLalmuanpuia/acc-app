<?php

namespace App\Ai\Tools\Narration;

use App\Services\NarrationService;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class DeleteNarrationSubHead implements Tool
{
    protected NarrationService $service;

    public function __construct(protected User $user)
    {
        $this->service = new NarrationService($user);
    }

    public function description(): Stringable|string
    {
        return 'Delete a narration sub-head by its ID. System sub-heads cannot be deleted. Always confirm with the user before calling.';
    }

    public function handle(Request $request): Stringable|string
    {
        if (empty($request['sub_head_id'])) {
            return json_encode(['success' => false, 'message' => "Field 'sub_head_id' is required."]);
        }

        $subHead = $this->service->findSubHead((int) $request['sub_head_id']);

        if (! $subHead) {
            return json_encode(['success' => false, 'message' => 'Sub-head not found or does not belong to your company.']);
        }

        if ($subHead->is_system) {
            return json_encode(['success' => false, 'message' => 'System sub-heads cannot be deleted.']);
        }

        $name = $subHead->name;

        $this->service->deleteSubHead($subHead);

        return json_encode([
            'success'      => true,
            'message'      => "Sub-head '{$name}' deleted successfully.",
            'deleted_name' => $name,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'sub_head_id' => $schema->integer()->description('ID of the sub-head to delete (required)'),
        ];
    }
}
