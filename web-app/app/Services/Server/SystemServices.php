<?php

namespace App\Services\Server;

use App\Arc\ArcCommand;
use App\Arc\CommandResult;
use App\Arc\Options;
use App\Services\Support\BulkResult;
use App\Services\Support\BulkRunner;

/**
 * The services page (web/list/server, web/bulk/service, web/start|stop|restart
 * /service, web/restart/system), E7 batch 3 spec §7.
 */
final class SystemServices
{
    /** Status report kind (route) => command (web/list/server/?cpu …). */
    public const REPORTS = [
        'cpu' => 'v-list-sys-cpu-status',
        'mem' => 'v-list-sys-memory-status',
        'disk' => 'v-list-sys-disk-status',
        'net' => 'v-list-sys-network-status',
        'web' => 'v-list-sys-web-status',
        'dns' => 'v-list-sys-dns-status',
        'mail' => 'v-list-sys-mail-status',
        'db' => 'v-list-sys-db-status',
    ];

    public const ACTIONS = ['start', 'stop', 'restart'];

    /** @var array<string, array{name: string, system: string, running: bool, cpu: float, memory: int, uptime: int}>|null per-request cache */
    private ?array $services = null;

    public function __construct(
        private readonly ArcCommand $arc,
        private readonly BulkRunner $bulk,
    ) {}

    /** @return array{hostname: string, os: string, version: string, arch: string, uptime: int, load: string, release: string} */
    public function info(): array
    {
        $info = $this->arc->json('v-list-sys-info')['sysinfo'] ?? [];

        return [
            'hostname' => (string) ($info['HOSTNAME'] ?? ''),
            'os' => (string) ($info['OS'] ?? ''),
            'version' => (string) ($info['VERSION'] ?? ''),
            'arch' => (string) ($info['ARCH'] ?? ''),
            'uptime' => (int) ($info['UPTIME'] ?? 0),
            'load' => (string) ($info['LOADAVERAGE'] ?? ''),
            'release' => (string) ($info['RELEASE'] ?? ''),
        ];
    }

    /** @return list<string> installed PHP versions */
    public function phpVersions(): array
    {
        return array_values(array_map('strval', array_filter($this->arc->json('v-list-sys-php', [], Options::allowFailure()), 'is_string')));
    }

    /** @return array<string, array{name: string, system: string, running: bool, cpu: float, memory: int, uptime: int}> */
    public function all(): array
    {
        if ($this->services === null) {
            $this->services = [];
            foreach ($this->arc->json('v-list-sys-services') as $name => $row) {
                if (is_array($row)) {
                    $this->services[(string) $name] = [
                        'name' => (string) $name,
                        'system' => (string) ($row['SYSTEM'] ?? ''),
                        'running' => ($row['STATE'] ?? '') === 'running',
                        // tenths of a percent, as legacy shows them
                        'cpu' => round(((int) ($row['CPU'] ?? 0)) / 10, 1),
                        'memory' => (int) ($row['MEM'] ?? 0),
                        'uptime' => (int) ($row['RTIME'] ?? 0),
                    ];
                }
            }
        }

        return $this->services;
    }

    public function knows(string $service): bool
    {
        return isset($this->all()[$service]);
    }

    /** Plain text of a status report (`kind` from REPORTS). */
    public function report(string $kind): string
    {
        return $this->arc->run(self::REPORTS[$kind], [], new Options(30, true))->stdout;
    }

    /** Start, stop or restart one service. */
    public function run(string $action, string $service): CommandResult
    {
        [$command, $args] = self::call($action, $service);

        return $this->arc->run($command, $args, Options::allowFailure());
    }

    /** @param  list<string>  $services */
    public function bulk(string $action, array $services): BulkResult
    {
        return $this->bulk->run($services, function (string $service) use ($action) {
            [$command, $args] = self::call($action, $service);

            return [$service, $command, $args];
        });
    }

    /** `v-restart-system yes` */
    public function restartServer(): CommandResult
    {
        return $this->arc->run('v-restart-system', ['yes'], Options::allowFailure());
    }

    /**
     * The command of an action, as web/start|stop|restart/service run it: the
     * firewall is stopped with v-stop-firewall and (re)started with
     * v-update-firewall.
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function call(string $action, string $service): array
    {
        if ($service === 'iptables') {
            return [$action === 'stop' ? 'v-stop-firewall' : 'v-update-firewall', []];
        }

        return match ($action) {
            'start' => ['v-start-service', [$service]],
            'stop' => ['v-stop-service', [$service]],
            default => ['v-restart-service', [$service, 'yes']],
        };
    }
}
