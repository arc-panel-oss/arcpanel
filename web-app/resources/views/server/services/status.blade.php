{{-- One status report of the server (web/list/server/?cpu …, E7 batch 3 spec §7.2). --}}
@php
    $labels = ['cpu' => __('CPU'), 'mem' => __('RAM'), 'disk' => __('Disk'), 'net' => __('Network'),
        'web' => __('Web'), 'dns' => __('DNS'), 'mail' => __('Mail'), 'db' => __('DB')];
@endphp
<x-ap-page :title="$labels[$kind]" :crumbs="[['label' => __('Server')], ['label' => __('Services'), 'url' => route('server.services')], ['label' => $labels[$kind]]]">
    <x-slot:actions>
        <nav class="ap-segment" aria-label="{{ __('Status reports') }}">
            @foreach ($reports as $value)
                <a href="{{ route('server.services.status', ['kind' => $value]) }}" @if ($value === $kind) aria-current="page" @endif>{{ $labels[$value] }}</a>
            @endforeach
        </nav>
        <x-ap-button :href="route('server.services.status', ['kind' => $kind])" icon="refresh">{{ __('Refresh') }}</x-ap-button>
    </x-slot:actions>

    <x-ap-card>
        <x-ap-code :label="$labels[$kind]">{{ $report }}</x-ap-code>
    </x-ap-card>
</x-ap-page>
