<?php

namespace App\Http\Controllers\Keys;

use App\Auth\Principal;
use App\Auth\Role;
use App\Http\Controllers\Controller;
use App\Policies\SshKeyPolicy;
use App\Services\Keys\SshKeyService;
use App\Services\Support\OwnerList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * SSH keys of an account (web/list|add|delete/key), E7 batch 2 spec §8.3.
 */
final class SshKeyController extends Controller
{
    public function __construct(private readonly SshKeyService $keys) {}

    /** /ssh-keys: the effective account, or the owner an administrator picked. */
    public function entry(Request $request, OwnerList $owners): RedirectResponse
    {
        return redirect()->route('ssh-keys.list', ['user' => $owners->forCreate($request->query('owner'))]);
    }

    public function index(string $user, Principal $principal, SshKeyPolicy $policy, OwnerList $owners): View
    {
        abort_unless($policy->view($principal, $user), 403);

        return view('keys.ssh-keys', [
            'user' => $user,
            'keys' => $this->keys->forUser($user),
            'owners' => $principal->actingRole() === Role::Admin ? $owners->pickable() : null,
            'canManage' => $policy->manage($principal, $user),
        ]);
    }

    public function store(Request $request, string $user, Principal $principal, SshKeyPolicy $policy): RedirectResponse
    {
        abort_unless($policy->manage($principal, $user), 403);
        $data = $request->validate([
            'key' => ['required', 'string', 'max:16384', 'not_regex:/[\r\n\x00]/'],
        ], ['key.required' => sprintf(__('Field "%s" can not be blank.'), __('SSH Key'))]);

        $back = redirect()->route('ssh-keys.list', ['user' => $user]);
        $key = SshKeyService::prepare($data['key'], $this->keys->forUser($user));
        if ($key === null) {
            return $back->withInput()->with('error', __('SSH Key already exists.'));
        }
        $result = $this->keys->create($user, $key);
        if (! $result->ok()) {
            return $back->withInput()->with('error', $result->errorMessage());
        }

        return $back->with('ok', __('SSH Key has been created successfully.'));
    }

    public function destroy(string $user, string $id, Principal $principal, SshKeyPolicy $policy): RedirectResponse
    {
        abort_unless($policy->manage($principal, $user), 403);
        abort_unless(array_key_exists($id, $this->keys->forUser($user)), 404);

        $result = $this->keys->delete($user, $id);
        $back = redirect()->route('ssh-keys.list', ['user' => $user]);

        return $result->ok() ? $back->with('ok', sprintf(__('SSH key %s has been deleted.'), $id)) : $back->with('error', $result->firstError());
    }
}
