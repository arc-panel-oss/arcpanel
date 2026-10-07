<?php

namespace App\Http\Requests\Api\V2;

/**
 * PUT /users/{user}/mail-domains/{domain}/smtp-relay.
 */
final class SetMailDomainRelayRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The relay host. */
            'host' => ['required', 'string', 'max:253', 'regex:/^[\w.-]+$/'],
            /** Defaults to 587. */
            'port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            /** @example relay-user */
            'username' => ['sometimes', 'nullable', 'string', 'max:255'],
            /** Sent to the command and never returned. */
            'password' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
