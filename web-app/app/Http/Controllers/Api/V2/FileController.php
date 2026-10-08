<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\ApiException;
use App\Api\ErrorCode;
use App\Api\Paginates;
use App\Arc\ArcCommandException;
use App\Arc\ExitCode;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\FilePathRequest;
use App\Http\Requests\Api\V2\ListFilesRequest;
use App\Http\Requests\Api\V2\ReadFileRequest;
use App\Http\Requests\Api\V2\SearchFilesRequest;
use App\Http\Resources\V2\FileContentResource;
use App\Http\Resources\V2\FileEntryResource;
use App\Http\Resources\V2\FileSearchResultResource;
use App\Http\Resources\V2\LocatedFileEntry;
use App\Policies\FilePolicy;
use App\Services\Files\FilePath;
use App\Services\Files\FileService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Files of a user's home directory (E34 batch 5 spec §4.1): a JSON layer
 * on FileService with the rules of FilePolicy, as the file manager page.
 * Paths are relative to the home directory (D48); a path outside it, or
 * behind a symbolic link that leaves it, answers 404, and so does every
 * path while FILE_MANAGER is off. Every command runs as the user.
 *
 * @tags Files
 */
final class FileController extends Controller
{
    public function __construct(
        private readonly FileService $files,
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

    /** {user} is an account the key may see; 404 otherwise. */
    private function assertView(string $user, Principal $principal, FilePolicy $policy): void
    {
        abort_unless($policy->view($principal, $user), 404);
    }

    /** A path below the home directory, not the home itself (404). */
    private function entryPath(string $path): FilePath
    {
        $entry = FilePath::from($path);
        abort_if($entry->isRoot(), 404);

        return $entry;
    }

    /**
     * Run reads of the commands, with the file statuses of their failures.
     *
     * @template T
     *
     * @param  Closure(): T  $read
     * @return T
     */
    private function guard(Closure $read, bool $tooLarge = false): mixed
    {
        try {
            return $read();
        } catch (ArcCommandException $e) {
            throw $this->commandError($e, $tooLarge);
        }
    }

    /**
     * File statuses of a failed read (E3 spec §8, E34 batch 5 D48, D55): a
     * path the command finds outside the home directory is 404, a full
     * quota 422 `E_LIMIT`, a file above the read limit 413, a search over
     * its time 422 `E_LIMIT`.
     */
    private function commandError(ArcCommandException $e, bool $tooLarge = false): ApiException|ArcCommandException
    {
        $exit = $e->exit();

        return match (true) {
            $e->invalidArgument => $e,
            $e->result === null && str_contains($e->getMessage(), ': timed out') => new ApiException(
                ErrorCode::Limit,
                __('This took too long.'),
                422,
            ),
            self::outsideHome($exit, $e->userMessage()) => new ApiException(ErrorCode::NotExist, __('This file or folder does not exist.'), 404),
            $exit === ExitCode::Disk => new ApiException(ErrorCode::Limit, __('Disk quota exceeded.'), 422),
            $exit === ExitCode::Limit && $tooLarge => new ApiException(ErrorCode::Limit, self::message($e->userMessage()), 413),
            $e->result === null => $e,
            default => new ApiException(ErrorCode::fromExit($exit), self::message($e->userMessage()), ErrorCode::statusOfExit($exit)),
        };
    }

    /** The command's message without the "Error: " prefix of func/main.sh. */
    private static function message(string $message): string
    {
        return (string) preg_replace('/^Error:\s*/', '', $message);
    }

    /** The command refused a path that resolves outside the home directory (func/fs.sh, v-search-fs-object). */
    private static function outsideHome(?ExitCode $exit, string $message): bool
    {
        return in_array($exit, [ExitCode::Forbidden, ExitCode::Invalid], true) && preg_match('/\binvalid path\b/', $message) === 1;
    }
}
