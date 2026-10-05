<?php

namespace App\Http\Controllers\Cron;

use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cron\StoreCronJobRequest;
use App\Http\Requests\Cron\UpdateCronJobRequest;
use App\Policies\Capabilities;
use App\Policies\CronPolicy;
use App\Services\Cron\CronService;
use App\Services\Support\OwnerList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Cron job list, creation and edit (web/list/cron, web/add/cron, web/edit/cron).
 */
final class CronController extends Controller
{
    /** Lists longer than this are paged on the server. */
    public const PAGE_SIZE = 500;

    public function __construct(private readonly CronService $cron) {}

    public function index(Request $request, Principal $principal, CronPolicy $policy, OwnerList $ownerList): View
    {
        abort_unless($policy->viewAny($principal), 403);

        $selection = $ownerList->forList($request->query('owner'));
        $records = $this->cron->forUsers($selection->users());
        $total = count($records);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($pages, max(1, (int) $request->query('page', 1)));
        $records = array_slice($records, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);

        $abilities = [];
        foreach ($records as $record) {
            $abilities[$record->user] ??= $policy->abilities($principal, $record->user);
        }

        // The notifications toggle belongs to one owner: the filtered one, or the only one.
        $reportsOwner = $selection->owner !== '' ? $selection->owner : (count($selection->owners) === 1 ? $selection->owners[0] : '');
        $reports = $reportsOwner !== '' && $policy->reports($principal, $reportsOwner)
            ? ['owner' => $reportsOwner, 'enabled' => $this->cron->reportsEnabled($reportsOwner)]
            : null;

        return view('cron.index', [
            'records' => $records,
            'abilities' => $abilities,
            'showOwner' => $selection->showOwner(),
            'everyUser' => $selection->everyUser,
            'owners' => $selection->owners,
            'owner' => $selection->owner,
            'reports' => $reports,
            'canCreate' => Capabilities::allows($principal->actingRole(), 'cron.create') && ! $principal->isReadOnly(),
            'canBulk' => ! $principal->isReadOnly(),
            'bulkActions' => [
                'suspend' => ['label' => __('Suspend'), 'icon' => 'pause', 'confirm' => __('Suspend %d cron jobs?')],
                'unsuspend' => ['label' => __('Unsuspend'), 'icon' => 'play'],
                'delete' => ['label' => __('Delete'), 'icon' => 'trash', 'danger' => true, 'confirm' => __('Delete %d cron jobs?')],
            ],
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ]);
    }

    public function create(Request $request, Principal $principal, CronPolicy $policy, OwnerList $ownerList): View
    {
        $owner = $ownerList->forCreate($request->query('owner'));
        abort_unless($policy->create($principal, $owner), 403);

        return view('cron.create', [
            'owner' => $owner,
            'owners' => $ownerList->pickable(),
            'ownerIsAdmin' => $ownerList->isAdmin($owner),
            'now' => $this->cron->systemTime(),
        ]);
    }

    public function store(StoreCronJobRequest $request, Principal $principal, CronPolicy $policy, OwnerList $ownerList): RedirectResponse
    {
        $owner = $ownerList->forCreate($request->input('owner'));
        abort_unless($policy->create($principal, $owner), 403);

        $result = $this->cron->apply([$this->cron->planCreate($owner, $request->schedule(), $request->command())]);
        if (! $result->ok()) {
            return back()->withInput()->with('error', $result->error);
        }

        // v-add-cron-job does not print the new job id: back to the owner's list.
        $filter = $owner !== $principal->effectiveUser() ? ['owner' => $owner] : [];

        return redirect()->route('cron.index', $filter)->with('ok', __('Cron job has been created successfully.'));
    }

    public function edit(string $user, string $job, Principal $principal, CronPolicy $policy): View
    {
        abort_unless($policy->view($principal, $user), 403);
        $record = $this->cron->find($user, $job);

        return view('cron.edit', [
            'record' => $record,
            'can' => $policy->abilities($principal, $user),
            'now' => $this->cron->systemTime(),
        ]);
    }

    public function update(UpdateCronJobRequest $request, string $user, string $job, Principal $principal, CronPolicy $policy): RedirectResponse
    {
        abort_unless($policy->update($principal, $user), 403);
        $record = $this->cron->find($user, $job);

        $result = $this->cron->apply([$this->cron->planUpdate($record, $request->schedule(), $request->command())]);
        $back = redirect()->route('cron.edit', ['user' => $user, 'job' => $job]);
        if (! $result->ok()) {
            return $back->withInput()->with('error', $result->error);
        }

        return $back->with('ok', __('Changes have been saved.'));
    }
}
