<?php

namespace App\Http\Controllers\Server;

use App\Arc\ArcCommandException;
use App\Arc\SysConfig;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\ServerPolicy;
use App\Services\Server\PanelUpdates;
use App\Services\Server\ServerSettings;
use App\Services\Server\SystemTaskService;
use App\Services\Server\UpdateCenter;
use App\Services\Support\PlanRunner;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The Updates page of the Update Center (E33 phase 1 spec §8): the releases
 * of the signed manifest with their pre-flight, the update policy, the
 * history with its rollback, and the installed packages. Updates and
 * rollbacks run as the system task.
 */
final class UpdateController extends Controller
{
    /** Runs the History card shows (spec §8). */
    public const HISTORY_ROWS = 20;

    public function __construct(
        private readonly UpdateCenter $center,
        private readonly PanelUpdates $updates,
        private readonly SystemTaskService $tasks,
    ) {}

    public function index(Principal $principal, ServerPolicy $policy, SysConfig $config): View
    {
        abort_unless($policy->viewUpdates($principal), 403);
        $config->forget();

        // Without a verified manifest the command fails with its reason; the page still works
        $releases = null;
        $manifestError = null;
        try {
            $releases = $this->center->releases();
        } catch (ArcCommandException $e) {
            $manifestError = $e->userMessage();
        }

        return view('server.updates', [
            'policy' => $this->center->policy(),
            'releases' => $releases,
            'manifestError' => $manifestError,
            'history' => array_slice($this->center->history(), 0, self::HISTORY_ROWS),
            'packages' => $this->updates->packages(),
            'task' => $this->tasks->task(),
            'sendEmail' => $config->get('UPGRADE_SEND_EMAIL') === 'true',
            'sendLog' => $config->get('UPGRADE_SEND_EMAIL_LOG') === 'true',
            'canManage' => $policy->manageUpdates($principal),
        ]);
    }

    /** GET the pre-flight of an update to VERSION for the dialog (JSON); it changes nothing. */
    public function preflight(string $version, Principal $principal, ServerPolicy $policy): JsonResponse
    {
        abort_unless($policy->viewUpdates($principal), 403);

        return response()->json($this->center->preflight($version));
    }

    /** POST check: download the manifest again. */
    public function check(Principal $principal, ServerPolicy $policy): RedirectResponse
    {
        abort_unless($policy->manageUpdates($principal), 403);
        $back = redirect()->route('server.updates');
        try {
            $this->center->releases(true);
        } catch (ArcCommandException $e) {
            return $back->with('error', $e->userMessage());
        }

        return $back->with('ok', __('The release manifest has been checked.'));
    }

    /** POST release: update to a release of the manifest (system task `arcpanel-release`, §7.6). */
    public function release(Request $request, Principal $principal, ServerPolicy $policy): RedirectResponse
    {
        abort_unless($policy->manageUpdates($principal), 403);
        $data = $request->validate([
            'version' => ['required', 'string', 'regex:/^'.UpdateCenter::VERSION_PATTERN.'$/'],
            'confirm_attention' => ['sometimes', 'boolean'],
        ]);
        $version = (string) $data['version'];
        $this->center->assertRelease($version, (bool) ($data['confirm_attention'] ?? false));

        $step = UpdateCenter::planRelease($version, 'admin', $principal->loginUser);
        $result = $this->tasks->start($step->args[0], array_slice($step->args, 1));
        $back = redirect()->route('server.updates');

        return $result->ok()
            ? $back->with('ok', sprintf(__('ArcPanel is being updated to %s.'), $version))
            : $back->with('error', SystemTaskService::refusal($result));
    }

    /** POST update: the newest packages, for a server without a verified manifest (system task, spec D4 of E7 batch 3). */
    public function update(Request $request, Principal $principal, ServerPolicy $policy): RedirectResponse
    {
        abort_unless($policy->manageUpdates($principal), 403);
        $data = $request->validate(['package' => ['nullable', 'string', Rule::in(PanelUpdates::PACKAGES)]]);
        $package = (string) ($data['package'] ?? '');

        $result = $this->tasks->start('arcpanel-update', $package === '' ? [] : [$package]);
        $back = redirect()->route('server.updates');

        return $result->ok()
            ? $back->with('ok', $package === '' ? __('The packages are being updated.') : sprintf(__('%s is being updated.'), $package))
            : $back->with('error', SystemTaskService::refusal($result));
    }

    /** POST policy: the automatic update policy, its window and delay (§7.2). */
    public function policy(Request $request, Principal $principal, ServerPolicy $policy, PlanRunner $runner): RedirectResponse
    {
        abort_unless($policy->manageUpdates($principal), 403);
        $data = $request->validate([
            'policy' => ['required', 'string', Rule::in(UpdateCenter::POLICIES)],
            'window_days' => ['required', 'array', 'min:1', 'max:7'],
            'window_days.*' => ['string', 'distinct', Rule::in(UpdateCenter::DAYS)],
            'window_from' => ['required', 'integer', 'min:0', 'max:23'],
            'window_to' => ['required', 'integer', 'min:1', 'max:24', function (string $attribute, mixed $value, Closure $fail) use ($request) {
                if ((int) $value === (int) $request->input('window_from')) {
                    $fail(__('The window must not start and end at the same hour.'));
                }
            }],
            'delay_days' => ['required', 'integer', 'min:0', 'max:30'],
        ]);
        $hours = sprintf('%02d-%02d', (int) $data['window_from'], (int) $data['window_to']);

        $result = $runner->apply($this->center->planPolicy((string) $data['policy'], array_map('strval', $data['window_days']), $hours, (int) $data['delay_days']));
        $back = redirect()->route('server.updates');
        if (! $result->ok()) {
            return $back->with('error', sprintf(__('%s failed'), $result->failed?->label ?? ''))->with('error_detail', $result->error);
        }

        return $back->with('ok', $result->changed() ? __('The update policy has been saved.') : __('Nothing has changed.'));
    }

    /** POST resume: clear the pause a failed update set (§7.8). */
    public function resume(Principal $principal, ServerPolicy $policy, PlanRunner $runner): RedirectResponse
    {
        abort_unless($policy->manageUpdates($principal), 403);
        $result = $runner->apply([UpdateCenter::planResume()]);
        $back = redirect()->route('server.updates');

        return $result->ok()
            ? $back->with('ok', __('Automatic updates have been resumed.'))
            : $back->with('error', (string) $result->error);
    }

    /** POST rollback: reinstall the version before run ID (system task `arcpanel-rollback`, §7.7). */
    public function rollback(int $id, Principal $principal, ServerPolicy $policy): RedirectResponse
    {
        abort_unless($policy->manageUpdates($principal), 403);
        $step = UpdateCenter::planRollback($id);
        $result = $this->tasks->start($step->args[0], array_slice($step->args, 1));
        $back = redirect()->route('server.updates');

        return $result->ok()
            ? $back->with('ok', sprintf(__('Update run %d is being rolled back.'), $id))
            : $back->with('error', SystemTaskService::refusal($result));
    }

    /** POST notifications: the upgrade e-mails (moved here from the server settings, E7 batch 3 spec D11). */
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
