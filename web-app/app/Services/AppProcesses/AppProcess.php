<?php

namespace App\Services\AppProcesses;

/**
 * One app process of `v-list-app-processes USER json` or `v-list-app-process
 * USER DOMAIN NAME json` (E42 spec §6.1): the record merged with the live
 * systemd state.
 */
final class AppProcess
{
    public function __construct(
        public readonly string $id,
        public readonly string $domain,
        public readonly string $name,
        public readonly string $kind,
        public readonly string $preset,
        public readonly string $start,
        public readonly string $cwd,
        public readonly string $port,
        public readonly int $memory,
        public readonly string $cpus,
        public readonly string $template,
        public readonly string $state,
        public readonly bool $suspended,
        public readonly string $status,
        public readonly string $pid,
        public readonly int $memoryUsed,
        public readonly int $cpuSeconds,
        public readonly int $restarts,
        public readonly int $uptime,
        public readonly string $reload,
        public readonly string $runtimeMissing,
        public readonly string $created,
    ) {}

    /** @param  array<string, mixed>  $row */
    public static function from(array $row): self
    {
        $value = fn (string $key) => is_scalar($row[$key] ?? null) ? (string) $row[$key] : '';

        return new self(
            id: $value('ID'),
            domain: $value('DOMAIN'),
            name: $value('NAME'),
            kind: $value('KIND') === 'web' ? 'web' : 'worker',
            preset: $value('PRESET'),
            start: $value('START'),
            cwd: $value('CWD'),
            port: $value('PORT'),
            memory: (int) $value('MEMORY'),
            cpus: $value('CPUS') !== '' ? $value('CPUS') : '1',
            template: $value('TEMPLATE'),
            state: $value('STATE') === 'stopped' ? 'stopped' : 'running',
            suspended: $value('SUSPENDED') === 'yes',
            status: $value('STATUS') !== '' ? $value('STATUS') : 'missing',
            pid: $value('PID'),
            memoryUsed: (int) $value('MEMORY_USED'),
            cpuSeconds: (int) $value('CPU_SECONDS'),
            restarts: (int) $value('RESTARTS'),
            uptime: (int) $value('UPTIME'),
            reload: $value('RELOAD'),
            runtimeMissing: $value('RUNTIME_MISSING'),
            created: trim($value('DATE').' '.$value('TIME')),
        );
    }

    public function running(): bool
    {
        return $this->status === 'running' || $this->status === 'starting';
    }

    public function isWeb(): bool
    {
        return $this->kind === 'web';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'domain' => $this->domain,
            'name' => $this->name,
            'kind' => $this->kind,
            'preset' => $this->preset,
            'start' => $this->start,
            'cwd' => $this->cwd,
            'port' => $this->port,
            'memory' => $this->memory,
            'cpus' => $this->cpus,
            'template' => $this->template,
            'state' => $this->state,
            'suspended' => $this->suspended,
            'status' => $this->status,
            'running' => $this->running(),
            'pid' => $this->pid,
            'memoryUsed' => $this->memoryUsed,
            'cpuSeconds' => $this->cpuSeconds,
            'restarts' => $this->restarts,
            'uptime' => $this->uptime,
            'reload' => $this->reload,
            'runtimeMissing' => $this->runtimeMissing,
            'created' => $this->created,
        ];
    }
}
