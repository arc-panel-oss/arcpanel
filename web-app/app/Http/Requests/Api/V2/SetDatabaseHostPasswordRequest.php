<?php

namespace App\Http\Requests\Api\V2;

/**
 * PUT /system/settings/databases/hosts/{type}/{host}/password (E34 batch 6
 * spec §5.7): the password of a database user on a MySQL host, as on the
 * page. The password reaches the command in a file and is never answered.
 */
final class SetDatabaseHostPasswordRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The database user, for example `root`. */
            'user' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.-]+$/'],
            /** The new password: 8 to 256 characters, no quotes and no backslash. */
            'password' => ['required', 'string', 'min:8', 'max:256', 'not_regex:/[\'"\\\\]/'],
        ];
    }
}
