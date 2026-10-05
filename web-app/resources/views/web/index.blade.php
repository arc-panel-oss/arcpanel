{{-- Web domains list (web/list/web, E1-S2 spec §8.1). --}}
@php
    $columns = array_values(array_filter([
        ['key' => 'domain', 'label' => __('Domain'), 'sortable' => true],
        $showOwner ? ['key' => 'owner', 'label' => __('Owner'), 'sortable' => true] : null,
        ['key' => 'ip', 'label' => __('IP Address'), 'sortable' => true, 'optional' => true],
        ['key' => 'template', 'label' => __('Web Template'), 'optional' => true],
        $systems['backend'] ? ['key' => 'backend', 'label' => __('Backend Template'), 'optional' => true] : null,
        $systems['proxy'] ? ['key' => 'proxy', 'label' => __('Proxy Template'), 'optional' => true] : null,
        ['key' => 'ssl', 'label' => __('SSL')],
        ['key' => 'disk', 'label' => __('Disk'), 'sortable' => true, 'type' => 'number', 'optional' => true],
        ['key' => 'bandwidth', 'label' => __('Bandwidth'), 'sortable' => true, 'type' => 'number', 'optional' => true],
        ['key' => 'status', 'label' => __('Status'), 'sortable' => true],
    ]));
    $size = fn (int $mb) => \App\Services\Dashboard\DashboardService::size($mb);
    $publicIp = fn (string $ip) => trim((string) ($ips[$ip]['NAT'] ?? '')) !== '' ? $ips[$ip]['NAT'] : $ip;
