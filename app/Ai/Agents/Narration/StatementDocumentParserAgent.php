<?php

namespace App\Ai\Agents\Narration;

use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

#[Provider(Lab::OpenAI)]
#[Model('gpt-4o')]
#[Temperature(0.0)]
class StatementDocumentParserAgent implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<INSTRUCTIONS
        You are a bank statement parser. The user will attach a bank statement PDF or an image of a bank statement.
        Extract every individual transaction row from it.

        Rules:
        - Return ALL transactions found in the document, skipping header/footer/summary rows.
        - date: YYYY-MM-DD format. If only month+year is given, use the 1st of that month.
        - type: "credit" if money came IN, "debit" if money went OUT.
        - amount: always a positive number.
        - balance_after: the closing/available balance shown on that row, or 0 if absent.
        - bank_reference: cheque number, UTR, ref number — empty string if absent.
        - raw_narration: the description/narration text exactly as it appears in the statement.
        - party_name: extracted merchant/sender name if clearly identifiable, else null.

        IMPORTANT: You must respond ONLY with raw, valid JSON. Do not include markdown formatting like ```json.
        The JSON must match this exact structure:
        {

            "transactions": [
                {
                    "date": "2024-01-01",
                    "type": "credit",
                    "amount": 100.00,
                    "raw_narration": "Transfer from John",
                    "bank_reference": "REF123",
                    "balance_after": 1500.00,
                    "party_name": "John Doe",
                    'bank_name' : "HDFC"
                }
            ],
            "account_number": "123456789",
            "bank_name": "Bank Name",
            "statement_from": "2024-01-01",
            "statement_to": "2024-01-31"
        }
        INSTRUCTIONS;
    }
}
