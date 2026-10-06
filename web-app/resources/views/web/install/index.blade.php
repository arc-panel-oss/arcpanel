{{-- Quick Install: pick an application for a web domain (web/add/webapp, E7 batch 2 spec §10.3). --}}
@php
    $edit = route('web.domains.edit', ['user' => $user, 'domain' => $domain]);
@endphp
<x-ap-page :title="__('Quick Install App')" :crumbs="[['label' => __('Web'), 'url' => route('web.domains.index')], ['label' => $domain, 'url' => $edit], ['label' => __('Quick Install App')]]"
    :description="__('Install a web application on :domain in a few steps.', ['domain' => $domain])">
    <x-ap-card :title="__('Applications')" icon="package">
        @if ($apps === [])
            <x-ap-empty icon="package" :title="__('No installers available')" />
        @else
            <ul class="ap-picker ap-store" role="list">
                @foreach ($apps as $key => $app)
                    @php($logo = $app['thumbnail'] !== '' ? asset('build/quick-install/'.$key.'/'.$app['thumbnail']) : null)
                    <li>
                        @if ($app['installable'])
                            <a class="ap-pick" href="{{ route('web.domains.install.form', ['user' => $user, 'domain' => $domain, 'app' => $key]) }}">
                        @else
                            <div class="ap-pick is-disabled" aria-disabled="true">
                        @endif
                            <span class="ap-pick-logo">@if ($logo)<img src="{{ $logo }}" alt="">@else{{ mb_substr($app['name'], 0, 2) }}@endif</span>
                            <b>{{ $app['name'] }}</b>
                            <span>{{ sprintf(__('Version: %s'), $app['version']) }}</span>
                            <span class="ap-pick-foot">
                                <x-ap-badge>{{ __(ucfirst($app['group'])) }}</x-ap-badge>
                                @unless ($app['installable'])
                                    <small>{{ sprintf(__('Unable to install %s, required php version is not available.'), $app['name']) }}</small>
                                @endunless
                            </span>
                        @if ($app['installable'])
                            </a>
                        @else
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ap-card>
</x-ap-page>
