<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public DNSSEC key of a zone (`v-list-dnssec-public-key`).
 *
 * @property array{domain: string, record: string, key_tag: string, flag: string, algorithm: string, key: string, ds: string} $resource
 */
final class DnsZoneDnssecResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $key = $this->resource;
        $number = fn (string $value): int|string => ctype_digit($value) ? (int) $value : $value;

        return [
            'domain' => $key['domain'],
            'record' => $key['record'],
            'key_tag' => $number($key['key_tag']),
            'flag' => $number($key['flag']),
            'algorithm' => $number($key['algorithm']),
            'key' => $key['key'],
            'ds' => $key['ds'],
        ];
    }
}
