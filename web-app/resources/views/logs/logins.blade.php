{{-- Login history of an account (web/list/log/auth, E7 batch 2 spec §9.2). --}}
@php
    $columns = [
        ['key' => 'status', 'label' => __('Status'), 'sortable' => true],
        ['key' => 'date', 'label' => __('Date'), 'sortable' => true],
        ['key' => 'time', 'label' => __('Time'), 'sortable' => true],
        ['key' => 'ip', 'label' => __('IP Address'), 'sortable' => true],
        ['key' => 'browser', 'label' => __('Browser'), 'sortable' => false],
    ];
@endphp
<x-ap-page :title="__('Login History')" :crumbs="[['label' => __('Account')], ['label' => __('Logs'), 'url' => route('logs.show', ['user' => $user])], ['label' => __('Login History')]]"
    :description="__('Sign-ins to :user, newest first.', ['user' => $user])">
    <x-slot:actions>
        <x-ap-button :href="route('logs.auth', ['user' => $user])" icon="refresh">{{ __('Refresh') }}</x-ap-button>
        @if ($canDelete)
            <x-ap-button variant="danger" :form-action="route('logs.auth.destroy', ['user' => $user])" form-method="DELETE" icon="trash"
                :confirm="__('Are you sure you want to delete the logs?')">{{ __('Delete') }}</x-ap-button>
        @endif
    </x-slot:actions>

    <x-ap-card :flush="true">
        @if ($rows === [])
            <x-ap-empty icon="history" :title="__('No log records')" />
        @else
            <x-ap-table id="login-history" :columns="$columns" :search-label="__('Search logs')" :caption="__('Login History')">
                @foreach ($rows as $row)
                    <tr data-row data-search="{{ $row['ip'] }} {{ $row['browser'] }}">
                        <td data-col="status" data-label="{{ __('Status') }}" data-sort-value="{{ $row['success'] ? 1 : 0 }}">
                            <x-ap-badge :tone="$row['success'] ? 'ok' : 'danger'">{{ $row['success'] ? __('Success') : __('Failed') }}</x-ap-badge>
                        </td>
                        <td data-col="date" data-label="{{ __('Date') }}">{{ $row['date'] }}</td>
                        <td data-col="time" data-label="{{ __('Time') }}">{{ $row['time'] }}</td>
                        <td data-col="ip" data-label="{{ __('IP Address') }}" class="mono">{{ $row['ip'] }}</td>
                        <td data-col="browser" data-label="{{ __('Browser') }}"><span class="ap-trunc" title="{{ $row['browser'] }}">{{ $row['browser'] }}</span></td>
                    </tr>
                @endforeach
                <x-slot:footer>
                    <span class="muted">{{ trans_choice(':count log record|:count log records', count($rows), ['count' => count($rows)]) }}</span>
                </x-slot:footer>
            </x-ap-table>
        @endif
    </x-ap-card>
</x-ap-page>
