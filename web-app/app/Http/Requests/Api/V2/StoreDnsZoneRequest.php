<?php

namespace App\Http\Requests\Api\V2;

use App\Services\Dns\DnsService;

/**
 * POST /users/{user}/dns-zones. The domain is lowercased and loses a leading "www.".
 */
final class StoreDnsZoneRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('domain'))) {
            $this->merge(['domain' => (string) preg_replace('/^www\./i', '', strtolower(trim($this->input('domain'))))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The zone name. */
            'domain' => ['required', 'string', 'max:253', 'regex:/^[\pL\pN]([\pL\pN-]*[\pL\pN])?(\.[\pL\pN]([\pL\pN-]*[\pL\pN])?)+$/u'],
            /** One of the user's addresses; the first one when left out. */
            'ip' => ['sometimes', 'ip'],
            /** A DNS template. Needs the right to change templates. */
            'template' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:/^[\w.-]*$/'],
            /** Name servers, one to eight. The user's own when left out. */
            'nameservers' => ['sometimes', 'array', 'min:1', 'max:'.DnsService::NAME_SERVERS],
            'nameservers.*' => ['required', 'string', 'max:253', 'regex:/^[\pL\pN._-]+$/u'],
            /** Sign the zone. Refused when the server has no DNSSEC. */
            'dnssec' => ['sometimes', 'boolean'],
            /** Seconds. 14400 when left out. */
            'ttl' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            /** YYYY-MM-DD. One year from today when left out. */
            'expiry' => ['sometimes', 'date_format:Y-m-d'],
        ];
    }
}
