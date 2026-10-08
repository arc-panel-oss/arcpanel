<?php

namespace App\Http\Requests\Api\V2;

final class SetPortPublicRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['public' => ['required', 'boolean']];
    }
}
