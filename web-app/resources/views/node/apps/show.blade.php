{{-- Page of one Node.js app (E14 spec §7); the node-apps island takes over the content. --}}
<x-ap-page :title="$app['domain']" :crumbs="[['label' => __('Node apps'), 'url' => route('node.apps.index', ['user' => $user])], ['label' => $app['domain']]]"
    :description="__('Node.js app of this web domain.')">
    <x-ap-island name="node-apps" :props="$props" class="ap-stack">
        {{-- Without scripts: what runs --}}
        <x-ap-card :title="$app['domain']" icon="hexagon">
            <dl class="ap-dl">
                <dt>{{ __('Status') }}</dt>
                <dd>{{ $app['suspended'] ? __('Suspended') : $app['status'] }}</dd>
                <dt>{{ __('Start') }}</dt>
                <dd class="mono">{{ $app['cwd'] }}/{{ $app['start'] }}</dd>
                <dt>{{ __('Runtime') }}</dt>
                <dd class="mono">{{ $app['runtime'] !== '' ? 'v'.$app['runtime'] : '—' }}</dd>
                <dt>{{ __('Port') }}</dt>
                <dd class="mono">127.0.0.1:{{ $app['port'] }}</dd>
                <dt>{{ __('Template') }}</dt>
                <dd class="mono">{{ $app['template'] }}</dd>
            </dl>
        </x-ap-card>
    </x-ap-island>
</x-ap-page>
