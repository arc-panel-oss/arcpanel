{{-- Overview of an account's backups (E48 spec §8.1): last run, next runs, destinations by name only, counts, Back up now. --}}
@use('App\Http\Controllers\Backups\OverviewController')
@use('App\Services\Backups\BackupDestination')
@php
    $runLabel = ['succeeded' => __('Succeeded'), 'failed' => __('Failed'), 'running' => __('Running'), 'interrupted' => __('Interrupted')];
    $runTone = ['succeeded' => 'ok', 'failed' => 'danger', 'running' => 'info'];
    $partial = $last !== null && $last->status === 'succeeded' && in_array('failed', $last->destinationStates(), true);
@endphp
<x-ap-page :title="__('Backups')" :crumbs="[['label' => __('Backups')]]" :eyebrow="__('Owner').': '.$user"
    :description="__('What is backed up, when, and where. Backups and restores run in the background.')">
    <x-slot:actions>
        @if ($can['create'] && $available)
            @if (count($archiveJobs) > 1)
                <details class="ap-dropdown">
                    <summary class="ap-btn ap-btn-primary"><x-ap-icon name="plus" />{{ __('Back up now') }}<x-ap-icon name="chevron-down" size="sm" /></summary>
                    <div class="ap-menu" role="group" aria-label="{{ __('Back up now') }}">
                        @foreach ($archiveJobs as $job)
                            <form method="post" action="{{ route('backups.archives.store', ['user' => $user]) }}">
                                @csrf
                                <input type="hidden" name="job" value="{{ $job->id }}">
                                <input type="hidden" name="from" value="overview">
                                <button type="submit" class="ap-menu-item">{{ sprintf(__('With the job %s'), $job->name) }}</button>
                            </form>
                        @endforeach
                    </div>
                </details>
            @else
                <form method="post" action="{{ route('backups.archives.store', ['user' => $user]) }}">
                    @csrf
                    @if ($archiveJobs !== [])<input type="hidden" name="job" value="{{ $archiveJobs[0]->id }}">@endif
                    <input type="hidden" name="from" value="overview">
                    <x-ap-button type="submit" variant="primary" icon="plus">{{ __('Back up now') }}</x-ap-button>
                </form>
            @endif
        @endif
    </x-slot:actions>

    @include('backups.partials.nav', ['current' => 'overview'])

    <div class="ap-grid-2">
        <x-ap-card :title="__('Last backup')" icon="history" data-last-backup>
            @if ($running !== null)
                <x-ap-alert tone="info">{{ sprintf(__('A backup is running since %s.'), $running->start) }}</x-ap-alert>
            @endif
            @if ($last === null)
                <p class="muted">{{ __('No backup has run for this account yet.') }}</p>
            @else
                <div class="ap-stat">
                    <div class="ap-stat-value">
                        <x-ap-badge :tone="$partial ? 'warn' : ($runTone[$last->status] ?? 'neutral')">{{ $partial ? __('Partial') : ($runLabel[$last->status] ?? $last->status) }}</x-ap-badge>
                        <small class="mono">{{ $last->start }}</small>
                    </div>
                    <div class="ap-stat-meta">
                        @if (($duration = OverviewController::duration($last)) !== '')<span>{{ $duration }}</span>@endif
                        @if ($last->size !== '')<span>{{ $last->size }} MB</span>@endif
                        @if ($last->engine === 'restic')<span>{{ __('Snapshot') }}</span>@else<span>{{ __('Archive') }}</span>@endif
                    </div>
                </div>
                @if ($last->error !== '' && $last->status !== 'succeeded')
                    <p class="ap-error"><x-ap-icon name="x-circle" size="sm" />{{ $last->error }}</p>
                @endif
                @if ($can['history'])
                    <p><a href="{{ route('backups.history', ['user' => $user]) }}">{{ __('All runs') }}</a></p>
                @endif
            @endif
        </x-ap-card>

        <x-ap-card :title="__('Next backups')" icon="clock" flush>
            @if ($jobs === [])
                <x-ap-empty icon="clock" :title="__('No scheduled backups')">{{ __('No backup job covers this account. Ask the administrator of the server.') }}</x-ap-empty>
            @else
                <ul class="ap-list" data-next-backups>
                    @foreach ($jobs as $job)
                        <li>
                            <div class="ap-list-main"><b>{{ $job->engine === 'snapshot' ? __('Snapshots') : __('Archives') }}</b><span>{{ $job->schedule->describe() }}</span></div>
                            <span class="subtle mono">{{ $job->nextRun ?: '—' }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ap-card>
    </div>

    <x-ap-card :title="__('Where backups are kept')" icon="disk">
        @if ($destinations === [])
            <p class="muted">{{ __('None yet.') }}</p>
        @else
            <div class="ap-row" data-destinations>
                @foreach ($destinations as $destination)
                    <x-ap-chip><x-ap-icon :name="BackupDestination::driverIcon($destination->driver)" size="sm" />
                        <span class="mono">{{ $destination->name }}</span>@if ($destination->encrypt) <x-ap-icon name="lock" size="sm" /><span class="sr-only">{{ __('Encrypted') }}</span>@endif
                    </x-ap-chip>
                @endforeach
            </div>
        @endif
        <div class="ap-row ap-backup-counts">
            <a href="{{ route('backups.archives.index', ['user' => $user]) }}">{{ trans_choice(':count archive|:count archives', $archives, ['count' => $archives]) }}</a>
            @if ($snapshots !== null)
                <a href="{{ route('backups.snapshots.index', ['user' => $user]) }}">{{ trans_choice(':count snapshot|:count snapshots', $snapshots, ['count' => $snapshots]) }}</a>
            @endif
            @if ($can['restore'])
                <a href="{{ route('backups.restore', ['user' => $user]) }}">{{ __('Restore…') }}</a>
            @endif
        </div>
    </x-ap-card>
</x-ap-page>
