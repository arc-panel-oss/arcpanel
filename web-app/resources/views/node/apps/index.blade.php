{{-- Node.js apps of an account (E14 spec §7); the node-apps island takes over the content. --}}
@php
    $account = $props['account'];
@endphp
<x-ap-page :title="__('Node apps')" :crumbs="[['label' => __('Node apps')], ['label' => $user]]"
    :description="__('Node.js servers of your web domains, run by pm2 with their own port and logs.')">
    <x-ap-island name="node-apps" :props="$props" class="ap-stack">
        {{-- Without scripts: the apps and their state --}}
        @unless ($account['allowed'])
            <x-ap-alert tone="warn">{{ __('Node.js is not included in your package.') }}</x-ap-alert>
        @endunless
        <x-ap-card :title="__('Node.js apps')" icon="hexagon" :flush="true">
            @if ($account['apps'] === [])
                <x-ap-empty icon="hexagon" :title="__('No Node.js app yet.')" />
            @else
                <div class="ap-table-wrap">
                    <table class="ap-table">
                        <caption class="sr-only">{{ __('Node.js apps') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Domain') }}</th>
                                <th scope="col">{{ __('Status') }}</th>
                                <th scope="col">{{ __('Runtime') }}</th>
                                <th scope="col" class="col-num">{{ __('Port') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($account['apps'] as $app)
                                <tr>
                                    <td data-label="{{ __('Domain') }}"><a class="mono" href="{{ route('node.apps.show', ['user' => $user, 'domain' => $app['domain']]) }}">{{ $app['domain'] }}</a></td>
                                    <td data-label="{{ __('Status') }}">{{ $app['suspended'] ? __('Suspended') : $app['status'] }}</td>
                                    <td data-label="{{ __('Runtime') }}" class="mono">{{ $app['runtime'] !== '' ? 'v'.$app['runtime'] : '—' }}</td>
                                    <td data-label="{{ __('Port') }}" class="col-num mono">{{ $app['port'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ap-card>
    </x-ap-island>
</x-ap-page>
