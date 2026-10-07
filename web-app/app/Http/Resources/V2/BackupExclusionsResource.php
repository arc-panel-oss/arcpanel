<?php

namespace App\Http\Resources\V2;

use App\Services\Backups\BackupExclusions;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Backup exclusions of a user (`v-list-user-backup-exclusions USER json`).
 *
 * @property BackupExclusions $resource
 */
final class BackupExclusionsResource extends JsonResource
{
    /** @return array<string, list<string>> */
    public function toArray(Request $request): array
    {
        $exclusions = $this->resource;

        return [
            'web' => $exclusions->get('WEB'),
            'dns' => $exclusions->get('DNS'),
            'mail' => $exclusions->get('MAIL'),
            'db' => $exclusions->get('DB'),
            'cron' => $exclusions->get('CRON'),
            'user' => $exclusions->get('USER'),
        ];
    }
}
