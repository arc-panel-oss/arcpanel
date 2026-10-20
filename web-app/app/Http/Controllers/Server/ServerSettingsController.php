<?php

namespace App\Http\Controllers\Server;

use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\BackupSettingsRequest;
use App\Http\Requests\Server\DatabaseSettingsRequest;
use App\Http\Requests\Server\GeneralSettingsRequest;
use App\Http\Requests\Server\MailSettingsRequest;
use App\Http\Requests\Server\SecuritySettingsRequest;
use App\Http\Requests\Server\SslSettingsRequest;
use App\Http\Requests\Server\WhiteLabelRequest;
use App\Policies\ServerPolicy;
use App\Services\Server\ServerSettings;
use App\Services\Server\ServerSettingsActions;
use App\Services\Server\SystemTaskService;
use App\Services\WebDomains\ApplyResult;
use App\View\WhiteLabel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Server settings (web/edit/server, web/edit/server/whitelabel), one page and
 * one form per tab (E7 batch 3 spec §8).
 */
final class ServerSettingsController extends Controller
{
    public function __construct(
        private readonly ServerSettings $settings,
        private readonly SystemTaskService $tasks,
        private readonly ServerSettingsActions $actions,
    ) {}

    public function index(Principal $principal, ServerPolicy $policy): RedirectResponse
    {
        abort_unless($policy->viewSettings($principal), 403);

        return redirect()->route('server.settings.tab', ['tab' => 'general']);
    }

    public function show(string $tab, Principal $principal, ServerPolicy $policy): View
    {
        abort_unless($policy->viewSettings($principal), 403);
        abort_unless($this->settings->available($tab), 404);
        $config = $this->settings->config();

        $data = match ($tab) {
            'general' => [
                'hostname' => $this->settings->hostname(),
                'timezone' => $this->settings->timezone(),
                'timezones' => $this->settings->timezones(),
                'languages' => $this->settings->languages(),
                'themes' => $this->settings->themes(),
            ],
            'web' => ['php' => $this->settings->phpVersions(), 'task' => $this->tasks->task()],
            'dns' => ['hosts' => $this->settings->dnsHosts()],
            'databases' => ['hosts' => $this->settings->databaseHosts()],
            'backups' => [
                'remote' => $this->settings->backupHost($this->settings->remoteBackupType($config)),
                'restic' => $this->settings->restic(),
            ],
            'ssl' => ['ssl' => $this->settings->ssl(), 'hostname' => $this->settings->hostname()],
            'plugins' => [
                'plugins' => array_map(fn (string $plugin) => $this->settings->pluginInstalled($plugin), array_combine(array_keys(ServerSettings::PLUGINS), array_keys(ServerSettings::PLUGINS))),
                'task' => $this->tasks->task(),
            ],
            'white-label' => ['customLogo' => WhiteLabel::customLogo()],
            default => [],
        };

        return view('server.settings.'.$tab, $data + [
            'tab' => $tab,
            'tabs' => $this->settings->tabs(),
            'config' => $config,
            'canEdit' => $policy->editSettings($principal),
            'protectAdmin' => $policy->protectAdmin($principal),
        ]);
    }

    public function general(GeneralSettingsRequest $request, Principal $principal, ServerPolicy $policy): RedirectResponse
    {
        abort_unless($policy->editSettings($principal), 403);
        $values = $request->values();
        $this->actions->checkGeneral($values);

        return $this->finish('general', $this->settings->apply($this->settings->planGeneral($values)));
    }

    /** POST web: the default PHP version (`v-change-sys-php`). */
    public function web(Request $request, Principal $principal, ServerPolicy $policy): RedirectResponse
    {
        abort_unless($policy->editSettings($principal), 403);
        $data = $request->validate(['php_default' => ['required', 'string', Rule::in($this->actions->installedPhp())]]);

        return $this->finish('web', $this->settings->apply($this->settings->planDefaultPhp($data['php_default'])));
    }

    /** POST web/php: install and remove PHP versions in the background (spec D4). */
    public function php(Request $request, Principal $principal, ServerPolicy $policy): RedirectResponse
    {
        abort_unless($policy->editSettings($principal), 403);
        $data = $request->validate(['versions' => ['array'], 'versions.*' => ['string', 'regex:/^\d\.\d$/']]);
        $changes = $this->settings->phpChanges(array_values($data['versions'] ?? []));
        $back = redirect()->route('server.settings.tab', ['tab' => 'web']);
        if ($changes['add'] === [] && $changes['delete'] === []) {
            return $back->with('ok', __('Nothing has changed.'));
        }

        $result = $this->tasks->start('php', [
            $changes['add'] === [] ? '-' : implode(',', $changes['add']),
            $changes['delete'] === [] ? '-' : implode(',', $changes['delete']),
        ]);

        return $result->ok()
            ? $back->with('ok', __('The PHP versions are being changed. This may take a few minutes.'))
            : $back->with('error', SystemTaskService::refusal($result));
    }

