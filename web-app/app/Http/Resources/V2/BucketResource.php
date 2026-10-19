<?php

namespace App\Http\Resources\V2;

use App\Services\ObjectStorage\Bucket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An object storage bucket of a user (E44 spec §10). Usage is what the
 * repair of the last five minutes counted.
 *
 * @property Bucket $resource
 */
final class BucketResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $bucket = $this->resource;

        return [
            /** @var string unique on the server */
            'name' => $bucket->name,
            /** @var string */
            'user' => $bucket->user,
            /** @var int the hard quota in MB */
            'quota_mb' => $bucket->quotaMb,
            /** @var int bytes the last scan counted */
            'used_bytes' => $bucket->usedBytes,
            /** @var int */
            'objects' => $bucket->objects,
            /** @var bool versions of overwritten and deleted objects are kept */
            'versioning' => $bucket->versioning,
            /** @var bool the account is suspended: its keys fail */
            'suspended' => $bucket->suspended,
            /** @var string|null in the server's time zone, YYYY-MM-DDTHH:MM:SS */
            'created_at' => UserResource::localTime($bucket->date, $bucket->time),
        ];
    }
}
