<?php

namespace App\Http\Requests\Server;

/** Mail tab of the server settings (E7 batch 3 spec §8.2); ServerPolicy authorizes. */
final class MailSettingsRequest extends ServerSettingsRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'webmail_alias' => ['nullable', 'string', 'max:63', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/'],
            'webmail_port' => ['nullable', 'integer', 'between:1024,65535'],
            'webmail_link' => ['nullable', 'string', 'in:port,domain'],
            'relay_host' => ['required_if_accepted:relay', 'nullable', 'string', 'max:253', 'regex:/^[A-Za-z0-9.-]+$/'],
            'relay_port' => ['nullable', 'integer', 'between:1,65535'],
            'relay_user' => ['nullable', 'string', 'max:128', 'not_regex:/[\'"\s\\\\]/'],
            'relay_password' => ['nullable', 'string', 'max:256', 'not_regex:/[\'"\n\r]/'],
        ];
    }

    /**
     * Fields the page did not show are null; an empty webmail port turns it off (E46).
     *
     * @return array{webmail_alias: ?string, webmail_port: ?string, webmail_link: ?string, relay: bool, relay_host: string, relay_port: string, relay_user: string, relay_password: ?string}
     */
    public function values(): array
    {
        return [
            'webmail_alias' => $this->has('webmail_alias') ? (string) $this->input('webmail_alias') : null,
            'webmail_port' => $this->has('webmail_port') ? ($this->filled('webmail_port') ? (string) (int) $this->input('webmail_port') : '') : null,
            'webmail_link' => $this->filled('webmail_link') ? (string) $this->input('webmail_link') : null,
            'relay' => $this->boolean('relay'),
            'relay_host' => trim((string) $this->input('relay_host')),
            'relay_port' => $this->filled('relay_port') ? (string) (int) $this->input('relay_port') : '587',
            'relay_user' => trim((string) $this->input('relay_user')),
            'relay_password' => $this->filled('relay_password') ? (string) $this->input('relay_password') : null,
        ];
    }
}
