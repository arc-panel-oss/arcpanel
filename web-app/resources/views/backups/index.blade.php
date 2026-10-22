{{-- Archives of an account (web/list/backup, E7 batch 1 spec §10.2) with their job and destinations (E48 spec §8.1). --}}
@php
    $columns = [
        ['key' => 'name', 'label' => __('File Name'), 'sortable' => true],
        ['key' => 'date', 'label' => __('Date'), 'sortable' => true],
        ['key' => 'size', 'label' => __('Size'), 'sortable' => true, 'type' => 'number'],
        ['key' => 'destinations', 'label' => __('Destinations')],
        ['key' => 'job', 'label' => __('Job'), 'sortable' => true, 'optional' => true],
        ['key' => 'runtime', 'label' => __('Runtime'), 'sortable' => true, 'type' => 'number', 'optional' => true],
    ];
    $size = fn (int $mb) => \App\Services\Dashboard\DashboardService::size($mb);
    $canBulk = $can['delete'];
@endphp
<x-ap-page :title="__('Archives')" :crumbs="[['label' => __('Backups'), 'url' => route('backups.overview', ['user' => $user])], ['label' => __('Archives')]]" :eyebrow="__('Owner').': '.$user"
    :description="$systems['mail']
        ? __('Daily backups of websites, mail, databases, cron jobs and files. New backups and restores are queued and run in the background.')
        : __('Daily backups of websites, databases, cron jobs and files. New backups and restores are queued and run in the background.')">
    <x-slot:actions>
        @if ($can['create'])
            <x-ap-button :form-action="route('backups.archives.store', ['user' => $user])" variant="primary" icon="plus">{{ __('Create Backup') }}</x-ap-button>
        @endif
    </x-slot:actions>

    @include('backups.partials.nav', ['current' => 'archives'])

    @if (count($destinations) > 1)
        <div class="ap-row" role="group" aria-label="{{ __('Destination') }}" data-destination-filter>
            <x-ap-chip :active="$destination === ''"><a href="{{ route('backups.archives.index', ['user' => $user]) }}">{{ __('All destinations') }}</a></x-ap-chip>
            @foreach ($destinations as $name)
                <x-ap-chip :active="$destination === $name"><a class="mono" href="{{ route('backups.archives.index', ['user' => $user, 'destination' => $name]) }}">{{ $name }}</a></x-ap-chip>
            @endforeach
        </div>
    @endif

    <x-ap-card :flush="true">
        @if ($archives === [])
            <x-ap-empty icon="archive" :title="__('No backups yet')">{{ __('Backups are created by the backup jobs of the server, or now with Create Backup.') }}</x-ap-empty>
        @else
            @if ($canBulk)
                <x-ap-bulk-bar table="backups" :action="route('backups.archives.bulk', ['user' => $user])"
                    :actions="['delete' => ['label' => __('Delete'), 'icon' => 'trash', 'danger' => true, 'confirm' => __('Delete %d backups?')]]" />
            @endif
            <x-ap-table id="backups" :columns="$columns" :selectable="$canBulk" :search-label="__('Search backups')" :caption="__('Backups')">
                @foreach ($archives as $archive)
                    @php($params = ['user' => $user, 'backup' => $archive->name])
                    <tr data-row data-search="{{ $archive->name }}">
                        @if ($canBulk)
                            <x-ap-row-check :value="$archive->name" :label="__('Select :name', ['name' => $archive->name])" form="backups-bulk" />
                        @endif
                        <td data-col="name" data-label="{{ __('File Name') }}" data-sort-value="{{ $archive->name }}">
                            <a class="mono" href="{{ route('backups.archives.show', $params) }}">{{ $archive->name }}</a>
                        </td>
                        <td data-col="date" data-label="{{ __('Date') }}" data-sort-value="{{ $archive->date }} {{ $archive->time }}" class="mono">{{ $archive->date }} {{ $archive->time }}</td>
                        <td data-col="size" data-label="{{ __('Size') }}" class="col-num mono" data-sort-value="{{ $archive->sizeMb }}">{{ $size($archive->sizeMb) }}</td>
                        <td data-col="destinations" data-label="{{ __('Destinations') }}">
                            <span class="ap-row">@foreach ($archive->destinations as $name)<x-ap-badge mono>{{ $name }}</x-ap-badge>@endforeach @if ($archive->encrypted)<x-ap-icon name="lock" size="sm" /><span class="sr-only">{{ __('Encrypted') }}</span>@endif</span>
                        </td>
                        <td data-col="job" data-label="{{ __('Job') }}" class="muted">{{ $jobNames[$archive->job] ?? ($archive->job !== '' ? $archive->job : '—') }}</td>
                        <td data-col="runtime" data-label="{{ __('Runtime') }}" class="col-num mono" data-sort-value="{{ $archive->runtime }}">{{ sprintf(__('%d minutes'), $archive->runtime) }}</td>
                        <td class="col-actions">
                            <details class="ap-dropdown ap-row-menu">
                                <summary class="ap-btn ap-btn-icon ap-btn-ghost ap-btn-sm" aria-label="{{ __('Actions for :name', ['name' => $archive->name]) }}" title="{{ __('Actions') }}"><x-ap-icon name="more" /></summary>
                                <div class="ap-menu" role="group" aria-label="{{ __('Actions for :name', ['name' => $archive->name]) }}">
                                    @if ($can['download'])
                                        <a class="ap-menu-item" href="{{ route('backups.archives.download', $params) }}"><x-ap-icon name="download" />{{ __('Download') }}</a>
                                    @endif
                                    @if ($can['restore'])
                                        <a class="ap-menu-item" href="{{ route('backups.restore', ['user' => $user, 'archive' => $archive->name]) }}"><x-ap-icon name="history" />{{ __('Restore') }}</a>
                                    @endif
                                    <a class="ap-menu-item" href="{{ route('backups.archives.show', $params) }}"><x-ap-icon name="list" />{{ __('Contents') }}</a>
                                    @if ($can['delete'])
                                        <div class="ap-menu-sep" role="separator"></div>
                                        <x-ap-button class="ap-menu-item is-danger" variant="danger" :form-action="route('backups.archives.destroy', $params)" form-method="DELETE" icon="trash"
                                            :confirm="sprintf(__('Are you sure you want to delete backup %s?'), $archive->name)">{{ __('Delete') }}</x-ap-button>
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
