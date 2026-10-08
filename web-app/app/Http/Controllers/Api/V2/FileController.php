<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\ApiException;
use App\Api\ErrorCode;
use App\Api\Paginates;
use App\Api\PlanGate;
use App\Arc\ArcCommandException;
use App\Arc\ExitCode;
use App\Auth\Principal;
use App\Http\Controllers\Concerns\AnswersFileRequests;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\ChangeFileModeRequest;
use App\Http\Requests\Api\V2\CompressFilesRequest;
use App\Http\Requests\Api\V2\CreateFileRequest;
use App\Http\Requests\Api\V2\DeleteFilesRequest;
use App\Http\Requests\Api\V2\ExtractArchiveRequest;
use App\Http\Requests\Api\V2\FilePathRequest;
use App\Http\Requests\Api\V2\ListFilesRequest;
use App\Http\Requests\Api\V2\ReadFileRequest;
use App\Http\Requests\Api\V2\RenameFileRequest;
use App\Http\Requests\Api\V2\SaveFileRequest;
use App\Http\Requests\Api\V2\SearchFilesRequest;
use App\Http\Requests\Api\V2\TransferFilesRequest;
use App\Http\Resources\V2\FileContentResource;
use App\Http\Resources\V2\FileEntryResource;
use App\Http\Resources\V2\FileSearchResultResource;
use App\Http\Resources\V2\LocatedFileEntry;
use App\Policies\FilePolicy;
use App\Services\Files\FileConflict;
use App\Services\Files\FilePath;
use App\Services\Files\FilePlan;
use App\Services\Files\FileService;
use App\Services\Files\FileStaging;
use App\Services\WebDomains\ApplyResult;
use App\Services\WebDomains\Step;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Files of a user's home directory (E34 batch 5 spec §4.1, §4.2): a JSON
 * layer on FileService with the rules of FilePolicy, as the file manager
 * page. Paths are relative to the home directory (D48); a path outside it,
 * or behind a symbolic link that leaves it, answers 404, and so does every
 * path while FILE_MANAGER is off. Every command runs as the user.
 *
 * Changes are planned first (FileService::plan…): the reads that find
 * conflicts run in a dry run too, so a conflict answers 409 there as it
 * would in the real call (D54). An operation over several paths stops at
 * the first failure; the error names the path (`error.path`) and lists the
 * finished paths (`error.done`, D49).
 *
 * @tags Files
 */
final class FileController extends Controller
{
    use AnswersFileRequests;

    public function __construct(
        private readonly FileService $files,
        private readonly FileStaging $staging,
        private readonly PlanGate $gate,
        private readonly Paginates $pages,
    ) {}

    /**
     * List a folder
     *
     * The entries of a folder of the user's home directory: folders first,
     * then by `sort` and `order`. `mime` and `writable` are null in a list
     * (getFileInfo reads them). Without `limit` the list is complete.
     *
     * @operationId listFiles
     */
    public function index(ListFilesRequest $request, string $user, Principal $principal, FilePolicy $policy): AnonymousResourceCollection
    {
        $this->assertView($user, $principal, $policy);
        $data = $request->validated();
        $dir = FilePath::from($data['path'] ?? '');
        ['limit' => $limit, 'offset' => $offset] = $this->pages->window($request);
        $sort = (string) ($data['sort'] ?? 'name');
        $order = (string) ($data['order'] ?? 'asc');
        $hidden = in_array((string) ($data['hidden'] ?? 'true'), ['1', 'true'], true);

        $listing = $this->guard(fn () => $limit === null
            ? $this->files->listAll($user, $dir, $sort, $order, $hidden)
            : $this->files->list($user, $dir, $offset, $limit, $sort, $order, $hidden));
        $meta = $limit === null ? ['total' => $listing['total']] : ['total' => $listing['total'], 'limit' => $limit, 'offset' => $offset];

        return FileEntryResource::collection(LocatedFileEntry::inFolder($dir, $listing['entries']))->additional(['meta' => $meta]);
    }

