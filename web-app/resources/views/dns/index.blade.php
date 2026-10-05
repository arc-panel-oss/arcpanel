{{-- DNS zones list (web/list/dns, E7 batch 1 spec §8.2). --}}
@php
    $columns = array_values(array_filter([
        ['key' => 'name', 'label' => __('Name'), 'sortable' => true],
        $showOwner ? ['key' => 'owner', 'label' => __('Owner'), 'sortable' => true] : null,
        ['key' => 'records', 'label' => __('Records'), 'sortable' => true, 'type' => 'number'],
        ['key' => 'template', 'label' => __('Template'), 'sortable' => true, 'optional' => true],
        ['key' => 'ttl', 'label' => __('TTL'), 'sortable' => true, 'type' => 'number', 'optional' => true],
        ['key' => 'soa', 'label' => __('SOA'), 'sortable' => true, 'optional' => true],
        ['key' => 'dnssec', 'label' => __('DNSSEC'), 'sortable' => true],
        ['key' => 'exp', 'label' => __('Expiration Date'), 'sortable' => true, 'optional' => true],
        ['key' => 'status', 'label' => __('Status'), 'sortable' => true],
    ]));
    $filter = array_filter(['owner' => $owner]);
@endphp
<x-ap-page :title="__('DNS')" :crumbs="[['label' => __('DNS')]]"
    :description="$everyUser ? __('DNS zones of every user on this server.') : ($showOwner ? __('Your DNS zones and those of your customers.') : __('The DNS zones this server answers for.'))">
    <x-slot:actions>
        @if ($canCreate)
            <x-ap-button :href="route('dns.create', $filter)" variant="primary" icon="plus">{{ __('Add DNS Domain') }}</x-ap-button>
        @endif
    </x-slot:actions>

    <x-ap-card :flush="true">
        @if ($total === 0 && $owner === '')
            <x-ap-empty icon="dns" :title="__('No DNS zones yet')">{{ __('Add a DNS zone to answer for a domain on this server.') }}
                @if ($canCreate)
                    <x-slot:actions><x-ap-button :href="route('dns.create')" variant="primary" icon="plus">{{ __('Add DNS Domain') }}</x-ap-button></x-slot:actions>
                @endif
            </x-ap-empty>
        @else
            @if ($canBulk)
                <x-ap-bulk-bar table="dns-zones" :action="route('dns.bulk', $filter)" :actions="$bulkActions" />
            @endif
            <x-ap-table id="dns-zones" :columns="$columns" :selectable="$canBulk" :search-label="__('Search DNS zones')" :caption="__('DNS zones')">
                @if ($showOwner)
                    <x-slot:toolbar>
                        <x-ap-owner-filter route="dns.index" :owners="$owners" :owner="$owner" />
                    </x-slot:toolbar>
                @endif

                @forelse ($records as $record)
                    @php
                        $can = $abilities[$record->user];
                        $params = ['user' => $record->user, 'domain' => $record->domain];
                    @endphp
                    <tr data-row data-search="{{ $record->domain }} {{ $record->ip }} {{ $record->user }}" @class(['is-disabled' => $record->suspended])>
                        @if ($canBulk)
                            <x-ap-row-check :value="$record->user.'/'.$record->domain" :label="__('Select :name', ['name' => $record->domain])" form="dns-zones-bulk" />
                        @endif
                        <td data-col="name" data-label="{{ __('Name') }}" data-sort-value="{{ $record->domain }}">
                            <a class="mono" href="{{ route('dns.records.index', $params) }}" title="{{ __('DNS Records') }}: {{ $record->domain }}">{{ $record->domain }}</a>
                            @if ($record->src !== '')
                                <div class="muted mono">⇢ {{ $record->src }}</div>
                            @endif
                        </td>
                        @if ($showOwner)
                            <td data-col="owner" data-label="{{ __('Owner') }}" class="muted">{{ $record->user }}</td>
                        @endif
                        <td data-col="records" data-label="{{ __('Records') }}" data-sort-value="{{ $record->records }}">{{ $record->records }}</td>
                        <td data-col="template" data-label="{{ __('Template') }}">{{ $record->template }}</td>
                        <td data-col="ttl" data-label="{{ __('TTL') }}" data-sort-value="{{ (int) $record->ttl }}" class="mono">{{ $record->ttl }}</td>
                        <td data-col="soa" data-label="{{ __('SOA') }}" class="mono">{{ $record->soa }}</td>
                        <td data-col="dnssec" data-label="{{ __('DNSSEC') }}" data-sort-value="{{ $record->dnssec ? 'yes' : 'no' }}">
                            <x-ap-badge :tone="$record->dnssec ? 'ok' : null">{{ $record->dnssec ? __('Enabled') : __('Disabled') }}</x-ap-badge>
                        </td>
                        <td data-col="exp" data-label="{{ __('Expiration Date') }}" class="mono">{{ $record->exp }}</td>
                        <td data-col="status" data-label="{{ __('Status') }}" data-sort-value="{{ $record->suspended ? 'suspended' : 'active' }}">
                            <x-ap-badge :tone="$record->suspended ? 'danger' : 'ok'">{{ $record->suspended ? __('Suspended') : __('Active') }}</x-ap-badge>
                        </td>
                        <td class="col-actions">
                            <details class="ap-dropdown ap-row-menu">
                                <summary class="ap-btn ap-btn-icon ap-btn-ghost ap-btn-sm" aria-label="{{ __('Actions for :name', ['name' => $record->domain]) }}" title="{{ __('Actions') }}"><x-ap-icon name="more" /></summary>
                                <div class="ap-menu" role="group" aria-label="{{ __('Actions for :name', ['name' => $record->domain]) }}">
                                    <a class="ap-menu-item" href="{{ route('dns.records.index', $params) }}"><x-ap-icon name="list" />{{ __('DNS Records') }}</a>
                                    @if ($can['update'] && ! $record->suspended)
                                        <a class="ap-menu-item" href="{{ route('dns.records.index', $params + ['add' => 1]) }}"><x-ap-icon name="plus" />{{ __('Add DNS Record') }}</a>
                                        <a class="ap-menu-item" href="{{ route('dns.edit', $params) }}"><x-ap-icon name="edit" />{{ __('Edit DNS Domain') }}</a>
                                    @endif
                                    @if ($record->dnssec)
                                        <a class="ap-menu-item" href="{{ route('dns.dnssec', $params) }}"><x-ap-icon name="key" />{{ __('View Public DNSSEC Key') }}</a>
                                    @endif
                                    @if ($can['suspend'])
                                        <div class="ap-menu-sep" role="separator"></div>
                                        @if ($record->suspended)
                                            <x-ap-button class="ap-menu-item" :form-action="route('dns.unsuspend', $params)" icon="play"
                                                :confirm="sprintf(__('Are you sure you want to unsuspend domain %s?'), $record->domain)">{{ __('Unsuspend') }}</x-ap-button>
                                        @else
                                            <x-ap-button class="ap-menu-item" :form-action="route('dns.suspend', $params)" icon="pause"
                                                :confirm="sprintf(__('Are you sure you want to suspend domain %s?'), $record->domain)">{{ __('Suspend') }}</x-ap-button>
                                        @endif
                                    @endif
                                    @if ($can['delete'])
                                        <x-ap-button class="ap-menu-item is-danger" variant="danger" :form-action="route('dns.destroy', $params)" form-method="DELETE" icon="trash"
                                            :confirm="sprintf(__('Are you sure you want to delete domain %s?'), $record->domain)">{{ __('Delete') }}</x-ap-button>
                                    @endif
                                </div>
                            </details>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="{{ count($columns) + 2 }}" class="muted">{{ __('No DNS zones for this owner.') }}</td></tr>
                @endforelse

                <x-slot:footer>
                    <x-ap-pager :page="$page" :pages="$pages" :total="$total" :per-page="\App\Http\Controllers\Dns\DnsController::PAGE_SIZE"
                        :url="route('dns.index', $filter)" />
                </x-slot:footer>
            </x-ap-table>
        @endif
    </x-ap-card>
</x-ap-page>
