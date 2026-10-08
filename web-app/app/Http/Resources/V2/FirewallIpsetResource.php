<?php

namespace App\Http\Resources\V2;

use App\Services\Firewall\IpsetList;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An IPset list of the firewall (`v-list-firewall-ipset json`); a rule uses
 * it as `ipset:NAME`.
 *
 * @property IpsetList $resource
 */
final class FirewallIpsetResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $list = $this->resource;

        return [
            /** @var string */
            'name' => $list->name,
            /** @var string a URL, `script:PATH` or a file */
            'source' => $list->source,
            /** @var 'v4'|'v6' */
            'ip_version' => $list->ipVersion,
            /** @var bool */
            'auto_update' => $list->autoUpdate,
            /** @var bool */
            'suspended' => $list->suspended,
            /** @var string|null in the server's time zone, YYYY-MM-DDTHH:MM:SS */
            'created_at' => UserResource::localTime($list->date, $list->time),
        ];
    }
}
