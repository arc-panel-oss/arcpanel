{{-- History of an account's backups (E48 spec §8.1): every recorded run with its job and the state per destination. --}}
@use('App\Http\Controllers\Backups\OverviewController')
@php
    $statusLabel = ['succeeded' => __('Succeeded'), 'failed' => __('Failed'), 'running' => __('Running'), 'interrupted' => __('Interrupted')];
    $statusTone = ['succeeded' => 'ok', 'failed' => 'danger', 'running' => 'info'];
    $triggerLabel = ['schedule' => __('Schedule'), 'request' => __('Request'), 'cli' => __('Command line')];
@endphp
<x-ap-page :title="__('Backup history')" :crumbs="[['label' => __('Backups'), 'url' => route('backups.overview', ['user' => $user])], ['label' => __('History')]]"
    :eyebrow="__('Owner').': '.$user" :description="__('The last 30 backups of the account, newest first.')">
    @include('backups.partials.nav', ['current' => 'history'])

    <x-ap-card flush>
        @if ($runs === [])
            <x-ap-empty icon="history" :title="__('No backups have run yet')" />
        @else
            <x-ap-table id="backup-history" :searchable="false" :caption="__('Backup history')" :actions-column="false" :columns="[
                ['key' => 'start', 'label' => __('Started')],
                ['key' => 'kind', 'label' => __('Kind')],
                ['key' => 'trigger', 'label' => __('Trigger'), 'optional' => true],
                ['key' => 'job', 'label' => __('Job'), 'optional' => true],
                ['key' => 'status', 'label' => __('State')],
                ['key' => 'destinations', 'label' => __('Destinations')],
                ['key' => 'duration', 'label' => __('Duration'), 'optional' => true],
                ['key' => 'size', 'label' => __('Size'), 'type' => 'number', 'optional' => true],
            ]">
                @foreach ($runs as $run)
                    @php($states = $run->destinationStates())
                    @php($partial = $run->status === 'succeeded' && in_array('failed', $states, true))
                    <tr data-row data-run="{{ $run->id }}">
                        <td data-col="start" data-label="{{ __('Started') }}" class="mono">{{ $run->start }}</td>
                        <td data-col="kind" data-label="{{ __('Kind') }}">{{ $run->engine === 'restic' ? __('Snapshot') : __('Archive') }}</td>
                        <td data-col="trigger" data-label="{{ __('Trigger') }}">{{ $triggerLabel[$run->trigger] ?? $run->trigger }}</td>
                        <td data-col="job" data-label="{{ __('Job') }}">{{ $jobNames[$run->job] ?? ($run->job !== '' ? $run->job : '—') }}</td>
                        <td data-col="status" data-label="{{ __('State') }}">
                            <x-ap-badge :tone="$partial ? 'warn' : ($statusTone[$run->status] ?? 'neutral')">{{ $partial ? __('Partial') : ($statusLabel[$run->status] ?? $run->status) }}</x-ap-badge>
                            @if ($run->error !== '')
                                <details class="ap-disclosure-plain"><summary>{{ __('Error') }}</summary><span class="ap-cell-note">{{ $run->error }}</span></details>
                            @endif
                        </td>
                        <td data-col="destinations" data-label="{{ __('Destinations') }}">
                            <span class="ap-row">
                                @forelse ($states as $name => $state)
                                    <x-ap-badge :tone="$state === 'ok' ? 'ok' : 'danger'" mono>{{ $name }}</x-ap-badge>
                                @empty
                                    <span class="muted">—</span>
                                @endforelse
                            </span>
                        </td>
                        <td data-col="duration" data-label="{{ __('Duration') }}">{{ OverviewController::duration($run) ?: '—' }}</td>
                        <td data-col="size" data-label="{{ __('Size') }}" class="col-num mono">{{ $run->size !== '' ? $run->size.' MB' : '—' }}</td>
                    </tr>
                @endforeach
            </x-ap-table>
        @endif
    </x-ap-card>
</x-ap-page>
