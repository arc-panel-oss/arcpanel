<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\ApiException;
use App\Api\ErrorCode;
use App\Api\PlanGate;
use App\Arc\ArcCommandException;
use App\Arc\SysConfig;
use App\Auth\Principal;
use App\Http\Controllers\Concerns\AnswersFileRequests;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\DownloadFilesRequest;
use App\Http\Requests\Api\V2\FinishUploadRequest;
use App\Http\Requests\Api\V2\StartUploadRequest;
use App\Http\Resources\V2\FileEntryResource;
use App\Policies\FilePolicy;
use App\Services\Files\DownloadTickets;
use App\Services\Files\FileConflict;
use App\Services\Files\FilePath;
use App\Services\Files\FileService;
use App\Services\Files\FileStaging;
use App\Services\Files\UploadRefused;
use App\Services\Files\UploadSessions;
use Closure;
use Dedoc\Scramble\Attributes\Header;
use Dedoc\Scramble\Attributes\Response as ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Uploads and downloads of a user's files (E34 batch 5 spec §4.3, §4.4) on
 * the file manager's UploadSessions and DownloadTickets. An upload session
 * belongs to the access key that started it: another key, of the same user
 * too, gets 404 for its token (D50). A download answers the bytes in the
 * same request through arcpanel-nginx (D51); a read-only key may download.
 *
 * @tags Files
 */
final class FileTransferController extends Controller
{
    use AnswersFileRequests;

    public function __construct(
        private readonly FileService $files,
        private readonly UploadSessions $uploads,
        private readonly DownloadTickets $tickets,
        private readonly SysConfig $config,
        private readonly PlanGate $gate,
    ) {}

    /**
     * Start or resume an upload
     *
     * Opens an upload session for one file of `size` bytes in the folder
     * `path`, or resumes the session of this access key for the same file
     * (folder, name, size and `modified_at`): `received` lists the chunks
     * it already holds. Send the file in chunks of `chunk_size` bytes with
     * uploadChunk, then finishUpload. A session lives 24 hours. Files above
     * `FILE_MANAGER_UPLOAD_MAX` answer `413` `E_LIMIT`, a full disk quota
     * `422` `E_LIMIT` (when the server enforces quotas), too little space
     * in the panel's staging folder `507` `E_DISK`.
     *
     * @operationId startUpload
     */
    public function start(StartUploadRequest $request, string $user, Principal $principal, FilePolicy $policy): JsonResponse
    {
        $this->assertManage($user, $principal, $policy);
        $data = $request->validated();
        $folder = FilePath::from($data['path'] ?? '');
        $name = $this->validName((string) $data['name'], 'name');
        $relative = trim((string) ($data['relative_path'] ?? ''), '/');
        if ($relative !== '' && basename($relative) !== $name) {
            throw ValidationException::withMessages(['relative_path' => __('The relative path must end in the name of the file.')]);
        }
        // The folders of a folder upload stay below the folder (404 otherwise)
        $nested = str_contains($relative, '/');
        $parent = $nested ? $folder->join(substr($relative, 0, (int) strrpos($relative, '/'))) : $folder;
        $size = (int) $data['size'];
        $max = UploadSessions::maxBytes($this->config->get('FILE_MANAGER_UPLOAD_MAX'));
        if ($size > $max) {
            throw new ApiException(ErrorCode::Limit, UploadSessions::tooLarge($max), 413);
        }
        $quota = $this->config->is('DISK_QUOTA', 'yes');

        // The commands finishUpload runs: a key that may not run them opens no session
        $steps = $this->files->planUpload($user, $this->files->stagedFile(FileStaging::token()), $parent, $parent->child($name), $nested, 'fail')->steps();
        $this->gate->authorize($steps);
        if ($this->gate->dryRun()) {
            $this->refused(fn () => $this->uploads->admit($user, $size, $quota));

            return $this->gate->preview($steps);
        }
        $session = $this->refused(fn () => $this->uploads->init(FileStaging::owner($principal), $user, [
            'path' => $folder,
            'name' => $name,
            'relativePath' => $relative,
            'size' => $size,
            'lastModified' => (int) ($data['modified_at'] ?? 0),
        ], $max, $quota));

        return response()->json(['data' => [
            /** @var string the session's token for uploadChunk, finishUpload and cancelUpload */
            'token' => $session['token'],
            /** @var int bytes of every chunk but the last */
            'chunk_size' => $session['chunkSize'],
            /** @var int number of chunks, indexes 0 to `chunks` − 1 */
            'chunks' => max(1, (int) ceil($size / $session['chunkSize'])),
            /** @var list<int> indexes of the chunks the session already holds */
            'received' => $session['received'],
        ]]);
    }

