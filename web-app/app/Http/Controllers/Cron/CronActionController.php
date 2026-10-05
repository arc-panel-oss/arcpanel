<?php

namespace App\Http\Controllers\Cron;

use App\Auth\OwnerScope;
use App\Auth\Principal;
use App\Http\Controllers\Concerns\ChecksBulkItems;
use App\Http\Controllers\Controller;
use App\Policies\CronPolicy;
use App\Services\Cron\CronService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Single and bulk actions on cron jobs (web/bulk/cron, web/suspend/cron,
 * web/unsuspend/cron, web/delete/cron) and the e-mail reports of an owner
 * (web/add/cron/reports, web/delete/cron/reports).
 */
final class CronActionController extends Controller
{
    use ChecksBulkItems;

    public function __construct(private readonly CronService $cron) {}

    public function suspend(string $user, string $job, Principal $principal, CronPolicy $policy): RedirectResponse
    {
        return $this->single('suspend', $user, $job, $policy->suspend($principal, $user));
    }

    public function unsuspend(string $user, string $job, Principal $principal, CronPolicy $policy): RedirectResponse
    {
        return $this->single('unsuspend', $user, $job, $policy->suspend($principal, $user));
    }

    public function destroy(string $user, string $job, Principal $principal, CronPolicy $policy): RedirectResponse
    {
        return $this->single('delete', $user, $job, $policy->delete($principal, $user));
    }

    /** POST /app/cron/bulk {action, items[]} with items "user/job". */
    public function bulk(Request $request, Principal $principal, CronPolicy $policy, OwnerScope $scope): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(array_keys(CronService::BULK_COMMANDS))],
            'items' => ['required', 'array', 'min:1', 'max:1000'],
            'items.*' => ['required', 'string', 'max:200', 'regex:/^[[:alnum:]][-.\w]*\/\d{1,9}$/'],
        ]);
        $action = $data['action'];
        $items = $this->bulkItems($data['items'], 2, $scope, fn (string $owner) => $policy->action($action, $principal, $owner));

        return $this->cron->bulk($action, $items)
            ->flash(redirect()->route('cron.index', array_filter(['owner' => $request->query('owner')])));
    }

    /** POST /app/cron/{user}/reports: send the output of the owner's cron jobs by e-mail. */
    public function enableReports(Request $request, string $user, Principal $principal, CronPolicy $policy): RedirectResponse
    {
        return $this->reports($request, $user, true, $policy->reports($principal, $user));
    }

    /** DELETE /app/cron/{user}/reports */
    public function disableReports(Request $request, string $user, Principal $principal, CronPolicy $policy): RedirectResponse
    {
        return $this->reports($request, $user, false, $policy->reports($principal, $user));
    }

    private function reports(Request $request, string $user, bool $enabled, bool $allowed): RedirectResponse
    {
        abort_unless($allowed, 403);

        $result = $this->cron->setReports($user, $enabled);
        $back = redirect()->route('cron.index', array_filter(['owner' => $request->query('owner')]));
        if (! $result->ok()) {
            return $back->with('error', $result->message() !== '' ? $result->message() : sprintf(__('Error code: %s'), $result->exitCode));
        }

        // Legacy stored these as error messages (spec §11): they are success toasts here.
        return $back->with('ok', $enabled
            ? __('Cron job email reporting has been successfully enabled.')
            : __('Cron job email reporting has been successfully disabled.'));
    }

    private function single(string $action, string $user, string $job, bool $allowed): RedirectResponse
    {
        abort_unless($allowed, 403);
        $this->cron->find($user, $job);

        $result = $this->cron->single($action, $user, $job);
        $back = $action === 'delete'
            ? redirect()->route('cron.index')
            : redirect()->back(fallback: route('cron.index'));

        if (! $result->ok()) {
            return $back->with('error', $result->firstError());
        }

        return $back->with('ok', match ($action) {
            'suspend' => __('Cron job has been suspended.'),
            'unsuspend' => __('Cron job has been unsuspended.'),
            default => __('Cron job has been deleted.'),
        });
    }
}