    /**
     * Get a file or folder
     *
     * One entry with its MIME type and whether the user may write it.
     *
     * @operationId getFileInfo
     */
    public function info(FilePathRequest $request, string $user, Principal $principal, FilePolicy $policy): FileEntryResource
    {
        $this->assertView($user, $principal, $policy);
        $path = FilePath::from($request->validated()['path'] ?? '');

        return new FileEntryResource(LocatedFileEntry::at($this->guard(fn () => $this->files->info($user, $path)), $path));
    }

    /**
     * Read a text file
     *
     * The content of a regular file of at most 2 MiB (`413` `E_LIMIT`
     * above) with its SHA-256 `hash`, which saveFile expects back. Bytes
     * that are not UTF-8 are replaced and `utf8` is false: saving such
     * content back would change the file.
     *
     * @operationId readFile
     */
    public function read(ReadFileRequest $request, string $user, Principal $principal, FilePolicy $policy): FileContentResource
    {
        $this->assertView($user, $principal, $policy);
        $path = $this->entryPath((string) $request->validated()['path']);
        $file = $this->guard(fn () => $this->files->read($user, $path), tooLarge: true);

        return new FileContentResource(['path' => $path->relative()] + $file);
    }

    /**
     * Search by name
     *
     * Entries whose name matches `q` in the folder `path` and below, at
     * most 500 (`meta.truncated` when there were more). A search that takes
     * longer than 10 seconds answers `422` `E_LIMIT`: narrow it.
     *
     * @operationId searchFiles
     */
    public function search(SearchFilesRequest $request, string $user, Principal $principal, FilePolicy $policy): AnonymousResourceCollection
    {
        $this->assertView($user, $principal, $policy);
        $data = $request->validated();
        $dir = FilePath::from($data['path'] ?? '');
        $found = $this->guard(fn () => $this->files->search($user, $dir, (string) $data['q']));

        return FileSearchResultResource::collection($found['results'])->additional(['meta' => [
            /** @var bool whether more than 500 entries matched */
            'truncated' => $found['truncated'],
        ]]);
    }

    /**
     * Get the size of a folder
     *
     * The bytes used by a folder and everything in it (`du -sb` as the
     * user). Counting longer than 30 seconds answers `422` `E_LIMIT`.
     *
     * @operationId getFolderSize
     */
    public function size(FilePathRequest $request, string $user, Principal $principal, FilePolicy $policy): JsonResponse
    {
        $this->assertView($user, $principal, $policy);
        $path = FilePath::from($request->validated()['path'] ?? '');
        $size = $this->guard(fn () => $this->files->size($user, $path));

        return response()->json(['data' => [
            /** @var string path relative to the home directory */
            'path' => $path->relative(),
            /** @var int bytes */
            'size' => $size,
        ]]);
    }

    /**
     * Save a text file
     *
     * Writes `content` over the file at `path`, or creates it when `hash`
     * is null. `hash` is the hash of the version read: when the file
     * changed since, nothing is written and the answer is `409` `E_INUSE`;
     * an existing file with a null `hash` answers `409` `E_EXISTS`. The
     * file keeps its mode, owner and the line endings sent. Content above
     * 2 MiB answers `413` `E_LIMIT`, a full disk quota `422` `E_LIMIT`, too
     * little space in the panel's staging folder `507` `E_DISK`.
     *
     * @operationId saveFile
     */
    public function save(SaveFileRequest $request, string $user, Principal $principal, FilePolicy $policy): FileContentResource|JsonResponse
    {
        $this->assertManage($user, $principal, $policy);
        $data = $request->validated();
        $path = $this->entryPath((string) $data['path']);
        $content = $this->rawContent($request);
        if (strlen($content) > FileService::EDIT_MAX) {
            throw new ApiException(ErrorCode::Limit, sprintf(__('The content is larger than %d bytes.'), FileService::EDIT_MAX), 413);
        }
        $token = FileStaging::token();
        $staged = $this->files->stagedFile($token);
        $step = $this->files->planSave($user, $path, $staged, $data['hash'] ?? null);
        $plan = new FilePlan([['path' => $path, 'steps' => [$step]]]);
        $this->gate->authorize([$step]);
        if ($this->gate->dryRun()) {
            return $this->gate->preview([$step]);
        }
        if ($this->staging->freeBytes() < (int) ceil(strlen($content) * 1.05)) {
            throw new ApiException(ErrorCode::Disk, __('The server has not enough space to stage this file now.'), 507);
        }
        try {
            $this->files->stageContent($content, $token);
            $result = $this->files->apply([$step]);
        } finally {
            FileStaging::removeTree(dirname($staged));
        }
        $this->assertApplied($plan, $result);
        $header = $this->files->header('v-save-fs-file', (string) $result->stdoutOf('v-save-fs-file'));

        return new FileContentResource([
            'path' => $path->relative(),
            'hash' => (string) ($header['HASH'] ?? ''),
            'size' => (int) ($header['SIZE'] ?? 0),
            'mtime' => (int) ($header['MTIME'] ?? 0),
            'mime' => (string) ($header['MIME'] ?? ''),
        ]);
    }

