{{-- Administrator dashboard (docs/design/05 §4.3–4.4, E1-S2 spec §7). --}}
@php
    $health = $widgets['health'];
    $h = $health->data;
@endphp
<x-ap-page :title="__('Dashboard')" :eyebrow="$h ? $h['hostname'].' · '.$h['os'] : null" :description="__('Server health and accounts across every owner.')">
    <x-slot:actions>
        @if ($canCreate)
            <x-ap-button :href="route('web.domains.create')" icon="globe">{{ __('Add Web Domain') }}</x-ap-button>
            <x-ap-button :href="$addUserUrl" variant="primary" icon="user-plus">{{ __('Add User') }}</x-ap-button>
        @endif
    </x-slot:actions>

    @if ($widgets['updates']->ok() && $widgets['updates']->data !== [])
        <x-ap-alert tone="warn" :title="trans_choice(':count update available|:count updates available', count($widgets['updates']->data), ['count' => count($widgets['updates']->data)])">
            {{ implode(', ', $widgets['updates']->data) }}
            <x-slot:actions><x-ap-button size="sm" :href="$updatesUrl">{{ __('Review updates') }}</x-ap-button></x-slot:actions>
        </x-ap-alert>
    @endif

    @if (! $health->ok())
        <x-ap-alert tone="danger" :title="__('Server health is not available')">{{ $health->error }}</x-ap-alert>
    @else
        <div class="ap-grid">
            <x-ap-meter :title="__('CPU')" icon="cpu" :percent="$h['cpu']['percent']" :value="$h['cpu']['percent']" unit="%"
                :meta="[__('Load').' '.implode(' · ', $h['load'])]" />
            <x-ap-meter :title="__('Memory')" icon="memory" :percent="$h['memory']['percent']"
                :value="round($h['memory']['usedMb'] / 1024, 1)" :unit="' / '.round($h['memory']['totalMb'] / 1024, 1).' GB'"
                :meta="[$h['memory']['percent'].'% '.__('used'), __('Swap').' '.round($h['memory']['swapUsedMb'] / 1024, 1).' GB']" />
            <x-ap-meter :title="__('Disk')" icon="disk" :percent="$h['disk']['percent']" :value="$h['disk']['used']" :unit="' / '.$h['disk']['size']"
                :meta="[$h['disk']['percent'].'% '.__('used'), $h['disk']['available'].' '.__('free')]" />
            @php($accounts = $widgets['accounts'])
            <x-ap-card :title="__('Accounts')" icon="users">
                @if ($accounts->ok())
                    <div class="ap-stat">
                        <div class="ap-stat-value"><a class="ap-stat-link" href="{{ route('users.index') }}">{{ $accounts->data['total'] }} <small>{{ trans_choice('user|users', $accounts->data['total']) }}</small></a></div>
                        <div class="ap-stat-meta">
                            <a href="{{ route('web.domains.index') }}">{{ trans_choice(':count web domain|:count web domains', $accounts->data['webDomains'], ['count' => $accounts->data['webDomains']]) }}</a>
                            <a href="{{ route('users.index', ['role' => 'admin']) }}">{{ trans_choice(':count administrator|:count administrators', $accounts->data['admins'], ['count' => $accounts->data['admins']]) }}</a>
                            <a href="{{ route('users.index', ['role' => 'reseller']) }}">{{ trans_choice(':count reseller|:count resellers', $accounts->data['resellers'], ['count' => $accounts->data['resellers']]) }}</a>
                        </div>
                    </div>
                    <div class="ap-row">
                        @if ($accounts->data['suspended'] > 0)
                            <x-ap-badge tone="danger">{{ $accounts->data['suspended'] }} {{ __('suspended') }}</x-ap-badge>
                        @else
                            <x-ap-badge tone="ok">{{ __('None suspended') }}</x-ap-badge>
                        @endif
                    </div>
                @else
                    <x-ap-alert tone="danger">{{ $accounts->error }}</x-ap-alert>
                @endif
            </x-ap-card>
        </div>
    @endif

    <div class="ap-split">
        <div class="ap-stack">
            @if ($widgets['graphs']->ok())
                @foreach ($widgets['graphs']->data as $graph)
                    <x-ap-rrd-graph :url="route('shell.rrd')" :service="$graph['service']" :title="__($graph['title'])" />
                @endforeach
            @endif
        </div>
        @php($services = $widgets['services']->ok() ? collect($widgets['services']->data)->sortBy(fn ($service) => $service['running'] ? 1 : 0)->values()->all() : [])
        @php($stopped = count(array_filter($services, fn ($service) => ! $service['running'])))
        <x-ap-card :title="__('Services')" icon="server" :flush="true">
            <x-slot:actions>
                @if ($widgets['services']->ok())
                    @if ($stopped > 0)
                        <x-ap-badge tone="danger">{{ trans_choice(':count stopped|:count stopped', $stopped, ['count' => $stopped]) }}</x-ap-badge>
                    @else
                        <x-ap-badge tone="ok">{{ __('All running') }}</x-ap-badge>
                    @endif
                @endif
                <x-ap-button :href="$servicesUrl" variant="ghost" size="sm">{{ __('All services') }}<x-ap-icon name="chevron-right" /></x-ap-button>
            </x-slot:actions>
            @if ($widgets['services']->ok())
                <ul class="ap-list">
                    @foreach ($services as $service)
                        <li>
                            <div class="ap-list-main"><b class="mono">{{ $service['name'] }}</b><span>{{ $service['system'] }}</span></div>
                            <x-ap-badge :tone="$service['running'] ? 'ok' : 'danger'">{{ $service['running'] ? __('Running') : __('Stopped') }}</x-ap-badge>
                        </li>
                    @endforeach
                </ul>
            @else
                <div class="ap-card-body"><x-ap-alert tone="danger">{{ $widgets['services']->error }}</x-ap-alert></div>
            @endif
        </x-ap-card>
    </div>

    <div class="ap-split">
        <x-ap-card :title="__('SSL expiring in 14 days')" icon="shield-check" :flush="true">
            @php($ssl = $widgets['ssl'])
            @if (! $ssl->ok())
                <div class="ap-card-body"><x-ap-alert tone="danger">{{ $ssl->error }}</x-ap-alert></div>
            @elseif ($ssl->data === [])
                <x-ap-empty icon="shield-check" :title="__('No certificate expires soon')" />
            @else
                <div class="ap-table-wrap">
                    <table class="ap-table ap-table-cards">
                        <thead><tr><th scope="col">{{ __('Domain') }}</th><th scope="col">{{ __('Owner') }}</th><th scope="col">{{ __('Expires') }}</th></tr></thead>
                        <tbody>
                            @foreach ($ssl->data as $row)
                                <tr>
                                    <td data-label="{{ __('Domain') }}"><a class="mono" href="{{ route('web.domains.edit', ['user' => $row['user'], 'domain' => $row['domain']]) }}#ssl">{{ $row['domain'] }}</a></td>
                                    <td data-label="{{ __('Owner') }}" class="muted">{{ $row['user'] }}</td>
                                    <td data-label="{{ __('Expires') }}"><x-ap-badge :tone="$row['days'] <= 3 ? 'danger' : 'warn'">{{ $row['expires'] }} · {{ trans_choice(':count day|:count days', max(0, $row['days']), ['count' => max(0, $row['days'])]) }}</x-ap-badge></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ap-card>

        <x-ap-card :title="__('Recent sign-ins')" icon="key" :flush="true">
            <x-slot:actions><x-ap-button :href="$logsUrl" variant="ghost" size="sm">{{ __('Auth log') }}<x-ap-icon name="chevron-right" /></x-ap-button></x-slot:actions>
            @php($auth = $widgets['authLog'])
            @if (! $auth->ok())
                <div class="ap-card-body"><x-ap-alert tone="danger">{{ $auth->error }}</x-ap-alert></div>
            @elseif ($auth->data === [])
                <x-ap-empty icon="key" :title="__('No sign-ins yet')" />
            @else
                <div class="ap-table-wrap">
                    <table class="ap-table ap-table-cards">
                        <thead><tr><th scope="col">{{ __('Time') }}</th><th scope="col">{{ __('IP address') }}</th><th scope="col">{{ __('Result') }}</th></tr></thead>
                        <tbody>
                            @foreach ($auth->data as $row)
                                <tr>
                                    <td data-label="{{ __('Time') }}" class="mono muted">{{ $row['date'] }} {{ $row['time'] }}</td>
                                    <td data-label="{{ __('IP address') }}" class="mono">{{ $row['ip'] }}</td>
                                    <td data-label="{{ __('Result') }}"><x-ap-badge :tone="$row['success'] ? 'ok' : 'danger'">{{ $row['success'] ? __('Success') : __('Failed') }}</x-ap-badge></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ap-card>
    </div>
</x-ap-page>
