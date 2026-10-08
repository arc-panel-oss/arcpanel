<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A PHP version of the server (`v-list-sys-php-versions json`, E34 batch 6
 * spec §5.7). The default version and versions in use are never removed.
 *
 * @property array{version: string, installed: bool, default: bool, used_by: list<string>} $resource
 */
final class PhpVersionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** @var string for example `8.4` */
            'version' => $this->resource['version'],
            /** @var bool */
            'installed' => $this->resource['installed'],
            /** @var bool the version of the system `php` binary */
            'default' => $this->resource['default'],
            /** @var list<string> web domains that use it, as `user/domain` */
            'used_by' => $this->resource['used_by'],
        ];
    }
}
