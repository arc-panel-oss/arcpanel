<?php

namespace App\Services\Node;

/**
 * One installed Node.js runtime of `v-list-user-node-versions json`.
 */
final class NodeRuntime
{
    public function __construct(
        public readonly string $version,
        public readonly string $major,
        public readonly bool $default,
        public readonly string $lts,
        public readonly string $status,
        public readonly string $eol,
        public readonly int $sizeMb,
        public readonly int $packages,
    ) {}

    /** @param  array<string, mixed>  $row */
    public static function from(array $row): self
    {
        return new self(
            version: (string) ($row['VERSION'] ?? ''),
            major: (string) ($row['MAJOR'] ?? ''),
            default: ($row['DEFAULT'] ?? '') === 'yes',
            lts: (string) ($row['LTS'] ?? ''),
            status: (string) ($row['STATUS'] ?? ''),
            eol: (string) ($row['EOL'] ?? ''),
            sizeMb: (int) ($row['SIZE'] ?? 0),
            packages: (int) ($row['PACKAGES'] ?? 0),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'major' => $this->major,
            'default' => $this->default,
            'lts' => $this->lts,
            'status' => $this->status,
            'eol' => $this->eol,
            'sizeMb' => $this->sizeMb,
            'packages' => $this->packages,
        ];
    }
}
