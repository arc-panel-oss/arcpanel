{{-- Action log of an account (web/list/log, E7 batch 2 spec §9.2). --}}
@php
    $columns = [
        ['key' => 'level', 'label' => __('Level'), 'sortable' => true],
        ['key' => 'date', 'label' => __('Date'), 'sortable' => true],
        ['key' => 'time', 'label' => __('Time'), 'sortable' => true],
        ['key' => 'category', 'label' => __('Category'), 'sortable' => true],
        ['key' => 'message', 'label' => __('Message'), 'sortable' => false],
    ];
    $levels = ['Info' => ['info', __('Information')], 'Warning' => ['warn', __('Warning')], 'Error' => ['danger', __('Error')]];
@endphp
<x-ap-page :title="__('Logs')" :crumbs="[['label' => __('Account')], ['label' => __('Logs')]]"
    :description="__('Changes made to :user and its objects, newest first.', ['user' => $user])">
    <x-slot:actions>
        @if ($canViewLogins)
            <x-ap-button :href="route('logs.auth', ['user' => $user])" icon="history">{{ __('Login History') }}</x-ap-button>
        @endif
        <x-ap-button :href="route('logs.show', ['user' => $user])" icon="refresh">{{ __('Refresh') }}</x-ap-button>
        @if ($canDelete)
            <x-ap-button variant="danger" :form-action="route('logs.destroy', ['user' => $user])" form-method="DELETE" icon="trash"
                :confirm="__('Are you sure you want to delete the logs?')">{{ __('Delete') }}</x-ap-button>
        @endif
    </x-slot:actions>

    <x-ap-owner-switch route="logs.index" :owners="$owners" :owner="$user" :label="__('Show logs')" />

    <x-ap-card :flush="true">
        @if ($rows === [])
            <x-ap-empty icon="file" :title="__('No log records')" />
        @else
            <x-ap-table id="action-log" :columns="$columns" :search-label="__('Search logs')" :caption="__('Logs')">
                @foreach ($rows as $row)
                    @php([$tone, $label] = $levels[$row['level']] ?? [null, $row['level']])
                    <tr data-row data-search="{{ $row['category'] }} {{ $row['message'] }}">
                        <td data-col="level" data-label="{{ __('Level') }}" data-sort-value="{{ $row['level'] }}"><x-ap-badge :tone="$tone">{{ $label }}</x-ap-badge></td>
                        <td data-col="date" data-label="{{ __('Date') }}">{{ $row['date'] }}</td>
                        <td data-col="time" data-label="{{ __('Time') }}">{{ $row['time'] }}</td>
                        <td data-col="category" data-label="{{ __('Category') }}">{{ __($row['category']) }}</td>
                        <td data-col="message" data-label="{{ __('Message') }}">{{ $row['message'] }}</td>
                    </tr>
                @endforeach
                <x-slot:footer>
                    <span class="muted">{{ trans_choice(':count log record|:count log records', count($rows), ['count' => count($rows)]) }}</span>
                </x-slot:footer>
            </x-ap-table>
        @endif
    </x-ap-card>
</x-ap-page>
