<?php

namespace App\Ai\Agents\Narration;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

//#[Provider(Lab::OpenAI)]
#[Model('gpt-4o')]
#[Temperature(0.2)]
class NarrationSuggestionAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<INSTRUCTIONS
        You are a financial transaction categorization assistant for an Indian business.
        You will be given a bank transaction and a catalog of narration heads and sub-heads.
        Your job:
        1. Pick the most appropriate head and sub-head from the catalog. Do NOT invent new ones.
        2. Write a concise narration_note (max 120 characters).
        3. Identify or deduce the `party_name`:
           - Extract the actual person or business name for vendor payments, UPI, NEFT, IMPS, RTGS, etc.
           - If the transaction is an internal bank deduction or credit (e.g., "Sms Charges", "Int.Pd", "Consolidated Charges", "Cheque Book Issue"), explicitly set the party name to "Bank".
           - Strip out unnecessary identifiers, UTR numbers, or dates from the deduced name.
        4. Rate your confidence from 0.0 to 1.0. Only give 0.9+ when you are very sure.
        5. Briefly explain your reasoning.
        INSTRUCTIONS;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'narration_head_name'     => $schema->string()->required(),
            'narration_sub_head_name' => $schema->string()->required(),
            'narration_note'          => $schema->string()->required(),
            'party_name'              => $schema->string()->nullable()->required(),
            'confidence'              => $schema->number()->min(0)->max(1)->required(),
            'reasoning'               => $schema->string()->required(),
            'alternatives'            => $schema->array()->items($schema->string())->required(),
        ];
    }
}