    public function mail(MailSettingsRequest $request, Principal $principal, ServerPolicy $policy): RedirectResponse
    {
        abort_unless($policy->editSettings($principal), 403);
        abort_unless($this->settings->available('mail'), 404);
        $values = $request->values();
        $this->actions->checkMail($values);

        return $this->finish('mail', $this->settings->apply($this->settings->planMail($values)));
    }

    public function databases(DatabaseSettingsRequest $request, Principal $principal, ServerPolicy $policy): RedirectResponse
    {
        abort_unless($policy->editSettings($principal), 403);
        abort_unless($this->settings->available('databases'), 404);

        return $this->finish('databases', $this->settings->apply($this->settings->planDatabases($request->values())));
    }

    /** POST databases/hosts/{type}/{host}/password: the password of one database host (spec §13.2). */
    public function databaseHostPassword(Request $request, string $type, string $host, Principal $principal, ServerPolicy $policy): RedirectResponse
    {
        abort_unless($policy->editSettings($principal), 403);
        abort_unless($this->actions->knowsDatabaseHost($type, $host), 404);
        $data = $request->validateWithBag('dbhost', [
            'user' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'password' => ['required', 'string', 'min:8', 'max:256', 'not_regex:/[\'"\\\\]/'],
        ]);

        return $this->finish('databases', $this->settings->apply(
            $this->settings->planDatabaseHostPassword($type, $host, $data['user'], $data['password'])));
    }

    public function backups(BackupSettingsRequest $request, Principal $principal, ServerPolicy $policy): RedirectResponse
    {
        abort_unless($policy->editSettings($principal), 403);
        $values = $request->values();
        $this->actions->checkBackups($values);

        return $this->finish('backups', $this->settings->apply($this->settings->planBackups($values)));
    }

    public function ssl(SslSettingsRequest $request, Principal $principal, ServerPolicy $policy): RedirectResponse
    {
        abort_unless($policy->editSettings($principal), 403);

        return $this->finish('ssl', $this->settings->apply(
            $this->settings->planSsl((string) $request->input('certificate'), (string) $request->input('key'))));
    }

    public function security(SecuritySettingsRequest $request, Principal $principal, ServerPolicy $policy): RedirectResponse
    {
        abort_unless($policy->editSettings($principal), 403);

        return $this->finish('security', $this->settings->apply(
            $this->settings->planSecurity($request->values(), $policy->protectAdmin($principal))));
    }

    /** POST plugins: the app installer flag; the other plugins are installed by the system task. */
    public function plugins(Request $request, Principal $principal, ServerPolicy $policy): RedirectResponse
    {
        abort_unless($policy->editSettings($principal), 403);

        return $this->finish('plugins', $this->settings->apply($this->settings->planAppInstaller($request->boolean('app_installer'))));
    }

    /** POST plugins/{plugin}: install or remove a plugin in the background (spec D4). */
    public function plugin(Request $request, string $plugin, Principal $principal, ServerPolicy $policy): RedirectResponse
    {
        abort_unless($policy->editSettings($principal), 403);
        abort_unless(ServerSettingsActions::knowsPlugin($plugin), 404);
        $data = $request->validate(['state' => ['required', 'in:on,off']]);

        $result = $this->tasks->start('plugin', [$plugin, $data['state']]);
        $back = redirect()->route('server.settings.tab', ['tab' => 'plugins']);

        return $result->ok()
            ? $back->with('ok', $data['state'] === 'on' ? __('The plugin is being installed.') : __('The plugin is being removed.'))
            : $back->with('error', SystemTaskService::refusal($result));
    }

    public function whiteLabel(WhiteLabelRequest $request, Principal $principal, ServerPolicy $policy): RedirectResponse
    {
        abort_unless($policy->editSettings($principal), 403);

        return $this->finish('white-label', $this->settings->apply($this->settings->planWhiteLabel($request->values())));
    }

    /** POST white-label/logo: apply the custom logos or restore the defaults (spec D6). */
    public function logo(Request $request, Principal $principal, ServerPolicy $policy): RedirectResponse
    {
        abort_unless($policy->editSettings($principal), 403);
        $data = $request->validate(['action' => ['required', 'in:apply,restore']]);

        return $this->finish('white-label', $this->settings->updateLogo($data['action'] === 'restore'));
    }

    /** Back to the tab with a toast; a failure names the step that failed and the steps already applied. */
    private function finish(string $tab, ApplyResult $result): RedirectResponse
    {
        $redirect = redirect()->route('server.settings.tab', ['tab' => $tab]);
        if (! $result->ok()) {
            $applied = array_map(fn ($step) => $step->label, $result->applied);
            $detail = $result->error.($applied === [] ? '' : ' '.sprintf(__('Applied before the failure: %s.'), implode(', ', $applied)));

            return $redirect->withInput(request()->except(['password', 'relay_password', 'key', 'certificate', 'repo', 's3_secret']))
                ->with('error', sprintf(__('%s failed'), $result->failed?->label ?? ''))
                ->with('error_detail', $detail);
        }

        return $redirect->with('ok', $result->changed() ? __('Changes have been saved.') : __('Nothing has changed.'));
    }
}
