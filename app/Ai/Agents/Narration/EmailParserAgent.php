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
#[Temperature(0.1)]  // deterministic parsing
class EmailParserAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<INSTRUCTIONS
        You are a bank transaction email parser for Indian banks.
        You will receive a raw bank alert or notification email (subject + body).

        Extract structured transaction data. Rules:
        - amount: always a positive float. Look for ₹, INR, Rs. prefixes. Strip commas.
        - type: "credit" if money was received/credited/deposited, "debit" if money was spent/debited/withdrawn/transferred out.
        - transaction_date: YYYY-MM-DD format. Parse DD-MM-YYYY, DD/MM/YYYY, or "01 Jan 2024" patterns. Use today's date if completely absent.
        - bank_reference: UTR/Ref/Txn/Transaction ID if present, else empty string.
        - balance_after: available/closing balance if mentioned, else 0.
        - party_name: merchant, sender, or recipient name if clearly identifiable (e.g. "Amazon", "Swiggy", "Rahul Sharma"). Null if not clearly stated.
        - bank_name: name of the bank sending this alert (e.g. "HDFC Bank", "SBI", "ICICI Bank"). Null if not determinable.
        - account_last4: last 4 digits of the account number if mentioned, else null.
        - raw_narration: the full email body text exactly as provided.

        Be precise. Never guess amounts or dates. If the email contains multiple transactions, extract only the primary/main transaction.
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
            'account_last4'    => $schema->string()->nullable()->required(),
            'raw_narration'    => $schema->string()->required(),
        ];
    }
}
