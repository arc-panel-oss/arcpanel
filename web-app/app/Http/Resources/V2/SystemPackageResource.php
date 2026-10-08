<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An entry of the system package catalog (`v-list-sys-packages json`, E5),
 * as the System packages page shows it (E34 batch 6 spec §5.5).
 *
 * @property array{slug: string, name: string, summary: string, category: string, provides: list<string>, packages: list<string>, available: bool, status: string, source: string, version: string, upgradable: bool, domains: list<string>, usedBy: int} $resource
 */
final class SystemPackageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** @var string the id the install and remove paths take */
            'slug' => $this->resource['slug'],
            /** @var string */
            'name' => $this->resource['name'],
            /** @var string */
            'summary' => $this->resource['summary'],
            /** @var string `runtime`, `media` or `tooling` */
            'category' => $this->resource['category'],
            /** @var list<string> runtimes the entry provides to the templates, for example `python` */
            'provides' => $this->resource['provides'],
            /** @var list<string> the distribution packages of the entry */
            'packages' => $this->resource['packages'],
            /** @var bool whether the distribution release of the server has the packages */
            'available' => $this->resource['available'],
            /** @var string `installed`, `partial` or `missing` */
            'status' => $this->resource['status'],
            /** @var string|null `arcpanel` (installed by the panel, which may remove it) or `system` */
            'source' => in_array($this->resource['source'], ['', '-'], true) ? null : $this->resource['source'],
            /** @var string|null the installed version */
            'version' => $this->resource['version'] !== '' ? $this->resource['version'] : null,
            /** @var bool whether the archive has a newer version */
            'upgradable' => $this->resource['upgradable'],
            /** @var list<string> web domains whose template needs the entry, as `user/domain` */
            'domains' => $this->resource['domains'],
            /** @var int the number of those web domains */
            'used_by' => $this->resource['usedBy'],
        ];
    }
}
