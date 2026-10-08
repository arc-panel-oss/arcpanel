<?php

namespace App\Http\Requests\Api\V2;

final class SetPortPublicOkRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['public_ok' => ['required', 'boolean']];
    }
}
