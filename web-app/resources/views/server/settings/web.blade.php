{{-- Server settings: Web Server and PHP versions (web/edit/server, E7 batch 3 spec §8.2). --}}
@php
    $installed = array_keys(array_filter($php, fn ($v) => $v['installed']));
    $default = array_keys(array_filter($php, fn ($v) => $v['default']))[0] ?? '';
    $fpm = ($config['WEB_BACKEND'] ?? '') === 'php-fpm';
    $systems = array_filter([
        'WEB_SYSTEM' => __('Web Server'), 'PROXY_SYSTEM' => __('Proxy Server'), 'WEB_BACKEND' => __('Backend Server'),
    ], fn ($label, $key) => ($config[$key] ?? '') !== '', ARRAY_FILTER_USE_BOTH);
    $editor = fn (string $service) => in_array($service, \App\Services\Server\ServiceConfigs::EDITORS, true) && Route::has('server.services.config')
        ? route('server.services.config', ['service' => $service]) : null;
@endphp
<x-ap-page :title="__('Server settings')" :crumbs="[['label' => __('Server')], ['label' => __('Server settings')], ['label' => __('Web Server')]]">
    @include('server.settings.partials.nav')

    <x-ap-card :title="__('Web Server')" icon="globe">
        <dl class="ap-dl">
            @foreach ($systems as $key => $label)
                @php($service = $key === 'WEB_BACKEND' ? 'php' : $config[$key])
                <dt>{{ $label }}</dt>
                <dd class="mono">
                    @if ($url = $editor($service))
                        <a href="{{ $url }}">{{ $config[$key] }}</a>
                    @else
                        {{ $config[$key] }}
                    @endif
                </dd>
            @endforeach
        </dl>
    </x-ap-card>

    @if ($fpm)
        <x-ap-card :title="__('Default PHP Version')" icon="hexagon">
            <x-ap-form :action="route('server.settings.update.web')">
                <x-ap-select name="php_default" :label="__('Default PHP Version')" :options="array_combine($installed, $installed)" :value="$default"
                    :hint="__('Version of the php command and of web domains on the default backend template.')" :disabled="! $canEdit" />
                @if ($canEdit)
                    <div class="ap-form-actions"><x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button></div>
                @endif
            </x-ap-form>
        </x-ap-card>

        <x-ap-task-panel :task="$task" :title="__('Changing PHP versions')"
            :done-message="__('The PHP versions have been changed.')" :fail-message="__('Changing the PHP versions failed.')">
            <x-ap-card :title="__('PHP Versions')" icon="layers">
                <x-ap-form :action="route('server.settings.php')">
                    <p class="muted">{{ __('Installing or removing a version can take a few minutes. It runs in the background.') }}</p>
                    <div class="ap-check-grid">
                        @foreach ($php as $version => $state)
                            @php($locked = $state['installed'] && ($state['default'] || $state['usedBy'] !== []))
                            @php($reason = $state['default'] ? __('Default version') : ($state['usedBy'] !== [] ? sprintf(__('Used by %s'), implode(', ', array_slice($state['usedBy'], 0, 5)).(count($state['usedBy']) > 5 ? ' …' : '')) : null))
                            @if ($locked)
                                <input type="hidden" name="versions[]" value="{{ $version }}">
                            @endif
                            <x-ap-checkbox name="versions[]" :id="'php-'.str_replace('.', '-', $version)" :value="$version" :label="'PHP '.$version"
                                :checked="$state['installed']" :hint="$reason" x-bind:disabled="running || {{ $locked || ! $canEdit ? 'true' : 'false' }}" />
                        @endforeach
                    </div>
                    @if ($canEdit)
                        <div class="ap-form-actions">
                            <x-ap-button type="submit" variant="primary" icon="check" x-bind:disabled="running">{{ __('Apply PHP versions') }}</x-ap-button>
                        </div>
                    @endif
                </x-ap-form>
            </x-ap-card>
        </x-ap-task-panel>
    @endif
</x-ap-page>
