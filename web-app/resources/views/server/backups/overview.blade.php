{{-- Backups hub, Overview (E48 spec §8.2): runs of the last 24 hours, the next runs, failing destinations and missing recovery kits. --}}
@use('App\Services\Backups\BackupDestination')
@use('App\Services\Backups\BackupJobRun')
@php
    $statusLabel = ['success' => __('Succeeded'), 'partial' => __('Partial'), 'failed' => __('Failed'), 'running' => __('Running')];
@endphp
<x-ap-page :title="__('Backups')" :crumbs="[['label' => __('Server')], ['label' => __('Backups')]]"
    :description="__('Backup jobs copy the accounts of this server to destinations on a schedule.')">
    @if ($canManage)
        <x-slot:actions>
            <x-ap-button :href="route('server.backups.destinations.create')" icon="plus">{{ __('Add destination') }}</x-ap-button>
            <x-ap-button :href="route('server.backups.jobs.create')" variant="primary" icon="plus">{{ __('Add job') }}</x-ap-button>
        </x-slot:actions>
    @endif

    @foreach ($failing as $destination)
        <x-ap-alert tone="danger" :title="sprintf(__('Destination %s fails'), $destination->name)">
            {{ __('The last transfers to it failed; jobs keep their other destinations.') }}
            <a href="{{ route('server.backups.destinations.edit', ['destination' => $destination->name]) }}">{{ __('Check the destination') }}</a>
        </x-ap-alert>
    @endforeach
    @if ($noKit !== [])
        <x-ap-alert tone="warn" :title="__('Download the recovery kits')">
            {{ __('Archives on encrypted destinations can only be read with their key. Without the recovery kit, a new server cannot restore them.') }}
            @foreach ($noKit as $destination)
                <a href="{{ route('server.backups.destinations.edit', ['destination' => $destination->name]) }}" class="mono">{{ $destination->name }}</a>@if (! $loop->last), @endif
            @endforeach
        </x-ap-alert>
    @endif

    @if ($jobs === [])
        <x-ap-card>
            <x-ap-empty icon="archive" :title="__('No backup jobs yet')">
                {{ $destinations === [] ? __('Add a destination for the archives first, then a job that sends the accounts there on a schedule.') : __('Add a job that sends the accounts to your destinations on a schedule.') }}
                @if ($canManage)
                    <x-slot:actions>
                        <x-ap-button :href="$destinations === [] ? route('server.backups.destinations.create') : route('server.backups.jobs.create')" variant="primary" icon="plus">
                            {{ $destinations === [] ? __('Add destination') : __('Add job') }}
                        </x-ap-button>
                    </x-slot:actions>
                @endif
            </x-ap-empty>
        </x-ap-card>
    @else
        <div class="ap-grid">
            @foreach (['success', 'partial', 'failed'] as $status)
                <x-ap-card>
                    <div class="ap-stat">
                        <div class="ap-stat-value"><a class="ap-stat-link" href="{{ route('server.backups.activity', ['status' => $status]) }}">{{ $counts[$status] }} <small>{{ $statusLabel[$status] }}</small></a></div>
                        <div class="ap-stat-meta"><span>{{ __('job runs in the last 24 hours') }}</span></div>
                    </div>
                </x-ap-card>
            @endforeach
            <x-ap-card>
                <div class="ap-stat">
                    <div class="ap-stat-value">{{ count($destinations) }} <small>{{ trans_choice('destination|destinations', count($destinations)) }}</small></div>
                    <div class="ap-stat-meta"><span>{{ trans_choice(':count encrypted|:count encrypted', count(array_filter($destinations, fn ($d) => $d->encrypt)), ['count' => count(array_filter($destinations, fn ($d) => $d->encrypt))]) }}</span></div>
                </div>
            </x-ap-card>
        </div>

        <div class="ap-grid-2">
            <x-ap-card :title="__('Next runs')" icon="clock" flush>
                @if ($next === [])
                    <x-ap-empty icon="clock" :title="__('Nothing scheduled')">{{ __('Every job is paused.') }}</x-ap-empty>
                @else
                    <ul class="ap-list" data-next-runs>
                        @foreach ($next as $job)
                            <li>
                                <div class="ap-list-main"><a href="{{ route('server.backups.jobs.edit', ['job' => $job->id]) }}"><b>{{ $job->name }}</b></a>
                                    <span>{{ $job->schedule->describe() }} · {{ $job->scopeLabel() }}</span></div>
                                <span class="subtle mono">{{ $job->nextRun }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ap-card>

            <x-ap-card :title="__('Recent runs')" icon="history" flush>
                <x-slot:actions><a href="{{ route('server.backups.activity') }}">{{ __('All activity') }}</a></x-slot:actions>
                @if ($recent === [])
                    <x-ap-empty icon="history" :title="__('No runs in the last 24 hours')" />
                @else
                    <ul class="ap-list" data-recent-runs>
                        @foreach ($recent as $run)
                            <li>
                                <div class="ap-list-main"><a href="{{ route('server.backups.activity.show', ['job' => $run->job, 'run' => $run->run]) }}"><b>{{ $run->jobName ?: $run->job }}</b></a>
                                    <span>{{ $run->start }} · {{ sprintf(__('%1$d of %2$d users'), $run->done, $run->users) }}</span></div>
                                <x-ap-badge :tone="$run->tone()">{{ $statusLabel[$run->status] ?? $run->status }}</x-ap-badge>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ap-card>
        </div>

        <x-ap-card :title="__('Destinations')" icon="disk" flush>
            <x-slot:actions><a href="{{ route('server.backups.destinations') }}">{{ __('Manage') }}</a></x-slot:actions>
            <ul class="ap-list">
                @foreach ($destinations as $destination)
                    <li>
                        <span class="ap-list-icon"><x-ap-icon :name="BackupDestination::driverIcon($destination->driver)" /></span>
                        <div class="ap-list-main"><b class="mono">{{ $destination->name }}@if ($destination->encrypt) <x-ap-icon name="lock" size="sm" /><span class="sr-only">{{ __('Encrypted') }}</span>@endif</b>
                            <span>{{ BackupDestination::driverLabel($destination->driver) }} · {{ $destination->location() }}</span></div>
                        <x-ap-badge :tone="BackupJobRun::statusTone($destination->state)">{{ $destination->state === 'ok' ? __('Working') : ($destination->state === 'failing' ? __('Failing') : __('Not checked')) }}</x-ap-badge>
                    </li>
                @endforeach
            </ul>
        </x-ap-card>
    @endif
</x-ap-page>
