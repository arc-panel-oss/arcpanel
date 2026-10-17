<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\ApiException;
use App\Api\CronSchedule;
use App\Api\CrossUserList;
use App\Api\ErrorCode;
use App\Api\Paginates;
use App\Api\PlanGate;
use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\SetCronReportsRequest;
use App\Http\Requests\Api\V2\StoreCronJobRequest;
use App\Http\Requests\Api\V2\UpdateCronJobRequest;
use App\Http\Resources\V2\CronJobResource;
use App\Policies\CronPolicy;
use App\Services\Cron\CronJobRecord;
use App\Services\Cron\CronService;
use App\Services\WebDomains\Step;
use App\Tenancy\TenancyDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Cron jobs and reports of a user (E34 batch 2 spec §6.5).
 *
 * @tags Cron
 */
final class CronController extends Controller
{
    public function __construct(
        private readonly CronService $cron,
        private readonly PlanGate $gate,
        private readonly Paginates $pages,
        private readonly TenancyDirectory $directory,
    ) {}

    /**
     * List the cron jobs of every user
     *
     * Every user's cron jobs for an administrator's key, those of the reseller
     * and its customers for a reseller's key; a user's key answers 403
     * (`listCronJobs` covers it). Sorted by user, then id. With
     * `updated_since`, only the users whose cron jobs changed at or after that
     * time are returned, each with all its cron jobs: `meta.as_of` is the next
     * `updated_since`, `meta.users` every user in scope and
     * `meta.changed_users` the users returned (E43 B2). `meta.timezone` is the
     * server's time zone.
     *
     * @operationId listAllCronJobs
     */
    public function indexAll(Request $request, CrossUserList $lists): AnonymousResourceCollection
    {
        $page = $lists->page($request, 'v-list-sys-cron-jobs', CronJobRecord::fromArray(...), fn (CronJobRecord $job) => ctype_digit($job->job) ? (int) $job->job : $job->job);
        $page['meta']['timezone'] = $this->cron->timezone();

        return CronJobResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * List the cron jobs of a user
     *
     * `meta.timezone` is the server's time zone.
     *
     * @operationId listCronJobs
     */
    public function index(Request $request, string $user, Principal $principal, CronPolicy $policy): AnonymousResourceCollection
    {
        $this->assertOwner($user, $principal, $policy);
        $page = $this->pages->slice($request, array_values($this->cron->forUser($user)));
        $page['meta']['timezone'] = $this->cron->timezone();

        return CronJobResource::collection($page['items'])->additional(['meta' => $page['meta']]);
    }

    /**
     * Create a cron job
     *
     * The id is the next one after the highest the user has. A job with the
     * same schedule and command answers 409 `E_EXISTS` with `existing`.
     *
     * @operationId createCronJob
     *
     * @status 201
     */
    public function store(StoreCronJobRequest $request, string $user, Principal $principal, CronPolicy $policy): CronJobResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->create($principal, $user), 403);
        $schedule = CronSchedule::parse((string) $request->input('schedule'));
        $command = (string) $request->input('command');
        $this->assertNew($user, $schedule, $command);
        $id = $this->nextId($user);

        $steps = [$this->cron->planCreate($user, $schedule, $command, $id)];
        if ($preview = $this->gate->execute($steps, $this->cron->apply(...))) {
            return $preview;
        }

