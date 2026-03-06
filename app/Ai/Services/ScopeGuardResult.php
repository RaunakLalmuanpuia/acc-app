<?php

namespace App\Ai\Services;

/**
 * Immutable result from ScopeGuardService::evaluate().
 */
final class ScopeGuardResult
{
    private function __construct(
        public readonly bool    $allowed,
        public readonly ?string $response,
        public readonly ?string $reason,  // 'jailbreak' | 'out_of_scope' | null
    ) {}

    public static function allowed(): self
    {
        return new self(true, null, null);
    }

    public static function blocked(string $response, string $reason): self
    {
        return new self(false, $response, $reason);
    }
}
