<?php

namespace App\Http\Controllers\Server;

use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Policies\ServerPolicy;
use App\Services\Server\SystemPackages;
use App\Services\Server\SystemTaskService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * System packages of the catalog (E5, spec 2026-10-06-006 §7): installs and
 * removals run as the system task.
 */
final class SystemPackageController extends Controller
{
    public function __construct(
        private readonly SystemPackages $packages,
        private readonly SystemTaskService $tasks,
    ) {}

    public function index(Principal $principal, ServerPolicy $policy): View
    {
        abort_unless($policy->viewPackages($principal), 403);

        return view('server.packages', [
            'groups' => $this->packages->grouped(),
            'task' => $this->tasks->task(),
            'canManage' => $policy->managePackages($principal),
        ]);
    }

    /** POST /server/packages/{slug}/{add|delete} */
    public function start(string $slug, string $action, Principal $principal, ServerPolicy $policy): RedirectResponse
    {
        abort_unless($policy->managePackages($principal), 403);
        $entry = $this->packages->find($slug);
        abort_if($entry === null, 404);

        $result = $this->packages->start($slug, $action);
        $back = redirect()->route('server.packages');
        if (! $result->ok()) {
            return $back->with('error', SystemTaskService::refusal($result));
        }

        return $back->with('ok', sprintf($action === 'add' ? __('%s is being installed.') : __('%s is being removed.'), $entry['name']));
    }
}
