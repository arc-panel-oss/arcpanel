<?php

namespace App\Tenancy;

use App\Arc\ArcCommand;
use App\Auth\Role;

/**
 * Who owns whom (E11 spec §6): `v-list-user-owners json`, read at most once
 * per request. The owner tree is never cached across requests, so a moved or
 * deleted customer leaves a reseller's scope at once.
 */
final class TenancyDirectory
{
    /** @var array<string, array{role: Role, owner: string, suspended: bool}>|null */
    private ?array $owners = null;

    public function __construct(private readonly ArcCommand $arc) {}

    /** @return array<string, array{role: Role, owner: string, suspended: bool}> user => entry */
    public function all(): array
    {
        if ($this->owners !== null) {
            return $this->owners;
        }
        $owners = [];
        foreach ($this->arc->json('v-list-user-owners') as $user => $row) {
            if (! is_string($user) || ! is_array($row)) {
                continue;
            }
            $owners[$user] = [
                'role' => Role::fromValue((string) ($row['ROLE'] ?? '')),
                'owner' => (string) ($row['OWNER'] ?? ''),
                'suspended' => ($row['SUSPENDED'] ?? '') === 'yes',
            ];
        }
        ksort($owners);

        return $this->owners = $owners;
    }

    public function exists(string $user): bool
    {
        return isset($this->all()[$user]);
    }

    public function roleOf(string $user): ?Role
    {
        return $this->all()[$user]['role'] ?? null;
    }

    /** Resolved owner: a reseller or ROOT_USER; empty for administrators and unknown users. */
    public function ownerOf(string $user): string
    {
        return $this->all()[$user]['owner'] ?? '';
    }

    public function isReseller(string $user): bool
    {
        return $this->roleOf($user) === Role::Reseller;
    }

    /** @return list<string> users with the user role owned by $reseller */
    public function customersOf(string $reseller): array
    {
        if (! $this->isReseller($reseller)) {
            return [];
        }
        $customers = [];
        foreach ($this->all() as $user => $entry) {
            if ($entry['role'] === Role::User && $entry['owner'] === $reseller) {
                $customers[] = $user;
            }
        }

        return $customers;
    }

    public function isCustomerOf(string $user, string $reseller): bool
    {
        return in_array($user, $this->customersOf($reseller), true);
    }

    /** @return list<string> */
    public function resellers(): array
    {
        return array_keys(array_filter($this->all(), fn (array $entry) => $entry['role'] === Role::Reseller));
    }

    public function forget(): void
    {
        $this->owners = null;
    }
}
