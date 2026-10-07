<?php

namespace App\Http\Resources\V2;

use App\Services\Node\NodeRuntime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One installed Node.js runtime of a user. */
final class NodeRuntimeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var NodeRuntime $runtime */
        $runtime = $this->resource;

        return [
            'version' => $runtime->version,
            'major' => $runtime->major,
            'default' => $runtime->default,
            'lts' => $runtime->lts !== '' ? $runtime->lts : null,
            'status' => $runtime->status,
            'eol' => $runtime->eol !== '' ? $runtime->eol : null,
            'size_mb' => $runtime->sizeMb,
            'packages' => $runtime->packages,
        ];
    }
}