    /**
     * Upload a chunk
     *
     * The raw bytes of chunk `index` (from 0) as the request body
     * (`application/octet-stream`): exactly `chunk_size` bytes, the last
     * chunk the rest of the file. A chunk sent again replaces the one
     * stored. Chunks have their own rate limit of 600 requests a minute per
     * access key. Takes no `dry_run`.
     *
     * @operationId uploadChunk
     *
     * @status 204
     */
    public function chunk(Request $request, string $user, string $token, int $index, Principal $principal, FilePolicy $policy): Response
    {
        $this->assertManage($user, $principal, $policy);
        $this->refuseDryRun($request);
        // The request body as a stream (php://input), never read into memory
        $stream = $request->getContent(true);
        try {
            $this->refused(fn () => $this->uploads->putChunk(FileStaging::owner($principal), $user, $token, $index, $stream));
        } finally {
            fclose($stream);
        }

        return response()->noContent();
    }

    /**
     * Finish an upload
     *
     * Assembles the chunks and writes the file as the user, with mode
     * `0644`, creating the folders of `relative_path`. A file that exists
     * answers `409` `E_EXISTS` with `conflicts` unless `on_conflict` says
     * otherwise; with `skip` the upload is dropped and the answer is `200`
     * with the existing file. All chunks must have arrived (`422`
     * otherwise). The session ends here, also when the write fails; a full
     * disk quota answers `422` `E_LIMIT`.
     *
     * @operationId finishUpload
     *
     * @status 201
     */
    public function finish(FinishUploadRequest $request, string $user, string $token, Principal $principal, FilePolicy $policy): FileEntryResource|JsonResponse
    {
        $this->assertManage($user, $principal, $policy);
        $owner = FileStaging::owner($principal);
        $session = $this->refused(fn () => $this->uploads->target($owner, $user, $token));
        $mode = (string) ($request->validated()['on_conflict'] ?? 'fail');
        $exists = in_array($mode, ['fail', 'skip'], true) && $this->guard(fn () => $this->files->find($user, $session['target'])) !== null;
        if ($exists && $mode === 'fail') {
            throw $this->conflictError(new FileConflict([$session['target']->name()]));
        }
        $skip = $exists && $mode === 'skip';
        $steps = $this->files->planUpload(
            $user, $session['staged'], $session['folder'], $session['target'], $session['nested'], $mode === 'skip' ? 'fail' : $mode,
        )->steps();
        $this->gate->authorize($steps);
        if ($this->gate->dryRun()) {
            return $this->gate->preview($skip ? [] : $steps);
        }
        if ($skip) {
            $this->refused(fn () => $this->uploads->cancel($owner, $user, $token));

            return $this->entry($user, $session['target']);
        }
        $result = $this->refused(fn () => $this->uploads->finish($owner, $user, $token, in_array($mode, ['fail', 'skip'], true) ? null : $mode));

        return $this->created($user, $result['path']);
    }

