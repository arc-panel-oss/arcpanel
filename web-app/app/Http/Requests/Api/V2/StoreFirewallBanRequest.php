<?php

namespace App\Http\Requests\Api\V2;

use App\Services\Firewall\FirewallService;
use Illuminate\Validation\Rule;

/**
 * POST /system/firewall/bans (E34 batch 6 spec §5.3): the fields of the Ban
 * IP address page.
 */
final class StoreFirewallBanRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('chain'))) {
            $this->merge(['chain' => strtoupper(trim($this->input('chain')))]);
        }
        if (is_string($this->input('ip'))) {
            $this->merge(['ip' => trim($this->input('ip'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** An IPv4 or IPv6 address, or a network in CIDR format. */
            'ip' => ['required', 'string', 'regex:/^[0-9A-Fa-f:.\/]{2,64}$/'],
            'chain' => ['required', Rule::in(FirewallService::CHAINS)],
        ];
    }
}
