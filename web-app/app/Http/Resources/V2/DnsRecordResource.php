<?php

namespace App\Http\Resources\V2;

use App\Services\Dns\DnsRecordRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One record of a DNS zone. `priority` is null for types without one;
 * `ttl` is null when the record uses the zone's TTL.
 *
 * @property DnsRecordRecord $resource
 */
final class DnsRecordResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $record = $this->resource;

        return [
            'id' => ctype_digit($record->id) ? (int) $record->id : $record->id,
            'name' => $record->record,
            'type' => $record->type,
            'value' => $record->value,
            'priority' => $record->hasPriority() && $record->priority !== '' ? (int) $record->priority : null,
            'ttl' => $record->ttl === '' ? null : (int) $record->ttl,
            'suspended' => $record->suspended,
            'created_at' => UserResource::localTime($record->date, $record->time),
        ];
    }
}
