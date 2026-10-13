{{-- Server settings: Web Server and PHP versions (web/edit/server, E7 batch 3 spec §8.2). --}}
@php
    $installed = array_keys(array_filter($php, fn ($v) => $v['installed']));
    $default = array_keys(array_filter($php, fn ($v) => $v['default']))[0] ?? '';
    $fpm = ($config['WEB_BACKEND'] ?? '') === 'php-fpm';
    $systems = array_filter([
        'WEB_SYSTEM' => __('Web Server'), 'PROXY_SYSTEM' => __('Proxy Server'), 'WEB_BACKEND' => __('Backend Server'),
    ], fn ($label, $key) => ($config[$key] ?? '') !== '', ARRAY_FILTER_USE_BOTH);
    $editor = fn (string $service) => in_array($service, \App\Services\Server\ServiceConfigs::NAMES, true) && Route::has('server.services.config')
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

        <x-ap-card :title="__('PHP Versions')" icon="layers">
            <p class="muted">{{ __('Install and remove PHP versions, their extensions and php.ini, and the PHP-FPM profiles on the PHP page.') }}</p>
            <div class="ap-form-actions"><x-ap-button :href="route('server.php.index')" icon="code">{{ __('Open the PHP page') }}</x-ap-button></div>
        </x-ap-card>
    @endif
</x-ap-page>