    /**
     * Create a folder
     *
     * A new folder `name` in the folder `path`; a name that exists answers
     * `409` `E_EXISTS` with `conflicts`.
     *
     * @operationId createDirectory
     *
     * @status 201
     */
    public function createDirectory(CreateFileRequest $request, string $user, Principal $principal, FilePolicy $policy): FileEntryResource|JsonResponse
    {
        $this->assertManage($user, $principal, $policy);
        $path = $this->newPath($request);
        $plan = $this->plan(fn () => $this->files->planMkdir($user, $path));

        return $this->execute($plan) ?? $this->created($user, $path);
    }

    /**
     * Create an empty file
     *
     * A new empty file `name` in the folder `path`; a name that exists
     * answers `409` `E_EXISTS` with `conflicts`.
     *
     * @operationId createFile
     *
     * @status 201
     */
    public function touch(CreateFileRequest $request, string $user, Principal $principal, FilePolicy $policy): FileEntryResource|JsonResponse
    {
        $this->assertManage($user, $principal, $policy);
        $path = $this->newPath($request);
        $plan = $this->plan(fn () => $this->files->planTouch($user, $path));

        return $this->execute($plan) ?? $this->created($user, $path);
    }

    /**
     * Rename a file or folder
     *
     * A new name in the same folder; a name that exists answers `409`
     * `E_EXISTS` with `conflicts`. Answers the entry at its new path.
     *
     * @operationId renameFile
     */
    public function rename(RenameFileRequest $request, string $user, Principal $principal, FilePolicy $policy): FileEntryResource|JsonResponse
    {
        $this->assertManage($user, $principal, $policy);
        $data = $request->validated();
        $path = $this->entryPath((string) $data['path']);
        $name = $this->validName((string) $data['name'], 'name');
        $plan = $this->plan(fn () => $this->files->planRename($user, $path, $name));

        return $this->execute($plan) ?? $this->entry($user, $plan->result);
    }

    /**
     * Copy files and folders
     *
     * Copies `paths` into the folder `destination`. A name that exists
     * there stops the copy before anything changes, with `409` `E_EXISTS`
     * and `conflicts`, unless `on_conflict` says otherwise; within its own
     * folder a copy always gets a free name. The copy stops at the first
     * failure: `error.path` names it and `error.done` lists the paths
     * copied before. A copy longer than 600 seconds answers `504` from the
     * web server and may leave part of the work done.
     *
     * @operationId copyFiles
     */
    public function copy(TransferFilesRequest $request, string $user, Principal $principal, FilePolicy $policy): JsonResponse
    {
        return $this->transfer($request, $user, $principal, $policy, false);
    }

    /**
     * Move files and folders
     *
     * Moves `paths` into the folder `destination`, with the conflict rules
     * of copyFiles; an entry already in `destination` stays where it is.
     * The move stops at the first failure: `error.path` names it and
     * `error.done` lists the paths moved before. A move longer than 600
     * seconds answers `504` from the web server and may leave part of the
     * work done.
     *
     * @operationId moveFiles
     */
    public function move(TransferFilesRequest $request, string $user, Principal $principal, FilePolicy $policy): JsonResponse
    {
        return $this->transfer($request, $user, $principal, $policy, true);
    }

