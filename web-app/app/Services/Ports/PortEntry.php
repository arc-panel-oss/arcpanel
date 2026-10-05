<?php

namespace App\Services\Ports;

/**
 * One entry of the port registry: a single port or a free range of a user
 * (`v-list-user-ports json`, `v-list-sys-ports json`, E12 spec §5.1).
 */
final class PortEntry
{
    public function __construct(
        public readonly string $user,
        public readonly int $start,
        public readonly int $end,
        public readonly string $proto,
        /** empty (free), manual, or <kind>:<id> of an app */
        public readonly string $app,
        public readonly bool $public,
        public readonly bool $publicOk,
        /** current id of the port's firewall rule, empty without one */
        public readonly string $rule,
        public readonly string $comment,
        public readonly bool $suspended,
        /** no, local (loopback) or any */
        public readonly string $listen,
        public readonly string $date,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function from(array $data, string $user = ''): self
    {
        $value = fn (string $key) => is_scalar($data[$key] ?? null) ? (string) $data[$key] : '';

        return new self(
            user: $value('USER') !== '' ? $value('USER') : $user,
            start: (int) $value('START'),
            end: (int) ($value('END') !== '' ? $value('END') : $value('START')),
            proto: $value('PROTO') !== '' ? $value('PROTO') : 'tcp',
            app: $value('APP'),
            public: $value('PUBLIC') === 'yes',
            publicOk: $value('PUBLIC_OK') === 'yes',
            rule: $value('FW_RULE'),
            comment: $value('COMMENT'),
            suspended: $value('SUSPENDED') === 'yes',
            listen: $value('LISTEN') !== '' ? $value('LISTEN') : 'no',
            date: $value('DATE'),
        );
    }

    /** 30001 or 30010-30019, as the commands take it */
    public function label(): string
    {
        return $this->start === $this->end ? (string) $this->start : $this->start.'-'.$this->end;
    }

    public function size(): int
    {
        return $this->end - $this->start + 1;
    }

    public function isRange(): bool
    {
        return $this->start !== $this->end;
    }

    public function assigned(): bool
    {
        return $this->app !== '';
    }

    /** Public in the registry, but closed while the user is suspended. */
    public function open(): bool
    {
        return $this->public && ! $this->suspended;
    }

    public function appLabel(): string
    {
        if ($this->app === '') {
            return '';
        }
        if ($this->app === 'manual') {
            return __('Reserved');
        }
        [$kind, $id] = array_pad(explode(':', $this->app, 2), 2, '');

        return match ($kind) {
            'node' => 'Node.js · '.$id,
            'docker' => 'Docker · '.$id,
            default => $this->app,
        };
    }
}
