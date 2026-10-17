<?php

namespace App\Services\Keys;

use DateTimeImmutable;

/**
 * An API access key of `v-list-access-keys USER json` or
 * `v-list-access-key KEY json`. The secret is only kept for the answer of
 * `v-add-access-key` (spec §8.2); every other record drops it. A key may be
 * limited to addresses and expire (E43 B6).
 */
final class AccessKey
{
    /**
     * @param  list<string>  $permissions
     * @param  list<string>  $allowedIps  addresses and networks; empty without a restriction
     */
    public function __construct(
        public readonly string $id,
        public readonly string $user,
        public readonly array $permissions,
        public readonly string $comment,
        public readonly string $date,
        public readonly string $time,
        public readonly ?string $secret = null,
        public readonly array $allowedIps = [],
        public readonly ?DateTimeImmutable $expires = null,
    ) {}

    /** @param  array<string, mixed>  $row */
    public static function fromArray(array $row, bool $withSecret = false): self
    {
        $permissions = $row['PERMISSIONS'] ?? [];
        $ips = $row['ALLOWED_IPS'] ?? [];
        $expires = (string) ($row['EXPIRES'] ?? '');

        return new self(
            id: (string) ($row['ACCESS_KEY_ID'] ?? ''),
            user: (string) ($row['USER'] ?? ''),
            permissions: is_array($permissions) ? array_values(array_map('strval', $permissions)) : [],
            comment: (string) ($row['COMMENT'] ?? ''),
            date: (string) ($row['DATE'] ?? ''),
            time: (string) ($row['TIME'] ?? ''),
            secret: $withSecret && isset($row['SECRET_ACCESS_KEY']) ? (string) $row['SECRET_ACCESS_KEY'] : null,
            allowedIps: is_array($ips) ? array_values(array_filter(array_map('strval', $ips), fn (string $ip) => $ip !== '')) : [],
            expires: ctype_digit($expires) ? (new DateTimeImmutable)->setTimestamp((int) $expires) : null,
        );
    }

    /** Whether the key is refused because its expiry has passed. */
    public function expired(): bool
    {
        return $this->expires !== null && $this->expires->getTimestamp() <= now()->getTimestamp();
    }

    /** Whether the key expires within AccessKeyRestrictions::EXPIRES_SOON_DAYS. */
    public function expiresSoon(): bool
    {
        return $this->expires !== null && ! $this->expired()
            && $this->expires->getTimestamp() <= now()->addDays(AccessKeyRestrictions::EXPIRES_SOON_DAYS)->getTimestamp();
    }
}
