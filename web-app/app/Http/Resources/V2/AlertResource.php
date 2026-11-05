<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An open alert (`v-list-alerts SCOPE json`, E16 §3.3): a metric rule that
 * fired and has not resolved.
 *
 * @property array<string, string> $resource
 */
final class AlertResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $row = $this->resource;

        return [
            /** @var string 14 digits; the id of its history entries */
            'id' => (string) ($row['ID'] ?? ''),
            'rule' => (string) ($row['RULE'] ?? ''),
            'title' => (string) ($row['TITLE'] ?? ''),
            /** @var string `system` or the user of the scope */
            'scope' => (string) ($row['SCOPE'] ?? ''),
            /** @var string what the alert is about: `mount:/home`, `service:nginx`, `web:example.com`, `user:alice`, `system` */
            'object' => (string) ($row['OBJECT'] ?? ''),
            /** @var string `warning` or `critical` */
            'severity' => (string) ($row['SEVERITY'] ?? ''),
            /** @var string the value against the thresholds, for example `93% (warning 90%, critical 95%)` */
            'value' => (string) ($row['VALUE'] ?? ''),
            'detail' => (string) ($row['DETAIL'] ?? ''),
            /** @var string when the condition was first seen, server time */
            'since' => str_replace(' ', 'T', (string) ($row['SINCE'] ?? '')),
            /** @var string when the first message went out, server time */
            'fired_at' => str_replace(' ', 'T', (string) ($row['FIRED'] ?? '')),
            /** @var string|null */
            'last_notified_at' => ($row['LAST_NOTIFIED'] ?? '') !== '' ? str_replace(' ', 'T', (string) $row['LAST_NOTIFIED']) : null,
            /** @var int messages sent for the alert */
            'count' => (int) ($row['COUNT'] ?? 0),
        ];
    }
}
