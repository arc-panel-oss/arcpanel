{{-- Jobs (E15 spec §6.2): the jobs of the account, its customers and the server, 50 a page, from the job index. --}}
@php
    $columns = array_values(array_filter([
        ['key' => 'type', 'label' => __('Type'), 'sortable' => false],
        ['key' => 'object', 'label' => __('Object'), 'sortable' => false],
        $showUser ? ['key' => 'user', 'label' => __('User'), 'sortable' => false] : null,
        ['key' => 'status', 'label' => __('Status'), 'sortable' => false],
        ['key' => 'started', 'label' => __('Started'), 'sortable' => false],
        ['key' => 'duration', 'label' => __('Duration'), 'sortable' => false, 'optional' => true],
        ['key' => 'actor', 'label' => __('Actor'), 'sortable' => false, 'optional' => true],
    ]));
    $statuses = ['' => __('Any status'), 'queued' => __('Queued'), 'running' => __('Running'), 'succeeded' => __('Succeeded'), 'failed' => __('Failed'), 'cancelled' => __('Cancelled')];
    $periods = ['' => __('Any time'), 'day' => __('Last 24 hours'), 'week' => __('Last 7 days'), 'month' => __('Last 30 days')];
@endphp
<x-ap-page :title="__('Jobs')" :crumbs="[['label' => __('Account')], ['label' => __('Jobs')]]"
    :description="__('Long operations of the panel: backups, restores, certificates, installs, updates and deploys. Finished jobs are kept for 30 days.')">
    @if ($error !== null)
        <x-ap-alert tone="danger" :title="$error" data-jobs-error>
            @if ($canRebuild)
                <x-ap-button size="sm" :form-action="route('jobs.rebuild')" icon="refresh">{{ __('Rebuild') }}</x-ap-button>
            @endif
        </x-ap-alert>
    @endif

    <x-ap-card>
        <form method="GET" action="{{ route('jobs.index') }}" class="ap-stack" aria-label="{{ __('Filter the jobs') }}">
            <div class="ap-form-grid">
                <x-ap-select name="status" :label="__('Status')" :options="$statuses" :value="$filters['status']" />
                <x-ap-input name="type" :label="__('Type')" :value="$filters['type']" mono :hint="__('A job type, or the start of one with .*, for example backup.*')" />
                @if ($showUser)
                    <x-ap-input name="user" :label="__('User')" :value="$filters['user']" mono />
                @endif
                <x-ap-select name="period" :label="__('Period')" :options="$periods" :value="$filters['period']" />
            </div>
            <div class="ap-form-actions">
                <x-ap-button type="submit" variant="primary" icon="filter">{{ __('Filter') }}</x-ap-button>
                <x-ap-button :href="route('jobs.index')" variant="ghost">{{ __('Reset') }}</x-ap-button>
            </div>
        </form>
    </x-ap-card>

    <x-ap-card :flush="true">
        @if ($jobs === [])
            <x-ap-empty icon="activity" :title="__('No jobs')">{{ __('Nothing matches these filters.') }}</x-ap-empty>
        @else
            <x-ap-table id="jobs" :columns="$columns" :search-label="__('Search jobs')" :caption="__('Jobs')">
                @foreach ($jobs as $job)
                    @php $id = substr($job->id, 4); @endphp
                    <tr data-row data-search="{{ $job->type }} {{ $job->object }} {{ $job->user }} {{ $job->actor }}" data-job-id="{{ $id }}">
                        <td data-col="type" data-label="{{ __('Type') }}" class="ap-mono"><a href="{{ route('jobs.show', ['id' => $id]) }}">{{ $job->type }}</a></td>
                        <td data-col="object" data-label="{{ __('Object') }}" class="ap-mono">{{ $job->object }}</td>
                        @if ($showUser)
                            <td data-col="user" data-label="{{ __('User') }}">{{ $job->user === 'system' ? __('Server') : $job->user }}</td>
                        @endif
                        <td data-col="status" data-label="{{ __('Status') }}">@include('jobs.partials.status', ['status' => $job->status])</td>
                        <td data-col="started" data-label="{{ __('Started') }}" class="ap-mono">{{ $job->startedAt ? str_replace('T', ' ', $job->startedAt) : '' }}</td>
                        <td data-col="duration" data-label="{{ __('Duration') }}" class="ap-mono">{{ \App\View\JobFormat::duration($job->startedAt, $job->finishedAt) }}</td>
                        <td data-col="actor" data-label="{{ __('Actor') }}">{{ $job->actor }}</td>
                    </tr>
                @endforeach
                <x-slot:footer>
                    <x-ap-pager :page="$page" :pages="$pages" :total="$total" :per-page="\App\Http\Controllers\Jobs\JobController::PAGE_SIZE"
                        :url="route('jobs.index', $query)" />
                </x-slot:footer>
            </x-ap-table>
        @endif
    </x-ap-card>
</x-ap-page>
