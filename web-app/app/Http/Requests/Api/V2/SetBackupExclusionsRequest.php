<?php

namespace App\Http\Requests\Api\V2;

/**
 * PUT /users/{user}/backup-exclusions.
 */
final class SetBackupExclusionsRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $line = ['string', 'max:4096', 'regex:/^[^,\s\'"]+/'];

        return [
            'web' => ['sometimes', 'array'],
            'web.*' => $line,
            'dns' => ['sometimes', 'array'],
            'dns.*' => $line,
            'mail' => ['sometimes', 'array'],
            'mail.*' => $line,
            'db' => ['sometimes', 'array'],
            'db.*' => $line,
            'cron' => ['sometimes', 'array'],
            'cron.*' => $line,
            'user' => ['sometimes', 'array'],
            'user.*' => $line,
        ];
    }
}
