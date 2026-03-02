<?php

namespace App\Ai\Tools\Inventory;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class DeleteInventoryItem implements Tool
{
    public function __construct(protected User $user) {}

    public function description(): Stringable|string
    {
        return 'Delete (soft-delete) an inventory item. Requires item_id or item name. Items referenced on existing invoices are preserved in those records — only the catalogue entry is removed.';
    }

    public function handle(Request $request): Stringable|string
    {
        $company = $this->user->companies()->first();

        if (! $company) {
            return json_encode(['success' => false, 'message' => 'No company profile found.']);
        }

        $query = $company->inventoryItems();

        if (! empty($request['item_id'])) {
            $query->where('id', $request['item_id']);
        } elseif (! empty($request['name'])) {
            $query->where('name', 'like', '%' . $request['name'] . '%');
        } else {
            return json_encode(['success' => false, 'message' => 'Provide either item_id or name.']);
        }

        $item = $query->first();

        if (! $item) {
            return json_encode(['success' => false, 'message' => 'Inventory item not found.']);
        }

        $name = $item->name;
        $item->delete();

        return json_encode([
            'success' => true,
            'message' => "Inventory item \"{$name}\" has been deleted successfully.",
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'item_id' => $schema->integer(),
            'name'    => $schema->string(),
        ];
    }
}
