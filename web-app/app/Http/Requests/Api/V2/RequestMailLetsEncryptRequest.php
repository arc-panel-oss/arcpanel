<?php

namespace App\Http\Requests\Api\V2;

use App\Services\MailDomains\MailDomainRecord;

/**
 * POST /users/{user}/mail-domains/{domain}/letsencrypt.
 */
final class RequestMailLetsEncryptRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** @example ecdsa-256 */
            'key_algorithm' => ['sometimes', 'string', 'in:'.implode(',', MailDomainRecord::KEY_ALGORITHMS)],
        ];
    }
}
