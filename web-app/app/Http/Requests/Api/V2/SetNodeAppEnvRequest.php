<?php

namespace App\Http\Requests\Api\V2;

use App\Services\Node\NodeAppActions;

final class SetNodeAppEnvRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return app(NodeAppActions::class)->envApiSaveRules();
    }
}
