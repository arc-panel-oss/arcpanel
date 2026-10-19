<?php

namespace App\Http\Requests\ObjectStorage;

use App\Services\Keys\UserSecretManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The Add S3 key form of the Object Storage page (E44 spec §7, §11).
 */
final class S3KeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9 _.-]{0,31}$/'],
            'bucket' => ['nullable', 'string', 'regex:/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/'],
            'access' => ['required', Rule::in(['rw', 'ro'])],
            'expires' => ['nullable', 'date_format:Y-m-d', 'after:today'],
            'save_as' => ['nullable', 'string', 'max:128', 'regex:'.UserSecretManager::NAME_PATTERN],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => __('Key name'), 'expires' => __('Expires on'), 'save_as' => __('Secret name')];
    }
}
