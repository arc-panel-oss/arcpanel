<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The results of one object type of `GET /search`, at most 50.
 *
 * @property array{type: string, items: list<array{name: string, owner: string, parent: ?string, path: string}>} $resource
 */
final class SearchGroupResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** @var 'users'|'web_domains'|'dns_zones'|'dns_records'|'mail_domains'|'mail_accounts'|'databases'|'cron_jobs' */
            'type' => $this->resource['type'],
            /** @var list<SearchResultResource> */
            'items' => SearchResultResource::collection($this->resource['items']),
        ];
    }
}
