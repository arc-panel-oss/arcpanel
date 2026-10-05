{{--
    Port entries with their actions (E12 spec §7.3–7.5). $entries: list of
    PortEntry; $abilitiesFor: fn (user) => PortPolicy::abilities(); $showUser
    adds the user column (Admin → Ports); $id names the table.
--}}
@php
    $showUser ??= false;
    $columns = array_values(array_filter([
        $showUser ? ['key' => 'user', 'label' => __('User'), 'sortable' => true] : null,
        ['key' => 'port', 'label' => __('Port'), 'sortable' => true, 'type' => 'number'],
        ['key' => 'proto', 'label' => __('Protocol'), 'optional' => true],
        ['key' => 'app', 'label' => __('Used by'), 'sortable' => true],
        ['key' => 'public', 'label' => __('Internet'), 'sortable' => true],
        ['key' => 'listen', 'label' => __('Listening'), 'optional' => true],
    ]));
    $listenLabels = ['no' => __('No'), 'local' => __('This server only'), 'any' => __('All addresses')];
@endphp
@if ($entries === [])
    <x-ap-empty icon="plug" :title="__('No ports yet')">{{ $empty ?? __('Ports allocated to this account appear here.') }}</x-ap-empty>
@else
    <x-ap-table :id="$id" :columns="$columns" :search-label="__('Search ports')" :caption="$caption ?? __('Ports')">
        @foreach ($entries as $entry)
            @php
                $can = $abilitiesFor($entry->user);
                $range = $entry->label();
                $route = ['user' => $entry->user, 'range' => $range];
            @endphp
            <tr data-row data-search="{{ $entry->user }} {{ $range }} {{ $entry->app }} {{ $entry->comment }}" @class(['is-disabled' => $entry->suspended])>
                @if ($showUser)
                    <td data-col="user" data-label="{{ __('User') }}" data-sort-value="{{ $entry->user }}"><a class="mono" href="{{ route('users.show', ['user' => $entry->user]) }}">{{ $entry->user }}</a></td>
                @endif
                <td data-col="port" data-label="{{ __('Port') }}" data-sort-value="{{ $entry->start }}" class="mono">
                    {{ $range }}@if ($entry->isRange()) <span class="muted">({{ $entry->size() }})</span>@endif
                </td>
                <td data-col="proto" data-label="{{ __('Protocol') }}" class="mono">{{ $entry->assigned() ? strtoupper($entry->proto) : '—' }}</td>
                <td data-col="app" data-label="{{ __('Used by') }}" data-sort-value="{{ $entry->app }}">
                    @if ($entry->assigned())
                        <b>{{ $entry->appLabel() }}</b>@if ($entry->comment !== '') <span class="muted">· {{ $entry->comment }}</span>@endif
                    @else
                        <span class="muted">{{ __('Free') }}@if ($entry->comment !== '') · {{ $entry->comment }}@endif</span>
                    @endif
                </td>
                <td data-col="public" data-label="{{ __('Internet') }}" data-sort-value="{{ $entry->public ? 'b' : ($entry->publicOk ? 'a' : 'c') }}">
                    @if ($entry->public && $entry->suspended)
                        <x-ap-badge tone="warn">{{ __('Closed while suspended') }}</x-ap-badge>
                    @elseif ($entry->public)
                        <x-ap-badge tone="ok">{{ __('Public') }}</x-ap-badge>
                        @if ($firewallOn && $entry->rule === '')<x-ap-badge tone="danger">{{ __('No firewall rule') }}</x-ap-badge>@endif
                    @elseif ($entry->publicOk)
                        <x-ap-badge>{{ __('Private, may be public') }}</x-ap-badge>
                    @else
                        <x-ap-badge>{{ __('Private') }}</x-ap-badge>
                    @endif
                    @if ($entry->listen === 'any' && ! $entry->public)
                        <x-ap-badge tone="warn">{{ __('Listens on all addresses') }}</x-ap-badge>
                    @endif
                </td>
                <td data-col="listen" data-label="{{ __('Listening') }}" class="muted">{{ $listenLabels[$entry->listen] ?? $entry->listen }}</td>
                <td class="col-actions">
                    <details class="ap-dropdown ap-row-menu">
                        <summary class="ap-btn ap-btn-icon ap-btn-ghost ap-btn-sm" aria-label="{{ __('Actions for port :port', ['port' => $range]) }}" title="{{ __('Actions') }}"><x-ap-icon name="more" /></summary>
                        <div class="ap-menu" role="group" aria-label="{{ __('Actions for port :port', ['port' => $range]) }}">
                            @if ($entry->assigned() && ! $entry->isRange() && $can['public'])
                                @if ($entry->public)
                                    <x-ap-button class="ap-menu-item" :form-action="route('ports.public', $route + ['public' => 'no'])" icon="lock">{{ __('Make private') }}</x-ap-button>
                                @elseif ($entry->publicOk)
                                    <x-ap-button class="ap-menu-item" :form-action="route('ports.public', $route + ['public' => 'yes'])" icon="globe"
                                        :confirm="sprintf(__('Open port %s to the internet?'), $range)">{{ __('Open to the internet') }}</x-ap-button>
                                @endif
                            @endif
                            @if ($entry->assigned() && $can['reserve'])
                                <x-ap-button class="ap-menu-item" :form-action="route('ports.release', $route)" icon="return"
                                    :confirm="sprintf(__('Release port %s? It stays in the pool.'), $range)">{{ __('Release') }}</x-ap-button>
                            @endif
                            @if (! $entry->publicOk && $can['publicOk'])
                                <x-ap-button class="ap-menu-item" :form-action="route('ports.public-ok', $route + ['public_ok' => 'yes'])" icon="shield">{{ __('Allow public use') }}</x-ap-button>
                            @elseif ($entry->publicOk && $can['forbidPublic'])
                                <x-ap-button class="ap-menu-item" :form-action="route('ports.public-ok', $route + ['public_ok' => 'no'])" icon="shield-check"
                                    :confirm="__('Public ports in this range will be closed.')">{{ __('Forbid public use') }}</x-ap-button>
                            @endif
                            @if (! $entry->assigned() && $can['reclaim'])
                                <x-ap-button class="ap-menu-item" :form-action="route('ports.reclaim', $route)" icon="arrow">{{ __('Take back') }}</x-ap-button>
                            @endif
                            @if ($can['delete'])
                                <div class="ap-menu-sep" role="separator"></div>
                                <x-ap-button class="ap-menu-item is-danger" variant="danger" :form-action="route('ports.destroy', $route + ($entry->assigned() ? ['force' => 1] : []))" form-method="DELETE" icon="trash"
                                    :confirm="$entry->assigned() ? sprintf(__('Port %s is used by %s. Delete it anyway?'), $range, $entry->appLabel()) : sprintf(__('Delete ports %s?'), $range)">{{ __('Delete') }}</x-ap-button>
                            @endif
                        </div>
                    </details>
                </td>
            </tr>
        @endforeach
    </x-ap-table>
@endif
