<?php

namespace App\Http\Requests\Cron;

/**
 * Add a cron job (web/add/cron), for the chosen owner.
 */
final class StoreCronJobRequest extends CronJobRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'owner' => ['nullable', 'string', 'max:32', 'regex:/^[[:alnum:]][-.\w]*$/'],
            ...parent::rules(),
        ];
    }
}
