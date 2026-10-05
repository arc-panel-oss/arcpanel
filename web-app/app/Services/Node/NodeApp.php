<?php

namespace App\Services\Node;

/**
 * One Node.js app of `v-list-node-apps USER json` or `v-list-node-app USER
 * DOMAIN json` (E14 spec §5.4): the record merged with the live pm2 state.
 */
final class NodeApp
{
    public function __construct(
        public readonly string $domain,
        public readonly string $name,
        public readonly string $start,
        public readonly string $cwd,
        public readonly string $nodeVersion,
        public readonly string $runtime,
        public readonly string $port,
        public readonly int $instances,
        public readonly int $maxMemory,
        public readonly string $template,
        public readonly string $state,
        public readonly string $suspended,
        public readonly string $status,
        public readonly string $reason,
        public readonly string $pid,
        public readonly float $cpu,
        public readonly int $memoryMb,
        public readonly int $restarts,
        public readonly int $uptime,
        public readonly string $url,
        public readonly bool $ssl,
        public readonly string $created,
    ) {}

    /** @param  array<string, mixed>  $row */
    public static function from(array $row): self
    {
        $value = fn (string $key) => is_scalar($row[$key] ?? null) ? (string) $row[$key] : '';
        $domain = $value('DOMAIN');

        return new self(
            domain: $domain,
            name: $value('NAME'),
            start: $value('START'),
            cwd: $value('CWD'),
            nodeVersion: $value('NODE_VERSION'),
            runtime: $value('RUNTIME'),
            port: $value('PORT'),
            instances: max(1, (int) $value('INSTANCES')),
            maxMemory: (int) $value('MAX_MEMORY'),
            template: $value('TEMPLATE'),
            state: $value('STATE') !== '' ? $value('STATE') : 'running',
            suspended: $value('SUSPENDED') !== '' ? $value('SUSPENDED') : 'no',
            status: $value('STATUS') !== '' ? $value('STATUS') : 'missing',
            reason: $value('REASON'),
            pid: $value('PID'),
            cpu: (float) $value('CPU'),
            memoryMb: (int) $value('MEMORY'),
            restarts: (int) $value('RESTARTS'),
            uptime: (int) $value('UPTIME'),
            url: $value('URL') !== '' ? $value('URL') : 'http://'.$domain.'/',
            ssl: $value('SSL') === 'yes',
            created: trim($value('DATE').' '.$value('TIME')),
        );
    }

    public function online(): bool
    {
        return $this->status === 'online' || $this->status === 'launching';
    }

    public function isSuspended(): bool
    {
        return $this->suspended !== 'no';
    }

    /** Start command as the wizard and the settings show it: the script of npm:<script> */
    public function script(): ?string
    {
        return str_starts_with($this->start, 'npm:') ? substr($this->start, 4) : null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'domain' => $this->domain,
            'name' => $this->name,
            'start' => $this->start,
            'script' => $this->script(),
            'cwd' => $this->cwd,
            'nodeVersion' => $this->nodeVersion,
            'runtime' => $this->runtime,
            'port' => $this->port,
            'instances' => $this->instances,
            'maxMemory' => $this->maxMemory,
            'template' => $this->template,
            'state' => $this->state,
            'suspended' => $this->isSuspended(),
            'status' => $this->status,
            'online' => $this->online(),
            'reason' => $this->reason,
            'pid' => $this->pid,
            'cpu' => $this->cpu,
            'memoryMb' => $this->memoryMb,
            'restarts' => $this->restarts,
            'uptime' => $this->uptime,
            'url' => $this->url,
            'ssl' => $this->ssl,
            'created' => $this->created,
        ];
    }
}
