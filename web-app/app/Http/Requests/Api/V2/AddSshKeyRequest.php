<?php

namespace App\Http\Requests\Api\V2;

/**
 * POST /users/{user}/ssh-keys: one public key line of authorized_keys.
 */
final class AddSshKeyRequest extends ApiRequest
{
    /** Key types that `ssh-keygen` reads from a public key line. */
    public const TYPES = 'ssh-rsa|ssh-dss|ssh-ed25519|ecdsa-sha2-nistp256|ecdsa-sha2-nistp384|ecdsa-sha2-nistp521|sk-ssh-ed25519@openssh\.com|sk-ecdsa-sha2-nistp256@openssh\.com';

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The public key line: type, key and an optional comment, which identifies the key; a missing comment is generated. At most 16384 characters, one line. */
            'key' => ['required', 'string', 'max:16384', 'regex:/^('.self::TYPES.') [A-Za-z0-9+\/]+={0,3}( [^\x00-\x1f\'"]+)?$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['key.regex' => __('The key is not an SSH public key line (type, key, optional comment).')];
    }
}
