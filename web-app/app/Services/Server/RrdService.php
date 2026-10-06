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

    /** @var list<array{service: string, title: string}>|null per-request cache */
    private ?array $graphs = null;

    public function __construct(private readonly ArcCommand $arc) {}

    /** @return list<array{service: string, title: string}> */
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
}
