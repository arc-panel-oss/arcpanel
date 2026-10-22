<?php

namespace App\Http\Resources\V2;

use App\Services\Files\FileFetch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One download of upload from URL
 * (docs/features/specs/2026-10-22-001-e3-upload-from-url-design.md §8).
 * The URL is shown without user information and with its query as `?…`;
 * headers and credentials are never answered.
 *
 * @property FileFetch $resource
 */
final class FileFetchResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $fetch = $this->resource;
        $time = fn (string $value) => $value === '' ? null : UserResource::localTime(substr($value, 0, 10), substr($value, 11, 8));

        return [
            /** @var string */
            'id' => $fetch->id,
            /** @var string the URL without user information, its query replaced by `?…` */
            'url' => $fetch->url,
            /** @var string destination folder relative to the home directory; empty for the home directory */
            'path' => $fetch->path,
            /** @var string file name; empty until the server's answer names it */
            'name' => $fetch->name,
            /** @var 'queued'|'running'|'done'|'skipped'|'failed'|'cancelled' */
            'status' => $fetch->status,
            /** @var int bytes received (the file's size when done) */
            'bytes' => $fetch->bytes,
            /** @var int|null bytes the server announced; null when unknown */
            'total' => $fetch->total,
            /** @var int|null percent received; null while the size is unknown */
            'percent' => $fetch->percent(),
            /** @var 'blocked'|'bad-url'|'dns'|'http'|'redirects'|'too-large'|'disk'|'timeout'|'network'|'exists'|'interrupted'|null reason of a failure */
            'error' => $fetch->error !== '' ? $fetch->error : null,
            /** @var int|null status code of the last response */
            'http_status' => $fetch->http,
            /** @var string|null server time zone, YYYY-MM-DDTHH:MM:SS */
            'created_at' => $time($fetch->created),
            /** @var string|null */
            'started_at' => $time($fetch->started),
            /** @var string|null */
            'ended_at' => $time($fetch->ended),
        ];
    }
}
