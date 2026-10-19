<?php

namespace App\Http\Resources\V2;

use App\Services\ObjectStorage\S3Key;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An S3 key of a user's object storage (E44 spec §7, §10). The secret is in
 * the answer of `createS3Key` only; it is never kept.
 *
 * @property S3Key $resource
 */
final class S3KeyResource extends JsonResource
{
    /** The secret of a key just created, once. */
    public ?string $secret = null;

    /** The name it was saved under in the secret store. */
    public ?string $savedAs = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $key = $this->resource;

        return [
            /** @var string the access key ID */
            'id' => $key->key,
            /** @var string */
            'name' => $key->name,
            /** @var string|null the one bucket the key reaches; null for every bucket of the account */
            'bucket' => $key->bucket !== '' ? $key->bucket : null,
            /** @var 'rw'|'ro' */
            'access' => $key->access,
            /** @var string|null YYYY-MM-DD: the key stops working at the start of that day (UTC) */
            'expires_on' => $key->expires !== '' ? $key->expires : null,
            /** @var bool */
            'expired' => $key->expired,
            /** @var string|null in the server's time zone, YYYY-MM-DDTHH:MM:SS */
            'created_at' => UserResource::localTime($key->date, $key->time),
            /** The secret access key, in the answer of `createS3Key` only. */
            'secret' => $this->when($this->secret !== null, fn () => $this->secret),
            /** The secret store entry the secret was saved to (`save_as`). */
            'saved_as' => $this->when($this->savedAs !== null, fn () => $this->savedAs),
        ];
    }
}
