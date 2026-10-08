<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A notification of the key owner (`v-list-user-notifications USER json`),
 * with plain text title and body as the panel's tray shows them.
 *
 * @property array{id: string, title: string, body: string, acknowledged: bool, date: string, time: string} $resource
 */
final class NotificationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** @var int */
            'id' => (int) $this->resource['id'],
            /** @var string plain text */
            'title' => $this->resource['title'],
            /** @var string plain text; links of the notice are dropped */
            'body' => $this->resource['body'],
            /** @var bool */
            'acknowledged' => $this->resource['acknowledged'],
            /** @var string|null in the server's time zone, YYYY-MM-DDTHH:MM:SS */
            'created_at' => UserResource::localTime($this->resource['date'], $this->resource['time']),
        ];
    }
}
