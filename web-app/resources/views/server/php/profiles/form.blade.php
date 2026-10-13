{{-- Add or edit a PHP-FPM profile (E41 spec §7.1); the pool fields follow the process manager. --}}
@php
    $editing = $profile !== null;
    $canManage ??= true;
    $title = $editing ? sprintf(__('Profile %s'), $profile['name']) : __('Add profile');
    $value = fn (string $key) => old(strtolower($key), $values[$key] ?? '');
@endphp
<x-ap-page :title="$title" :crumbs="[['label' => __('Server')], ['label' => __('PHP'), 'url' => route('server.php.index')], ['label' => __('PHP-FPM profiles'), 'url' => route('server.php.profiles.index')], ['label' => $editing ? $profile['name'] : __('Add profile')]]">
    @if ($editing && $profile['domains'] > 0)
        <x-ap-alert tone="info">{{ trans_choice('Saving rewrites the pool of :count web domain and reloads PHP-FPM.|Saving rewrites the pools of :count web domains and reloads PHP-FPM.', $profile['domains'], ['count' => $profile['domains']]) }}</x-ap-alert>
    @endif
    <x-ap-form :action="$editing ? route('server.php.profiles.update', ['profile' => $profile['name']]) : route('server.php.profiles.store')" :method="$editing ? 'PUT' : 'POST'" focus-first>
        <div x-data="apPhpProfile({ pm: @js($value('PM')) })" class="ap-stack">
            <x-ap-card :title="__('Profile')" icon="sliders">
                <div class="ap-form-grid">
                    @unless ($editing)
                        <x-ap-input name="name" :label="__('Name')" :value="old('name')" mono required :hint="__('Lower-case letters, digits and -, up to 32 characters.')" />
                    @endunless
                    <x-ap-input name="description" :label="__('Description')" :value="$value('DESCRIPTION')" />
                </div>
            </x-ap-card>

            <x-ap-card :title="__('Process manager')" icon="activity">
                <div class="ap-form-grid">
                    <x-ap-select name="pm" :label="__('Process manager')" :value="$value('PM')" x-model="pm"
                        :options="['ondemand' => __('On demand: start processes when requests come'), 'dynamic' => __('Dynamic: keep spare processes ready'), 'static' => __('Static: a fixed number of processes')]" />
                    <x-ap-input name="max_children" type="number" :label="__('Maximum processes')" :value="$value('MAX_CHILDREN')" min="1" max="500" mono required :hint="__('pm.max_children, 1 to 500.')" />
                    <x-ap-input name="max_requests" type="number" :label="__('Requests per process')" :value="$value('MAX_REQUESTS')" min="0" max="100000" mono required :hint="__('pm.max_requests; 0 never recycles a process.')" />
                    <x-ap-input name="request_terminate_timeout" :label="__('Request timeout')" :value="$value('REQUEST_TERMINATE_TIMEOUT')" mono :hint="__('request_terminate_timeout, for example 300s; empty for none.')" />
                </div>
                <div class="ap-form-grid" x-show="pm === 'dynamic'" data-pm="dynamic">
                    <x-ap-input name="start_servers" type="number" :label="__('Processes at start')" :value="$value('START_SERVERS')" min="1" max="500" mono x-bind:disabled="pm !== 'dynamic'" />
                    <x-ap-input name="min_spare" type="number" :label="__('Minimum spare processes')" :value="$value('MIN_SPARE')" min="1" max="500" mono x-bind:disabled="pm !== 'dynamic'" />
                    <x-ap-input name="max_spare" type="number" :label="__('Maximum spare processes')" :value="$value('MAX_SPARE')" min="1" max="500" mono x-bind:disabled="pm !== 'dynamic'"
                        :hint="__('Minimum spare ≤ at start ≤ maximum spare ≤ maximum processes.')" />
                </div>
                <div class="ap-form-grid" x-show="pm === 'ondemand'" data-pm="ondemand">
                    <x-ap-input name="idle_timeout" :label="__('Idle timeout')" :value="$value('IDLE_TIMEOUT') ?: '10s'" mono x-bind:disabled="pm !== 'ondemand'"
                        :hint="__('pm.process_idle_timeout, for example 10s.')" />
                </div>
            </x-ap-card>

            <x-ap-card :title="__('PHP limits')" icon="hexagon">
                <p class="muted">{{ __('Written as php_admin_value lines of the pool: sites cannot raise them. Empty takes the value of php.ini.') }}</p>
                <div class="ap-form-grid">
                    <x-ap-input name="memory_limit" :label="__('Memory limit')" :value="$value('MEMORY_LIMIT')" mono :hint="__('For example 256M; -1 for no limit.')" />
                    <x-ap-input name="upload_max_filesize" :label="__('Maximum upload size')" :value="$value('UPLOAD_MAX_FILESIZE')" mono />
                    <x-ap-input name="post_max_size" :label="__('Maximum POST size')" :value="$value('POST_MAX_SIZE')" mono :hint="__('At least the upload size.')" />
                    <x-ap-input name="max_execution_time" type="number" :label="__('Maximum execution time')" :value="$value('MAX_EXECUTION_TIME')" min="0" mono :hint="__('Seconds.')" />
                    <x-ap-input name="max_input_vars" type="number" :label="__('Maximum input variables')" :value="$value('MAX_INPUT_VARS')" min="1" mono />
                </div>
            </x-ap-card>
        </div>
        @if ($canManage)
            <div class="ap-form-actions">
                <x-ap-button :href="route('server.php.profiles.index')">{{ __('Cancel') }}</x-ap-button>
                <x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button>
            </div>
        @endif
    </x-ap-form>
</x-ap-page>
