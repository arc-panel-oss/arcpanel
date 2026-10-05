<?php

namespace App\Http\Requests\Users;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Create wizard (web/add/user plus the optional first web domain and
 * database of E11 spec §7.3). Package, role and owner are checked by the
 * controller against what the principal may assign.
 */
final class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $domain = strtolower(trim((string) $this->input('domain')));
        $this->merge([
            'username' => strtolower(trim((string) $this->input('username'))),
            'domain' => (string) preg_replace('/^www\./i', '', $domain),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'min:2', 'max:30', 'regex:/^[a-z0-9][-.a-z0-9_]*[a-z0-9]$/'],
            'password' => ['required', 'string', 'min:8', 'max:256', 'regex:/[a-z]/', 'regex:/[A-Z]/', 'regex:/\d/'],
            'email' => ['required', 'email', 'max:254'],
            'name' => ['required', 'string', 'max:100', 'not_regex:/[\'"\\\\\n\r]/'],
            'language' => ['required', 'string', 'max:10', 'regex:/^[a-z]{2,3}(-[a-z]{2})?$/'],
            'package' => ['required', 'string', 'max:100', 'regex:/^[[:alnum:]][-.\w]*$/'],
            'role' => ['nullable', 'in:user,admin,reseller'],
            'owner' => ['nullable', 'string', 'max:32', 'regex:/^[[:alnum:]][-.\w]*$/'],
            'login_disabled' => ['nullable', 'boolean'],
            'notify' => ['nullable', 'email', 'max:254'],
            'domain' => ['nullable', 'string', 'max:253', 'regex:/^[\pL\pN]([\pL\pN-]*[\pL\pN])?(\.[\pL\pN]([\pL\pN-]*[\pL\pN])?)+$/u'],
            'dns' => ['nullable', 'boolean'],
            'mail' => ['nullable', 'boolean'],
            'database' => ['nullable', 'string', 'max:40', 'regex:/^[a-zA-Z0-9_]+$/'],
            'db_user' => ['nullable', 'required_with:database', 'string', 'max:30', 'regex:/^[a-zA-Z0-9_]+$/'],
            'db_password' => ['nullable', 'required_with:database', 'string', 'min:8', 'max:256'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['password.regex' => __('Password does not match the minimum requirements.')];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'username' => __('Username'),
            'password' => __('Password'),
            'email' => __('Email'),
            'name' => __('Name'),
            'package' => __('Package'),
            'domain' => __('Domain'),
            'database' => __('Database'),
            'db_user' => __('Database user'),
            'db_password' => __('Database password'),
        ];
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
