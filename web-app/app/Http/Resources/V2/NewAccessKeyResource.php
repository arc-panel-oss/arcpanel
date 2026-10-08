<?php

namespace App\Http\Resources\V2;

use App\Services\Keys\AccessKey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The answer of createAccessKey: the key with its secret, which no other
 * answer shows again (E34 batch 6 D62).
 *
 * @property AccessKey $resource
 */
final class NewAccessKeyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** @var string the access key id, 20 letters and digits */
            'id' => $this->resource->id,
            /** @var list<string> */
            'permissions' => $this->resource->permissions,
            /** @var string */
            'comment' => $this->resource->comment,
            /** @var string|null in the server's time zone, YYYY-MM-DDTHH:MM:SS */
            'created_at' => UserResource::localTime($this->resource->date, $this->resource->time),
            /** @var string the secret access key; shown only here. Authenticate with `Authorization: Bearer ID:SECRET`. */
            'secret' => (string) $this->resource->secret,
        ];
    }
}
