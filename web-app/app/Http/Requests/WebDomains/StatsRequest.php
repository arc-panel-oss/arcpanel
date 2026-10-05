<?php

namespace App\Http\Requests\WebDomains;

final class StatsRequest extends TabRequest
{
    public function tab(): string
    {
        return 'stats';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'max:50', 'regex:/^[\w-]+$/'],
            'auth' => ['nullable', 'boolean'],
            'user' => ['nullable', 'required_if:auth,1', 'string', 'max:64', 'regex:/^[\w.-]+$/'],
            'password' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array{type: string, auth: bool, user: ?string, password: ?string} */
    public function values(): array
    {
        return [
            'type' => (string) $this->input('type'),
            'auth' => $this->boolean('auth'),
            'user' => $this->input('user'),
            'password' => $this->input('password'),
        ];
    }
}
