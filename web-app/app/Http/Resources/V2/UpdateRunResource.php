<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A run of the update history (`v-list-sys-update-history json`, E33 phase 1
 * spec §7.1, §7.8).
 *
 * @property array<string, mixed> $resource
 */
final class UpdateRunResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $row = $this->resource;
        $time = fn (string $key) => is_string($row[$key] ?? null) && $row[$key] !== '' ? str_replace(' ', 'T', $row[$key]) : null;
        $task = is_string($row['TASK'] ?? null) && preg_match('/^\d{14}$/', $row['TASK']) === 1 ? 'sys:'.$row['TASK'] : null;

        return [
            /** @var int rollbackPanelUpdate takes it */
            'id' => (int) ($row['ID'] ?? 0),
            /** @var string the version before the run */
            'from' => (string) ($row['FROM'] ?? ''),
            /** @var string the target version */
            'to' => (string) ($row['TO'] ?? ''),
            /** @var string `policy` (the hourly runner), `admin` (the Updates page) or `api` */
            'trigger' => (string) ($row['TRIGGER'] ?? ''),
            /** @var string|null the user or access key that started it */
            'actor' => is_string($row['ACTOR'] ?? null) && $row['ACTOR'] !== '' ? $row['ACTOR'] : null,
            /** @var string|null in the server's time zone */
            'started_at' => $time('START'),
            /** @var string|null in the server's time zone */
            'ended_at' => $time('END'),
            /** @var string `running`, `done`, `failed`, `rolled-back`, `paused` or `skipped` */
            'result' => (string) ($row['RESULT'] ?? ''),
            /** @var string|null the failed checks, or why the run was skipped */
            'reason' => is_string($row['REASON'] ?? null) && $row['REASON'] !== '' ? $row['REASON'] : null,
            /** @var string|null the system task of the run (getTask) */
            'task' => $task,
            /** @var bool rollbackPanelUpdate is allowed: only the newest update can be rolled back */
            'rollback' => ($row['ROLLBACK'] ?? '') === 'yes',
            /** @var string|null why the newest update cannot be rolled back */
            'rollback_reason' => is_string($row['ROLLBACK_REASON'] ?? null) && $row['ROLLBACK_REASON'] !== '' ? $row['ROLLBACK_REASON'] : null,
        ];
    }
}
