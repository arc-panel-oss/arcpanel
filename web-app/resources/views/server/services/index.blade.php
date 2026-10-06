{{-- Services of the server (web/list/server, E7 batch 3 spec §7.2). --}}
@php
    $columns = [
        ['key' => 'name', 'label' => __('Service'), 'sortable' => true],
        ['key' => 'system', 'label' => __('Description'), 'sortable' => true],
        ['key' => 'state', 'label' => __('Status'), 'sortable' => true],
        ['key' => 'uptime', 'label' => __('Uptime'), 'sortable' => true],
        ['key' => 'cpu', 'label' => __('CPU'), 'sortable' => true],
        ['key' => 'memory', 'label' => __('Memory'), 'sortable' => true],
    ];
    $reportLabels = ['cpu' => __('CPU'), 'mem' => __('RAM'), 'disk' => __('Disk'), 'net' => __('Network'),
        'web' => __('Web'), 'dns' => __('DNS'), 'mail' => __('Mail'), 'db' => __('DB')];
    $uptime = fn (int $minutes) => $minutes >= 60
        ? (round($minutes / 60) >= 24
            ? trans_choice(':count day|:count days', (int) round($minutes / 1440), ['count' => (int) round($minutes / 1440)])
            : trans_choice(':count hour|:count hours', (int) round($minutes / 60), ['count' => (int) round($minutes / 60)]))
        : trans_choice(':count minute|:count minutes', $minutes, ['count' => $minutes]);
