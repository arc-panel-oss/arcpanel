<?php

namespace App\Http\Resources\V2;

use App\Services\Backups\BackupArchive;
use App\Services\Backups\ResticSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A restic snapshot (`v-list-user-backups-restic USER json`).
 *
 * @property ResticSnapshot $resource
 */
final class SnapshotResource extends JsonResource
{
    public function __construct(
        ResticSnapshot $snapshot,
        private readonly ?BackupArchive $content = null,
    ) {
        parent::__construct($snapshot);
    }

    /** @param  iterable<ResticSnapshot>  $resource */
    public static function collection($resource): AnonymousResourceCollection
    {
        $items = $resource instanceof \Illuminate\Pagination\AbstractPaginator
            ? $resource->getCollection()
            : collect($resource);

        return new AnonymousResourceCollection(
            $items->map(fn (ResticSnapshot $snapshot) => new self($snapshot)),
            self::class,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $snapshot = $this->resource;

        $data = [
            /** @var string full restic snapshot id */
            'id' => $snapshot->id,
            /** @var string short restic snapshot id */
            'short_id' => $snapshot->shortId,
            /** @var string|null creation time in the server's time zone, YYYY-MM-DDTHH:MM:SS */
            'created_at' => $snapshot->time !== null
                ? UserResource::localTime($snapshot->time->format('Y-m-d'), $snapshot->time->format('H:i:s'))
                : null,
            /** @var string hostname recorded in the snapshot */
            'hostname' => $snapshot->hostname,
            /** @var list<string> backed-up paths */
            'paths' => $snapshot->paths,
        ];

        if ($this->content !== null) {
            $data['content'] = self::content($this->content);
        }

        return $data;
    }

    /** @return array{web: list<string>, dns: list<string>, mail: list<string>, db: list<string>, udir: list<string>, cron: bool} */
    public static function content(BackupArchive $archive): array
    {
        return [
            'web' => $archive->items['web'],
            'dns' => $archive->items['dns'],
            'mail' => $archive->items['mail'],
            'db' => $archive->items['db'],
            'udir' => $archive->items['udir'],
            'cron' => $archive->items['cron'] !== [],
        ];
    }
}
