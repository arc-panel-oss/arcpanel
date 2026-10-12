<?php

namespace App\Http\Requests\Api\V2;

/**
 * PUT /users/{user}/web-domains/{domain}/php (E41 §7.2, §9).
 */
final class SetWebDomainPhpRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** An installed version the package allows, for example `8.4`; the current one when left out. */
            'version' => ['sometimes', 'string', 'regex:/^\d{1,2}\.\d{1,2}$/'],
            /** A PHP-FPM profile the package allows; the current one when left out. */
            'profile' => ['sometimes', 'string', 'regex:/^[a-z0-9][a-z0-9-]{0,31}$/'],
        ];
    }
}
