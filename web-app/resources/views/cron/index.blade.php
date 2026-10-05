{{-- Cron jobs list (web/list/cron, E7 batch 1 spec §7.2). --}}
@php
    $columns = array_values(array_filter([
        ['key' => 'command', 'label' => __('Command'), 'sortable' => true],
        $showOwner ? ['key' => 'owner', 'label' => __('Owner'), 'sortable' => true] : null,
        ['key' => 'min', 'label' => __('Minute'), 'sortable' => true],
        ['key' => 'hour', 'label' => __('Hour'), 'sortable' => true],
        ['key' => 'day', 'label' => __('Day'), 'sortable' => true],
        ['key' => 'month', 'label' => __('Month'), 'sortable' => true],
        ['key' => 'wday', 'label' => __('Day of Week'), 'sortable' => true],
        ['key' => 'status', 'label' => __('Status'), 'sortable' => true],
    ]));
    $filter = array_filter(['owner' => $owner]);
@endphp
<x-ap-page :title="__('Cron Jobs')" :crumbs="[['label' => __('Cron Jobs')]]"
    :description="$everyUser ? __('Cron jobs of every user on this server.') : ($showOwner ? __('Your cron jobs and those of your customers.') : __('Commands that run on a schedule.'))">
    <x-slot:actions>
        @if ($reports !== null)
            @if ($reports['enabled'])
                <x-ap-button :form-action="route('cron.reports.disable', ['user' => $reports['owner']] + $filter)" form-method="DELETE" icon="mail">{{ __('Disable Notifications') }}</x-ap-button>
            @else
                <x-ap-button :form-action="route('cron.reports.enable', ['user' => $reports['owner']] + $filter)" icon="mail">{{ __('Enable Notifications') }}</x-ap-button>
            @endif
        @endif
        @if ($canCreate)
            <x-ap-button :href="route('cron.create', $filter)" variant="primary" icon="plus">{{ __('Add Cron Job') }}</x-ap-button>
        @endif
    </x-slot:actions>

    <x-ap-card :flush="true">
        @if ($total === 0 && $owner === '')
            <x-ap-empty icon="clock" :title="__('No cron jobs yet')">{{ __('Add a cron job to run a command on a schedule.') }}
                @if ($canCreate)
                    <x-slot:actions><x-ap-button :href="route('cron.create')" variant="primary" icon="plus">{{ __('Add Cron Job') }}</x-ap-button></x-slot:actions>
                @endif
            </x-ap-empty>
        @else
            @if ($canBulk)
                <x-ap-bulk-bar table="cron-jobs" :action="route('cron.bulk', $filter)" :actions="$bulkActions" />
            @endif
            <x-ap-table id="cron-jobs" :columns="$columns" :selectable="$canBulk" :search-label="__('Search cron jobs')" :caption="__('Cron Jobs')">
                @if ($showOwner)
                    <x-slot:toolbar>
                        <x-ap-owner-filter route="cron.index" :owners="$owners" :owner="$owner" />
                    </x-slot:toolbar>
                @endif

                @forelse ($records as $record)
                    @php
                        $can = $abilities[$record->user];
                        $params = ['user' => $record->user, 'job' => $record->job];
                    @endphp
                    <tr data-row data-search="{{ $record->command }} {{ $record->user }}" @class(['is-disabled' => $record->suspended])>
                        @if ($canBulk)
                            <x-ap-row-check :value="$record->user.'/'.$record->job" :label="__('Select :name', ['name' => $record->command])" form="cron-jobs-bulk" />
                        @endif
                        <td data-col="command" data-label="{{ __('Command') }}" data-sort-value="{{ $record->command }}">
                            @if ($record->suspended)
                                <span class="mono ap-trunc" title="{{ $record->command }}">{{ $record->command }}</span>
                            @else
                                <a class="mono ap-trunc" href="{{ route('cron.edit', $params) }}" title="{{ __('Edit Cron Job') }}: {{ $record->command }}">{{ $record->command }}</a>
                            @endif
                        </td>
                        @if ($showOwner)
                            <td data-col="owner" data-label="{{ __('Owner') }}" class="muted">{{ $record->user }}</td>
                        @endif
                        <td data-col="min" data-label="{{ __('Minute') }}" class="mono">{{ $record->min }}</td>
                        <td data-col="hour" data-label="{{ __('Hour') }}" class="mono">{{ $record->hour }}</td>
                        <td data-col="day" data-label="{{ __('Day') }}" class="mono">{{ $record->day }}</td>
                        <td data-col="month" data-label="{{ __('Month') }}" class="mono">{{ $record->month }}</td>
                        <td data-col="wday" data-label="{{ __('Day of Week') }}" class="mono">{{ $record->wday }}</td>
                        <td data-col="status" data-label="{{ __('Status') }}" data-sort-value="{{ $record->suspended ? 'suspended' : 'active' }}">
                            <x-ap-badge :tone="$record->suspended ? 'danger' : 'ok'">{{ $record->suspended ? __('Suspended') : __('Active') }}</x-ap-badge>
                        </td>
                        <td class="col-actions">
                            <details class="ap-dropdown ap-row-menu">
                                <summary class="ap-btn ap-btn-icon ap-btn-ghost ap-btn-sm" aria-label="{{ __('Actions for :name', ['name' => $record->command]) }}" title="{{ __('Actions') }}"><x-ap-icon name="more" /></summary>
                                <div class="ap-menu" role="group" aria-label="{{ __('Actions for :name', ['name' => $record->command]) }}">
                                    @if (! $record->suspended)
                                        <a class="ap-menu-item" href="{{ route('cron.edit', $params) }}"><x-ap-icon name="edit" />{{ __('Edit Cron Job') }}</a>
                                    @endif
                                    @if ($can['suspend'])
                                        <div class="ap-menu-sep" role="separator"></div>
                                        @if ($record->suspended)
                                            <x-ap-button class="ap-menu-item" :form-action="route('cron.unsuspend', $params)" icon="play"
                                                :confirm="__('Are you sure you want to unsuspend this cron job?')">{{ __('Unsuspend') }}</x-ap-button>
                                        @else
                                            <x-ap-button class="ap-menu-item" :form-action="route('cron.suspend', $params)" icon="pause"
                                                :confirm="__('Are you sure you want to suspend this cron job?')">{{ __('Suspend') }}</x-ap-button>
                                        @endif
                                    @endif
                                    @if ($can['delete'])
                                        <x-ap-button class="ap-menu-item is-danger" variant="danger" :form-action="route('cron.destroy', $params)" form-method="DELETE" icon="trash"
                                            :confirm="__('Are you sure you want to delete this cron job?')">{{ __('Delete') }}</x-ap-button>
                                    @endif
                                </div>
                            </details>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="{{ count($columns) + 2 }}" class="muted">{{ __('No cron jobs for this owner.') }}</td></tr>
                @endforelse

                <x-slot:footer>
                    <x-ap-pager :page="$page" :pages="$pages" :total="$total" :per-page="\App\Http\Controllers\Cron\CronController::PAGE_SIZE"
                        :url="route('cron.index', $filter)" />
                </x-slot:footer>
            </x-ap-table>
        @endif
    </x-ap-card>
</x-ap-page>
