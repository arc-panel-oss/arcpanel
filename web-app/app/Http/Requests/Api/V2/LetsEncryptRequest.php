<?php

namespace App\Http\Requests\Api\V2;

use App\Services\WebDomains\WebDomainService;
use Illuminate\Validation\Rule;

/** POST /users/{user}/web-domains/{domain}/letsencrypt. */
final class LetsEncryptRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The key type of the certificate; the domain's current one when left out. */
            'key_algorithm' => ['sometimes', Rule::in(WebDomainService::KEY_ALGORITHMS)],
        ];
    }
}
