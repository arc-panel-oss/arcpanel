{{-- Add a mail domain (web/add/mail, E7 batch 1 spec §9.2). --}}
@php
    $clientOptions = [];
    foreach ($clients as $client) {
        $clientOptions[$client] = ucfirst($client);
    }
    $clientOptions['disabled'] = __('Disabled');
    $defaultClient = in_array('roundcube', $clients, true) ? 'roundcube' : (array_key_first($clientOptions) ?? 'disabled');
@endphp
<x-ap-page :title="__('Add Mail Domain')" :crumbs="[['label' => __('Mail'), 'url' => route('mail.index')], ['label' => __('Add Mail Domain')]]"
    :description="$owners !== null ? __('The mail domain belongs to the owner you choose.') : null">

    <x-ap-owner-picker route="mail.create" :owners="$owners" :owner="$owner" :admin="$ownerIsAdmin" :object="__('a mail domain')" />

    <x-ap-card :title="__('Mail Domain')" icon="mail">
        <x-ap-form :action="route('mail.store')" :focus-first="true" x-data="{ relay: {{ old('relay') ? 'true' : 'false' }} }">
            <input type="hidden" name="owner" value="{{ $owner }}">
            <div class="ap-form-grid">
                <x-ap-input name="domain" :label="__('Domain')" required mono autocomplete="off" spellcheck="false" placeholder="example.com" />
                @if ($systems['webmail'])
                    <x-ap-select name="webmail" :label="__('Webmail Client')" :options="$clientOptions" :value="$defaultClient" />
                @endif
            </div>

            <div class="ap-settings">
                @if ($systems['antispam'])
                    <x-ap-switch name="antispam" :label="__('Spam Filter')" :description="__('Mark incoming spam.')" :checked="true" />
                    <x-ap-switch name="reject" :label="__('Reject Spam')" :description="__('Refuse spam instead of delivering it marked.')" :checked="true" />
                @endif
                @if ($systems['antivirus'])
                    <x-ap-switch name="antivirus" :label="__('Anti-Virus')" :description="__('Scan incoming mail for viruses.')" :checked="true" />
                @endif
                <x-ap-switch name="dkim" :label="__('DKIM Support')" :description="__('Sign outgoing mail so receivers can check it.')" :checked="true" />
                <x-ap-switch name="relay" :label="__('SMTP Relay')" :description="__('Send the outgoing mail of this domain through another server.')" x-model="relay" />
            </div>

            <div class="ap-form-grid" x-show="relay" x-cloak>
                <x-ap-input name="relay_host" :label="__('Host')" mono autocomplete="off" spellcheck="false" />
                <x-ap-input name="relay_port" :label="__('Port')" mono autocomplete="off" inputmode="numeric" placeholder="587" />
                <x-ap-input name="relay_user" :label="__('Username')" mono autocomplete="off" spellcheck="false" />
                <x-ap-password name="relay_password" :label="__('Password')" :meter="false" autocomplete="off" />
            </div>

            <div class="ap-form-actions">
                <x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button>
                <x-ap-button :href="route('mail.index')" variant="ghost">{{ __('Cancel') }}</x-ap-button>
            </div>
        </x-ap-form>
    </x-ap-card>
</x-ap-page>
