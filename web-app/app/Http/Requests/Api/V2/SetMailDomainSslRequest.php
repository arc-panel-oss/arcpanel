<?php

namespace App\Http\Requests\Api\V2;

/**
 * PUT /users/{user}/mail-domains/{domain}/ssl. The certificate is required.
 */
final class SetMailDomainSslRequest extends ApiRequest
{
    /** PEM text spans lines. */
    protected function multiLineFields(): array
    {
        return ['certificate', 'key', 'ca'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** PEM of the certificate. */
            'certificate' => ['required', 'string', 'max:65535'],
            /** PEM of the private key. Required when this is the domain's first uploaded certificate. */
            'key' => ['sometimes', 'nullable', 'string', 'max:65535'],
            /** PEM of the certificate chain. */
            'ca' => ['sometimes', 'nullable', 'string', 'max:65535'],
        ];
    }
}
