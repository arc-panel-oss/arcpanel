<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A service of the server (`v-list-sys-services json`), as the services
 * page shows it.
 *
 * @property array{name: string, system: string, running: bool, cpu: float, memory: int, uptime: int} $resource
 */
final class ServiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** @var string the name the start, stop and restart paths take */
            'name' => $this->resource['name'],
            /** @var string what the service is, for example `web server` */
            'system' => $this->resource['system'],
            /** @var bool */
            'running' => $this->resource['running'],
            /** @var float CPU usage in percent */
            'cpu' => $this->resource['cpu'],
            /** @var int memory in MB */
            'memory_mb' => $this->resource['memory'],
            /** @var int minutes since the service started */
            'uptime_minutes' => $this->resource['uptime'],
        ];
    }
}
