<?php

namespace App\Ai\Tools\Inventory;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetInventory implements Tool
{
    public function __construct(protected User $user) {}

    public function description(): Stringable|string
    {
        return 'List inventory items for the user\'s company. Supports filtering by name/SKU/category, active status, low stock, and pagination.';
    }

    public function handle(Request $request): Stringable|string
    {
        $company = $this->user->companies()->first();

        if (! $company) {
            return json_encode(['success' => false, 'message' => 'No company profile found.']);
        }

        $query = $company->inventoryItems();

        if (! empty($request['search'])) {
            $search = $request['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%");
            });
        }

        if (isset($request['is_active'])) {
            $query->where('is_active', (bool) $request['is_active']);
        }

        if (! empty($request['low_stock_only'])) {
            $query->where('track_stock', true)
                ->whereColumn('stock_quantity', '<=', 'low_stock_alert');
        }

        if (! empty($request['category'])) {
            $query->where('category', $request['category']);
        }

        $perPage = min((int) ($request['per_page'] ?? 20), 50);
        $page    = max((int) ($request['page']     ?? 1), 1);

        $total = $query->count();
        $items = $query->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get([
                'id', 'name', 'sku', 'category', 'unit',
                'hsn_code', 'gst_rate', 'rate', 'cost_price', 'mrp',
                'track_stock', 'stock_quantity', 'low_stock_alert', 'is_active',
            ])
            ->toArray();

        return json_encode([
            'success' => true,
            'total'   => $total,
            'page'    => $page,
            'items'   => $items,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'search'         => $schema->string(),
            'category'       => $schema->string(),
            'is_active'      => $schema->boolean(),
            'low_stock_only' => $schema->boolean(),
            'page'           => $schema->integer()->min(1),
            'per_page'       => $schema->integer()->min(1)->max(50),
        ];
    }
}
