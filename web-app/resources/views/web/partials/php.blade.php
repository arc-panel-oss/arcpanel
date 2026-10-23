{{-- PHP tab of a web domain (E41 spec §8): version and PHP-FPM profile within the package, and the effective PHP limits. --}}
@php
    $statusLabel = ['active' => __('Active support'), 'security' => __('Security fixes only'), 'eol' => __('End of life')];
    $versionOptions = [];
    foreach ($php['versions'] as $v => $state) {
        $versionOptions[$v] = 'PHP '.$v.(($state['status'] ?? '') === 'eol' ? ' · '.__('End of life') : '');
    }
    if ($php['version'] !== '' && ! isset($versionOptions[$php['version']])) {
        $versionOptions[$php['version']] = 'PHP '.$php['version'].' · '.__('Outside the package');
    }
    $pmLabel = ['ondemand' => __('On demand'), 'dynamic' => __('Dynamic'), 'static' => __('Static')];
    $profileOptions = [];
    foreach ($phpProfiles as $name => $profile) {
        $v = $profile['values'];
        $profileOptions[$name] = [
            'title' => $name,
            'icon' => 'cpu',
            'description' => $v['DESCRIPTION'],
            'meta' => sprintf(__('%1$s · up to %2$s processes · memory %3$s'), $pmLabel[$v['PM']] ?? $v['PM'], $v['MAX_CHILDREN'], $v['MEMORY_LIMIT'] !== '' ? $v['MEMORY_LIMIT'] : __('from php.ini')),
            'badge' => ! in_array($name, $php['profiles'], true) ? __('Outside the package') : null,
            'badgeTone' => 'warn',
        ];
    }
    $sourceLabel = ['profile' => __('PHP-FPM profile'), 'php.ini' => 'php.ini', 'domain' => __('This domain')];
    $directiveLabel = [
        'memory_limit' => __('Memory limit'), 'upload_max_filesize' => __('Maximum upload size'), 'post_max_size' => __('Maximum POST size'),
        'max_execution_time' => __('Maximum execution time'), 'max_input_vars' => __('Maximum input variables'),
    ];
    $canChange = $can['changePhp'];
@endphp
<x-ap-card :title="__('PHP')" icon="code">
    @if (! $php['allowed'])
        <x-ap-alert tone="warn" :title="__('Outside the package')">
            {{ __('The package of this user no longer offers the PHP version or the profile of this domain. They keep working, but once changed they cannot be chosen again.') }}
        </x-ap-alert>
    @endif
    @if ($php['version'] !== '' && ! $php['profileApplied'])
        <x-ap-alert tone="info" :title="__('Profile not applied')">
            {{ sprintf(__('The backend template %s has no profile line, so the pool keeps its own settings.'), $php['backend']) }}
        </x-ap-alert>
    @endif
    @if ($php['version'] === '')
        <p class="muted">{{ __('This domain runs without PHP (backend template no-php). Choose a version to turn PHP on.') }}</p>
    @endif
    <x-ap-form :action="route('web.domains.update.php', $params)">
        <x-ap-select name="php_version" :label="__('PHP version')" :options="$versionOptions" :value="$php['version']" bag="php"
            :placeholder="$php['version'] === '' ? __('Choose a version') : null"
            :hint="isset($php['versions'][$php['version']]['eol']) && $php['versions'][$php['version']]['eol'] !== '' ? sprintf(__('Supported until %s.'), $php['versions'][$php['version']]['eol']) : null"
            :disabled="! $canChange" />
        <x-ap-card-picker name="php_profile" :label="__('PHP-FPM profile')" :options="$profileOptions" :value="$php['profile']" bag="php" class="ap-php-profile-picker" />
        @if (count($profileOptions) === 1)
            <p class="ap-hint">{{ __('The package of this domain offers this profile only.') }}</p>
        @endif
        @if ($canChange)
            <div class="ap-form-actions"><x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button></div>
        @endif
    </x-ap-form>
</x-ap-card>

