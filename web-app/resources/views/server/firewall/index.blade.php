{{-- Firewall rules (web/list/firewall, E7 batch 2 spec §6.2). --}}
@php
    $columns = [
        ['key' => 'pos', 'label' => __('Pos'), 'sortable' => true],
        ['key' => 'action', 'label' => __('Action'), 'sortable' => true],
        ['key' => 'comment', 'label' => __('Comment'), 'sortable' => true],
        ['key' => 'protocol', 'label' => __('Protocol'), 'sortable' => true],
        ['key' => 'port', 'label' => __('Port'), 'sortable' => true],
        ['key' => 'ip', 'label' => __('IP Address'), 'sortable' => true],
        ['key' => 'status', 'label' => __('Status'), 'sortable' => true],
    ];
    $last = count($rules) - 1;
@endphp
<x-ap-page :title="__('Firewall')" :crumbs="[['label' => __('Server')], ['label' => __('Firewall')]]"
    :description="__('Rules that accept or drop traffic to this server, in the order they apply.')">
    <x-slot:actions>
        @if ($banlist)
            <x-ap-button :href="route('server.firewall.bans')" icon="lock">{{ __('Banned IP Addresses') }}</x-ap-button>
        @endif
        <x-ap-button :href="route('server.firewall.ipsets')" icon="list">{{ __('IPset IP Lists') }}</x-ap-button>
        @if ($canManage)
            <x-ap-button :href="route('server.firewall.create')" variant="primary" icon="plus">{{ __('Add Rule') }}</x-ap-button>
        @endif
    </x-slot:actions>

    @if ($status->needsReload())
        <x-ap-alert tone="warn" :title="$status->status === 'missing' ? __('The firewall rules are not loaded') : __('The loaded firewall rules differ from these rules')">
            {{ __('Something outside the panel changed or removed the nftables table of the firewall. Reload the firewall to apply the rules below again.') }}
            @if ($canManage)
                <x-slot:actions>
                    <x-ap-form :action="route('server.firewall.reload')">
                        <x-ap-button type="submit" icon="refresh">{{ __('Reload firewall') }}</x-ap-button>
                    </x-ap-form>
                </x-slot:actions>
            @endif
        </x-ap-alert>
    @endif

    <x-ap-card :flush="true">
        @if ($rules === [])
            <x-ap-empty icon="shield" :title="__('No firewall rules')">{{ __('Add a rule to accept or drop traffic.') }}</x-ap-empty>
        @else
            @if ($canManage)
                <x-ap-bulk-bar table="firewall-rules" :action="route('server.firewall.bulk')" :actions="$bulkActions" />
            @endif
            <x-ap-table id="firewall-rules" :columns="$columns" :selectable="$canManage" :search-label="__('Search rules')" :caption="__('Firewall')">
                @foreach ($rules as $index => $rule)
                    @php
                        $params = ['rule' => $rule->id];
                        $changeable = $canManage && ! $rule->managed();
                    @endphp
                    <tr data-row data-search="{{ $rule->comment }} {{ $rule->port }} {{ $rule->ip }} {{ $rule->protocol }}" @class(['is-disabled' => $rule->suspended])>
                        @if ($canManage)
                            <x-ap-row-check :value="$rule->id" :label="__('Select rule #:id', ['id' => $rule->id])" form="firewall-rules-bulk" :disabled="! $changeable" />
                        @endif
                        <td data-col="pos" data-label="{{ __('Pos') }}" data-sort-value="{{ (int) $rule->id }}">
                            <a class="mono" href="{{ route('server.firewall.edit', $params) }}" title="{{ __('Edit Firewall Rule') }}">{{ $rule->id }}</a>
                        </td>
                        <td data-col="action" data-label="{{ __('Action') }}" data-sort-value="{{ $rule->action }}">
                            <x-ap-badge :tone="$rule->action === 'DROP' ? 'danger' : 'ok'">{{ $rule->action }}</x-ap-badge>
                        </td>
                        <td data-col="comment" data-label="{{ __('Comment') }}" data-sort-value="{{ $rule->comment }}">
                            <span class="ap-trunc" title="{{ $rule->comment }}">{{ $rule->comment }}</span>
                            @if ($rule->managed())
                                <a href="{{ route('server.ports') }}"><x-ap-badge tone="info">{{ __('Managed by Ports') }}</x-ap-badge></a>
                            @endif
                        </td>
                        <td data-col="protocol" data-label="{{ __('Protocol') }}">{{ __($rule->protocol) }}</td>
                        <td data-col="port" data-label="{{ __('Port') }}" class="mono">{{ $rule->protocol === 'ICMP' ? '—' : $rule->port }}</td>
                        <td data-col="ip" data-label="{{ __('IP Address') }}" data-sort-value="{{ $rule->ip }}">
                            {{-- 0.0.0.0/0 and ::/0 match IPv4 and IPv6 alike since E40 --}}
                            @if (in_array($rule->ip, ['0.0.0.0/0', '::/0'], true))
                                <span title="{{ $rule->ip }}">{{ __('Any address') }}</span> <span class="muted">{{ __('IPv4 and IPv6') }}</span>
                            @else
                                <span class="mono">{{ $rule->ip }}</span>
                            @endif
                        </td>
                        <td data-col="status" data-label="{{ __('Status') }}" data-sort-value="{{ $rule->suspended ? 'suspended' : 'active' }}">
                            <x-ap-badge :tone="$rule->suspended ? 'danger' : 'ok'">{{ $rule->suspended ? __('Suspended') : __('Active') }}</x-ap-badge>
                        </td>
                        <td class="col-actions">
                            <details class="ap-dropdown ap-row-menu">
                                <summary class="ap-btn ap-btn-icon ap-btn-ghost ap-btn-sm" aria-label="{{ __('Actions for rule #:id', ['id' => $rule->id]) }}" title="{{ __('Actions') }}"><x-ap-icon name="more" /></summary>
                                <div class="ap-menu" role="group" aria-label="{{ __('Actions for rule #:id', ['id' => $rule->id]) }}">
                                    <a class="ap-menu-item" href="{{ route('server.firewall.edit', $params) }}"><x-ap-icon name="edit" />{{ __('Edit Firewall Rule') }}</a>
                                    @if ($canManage)
                                        @if ($index > 0)
                                            <x-ap-button class="ap-menu-item" :form-action="route('server.firewall.move', $params + ['direction' => 'up'])" icon="arrow-up"
                                                :confirm="sprintf(__('Are you sure you want to move rule #%s up?'), $rule->id)">{{ __('Move Firewall Rule Up') }}</x-ap-button>
                                        @endif
                                        @if ($index < $last)
                                            <x-ap-button class="ap-menu-item" :form-action="route('server.firewall.move', $params + ['direction' => 'down'])" icon="chevron-down"
                                                :confirm="sprintf(__('Are you sure you want to move rule #%s down?'), $rule->id)">{{ __('Move Firewall Rule Down') }}</x-ap-button>
                                        @endif
                                    @endif
                                    @if ($changeable)
                                        <div class="ap-menu-sep" role="separator"></div>
                                        @if ($rule->suspended)
                                            <x-ap-button class="ap-menu-item" :form-action="route('server.firewall.unsuspend', $params)" icon="play"
                                                :confirm="sprintf(__('Are you sure you want to unsuspend rule #%s?'), $rule->id)">{{ __('Unsuspend') }}</x-ap-button>
                                        @else
                                            <x-ap-button class="ap-menu-item" :form-action="route('server.firewall.suspend', $params)" icon="pause"
                                                :confirm="sprintf(__('Are you sure you want to suspend rule #%s?'), $rule->id)">{{ __('Suspend') }}</x-ap-button>
                                        @endif
                                        <x-ap-button class="ap-menu-item is-danger" variant="danger" :form-action="route('server.firewall.destroy', $params)" form-method="DELETE" icon="trash"
                                            :confirm="sprintf(__('Are you sure you want to delete rule #%s?'), $rule->id)">{{ __('Delete') }}</x-ap-button>
                                    @endif
                                </div>
                            </details>
                        </td>
                    </tr>
                @endforeach
                <x-slot:footer>
                    <span class="muted">{{ trans_choice(':count firewall rule|:count firewall rules', count($rules), ['count' => count($rules)]) }}</span>
                </x-slot:footer>
            </x-ap-table>
        @endif
    </x-ap-card>
</x-ap-page>
