<?php

namespace App\Http\Requests\Api\V2;

use App\Services\Node\NodeService;

/** POST /users/{user}/node/versions/{version}/packages */
final class InstallNodePackageRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'spec' => ['required', 'string', 'max:280', 'regex:'.NodeService::PACKAGE_SPEC],
        ];
    }
}
