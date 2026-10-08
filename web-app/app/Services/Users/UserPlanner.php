<?php

namespace App\Services\Users;

use App\Arc\Secret;
use App\Auth\Role;
use App\Services\WebDomains\Step;

/**
 * The commands of each user form, in the order of web/add/user and
 * web/edit/user. Every tab runs only the commands of the values it changed
 * (D7 of the E1-S2 spec).
 */
final class UserPlanner
{
    /**
     * Account steps of the create wizard: a reseller adds a customer through
     * v-add-reseller-user (ownership and quota checked by the command), an
     * administrator through v-add-user and, for a reseller's customer,
     * v-change-user-owner.
     *
     * @param  array{username: string, password: string, email: string, package: string, name: string, language: string, role: string, owner: string, login_disabled: bool}  $input
     * @return list<Step>
     */
    public function create(Role $role, string $principal, string $rootUser, array $input): array
    {
        $user = $input['username'];
        $password = Secret::file($input['password']);
        $steps = [];

        if ($role === Role::Reseller) {
            $steps[] = new Step(sprintf(__('Add customer %s'), $user), 'v-add-reseller-user',
                [$principal, $user, $password, $input['email'], $input['package'], $input['name']]);
        } else {
            $steps[] = new Step(sprintf(__('Add user %s'), $user), 'v-add-user',
                [$user, $password, $input['email'], $input['package'], $input['name']]);
        }
        if ($input['language'] !== '' && $input['language'] !== 'en') {
            $steps[] = new Step(__('Set the language'), 'v-change-user-language', [$user, $input['language']]);
        }
        if ($role === Role::Admin) {
            if ($input['role'] !== '' && $input['role'] !== Role::User->value) {
                $steps[] = new Step(__('Set the role'), 'v-change-user-role', [$user, $input['role']]);
            }
            if ($input['role'] === Role::User->value && $input['owner'] !== '' && $input['owner'] !== $rootUser) {
                $steps[] = new Step(__('Set the owner'), 'v-change-user-owner', [$user, $input['owner']]);
            }
            if ($input['login_disabled']) {
                $steps[] = new Step(__('Disable the login'), 'v-change-user-config-value', [$user, 'LOGIN_DISABLED', 'yes']);
            }
        }

        return $steps;
    }

    /**
     * General tab: name, contact, password, language (web/edit/user).
     *
     * @param  array{name: string, email: string, password: ?string, language: string}  $input
     * @return list<Step>
     */
    public function general(UserRecord $current, array $input): array
    {
        $user = $current->name;
        $steps = [];
        if ($input['password'] !== null && $input['password'] !== '') {
            $steps[] = new Step(__('Change the password'), 'v-change-user-password', [$user, Secret::file($input['password'])]);
        }
        if ($input['language'] !== '' && $input['language'] !== $current->language()) {
            $steps[] = new Step(__('Change the language'), 'v-change-user-language', [$user, $input['language']]);
        }
        if ($input['email'] !== $current->email()) {
            $steps[] = new Step(__('Change the contact e-mail'), 'v-change-user-contact', [$user, $input['email']]);
        }
        if ($input['name'] !== $current->fullName()) {
            $steps[] = new Step(__('Change the name'), 'v-change-user-name', [$user, $input['name']]);
        }

        return $steps;
    }

    /** @return list<Step> */
    public function package(UserRecord $current, string $package, Role $role, string $principal): array
    {
        if ($package === $current->package()) {
            return [];
        }

        return $role === Role::Reseller
            ? [new Step(__('Change the package'), 'v-change-reseller-user-package', [$principal, $current->name, $package])]
            : [new Step(__('Change the package'), 'v-change-user-package', [$current->name, $package])];
    }

    /**
     * @param  list<string>  $nameservers
     * @return list<Step>
     */
    public function nameservers(UserRecord $current, array $nameservers): array
    {
        if ($nameservers === $current->nameservers()) {
            return [];
        }

        return [new Step(__('Change the name servers'), 'v-change-user-ns', [$current->name, ...$nameservers])];
    }

    /**
     * Security tab: the IP allow list of the login (the user too), and, for
     * someone else's account, login disabling and two-factor removal. A
     * null `allowed_ips` leaves the allow list as it is (API v2).
     *
     * @param  array{use_iplist: bool, allowed_ips: ?string, login_disabled: ?bool, twofa_off: bool}  $input
     * @return list<Step>
     */
    public function security(UserRecord $current, array $input): array
    {
        $user = $current->name;
        $steps = [];
        if ($input['login_disabled'] !== null && $input['login_disabled'] !== $current->loginDisabled()) {
            $steps[] = new Step($input['login_disabled'] ? __('Disable the login') : __('Enable the login'),
                'v-change-user-config-value', [$user, 'LOGIN_DISABLED', $input['login_disabled'] ? 'yes' : 'no']);
        }
        if ($input['allowed_ips'] !== null) {
            $ips = $input['use_iplist'] ? $input['allowed_ips'] : '';
            if ($input['use_iplist'] !== $current->loginUsesIpList()) {
                $steps[] = new Step(__('Change the login IP allow list'), 'v-change-user-config-value', [$user, 'LOGIN_USE_IPLIST', $input['use_iplist'] ? 'yes' : 'no']);
            }
            if ($ips !== $current->get('LOGIN_ALLOW_IPS')) {
                $steps[] = new Step(__('Change the allowed IP addresses'), 'v-change-user-config-value', [$user, 'LOGIN_ALLOW_IPS', $ips]);
            }
        }
        if ($input['twofa_off'] && $current->twoFactor()) {
            $steps[] = new Step(__('Turn off two-factor authentication'), 'v-delete-user-2fa', [$user]);
        }

        return $steps;
    }

    /**
     * Administration tab (administrators): owner before role, so a customer
     * that becomes a reseller is first moved back to the administrator.
     *
     * @param  array{role: ?string, owner: ?string, shell: ?string, phpcli: ?string}  $input
     * @return list<Step>
     */
    public function administration(UserRecord $current, array $input, string $rootUser): array
    {
        $user = $current->name;
        $steps = [];
        $role = $input['role'] ?? $current->role()->value;
        $owner = $input['owner'] ?? '';
        $currentOwner = $current->owner === '' ? $rootUser : $current->owner;

        if ($role !== Role::User->value && $currentOwner !== $rootUser && $current->role() === Role::User) {
            $owner = $rootUser;
        }
        if ($owner !== '' && $owner !== $currentOwner && $current->role() === Role::User) {
            $steps[] = new Step(__('Change the owner'), 'v-change-user-owner', [$user, $owner]);
        }
        if ($role !== $current->role()->value) {
            $steps[] = new Step(__('Change the role'), 'v-change-user-role', [$user, $role]);
        }
        if (($input['shell'] ?? '') !== '' && $input['shell'] !== $current->get('SHELL')) {
            $steps[] = new Step(__('Change the shell'), 'v-change-user-shell', [$user, $input['shell']]);
        }
        if (($input['phpcli'] ?? '') !== '' && $input['phpcli'] !== $current->get('PHPCLI')) {
            $steps[] = new Step(__('Change the PHP CLI version'), 'v-change-user-php-cli', [$user, $input['phpcli']]);
        }

        return $steps;
    }
}
