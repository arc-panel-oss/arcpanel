<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The last lines of a service's journal (`v-list-sys-service-log`, E43 B5).
 *
 * @property array{service: string, lines: list<string>} $resource
 */
final class ServiceLogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** @var string the service, as listServices names it */
            'service' => $this->resource['service'],
            /** @var list<string> journal lines in `short-iso` form, the newest last */
            'lines' => $this->resource['lines'],
        ];
    }
}
