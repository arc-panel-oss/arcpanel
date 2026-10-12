<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A backup of a php.ini (`v-list-sys-php-ini-backups VERSION SAPI json`,
 * E41 §6.1); the ten newest are kept.
 *
 * @property array{id: string, date: string, size: int} $resource
 */
final class PhpIniBackupResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** @var string the id the restore path takes, `YYYYmmdd-HHMMSS` */
            'id' => $this->resource['id'],
            /** @var string `YYYY-MM-DD HH:MM:SS` */
            'created_at' => $this->resource['date'],
            /** @var int bytes */
            'size' => $this->resource['size'],
        ];
    }
}
