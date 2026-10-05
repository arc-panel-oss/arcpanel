{{-- Add a DNS zone (web/add/dns, E7 batch 1 spec §8.2). --}}
@php
    $advancedErrors = $errors->hasAny(['dnssec', 'exp', 'ttl', 'ns', 'ns.*']);
@endphp
<x-ap-page :title="__('Add DNS Zone')" :crumbs="[['label' => __('DNS'), 'url' => route('dns.index')], ['label' => __('Add DNS Zone')]]"
    :description="$owners !== null ? __('The DNS zone belongs to the owner you choose.') : null">

    <x-ap-owner-picker route="dns.create" :owners="$owners" :owner="$owner" :admin="$ownerIsAdmin" :object="__('a dns domain')" />

    <x-ap-card :title="__('DNS Zone')" icon="dns">
        <x-ap-form :action="route('dns.store')" :focus-first="true">
            <input type="hidden" name="owner" value="{{ $owner }}">
            <div class="ap-form-grid">
                <x-ap-input name="domain" :label="__('Domain')" required mono autocomplete="off" spellcheck="false" placeholder="example.com" />
                @include('dns.partials.ip-field', ['value' => $defaults['ip']])
                @if ($templates !== null)
                    <x-ap-select name="template" :label="__('Template')" :options="array_combine($templates, $templates)" :value="$defaults['template']" />
                @endif
            </div>

            <details class="ap-advanced" @if ($advancedErrors) open @endif>
                <summary>{{ __('Advanced Options') }}</summary>
                <div class="ap-fieldset-plain">
                    @if ($dnssec)
                        <x-ap-checkbox name="dnssec" value="yes" :label="__('Enable DNSSEC')" />
                    @endif
                    <div class="ap-form-grid">
                        <x-ap-input name="exp" :label="__('Expiration Date')" :value="$defaults['exp']" mono autocomplete="off" :hint="__('YYYY-MM-DD')" />
                        <x-ap-input name="ttl" :label="__('TTL')" :value="$defaults['ttl']" mono autocomplete="off" inputmode="numeric" />
                    </div>
                    @include('dns.partials.name-servers', ['values' => old('ns', $defaults['ns'])])
                </div>
            </details>

            <div class="ap-form-actions">
                <x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button>
                <x-ap-button :href="route('dns.index')" variant="ghost">{{ __('Cancel') }}</x-ap-button>
            </div>
        </x-ap-form>
    </x-ap-card>
</x-ap-page>
