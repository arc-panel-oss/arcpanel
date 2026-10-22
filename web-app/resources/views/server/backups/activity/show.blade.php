{{-- One run of a backup job (E48 spec §8.2 Activity): the result of each account, linked to its backups. --}}
@php
    $statusLabel = ['success' => __('Succeeded'), 'partial' => __('Partial'), 'failed' => __('Failed'), 'running' => __('Running')];
    $userLabel = ['succeeded' => __('Succeeded'), 'failed' => __('Failed'), 'running' => __('Running'), 'interrupted' => __('Interrupted'), 'queued' => __('Queued')];
    $userTone = ['succeeded' => 'ok', 'failed' => 'danger', 'running' => 'info', 'interrupted' => 'warn', 'queued' => 'neutral'];
    $title = sprintf(__('%1$s, %2$s'), $run->jobName ?: $run->job, $run->start);
@endphp
<x-ap-page :title="$title" :crumbs="[['label' => __('Server')], ['label' => __('Backups'), 'url' => route('server.backups')], ['label' => __('Activity'), 'url' => route('server.backups.activity')], ['label' => $run->start]]">
    @if ($run->status === 'running')
        <x-slot:actions>
            <x-ap-button icon="refresh" :href="url()->current()">{{ __('Refresh') }}</x-ap-button>
        </x-slot:actions>
    @endif
    <x-ap-card :title="__('Run')" icon="history">
        <dl class="ap-dl">
            <dt>{{ __('Job') }}</dt><dd><a href="{{ route('server.backups.jobs.edit', ['job' => $run->job]) }}">{{ $run->jobName ?: $run->job }}</a></dd>
            <dt>{{ __('State') }}</dt><dd><x-ap-badge :tone="$run->tone()">{{ $statusLabel[$run->status] ?? $run->status }}</x-ap-badge></dd>
            <dt>{{ __('Started') }}</dt><dd class="mono">{{ $run->start }}</dd>
            <dt>{{ __('Ended') }}</dt><dd class="mono">{{ $run->end ?: '—' }}</dd>
            <dt>{{ __('Accounts') }}</dt><dd>{{ sprintf(__('%1$d of %2$d backed up, %3$d failed, %4$d skipped'), $run->done, $run->users, $run->failed, $run->skipped) }}</dd>
        </dl>
    </x-ap-card>

    <x-ap-card :title="__('Accounts')" icon="users" flush>
        @if ($results === [])
            <x-ap-empty icon="users" :title="__('No account results')">{{ __('Skipped accounts were backed up recently enough for their package, suspended, or without incremental backups.') }}</x-ap-empty>
        @else
            <x-ap-table id="backup-run-users" :searchable="false" :caption="__('Results per account')" :actions-column="false" :columns="[
                ['key' => 'user', 'label' => __('User')],
                ['key' => 'status', 'label' => __('State')],
                ['key' => 'destinations', 'label' => __('Destinations')],
                ['key' => 'size', 'label' => __('Size'), 'type' => 'number'],
                ['key' => 'time', 'label' => __('Time')],
            ]">
                @foreach ($results as $result)
                    <tr data-row data-user="{{ $result->user }}">
                        <td data-col="user" data-label="{{ __('User') }}"><a class="mono" href="{{ route('backups.archives.index', ['user' => $result->user]) }}">{{ $result->user }}</a></td>
                        <td data-col="status" data-label="{{ __('State') }}">
                            <x-ap-badge :tone="$userTone[$result->status] ?? 'neutral'">{{ $userLabel[$result->status] ?? $result->status }}</x-ap-badge>
                            @if ($result->error !== '')<small class="ap-cell-note">{{ $result->error }}</small>@endif
                        </td>
                        <td data-col="destinations" data-label="{{ __('Destinations') }}">
                            <span class="ap-row">
                                @foreach ($result->destinationStates() as $name => $state)
                                    <x-ap-badge :tone="$state === 'ok' ? 'ok' : 'danger'" mono>{{ $name }}</x-ap-badge>
                                @endforeach
                            </span>
                        </td>
                        <td data-col="size" data-label="{{ __('Size') }}" class="col-num mono">{{ $result->size !== '' ? $result->size.' MB' : '—' }}</td>
                        <td data-col="time" data-label="{{ __('Time') }}" class="mono">{{ $result->start }}@if ($result->end !== '') – {{ substr($result->end, 11) }}@endif</td>
                    </tr>
                @endforeach
            </x-ap-table>
        @endif
    </x-ap-card>
</x-ap-page>