@endphp
<x-ap-page :title="__('Web domains')" :crumbs="[['label' => __('Web')], ['label' => __('Web domains')]]"
    :description="$everyUser ? __('Web domains of every user on this server.') : ($showOwner ? __('Your web domains and those of your customers.') : __('Your websites and their settings.'))">
    <x-slot:actions>
        @if ($canCreate)
            <x-ap-button :href="route('web.domains.create', array_filter(['owner' => $owner]))" variant="primary" icon="plus">{{ __('Add Web Domain') }}</x-ap-button>
        @endif
    </x-slot:actions>

    <x-ap-card :flush="true">
        @if ($total === 0 && $owner === '')
            <x-ap-empty icon="globe" :title="__('No web domains yet')">{{ __('Add your first domain to create its document root, DNS zone and mail domain in one step.') }}
                @if ($canCreate)
                    <x-slot:actions><x-ap-button :href="route('web.domains.create')" variant="primary" icon="plus">{{ __('Add Web Domain') }}</x-ap-button></x-slot:actions>
                @endif
            </x-ap-empty>
        @else
            @if ($canBulk)
                <x-ap-bulk-bar table="web-domains" :action="route('web.domains.bulk', array_filter(['owner' => $owner]))" :actions="$bulkActions" />
            @endif
            <x-ap-table id="web-domains" :columns="$columns" :selectable="$canBulk" :search-label="__('Search web domains')" :caption="__('Web domains')">
                @if ($showOwner)
                    <x-slot:toolbar>
                        <x-ap-owner-filter route="web.domains.index" :owners="$owners" :owner="$owner" />
                    </x-slot:toolbar>
                @endif

                @forelse ($records as $record)
                    @php
                        $can = $abilities[$record->user];
                        $key = $record->user.'/'.$record->domain;
                        $params = ['user' => $record->user, 'domain' => $record->domain];
                    @endphp
                    <tr data-row data-search="{{ $record->domain }} {{ implode(' ', $record->aliases) }} {{ $record->user }}" @class(['is-disabled' => $record->suspended])>
                        @if ($canBulk)
                            <x-ap-row-check :value="$key" :label="__('Select :domain', ['domain' => $record->domain])" form="web-domains-bulk" />
                        @endif
                        <td data-col="domain" data-label="{{ __('Domain') }}" data-sort-value="{{ $record->domain }}">
                            <div class="ap-cell-main">
                                <a class="mono" href="{{ route('web.domains.edit', $params) }}">{{ $record->domain }}</a>
                                @if ($record->extraAliases() !== [])<span class="ap-trunc">{{ implode(', ', $record->extraAliases()) }}</span>@endif
                            </div>
                        </td>
                        @if ($showOwner)
                            <td data-col="owner" data-label="{{ __('Owner') }}" class="muted">{{ $record->user }}</td>
                        @endif
                        <td data-col="ip" data-label="{{ __('IP Address') }}" class="mono">{{ $publicIp($record->ip) }}</td>
                        <td data-col="template" data-label="{{ __('Web Template') }}" class="muted" title="{{ $record->template }}">{{ $templateTitles['web'][$record->template] ?? $record->template }}</td>
                        @if ($systems['backend'])
                            <td data-col="backend" data-label="{{ __('Backend Template') }}" class="muted">{{ $record->backendTemplate }}</td>
                        @endif
                        @if ($systems['proxy'])
                            <td data-col="proxy" data-label="{{ __('Proxy Template') }}" class="muted" title="{{ $record->proxyTemplate }}">{{ $record->proxyTemplate !== '' ? ($templateTitles['proxy'][$record->proxyTemplate] ?? $record->proxyTemplate) : '—' }}</td>
                        @endif
                        <td data-col="ssl" data-label="{{ __('SSL') }}">
                            @if ($record->ssl)
                                <x-ap-badge tone="ok">{{ $record->letsEncrypt ? __('Let’s Encrypt') : __('Enabled') }}</x-ap-badge>
                            @else
                                <x-ap-badge>{{ __('Disabled') }}</x-ap-badge>
                            @endif
                        </td>
                        <td data-col="disk" data-label="{{ __('Disk') }}" class="col-num mono" data-sort-value="{{ $record->diskMb }}">{{ $size($record->diskMb) }}</td>
                        <td data-col="bandwidth" data-label="{{ __('Bandwidth') }}" class="col-num mono" data-sort-value="{{ $record->bandwidthMb }}">{{ $size($record->bandwidthMb) }}</td>
                        <td data-col="status" data-label="{{ __('Status') }}" data-sort-value="{{ $record->suspended ? 'suspended' : 'active' }}">
                            <x-ap-badge :tone="$record->suspended ? 'danger' : 'ok'">{{ $record->suspended ? __('Suspended') : __('Active') }}</x-ap-badge>
                        </td>
                        <td class="col-actions">
                            <details class="ap-dropdown ap-row-menu">
                                <summary class="ap-btn ap-btn-icon ap-btn-ghost ap-btn-sm" aria-label="{{ __('Actions for :domain', ['domain' => $record->domain]) }}" title="{{ __('Actions') }}"><x-ap-icon name="more" /></summary>
                                <div class="ap-menu" role="group" aria-label="{{ __('Actions for :domain', ['domain' => $record->domain]) }}">
                                    <a class="ap-menu-item" href="{{ route('web.domains.edit', $params) }}"><x-ap-icon name="edit" />{{ __('Edit Domain') }}</a>
                                    <a class="ap-menu-item" href="{{ route('web.domains.logs', $params + ['type' => 'access']) }}"><x-ap-icon name="file" />{{ __('View Logs') }}</a>
                                    <a class="ap-menu-item" href="http://{{ $record->domain }}/" target="_blank" rel="noopener"><x-ap-icon name="external" />{{ __('Visit') }}</a>
                                    @if ($can['suspend'])
                                        <div class="ap-menu-sep" role="separator"></div>
                                        @if ($record->suspended)
                                            <x-ap-button class="ap-menu-item" :form-action="route('web.domains.unsuspend', $params)" icon="play">{{ __('Unsuspend') }}</x-ap-button>
                                        @else
                                            <x-ap-button class="ap-menu-item" :form-action="route('web.domains.suspend', $params)" icon="pause"
                                                :confirm="sprintf(__('Are you sure you want to suspend domain %s?'), $record->domain)">{{ __('Suspend') }}</x-ap-button>
                                        @endif
                                    @endif
                                    @if ($can['rebuild'])
                                        <x-ap-button class="ap-menu-item" :form-action="route('web.domains.rebuild', $params)" icon="refresh">{{ __('Rebuild') }}</x-ap-button>
                                    @endif
                                    @if ($canPurge && $can['update'] && ($record->fastcgiCache || $record->proxyTemplate === 'caching'))
                                        <x-ap-button class="ap-menu-item" :form-action="route('web.domains.purge-cache', $params)" icon="layers">{{ __('Purge Nginx Cache') }}</x-ap-button>
                                    @endif
                                    @if ($can['delete'])
                                        <x-ap-button class="ap-menu-item is-danger" variant="danger" :form-action="route('web.domains.destroy', $params)" form-method="DELETE" icon="trash"
                                            :confirm="sprintf(__('Are you sure you want to delete domain %s?'), $record->domain)">{{ __('Delete') }}</x-ap-button>
                                    @endif
                                </div>
                            </details>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="{{ count($columns) + 2 }}" class="muted">{{ __('No web domains for this owner.') }}</td></tr>
                @endforelse

                <x-slot:footer>
                    <x-ap-pager :page="$page" :pages="$pages" :total="$total" :per-page="\App\Http\Controllers\WebDomains\WebDomainController::PAGE_SIZE"
                        :url="route('web.domains.index', array_filter(['owner' => $owner]))" />
                </x-slot:footer>
            </x-ap-table>
        @endif
    </x-ap-card>
</x-ap-page>
