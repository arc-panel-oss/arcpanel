<?php

namespace App\Http\Requests\WebDomains;

final class FtpRequest extends TabRequest
{
    public function tab(): string
    {
        return 'ftp';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $path = ['nullable', 'string', 'max:255', 'regex:/^[\pL\pN\/._ -]*$/u', 'not_regex:/\.\./'];

        return [
            'accounts' => ['nullable', 'array', 'max:100'],
            'accounts.*.user' => ['required', 'string', 'max:64', 'regex:/^[\w.-]+$/'],
            'accounts.*.path' => $path,
            'accounts.*.password' => ['nullable', 'string', 'max:255'],
            'accounts.*.email' => ['nullable', 'email', 'max:255'],
            'accounts.*.delete' => ['nullable', 'boolean'],
            'new.user' => ['nullable', 'string', 'max:64', 'regex:/^[\w.-]+$/'],
            'new.password' => ['nullable', 'required_with:new.user', 'string', 'max:255'],
            'new.path' => $path,
            'new.email' => ['nullable', 'email', 'max:255'],
        ];
    }

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return [
            'new.user' => __('Username'),
            'new.password' => __('Password'),
            'new.email' => __('Email'),
            'accounts.*.email' => __('Email'),
        ];
    }

    /** @return array{accounts: list<array<string, mixed>>, new: array<string, mixed>} */
    public function values(): array
    {
        $accounts = [];
        foreach ((array) $this->input('accounts', []) as $account) {
            $accounts[] = [
                'user' => (string) ($account['user'] ?? ''),
                'path' => $account['path'] ?? '',
                'password' => $account['password'] ?? '',
                'email' => $account['email'] ?? '',
                'delete' => filter_var($account['delete'] ?? false, FILTER_VALIDATE_BOOL),
            ];
        }

        return ['accounts' => $accounts, 'new' => (array) $this->input('new', [])];
    }
}
