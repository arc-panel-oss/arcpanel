{{--
    Edit a mail domain (web/edit/mail, E7 batch 1 spec §9.3): one form per
    tab, each posting to its own route and running only its own commands.
--}}
@php
    $params = ['user' => $record->user, 'domain' => $record->domain];
    $tabs = ['general' => __('General'), 'ssl' => __('SSL'), 'relay' => __('SMTP Relay')];
    $locked = ! $can['update'];
    $clientOptions = [];
    foreach ($clients as $client) {
        $clientOptions[$client] = ucfirst($client);
    }
    $clientOptions['disabled'] = __('Disabled');
    $algorithms = ['ecdsa-256' => 'ECDSA P-256 ('.__('recommended').')', 'ecdsa-384' => 'ECDSA P-384', 'rsa' => 'RSA 4096'];
@endphp
<x-ap-page :title="$record->domain" :crumbs="[['label' => __('Mail'), 'url' => route('mail.index')], ['label' => $record->domain, 'url' => route('mail.accounts.index', $params)], ['label' => __('Edit Mail Domain')]]">
    <x-slot:head>
        <div class="ap-entity">
            <span class="ap-entity-icon"><x-ap-icon name="mail" /></span>
            <div class="ap-entity-text">
                <span class="eyebrow">@if ($isAdminView){{ __('Owner') }}: {{ $record->user }} · @endif{{ __('Accounts') }}: {{ $record->accounts }}</span>
                <h1 class="mono">{{ $record->domain }}</h1>
                <div class="ap-row">
                    <x-ap-badge :tone="$record->suspended ? 'danger' : 'ok'">{{ $record->suspended ? __('Suspended') : __('Active') }}</x-ap-badge>
                    @if ($record->ssl)
                        <x-ap-badge tone="ok">{{ $record->letsEncrypt ? __('Let’s Encrypt') : __('SSL') }}</x-ap-badge>
                    @else
                        <x-ap-badge>{{ __('No SSL') }}</x-ap-badge>
                    @endif
                    @if ($record->dkim)
                        <x-ap-badge tone="ok">{{ __('DKIM') }}</x-ap-badge>
                    @endif
                </div>
            </div>
        </div>
    </x-slot:head>
    <x-slot:actions>
        <x-ap-button :href="route('mail.accounts.index', $params)" icon="list">{{ __('Mail Accounts') }}</x-ap-button>
        <x-ap-button :href="route('mail.dns', $params)" icon="dns">{{ __('DNS Records') }}</x-ap-button>
        @if ($can['suspend'])
            @if ($record->suspended)
                <x-ap-button :form-action="route('mail.unsuspend', $params)" icon="play" :confirm="sprintf(__('Are you sure you want to unsuspend domain %s?'), $record->domain)">{{ __('Unsuspend') }}</x-ap-button>
            @else
                <x-ap-button :form-action="route('mail.suspend', $params)" icon="pause" :confirm="sprintf(__('Are you sure you want to suspend domain %s?'), $record->domain)">{{ __('Suspend') }}</x-ap-button>
            @endif
        @endif
        @if ($can['delete'])
            <x-ap-button variant="danger" :form-action="route('mail.destroy', $params)" form-method="DELETE" icon="trash" :confirm="sprintf(__('Are you sure you want to delete domain %s?'), $record->domain)">{{ __('Delete') }}</x-ap-button>
        @endif
    </x-slot:actions>

    @if ($locked)
        <x-ap-alert tone="info" :title="__('Read-only')">{{ __('You can view these settings but not change them.') }}</x-ap-alert>
    @endif

    <x-ap-tabs :label="__('Mail domain settings')" :tabs="$tabs" initial="general">
        {{-- General --}}
        <x-ap-tab-panel name="general">
            <x-ap-card :title="__('General')" icon="mail">
                <x-ap-form :action="route('mail.update.general', $params)">
                    <fieldset class="ap-fieldset-plain" @disabled($locked)>
                        <legend class="sr-only">{{ __('General') }}</legend>
                        <div class="ap-form-grid">
                            @if ($systems['webmail'])
                                <x-ap-select name="webmail" :label="__('Webmail Client')" :options="$clientOptions" :value="$record->webmail !== '' ? $record->webmail : 'disabled'" bag="general" />
                            @endif
                            <x-ap-input name="catchall" type="email" :label="__('Catch-All Email')" :value="$record->catchall" mono autocomplete="off" bag="general"
                                :hint="__('Mail to unknown addresses of this domain goes here.')" />
                            <x-ap-input name="rate" :label="__('Rate Limit')" :value="$record->rateLimit" mono autocomplete="off" inputmode="numeric" bag="general"
                                :hint="__('email / hour / account')" :disabled="! $can['updateRateLimit']" />
                        </div>
                        <div class="ap-settings">
                            @if ($systems['antispam'])
                                <x-ap-switch name="antispam" :label="__('Spam Filter')" :description="__('Mark incoming spam.')" :checked="$record->antispam" bag="general" />
                                <x-ap-switch name="reject" :label="__('Reject Spam')" :description="__('Refuse spam instead of delivering it marked.')" :checked="$record->reject" bag="general" />
                            @endif
                            @if ($systems['antivirus'])
                                <x-ap-switch name="antivirus" :label="__('Anti-Virus')" :description="__('Scan incoming mail for viruses.')" :checked="$record->antivirus" bag="general" />
                            @endif
                            <x-ap-switch name="dkim" :label="__('DKIM Support')" :description="__('Sign outgoing mail so receivers can check it.')" :checked="$record->dkim" bag="general" />
                        </div>
                    </fieldset>
                    @unless ($locked)
                        <div class="ap-form-actions"><x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button></div>
                    @endunless
                </x-ap-form>
            </x-ap-card>
        </x-ap-tab-panel>

        {{-- SSL --}}
        <x-ap-tab-panel name="ssl">
            <x-ap-card :title="__('SSL')" icon="lock">
                <x-ap-form :action="route('mail.update.ssl', $params)" x-data="{ ssl: {{ $record->ssl ? 'true' : 'false' }}, le: {{ $record->letsEncrypt ? 'true' : 'false' }} }">
                    <fieldset class="ap-fieldset-plain" @disabled($locked)>
                        <legend class="sr-only">{{ __('SSL') }}</legend>
                        <div class="ap-settings">
                            <x-ap-switch name="ssl" :label="__('Enable SSL for this domain')" :description="__('Encrypt mail connections to mail.:domain.', ['domain' => $record->domain])" :checked="$record->ssl" bag="ssl" x-model="ssl" />
                            <x-ap-switch name="letsencrypt" :label="__('Use Let’s Encrypt to obtain SSL certificate')" :description="__('Issue and renew the certificate automatically.')"
                                :checked="$record->letsEncrypt" bag="ssl" x-model="le" />
                        </div>
                        <div x-show="ssl && le">
                            <x-ap-select name="key_algo" :label="__('Key Type')" :options="$algorithms" :value="$record->keyAlgo()" bag="ssl" />
                            <x-ap-alert>{{ sprintf(__('To enable Let\'s Encrypt SSL, ensure that DNS records exist for mail.%s and %s!'), $record->domain, $webmailAlias.'.'.$record->domain) }}</x-ap-alert>
                        </div>
                        @if ($record->letsEncrypt)
                            <x-ap-alert x-show="ssl && ! le">{{ __('Switching from Let’s Encrypt to your own certificate takes two saves: the first one removes the Let’s Encrypt certificate, the second one installs yours.') }}</x-ap-alert>
                        @endif
                        <div class="ap-stack" x-show="ssl && !le">
                            <x-ap-textarea name="certificate" :label="__('SSL Certificate')" :value="$record->letsEncrypt ? '' : ($ssl?->certificate ?? '')" rows="6" mono bag="ssl" spellcheck="false" />
                            <x-ap-textarea name="key" :label="__('SSL Private Key')" rows="6" mono bag="ssl" spellcheck="false" autocomplete="off"
                                :hint="$record->ssl && ! $record->letsEncrypt ? __('Leave empty to keep the current key.') : null" />
                            <x-ap-textarea name="ca" :label="__('SSL Certificate Authority / Intermediate')" :value="$record->letsEncrypt ? '' : ($ssl?->ca ?? '')" rows="4" mono bag="ssl" spellcheck="false"
                                :hint="__('Optional')" />
                        </div>
                    </fieldset>
                    @if ($ssl && $ssl->subject !== '')
                        <dl class="ap-dl">
                            <dt>{{ __('Issued To') }}</dt><dd class="mono">{{ $ssl->subject }}</dd>
                            @if ($ssl->aliases !== '')
                                <dt>{{ __('Alternate') }}</dt><dd class="mono">{{ str_replace(',', ', ', $ssl->aliases) }}</dd>
                            @endif
                            <dt>{{ __('Not Before') }}</dt><dd>{{ $ssl->notBefore }}</dd>
                            <dt>{{ __('Not After') }}</dt><dd>{{ $ssl->notAfter }}</dd>
                            <dt>{{ __('Signature') }}</dt><dd>{{ $ssl->signature }}</dd>
                            <dt>{{ __('Key Size') }}</dt><dd>{{ $ssl->pubKey }}</dd>
                            <dt>{{ __('Issued By') }}</dt><dd>{{ $ssl->issuer }}</dd>
                        </dl>
                    @endif
                    @unless ($locked)
                        <div class="ap-form-actions"><x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button></div>
                    @endunless
                </x-ap-form>
            </x-ap-card>
        </x-ap-tab-panel>

        {{-- SMTP relay --}}
        <x-ap-tab-panel name="relay">
            <x-ap-card :title="__('SMTP Relay')" icon="send">
                <x-ap-form :action="route('mail.update.relay', $params)" x-data="{ relay: {{ $record->relay ? 'true' : 'false' }} }">
                    <fieldset class="ap-fieldset-plain" @disabled($locked)>
                        <legend class="sr-only">{{ __('SMTP Relay') }}</legend>
                        <div class="ap-settings">
                            <x-ap-switch name="relay" :label="__('SMTP Relay')" :description="__('Send the outgoing mail of this domain through another server.')" :checked="$record->relay" bag="relay" x-model="relay" />
                        </div>
                        <div class="ap-form-grid" x-show="relay">
                            <x-ap-input name="relay_host" :label="__('Host')" :value="$record->relayHost" mono autocomplete="off" spellcheck="false" bag="relay" />
                            <x-ap-input name="relay_port" :label="__('Port')" :value="$record->relayPort" mono autocomplete="off" inputmode="numeric" placeholder="587" bag="relay" />
                            <x-ap-input name="relay_user" :label="__('Username')" :value="$record->relayUser" mono autocomplete="off" spellcheck="false" bag="relay" />
                            <x-ap-password name="relay_password" :label="__('Password')" :meter="false" autocomplete="off" bag="relay"
                                :hint="$record->relay ? __('The stored password is not shown. Enter it again whenever you save a change of the relay.') : null" />
                        </div>
                    </fieldset>
                    @unless ($locked)
                        <div class="ap-form-actions"><x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button></div>
                    @endunless
                </x-ap-form>
            </x-ap-card>
        </x-ap-tab-panel>
    </x-ap-tabs>
</x-ap-page>
