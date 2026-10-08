<?php

namespace App\Services\Users;

use App\Arc\SysConfig;
use App\Auth\Principal;
use App\Auth\Role;
use App\Services\WebDomains\Step;
use App\Tenancy\TenancyDirectory;
use Illuminate\Validation\ValidationException;

/**
 * The checks and plans of the user changes that the user pages and API v2
 * share (E34 spec D2): what the principal may choose, a reseller's quota,
 * and the commands of each change. Policies decide whether the principal
 * may make the change at all; the controllers call them first.
 */
final class UserActions
{
    public function __construct(
        private readonly UserService $users,
        private readonly UserPlanner $planner,
        private readonly TenancyDirectory $tenancy,
        private readonly SysConfig $config,
    ) {}

    /**
     * A new account: a reseller creates its own customer, an administrator
     * any user. The choices are checked (taken username, package, role,
     * owner, the reseller's quota) before the steps are planned. Answers
     * the input as planned, the steps, and the owner whose reseller
     * counters change.
     *
     * @param  array{username: string, password: string, email: string, package: string, name: string, language: string, role: string, owner: string, login_disabled: bool}  $input
     * @return array{input: array{username: string, password: string, email: string, package: string, name: string, language: string, role: string, owner: string, login_disabled: bool}, steps: list<Step>, counters: string}
     *
     * @throws ValidationException
     */
    public function planCreate(Principal $principal, array $input): array
    {
        $role = $principal->actingRole();
        if ($role !== Role::Admin) {
            $input['role'] = Role::User->value;
            $input['owner'] = '';
            $input['login_disabled'] = false;
        }
        $this->checkCreate($input, $role, $principal);

        return [
            'input' => $input,
            'steps' => $this->planner->create($role, $principal->effectiveUser(), $this->config->get('ROOT_USER'), $input),
            'counters' => $role === Role::Reseller ? $principal->effectiveUser() : $input['owner'],
        ];
    }

    /**
     * Name, contact e-mail, password and language (the General tab).
     *
     * @param  array{name: string, email: string, password: ?string, language: string}  $values
     * @return list<Step>
     *
     * @throws ValidationException
     */
    public function planGeneral(UserRecord $record, array $values): array
    {
        if (! array_key_exists($values['language'], $this->users->languages())) {
            throw ValidationException::withMessages(['language' => __('Please select a valid language.')]);
        }

        return $this->planner->general($record, $values);
    }

    /**
     * Another package: one the principal may assign, with room in a
     * reseller's plan, and with customers allowed for a reseller's account.
     *
     * @return list<Step>
     *
     * @throws ValidationException
     */
    public function planPackage(Principal $principal, UserRecord $record, string $package): array
    {
        $role = $principal->actingRole();
        if ($package !== $record->package()) {
            if (! array_key_exists($package, $this->users->assignablePackages($role, $principal->effectiveUser()))) {
                throw ValidationException::withMessages(['package' => __('Please select a valid package.')]);
            }
            if ($role === Role::Reseller && ($key = $this->users->quotaProblem($principal->effectiveUser(), $package, $record->name)) !== null) {
                throw ValidationException::withMessages(['package' => self::quotaMessage($key)]);
            }
            if ($record->role() === Role::Reseller && self::sellsNoUsers($this->users->packageTenancy($package))) {
                throw ValidationException::withMessages(['package' => __('A reseller needs a package that allows customers (USERS above 0).')]);
            }
        }

        return $this->planner->package($record, $package, $role, $principal->effectiveUser());
    }

    /**
     * Name servers (the Name servers tab): 2 to 8 host names, checked by
     * the request.
     *
     * @param  list<string>  $nameservers
     * @return list<Step>
     */
    public function planNameservers(UserRecord $record, array $nameservers): array
    {
        return $this->planner->nameservers($record, $nameservers);
    }

    /**
     * The Security tab (E34 batch 6 D66): the login IP allow list for
     * everyone who may update the account; disabling the login and removing
     * two-factor authentication of someone else only with $manage
     * (UserPolicy::manageSecurity()); a user removes its own two-factor
     * authentication. Values the principal may not change are dropped, as
     * the page drops them.
     *
     * @param  array{use_iplist: bool, allowed_ips: ?string, login_disabled: ?bool, twofa_off: bool}  $values
     * @return list<Step>
     */
    public function planSecurity(Principal $principal, UserRecord $record, array $values, bool $manage): array
    {
        if (! $manage) {
            $values['login_disabled'] = null;
            if ($record->name !== $principal->effectiveUser()) {
                $values['twofa_off'] = false;
            }
        }

        return $this->planner->security($record, $values);
    }

