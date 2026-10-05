<?php

namespace App\Http\Controllers\Files;

use App\Arc\ExitCode;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\BackupPolicy;
use App\Services\Backups\BackupService;
use App\Services\Files\FilePath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The file manager's snapshot mode (E7 batch 1 spec §10.3): folders of a
 * restic snapshot, read-only, and "Restore" of one file or folder through
 * the backup queue. Paths are relative to the home directory, as in the
 * other file manager routes.
 */
final class SnapshotFileController extends Controller
{
    public function __construct(private readonly BackupService $backups) {}

    /** GET files/{user}/api/snapshots/{snapshot}/list?path= */
    public function list(Request $request, string $user, string $snapshot, Principal $principal, BackupPolicy $policy): JsonResponse
    {
        abort_unless($policy->view($principal, $user), 403);
        $this->assertIncremental($user);
        $path = FilePath::from(is_string($request->query('path')) ? $request->query('path') : '');
        $listing = $this->backups->snapshotFolder($user, $snapshot, $path->absolute(self::home($user)));

        return response()->json([
            'path' => $path->relative(),
            'dir' => ['name' => $path->name(), 'type' => 'd', 'dir' => true, 'writable' => false],
            'total' => count($listing->entries),
            'offset' => 0,
            'entries' => $listing->entries,
        ]);
    }

    /** POST files/{user}/api/snapshots/{snapshot}/restore {path} */
    public function restore(Request $request, string $user, string $snapshot, Principal $principal, BackupPolicy $policy): JsonResponse
    {
        abort_unless($policy->restore($principal, $user), 403);
        $this->assertIncremental($user);
        $data = $request->validate(['path' => ['required', 'string', 'max:4096']]);
        $path = FilePath::from($data['path']);
        abort_if($path->isRoot(), 422);

        $result = $this->backups->restoreFile($user, $snapshot, $path->absolute(self::home($user)));
        if ($result->ok()) {
            return response()->json(['message' => sprintf(__('%s will be restored from the snapshot. The restore runs in the background.'), $path->name())]);
        }

        $code = match ($result->exit()) {
            ExitCode::Exists => 409,
            ExitCode::NotExist => 404,
            ExitCode::Invalid, ExitCode::Args => 422,
            default => 500,
        };
        $message = match ($result->exit()) {
            ExitCode::Exists => __('An existing restoration task is already running. Please wait for it to finish before launching it again.'),
            ExitCode::Invalid => __('This name cannot be restored on its own: restore its folder instead.'),
            default => $result->message(),
        };

        return response()->json(['error' => ['code' => $result->exit()?->name ?? 'E_FAILED', 'message' => $message]], $code);
    }

    /** Hosting users live in /home (func/main.sh). */
    private static function home(string $user): string
    {
        return '/home/'.$user;
    }

    private function assertIncremental(string $user): void
    {
        abort_unless($this->backups->incremental($user), 404);
    }
}
