<?php

namespace App\Http\Requests\Users;

final class UserGeneralRequest extends UserTabRequest
{
    public function tab(): string
    {
        return 'general';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', 'not_regex:/[\'"\\\\$`<>[:cntrl:]]/'],
            'email' => ['required', 'email', 'max:254'],
            'password' => ['nullable', 'string', 'min:8', 'max:256', 'regex:/[a-z]/', 'regex:/[A-Z]/', 'regex:/\d/'],
            'language' => ['required', 'string', 'max:10', 'regex:/^[a-z]{2,3}(-[a-z]{2})?$/'],
        ];
    }

    /** @return array{name: string, email: string, password: ?string, language: string} */
    public function values(): array
    {
        return [
            'name' => trim((string) $this->input('name')),
            'email' => trim((string) $this->input('email')),
            'password' => $this->filled('password') ? (string) $this->input('password') : null,
            'language' => (string) $this->input('language'),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['password.regex' => __('Password does not match the minimum requirements.')];
    }
}
