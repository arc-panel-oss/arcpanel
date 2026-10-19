<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\PlanGate;
use App\Api\SystemTaskStarter;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\SetDatabaseHostPasswordRequest;
use App\Http\Requests\Api\V2\SetPhpVersionsRequest;
use App\Http\Requests\Api\V2\SetPluginRequest;
use App\Http\Requests\Api\V2\UpdateServerSettingsRequest;
use App\Http\Resources\V2\PhpVersionResource;
use App\Http\Resources\V2\PluginResource;
use App\Policies\ServerPolicy;
use App\Services\Server\ServerSettings;
use App\Services\Server\ServerSettingsActions;
use App\Services\Server\ServerSettingsApi;
use App\Services\Server\SystemTaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Server settings for administrators (E34 batch 6 spec §5.7, D58): Admin →
 * Server → Server settings as JSON. Each tab answers only the fields of its
 * allow-list; secrets answer `{"set": bool}` and are never returned, also
 * not after a change or in a dry run. A key may not change the API
 * switches, the protection of the administrator, phpMyAdmin single sign-on
 * or the logo: those fields answer `403` naming them. PHP versions and
 * plugins change as the system task (`202` with `sys:ID`).
 *
 * @tags Server
 */
final class ServerSettingsController extends Controller
{
    public function __construct(
        private readonly ServerSettings $settings,
        private readonly ServerSettingsApi $api,
        private readonly ServerSettingsActions $actions,
        private readonly PlanGate $gate,
    ) {}

