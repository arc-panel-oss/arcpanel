<?php

namespace App\Http\Resources\V2;

use App\Services\Keys\UserSecretManager;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A secret of the secret store (`v-list-user-secret-store` or
 * `v-list-user-secret USER NAME json`, E38): never its value. The detail
 * adds the kept versions and the apps that reference it.
 *
 * @property array<string, mixed> $resource
 */
final class UserSecretResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $row = $this->resource;
        $name = (string) ($row['NAME'] ?? '');
        $data = [
            /** @var string the path name, for example `/shop/db_password` */
            'name' => $name,
            'description' => (string) ($row['DESCRIPTION'] ?? ''),
            /** @var int the current version */
            'version' => (int) ($row['VERSION'] ?? 0),
            /** @var string 8 hex characters of a keyed hash of the value: equal values of one user have equal fingerprints */
            'fingerprint' => (string) ($row['FINGERPRINT'] ?? ''),
            /** @var string what an app environment writes to use the secret */
            'reference' => UserSecretManager::reference($name),
            /** @var string date and time of the current version, server time */
            'updated_at' => self::timestamp($row),
        ];
        if (is_array($row['USED_BY'] ?? null)) {
            /** @var list<array{version: int, fingerprint: string, created_at: string}> the kept versions, oldest first (at most five) */
            $data['versions'] = array_map(fn (array $version) => [
                'version' => (int) ($version['VERSION'] ?? 0),
                'fingerprint' => (string) ($version['FINGERPRINT'] ?? ''),
                'created_at' => self::timestamp($version),
            ], array_values(array_filter((array) ($row['VERSIONS'] ?? []), 'is_array')));
            /** @var list<array{type: string, id: string}> the apps whose environment references the secret: `node` (id: web domain), `docker` (id: app name) or `app`, an app process (id: web domain/process name) */
            $data['used_by'] = self::apps(array_map('strval', $row['USED_BY']));
        } else {
            /** @var int how many apps reference the secret */
            $data['used_by_count'] = (int) ($row['USED_BY'] ?? 0);
        }

        return $data;
    }

    /**
     * node:DOMAIN, docker:NAME and app:DOMAIN/NAME words as objects.
     *
     * @param  list<string>  $words
     * @return list<array{type: string, id: string}>
     */
    public static function apps(array $words): array
    {
        $apps = [];
        foreach ($words as $word) {
            if (preg_match('/^(node|docker|app):(\S+)$/', $word, $m) === 1) {
                $apps[] = ['type' => $m[1], 'id' => $m[2]];
            }
        }

        return $apps;
    }

    /** @param  array<string, mixed>  $row */
    private static function timestamp(array $row): string
    {
        return ($row['DATE'] ?? '') === '' ? '' : $row['DATE'].'T'.($row['TIME'] ?? '00:00:00');
    }
}
