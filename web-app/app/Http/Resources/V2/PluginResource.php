<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A plugin of the server settings (E34 batch 6 spec §5.7): `filemanager`,
 * `web-terminal`, `cgroups`, `quota` or `firewall`.
 *
 * @property array{name: string, enabled: bool} $resource
 */
final class PluginResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** @var string */
            'name' => $this->resource['name'],
            /** @var bool whether the plugin is installed */
            'enabled' => $this->resource['enabled'],
        ];
    }
}
