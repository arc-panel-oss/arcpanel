{{-- IP addresses of the server (web/list/ip, E7 batch 2 spec §7.2). --}}
@php
    $columns = [
        ['key' => 'ip', 'label' => __('IP Address'), 'sortable' => true],
        ['key' => 'netmask', 'label' => __('Netmask'), 'sortable' => true],
        ['key' => 'interface', 'label' => __('Interface'), 'sortable' => true],
        ['key' => 'status', 'label' => __('Status'), 'sortable' => true],
        ['key' => 'domains', 'label' => __('Domains'), 'sortable' => true],
        ['key' => 'owner', 'label' => __('Owner'), 'sortable' => true],
    ];
@endphp
<x-ap-page :title="__('IP Addresses')" :crumbs="[['label' => __('Server')], ['label' => __('IP Addresses')]]"
    :description="__('Addresses of this server, who may use them and how they map to public addresses.')">
    <x-slot:actions>
        @if ($canManage)
            <x-ap-button :form-action="route('server.ips.refresh')" icon="refresh">{{ __('Refresh IP Addresses') }}</x-ap-button>
            <x-ap-button :href="route('server.ips.create')" variant="primary" icon="plus">{{ __('Add IP Address') }}</x-ap-button>
        @endif
    </x-slot:actions>

    <x-ap-card :flush="true">
        @if ($ips === [])
            <x-ap-empty icon="hash" :title="__('No IP addresses')" />
        @else
            @if ($canManage)
                <x-ap-bulk-bar table="server-ips" :action="route('server.ips.bulk')"
                    :actions="['delete' => ['label' => __('Delete'), 'icon' => 'trash', 'danger' => true, 'confirm' => __('Delete %d IP addresses?')]]" />
            @endif
            <x-ap-table id="server-ips" :columns="$columns" :selectable="$canManage" :search-label="__('Search IP addresses')" :caption="__('IP Addresses')">
                @foreach ($ips as $ip)
                    @php($params = ['ip' => $ip->ip])
                    <tr data-row data-search="{{ $ip->ip }} {{ $ip->nat }} {{ $ip->owner }} {{ $ip->name }}">
                        @if ($canManage)
                            <x-ap-row-check :value="$ip->ip" :label="__('Select :name', ['name' => $ip->ip])" form="server-ips-bulk" />
                        @endif
                        <td data-col="ip" data-label="{{ __('IP Address') }}" data-sort-value="{{ $ip->ip }}">
                            <a class="mono" href="{{ route('server.ips.edit', $params) }}" title="{{ __('Edit IP Address') }}">{{ $ip->ip }}</a>
                            @if ($ip->nat !== '')
                                <span class="muted mono">→ {{ $ip->nat }}</span>
                            @endif
                            @if ($ip->name !== '')
                                <div class="muted">{{ $ip->name }}</div>
                            @endif
                        </td>
                        <td data-col="netmask" data-label="{{ __('Netmask') }}" class="mono">{{ $ip->netmask }}</td>
                        <td data-col="interface" data-label="{{ __('Interface') }}" class="mono">{{ $ip->interface }}</td>
                        <td data-col="status" data-label="{{ __('Status') }}" data-sort-value="{{ $ip->status }}">
                            <x-ap-badge :tone="$ip->shared() ? 'info' : 'accent'">{{ __($ip->status) }}</x-ap-badge>
                        </td>
                        <td data-col="domains" data-label="{{ __('Domains') }}" data-sort-value="{{ (int) $ip->domains }}">{{ $ip->domains }}</td>
                        <td data-col="owner" data-label="{{ __('Owner') }}" class="muted">{{ $ip->owner }}</td>
                        <td class="col-actions">
                            <details class="ap-dropdown ap-row-menu">
                                <summary class="ap-btn ap-btn-icon ap-btn-ghost ap-btn-sm" aria-label="{{ __('Actions for :name', ['name' => $ip->ip]) }}" title="{{ __('Actions') }}"><x-ap-icon name="more" /></summary>
                                <div class="ap-menu" role="group" aria-label="{{ __('Actions for :name', ['name' => $ip->ip]) }}">
                                    <a class="ap-menu-item" href="{{ route('server.ips.edit', $params) }}"><x-ap-icon name="edit" />{{ __('Edit IP Address') }}</a>
                                    @if ($canManage)
                                        <x-ap-button class="ap-menu-item is-danger" variant="danger" :form-action="route('server.ips.destroy', $params)" form-method="DELETE" icon="trash"
                                            :confirm="sprintf(__('Are you sure you want to delete IP address %s?'), $ip->ip)">{{ __('Delete') }}</x-ap-button>
                                    @endif
                                </div>
                            </details>
                        </td>
                    </tr>
                @endforeach
                <x-slot:footer>
                    <span class="muted">{{ trans_choice(':count IP address|:count IP addresses', count($ips), ['count' => count($ips)]) }}</span>
                </x-slot:footer>
            </x-ap-table>
        @endif
    </x-ap-card>
</x-ap-page>
