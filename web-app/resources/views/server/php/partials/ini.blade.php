{{-- The php.ini tab of a PHP version (E41 spec §6, §8): the form, the file editor and the backups. --}}
@php
    $labels = [
        'memory_limit' => __('Memory limit'), 'max_execution_time' => __('Maximum execution time'), 'max_input_time' => __('Maximum input time'),
        'max_input_vars' => __('Maximum input variables'), 'upload_max_filesize' => __('Maximum upload size'), 'post_max_size' => __('Maximum POST size'),
        'max_file_uploads' => __('Maximum file uploads'), 'display_errors' => __('Display errors'), 'error_reporting' => __('Error reporting'),
        'log_errors' => __('Log errors'), 'date.timezone' => __('Time zone'), 'default_socket_timeout' => __('Socket timeout'),
        'allow_url_fopen' => __('Open URLs as files'), 'short_open_tag' => __('Short open tags'), 'cgi.fix_pathinfo' => __('Fix PATH_INFO'),
        'session.gc_maxlifetime' => __('Session lifetime'), 'opcache.enable' => __('OPcache'), 'opcache.memory_consumption' => __('OPcache memory (MB)'),
        'opcache.max_accelerated_files' => __('OPcache files'), 'opcache.validate_timestamps' => __('OPcache checks file changes'),
        'opcache.revalidate_freq' => __('OPcache check interval (s)'), 'disable_functions' => __('Disabled functions'),
    ];
    $booleans = \App\Services\Php\PhpManager::BOOLEAN_DIRECTIVES;
    $field = fn (string $d) => \App\Http\Controllers\Server\PhpController::field($d);
    $hint = function (string $directive) use ($ini, $sapi) {
        $v = $ini['values'][$directive];
        $parts = [$v['source'] === 'file' ? sprintf(__('Line %s of the file'), $v['line']) : __('Not set in the file: PHP default')];
        if ($sapi === 'fpm' && $v['overrides'] > 0) {
            $parts[] = trans_choice(':count web domain overrides it through its PHP-FPM profile|:count web domains override it through their PHP-FPM profile', $v['overrides'], ['count' => $v['overrides']]);
        }

        return implode(' · ', $parts);
    };
    $disabled = array_values(array_filter(array_map('trim', explode(',', $ini['values']['disable_functions']['value']))));
@endphp

