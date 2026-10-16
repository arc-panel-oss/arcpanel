<?php

namespace App\Http\Requests\Api\V2;

use App\Services\AppProcesses\AppProcessActions;

final class UpdateAppProcessRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return app(AppProcessActions::class)->updateRules();
    }
}
