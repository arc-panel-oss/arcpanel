<?php

namespace App\Http\Controllers\Files;

use App\Arc\ArcCommandException;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\FilePolicy;
use App\Services\Files\FileEntry;
use App\Services\Files\FilePath;
use App\Services\Files\FileService;
use App\Services\Files\UploadRefused;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * JSON routes of the file manager island (E3 spec §6.4). Routes with {user}
 * run through `effective-user` (404 outside the scope) and `files.enabled`;
 * paths are relative to the home directory and a path that leaves it
 * answers 404.
 */
final class FileController extends Controller
{
    private const MODE = '/^[0-7]?[0-7]{3}$/';

    public function __construct(private readonly FileService $files) {}

    public function list(Request $request, string $user, Principal $principal, FilePolicy $policy): JsonResponse
    {
        abort_unless($policy->view($principal, $user), 403);
        $data = $request->validate([
            'path' => ['nullable', 'string'],
            'offset' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'sort' => ['nullable', Rule::in(['name', 'size', 'mtime'])],
            'order' => ['nullable', Rule::in(['asc', 'desc'])],
            'hidden' => ['nullable', Rule::in(['yes', 'no'])],
        ]);
        $path = FilePath::from($data['path'] ?? '');

        return $this->guard(function () use ($user, $path, $data) {
            $listing = $this->files->list(
                $user, $path, (int) ($data['offset'] ?? 0), (int) ($data['limit'] ?? 2000),
                (string) ($data['sort'] ?? 'name'), (string) ($data['order'] ?? 'asc'), ($data['hidden'] ?? 'yes') === 'yes',
            );

            return [
                'path' => $path->relative(),
                'dir' => $this->files->info($user, $path)->toArray(),
                'total' => $listing['total'],
                'offset' => $listing['offset'],
                'entries' => array_map(fn (FileEntry $e) => $e->toArray(), $listing['entries']),
            ];
        });
    }

    public function info(Request $request, string $user, Principal $principal, FilePolicy $policy): JsonResponse
    {
        abort_unless($policy->view($principal, $user), 403);
        $path = $this->path($request);

        return $this->guard(fn () => ['path' => $path->relative(), 'entry' => $this->files->info($user, $path)->toArray()]);
    }

    public function read(Request $request, string $user, Principal $principal, FilePolicy $policy): JsonResponse
    {
        abort_unless($policy->view($principal, $user), 403);
        $path = $this->path($request);

        return $this->guard(fn () => ['path' => $path->relative()] + $this->files->read($user, $path));
    }

    public function size(Request $request, string $user, Principal $principal, FilePolicy $policy): JsonResponse
    {
        abort_unless($policy->view($principal, $user), 403);
        $path = $this->path($request);

        return $this->guard(fn () => ['path' => $path->relative(), 'size' => $this->files->size($user, $path)]);
    }

    public function search(Request $request, string $user, Principal $principal, FilePolicy $policy): JsonResponse
    {
        abort_unless($policy->view($principal, $user), 403);
        $data = $request->validate([
            'path' => ['nullable', 'string'],
            'q' => ['required', 'string', 'min:1', 'max:100', 'not_regex:/[\/\x00-\x1f]/'],
        ]);
        $path = FilePath::from($data['path'] ?? '');

        return $this->guard(fn () => ['path' => $path->relative()] + $this->files->search($user, $path, (string) $data['q']));
    }

    public function save(Request $request, string $user, Principal $principal, FilePolicy $policy): JsonResponse
    {
        abort_unless($policy->manage($principal, $user), 403);
        $data = $request->validate([
            'path' => ['required', 'string'],
            'content' => ['present', 'nullable', 'string', 'max:'.FileService::EDIT_MAX],
            'hash' => ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/'],
        ]);
        $path = FilePath::from($data['path']);
        abort_if($path->isRoot(), 404);

        return $this->guard(fn () => ['path' => $path->relative()] + $this->files->save($user, $path, (string) ($data['content'] ?? ''), $data['hash'] ?? null));
    }

    public function mkdir(Request $request, string $user, Principal $principal, FilePolicy $policy): JsonResponse
    {
        return $this->create($request, $user, $principal, $policy, fn (FilePath $p) => $this->files->mkdir($user, $p));
    }

    public function touch(Request $request, string $user, Principal $principal, FilePolicy $policy): JsonResponse
    {
        return $this->create($request, $user, $principal, $policy, fn (FilePath $p) => $this->files->touch($user, $p));
    }

    public function rename(Request $request, string $user, Principal $principal, FilePolicy $policy): JsonResponse
    {
        abort_unless($policy->manage($principal, $user), 403);
        $data = $request->validate(['path' => ['required', 'string'], 'name' => ['required', 'string', 'max:255']]);
        $path = FilePath::from($data['path']);
        abort_if($path->isRoot(), 404);
        $this->assertName((string) $data['name']);

        return $this->guard(fn () => ['path' => $this->files->rename($user, $path, (string) $data['name'])->relative()]);
    }

    public function copy(Request $request, string $user, Principal $principal, FilePolicy $policy): JsonResponse
    {
        return $this->transfer($request, $user, $principal, $policy, false);
    }

