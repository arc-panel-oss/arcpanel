<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\ApiException;
use App\Api\ErrorCode;
use App\Api\Paginates;
use App\Api\PlanGate;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\ChangeDatabasePasswordRequest;
use App\Http\Requests\Api\V2\StoreDatabaseRequest;
use App\Http\Requests\Api\V2\UpdateDatabaseRequest;
use App\Http\Resources\V2\DatabaseResource;
use App\Policies\DatabasePolicy;
use App\Services\Databases\DatabaseActions;
use App\Services\Databases\DatabaseService;
use App\Services\Databases\DbAdminLinks;
use App\Services\Mail\HostingCredentialsMailer;
use App\Services\WebDomains\Step;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Throwable;

/**
 * Databases of a user (E34 batch 2 spec §6.4).
 *
 * @tags Databases
 */
final class DatabaseController extends Controller
{
    public function __construct(
        private readonly DatabaseService $databases,
        private readonly DatabaseActions $actions,
        private readonly PlanGate $gate,
        private readonly Paginates $pages,
    ) {}

    /**
     * List the databases of a user
     *
     * @operationId listDatabases
     */
    public function index(Request $request, string $user, Principal $principal, DatabasePolicy $policy): AnonymousResourceCollection
    {
        $this->assertOwner($user, $principal, $policy);
        $page = $this->pages->slice($request, array_values($this->databases->forUser($user)));

        return DatabaseResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * Create a database
     *
     * `name` and `db_user` are the short names. The stored name adds the owner
     * prefix. A full name the user has answers 409 `E_EXISTS`.
     *
     * @operationId createDatabase
     *
     * @status 201
     */
    public function store(StoreDatabaseRequest $request, string $user, Principal $principal, DatabasePolicy $policy, HostingCredentialsMailer $mailer, DbAdminLinks $links): DatabaseResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->create($principal, $user), 403);
        $short = (string) $request->input('name');
        $dbUser = (string) $request->input('db_user');
        $type = $request->exists('type') ? (string) $request->input('type') : ($this->databases->types()[0] ?? '');
        $full = $this->actions->fullName($user, $short, $type);
        if (array_key_exists($full, $this->databases->forUser($user))) {
            throw new ApiException(ErrorCode::Exists, sprintf(__('Database %s exists.'), $full), 409);
        }
        $this->actions->assertShortName('name', $user, $short);
        $this->actions->assertShortName('db_user', $user, $dbUser);
        $types = $this->databases->types();
        $hosts = $this->databases->hosts();
        $this->actions->assertAllowed('type', $type, $types, sprintf(__('Type must be one of: %s'), implode(', ', $types)));
        $host = $request->exists('host') ? (string) $request->input('host') : ($hosts[0] ?? '');
        $this->actions->assertAllowed('host', $host, $hosts, sprintf(__('Host must be one of: %s'), implode(', ', $hosts)));
        $charset = (string) $request->input('charset', 'utf8mb4');
        $password = (string) $request->input('password');

        $steps = [$this->databases->planCreate($user, $short, $dbUser, $password, $type, $host, $charset)];
        if ($preview = $this->gate->execute($steps, $this->databases->apply(...))) {
            return $preview;
        }

        $resource = new DatabaseResource($this->databases->find($user, $full));
        if ($request->exists('send_credentials_to') && $request->input('send_credentials_to') !== null) {
            $sent = true;
            try {
                $mailer->sendDatabaseCredentials(
                    (string) $request->input('send_credentials_to'),
                    $full,
                    $this->actions->fullName($user, $dbUser, $type),
                    $password,
                    $links->adminUrl($type),
                    $principal->language,
                );
            } catch (Throwable) {
                $sent = false;
            }
            $resource->additional(['meta' => ['credentials_sent' => $sent]]);
        }

