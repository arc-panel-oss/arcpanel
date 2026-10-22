{{-- Backups hub, Schedules (E48 spec §8.2): the backup jobs with their schedule, scope, targets and switch. --}}
@php
    $statusLabel = ['success' => __('Succeeded'), 'partial' => __('Partial'), 'failed' => __('Failed'), 'running' => __('Running')];
@endphp
<x-ap-page :title="__('Backup schedules')" :crumbs="[['label' => __('Server')], ['label' => __('Backups'), 'url' => route('server.backups')], ['label' => __('Schedules')]]"
    :description="__('A job backs up a set of accounts on a schedule and sends each backup to its destinations, each with its own retention.')">
    @if ($canManage)
        <x-slot:actions>
            <x-ap-button :href="route('server.backups.jobs.create')" variant="primary" icon="plus">{{ __('Add job') }}</x-ap-button>
        </x-slot:actions>
    @endif

    <x-ap-card :title="__('Jobs')" icon="clock" flush>
        @if ($jobs === [])
            <x-ap-empty icon="clock" :title="__('No backup jobs yet')">
                {{ $destinations === [] ? __('Add a destination first: a job needs somewhere to send the backups.') : __('Add a job to back up the accounts on a schedule.') }}
                @if ($canManage)
                    <x-slot:actions>
                        <x-ap-button :href="$destinations === [] ? route('server.backups.destinations.create') : route('server.backups.jobs.create')" variant="primary" icon="plus">
                            {{ $destinations === [] ? __('Add destination') : __('Add job') }}
                        </x-ap-button>
                    </x-slot:actions>
                @endif
            </x-ap-empty>
        @else
            <x-ap-table id="backup-jobs" :caption="__('Backup jobs')" :columns="[
                ['key' => 'name', 'label' => __('Name')],
                ['key' => 'schedule', 'label' => __('Schedule')],
                ['key' => 'scope', 'label' => __('Accounts'), 'optional' => true],
                ['key' => 'targets', 'label' => __('Destinations')],
                ['key' => 'next', 'label' => __('Next run')],
                ['key' => 'last', 'label' => __('Last run')],
                ['key' => 'enabled', 'label' => __('Enabled')],
            ]">
                @foreach ($jobs as $job)
                    <tr data-row data-job="{{ $job->id }}" data-search="{{ $job->name }} {{ $job->engine }}">
                        <td data-col="name" data-label="{{ __('Name') }}">
                            <a href="{{ route('server.backups.jobs.edit', ['job' => $job->id]) }}"><b>{{ $job->name }}</b></a>
                            <small class="muted ap-cell-note">{{ $job->engine === 'snapshot' ? __('Snapshots (restic)') : __('Archives') }}</small>
                        </td>
                        <td data-col="schedule" data-label="{{ __('Schedule') }}">{{ $job->schedule->describe() }}</td>
                        <td data-col="scope" data-label="{{ __('Accounts') }}">{{ $job->scopeLabel() }}
                            @if ($job->excluded !== [])
                                <small class="muted ap-cell-note">{{ sprintf(__('Except %s'), implode(', ', $job->excluded)) }}</small>
                            @endif
                        </td>
                        <td data-col="targets" data-label="{{ __('Destinations') }}">
                            <span class="ap-row">
                                @foreach ($job->destinations() as $name)
                                    <x-ap-chip class="mono">{{ $name }}@if (($destinations[$name] ?? null)?->encrypt) <x-ap-icon name="lock" size="sm" />@endif</x-ap-chip>
                                @endforeach
                            </span>
                        </td>
                        <td data-col="next" data-label="{{ __('Next run') }}" class="mono">{{ $job->enabled ? ($job->nextRun ?: '—') : __('Paused') }}</td>
                        <td data-col="last" data-label="{{ __('Last run') }}">
                            @if ($job->running)
                                <x-ap-badge tone="info">{{ __('Running') }}</x-ap-badge>
                            @elseif ($job->lastStatus !== '')
                                <x-ap-badge :tone="\App\Services\Backups\BackupJobRun::statusTone($job->lastStatus)">{{ $statusLabel[$job->lastStatus] ?? $job->lastStatus }}</x-ap-badge>
                                <small class="muted ap-cell-note mono">{{ $job->lastEnd }}</small>
                            @else
                                <span class="muted">{{ __('Never') }}</span>
                            @endif
                        </td>
                        <td data-col="enabled" data-label="{{ __('Enabled') }}">
                            @if ($canManage)
                                <form method="post" action="{{ route('server.backups.jobs.toggle', ['job' => $job->id]) }}" x-data x-on:change="$el.requestSubmit()">
                                    @csrf
                                    <input type="hidden" name="enabled" value="{{ $job->enabled ? '0' : '1' }}">
                                    <span class="ap-switch"><input type="checkbox" role="switch" @checked($job->enabled) aria-label="{{ sprintf(__('Run job %s on its schedule'), $job->name) }}"></span>
                                    <noscript><button type="submit" class="ap-btn ap-btn-sm">{{ $job->enabled ? __('Pause') : __('Resume') }}</button></noscript>
                                </form>
                            @else
                                {{ $job->enabled ? __('Yes') : __('Paused') }}
                            @endif
                        </td>
                        <td class="col-actions">
                            @if ($canManage)
                                <div class="ap-row">
                                    <x-ap-button size="sm" icon="play" :form-action="route('server.backups.jobs.run', ['job' => $job->id])" :disabled="$job->running"
                                        :confirm="sprintf(__('Run job %s now for all its accounts?'), $job->name)">{{ __('Run now') }}</x-ap-button>
                                    <x-ap-button size="sm" icon="edit" :href="route('server.backups.jobs.edit', ['job' => $job->id])"
                                        :aria-label="sprintf(__('Edit job %s'), $job->name)">{{ __('Edit') }}</x-ap-button>
                                </div>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-ap-table>
        @endif
    </x-ap-card>
</x-ap-page>
