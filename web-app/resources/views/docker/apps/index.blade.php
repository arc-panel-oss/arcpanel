{{-- Docker apps of an account (E4 spec §7); the docker-apps island takes over the content. --}}
<x-ap-page :title="__('Docker apps')" :crumbs="[['label' => __('Docker apps')], ['label' => $user]]"
    :description="__('Apps of the store, each running for one of your web domains with its own ports, logs and backups.')">
    <x-slot:actions>
        <x-ap-button :href="route('docker.store', ['user' => $user])" variant="primary" icon="plus">{{ __('Open the store') }}</x-ap-button>
    </x-slot:actions>
    <x-ap-island name="docker-apps" :props="$props" class="ap-stack">
        {{-- Without scripts: the apps and their state --}}
        <x-ap-card :title="__('Docker apps')" icon="container" :flush="true">
            @if ($account['apps'] === [])
                <x-ap-empty icon="container" :title="__('No Docker app yet.')" />
            @else
                <div class="ap-table-wrap">
                    <table class="ap-table">
                        <caption class="sr-only">{{ __('Docker apps') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('App name') }}</th>
                                <th scope="col">{{ __('Web domain') }}</th>
                                <th scope="col">{{ __('Status') }}</th>
                                <th scope="col">{{ __('Version') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($account['apps'] as $app)
                                <tr>
                                    <td data-label="{{ __('App name') }}"><a class="mono" href="{{ route('docker.apps.show', ['user' => $user, 'name' => $app['name']]) }}">{{ $app['name'] }}</a> {{ $app['appName'] }}</td>
                                    <td data-label="{{ __('Web domain') }}" class="mono">{{ $app['domain'] !== '' ? $app['domain'] : '—' }}</td>
                                    <td data-label="{{ __('Status') }}">{{ $app['suspended'] ? __('Suspended') : __($app['status']) }}</td>
                                    <td data-label="{{ __('Version') }}" class="mono">{{ $app['version'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ap-card>
    </x-ap-island>
</x-ap-page>
