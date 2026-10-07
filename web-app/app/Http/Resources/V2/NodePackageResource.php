<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One global npm package of an installed runtime. */
final class NodePackageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array{name: string, version: string, latest: string, outdated: string, bundled: bool} $package */
        $package = $this->resource;

        return [
            'name' => $package['name'],
            'version' => $package['version'],
            'latest' => $package['latest'] !== '' ? $package['latest'] : null,
            'outdated' => $package['outdated'] === 'yes',
            'bundled' => $package['bundled'],
        ];
    }
}
