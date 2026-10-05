<?php

namespace App\Http\Requests\Databases;

use App\Services\Databases\DatabaseService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Edit a database (web/edit/db): the user name without prefix and an
 * optional new password.
 */
final class UpdateDatabaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'dbuser' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_]+$/'],
            'password' => ['nullable', 'string', 'max:256', 'regex:'.DatabaseService::PASSWORD_RULE],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'password.regex' => __('Password does not match the minimum requirements.'),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['dbuser' => __('Username'), 'password' => __('Password')];
    }
}
