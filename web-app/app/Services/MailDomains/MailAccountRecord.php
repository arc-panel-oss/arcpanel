<?php

namespace App\Services\MailDomains;

/**
 * One mail account as `v-list-mail-account(s) USER DOMAIN [ACCOUNT] json`
 * returns it. ALIAS and FWD are comma-separated; the forward ":blackhole:"
 * is "Discard all mail".
 */
final class MailAccountRecord
{
    public const BLACKHOLE = ':blackhole:';

    public function __construct(
        public readonly string $user,
        public readonly string $domain,
        public readonly string $account,
        /** @var list<string> */
        public readonly array $aliases,
        /** @var list<string> every stored forward, ":blackhole:" included */
        public readonly array $forwards,
        public readonly bool $fwdOnly,
        public readonly bool $autoreply,
        /** QUOTA as stored: a number of MB or "unlimited" */
        public readonly string $quota,
        public readonly string $rateLimit,
        public readonly int $diskMb,
        public readonly bool $suspended,
        public readonly string $date,
        public readonly string $time,
    ) {}

    /** @param  array<string, mixed>  $row */
    public static function fromArray(string $user, string $domain, string $account, array $row): self
    {
        $value = fn (string $key): string => trim((string) ($row[$key] ?? ''));
        $list = fn (string $key): array => array_values(array_filter(array_map('trim', explode(',', $value($key))), fn (string $item) => $item !== ''));

        return new self(
            user: $user,
            domain: $domain,
            account: $account,
            aliases: $list('ALIAS'),
            forwards: $list('FWD'),
            fwdOnly: $value('FWD_ONLY') === 'yes',
            autoreply: $value('AUTOREPLY') === 'yes',
            quota: $value('QUOTA'),
            rateLimit: $value('RATE_LIMIT'),
            diskMb: (int) $value('U_DISK'),
            suspended: $value('SUSPENDED') === 'yes',
            date: $value('DATE'),
            time: $value('TIME'),
        );
    }

    public function email(): string
    {
        return $this->account.'@'.$this->domain;
    }

    /** "Discard all mail": the account forwards to :blackhole:. */
    public function discards(): bool
    {
        return in_array(self::BLACKHOLE, $this->forwards, true);
    }

    /** @return list<string> the forwards the form shows (without :blackhole:) */
    public function forwardAddresses(): array
    {
        return array_values(array_filter($this->forwards, fn (string $forward) => $forward !== self::BLACKHOLE));
    }

    /** Whether the quota is unlimited ("unlimited", 0 or empty). */
    public function unlimitedQuota(): bool
    {
        return in_array($this->quota, ['', '0', 'unlimited'], true);
    }
}
