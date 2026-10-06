<?php

namespace App\Http\Controllers\Server;

use App\Arc\SysConfig;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\ServerPolicy;
use App\Services\Server\PanelUpdates;
use App\Services\Server\ServerSettings;
use App\Services\Server\SystemTaskService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Updates of the panel packages (web/list/updates, web/update/hestia,
 * web/add|delete/cron/autoupdate), E7 batch 3 spec §10.
 */
final class UpdateController extends Controller
{
    public function __construct(
        private readonly PanelUpdates $updates,
        private readonly SystemTaskService $tasks,
    ) {}

    public function index(Principal $principal, ServerPolicy $policy, SysConfig $config): View
    {
        abort_unless($policy->viewUpdates($principal), 403);
        $config->forget();

        return view('server.updates', [
            'packages' => $this->updates->packages(),
            'autoUpdate' => $this->updates->autoUpdate(),
            'task' => $this->tasks->task(),
            'sendEmail' => $config->get('UPGRADE_SEND_EMAIL') === 'true',
            'sendLog' => $config->get('UPGRADE_SEND_EMAIL_LOG') === 'true',
            'canManage' => $policy->manageUpdates($principal),
        ]);
    }

    /** POST update: one package, or every package without one (system task, spec D4). */
    public function update(Request $request, Principal $principal, ServerPolicy $policy): RedirectResponse
    {
        abort_unless($policy->manageUpdates($principal), 403);
        $data = $request->validate(['package' => ['nullable', 'string', Rule::in(PanelUpdates::PACKAGES)]]);
        $package = (string) ($data['package'] ?? '');

        $result = $this->tasks->start('hestia-update', $package === '' ? [] : [$package]);
        $back = redirect()->route('server.updates');

        return $result->ok()
            ? $back->with('ok', $package === '' ? __('The packages are being updated.') : sprintf(__('%s is being updated.'), $package))
            : $back->with('error', SystemTaskService::refusal($result));
    }

    public function autoUpdate(Request $request, Principal $principal, ServerPolicy $policy): RedirectResponse
    {
        abort_unless($policy->manageUpdates($principal), 403);
        $data = $request->validate(['state' => ['required', 'in:on,off']]);

        $result = $this->updates->setAutoUpdate($data['state'] === 'on');
        $back = redirect()->route('server.updates');

        return $result->ok()
            ? $back->with('ok', $data['state'] === 'on' ? __('Automatic updates are on.') : __('Automatic updates are off.'))
            : $back->with('error', $result->errorMessage());
    }

    /** POST notifications: the upgrade e-mails (moved here from the server settings, spec D11). */
    public function notifications(Request $request, Principal $principal, ServerPolicy $policy, ServerSettings $settings): RedirectResponse
    {
        abort_unless($policy->manageUpdates($principal), 403);
        $result = $settings->apply($settings->planUpdateNotifications($request->boolean('send_email'), $request->boolean('send_log')));
        $back = redirect()->route('server.updates');
        if (! $result->ok()) {
            return $back->with('error', sprintf(__('%s failed'), $result->failed?->label ?? ''))->with('error_detail', $result->error);
        }

        return $back->with('ok', $result->changed() ? __('Changes have been saved.') : __('Nothing has changed.'));
    }
}
