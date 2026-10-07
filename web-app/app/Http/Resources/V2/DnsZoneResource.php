<?php

namespace App\Http\Resources\V2;

use App\Services\Dns\DnsService;
use App\Services\Dns\DnsZoneRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A DNS zone (`v-list-dns-domain USER DOMAIN json`). `dnssec` is null when
 * the server has no DNSSEC.
 *
 * @property DnsZoneRecord $resource
 */
final class DnsZoneResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $zone = $this->resource;

        return [
            'domain' => $zone->domain,
            'user' => $zone->user,
            'ip' => $zone->ip,
            'template' => $zone->template,
            'ttl' => (int) $zone->ttl,
            'expiry' => $zone->exp,
            'soa' => $zone->soa,
            'serial' => $zone->serial === '' ? null : (int) $zone->serial,
            'dnssec' => app(DnsService::class)->dnssecSupported() ? $zone->dnssec : null,
            'records' => $zone->records,
            'suspended' => $zone->suspended,
            'created_at' => UserResource::localTime($zone->date, $zone->time),
        ];
    }
}
