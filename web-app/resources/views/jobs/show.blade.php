{{-- Job (E15 spec §6.2): status, times, actor, command, error, the object and the log followed live; cancel and retry. --}}
@php
    $id = substr($job->id, 4);
    $active = in_array($job->status, ['queued', 'running'], true);
    $time = fn (?string $value) => $value === null || $value === '' ? '—' : str_replace('T', ' ', $value);
@endphp
<x-ap-page :title="$job->type ?? __('Job')" :crumbs="[['label' => __('Jobs'), 'url' => route('jobs.index')], ['label' => $id]]"
    :description="__('A long operation of the panel and its output. The output is masked: secrets show as ***.')">
    <x-slot:actions>
        @if ($canManage && $active)
            <x-ap-button variant="danger" icon="x" :form-action="route('jobs.cancel', ['id' => $id])"
                :confirm="sprintf(__('Cancel job %s? A running job is stopped.'), $id)">{{ __('Cancel') }}</x-ap-button>
        @endif
        @if ($canManage && $job->retryable && in_array($job->status, ['failed', 'cancelled'], true))
            <x-ap-button icon="refresh" :form-action="route('jobs.retry', ['id' => $id])"
                :confirm="sprintf(__('Run job %s again as a new job?'), $id)">{{ __('Retry') }}</x-ap-button>
        @endif
    </x-slot:actions>

    <div class="ap-stack" x-data="apJob({ url: @js($statusUrl), status: @js($job->status), offset: @js($offset), log: @js($job->log) })" data-job="{{ $id }}">
        <x-ap-card :title="__('Job')" icon="activity">
            <x-slot:actions>
                <span class="ap-row" x-show="active" x-cloak><span class="ap-spinner" aria-hidden="true"></span>{{ __('Live') }}</span>
            </x-slot:actions>
            <dl class="ap-dl">
                <dt>{{ __('Status') }}</dt>
                <dd data-job-status>@include('jobs.partials.status', ['status' => $job->status])</dd>
                <dt>{{ __('Type') }}</dt>
                <dd class="ap-mono">{{ $job->type }}</dd>
                <dt>{{ __('Object') }}</dt>
                <dd class="ap-mono">
                    @if ($link !== null)
                        <a href="{{ $link['url'] }}">{{ $link['label'] }}</a>
                    @else
                        {{ $job->object }}
                    @endif
                </dd>
                <dt>{{ __('User') }}</dt>
                <dd>{{ $job->user === 'system' ? __('Server') : $job->user }}</dd>
                <dt>{{ __('Actor') }}</dt>
                <dd>{{ $job->actor }}</dd>
                <dt>{{ __('Arguments') }}</dt>
                <dd class="ap-mono">{{ $job->args }}</dd>
                <dt>{{ __('Queued') }}</dt>
                <dd class="ap-mono">{{ $time($job->queuedAt) }}</dd>
                <dt>{{ __('Started') }}</dt>
                <dd class="ap-mono">{{ $time($job->startedAt) }}</dd>
                <dt>{{ __('Finished') }}</dt>
                <dd class="ap-mono">{{ $time($job->finishedAt) }}</dd>
                @if ($job->exitCode !== null)
                    <dt>{{ __('Exit code') }}</dt>
                    <dd class="ap-mono">{{ $job->exitCode }}</dd>
                @endif
                @if ($job->retryOf !== null)
                    <dt>{{ __('Retry of') }}</dt>
                    <dd class="ap-mono"><a href="{{ route('jobs.show', ['id' => $job->retryOf]) }}">{{ $job->retryOf }}</a></dd>
                @endif
            </dl>
            @if ($job->error !== null && $job->error !== '')
                <x-ap-alert tone="danger" :title="__('Error')" data-job-error>{{ $job->error }}</x-ap-alert>
            @endif
        </x-ap-card>

        <div class="ap-log">
            <div class="ap-log-head"><span class="mono muted ap-log-title">{{ __('Output') }}</span></div>
            <pre class="ap-log-body ap-task-log" x-ref="log" tabindex="0" role="log" aria-live="polite" aria-label="{{ __('Job output') }}" x-text="log">{{ $job->log }}</pre>
        </div>
    </div>
</x-ap-page>
