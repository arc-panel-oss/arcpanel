<?php

namespace App\Http\Controllers\Keys;

use App\Auth\Principal;
use App\Auth\Role;
use App\Auth\SudoMode;
use App\Http\Controllers\Controller;
use App\Policies\AccessKeyPolicy;
use App\Services\Keys\AccessKey;
use App\Services\Keys\AccessKeyRestrictions;
use App\Services\Keys\AccessKeyService;
use App\Services\Keys\AccessKeySets;
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
            // v2 full access, v2 areas, v1 (spec 2026-10-08-008 U3)
            'groups' => AccessKeySets::groups($policy->allowedSets($user, $this->keys->permissionSets())),
        ]);
    }

    /**
     * The new key with its secret, answered directly (no redirect): the
     * secret is never stored in the session or a flash (spec §8.2).
     */
    public function store(Request $request, string $user, Principal $principal, AccessKeyPolicy $policy, SudoMode $sudo): Response|RedirectResponse
    {
        $this->authorizeManage($user, $principal, $policy);
        // A key outlives a password change, so it needs the password (E39 W2 D12)
        if ($sudo->required($principal, $user)) {
            return $sudo->challenge($request);
        }
        $allowed = $policy->allowedSets($user, $this->keys->permissionSets());
        $data = $request->validate([
            'sets' => ['required', 'array', 'min:1'],
            'sets.*' => ['required', 'string', 'max:64'],
            // the rule of v-add-access-key, so a refused comment shows next to its field
            'comment' => ['nullable', 'string', 'max:66', 'regex:'.AccessKeyService::COMMENT],
            // the rules of v-add-access-key for the restrictions (E43 B6)
            'allowed_ips' => ['nullable', 'string', 'max:2000', function (string $attribute, mixed $value, \Closure $fail) {
                $list = AccessKeyRestrictions::list((string) $value);
                if (count($list) > AccessKeyRestrictions::MAX_IPS) {
                    $fail(sprintf(__('Use at most %d addresses or networks.'), AccessKeyRestrictions::MAX_IPS));
                } elseif (count($list) !== count(array_unique($list))) {
                    $fail(__('Each address or network may be listed once.'));
                }
                foreach ($list as $entry) {
                    if (! AccessKeyRestrictions::isAddressOrNetwork($entry)) {
                        $fail(sprintf(__('%s is not an IPv4 or IPv6 address or network.'), $entry));

                        return;
                    }
                }
            }],
            'expires_at' => ['nullable', 'string', 'max:40', AccessKeyRestrictions::expiryRule()],
        ], [
            'sets.required' => sprintf(__('Field "%s" can not be blank.'), __('Permissions')),
            'comment.max' => __('Use at most 66 characters.'),
            'comment.regex' => __('Use 2 to 66 letters, digits, spaces, dots, dashes or underscores, starting and ending with a letter or digit.'),
        ]);
        $sets = array_values(array_unique($data['sets']));
        foreach ($sets as $set) {
            if (! in_array($set, $allowed, true)) {
                return back()->withInput()->withErrors(['sets' => sprintf(__('API %s not allowed'), $set)]);
            }
        }

        $key = $this->keys->create($user, $sets, trim((string) ($data['comment'] ?? '')),
            AccessKeyRestrictions::list((string) ($data['allowed_ips'] ?? '')), AccessKeyRestrictions::timestamp($data['expires_at'] ?? null));
        if (! $key instanceof AccessKey) {
            return back()->withInput()->with('error', $key->errorMessage());
        }

        return response()->view('keys.access-key', [
            'user' => $user,
            'key' => $key,
            'created' => true,
            'canManage' => true,
            // the panel origin of the usage examples (spec 2026-10-08-008 U6)
            'endpoint' => $request->getSchemeAndHttpHost(),
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
