<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\ApiException;
use App\Api\CrossUserList;
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
use App\Services\Databases\DatabaseRecord;
use App\Services\Databases\DatabaseService;
use App\Services\Databases\DbAdminLinks;
use App\Services\Mail\HostingCredentialsMailer;
use App\Services\WebDomains\Step;
use Dedoc\Scramble\Attributes\Header;
use Dedoc\Scramble\Attributes\Response as ApiResponse;
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
     * List the databases of every user
     *
     * Every user's databases for an administrator's key, those of the reseller
     * and its customers for a reseller's key; a user's key answers 403
     * (`listDatabases` covers it). Sorted by user, then name. With
     * `updated_since`, only the users whose databases changed at or after that
     * time are returned, each with all its databases: `meta.as_of` is the next
     * `updated_since`, `meta.users` every user in scope and
     * `meta.changed_users` the users returned (E43 B2).
     *
     * @operationId listAllDatabases
     */
    public function indexAll(Request $request, CrossUserList $lists): AnonymousResourceCollection
    {
        $page = $lists->page($request, 'v-list-sys-databases', DatabaseRecord::fromArray(...), fn (DatabaseRecord $database) => $database->database);

        return DatabaseResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

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
     * Export a database dump
     *
     * Runs `v-dump-database` and serves the `.sql.gz` through nginx (D35).
     * The dump file is removed about an hour later, as on the Databases page.
     *
     * @operationId dumpDatabase
     */
    #[ApiResponse(
        status: 200,
        description: 'The gzip dump: an empty body with `X-Accel-Redirect` so nginx serves the bytes (D35).',
        mediaType: 'application/gzip',
    )]
    #[Header('Content-Disposition', description: '`attachment; filename="<dump>.sql.gz"`', status: 200)]
    #[Header('X-Accel-Redirect', description: 'Internal nginx path under `/backup/` (D35).', status: 200)]
    public function dump(string $user, string $database, Principal $principal, DatabasePolicy $policy): Response|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        $this->databases->find($user, $database);

        $steps = [new Step(sprintf(__('Export database %s'), $database), 'v-dump-database', [$user, $database, 'file', 'gzip'])];
        $this->gate->authorize($steps);
        if ($this->gate->dryRun()) {
            return $this->gate->preview($steps);
        }

        $path = $this->databases->dump($user, $database);
        if (preg_match('#^/backup/[\w.-]+$#', $path) !== 1) {
            throw new ApiException(ErrorCode::Server, __('The database could not be exported.'), 500);
        }

        $name = basename($path);

        return response('', 200, [
            'Content-Type' => 'application/gzip',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'X-Accel-Redirect' => $path,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
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
