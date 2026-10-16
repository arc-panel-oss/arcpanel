<?php

namespace App\Services\AppProcesses;

/**
 * The app processes of a user with the package limits
 * (`v-list-app-processes USER json`, E42 spec §6.1).
 */
final class AppProcesses
{
    /** @param  list<AppProcess>  $processes */
    public function __construct(
        public readonly string $user,
        public readonly bool $allowed,
        public readonly string $limit,
        public readonly int $maxMemory,
        public readonly int $defaultMemory,
        public readonly string $maxCpus,
        public readonly array $processes,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function from(array $data): self
    {
        $processes = [];
        foreach ((array) ($data['PROCESSES'] ?? []) as $row) {
            if (is_array($row)) {
                $processes[] = AppProcess::from($row);
            }
        }

        return new self(
            user: (string) ($data['USER'] ?? ''),
            allowed: ($data['ENABLED'] ?? '') === 'yes',
            limit: (string) ($data['LIMIT'] ?? '0'),
            maxMemory: (int) ($data['MAX_MEMORY'] ?? 0),
            defaultMemory: (int) ($data['DEFAULT_MEMORY'] ?? 0),
            maxCpus: (string) ($data['MAX_CPUS'] ?? '1'),
            processes: $processes,
        );
    }

    /** Whether another process fits in APP_PROCESSES_MAX. */
    public function hasRoom(): bool
    {
        return $this->limit === 'unlimited' || count($this->processes) < (int) $this->limit;
    }

    public function find(string $domain, string $name): ?AppProcess
    {
        foreach ($this->processes as $process) {
            if ($process->domain === $domain && $process->name === $name) {
                return $process;
            }
        }

        return null;
    }

    /** @return list<AppProcess> */
    public function ofDomain(string $domain): array
    {
        return array_values(array_filter($this->processes, fn (AppProcess $process) => $process->domain === $domain));
    }

    public function hasWeb(string $domain): bool
    {
        foreach ($this->ofDomain($domain) as $process) {
            if ($process->isWeb()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Processes per state for the list header and the Dashboard card.
     *
     * @return array{running: int, stopped: int, failed: int, suspended: int}
     */
    public function totals(): array
    {
        $totals = ['running' => 0, 'stopped' => 0, 'failed' => 0, 'suspended' => 0];
        foreach ($this->processes as $process) {
            $key = match (true) {
                $process->suspended || $process->status === 'suspended' => 'suspended',
                $process->running() => 'running',
                $process->status === 'failed' => 'failed',
                default => 'stopped',
            };
            $totals[$key]++;
        }

        return $totals;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'user' => $this->user,
            'allowed' => $this->allowed,
            'limit' => $this->limit,
            'count' => count($this->processes),
            'hasRoom' => $this->hasRoom(),
            'maxMemory' => $this->maxMemory,
            'defaultMemory' => $this->defaultMemory,
            'maxCpus' => $this->maxCpus,
            'totals' => $this->totals(),
            'processes' => array_map(fn (AppProcess $process) => $process->toArray(), $this->processes),
        ];
    }
}
