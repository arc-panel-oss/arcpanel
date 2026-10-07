<?php

namespace App\Http\Requests\Api\V2;

/**
 * PATCH /users/{user}/dns-zones/{domain}. Fields left out keep their value.
 * `soa` can only be changed here.
 */
final class UpdateDnsZoneRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ip' => ['sometimes', 'ip'],
            'template' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:/^[\w.-]*$/'],
            'ttl' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            /** The SOA record. */
            'soa' => ['sometimes', 'string', 'max:253', 'regex:/^[\pL\pN._-]+$/u'],
            'expiry' => ['sometimes', 'date_format:Y-m-d'],
            'dnssec' => ['sometimes', 'boolean'],
        ];
    }
}
