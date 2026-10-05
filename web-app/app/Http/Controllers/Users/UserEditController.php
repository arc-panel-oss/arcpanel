<?php

namespace App\Http\Controllers\Users;

use App\Arc\SysConfig;
use App\Auth\LegacySession;
use App\Auth\Principal;
use App\Auth\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Users\UserAdministrationRequest;
use App\Http\Requests\Users\UserGeneralRequest;
use App\Http\Requests\Users\UserNameserversRequest;
use App\Http\Requests\Users\UserPackageRequest;
use App\Http\Requests\Users\UserSecurityRequest;
use App\Policies\ImpersonationPolicy;
use App\Policies\UserPolicy;
use App\Services\Users\UserPlanner;
use App\Services\Users\UserRecord;
use App\Services\Users\UserService;
use App\Services\WebDomains\ApplyResult;
use App\Tenancy\TenancyDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The edit page of a user (web/edit/user) and "My account": one form per
 * tab, each running only the commands of the values it changed (E11 spec
 * §7.4–7.5).
 */
final class UserEditController extends Controller
{
    public function __construct(
        private readonly UserService $users,
        private readonly UserPlanner $planner,
        private readonly TenancyDirectory $tenancy,
        private readonly SysConfig $config,
    ) {}

    public function edit(string $user, Principal $principal, UserPolicy $policy, ImpersonationPolicy $impersonation): View
    {
        if ($user === $principal->effectiveUser()) {
            return $this->account($principal, $policy, $impersonation);
        }
        abort_unless($policy->view($principal, $user), 404);

        return $this->page($this->users->find($user), $principal, $policy, $impersonation);
    }

    public function account(Principal $principal, UserPolicy $policy, ImpersonationPolicy $impersonation): View
    {
        return $this->page($this->users->find($principal->effectiveUser()), $principal, $policy, $impersonation);
    }

    public function general(UserGeneralRequest $request, string $user, Principal $principal, UserPolicy $policy): RedirectResponse
    {
        abort_unless($policy->update($principal, $user), 403);
        $record = $this->users->find($user);
        $values = $request->values();
        if (! array_key_exists($values['language'], $this->users->languages())) {
            throw $this->invalid('general', $user, $principal, ['language' => __('Please select a valid language.')]);
        }

        $result = $this->users->apply($this->planner->general($record, $values));
        if ($result->ok() && $user === $principal->loginUser && $values['language'] !== $record->language()) {
            // The language of the session follows, as web/edit/user does.
            app(LegacySession::class)->write(['language' => $values['language']]);
        }

        return $this->finish($user, $principal, 'general', $result);
    }

    public function package(UserPackageRequest $request, string $user, Principal $principal, UserPolicy $policy): RedirectResponse
    {
        abort_unless($policy->changePackage($principal, $user), 403);
        $record = $this->users->find($user);
        $package = (string) $request->input('package');
        $role = $principal->actingRole();

        if ($package !== $record->package()) {
            if (! array_key_exists($package, $this->users->assignablePackages($role, $principal->effectiveUser()))) {
                throw $this->invalid('package', $user, $principal, ['package' => __('Please select a valid package.')]);
            }
            if ($role === Role::Reseller && ($key = $this->users->quotaProblem($principal->effectiveUser(), $package, $user)) !== null) {
                throw $this->invalid('package', $user, $principal, ['package' => UserController::quotaMessage($key)]);
            }
            if ($record->role() === Role::Reseller && UserController::sellsNoUsers($this->users->packageTenancy($package))) {
                throw $this->invalid('package', $user, $principal, ['package' => __('A reseller needs a package that allows customers (USERS above 0).')]);
            }
        }

        $result = $this->users->apply($this->planner->package($record, $package, $role, $principal->effectiveUser()));
        if ($result->changed()) {
            $this->users->updateCounters($record->owner);
        }

        return $this->finish($user, $principal, 'package', $result);
    }

    public function nameservers(UserNameserversRequest $request, string $user, Principal $principal, UserPolicy $policy): RedirectResponse
    {
        abort_unless($policy->changeNameservers($principal, $user), 403);
        $record = $this->users->find($user);

        return $this->finish($user, $principal, 'nameservers', $this->users->apply($this->planner->nameservers($record, $request->values())));
    }

    public function security(UserSecurityRequest $request, string $user, Principal $principal, UserPolicy $policy): RedirectResponse
    {
        abort_unless($policy->update($principal, $user), 403);
        $record = $this->users->find($user);
        $manage = $policy->manageSecurity($principal, $user);
        $values = $request->values(withLogin: $manage);
        if (! $manage && $user !== $principal->effectiveUser()) {
            $values['twofa_off'] = false;
        }

        return $this->finish($user, $principal, 'security', $this->users->apply($this->planner->security($record, $values)));
    }