    /**
     * The Administration tab (administrators, E34 batch 6 D66): role,
     * owner, shell and PHP CLI version; a null value keeps it. A reseller
     * with customers cannot be demoted, a user becomes a reseller only with
     * a package that allows customers, the owner is ROOT_USER or a reseller,
     * shells and PHP versions must be installed. The caller drops role and
     * owner when UserPolicy::changeRole() or changeOwner() refuses them.
     *
     * @param  array{role: ?string, owner: ?string, shell: ?string, phpcli: ?string}  $values
     * @return list<Step>
     *
     * @throws ValidationException with the fields role, owner, shell and phpcli
     */
    public function planAdministration(UserRecord $record, array $values): array
    {
        $user = $record->name;
        $errors = [];
        $newRole = $values['role'] ?? $record->role()->value;
        if ($record->role() === Role::Reseller && $newRole !== Role::Reseller->value && $this->tenancy->customersOf($user) !== []) {
            $errors['role'] = __('Move or delete the customers of this reseller first.');
        }
        if ($newRole === Role::Reseller->value && $record->role() !== Role::Reseller
            && self::sellsNoUsers($this->users->packageTenancy($record->package()))) {
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
            throw ValidationException::withMessages($errors);
        }

        return $this->planner->administration($record, $values, $this->config->get('ROOT_USER'));
    }

    /** Why the account cannot be deleted yet (a reseller with customers, E11 spec §5.4), or null. */
    public function deleteRefusal(string $user): ?string
    {
        return $this->tenancy->customersOf($user) !== [] ? __('Move or delete the customers of this reseller first.') : null;
    }

    /** @return array<string, string> ROOT_USER and every reseller: the owners an administrator may give a user */
    public function ownerOptions(): array
    {
        $root = $this->config->get('ROOT_USER');
        $options = [$root => $root.' ('.__('Administrator').')'];
        foreach ($this->tenancy->resellers() as $reseller) {
            $options[$reseller] = $reseller.' ('.__('Reseller').')';
        }

        return $options;
    }

    public static function quotaMessage(string $key): string
    {
        return sprintf(__('The plan has no room for %s.'), $key);
    }

    /** @param  array<string, string>  $tenancy */
    public static function sellsNoUsers(array $tenancy): bool
    {
        $users = $tenancy['USERS'] ?? '0';

        return $users !== 'unlimited' && (int) $users < 1;
    }

    /**
     * Package, role and owner must be ones the principal may choose; a
     * reseller's plan must have room for the package.
     *
     * @param  array{username: string, package: string, language: string, role: string, owner: string}  $input
     */
    private function checkCreate(array $input, Role $role, Principal $principal): void
    {
        $errors = [];
        if (array_key_exists($input['username'], $this->users->all())) {
            $errors['username'] = __('This username is already taken.');
        }
        $packages = $this->users->assignablePackages($role, $principal->effectiveUser());
        if (! array_key_exists($input['package'], $packages)) {
            $errors['package'] = __('Please select a valid package.');
        }
        if ($input['language'] !== '' && ! array_key_exists($input['language'], $this->users->languages())) {
            $errors['language'] = __('Please select a valid language.');
        }
        if ($role === Role::Admin && $input['owner'] !== '' && ! array_key_exists($input['owner'], $this->ownerOptions())) {
            $errors['owner'] = __('Please select a valid owner.');
        }
        if ($input['role'] === Role::Reseller->value && ! isset($errors['package']) && self::sellsNoUsers($this->users->packageTenancy($input['package']))) {
            $errors['package'] = __('A reseller needs a package that allows customers (USERS above 0).');
        }
        $reseller = $role === Role::Reseller ? $principal->effectiveUser() : ($input['role'] === Role::User->value ? $input['owner'] : '');
        if (! isset($errors['package']) && $reseller !== '' && $this->tenancy->isReseller($reseller)
            && ($key = $this->users->quotaProblem($reseller, $input['package'])) !== null) {
            $errors['package'] = self::quotaMessage($key);
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
