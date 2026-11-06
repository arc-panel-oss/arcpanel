<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\ApiException;
use App\Api\ErrorCode;
use App\Api\Paginates;
use App\Api\PlanGate;
use App\Api\TaskLink;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\RunSystemDoctorRequest;
use App\Http\Resources\V2\DoctorDomainResource;
use App\Http\Resources\V2\DoctorResultResource;
use App\Http\Resources\V2\TaskResource;
use App\Policies\DoctorPolicy;
use App\Services\Doctor\DoctorStore;
use App\Services\Jobs\JobStore;
use App\Services\Support\PlanRunner;
use App\Services\Tasks\JobMapper;
use App\Services\WebDomains\Step;
use App\Tenancy\TenancyDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Server and Site Doctor (E17 spec §6): the stored results of the read-only
 * checks of the server (`/system/doctor`, administrators) and of the domains
 * of an account, runs and fixes. A run or a fix answers 202 with its task
 * (a `doctor.run` or `doctor.fix` job); a fix of a check that passes or has
 * no fix answers 409 `E_EXISTS`.
 *
 * @tags Doctor
 */
final class DoctorController extends Controller
{
    public function __construct(
        private readonly DoctorStore $doctor,
        private readonly PlanGate $gate,
        private readonly PlanRunner $runner,
        private readonly TenancyDirectory $directory,
        private readonly Paginates $pages,
        private readonly JobStore $jobs,
    ) {}

    /**
     * Show the Doctor result of the server
     *
     * The stored result of the 15 server checks: score and checks.
     * `checked_at` and `score` are null before the first run.
     *
     * @operationId getSystemDoctor
     */
    public function system(Principal $principal, DoctorPolicy $policy): DoctorResultResource
    {
        $this->assertScope('system', $principal, $policy);

        return new DoctorResultResource($this->doctor->server());
    }

