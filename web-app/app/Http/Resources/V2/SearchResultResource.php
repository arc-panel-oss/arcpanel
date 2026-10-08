<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An object found by `GET /search` (E34 batch 6 §4.3) with the path of its
 * API v2 resource.
 *
 * @property array{name: string, owner: string, parent: ?string, path: string} $resource
 */
final class SearchResultResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** @var string what matched: a domain, `record.domain`, `account@domain`, a database, a cron command or a user name */
            'name' => $this->resource['name'],
            /** @var string the user that owns the object (the user itself for a user) */
            'owner' => $this->resource['owner'],
            /** @var string|null the zone of a DNS record, the domain of a mail account */
            'parent' => $this->resource['parent'],
            /** @var string the object's path under /api/v2 */
            'path' => $this->resource['path'],
        ];
    }
}
