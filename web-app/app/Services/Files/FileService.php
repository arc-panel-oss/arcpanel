<?php

namespace App\Services\Files;

use App\Arc\ArcCommand;
use App\Arc\ArcCommandException;
use App\Arc\ExitCode;
use App\Arc\Options;
use App\Services\WebDomains\ApplyResult;
use App\Services\WebDomains\Step;
use JsonException;

/**
 * Files of a user's home directory (E3 spec §6.1): one method per command
 * through ArcCommand, with paths relative to the home directory. Every
 * command reads and writes as the user; FilePolicy decides who may call
 * what. Copy, move and rename check the destination first and never
 * overwrite unless asked (D12).
 *
 * Changes are planned first (E34 batch 5 spec §5): each plan… method runs
 * the reads an operation depends on (conflicts, file or folder) and returns
 * the change commands as a FilePlan, which REST API v2 checks, previews and
 * runs through apply(); the methods the page calls run the same plan.
 */
final class FileService
{
    /** Bytes the editor opens at most */
    public const EDIT_MAX = 2097152;

    /** Results of a name search at most */
    public const SEARCH_MAX = 500;

    /** Entries the listing command answers at most per call */
    public const LIST_MAX = 5000;

    /** Seconds for commands that copy, pack or unpack whole trees */
    private const LONG = 600;

    /** Commands that run with the LONG timeout */
    private const LONG_COMMANDS = [
        'v-delete-fs-file', 'v-delete-fs-directory', 'v-copy-fs-file', 'v-copy-fs-directory', 'v-move-fs-file',
        'v-move-fs-directory', 'v-change-fs-directory-permission', 'v-add-fs-zip', 'v-extract-fs-archive',
    ];

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

    /**
     * Every entry of $dir, read in pages of LIST_MAX.
     *
     * @return array{total: int, entries: list<FileEntry>}
     */
    public function listAll(string $user, FilePath $dir, string $sort = 'name', string $order = 'asc', bool $hidden = true): array
    {
        $entries = [];
        do {
            $page = $this->list($user, $dir, count($entries), self::LIST_MAX, $sort, $order, $hidden);
            array_push($entries, ...$page['entries']);
        } while ($page['entries'] !== [] && count($entries) < $page['total']);

        return ['total' => $page['total'], 'entries' => $entries];
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
        $file = $this->stageContent($content);
        try {
            $stdout = $this->arc->run('v-save-fs-file', $this->planSave($user, $path, $file, $hash)->args)->stdout;
        } finally {
            FileStaging::removeTree(dirname($file));
        }
        $header = $this->header('v-save-fs-file', $stdout);

        return ['hash' => (string) ($header['HASH'] ?? ''), 'size' => (int) ($header['SIZE'] ?? 0), 'mtime' => (int) ($header['MTIME'] ?? 0)];
    }

    /**
     * Write $content into a new private folder of the staging tree; the
     * caller removes it with FileStaging::removeTree(dirname($file)).
     */
    public function stageContent(string $content, ?string $token = null): string
    {
        $file = $this->staging->newSessionDir($token ?? FileStaging::token()).'/file';
        file_put_contents($file, $content);
        chmod($file, 0600);

        return $file;
    }

    /** Where stageContent() writes the content of $token. */
    public function stagedFile(string $token): string
    {
        return $this->staging->uploads().'/'.$token.'/file';
    }

    /** The command that saves the staged file over $path (see save()). */
    public function planSave(string $user, FilePath $path, string $staged, ?string $hash): Step
    {
        return new Step(sprintf(__('Save %s'), $path->relative()), 'v-save-fs-file', [$user, $staged, $this->absolute($user, $path), $hash ?? '']);
    }

    /**
     * The JSON header line that v-read-fs-file and v-save-fs-file print
     * first: SIZE, MTIME, MIME and HASH.
     *
     * @return array<mixed>
     */
    public function header(string $command, string $stdout): array
    {
        $newline = strpos($stdout, "\n");

        return $this->decode($command, trim($newline === false ? $stdout : substr($stdout, 0, $newline)));
    }

    public function mkdir(string $user, FilePath $path): void
    {
        $this->runPlan($this->planMkdir($user, $path));
    }

    /** A new folder; an existing name stops it with FileConflict. */
    public function planMkdir(string $user, FilePath $path): FilePlan
    {
        $this->refuseExisting($user, [$path]);

        return new FilePlan([['path' => $path, 'steps' => [
            new Step(sprintf(__('Create the folder %s'), $path->relative()), 'v-add-fs-directory', [$user, $this->absolute($user, $path)]),
        ]]], $path);
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
        $this->runPlan($this->planTouch($user, $path));
    }

