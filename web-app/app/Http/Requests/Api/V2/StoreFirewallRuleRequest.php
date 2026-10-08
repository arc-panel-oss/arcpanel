<?php

namespace App\Http\Requests\Api\V2;

use App\Services\Firewall\FirewallService;
use Illuminate\Validation\Rule;

/**
 * POST /system/firewall/rules (E34 batch 6 spec §5.3): the fields of the Add
 * rule page. `action` and `protocol` are uppercased and a numeric `port`
 * becomes text, as the commands take them; the commands check the formats
 * of the port and the address.
 */
class StoreFirewallRuleRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['action', 'protocol'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => strtoupper(trim($this->input($field)))]);
            }
        }
        if (is_int($this->input('port'))) {
            $this->merge(['port' => (string) $this->input('port')]);
        }
        foreach (['source', 'comment'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(FirewallService::ACTIONS)],
            'protocol' => ['required', Rule::in(FirewallService::PROTOCOLS)],
            /** A port, a range (`80-82`) or a list (`80,443`); `0` (the default) is every port. */
            'port' => ['sometimes', 'string', 'max:255'],
            /** An address, a network in CIDR format, or `ipset:NAME` for an IPv4 IPset list. */
            'source' => ['required', 'string', 'max:255'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
