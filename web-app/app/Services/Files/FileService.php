<?php

namespace App\Services\Files;

use App\Arc\ArcCommand;
use App\Arc\ArcCommandException;
use App\Arc\ExitCode;
use App\Arc\Options;
use JsonException;

/**
 * Files of a user's home directory (E3 spec §6.1): one method per command
 * through ArcCommand, with paths relative to the home directory. Every
 * command reads and writes as the user; FilePolicy decides who may call
 * what. Copy, move and rename check the destination first and never
 * overwrite unless asked (D12).
 */
final class FileService
{
    /** Bytes the editor opens at most */
    public const EDIT_MAX = 2097152;

    /** Results of a name search at most */
    public const SEARCH_MAX = 500;

    /** Seconds for commands that copy, pack or unpack whole trees */
    private const LONG = 600;

    public const CONFLICT_MODES = ['skip', 'overwrite', 'rename'];

    /** @var array<string, FileAccount> */
    private array $accounts = [];

    public function __construct(
        private readonly ArcCommand $arc,
        private readonly FileStaging $staging,
    ) {}

    public function account(string $user): FileAccount
    {
        return $this->accounts[$user] ??= FileAccount::from($user, (array) ($this->arc->json('v-list-user', [$user])[$user] ?? []));
    }

    public function absolute(string $user, FilePath $path): string
    {
        return $path->absolute($this->account($user)->home);
    }

    /**
     * @return array{total: int, offset: int, entries: list<FileEntry>}
     */
    public function list(string $user, FilePath $dir, int $offset = 0, int $limit = 2000, string $sort = 'name', string $order = 'asc', bool $hidden = true): array
    {
        $data = $this->decode('v-list-fs-directory-json', $this->arc->run('v-list-fs-directory-json', [
            $user, $this->absolute($user, $dir), (string) $offset, (string) $limit, $sort, $order, $hidden ? 'yes' : 'no',
        ])->stdout);

        return [
            'total' => (int) ($data['TOTAL'] ?? 0),
            'offset' => (int) ($data['OFFSET'] ?? 0),
            'entries' => array_values(array_map(fn ($row) => FileEntry::from((array) $row), array_filter((array) ($data['ENTRIES'] ?? []), 'is_array'))),
        ];
    }

    public function info(string $user, FilePath $path): FileEntry
    {
        return FileEntry::from($this->arc->json('v-get-fs-file-info', [$user, $this->absolute($user, $path)]));
    }

    /** null when nothing is there */
    public function find(string $user, FilePath $path): ?FileEntry
    {
        $result = $this->arc->run('v-get-fs-file-info', [$user, $this->absolute($user, $path), 'json'], Options::allowFailure());
        if ($result->exit() === ExitCode::NotExist) {
            return null;
        }
        if (! $result->ok()) {
            throw ArcCommandException::failed($result);
        }

        return FileEntry::from($this->decode('v-get-fs-file-info', $result->stdout));
    }

    /**
     * Editor content. Invalid UTF-8 comes back scrubbed and marked, so the
     * page opens it read-only (E3 spec §7.6).
     *
     * @return array{content: string, hash: string, size: int, mtime: int, mime: string, utf8: bool}
     */
    public function read(string $user, FilePath $path, int $max = self::EDIT_MAX): array
    {
        $stdout = $this->arc->run('v-read-fs-file', [$user, $this->absolute($user, $path), (string) $max])->stdout;
        $newline = strpos($stdout, "\n");
        $header = $this->decode('v-read-fs-file', $newline === false ? $stdout : substr($stdout, 0, $newline));
        $content = $newline === false ? '' : substr($stdout, $newline + 1);
        $utf8 = mb_check_encoding($content, 'UTF-8');

        return [
            'content' => $utf8 ? $content : mb_scrub($content, 'UTF-8'),
            'hash' => (string) ($header['HASH'] ?? ''),
            'size' => (int) ($header['SIZE'] ?? 0),
            'mtime' => (int) ($header['MTIME'] ?? 0),
            'mime' => (string) ($header['MIME'] ?? ''),
            'utf8' => $utf8,
        ];
    }

    /**
     * Save editor content. $hash is the hash read; null creates a new file.
     * A file that changed since it was read fails with E_INUSE (409).
     *
     * @return array{hash: string, size: int, mtime: int}
     */
    public function save(string $user, FilePath $path, string $content, ?string $hash): array
    {
        $token = FileStaging::token();
        $dir = $this->staging->newSessionDir($token);
        try {
            $file = $dir.'/file';
            file_put_contents($file, $content);
            chmod($file, 0600);
            $stdout = $this->arc->run('v-save-fs-file', [$user, $file, $this->absolute($user, $path), $hash ?? ''])->stdout;
        } finally {
            FileStaging::removeTree($dir);
        }
        $header = $this->decode('v-save-fs-file', trim($stdout));

        return ['hash' => (string) ($header['HASH'] ?? ''), 'size' => (int) ($header['SIZE'] ?? 0), 'mtime' => (int) ($header['MTIME'] ?? 0)];
    }

