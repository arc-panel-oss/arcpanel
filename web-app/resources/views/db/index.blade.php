{{-- Databases list (web/list/db, E7 batch 1 spec §6.2). --}}
@php
    $columns = array_values(array_filter([
        ['key' => 'name', 'label' => __('Name'), 'sortable' => true],
        $showOwner ? ['key' => 'owner', 'label' => __('Owner'), 'sortable' => true] : null,
        ['key' => 'disk', 'label' => __('Disk'), 'sortable' => true, 'type' => 'number'],
        ['key' => 'type', 'label' => __('Type'), 'sortable' => true],
        ['key' => 'dbuser', 'label' => __('Username'), 'sortable' => true, 'optional' => true],
        ['key' => 'host', 'label' => __('Hostname'), 'sortable' => true, 'optional' => true],
        ['key' => 'charset', 'label' => __('Charset'), 'sortable' => true, 'optional' => true],
        ['key' => 'status', 'label' => __('Status'), 'sortable' => true],
    ]));
    $size = fn (int $mb) => \App\Services\Dashboard\DashboardService::size($mb);
@endphp
<x-ap-page :title="__('Databases')" :crumbs="[['label' => __('Databases')]]"
    :description="$everyUser ? __('Databases of every user on this server.') : ($showOwner ? __('Your databases and those of your customers.') : __('Your MySQL and PostgreSQL databases.'))">
    <x-slot:actions>
        @foreach ($links as $link)
            <x-ap-button :href="$link['url']" icon="external" target="_blank" rel="noopener" @class(['is-disabled' => $link['disabled']])
                :title="$link['disabled'] ? __('Open the panel by its host name to use :tool', ['tool' => $link['label']]) : null">{{ $link['label'] }}</x-ap-button>
        @endforeach
        @if ($canCreate)
            <x-ap-button :href="route('db.create', array_filter(['owner' => $owner]))" variant="primary" icon="plus">{{ __('Add Database') }}</x-ap-button>
        @endif
    </x-slot:actions>

    <x-ap-card :flush="true">
        @if ($total === 0 && $owner === '')
            <x-ap-empty icon="db" :title="__('No databases yet')">{{ __('Add a database for your website or application.') }}
                @if ($canCreate)
                    <x-slot:actions><x-ap-button :href="route('db.create')" variant="primary" icon="plus">{{ __('Add Database') }}</x-ap-button></x-slot:actions>
                @endif
            </x-ap-empty>
        @else
            @if ($canBulk)
                <x-ap-bulk-bar table="databases" :action="route('db.bulk', array_filter(['owner' => $owner]))" :actions="$bulkActions" />
            @endif
            <x-ap-table id="databases" :columns="$columns" :selectable="$canBulk" :search-label="__('Search databases')" :caption="__('Databases')">
                @if ($showOwner)
                    <x-slot:toolbar>
                        <x-ap-owner-filter route="db.index" :owners="$owners" :owner="$owner" />
                    </x-slot:toolbar>
                @endif

                @forelse ($records as $record)
                    @php
                        $can = $abilities[$record->user];
                        $params = ['user' => $record->user, 'database' => $record->database];
                    @endphp
                    <tr data-row data-search="{{ $record->database }} {{ $record->dbUser }} {{ $record->user }}" @class(['is-disabled' => $record->suspended])>
                        @if ($canBulk)
                            <x-ap-row-check :value="$record->user.'/'.$record->database" :label="__('Select :name', ['name' => $record->database])" form="databases-bulk" />
                        @endif
                        <td data-col="name" data-label="{{ __('Name') }}" data-sort-value="{{ $record->database }}">
                            @if ($record->suspended)
                                <span class="mono">{{ $record->database }}</span>
                            @else
                                <a class="mono" href="{{ route('db.edit', $params) }}">{{ $record->database }}</a>
                            @endif
                        </td>
                        @if ($showOwner)
                            <td data-col="owner" data-label="{{ __('Owner') }}" class="muted">{{ $record->user }}</td>
                        @endif
                        <td data-col="disk" data-label="{{ __('Disk') }}" class="col-num mono" data-sort-value="{{ $record->diskMb }}">{{ $size($record->diskMb) }}</td>
                        <td data-col="type" data-label="{{ __('Type') }}">{{ $record->type }}</td>
                        <td data-col="dbuser" data-label="{{ __('Username') }}" class="mono">{{ $record->dbUser }}</td>
                        <td data-col="host" data-label="{{ __('Hostname') }}" class="mono muted">{{ $record->host }}</td>
                        <td data-col="charset" data-label="{{ __('Charset') }}" class="muted">{{ $record->charset }}</td>
                        <td data-col="status" data-label="{{ __('Status') }}" data-sort-value="{{ $record->suspended ? 'suspended' : 'active' }}">
                            <x-ap-badge :tone="$record->suspended ? 'danger' : 'ok'">{{ $record->suspended ? __('Suspended') : __('Active') }}</x-ap-badge>
                        </td>
                        <td class="col-actions">
                            <details class="ap-dropdown ap-row-menu">
                                <summary class="ap-btn ap-btn-icon ap-btn-ghost ap-btn-sm" aria-label="{{ __('Actions for :name', ['name' => $record->database]) }}" title="{{ __('Actions') }}"><x-ap-icon name="more" /></summary>
                                <div class="ap-menu" role="group" aria-label="{{ __('Actions for :name', ['name' => $record->database]) }}">
                                    @if (! $record->suspended)
                                        <a class="ap-menu-item" href="{{ route('db.edit', $params) }}"><x-ap-icon name="edit" />{{ __('Edit Database') }}</a>
                                    @endif
                                    @if ($can['update'] && $sso && $record->type === 'mysql' && ! $record->suspended)
                                        <x-ap-button class="ap-menu-item" :form-action="route('db.phpmyadmin', $params)" icon="external" formtarget="_blank">{{ __('phpMyAdmin') }}</x-ap-button>
                                    @endif
                                    @if ($can['update'])
                                        <x-ap-button class="ap-menu-item" :form-action="route('db.download', $params)" icon="download">{{ __('Download') }}</x-ap-button>
                                    @endif
                                    @if ($can['suspend'])
                                        <div class="ap-menu-sep" role="separator"></div>
                                        @if ($record->suspended)
                                            <x-ap-button class="ap-menu-item" :form-action="route('db.unsuspend', $params)" icon="play"
                                                :confirm="sprintf(__('Are you sure you want to unsuspend database %s?'), $record->database)">{{ __('Unsuspend') }}</x-ap-button>
                                        @else
                                            <x-ap-button class="ap-menu-item" :form-action="route('db.suspend', $params)" icon="pause"
                                                :confirm="sprintf(__('Are you sure you want to suspend database %s?'), $record->database)">{{ __('Suspend') }}</x-ap-button>
                                        @endif
                                    @endif
                                    @if ($can['delete'])
                                        <x-ap-button class="ap-menu-item is-danger" variant="danger" :form-action="route('db.destroy', $params)" form-method="DELETE" icon="trash"
                                            :confirm="sprintf(__('Are you sure you want to delete database %s?'), $record->database)">{{ __('Delete') }}</x-ap-button>
                                    @endif
                                </div>
                            </details>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="{{ count($columns) + 2 }}" class="muted">{{ __('No databases for this owner.') }}</td></tr>
                @endforelse

                <x-slot:footer>
                    <x-ap-pager :page="$page" :pages="$pages" :total="$total" :per-page="\App\Http\Controllers\Databases\DatabaseController::PAGE_SIZE"
                        :url="route('db.index', array_filter(['owner' => $owner]))" />
                </x-slot:footer>
            </x-ap-table>
        @endif
    </x-ap-card>
</x-ap-page>
