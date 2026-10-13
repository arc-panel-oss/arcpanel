{{-- Server settings: DNS Server, the DNS cluster read-only (web/edit/server, E7 batch 3 spec §8.2). --}}
<x-ap-page :title="__('Server settings')" :crumbs="[['label' => __('Server')], ['label' => __('Server settings')], ['label' => __('DNS Server')]]">
    @include('server.settings.partials.nav')

    <x-ap-card :title="__('DNS Server')" icon="dns">
        <dl class="ap-dl">
            <dt>{{ __('DNS Server') }}</dt>
            <dd class="mono">
                @if (Route::has('server.services.config') && in_array($config['DNS_SYSTEM'], \App\Services\Server\ServiceConfigs::NAMES, true))
                    <a href="{{ route('server.services.config', ['service' => $config['DNS_SYSTEM']]) }}">{{ $config['DNS_SYSTEM'] }}</a>
                @else
                    {{ $config['DNS_SYSTEM'] }}
                @endif
            </dd>
            <dt>{{ __('DNSSEC') }}</dt><dd>{{ ($config['SUPPORT_DNSSEC'] ?? '') === 'yes' ? __('Supported') : __('Not supported') }}</dd>
        </dl>
    </x-ap-card>

    <x-ap-card :title="__('DNS Cluster')" icon="layers" :flush="$hosts !== []">
        @if ($hosts === [])
            <x-ap-empty icon="dns" :title="__('No DNS cluster hosts')">{{ __('Cluster hosts are added on the command line with v-add-remote-dns-host.') }}</x-ap-empty>
        @else
            <div class="ap-table-wrap">
                <table class="ap-table">
                    <caption class="sr-only">{{ __('DNS Cluster') }}</caption>
                    <thead><tr><th scope="col">{{ __('Host') }}</th><th scope="col">{{ __('Port') }}</th><th scope="col">{{ __('Type') }}</th><th scope="col">{{ __('Username') }}</th><th scope="col">{{ __('Status') }}</th></tr></thead>
                    <tbody>
                        @foreach ($hosts as $host)
                            <tr>
                                <td class="mono">{{ $host['host'] }}</td>
                                <td class="mono">{{ $host['PORT'] ?? '' }}</td>
                                <td>{{ $host['TYPE'] ?? '' }}</td>
                                <td class="mono">{{ $host['USER'] ?? '' }}</td>
                                <td><x-ap-badge :tone="($host['SUSPENDED'] ?? 'no') === 'yes' ? 'warn' : 'ok'">{{ ($host['SUSPENDED'] ?? 'no') === 'yes' ? __('Suspended') : __('Active') }}</x-ap-badge></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ap-card>
</x-ap-page>