    public function mkdir(string $user, FilePath $path): void
    {
        $this->refuseExisting($user, [$path]);
        $this->arc->run('v-add-fs-directory', [$user, $this->absolute($user, $path)]);
    }

    /** Folders of $path that may already exist (folder uploads). */
    public function ensureDirectory(string $user, FilePath $path): void
    {
        if (! $path->isRoot()) {
            $this->arc->run('v-add-fs-directory', [$user, $this->absolute($user, $path)]);
        }
    }

    public function touch(string $user, FilePath $path): void
    {
        $this->refuseExisting($user, [$path]);
        $this->arc->run('v-add-fs-file', [$user, $this->absolute($user, $path)]);
    }

    public function rename(string $user, FilePath $path, string $name): FilePath
    {
        $target = $path->parent()->child($name);
        $this->refuseExisting($user, [$target]);
        $this->moveEntry($user, $this->info($user, $path), $path, $target);

        return $target;
    }

    /**
     * Copy entries into $dest. Without $onConflict an existing name stops
     * everything with FileConflict; "skip" leaves it, "overwrite" replaces
     * it, "rename" picks the first free "name (n).ext".
     *
     * @param  list<FilePath>  $paths
     * @return list<string> names created in $dest
     */
    public function copy(string $user, array $paths, FilePath $dest, ?string $onConflict): array
    {
        return $this->transfer($user, $paths, $dest, $onConflict, false);
    }

    /**
     * @param  list<FilePath>  $paths
     * @return list<string>
     */
    public function move(string $user, array $paths, FilePath $dest, ?string $onConflict): array
    {
        return $this->transfer($user, $paths, $dest, $onConflict, true);
    }

    /** @param  list<FilePath>  $paths */
    public function delete(string $user, array $paths): void
    {
        foreach ($paths as $path) {
            if ($path->isRoot()) {
                throw new InvalidFilePath;
            }
            $entry = $this->info($user, $path);
            $this->arc->run($entry->type === 'd' ? 'v-delete-fs-directory' : 'v-delete-fs-file', [$user, $this->absolute($user, $path)], new Options(self::LONG));
        }
    }

    /**
     * File: chmod. Folder: the folder only, or recursively with folder and
     * file modes.
     */
    public function chmod(string $user, FilePath $path, string $mode, bool $recursive = false, ?string $filesMode = null): void
    {
        $entry = $this->info($user, $path);
        if ($entry->type === 'd') {
            $this->arc->run('v-change-fs-directory-permission', [
                $user, $this->absolute($user, $path), $mode, $filesMode ?? $mode, $recursive ? 'yes' : 'no',
            ], new Options(self::LONG));
        } else {
            $this->arc->run('v-change-fs-file-permission', [$user, $this->absolute($user, $path), $mode]);
        }
    }

    /**
     * Zip entries of one folder into $name in that folder.
     *
     * @param  list<FilePath>  $paths
     */
    public function compress(string $user, array $paths, string $name): FilePath
    {
        $parent = $paths[0]->parent();
        $archive = $parent->child($name);
        $this->arc->run('v-add-fs-zip', [
            $user, $this->absolute($user, $archive), ...array_map(fn (FilePath $p) => $this->absolute($user, $p), $paths),
        ], new Options(self::LONG));

        return $archive;
    }

    public function extract(string $user, FilePath $archive, FilePath $dest): void
    {
        $this->arc->run('v-extract-fs-archive', [$user, $this->absolute($user, $archive), $this->absolute($user, $dest)], new Options(self::LONG));
    }

    /**
     * Name search below $dir (find -name), at most SEARCH_MAX results.
     *
     * @return array{results: list<array{path: string, type: string, size: int}>, truncated: bool}
     */
    public function search(string $user, FilePath $dir, string $term): array
    {
        $pattern = strpbrk($term, '*?[') === false ? '*'.$term.'*' : $term;
        $stdout = $this->arc->run('v-search-fs-object', [$user, $pattern, $this->absolute($user, $dir)], new Options(10))->stdout;
        $results = [];
        $truncated = false;
        foreach (explode("\n", $stdout) as $line) {
            // type|mode|date|time|owner|group|size|path relative to $dir
            $fields = explode('|', $line, 8);
            if (count($fields) < 8 || $fields[7] === '') {
                continue;
            }
            if (count($results) >= self::SEARCH_MAX) {
                $truncated = true;
                break;
            }
            $relative = $dir->isRoot() ? $fields[7] : $dir->relative().'/'.$fields[7];
            $results[] = ['path' => $relative, 'type' => in_array($fields[0], ['d', 'f', 'l'], true) ? $fields[0] : 'o', 'size' => (int) $fields[6]];
        }

        return ['results' => $results, 'truncated' => $truncated];
    }

