<?php

namespace App\Http\Requests\Api\V2;

use App\Auth\Principal;
use App\Policies\BackupPolicy;
use App\Services\Backups\BackupJob;
use Illuminate\Validation\Validator;

/**
 * POST /system/backup-jobs (E48 spec §5.1, §9). v-add-backup-job checks
 * again, including that the destinations exist and the schedule ever runs.
 */
final class StoreBackupJobRequest extends ApiRequest
{
    public const NAME = '/^[^\'"\\\\`$\x00-\x1f]{1,64}$/';

    public const ACCOUNT = '/^[a-z0-9][a-z0-9_.-]{0,31}$/i';

    public const SCHEDULE = '/^(hourly:[0-5]?\d|daily:([01]?\d|2[0-3]):[0-5]?\d|weekly:[1-7]:([01]?\d|2[0-3]):[0-5]?\d'
        .'|monthly:(0?[1-9]|1\d|2[0-8]):([01]?\d|2[0-3]):[0-5]?\d|cron:[0-9*\/,\- ]{9,128})$/';

    /** Administrators only: everyone else gets 403 before validation. */
    public function authorize(): bool
    {
        return app(BackupPolicy::class)->manageJobs(app(Principal::class));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'regex:'.self::NAME],
            /** `archive` for tar archives, `snapshot` for restic snapshots. */
            'engine' => ['required', 'string', 'in:archive,snapshot'],
            /** `hourly:MM`, `daily:HH:MM`, `weekly:D:HH:MM` (1 is Monday), `monthly:DD:HH:MM` (1–28) or `cron:M H DOM MON DOW`, in the server's time zone. */
            'schedule' => ['required', 'string', 'regex:'.self::SCHEDULE],
            ...self::scopeRules(),
            /** Paused when false. Default true. */
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, list<string>> the scope and target rules, shared with UpdateBackupJobRequest */
    public static function scopeRules(bool $partial = false): array
    {
        $keep = ['nullable', 'integer', 'min:-1', 'max:9999'];
        $required = $partial ? 'sometimes' : 'required';

        return [
            /** `all` (default), `packages` or `users`. */
            'scope' => ['sometimes', 'string', 'in:all,packages,users'],
            /** The packages of a `packages` scope. */
            'packages' => ['required_if:scope,packages', 'array'],
            'packages.*' => ['string', 'regex:/^[A-Za-z0-9_.-]{1,64}$/'],
            /** The users of a `users` scope. */
            'users' => ['required_if:scope,users', 'array'],
            'users.*' => ['string', 'regex:'.self::ACCOUNT],
            /** Users left out of an `all` or `packages` scope. */
            'exclude_users' => ['sometimes', 'array'],
            'exclude_users.*' => ['string', 'regex:'.self::ACCOUNT],
            /** One to eight destinations with their retention; null or -1 keeps the package's number (archives) or sets no rule (snapshots). */
            'targets' => [$required, 'array', 'min:1', 'max:8'],
            'targets.*.destination' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{0,31}$/', 'distinct'],
            'targets.*.keep_last' => $keep,
            'targets.*.daily' => $keep,
            'targets.*.weekly' => $keep,
            'targets.*.monthly' => $keep,
            'targets.*.yearly' => $keep,
        ];
    }

    /** A snapshot job keeps something: at least one retention value of each target is set. */
    public function after(): array
    {
        return [fn (Validator $validator) => self::checkRetention($validator, (string) $this->input('engine'), (array) $this->input('targets', []))];
    }

    /** @param  array<mixed>  $targets */
    public static function checkRetention(Validator $validator, string $engine, array $targets): void
    {
        if ($engine !== 'snapshot') {
            return;
        }
        foreach ($targets as $i => $target) {
            $set = array_filter(['keep_last', 'daily', 'weekly', 'monthly', 'yearly'], fn ($k) => (int) (((array) $target)[$k] ?? -1) > 0);
            if ($set === []) {
                $validator->errors()->add("targets.$i.keep_last", __('Keep at least one snapshot: set the number of snapshots or of daily, weekly, monthly or yearly ones.'));
            }
        }
    }

    /**
     * Arguments of v-add-backup-job.
     *
     * @return array{name: string, engine: string, scope: string, schedule: string, targets: string, exclude: string}
     */
    public function values(): array
    {
        return self::valuesFrom($this->all());
    }

    /**
     * Arguments of v-add-backup-job and v-change-backup-job from a body of
     * this shape.
     *
     * @param  array<mixed>  $input
     * @return array{name: string, engine: string, scope: string, schedule: string, targets: string, exclude: string}
     */
    public static function valuesFrom(array $input): array
    {
        $names = fn (string $key): string => implode(',', array_values(array_unique(array_map('strval', (array) ($input[$key] ?? [])))));
        $scope = match ($input['scope'] ?? 'all') {
            'packages' => 'packages:'.$names('packages'),
            'users' => 'users:'.$names('users'),
            default => 'all',
        };
        $snapshot = ($input['engine'] ?? '') === 'snapshot';
        $number = fn (array $target, string $key): int => isset($target[$key]) && is_numeric($target[$key]) ? (int) $target[$key] : -1;
        $targets = [];
        foreach ((array) ($input['targets'] ?? []) as $target) {
            $target = (array) $target;
            $targets[] = [
                'destination' => (string) ($target['destination'] ?? ''),
                'keepLast' => $number($target, 'keep_last'),
                'daily' => $snapshot ? $number($target, 'daily') : -1,
                'weekly' => $snapshot ? $number($target, 'weekly') : -1,
                'monthly' => $snapshot ? $number($target, 'monthly') : -1,
                'yearly' => $snapshot ? $number($target, 'yearly') : -1,
            ];
        }
        $schedule = (string) ($input['schedule'] ?? '');

        return [
            'name' => trim((string) ($input['name'] ?? '')),
            'engine' => (string) ($input['engine'] ?? ''),
            'scope' => $scope,
            'schedule' => str_starts_with($schedule, 'cron:') ? 'cron:'.preg_replace('/\s+/', ' ', trim(substr($schedule, 5))) : $schedule,
            'targets' => BackupJob::targetsArg($targets),
            'exclude' => $scope === 'all' || str_starts_with($scope, 'packages:') ? $names('exclude_users') : '',
        ];
    }
}
