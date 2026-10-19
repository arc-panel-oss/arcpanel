<?php

namespace App\Http\Requests\Api\V2;

/**
 * POST /system/object-storage (E44 spec §4, §10).
 */
final class InstallObjectStorageRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** A folder of its own outside /home and the panel, empty or holding Silo data; default /srv/arcpanel-silo. */
            'data_path' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^\/[A-Za-z0-9._\/-]+$/', 'not_regex:/\.\./'],
        ];
    }
}
