<?php

namespace App\Http\Requests\Server;

/** SSL tab of the server settings (E7 batch 3 spec §8.2); ServerPolicy authorizes. */
final class SslSettingsRequest extends ServerSettingsRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'certificate' => ['required', 'string', 'max:65536', 'regex:/-----BEGIN CERTIFICATE-----/'],
            'key' => ['required', 'string', 'max:65536', 'regex:/-----BEGIN [A-Z ]*PRIVATE KEY-----/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'certificate.regex' => __('The certificate must be in PEM format.'),
            'key.regex' => __('The private key must be in PEM format.'),
        ];
    }
}
