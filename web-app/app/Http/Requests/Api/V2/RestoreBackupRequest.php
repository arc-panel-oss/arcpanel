<?php

namespace App\Http\Requests\Api\V2;

/**
 * POST /users/{user}/backups/{backup}/restore.
 */
final class RestoreBackupRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'web' => ['sometimes', 'array'],
            'web.*' => ['string', 'max:255', 'regex:/^[^,\s\'"]+$/'],
            'dns' => ['sometimes', 'array'],
            'dns.*' => ['string', 'max:255', 'regex:/^[^,\s\'"]+$/'],
            'mail' => ['sometimes', 'array'],
            'mail.*' => ['string', 'max:255', 'regex:/^[^,\s\'"]+$/'],
            'db' => ['sometimes', 'array'],
            'db.*' => ['string', 'max:255', 'regex:/^[^,\s\'"]+$/'],
            'udir' => ['sometimes', 'array'],
            'udir.*' => ['string', 'max:255', 'regex:/^[^,\s\'"]+$/'],
            'cron' => ['sometimes', 'boolean'],
        ];
    }

    /** Whether the body selects objects; an empty body restores everything. */
    public function selectsObjects(): bool
    {
        return $this->exists('web') || $this->exists('dns') || $this->exists('mail')
            || $this->exists('db') || $this->exists('udir') || $this->exists('cron');
    }
}
