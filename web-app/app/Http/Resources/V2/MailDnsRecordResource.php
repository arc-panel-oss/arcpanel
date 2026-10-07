<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One DNS record a mail domain needs (`MailService::dnsHints`).
 *
 * @property array{record: string, type: string, priority: string, ttl: string, value: string} $resource
 */
final class MailDnsRecordResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $priority = $this->resource['priority'];

        return [
            /** @var string */
            'name' => $this->resource['record'],
            /** @var string */
            'type' => $this->resource['type'],
            /** @var string */
            'value' => $this->resource['value'],
            /** @var int|null null for a record type without a priority */
            'priority' => ctype_digit($priority) ? (int) $priority : null,
        ];
    }
}
