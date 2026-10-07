<?php

namespace App\Http\Resources\V2;

use App\Services\Node\NodeAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Node.js account overview (`v-list-user-node-versions`). */
final class NodeAccountResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var NodeAccount $account */
        $account = $this->resource;

        return [
            'enabled' => $account->enabled,
            'default_version' => $account->default !== '' ? $account->default : null,
            'allowed_versions' => $account->allowedMajors,
            'limit' => $account->limit,
            'room' => $account->hasRoom(),
            'runtimes' => NodeRuntimeResource::collection($account->runtimes),
        ];
    }
}
