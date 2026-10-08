<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The data points of a server graph for a period (`v-export-rrd`, E34
 * batch 6 spec D65): one value per series at each point, null where the
 * server recorded nothing.
 *
 * @property array{graph: array{service: string, title: string, type: string}, period: string, unit: string|null, start: int, end: int, step: int, series: list<string>, points: list<array{time: int, values: list<float|null>}>} $resource
 */
final class GraphDataResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** @var string */
            'id' => $this->resource['graph']['service'],
            /** @var string */
            'title' => $this->resource['graph']['title'],
            /** @var string `daily`, `weekly`, `monthly`, `yearly`, `biennially` or `triennially` */
            'period' => $this->resource['period'],
            /** @var string|null the unit of the values, for example `Connections` or `KBytes` */
            'unit' => $this->resource['unit'],
            /** @var int the first point, in seconds since the Unix epoch */
            'start' => $this->resource['start'],
            /** @var int the end of the period, in seconds since the Unix epoch */
            'end' => $this->resource['end'],
            /** @var int seconds between two points */
            'step' => $this->resource['step'],
            /** @var list<string> the names of the series, in the order of `values` */
            'series' => $this->resource['series'],
            /** @var list<array{time: int, values: list<float|null>}> `time` in seconds since the Unix epoch; one value per series */
            'points' => $this->resource['points'],
        ];
    }
}
