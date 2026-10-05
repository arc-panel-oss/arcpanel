{{-- The Docker app store (E4 spec §7); the docker-apps island adds search and category chips. --}}
<x-ap-page :title="__('Store')" :crumbs="[['label' => __('Docker apps'), 'url' => route('docker.apps.index', ['user' => $user])], ['label' => __('Store')]]"
    :description="__('Curated apps that run on your domains with HTTPS, without SSH.')">
    <x-ap-island name="docker-apps" :props="$props" class="ap-stack">
        {{-- Without scripts: the catalog --}}
        <x-ap-card :title="__('Store')" icon="container">
            <ul class="ap-picker ap-store" role="list">
                @foreach ($catalog as $app)
                    <li>
                        <a class="ap-pick" href="{{ route('docker.store.show', ['user' => $user, 'slug' => $app['slug']]) }}">
                            <span class="ap-pick-logo">@if ($app['icon'] !== '')<img src="{{ $app['icon'] }}" alt="">@else{{ mb_substr($app['name'], 0, 2) }}@endif</span>
                            <b>{{ $app['name'] }}</b>
                            <span>{{ $app['summary'] }}</span>
                            <span class="ap-pick-foot"><x-ap-badge>{{ __($app['category']) }}</x-ap-badge><small class="mono">{{ $app['version'] }}</small></span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </x-ap-card>
    </x-ap-island>
</x-ap-page>
