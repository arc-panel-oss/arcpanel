<?php

namespace App\Services\Deploy\Oidc;

use InvalidArgumentException;
use RuntimeException;

/**
 * An OIDC token refused by the upload endpoint (E20 spec §6.3): it answers
 * 401 with the short `reason`. `claims` holds the decoded but unverified
 * claims for the panel log; the token itself is never kept.
 */
final class OidcRejected extends RuntimeException
{
    public const ISSUER = 'issuer';

    public const SIGNATURE = 'signature';

    public const AUDIENCE = 'audience';

    public const EXPIRED = 'expired';

    public const PUBLISHER = 'publisher';

    public const REPLAY = 'replay';

    public const REASONS = [self::ISSUER, self::SIGNATURE, self::AUDIENCE, self::EXPIRED, self::PUBLISHER, self::REPLAY];

    /**
     * @param  array<string, mixed>  $claims  decoded claims, unverified
     */
    public function __construct(
        public readonly string $reason,
        public readonly array $claims = [],
    ) {
        if (! in_array($reason, self::REASONS, true)) {
            throw new InvalidArgumentException("Unknown OIDC rejection reason: {$reason}");
        }

        parent::__construct("OIDC token rejected: {$reason}");
    }
}
