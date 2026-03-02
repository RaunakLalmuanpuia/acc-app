<?php

namespace App\Ai\Tools\Narration;

use App\Services\NarrationService;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class UpdateNarrationSubHead implements Tool
{
    protected NarrationService $service;

    public function __construct(protected User $user)
    {
        $this->service = new NarrationService($user);
    }

    public function description(): Stringable|string
    {
        return 'Update an existing narration sub-head by its ID. Only provided fields will be changed. System sub-heads cannot be modified.';
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
            return json_encode(['success' => false, 'message' => 'System sub-heads cannot be modified.']);
        }

        $data = (array) $request;
        unset($data['sub_head_id']);

        if (empty($data)) {
            return json_encode(['success' => false, 'message' => 'No fields provided to update.']);
        }

        $this->service->updateSubHead($subHead, $data);

        return json_encode([
            'success'  => true,
            'message'  => "Sub-head '{$subHead->fresh()->name}' updated successfully.",
            'sub_head' => $this->service->formatSubHead($subHead->fresh()),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'sub_head_id'        => $schema->integer()->description('ID of the sub-head to update (required)'),
            'name'               => $schema->string()->description('New display name'),
            'description'        => $schema->string()->description('Description'),
            'ledger_code'        => $schema->string()->description('Ledger/account code'),
            'ledger_name'        => $schema->string()->description('Ledger/account name'),
            'requires_reference' => $schema->boolean()->description('Reference number required'),
            'requires_party'     => $schema->boolean()->description('Party name required'),
            'sort_order'         => $schema->integer()->description('Display order'),
            'is_active'          => $schema->boolean()->description('Active status'),
        ];
    }
}
