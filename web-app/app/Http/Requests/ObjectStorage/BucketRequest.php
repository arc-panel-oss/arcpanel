<?php

namespace App\Http\Requests\ObjectStorage;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The Add bucket and Edit bucket forms of the Object Storage page (E44 spec
 * §11); the name only when adding. v-add-user-bucket and
 * v-change-user-bucket check the package limits.
 */
final class BucketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => [$this->route('bucket') === null ? 'required' : 'prohibited', 'string', 'regex:/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/'],
            'quota' => ['required', 'integer', 'min:1', 'max:999999999'],
            'versioning' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => __('Bucket name'), 'quota' => __('Quota (MB)')];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['name.regex' => __('Use 3 to 63 lower case letters, digits and hyphens, with a letter or digit at both ends.')];
    }
}
