<?php

namespace App\Http\Resources\V2;

use App\Services\Keys\AccessKey;
use App\Services\Keys\AccessKeyRestrictions;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An access key of a user (`v-list-access-key KEY json`), never with its
 * secret (E34 batch 6 D62).
 *
 * @property AccessKey $resource
 */
final class AccessKeyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** @var string the access key id, 20 letters and digits */
            'id' => $this->resource->id,
            /** @var list<string> the permission sets; empty for an administrator's key without sets, which may run every command */
            'permissions' => $this->resource->permissions,
            /** @var string */
            'comment' => $this->resource->comment,
            /** @var string|null in the server's time zone, YYYY-MM-DDTHH:MM:SS */
            'created_at' => UserResource::localTime($this->resource->date, $this->resource->time),
            /** @var list<string> addresses and networks the key is limited to; empty when unrestricted (E43 B6) */
            'allowed_ips' => $this->resource->allowedIps,
            /** @var string|null ISO 8601 time in UTC after which the key is refused; null without an expiry */
            'expires_at' => AccessKeyRestrictions::iso($this->resource->expires),
        ];
    }
}
