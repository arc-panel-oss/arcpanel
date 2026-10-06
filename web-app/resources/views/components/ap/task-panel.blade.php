{{--
    The system task (E7 batch 3 spec §6.2): status, log and end message,
    polled by apTask through a panel restart. The slot can read `running`
    (e.g. to disable the buttons that start another task).
--}}
@props(['task', 'title', 'doneMessage', 'failMessage', 'reload' => true])
<div {{ $attributes->merge(['class' => 'ap-stack']) }}
    x-data="apTask({ url: @js(route('server.task')), task: @js($task), doneMessage: @js($doneMessage), failMessage: @js($failMessage), reloadOnEnd: @js((bool) $reload) })">
    <template x-if="task && task.status === 'running'">
        <x-ap-card :title="$title" icon="activity">
            <x-slot:actions>
                <span class="ap-row"><span class="ap-spinner" aria-hidden="true"></span>{{ __('Running') }}</span>
            </x-slot:actions>
            <p class="muted mono" x-text="task.kind + ' ' + task.args"></p>
            <div class="ap-log"><pre class="ap-log-body ap-task-log" tabindex="0" role="log" aria-live="polite" aria-label="{{ __('Task output') }}" x-text="task.log"></pre></div>
        </x-ap-card>
    </template>
    <template x-if="task && (task.status === 'done' || task.status === 'failed')">
        <details class="ap-card ap-task-last">
            <summary class="ap-card-head">
                <span class="ap-card-title"><x-ap-icon name="activity" /><span>{{ __('Last task') }}</span></span>
                <span class="muted mono" x-text="task.kind + ' ' + task.args + ' · ' + task.end"></span>
                <x-ap-badge tone="ok" x-show="task.status === 'done'">{{ __('Done') }}</x-ap-badge>
                <x-ap-badge tone="danger" x-show="task.status === 'failed'">{{ __('Failed') }}</x-ap-badge>
            </summary>
            <div class="ap-log"><pre class="ap-log-body ap-task-log" tabindex="0" aria-label="{{ __('Task output') }}" x-text="task.log"></pre></div>
        </details>
    </template>
    {{ $slot }}
</div>
