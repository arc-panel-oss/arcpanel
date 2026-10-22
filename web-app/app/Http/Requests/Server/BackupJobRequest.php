<?php

namespace App\Http\Requests\Server;

use App\Auth\Principal;
use App\Policies\BackupPolicy;
use App\Services\Backups\BackupJob;
use App\Services\Backups\BackupSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Add or change a backup job (E48 spec §5.1, §8.2 Schedules): the schedule
 * from its preset fields, the scope, the excluded users and one row per
 * target with its retention. v-add-backup-job checks again, including that
 * the schedule ever runs. BackupPolicy authorizes.
 */
final class BackupJobRequest extends FormRequest
{
    private const NAME = '/^[^\'"\\\\`$\x00-\x1f]{1,64}$/';

    private const ACCOUNT = '/^[a-z0-9][a-z0-9_.-]{0,31}$/i';

    /** Only administrators get as far as validation; everyone else gets 403. */
    public function authorize(): bool
    {
        return app(BackupPolicy::class)->manageJobs(app(Principal::class));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $keep = ['nullable', 'integer', 'min:-1', 'max:9999'];

        return [
            'name' => ['required', 'string', 'regex:'.self::NAME],
            'engine' => ['required', 'in:archive,snapshot'],
            'preset' => ['required', 'in:'.implode(',', BackupSchedule::PRESETS)],
            'hour' => ['nullable', 'integer', 'between:0,23'],
            'minute' => ['nullable', 'integer', 'between:0,59'],
            'weekday' => ['nullable', 'integer', 'between:1,7'],
            'day' => ['nullable', 'integer', 'between:1,28'],
            'cron' => ['required_if:preset,cron', 'nullable', 'string', 'max:128', 'regex:/^[0-9*\/,\- ]+$/'],
            'scope' => ['required', 'in:all,packages,users'],
            'packages' => ['required_if:scope,packages', 'array'],
            'packages.*' => ['string', 'regex:/^[A-Za-z0-9_.-]{1,64}$/'],
            'users' => ['required_if:scope,users', 'array'],
            'users.*' => ['string', 'regex:'.self::ACCOUNT],
            'exclude' => ['nullable', 'array'],
            'exclude.*' => ['string', 'regex:'.self::ACCOUNT],
            'targets' => ['required', 'array', 'min:1', 'max:8'],
            'targets.*.destination' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{0,31}$/', 'distinct'],
            'targets.*.keep_last' => $keep,
            'targets.*.daily' => $keep,
            'targets.*.weekly' => $keep,
            'targets.*.monthly' => $keep,
            'targets.*.yearly' => $keep,
        ];
    }

    /** A snapshot job keeps something: at least one retention field is set. */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->input('engine') !== 'snapshot') {
                return;
            }
            foreach ((array) $this->input('targets', []) as $i => $target) {
                $set = array_filter(['keep_last', 'daily', 'weekly', 'monthly', 'yearly'], fn ($k) => (int) ($target[$k] ?? -1) > 0);
                if ($set === []) {
                    $validator->errors()->add("targets.$i.keep_last", __('Keep at least one snapshot: set the number of snapshots or of daily, weekly, monthly or yearly ones.'));
                }
            }
        }];
    }

    public function schedule(): string
    {
        return BackupSchedule::fromForm((string) $this->input('preset'), (int) $this->input('hour', 0), (int) $this->input('minute', 0),
            (int) $this->input('weekday', 1), (int) $this->input('day', 1), (string) $this->input('cron', ''));
    }

    /**
     * Arguments of v-add-backup-job and v-change-backup-job.
     *
     * @return array{name: string, engine: string, scope: string, schedule: string, targets: string, exclude: string}
     */
    public function values(): array
    {
        $scope = match ($this->input('scope')) {
            'packages' => 'packages:'.implode(',', array_unique((array) $this->input('packages', []))),
            'users' => 'users:'.implode(',', array_unique((array) $this->input('users', []))),
            default => 'all',
        };
        $snapshot = $this->input('engine') === 'snapshot';
        $number = fn (array $target, string $key): int => isset($target[$key]) && $target[$key] !== '' ? (int) $target[$key] : -1;
        $targets = [];
        foreach ((array) $this->input('targets', []) as $target) {
            $targets[] = [
                'destination' => (string) $target['destination'],
                'keepLast' => $number($target, 'keep_last'),
                'daily' => $snapshot ? $number($target, 'daily') : -1,
                'weekly' => $snapshot ? $number($target, 'weekly') : -1,
                'monthly' => $snapshot ? $number($target, 'monthly') : -1,
                'yearly' => $snapshot ? $number($target, 'yearly') : -1,
            ];
        }

        return [
            'name' => trim((string) $this->input('name')),
            'engine' => (string) $this->input('engine'),
            'scope' => $scope,
            'schedule' => $this->schedule(),
            'targets' => BackupJob::targetsArg($targets),
            'exclude' => $scope === 'all' || str_starts_with($scope, 'packages:') ? implode(',', array_unique((array) $this->input('exclude', []))) : '',
        ];
    }
}
