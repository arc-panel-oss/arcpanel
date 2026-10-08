<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A permission set a key may give (`v-list-apis json`), E34 batch 6 §4.6.
 *
 * @property array{name: string, api: int, description: ?string} $resource
 */
final class AccessKeySetResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** @var string */
            'name' => $this->resource['name'],
            /** @var 1|2 `2`: REST API v2 only, API v1 refuses the set; `1`: a set of API v1, whose commands API v2 runs too */
            'api' => $this->resource['api'],
            /** @var string|null */
            'description' => $this->resource['description'],
        ];
    }
}
