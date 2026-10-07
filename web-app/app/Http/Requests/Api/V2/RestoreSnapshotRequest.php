<?php

namespace App\Http\Requests\Api\V2;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /users/{user}/snapshots/{snapshot}/restore.
 */
final class RestoreSnapshotRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['sometimes', Rule::in(['web', 'dns', 'mail', 'db', 'cron'])],
            'object' => ['nullable', 'required_if:type,web,dns,mail,db', 'string', 'max:255', 'regex:/^[^,\s\'"]+$/'],
            'path' => ['sometimes', 'string', 'max:4096'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->exists('path') && $this->exists('type')) {
                $validator->errors()->add('path', __('Send either a path or a type and object, not both.'));
            }
        });
    }

    public function restoresPath(): bool
    {
        return $this->exists('path');
    }

    public function restoresObject(): bool
    {
        return $this->exists('type');
    }
}
