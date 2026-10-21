<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\ApiException;
use App\Api\ErrorCode;
use App\Api\Paginates;
use App\Api\PlanGate;
use App\Arc\Secret;
use App\Arc\SysConfig;
use App\Auth\Principal;
use App\Auth\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\StoreUserRequest;
use App\Http\Requests\Api\V2\UpdateUserRequest;
use App\Http\Requests\Api\V2\UserPackageRequest;
use App\Http\Requests\Api\V2\UserPasswordRequest;
use App\Http\Resources\V2\ApiRole;
use App\Http\Resources\V2\UserResource;
use App\Policies\UserPolicy;
use App\Services\Users\UserActions;
use App\Services\Users\UserRecord;
use App\Services\Users\UserService;
use App\Services\WebDomains\Step;
use App\Tenancy\TenancyDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Users and customers (E34 spec §6.1, E11 spec §7): an administrator
 * manages every user, a reseller its customers; everyone reads and
 * changes the password of its own account.
 *
 * @tags Users
 */
final class UserController extends Controller
{
    /** The command a change of the account runs after its plan, for a reseller's totals. */
    private const COUNTERS = 'v-update-reseller-counters';

    public function __construct(
        private readonly UserService $users,
        private readonly UserActions $actions,
        private readonly TenancyDirectory $tenancy,
        private readonly SysConfig $config,
        private readonly PlanGate $gate,
        private readonly Paginates $pages,
    ) {}

