<?php

namespace App\Services\Banking;

use App\DTOs\Banking\NarrationSuggestionDTO;
use App\Models\NarrationRule;

class NarrationRuleEngine
{
    public function match(string $narration, string $type, float $amount, int $companyId): ?NarrationSuggestionDTO
    {
        $rule = NarrationRule::findBestMatch($narration, $type, $amount, $companyId);

        if (!$rule) {
            return null;
        }

        $rule->increment('match_count');
        $rule->update(['last_matched_at' => now()]);

        return new NarrationSuggestionDTO(
            narrationHeadId:    $rule->narration_head_id,
            narrationSubHeadId: $rule->narration_sub_head_id,
            narrationNote:      $rule->generateNote($narration, $amount),
            partyName:          null,
            source:             'rule_based',
            confidence:         1.00,
            aiSuggestions:      [],
            appliedRuleId:      $rule->id,
            aiMetadata:         [],
        );
    }
}
