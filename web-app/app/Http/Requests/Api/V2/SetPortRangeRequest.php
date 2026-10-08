<?php

namespace App\Http\Requests\Api\V2;

use App\Services\Ports\PortActions;

final class SetPortRangeRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return app(PortActions::class)->serverRangeRules();
    }
}
