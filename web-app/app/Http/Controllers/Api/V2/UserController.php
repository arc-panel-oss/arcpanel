<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\ApiException;
use App\Api\ErrorCode;
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

        return UserResource::collection(array_values($shown))->additional(['meta' => (object) []]);
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
     * Name, contact e-mail and language; the fields left out keep their
     * value. Users change their own account, administrators and resellers
     * those they manage.
     *
     * @operationId updateUser
     */
    public function update(UpdateUserRequest $request, string $user, Principal $principal, UserPolicy $policy): UserResource|JsonResponse
    {
        $this->assertReadable($user, $principal, $policy);
        abort_unless($policy->update($principal, $user), 403);
        $record = $this->users->find($user);
        $values = [
            'name' => trim((string) $request->input('name', $record->fullName())),
            'email' => trim((string) $request->input('email', $record->email())),
            'password' => null,
            'language' => (string) $request->input('language', $record->language()),
        ];

        return $this->gate->execute($this->actions->planGeneral($record, $values), $this->users->apply(...))
            ?? new UserResource($this->users->find($user));
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

    /** The account itself, or one the principal manages; anything else answers 404. */
    private function assertReadable(string $user, Principal $principal, UserPolicy $policy): void
    {
        abort_unless($user === $principal->effectiveUser() || $policy->view($principal, $user), 404);
    }
}
