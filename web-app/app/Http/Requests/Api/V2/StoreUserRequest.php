<?php

namespace App\Http\Requests\Api\V2;

use App\Http\Resources\V2\ApiRole;
use Illuminate\Validation\Rule;

/**
 * POST /users: the account of the create wizard (E11 spec §7.3). Role,
 * owner and login_disabled are an administrator's choices; for a reseller
 * the new user is always its own customer.
 */
final class StoreUserRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('username'))) {
            $this->merge(['username' => strtolower(trim((string) $this->input('username')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The login name: 2 to 30 of a-z, 0-9, dot, dash and underscore. */
            'username' => ['required', 'string', 'min:2', 'max:30', 'regex:/^[a-z0-9][-.a-z0-9_]*[a-z0-9]$/'],
            /** At least 8 characters with a lower-case and an upper-case letter and a digit. */
            'password' => ['required', 'string', 'min:8', 'max:256', 'regex:/[a-z]/', 'regex:/[A-Z]/', 'regex:/\d/'],
            'email' => ['required', 'email', 'max:254'],
            /** The full name. */
            'name' => ['required', 'string', 'max:100', 'not_regex:/[\'"\\\\$`<>[:cntrl:]]/'],
            /** A package the caller may assign (see GET /packages). */
            'package' => ['required', 'string', 'max:100', 'regex:/^[[:alnum:]][-.\w]*$/'],
            /** A language of the server; the server language when left out. */
            'language' => ['nullable', 'string', 'max:10', 'regex:/^[a-z]{2,3}(-[a-z]{2})?$/'],
            /** Administrators only. */
            'role' => ['nullable', Rule::enum(ApiRole::class)],
            /** Administrators only: the administrator or a reseller that owns a new user. */
            'owner' => ['nullable', 'string', 'max:32', 'regex:/^[[:alnum:]][-.\w]*$/'],
            /** Administrators only. */
            'login_disabled' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['password.regex' => __('Password does not match the minimum requirements.')];
    }

    /** @return array{username: string, password: string, email: string, package: string, name: string, language: string, role: string, owner: string, login_disabled: bool} */
    public function account(): array
    {
        return [
            'username' => (string) $this->input('username'),
            'password' => (string) $this->input('password'),
            'email' => trim((string) $this->input('email')),
            'package' => (string) $this->input('package'),
            'name' => trim((string) $this->input('name')),
            'language' => (string) $this->input('language'),
            'role' => (string) ($this->input('role') ?: 'user'),
            'owner' => (string) $this->input('owner'),
            'login_disabled' => $this->boolean('login_disabled'),
        ];
    }
}
