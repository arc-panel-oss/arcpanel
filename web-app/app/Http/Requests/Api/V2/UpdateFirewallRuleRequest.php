<?php

namespace App\Http\Requests\Api\V2;

/**
 * PATCH /system/firewall/rules/{rule} (E34 batch 6 spec §5.3): the fields of
 * StoreFirewallRuleRequest, each optional; fields left out keep their value.
 */
final class UpdateFirewallRuleRequest extends StoreFirewallRuleRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = parent::rules();
        foreach (['action', 'protocol', 'source'] as $field) {
            $rules[$field] = ['sometimes', ...array_slice($rules[$field], 1)];
        }

        return $rules;
    }
}
