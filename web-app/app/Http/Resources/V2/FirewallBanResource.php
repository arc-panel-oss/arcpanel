<?php

namespace App\Http\Resources\V2;

use App\Services\Firewall\FirewallBan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A banned address of the firewall extension (`v-list-firewall-ban json`).
 *
 * @property FirewallBan $resource
 */
final class FirewallBanResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $ban = $this->resource;

        return [
            /** @var string an address or a network; URL-encode it in paths */
            'ip' => $ban->ip,
            /** @var 'SSH'|'WEB'|'FTP'|'DNS'|'MAIL'|'DB'|'ARCPANEL' */
            'chain' => $ban->chain,
            /** @var string|null when the address was banned, in the server's time zone */
            'created_at' => UserResource::localTime($ban->date, $ban->time),
        ];
    }
}
