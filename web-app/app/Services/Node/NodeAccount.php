<?php

namespace App\Services\Node;

/**
 * Node.js of one user (`v-list-user-node-versions USER json`, E13 spec
 * §5.4): the package settings, whether nvm is installed and the runtimes.
 */
final class NodeAccount
{
    /** @param  list<NodeRuntime>  $runtimes */
    public function __construct(
        public readonly string $user,
        public readonly string $package,
        public readonly bool $allowed,
        public readonly string $allowedMajors,
        public readonly string $limit,
        public readonly bool $enabled,
        public readonly bool $suspended,
        public readonly string $default,
        public readonly string $arch,
        public readonly array $runtimes,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function from(array $data): self
    {
        $runtimes = [];
        foreach ((array) ($data['VERSIONS'] ?? []) as $row) {
            if (is_array($row)) {
                $runtimes[] = NodeRuntime::from($row);
            }
        }

        return new self(
            user: (string) ($data['USER'] ?? ''),
            package: (string) ($data['PACKAGE'] ?? ''),
            allowed: ($data['NODE'] ?? '') === 'yes',
            allowedMajors: (string) ($data['ALLOWED'] ?? 'lts'),
            limit: (string) ($data['LIMIT'] ?? '0'),
            enabled: ($data['ENABLED'] ?? '') === 'yes',
            suspended: ($data['SUSPENDED'] ?? '') === 'yes',
            default: (string) ($data['DEFAULT'] ?? ''),
            arch: (string) ($data['ARCH'] ?? ''),
            runtimes: $runtimes,
        );
    }

    public function unlimited(): bool
    {
        return $this->limit === 'unlimited';
    }

    /** Whether another runtime fits in NODE_VERSIONS. */
    public function hasRoom(): bool
    {
        return $this->unlimited() || count($this->runtimes) < (int) $this->limit;
    }

    public function has(string $version): bool
    {
        foreach ($this->runtimes as $runtime) {
            if ($runtime->version === $version) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'user' => $this->user,
            'package' => $this->package,
            'allowed' => $this->allowed,
            'allowedMajors' => $this->allowedMajors,
            'limit' => $this->limit,
            'enabled' => $this->enabled,
            'suspended' => $this->suspended,
            'default' => $this->default,
            'arch' => $this->arch,
            'hasRoom' => $this->hasRoom(),
            'runtimes' => array_map(fn (NodeRuntime $r) => $r->toArray(), $this->runtimes),
        ];
    }
}
