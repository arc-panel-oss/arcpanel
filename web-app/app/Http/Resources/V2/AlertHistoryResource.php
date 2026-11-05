<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry of the alert history (a row of `alerts_history`, E16 §7): an
 * alert from firing to resolved, or one event, with its deliveries.
 *
 * @property object $resource
 */
final class AlertHistoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $row = $this->resource;
        $deliveries = json_decode((string) $row->channels, true);

        return [
            /** @var string 14 digits */
            'id' => sprintf('%014d', (int) $row->id),
            'rule' => (string) $row->rule,
            /** @var string `system` or the user of the scope */
            'scope' => $row->user ?? 'system',
            'object' => (string) $row->object,
            /** @var string `info`, `warning` or `critical` */
            'severity' => (string) $row->severity,
            /** @var string `firing` while a metric alert is open, else `resolved` */
            'state' => (string) $row->state,
            /** @var string|null the value, or the job or run id of an event */
            'value' => $row->value,
            'message' => (string) $row->message,
            /** @var string server time */
            'fired_at' => str_replace(' ', 'T', (string) $row->fired_at),
            /** @var string|null */
            'resolved_at' => $row->resolved_at !== null ? str_replace(' ', 'T', (string) $row->resolved_at) : null,
            /** @var int messages sent */
            'count' => (int) $row->notify_count,
            /** @var list<array{time: string, event: string, channel: string, name: string, type: string, result: string, error: string}> each delivery: `result` is `sent`, `failed`, `dropped` (after the retries), `skipped` or `limited` */
            'deliveries' => is_array($deliveries) ? array_values($deliveries) : [],
        ];
    }
}