    /**
     * Get the server settings
     *
     * The allow-listed fields of every tab, grouped by tab. Tabs whose
     * system the server does not run (`dns`, `mail`, `databases`) are left
     * out. Secrets (the SMTP relay password, the remote backup password,
     * the restic repository, the panel's private key) answer `{"set": bool}`.
     *
     * @operationId getServerSettings
     */
    public function show(Principal $principal, ServerPolicy $policy): JsonResponse
    {
        abort_unless($policy->viewSettings($principal), 403);
        $data = [];
        foreach ($this->settings->tabs() as $tab) {
            $data[$tab] = $this->api->read($tab);
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Change the settings of a tab
     *
     * The fields of one tab (`general`, `web`, `mail`, `databases`,
     * `backups`, `ssl`, `security`, `plugins`, `white-label`; `dns` is
     * read-only), under the names of getServerSettings. Fields left out keep
     * their value and only a changed value runs its command. A tab whose
     * system the server does not run answers `404`. `API_SYSTEM`, `API`,
     * `API_ALLOWED_IP`, `POLICY_SYSTEM_PROTECTED_ADMIN`,
     * `POLICY_SYSTEM_HIDE_ADMIN`, `POLICY_SYSTEM_HIDE_SERVICES`, `pma_sso`,
     * `pma_restrict` and the logo answer `403` naming the field; they stay
     * on the page. Secrets (`relay_password`, `password`, `repo`, `key`) are
     * accepted and never answered. Answers the tab as getServerSettings
     * shows it; a change that fails half-way names the steps applied.
     *
     * @operationId updateServerSettings
     */
    public function update(UpdateServerSettingsRequest $request, string $tab, Principal $principal, ServerPolicy $policy): JsonResponse
    {
        abort_unless($policy->editSettings($principal), 403);
        $steps = $this->api->plan($tab, $request->validated());

        return $this->gate->execute($steps, $this->settings->apply(...)) ?? response()->json(['data' => $this->api->read($tab)]);
    }

    /**
     * Set the PHP versions
     *
     * `versions` is the full list of PHP versions the server should have:
     * missing ones are installed, installed ones left out are removed, as
     * the system task (`202` with `sys:ID`). The default version and
     * versions that web domains use are never removed: leaving them out
     * answers `422`. Without a change, the versions answer `200`.
     *
     * @operationId setPhpVersions
     *
     * @monitor
     *
     * @status 202
     */
    public function php(SetPhpVersionsRequest $request, Principal $principal, ServerPolicy $policy, SystemTaskStarter $starter): AnonymousResourceCollection|JsonResponse
    {
        abort_unless($policy->editSettings($principal), 403);
        $wanted = array_values(array_map('strval', $request->validated()['versions'] ?? []));
        $states = $this->settings->phpVersions();
        $errors = [];
        foreach ($wanted as $i => $version) {
            if (! isset($states[$version])) {
                $errors['versions.'.$i] = sprintf(__('PHP %s is not supported on this server.'), $version);
            }
        }
        foreach ($states as $version => $state) {
            if (! $state['installed'] || in_array((string) $version, $wanted, true)) {
                continue;
            }
            if ($state['default']) {
                $errors['versions'][] = sprintf(__('PHP %s is the default version and is never removed.'), $version);
            } elseif ($state['usedBy'] !== []) {
                $errors['versions'][] = sprintf(__('PHP %s is used by %s and is never removed.'), $version, implode(', ', $state['usedBy']));
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $changes = $this->settings->phpChanges($wanted);
        if ($changes['add'] === [] && $changes['delete'] === []) {
            if ($this->gate->dryRun()) {
                return $this->gate->preview([]);
            }

            return PhpVersionResource::collection(array_map(fn (string $version, array $state) => [
                'version' => $version, 'installed' => $state['installed'], 'default' => $state['default'], 'used_by' => $state['usedBy'],
            ], array_map('strval', array_keys($states)), array_values($states)));
        }

        return $starter->start(SystemTaskService::step(__('Change the PHP versions'), 'php', [
            $changes['add'] === [] ? '-' : implode(',', $changes['add']),
            $changes['delete'] === [] ? '-' : implode(',', $changes['delete']),
        ]));
    }

    /**
     * Install or remove a plugin
     *
     * `filemanager`, `web-terminal`, `cgroups`, `quota` or `firewall`, as
     * the system task (`202` with `sys:ID`). A plugin already in that state
     * answers `200`.
     *
     * @operationId setPlugin
     *
     * @monitor
     *
     * @status 202
     */
    public function plugin(SetPluginRequest $request, string $plugin, Principal $principal, ServerPolicy $policy, SystemTaskStarter $starter): PluginResource|JsonResponse
    {
        abort_unless($policy->editSettings($principal), 403);
        abort_unless(ServerSettingsActions::knowsPlugin($plugin), 404);
        $enabled = (bool) $request->validated()['enabled'];
        $this->settings->config();
        if ($this->settings->pluginInstalled($plugin) === $enabled) {
            return $this->gate->dryRun() ? $this->gate->preview([]) : new PluginResource(['name' => $plugin, 'enabled' => $enabled]);
        }

        return $starter->start(SystemTaskService::step(
            sprintf($enabled ? __('Install the plugin %s') : __('Remove the plugin %s'), $plugin),
            'plugin',
            [$plugin, $enabled ? 'on' : 'off'],
        ));
    }

    /**
     * Change the password of a database host
     *
     * The password of `user` on a MySQL host of the server (getServerSettings
     * `databases.hosts`). The password reaches the command in a file and is
     * never answered, also not in a dry run.
     *
     * @operationId setDatabaseHostPassword
     *
     * @status 204
     */
    public function databaseHostPassword(SetDatabaseHostPasswordRequest $request, string $type, string $host, Principal $principal, ServerPolicy $policy): Response|JsonResponse
    {
        abort_unless($policy->editSettings($principal), 403);
        abort_unless($this->settings->available('databases') && $this->actions->knowsDatabaseHost($type, $host), 404);
        $data = $request->validated();
        $steps = $this->settings->planDatabaseHostPassword($type, $host, (string) $data['user'], (string) $data['password']);

        return $this->gate->execute($steps, $this->settings->apply(...)) ?? response()->noContent();
    }
}
