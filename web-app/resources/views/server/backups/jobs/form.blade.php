{{--
    Add or change a backup job (E48 spec §5.1, §8.2): schedule presets with a
    cron field and the next runs, the accounts it covers, and one row per
    destination with its retention (apBackupJob).
--}}
@use('App\Services\Backups\BackupDestination')
@php
    $editing = $job !== null;
    $canManage ??= true;
    $runs ??= [];
    $title = $editing ? sprintf(__('Job %s'), $job->name) : __('Add job');
    $crumbs = [['label' => __('Server')], ['label' => __('Backups'), 'url' => route('server.backups')],
        ['label' => __('Schedules'), 'url' => route('server.backups.jobs')], ['label' => $editing ? $job->name : __('Add job')]];
    $schedule = $job?->schedule;
    $scope = $job === null ? 'all' : (str_starts_with($job->scope, 'packages:') ? 'packages' : (str_starts_with($job->scope, 'users:') ? 'users' : 'all'));
    $listed = $job !== null && $scope !== 'all' ? explode(',', substr($job->scope, strlen($scope) + 1)) : [];
    $targets = old('targets', $job !== null ? array_map(fn (array $t) => [
        'destination' => $t['destination'],
        'keep_last' => $t['keepLast'] < 0 ? '' : (string) $t['keepLast'],
        'daily' => $t['daily'] < 0 ? '' : (string) $t['daily'],
        'weekly' => $t['weekly'] < 0 ? '' : (string) $t['weekly'],
        'monthly' => $t['monthly'] < 0 ? '' : (string) $t['monthly'],
        'yearly' => $t['yearly'] < 0 ? '' : (string) $t['yearly'],
    ], $job->targets) : []);
    $state = [
        'previewUrl' => route('server.backups.jobs.preview'),
        'preset' => old('preset', $schedule->preset ?? 'daily'),
        'hour' => (int) old('hour', $schedule->hour ?? 5),
        'minute' => (int) old('minute', $schedule->minute ?? 10),
        'weekday' => (int) old('weekday', $schedule->weekday ?? 1),
        'day' => (int) old('day', $schedule->day ?? 1),
        'cron' => (string) old('cron', $schedule->cron ?? ''),
        'scope' => old('scope', $scope),
        'engine' => old('engine', $job->engine ?? 'archive'),
        'targets' => array_values($targets),
    ];
    $packagesPicked = (array) old('packages', $scope === 'packages' ? $listed : []);
    $usersPicked = (array) old('users', $scope === 'users' ? $listed : []);
    $excluded = (array) old('exclude', $job->excluded ?? []);
    $hours = array_combine(range(0, 23), array_map(fn ($h) => sprintf('%02d', $h), range(0, 23)));
    $minutes = array_combine(range(0, 59), array_map(fn ($m) => sprintf('%02d', $m), range(0, 59)));
    $weekdays = [1 => __('Monday'), 2 => __('Tuesday'), 3 => __('Wednesday'), 4 => __('Thursday'), 5 => __('Friday'), 6 => __('Saturday'), 7 => __('Sunday')];
    $statusLabel = ['success' => __('Succeeded'), 'partial' => __('Partial'), 'failed' => __('Failed'), 'running' => __('Running')];
