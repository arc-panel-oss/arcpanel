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
        'php' => $php !== null ? __('PHP') : null,
        'processes' => $appProcesses !== null ? __('App processes') : null,
        'deploy' => $deploy !== null ? __('Deploy') : null,
        'doctor' => $doctor !== null ? __('Doctor') : null,
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
        <x-ap-button size="sm" :href="($record->ssl ? 'https://' : 'http://').$record->domain.'/'" icon="external" target="_blank" rel="noopener">{{ __('Visit') }}</x-ap-button>
        @if ($fileManagerUrl)
            <x-ap-button size="sm" :href="$fileManagerUrl" icon="folder">{{ __('Open document root') }}</x-ap-button>
        @endif
        <x-ap-button size="sm" :href="route('web.domains.logs', $params + ['type' => 'access'])" icon="file">{{ __('Logs') }}</x-ap-button>
        @if ($appInstallerUrl)
            <x-ap-button size="sm" :href="$appInstallerUrl" icon="package">{{ __('Quick Install App') }}</x-ap-button>
        @endif
        @if ($can['suspend'])
            @if ($record->suspended)
                <x-ap-button size="sm" :form-action="route('web.domains.unsuspend', $params)" icon="play">{{ __('Unsuspend') }}</x-ap-button>
            @else
                <x-ap-button size="sm" :form-action="route('web.domains.suspend', $params)" icon="pause" :confirm="sprintf(__('Are you sure you want to suspend domain %s?'), $record->domain)">{{ __('Suspend') }}</x-ap-button>
            @endif
        @endif
        @if ($can['delete'])
            <x-ap-button size="sm" variant="danger" :form-action="route('web.domains.destroy', $params)" form-method="DELETE" icon="trash" :confirm="sprintf(__('Are you sure you want to delete domain %s?'), $record->domain)">{{ __('Delete') }}</x-ap-button>
        @endif
    </x-slot:actions>

    @if ($locked)
        <x-ap-alert tone="info" :title="__('Read-only')">{{ __('You can view these settings but not change them.') }}</x-ap-alert>
    @endif

    {{-- A Git Deploy answer with a one-time secret opens on its tab --}}
    <x-ap-tabs :label="__('Domain settings')" :tabs="$tabs" :initial="isset($deployOnce) ? 'deploy' : 'general'">
        {{-- General --}}
        <x-ap-tab-panel name="general">
            <x-ap-card :title="__('General')" icon="globe">
                <x-ap-form :action="route('web.domains.update.general', $params)">
                    <fieldset class="ap-fieldset-plain" @disabled($locked)>
                        <x-ap-textarea name="aliases" :label="__('Aliases')" :value="implode(PHP_EOL, $record->aliases)" rows="3" mono bag="general"
                            :hint="__('One per line. Each alias gets the same certificate.')" />
                        <x-ap-select name="ip" :label="__('IP Address')" :options="$ipOptions" :value="$record->ip" bag="general" required />
                        {{-- The target fields show while the switch is on (.ap-reveal-group) --}}
                        <div class="ap-reveal-group">
                            <div class="ap-settings">
                                <x-ap-switch name="docroot_custom" :label="__('Custom document root')" :description="__('Serve the files of another of your domains, or of a folder.')"
                                    :checked="$docroot !== null" bag="general" />
                            </div>
                            <div class="ap-form-grid ap-reveal">
                                <x-ap-select name="docroot_domain" :label="__('Point to')" :options="$domainOptions" :value="$docroot['domain'] ?? $record->domain" bag="general" />
                                <x-ap-input name="docroot_folder" :label="__('Directory')" :value="$docroot['folder'] ?? ''" mono bag="general"
                                    :prefix="'/home/'.$record->user.'/web/…/public_html/'" />
                            </div>
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
                <x-ap-form :action="route('web.domains.update.ssl', $params)" id="web-ssl-form" x-data="{ ssl: {{ $record->ssl ? 'true' : 'false' }}, le: {{ $record->letsEncrypt ? 'true' : 'false' }} }">
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
                        <div class="ap-form-actions">
                            <x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button>
                            <x-ap-csr-modal :action="route('web.domains.ssl.generate', $params)" form="web-ssl-form" :domain="$record->domain" :aliases="implode(', ', $record->aliases)" />
                        </div>
                    @endunless
                </x-ap-form>
            </x-ap-card>
        </x-ap-tab-panel>

        {{-- Redirect --}}
        <x-ap-tab-panel name="redirect">
            <x-ap-card :title="__('Redirect')" icon="arrow">
                <x-ap-form :action="route('web.domains.update.redirect', $params)">
                    <fieldset class="ap-fieldset-plain" @disabled($locked)>
                        {{-- The target fields show while redirection is on (.ap-reveal-group) --}}
                        <div class="ap-reveal-group">
                            <div class="ap-settings">
                                <x-ap-switch name="enabled" :label="__('Enable domain redirection')" :description="__('Send visitors of this domain to another address.')" :checked="$record->redirect !== ''" bag="redirect" />
                            </div>
                            <div class="ap-stack ap-reveal">
                                <x-ap-radio-group name="target" :label="__('Redirect to')" bag="redirect" :value="$redirectChoice" :options="[
                                    'www' => 'www.'.$record->domain,
                                    'bare' => $record->domain,
                                    'custom' => __('Custom URL'),
                                ]" />
                                <div class="ap-form-grid">
                                    <x-ap-input name="custom" :label="__('Custom URL')" :value="$redirectChoice === 'custom' ? $record->redirect : ''" mono bag="redirect" placeholder="https://example.com/" />
                                    <x-ap-select name="code" :label="__('Status code')" :options="['301' => '301 '.__('Moved permanently'), '302' => '302 '.__('Found')]" :value="(string) $record->redirectCode" bag="redirect" />
                                </div>
                            </div>
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

        {{-- PHP (E41) --}}
        @if ($php !== null)
            <x-ap-tab-panel name="php">
                @include('web.partials.php')
            </x-ap-tab-panel>
        @endif

        {{-- App processes (E42) --}}
        @if ($appProcesses !== null)
            <x-ap-tab-panel name="processes">
                <x-ap-card :title="__('App processes')" icon="terminal" :flush="$appProcesses['processes'] !== []">
                    <x-slot:actions>
                        @if ($appProcesses['create'] !== null)
                            <x-ap-button :href="$appProcesses['create']" size="sm"><x-ap-icon name="plus" />{{ __('Add process') }}</x-ap-button>
                        @endif
                        <x-ap-button :href="$appProcesses['list']" variant="ghost" size="sm">{{ __('All processes') }}<x-ap-icon name="chevron-right" /></x-ap-button>
                    </x-slot:actions>
                    @if ($appProcesses['processes'] === [])
                        <p class="muted">{{ __('No app process for this domain yet: run Laravel Octane, a queue worker or your own server for it.') }}</p>
                    @else
                        <ul class="ap-list">
                            @foreach ($appProcesses['processes'] as $process)
                                <li>
                                    <div class="ap-list-main"><a class="mono" href="{{ route('app-processes.show', ['user' => $record->user, 'domain' => $process->domain, 'name' => $process->name]) }}">{{ $process->name }}</a><span class="mono">{{ $process->preset }}{{ $process->port !== '' ? ' · :'.$process->port : '' }}</span></div>
                                    <x-ap-badge :tone="$process->suspended ? 'warn' : ($process->running() ? 'ok' : ($process->status === 'failed' ? 'danger' : null))">{{ $process->suspended ? __('Suspended') : ($process->running() ? __('Running') : ($process->status === 'failed' ? __('Failed') : __('Stopped'))) }}</x-ap-badge>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-ap-card>
            </x-ap-tab-panel>
        @endif

        {{-- Git Deploy (E20) --}}
        @if ($deploy !== null)
            <x-ap-tab-panel name="deploy">
                @include('web.partials.deploy')
            </x-ap-tab-panel>
        @endif

        {{-- Doctor (E17) --}}
        @if ($doctor !== null)
            <x-ap-tab-panel name="doctor">
                <x-ap-card>
                    <div class="ap-row" data-doctor-score="{{ $doctor['result']['score'] ?? '' }}">
                        <span>{{ $doctor['result']['score'] !== null ? __('Score :score', ['score' => $doctor['result']['score']]) : __('No score') }}</span>
                        <span class="muted">{{ $doctor['result']['checked_at'] !== null ? __('Checked :time', ['time' => $doctor['result']['checked_at']]) : __('Never checked') }}</span>
                        @if ($doctor['canRun'])
                            <x-ap-button size="sm" icon="refresh" :form-action="route('doctor.domain.run', $params)">{{ __('Run now') }}</x-ap-button>
                        @endif
                        <x-ap-button variant="ghost" size="sm" :href="route('doctor.domain', $params)">{{ __('Doctor page') }}<x-ap-icon name="chevron-right" /></x-ap-button>
                    </div>
                </x-ap-card>
                @include('doctor.partials.checks', [
                    'result' => $doctor['result'],
                    'fixUrl' => $doctor['canRun'] ? fn (string $check) => route('doctor.domain.fix', [...$params, 'check' => $check]) : null,
                    'links' => ['dns' => route('dns.records.index', $params), 'php' => '#php'],
                ])
            </x-ap-tab-panel>
        @endif

        {{-- FTP: the additional accounts in a table; added and edited in dialogs, deleted per row or in bulk --}}
        @if ($systems['ftp'])
            @php
                $ftpAction = route('web.domains.update.ftp', $params);
                $ftpBase = '/home/'.$record->user.'/web/'.$record->domain.'/';
                $ftpErrorKeys = $errors->getBag('ftp')->keys();
                $ftpAddOpen = collect($ftpErrorKeys)->contains(fn ($key) => str_starts_with($key, 'new.'));
                $ftpEditOpen = collect($ftpErrorKeys)->contains(fn ($key) => str_starts_with($key, 'accounts.'));
                $ftpColumns = [
                    ['key' => 'user', 'label' => __('Username'), 'sortable' => true],
                    ['key' => 'path', 'label' => __('Path'), 'sortable' => true],
                ];
            @endphp
            <x-ap-tab-panel name="ftp">
                <x-ap-card :title="__('Additional FTP accounts')" icon="key" :flush="true">
                    @unless ($locked)
                        <x-slot:actions>
                            <x-ap-button variant="primary" size="sm" icon="plus" x-on:click="$dispatch('ap-modal', { id: 'ftp-add' })">{{ __('FTP account') }}</x-ap-button>
                        </x-slot:actions>
                    @endunless
                    @if ($record->ftpAccounts === [])
                        <x-ap-empty icon="key" :title="__('No additional FTP accounts')">{{ __('An additional FTP account opens one folder of this domain and nothing else, for a developer or a deploy tool.') }}</x-ap-empty>
                    @else
                        @unless ($locked)
                            <x-ap-bulk-bar table="ftp-accounts" :action="$ftpAction"
                                :actions="['delete' => ['label' => __('Delete'), 'icon' => 'trash', 'danger' => true, 'confirm' => __('Delete %d FTP accounts?')]]" />
                        @endunless
                        <x-ap-table id="ftp-accounts" :columns="$ftpColumns" :selectable="! $locked" :actions-column="! $locked" :per-page="10"
                            :search-label="__('Search FTP accounts')" :caption="__('Additional FTP accounts')">
                            @foreach ($record->ftpAccounts as $account)
                                @php($account['path'] = ltrim($account['path'], '/'))
                                <tr data-row data-search="{{ $account['user'] }} {{ $account['path'] }}">
                                    @unless ($locked)
                                        <x-ap-row-check :value="$account['user']" :label="__('Select :name', ['name' => $account['user']])" form="ftp-accounts-bulk" />
                                    @endunless
                                    <td data-col="user" data-label="{{ __('Username') }}" data-sort-value="{{ $account['user'] }}"><span class="mono">{{ $account['user'] }}</span></td>
                                    <td data-col="path" data-label="{{ __('Path') }}" data-sort-value="{{ $account['path'] }}" class="mono ap-ftp-path" title="{{ $ftpBase.$account['path'] }}">
                                        <span class="muted ap-ftp-base">{{ $ftpBase }}</span>{{ $account['path'] !== '' ? $account['path'] : '.' }}
                                    </td>
                                    @unless ($locked)
                                        <td class="col-actions">
                                            <div class="ap-row-actions">
                                                <x-ap-button variant="ghost" size="sm" :icon-only="true" icon="edit" :label="sprintf(__('Edit FTP account %s'), $account['user'])"
                                                    x-on:click="$dispatch('ap-ftp-edit', {{ \Illuminate\Support\Js::from($account) }}); $dispatch('ap-modal', { id: 'ftp-edit' })" />
                                                <form method="post" action="{{ $ftpAction }}" class="ap-inline-form" data-confirm-danger
                                                    data-confirm="{{ sprintf(__('Are you sure you want to delete FTP account %s?'), $account['user']) }}" data-confirm-accept="{{ __('Delete') }}">
                                                    @csrf
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="items[]" value="{{ $account['user'] }}">
                                                    <button type="submit" class="ap-btn ap-btn-ghost ap-btn-sm ap-btn-icon is-danger" aria-label="{{ sprintf(__('Delete FTP account %s'), $account['user']) }}"
                                                        title="{{ sprintf(__('Delete FTP account %s'), $account['user']) }}"><x-ap-icon name="trash" /></button>
                                                </form>
                                            </div>
                                        </td>
                                    @endunless
                                </tr>
                            @endforeach
                        </x-ap-table>
                    @endif
                </x-ap-card>

                @unless ($locked)
                    <x-ap-modal id="ftp-add" :title="__('Add FTP account')" icon="key" :open="$ftpAddOpen"
                        :description="__('The account opens the folder below and the folders in it, nothing else.')">
                        <x-ap-form :action="$ftpAction" id="ftp-add-form">
                            <x-ap-input name="new[user]" :label="__('Username')" :prefix="$ftpPrefix" mono bag="ftp" autocomplete="off" required />
                            <x-ap-password name="new[password]" :label="__('Password')" bag="ftp" :generate="true" required />
                            <x-ap-input name="new[path]" :label="__('Path')" mono bag="ftp" :hint="sprintf(__('A folder in %s; empty for that folder itself.'), $ftpBase)" />
                            <x-ap-input name="new[email]" :label="__('Send FTP credentials to email')" type="email" bag="ftp" />
                        </x-ap-form>
                        <x-slot:footer>
                            <x-ap-button variant="ghost" x-on:click="close()">{{ __('Cancel') }}</x-ap-button>
                            <x-ap-button type="submit" form="ftp-add-form" variant="primary" icon="plus">{{ __('Add FTP account') }}</x-ap-button>
                        </x-slot:footer>
                    </x-ap-modal>

                    <x-ap-modal id="ftp-edit" :title="__('Edit FTP account')" icon="edit" :open="$ftpEditOpen">
                        <div x-data="{ user: @js((string) old('accounts.0.user', '')), path: @js((string) old('accounts.0.path', '')) }"
                            x-on:ap-ftp-edit.window="user = $event.detail.user; path = $event.detail.path">
                            <x-ap-form :action="$ftpAction" id="ftp-edit-form">
                                <input type="hidden" name="accounts[0][user]" x-bind:value="user">
                                <div class="ap-field">
                                    <span class="ap-label">{{ __('Username') }}</span>
                                    <span class="mono" x-text="user"></span>
                                </div>
                                <x-ap-input name="accounts[0][path]" :label="__('Path')" mono bag="ftp" x-model="path" :hint="sprintf(__('A folder in %s; empty for that folder itself.'), $ftpBase)" />
                                <x-ap-password name="accounts[0][password]" :label="__('New password')" bag="ftp" :generate="true" :hint="__('Leave empty to keep the current password.')" />
                                <x-ap-input name="accounts[0][email]" :label="__('Send FTP credentials to email')" type="email" bag="ftp" :hint="__('Sent only with a new password.')" />
                            </x-ap-form>
                        </div>
                        <x-slot:footer>
                            <x-ap-button variant="ghost" x-on:click="close()">{{ __('Cancel') }}</x-ap-button>
                            <x-ap-button type="submit" form="ftp-edit-form" variant="primary" icon="check">{{ __('Save') }}</x-ap-button>
                        </x-slot:footer>
                    </x-ap-modal>
                @endunless
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
