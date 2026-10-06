{{-- Quick Install: the form of one application and the install task (E7 batch 2 spec §10.3). --}}
@php
    $edit = route('web.domains.edit', ['user' => $user, 'domain' => $domain]);
    $picker = route('web.domains.install', ['user' => $user, 'domain' => $domain]);
    $title = sprintf(__('Install %s'), $app['name']);
    $field = fn (string $key) => 'options['.$key.']';
@endphp
<x-ap-page :title="$title" :crumbs="[['label' => __('Web'), 'url' => route('web.domains.index')], ['label' => $domain, 'url' => $edit], ['label' => __('Quick Install App'), 'url' => $picker], ['label' => $app['name']]]"
    :description="sprintf(__('Version: %s'), $app['version'])">

    <div x-data="apTask({ url: {{ json_encode(route('web.domains.install.task', ['user' => $user, 'domain' => $domain])) }}, task: {{ json_encode($task) }},
        doneMessage: {{ json_encode(sprintf(__('%s installed successfully.'), $app['name'])) }}, failMessage: {{ json_encode(sprintf(__('Unable to install %s.'), $app['name'])) }} })"
        class="ap-stack">

        <template x-if="task && task.status">
            <x-ap-card :title="__('Installation')" icon="activity">
                <x-slot:actions>
                    <span x-show="running" class="ap-row"><span class="ap-spinner" aria-hidden="true"></span>{{ __('Running') }}</span>
                    <x-ap-badge tone="ok" x-show="task.status === 'done'">{{ __('Done') }}</x-ap-badge>
                    <x-ap-badge tone="danger" x-show="task.status === 'failed'">{{ __('Failed') }}</x-ap-badge>
                </x-slot:actions>
                <p x-show="task.status === 'done'" class="ap-row">
                    <a class="ap-btn ap-btn-primary" href="http://{{ $domain }}/" target="_blank" rel="noopener"><x-ap-icon name="external" />{{ sprintf(__('Open %s'), $domain) }}</a>
                </p>
                <div class="ap-log"><pre class="ap-log-body ap-task-log" tabindex="0" role="log" aria-live="polite" aria-label="{{ __('Installation output') }}" x-text="task.log"></pre></div>
            </x-ap-card>
        </template>

        @unless ($form['rootClean'])
            <x-ap-alert tone="warn" :title="__('Data Loss Warning!')">
                {{ __('Your web folder already has files uploaded to it. The installer will overwrite your files and/or the installation might fail.') }}
                {{ sprintf(__('Please make sure ~/web/%s/public_html is empty!'), $domain) }}
            </x-ap-alert>
        @endunless

        <x-ap-card :title="$app['name']" icon="package">
            <x-ap-form :action="route('web.domains.install.start', ['user' => $user, 'domain' => $domain, 'app' => $app['name']])">
                @foreach ($form['fields'] as $item)
                    @switch($item['type'])
                        @case('password')
                            <x-ap-password :name="$field($item['key'])" :id="'qi-'.$item['key']" :label="__($item['label'])" :required="$item['required']" :generate="true"
                                :hint="$item['placeholder'] === 'auto' ? __('Leave empty to generate one') : null" />
                            @break
                        @case('boolean')
                            <x-ap-checkbox :name="$field($item['key'])" :id="'qi-'.$item['key']" :label="__($item['label'])" value="true" :checked="$item['value'] !== ''" />
                            @break
                        @case('select')
                            <x-ap-select :name="$field($item['key'])" :id="'qi-'.$item['key']" :label="__($item['label'])" :options="$item['options']" :value="$item['value']" :required="$item['required']" />
                            @break
                        @default
                            <x-ap-input :name="$field($item['key'])" :id="'qi-'.$item['key']" :label="__($item['label'])" :value="$item['value']"
                                :placeholder="$item['placeholder'] !== '' ? $item['placeholder'] : null" :required="$item['required']" autocomplete="off" />
                    @endswitch
                @endforeach

                <div class="ap-form-actions">
                    <x-ap-button type="submit" variant="primary" icon="download" x-bind:disabled="running" :disabled="$busy">{{ $title }}</x-ap-button>
                    <x-ap-button :href="$picker" variant="ghost">{{ __('Cancel') }}</x-ap-button>
                </div>
            </x-ap-form>
        </x-ap-card>
    </div>
</x-ap-page>
