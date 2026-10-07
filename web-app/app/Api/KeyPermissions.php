<?php

namespace App\Api;

use App\Auth\Role;

/**
 * The access key of an API v2 request as `v-check-access-key-permissions`
 * describes it: owner, role, permission sets and the commands they allow
 * (E34 spec D3, D5). `*` allows every command (the administrator's key
 * without sets).
 */
final class KeyPermissions
{
    /**
     * @param  list<string>  $sets
     * @param  list<string>  $commands
     */
    public function __construct(
        public readonly string $keyId,
        public readonly string $user,
        public readonly Role $role,
        public readonly array $sets,
        public readonly array $commands,
    ) {}

    /** @param  array<string, mixed>  $data  `v-check-access-key-permissions … json` */
    public static function fromCommand(array $data): self
    {
        $list = fn (string $key): array => array_values(array_filter((array) ($data[$key] ?? []), fn ($value) => is_string($value) && $value !== ''));

        return new self(
            keyId: (string) ($data['ACCESS_KEY_ID'] ?? ''),
            user: (string) ($data['USER'] ?? ''),
            role: Role::fromValue((string) ($data['ROLE'] ?? '')),
            sets: $list('PERMISSIONS'),
            commands: $list('COMMANDS'),
        );
    }

    public function unrestricted(): bool
    {
        return in_array('*', $this->commands, true);
    }

    public function allows(string $command): bool
    {
        return $this->unrestricted() || in_array($command, $this->commands, true);
    }

    /**
     * @param  list<string>  $commands
     * @return list<string> those the key may not run, once each
     */
    public function missing(array $commands): array
    {
        return array_values(array_unique(array_filter($commands, fn (string $command) => ! $this->allows($command))));
    }
}