    /**
     * Cancel an upload
     *
     * Ends the session and removes its chunks. Takes no `dry_run`.
     *
     * @operationId cancelUpload
     *
     * @status 204
     */
    public function cancel(Request $request, string $user, string $token, Principal $principal, FilePolicy $policy): Response
    {
        $this->assertManage($user, $principal, $policy);
        $this->refuseDryRun($request);
        $this->refused(fn () => $this->uploads->cancel(FileStaging::owner($principal), $user, $token));

        return response()->noContent();
    }

    /**
     * Download files
     *
     * Answers the bytes in this request: one file as it is, a folder or
     * several entries of one folder as a zip (symbolic links stored as
     * links). The panel prepares the download with `v-add-fs-download` as
     * the user and arcpanel-nginx sends it (`X-Accel-Redirect`, with range
     * requests). `inline` shows one image, audio or video file, PDF or plain
     * text in the browser; SVG is always an attachment. Too little space in
     * the panel's staging folder answers `507` `E_DISK`. A zip that takes
     * longer than 600 seconds answers `504` from the web server.
     *
     * @operationId downloadFiles
     */
    #[ApiResponse(
        status: 200,
        description: 'The file or the zip: an empty body with `X-Accel-Redirect` so nginx serves the bytes (D51).',
        mediaType: 'application/octet-stream',
    )]
    #[Header('Content-Disposition', description: '`attachment` (or `inline`) with the RFC 5987 file name.', status: 200)]
    #[Header('X-Accel-Redirect', description: 'Internal nginx path of the prepared download (D51).', status: 200)]
    public function download(DownloadFilesRequest $request, string $user, Principal $principal, FilePolicy $policy): Response|JsonResponse
    {
        $this->assertView($user, $principal, $policy);
        $data = $request->validated();
        $paths = array_map(fn ($path) => $this->entryPath((string) $path), array_values((array) $data['paths']));
        foreach ($paths as $path) {
            if (! $path->parent()->equals($paths[0]->parent())) {
                throw ValidationException::withMessages(['paths' => __('Select items of one folder.')]);
            }
        }
        $step = $this->files->planDownload($user, FileStaging::token(), $paths);
        $this->gate->authorize([$step]);
        if ($this->gate->dryRun()) {
            return $this->gate->preview([$step]);
        }
        $ticket = $this->refused(fn () => $this->tickets->create(FileStaging::owner($principal), $user, $paths), download: true);

        return response('', 200, DownloadTickets::headers($ticket['ticket'], $ticket, (bool) ($data['inline'] ?? false)));
    }

    /** Chunks are not planned (§4.3): a dry run of uploadChunk or cancelUpload is refused, so nothing changes. */
    private function refuseDryRun(Request $request): void
    {
        if ($this->gate->dryRun()) {
            throw ValidationException::withMessages(['dry_run' => __('This operation has no dry run.')]);
        }
    }

    /**
     * Run an upload or download step with the statuses of D50 and D55: a
     * session of another key or an expired one is 404, a full quota 422
     * `E_LIMIT`, too little staging space 507 `E_DISK` (for a download, the
     * `E_DISK` of the command too).
     *
     * @template T
     *
     * @param  Closure(): T  $run
     * @return T
     */
    private function refused(Closure $run, bool $download = false): mixed
    {
        try {
            return $run();
        } catch (FileConflict $e) {
            throw $this->conflictError($e);
        } catch (UploadRefused $e) {
            throw match (true) {
                in_array($e->status, [403, 404], true) => new ApiException(ErrorCode::NotExist, __('This upload does not exist or has expired.'), 404),
                $e->errorCode === 'E_DISK' && $download => new ApiException(ErrorCode::Disk, __('The server has not enough space to prepare this download now.'), 507),
                $e->errorCode === 'E_DISK' && $e->status === 422 => new ApiException(ErrorCode::Limit, $e->getMessage(), 422),
                default => new ApiException(ErrorCode::tryFrom($e->errorCode) ?? ErrorCode::Invalid, $e->getMessage(), $e->status),
            };
        } catch (ArcCommandException $e) {
            throw $this->commandError($e);
        }
    }
}
