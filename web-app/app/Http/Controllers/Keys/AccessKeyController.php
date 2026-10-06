<?php

namespace App\Http\Controllers\Keys;

use App\Auth\Principal;
use App\Auth\Role;
use App\Http\Controllers\Controller;
use App\Policies\AccessKeyPolicy;
use App\Services\Keys\AccessKey;
use App\Services\Keys\AccessKeyService;
use App\Services\Support\OwnerList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * API access keys of an account (web/list|add|delete|bulk/access-key),
 * E7 batch 2 spec §8.1–§8.2. Every route answers 404 when API_SYSTEM gives
 * the owner no keys.
 */
final class AccessKeyController extends Controller
{
    public function __construct(private readonly AccessKeyService $keys) {}

    /** /access-keys: the effective account, or the owner an administrator picked. */
    public function entry(Request $request, OwnerList $owners): RedirectResponse
    {
        return redirect()->route('access-keys.list', ['user' => $owners->forCreate($request->query('owner'))]);
    }

    public function index(string $user, Principal $principal, AccessKeyPolicy $policy, OwnerList $owners): View
    {
        $this->authorizeView($user, $principal, $policy);

        return view('keys.access-keys', [
            'user' => $user,
            'keys' => $this->keys->forUser($user),
            'owners' => $principal->actingRole() === Role::Admin ? $owners->pickable() : null,
            'canManage' => $policy->manage($principal, $user),
        ]);
    }

    public function create(string $user, Principal $principal, AccessKeyPolicy $policy): View
    {
        $this->authorizeManage($user, $principal, $policy);

        return view('keys.access-key-create', [
            'user' => $user,
            'sets' => $policy->allowedSets($user, $this->keys->permissionSets()),
        ]);
    }

    /**
     * The new key with its secret, answered directly (no redirect): the
     * secret is never stored in the session or a flash (spec §8.2).
     */
    public function store(Request $request, string $user, Principal $principal, AccessKeyPolicy $policy): Response|RedirectResponse
    {
        $this->authorizeManage($user, $principal, $policy);
        $allowed = $policy->allowedSets($user, $this->keys->permissionSets());
        $data = $request->validate([
            'sets' => ['required', 'array', 'min:1'],
            'sets.*' => ['required', 'string', 'max:64'],
            'comment' => ['nullable', 'string', 'max:255', 'not_regex:/[\r\n\x00\'"]/'],
        ], ['sets.required' => sprintf(__('Field "%s" can not be blank.'), __('Permissions'))]);
        $sets = array_values(array_unique($data['sets']));
        foreach ($sets as $set) {
            if (! in_array($set, $allowed, true)) {
                return back()->withInput()->withErrors(['sets' => sprintf(__('API %s not allowed'), $set)]);
            }
        }

        $key = $this->keys->create($user, $sets, trim((string) ($data['comment'] ?? '')));
        if (! $key instanceof AccessKey) {
            return back()->withInput()->with('error', $key->errorMessage());
        }

        return response()->view('keys.access-key', [
            'user' => $user,
            'key' => $key,
            'created' => true,
            'canManage' => true,
        ])->header('Cache-Control', 'no-store');
    }

    public function show(string $user, string $key, Principal $principal, AccessKeyPolicy $policy): View
    {
        $this->authorizeView($user, $principal, $policy);

        return view('keys.access-key', [
            'user' => $user,
            'key' => $this->keys->find($user, $key),
            'created' => false,
            'canManage' => $policy->manage($principal, $user),
        ]);
    }

    public function destroy(string $user, string $key, Principal $principal, AccessKeyPolicy $policy): RedirectResponse
    {
        $this->authorizeManage($user, $principal, $policy);
        $this->keys->find($user, $key);

        $result = $this->keys->delete([$key]);
        $back = redirect()->route('access-keys.list', ['user' => $user]);

        return $result->ok() ? $back->with('ok', sprintf(__('Access key %s has been deleted.'), $key)) : $back->with('error', $result->firstError());
    }

    /** POST /app/access-keys/{user}/bulk {action: delete, items[]}: keys of this owner only (spec §12.5). */
    public function bulk(Request $request, string $user, Principal $principal, AccessKeyPolicy $policy): RedirectResponse
    {
        $this->authorizeManage($user, $principal, $policy);
        $data = $request->validate([
            'action' => ['required', 'in:delete'],
            'items' => ['required', 'array', 'min:1', 'max:1000'],
            'items.*' => ['required', 'string', 'regex:/^[A-Za-z0-9]{20}$/'],
        ]);
        $owned = array_map(fn (AccessKey $key) => $key->id, $this->keys->forUser($user));
        $items = array_values(array_unique($data['items']));
        foreach ($items as $key) {
            abort_unless(in_array($key, $owned, true), 404);
        }

        return $this->keys->delete($items)->flash(redirect()->route('access-keys.list', ['user' => $user]));
    }

    private function authorizeView(string $user, Principal $principal, AccessKeyPolicy $policy): void
    {
        abort_unless($policy->available($user), 404);
        abort_unless($policy->view($principal, $user), 403);
    }

    private function authorizeManage(string $user, Principal $principal, AccessKeyPolicy $policy): void
    {
        abort_unless($policy->available($user), 404);
        abort_unless($policy->manage($principal, $user), 403);
    }
}
