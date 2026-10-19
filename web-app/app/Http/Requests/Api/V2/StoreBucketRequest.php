<?php

namespace App\Http\Requests\Api\V2;

/**
 * POST /users/{user}/buckets (E44 spec §10).
 */
final class StoreBucketRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Unique on the server: 3-63 lower case letters, digits and hyphens, a letter or digit at both ends. */
            'name' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/'],
            /** The hard quota in MB; the quotas of the account's buckets stay within its package. */
            'quota_mb' => ['required', 'integer', 'min:1', 'max:999999999'],
            /** Keep versions of overwritten and deleted objects. */
            'versioning' => ['sometimes', 'boolean'],
        ];
    }
}
