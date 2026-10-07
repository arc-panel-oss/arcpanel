<?php

namespace App\Http\Resources\V2;

use App\Services\Databases\DatabaseRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A database (`v-list-database USER DATABASE json`).
 *
 * @property DatabaseRecord $resource
 */
final class DatabaseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $database = $this->resource;

        return [
            /** @var string the full name, with the owner prefix */
            'name' => $database->database,
            /** @var string the name without the owner prefix */
            'short_name' => $database->shortName(),
            /** @var string the owner */
            'user' => $database->user,
            /** @var string the full database user name */
            'db_user' => $database->dbUser,
            /** @var string */
            'type' => $database->type,
            /** @var string */
            'host' => $database->host,
            /** @var string */
            'charset' => $database->charset,
            /** @var int */
            'disk_mb' => $database->diskMb,
            /** @var bool */
            'suspended' => $database->suspended,
            /** @var string|null creation time in the server's time zone, YYYY-MM-DDTHH:MM:SS */
            'created_at' => UserResource::localTime($database->date, $database->time),
        ];
    }
}
