<?php

namespace App\Services\Files;

/**
 * What the file manager needs from `v-list-user USER json`: the home
 * directory, disk use and quota in MB, and the suspension.
 */
final class FileAccount
{
    public function __construct(
        public readonly string $user,
        public readonly string $home,
        public readonly int $usedMb,
        /** null when the package has no limit */
        public readonly ?int $quotaMb,
        public readonly bool $suspended,
    ) {}

    /** @param  array<mixed>  $record */
    public static function from(string $user, array $record): self
    {
        $quota = (string) ($record['DISK_QUOTA'] ?? 'unlimited');
        $home = (string) ($record['HOME'] ?? '');

        return new self(
            user: $user,
            home: $home !== '' ? $home : '/home/'.$user,
            usedMb: (int) ($record['U_DISK'] ?? 0),
            quotaMb: ctype_digit($quota) ? (int) $quota : null,
            suspended: ($record['SUSPENDED'] ?? 'no') === 'yes',
        );
    }

    /** Whether $bytes more would go over the package's disk quota. */
    public function exceedsQuota(int $bytes): bool
    {
        return $this->quotaMb !== null && $this->usedMb + (int) ceil($bytes / 1048576) > $this->quotaMb;
    }

    /** @return array{used: int, quota: int|null} */
    public function quota(): array
    {
        return ['used' => $this->usedMb, 'quota' => $this->quotaMb];
    }
}
