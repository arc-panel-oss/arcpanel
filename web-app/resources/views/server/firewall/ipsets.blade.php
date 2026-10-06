{{-- IPset lists of the firewall (web/list/firewall/ipset, E7 batch 2 spec §6.2). --}}
@php
    $columns = [
        ['key' => 'name', 'label' => __('IP List Name'), 'sortable' => true],
        ['key' => 'autoupdate', 'label' => __('Auto Update'), 'sortable' => true],
        ['key' => 'version', 'label' => __('IP Version'), 'sortable' => true],
        ['key' => 'date', 'label' => __('Date'), 'sortable' => true],
        ['key' => 'time', 'label' => __('Time'), 'sortable' => true],
    ];
@endphp
<x-ap-page :title="__('IPset IP Lists')" :crumbs="[['label' => __('Server')], ['label' => __('Firewall'), 'url' => route('server.firewall')], ['label' => __('IPset IP Lists')]]"
    :description="__('Named address lists that firewall rules can use as their source.')">
    <x-slot:actions>
        @if ($canManage)
            <x-ap-button :href="route('server.firewall.ipsets.create')" variant="primary" icon="plus">{{ __('Add IP list') }}</x-ap-button>
        @endif
    </x-slot:actions>

    <x-ap-card :flush="true">
        @if ($lists === [])
            <x-ap-empty icon="list" :title="__('There are currently no IP lists defined.')" />
        @else
            @if ($canManage)
                <x-ap-bulk-bar table="firewall-ipsets" :action="route('server.firewall.ipsets.bulk')"
                    :actions="['delete' => ['label' => __('Delete'), 'icon' => 'trash', 'danger' => true, 'confirm' => __('Delete %d IP lists?')]]" />
            @endif
            <x-ap-table id="firewall-ipsets" :columns="$columns" :selectable="$canManage" :search-label="__('Search IP lists')" :caption="__('IPset IP Lists')">
                @foreach ($lists as $list)
                    <tr data-row data-search="{{ $list->name }} {{ $list->source }}" @class(['is-disabled' => $list->suspended])>
                        @if ($canManage)
                            <x-ap-row-check :value="$list->name" :label="__('Select :name', ['name' => $list->name])" form="firewall-ipsets-bulk" />
                        @endif
                        <td data-col="name" data-label="{{ __('IP List Name') }}" data-sort-value="{{ $list->name }}">
                            <span class="mono">{{ $list->name }}</span>
                            <div class="muted ap-trunc" title="{{ $list->source }}">{{ $list->source }}</div>
                        </td>
                        <td data-col="autoupdate" data-label="{{ __('Auto Update') }}" data-sort-value="{{ $list->autoUpdate ? 1 : 0 }}">
                            <x-ap-badge :tone="$list->autoUpdate ? 'ok' : null">{{ $list->autoUpdate ? __('Enabled') : __('Disabled') }}</x-ap-badge>
                        </td>
                        <td data-col="version" data-label="{{ __('IP Version') }}">{{ $list->ipVersion }}</td>
                        <td data-col="date" data-label="{{ __('Date') }}">{{ $list->date }}</td>
                        <td data-col="time" data-label="{{ __('Time') }}">{{ $list->time }}</td>
                        <td class="col-actions">
                            @if ($canManage)
                                <x-ap-button variant="ghost" size="sm" :icon-only="true" icon="trash" :label="__('Delete :name', ['name' => $list->name])"
                                    :form-action="route('server.firewall.ipsets.destroy', ['name' => $list->name])" form-method="DELETE"
                                    :confirm="sprintf(__('Are you sure you want to delete IP list %s?'), $list->name)" />
                            @endif
                        </td>
                    </tr>
                @endforeach
                <x-slot:footer>
                    <span class="muted">{{ trans_choice(':count IP list|:count IP lists', count($lists), ['count' => count($lists)]) }}</span>
                </x-slot:footer>
            </x-ap-table>
        @endif
    </x-ap-card>
</x-ap-page>