    /**
     * Delete files and folders
     *
     * Deletes `paths`, folders with their content; there is no trash. The
     * delete stops at the first failure: `error.path` names it and
     * `error.done` lists the paths deleted before. A delete longer than 600
     * seconds answers `504` from the web server and may leave part of the
     * work done.
     *
     * @operationId deleteFiles
     *
     * @status 204
     */
    public function delete(DeleteFilesRequest $request, string $user, Principal $principal, FilePolicy $policy): Response|JsonResponse
    {
        $this->assertManage($user, $principal, $policy);
        $paths = $this->paths($request);
        $plan = $this->plan(fn () => $this->files->planDelete($user, $paths));

        return $this->execute($plan) ?? response()->noContent();
    }

    /**
     * Change permissions
     *
     * Sets the octal `mode` of `paths`. A folder changes alone, or with
     * `recursive` everything inside it too: folders get `mode` and files
     * `files_mode` (default `mode`); symbolic links are skipped. The change
     * stops at the first failure: `error.path` names it and `error.done`
     * lists the paths changed before. A change longer than 600 seconds
     * answers `504` from the web server and may leave part of the work
     * done.
     *
     * @operationId changeFileMode
     *
     * @status 204
     */
    public function chmod(ChangeFileModeRequest $request, string $user, Principal $principal, FilePolicy $policy): Response|JsonResponse
    {
        $this->assertManage($user, $principal, $policy);
        $data = $request->validated();
        $paths = $this->paths($request);
        $plan = $this->plan(fn () => $this->files->planChmod(
            $user, $paths, (string) $data['mode'], (bool) ($data['recursive'] ?? false), $data['files_mode'] ?? null,
        ));

        return $this->execute($plan) ?? response()->noContent();
    }

    /**
     * Compress into a zip archive
     *
     * Zips `paths`, entries of one folder, into the new archive `name` in
     * that folder; an archive that exists answers `409` `E_EXISTS` with
     * `conflicts`. Symbolic links are stored as links. Answers the
     * archive's entry. Compressing longer than 600 seconds answers `504`
     * from the web server and may leave the archive behind.
     *
     * @operationId compressFiles
     *
     * @status 201
     */
    public function compress(CompressFilesRequest $request, string $user, Principal $principal, FilePolicy $policy): FileEntryResource|JsonResponse
    {
        $this->assertManage($user, $principal, $policy);
        $paths = $this->paths($request, sameFolder: true);
        $name = $this->validName((string) $request->validated()['name'], 'name');
        $plan = $this->plan(function () use ($user, $paths, $name): FilePlan {
            $plan = $this->files->planCompress($user, $paths, $name);
            $this->files->refuseExisting($user, [$plan->result]);

            return $plan;
        });

        return $this->execute($plan) ?? $this->created($user, $plan->result);
    }

    /**
     * Extract an archive
     *
     * Unpacks the archive `path` (zip, tar.gz, tgz, tar.bz2, tbz, tar.zst
     * or 7z) into `destination`, a folder that must not exist yet: the plan
     * creates it, so nothing is overwritten. An existing `destination`
     * answers `409` `E_EXISTS` with `conflicts`; single-file `.gz`, `.bz2`
     * and `.zst` are refused. Answers the new folder's entry. Extracting
     * longer than 600 seconds answers `504` from the web server and may
     * leave part of the content behind.
     *
     * @operationId extractArchive
     *
     * @status 201
     */
    public function extract(ExtractArchiveRequest $request, string $user, Principal $principal, FilePolicy $policy): FileEntryResource|JsonResponse
    {
        $this->assertManage($user, $principal, $policy);
        $data = $request->validated();
        $archive = $this->entryPath((string) $data['path']);
        $dest = FilePath::from((string) $data['destination']);
        if ($dest->isRoot()) {
            throw ValidationException::withMessages(['destination' => __('Choose a folder that does not exist yet.')]);
        }
        $plan = $this->plan(function () use ($user, $archive, $dest): FilePlan {
            $this->files->refuseExisting($user, [$dest]);

            return $this->files->planExtract($user, $archive, $dest, create: true);
        });

        return $this->execute($plan) ?? $this->created($user, $dest);
    }

