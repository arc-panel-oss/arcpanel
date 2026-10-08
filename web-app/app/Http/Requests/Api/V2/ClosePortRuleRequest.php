<?php

namespace App\Http\Requests\Api\V2;

use App\Services\Ports\PortActions;

final class ClosePortRuleRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return app(PortActions::class)->closeRuleRules();
    }
}
