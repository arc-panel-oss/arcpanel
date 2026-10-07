<?php

namespace App\Http\Requests\Api\V2;

/**
 * PATCH /users/{user}/cron-jobs/{id}. Fields left out keep their value.
 */
final class UpdateCronJobRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'schedule' => ['sometimes', 'string', 'max:640'],
            'command' => ['sometimes', 'string', 'max:4096', 'not_regex:/[\r\n\x00]/'],
        ];
    }
}
