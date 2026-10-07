<?php

namespace App\Http\Requests\Api\V2;

/**
 * POST /users/{user}/cron-jobs.
 */
final class StoreCronJobRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Five crontab fields, for example `*\/5 * * * *`. */
            'schedule' => ['required', 'string', 'max:640'],
            'command' => ['required', 'string', 'max:4096', 'not_regex:/[\r\n\x00]/'],
        ];
    }
}
