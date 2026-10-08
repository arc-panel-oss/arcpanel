<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An entry of a user's action log (`v-list-user-log USER json LINES`).
 *
 * @property array{date: string, time: string, level: string, category: string, message: string} $resource
 */
final class LogEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** @var string|null in the server's time zone, YYYY-MM-DDTHH:MM:SS */
            'time' => UserResource::localTime($this->resource['date'], $this->resource['time']),
            /** @var string `Info`, `Warning` or `Error` */
            'level' => $this->resource['level'],
            /** @var string the area of the panel, for example `Web` or `System` */
            'category' => $this->resource['category'],
            /** @var string plain text as the command logged it */
            'message' => $this->resource['message'],
        ];
    }
}
