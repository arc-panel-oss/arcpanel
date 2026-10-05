<?php

namespace App\Http\Requests\Users;

final class UserSecurityRequest extends UserTabRequest
{
    public function tab(): string
    {
        return 'security';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'login_disabled' => ['nullable', 'boolean'],
            'use_iplist' => ['nullable', 'boolean'],
            'allowed_ips' => ['nullable', 'required_if:use_iplist,1', 'string', 'max:2000', 'regex:/^[0-9a-fA-F:.\/,\s]*$/'],
            'twofa_off' => ['nullable', 'boolean'],
        ];
    }

    /** @return array{use_iplist: bool, allowed_ips: string, login_disabled: ?bool, twofa_off: bool} */
    public function values(bool $withLogin): array
    {
        $ips = preg_split('/[\s,]+/', (string) $this->input('allowed_ips')) ?: [];

        return [
            'use_iplist' => $this->boolean('use_iplist'),
            'allowed_ips' => implode(',', array_values(array_filter($ips))),
            'login_disabled' => $withLogin ? $this->boolean('login_disabled') : null,
            'twofa_off' => $this->boolean('twofa_off'),
        ];
    }
}
