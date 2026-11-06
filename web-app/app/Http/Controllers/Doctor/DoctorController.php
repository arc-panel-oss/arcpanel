<?php

namespace App\Http\Controllers\Doctor;

use App\Arc\CommandResult;
use App\Auth\OwnerScope;
use App\Auth\Principal;
use App\Auth\Role;
use App\Http\Controllers\Controller;
use App\Policies\DoctorPolicy;
use App\Services\Doctor\DoctorStore;
use App\Services\Jobs\JobStore;
use App\Services\Jobs\JobStoreException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The Doctor pages (E17 spec §5): Server › Doctor (`/server/doctor`, the
 * server checks and every domain), Doctor (`/doctor/{user}`, the domains
 * of an account and, for its own page, of its customers) and the checks of
 * one domain (`/doctor/{user}/{domain}`). Runs and fixes start jobs; the
 * page follows the job given in `?job=` and reloads when it ends.
 */
final class DoctorController extends Controller
{
    public function __construct(private readonly DoctorStore $doctor) {}

    /** /server/doctor */
    public function server(Request $request, Principal $principal, DoctorPolicy $policy, JobStore $jobs): View
    {
        abort_unless($policy->view($principal, 'system'), 403);

        return view('doctor.server', [
            'result' => $this->doctor->server(),
            'domains' => $this->doctor->summary(null),
            'canRun' => $policy->run($principal, 'system'),
            'task' => $this->task($request, $jobs, 'doctor:system', 'doctor:all'),
        ]);
    }

    public function serverRun(Request $request, Principal $principal, DoctorPolicy $policy): RedirectResponse
    {
        abort_unless($policy->view($principal, 'system'), 403);
        abort_unless($policy->run($principal, 'system'), 403);
        $scope = $request->boolean('all') ? 'all' : 'system';

        return $this->started($this->doctor->run($scope, '', $principal->loginUser), route('server.doctor'),
            $scope === 'all' ? __('The Doctor checks the server and every domain.') : __('The Doctor checks the server.'));
    }

    public function serverFix(string $check, Principal $principal, DoctorPolicy $policy): RedirectResponse
    {
        abort_unless($policy->view($principal, 'system'), 403);
        abort_unless($policy->run($principal, 'system'), 403);
        abort_unless(DoctorStore::validCheck($check, true), 404);

        return $this->started($this->doctor->fix('system', $check, '', $principal->loginUser), route('server.doctor'),
            sprintf(__('The fix of %s runs.'), $check));
    }

    /** /doctor: the page of the effective account. */
    public function entry(Principal $principal): RedirectResponse
    {
        return redirect()->route('doctor.show', ['user' => $principal->effectiveUser()]);
    }

    /** /doctor/{user}: the domains of the account, and of its customers on its own page. */
    public function show(Request $request, string $user, Principal $principal, DoctorPolicy $policy, OwnerScope $scope, JobStore $jobs): View
    {
        abort_unless($policy->view($principal, $user), 404);
        $users = $this->users($user, $principal, $scope);

        return view('doctor.index', [
            'user' => $user,
            'domains' => $this->doctor->summary($users),
            'many' => $users === null || count($users) > 1,
            'canRun' => $policy->run($principal, $user),
            'task' => $this->task($request, $jobs, ...array_map(fn ($u) => 'doctor:'.$u, $users ?? ['all'])),
        ]);
    }

    public function run(string $user, Principal $principal, DoctorPolicy $policy, OwnerScope $scope): RedirectResponse
    {
        abort_unless($policy->view($principal, $user), 404);
        abort_unless($policy->run($principal, $user), 403);
        $users = $this->users($user, $principal, $scope);
        $back = route('doctor.show', ['user' => $user]);
        if ($users === null) {
            return $this->started($this->doctor->run('all', '', $principal->loginUser), $back, __('The Doctor checks every domain.'));
        }
        $first = null;
        foreach ($users as $one) {
            if (! $policy->run($principal, $one)) {
                continue;
            }
            $result = $this->doctor->run($one, '', $principal->loginUser);
            if (! $result->ok() && $users === [$one]) {
                return redirect()->to($back)->with('error', DoctorStore::refusal($result));
            }
            $first ??= DoctorStore::jobId($result);
        }

        return redirect()->to($first !== null ? $back.'?job='.$first : $back)->with('ok', __('The Doctor checks the domains.'));
    }

