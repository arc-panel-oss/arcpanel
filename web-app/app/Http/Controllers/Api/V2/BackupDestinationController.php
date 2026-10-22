<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\ApiException;
use App\Api\ErrorCode;
use App\Api\Paginates;
use App\Api\PlanGate;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\StoreBackupDestinationRequest;
use App\Http\Requests\Api\V2\UpdateBackupDestinationRequest;
use App\Http\Resources\V2\BackupDestinationResource;
use App\Policies\BackupPolicy;
use App\Services\Backups\DestinationRules;
use App\Services\Backups\DestinationService;
use App\Services\Support\PlanRunner;
use App\Services\WebDomains\Step;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Backup destinations of the server for administrators (E48 spec §4, §9):
 * Server → Backups → Destinations as JSON. Secrets are write-only: a
 * destination lists the names of its stored secrets, never their values.
 * The recovery kit of an encrypted destination is not in the API (§4.5).
 *
 * @tags Backups
 */
final class BackupDestinationController extends Controller
{
    public function __construct(
        private readonly DestinationService $destinations,
        private readonly PlanGate $gate,
        private readonly PlanRunner $runner,
        private readonly Paginates $pages,
    ) {}

    /**
     * List the backup destinations
     *
     * Sorted by name.
     *
     * @operationId listBackupDestinations
     */
    public function index(Request $request, Principal $principal, BackupPolicy $policy): AnonymousResourceCollection
    {
        abort_unless($policy->viewDestinations($principal), 403);
        $page = $this->pages->slice($request, array_values($this->destinations->all()));

        return BackupDestinationResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * Add a backup destination
     *
     * The destination starts `unchecked`: test it with
     * `checkBackupDestination`. A name in use answers `409 E_EXISTS`.
     *
     * @operationId createBackupDestination
     *
     * @status 201
     */
    public function store(StoreBackupDestinationRequest $request): BackupDestinationResource|JsonResponse
    {
        $name = (string) $request->validated()['name'];
        if (array_key_exists($name, $this->destinations->all())) {
            throw new ApiException(ErrorCode::Exists, sprintf(__('Backup destination %s already exists.'), $name), 409, existing: self::path($name));
        }
        $step = new Step(sprintf(__('Add backup destination %s'), $name), 'v-add-backup-destination',
            DestinationService::args($name, $request->driver(), $request->fields(), $request->secrets()));
        if ($preview = $this->gate->execute([$step], $this->runner->apply(...))) {
            return $preview;
        }

        return (new BackupDestinationResource($this->destinations->find($name)))->response()->setStatusCode(201)
            ->header('Location', route('api.v2.system.backup-destinations.show', ['destination' => $name]));
    }

    /**
     * Get a backup destination
     *
     * @operationId getBackupDestination
     */
    public function show(string $destination, Principal $principal, BackupPolicy $policy): BackupDestinationResource
    {
        abort_unless($policy->viewDestinations($principal), 403);

        return new BackupDestinationResource($this->destinations->find($destination));
    }

    /**
     * Update a backup destination
     *
     * Fields left out keep their value and secrets left out stay stored;
     * the driver does not change. A change of the connection makes the
     * destination `unchecked` until it is tested again.
     *
     * @operationId updateBackupDestination
     */
    public function update(UpdateBackupDestinationRequest $request, string $destination): BackupDestinationResource|JsonResponse
    {
        $stored = $request->stored();
        $fields = $request->fields();
        $secrets = $request->secrets();
        $steps = $fields === DestinationRules::storedFields($stored) && $secrets === [] ? [] : [
            new Step(sprintf(__('Change backup destination %s'), $stored->name), 'v-change-backup-destination',
                DestinationService::args($stored->name, null, $fields, $secrets)),
        ];

        return $this->gate->execute($steps, $this->runner->apply(...)) ?? new BackupDestinationResource($this->destinations->find($stored->name));
    }

    /**
     * Delete a backup destination
     *
     * The backups stored there are left in place. A destination that a job
     * still stores to answers `409 E_INUSE`.
     *
     * @operationId deleteBackupDestination
     *
     * @status 204
     */
    public function destroy(string $destination, Principal $principal, BackupPolicy $policy): Response|JsonResponse
    {
        abort_unless($policy->manageDestinations($principal), 403);
        $record = $this->destinations->find($destination);
        $step = new Step(sprintf(__('Delete backup destination %s'), $record->name), 'v-delete-backup-destination', [$record->name]);

        return $this->gate->execute([$step], $this->runner->apply(...)) ?? response()->noContent();
    }

    /**
     * Test a backup destination
     *
     * Connects, writes, lists and removes a small file, and records the
     * state (`ok` or `failing`). An SFTP destination answers its host key's
     * fingerprint in `host_key` the first time with `ok: false`: send it
     * back as `accept` once you have compared it with the server's.
     *
     * @operationId checkBackupDestination
     */
    public function check(Request $request, string $destination, Principal $principal, BackupPolicy $policy): JsonResponse
    {
        abort_unless($policy->manageDestinations($principal), 403);
        $data = $request->validate([
            /** The SHA256 fingerprint of the SFTP host key to pin, as `host_key` gave it. */
            'accept' => ['nullable', 'string', 'regex:/^SHA256:[A-Za-z0-9+\/=]{20,64}$/'],
        ]);
        $record = $this->destinations->find($destination);
        $accept = (string) ($data['accept'] ?? '');
        $steps = [new Step(sprintf(__('Test backup destination %s'), $record->name), 'v-check-backup-destination', [$record->name, $accept, 'json'])];
        $this->gate->authorize($steps);
        if ($this->gate->dryRun()) {
            return $this->gate->preview($steps);
        }
        $result = $this->destinations->check($record->name, $accept);

        return response()->json(['data' => [
            /** @var bool */
            'ok' => $result['ok'],
            /** @var string|null */
            'error' => $result['error'] !== '' ? $result['error'] : null,
            /** @var string|null the fingerprint to confirm with `accept` (SFTP) */
            'host_key' => $result['hostKey'] !== '' ? $result['hostKey'] : null,
            /** @var int|null */
            'latency_ms' => ctype_digit($result['latency']) ? (int) $result['latency'] : null,
        ]]);
    }

    private static function path(string $name): string
    {
        return '/api/v2/system/backup-destinations/'.$name;
    }
}
