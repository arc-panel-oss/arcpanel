<?php

namespace App\Http\Resources\V2;

use App\Services\Ports\PortFinding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A reconciliation finding of the port registry (E12 spec §5.4). */
final class PortFindingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var PortFinding $finding */
        $finding = $this->resource;

        return [
            /** `orphan-socket`, `foreign-socket`, `exposed-private`, `missing-rule`, `stale-rule`, `unmanaged-rule`, `orphan-file`, `over-limit` or `orphan-web` */
            'type' => $finding->type,
            'title' => $finding->title(),
            'user' => $finding->user !== '' ? $finding->user : null,
            'port' => $finding->port !== '' ? $finding->port : null,
            'proto' => $finding->proto !== '' ? $finding->proto : null,
            'rule' => $finding->rule !== '' ? $finding->rule : null,
            'detail' => $finding->detail,
        ];
    }
}
