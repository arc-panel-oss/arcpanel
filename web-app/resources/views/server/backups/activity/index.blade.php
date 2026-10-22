{{-- Backups hub, Activity (E48 spec §8.2): the job runs of the server, filtered by job, state and user. --}}
@php
    $statusLabel = ['success' => __('Succeeded'), 'partial' => __('Partial'), 'failed' => __('Failed'), 'running' => __('Running')];
    $triggerLabel = ['schedule' => __('Schedule'), 'request' => __('Request'), 'cli' => __('Command line')];
    $filtered = array_filter($filters, fn ($v) => $v !== '') !== [];
@endphp
<x-ap-page :title="__('Backup activity')" :crumbs="[['label' => __('Server')], ['label' => __('Backups'), 'url' => route('server.backups')], ['label' => __('Activity')]]"
    :description="__('Every run of the backup jobs, newest first. A run opens the result of each account.')">
    <x-ap-card flush>
        <form method="get" action="{{ route('server.backups.activity') }}" class="ap-toolbar ap-row" data-activity-filters>
            <label class="ap-field"><span class="ap-label">{{ __('Job') }}</span>
                <select class="ap-select" name="job" data-ap-autosubmit>
                    <option value="">{{ __('All jobs') }}</option>
                    @foreach ($jobs as $job)
                        <option value="{{ $job->id }}" @selected($filters['job'] === $job->id)>{{ $job->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="ap-field"><span class="ap-label">{{ __('State') }}</span>
                <select class="ap-select" name="status" data-ap-autosubmit>
                    <option value="">{{ __('Any state') }}</option>
                    @foreach ($statusLabel as $value => $label)
                        <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="ap-field"><span class="ap-label">{{ __('User') }}</span>
                <input class="ap-input ap-input-mono" name="user" value="{{ $filters['user'] }}" autocomplete="off" spellcheck="false">
            </label>
            <x-ap-button type="submit" icon="filter">{{ __('Filter') }}</x-ap-button>
            @if ($filtered)
                <a href="{{ route('server.backups.activity') }}">{{ __('Clear') }}</a>
            @endif
        </form>
        @if ($runs === [])
            <x-ap-empty icon="history" :title="$filtered ? __('No runs match') : __('No runs yet')">
                {{ $filtered ? __('Change or clear the filters.') : __('Runs show here once a job has run on its schedule or with Run now.') }}
            </x-ap-empty>
        @else
            <x-ap-table id="backup-activity" :searchable="false" :caption="__('Backup job runs')" :actions-column="false" :columns="[
                ['key' => 'start', 'label' => __('Started')],
                ['key' => 'job', 'label' => __('Job')],
                ['key' => 'trigger', 'label' => __('Trigger'), 'optional' => true],
                ['key' => 'status', 'label' => __('State')],
                ['key' => 'users', 'label' => __('Accounts')],
                ['key' => 'end', 'label' => __('Ended'), 'optional' => true],
            ]">
                @foreach ($runs as $run)
                    <tr data-row data-run="{{ $run->run }}">
                        <td data-col="start" data-label="{{ __('Started') }}"><a class="mono" href="{{ route('server.backups.activity.show', ['job' => $run->job, 'run' => $run->run]) }}">{{ $run->start }}</a></td>
                        <td data-col="job" data-label="{{ __('Job') }}">{{ $run->jobName ?: $run->job }}</td>
                        <td data-col="trigger" data-label="{{ __('Trigger') }}">{{ $triggerLabel[$run->trigger] ?? $run->trigger }}</td>
                        <td data-col="status" data-label="{{ __('State') }}"><x-ap-badge :tone="$run->tone()">{{ $statusLabel[$run->status] ?? $run->status }}</x-ap-badge></td>
                        <td data-col="users" data-label="{{ __('Accounts') }}">
                            {{ sprintf(__('%1$d of %2$d'), $run->done, $run->users) }}
                            @if ($run->failed > 0)<small class="ap-cell-note">{{ trans_choice(':count failed|:count failed', $run->failed, ['count' => $run->failed]) }}</small>@endif
                            @if ($run->skipped > 0)<small class="muted ap-cell-note">{{ trans_choice(':count skipped|:count skipped', $run->skipped, ['count' => $run->skipped]) }}</small>@endif
                        </td>
                        <td data-col="end" data-label="{{ __('Ended') }}" class="mono">{{ $run->end ?: '—' }}</td>
                    </tr>
                @endforeach
            </x-ap-table>
        @endif
    </x-ap-card>
</x-ap-page>
