<?php

namespace App\Services\Deploy\Oidc;

/**
 * An OIDC token that passed every check of E20 spec §6.3 steps 1–5.
 */
final class VerifiedToken
{
    /**
     * @param  array<string, mixed>  $claims
     */
    public function __construct(
        public readonly array $claims,
        /** PUB of the trusted publisher that matched */
        public readonly string $publisher,
        /** the token's `iss` as sent */
        public readonly string $issuer,
        /** the token's `sub`, empty without one */
        public readonly string $subject,
        public readonly int $exp,
    ) {}
}
