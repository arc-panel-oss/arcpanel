<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A package of the panel (`v-list-sys-arcpanel-updates json`), as the
 * Updates page lists it (E34 batch 6 spec §5.4).
 *
 * @property array{name: string, version: string, arch: string, upToDate: bool, description: string, date: string} $resource
 */
final class PanelPackageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $date = str_replace(' ', 'T', $this->resource['date']);

        return [
            /** @var string `arcpanel`, `arcpanel-nginx` or `arcpanel-php`; runUpdates takes it as `package` */
            'name' => $this->resource['name'],
            /** @var string the installed version */
            'version' => $this->resource['version'],
            /** @var string */
            'arch' => $this->resource['arch'],
            /** @var string */
            'description' => $this->resource['description'],
            /** @var bool false when the archive has a newer version */
            'up_to_date' => $this->resource['upToDate'],
            /** @var string|null when the installed version was built */
            'built_at' => $date !== '' ? $date : null,
        ];
    }
}
