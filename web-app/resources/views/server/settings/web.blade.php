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
    // Git Deploy (E20 spec §3.4): null when the server cannot list its settings
    $deploy ??= null;
    $deployIssuers = [];
    foreach ($deploy !== null ? \App\Services\Server\DeploySettings::issuers($deploy['DEPLOY_OIDC_ISSUERS']) : [] as $issuer) {
        $deployIssuers[$issuer] = [
            'label' => \App\Services\Deploy\DeployService::issuerLabel($issuer),
            'builtIn' => \App\Services\Server\DeploySettings::builtIn($issuer),
        ];
    }
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

    @if ($deploy !== null)
        {{-- Git Deploy (E20 spec §3.4, §7) --}}
        <x-ap-card :title="__('Git Deploy')" icon="git">
            <x-slot:actions>
                @if ($deploy['DEPLOY_PORT'] === '')
                    <x-ap-badge tone="warn">{{ __('Endpoint turned off') }}</x-ap-badge>
                @elseif ($deploy['PORT_OPEN'] === 'yes')
                    <x-ap-badge tone="ok">{{ sprintf(__('Port %s open'), $deploy['DEPLOY_PORT']) }} · {{ trans_choice(':count domain|:count domains', (int) $deploy['DOMAINS'], ['count' => (int) $deploy['DOMAINS']]) }}</x-ap-badge>
                @else
                    <x-ap-badge>{{ sprintf(__('Port %s closed'), $deploy['DEPLOY_PORT']) }} · {{ (int) $deploy['DOMAINS'] === 0 ? __('no domain uses Git Deploy') : trans_choice(':count domain|:count domains', (int) $deploy['DOMAINS'], ['count' => (int) $deploy['DOMAINS']]) }}</x-ap-badge>
                @endif
            </x-slot:actions>
            <p class="muted">{{ __('Webhooks and CI uploads reach this server on their own port, so the panel port can stay closed to the internet.') }}</p>
            <x-ap-form :action="route('server.settings.deploy')">
                <div class="ap-form-grid">
                    <x-ap-input name="port" :label="__('Deploy port')" type="number" :value="$deploy['DEPLOY_PORT']"
                        :hint="__('Opened in the firewall only while a domain uses Git Deploy. It answers webhooks and uploads, nothing else. Leave it empty to turn the endpoint off.')" />
                    <x-ap-input name="max_artifact" :label="__('Largest upload')" :value="$deploy['DEPLOY_MAX_ARTIFACT']" mono
                        :hint="__('Size of the tar.gz a CI may upload (push deploys), from 1M to 1024M.')" />
                    <x-ap-input name="timeout" :label="__('Build time limit')" type="number" :value="\App\Services\Server\DeploySettings::minutes($deploy['DEPLOY_BUILD_TIMEOUT'])" :suffix="__('min')"
                        :hint="__('A build that runs longer is stopped; the live release stays.')" />
                </div>
                <fieldset class="ap-field ap-fieldset">
                    <legend class="ap-label">{{ __('CI providers that may deploy') }}</legend>
                    <ul class="ap-list">
                        @foreach ($deployIssuers as $issuer => $item)
                            <li>
                                <span class="ap-list-icon"><x-ap-icon :name="$item['builtIn'] ? 'git' : 'server'" /></span>
                                <div class="ap-list-main"><b>{{ $item['label'] }}</b><span class="subtle mono ap-break">{{ $issuer }}</span></div>
                                @if ($item['builtIn'])
                                    <x-ap-badge>{{ __('Built in') }}</x-ap-badge>
                                @elseif ($canEdit)
                                    {{-- Submits the removal form below the card, not this one --}}
                                    <x-ap-button type="submit" form="deploy-issuer-remove" name="issuer" :value="$issuer" variant="ghost" size="sm" :icon-only="true" icon="trash"
                                        :label="sprintf(__('Remove %s'), $item['label'])"
                                        :confirm="sprintf(__('Remove %s? Domains that trust it can no longer receive uploads from it.'), $item['label'])" />
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    @error('issuer')<p class="ap-error" role="alert">{{ $message }}</p>@enderror
                    @if ($canEdit)
                        <div class="ap-input-group">
                            <input class="ap-input ap-input-mono{{ $errors->has('new_issuer') ? ' is-invalid' : '' }}" type="url" name="new_issuer" id="deploy-new-issuer"
                                value="{{ old('new_issuer') }}" placeholder="https://github.example.com/_services/token" aria-label="{{ __('Issuer URL') }}"
                                aria-describedby="deploy-new-issuer-hint">
                            <x-ap-button type="submit">
                                <x-ap-icon name="plus" />{{ __('Add provider') }}
                            </x-ap-button>
                        </div>
                        @error('new_issuer')<p class="ap-error" role="alert">{{ $message }}</p>@enderror
                        <span class="ap-hint" id="deploy-new-issuer-hint">{{ __('The issuer URL of a GitHub Enterprise Server or a self-hosted GitLab. The server reads its signing keys from /.well-known/openid-configuration.') }}</span>
                    @endif
                </fieldset>
                @if ($canEdit)
                    <div class="ap-form-actions"><x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button></div>
                @endif
            </x-ap-form>
            @if ($canEdit)
                <x-ap-form :action="route('server.settings.deploy.issuers.destroy')" method="DELETE" id="deploy-issuer-remove" hidden />
            @endif
        </x-ap-card>
    @endif
</x-ap-page>
