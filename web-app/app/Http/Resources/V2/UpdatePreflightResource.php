<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The pre-flight of an update to a release (`v-check-sys-arcpanel-update
 * VERSION json manual`, E33 phase 1 spec §7.3).
 *
 * @property array{version: string, ready: bool, checks: list<array{check: string, status: string, message: string}>} $resource
 */
final class UpdatePreflightResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** @var string */
            'version' => $this->resource['version'],
            /** @var bool no check failed; the update task runs the same checks again before it installs anything */
            'ready' => $this->resource['ready'],
            /**
             * @var list<array{check: string, status: string, message: string}> `check` is `manifest`, `version`,
             *      `disk`, `packages`, `activity` or `attention`; `status` is `ok`, `warn` or `fail`
             */
            'checks' => $this->resource['checks'],
        ];
    }
}
