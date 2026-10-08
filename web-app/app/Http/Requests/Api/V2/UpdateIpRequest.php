<?php

namespace App\Http\Requests\Api\V2;

/**
 * PATCH /system/ips/{ip} (E34 batch 6 spec §5.2): fields left out keep
 * their value; a null or empty `name` or `nat` removes it. The controller
 * checks that `owner` is an account.
 */
final class UpdateIpRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'owner' => ['sometimes', 'string', 'max:64'],
            'shared' => ['sometimes', 'boolean'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.-]*$/'],
            'nat' => ['sometimes', 'nullable', 'string', 'ip'],
        ];
    }
}
