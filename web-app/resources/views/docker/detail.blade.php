{{-- A catalog app with its install form (E4 spec §7); the docker-apps island renders the form. --}}
<x-ap-page :title="$app['name']" :crumbs="[['label' => __('Docker apps'), 'url' => route('docker.apps.index', ['user' => $user])], ['label' => __('Store'), 'url' => route('docker.store', ['user' => $user])], ['label' => $app['name']]]"
    :description="$app['summary']">
    <x-ap-island name="docker-apps" :props="$props" class="ap-stack">
        {{-- Without scripts: what the app is; installing needs scripts --}}
        <x-ap-card :title="$app['name']" icon="container">
            @foreach (preg_split('/\n{2,}/', $app['description']) ?: [] as $paragraph)
                <p>{{ $paragraph }}</p>
            @endforeach
            <dl class="ap-dl">
                <dt>{{ __('Version') }}</dt>
                <dd class="mono">{{ $app['version'] }}</dd>
                <dt>{{ __('Requirements') }}</dt>
                <dd class="mono">{{ __('Memory') }} {{ $app['requirements']['memory'] }} · {{ __('Disk') }} {{ $app['requirements']['disk'] }}</dd>
                <dt>{{ __('License') }}</dt>
                <dd>{{ $app['license'] !== '' ? $app['license'] : '—' }}</dd>
            </dl>
        </x-ap-card>
    </x-ap-island>
</x-ap-page>
