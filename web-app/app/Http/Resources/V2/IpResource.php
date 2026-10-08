<?php

namespace App\Http\Resources\V2;

use App\Services\Ips\SystemIp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An IP address of the server (`v-list-sys-ip IP json`).
 *
 * @property SystemIp $resource
 */
final class IpResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $ip = $this->resource;

        return [
            /** @var string the address; URL-encode it in paths where needed */
            'ip' => $ip->ip,
            /** @var string */
            'netmask' => $ip->netmask,
            /** @var string the network interface */
            'interface' => $ip->interface,
            /** @var bool shared by every account, or dedicated to `owner` */
            'shared' => $ip->shared(),
            /** @var string */
            'owner' => $ip->owner,
            /** @var string|null the assigned domain */
            'name' => $ip->name !== '' ? $ip->name : null,
            /** @var string|null the public address in front of this one */
            'nat' => $ip->nat !== '' ? $ip->nat : null,
            /** @var int web domains on the address */
            'web_domains' => (int) $ip->domains,
            /** @var list<string> accounts that use the address */
            'users' => $ip->users,
            /** @var string|null creation time in the server's time zone, YYYY-MM-DDTHH:MM:SS */
            'created_at' => UserResource::localTime($ip->date, $ip->time),
        ];
    }
}
