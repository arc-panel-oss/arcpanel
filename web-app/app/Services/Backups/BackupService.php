<?php

namespace App\Services\Backups;

use App\Arc\ArcCommand;
use App\Arc\CommandResult;
use App\Arc\Options;
use App\Arc\Secret;
use App\Services\Support\BulkResult;
use App\Services\Support\BulkRunner;
use App\Services\Support\ObjectLookup;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Backups through the v-* commands, as the legacy pages call them
 * (web/list/backup, web/list/backup/incremental, web/edit/backup/exclusions,
 * web/schedule/*, web/bulk/backup, web/bulk/restore, web/download/backup).
 * Backups and restores go to the Hestia backup queue (E7 batch 1 spec D6).
 */
final class BackupService
{
    /** Archive names of a user: USER.DATE_TIME.tar[.gz|.zst]. */
    public const NAME_PATTERN = '[A-Za-z0-9][A-Za-z0-9_.-]{0,250}';

    public function __construct(
        private readonly ArcCommand $arc,
        private readonly ObjectLookup $lookup,
        private readonly BulkRunner $runner,
    ) {}

    /** @var array<string, array<string, string>> per-request `v-list-user` records */
    private array $users = [];

    /**
     * Backup keys of the account's package: BACKUPS (archives kept) and
     * BACKUPS_INCREMENTAL. Only `v-list-user` prints the latter.
     *
     * @return array{backups: string, incremental: bool}
     */
    public function settings(string $user): array
    {
        $record = $this->users[$user] ??= array_map('strval', array_filter(
            (array) ($this->arc->json('v-list-user', [$user], Options::allowFailure())[$user] ?? []),
            'is_scalar',
        ));

        return ['backups' => $record['BACKUPS'] ?? '', 'incremental' => ($record['BACKUPS_INCREMENTAL'] ?? 'no') === 'yes'];
    }

    public function incremental(string $user): bool
    {
        return $this->settings($user)['incremental'];
    }

    // Tar archives

    /** @return list<BackupArchive> newest first */
    public function archives(string $user): array
    {
        $archives = [];
        foreach ($this->arc->json('v-list-user-backups', [$user]) as $name => $row) {
            if (is_string($name) && is_array($row)) {
                $archives[] = BackupArchive::fromArray($user, $name, $row);
            }
        }
        usort($archives, fn (BackupArchive $a, BackupArchive $b) => [$b->date, $b->time, $b->name] <=> [$a->date, $a->time, $a->name]);

        return $archives;
    }

    /** One archive of the user; 404 when it is not in the user's list. */
    public function archive(string $user, string $name): BackupArchive
    {
        if (! self::ownsName($user, $name)) {
            throw new NotFoundHttpException;
        }

        return BackupArchive::fromArray($user, $name, $this->lookup->one('v-list-user-backup', [$user, $name], $name));
    }

    /** Whether an archive name belongs to the user (USER.… as v-backup-user names them). */
    public static function ownsName(string $user, string $name): bool
    {
        return preg_match('/^'.self::NAME_PATTERN.'$/', $name) === 1
            && str_starts_with($name, $user.'.')
            && ! str_contains($name, '..');
    }

    /** `v-schedule-user-backup USER`: exit 4 when a backup of the user is already queued. */
    public function schedule(string $user): CommandResult
    {
        return $this->arc->run('v-schedule-user-backup', [$user], Options::allowFailure());
    }

    /**
     * `v-schedule-user-restore USER BACKUP [WEB DNS MAIL DB CRON UDIR]`:
     * everything, or the selected objects of each type (web/schedule/restore,
     * web/bulk/restore); a type without selection is "no".
     *
     * @param  array<string, list<string>>|null  $selection  type => objects; cron takes ["yes"]
     */
    public function restore(string $user, string $backup, ?array $selection = null): CommandResult
    {
        $args = [$user, $backup];
        if ($selection !== null) {
            foreach (BackupArchive::TYPES as $type) {
                $objects = $selection[$type] ?? [];
                $args[] = $type === 'cron'
                    ? ($objects !== [] ? 'yes' : 'no')
                    : ($objects !== [] ? implode(',', $objects) : 'no');
            }
        }

        return $this->arc->run('v-schedule-user-restore', $args, Options::allowFailure());
    }

    /** @param  list<string>  $names */
    public function delete(string $user, array $names): BulkResult
    {
        return $this->runner->run($names, fn (string $name) => [$name, 'v-delete-user-backup', [$user, $name]]);
    }

    /** `v-schedule-user-backup-download USER BACKUP`: fetch a remote archive to the local backup folder. */
    public function scheduleDownload(string $user, string $backup): CommandResult
    {
        return $this->arc->run('v-schedule-user-backup-download', [$user, $backup], Options::allowFailure());
    }

    // Restic snapshots

    /** @return list<ResticSnapshot> newest first */
    public function snapshots(string $user): array
    {
        $snapshots = [];
        foreach ($this->arc->json('v-list-user-backups-restic', [$user], Options::allowFailure()) as $row) {
            if (is_array($row) && ($snapshot = ResticSnapshot::fromArray($row)) !== null) {
                $snapshots[] = $snapshot;
            }
        }
        usort($snapshots, fn (ResticSnapshot $a, ResticSnapshot $b) => ($b->time?->getTimestamp() ?? 0) <=> ($a->time?->getTimestamp() ?? 0));

        return $snapshots;
    }

    /** One snapshot of the list; 404 when the user has no such snapshot. */
    public function snapshot(string $user, string $id): ResticSnapshot
    {
        foreach ($this->snapshots($user) as $snapshot) {
            if ($snapshot->shortId === $id || $snapshot->id === $id) {
                return $snapshot;
            }
        }

        throw new NotFoundHttpException;
    }

    /**
     * What a snapshot holds: the backup.conf stored in it (`v-list-user-backup-restic
     * USER SNAPSHOT json`). Its only entry is keyed by the backup name, not by
     * "snapshot" as templates/pages/list_backup_detail_incremental.php reads it.
     */
    public function snapshotContent(string $user, string $id): BackupArchive
    {
        return BackupArchive::fromArray($user, $id, $this->lookup->one('v-list-user-backup-restic', [$user, $id]));
    }

    /** `v-schedule-user-backup-restic USER` */
    public function scheduleSnapshot(string $user): CommandResult
    {
        return $this->arc->run('v-schedule-user-backup-restic', [$user], Options::allowFailure());
    }

    /** `v-schedule-user-restore-restic USER SNAPSHOT [OBJECT VALUE]`: everything, or one web, dns, mail or db object, or the cron jobs. */
    public function restoreSnapshot(string $user, string $id, ?string $object = null, string $value = ''): CommandResult
    {
        $args = [$user, $id];
        if ($object !== null) {
            $args[] = $object;
            $args[] = $object === 'cron' ? 'yes' : $value;
        }

        return $this->arc->run('v-schedule-user-restore-restic', $args, Options::allowFailure());
    }

    /** `v-delete-user-backup-restic USER SNAPSHOT` (restic forget, at once). */
    public function deleteSnapshot(string $user, string $id): CommandResult
    {
        return $this->arc->run('v-delete-user-backup-restic', [$user, $id], Options::allowFailure());
    }

    /** A folder of a snapshot, for the file manager's snapshot mode. */
    public function snapshotFolder(string $user, string $id, string $path): SnapshotListing
    {
        $result = $this->arc->run('v-list-user-files-restic', [$user, $id, $path], Options::allowFailure());
        if (! $result->ok()) {
            throw new NotFoundHttpException;
        }

        return SnapshotListing::parse($result->stdout, $path);
    }

    /** `v-schedule-user-restore-file-restic USER SNAPSHOT PATH` (E7 batch 1 spec §10.4). */
    public function restoreFile(string $user, string $id, string $path): CommandResult
    {
        return $this->arc->run('v-schedule-user-restore-file-restic', [$user, $id, $path], Options::allowFailure());
    }

    // Exclusions

    public function exclusions(string $user): BackupExclusions
    {
        return BackupExclusions::fromJson($this->arc->json('v-list-user-backup-exclusions', [$user]));
    }

    public function saveExclusions(string $user, BackupExclusions $exclusions): CommandResult
    {
        return $this->arc->run('v-update-user-backup-exclusions', [$user, Secret::file($exclusions->toFile())], Options::allowFailure());
    }
}
