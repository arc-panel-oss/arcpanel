<?php

namespace App\Rules;

use App\Services\Users\UserService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A comma list of shells of this server (`v-list-sys-shells`), such as
 * POLICY_RESELLER_PACKAGE_SHELLS (E39 W4 spec §8.1).
 */
final class ServerShells implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $shells = explode(',', (string) $value);
        $unknown = array_diff($shells, array_keys(app(UserService::class)->shells()));
        if (in_array('', $shells, true) || $unknown !== []) {
            $fail(sprintf(__('Use a comma list of shells of this server: %s.'), implode(', ', array_keys(app(UserService::class)->shells()))));
        }
    }
}
