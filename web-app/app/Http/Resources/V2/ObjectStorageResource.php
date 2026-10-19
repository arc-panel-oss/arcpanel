<?php

namespace App\Http\Resources\V2;

use App\Services\ObjectStorage\SiloStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The object storage service of the server (E44 spec §4, §10).
 *
 * @property SiloStatus $resource
 */
final class ObjectStorageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $silo = $this->resource;

        return [
            /** @var bool */
            'installed' => $silo->installed,
            /** @var string|null the Silo package version */
            'version' => $silo->version !== '' ? $silo->version : null,
            /** @var bool */
            'running' => $silo->running,
            /** @var string|null */
            'data_path' => $silo->dataPath !== '' ? $silo->dataPath : null,
            /** @var string|null the hostname of the S3 endpoint */
            'hostname' => $silo->hostname !== '' ? $silo->hostname : null,
            /** @var string|null https:// with a certificate, else http:// */
            'endpoint' => $silo->endpoint !== '' ? $silo->endpoint : null,
            /** @var string */
            'region' => $silo->region,
            /** @var int accounts that hold buckets */
            'accounts' => $silo->accounts,
            /** @var int */
            'buckets' => $silo->buckets,
            /** @var int */
            'objects' => $silo->objects,
            /** @var int bytes the last scan counted */
            'used_bytes' => $silo->bytes,
        ];
    }
}
