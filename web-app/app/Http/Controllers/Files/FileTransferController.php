<?php

namespace App\Http\Controllers\Files;

use App\Arc\SysConfig;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\FilePolicy;
use App\Services\Files\DownloadTickets;
use App\Services\Files\FilePath;
use App\Services\Files\FileService;
use App\Services\Files\UploadSessions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Uploads in resumable chunks and download tickets of the file manager
 * (E3 spec §6.2, §6.3). Sessions and tickets belong to the login user that
 * created them.
 */
final class FileTransferController extends Controller
{
    public function __construct(
        private readonly UploadSessions $uploads,
        private readonly DownloadTickets $tickets,
        private readonly SysConfig $config,
    ) {}

    public function init(Request $request, string $user, Principal $principal, FilePolicy $policy): JsonResponse
    {
        abort_unless($policy->manage($principal, $user), 403);
        $data = $request->validate([
            'path' => ['nullable', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'relativePath' => ['nullable', 'string', 'max:4096'],
            'size' => ['required', 'integer', 'min:0'],
            'lastModified' => ['nullable', 'integer', 'min:0'],
        ]);
        $name = (string) $data['name'];
        $relative = trim((string) ($data['relativePath'] ?? ''), '/');
        $path = FilePath::from($data['path'] ?? '');
        if (! FilePath::validName($name) || ($relative !== '' && basename($relative) !== $name)) {
            throw ValidationException::withMessages(['name' => __('Enter a valid name.')]);
        }
        if ($relative !== '') {
            // Folders of a folder upload must stay below the target folder
            $path->join($relative);
        }

        return response()->json($this->uploads->init($principal->loginUser, $user, [
            'path' => $path,
            'name' => $name,
            'relativePath' => $relative,
            'size' => (int) $data['size'],
            'lastModified' => (int) ($data['lastModified'] ?? 0),
        ], UploadSessions::maxBytes($this->config->get('FILE_MANAGER_UPLOAD_MAX')), $this->config->is('DISK_QUOTA', 'yes')));
    }

    public function chunk(Request $request, string $user, string $token, int $index, Principal $principal, FilePolicy $policy): JsonResponse
    {
        abort_unless($policy->manage($principal, $user), 403);
        // The request body as a stream (php://input), never read into memory
        $stream = $request->getContent(true);
        try {
            $received = $this->uploads->putChunk($principal->loginUser, $user, $token, $index, $stream);
        } finally {
            fclose($stream);
        }

        return response()->json(['received' => $received]);
    }

    public function finish(Request $request, string $user, string $token, Principal $principal, FilePolicy $policy): JsonResponse
    {
        abort_unless($policy->manage($principal, $user), 403);
        $data = $request->validate(['onConflict' => ['nullable', Rule::in(FileService::CONFLICT_MODES)]]);
        $result = $this->uploads->finish($principal->loginUser, $user, $token, $data['onConflict'] ?? null);

        return response()->json(['path' => $result['path']->relative(), 'name' => $result['name']], 201);
    }

    public function cancel(string $user, string $token, Principal $principal, FilePolicy $policy): JsonResponse
    {
        abort_unless($policy->manage($principal, $user), 403);
        $this->uploads->cancel($principal->loginUser, $user, $token);

        return response()->json(['cancelled' => true]);
    }

    /** Create a ticket for one file, or a zip of several entries of one folder. */
    public function download(Request $request, string $user, Principal $principal, FilePolicy $policy): JsonResponse
    {
        abort_unless($policy->view($principal, $user), 403);
        $data = $request->validate(['paths' => ['required', 'array', 'min:1', 'max:1000'], 'paths.*' => ['required', 'string', 'distinct']]);
        $paths = array_map(fn ($p) => FilePath::from((string) $p), array_values($data['paths']));
        foreach ($paths as $path) {
            abort_if($path->isRoot(), 404);
            if (! $path->parent()->equals($paths[0]->parent())) {
                throw ValidationException::withMessages(['paths' => __('Select items of one folder.')]);
            }
        }
        $ticket = $this->tickets->create($principal->loginUser, $user, $paths);

        return response()->json([
            'url' => route('files.download', ['user' => $user, 'ticket' => $ticket['ticket']]),
            'name' => $ticket['name'],
            'size' => $ticket['size'],
            'mime' => $ticket['mime'],
        ], 201);
    }

    /** The ticket's bytes, sent by hestia-nginx (X-Accel-Redirect). */
    public function show(Request $request, string $user, string $ticket, Principal $principal, FilePolicy $policy): Response
    {
        abort_unless($policy->view($principal, $user), 403);
        $meta = $this->tickets->resolve($principal->loginUser, $user, $ticket);
        $inline = $request->boolean('inline') && preg_match('#^(image/|video/|audio/|application/pdf$|text/plain$)#', $meta['mime']) === 1;
        // SVG can carry scripts: never inline
        if ($meta['mime'] === 'image/svg+xml') {
            $inline = false;
        }
        $fallback = preg_replace('/[^\x20-\x7e]|["\\\\]/', '_', $meta['name']) ?: 'download';

        return response('', 200, [
            'X-Accel-Redirect' => DownloadTickets::ACCEL_PREFIX.$ticket,
            'Content-Type' => $meta['mime'],
            'Content-Disposition' => sprintf('%s; filename="%s"; filename*=UTF-8\'\'%s', $inline ? 'inline' : 'attachment', $fallback, rawurlencode($meta['name'])),
            'X-Content-Type-Options' => 'nosniff',
            // Browsers refuse to show a PDF in a sandbox; their PDF viewer runs no page scripts
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'"
                .($meta['mime'] === 'application/pdf' ? '' : '; sandbox'),
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
