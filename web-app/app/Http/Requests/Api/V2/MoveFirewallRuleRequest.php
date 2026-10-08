<?php

namespace App\Http\Requests\Api\V2;

/**
 * POST /system/firewall/rules/{rule}/move (E34 batch 6 spec D59).
 */
final class MoveFirewallRuleRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** `up` swaps the rule with the one before it, `down` with the one after it. */
            'direction' => ['required', 'string', 'in:up,down'],
        ];
    }
}
