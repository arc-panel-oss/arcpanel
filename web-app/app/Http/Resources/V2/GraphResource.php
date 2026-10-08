<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A server graph (`v-list-sys-rrd json`, E34 batch 6 spec D65).
 *
 * @property array{service: string, title: string, type: string} $resource
 */
final class GraphResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** @var string the id getGraph takes, for example `la`, `mem` or `net_eth0` */
            'id' => $this->resource['service'],
            /** @var string */
            'title' => $this->resource['title'],
            /** @var string the kind of graph, for example `la`, `mem`, `net`, `web` or `db` */
            'type' => $this->resource['type'],
        ];
    }
}
