<?php

namespace App\Http\Controllers\Concerns;

use App\Api\ApiException;
use App\Api\ErrorCode;
use App\Arc\ArcCommandException;
use App\Arc\ExitCode;
use App\Auth\Principal;
use App\Http\Resources\V2\FileEntryResource;
use App\Http\Resources\V2\LocatedFileEntry;
use App\Policies\FilePolicy;
use App\Services\Files\FileConflict;
use App\Services\Files\FilePath;
use App\Services\Files\FileService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * What the file controllers of REST API v2 share (E34 batch 5 spec §4):
 * the FilePolicy checks, the path rules of D48 and the statuses of failed
 * file commands. The controller holds the FileService as $files.
 *
 * @property-read FileService $files
 */
trait AnswersFileRequests
{
    /** {user} is an account the key may see; 404 otherwise. */
    private function assertView(string $user, Principal $principal, FilePolicy $policy): void
    {
        abort_unless($policy->view($principal, $user), 404);
    }

    /** Changes: not on a suspended account unless the key is an administrator's (E3 D10). */
    private function assertManage(string $user, Principal $principal, FilePolicy $policy): void
    {
        $this->assertView($user, $principal, $policy);
        abort_unless($policy->manage($principal, $user), 403);
    }

    /** A path below the home directory, not the home itself (404). */
    private function entryPath(string $path): FilePath
    {
        $entry = FilePath::from($path);
        abort_if($entry->isRoot(), 404);

        return $entry;
    }

    private function validName(string $name, string $field): string
    {
        if (! FilePath::validName($name)) {
            throw ValidationException::withMessages([$field => __('Enter a valid name.')]);
        }

        return $name;
    }

    /**
     * Answer 201 with the entry at $path and its Location.
     */
    private function created(string $user, FilePath $path): JsonResponse
    {
        return $this->entry($user, $path)->response()->setStatusCode(201)
            ->header('Location', route('api.v2.files.info', ['user' => $user, 'path' => $path->relative()]));
    }

    private function entry(string $user, FilePath $path): FileEntryResource
    {
        return new FileEntryResource(LocatedFileEntry::at($this->guard(fn () => $this->files->info($user, $path)), $path));
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
     * Names that exist (D12, D55): 409 `E_EXISTS` with `conflicts`. The
     * exception's own render() would answer without the API headers.
     */
    private function conflictError(FileConflict $e): ApiException
    {
        return new ApiException(ErrorCode::Exists, $e->getMessage(), 409, ['conflicts' => $e->names]);
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
