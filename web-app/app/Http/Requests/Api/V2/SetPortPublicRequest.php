<?php

namespace App\Http\Requests\Api\V2;

final class SetPortPublicRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'public' => ['required', 'boolean'],
            /** Administrators only: link this existing firewall rule on the port instead of adding one (403 for anyone else). */
            'rule' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
