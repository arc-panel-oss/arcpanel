<?php

namespace App\Http\Requests\Api\V2;

/**
 * PUT /system/object-storage/endpoint (E44 spec §5, §10).
 */
final class SetObjectStorageEndpointRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The hostname of the S3 endpoint, for example `s3.example.com`; null removes the endpoint. */
            'hostname' => ['present', 'nullable', 'string', 'max:253', 'regex:/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i'],
            /** Request a Let's Encrypt certificate (the default); its DNS must point at the server. */
            'letsencrypt' => ['sometimes', 'boolean'],
        ];
    }
}
