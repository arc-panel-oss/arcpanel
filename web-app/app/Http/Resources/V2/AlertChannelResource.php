<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An alert channel of a scope (`v-list-alert-channels SCOPE json`, E16
 * §3.2). The token or webhook URL is write-only: only `secret_set` says
 * whether one is stored.
 *
 * @property array<string, string> $resource
 */
final class AlertChannelResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $row = $this->resource;
        $status = (string) ($row['STATUS'] ?? '');

        return [
            /** @var string 14 digits */
            'id' => (string) ($row['ID'] ?? ''),
            'name' => (string) ($row['NAME'] ?? ''),
            /** @var string `email`, `telegram`, `slack`, `discord`, `mattermost` or `panel` */
            'type' => (string) ($row['TYPE'] ?? ''),
            /** @var string email: the addresses, comma-separated; telegram: the chat id; else empty */
            'target' => (string) ($row['TARGET'] ?? ''),
            'enabled' => ($row['ENABLED'] ?? '') === 'yes',
            /** @var bool whether a bot token or webhook URL is stored */
            'secret_set' => ($row['SECRET_SET'] ?? '') === 'yes',
            /** @var string|null the error of the last delivery, null when it worked */
            'error' => $status === '' || $status === 'ok' ? null : $status,
            /** @var int failed deliveries in a row; ten turn the channel off */
            'failures' => (int) ($row['FAILS'] ?? 0),
            /** @var string|null date and time of the last message sent, server time */
            'last_sent_at' => ($row['LAST_SENT'] ?? '') !== '' ? str_replace(' ', 'T', (string) $row['LAST_SENT']) : null,
        ];
    }
}
