<?php

namespace App\Http\Requests\Api\V2;

use App\Services\Node\NodeService;

/** POST /users/{user}/node/versions */
final class InstallNodeVersionRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'spec' => ['required', 'string', 'regex:'.NodeService::SPEC],
        ];
    }
}
