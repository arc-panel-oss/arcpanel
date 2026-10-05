{{-- Add a web domain (web/add/web, E1-S2 spec §8.2). --}}
<x-ap-page :title="__('Add Web Domain')" :crumbs="[['label' => __('Web domains'), 'url' => route('web.domains.index')], ['label' => __('Add Web Domain')]]"
    :description="$owners !== null ? __('The domain belongs to the owner you choose.') : null">

    <x-ap-owner-picker route="web.domains.create" :owners="$owners" :owner="$owner" />

    <x-ap-card :title="__('Domain')" icon="globe">
        <x-ap-form :action="route('web.domains.store')" :focus-first="true">
            <input type="hidden" name="owner" value="{{ $owner }}">
            <div class="ap-form-grid">
                <x-ap-input name="domain" :label="__('Domain')" :hint="__('Without www. An alias for www is added for you.')" required mono autocomplete="off" spellcheck="false" />
                <x-ap-select name="ip" :label="__('IP Address')" :options="$ips" :value="array_key_first($ips)" required />
            </div>
            <x-ap-textarea name="aliases" :label="__('Aliases')" rows="2" mono :hint="__('One per line. Leave empty to add www. of the domain.')" />
            <div class="ap-row ap-checks">
                @if ($systems['dns'])
                    <x-ap-checkbox name="dns" :label="__('Create DNS zone')" :checked="true" />
                @endif
                @if ($systems['mail'])
                    <x-ap-checkbox name="mail" :label="__('Create mail domain')" :checked="true" />
                @endif
            </div>

            @if ($canTemplates)
                <details class="ap-advanced" @if ($errors->hasAny(['template', 'backend_template', 'proxy_template', 'proxy_extensions', 'backend_port'])) open @endif>
                    <summary>{{ __('Advanced Options') }}</summary>
                    @include('web.partials.templates', [
                        'values' => $defaults,
                        'bag' => null,
                    ])
                    @if ($systems['proxy'])
                        <x-ap-textarea name="proxy_extensions" :label="__('Proxy Extensions')" rows="2" mono :hint="__('Leave empty for the default list.')" />
                    @endif
                </details>
            @endif

            <div class="ap-form-actions">
                <x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button>
                <x-ap-button :href="route('web.domains.index')" variant="ghost">{{ __('Cancel') }}</x-ap-button>
            </div>
        </x-ap-form>
    </x-ap-card>
</x-ap-page>
