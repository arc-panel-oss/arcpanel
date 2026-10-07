<?php

namespace App\Http\Requests\Api\V2;

/**
 * PUT /users/{user}/cron-reports.
 */
final class SetCronReportsRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
        ];
    }
}