        return (new CronJobResource($this->cron->find($user, $id)))->response()->setStatusCode(201)
            ->header('Location', route('api.v2.cron-jobs.show', ['user' => $user, 'id' => $id]));
    }

    /**
     * Get a cron job
     *
     * @operationId getCronJob
     */
    public function show(string $user, string $id, Principal $principal, CronPolicy $policy): CronJobResource
    {
        $this->assertOwner($user, $principal, $policy);

        return new CronJobResource($this->cron->find($user, $id));
    }

    /**
     * Update a cron job
     *
     * The id does not change. Fields left out keep their value.
     *
     * @operationId updateCronJob
     */
    public function update(UpdateCronJobRequest $request, string $user, string $id, Principal $principal, CronPolicy $policy): CronJobResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->update($principal, $user), 403);
        $record = $this->cron->find($user, $id);
        $schedule = $request->exists('schedule')
            ? CronSchedule::parse((string) $request->input('schedule'))
            : ['min' => $record->min, 'hour' => $record->hour, 'day' => $record->day, 'month' => $record->month, 'wday' => $record->wday];
        $command = $request->exists('command') ? (string) $request->input('command') : $record->command;

        return $this->gate->execute([$this->cron->planUpdate($record, $schedule, $command)], $this->cron->apply(...))
            ?? new CronJobResource($this->cron->find($user, $id));
    }

    /**
     * Delete a cron job
     *
     * @operationId deleteCronJob
     *
     * @status 204
     */
    public function destroy(string $user, string $id, Principal $principal, CronPolicy $policy): Response|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->delete($principal, $user), 403);
        $this->cron->find($user, $id);

        return $this->gate->execute([
            new Step(sprintf(__('Delete cron job %s'), $id), 'v-delete-cron-job', [$user, $id]),
        ], $this->cron->apply(...)) ?? response()->noContent();
    }

    /**
     * Suspend a cron job
     *
     * @operationId suspendCronJob
     */
    public function suspend(string $user, string $id, Principal $principal, CronPolicy $policy): CronJobResource|JsonResponse
    {
        return $this->setSuspended($user, $id, true, $principal, $policy);
    }

    /**
     * Unsuspend a cron job
     *
     * @operationId unsuspendCronJob
     */
    public function unsuspend(string $user, string $id, Principal $principal, CronPolicy $policy): CronJobResource|JsonResponse
    {
        return $this->setSuspended($user, $id, false, $principal, $policy);
    }

    /**
     * Whether cron mails the output of the user's jobs
     *
     * @operationId getCronReports
     */
    public function reports(string $user, Principal $principal, CronPolicy $policy): JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);

        return response()->json(['data' => ['enabled' => $this->cron->reportsEnabled($user)]]);
    }

    /**
     * Turn cron reports on or off
     *
     * Nothing runs when the value is unchanged.
     *
     * @operationId setCronReports
     */
    public function setReports(SetCronReportsRequest $request, string $user, Principal $principal, CronPolicy $policy): JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->update($principal, $user), 403);
        $enabled = $request->boolean('enabled');
        $steps = $this->cron->planReports($user, $enabled, $this->cron->reportsEnabled($user));

        return $this->gate->execute($steps, $this->cron->apply(...))
            ?? response()->json(['data' => ['enabled' => $enabled]]);
    }

    private function setSuspended(string $user, string $id, bool $suspend, Principal $principal, CronPolicy $policy): CronJobResource|JsonResponse
    {
        $this->assertOwner($user, $principal, $policy);
        abort_unless($policy->suspend($principal, $user), 403);
        $record = $this->cron->find($user, $id);
        $steps = $record->suspended === $suspend ? [] : [$suspend
            ? new Step(sprintf(__('Suspend cron job %s'), $id), 'v-suspend-cron-job', [$user, $id])
            : new Step(sprintf(__('Unsuspend cron job %s'), $id), 'v-unsuspend-cron-job', [$user, $id])];

        return $this->gate->execute($steps, $this->cron->apply(...))
            ?? new CronJobResource($this->cron->find($user, $id));
    }

    private function assertOwner(string $user, Principal $principal, CronPolicy $policy): void
    {
        abort_unless($policy->view($principal, $user) && array_key_exists($user, $this->directory->all()), 404);
    }

    /** @param  array{min: string, hour: string, day: string, month: string, wday: string}  $schedule */
    private function assertNew(string $user, array $schedule, string $command): void
    {
        $wanted = CronSchedule::format($schedule);
        foreach ($this->cron->forUser($user) as $job) {
            if ($job->command === $command && CronSchedule::format([
                'min' => $job->min, 'hour' => $job->hour, 'day' => $job->day, 'month' => $job->month, 'wday' => $job->wday,
            ]) === $wanted) {
                throw new ApiException(
                    ErrorCode::Exists,
                    __('Cron job exists.'),
                    409,
                    existing: route('api.v2.cron-jobs.show', ['user' => $user, 'id' => $job->job], false),
                );
            }
        }
    }

    private function nextId(string $user): string
    {
        $highest = 0;
        foreach ($this->cron->forUser($user) as $job) {
            if (ctype_digit($job->job)) {
                $highest = max($highest, (int) $job->job);
            }
        }

        return (string) ($highest + 1);
    }
}
