{{-- App processes of an account (E42 spec §9); the app-processes island takes over the content. --}}
@php
    $account = $props['account'];
@endphp
<x-ap-page :title="__('App processes')" :crumbs="[['label' => __('App processes')], ['label' => $user]]"
    :description="__('Long-running servers and workers of your web domains, run as your user with their own limits and logs.')">
    <x-ap-island name="app-processes" :props="$props" class="ap-stack">
        {{-- Without scripts: the processes and their state --}}
        @unless ($account['allowed'])
            <x-ap-alert tone="warn">{{ __('App processes are not included in your package.') }}</x-ap-alert>
        @endunless
        <x-ap-card :title="__('App processes')" icon="terminal" :flush="true">
            @if ($account['processes'] === [])
                <x-ap-empty icon="terminal" :title="__('No app process yet.')" />
            @else
                <div class="ap-table-wrap">
                    <table class="ap-table">
                        <caption class="sr-only">{{ __('App processes') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Process') }}</th>
                                <th scope="col">{{ __('Status') }}</th>
                                <th scope="col">{{ __('Preset') }}</th>
                                <th scope="col" class="col-num">{{ __('Port') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($account['processes'] as $process)
                                <tr>
                                    <td data-label="{{ __('Process') }}"><a class="mono" href="{{ route('app-processes.show', ['user' => $user, 'domain' => $process['domain'], 'name' => $process['name']]) }}">{{ $process['domain'] }}/{{ $process['name'] }}</a></td>
                                    <td data-label="{{ __('Status') }}">{{ $process['suspended'] ? __('Suspended') : $process['status'] }}</td>
                                    <td data-label="{{ __('Preset') }}" class="mono">{{ $process['preset'] }}</td>
                                    <td data-label="{{ __('Port') }}" class="col-num mono">{{ $process['port'] !== '' ? $process['port'] : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ap-card>
    </x-ap-island>
</x-ap-page>
