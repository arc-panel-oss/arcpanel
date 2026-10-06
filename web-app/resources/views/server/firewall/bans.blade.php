{{-- Banned IP addresses (web/list/firewall/banlist, E7 batch 2 spec §6.2). --}}
@php
    $columns = [
        ['key' => 'ip', 'label' => __('IP Address'), 'sortable' => true],
        ['key' => 'chain', 'label' => __('Chain'), 'sortable' => true],
        ['key' => 'date', 'label' => __('Date'), 'sortable' => true],
        ['key' => 'time', 'label' => __('Time'), 'sortable' => true],
    ];
@endphp
<x-ap-page :title="__('Banned IP Addresses')" :crumbs="[['label' => __('Server')], ['label' => __('Firewall'), 'url' => route('server.firewall')], ['label' => __('Banned IP Addresses')]]"
    :description="__('Addresses blocked by the brute-force protection, per service.')">
    <x-slot:actions>
        @if ($canManage)
            <x-ap-button :href="route('server.firewall.bans.create')" variant="primary" icon="plus">{{ __('Ban IP Address') }}</x-ap-button>
        @endif
    </x-slot:actions>

    <x-ap-card :flush="true">
        @if ($bans === [])
            <x-ap-empty icon="lock" :title="__('There are currently no banned IP addresses.')" />
        @else
            @if ($canManage)
                <x-ap-bulk-bar table="firewall-bans" :action="route('server.firewall.bans.bulk')"
                    :actions="['delete' => ['label' => __('Delete'), 'icon' => 'trash', 'danger' => true, 'confirm' => __('Unban %d IP addresses?')]]" />
            @endif
            <x-ap-table id="firewall-bans" :columns="$columns" :selectable="$canManage" :search-label="__('Search IP addresses')" :caption="__('Banned IP Addresses')">
                @foreach ($bans as $ban)
                    <tr data-row data-search="{{ $ban->ip }} {{ $ban->chain }}">
                        @if ($canManage)
                            <x-ap-row-check :value="$ban->item()" :label="__('Select :name', ['name' => $ban->ip])" form="firewall-bans-bulk" />
                        @endif
                        <td data-col="ip" data-label="{{ __('IP Address') }}" class="mono">{{ $ban->ip }}</td>
                        <td data-col="chain" data-label="{{ __('Chain') }}">{{ $ban->chain }}</td>
                        <td data-col="date" data-label="{{ __('Date') }}">{{ $ban->date }}</td>
                        <td data-col="time" data-label="{{ __('Time') }}">{{ $ban->time }}</td>
                        <td class="col-actions">
                            @if ($canManage)
                                <form method="post" action="{{ route('server.firewall.bans.destroy') }}" class="ap-inline-form"
                                    data-confirm="{{ sprintf(__('Are you sure you want to delete IP address %s?'), $ban->ip) }}" data-confirm-danger>
                                    @csrf
                                    @method('DELETE')
                                    <input type="hidden" name="item" value="{{ $ban->item() }}">
                                    <button type="submit" class="ap-btn ap-btn-ghost ap-btn-sm ap-btn-icon" aria-label="{{ __('Delete :name', ['name' => $ban->ip]) }}" title="{{ __('Delete') }}"><x-ap-icon name="trash" /></button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                <x-slot:footer>
                    <span class="muted">{{ trans_choice(':count banned IP address|:count banned IP addresses', count($bans), ['count' => count($bans)]) }}</span>
                </x-slot:footer>
            </x-ap-table>
        @endif
    </x-ap-card>
</x-ap-page>
