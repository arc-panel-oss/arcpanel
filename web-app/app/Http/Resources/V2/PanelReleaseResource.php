<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A release of the signed release manifest that is newer than the installed
 * version (`v-list-sys-arcpanel-releases json`, E33 phase 1 spec §5.4, §7.2).
 *
 * @property array<string, mixed> $resource
 */
final class PanelReleaseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $row = $this->resource;
        $engines = is_array($row['ENGINES'] ?? null) ? array_map('strval', $row['ENGINES']) : [];
        $withdrawn = is_array($row['WITHDRAWN'] ?? null) ? $row['WITHDRAWN'] : null;

        return [
            /** @var string runUpdates takes it as `version` */
            'version' => (string) ($row['VERSION'] ?? ''),
            /** @var string the release line, such as `2.5` */
            'line' => (string) ($row['LINE'] ?? ''),
            /** @var string the release date, `YYYY-MM-DD` */
            'date' => (string) ($row['DATE'] ?? ''),
            /** @var string `patch`, `minor` or `major`, from the installed version */
            'kind' => (string) ($row['KIND'] ?? ''),
            /** @var bool the release fixes a security issue; the update delay does not apply */
            'security' => ($row['SECURITY'] ?? false) === true,
            /** @var bool the release needs the administrator's attention: runUpdates needs `confirm_attention` */
            'attention' => ($row['ATTENTION'] ?? false) === true,
            /** @var list<string> what the administrator should know before updating */
            'attention_items' => array_values(array_map('strval', is_array($row['ATTENTION_ITEMS'] ?? null) ? $row['ATTENTION_ITEMS'] : [])),
            /** @var bool the release has upgrade steps, so an update to it cannot be rolled back */
            'steps' => ($row['STEPS'] ?? false) === true,
            /** @var string|null the oldest version that may update to this release directly */
            'min_from' => is_string($row['MIN_FROM'] ?? null) ? $row['MIN_FROM'] : null,
            /** @var array<string, string> the engine packages of the release and their versions */
            'engines' => (object) $engines,
            /** @var string|null the release notes */
            'notes' => is_string($row['NOTES'] ?? null) && $row['NOTES'] !== '' ? $row['NOTES'] : null,
            /** @var array{date: string, reason: string}|null set when the release was withdrawn; it cannot be installed */
            'withdrawn' => $withdrawn !== null ? ['date' => (string) ($withdrawn['date'] ?? ''), 'reason' => (string) ($withdrawn['reason'] ?? '')] : null,
            /** @var bool APT offers the release for this server's distribution and architecture */
            'available' => ($row['AVAILABLE'] ?? false) === true,
            /** @var bool the update policy would install the release now */
            'eligible' => ($row['ELIGIBLE'] ?? false) === true,
            /** @var string|null why the policy does not install it: `attention`, `withdrawn`, `delay until <date>`, `outside window`, `paused`, `min_from <version>`, `policy`, `not available` or `rolled back` (an update to it was rolled back on this server) */
            'reason' => is_string($row['REASON'] ?? null) && $row['REASON'] !== '' ? $row['REASON'] : null,
        ];
    }
}
