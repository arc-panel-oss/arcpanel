<?php

namespace App\Http\Requests\Api\V2;

/** PUT /users/{user}/node */
final class SetNodeEnabledRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
        ];
    }
}
