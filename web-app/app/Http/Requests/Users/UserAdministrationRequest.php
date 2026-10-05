<?php

namespace App\Http\Requests\Users;

final class UserAdministrationRequest extends UserTabRequest
{
    public function tab(): string
    {
        return 'administration';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $name = ['nullable', 'string', 'max:32', 'regex:/^[[:alnum:]][-.\w]*$/'];

        return [
            'role' => ['nullable', 'in:user,admin,reseller'],
            'owner' => $name,
            'shell' => $name,
            'phpcli' => ['nullable', 'string', 'max:10', 'regex:/^\d+\.\d+$/'],
        ];
    }

    /** @return array{role: ?string, owner: ?string, shell: ?string, phpcli: ?string} */
    public function values(): array
    {
        return [
            'role' => $this->input('role'),
            'owner' => $this->input('owner'),
            'shell' => $this->input('shell'),
            'phpcli' => $this->input('phpcli'),
        ];
    }
}
