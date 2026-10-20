<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\PlanGate;
use App\Api\SystemTaskStarter;
use App\Arc\SysConfig;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\RunUpdatesRequest;
use App\Http\Requests\Api\V2\SetUpdateSettingsRequest;
use App\Http\Resources\V2\PanelPackageResource;
use App\Http\Resources\V2\UpdateSettingsResource;
use App\Policies\ServerPolicy;
use App\Services\Server\PanelUpdates;
use App\Services\Server\ServerSettings;
use App\Services\Server\UpdateCenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Updates of the panel packages for administrators (E34 batch 6 spec §5.4):
 * Admin → Server → Updates as JSON. An update runs as the system task of
 * the server: `202` with the task `sys:ID`, followed with getTask. The
 * Update Center of E33 (releases, policy, history) is UpdateCenterController.
 *
 * @tags Server
 */
final class UpdateController extends Controller
{
    public function __construct(
        private readonly PanelUpdates $updates,
        private readonly UpdateCenter $center,
        private readonly SysConfig $config,
        private readonly PlanGate $gate,
    ) {}

    /**
     * List the panel packages
     *
     * The packages of the panel with their version and whether an update
     * is available. `meta` holds the update settings (setUpdateSettings).
     * The list is not paginated.
     *
     * @operationId listUpdates
     */
    public function index(Principal $principal, ServerPolicy $policy): AnonymousResourceCollection
    {
        abort_unless($policy->viewUpdates($principal), 403);

        return PanelPackageResource::collection($this->updates->packages())->additional(['meta' => $this->current()]);
    }

    /**
     * Update the panel packages
     *
     * Updates `package`, or every package without it, as the system task;
     * the panel may restart while it runs. With `version` instead, the
     * system task updates the panel to that release of listPanelReleases
     * with its pre-flight, health check and rollback (E33): a withdrawn
     * release is refused, and a release that needs attention needs
     * `confirm_attention: true`. The update and its result are in
     * listUpdateHistory. Another running system task answers `409 E_INUSE`
     * with `existing`.
     *
     * @operationId runUpdates
     *
     * @monitor
     *
     * @status 202
     */
    public function run(RunUpdatesRequest $request, Principal $principal, ServerPolicy $policy, SystemTaskStarter $starter): JsonResponse
    {
        abort_unless($policy->manageUpdates($principal), 403);
        $data = $request->validated();
        if (isset($data['version'])) {
            $version = (string) $data['version'];
            $this->assertRelease($version, (bool) ($data['confirm_attention'] ?? false));

            return $starter->start(UpdateCenter::planRelease($version, 'api', $principal->accessKey ?? $principal->loginUser));
        }
        $package = $data['package'] ?? null;

        return $starter->start(PanelUpdates::planRun($package !== null ? (string) $package : null));
    }

    /**
     * Change the update settings
     *
     * `auto_update`, `send_email` and `send_log`; fields left out keep their
     * value, and only a changed value runs its command. `auto_update` is the
     * update policy of setUpdatePolicy: `true` sets `patch`, `false` sets
     * `notify`, and it reads `true` with the policy `patch`.
     *
     * @operationId setUpdateSettings
     */
    public function settings(SetUpdateSettingsRequest $request, Principal $principal, ServerPolicy $policy, ServerSettings $server): UpdateSettingsResource|JsonResponse
    {
        abort_unless($policy->manageUpdates($principal), 403);
        $data = $request->validated();
        $steps = [
            ...(array_key_exists('auto_update', $data) ? $this->center->planAutoUpdate((bool) $data['auto_update']) : []),
            // A value left out is null: its key is not written
            ...$server->planUpdateNotifications(
                array_key_exists('send_email', $data) ? (bool) $data['send_email'] : null,
                array_key_exists('send_log', $data) ? (bool) $data['send_log'] : null,
            ),
        ];

        return $this->gate->execute($steps, $server->apply(...)) ?? new UpdateSettingsResource($this->current());
    }

    /** @return array{auto_update: bool, send_email: bool, send_log: bool} */
    private function current(): array
    {
        $this->config->forget();

        return [
            'auto_update' => $this->center->autoUpdate(),
            'send_email' => $this->config->get('UPGRADE_SEND_EMAIL') === 'true',
            'send_log' => $this->config->get('UPGRADE_SEND_EMAIL_LOG') === 'true',
        ];
    }

    /**
     * The update runs to a release of the manifest: listed, not withdrawn,
     * and confirmed when it needs attention. The task checks the rest
     * (E33 phase 1 spec §7.3).
     */
    private function assertRelease(string $version, bool $confirmed): void
    {
        $release = $this->center->release($version);
        if ($release === null) {
            throw ValidationException::withMessages(['version' => sprintf(__('ArcPanel %s is not a release newer than the installed version.'), $version)]);
        }
        if (is_array($release['WITHDRAWN'] ?? null)) {
            throw ValidationException::withMessages(['version' => sprintf(__('ArcPanel %s was withdrawn.'), $version)]);
        }
        if (($release['ATTENTION'] ?? false) === true && ! $confirmed) {
            throw ValidationException::withMessages(['confirm_attention' => sprintf(__('ArcPanel %s needs attention: read its attention items and confirm them.'), $version)]);
        }
    }
}
