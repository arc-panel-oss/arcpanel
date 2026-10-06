{{--
    Data table (docs/design/05 §5) around server-rendered rows.
    columns: list of [key, label, sortable?, type? ('number'), optional? (column chooser),
    hidden? (optional and left out until the viewer picks it), class?]
    Rows: <tr data-row data-search="…"> with <td data-col="key" data-label="Label">.
    selectable: rows carry <x-ap-row-check> boxes that belong to the bulk form.
    apTable adds client sort, filter, column chooser (per id in localStorage)
    and selection; below 768 px rows render as cards.
--}}
@props([
    'id',
    'columns' => [],
    'selectable' => false,
    'searchable' => true,
    'searchLabel' => null,
    'empty' => null,
    'toolbar' => null,
    'footer' => null,
    'actionsColumn' => true,
    'caption' => null,
])
@php
    $optional = array_values(array_filter($columns, fn ($c) => ($c['optional'] ?? false) || ($c['hidden'] ?? false)));
    $defaultHidden = array_values(array_map(fn ($c) => $c['key'], array_filter($columns, fn ($c) => $c['hidden'] ?? false)));
@endphp
@if ($defaultHidden !== [])
    {{-- Default-hidden columns stay out of the first paint; apTable takes over (data-columns-ready) --}}
    <style>@foreach ($defaultHidden as $key)#{{ $id }}:not([data-columns-ready]) [data-col="{{ $key }}"]{display:none}@endforeach</style>
@endif
<div {{ $attributes->merge(['class' => 'ap-table-block']) }} x-data="apTable({ id: @js($id) })">
    @if ($searchable || $toolbar || $optional)
        <div class="ap-toolbar">
            @if ($searchable)
                <div class="ap-input-affix ap-toolbar-search" x-cloak>
                    <x-ap-icon name="search" size="sm" />
                    <input class="ap-input" type="search" x-model="query" placeholder="{{ $searchLabel ?? __('Search') }}" aria-label="{{ $searchLabel ?? __('Search') }}" aria-controls="{{ $id }}">
                </div>
            @endif
            {{ $toolbar }}
            <div class="ap-topbar-spacer"></div>
            @if ($optional)
                <details class="ap-dropdown" x-cloak>
                    <summary class="ap-btn ap-btn-ghost ap-btn-sm"><x-ap-icon name="sliders" />{{ __('Columns') }}</summary>
                    <div class="ap-menu" role="group" aria-label="{{ __('Columns') }}">
                        @foreach ($optional as $column)
                            <label class="ap-menu-item ap-check"><input type="checkbox" x-bind:checked="!isHidden(@js($column['key']))" x-on:change="toggleColumn(@js($column['key']))"><span>{{ $column['label'] }}</span></label>
                        @endforeach
                    </div>
                </details>
            @endif
        </div>
    @endif
    <div class="ap-table-wrap">
        <table class="ap-table ap-table-cards" id="{{ $id }}">
            @if ($caption)<caption class="sr-only">{{ $caption }}</caption>@endif
            <thead>
                <tr>
                    @if ($selectable)
                        <th scope="col" class="col-check"><label class="ap-check"><input type="checkbox" data-select-all aria-label="{{ __('Select all rows') }}" x-cloak></label></th>
                    @endif
                    @foreach ($columns as $column)
                        @php($key = $column['key'])
                        <th scope="col" data-col="{{ $key }}"
                            @class([$column['class'] ?? null, 'col-num' => ($column['type'] ?? null) === 'number'])
                            @if (($column['optional'] ?? false) || ($column['hidden'] ?? false)) data-optional @endif
                            @if ($column['hidden'] ?? false) data-default-hidden @endif
                            @if (($column['type'] ?? null) === 'number') data-sort-type="number" @endif
                            @if ($column['sortable'] ?? false) x-bind:aria-sort="ariaSort(@js($key))" @endif>
                            @if ($column['sortable'] ?? false)
                                <button type="button" x-on:click="sort(@js($key))">{{ $column['label'] }}<x-ap-icon name="updown" size="sm" /></button>
                            @else
                                {{ $column['label'] }}
                            @endif
                        </th>
                    @endforeach
                    @if ($actionsColumn)
                        <th scope="col" class="col-actions"><span class="sr-only">{{ __('Actions') }}</span></th>
                    @endif
                </tr>
            </thead>
            <tbody>
                {{ $slot }}
            </tbody>
        </table>
        <p class="ap-table-nomatch" data-table-empty hidden>{{ __('No results') }}</p>
    </div>
    @if ($empty)
        {{ $empty }}
    @endif
    @if ($footer)
        <div class="ap-card-foot">{{ $footer }}</div>
    @endif
</div>