    /** A new empty file; an existing name stops it with FileConflict. */
    public function planTouch(string $user, FilePath $path): FilePlan
    {
        $this->refuseExisting($user, [$path]);

        return new FilePlan([['path' => $path, 'steps' => [
            new Step(sprintf(__('Create the file %s'), $path->relative()), 'v-add-fs-file', [$user, $this->absolute($user, $path)]),
        ]]], $path);
    }

    public function rename(string $user, FilePath $path, string $name): FilePath
    {
        return $this->runPlan($this->planRename($user, $path, $name));
    }

    /** A new name within the folder; the result is the new path. */
    public function planRename(string $user, FilePath $path, string $name): FilePlan
    {
        $target = $path->parent()->child($name);
        $this->refuseExisting($user, [$target]);

        return new FilePlan([['path' => $path, 'steps' => [$this->moveStep($user, $this->info($user, $path), $path, $target)]]], $target);
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
        return $this->runPlan($this->planTransfer($user, $paths, $dest, $onConflict, false));
    }

    /**
     * @param  list<FilePath>  $paths
     * @return list<string>
     */
    public function move(string $user, array $paths, FilePath $dest, ?string $onConflict): array
    {
        return $this->runPlan($this->planTransfer($user, $paths, $dest, $onConflict, true));
    }

    /**
     * Copy or move entries into $dest (see copy()); the result is the names
     * created in $dest.
     *
     * @param  list<FilePath>  $paths
     */
    public function planTransfer(string $user, array $paths, FilePath $dest, ?string $onConflict, bool $move): FilePlan
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

        $items = [];
        $created = [];
        foreach ($paths as $path) {
            $name = $path->name();
            $target = $dest->child($name);
            $steps = [];
            if (in_array($name, $taken, true)) {
                if (($path->parent()->equals($dest) && $move) || $onConflict === 'skip') {
                    $items[] = ['path' => $path, 'steps' => []];

                    continue;
                }
                if ($onConflict === 'rename' || $path->parent()->equals($dest)) {
                    $name = self::freeName($name, $taken);
                    $target = $dest->child($name);
                } else {
                    // overwrite: cp and mv put a folder inside an existing folder
                    $steps = $this->planDelete($user, [$target])->steps();
                }
            }
            $entry = $this->info($user, $path);
            $steps[] = $move
                ? $this->moveStep($user, $entry, $path, $target)
                : new Step(
                    sprintf(__('Copy %s to %s'), $path->relative(), $target->relative()),
                    $entry->type === 'd' ? 'v-copy-fs-directory' : 'v-copy-fs-file',
                    [$user, $this->absolute($user, $path), $this->absolute($user, $target)],
                );
            $items[] = ['path' => $path, 'steps' => $steps];
            $taken[] = $name;
            $created[] = $name;
        }