    private function transfer(TransferFilesRequest $request, string $user, Principal $principal, FilePolicy $policy, bool $move): JsonResponse
    {
        $this->assertManage($user, $principal, $policy);
        $data = $request->validated();
        $paths = $this->paths($request);
        $dest = FilePath::from($data['destination'] ?? '');
        $onConflict = ($data['on_conflict'] ?? 'fail') === 'fail' ? null : (string) $data['on_conflict'];
        $plan = $this->plan(fn () => $this->files->planTransfer($user, $paths, $dest, $onConflict, $move));

        return $this->execute($plan) ?? response()->json(['data' => [
            /** @var string the folder, relative to the home directory */
            'destination' => $dest->relative(),
            /** @var list<string> the names created in the folder, in the order of `paths`; skipped entries are left out */
            'names' => $plan->result,
        ]]);
    }

    /** `name` in the folder `path` of a create request. */
    private function newPath(CreateFileRequest $request): FilePath
    {
        $data = $request->validated();

        return FilePath::from($data['path'] ?? '')->child($this->validName((string) $data['name'], 'name'));
    }

    /** @return list<FilePath> */
    private function paths(Request $request, bool $sameFolder = false): array
    {
        $paths = array_map(fn ($path) => $this->entryPath((string) $path), array_values((array) $request->input('paths')));
        foreach ($paths as $path) {
            if ($sameFolder && ! $path->parent()->equals($paths[0]->parent())) {
                throw ValidationException::withMessages(['paths' => __('Select items of one folder.')]);
            }
        }

        return $paths;
    }

    /** `content` as sent: TrimStrings would drop its final line break. */
    private function rawContent(Request $request): string
    {
        $body = json_decode((string) $request->getContent(), true);
        $content = is_array($body) ? ($body['content'] ?? '') : $request->input('content', '');

        return is_string($content) ? $content : '';
    }

    /**
     * Build a plan: its reads run now, in a dry run too (D54); a name that
     * exists answers 409 with `conflicts`.
     *
     * @param  Closure(): FilePlan  $build
     */
    private function plan(Closure $build): FilePlan
    {
        try {
            return $build();
        } catch (FileConflict $e) {
            throw $this->conflictError($e);
        } catch (ArcCommandException $e) {
            throw $this->commandError($e);
        }
    }

    /**
     * Check the plan against the key's sets, then answer the dry run or
     * run it. Null when it ran.
     */
    private function execute(FilePlan $plan): ?JsonResponse
    {
        $steps = $plan->steps();
        $this->gate->authorize($steps);
        if ($this->gate->dryRun()) {
            return $this->gate->preview($steps);
        }
        $this->assertApplied($plan, $this->files->apply($steps));

        return null;
    }

    /**
     * A failed Step answers with the status of its command, the Step and
     * those that ran (PlanGate), the path it worked on and the paths that
     * were finished (D49).
     */
    private function assertApplied(FilePlan $plan, ApplyResult $result): void
    {
        if ($result->ok()) {
            return;
        }
        $exit = ExitCode::tryFrom($result->exitCode);
        [$code, $message, $status] = match (true) {
            $result->invalidArgument => [ErrorCode::Invalid, PlanGate::INVALID_ARGUMENT, 422],
            self::outsideHome($exit, $result->error) => [ErrorCode::NotExist, __('This file or folder does not exist.'), 404],
            $exit === ExitCode::Disk => [ErrorCode::Limit, __('Disk quota exceeded.'), 422],
            default => [ErrorCode::fromExit($exit), self::message($result->error), ErrorCode::statusOfExit($exit)],
        };
        $ran = count($result->applied);

        throw new ApiException($code, $message, $status, [
            'step' => $result->failed !== null ? PlanGate::describe($result->failed, withArgs: false) : null,
            'applied' => array_map(fn (Step $step) => PlanGate::describe($step, withArgs: false), $result->applied),
            'path' => $plan->failedPath($ran)?->relative(),
            'done' => array_map(fn (FilePath $path) => $path->relative(), $plan->done($ran)),
        ]);
    }
}