@endphp
<x-ap-page :title="$title" :crumbs="$crumbs">
    @if ($editing && $canManage)
        <x-slot:actions>
            <x-ap-button icon="play" :form-action="route('server.backups.jobs.run', ['job' => $job->id])" :disabled="$job->running"
                :confirm="sprintf(__('Run job %s now for all its accounts?'), $job->name)">{{ __('Run now') }}</x-ap-button>
        </x-slot:actions>
    @endif

    @if ($destinations === [])
        <x-ap-alert tone="warn" :title="__('No destinations yet')">
            {{ __('A job sends its backups to destinations.') }} <a href="{{ route('server.backups.destinations.create') }}">{{ __('Add destination') }}</a>
        </x-ap-alert>
    @endif

    <x-ap-form :action="$editing ? route('server.backups.jobs.update', ['job' => $job->id]) : route('server.backups.jobs.store')" :method="$editing ? 'PUT' : 'POST'" focus-first>
        <fieldset class="ap-fieldset-plain ap-stack" x-data="apBackupJob(@js($state))" @disabled(! $canManage)>
            <x-ap-card :title="__('Job')" icon="archive">
                <div class="ap-form-grid">
                    <x-ap-input name="name" :label="__('Name')" :value="old('name', $job->name ?? '')" required />
                </div>
                <fieldset class="ap-field ap-fieldset">
                    <legend class="ap-label">{{ __('What to make') }}</legend>
                    <div class="ap-stack">
                        <label class="ap-check"><input type="radio" name="engine" value="archive" x-model="engine">
                            <span><b>{{ __('Archives') }}</b> — {{ __('a complete .tar of each account: web, mail, databases, DNS, cron and files; it restores on any ArcPanel server.') }}</span></label>
                        <label class="ap-check"><input type="radio" name="engine" value="snapshot" x-model="engine">
                            <span><b>{{ __('Snapshots') }}</b> — {{ __('incremental, deduplicated restic snapshots of each home folder; quick to make often, files restore one by one.') }}</span></label>
                    </div>
                </fieldset>
            </x-ap-card>

            <x-ap-card :title="__('Schedule')" icon="clock">
                <div class="ap-form-grid">
                    <x-ap-select name="preset" :label="__('Runs')" x-model="preset" :value="$state['preset']"
                        :options="['hourly' => __('Every hour'), 'daily' => __('Every day'), 'weekly' => __('Every week'), 'monthly' => __('Every month'), 'cron' => __('Custom (cron)')]" />
                    <div x-show="preset === 'weekly'" x-cloak>
                        <x-ap-select name="weekday" :label="__('Day of the week')" :options="$weekdays" :value="$state['weekday']" x-model.number="weekday" x-bind:disabled="preset !== 'weekly'" />
                    </div>
                    <div x-show="preset === 'monthly'" x-cloak>
                        <x-ap-select name="day" :label="__('Day of the month')" :options="array_combine(range(1, 28), range(1, 28))" :value="$state['day']" x-model.number="day"
                            x-bind:disabled="preset !== 'monthly'" :hint="__('1 to 28, so that it runs every month.')" />
                    </div>
                    <div x-show="preset !== 'hourly' && preset !== 'cron'">
                        <x-ap-select name="hour" :label="__('Hour')" :options="$hours" :value="$state['hour']" x-model.number="hour" x-bind:disabled="preset === 'hourly' || preset === 'cron'" />
                    </div>
                    <div x-show="preset !== 'cron'">
                        <x-ap-select name="minute" :label="__('Minute')" :options="$minutes" :value="$state['minute']" x-model.number="minute" x-bind:disabled="preset === 'cron'" />
                    </div>
                    <div x-show="preset === 'cron'" x-cloak>
                        <x-ap-input name="cron" :label="__('Cron expression')" :value="$state['cron']" mono x-model="cron" x-bind:disabled="preset !== 'cron'" placeholder="30 */6 * * *"
                            :hint="__('Minute, hour, day of the month, month, day of the week; in the time zone of the server.')" />
                    </div>
                </div>
                <div class="ap-schedule-preview" aria-live="polite" data-schedule-preview>
                    <template x-if="preview.valid && preview.times.length > 0">
                        <div>
                            <p class="muted"><span x-text="preview.describe"></span> · {{ __('next runs:') }}</p>
                            <ul class="ap-row"><template x-for="time in preview.times" :key="time"><li><x-ap-chip class="mono"><span x-text="time"></span></x-ap-chip></li></template></ul>
                        </div>
                    </template>
                    <template x-if="!preview.valid">
                        <p class="ap-error"><x-ap-icon name="x-circle" size="sm" /><span x-text="preview.message"></span></p>
                    </template>
                </div>
                <p class="muted">{{ __('Each package sets how often its accounts are backed up at most; a scheduled run skips accounts backed up more recently than that.') }}</p>
            </x-ap-card>

            <x-ap-card :title="__('Accounts')" icon="users">
                <fieldset class="ap-field ap-fieldset">
                    <legend class="ap-label">{{ __('Back up') }}</legend>
                    <div class="ap-row ap-radio-row">
                        <label class="ap-check"><input type="radio" name="scope" value="all" x-model="scope"><span>{{ __('All users') }}</span></label>
                        <label class="ap-check"><input type="radio" name="scope" value="packages" x-model="scope"><span>{{ __('Users of some packages') }}</span></label>
                        <label class="ap-check"><input type="radio" name="scope" value="users" x-model="scope"><span>{{ __('Some users') }}</span></label>
                    </div>
                </fieldset>
                @error('packages')<span class="ap-error"><x-ap-icon name="x-circle" size="sm" />{{ $message }}</span>@enderror
                <fieldset class="ap-field ap-fieldset" x-show="scope === 'packages'" x-cloak x-bind:disabled="scope !== 'packages'">
                    <legend class="ap-label">{{ __('Packages') }}</legend>
                    <div class="ap-check-grid">
                        @foreach ($packages as $package)
                            <label class="ap-check"><input type="checkbox" name="packages[]" value="{{ $package }}" @checked(in_array($package, $packagesPicked, true))><span class="mono">{{ $package }}</span></label>
                        @endforeach
                    </div>
                </fieldset>
                @error('users')<span class="ap-error"><x-ap-icon name="x-circle" size="sm" />{{ $message }}</span>@enderror
                <fieldset class="ap-field ap-fieldset" x-show="scope === 'users'" x-cloak x-bind:disabled="scope !== 'users'">
                    <legend class="ap-label">{{ __('Users') }}</legend>
                    <div class="ap-check-grid">
                        @foreach ($users as $user)
                            <label class="ap-check"><input type="checkbox" name="users[]" value="{{ $user }}" @checked(in_array($user, $usersPicked, true))><span class="mono">{{ $user }}</span></label>
                        @endforeach
                    </div>
                </fieldset>
                <details class="ap-disclosure-plain" x-show="scope !== 'users'" @if ($excluded !== []) open @endif>
                    <summary>{{ $excluded === [] ? __('Leave out some users') : trans_choice('Leave out :count user|Leave out :count users', count($excluded), ['count' => count($excluded)]) }}</summary>
                    <fieldset class="ap-field ap-fieldset" x-bind:disabled="scope === 'users'">
                        <legend class="sr-only">{{ __('Users left out') }}</legend>
                        <div class="ap-check-grid">
                            @foreach ($users as $user)
                                <label class="ap-check"><input type="checkbox" name="exclude[]" value="{{ $user }}" @checked(in_array($user, $excluded, true))><span class="mono">{{ $user }}</span></label>
                            @endforeach
                        </div>
                    </fieldset>
                </details>
            </x-ap-card>

            <x-ap-card :title="__('Destinations')" icon="disk">
                <p class="muted" x-show="engine === 'archive'">{{ __('Each destination keeps its own number of archives. Empty keeps the number of backups of the account\'s package, which is also the most any destination keeps.') }}</p>
                <p class="muted" x-show="engine === 'snapshot'" x-cloak>{{ __('Each destination keeps snapshots by these rules; a snapshot kept by any rule stays. Empty means no limit of that kind.') }}</p>
                @error('targets')<span class="ap-error"><x-ap-icon name="x-circle" size="sm" />{{ $message }}</span>@enderror
                <div class="ap-stack" data-targets>
                    <template x-for="(target, index) in targets" :key="index">
                        <div class="ap-target-row">
                            <label class="ap-field"><span class="ap-label">{{ __('Destination') }}</span>
                                <select class="ap-select" x-bind:name="'targets[' + index + '][destination]'" x-model="target.destination" required>
                                    <option value="">{{ __('Choose…') }}</option>
                                    @foreach ($destinations as $destination)
                                        <option value="{{ $destination->name }}">{{ $destination->name }} ({{ BackupDestination::driverLabel($destination->driver) }}{{ $destination->encrypt ? ', '.__('encrypted') : '' }})</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="ap-field"><span class="ap-label" x-text="engine === 'snapshot' ? @js(__('Last snapshots')) : @js(__('Archives to keep'))"></span>
                                <input class="ap-input ap-input-mono" type="number" min="1" max="9999" x-bind:name="'targets[' + index + '][keep_last]'" x-model="target.keep_last"></label>
                            <template x-if="engine === 'snapshot'">
                                <div class="ap-target-keep">
                                    <label class="ap-field"><span class="ap-label">{{ __('Daily') }}</span><input class="ap-input ap-input-mono" type="number" min="1" max="9999" x-bind:name="'targets[' + index + '][daily]'" x-model="target.daily"></label>
                                    <label class="ap-field"><span class="ap-label">{{ __('Weekly') }}</span><input class="ap-input ap-input-mono" type="number" min="1" max="9999" x-bind:name="'targets[' + index + '][weekly]'" x-model="target.weekly"></label>
                                    <label class="ap-field"><span class="ap-label">{{ __('Monthly') }}</span><input class="ap-input ap-input-mono" type="number" min="1" max="9999" x-bind:name="'targets[' + index + '][monthly]'" x-model="target.monthly"></label>
                                    <label class="ap-field"><span class="ap-label">{{ __('Yearly') }}</span><input class="ap-input ap-input-mono" type="number" min="1" max="9999" x-bind:name="'targets[' + index + '][yearly]'" x-model="target.yearly"></label>
                                </div>
                            </template>
                            <button type="button" class="ap-btn ap-btn-ghost ap-btn-sm" x-on:click="removeTarget(index)" x-bind:disabled="targets.length === 1"
                                aria-label="{{ __('Remove this destination') }}"><x-ap-icon name="trash" /></button>
                        </div>
                    </template>
                </div>
                @foreach ($errors->getMessages() as $key => $messages)
                    @if (str_starts_with($key, 'targets.'))
                        <span class="ap-error"><x-ap-icon name="x-circle" size="sm" />{{ $messages[0] }}</span>
                    @endif
                @endforeach
                <div><x-ap-button icon="plus" x-on:click="addTarget()" x-bind:disabled="targets.length >= 8">{{ __('Add destination') }}</x-ap-button></div>
            </x-ap-card>

            @unless ($editing)
                <x-ap-card>
                    <x-ap-switch name="enabled" :label="__('Run on the schedule')" :description="__('Off saves the job paused; Run now still works.')" :checked="true" />
                </x-ap-card>
            @endunless
        </fieldset>
        @if ($canManage)
            <div class="ap-form-actions">
                <x-ap-button :href="route('server.backups.jobs')">{{ __('Cancel') }}</x-ap-button>
                <x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button>
            </div>
        @endif
    </x-ap-form>

    @if ($editing)
        <x-ap-card :title="__('Recent runs')" icon="history" flush>
            <x-slot:actions><a href="{{ route('server.backups.activity', ['job' => $job->id]) }}">{{ __('All runs') }}</a></x-slot:actions>
            @if ($runs === [])
                <x-ap-empty icon="history" :title="__('This job has not run yet')" />
            @else
                <ul class="ap-list">
                    @foreach ($runs as $run)
                        <li>
                            <div class="ap-list-main"><a href="{{ route('server.backups.activity.show', ['job' => $job->id, 'run' => $run->run]) }}"><b class="mono">{{ $run->start }}</b></a>
                                <span>{{ sprintf(__('%1$d of %2$d users'), $run->done, $run->users) }}@if ($run->failed > 0) · {{ trans_choice(':count failed|:count failed', $run->failed, ['count' => $run->failed]) }}@endif</span></div>
                            <x-ap-badge :tone="$run->tone()">{{ $statusLabel[$run->status] ?? $run->status }}</x-ap-badge>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ap-card>

        @if ($canManage)
            <x-ap-card :title="__('Delete job')" icon="trash">
                <p>{{ __('The job stops; the backups it made stay on their destinations and in the users\' lists.') }}</p>
                <x-ap-button icon="trash" :form-action="route('server.backups.jobs.destroy', ['job' => $job->id])" form-method="DELETE"
                    :confirm="sprintf(__('Delete job %s?'), $job->name)" :disabled="$job->running">{{ __('Delete') }}</x-ap-button>
            </x-ap-card>
        @endif
    @endif
</x-ap-page>
