<?php

namespace App\Http\Requests\Api\V2;

/**
 * POST /system/firewall/ipsets (E34 batch 6 spec §5.3): the fields of the
 * Add IP list page.
 */
final class StoreFirewallIpsetRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'regex:/^[\w.-]{1,255}$/'],
            /** A URL, `script:PATH` or a file on the server with one address or network per line. */
            'source' => ['required', 'string', 'max:255'],
            'ip_version' => ['required', 'string', 'in:v4,v6'],
            /** Whether the list is read again every day; off by default. */
            'auto_update' => ['sometimes', 'boolean'],
        ];
    }
}
