<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A network interface of the server (`v-list-sys-interfaces json`), the
 * choices of `interface` in createIp.
 *
 * @property string $resource
 */
final class NetworkInterfaceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** @var string for example `eth0` */
            'name' => $this->resource,
        ];
    }
}