    /**
     * List users
     *
     * Every user for an administrator, the customers for a reseller.
     *
     * @operationId listUsers
     */
    public function index(Request $request, Principal $principal, UserPolicy $policy): AnonymousResourceCollection
    {
        abort_unless($policy->viewAny($principal), 403);
        $filters = $request->validate([
            /** Only users of this role. */
            'role' => ['nullable', Rule::enum(ApiRole::class)],
            /** Administrators: only the customers of this reseller (or of the administrator). */
            'owner' => ['nullable', 'string', 'max:32', 'regex:/^[[:alnum:]][-.\w]*$/'],
        ]);
        $isAdmin = $principal->actingRole() === Role::Admin;

        $records = $isAdmin
            ? $this->users->all()
            : $this->users->only($this->tenancy->customersOf($principal->effectiveUser()));
        if ($isAdmin && $this->config->is('POLICY_SYSTEM_HIDE_ADMIN') && $principal->loginUser !== $this->config->get('ROOT_USER')) {
            unset($records[$this->config->get('ROOT_USER')]);
        }
        $role = (string) ($filters['role'] ?? '');
        $owner = $isAdmin ? (string) ($filters['owner'] ?? '') : '';
        $shown = array_filter($records, fn (UserRecord $record) => ($role === '' || $record->role()->value === $role)
            && ($owner === '' || $record->owner === $owner));

        $page = $this->pages->slice($request, array_values($shown));

        return UserResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * Create a user
     *
     * An administrator creates any user (with `role`, `owner` and
     * `login_disabled`), a reseller a customer of its own within its plan.
     * A repeated call answers 409 `E_EXISTS`.
     *
     * @operationId createUser
     *
     * @status 201
     */
    public function store(StoreUserRequest $request, Principal $principal, UserPolicy $policy): UserResource|JsonResponse
    {
        abort_unless($policy->create($principal), 403);
        $account = $request->account();
        if ($account['language'] === '') {
            $account['language'] = $this->config->get('LANGUAGE', 'en') ?: 'en';
        }
        if (array_key_exists($account['username'], $this->users->all())) {
            throw new ApiException(ErrorCode::Exists, __('This username is already taken.'), 409);
        }
        ['input' => $input, 'steps' => $steps, 'counters' => $counters] = $this->actions->planCreate($principal, $account);

        if ($preview = $this->gate->execute($steps, $this->users->apply(...), $counters !== '' ? [self::COUNTERS] : [])) {
            return $preview;
        }
        $this->users->updateCounters($counters);

        return (new UserResource($this->users->find($input['username'])))->response()->setStatusCode(201)
            ->header('Location', route('api.v2.users.show', ['user' => $input['username']]));
    }

    /**
     * Get a user
     *
     * @operationId getUser
     */
    public function show(string $user, Principal $principal, UserPolicy $policy): UserResource
    {
        $this->assertReadable($user, $principal, $policy);

        return new UserResource($this->users->find($user));
    }

    /**
     * Update a user
     *
     * The fields left out keep their value. Name, contact e-mail, language
     * and `allowed_ips`: users for their own account, administrators and
     * resellers for those they manage. `nameservers`: administrators and
     * resellers for their customers, an administrator for itself.
     * `login_disabled`: administrators, not for themselves. `role`,
     * `owner`, `shell` and `php_cli`: administrators, never for the root
     * administrator; `role` and `owner` not for themselves either. A
     * reseller with customers cannot be demoted, a user becomes a reseller
     * only with a package that allows customers. A field the caller may not
     * change answers 403 naming it, before anything runs.
     *
     * @operationId updateUser
     */
    public function update(UpdateUserRequest $request, string $user, Principal $principal, UserPolicy $policy): UserResource|JsonResponse
    {
        $this->assertReadable($user, $principal, $policy);
        abort_unless($policy->update($principal, $user), 403);
        $record = $this->users->find($user);
        $this->assertFieldsAllowed($request, $user, $principal, $policy);

        $values = [
            'name' => trim((string) $request->input('name', $record->fullName())),
            'email' => trim((string) $request->input('email', $record->email())),
            'password' => null,
            'language' => (string) $request->input('language', $record->language()),
        ];
        $steps = $this->actions->planGeneral($record, $values);
        if ($request->exists('nameservers')) {
            $steps = [...$steps, ...$this->actions->planNameservers($record, array_values((array) $request->input('nameservers')))];
        }
        if ($request->exists('allowed_ips') || $request->exists('login_disabled')) {
            $ips = $request->input('allowed_ips');
            $steps = [...$steps, ...$this->actions->planSecurity($principal, $record, [
                'use_iplist' => is_array($ips),
                'allowed_ips' => $request->exists('allowed_ips') ? implode(',', (array) $ips) : null,
                'login_disabled' => $request->exists('login_disabled') ? $request->boolean('login_disabled') : null,
                'twofa_off' => false,
            ], $policy->manageSecurity($principal, $user))];
        }
        $administration = [];
        if (array_filter(UpdateUserRequest::ADMINISTRATION, fn (string $field) => $request->exists($field)) !== []) {
            try {
                $administration = $this->actions->planAdministration($record, [
                    'role' => $request->input('role'),
                    'owner' => $request->input('owner'),
                    'shell' => $request->input('shell'),
                    'phpcli' => $request->input('php_cli'),
                ]);
            } catch (ValidationException $e) {
                $errors = $e->errors();
                if (isset($errors['phpcli'])) {
                    $errors['php_cli'] = $errors['phpcli'];
                    unset($errors['phpcli']);
                }
                throw ValidationException::withMessages($errors);
            }
        }
        $steps = [...$steps, ...$administration];

        if ($preview = $this->gate->execute($steps, $this->users->apply(...), $administration !== [] ? [self::COUNTERS] : [])) {
            return $preview;
        }
        if ($administration !== []) {
            $this->users->updateCounters($record->owner);
        }

        return new UserResource($this->users->find($user));
    }

    /**
     * Turn off two-factor authentication
     *
     * Users for their own account, administrators for the accounts they
     * manage. Turning it on, with its recovery codes, is done by the user
     * on the Security tab of the Account page of the panel. Answers 204
     * without a command when it is already off.
     *
     * @operationId disableUserTwoFactor
     *
     * @status 204
     */
    public function disableTwoFactor(string $user, Principal $principal, UserPolicy $policy): Response|JsonResponse
    {
        $this->assertReadable($user, $principal, $policy);
        $manage = $policy->manageSecurity($principal, $user);
        abort_unless($policy->update($principal, $user) && ($user === $principal->effectiveUser() || $manage), 403);
        $record = $this->users->find($user);
        $steps = $this->actions->planSecurity($principal, $record, [
            'use_iplist' => $record->loginUsesIpList(), 'allowed_ips' => null, 'login_disabled' => null, 'twofa_off' => true,
        ], $manage);

        return $this->gate->execute($steps, $this->users->apply(...)) ?? response()->noContent();
    }

    /**
     * Change the package of a user
     *
     * @operationId changeUserPackage
     */
    public function package(UserPackageRequest $request, string $user, Principal $principal, UserPolicy $policy): UserResource|JsonResponse
    {
        $this->assertReadable($user, $principal, $policy);
        abort_unless($policy->changePackage($principal, $user), 403);
        $record = $this->users->find($user);
        $steps = $this->actions->planPackage($principal, $record, (string) $request->input('package'));

        if ($preview = $this->gate->execute($steps, $this->users->apply(...), $steps !== [] ? [self::COUNTERS] : [])) {
            return $preview;
        }
        if ($steps !== []) {
            $this->users->updateCounters($record->owner);
        }

        return new UserResource($this->users->find($user));
    }

    /**
     * Change the password of a user
     *
     * @operationId changeUserPassword
     *
     * @status 204
     */
    public function password(UserPasswordRequest $request, string $user, Principal $principal, UserPolicy $policy): Response|JsonResponse
    {
        $this->assertReadable($user, $principal, $policy);
        abort_unless($policy->update($principal, $user), 403);
        $this->users->find($user);
        $steps = [new Step(__('Change the password'), 'v-change-user-password', [$user, Secret::file((string) $request->input('password'))])];

        return $this->gate->execute($steps, $this->users->apply(...)) ?? response()->noContent();
    }

    /**
     * Suspend a user
     *
     * Suspends the account and its objects; a suspended user answers as it is.
     *
     * @operationId suspendUser
     */
    public function suspend(string $user, Principal $principal, UserPolicy $policy): UserResource|JsonResponse
    {
        return $this->setSuspended($user, true, $principal, $policy);
    }

    /**
     * Unsuspend a user
     *
     * @operationId unsuspendUser
     */
    public function unsuspend(string $user, Principal $principal, UserPolicy $policy): UserResource|JsonResponse
    {
        return $this->setSuspended($user, false, $principal, $policy);
    }

    /**
     * Delete a user
     *
     * Deletes the account with all its data. A reseller with customers
     * answers 409 `E_INUSE`.
     *
     * @operationId deleteUser
     *
     * @status 204
     */
    public function destroy(string $user, Principal $principal, UserPolicy $policy): Response|JsonResponse
    {
        $this->assertReadable($user, $principal, $policy);
        abort_unless($policy->delete($principal, $user), 403);
        $record = $this->users->find($user);
        if (($reason = $this->actions->deleteRefusal($user)) !== null) {
            throw new ApiException(ErrorCode::InUse, $reason, 409);
        }
        $steps = [new Step(sprintf(__('Delete user %s'), $user), 'v-delete-user', [$user])];

        if ($preview = $this->gate->execute($steps, $this->users->apply(...), [self::COUNTERS])) {
            return $preview;
        }
        $this->users->updateCounters($record->owner);

        return response()->noContent();
    }

    private function setSuspended(string $user, bool $suspend, Principal $principal, UserPolicy $policy): UserResource|JsonResponse
    {
        $this->assertReadable($user, $principal, $policy);
        abort_unless($policy->suspend($principal, $user), 403);
        $record = $this->users->find($user);
        $steps = $record->suspended() === $suspend ? [] : [$suspend
            ? new Step(sprintf(__('Suspend user %s'), $user), 'v-suspend-user', [$user])
            : new Step(sprintf(__('Unsuspend user %s'), $user), 'v-unsuspend-user', [$user])];

        if ($preview = $this->gate->execute($steps, $this->users->apply(...), $steps !== [] ? [self::COUNTERS] : [])) {
            return $preview;
        }
        if ($steps !== []) {
            $this->users->updateCounters($record->owner);
        }

        return new UserResource($this->users->find($user));
    }

    /**
     * Fields of D63 that the principal may not change answer 403 naming the
     * field, before anything runs.
     */
    private function assertFieldsAllowed(UpdateUserRequest $request, string $user, Principal $principal, UserPolicy $policy): void
    {
        $administer = $policy->administer($principal, $user) && $user !== $this->config->get('ROOT_USER');
        $allowed = [
            'nameservers' => $policy->changeNameservers($principal, $user),
            'login_disabled' => $policy->manageSecurity($principal, $user),
            'role' => $administer && $policy->changeRole($principal, $user),
            'owner' => $administer && $policy->changeOwner($principal, $user),
            'shell' => $administer,
            'php_cli' => $administer,
        ];
        foreach ($allowed as $field => $ok) {
            if ($request->exists($field) && ! $ok) {
                throw new ApiException(ErrorCode::Forbidden, sprintf('You may not change the field %s of this user.', $field), 403);
            }
        }
    }

    /** The account itself, or one the principal manages; anything else answers 404. */
    private function assertReadable(string $user, Principal $principal, UserPolicy $policy): void
    {
        abort_unless($user === $principal->effectiveUser() || $policy->view($principal, $user), 404);
    }
}
