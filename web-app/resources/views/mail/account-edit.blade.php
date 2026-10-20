{{--
    Edit a mail account (web/edit/mail?account=, E7 batch 1 spec §9.4): one
    form per tab, each running only its own commands, and the client
    configuration panel beside them.
--}}
@php
    $params = ['user' => $record->user, 'domain' => $record->domain];
    $accountParams = $params + ['account' => $account->account];
    $email = $account->email();
    $tabs = ['general' => __('General'), 'forwarding' => __('Aliases and forwarding'), 'autoreply' => __('Auto Reply')];
    $locked = ! $can['update'];
    $state = \Illuminate\Support\Js::from([
        'account' => $account->account,
        'domain' => $record->domain,
        'blackhole' => $account->discards(),
        'fwdOnly' => $account->fwdOnly,
    ])->toHtml();
@endphp
<x-ap-page :title="$email" :crumbs="[['label' => __('Mail'), 'url' => route('mail.index')], ['label' => $record->domain, 'url' => route('mail.accounts.index', $params)], ['label' => __('Edit Mail Account')]]">
    <x-slot:head>
        <div class="ap-entity">
            <span class="ap-entity-icon"><x-ap-icon name="inbox" /></span>
            <div class="ap-entity-text">
                <span class="eyebrow">{{ __('Owner') }}: {{ $record->user }} · {{ __('Edit Mail Account') }}</span>
                <h1 class="mono">{{ $email }}</h1>
                <div class="ap-row">
                    <x-ap-badge :tone="$account->suspended ? 'danger' : 'ok'">{{ $account->suspended ? __('Suspended') : __('Active') }}</x-ap-badge>
                    <x-ap-badge>{{ __('Quota') }}: @if ($account->unlimitedQuota())<x-ap-inf />@else{{ \App\Services\Dashboard\DashboardService::size((int) $account->quota) }}@endif</x-ap-badge>
                    @if ($account->autoreply)
                        <x-ap-badge tone="ok">{{ __('Auto Reply') }}</x-ap-badge>
                    @endif
                </div>
            </div>
        </div>
    </x-slot:head>
    <x-slot:actions>
        <x-ap-button :href="route('mail.accounts.index', $params)" icon="return">{{ __('Back') }}</x-ap-button>
        @if ($webmailUrl && ! $account->suspended)
            <x-ap-button :href="$webmailUrl.'?'.http_build_query(['_user' => $email])" icon="external" target="_blank" rel="noopener">{{ __('Open Webmail') }}</x-ap-button>
        @endif
        @if ($signOn)
            <x-ap-button :form-action="route('mail.accounts.webmail', $accountParams)" icon="key" formtarget="_blank">{{ __('Open Webmail (signed in)') }}</x-ap-button>
        @endif
        @if ($can['suspend'])
            @if ($account->suspended)
                <x-ap-button :form-action="route('mail.accounts.unsuspend', $accountParams)" icon="play" :confirm="sprintf(__('Are you sure you want to unsuspend %s?'), $account->account)">{{ __('Unsuspend') }}</x-ap-button>
            @else
                <x-ap-button :form-action="route('mail.accounts.suspend', $accountParams)" icon="pause" :confirm="sprintf(__('Are you sure you want to suspend %s?'), $account->account)">{{ __('Suspend') }}</x-ap-button>
            @endif
        @endif
        @if ($can['delete'])
            <x-ap-button variant="danger" :form-action="route('mail.accounts.destroy', $accountParams)" form-method="DELETE" icon="trash" :confirm="sprintf(__('Are you sure you want to delete %s?'), $account->account)">{{ __('Delete') }}</x-ap-button>
        @endif
    </x-slot:actions>

    @if ($locked)
        <x-ap-alert tone="info" :title="__('Read-only')">{{ __('You can view these settings but not change them.') }}</x-ap-alert>
    @endif

    <div class="ap-mail-layout" x-data="apMailAccount({{ $state }})" x-on:input="sync($event)">
        <x-ap-tabs :label="__('Mail account settings')" :tabs="$tabs" initial="general">
            {{-- General --}}
            <x-ap-tab-panel name="general">
                <x-ap-card :title="__('General')" icon="user">
                    <x-ap-form :action="route('mail.accounts.update.general', $accountParams)">
                        <fieldset class="ap-fieldset-plain" @disabled($locked)>
                            <legend class="sr-only">{{ __('General') }}</legend>
                            <x-ap-input name="account_name" :label="__('Account')" :value="$email" mono disabled />
                            <x-ap-password name="password" :label="__('Password')" :generate="true" bag="general"
                                :hint="__('Leave empty to keep the current password.')" />
                            <x-ap-unlimited-input name="quota" :label="__('Quota')" :value="$account->unlimitedQuota() ? 'unlimited' : $account->quota" :suffix="__('MB')" bag="general" />
                            <x-ap-input name="rate" :label="__('Rate Limit')" :value="$account->rateLimit" mono autocomplete="off" inputmode="numeric" bag="general"
                                :hint="__('email / hour')" :disabled="! $can['updateRateLimit']" />
                            <x-ap-input name="email" type="email" :label="__('Email login credentials to:')" autocomplete="email" bag="general" />
                        </fieldset>
                        @unless ($locked)
                            <div class="ap-form-actions"><x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button></div>
                        @endunless
                    </x-ap-form>
                </x-ap-card>
            </x-ap-tab-panel>

            {{-- Aliases and forwarding --}}
            <x-ap-tab-panel name="forwarding">
                <x-ap-card :title="__('Aliases and forwarding')" icon="send">
                    <x-ap-form :action="route('mail.accounts.update.forwarding', $accountParams)">
                        <fieldset class="ap-fieldset-plain" @disabled($locked)>
                            <legend class="sr-only">{{ __('Aliases and forwarding') }}</legend>
                            <x-ap-textarea name="aliases" :label="__('Aliases')" :value="implode(PHP_EOL, $account->aliases)" rows="3" mono spellcheck="false" bag="forwarding"
                                :hint="__('Use local-part without domain name')" />
                            <x-ap-textarea name="forward" :label="__('Forward to')" :value="implode(PHP_EOL, $account->forwardAddresses())" rows="3" mono spellcheck="false" bag="forwarding"
                                :hint="__('One or more email addresses')" x-bind:disabled="blackhole" />
                            <x-ap-checkbox name="blackhole" :label="__('Discard all mail')" :checked="$account->discards()" bag="forwarding" x-model="blackhole" />
                            <div x-show="! blackhole">
                                <x-ap-checkbox name="fwd_only" :label="__('Do not store forwarded mail')" :checked="$account->fwdOnly" bag="forwarding" x-model="fwdOnly" />
                            </div>
                        </fieldset>
                        @unless ($locked)
                            <div class="ap-form-actions"><x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button></div>
                        @endunless
                    </x-ap-form>
                </x-ap-card>
            </x-ap-tab-panel>

            {{-- Autoreply --}}
            <x-ap-tab-panel name="autoreply">
                <x-ap-card :title="__('Auto Reply')" icon="send">
                    <x-ap-form :action="route('mail.accounts.update.autoreply', $accountParams)" x-data="{ on: {{ $account->autoreply ? 'true' : 'false' }} }">
                        <fieldset class="ap-fieldset-plain" @disabled($locked)>
                            <legend class="sr-only">{{ __('Auto Reply') }}</legend>
                            <div class="ap-settings">
                                <x-ap-switch name="autoreply" :label="__('Auto Reply')" :description="__('Answer every incoming message with the text below.')" :checked="$account->autoreply" bag="autoreply" x-model="on" />
                            </div>
                            <div x-show="on">
                                <x-ap-textarea name="message" :label="__('Message')" :value="$message" rows="6" bag="autoreply" />
                            </div>
                        </fieldset>
                        @unless ($locked)
                            <div class="ap-form-actions"><x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button></div>
                        @endunless
                    </x-ap-form>
                </x-ap-card>
            </x-ap-tab-panel>
        </x-ap-tabs>

        @include('mail.partials.client-config')
    </div>
</x-ap-page>
