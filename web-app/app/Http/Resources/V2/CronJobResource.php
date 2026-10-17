<?php

namespace App\Http\Resources\V2;

use App\Api\CronSchedule;
use App\Services\Cron\CronJobRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A cron job (`v-list-cron-job USER JOB json`).
 *
 * @property CronJobRecord $resource
 */
final class CronJobResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $job = $this->resource;

        return [
            /** @var int */
            'id' => ctype_digit($job->job) ? (int) $job->job : $job->job,
            /** @var string the owner */
            'user' => $job->user,
            /** @var string five crontab fields */
            'schedule' => CronSchedule::format([
                'min' => $job->min,
                'hour' => $job->hour,
                'day' => $job->day,
                'month' => $job->month,
                'wday' => $job->wday,
            ]),
            /** @var string */
            'command' => $job->command,
            /** @var bool */
            'suspended' => $job->suspended,
            /** @var string|null creation time in the server's time zone, YYYY-MM-DDTHH:MM:SS */
            'created_at' => UserResource::localTime($job->date, $job->time),
        ];
    }
}