        return new FilePlan($items, $created);
    }

    /** @param  list<FilePath>  $paths */
    public function delete(string $user, array $paths): void
    {
        $this->runPlan($this->planDelete($user, $paths));
    }

    /**
     * One delete command per path, for a file or a folder.
     *
     * @param  list<FilePath>  $paths
     */
    public function planDelete(string $user, array $paths): FilePlan
    {
        $items = [];
        foreach ($paths as $path) {
            if ($path->isRoot()) {
                throw new InvalidFilePath;
            }
            $entry = $this->info($user, $path);
            $items[] = ['path' => $path, 'steps' => [new Step(
                sprintf(__('Delete %s'), $path->relative()),
                $entry->type === 'd' ? 'v-delete-fs-directory' : 'v-delete-fs-file',
                [$user, $this->absolute($user, $path)],
            )]];
        }

        return new FilePlan($items);
    }

    /**
     * File: chmod. Folder: the folder only, or recursively with folder and
     * file modes.
     */
    public function chmod(string $user, FilePath $path, string $mode, bool $recursive = false, ?string $filesMode = null): void
    {
        $this->runPlan($this->planChmod($user, [$path], $mode, $recursive, $filesMode));
    }

    /** @param  list<FilePath>  $paths */
    public function planChmod(string $user, array $paths, string $mode, bool $recursive = false, ?string $filesMode = null): FilePlan
    {
        $items = [];
        foreach ($paths as $path) {
            $entry = $this->info($user, $path);
            $label = sprintf(__('Change the permissions of %s'), $path->isRoot() ? '~' : $path->relative());
            $items[] = ['path' => $path, 'steps' => [$entry->type === 'd'
                ? new Step($label, 'v-change-fs-directory-permission', [$user, $this->absolute($user, $path), $mode, $filesMode ?? $mode, $recursive ? 'yes' : 'no'])
                : new Step($label, 'v-change-fs-file-permission', [$user, $this->absolute($user, $path), $mode])]];
        }

        return new FilePlan($items);
    }

    /**
     * Zip entries of one folder into $name in that folder.
     *
     * @param  list<FilePath>  $paths
     */
    public function compress(string $user, array $paths, string $name): FilePath
    {
        return $this->runPlan($this->planCompress($user, $paths, $name));
    }

    /**
     * The zip command; the result is the archive's path. The command refuses
     * an archive that exists (E_EXISTS).
     *
     * @param  list<FilePath>  $paths
     */
    public function planCompress(string $user, array $paths, string $name): FilePlan
    {
        $archive = $paths[0]->parent()->child($name);

        return new FilePlan([['path' => $archive, 'steps' => [new Step(
            sprintf(__('Compress into %s'), $archive->relative()),
            'v-add-fs-zip',
            [$user, $this->absolute($user, $archive), ...array_map(fn (FilePath $p) => $this->absolute($user, $p), $paths)],
        )]]], $archive);
    }

    public function extract(string $user, FilePath $archive, FilePath $dest): void
    {
        $this->runPlan($this->planExtract($user, $archive, $dest));
    }

    /**
     * Unpack $archive into $dest; the result is $dest. With $create the plan
     * creates $dest first (E34 batch 5 D52: REST API v2 extracts into a new
     * folder only); without it the command creates a missing folder itself.
     */
    public function planExtract(string $user, FilePath $archive, FilePath $dest, bool $create = false): FilePlan
    {
        $steps = [];
        if ($create) {
            $steps[] = new Step(sprintf(__('Create the folder %s'), $dest->relative()), 'v-add-fs-directory', [$user, $this->absolute($user, $dest)]);
        }
        $steps[] = new Step(
            sprintf(__('Extract %s into %s'), $archive->relative(), $dest->isRoot() ? '~' : $dest->relative()),
            'v-extract-fs-archive',
            [$user, $this->absolute($user, $archive), $this->absolute($user, $dest)],
        );

        return new FilePlan([['path' => $archive, 'steps' => $steps]], $dest);
    }

    /**
     * Run Steps in order with the timeouts of their commands. The first
     * failure stops the run; the Steps before it stay applied (D49).
     *
     * @param  list<Step>  $steps
     */
    public function apply(array $steps): ApplyResult
    {
        $applied = [];
        $stdout = [];
        foreach ($steps as $step) {
            try {
                $stdout[$step->command] = $this->arc->run($step->command, $step->args, $this->options($step->command))->stdout;
            } catch (ArcCommandException $e) {
                return new ApplyResult($applied, $step, $e->userMessage(), $e->getCode(), $stdout, $e->invalidArgument);
            }
            $applied[] = $step;
        }

        return new ApplyResult($applied, stdoutByCommand: $stdout);
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
     * Names of $dir, for conflict checks and free names: every entry, also
     * in folders above LIST_MAX entries.
     *
     * @return list<string>
     */
    public function names(string $user, FilePath $dir): array
    {
        return array_map(fn (FileEntry $e) => $e->name, $this->listAll($user, $dir)['entries']);
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
     * Stop with FileConflict (the names) when one of $paths exists.
     *
     * @param  list<FilePath>  $paths
     */
    public function refuseExisting(string $user, array $paths): void
    {
        $existing = array_values(array_map(fn (FilePath $p) => $p->name(), array_filter($paths, fn (FilePath $p) => $this->find($user, $p) !== null)));
        if ($existing !== []) {
            throw new FileConflict($existing);
        }
    }

    private function moveStep(string $user, FileEntry $entry, FilePath $from, FilePath $to): Step
    {
        return new Step(
            sprintf(__('Move %s to %s'), $from->relative(), $to->relative()),
            $entry->type === 'd' ? 'v-move-fs-directory' : 'v-move-fs-file',
            [$user, $this->absolute($user, $from), $this->absolute($user, $to)],
        );
    }

    /** Run the Steps of $plan and answer its result; a failed command throws. */
    private function runPlan(FilePlan $plan): mixed
    {
        foreach ($plan->steps() as $step) {
            $this->arc->run($step->command, $step->args, $this->options($step->command));
        }

        return $plan->result;
    }

    private function options(string $command): ?Options
    {
        return in_array($command, self::LONG_COMMANDS, true) ? new Options(self::LONG) : null;
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
