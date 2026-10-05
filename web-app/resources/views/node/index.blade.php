{{-- Node.js runtimes and global packages of an account (E13 spec §7); the node-manager island takes over the content. --}}
@php
    $account = $props['account'];
@endphp
<x-ap-page :title="__('Node.js')" :crumbs="[['label' => __('Node.js')], ['label' => $user]]"
    :description="__('Node.js versions and global npm packages for your shell and your apps.')">
    <x-ap-island name="node-manager" :props="$props" class="ap-stack">
        {{-- Without scripts: what is installed --}}
        @unless ($account['allowed'])
            <x-ap-alert tone="warn">{{ __('Node.js is not included in your package.') }}</x-ap-alert>
        @endunless
        <x-ap-card :title="__('Runtimes')" icon="hexagon" :flush="true">
            @if ($account['runtimes'] === [])
                <x-ap-empty icon="hexagon" :title="$account['enabled'] ? __('No runtime is installed yet.') : __('Node.js is not enabled yet.')" />
            @else
                <div class="ap-table-wrap">
                    <table class="ap-table">
                        <caption class="sr-only">{{ __('Runtimes') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Version') }}</th>
                                <th scope="col">{{ __('LTS') }}</th>
                                <th scope="col">{{ __('Status') }}</th>
                                <th scope="col" class="col-num">{{ __('Size') }}</th>
                                <th scope="col" class="col-num">{{ __('Packages') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($account['runtimes'] as $runtime)
                                <tr>
                                    <td data-label="{{ __('Version') }}"><span class="mono">v{{ $runtime['version'] }}</span>
                                        @if ($runtime['default'])<x-ap-badge tone="accent">{{ __('Default') }}</x-ap-badge>@endif</td>
                                    <td data-label="{{ __('LTS') }}">{{ $runtime['lts'] }}</td>
                                    <td data-label="{{ __('Status') }}">{{ $runtime['status'] }}</td>
                                    <td data-label="{{ __('Size') }}" class="col-num mono">{{ $runtime['sizeMb'] }} MB</td>
                                    <td data-label="{{ __('Packages') }}" class="col-num mono">{{ $runtime['packages'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ap-card>
    </x-ap-island>
</x-ap-page>
