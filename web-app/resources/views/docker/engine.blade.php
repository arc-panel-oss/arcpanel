{{-- Server → Docker (E4 spec §7); the docker-apps island adds the install task and the report. --}}
<x-ap-page :title="__('Docker')" :crumbs="[['label' => __('Server')], ['label' => __('Docker')]]"
    :description="__('The engine that runs the Docker apps of the server.')">
    <x-ap-island name="docker-apps" :props="$props" class="ap-stack">
        {{-- Without scripts: the state of the engine --}}
        <x-ap-card :title="__('Docker engine')" icon="container">
            <dl class="ap-dl">
                <dt>{{ __('Status') }}</dt>
                <dd>{{ ! $status['installed'] ? __('Not installed') : ($status['running'] ? __('Running') : __('Stopped')) }}</dd>
                @if ($status['installed'])
                    <dt>{{ __('Version') }}</dt>
                    <dd class="mono">{{ $status['version'] }}</dd>
                    <dt>{{ __('Data root') }}</dt>
                    <dd class="mono">{{ $status['dataRoot'] }}</dd>
                    <dt>{{ __('Apps') }}</dt>
                    <dd class="mono">{{ $status['apps'] }}</dd>
                @endif
            </dl>
        </x-ap-card>
    </x-ap-island>
</x-ap-page>