        return $resource->response()->setStatusCode(201)
            ->header('Location', route('api.v2.databases.show', ['user' => $user, 'database' => $full]));
    }

    /**
     * Get a database
     *
     * @operationId getDatabase
     */
    public function show(string $user, string $database, Principal $principal, DatabasePolicy $policy): DatabaseResource
    {
        $this->assertOwner($user, $principal, $policy);

        return new DatabaseResource($this->databases->find($user, $database));
    }

    /**
     * Change the database user
     *
     * `db_user` is the short name.
     *
     * @operationId updateDatabase
     */
    public function update(UpdateDatabaseRequest $request, string $user, string $database, Principal $principal, DatabasePolicy $policy): DatabaseResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->update($principal, $user), 403);
        $record = $this->databases->find($user, $database);
        $short = (string) $request->input('db_user');
        $this->actions->assertShortName('db_user', $user, $short);

        return $this->gate->execute($this->databases->planUpdate($record, $short, ''), $this->databases->apply(...))
            ?? new DatabaseResource($this->databases->find($user, $database));
    }

    /**
     * Change the password of a database
     *
     * @operationId changeDatabasePassword
     *
     * @status 204
     */
    public function password(ChangeDatabasePasswordRequest $request, string $user, string $database, Principal $principal, DatabasePolicy $policy): Response|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->update($principal, $user), 403);
        $record = $this->databases->find($user, $database);

        return $this->gate->execute($this->databases->planUpdate($record, '', (string) $request->input('password')), $this->databases->apply(...))
            ?? response()->noContent();
    }

    /**
     * Delete a database
     *
     * @operationId deleteDatabase
     *
     * @status 204
     */
    public function destroy(string $user, string $database, Principal $principal, DatabasePolicy $policy): Response|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->delete($principal, $user), 403);
        $this->databases->find($user, $database);

        return $this->gate->execute([
            new Step(sprintf(__('Delete database %s'), $database), 'v-delete-database', [$user, $database]),
        ], $this->databases->apply(...)) ?? response()->noContent();
    }

    /**
     * Suspend a database
     *
     * @operationId suspendDatabase
     */
    public function suspend(string $user, string $database, Principal $principal, DatabasePolicy $policy): DatabaseResource|JsonResponse
    {
        return $this->setSuspended($user, $database, true, $principal, $policy);
    }

    /**
     * Unsuspend a database
     *
     * @operationId unsuspendDatabase
     */
    public function unsuspend(string $user, string $database, Principal $principal, DatabasePolicy $policy): DatabaseResource|JsonResponse
    {
        return $this->setSuspended($user, $database, false, $principal, $policy);
    }

    /**
     * Rebuild a database
     *
     * Administrators only.
     *
     * @operationId rebuildDatabase
     */
    public function rebuild(string $user, string $database, Principal $principal, DatabasePolicy $policy): DatabaseResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->rebuild($principal, $user), 403);
        $this->databases->find($user, $database);

        return $this->gate->execute([
            new Step(sprintf(__('Rebuild database %s'), $database), 'v-rebuild-database', [$user, $database]),
        ], $this->databases->apply(...)) ?? new DatabaseResource($this->databases->find($user, $database));
    }

    private function setSuspended(string $user, string $database, bool $suspend, Principal $principal, DatabasePolicy $policy): DatabaseResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->suspend($principal, $user), 403);
        $record = $this->databases->find($user, $database);
        $steps = $record->suspended === $suspend ? [] : [$suspend
            ? new Step(sprintf(__('Suspend database %s'), $database), 'v-suspend-database', [$user, $database])
            : new Step(sprintf(__('Unsuspend database %s'), $database), 'v-unsuspend-database', [$user, $database])];

        return $this->gate->execute($steps, $this->databases->apply(...))
            ?? new DatabaseResource($this->databases->find($user, $database));
    }

    private function assertOwner(string $user, Principal $principal, DatabasePolicy $policy): void
    {
        abort_unless($policy->view($principal, $user) && $this->actions->ownerExists($user), 404);
    }
}
