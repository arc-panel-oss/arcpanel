{{-- Add a mail account (web/add/mail?domain=, E7 batch 1 spec §9.4). --}}
@php
    $params = ['user' => $record->user, 'domain' => $record->domain];
    $state = \Illuminate\Support\Js::from([
        'account' => (string) old('account', ''),
        'domain' => $record->domain,
        'blackhole' => (bool) old('blackhole'),
        'fwdOnly' => (bool) old('fwd_only'),
    ])->toHtml();
    $advancedErrors = $errors->hasAny(['quota', 'aliases', 'forward', 'rate']);
@endphp
<x-ap-page :title="__('Add Mail Account')" :crumbs="[['label' => __('Mail'), 'url' => route('mail.index')], ['label' => $record->domain, 'url' => route('mail.accounts.index', $params)], ['label' => __('Add Mail Account')]]">
    <div class="ap-mail-layout" x-data="apMailAccount({{ $state }})" x-on:input="sync($event)">
        <x-ap-card :title="__('Mail Account')" icon="user-plus">
            <x-ap-form :action="route('mail.accounts.store', $params)" :focus-first="true">
                <div class="ap-form-grid">
                    <x-ap-input name="domain_name" :label="__('Domain')" :value="$record->domain" mono disabled />
                    <x-ap-input name="account" :label="__('Account')" required mono autocomplete="off" spellcheck="false" :suffix="'@'.$record->domain" />
                </div>
                <x-ap-password name="password" :label="__('Password')" :generate="true" x-bind:required="! fwdOnly"
                    :hint="__('At least 8 characters with upper and lower case letters and a digit.')" />

                <details class="ap-advanced" @if ($advancedErrors) open @endif>
                    <summary>{{ __('Advanced Options') }}</summary>
                    <div class="ap-fieldset-plain">
                        <x-ap-unlimited-input name="quota" :label="__('Quota')" :suffix="__('MB')" />
                        <x-ap-textarea name="aliases" :label="__('Aliases')" rows="3" mono spellcheck="false" :hint="__('Use local-part without domain name')" />
                        <x-ap-textarea name="forward" :label="__('Forward to')" rows="3" mono spellcheck="false" :hint="__('One or more email addresses')"
                            x-bind:disabled="blackhole" />
                        <x-ap-checkbox name="blackhole" :label="__('Discard all mail')" x-model="blackhole" />
                        <div x-show="! blackhole">
                            <x-ap-checkbox name="fwd_only" :label="__('Do not store forwarded mail')" x-model="fwdOnly" />
                        </div>
                        <x-ap-input name="rate" :label="__('Rate Limit')" mono autocomplete="off" inputmode="numeric" :hint="__('email / hour')"
                            :disabled="! $can['updateRateLimit']" />
                    </div>
                </details>

                <x-ap-input name="email" type="email" :label="__('Email login credentials to:')" autocomplete="email" />

                <div class="ap-form-actions">
                    <x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button>
                    <x-ap-button :href="route('mail.accounts.index', $params)" variant="ghost">{{ __('Cancel') }}</x-ap-button>
                </div>
            </x-ap-form>
        </x-ap-card>

        @include('mail.partials.client-config')
    </div>
</x-ap-page>
