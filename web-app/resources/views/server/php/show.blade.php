{{-- A PHP version (E41 spec 2026-10-12-001 §8): overview, extensions and the php.ini of PHP-FPM and of the command line. --}}
@php
    $labels = ['overview' => __('Overview'), 'extensions' => __('Extensions'), 'ini-fpm' => __('php.ini (PHP-FPM)'), 'ini-cli' => __('php.ini (command line)')];
    $statusTone = ['active' => 'ok', 'security' => 'warn', 'eol' => 'danger'];
    $statusLabel = ['active' => __('Active support'), 'security' => __('Security fixes only'), 'eol' => __('End of life'), 'unknown' => __('Unknown')];
@endphp
<x-ap-page :title="'PHP '.$version" :crumbs="[['label' => __('Server')], ['label' => __('PHP'), 'url' => route('server.php.index')], ['label' => 'PHP '.$version]]">
    <x-slot:head>
        <div class="ap-entity">
            <span class="ap-entity-icon"><x-ap-icon name="code" /></span>
            <div class="ap-entity-text">
                <span class="eyebrow">{{ __('PHP version') }}</span>
                <h1 class="mono">PHP {{ $version }}</h1>
                <div class="ap-row">
                    <x-ap-badge :tone="$statusTone[$state['status']] ?? null">{{ $statusLabel[$state['status']] ?? $state['status'] }}</x-ap-badge>
                    @if ($state['eol'] !== '')
                        <x-ap-badge>{{ sprintf(__('End of life: %s'), $state['eol']) }}</x-ap-badge>
                    @endif
                    @if ($state['default'])
                        <x-ap-badge tone="info">{{ __('Default') }}</x-ap-badge>
                    @endif
                </div>
            </div>
        </div>
    </x-slot:head>

    <nav class="ap-tabs" aria-label="{{ sprintf(__('PHP %s'), $version) }}">
        @foreach ($labels as $name => $label)
            <a class="ap-tab" href="{{ route('server.php.show', ['version' => $version, 'tab' => $name]) }}" @if ($name === $tab) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>

    @if ($tab === 'overview')
        <x-ap-card :title="__('Overview')" icon="info">
            <dl class="ap-dl">
                <dt>{{ __('PHP-FPM service') }}</dt>
                <dd>
                    <span class="mono">php{{ $version }}-fpm</span>
                    @if ($service === 'running')
                        <x-ap-badge tone="ok">{{ __('Running') }}</x-ap-badge>
                    @elseif ($service === 'stopped')
                        <x-ap-badge tone="danger">{{ __('Stopped') }}</x-ap-badge>
                    @endif
                </dd>
                <dt>{{ __('php.ini (PHP-FPM)') }}</dt><dd class="mono">/etc/php/{{ $version }}/fpm/php.ini</dd>
                <dt>{{ __('php.ini (command line)') }}</dt><dd class="mono">/etc/php/{{ $version }}/cli/php.ini</dd>
                <dt>{{ __('Pools') }}</dt><dd class="mono">/etc/php/{{ $version }}/fpm/pool.d/</dd>
                <dt>{{ __('Command') }}</dt><dd class="mono">/usr/bin/php{{ $version }}</dd>
                <dt>{{ __('Released') }}</dt><dd class="mono">{{ $state['released'] ?: '—' }}</dd>
                <dt>{{ __('Active support until') }}</dt><dd class="mono">{{ $state['activeUntil'] ?: '—' }}</dd>
                <dt>{{ __('Web domains') }}</dt><dd>{{ $state['usedBy'] !== [] ? implode(', ', $state['usedBy']) : __('None') }}</dd>
            </dl>
            @if ($canManage)
                <div class="ap-form-actions">
                    <x-ap-button :form-action="route('server.php.reload', ['version' => $version])" icon="rotate">{{ __('Reload PHP-FPM') }}</x-ap-button>
                </div>
            @endif
        </x-ap-card>
    @elseif ($tab === 'extensions')
        @php($groupLabels = [
            'cache' => __('Caching'), 'database' => __('Databases'), 'image' => __('Images'), 'format' => __('Formats and text'),
            'network' => __('Network'), 'security' => __('Security'), 'async' => __('Queues and async'), 'debug' => __('Debugging'), 'other' => __('Other'),
        ])
        <x-ap-task-panel :task="$task" :title="__('Changing extensions')"
            :done-message="__('The task has finished.')" :fail-message="__('The task failed.')">
            @foreach ($groups as $group => $extensions)
                <x-ap-card :title="$groupLabels[$group] ?? $group" icon="package" flush>
                    <ul class="ap-list ap-php-extensions" role="list">
                        @foreach ($extensions as $ext)
                            @php($toggle = route('server.php.extensions.toggle', ['version' => $version, 'name' => $ext['name']]))
                            <li class="ap-php-extension" data-extension="{{ $ext['name'] }}"
                                x-data="apPhpExtension({ url: @js($toggle), fpm: @js($ext['fpm']), cli: @js($ext['cli']) })">
                                <div class="ap-php-extension-name">
                                    <b class="mono">{{ $ext['name'] }}</b>
                                    @if ($ext['required'])
                                        <x-ap-badge tone="info">{{ __('Required') }}</x-ap-badge>
                                    @endif
                                    @if (! $ext['catalog'])
                                        <x-ap-badge>{{ __('Not managed by the panel') }}</x-ap-badge>
                                    @elseif ($ext['installed'])
                                        <x-ap-badge tone="ok">{{ __('Installed') }}</x-ap-badge>
                                    @elseif (! $ext['available'])
                                        <x-ap-badge>{{ __('Not available for this version') }}</x-ap-badge>
                                    @endif
                                    @if ($ext['note'] !== '')
                                        <small class="muted">{{ $ext['note'] }}</small>
                                    @endif
                                    @if ($ext['conflicts'] !== [])
                                        <small class="muted">{{ sprintf(__('Cannot be enabled together with %s'), implode(', ', $ext['conflicts'])) }}</small>
                                    @endif
                                </div>
                                @if ($ext['installed'])
                                    <div class="ap-row ap-php-extension-sapis">
                                        @foreach (['fpm' => 'PHP-FPM', 'cli' => __('CLI')] as $sapi => $sapiLabel)
                                            <label class="ap-check">
                                                <input type="checkbox" x-model="{{ $sapi }}" x-on:change="toggle('{{ $sapi }}')"
                                                    @disabled(! $canManage || ! $ext['catalog'] || ($ext['required'] && $ext[$sapi]))
                                                    x-bind:disabled="busy || {{ ! $canManage || ! $ext['catalog'] || ($ext['required'] && $ext[$sapi]) ? 'true' : 'false' }}"
                                                    aria-label="{{ sprintf(__('%1$s for %2$s'), $ext['name'], $sapiLabel) }}">
                                                <span>{{ $sapiLabel }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                @endif
                                @if ($canManage && $ext['catalog'] && ! $ext['toggleOnly'])
                                    <div class="ap-row">
                                        @if (! $ext['installed'] && $ext['available'])
                                            <form method="post" action="{{ route('server.php.extensions', ['version' => $version]) }}"
                                                data-confirm="{{ sprintf(__('Install %1$s for PHP %2$s?'), $ext['name'], $version) }}">
                                                @csrf
                                                <input type="hidden" name="action" value="install">
                                                <input type="hidden" name="name" value="{{ $ext['name'] }}">
                                                <x-ap-button type="submit" size="sm" variant="primary" icon="download" x-bind:disabled="running">{{ __('Install') }}</x-ap-button>
                                            </form>
                                        @elseif ($ext['installed'] && ! $ext['required'])
                                            <form method="post" action="{{ route('server.php.extensions', ['version' => $version]) }}"
                                                data-confirm="{{ sprintf(__('Remove %1$s from PHP %2$s?'), $ext['name'], $version) }}" data-confirm-danger>
                                                @csrf
                                                <input type="hidden" name="action" value="remove">
                                                <input type="hidden" name="name" value="{{ $ext['name'] }}">
                                                <x-ap-button type="submit" size="sm" icon="trash" x-bind:disabled="running">{{ __('Remove') }}</x-ap-button>
                                            </form>
                                        @endif
                                    </div>
                                @endif
                                <p class="ap-error" role="alert" x-show="error" x-text="error" x-cloak></p>
                            </li>
                        @endforeach
                    </ul>
                </x-ap-card>
            @endforeach
        </x-ap-task-panel>
    @else
        @include('server.php.partials.ini')
    @endif
</x-ap-page>
