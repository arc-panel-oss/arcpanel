<?php

namespace App\Http\Resources\V2;

use App\Services\Firewall\FirewallStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Whether the loaded firewall is the one the panel loaded last
 * (`v-check-firewall json`, E40 D13).
 *
 * @property FirewallStatus $resource
 */
final class FirewallStatusResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $status = $this->resource;

        return [
            /** @var string FIREWALL_SYSTEM: `nftables`, or `iptables` on a server whose upgrade could not move its rules */
            'engine' => $status->engine,
            /** @var 'ok'|'drift'|'missing'|'off' `drift`: the table changed outside the panel; `missing`: no table is loaded */
            'status' => $status->status,
            /** @var string UTC, YYYY-MM-DDTHH:MM:SSZ */
            'checked_at' => $status->checkedAt,
        ];
    }
}
