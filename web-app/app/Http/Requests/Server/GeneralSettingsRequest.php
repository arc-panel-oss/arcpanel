<?php

namespace App\Http\Requests\Server;

/** General tab of the server settings (E7 batch 3 spec §8.2); ServerPolicy authorizes. */
final class GeneralSettingsRequest extends ServerSettingsRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'hostname' => ['required', 'string', 'max:253', 'regex:/^[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/'],
            'timezone' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_+\-\/]+$/'],
            'language' => ['required', 'string', 'max:10', 'regex:/^[a-z]{2,3}(-[a-z]{2})?$/'],
            'theme' => ['required', 'string', 'max:64', 'regex:/^[\w-]+$/'],
        ];
    }

    /** @return array{hostname: string, timezone: string, language: string, language_all: bool, theme: string, user_theme: bool, debug: bool, experimental: bool} */
    public function values(): array
    {
        return [
            'hostname' => trim((string) $this->input('hostname')),
            'timezone' => (string) $this->input('timezone'),
            'language' => (string) $this->input('language'),
            'language_all' => $this->boolean('language_all'),
            'theme' => (string) $this->input('theme'),
            'user_theme' => $this->boolean('user_theme'),
            'debug' => $this->boolean('debug'),
            'experimental' => $this->boolean('experimental'),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['hostname.regex' => __('Please enter a valid hostname.')];
    }
}