@if (($phpSettings ?? null) !== null)
    {{-- Per-domain PHP settings within the package maximums (E19 spec §6) --}}
    @php
        $canSettings = $can['changePhpSettings'] ?? false;
        $settingLabel = $directiveLabel + ['display_errors' => __('Display errors'), 'date.timezone' => __('Time zone')];
        $settingSource = ['profile' => __('the PHP-FPM profile'), 'php.ini' => 'php.ini', 'domain' => __('this domain'), 'capped' => __('the package maximum')];
    @endphp
    <x-ap-card :title="__('PHP settings')" icon="sliders">
        @if (! $phpSettings['supported'])
            <p class="muted">{{ __('This backend template takes no PHP settings.') }}</p>
        @else
            <p class="muted">{{ __('A value set here wins over the PHP-FPM profile and php.ini. Leave a field empty to use the inherited value.') }}</p>
            @if ($phpSettings['pool'] === 'user')
                <x-ap-alert tone="info">{{ __('These settings apply to every web domain of this user, which share one PHP-FPM pool.') }}</x-ap-alert>
            @endif
            @foreach ($phpSettings['warnings'] as $warning)
                <x-ap-alert tone="warn">{{ $warning }}</x-ap-alert>
            @endforeach
            <x-ap-form :action="route('web.domains.update.php-settings', $params)">
                <div class="ap-form-grid">
                    @foreach ($phpSettings['settings'] as $directive => $setting)
                        @php
                            $field = 'settings['.str_replace('.', '_', $directive).']';
                            $inherited = $setting['profile'] !== '' ? $setting['profile'] : $setting['ini'];
                            $hint = $directive.' · '.($setting['max'] !== '' ? sprintf(__('Maximum %s.'), $setting['max']).' ' : '')
                                .sprintf(__('Now %1$s from %2$s.'), $setting['effective'] !== '' ? $setting['effective'] : '—', $settingSource[$setting['source']] ?? $setting['source']);
                        @endphp
                        <div>
                            @if ($directive === 'display_errors')
                                <x-ap-select :name="$field" :label="$settingLabel[$directive] ?? $directive" :value="$setting['value']" bag="phpSettings" :hint="$hint"
                                    :options="['' => sprintf(__('Inherited (%s)'), $inherited !== '' ? $inherited : '—'), 'on' => __('On'), 'off' => __('Off')]" :disabled="! $canSettings" />
                            @else
                                <x-ap-input :name="$field" :label="$settingLabel[$directive] ?? $directive" :value="$setting['value']" :placeholder="$inherited" mono
                                    bag="phpSettings" :hint="$hint" :disabled="! $canSettings" />
                            @endif
                            @if ($setting['source'] === 'capped')
                                <x-ap-badge tone="warn">{{ __('Capped') }}</x-ap-badge>
                            @endif
                        </div>
                    @endforeach
                </div>
                @if ($canSettings)
                    <div class="ap-form-actions"><x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button></div>
                @endif
            </x-ap-form>
            @if ($canSettings)
                <x-ap-form :action="route('web.domains.reset.php-settings', $params)">
                    <x-ap-button type="submit" variant="ghost" icon="refresh">{{ __('Reset all') }}</x-ap-button>
                </x-ap-form>
            @endif
        @endif
    </x-ap-card>
@endif

@if ($php['effective'] !== [])
    <x-ap-card :title="__('Effective PHP limits')" icon="sliders">
        <p class="muted">{{ __('The profile of the pool wins over php.ini of the version.') }}</p>
        <dl class="ap-dl">
            @foreach ($php['effective'] as $directive => $value)
                <dt>{{ $directiveLabel[$directive] ?? $directive }} <span class="muted mono">{{ $directive }}</span></dt>
                <dd><span class="mono">{{ $value['value'] !== '' ? $value['value'] : '—' }}</span> <x-ap-badge>{{ $sourceLabel[$value['source']] ?? $value['source'] }}</x-ap-badge></dd>
            @endforeach
        </dl>
    </x-ap-card>
@endif