<x-ap-card :title="__('Settings')" icon="sliders">
    <x-slot:actions>
        <x-ap-button icon="history" x-data x-on:click="$dispatch('ap-modal', { id: 'php-ini-backups' })">{{ sprintf(__('Backups (%d)'), count($backups)) }}</x-ap-button>
    </x-slot:actions>
    <p class="muted mono">{{ $ini['file'] }} · {{ sprintf(__('%s bytes'), number_format($ini['size'])) }} · {{ $ini['mtime'] }}</p>
    @php
        // The form directives by topic; a directive missing here goes to Other
        $sections = [
            __('Resource limits') => ['memory_limit', 'max_execution_time', 'max_input_time', 'max_input_vars', 'upload_max_filesize', 'post_max_size', 'max_file_uploads'],
            __('Errors') => ['display_errors', 'log_errors', 'error_reporting'],
            __('OPcache') => ['opcache.enable', 'opcache.validate_timestamps', 'opcache.memory_consumption', 'opcache.max_accelerated_files', 'opcache.revalidate_freq'],
        ];
        $grouped = array_merge(...array_values($sections));
        $sections[__('Other')] = array_values(array_filter(\App\Services\Php\PhpManager::FORM_DIRECTIVES, fn ($d) => ! in_array($d, $grouped, true) && $d !== 'disable_functions'));
    @endphp
    <x-ap-form :action="route('server.php.ini.values', ['version' => $version, 'sapi' => $sapi])">
        @foreach ($sections as $sectionLabel => $directives)
            @php($directives = array_values(array_filter($directives, fn ($d) => isset($ini['values'][$d]))))
            @continue($directives === [])
            <div class="ap-ini-section">
                <h3 class="ap-ini-section-title">{{ $sectionLabel }}</h3>
                @php($switches = array_values(array_filter($directives, fn ($d) => in_array($d, $booleans, true))))
                @if ($switches !== [])
                    <div class="ap-settings">
                        @foreach ($switches as $directive)
                            <x-ap-switch :name="$field($directive)" :label="$labels[$directive]" :description="$directive.' · '.$hint($directive)"
                                :checked="\App\Http\Controllers\Server\PhpController::truthy($ini['values'][$directive]['value'])" :disabled="! $canManage" />
                        @endforeach
                    </div>
                @endif
                @php($inputs = array_values(array_diff($directives, $switches)))
                @if ($inputs !== [])
                    <div class="ap-form-grid">
                        @foreach ($inputs as $directive)
                            <x-ap-input :name="$field($directive)" :label="$labels[$directive]" :value="old($field($directive), $ini['values'][$directive]['value'])"
                                :hint="$directive.' · '.$hint($directive)" mono :disabled="! $canManage" />
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach
        <div class="ap-field" x-data="apNameList({ items: @js($disabled) })">
            <input type="hidden" name="disable_functions_editor" value="1">
            <span class="ap-label">{{ $labels['disable_functions'] }} <span class="muted mono">disable_functions</span></span>
            <ul class="ap-chips" role="list">
                <template x-for="item in items" :key="item">
                    <li class="ap-chip">
                        <span class="mono" x-text="item"></span>
                        <input type="hidden" name="disable_functions_list[]" x-bind:value="item">
                        @if ($canManage)
                            <button type="button" class="ap-chip-remove" x-on:click="remove(item)" x-bind:aria-label="@js(__('Remove')) + ' ' + item">×</button>
                        @endif
                    </li>
                </template>
            </ul>
            @if ($canManage)
                <div class="ap-row">
                    <input class="ap-input ap-input-mono" type="text" x-model="draft" x-on:keydown.enter.prevent="add()"
                        placeholder="exec" aria-label="{{ __('Function to disable') }}">
                    <x-ap-button size="sm" icon="plus" x-on:click="add()">{{ __('Add') }}</x-ap-button>
                </div>
            @endif
            <small class="ap-hint">{{ $hint('disable_functions') }}</small>
        </div>
        @if ($canManage)
            <div class="ap-form-actions"><x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button></div>
        @endif
    </x-ap-form>
</x-ap-card>

<x-ap-card :title="__('Edit file')" icon="file-code">
    <p class="muted">{{ __('Validate checks the file with this version of PHP. Saving keeps a backup and restores it when PHP-FPM does not start.') }}</p>
    <x-ap-island name="php-ini-editor" :props="$editor" class="ap-php-ini-editor">
        <x-ap-skeleton />
    </x-ap-island>
</x-ap-card>

<x-ap-drawer id="php-ini-backups" :title="__('Backups')" :description="__('The ten newest versions of this file. Restoring one keeps the current file as a backup.')">
    @forelse ($backups as $backup)
        <div class="ap-row ap-php-backup" data-backup="{{ $backup['id'] }}">
            <span class="mono">{{ $backup['date'] }}</span>
            <span class="muted mono">{{ sprintf(__('%s bytes'), number_format($backup['size'])) }}</span>
            @if ($canManage)
                <x-ap-button size="sm" icon="return" :form-action="route('server.php.ini.restore', ['version' => $version, 'sapi' => $sapi, 'backup' => $backup['id']])"
                    :confirm="sprintf(__('Restore the php.ini of %s?'), $backup['date'])">{{ __('Restore') }}</x-ap-button>
            @endif
        </div>
    @empty
        <x-ap-empty icon="history" :title="__('No backups yet')">{{ __('A backup is kept each time the file is saved.') }}</x-ap-empty>
    @endforelse
</x-ap-drawer>
