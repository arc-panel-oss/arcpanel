<?php

namespace App\Services\Users;

use App\Auth\Role;

/**
 * One panel user as `v-list-user USER json` (or one entry of `v-list-users
 * json`) describes it, plus its owner from the tenancy sidecar.
 */
final class UserRecord
{
    /** Package limits that are counted against usage, with their usage key. */
    public const USAGE = [
        'WEB_DOMAINS' => 'U_WEB_DOMAINS',
        'DNS_DOMAINS' => 'U_DNS_DOMAINS',
        'MAIL_DOMAINS' => 'U_MAIL_DOMAINS',
        'MAIL_ACCOUNTS' => 'U_MAIL_ACCOUNTS',
        'DATABASES' => 'U_DATABASES',
        'CRON_JOBS' => 'U_CRON_JOBS',
        'DISK_QUOTA' => 'U_DISK',
        'BANDWIDTH' => 'U_BANDWIDTH',
    ];

    /**
     * @param  array<string, string>  $data
     */
    public function __construct(
        public readonly string $name,
        public readonly array $data,
        public readonly string $owner = '',
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function from(string $name, array $data, string $owner = ''): self
    {
        unset($data['RKEY']);

        return new self($name, array_map('strval', array_filter($data, 'is_scalar')), $owner);
    }

    public function get(string $key, string $default = ''): string
    {
        return $this->data[$key] ?? $default;
    }

    public function fullName(): string
    {
        return $this->get('NAME');
    }

    public function email(): string
    {
        return $this->get('CONTACT');
    }

    public function package(): string
    {
        return $this->get('PACKAGE');
    }

    public function role(): Role
    {
        return Role::fromValue($this->get('ROLE'));
    }

    public function suspended(): bool
    {
        return $this->get('SUSPENDED') === 'yes';
    }

    public function language(): string
    {
        return $this->get('LANGUAGE', 'en') ?: 'en';
    }

    /** @return list<string> */
    public function nameservers(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->get('NS')))));
    }

    public function loginDisabled(): bool
    {
        return $this->get('LOGIN_DISABLED') === 'yes';
    }

    public function loginUsesIpList(): bool
    {
        return $this->get('LOGIN_USE_IPLIST') === 'yes';
    }

    /** v-list-user prints TWOFA as yes or empty since 2.6.0 (E39 W5 N19). */
    public function twoFactor(): bool
    {
        return $this->get('TWOFA') !== '';
    }

    /** Unused two-factor recovery codes (E39 W5 §11.2). */
    public function recoveryCodesLeft(): int
    {
        return (int) $this->get('TWOFA_RECOVERY_LEFT');
    }

    /**
     * Usage against the package limit of each counted key.
     *
     * @return array<string, array{used: int, limit: string}>
     */
    public function usage(): array
    {
        $usage = [];
        foreach (self::USAGE as $limit => $used) {
            $usage[$limit] = ['used' => (int) $this->get($used, '0'), 'limit' => $this->get($limit, 'unlimited')];
        }

        return $usage;
    }

    public function withOwner(string $owner): self
    {
        return new self($this->name, $this->data, $owner);
    }
}
