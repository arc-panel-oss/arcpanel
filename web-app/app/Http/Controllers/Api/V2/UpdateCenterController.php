<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\ApiException;
use App\Api\ErrorCode;
use App\Api\Paginates;
use App\Api\PlanGate;
use App\Api\SystemTaskStarter;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\SetUpdatePolicyRequest;
use App\Http\Resources\V2\PanelReleaseResource;
use App\Http\Resources\V2\UpdatePolicyResource;
use App\Http\Resources\V2\UpdatePreflightResource;
use App\Http\Resources\V2\UpdateRunResource;
use App\Policies\ServerPolicy;
use App\Services\Server\UpdateCenter;
use App\Services\Support\PlanRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The Update Center of the panel for administrators (E33 phase 1 spec §9):
 * the releases of the signed release manifest, their pre-flight, the
 * automatic update policy and the update history with its rollback. An
 * update to a release starts with runUpdates and `version`.
 *
 * @tags Server
 */
final class UpdateCenterController extends Controller
{
    public function __construct(
        private readonly UpdateCenter $center,
        private readonly PlanGate $gate,
        private readonly Paginates $pages,
        private readonly PlanRunner $runner,
    ) {}

    /**
     * List the panel releases
     *
     * The releases of the signed release manifest that are newer than the
     * installed version, newest first, with whether the update policy would
     * install each one and why not. `refresh=true` downloads the manifest
     * again; otherwise a copy older than an hour is downloaded. Without a
     * verified manifest the answer is `404 E_NOTEXIST` with the reason;
     * runUpdates without `version` still updates to the latest packages.
     *
     * @operationId listPanelReleases
     */
    public function releases(Request $request, Principal $principal, ServerPolicy $policy): AnonymousResourceCollection
    {
        abort_unless($policy->viewUpdates($principal), 403);
        // A query string carries `true`, which the boolean rule refuses
        $request->validate(['refresh' => ['sometimes', 'in:true,false,1,0']]);

        $page = $this->pages->slice($request, $this->center->releases($request->boolean('refresh')));

        return PanelReleaseResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * Check an update to a release
     *
     * Runs the pre-flight of an update to `version` without changing
     * anything: the manifest, the version, the free disk space, a simulated
     * install, the running tasks and backups, and whether the release needs
     * attention. `ready` is false when a check failed.
     *
     * @operationId checkPanelRelease
     */
    public function preflight(string $version, Principal $principal, ServerPolicy $policy): UpdatePreflightResource
    {
        abort_unless($policy->viewUpdates($principal), 403);

        return new UpdatePreflightResource(['version' => $version, ...$this->center->preflight($version)]);
    }

    /**
     * Show the update policy
     *
     * The automatic update policy, its window and delay, whether automatic
     * updates are paused, the installed version and the state of the release
     * manifest.
     *
     * @operationId getUpdatePolicy
     */
    public function policy(Principal $principal, ServerPolicy $policy): UpdatePolicyResource
    {
        abort_unless($policy->viewUpdates($principal), 403);

        return new UpdatePolicyResource($this->center->policy());
    }

    /**
     * Change the update policy
     *
     * `policy`, `window_days`, `window_hours` and `delay_days`; fields left
     * out keep their value, and nothing runs when nothing changes. `off`
     * removes the hourly check of the cron table, the other policies install
     * it.
     *
     * @operationId setUpdatePolicy
     */
    public function setPolicy(SetUpdatePolicyRequest $request, Principal $principal, ServerPolicy $policy): UpdatePolicyResource|JsonResponse
    {
        abort_unless($policy->manageUpdates($principal), 403);
        $data = $request->validated();
        $steps = $this->center->planPolicy(
            isset($data['policy']) ? (string) $data['policy'] : null,
            isset($data['window_days']) ? array_map('strval', (array) $data['window_days']) : null,
            isset($data['window_hours']) ? (string) $data['window_hours'] : null,
            isset($data['delay_days']) ? (int) $data['delay_days'] : null,
        );

        return $this->gate->execute($steps, $this->runner->apply(...)) ?? new UpdatePolicyResource($this->center->policy());
    }

    /**
     * Resume automatic updates
     *
     * Clears the pause that a failed update set (`paused`), so the policy
     * installs releases again.
     *
     * @operationId resumeAutoUpdates
     */
    public function resume(Principal $principal, ServerPolicy $policy): UpdatePolicyResource|JsonResponse
    {
        abort_unless($policy->manageUpdates($principal), 403);

        return $this->gate->execute([UpdateCenter::planResume()], $this->runner->apply(...)) ?? new UpdatePolicyResource($this->center->policy());
    }

    /**
     * List the update history
     *
     * The update runs of the panel, newest first, from the policy, the
     * Updates page and the API. Only the newest update can be rolled back:
     * `rollback` says whether it can, `rollback_reason` why not.
     *
     * @operationId listUpdateHistory
     */
    public function history(Request $request, Principal $principal, ServerPolicy $policy): AnonymousResourceCollection
    {
        abort_unless($policy->viewUpdates($principal), 403);
        $page = $this->pages->slice($request, $this->center->history());

        return UpdateRunResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * Roll back an update
     *
     * Reinstalls the version before update run `id` and its configuration, as
     * the system task; the panel restarts while it runs. Another running
     * system task answers `409 E_INUSE` with `existing`.
     *
     * @operationId rollbackPanelUpdate
     *
     * @monitor
     *
     * @status 202
     *
     * @conflict E_FORBIDDEN The rollback is not allowed: the message names the condition that failed (only the newest update, to a patch release without upgrade steps, while APT offers the previous version and its saved configuration exists).
     */
    public function rollback(int $id, Principal $principal, ServerPolicy $policy, SystemTaskStarter $starter): JsonResponse
    {
        abort_unless($policy->manageUpdates($principal), 403);
        try {
            return $starter->start(UpdateCenter::planRollback($id));
        } catch (ApiException $e) {
            // A refusal of v-start-sys-task names the failed condition; a refused key has no step
            if ($e->errorCode === ErrorCode::Forbidden && array_key_exists('step', $e->details)) {
                throw new ApiException(ErrorCode::Forbidden, $e->getMessage(), 409);
            }

            throw $e;
        }
    }
}
