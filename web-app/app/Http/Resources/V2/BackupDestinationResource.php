<?php

namespace App\Http\Resources\V2;

use App\Services\Backups\BackupDestination;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A backup destination (E48 spec §4): its driver, its fields and the names
 * of its stored secrets, never their values.
 *
 * @property BackupDestination $resource
 */
final class BackupDestinationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $destination = $this->resource;

        return [
            /** @var string */
            'name' => $destination->name,
            /** @var 'local'|'sftp'|'ftp'|'s3'|'b2'|'gdrive'|'dropbox'|'onedrive'|'webdav'|'rclone' */
            'driver' => $destination->driver,
            /** @var string */
            'description' => $destination->description,
            /** @var bool archives and snapshot passwords are encrypted with the destination's key */
            'encrypt' => $destination->encrypt,
            /** @var string|null bandwidth limit of transfers, for example `10M`; null for none */
            'bwlimit' => $destination->bwlimit !== '' ? $destination->bwlimit : null,
            /** @var array<string, string> the fields of the driver, for example `HOST`, `PORT`, `PATH` */
            'fields' => (object) $destination->fields,
            /** @var list<string> the secrets that are stored, for example `PASSWORD`; their values are never returned */
            'secrets' => $destination->secrets,
            /** @var 'ok'|'failing'|'unchecked' */
            'state' => $destination->state,
            /** @var string|null time of the last test of the connection */
            'checked_at' => BackupRunResource::stamp($destination->checked),
            /** @var string|null fingerprint of the pinned SSH host key of an SFTP destination */
            'host_key' => $destination->hostKey !== '' ? $destination->hostKey : null,
            /** @var string|null time the recovery kit of an encrypted destination was last exported; the kit is not in the API */
            'recovery_kit_exported_at' => BackupRunResource::stamp($destination->kit),
            /** @var list<string> the backup jobs that store to it */
            'jobs' => $destination->jobs,
            /** @var string|null */
            'created_at' => BackupRunResource::stamp($destination->created),
        ];
    }
}
