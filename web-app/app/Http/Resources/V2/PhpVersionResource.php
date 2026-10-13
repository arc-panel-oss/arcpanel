<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A PHP version of the server (`v-list-sys-php-versions json`, E34 batch 6
 * spec §5.7). The default version and versions in use are never removed.
 * The PHP Manager (E41 §9) adds the catalog fields: support status, dates,
 * whether it may be installed and is available, and its enabled modules.
 *
 * @property array{version: string, installed: bool, default: bool, used_by: list<string>, status?: string, released?: string, active_until?: string, eol?: string, installable?: bool, available?: bool, extensions?: int} $resource
 */
final class PhpVersionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $date = fn (?string $value) => $value !== null && $value !== '' ? $value : null;

        return [
            /** @var string for example `8.4` */
            'version' => $this->resource['version'],
            /** @var bool */
            'installed' => $this->resource['installed'],
            /** @var bool the version of the system `php` binary */
            'default' => $this->resource['default'],
            /** @var list<string> web domains that use it, as `user/domain` */
            'used_by' => $this->resource['used_by'],
            $this->mergeWhen(isset($this->resource['status']), fn () => [
                /** @var string `active`, `security` (security fixes only), `eol` (end of life) or `unknown` */
                'status' => $this->resource['status'],
                /** @var string|null release date, `YYYY-MM-DD` */
                'released' => $date($this->resource['released'] ?? null),
                /** @var string|null end of active support, `YYYY-MM-DD` */
                'active_until' => $date($this->resource['active_until'] ?? null),
                /** @var string|null end of life, `YYYY-MM-DD` */
                'eol' => $date($this->resource['eol'] ?? null),
                /** @var bool whether the catalog allows new installs (7.4 and later) */
                'installable' => $this->resource['installable'] ?? false,
                /** @var bool whether the PHP repository of the server provides it */
                'available' => $this->resource['available'] ?? false,
                /** @var int modules enabled for PHP-FPM; 0 when not installed */
                'extensions' => $this->resource['extensions'] ?? 0,
            ]),
        ];
    }

    /**
     * A row of PhpManager::versions() as the resource takes it.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function fromManager(array $row): array
    {
        return [
            'version' => $row['version'], 'installed' => $row['installed'], 'default' => $row['default'], 'used_by' => $row['usedBy'],
            'status' => $row['status'], 'released' => $row['released'], 'active_until' => $row['activeUntil'], 'eol' => $row['eol'],
            'installable' => $row['installable'], 'available' => $row['available'], 'extensions' => $row['extensions'],
        ];
    }
}
