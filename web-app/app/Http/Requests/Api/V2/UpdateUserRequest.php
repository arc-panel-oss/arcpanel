<?php

namespace App\Http\Requests\Api\V2;

use App\Http\Resources\V2\ApiRole;
use Illuminate\Validation\Rule;

/**
 * PATCH /users/{user}: the General tab without the password (PUT
 * /users/{user}/password), and the Name servers, Security and
 * Administration tabs (E34 batch 6 D63); fields left out keep their value.
 */
final class UpdateUserRequest extends ApiRequest
{
    /** The fields of the Administration tab, administrators only. */
    public const ADMINISTRATION = ['role', 'owner', 'shell', 'php_cli'];

    protected function prepareForValidation(): void
    {
        if (is_array($this->input('nameservers'))) {
            $this->merge(['nameservers' => array_map(fn ($ns) => is_string($ns) ? strtolower(trim($ns)) : $ns, $this->input('nameservers'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $name = ['sometimes', 'required', 'string', 'max:32', 'regex:/^[[:alnum:]][-.\w]*$/'];

        return [
            /** The full name. */
            'name' => ['sometimes', 'required', 'string', 'max:100', 'not_regex:/[\'"\\\\$`<>[:cntrl:]]/'],
            'email' => ['sometimes', 'required', 'email', 'max:254'],
            'language' => ['sometimes', 'required', 'string', 'max:10', 'regex:/^[a-z]{2,3}(-[a-z]{2})?$/'],
            /** 2 to 8 host names. Administrators and resellers for their customers, an administrator for itself. */
            'nameservers' => ['sometimes', 'required', 'array', 'list', 'min:2', 'max:8'],
            'nameservers.*' => ['string', 'max:253', 'regex:/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z0-9-]+$/'],
            /** The IPv4 addresses the login is allowed from, compared exactly (the user record takes no networks and no IPv6); null drops the restriction. */
            'allowed_ips' => ['sometimes', 'nullable', 'array', 'list', 'min:1', 'max:24'],
            'allowed_ips.*' => ['string', 'distinct', 'ipv4'],
            /** Administrators, not for their own account. */
            'login_disabled' => ['sometimes', 'boolean'],
            /** Administrators, never for the root administrator or themselves. */
            'role' => ['sometimes', Rule::enum(ApiRole::class)],
            /** Administrators: the root administrator or a reseller that owns a user. */
            'owner' => $name,
            /** Administrators: a shell installed on the server (`nologin` without SSH). */
            'shell' => $name,
            /** Administrators: a PHP version installed on the server, for the command line. */
            'php_cli' => ['sometimes', 'required', 'string', 'max:10', 'regex:/^\d+\.\d+$/'],
        ];
    }
}
