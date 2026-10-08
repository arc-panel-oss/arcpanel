<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A status report of the services page (`v-list-sys-cpu-status` …): the
 * command's plain text.
 *
 * @property array{kind: string, text: string} $resource
 */
final class ServiceStatusResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** @var 'cpu'|'mem'|'disk'|'net'|'web'|'dns'|'mail'|'db' */
            'kind' => $this->resource['kind'],
            /** @var string the report as the command prints it */
            'text' => $this->resource['text'],
        ];
    }
}
