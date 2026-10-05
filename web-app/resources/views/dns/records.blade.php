{{-- Records of a DNS zone (web/list/dns?domain=, E7 batch 1 spec §8.2), added and edited in a drawer. --}}
@php
    $params = ['user' => $zone->user, 'domain' => $zone->domain];
    $columns = [
        ['key' => 'record', 'label' => __('Record'), 'sortable' => true],
        ['key' => 'type', 'label' => __('Type'), 'sortable' => true],
        ['key' => 'priority', 'label' => __('Priority'), 'sortable' => true, 'type' => 'number'],
        ['key' => 'ttl', 'label' => __('TTL'), 'sortable' => true, 'type' => 'number', 'optional' => true],
        ['key' => 'value', 'label' => __('IP or Value'), 'sortable' => true],
        ['key' => 'status', 'label' => __('Status'), 'sortable' => true],
    ];
    $current = $editing?->fields() ?? ['record' => '@', 'type' => 'A', 'value' => '', 'priority' => '', 'ttl' => ''];
    $label = fn ($record) => $record->record.' '.$record->type;
    $recordForm = 'apDnsRecord('.\Illuminate\Support\Js::from(['type' => (string) old('type', $current['type']), 'priorityTypes' => $priorityTypes])->toHtml().')';
@endphp
<x-ap-page :title="__('DNS Records')" :crumbs="[['label' => __('DNS'), 'url' => route('dns.index')], ['label' => $zone->domain]]">
    <x-slot:head>
        <div class="ap-entity">
            <span class="ap-entity-icon"><x-ap-icon name="dns" /></span>
            <div class="ap-entity-text">
                <span class="eyebrow">{{ __('Owner') }}: {{ $zone->user }} · {{ __('DNS Records') }}</span>
                <h1 class="mono">{{ $zone->domain }}</h1>
                <div class="ap-row">
                    <x-ap-badge :tone="$zone->suspended ? 'danger' : 'ok'">{{ $zone->suspended ? __('Suspended') : __('Active') }}</x-ap-badge>
                    <x-ap-badge>{{ $zone->ip }}</x-ap-badge>
                    <x-ap-badge>{{ __('TTL') }} {{ $zone->ttl }}</x-ap-badge>
                </div>
            </div>
        </div>
    </x-slot:head>
    <x-slot:actions>
        <x-ap-button :href="route('dns.index')" icon="return">{{ __('Back') }}</x-ap-button>
        @if ($can['update'])
            <x-ap-button :href="route('dns.edit', $params)" icon="edit">{{ __('Edit DNS Domain') }}</x-ap-button>
            @if ($editing !== null)
                <x-ap-button :href="route('dns.records.index', $params + ['add' => 1])" variant="primary" icon="plus">{{ __('Add Record') }}</x-ap-button>
            @else
                <x-ap-button variant="primary" icon="plus" x-data x-on:click="$dispatch('ap-modal', { id: 'dns-record' })">{{ __('Add Record') }}</x-ap-button>
            @endif
        @endif
    </x-slot:actions>

    <x-ap-card :flush="true">
        @if ($canBulk)
            <x-ap-bulk-bar table="dns-records" :action="route('dns.records.bulk', $params)" :actions="$bulkActions" />
        @endif
        <x-ap-table id="dns-records" :columns="$columns" :selectable="$canBulk" :search-label="__('Search DNS records')" :caption="__('DNS Records')">
            @forelse ($records as $record)
                @php
                    $recordParams = $params + ['record' => $record->id];
                @endphp
                <tr data-row data-search="{{ $record->record }} {{ $record->type }} {{ $record->value }}" @class(['is-disabled' => $record->suspended])>
                    @if ($canBulk)
                        <x-ap-row-check :value="$record->id" :label="__('Select :name', ['name' => $label($record)])" form="dns-records-bulk" />
                    @endif
                    <td data-col="record" data-label="{{ __('Record') }}" data-sort-value="{{ $record->record }}">
                        @if ($can['update'] && ! $record->suspended)
                            <a class="mono ap-trunc" href="{{ route('dns.records.index', $params + ['edit' => $record->id]) }}" title="{{ __('Edit DNS Record') }}: {{ $record->record }}">{{ $record->record }}</a>
                        @else
                            <span class="mono ap-trunc" title="{{ $record->record }}">{{ $record->record }}</span>
                        @endif
                    </td>
                    <td data-col="type" data-label="{{ __('Type') }}"><x-ap-badge :mono="true">{{ $record->type }}</x-ap-badge></td>
                    <td data-col="priority" data-label="{{ __('Priority') }}" data-sort-value="{{ (int) $record->priority }}" class="mono">{{ $record->priority }}</td>
                    <td data-col="ttl" data-label="{{ __('TTL') }}" data-sort-value="{{ (int) $record->ttl }}" class="mono">{{ $record->ttl !== '' ? $record->ttl : __('Default') }}</td>
                    <td data-col="value" data-label="{{ __('IP or Value') }}" data-sort-value="{{ $record->value }}">
                        <span class="mono ap-break">{{ $record->value }}</span>
                    </td>
                    <td data-col="status" data-label="{{ __('Status') }}" data-sort-value="{{ $record->suspended ? 'suspended' : 'active' }}">
                        <x-ap-badge :tone="$record->suspended ? 'danger' : 'ok'">{{ $record->suspended ? __('Suspended') : __('Active') }}</x-ap-badge>
                    </td>
                    <td class="col-actions">
                        @if ($can['update'] || $can['delete'])
                            <details class="ap-dropdown ap-row-menu">
                                <summary class="ap-btn ap-btn-icon ap-btn-ghost ap-btn-sm" aria-label="{{ __('Actions for :name', ['name' => $label($record)]) }}" title="{{ __('Actions') }}"><x-ap-icon name="more" /></summary>
                                <div class="ap-menu" role="group" aria-label="{{ __('Actions for :name', ['name' => $label($record)]) }}">
                                    @if ($can['update'] && ! $record->suspended)
                                        <a class="ap-menu-item" href="{{ route('dns.records.index', $params + ['edit' => $record->id]) }}"><x-ap-icon name="edit" />{{ __('Edit DNS Record') }}</a>
                                    @endif
                                    @if ($can['suspend'])
                                        <div class="ap-menu-sep" role="separator"></div>
                                        @if ($record->suspended)
                                            <x-ap-button class="ap-menu-item" :form-action="route('dns.records.unsuspend', $recordParams)" icon="play">{{ __('Unsuspend') }}</x-ap-button>
                                        @else
                                            <x-ap-button class="ap-menu-item" :form-action="route('dns.records.suspend', $recordParams)" icon="pause">{{ __('Suspend') }}</x-ap-button>
                                        @endif
                                    @endif
                                    @if ($can['delete'])
                                        <x-ap-button class="ap-menu-item is-danger" variant="danger" :form-action="route('dns.records.destroy', $recordParams)" form-method="DELETE" icon="trash"
                                            :confirm="sprintf(__('Are you sure you want to delete record %s?'), $record->id)">{{ __('Delete') }}</x-ap-button>
                                    @endif
                                </div>
                            </details>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="{{ count($columns) + 2 }}" class="muted">{{ __('This zone has no records.') }}</td></tr>
            @endforelse
        </x-ap-table>
    </x-ap-card>

    @if ($can['update'])
        <x-ap-drawer id="dns-record" :title="$editing !== null ? __('Edit DNS Record') : __('Add DNS Record')" :description="$zone->domain" :open="$openForm">
            <x-ap-form :action="$editing !== null ? route('dns.records.update', $params + ['record' => $editing->id]) : route('dns.records.store', $params)"
                :x-data="$recordForm">
                @if (session('record_error'))
                    <x-ap-alert tone="danger">{{ session('record_error') }}</x-ap-alert>
                @endif
                <x-ap-input name="record" :label="__('Record')" :value="$current['record']" required mono autocomplete="off" spellcheck="false" />
                <x-ap-select name="type" :label="__('Type')" :options="array_combine($types, $types)" :value="$current['type']" required x-model="type" />
                <x-ap-input name="value" :label="__('IP or Value')" :value="$current['value']" required mono autocomplete="off" spellcheck="false" list="dns-record-ips" />
                <datalist id="dns-record-ips">
                    @foreach ($ips as $address)
                        <option value="{{ $address }}"></option>
                    @endforeach
                </datalist>
                <div x-show="hasPriority">
                    <x-ap-input name="priority" :label="__('Priority')" :value="$current['priority']" mono autocomplete="off" inputmode="numeric" x-bind:disabled="! hasPriority" />
                </div>
                <x-ap-input name="ttl" :label="__('TTL')" :value="$current['ttl']" mono autocomplete="off" inputmode="numeric" :placeholder="__('Default')" :hint="__('Optional')" />
                @if ($editing !== null)
                    <x-ap-input name="id" :label="__('Record ID')" :value="$editing->id" mono autocomplete="off" inputmode="numeric" />
                @endif
                <div class="ap-form-actions">
                    <x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button>
                    <x-ap-button variant="ghost" x-on:click="$dispatch('ap-modal', { id: 'dns-record', open: false })">{{ __('Cancel') }}</x-ap-button>
                </div>
            </x-ap-form>
        </x-ap-drawer>
    @endif
</x-ap-page>
