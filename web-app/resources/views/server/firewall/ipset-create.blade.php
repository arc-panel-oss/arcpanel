{{-- Add an IPset list (web/add/firewall/ipset, E7 batch 2 spec §6.2). --}}
@php
    $blacklist = '/usr/local/hestia/install/common/firewall/ipset/blacklist.sh';
    $countries = [
        'br' => __('Brazil'), 'ca' => __('Canada'), 'cn' => __('China'), 'fr' => __('France'), 'de' => __('Germany'),
        'in' => __('India'), 'id' => __('Indonesia'), 'nl' => __('Netherlands'), 'ro' => __('Romania'), 'ru' => __('Russia'),
        'es' => __('Spain'), 'ch' => __('Switzerland'), 'tr' => __('Turkey'), 'ua' => __('Ukraine'), 'gb' => __('United Kingdom'),
        'us' => __('United States'),
    ];
@endphp
<x-ap-page :title="__('Add IP list')" :crumbs="[['label' => __('Server')], ['label' => __('Firewall'), 'url' => route('server.firewall')], ['label' => __('IPset IP Lists'), 'url' => route('server.firewall.ipsets')], ['label' => __('Add IP list')]]">
    <x-ap-card :title="__('IP list')" icon="list">
        <x-ap-form :action="route('server.firewall.ipsets.store')" :focus-first="true" x-data="{ source: {{ json_encode((string) old('source', '')) }} }">
            <x-ap-input name="name" :label="__('IP List Name')" required mono maxlength="255" autocomplete="off" />
            <x-ap-input name="source" :label="__('Data Source')" :hint="__('(URL, script or file)')" required mono maxlength="255" autocomplete="off" x-model="source" />
            <x-ap-field :label="__('Known lists')" for="ipset-source-preset" :hint="__('Fill the data source with a known list.')">
                <div class="ap-select-wrap">
                    <select class="ap-select" id="ipset-source-preset" x-on:change="if ($event.target.value) source = $event.target.value">
                        <option value="">{{ __('Choose a list') }}</option>
                        <optgroup label="{{ __('Blacklist') }}">
                            <option value="script:{{ $blacklist }}">{{ __('[IPv4] Block Malicious IPs') }}</option>
                        </optgroup>
                        <optgroup label="IPverse">
                            @foreach ($countries as $iso => $country)
                                <option value="https://raw.githubusercontent.com/ipverse/rir-ip/master/country/{{ $iso }}/ipv4-aggregated.txt">[IPv4] {{ __('Country') }} - {{ $country }}</option>
                            @endforeach
                        </optgroup>
                    </select>
                    <x-ap-icon name="chevron-down" size="sm" />
                </div>
            </x-ap-field>
            <x-ap-select name="version" :label="__('IP Version')" :options="['v4' => __('IPv4'), 'v6' => __('IPv6')]" value="v4" required />
            <x-ap-select name="autoupdate" :label="__('Auto Update')" :options="['yes' => __('Yes'), 'no' => __('No')]" value="yes" required />

            <div class="ap-form-actions">
                <x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button>
                <x-ap-button :href="route('server.firewall.ipsets')" variant="ghost">{{ __('Cancel') }}</x-ap-button>
            </div>
        </x-ap-form>
    </x-ap-card>
</x-ap-page>
