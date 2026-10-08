<?php

namespace App\Http\Requests\Api\V2;

/**
 * POST /system/ips (E34 batch 6 spec §5.2), the fields of the Add IP
 * address page. The controller checks that `interface` is one of the
 * server's and `owner` an account, after the role check.
 */
final class StoreIpRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ip' => ['required', 'string', 'ip'],
            /** A netmask (`255.255.255.0`) or a prefix length. */
            'netmask' => ['required', 'string', 'max:45', 'regex:/^[0-9A-Fa-f:.\/]{1,45}$/'],
            /** One of listInterfaces. */
            'interface' => ['required', 'string', 'max:64'],
            /** The account the address belongs to; the root administrator when left out. */
            'owner' => ['sometimes', 'string', 'max:64'],
            /** Shared by every account (the default), or dedicated to `owner`. */
            'shared' => ['sometimes', 'boolean'],
            /** The domain assigned to the address. */
            'name' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.-]*$/'],
            /** The public address in front of this one (NAT). */
            'nat' => ['sometimes', 'nullable', 'string', 'ip'],
        ];
    }
}
