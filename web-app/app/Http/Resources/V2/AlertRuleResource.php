<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An alert rule of a scope (`v-list-alert-rules SCOPE json`, E16 §4): its
 * line of rules.conf or the catalog default.
 *
 * @property array<string, string> $resource
 */
final class AlertRuleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $row = $this->resource;

        return [
            /** @var string the rule type of the catalog, for example `disk.usage` */
            'rule' => (string) ($row['RULE'] ?? ''),
            'title' => (string) ($row['TITLE'] ?? ''),
            /** @var string `metric` (thresholds, resolves) or `event` */
            'kind' => (string) ($row['KIND'] ?? ''),
            'enabled' => ($row['ENABLED'] ?? '') === 'yes',
            /** @var int|float|null the warning threshold of a metric rule */
            'warning' => AlertResources::number((string) ($row['WARNING'] ?? '')),
            /** @var int|float|null the critical threshold, null when the rule has one level */
            'critical' => AlertResources::number((string) ($row['CRITICAL'] ?? '')),
            /** @var string the unit of the thresholds: `%`, `per core`, `days` or empty */
            'unit' => (string) ($row['UNIT'] ?? ''),
            /** @var string the thresholds the rule takes: `both`, `warning`, `below` (lower is worse) or `none` */
            'levels' => (string) ($row['LEVELS'] ?? ''),
            /** @var int minutes the condition must hold before a metric rule fires */
            'for' => (int) ($row['FOR'] ?? 0),
            /** @var string|null the lowest severity an event rule sends: `info`, `warning` or `critical` */
            'severity' => ($row['SEVERITY'] ?? '') !== '' ? (string) $row['SEVERITY'] : null,
            /** @var int minutes between two messages of one open alert or event object */
            'cooldown' => (int) ($row['COOLDOWN'] ?? 1440),
            /** @var list<string> the channel ids of the scope */
            'channels' => AlertResources::ids((string) ($row['CHANNELS'] ?? '')),
            /** @var string the services or mount points to watch, comma-separated; empty for all */
            'options' => (string) ($row['OPTIONS'] ?? ''),
            /** @var int minutes between two evaluations of a metric rule */
            'interval' => (int) ($row['INTERVAL'] ?? 1),
            /** @var bool false while the scope uses the catalog default */
            'custom' => ($row['CUSTOM'] ?? '') === 'yes',
        ];
    }
}
