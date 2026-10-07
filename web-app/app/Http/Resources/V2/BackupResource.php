<?php

namespace App\Http\Resources\V2;

use App\Services\Backups\BackupArchive;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A tar backup archive (`v-list-user-backup USER BACKUP json`).
 *
 * @property BackupArchive $resource
 */
final class BackupResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $archive = $this->resource;

        return [
            /** @var string */
            'name' => $archive->name,
            /** @var string */
            'type' => self::typeFromName($archive->name),
            /** @var int */
            'size_mb' => $archive->sizeMb,
            /** @var string|null creation time in the server's time zone, YYYY-MM-DDTHH:MM:SS */
            'created_at' => UserResource::localTime($archive->date, $archive->time),
            /** @var int */
            'runtime_seconds' => $archive->runtime,
            /** @var array{web: list<string>, dns: list<string>, mail: list<string>, db: list<string>, udir: list<string>, cron: bool} */
            'content' => [
                'web' => $archive->items['web'],
                'dns' => $archive->items['dns'],
                'mail' => $archive->items['mail'],
                'db' => $archive->items['db'],
                'udir' => $archive->items['udir'],
                'cron' => $archive->items['cron'] !== [],
            ],
        ];
    }

    public static function typeFromName(string $name): string
    {
        return match (true) {
            str_ends_with($name, '.tar.gz'), str_ends_with($name, '.tgz') => 'tar.gz',
            str_ends_with($name, '.tar.zst') => 'tar.zst',
            default => 'tar',
        };
    }
}
