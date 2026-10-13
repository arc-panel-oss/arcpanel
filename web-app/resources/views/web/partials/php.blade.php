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
    $sourceLabel = ['profile' => __('PHP-FPM profile'), 'php.ini' => 'php.ini'];
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
