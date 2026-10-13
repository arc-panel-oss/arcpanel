<?php

namespace App\Http\Controllers\Users;

use App\Arc\SysConfig;
use App\Auth\LegacySession;
use App\Auth\LoginService;
use App\Auth\Principal;
use App\Auth\Role;
use App\Auth\SudoMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Users\UserAdministrationRequest;
use App\Http\Requests\Users\UserGeneralRequest;
use App\Http\Requests\Users\UserNameserversRequest;
use App\Http\Requests\Users\UserPackageRequest;
use App\Http\Requests\Users\UserSecurityRequest;
use App\Policies\ImpersonationPolicy;
use App\Policies\UserPolicy;
use App\Services\Users\UserActions;
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

    public function general(UserGeneralRequest $request, string $user, Principal $principal, UserPolicy $policy, UserActions $actions, SudoMode $sudo): RedirectResponse
    {
        abort_unless($policy->update($principal, $user), 403);
        $record = $this->users->find($user);
        $values = $request->values();
        $newPassword = ($values['password'] ?? '') !== '';
        if (($newPassword || $values['email'] !== $record->email()) && $sudo->required($principal, $user)) {
            return $sudo->challenge($request);
        }
        $steps = $this->onTab('general', $user, $principal, fn () => $actions->planGeneral($record, $values));

        $result = $this->users->apply($steps);
        if ($result->ok() && $user === $principal->loginUser && $values['language'] !== $record->language()) {
            // The language of the session follows, as web/edit/user does.
            app(LegacySession::class)->write(['language' => $values['language']]);
        }
        if ($result->ok() && $newPassword && $user === $principal->loginUser && ! $principal->isImpersonating()) {
            // The password change ended every older session; this one goes
            // on under a new ID (E39 W2 spec D11).
            app(LegacySession::class)->establish(['LOGIN_TIME' => LoginService::loginTime()]);
        }

        return $this->finish($user, $principal, 'general', $result);
    }

    public function package(UserPackageRequest $request, string $user, Principal $principal, UserPolicy $policy, UserActions $actions): RedirectResponse
    {
        abort_unless($policy->changePackage($principal, $user), 403);
        $record = $this->users->find($user);
        $package = (string) $request->input('package');
        $steps = $this->onTab('package', $user, $principal, fn () => $actions->planPackage($principal, $record, $package));

        $result = $this->users->apply($steps);
        if ($result->changed()) {
            $this->users->updateCounters($record->owner);
        }

        return $this->finish($user, $principal, 'package', $result);
    }

    public function nameservers(UserNameserversRequest $request, string $user, Principal $principal, UserPolicy $policy, UserActions $actions): RedirectResponse
    {
        abort_unless($policy->changeNameservers($principal, $user), 403);
        $record = $this->users->find($user);

        return $this->finish($user, $principal, 'nameservers', $this->users->apply($actions->planNameservers($record, $request->values())));
    }

    public function security(UserSecurityRequest $request, string $user, Principal $principal, UserPolicy $policy, UserActions $actions, SudoMode $sudo): RedirectResponse
    {
        abort_unless($policy->update($principal, $user), 403);
        if ($sudo->required($principal, $user)) {
            return $sudo->challenge($request);
        }
        $record = $this->users->find($user);
        $manage = $policy->manageSecurity($principal, $user);
        $steps = $actions->planSecurity($principal, $record, $request->values(withLogin: $manage), $manage);

        return $this->finish($user, $principal, 'security', $this->users->apply($steps));
    }

    public function administration(UserAdministrationRequest $request, string $user, Principal $principal, UserPolicy $policy, UserActions $actions): RedirectResponse
    {
        abort_unless($policy->administer($principal, $user), 403);
        $record = $this->users->find($user);
        $values = $request->values();
        if (! $policy->changeRole($principal, $user)) {
            $values['role'] = null;
        }
        if (! $policy->changeOwner($principal, $user)) {
            $values['owner'] = null;
        }
        $steps = $this->onTab('administration', $user, $principal, fn () => $actions->planAdministration($record, $values));

        $previousOwner = $record->owner;
        $result = $this->users->apply($steps);
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

    /**
     * The plan of a tab; its validation errors go back to the tab.
     *
     * @template T
     *
     * @param  \Closure(): T  $plan
     * @return T
     */
    private function onTab(string $tab, string $user, Principal $principal, \Closure $plan): mixed
    {
        try {
            return $plan();
        } catch (ValidationException $e) {
            throw $e->errorBag($tab)->redirectTo($this->tabUrl($user, $principal, $tab));
        }
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
