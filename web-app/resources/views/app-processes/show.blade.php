{{-- Page of one app process (E42 spec §9); the app-processes island takes over the content. --}}
<x-ap-page :title="$process['domain'].'/'.$process['name']" :crumbs="[['label' => __('App processes'), 'url' => route('app-processes.index', ['user' => $user])], ['label' => $process['domain'].'/'.$process['name']]]"
    :description="__('App process of this web domain.')">
    <x-ap-island name="app-processes" :props="$props" class="ap-stack">
        {{-- Without scripts: what runs --}}
        <x-ap-card :title="$process['domain'].'/'.$process['name']" icon="terminal">
            <dl class="ap-dl">
                <dt>{{ __('Status') }}</dt>
                <dd>{{ $process['suspended'] ? __('Suspended') : $process['status'] }}</dd>
                <dt>{{ __('Preset') }}</dt>
                <dd class="mono">{{ $process['preset'] }}</dd>
                <dt>{{ __('Command') }}</dt>
                <dd class="mono">{{ $process['start'] }}</dd>
                <dt>{{ __('Working directory') }}</dt>
                <dd class="mono">{{ $process['cwd'] }}</dd>
                @if ($process['kind'] === 'web')
                    <dt>{{ __('Port') }}</dt>
                    <dd class="mono">127.0.0.1:{{ $process['port'] }}</dd>
                @endif
            </dl>
        </x-ap-card>
    </x-ap-island>
</x-ap-page>
