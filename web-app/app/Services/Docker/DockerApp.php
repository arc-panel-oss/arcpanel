<?php

namespace App\Services\Docker;

/**
 * One Docker app of `v-list-docker-apps USER json` or `v-list-docker-app USER
 * NAME json` (E4 spec §5.5): the record merged with the Docker state. The
 * detail fields (services, ports, catalog entry, post-install note, last
 * task) come with v-list-docker-app only.
 */
final class DockerApp
{
    /**
     * @param  list<array{service: string, image: string, state: string, health: string, status: string}>  $services
     * @param  list<array{name: string, port: string, container: string, public: bool, description: string}>  $ports
     * @param  array<string, mixed>  $catalog
     * @param  array<string, string>  $task
     */
    public function __construct(
        public readonly string $name,
        public readonly string $slug,
        public readonly string $appName,
        public readonly string $version,
        public readonly string $latest,
        public readonly bool $update,
        public readonly string $domain,
        public readonly string $url,
        public readonly bool $ssl,
        public readonly string $webPort,
        public readonly string $extraPorts,
        public readonly string $state,
        public readonly string $status,
        public readonly string $reason,
        public readonly string $suspended,
        public readonly string $prevVersion,
        public readonly string $cpus,
        public readonly int $memory,
        public readonly float $cpu,
        public readonly int $memoryMb,
        public readonly int $containers,
        public readonly int $running,
        public readonly string $created,
        public readonly array $services = [],
        public readonly array $ports = [],
        public readonly array $catalog = [],
        public readonly string $postInstall = '',
        public readonly array $task = [],
        public readonly bool $rollback = false,
        public readonly string $lastDump = '',
    ) {}

    /** @param  array<string, mixed>  $row */
    public static function from(array $row): self
    {
        $value = fn (string $key) => is_scalar($row[$key] ?? null) ? (string) $row[$key] : '';
        $services = [];
        foreach ((array) ($row['SERVICES'] ?? []) as $service) {
            if (is_array($service)) {
                $services[] = [
                    'service' => (string) ($service['SERVICE'] ?? ''),
                    'image' => (string) ($service['IMAGE'] ?? ''),
                    'state' => (string) ($service['STATE'] ?? ''),
                    'health' => (string) ($service['HEALTH'] ?? ''),
                    'status' => (string) ($service['STATUS'] ?? ''),
                ];
            }
        }
        $ports = [];
        foreach ((array) ($row['PORTS'] ?? []) as $port) {
            if (is_array($port)) {
                $ports[] = [
                    'name' => (string) ($port['NAME'] ?? ''),
                    'port' => (string) ($port['PORT'] ?? ''),
                    'container' => (string) ($port['CONTAINER'] ?? ''),
                    'public' => ($port['PUBLIC'] ?? '') === 'yes',
                    'description' => (string) ($port['DESCRIPTION'] ?? ''),
                ];
            }
        }
        $task = [];
        foreach ((array) ($row['TASK'] ?? []) as $key => $item) {
            if (is_string($key) && is_scalar($item)) {
                $task[strtolower($key)] = (string) $item;
            }
        }

        return new self(
            name: $value('NAME'),
            slug: $value('SLUG'),
            appName: $value('APP_NAME') !== '' ? $value('APP_NAME') : $value('SLUG'),
            version: $value('VERSION'),
            latest: $value('LATEST'),
            update: $value('UPDATE') === 'yes',
            domain: $value('DOMAIN'),
            url: $value('URL'),
            ssl: $value('SSL') === 'yes',
            webPort: $value('WEB_PORT'),
            extraPorts: $value('EXTRA_PORTS'),
            state: $value('STATE') !== '' ? $value('STATE') : 'running',
            status: $value('STATUS') !== '' ? $value('STATUS') : 'missing',
            reason: $value('REASON'),
            suspended: $value('SUSPENDED') !== '' ? $value('SUSPENDED') : 'no',
            prevVersion: $value('PREV_VERSION'),
            cpus: $value('CPUS'),
            memory: (int) $value('MEMORY'),
            cpu: (float) $value('CPU'),
            memoryMb: (int) $value('MEM'),
            containers: (int) $value('CONTAINERS'),
            running: (int) $value('RUNNING'),
            created: trim($value('DATE').' '.$value('TIME')),
            services: $services,
            ports: $ports,
            catalog: is_array($row['CATALOG'] ?? null) ? $row['CATALOG'] : [],
            postInstall: $value('POST_INSTALL'),
            task: $task,
            rollback: $value('ROLLBACK') === 'yes',
            lastDump: $value('LAST_DUMP'),
        );
    }

    public function isSuspended(): bool
    {
        return $this->suspended !== 'no';
    }

    public function busy(): bool
    {
        return $this->status === 'installing' || $this->status === 'updating';
    }

    public function running(): bool
    {
        return $this->status === 'running';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'appName' => $this->appName,
            'version' => $this->version,
            'latest' => $this->latest,
            'update' => $this->update,
            'domain' => $this->domain,
            'url' => $this->url,
            'ssl' => $this->ssl,
            'webPort' => $this->webPort,
            'extraPorts' => $this->extraPorts,
            'state' => $this->state,
            'status' => $this->status,
            'reason' => $this->reason,
            'suspended' => $this->isSuspended(),
            'busy' => $this->busy(),
            'prevVersion' => $this->prevVersion,
            'cpus' => $this->cpus,
            'memory' => $this->memory,
            'cpu' => $this->cpu,
            'memoryMb' => $this->memoryMb,
            'containers' => $this->containers,
            'runningContainers' => $this->running,
            'created' => $this->created,
            'services' => $this->services,
            'ports' => $this->ports,
            'catalog' => $this->catalog,
            'postInstall' => $this->postInstall,
            'task' => $this->task,
            'rollback' => $this->rollback,
            'lastDump' => $this->lastDump,
        ];
    }
}
