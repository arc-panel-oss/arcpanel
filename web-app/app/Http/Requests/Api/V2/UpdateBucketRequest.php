<?php

namespace App\Http\Requests\Api\V2;

/**
 * PATCH /users/{user}/buckets/{bucket} (E44 spec §10): fields left out keep
 * their value.
 */
final class UpdateBucketRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The hard quota in MB. A quota below the usage stops uploads until the bucket is below it. */
            'quota_mb' => ['sometimes', 'integer', 'min:1', 'max:999999999'],
            /** `false` suspends versioning; versions already kept stay. */
            'versioning' => ['sometimes', 'boolean'],
        ];
    }
}
