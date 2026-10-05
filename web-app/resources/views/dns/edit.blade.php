{{-- Edit a DNS zone (web/edit/dns, E7 batch 1 spec §8.2). --}}
@php
    $params = ['user' => $zone->user, 'domain' => $zone->domain];
@endphp
<x-ap-page :title="__('Edit DNS Domain')" :crumbs="[['label' => __('DNS'), 'url' => route('dns.index')], ['label' => $zone->domain, 'url' => route('dns.records.index', $params)], ['label' => __('Edit DNS Domain')]]">
    <x-slot:head>
        <div class="ap-entity">
            <span class="ap-entity-icon"><x-ap-icon name="dns" /></span>
            <div class="ap-entity-text">
                <span class="eyebrow">{{ __('Owner') }}: {{ $zone->user }} · {{ __('Records') }}: {{ $zone->records }}</span>
                <h1 class="mono">{{ $zone->domain }}</h1>
                <div class="ap-row">
                    <x-ap-badge :tone="$zone->suspended ? 'danger' : 'ok'">{{ $zone->suspended ? __('Suspended') : __('Active') }}</x-ap-badge>
                    <x-ap-badge :tone="$zone->dnssec ? 'ok' : null">{{ __('DNSSEC') }}: {{ $zone->dnssec ? __('Enabled') : __('Disabled') }}</x-ap-badge>
                </div>
            </div>
        </div>
    </x-slot:head>
    <x-slot:actions>
        <x-ap-button :href="route('dns.records.index', $params)" icon="list">{{ __('DNS Records') }}</x-ap-button>
        @if ($zone->dnssec)
            <x-ap-button :href="route('dns.dnssec', $params)" icon="key">{{ __('View Public DNSSEC Key') }}</x-ap-button>
        @endif
    </x-slot:actions>

    <x-ap-card :title="__('DNS Zone')" icon="dns">
        <x-ap-form :action="route('dns.update', $params)">
            <fieldset class="ap-fieldset-plain" @disabled(! $can['update'])>
                <legend class="sr-only">{{ __('DNS Zone') }}</legend>
                <div class="ap-form-grid">
                    <x-ap-input name="domain_name" :label="__('Domain')" :value="$zone->domain" mono disabled />
                    @include('dns.partials.ip-field', ['value' => $zone->ip])
                    @if ($templates !== null)
                        <x-ap-select name="template" :label="__('Template')" :options="array_combine($templates, $templates)" :value="$zone->template" />
                    @else
                        <x-ap-input name="template_name" :label="__('Template')" :value="$zone->template" disabled />
                    @endif
                    <x-ap-input name="soa" :label="__('SOA')" :value="$zone->soa" required mono autocomplete="off" spellcheck="false" />
                    <x-ap-input name="exp" :label="__('Expiration Date')" :value="$zone->exp" required mono autocomplete="off" :hint="__('YYYY-MM-DD')" />
                    <x-ap-input name="ttl" :label="__('TTL')" :value="$zone->ttl" required mono autocomplete="off" inputmode="numeric" />
                </div>
                @if ($dnssec)
                    <x-ap-checkbox name="dnssec" value="yes" :label="__('Enable DNSSEC')" :checked="$zone->dnssec" />
                @endif
            </fieldset>

            @if ($can['update'])
                <div class="ap-form-actions">
                    <x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button>
                    <x-ap-button :href="route('dns.index')" variant="ghost">{{ __('Cancel') }}</x-ap-button>
                </div>
            @endif
        </x-ap-form>
    </x-ap-card>
</x-ap-page>