    /** /doctor/{user}/{domain}: the web and mail checks of a domain. */
    public function domain(Request $request, string $user, string $domain, Principal $principal, DoctorPolicy $policy, JobStore $jobs): View
    {
        abort_unless($policy->view($principal, $user), 404);
        $this->assertDomain($user, $domain);
        $result = $this->doctor->domain($user, $domain);

        return view('doctor.domain', [
            'user' => $user,
            'domain' => $domain,
            'result' => $result,
            'canRun' => $policy->run($principal, $user),
            'task' => $this->task($request, $jobs, 'doctor:'.$user.':'.$domain),
        ]);
    }

    public function domainRun(string $user, string $domain, Principal $principal, DoctorPolicy $policy): RedirectResponse
    {
        abort_unless($policy->view($principal, $user), 404);
        abort_unless($policy->run($principal, $user), 403);
        $this->assertDomain($user, $domain);

        return $this->started($this->doctor->run($user, $domain, $principal->loginUser), route('doctor.domain', ['user' => $user, 'domain' => $domain]),
            sprintf(__('The Doctor checks %s.'), $domain));
    }

    public function domainFix(string $user, string $domain, string $check, Principal $principal, DoctorPolicy $policy): RedirectResponse
    {
        abort_unless($policy->view($principal, $user), 404);
        abort_unless($policy->run($principal, $user), 403);
        $this->assertDomain($user, $domain);
        abort_unless(DoctorStore::validCheck($check, false), 404);

        return $this->started($this->doctor->fix($user, $check, $domain, $principal->loginUser), route('doctor.domain', ['user' => $user, 'domain' => $domain]),
            sprintf(__('The fix of %s runs.'), $check));
    }

    /**
     * The users whose domains a page lists: the tree of the account on its
     * own page (null for every user), else the account alone.
     *
     * @return list<string>|null
     */
    private function users(string $user, Principal $principal, OwnerScope $scope): ?array
    {
        if ($user !== $principal->effectiveUser() || $principal->actingRole() === Role::User) {
            return [$user];
        }

        return $scope->visibleUsers();
    }

    /** 404 for a domain the user has no result or web/mail domain for (the command checks it too). */
    private function assertDomain(string $user, string $domain): void
    {
        abort_unless(preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{0,252}$/', $domain) === 1, 404);
        $known = array_column($this->doctor->summary([$user]), 'domain');
        abort_unless(in_array($domain, $known, true), 404);
    }

    private function started(CommandResult $result, string $back, string $message): RedirectResponse
    {
        if (! $result->ok()) {
            return redirect()->to($back)->with('error', DoctorStore::refusal($result));
        }
        $id = DoctorStore::jobId($result);

        return redirect()->to($id !== null ? $back.'?job='.$id : $back)->with('ok', $message);
    }

    /**
     * The job of `?job=` for the task panel when it is a Doctor job of one
     * of OBJECTS (or a fix of them), in the shape of apTask.
     *
     * @return array{id: string, kind: string, args: string, status: string, exit: string, start: string, end: string, log: string}|null
     */
    private function task(Request $request, JobStore $jobs, string ...$objects): ?array
    {
        $id = (string) $request->query('job', '');
        if (! JobStore::validId($id)) {
            return null;
        }
        try {
            $job = $jobs->findWithTail($id, 200);
        } catch (JobStoreException) {
            return null;
        }
        $object = (string) ($job['OBJECT'] ?? '');
        $ours = $job !== null && str_starts_with((string) ($job['TYPE'] ?? ''), 'doctor.')
            && array_filter($objects, fn ($o) => $object === $o || str_starts_with($object, $o.':')) !== [];
        if (! $ours) {
            return null;
        }

        return [
            'id' => $id,
            'kind' => (string) $job['TYPE'],
            'args' => (string) ($job['ARGS'] ?? ''),
            'status' => (string) ($job['STATUS'] ?? ''),
            'exit' => (string) ($job['EXIT'] ?? ''),
            'start' => (string) ($job['START'] ?? ''),
            'end' => (string) ($job['END'] ?? ''),
            'log' => (string) ($job['LOG'] ?? ''),
        ];
    }
}