@endphp
<x-ap-page :title="__('Services')" :crumbs="[['label' => __('Server')], ['label' => __('Services')]]"
    :description="__('Services of this server, their state and their configuration.')">
    <x-slot:actions>
        @if ($canManage)
            <x-ap-button variant="danger" icon="refresh" x-data x-on:click="$dispatch('ap-modal', { id: 'restart-server' })">{{ __('Restart server') }}</x-ap-button>
        @endif
    </x-slot:actions>

    <x-ap-card :title="$info['hostname']">
        <dl class="ap-dl">
            <dt>{{ __('Operating System') }}</dt><dd>{{ $info['os'] }} {{ $info['version'] }} ({{ $info['arch'] }})</dd>
            <dt>{{ __('Uptime') }}</dt><dd>{{ $uptime($info['uptime']) }}</dd>
            <dt>{{ __('Load Average') }}</dt><dd class="mono">{{ $info['load'] }}</dd>
            <dt>{{ __('PHP versions') }}</dt><dd class="mono">{{ $php === [] ? '—' : implode(', ', $php) }}</dd>
        </dl>
        <nav class="ap-segment" aria-label="{{ __('Status reports') }}">
            @foreach ($reports as $kind)
                <a href="{{ route('server.services.status', ['kind' => $kind]) }}">{{ $reportLabels[$kind] }}</a>
            @endforeach
        </nav>
    </x-ap-card>

    <x-ap-card :flush="true">
        @if ($services === [])
            <x-ap-empty icon="activity" :title="__('No services')" />
        @else
            @if ($canManage)
                <x-ap-bulk-bar table="server-services" :action="route('server.services.bulk')"
                    :actions="[
                        'start' => ['label' => __('Start'), 'icon' => 'play'],
                        'restart' => ['label' => __('Restart'), 'icon' => 'refresh', 'confirm' => __('Restart %d services?')],
                        'stop' => ['label' => __('Stop'), 'icon' => 'pause', 'danger' => true, 'confirm' => __('Stop %d services?')],
                    ]" />
            @endif
            <x-ap-table id="server-services" :columns="$columns" :selectable="$canManage" :search-label="__('Search services')" :caption="__('Services')">
                @foreach ($services as $service)
                    @php($name = $service['name'])
                    <tr data-row data-search="{{ $name }} {{ $service['system'] }}">
                        @if ($canManage)
                            <x-ap-row-check :value="$name" :label="__('Select :name', ['name' => $name])" form="server-services-bulk" />
                        @endif
                        <td data-col="name" data-label="{{ __('Service') }}" data-sort-value="{{ $name }}">
                            @if ($service['editor'])
                                <a class="mono" href="{{ $service['editor'] }}" title="{{ __('Edit') }}: {{ $name }}">{{ $name }}</a>
                            @else
                                <span class="mono">{{ $name }}</span>
                            @endif
                        </td>
                        <td data-col="system" data-label="{{ __('Description') }}">{{ __($service['system']) }}</td>
                        <td data-col="state" data-label="{{ __('Status') }}" data-sort-value="{{ $service['running'] ? 1 : 0 }}">
                            <x-ap-badge :tone="$service['running'] ? 'ok' : 'danger'">{{ $service['running'] ? __('running') : __('stopped') }}</x-ap-badge>
                        </td>
                        <td data-col="uptime" data-label="{{ __('Uptime') }}" data-sort-value="{{ $service['uptime'] }}">{{ $service['running'] ? $uptime($service['uptime']) : '—' }}</td>
                        <td data-col="cpu" data-label="{{ __('CPU') }}" data-sort-value="{{ $service['cpu'] }}">{{ $service['cpu'] }}%</td>
                        <td data-col="memory" data-label="{{ __('Memory') }}" data-sort-value="{{ $service['memory'] }}">{{ $service['memory'] }} {{ __('MB') }}</td>
                        <td class="col-actions">
                            <details class="ap-dropdown ap-row-menu">
                                <summary class="ap-btn ap-btn-icon ap-btn-ghost ap-btn-sm" aria-label="{{ __('Actions for :name', ['name' => $name]) }}" title="{{ __('Actions') }}"><x-ap-icon name="more" /></summary>
                                <div class="ap-menu" role="group" aria-label="{{ __('Actions for :name', ['name' => $name]) }}">
                                    @if ($service['editor'])
                                        <a class="ap-menu-item" href="{{ $service['editor'] }}"><x-ap-icon name="edit" />{{ __('Edit config') }}</a>
                                    @endif
                                    @if ($canManage)
                                        <x-ap-button class="ap-menu-item" :form-action="route('server.services.action', ['service' => $name, 'action' => 'restart'])" icon="refresh"
                                            :confirm="sprintf(__('Are you sure you want to restart the %s service?'), $name)">{{ __('Restart') }}</x-ap-button>
                                        @if ($service['running'])
                                            @if ($service['canStop'])
                                                <x-ap-button class="ap-menu-item is-danger" variant="danger" :form-action="route('server.services.action', ['service' => $name, 'action' => 'stop'])" icon="pause"
                                                    :confirm="sprintf(__('Are you sure you want to stop the %s service?'), $name)">{{ __('Stop') }}</x-ap-button>
                                            @else
                                                <span class="ap-menu-item is-disabled" title="{{ __('This service is required by the panel.') }}"><x-ap-icon name="pause" />{{ __('Stop') }}</span>
                                            @endif
                                        @else
                                            <x-ap-button class="ap-menu-item" :form-action="route('server.services.action', ['service' => $name, 'action' => 'start'])" icon="play"
                                                :confirm="sprintf(__('Are you sure you want to start the %s service?'), $name)">{{ __('Start') }}</x-ap-button>
                                        @endif
                                    @endif
                                </div>
                            </details>
                        </td>
                    </tr>
                @endforeach
                <x-slot:footer>
                    <span class="muted">{{ trans_choice(':count service|:count services', count($services), ['count' => count($services)]) }}</span>
                </x-slot:footer>
            </x-ap-table>
        @endif
    </x-ap-card>

    @if ($canManage)
        <x-ap-modal id="restart-server" :title="__('Restart server')" :description="__('Are you sure you want to restart the server?')" icon="refresh"
            :open="$errors->has('hostname')">
            <x-ap-form :action="route('server.services.restart-server')" id="restart-server-form">
                <x-ap-input name="hostname" :label="sprintf(__('Type %s to confirm'), $info['hostname'])" mono autocomplete="off" required />
            </x-ap-form>
            <x-slot:footer>
                <x-ap-button variant="ghost" x-on:click="close()">{{ __('Cancel') }}</x-ap-button>
                <x-ap-button type="submit" form="restart-server-form" variant="danger" icon="refresh">{{ __('Restart') }}</x-ap-button>
            </x-slot:footer>
        </x-ap-modal>
    @endif
</x-ap-page>
