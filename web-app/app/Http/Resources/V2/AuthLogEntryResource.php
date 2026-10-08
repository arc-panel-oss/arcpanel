<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An entry of a user's login history (`v-list-user-auth-log USER json LINES`).
 *
 * @property array{date: string, time: string, ip: string, success: bool, browser: string} $resource
 */
final class AuthLogEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** @var string|null in the server's time zone, YYYY-MM-DDTHH:MM:SS */
            'time' => UserResource::localTime($this->resource['date'], $this->resource['time']),
            /** @var string the client's address */
            'ip' => $this->resource['ip'],
            /** @var bool whether the login succeeded */
            'success' => $this->resource['success'],
            /** @var string */
            'user_agent' => $this->resource['browser'],
        ];
    }
}
