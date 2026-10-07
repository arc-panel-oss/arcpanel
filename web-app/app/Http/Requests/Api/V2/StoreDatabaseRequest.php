<?php

namespace App\Http\Requests\Api\V2;

use App\Services\Databases\DatabaseService;

/**
 * POST /users/{user}/databases. Names are the short names, without the owner prefix.
 */
final class StoreDatabaseRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The database name without the owner prefix. */
            'name' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/'],
            /** The database user without the owner prefix. */
            'db_user' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_]+$/'],
            'password' => ['required', 'string', 'max:256', 'regex:'.DatabaseService::PASSWORD_RULE],
            /** mysql or pgsql. The first type of the server when left out. */
            'type' => ['sometimes', 'string', 'max:16', 'regex:/^[a-z]+$/'],
            /** A database host. The first one when left out. */
            'host' => ['sometimes', 'string', 'max:253', 'regex:/^[\w.:-]+$/'],
            'charset' => ['sometimes', 'string', 'in:'.implode(',', DatabaseService::CHARSETS)],
            /** An address that receives the database settings. */
            'send_credentials_to' => ['sometimes', 'nullable', 'email', 'max:255'],
        ];
    }
}
