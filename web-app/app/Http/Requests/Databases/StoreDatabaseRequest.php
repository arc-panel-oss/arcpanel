<?php

namespace App\Http\Requests\Databases;

use App\Services\Databases\DatabaseService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add a database (web/add/db). Names are given without the owner's prefix;
 * v-add-database adds it and checks the final length and characters.
 */
final class StoreDatabaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'owner' => ['nullable', 'string', 'max:32', 'regex:/^[[:alnum:]][-.\w]*$/'],
            'database' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/'],
            'dbuser' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_]+$/'],
            'password' => ['required', 'string', 'max:256', 'regex:'.DatabaseService::PASSWORD_RULE],
            'email' => ['nullable', 'email:filter', 'max:254'],
            'type' => ['required', 'string', 'max:16', 'regex:/^[a-z]+$/'],
            'host' => ['required', 'string', 'max:253', 'regex:/^[\w.:-]+$/'],
            'charset' => ['required', Rule::in(DatabaseService::CHARSETS)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'password.regex' => __('Password does not match the minimum requirements.'),
            'email.email' => __('Please enter a valid email address.'),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'database' => __('Database'),
            'dbuser' => __('Username'),
            'password' => __('Password'),
            'type' => __('Type'),
            'host' => __('Host'),
            'charset' => __('Charset'),
        ];
    }
}
