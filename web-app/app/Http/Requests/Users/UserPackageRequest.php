<?php

namespace App\Http\Requests\Users;

final class UserPackageRequest extends UserTabRequest
{
    public function tab(): string
    {
        return 'package';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['package' => ['required', 'string', 'max:100', 'regex:/^[[:alnum:]][-.\w]*$/']];
    }
}
