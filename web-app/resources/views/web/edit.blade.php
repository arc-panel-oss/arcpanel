{{--
    Edit a web domain (web/edit/web, E1-S2 spec §8.3): one form per tab, each
    posting to its own route and running only its own commands.
--}}
@php
    $params = ['user' => $record->user, 'domain' => $record->domain];
    $tabs = array_filter([
        'general' => __('General'),
        'ssl' => __('SSL'),
        'redirect' => __('Redirect'),
        'advanced' => $can['updateTemplates'] ? __('Advanced') : null,
        'ftp' => $systems['ftp'] ? __('FTP') : null,
        'stats' => __('Statistics'),
    ]);
    $locked = ! $can['update'];
@endphp
<x-ap-page :title="$record->domain" :crumbs="[['label' => __('Web domains'), 'url' => route('web.domains.index')], ['label' => $record->domain]]">
    <x-slot:head>
        <div class="ap-entity">
            <span class="ap-entity-icon"><x-ap-icon name="globe" /></span>
            <div class="ap-entity-text">
                <span class="eyebrow">@if ($isAdminView){{ __('Owner') }}: {{ $record->user }} · @endif{{ $publicIp }}</span>
                <h1 class="mono">{{ $record->domain }}</h1>
                <div class="ap-row">
                    @if ($record->suspended)
                        <x-ap-badge tone="danger">{{ __('Suspended') }}</x-ap-badge>
                    @else
                        <x-ap-badge tone="ok">{{ __('Active') }}</x-ap-badge>
                    @endif
                    @if ($record->ssl)
                        <x-ap-badge tone="ok">{{ $record->letsEncrypt ? __('Let’s Encrypt') : __('SSL') }}</x-ap-badge>
                    @else
                        <x-ap-badge>{{ __('No SSL') }}</x-ap-badge>
                    @endif
                    <x-ap-badge>{{ $activeTemplate }}</x-ap-badge>
                    @if ($activePort !== '')
                        <a class="ap-badge ap-badge-mono" href="{{ route('ports.index') }}" title="{{ __('App port') }}">{{ __('Port') }} {{ $activePort }}</a>
                    @elseif ($activePortMissing)
                        <x-ap-badge tone="warn">{{ __('App port not set') }}</x-ap-badge>
                    @endif
                </div>
            </div>
        </div>
    </x-slot:head>
    <x-slot:actions>
        <x-ap-button :href="($record->ssl ? 'https://' : 'http://').$record->domain.'/'" icon="external" target="_blank" rel="noopener">{{ __('Visit') }}</x-ap-button>
        @if ($fileManagerUrl)
            <x-ap-button :href="$fileManagerUrl" icon="folder">{{ __('Open files') }}</x-ap-button>
        @endif
        <x-ap-button :href="route('web.domains.logs', $params + ['type' => 'access'])" icon="file">{{ __('Logs') }}</x-ap-button>
        @if ($appInstallerUrl)
            <x-ap-button :href="$appInstallerUrl" icon="package">{{ __('Quick Install App') }}</x-ap-button>
        @endif
        @if ($can['suspend'])
            @if ($record->suspended)
                <x-ap-button :form-action="route('web.domains.unsuspend', $params)" icon="play">{{ __('Unsuspend') }}</x-ap-button>
            @else
                <x-ap-button :form-action="route('web.domains.suspend', $params)" icon="pause" :confirm="sprintf(__('Are you sure you want to suspend domain %s?'), $record->domain)">{{ __('Suspend') }}</x-ap-button>
            @endif
        @endif
        @if ($can['delete'])
            <x-ap-button variant="danger" :form-action="route('web.domains.destroy', $params)" form-method="DELETE" icon="trash" :confirm="sprintf(__('Are you sure you want to delete domain %s?'), $record->domain)">{{ __('Delete') }}</x-ap-button>
        @endif
    </x-slot:actions>

    @if ($locked)
        <x-ap-alert tone="info" :title="__('Read-only')">{{ __('You can view these settings but not change them.') }}</x-ap-alert>
    @endif

    <x-ap-tabs :label="__('Domain settings')" :tabs="$tabs" initial="general">
        {{-- General --}}
        <x-ap-tab-panel name="general">
            <x-ap-card :title="__('General')" icon="globe">
                <x-ap-form :action="route('web.domains.update.general', $params)">
                    <fieldset class="ap-fieldset-plain" @disabled($locked)>
                        <x-ap-textarea name="aliases" :label="__('Aliases')" :value="implode(PHP_EOL, $record->aliases)" rows="3" mono bag="general"
                            :hint="__('One per line. Each alias gets the same certificate.')" />
                        <x-ap-select name="ip" :label="__('IP Address')" :options="$ipOptions" :value="$record->ip" bag="general" required />
                        <div class="ap-settings">
                            <x-ap-switch name="docroot_custom" :label="__('Custom document root')" :description="__('Serve the files of another of your domains, or of a folder.')"
                                :checked="$docroot !== null" bag="general" />
                        </div>
                        <div class="ap-form-grid">
                            <x-ap-select name="docroot_domain" :label="__('Point to')" :options="$domainOptions" :value="$docroot['domain'] ?? $record->domain" bag="general" />
                            <x-ap-input name="docroot_folder" :label="__('Directory')" :value="$docroot['folder'] ?? ''" mono bag="general"
                                :prefix="'/home/'.$record->user.'/web/…/public_html/'" />
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
                <x-ap-form :action="route('web.domains.update.ssl', $params)" x-data="{ ssl: {{ $record->ssl ? 'true' : 'false' }}, le: {{ $record->letsEncrypt ? 'true' : 'false' }} }">
                    <fieldset class="ap-fieldset-plain" @disabled($locked)>
                        <div class="ap-settings">
                            <x-ap-switch name="ssl" :label="__('Enable SSL for this domain')" :description="__('Serve this domain over HTTPS.')" :checked="$record->ssl" bag="ssl" x-model="ssl" />
                            <x-ap-switch name="letsencrypt" :label="__('Use Let’s Encrypt to obtain SSL certificate')" :description="__('Issue and renew the certificate automatically.')"
                                :checked="$record->letsEncrypt" bag="ssl" x-model="le" />
                            <x-ap-switch name="force" :label="__('Enable automatic HTTPS redirection')" :description="__('Redirect every HTTP request to HTTPS.')" :checked="$record->sslForce" bag="ssl" />
                            <x-ap-switch name="hsts" :label="__('Enable SSL HSTS')" :description="__('Browsers will refuse plain HTTP for a year.')" :checked="$record->sslHsts" bag="ssl" />
                        </div>
                        <x-ap-select name="key_algo" :label="__('Key type')" :options="['ecdsa-256' => 'ECDSA P-256', 'ecdsa-384' => 'ECDSA P-384', 'rsa' => 'RSA 4096']"
                            :value="$record->sslKeyAlgo" bag="ssl" :hint="__('Used for Let’s Encrypt certificates.')" />
                        <div class="ap-stack" x-show="ssl && !le">
                            <x-ap-textarea name="certificate" :label="__('SSL Certificate')" :value="$record->letsEncrypt ? '' : ($ssl?->certificate ?? '')" rows="6" mono bag="ssl" spellcheck="false" />
                            <x-ap-textarea name="key" :label="__('SSL Private Key')" rows="6" mono bag="ssl" spellcheck="false" autocomplete="off"
                                :hint="$record->ssl ? __('Leave empty to keep the current key.') : null" />
                            <x-ap-textarea name="ca" :label="__('SSL Certificate Authority / Intermediate')" :value="$record->letsEncrypt ? '' : ($ssl?->ca ?? '')" rows="4" mono bag="ssl" spellcheck="false" />
                        </div>
                    </fieldset>
                    @if ($ssl && $ssl->subject !== '')
                        <dl class="ap-dl">
                            <dt>{{ __('Subject') }}</dt><dd class="mono">{{ $ssl->subject }}</dd>
                            <dt>{{ __('Aliases') }}</dt><dd class="mono">{{ $ssl->aliases }}</dd>
                            <dt>{{ __('Issued By') }}</dt><dd>{{ $ssl->issuer }}</dd>
                            <dt>{{ __('Valid From') }}</dt><dd>{{ $ssl->notBefore }}</dd>
                            <dt>{{ __('Valid To') }}</dt><dd>{{ $ssl->notAfter }}</dd>
                        </dl>
                    @endif
                    @unless ($locked)
                        <div class="ap-form-actions"><x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button></div>
                    @endunless
                </x-ap-form>
            </x-ap-card>
        </x-ap-tab-panel>

        {{-- Redirect --}}
        <x-ap-tab-panel name="redirect">
            <x-ap-card :title="__('Redirect')" icon="arrow">
                <x-ap-form :action="route('web.domains.update.redirect', $params)">
                    <fieldset class="ap-fieldset-plain" @disabled($locked)>
                        <div class="ap-settings">
                            <x-ap-switch name="enabled" :label="__('Enable domain redirection')" :description="__('Send visitors of this domain to another address.')" :checked="$record->redirect !== ''" bag="redirect" />
                        </div>
                        <x-ap-radio-group name="target" :label="__('Redirect to')" bag="redirect" :value="$redirectChoice" :options="[
                            'www' => 'www.'.$record->domain,
                            'bare' => $record->domain,
                            'custom' => __('Custom URL'),
                        ]" />
                        <div class="ap-form-grid">
                            <x-ap-input name="custom" :label="__('Custom URL')" :value="$redirectChoice === 'custom' ? $record->redirect : ''" mono bag="redirect" placeholder="https://example.com/" />
                            <x-ap-select name="code" :label="__('Status code')" :options="['301' => '301 '.__('Moved permanently'), '302' => '302 '.__('Found')]" :value="(string) $record->redirectCode" bag="redirect" />
                        </div>
                    </fieldset>
                    @unless ($locked)
                        <div class="ap-form-actions"><x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button></div>
                    @endunless
                </x-ap-form>
            </x-ap-card>
        </x-ap-tab-panel>

        {{-- Advanced --}}
        @if ($can['updateTemplates'])
            <x-ap-tab-panel name="advanced">
                <x-ap-card :title="__('Advanced Options')" icon="layers">
                    <x-ap-form :action="route('web.domains.update.advanced', $params)">
                        @if ($systems['proxy'])
                            <div class="ap-settings">
                                <x-ap-switch name="proxy" :label="__('Proxy Support')" :description="__('Serve static files through the proxy server.')" :checked="$record->hasProxy()" bag="advanced" />
                            </div>
                        @endif
                        @include('web.partials.templates', [
                            'values' => ['template' => $record->template, 'backend_template' => $record->backendTemplate, 'proxy_template' => $record->proxyTemplate ?: 'default'],
                            'bag' => 'advanced',
                        ])
                        @if ($systems['proxy'])
                            <x-ap-textarea name="proxy_extensions" :label="__('Proxy Extensions')" :value="implode(', ', $record->proxyExtensions)" rows="3" mono bag="advanced" />
                        @endif
                        @if ($systems['nginx'])
                            <div class="ap-settings">
                                <x-ap-switch name="fastcgi_cache" :label="__('Enable FastCGI Cache')" :checked="$record->fastcgiCache" bag="advanced" />
                            </div>
                            <x-ap-input name="fastcgi_duration" :label="__('Cache Duration')" :value="$record->fastcgiDuration ?: '2m'" mono bag="advanced" :hint="__('For example 30s, 2m, 1h or 1d.')" />
                        @endif
                        <div class="ap-form-actions"><x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button></div>
                    </x-ap-form>
                </x-ap-card>
            </x-ap-tab-panel>
        @endif

        {{-- FTP --}}
        @if ($systems['ftp'])
            <x-ap-tab-panel name="ftp">
                <x-ap-card :title="__('Additional FTP accounts')" icon="key">
                    <x-ap-form :action="route('web.domains.update.ftp', $params)">
                        <fieldset class="ap-fieldset-plain" @disabled($locked)>
                            @forelse ($record->ftpAccounts as $index => $account)
                                <fieldset class="ap-ftp-account">
                                    <legend class="mono">{{ $account['user'] }}</legend>
                                    <input type="hidden" name="accounts[{{ $index }}][user]" value="{{ $account['user'] }}">
                                    <div class="ap-form-grid">
                                        <x-ap-input :name="'accounts['.$index.'][path]'" :label="__('Path')" :value="$account['path']" mono bag="ftp" :prefix="'/home/'.$record->user.'/web/'.$record->domain.'/'" />
                                        <x-ap-password :name="'accounts['.$index.'][password]'" :label="__('New password')" bag="ftp" :generate="true" :hint="__('Leave empty to keep the current password.')" />
                                        <x-ap-input :name="'accounts['.$index.'][email]'" :label="__('Send FTP credentials to email')" type="email" bag="ftp" />
                                    </div>
                                    <x-ap-checkbox :name="'accounts['.$index.'][delete]'" :label="__('Delete this account')" bag="ftp" />
                                </fieldset>
                            @empty
                                <p class="muted">{{ __('This domain has no additional FTP accounts.') }}</p>
                            @endforelse
                            <fieldset class="ap-ftp-account">
                                <legend>{{ __('Add FTP account') }}</legend>
                                <div class="ap-form-grid">
                                    <x-ap-input name="new[user]" :label="__('Username')" :prefix="$ftpPrefix" mono bag="ftp" autocomplete="off" />
                                    <x-ap-password name="new[password]" :label="__('Password')" bag="ftp" :generate="true" />
                                    <x-ap-input name="new[path]" :label="__('Path')" mono bag="ftp" :prefix="'/home/'.$record->user.'/web/'.$record->domain.'/'" />
                                    <x-ap-input name="new[email]" :label="__('Send FTP credentials to email')" type="email" bag="ftp" />
                                </div>
                            </fieldset>
                        </fieldset>
                        @unless ($locked)
                            <div class="ap-form-actions"><x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button></div>
                        @endunless
                    </x-ap-form>
                </x-ap-card>
            </x-ap-tab-panel>
        @endif

        {{-- Statistics --}}
        <x-ap-tab-panel name="stats">
            <x-ap-card :title="__('Statistics')" icon="chart">
                <x-ap-form :action="route('web.domains.update.stats', $params)">
                    <fieldset class="ap-fieldset-plain" @disabled($locked)>
                        <x-ap-select name="type" :label="__('Web Statistics')" :options="array_combine($statsEngines, $statsEngines)" :value="$record->stats ?: 'none'" bag="stats" />
                        <div class="ap-settings">
                            <x-ap-switch name="auth" :label="__('Statistics Authorization')" :description="__('Protect the statistics with a login.')" :checked="$record->statsUser !== ''" bag="stats" />
                        </div>
                        <div class="ap-form-grid">
                            <x-ap-input name="user" :label="__('Username')" :value="$record->statsUser" mono bag="stats" autocomplete="off" />
                            <x-ap-password name="password" :label="__('Password')" bag="stats" :generate="true" :hint="$record->statsUser !== '' ? __('Leave empty to keep the current password.') : null" />
                        </div>
                        @if ($record->stats !== '')
                            <p><a href="{{ ($record->ssl ? 'https' : 'http') }}://{{ $record->domain }}/vstats/" target="_blank" rel="noopener">{{ __('Open statistics') }}</a></p>
                        @endif
                    </fieldset>
                    @unless ($locked)
                        <div class="ap-form-actions"><x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button></div>
                    @endunless
                </x-ap-form>
            </x-ap-card>
        </x-ap-tab-panel>
    </x-ap-tabs>
</x-ap-page>