    /**
     * List the Doctor results of the domains of an account
     *
     * One row per web or mail domain: score, failed and warning counts and
     * the failing checks; worst score first.
     *
     * @operationId listDoctorDomains
     */
    public function index(Request $request, string $user, Principal $principal, DoctorPolicy $policy): AnonymousResourceCollection
    {
        $this->assertScope($user, $principal, $policy);
        $page = $this->pages->slice($request, $this->doctor->summary([$user]));

        return DoctorDomainResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * Show the Doctor result of a domain
     *
     * The stored result of the web and mail checks of the domain.
     *
     * @operationId getDoctorDomain
     */
    public function show(string $user, string $domain, Principal $principal, DoctorPolicy $policy): DoctorResultResource
    {
        $this->assertScope($user, $principal, $policy);
        $this->assertDomain($user, $domain);

        return new DoctorResultResource($this->doctor->domain($user, $domain));
    }

    /**
     * Run the Doctor on the server
     *
     * Starts a `doctor.run` job of the server checks, or of every check of
     * the server and every domain with `all`; follow the task. A run of the
     * same scope that is still going answers 409 `E_INUSE`.
     *
     * @operationId runSystemDoctor
     *
     * @monitor
     *
     * @status 202
     */
    public function runSystem(RunSystemDoctorRequest $request, Principal $principal, DoctorPolicy $policy): TaskResource|JsonResponse
    {
        $this->assertScope('system', $principal, $policy, true);
        $scope = (bool) ($request->validated()['all'] ?? false) ? 'all' : 'system';

        return $this->start([DoctorStore::runStep($scope, '', self::actor($principal))]);
    }

    /**
     * Run the Doctor on the domains of an account
     *
     * Starts a `doctor.run` job of the web and mail checks of every domain
     * of the account; follow the task.
     *
     * @operationId runUserDoctor
     *
     * @monitor
     *
     * @status 202
     */
    public function runUser(string $user, Principal $principal, DoctorPolicy $policy): TaskResource|JsonResponse
    {
        $this->assertScope($user, $principal, $policy, true);

        return $this->start([DoctorStore::runStep($user, '', self::actor($principal))]);
    }

    /**
     * Run the Doctor on a domain
     *
     * Starts a `doctor.run` job of the web and mail checks of the domain;
     * follow the task.
     *
     * @operationId runDoctorDomain
     *
     * @monitor
     *
     * @status 202
     */
    public function runDomain(string $user, string $domain, Principal $principal, DoctorPolicy $policy): TaskResource|JsonResponse
    {
        $this->assertScope($user, $principal, $policy, true);
        $this->assertDomain($user, $domain);

        return $this->start([DoctorStore::runStep($user, $domain, self::actor($principal))]);
    }

    /**
     * Fix a failing server check
     *
     * Runs the check again and, when it still fails, starts a `doctor.fix`
     * job that runs the check's allow-listed fix command, then the check
     * again. 409 `E_EXISTS` when the check passes or has no fix.
     *
     * @operationId fixSystemDoctorCheck
     *
     * @monitor
     *
     * @status 202
     */
    public function fixSystem(string $check, Principal $principal, DoctorPolicy $policy): TaskResource|JsonResponse
    {
        $this->assertScope('system', $principal, $policy, true);
        abort_unless(DoctorStore::validCheck($check, true), 404);

        return $this->start([DoctorStore::fixStep('system', $check, '', self::actor($principal), $this->fixCommand($this->doctor->server(), $check))]);
    }

    /**
     * Fix a failing check of a domain
     *
     * Runs the check again and, when it still fails, starts a `doctor.fix`
     * job that runs the check's allow-listed fix command (for example a
     * Let's Encrypt certificate or the HTTPS redirect), then the check
     * again. 409 `E_EXISTS` when the check passes or has no fix.
     *
     * @operationId fixDoctorCheck
     *
     * @monitor
     *
     * @status 202
     */
    public function fixDomain(string $user, string $domain, string $check, Principal $principal, DoctorPolicy $policy): TaskResource|JsonResponse
    {
        $this->assertScope($user, $principal, $policy, true);
        $this->assertDomain($user, $domain);
        abort_unless(DoctorStore::validCheck($check, false), 404);

        return $this->start([DoctorStore::fixStep($user, $check, $domain, self::actor($principal), $this->fixCommand($this->doctor->domain($user, $domain), $check))]);
    }

    /**
     * Run the step that starts the job, answer 202 with the job's task.
     *
     * @param  list<Step>  $steps
     */
    private function start(array $steps): TaskResource|JsonResponse
    {
        $this->gate->authorize($steps);
        if ($this->gate->dryRun()) {
            return $this->gate->preview($steps);
        }
        $result = $this->runner->apply($steps);
        $this->gate->assertApplied($result);
        $id = trim((string) $result->stdoutOf($steps[0]->command));
        $job = JobStore::validId($id) ? $this->jobs->find($id) : null;
        if ($job === null) {
            throw new ApiException(ErrorCode::Server, __('The task could not be started.'), 500);
        }
        $view = JobMapper::fromRecord($job);

        return (new TaskResource($view))->response()->setStatusCode(202)->header('Link', TaskLink::monitor($view->id));
    }

    /** The fix command of a check in a stored result, for the plan's label. */
    private function fixCommand(array $result, string $check): string
    {
        return (string) (DoctorStore::check($result, $check)['fix']['command'] ?? $check);
    }

    private function assertScope(string $user, Principal $principal, DoctorPolicy $policy, bool $run = false): void
    {
        if ($user !== 'system') {
            abort_unless(array_key_exists($user, $this->directory->all()), 404);
        }
        abort_unless($policy->view($principal, $user), $user === 'system' ? 403 : 404);
        if ($run) {
            abort_unless($policy->run($principal, $user), 403);
        }
    }

    private function assertDomain(string $user, string $domain): void
    {
        abort_unless(in_array($domain, array_column($this->doctor->summary([$user]), 'domain'), true), 404);
    }

    private static function actor(Principal $principal): string
    {
        return JobStore::actor($principal->accessKey !== null ? 'api:'.$principal->accessKey : $principal->loginUser);
    }
}
