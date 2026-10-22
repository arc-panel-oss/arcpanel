<?php

namespace App\Services\Files;

use App\Arc\ArcCommand;
use App\Arc\Secret;
use App\Arc\SysConfig;

/**
 * Upload from URL (docs/features/specs/2026-10-22-001-e3-upload-from-url-design.md
 * §6.3): the fetches of an account, one method per command. The request
 * (URL, headers, credentials) reaches v-add-fs-fetch as a Secret file, so
 * it is never part of a command line or a log.
 */
final class RemoteFetches
{
    /** Default of FILE_FETCH_MAX_SIZE */
    public const MAX_DEFAULT = '2G';

    public function __construct(
        private readonly ArcCommand $arc,
        private readonly FileService $files,
        private readonly SysConfig $config,
    ) {}

    /** Whether FILE_FETCH lets accounts fetch (D11; on unless 'no') */
    public function enabled(): bool
    {
        return $this->config->get('FILE_FETCH') !== 'no';
    }

    /** FILE_FETCH_MAX_SIZE in bytes */
    public function maxBytes(): int
    {
        return UploadSessions::bytes($this->config->get('FILE_FETCH_MAX_SIZE'), self::MAX_DEFAULT);
    }

    /** @return list<FileFetch> newest first */
    public function list(string $user): array
    {
        $home = $this->files->account($user)->home;
        $rows = $this->arc->json('v-list-fs-fetches', [$user]);

        return array_values(array_map(fn ($row) => FileFetch::from((array) $row, $home), array_filter($rows, 'is_array')));
    }

    public function find(string $user, string $id): ?FileFetch
    {
        foreach ($this->list($user) as $fetch) {
            if ($fetch->id === $id) {
                return $fetch;
            }
        }

        return null;
    }

    /** Queues a fetch into DIR and returns its ID. */
    public function start(string $user, FilePath $dir, FetchRequest $request, string $onConflict = 'rename', bool $private = false): string
    {
        return trim($this->arc->run('v-add-fs-fetch', [
            $user,
            $this->files->absolute($user, $dir),
            Secret::file($request->toRequestFile()),
            $onConflict,
            $private ? 'yes' : 'no',
        ])->stdout);
    }

    /** Cancels a waiting or running fetch, or removes a finished one. */
    public function delete(string $user, string $id): void
    {
        $this->arc->run('v-delete-fs-fetch', [$user, $id]);
    }
}
