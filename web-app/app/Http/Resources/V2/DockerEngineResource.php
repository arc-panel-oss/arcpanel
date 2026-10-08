<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Docker engine of the server (`v-list-sys-docker`). */
final class DockerEngineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $status */
        $status = $this->resource;
        $report = is_array($status['report'] ?? null) ? $status['report'] : [];
        $findings = [];
        foreach ((array) ($report['findings'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $findings[] = [
                'user' => (string) ($row['user'] ?? ''),
                'app' => (string) ($row['app'] ?? ''),
                'type' => (string) ($row['type'] ?? ''),
                'detail' => (string) ($row['detail'] ?? ''),
                'repaired' => ($row['repaired'] ?? '') === 'yes',
            ];
        }
        $time = (string) ($report['time'] ?? '');

        return [
            'installed' => (bool) ($status['installed'] ?? false),
            'running' => (bool) ($status['running'] ?? false),
            'version' => ($status['version'] ?? '') !== '' ? (string) $status['version'] : null,
            'compose_version' => ($status['composeVersion'] ?? '') !== '' ? (string) $status['composeVersion'] : null,
            'data_root' => ($status['dataRoot'] ?? '') !== '' ? (string) $status['dataRoot'] : null,
            'disk_used' => (int) ($status['diskUsed'] ?? 0),
            'disk_free' => (int) ($status['diskFree'] ?? 0),
            'images' => (int) ($status['images'] ?? 0),
            'containers' => (int) ($status['containers'] ?? 0),
            'apps' => (int) ($status['apps'] ?? 0),
            'users' => (int) ($status['users'] ?? 0),
            'report' => [
                'time' => $time !== '' ? UserResource::localTime(...self::reportParts($time)) : null,
                'findings' => $findings,
            ],
        ];
    }

    /** @return array{0: string, 1: string} */
    private static function reportParts(string $time): array
    {
        $parts = explode(' ', $time, 2);

        return [$parts[0] ?? '', $parts[1] ?? '00:00:00'];
    }
}
