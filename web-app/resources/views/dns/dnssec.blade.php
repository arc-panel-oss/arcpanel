{{-- Public DNSSEC key of a zone (web/list/dns?domain=&action=, templates/pages/list_dns_public.php). --}}
@php
    $params = ['user' => $zone->user, 'domain' => $zone->domain];
    $fields = $key === null ? [] : [
        'record' => __('DNSKEY Record'),
        'ds' => __('DS Record'),
        'key' => __('Public Key'),
        'type' => __('Key Type / Flag'),
        'keytag' => __('Key Tag'),
        'flag' => __('Flag'),
        'algorithm' => __('Algorithm'),
    ];
    $copyable = ['record', 'ds', 'key'];
@endphp
<x-ap-page :title="__('View Public DNSSEC Key')" :crumbs="[['label' => __('DNS'), 'url' => route('dns.index')], ['label' => $zone->domain, 'url' => route('dns.records.index', $params)], ['label' => __('DNSSEC')]]">
    <x-slot:head>
        <div class="ap-entity">
            <span class="ap-entity-icon"><x-ap-icon name="key" /></span>
            <div class="ap-entity-text">
                <span class="eyebrow">{{ __('Owner') }}: {{ $zone->user }} · {{ __('View Public DNSSEC Key') }}</span>
                <h1 class="mono">{{ $zone->domain }}</h1>
            </div>
        </div>
    </x-slot:head>
    <x-slot:actions>
        <x-ap-button :href="route('dns.records.index', $params)" icon="return">{{ __('Back') }}</x-ap-button>
    </x-slot:actions>

    <x-ap-card :title="__('DNSSEC')" icon="key">
        @if ($key === null)
            <x-ap-alert>{{ __('DNSSEC is not enabled for this zone.') }}</x-ap-alert>
        @else
            <div class="ap-fieldset-plain ap-dnssec">
                @foreach ($fields as $name => $label)
                    @php $id = 'dnssec-'.$name; @endphp
                    <x-ap-field :label="$label" :for="$id">
                        <div class="ap-input-group" x-data="{ copied: false }">
                            @if (in_array($name, $copyable, true))
                                <textarea class="ap-input ap-input-mono ap-dnssec-value" id="{{ $id }}" rows="{{ $name === 'key' ? 3 : 2 }}" readonly x-ref="value">{{ $key[$name] }}</textarea>
                                <button type="button" class="ap-btn ap-btn-sm" title="{{ __('Copy') }}"
                                    x-on:click="navigator.clipboard?.writeText($refs.value.value).then(() => { copied = true; setTimeout(() => copied = false, 1500) })">
                                    <x-ap-icon name="copy" /><span x-text="copied ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span>
                                </button>
                            @else
                                <input class="ap-input ap-input-mono" id="{{ $id }}" type="text" value="{{ $key[$name] }}" readonly>
                            @endif
                        </div>
                    </x-ap-field>
                @endforeach
            </div>
        @endif
    </x-ap-card>
</x-ap-page>
