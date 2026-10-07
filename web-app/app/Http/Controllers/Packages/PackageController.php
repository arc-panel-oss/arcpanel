<?php

namespace App\Http\Controllers\Packages;

use App\Auth\Principal;
use App\Auth\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Packages\PackageRequest;
use App\Policies\PackagePolicy;
use App\Services\Packages\PackageActions;
use App\Services\Packages\PackageRecord;
use App\Services\Packages\PackageService;
use App\Services\Users\UserService;
use App\Services\WebDomains\ApplyResult;
use App\Services\WebDomains\Step;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Packages (web/list/package, web/add/package, web/edit/package) with the
 * reseller settings of E11 spec §8. The checks and plans are those of
 * PackageActions, shared with API v2.
 */
final class PackageController extends Controller
{
    public function __construct(
        private readonly PackageService $packages,
        private readonly PackageActions $actions,
        private readonly UserService $users,
    ) {}

    public function index(Principal $principal, PackagePolicy $policy): View
    {
        abort_unless($policy->viewAny($principal), 403);
        $isReseller = $principal->actingRole() === Role::Reseller;
        $records = $this->actions->visible($principal);

        $abilities = [];
        foreach ($records as $name => $record) {
            $abilities[$name] = $policy->abilities($principal, $record);
        }

        return view('packages.index', [
            'records' => $records,
            'abilities' => $abilities,
            'isReseller' => $isReseller,
            'reseller' => $isReseller ? $principal->effectiveUser() : '',
            'canCreate' => $policy->create($principal),
            'showResellerColumns' => $policy->manageResellerSettings($principal),
        ]);
    }

    public function create(Principal $principal, PackagePolicy $policy): View
    {
        abort_unless($policy->create($principal), 403);

        return $this->form($principal, $policy, null);
    }

    public function store(PackageRequest $request, Principal $principal, PackagePolicy $policy): RedirectResponse
    {
        abort_unless($policy->create($principal), 403);
        $steps = $this->actions->planCreate($principal, (string) $request->input('name'),
            $request->packageValues([], $this->packages->resourcesLimit()), $request->tenancyValues(),
            $request->portsValue(), $request->nodeValues(), $request->dockerValues());

        $result = $this->users->apply($steps);
        if (! $result->ok()) {
            return $this->failed($result);
        }

        return redirect()->route('packages.index')->with('ok', __('Package has been created.'));
    }

    public function edit(string $package, Principal $principal, PackagePolicy $policy): View
    {
        $record = $this->actions->visibleOr404($principal, $package);
        abort_unless($policy->update($principal, $record), 403);

        return $this->form($principal, $policy, $record);
    }

    public function update(PackageRequest $request, string $package, Principal $principal, PackagePolicy $policy): RedirectResponse
    {
        $record = $this->actions->visibleOr404($principal, $package);
        abort_unless($policy->update($principal, $record), 403);
        $newName = PackageActions::resellerOf($principal) !== '' ? $record->name : (string) $request->input('name');
        $steps = $this->actions->planUpdate($principal, $record, $newName,
            $request->packageValues($record->data, $this->packages->resourcesLimit()), $request->tenancyValues(),
            $request->portsValue(), $request->nodeValues(), $request->dockerValues());

        $result = $this->users->apply($steps);
        if (! $result->ok()) {
            return $this->failed($result);
        }

        return redirect()->route('packages.edit', ['package' => $newName])->with('ok', __('Changes have been saved.'));
    }

    public function copy(Request $request, string $package, Principal $principal, PackagePolicy $policy): RedirectResponse
    {
        $record = $this->actions->visibleOr404($principal, $package);
        abort_unless($policy->abilities($principal, $record)['copy'], 403);
        $name = (string) $request->validate(['name' => ['required', 'string', 'max:60', 'regex:/^[[:alnum:]][-.\w]*$/']])['name'];

        $result = $this->users->apply($this->actions->planCopy($record, $name));

        return $result->ok()
            ? redirect()->route('packages.edit', ['package' => $name])->with('ok', __('Package has been copied.'))
            : back()->with('error', $result->error);
    }

    public function destroy(string $package, Principal $principal, PackagePolicy $policy): RedirectResponse
    {
        $record = $this->actions->visibleOr404($principal, $package);
        abort_unless($policy->delete($principal, $record), 403);
        if (($reason = $this->actions->deleteRefusal($record)) !== null) {
            return back()->with('error', $reason);
        }

        $result = $this->users->apply($this->actions->planDelete($principal, $record));

        return $result->ok()
            ? redirect()->route('packages.index')->with('ok', __('Package has been deleted.'))
            : back()->with('error', $result->error);
    }

    private function form(Principal $principal, PackagePolicy $policy, ?PackageRecord $record): View
    {
        $reseller = PackageActions::resellerOf($principal);

        return view('packages.form', [
            'record' => $record,
            'values' => array_merge(PackageService::defaults(($this->packages->all()['default'] ?? null)?->get('NS') ?? ''), $record?->data ?? []),
            'name' => $record === null ? '' : UserService::packageLabel($record->name, $reseller),
            'canRename' => $reseller === '',
            'templates' => $this->packages->templates(),
            'resources' => $this->packages->resourcesLimit(),
            'resellerSettings' => $policy->manageResellerSettings($principal),
            'ownLimits' => $reseller !== '' ? $this->users->find($reseller)->data : [],
            'ownPorts' => $reseller !== '' ? $this->actions->ownPorts($reseller) : null,
            'ownNode' => $reseller !== '' ? $this->actions->ownNode($reseller) : null,
            'ownDocker' => $reseller !== '' ? $this->actions->ownDocker($reseller) : null,
        ]);
    }

    private function failed(ApplyResult $result): RedirectResponse
    {
        /** @var Step $failed */
        $failed = $result->failed;

        return back()->withInput()
            ->with('error', sprintf(__('%s failed'), $failed->label))
            ->with('error_detail', $result->error);
    }
}
