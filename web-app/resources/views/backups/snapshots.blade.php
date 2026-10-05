{{-- Restic snapshots of an account (web/list/backup/incremental, E7 batch 1 spec §10.2). --}}
<x-ap-page :title="__('Snapshots')" :crumbs="[['label' => __('Backups'), 'url' => route('backups.archives.index', ['user' => $user])], ['label' => __('Snapshots')]]"
    :eyebrow="__('Owner').': '.$user" :description="__('Incremental backups of the home directory and the account settings. Browse a snapshot to restore single files.')">
    <x-slot:actions>
        @if ($can['create'])
            <x-ap-button :form-action="route('backups.snapshots.store', ['user' => $user])" variant="primary" icon="plus">{{ __('Create Snapshot') }}</x-ap-button>
        @endif
    </x-slot:actions>

    @include('backups.partials.nav', ['current' => 'snapshots'])

    <x-ap-card :flush="true">
        @if ($snapshots === [])
            <x-ap-empty icon="history" :title="__('No snapshots yet')">{{ __('Snapshots are created every night, or now with Create Snapshot.') }}</x-ap-empty>
        @else
            <x-ap-table id="snapshots" :columns="[
                ['key' => 'id', 'label' => __('Snapshot'), 'sortable' => true],
                ['key' => 'date', 'label' => __('Date'), 'sortable' => true],
                ['key' => 'host', 'label' => __('Hostname'), 'optional' => true],
            ]" :search-label="__('Search snapshots')" :caption="__('Snapshots')">
                @foreach ($snapshots as $snapshot)
                    @php($params = ['user' => $user, 'snapshot' => $snapshot->shortId])
                    <tr data-row data-search="{{ $snapshot->shortId }} {{ $snapshot->label() }}">
                        <td data-col="id" data-label="{{ __('Snapshot') }}" data-sort-value="{{ $snapshot->shortId }}">
                            <a class="mono" href="{{ route('backups.snapshots.show', $params) }}">{{ $snapshot->shortId }}</a>
                        </td>
                        <td data-col="date" data-label="{{ __('Date') }}" data-sort-value="{{ $snapshot->time?->getTimestamp() ?? 0 }}" class="mono">{{ $snapshot->label() }}</td>
                        <td data-col="host" data-label="{{ __('Hostname') }}" class="muted">{{ $snapshot->hostname }}</td>
                        <td class="col-actions">
                            <details class="ap-dropdown ap-row-menu">
                                <summary class="ap-btn ap-btn-icon ap-btn-ghost ap-btn-sm" aria-label="{{ __('Actions for :name', ['name' => $snapshot->shortId]) }}" title="{{ __('Actions') }}"><x-ap-icon name="more" /></summary>
                                <div class="ap-menu" role="group" aria-label="{{ __('Actions for :name', ['name' => $snapshot->shortId]) }}">
                                    <a class="ap-menu-item" href="{{ route('backups.snapshots.show', $params) }}"><x-ap-icon name="list" />{{ __('Restore') }}</a>
                                    @if ($browse)
                                        <a class="ap-menu-item" href="{{ route('files.show', ['user' => $user, 'snapshot' => $snapshot->shortId]) }}"><x-ap-icon name="folder" />{{ __('Browse Files') }}</a>
                                    @endif
                                    @if ($can['delete'])
                                        <div class="ap-menu-sep" role="separator"></div>
                                        <x-ap-button class="ap-menu-item is-danger" variant="danger" :form-action="route('backups.snapshots.destroy', $params)" form-method="DELETE" icon="trash"
                                            :confirm="sprintf(__('Are you sure you want to delete snapshot %s?'), $snapshot->shortId)">{{ __('Delete') }}</x-ap-button>
                                    @endif
                                </div>
                            </details>
                        </td>
                    </tr>
                @endforeach
            </x-ap-table>
        @endif
    </x-ap-card>
</x-ap-page>
