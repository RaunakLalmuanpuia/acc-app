<?php

namespace App\Ai\Tools\Inventory;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class CreateInventoryItem implements Tool
{
    public function __construct(protected User $user) {}

    public function description(): Stringable|string
    {
        return 'Create a new product or service item in the inventory. Name and selling rate are required.';
    }

    public function handle(Request $request): Stringable|string
    {
        $company = $this->user->companies()->first();

        if (! $company) {
            return json_encode(['success' => false, 'message' => 'Please set up your company profile first.']);
        }

        if (! empty($request['sku'])) {
            $skuExists = $company->inventoryItems()->where('sku', $request['sku'])->exists();
            if ($skuExists) {
                return json_encode([
                    'success' => false,
                    'message' => "An item with SKU \"{$request['sku']}\" already exists.",
                ]);
            }
        }

        $item = $company->inventoryItems()->create([
            'name'            => $request['name'],
            'sku'             => $request['sku']             ?? null,
            'description'     => $request['description']     ?? null,
            'category'        => $request['category']        ?? null,
            'brand'           => $request['brand']           ?? null,
            'unit'            => $request['unit']            ?? 'Nos',
            'hsn_code'        => $request['hsn_code']        ?? null,
            'gst_rate'        => $request['gst_rate']        ?? 0,
            'rate'            => $request['rate'],
            'cost_price'      => $request['cost_price']      ?? null,
            'mrp'             => $request['mrp']             ?? null,
            'track_stock'     => $request['track_stock']     ?? false,
            'stock_quantity'  => $request['stock_quantity']  ?? 0,
            'low_stock_alert' => $request['low_stock_alert'] ?? 0,
            'is_active'       => true,
        ]);

        return json_encode([
            'success'   => true,
            'message'   => "Item \"{$item->name}\" created successfully.",
            'item_id'   => $item->id,
            'item_name' => $item->name,
            'rate'      => $item->rate,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name'            => $schema->string()->required(),
            'rate'            => $schema->number()->required(),
            'sku'             => $schema->string(),
            'description'     => $schema->string(),
            'category'        => $schema->string(),
            'brand'           => $schema->string(),
            'unit'            => $schema->string(),
            'hsn_code'        => $schema->string(),
            'gst_rate'        => $schema->number()->min(0)->max(100),
            'cost_price'      => $schema->number(),
            'mrp'             => $schema->number(),
            'track_stock'     => $schema->boolean(),
            'stock_quantity'  => $schema->integer()->min(0),
            'low_stock_alert' => $schema->integer()->min(0),
        ];
    }
}
