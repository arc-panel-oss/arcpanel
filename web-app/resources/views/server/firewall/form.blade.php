{{-- Add or edit a firewall rule (web/add/firewall, web/edit/firewall, E7 batch 2 spec §6.2). --}}
@php
    $title = $rule === null ? __('Add Firewall Rule') : __('Edit Firewall Rule');
    $action = $rule === null ? route('server.firewall.store') : route('server.firewall.update', ['rule' => $rule->id]);
    $ip = old('ip', $rule?->ip ?? '');
@endphp
<x-ap-page :title="$title" :crumbs="[['label' => __('Server')], ['label' => __('Firewall'), 'url' => route('server.firewall')], ['label' => $title]]">
    @if ($rule !== null)
        <x-slot:head>
            <div class="ap-entity">
                <span class="ap-entity-icon"><x-ap-icon name="shield" /></span>
                <div class="ap-entity-text">
                    <span class="eyebrow">{{ __('Rule') }} #{{ $rule->id }} · {{ $rule->date }} {{ $rule->time }}</span>
                    <h1 class="ap-trunc" title="{{ $rule->comment }}">{{ $rule->comment !== '' ? $rule->comment : '#'.$rule->id }}</h1>
                    <div class="ap-row">
                        <x-ap-badge :tone="$rule->suspended ? 'danger' : 'ok'">{{ $rule->suspended ? __('Suspended') : __('Active') }}</x-ap-badge>
                        @if ($rule->managed())
                            <x-ap-badge tone="info">{{ __('Managed by Ports') }}</x-ap-badge>
                        @endif
                    </div>
                </div>
            </div>
        </x-slot:head>
    @endif

    @if ($rule !== null && $rule->managed())
        <x-ap-alert tone="info" :title="__('Managed by Ports')">
            {{ __('The port registry opened this rule for a public port. Change it on the Ports page.') }}
            <x-slot:actions><x-ap-button :href="route('server.ports')" icon="plug">{{ __('Ports') }}</x-ap-button></x-slot:actions>
        </x-ap-alert>
    @endif

    <x-ap-card :title="__('Rule')" icon="shield">
        <x-ap-form :action="$action" :focus-first="$rule === null" x-data="{ ip: {{ json_encode($ip) }} }">
            <fieldset class="ap-fieldset-plain" @disabled(! $canChange)>
                <legend class="sr-only">{{ __('Rule') }}</legend>
                <x-ap-select name="action" :label="__('Action')" :options="['DROP' => __('DROP'), 'ACCEPT' => __('ACCEPT')]" :value="$rule?->action ?? 'DROP'" required />
                <x-ap-select name="protocol" :label="__('Protocol')" :options="['TCP' => __('TCP'), 'UDP' => __('UDP'), 'ICMP' => __('ICMP')]" :value="$rule?->protocol ?? 'TCP'" required />
                <x-ap-input name="port" :label="__('Port')" :value="$rule?->port" :hint="__('(Ranges and lists are acceptable)')"
                    placeholder="{{ __('All ports: 0, Range: 80-82, List: 80,443,8080,8443') }}" required mono autocomplete="off" />
                <x-ap-input name="ip" :value="$rule?->ip" :label="__('IP Address / IPset IP List')" :hint="__('IPv4 or IPv6, CIDR format supported; 0.0.0.0/0 or ::/0 means every address of both versions.')" required mono autocomplete="off" x-model="ip" />
                @if ($ipsets !== [])
                    <x-ap-select name="ipset" :label="__('IPset IP Lists')" :placeholder="__('Choose a list')"
                        :options="collect($ipsets)->mapWithKeys(fn ($name) => ['ipset:'.$name => $name])->all()"
                        x-on:change="if ($event.target.value) ip = $event.target.value" />
                @endif
                <x-ap-input name="comment" :label="__('Comment')" :value="$rule?->comment" :hint="__('(Optional)')" maxlength="255" />
            </fieldset>

            @if ($canChange)
                <div class="ap-form-actions">
                    <x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button>
                    <x-ap-button :href="route('server.firewall')" variant="ghost">{{ __('Cancel') }}</x-ap-button>
                </div>
            @endif
        </x-ap-form>
    </x-ap-card>
</x-ap-page>
