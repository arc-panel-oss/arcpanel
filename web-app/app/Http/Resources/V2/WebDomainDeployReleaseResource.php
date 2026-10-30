<?php

namespace App\Http\Resources\V2;

use App\Api\TaskId;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One release of a web domain's Git Deploy (`v-list-web-domain-deploys
 * USER DOMAIN json`, E20 §3.3).
 *
 * @property array{user: string, row: array<string, string>} $resource
 */
final class WebDomainDeployReleaseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $row = $this->resource['row'];
        $value = fn (string $key): ?string => ($v = trim((string) ($row[$key] ?? ''))) !== '' ? $v : null;
        $created = $value('CREATED');
        $task = $value('TASK');

        return [
            /** @var string `YYYYMMDD-HHMMSS` (UTC), the release to roll back to */
            'id' => (string) ($row['ID'] ?? ''),
            /** @var string|null `pull` or `archive` */
            'source' => $value('SOURCE'),
            /** @var string|null the commit of a pull deploy */
            'commit' => $value('COMMIT'),
            /** @var string|null the ref deployed */
            'ref' => $value('REF'),
            /** @var string|null UTC */
            'created_at' => $created !== null && preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})$/', $created, $m) === 1 ? $m[1].'T'.$m[2].'Z' : null,
            /** @var string|null the task that made it, for `getTask` while the task record is kept */
            'task' => $task !== null && preg_match('/^\d{14}$/', $task) === 1 ? TaskId::queue($this->resource['user'], $task)->apiId() : null,
            /** @var bool whether it is live */
            'current' => ($row['CURRENT'] ?? '') === 'yes',
        ];
    }
}
