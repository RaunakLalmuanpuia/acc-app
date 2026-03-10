<?php

namespace App\Services\Banking;

use App\Ai\Agents\Narration\NarrationSuggestionAgent;
use App\DTOs\Banking\NarrationSuggestionDTO;
use App\Models\NarrationHead;

class NarrationAiService
{
    public function suggest(
        string $rawNarration,
        string $type,
        float  $amount,
        string $date,
        int    $companyId
    ): NarrationSuggestionDTO {

        $catalog = $this->buildCatalog($companyId, $type);

        $catalogJson = json_encode($catalog, JSON_PRETTY_PRINT);
        $amountStr   = number_format($amount, 2);

        $prompt = <<<PROMPT
        Transaction Details:
        - Raw Narration : {$rawNarration}
        - Type          : {$type}
        - Amount        : ₹{$amountStr}
        - Date          : {$date}

        Available Narration Catalog (Head → Sub-heads):
        {$catalogJson}

        Categorize this transaction using only the heads and sub-heads listed above.
        PROMPT;

        // The SDK returns a StructuredAgentResponse — access like an array
        $response = NarrationSuggestionAgent::make()->prompt($prompt);

        [$headId, $subHeadId] = $this->resolveIds(
            $response['narration_head_name'] ?? '',
            $response['narration_sub_head_name'] ?? '',
            $companyId,
            $type
        );

        return new NarrationSuggestionDTO(
            narrationHeadId:    $headId,
            narrationSubHeadId: $subHeadId,
            narrationNote:      $response['narration_note'] ?? null,
            partyName:          $response['party_name'] ?: null,
            source:             'ai_suggested',
            confidence:         (float) ($response['confidence'] ?? 0.5),
            aiSuggestions:      $response['alternatives'] ?? [],
            appliedRuleId:      null,
            aiMetadata:         [
                'reasoning'     => $response['reasoning'] ?? null,
                'head_name'     => $response['narration_head_name'] ?? null,
                'sub_head_name' => $response['narration_sub_head_name'] ?? null,
            ],
        );
    }

    // ── Private Helpers ────────────────────────────────────────────────────

    private function buildCatalog(int $companyId, string $type): array
    {
        return NarrationHead::with('activeSubHeads')
            ->forCompany($companyId)
            ->active()
            ->forTransactionType($type)
            ->orderBy('sort_order')
            ->get()
            ->map(fn($head) => [
                'head'      => $head->name,
                'sub_heads' => $head->activeSubHeads->pluck('name')->toArray(),
            ])
            ->toArray();
    }

    private function resolveIds(string $headName, string $subHeadName, int $companyId, string $type): array
    {
        $head = NarrationHead::forCompany($companyId)
            ->active()
            ->forTransactionType($type)
            ->where('name', $headName)
            ->first();

        if (!$head) {
            return [null, null];
        }

        $subHead = $head->activeSubHeads()->where('name', $subHeadName)->first();

        return [$head->id, $subHead?->id];
    }
}
