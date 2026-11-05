{{--
    The system task (E7 batch 3 spec §6.2), since E15 a sys.* job: status,
    log and end message, polled by apTask on the job's status route through
    a panel restart, with a link to the Job page. The slot can read `running`
    (e.g. to disable the buttons that start another task).
--}}
@props(['task', 'title', 'doneMessage', 'failMessage', 'reload' => true])
@php
    $jobId = preg_match('/^\d{14}$/', (string) ($task['id'] ?? '')) === 1 ? $task['id'] : null;
@endphp
<div {{ $attributes->merge(['class' => 'ap-stack']) }}
    x-data="apTask({ url: @js($jobId ? route('jobs.status', ['id' => $jobId]) : ''), task: @js($task), doneMessage: @js($doneMessage), failMessage: @js($failMessage), reloadOnEnd: @js((bool) $reload) })">
    <template x-if="task && running">
        <x-ap-card :title="$title" icon="activity">
            <x-slot:actions>
                <span class="ap-row"><span class="ap-spinner" aria-hidden="true"></span>{{ __('Running') }}</span>
            </x-slot:actions>
            <p class="muted mono"><span x-text="task.kind + ' ' + task.args"></span>@if ($jobId) · <a href="{{ route('jobs.show', ['id' => $jobId]) }}">{{ __('Job page') }}</a>@endif</p>
            <div class="ap-log"><pre class="ap-log-body ap-task-log" tabindex="0" role="log" aria-live="polite" aria-label="{{ __('Task output') }}" x-text="task.log"></pre></div>
        </x-ap-card>
    </template>
    <template x-if="task && task.status && !running">
        <details class="ap-card ap-task-last">
            <summary class="ap-card-head">
                <span class="ap-card-title"><x-ap-icon name="activity" /><span>{{ __('Last task') }}</span></span>
                <span class="muted mono" x-text="task.kind + ' ' + task.args + ' · ' + task.end"></span>
                <x-ap-badge tone="ok" x-show="succeeded">{{ __('Done') }}</x-ap-badge>
                <x-ap-badge tone="danger" x-show="task.status === 'failed'">{{ __('Failed') }}</x-ap-badge>
                <x-ap-badge x-show="task.status === 'cancelled'">{{ __('Cancelled') }}</x-ap-badge>
            </summary>
            <div class="ap-log"><pre class="ap-log-body ap-task-log" tabindex="0" aria-label="{{ __('Task output') }}" x-text="task.log"></pre></div>
        </details>
    </template>
    {{ $slot }}
</div>
