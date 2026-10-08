<?php

namespace App\Services\Server;

use App\Arc\ArcCommand;
use App\Arc\Options;

/**
 * Server graphs of `v-list-sys-rrd` (web/list/rrd): the service name that
 * `v-export-rrd` takes is RRD, with "net_" in front for network graphs.
 */
final class RrdService
{
    public const PERIODS = ['daily', 'weekly', 'monthly', 'yearly', 'biennially', 'triennially'];

    /** The unit of a graph's values, by service (web/list/rrd/ajax.php). */
    private const UNITS = [
        'la' => 'Points',
        'mem' => 'Mbytes',
        'apache2' => 'Connections',
        'nginx' => 'Connections',
        'mail' => 'Queue Size',
        'ftp' => 'Connections',
        'ssh' => 'Connections',
    ];

    /** @var list<array{service: string, title: string, type: string}>|null per-request cache */
    private ?array $graphs = null;

    public function __construct(private readonly ArcCommand $arc) {}

    /** @return list<array{service: string, title: string, type: string}> */
    public function graphs(): array
    {
        if ($this->graphs === null) {
            $graphs = [];
            foreach ($this->arc->json('v-list-sys-rrd', [], Options::allowFailure()) as $row) {
                if (is_array($row) && ($row['RRD'] ?? '') !== '') {
                    $rrd = (string) $row['RRD'];
                    $graphs[] = [
                        'service' => ($row['TYPE'] ?? '') === 'net' ? 'net_'.$rrd : $rrd,
                        'title' => (string) ($row['TITLE'] ?? $rrd),
                        'type' => (string) ($row['TYPE'] ?? ''),
                    ];
                }
            }
            $this->graphs = $graphs;
        }

        return $this->graphs;
    }

    /** Whether `v-export-rrd` may be asked for this service (spec §12.2). */
    public function knows(string $service): bool
    {
        return in_array($service, array_column($this->graphs(), 'service'), true);
    }

    /** @return array{service: string, title: string, type: string}|null */
    public function find(string $service): ?array
    {
        foreach ($this->graphs() as $graph) {
            if ($graph['service'] === $service) {
                return $graph;
            }
        }

        return null;
    }

    /** The unit of a graph's values, or null when the panel does not know it. */
    public static function unit(string $service): ?string
    {
        return self::UNITS[$service] ?? match (true) {
            str_starts_with($service, 'net_') => 'KBytes',
            str_starts_with($service, 'pgsql_'), str_starts_with($service, 'mysql_') => 'Queries',
            default => null,
        };
    }

    /**
     * The series of one graph for a period (`v-export-rrd SERVICE PERIOD`,
     * rrdtool's JSON export), or null when the command has no data for it.
     *
     * @return array{start: int, end: int, step: int, series: list<string>, points: list<array{time: int, values: list<float|null>}>}|null
     */
    public function export(string $service, string $period): ?array
    {
        $result = $this->arc->run('v-export-rrd', [$service, $period, 'json'], Options::allowFailure());
        $data = $result->ok() ? json_decode($result->stdout, true) : null;
        if (! is_array($data) || ! is_array($data['meta'] ?? null)) {
            return null;
        }
        $meta = $data['meta'];
        $start = (int) ($meta['start'] ?? 0);
        $step = max(1, (int) ($meta['step'] ?? 1));
        $points = [];
        foreach (array_values(is_array($data['data'] ?? null) ? $data['data'] : []) as $i => $row) {
            $points[] = [
                'time' => $start + $i * $step,
                'values' => array_map(fn (mixed $value) => is_numeric($value) ? (float) $value : null, array_values((array) $row)),
            ];
        }

        return [
            'start' => $start,
            'end' => (int) ($meta['end'] ?? ($start + count($points) * $step)),
            'step' => $step,
            'series' => array_values(array_map('strval', (array) ($meta['legend'] ?? []))),
            'points' => $points,
        ];
    }
}
