<?php

namespace App\Services;

use App\Models\Company;
use App\Models\NarrationHead;
use App\Models\NarrationSubHead;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class NarrationService
{
    public function __construct(protected User $user) {}

    protected function getCompany(): ?Company
    {
        return $this->user->companies()->first();
    }

    protected function generateSlug(string $name): string
    {
        return Str::slug($name);
    }

    public function getHeads(array $filters = []): Collection
    {
        $company = $this->getCompany();


        $query = NarrationHead::query()
            ->where('company_id', $company->id)
            ->orderBy('sort_order');


        if (!empty($filters['type']) && $filters['type'] !== 'both') {
            $query->whereIn('type', [$filters['type'], 'both']);
        }


        return $query->with('activeSubHeads')->get();
    }

    public function findHead(int $id): ?NarrationHead
    {
        $company = $this->getCompany();

        return NarrationHead::where('id', $id)
            ->where(function ($q) use ($company) {
                $q->where('is_system', true);
                if ($company) {
                    $q->orWhere('company_id', $company->id);
                }
                $q->orWhereNull('company_id');
            })
            ->first();
    }

    public function createHead(array $data): NarrationHead
    {
        $company = $this->getCompany();

        return NarrationHead::create([
            'company_id'  => $company?->id,
            'name'        => $data['name'],
            'slug'        => $data['slug'] ?? $this->generateSlug($data['name']),
            'type'        => $data['type'],
            'description' => $data['description'] ?? null,
            'color'       => $data['color'] ?? null,
            'icon'        => $data['icon'] ?? null,
            'sort_order'  => $data['sort_order'] ?? 0,
            'is_active'   => $data['is_active'] ?? true,
            'is_system'   => false,
        ]);
    }

    public function updateHead(NarrationHead $head, array $data): bool
    {
        $updatable = ['name', 'type', 'description', 'color', 'icon', 'sort_order', 'is_active'];
        $updates   = array_intersect_key($data, array_flip($updatable));

        if (isset($updates['name'])) {
            $updates['slug'] = $this->generateSlug($updates['name']);
        }

        return $head->update($updates);
    }

    public function deleteHead(NarrationHead $head): bool
    {
        $head->subHeads()->delete();
        return (bool) $head->delete();
    }

    public function formatHead(NarrationHead $head, bool $withSubHeads = false): array
    {
        $data = [
            'id'          => $head->id,
            'name'        => $head->name,
            'slug'        => $head->slug,
            'type'        => $head->type,
            'description' => $head->description,
            'color'       => $head->color,
            'icon'        => $head->icon,
            'sort_order'  => $head->sort_order,
            'is_active'   => $head->is_active,
            'is_system'   => $head->is_system,
        ];

        if ($withSubHeads) {
            $subHeads = $head->relationLoaded('activeSubHeads')
                ? $head->activeSubHeads
                : $head->activeSubHeads()->get();

            $data['sub_heads'] = $subHeads
                ->map(fn($s) => $this->formatSubHead($s))
                ->values()
                ->all();
        }

        return $data;
    }

    public function getSubHeads(int $headId, array $filters = []): ?Collection
    {
        $head = $this->findHead($headId);

        if (! $head) {
            return null;
        }

        $query = NarrationSubHead::where('narration_head_id', $headId);

        if (! empty($filters['active_only'])) {
            $query->where('is_active', true);
        }

        return $query->orderBy('sort_order')->get();
    }

    public function findSubHead(int $id): ?NarrationSubHead
    {
        $subHead = NarrationSubHead::find($id);

        if (! $subHead) {
            return null;
        }

        if (! $this->findHead($subHead->narration_head_id)) {
            return null;
        }

        return $subHead;
    }

    public function createSubHead(NarrationHead $head, array $data): NarrationSubHead
    {
        return NarrationSubHead::create([
            'narration_head_id'  => $head->id,
            'name'               => $data['name'],
            'slug'               => $data['slug'] ?? $this->generateSlug($data['name']),
            'description'        => $data['description'] ?? null,
            'ledger_code'        => $data['ledger_code'] ?? null,
            'ledger_name'        => $data['ledger_name'] ?? null,
            'requires_reference' => $data['requires_reference'] ?? false,
            'requires_party'     => $data['requires_party'] ?? false,
            'custom_fields'      => $data['custom_fields'] ?? null,
            'sort_order'         => $data['sort_order'] ?? 0,
            'is_active'          => $data['is_active'] ?? true,
            'is_system'          => false,
        ]);
    }

    public function updateSubHead(NarrationSubHead $subHead, array $data): bool
    {
        $updatable = [
            'name', 'description', 'ledger_code', 'ledger_name',
            'requires_reference', 'requires_party', 'custom_fields',
            'sort_order', 'is_active',
        ];

        $updates = array_intersect_key($data, array_flip($updatable));

        if (isset($updates['name'])) {
            $updates['slug'] = $this->generateSlug($updates['name']);
        }

        return $subHead->update($updates);
    }

    public function deleteSubHead(NarrationSubHead $subHead): bool
    {
        return (bool) $subHead->delete();
    }

    public function formatSubHead(NarrationSubHead $subHead): array
    {
        return [
            'id'                 => $subHead->id,
            'narration_head_id'  => $subHead->narration_head_id,
            'name'               => $subHead->name,
            'slug'               => $subHead->slug,
            'description'        => $subHead->description,
            'ledger_code'        => $subHead->ledger_code,
            'ledger_name'        => $subHead->ledger_name,
            'requires_reference' => $subHead->requires_reference,
            'requires_party'     => $subHead->requires_party,
            'custom_fields'      => $subHead->custom_fields,
            'sort_order'         => $subHead->sort_order,
            'is_active'          => $subHead->is_active,
            'is_system'          => $subHead->is_system,
        ];
    }
}
