<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The Doctor summary of a domain (`v-list-sys-doctor USER json`, E17
 * spec §3.2).
 *
 * @property array{user: string, domain: string, checked_at: ?string, score: ?int, fail: int, warn: int, failing: list<string>} $resource
 */
final class DoctorDomainResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $row = $this->resource;

        return [
            'user' => $row['user'],
            'domain' => $row['domain'],
            /** @var string|null server time; null before the first run */
            'checked_at' => $row['checked_at'] !== null ? str_replace(' ', 'T', $row['checked_at']) : null,
            /** @var int|null */
            'score' => $row['score'],
            /** @var int checks that fail */
            'fail' => $row['fail'],
            /** @var int checks that warn */
            'warn' => $row['warn'],
            /** @var list<string> ids of the checks that fail */
            'failing' => $row['failing'],
        ];
    }
}
