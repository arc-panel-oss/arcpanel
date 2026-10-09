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
            // The user record and the login take IPv4 addresses only, compared exactly (as API v2)
            'allowed_ips' => ['nullable', 'required_if:use_iplist,1', 'string', 'max:2000', function (string $attribute, mixed $value, \Closure $fail) {
                foreach (self::split($value) as $ip) {
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                        $fail(sprintf(__('%s is not an IPv4 address.'), $ip));

                        return;
                    }
                }
            }],
            'twofa_off' => ['nullable', 'boolean'],
        ];
    }

    /** @return array{use_iplist: bool, allowed_ips: string, login_disabled: ?bool, twofa_off: bool} */
    public function values(bool $withLogin): array
    {
        return [
            'use_iplist' => $this->boolean('use_iplist'),
            'allowed_ips' => implode(',', self::split($this->input('allowed_ips'))),
            'login_disabled' => $withLogin ? $this->boolean('login_disabled') : null,
            'twofa_off' => $this->boolean('twofa_off'),
        ];
    }

    /** @return list<string> the addresses of the field, one per line or comma */
    private static function split(mixed $value): array
    {
        return array_values(array_filter(preg_split('/[\s,]+/', is_string($value) ? $value : '') ?: []));
    }
}
