<?php

namespace App\Http\Controllers\Keys;

use App\Arc\CommandResult;
use App\Auth\Principal;
use App\Auth\Role;
use App\Http\Controllers\Controller;
use App\Policies\UserSecretPolicy;
use App\Services\Keys\UserSecretManager;
use App\Services\Support\OwnerList;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * Secret store of an account (E38 spec §7). Values are write-only: they go
 * to the commands in a Secret file and are never shown, flashed or kept in
 * the session.
 */
final class SecretController extends Controller
{
    public function __construct(private readonly UserSecretManager $secrets) {}

    /** /secrets: the effective account, or the owner an administrator picked. */
    public function entry(Request $request, OwnerList $owners): RedirectResponse
    {
        return redirect()->route('secrets.list', ['user' => $owners->forCreate($request->query('owner'))]);
    }

    public function index(string $user, Principal $principal, UserSecretPolicy $policy, OwnerList $owners): View
    {
        abort_unless($policy->view($principal, $user), 403);

        return view('keys.secret-list', [
            'user' => $user,
            'secrets' => $this->secrets->forUser($user),
            'owners' => $principal->actingRole() === Role::Admin ? $owners->pickable() : null,
            'canManage' => $policy->manage($principal, $user),
        ]);
    }

    public function show(Request $request, string $user, Principal $principal, UserSecretPolicy $policy): View
    {
        abort_unless($policy->view($principal, $user), 403);
        $secret = $this->secrets->find($user, (string) $request->query('name', ''));
        abort_if($secret === null, 404);

        return view('keys.secret', [
            'user' => $user,
            'secret' => $secret,
            'canManage' => $policy->manage($principal, $user),
        ]);
    }

    public function store(Request $request, string $user, Principal $principal, UserSecretPolicy $policy): RedirectResponse
    {
        abort_unless($policy->manage($principal, $user), 403);
        $data = $this->validated($request, [
            'name' => ['required', 'string', $this->nameRule()],
            'value' => $this->valueRules(),
            'description' => $this->descriptionRules(),
        ]);
        $name = $data['name'];

        $result = $this->secrets->create($user, $name, $data['value'], (string) ($data['description'] ?? ''));
        if (! $result->ok()) {
            return redirect()->route('secrets.list', ['user' => $user])->withInput($request->except('value'))
                ->with('error', UserSecretManager::reason($result));
        }

        return redirect()->route('secrets.show', ['user' => $user, 'name' => $name])
            ->with('ok', sprintf(__('Secret %s has been created. Use it as %s.'), $name, UserSecretManager::reference($name)));
    }

    public function value(Request $request, string $user, Principal $principal, UserSecretPolicy $policy): RedirectResponse
    {
        $name = $this->existing($request, $user, $principal, $policy);
        $data = $this->validated($request, ['value' => $this->valueRules(), 'restart' => ['nullable', 'boolean']]);
        $restart = (bool) ($data['restart'] ?? false);

        $result = $this->secrets->replace($user, $name, $data['value'], $restart);
        $back = redirect()->route('secrets.show', ['user' => $user, 'name' => $name]);
        if (! $result->ok()) {
            return $back->with('error', UserSecretManager::reason($result));
        }

        return $back->with('ok', sprintf(__('Secret %s has been changed.'), $name).$this->usedBySuffix($result, $restart));
    }

    public function rollback(Request $request, string $user, Principal $principal, UserSecretPolicy $policy): RedirectResponse
    {
        $name = $this->existing($request, $user, $principal, $policy);
        $data = $request->validate([
            'version' => ['required', 'string', 'regex:/^[1-9][0-9]{0,8}$/'],
            'restart' => ['nullable', 'boolean'],
        ]);
        $restart = (bool) ($data['restart'] ?? false);

        $result = $this->secrets->rollback($user, $name, $data['version'], $restart);
        $back = redirect()->route('secrets.show', ['user' => $user, 'name' => $name]);
        if (! $result->ok()) {
            return $back->with('error', UserSecretManager::reason($result));
        }

        return $back->with('ok', sprintf(__('Secret %s has been rolled back to version %s.'), $name, $data['version'])
            .$this->usedBySuffix($result, $restart));
    }

    public function describe(Request $request, string $user, Principal $principal, UserSecretPolicy $policy): RedirectResponse
    {
        $name = $this->existing($request, $user, $principal, $policy);
        $data = $request->validate(['description' => $this->descriptionRules()]);

        $result = $this->secrets->describe($user, $name, (string) ($data['description'] ?? ''));
        $back = redirect()->route('secrets.show', ['user' => $user, 'name' => $name]);

        return $result->ok()
            ? $back->with('ok', __('The description has been saved.'))
            : $back->with('error', UserSecretManager::reason($result));
    }

    public function destroy(Request $request, string $user, Principal $principal, UserSecretPolicy $policy): RedirectResponse
    {
        $name = $this->existing($request, $user, $principal, $policy);

        $result = $this->secrets->delete($user, $name, $request->boolean('force'));
        if (! $result->ok()) {
            return redirect()->route('secrets.show', ['user' => $user, 'name' => $name])
                ->with('error', UserSecretManager::reason($result));
        }

        return redirect()->route('secrets.list', ['user' => $user])
            ->with('ok', sprintf(__('Secret %s has been deleted.'), $name));
    }

    /** The name of the request after the manage check; 404 when the user has no such secret. */
    private function existing(Request $request, string $user, Principal $principal, UserSecretPolicy $policy): string
    {
        abort_unless($policy->manage($principal, $user), 403);
        $name = (string) $request->input('name', '');
        abort_if($this->secrets->find($user, $name) === null, 404);

        return $name;
    }

    /**
     * " Restarted: …" or " Restart these apps to use it: …" for the apps a
     * change printed.
     *
     * and " These apps did not restart: …" for the ones that failed.
     */
    private function usedBySuffix(CommandResult $result, bool $restart): string
    {
        $apps = UserSecretManager::usedBy($result);
        if ($apps === []) {
            return '';
        }
        $text = ' '.sprintf($restart ? __('Restarted: %s.') : __('Restart these apps to use it: %s.'), implode(', ', $apps));
        $failed = UserSecretManager::failedRestarts($result);

        return $failed === [] ? $text : $text.' '.sprintf(__('These apps did not restart: %s.'), implode(', ', $failed));
    }

    /**
     * Validate like $request->validate(), but a failure never flashes the
     * value into the session.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function validated(Request $request, array $rules): array
    {
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            throw new HttpResponseException(redirect()->to(url()->previous())->withErrors($validator)->withInput($request->except('value')));
        }

        return $validator->validated();
    }

    private function nameRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (! is_string($value) || ! UserSecretManager::validName($value)) {
                $fail(__('A name is a path like /shop/db_password: one to five parts of letters, digits, _ . and -.'));
            }
        };
    }

    /** @return list<mixed> */
    private function valueRules(): array
    {
        return ['required', 'string', 'max:'.UserSecretManager::MAX_VALUE, 'not_regex:/\x00/'];
    }

    /** @return list<mixed> */
    private function descriptionRules(): array
    {
        return ['nullable', 'string', 'max:255', 'not_regex:/[\'"$`\\\\|\x00-\x1f]/'];
    }
}