    public function move(Request $request, string $user, Principal $principal, FilePolicy $policy): JsonResponse
    {
        return $this->transfer($request, $user, $principal, $policy, true);
    }

    public function delete(Request $request, string $user, Principal $principal, FilePolicy $policy): JsonResponse
    {
        abort_unless($policy->manage($principal, $user), 403);
        $paths = $this->paths($request);
        $this->guard(fn () => $this->files->delete($user, $paths));

        return response()->json(['deleted' => count($paths)]);
    }

    public function chmod(Request $request, string $user, Principal $principal, FilePolicy $policy): JsonResponse
    {
        abort_unless($policy->manage($principal, $user), 403);
        $data = $request->validate([
            'mode' => ['required', 'string', 'regex:'.self::MODE],
            'filesMode' => ['nullable', 'string', 'regex:'.self::MODE],
            'recursive' => ['nullable', 'boolean'],
        ]);
        $paths = $this->paths($request);
        $this->guard(function () use ($user, $paths, $data) {
            foreach ($paths as $path) {
                $this->files->chmod($user, $path, (string) $data['mode'], (bool) ($data['recursive'] ?? false), $data['filesMode'] ?? null);
            }
        });

        return response()->json(['changed' => count($paths)]);
    }

    public function compress(Request $request, string $user, Principal $principal, FilePolicy $policy): JsonResponse
    {
        abort_unless($policy->manage($principal, $user), 403);
        $paths = $this->paths($request, sameFolder: true);
        $name = (string) $request->validate(['name' => ['required', 'string', 'max:255', 'regex:/\.zip$/i']])['name'];
        $this->assertName($name);

        return $this->guard(fn () => ['path' => $this->files->compress($user, $paths, $name)->relative()]);
    }

    public function extract(Request $request, string $user, Principal $principal, FilePolicy $policy): JsonResponse
    {
        abort_unless($policy->manage($principal, $user), 403);
        $data = $request->validate(['path' => ['required', 'string'], 'dest' => ['nullable', 'string']]);
        $archive = FilePath::from($data['path']);
        $dest = FilePath::from($data['dest'] ?? $archive->parent()->relative());
        abort_if($archive->isRoot(), 404);

        return $this->guard(function () use ($user, $archive, $dest) {
            $this->files->extract($user, $archive, $dest);

            return ['path' => $dest->relative()];
        });
    }

    /** @param  Closure(FilePath): void  $action */
    private function create(Request $request, string $user, Principal $principal, FilePolicy $policy, Closure $action): JsonResponse
    {
        abort_unless($policy->manage($principal, $user), 403);
        $data = $request->validate(['path' => ['nullable', 'string'], 'name' => ['required', 'string', 'max:255']]);
        $this->assertName((string) $data['name']);
        $path = FilePath::from($data['path'] ?? '')->child((string) $data['name']);

        return $this->guard(function () use ($action, $path) {
            $action($path);

            return ['path' => $path->relative()];
        }, 201);
    }

    private function transfer(Request $request, string $user, Principal $principal, FilePolicy $policy, bool $move): JsonResponse
    {
        abort_unless($policy->manage($principal, $user), 403);
        $paths = $this->paths($request);
        $data = $request->validate([
            'dest' => ['present', 'nullable', 'string'],
            'onConflict' => ['nullable', Rule::in(FileService::CONFLICT_MODES)],
        ]);
        $dest = FilePath::from($data['dest'] ?? '');
        $onConflict = $data['onConflict'] ?? null;

        return $this->guard(fn () => [
            'dest' => $dest->relative(),
            'names' => $move ? $this->files->move($user, $paths, $dest, $onConflict) : $this->files->copy($user, $paths, $dest, $onConflict),
        ]);
    }

    private function path(Request $request): FilePath
    {
        return FilePath::from((string) ($request->validate(['path' => ['nullable', 'string']])['path'] ?? ''));
    }

    /** @return list<FilePath> */
    private function paths(Request $request, bool $sameFolder = false): array
    {
        $data = $request->validate(['paths' => ['required', 'array', 'min:1', 'max:1000'], 'paths.*' => ['required', 'string', 'distinct']]);
        $paths = array_map(fn ($p) => FilePath::from((string) $p), array_values($data['paths']));
        foreach ($paths as $path) {
            abort_if($path->isRoot(), 404);
            if ($sameFolder && ! $path->parent()->equals($paths[0]->parent())) {
                throw ValidationException::withMessages(['paths' => __('Select items of one folder.')]);
            }
        }

        return $paths;
    }

    private function assertName(string $name): void
    {
        if (! FilePath::validName($name)) {
            throw ValidationException::withMessages(['name' => __('Enter a valid name.')]);
        }
    }

    /**
     * Runs $action and answers its result as JSON; disk and size failures of
     * the commands get the file manager's statuses (E3 spec §8).
     */
    private function guard(Closure $action, int $status = 200): JsonResponse
    {
        try {
            $result = $action();
        } catch (ArcCommandException $e) {
            $user = request()->route('user');
            throw UploadRefused::fromCommand($e, is_string($user) ? $this->files->account($user)->home : null);
        }

        return response()->json($result ?? ['ok' => true], $status);
    }
}