    public function administration(UserAdministrationRequest $request, string $user, Principal $principal, UserPolicy $policy): RedirectResponse
    {
        abort_unless($policy->administer($principal, $user), 403);
        $record = $this->users->find($user);
        $values = $request->values();
        $errors = [];

        if (! $policy->changeRole($principal, $user)) {
            $values['role'] = null;
        }
        if (! $policy->changeOwner($principal, $user)) {
            $values['owner'] = null;
        }
        $newRole = $values['role'] ?? $record->role()->value;
        if ($record->role() === Role::Reseller && $newRole !== Role::Reseller->value && $this->tenancy->customersOf($user) !== []) {
            $errors['role'] = __('Move or delete the customers of this reseller first.');
        }
        if ($newRole === Role::Reseller->value && $record->role() !== Role::Reseller
            && UserController::sellsNoUsers($this->users->packageTenancy($record->package()))) {
            $errors['role'] = __('A reseller needs a package that allows customers (USERS above 0).');
        }
        $owners = array_merge([$this->config->get('ROOT_USER')], $this->tenancy->resellers());
        if (($values['owner'] ?? '') !== '' && ! in_array($values['owner'], $owners, true)) {
            $errors['owner'] = __('Please select a valid owner.');
        }
        if (($values['shell'] ?? '') !== '' && ! array_key_exists((string) $values['shell'], $this->users->shells())) {
            $errors['shell'] = __('Please select a valid shell.');
        }
        if (($values['phpcli'] ?? '') !== '' && ! array_key_exists((string) $values['phpcli'], $this->users->phpVersions())) {
            $errors['phpcli'] = __('Please select a valid PHP version.');
        }
        if ($errors !== []) {
            throw $this->invalid('administration', $user, $principal, $errors);
        }

        $previousOwner = $record->owner;
        $result = $this->users->apply($this->planner->administration($record, $values, $this->config->get('ROOT_USER')));
        if ($result->changed()) {
            $this->users->updateCounters($previousOwner);
        }

        return $this->finish($user, $principal, 'administration', $result);
    }

    private function page(UserRecord $record, Principal $principal, UserPolicy $policy, ImpersonationPolicy $impersonation): View
    {
        $user = $record->name;
        $can = $policy->abilities($principal, $user, $impersonation);
        $role = $principal->actingRole();
        $reseller = $role === Role::Reseller ? $principal->effectiveUser() : '';
        $packages = $can['changePackage'] ? $this->users->assignablePackages($role, $principal->effectiveUser()) : [];
        $packageOptions = [];
        foreach (array_keys($packages) as $name) {
            $packageOptions[$name] = UserService::packageLabel($name, $reseller);
        }

        $owners = [];
        if ($can['changeOwner']) {
            $root = $this->config->get('ROOT_USER');
            $owners[$root] = $root;
            foreach ($this->tenancy->resellers() as $name) {
                $owners[$name] = $name;
            }
        }

        return view('users.edit', [
            'record' => $record,
            'can' => $can,
            'isSelf' => $user === $principal->effectiveUser(),
            'packages' => $packageOptions,
            'packageLabel' => UserService::packageLabel($record->package(), $reseller),
            'languages' => $this->users->languages(),
            'shells' => $can['administer'] ? $this->users->shells() : [],
            'phpVersions' => $can['administer'] ? $this->users->phpVersions() : [],
            'owners' => $owners,
            'currentOwner' => $record->owner === '' ? $this->config->get('ROOT_USER') : $record->owner,
            'roles' => ['user' => __('User'), 'reseller' => __('Reseller'), 'admin' => __('Administrator')],
        ]);
    }

    /** @param  array<string, string>  $messages */
    private function invalid(string $tab, string $user, Principal $principal, array $messages): ValidationException
    {
        return ValidationException::withMessages($messages)->errorBag($tab)->redirectTo($this->tabUrl($user, $principal, $tab));
    }

    /** Back to the tab with a toast; a failure names the step that failed. */
    private function finish(string $user, Principal $principal, string $tab, ApplyResult $result): RedirectResponse
    {
        $redirect = redirect()->to($this->tabUrl($user, $principal, $tab));
        if (! $result->ok()) {
            return $redirect->withInput(request()->except(['password']))
                ->with('error', sprintf(__('%s failed'), $result->failed?->label ?? ''))
                ->with('error_detail', $result->error);
        }

        return $redirect->with('ok', $result->changed() ? __('Changes have been saved.') : __('Nothing has changed.'));
    }

    private function tabUrl(string $user, Principal $principal, string $tab): string
    {
        $url = $user === $principal->effectiveUser() ? route('account.edit') : route('users.edit', ['user' => $user]);

        return $url.'#'.$tab;
    }
}
