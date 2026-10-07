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