    public function size(string $user, FilePath $path): int
    {
        return (int) ($this->arc->json('v-list-fs-disk-usage', [$user, $this->absolute($user, $path)], new Options(40))['SIZE'] ?? 0);
    }

    /**
     * Write a staged file into the home directory (upload hand-off).
     * $onConflict: fail, overwrite or rename. Returns the final path.
     */
    public function placeUpload(string $user, string $staged, FilePath $path, string $onConflict): FilePath
    {
        $final = trim($this->arc->run('v-add-fs-upload', [$user, $staged, $this->absolute($user, $path), '0644', $onConflict], new Options(self::LONG))->stdout);
        $home = rtrim($this->account($user)->home, '/');

        return str_starts_with($final, $home.'/') ? FilePath::from(substr($final, strlen($home) + 1)) : $path;
    }

    /**
     * Write a download ticket (v-add-fs-download).
     *
     * @param  list<FilePath>  $paths
     * @return array{name: string, size: int, mime: string}
     */
    public function prepareDownload(string $user, string $ticket, array $paths): array
    {
        $data = $this->decode('v-add-fs-download', trim($this->arc->run('v-add-fs-download', [
            $user, $ticket, ...array_map(fn (FilePath $p) => $this->absolute($user, $p), $paths),
        ], new Options(self::LONG))->stdout));

        return ['name' => (string) ($data['NAME'] ?? 'download'), 'size' => (int) ($data['SIZE'] ?? 0), 'mime' => (string) ($data['MIME'] ?? 'application/octet-stream')];
    }

    /**
     * Names of $dir, for conflict checks and free names.
     *
     * @return list<string>
     */
    public function names(string $user, FilePath $dir): array
    {
        return array_map(fn (FileEntry $e) => $e->name, $this->list($user, $dir, 0, 5000)['entries']);
    }

    /** First free "name (n).ext" among $taken. */
    public static function freeName(string $name, array $taken): string
    {
        $dot = strrpos($name, '.');
        [$base, $ext] = $dot !== false && $dot > 0 ? [substr($name, 0, $dot), substr($name, $dot)] : [$name, ''];
        for ($n = 1; ; $n++) {
            $candidate = "{$base} ({$n}){$ext}";
            if (! in_array($candidate, $taken, true)) {
                return $candidate;
            }
        }
    }

    /**
     * @param  list<FilePath>  $paths
     * @return list<string>
     */
    private function transfer(string $user, array $paths, FilePath $dest, ?string $onConflict, bool $move): array
    {
        foreach ($paths as $path) {
            if ($path->isRoot() || $path->contains($dest)) {
                throw new InvalidFilePath;
            }
        }
        $taken = $this->names($user, $dest);
        // Within the same folder a copy gets a free name and a move does nothing
        $conflicts = array_values(array_map(
            fn (FilePath $p) => $p->name(),
            array_filter($paths, fn (FilePath $p) => ! $p->parent()->equals($dest) && in_array($p->name(), $taken, true)),
        ));
        if ($conflicts !== [] && $onConflict === null) {
            throw new FileConflict($conflicts);
        }

        $done = [];
        foreach ($paths as $path) {
            $name = $path->name();
            $target = $dest->child($name);
            if (in_array($name, $taken, true)) {
                if ($path->parent()->equals($dest) && $move) {
                    continue;
                }
                if ($onConflict === 'skip') {
                    continue;
                }
                if ($onConflict === 'rename' || $path->parent()->equals($dest)) {
                    $name = self::freeName($name, $taken);
                    $target = $dest->child($name);
                } else {
                    // overwrite: cp and mv put a folder inside an existing folder
                    $this->delete($user, [$target]);
                }
            }
            $entry = $this->info($user, $path);
            if ($move) {
                $this->moveEntry($user, $entry, $path, $target);
            } else {
                $this->arc->run($entry->type === 'd' ? 'v-copy-fs-directory' : 'v-copy-fs-file', [
                    $user, $this->absolute($user, $path), $this->absolute($user, $target),
                ], new Options(self::LONG));
            }
            $taken[] = $name;
            $done[] = $name;
        }

        return $done;
    }

    private function moveEntry(string $user, FileEntry $entry, FilePath $from, FilePath $to): void
    {
        $this->arc->run($entry->type === 'd' ? 'v-move-fs-directory' : 'v-move-fs-file', [
            $user, $this->absolute($user, $from), $this->absolute($user, $to),
        ], new Options(self::LONG));
    }

    /** @param  list<FilePath>  $paths */
    private function refuseExisting(string $user, array $paths): void
    {
        $existing = array_values(array_map(fn (FilePath $p) => $p->name(), array_filter($paths, fn (FilePath $p) => $this->find($user, $p) !== null)));
        if ($existing !== []) {
            throw new FileConflict($existing);
        }
    }

    /** @return array<mixed> */
    private function decode(string $command, string $json): array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ArcCommandException($command.' returned invalid JSON: '.$e->getMessage());
        }

        return is_array($data) ? $data : [];
    }
}
