{{-- Ban an IP address (web/add/firewall/banlist, E7 batch 2 spec §6.2). --}}
<x-ap-page :title="__('Ban IP Address')" :crumbs="[['label' => __('Server')], ['label' => __('Firewall'), 'url' => route('server.firewall')], ['label' => __('Banned IP Addresses'), 'url' => route('server.firewall.bans')], ['label' => __('Ban IP Address')]]">
    <x-ap-card :title="__('Ban IP Address')" icon="lock">
        <x-ap-form :action="route('server.firewall.bans.store')" :focus-first="true">
            <x-ap-input name="ip" :label="__('IP Address')" :hint="__('(Support CIDR format)')" required mono autocomplete="off" />
            <x-ap-select name="chain" :label="__('Banlist')" :options="array_combine($chains, $chains)" value="SSH" required />

            <div class="ap-form-actions">
                <x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button>
                <x-ap-button :href="route('server.firewall.bans')" variant="ghost">{{ __('Cancel') }}</x-ap-button>
            </div>
        </x-ap-form>
    </x-ap-card>
</x-ap-page>
