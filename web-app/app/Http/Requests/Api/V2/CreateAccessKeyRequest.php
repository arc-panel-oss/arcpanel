<?php

namespace App\Http\Requests\Api\V2;

use App\Services\Keys\AccessKeyRestrictions;

/**
 * POST /users/{user}/access-keys (E34 batch 6 §4.6, D62), with the address
 * list and expiry of E43 B6.
 */
final class CreateAccessKeyRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Permission sets (see GET /access-key-sets). At least one, except for the own key of an administrator, made with a key without sets: no set gives every command. */
            'permissions' => ['present', 'array', 'list', 'max:50'],
            'permissions.*' => ['string', 'distinct', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/'],
            /** A note for the key, at most 255 characters. */
            'comment' => ['nullable', 'string', 'max:255', 'not_regex:/[\'"\\\\[:cntrl:]]/'],
            /** IPv4 or IPv6 addresses or CIDR networks the key is limited to, at most 20; empty or absent means any address. Set once: a different list means a new key. */
            'allowed_ips' => ['sometimes', 'nullable', 'array', 'list', 'max:'.AccessKeyRestrictions::MAX_IPS],
            'allowed_ips.*' => ['string', 'distinct', 'max:43', AccessKeyRestrictions::addressRule()],
            /** ISO 8601 time after which the key is refused, at least an hour and at most three years ahead; absent or null means no expiry. Set once. */
            'expires_at' => ['sometimes', 'nullable', 'string', 'max:40', AccessKeyRestrictions::expiryRule()],
        ];
    }

    /** @return list<string> */
    public function sets(): array
    {
        return array_values(array_map('strval', (array) $this->input('permissions', [])));
    }

    /** @return list<string> */
    public function allowedIps(): array
    {
        return AccessKeyRestrictions::list((array) $this->input('allowed_ips', []));
    }

    /** The Unix time of expires_at; null without an expiry. */
    public function expires(): ?int
    {
        $value = $this->input('expires_at');

        return AccessKeyRestrictions::timestamp(is_string($value) ? $value : null);
    }
}
