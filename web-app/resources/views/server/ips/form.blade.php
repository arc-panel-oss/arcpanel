{{-- Add or edit an IP address (web/add/ip, web/edit/ip, E7 batch 2 spec §7.2). --}}
@php
    $title = $ip === null ? __('Add IP Address') : __('Edit IP Address');
    $action = $ip === null ? route('server.ips.store') : route('server.ips.update', ['ip' => $ip->ip]);
    $shared = \App\View\FormField::submitted() ? old('shared') !== null : ($ip === null || $ip->shared());
@endphp
<x-ap-page :title="$title" :crumbs="[['label' => __('Server')], ['label' => __('IP Addresses'), 'url' => route('server.ips')], ['label' => $title]]">
    @if ($ip !== null)
        <x-slot:head>
            <div class="ap-entity">
                <span class="ap-entity-icon"><x-ap-icon name="hash" /></span>
                <div class="ap-entity-text">
                    <span class="eyebrow">{{ $ip->interface }} · {{ $ip->date }} {{ $ip->time }}</span>
                    <h1 class="mono">{{ $ip->ip }}</h1>
                    <div class="ap-row">
                        <x-ap-badge :tone="$ip->shared() ? 'info' : 'accent'">{{ __($ip->status) }}</x-ap-badge>
                        <x-ap-badge>{{ trans_choice(':count domain|:count domains', (int) $ip->domains, ['count' => (int) $ip->domains]) }}</x-ap-badge>
                    </div>
                </div>
            </div>
        </x-slot:head>
    @endif

    <x-ap-card :title="__('IP Address')" icon="hash">
        <x-ap-form :action="$action" :focus-first="$ip === null" x-data="{ shared: {{ $shared ? 'true' : 'false' }} }">
            <fieldset class="ap-fieldset-plain" @disabled(! $canManage)>
                <legend class="sr-only">{{ __('IP Address') }}</legend>
                @if ($ip === null)
                    <x-ap-input name="ip" :label="__('IP Address')" required mono autocomplete="off" />
                    <x-ap-input name="netmask" :label="__('Netmask')" required mono autocomplete="off" placeholder="255.255.255.0" />
                    <x-ap-select name="interface" :label="__('Interface')" :options="array_combine($interfaces, $interfaces)" :value="$interfaces[0] ?? null" required />
                @else
                    <x-ap-input name="netmask_view" :label="__('Netmask')" :value="$ip->netmask" mono disabled />
                @endif

                <x-ap-checkbox name="shared" :label="__('Shared')" :checked="$shared" x-model="shared" />
                <div x-show="! shared">
                    <x-ap-select name="owner" :label="__('Assigned User')" :options="array_combine($owners, $owners)" :value="$ip?->owner" :required="$ip === null" />
                </div>
                <x-ap-input name="name" :label="__('Assigned Domain')" :value="$ip?->name" :hint="__('(Optional)')" mono autocomplete="off" />
                <x-ap-input name="nat" :label="__('NAT IP Association')" :value="$ip?->nat" :hint="__('(Optional)')" mono autocomplete="off" />
            </fieldset>

            @if ($canManage)
                <div class="ap-form-actions">
                    <x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button>
                    <x-ap-button :href="route('server.ips')" variant="ghost">{{ __('Cancel') }}</x-ap-button>
                </div>
            @endif
        </x-ap-form>
    </x-ap-card>
</x-ap-page>
