<?php

namespace App\Http\Controllers\Files;

use App\Auth\Principal;
use App\Auth\Role;
use App\Http\Controllers\Controller;
use App\Policies\FilePolicy;
use App\Services\Files\CurlCommand;
use App\Services\Files\FetchRequest;
use App\Services\Files\FileFetch;
use App\Services\Files\FilePath;
use App\Services\Files\RemoteFetches;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Upload from URL in the file manager
 * (docs/features/specs/2026-10-22-001-e3-upload-from-url-design.md §3, §6.1):
 * the fetches of an account, a new one from the Simple, cURL or Custom
 * form, and cancel or remove. Every answer carries the account's list.
 */
final class FileFetchController extends Controller
{
    public const MODES = ['simple', 'curl', 'custom'];

    public function __construct(private readonly RemoteFetches $fetches) {}

    public function index(string $user, Principal $principal, FilePolicy $policy): JsonResponse
    {
        abort_unless($policy->view($principal, $user), 403);

        return response()->json($this->listing($user));
    }

    public function store(Request $request, string $user, Principal $principal, FilePolicy $policy): JsonResponse
    {
        abort_unless($policy->manage($principal, $user), 403);
        $data = $request->validate([
            'mode' => ['required', Rule::in(self::MODES)],
            'path' => ['nullable', 'string'],
            'url' => ['nullable', 'string'],
            'command' => ['nullable', 'string', 'max:65536'],
            'headers' => ['nullable', 'array'],
            'auth' => ['nullable', 'array'],
            'name' => ['nullable', 'string', 'max:255'],
            'onConflict' => ['nullable', Rule::in(['rename', 'overwrite', 'skip'])],
            'allowPrivate' => ['nullable', 'boolean'],
        ]);
        $private = (bool) ($data['allowPrivate'] ?? false);
        if ($private && $principal->actingRole() !== Role::Admin) {
            throw ValidationException::withMessages(['allowPrivate' => __('Only administrators may download from private addresses.')]);
        }

        $fields = match ($data['mode']) {
            'simple' => ['url' => $data['url'] ?? '', 'name' => $data['name'] ?? ''],
            'custom' => [
                'url' => $data['url'] ?? '',
                'headers' => $data['headers'] ?? [],
                'auth' => $data['auth'] ?? [],
                'name' => $data['name'] ?? '',
            ],
            'curl' => $this->curl((string) ($data['command'] ?? '')),
        };
        if ($data['mode'] === 'curl' && ($data['name'] ?? '') !== '') {
            $fields['name'] = $data['name'];
        }
        // Errors of a parsed command belong to the command field
        $fetch = FetchRequest::from($fields, $data['mode'] === 'curl'
            ? ['url' => 'command', 'headers' => 'command', 'auth' => 'command']
            : []);

        $id = $this->fetches->start($user, FilePath::from($data['path'] ?? ''), $fetch, $data['onConflict'] ?? 'rename', $private);

        return response()->json(['id' => $id, ...$this->listing($user)], 201);
    }

    public function destroy(string $user, string $id, Principal $principal, FilePolicy $policy): JsonResponse
    {
        abort_unless($policy->manage($principal, $user), 403);
        abort_if($this->fetches->find($user, $id) === null, 404);
        $this->fetches->delete($user, $id);

        return response()->json($this->listing($user));
    }

    /** @return array<string, mixed> */
    private function curl(string $command): array
    {
        try {
            return CurlCommand::parse($command);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['command' => $e->getMessage()]);
        }
    }

    /** @return array{fetches: list<array<string, mixed>>, active: int} */
    private function listing(string $user): array
    {
        $list = $this->fetches->list($user);

        return [
            'fetches' => array_map(fn (FileFetch $f) => $f->toArray(), $list),
            'active' => count(array_filter($list, fn (FileFetch $f) => $f->active())),
        ];
    }
}
