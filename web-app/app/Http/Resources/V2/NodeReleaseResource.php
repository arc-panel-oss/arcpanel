<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One major from `v-list-sys-node-releases`. */
final class NodeReleaseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array{major: string, latest: string, lts: string, status: string, eol: string, cached: bool, allowed: bool} $release */
        $release = $this->resource;

        return [
            'major' => $release['major'],
            'latest' => $release['latest'],
            'lts' => $release['lts'] !== '' ? $release['lts'] : null,
            'status' => $release['status'],
            'eol' => $release['eol'] !== '' ? $release['eol'] : null,
            'cached' => $release['cached'],
            'allowed' => $release['allowed'],
        ];
    }
}
