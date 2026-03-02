<?php

namespace App\Ai\Tools\Inventory;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class UpdateInventoryItem implements Tool
{
    public function __construct(protected User $user) {}

    public function description(): Stringable|string
    {
        return 'Update an existing inventory item. Provide item_id and any fields to change. Use adjust_stock (positive or negative integer) to add or subtract from the current stock quantity.';
    }

    public function handle(Request $request): Stringable|string
    {
        $company = $this->user->companies()->first();

        if (! $company) {
            return json_encode(['success' => false, 'message' => 'No company profile found.']);
        }

        $item = $company->inventoryItems()->find($request['item_id']);

        if (! $item) {
            return json_encode(['success' => false, 'message' => 'Inventory item not found.']);
        }

        $updatable = [
            'name', 'sku', 'description', 'category', 'brand', 'unit',
            'hsn_code', 'gst_rate', 'rate', 'cost_price', 'mrp',
            'track_stock', 'stock_quantity', 'low_stock_alert', 'is_active',
        ];

        $updates = [];
        foreach ($updatable as $field) {
            if (isset($request[$field])) {
                $updates[$field] = $request[$field];
            }
        }

        if (isset($request['adjust_stock'])) {
            $updates['stock_quantity'] = max(0, $item->stock_quantity + (int) $request['adjust_stock']);
        }

        if (empty($updates)) {
            return json_encode(['success' => false, 'message' => 'No fields provided to update.']);
        }

        $item->update($updates);

        return json_encode([
            'success'        => true,
            'message'        => "Item \"{$item->name}\" updated successfully.",
            'updated_fields' => array_keys($updates),
            'current_stock'  => $item->fresh()->stock_quantity,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'item_id'         => $schema->integer()->required(),
            'name'            => $schema->string(),
            'sku'             => $schema->string(),
            'description'     => $schema->string(),
            'category'        => $schema->string(),
            'brand'           => $schema->string(),
            'unit'            => $schema->string(),
            'hsn_code'        => $schema->string(),
            'gst_rate'        => $schema->number()->min(0)->max(100),
            'rate'            => $schema->number(),
            'cost_price'      => $schema->number(),
            'mrp'             => $schema->number(),
            'track_stock'     => $schema->boolean(),
            'stock_quantity'  => $schema->integer()->min(0),
            'adjust_stock'    => $schema->integer(),
            'low_stock_alert' => $schema->integer()->min(0),
            'is_active'       => $schema->boolean(),
        ];
    }
}
