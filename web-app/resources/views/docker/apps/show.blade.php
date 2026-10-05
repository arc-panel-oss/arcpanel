{{-- Page of one Docker app (E4 spec §7); the docker-apps island takes over the content. --}}
<x-ap-page :title="$app['name']" :crumbs="[['label' => __('Docker apps'), 'url' => route('docker.apps.index', ['user' => $user])], ['label' => $app['name']]]"
    :description="$app['appName'].' '.$app['version']">
    <x-ap-island name="docker-apps" :props="$props" class="ap-stack">
        {{-- Without scripts: what runs --}}
        <x-ap-card :title="$app['name']" icon="container">
            <dl class="ap-dl">
                <dt>{{ __('Status') }}</dt>
                <dd>{{ $app['suspended'] ? __('Suspended') : __($app['status']) }}</dd>
                <dt>{{ __('App') }}</dt>
                <dd>{{ $app['appName'] }} <span class="mono">{{ $app['version'] }}</span></dd>
                <dt>{{ __('Web domain') }}</dt>
                <dd class="mono">{{ $app['domain'] !== '' ? $app['domain'] : '—' }}</dd>
                @foreach ($app['ports'] as $port)
                    <dt>{{ __('Port') }} {{ $port['name'] }}</dt>
                    <dd class="mono">{{ $port['port'] }}</dd>
                @endforeach
            </dl>
        </x-ap-card>
    </x-ap-island>
</x-ap-page>
