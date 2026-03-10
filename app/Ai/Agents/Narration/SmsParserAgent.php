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

#[Provider(Lab::OpenAI)]
#[Model('gpt-4o')]
#[Temperature(0.1)]   // low temperature — we want deterministic parsing
class SmsParserAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<INSTRUCTIONS
        You are a bank SMS parser for Indian banks. Extract structured transaction data from raw SMS text.
        Rules:
        - amount must always be a positive float
        - type must be exactly "credit" or "debit"
        - transaction_date must be in YYYY-MM-DD format; use today's date if not present
        - bank_reference: UTR/Ref/Txn number if present, else empty string
        - balance_after: balance figure if mentioned, else 0
        - party_name: merchant or counterparty name if clearly identifiable, else null
        - raw_narration: the sms received itself
        Be precise; never guess amounts or dates.
        INSTRUCTIONS;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'type'             => $schema->string()->enum(['credit', 'debit'])->required(),
            'amount'           => $schema->number()->min(0)->required(),
            'bank_reference'   => $schema->string()->required(),
            'party_name'       => $schema->string()->nullable()->required(),
            'transaction_date' => $schema->string()->required(),
            'balance_after'    => $schema->number()->min(0)->required(),
            'bank_name'        => $schema->string()->nullable()->required(),
            'raw_narration'    => $schema->string()->required(),
        ];
    }
}
