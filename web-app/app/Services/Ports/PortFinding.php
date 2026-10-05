<?php

namespace App\Services\Ports;

/**
 * A reconciliation finding of `v-list-sys-ports` / `v-check-sys-ports`
 * (E12 spec §5.4; orphan-web from E6 spec §5.4).
 */
final class PortFinding
{
    public const TYPES = ['orphan-socket', 'foreign-socket', 'exposed-private', 'missing-rule', 'stale-rule', 'unmanaged-rule', 'orphan-file', 'over-limit', 'orphan-web'];

    public function __construct(
        public readonly string $type,
        public readonly string $user,
        public readonly string $port,
        public readonly string $proto,
        public readonly string $rule,
        public readonly string $detail,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function from(array $data): self
    {
        $value = fn (string $key) => is_scalar($data[$key] ?? null) ? (string) $data[$key] : '';

        return new self($value('TYPE'), $value('USER'), $value('PORT'), $value('PROTO'), $value('RULE'), $value('DETAIL'));
    }

    public function title(): string
    {
        return match ($this->type) {
            'orphan-socket' => __('Listening without an owner'),
            'foreign-socket' => __('Used by another account'),
            'exposed-private' => __('Private port listens on all addresses'),
            'missing-rule' => __('Public port without a firewall rule'),
            'stale-rule' => __('Firewall rule without a public port'),
            'unmanaged-rule' => __('Firewall rule not managed by the registry'),
            'orphan-file' => __('Ports of a deleted user'),
            'over-limit' => __('Above the PORTS of the package'),
            'orphan-web' => __('Port no longer used by its web domain'),
            default => $this->type,
        };
    }

    /** danger for exposure, warn for drift, info otherwise */
    public function tone(): string
    {
        return match ($this->type) {
            'exposed-private', 'stale-rule' => 'danger',
            'missing-rule', 'unmanaged-rule', 'orphan-socket', 'over-limit' => 'warn',
            default => 'info',
        };
    }
}
