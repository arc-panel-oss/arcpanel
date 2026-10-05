{{-- Admin → Server → Ports (E12 spec §7.3): allocations, live sockets with findings, range and reservations. --}}
@php
    $roleLabels = ['admin' => __('Administrator'), 'reseller' => __('Reseller'), 'user' => __('User')];
    $abilitiesFor = fn (string $user) => ['reserve' => $canChange, 'public' => $canChange, 'publicOk' => $canChange, 'forbidPublic' => $canChange, 'give' => false, 'reclaim' => false, 'delete' => $canChange];
    $findings = $registry->findings;
    $allocated = array_sum(array_map(fn (array $row) => (int) ($row['ALLOCATED'] ?? 0), $registry->users));
    $public = array_sum(array_map(fn (array $row) => (int) ($row['PUBLIC'] ?? 0), $registry->users));
    $size = $registry->rangeEnd - $registry->rangeStart + 1;
    $limit = fn (array $row) => ($row['LIMIT'] ?? '0') === 'unlimited' ? '∞' : ($row['LIMIT'] ?? '0');
@endphp
<x-ap-page :title="__('Ports')" :crumbs="[['label' => __('Server')], ['label' => __('Ports')]]"
    :description="__('Which ports each account may use, and which of them are open to the internet.')">
    <x-slot:actions>
        @if ($canChange)
            @if ($findings !== [])
                <x-ap-button :form-action="route('server.ports.repair')" icon="refresh"
                    :confirm="__('Write missing firewall rules, delete stale ones and archive the ports of deleted users?')">{{ __('Repair') }}</x-ap-button>
            @endif
            <x-ap-button variant="primary" icon="plus" x-data x-on:click="$dispatch('ap-modal', { id: 'allocate-ports' })">{{ __('Allocate ports') }}</x-ap-button>
        @endif
    </x-slot:actions>

    @unless ($registry->firewallOn())
        <x-ap-alert tone="warn" :title="__('The firewall is off')">{{ __('Every port that listens on all addresses is reachable from the internet. Public flags are kept and their rules are written when the firewall is turned on.') }}</x-ap-alert>
    @endunless
    @unless ($registry->enabled)
        <x-ap-alert tone="warn" :title="__('The registry is switched off')">{{ __('Apps get any free port of the range without pool checks.') }}</x-ap-alert>
    @endunless

    <div class="ap-grid">
        <x-ap-card>
            <div class="ap-stat">
                <div class="ap-stat-value">{{ $allocated }} <small>/ {{ $size }}</small></div>
                <div class="ap-stat-meta"><span>{{ __('allocated in') }} <span class="mono">{{ $registry->rangeStart }}–{{ $registry->rangeEnd }}</span></span></div>
            </div>
        </x-ap-card>
        <x-ap-card>
            <div class="ap-stat">
                <div class="ap-stat-value">{{ $public }} <small>{{ __('public') }}</small></div>
                <div class="ap-stat-meta"><span>{{ $registry->firewallOn() ? __('open through tagged firewall rules') : __('firewall off') }}</span></div>
            </div>
        </x-ap-card>
        <x-ap-card>
            <div class="ap-stat">
                <div class="ap-stat-value">{{ count($findings) }} <small>{{ __('findings') }}</small></div>
                <div class="ap-stat-meta"><span>{{ count($registry->sockets) }} {{ __('sockets listening in the range') }}</span></div>
            </div>
        </x-ap-card>
    </div>

    <x-ap-tabs :label="__('Views')" :tabs="['allocations' => __('Allocations'), 'live' => __('Live').($findings !== [] ? ' · '.count($findings) : ''), 'settings' => __('Range and reservations')]" initial="allocations">
        <x-ap-tab-panel name="allocations">
            <div class="ap-stack">
                <x-ap-card :title="__('Accounts')" icon="users" :flush="true">
                    @if ($tree === [])
                        <x-ap-empty icon="users" :title="__('No accounts')" />
                    @else
                        <div class="ap-table-wrap">
                            <table class="ap-table ap-table-cards">
                                <caption class="sr-only">{{ __('Ports per account') }}</caption>
                                <thead><tr>
                                    <th scope="col">{{ __('Account') }}</th><th scope="col">{{ __('Role') }}</th>
                                    <th scope="col" class="col-num">{{ __('Allocated') }}</th><th scope="col" class="col-num">{{ __('In use') }}</th><th scope="col" class="col-num">{{ __('Public') }}</th>
                                </tr></thead>
                                <tbody>
                                    @foreach ($tree as $name => $node)
                                        @foreach ([$name => $node['row'], ...$node['children']] as $user => $row)
                                            @php($child = $user !== $name)
                                            <tr @class(['is-disabled' => ($row['SUSPENDED'] ?? 'no') === 'yes'])>
                                                <td data-label="{{ __('Account') }}">
                                                    @if ($child)<span class="muted" aria-hidden="true">└ </span>@endif
                                                    <a class="mono" href="{{ route('users.show', ['user' => $user]) }}">{{ $user }}</a>
                                                </td>
                                                <td data-label="{{ __('Role') }}"><x-ap-badge :tone="($row['ROLE'] ?? '') === 'reseller' ? 'info' : null">{{ $roleLabels[$row['ROLE'] ?? ''] ?? ($row['ROLE'] ?? '') }}</x-ap-badge></td>
                                                <td data-label="{{ __('Allocated') }}" class="col-num mono">
                                                    {{ $row['ALLOCATED'] ?? 0 }} / {{ $limit($row) }}
                                                    @if (($row['TREE_TOTAL'] ?? '') !== '')<span class="muted">· {{ __('tree') }} {{ $row['TREE_USED'] }} / {{ $row['TREE_TOTAL'] }}</span>@endif
                                                </td>
                                                <td data-label="{{ __('In use') }}" class="col-num mono">{{ $row['ASSIGNED'] ?? 0 }}</td>
                                                <td data-label="{{ __('Public') }}" class="col-num mono">{{ $row['PUBLIC'] ?? 0 }}</td>
                                            </tr>
                                        @endforeach
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-ap-card>

                <x-ap-card :title="__('Entries')" icon="plug" :flush="true">
                    @include('ports.partials.entries', ['entries' => $registry->entries, 'abilitiesFor' => $abilitiesFor, 'showUser' => true,
                        'id' => 'ports', 'caption' => __('Every allocated port'), 'firewallOn' => $registry->firewallOn(),
                        'empty' => __('Allocate ports to an account, or give packages a PORTS value.')])
                </x-ap-card>
            </div>
        </x-ap-tab-panel>

        <x-ap-tab-panel name="live">
            <div class="ap-stack">
                <x-ap-card :title="__('Findings')" icon="alert" :flush="true">
                    @if ($findings === [])
                        <x-ap-empty icon="shield-check" :title="__('The registry matches the server')">{{ __('Every listening port in the range has an owner and every public port has its firewall rule.') }}</x-ap-empty>
                    @else
                        <ul class="ap-list" role="list">
                            @foreach ($findings as $finding)
                                <li class="ap-list-item">
                                    <div class="ap-row">
                                        <x-ap-badge :tone="$finding->tone()">{{ $finding->title() }}</x-ap-badge>
                                        @if ($finding->port !== '')<span class="mono">{{ $finding->port }}/{{ $finding->proto }}</span>@endif
                                        @if ($finding->user !== '')<span class="mono">{{ $finding->user }}</span>@endif
                                        <span class="muted">{{ $finding->detail }}</span>
                                    </div>
                                    @if ($canChange)
                                        <div class="ap-row">
                                            @if ($finding->type === 'orphan-socket' && in_array($finding->user, $userNames, true))
                                                <x-ap-button size="sm" icon="plus" :form-action="route('server.ports.register', ['user' => $finding->user, 'port' => $finding->port, 'proto' => $finding->proto])">{{ sprintf(__('Register to %s'), $finding->user) }}</x-ap-button>
                                            @elseif ($finding->type === 'stale-rule')
                                                <x-ap-button size="sm" variant="danger" icon="trash" :form-action="route('server.ports.close-rule', ['rule' => $finding->rule])">{{ __('Close rule') }}</x-ap-button>
                                            @elseif ($finding->type === 'unmanaged-rule' && ($holder = $registry->holderOf((int) $finding->port)) !== null && $holder->assigned())
                                                <x-ap-button size="sm" icon="link" :form-action="route('server.ports.adopt', ['port' => $finding->port, 'rule' => $finding->rule])">{{ sprintf(__('Link to %s'), $holder->user) }}</x-ap-button>
                                            @endif
                                        </div>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-ap-card>

                <x-ap-card :title="__('Listening in the range')" icon="activity" :flush="true">
                    @if ($registry->sockets === [])
                        <x-ap-empty icon="activity" :title="__('Nothing listens in the range')" />
                    @else
                        <div class="ap-table-wrap">
                            <table class="ap-table ap-table-cards">
                                <caption class="sr-only">{{ __('Sockets listening in the server range') }}</caption>
                                <thead><tr><th scope="col">{{ __('Port') }}</th><th scope="col">{{ __('Address') }}</th><th scope="col">{{ __('Account') }}</th><th scope="col">{{ __('Owner in the registry') }}</th></tr></thead>
                                <tbody>
                                    @foreach ($registry->sockets as $socket)
                                        @php($holder = $registry->holderOf((int) $socket['PORT']))
                                        <tr>
                                            <td data-label="{{ __('Port') }}" class="mono">{{ $socket['PORT'] }}/{{ $socket['PROTO'] }}</td>
                                            <td data-label="{{ __('Address') }}">{{ ($socket['SCOPE'] ?? '') === 'local' ? __('This server only') : __('All addresses') }}</td>
                                            <td data-label="{{ __('Account') }}" class="mono">{{ $socket['ACCOUNT'] ?: $socket['UID'] }}</td>
                                            <td data-label="{{ __('Owner in the registry') }}" class="mono">{{ $holder?->user ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-ap-card>
            </div>
        </x-ap-tab-panel>

        <x-ap-tab-panel name="settings">
            <div class="ap-split">
                <x-ap-card :title="__('Server range')" icon="sliders">
                    <p class="muted">{{ __('The registry allocates only from this range. It cannot shrink past allocated ports.') }}</p>
                    @if ($canChange)
                        <x-ap-form :action="route('server.ports.range')">
                            <div class="ap-form-grid">
                                <x-ap-input name="start" type="number" :label="__('First port')" :value="$registry->rangeStart" required mono />
                                <x-ap-input name="end" type="number" :label="__('Last port')" :value="$registry->rangeEnd" required mono />
                            </div>
                            <div class="ap-row"><x-ap-button type="submit" icon="check">{{ __('Save') }}</x-ap-button></div>
                        </x-ap-form>
                    @else
                        <p class="mono">{{ $registry->rangeStart }}–{{ $registry->rangeEnd }}</p>
                    @endif
                </x-ap-card>

                <x-ap-card :title="__('Reserved ports')" icon="lock" :flush="true">
                    <div class="ap-table-wrap">
                        <table class="ap-table ap-table-cards">
                            <caption class="sr-only">{{ __('Ports the registry never hands out') }}</caption>
                            <thead><tr><th scope="col">{{ __('Port') }}</th><th scope="col">{{ __('Reason') }}</th><th scope="col"><span class="sr-only">{{ __('Actions') }}</span></th></tr></thead>
                            <tbody>
                                @foreach ($registry->reserved as $entry)
                                    <tr>
                                        <td data-label="{{ __('Port') }}" class="mono">{{ $entry->label() }}</td>
                                        <td data-label="{{ __('Reason') }}">{{ $entry->comment }} @if ($entry->app !== 'system')<x-ap-badge>{{ $entry->app === 'admin' ? __('Added by an administrator') : __('Listening at setup') }}</x-ap-badge>@endif</td>
                                        <td class="col-actions">
                                            @if ($canChange && $entry->app !== 'system')
                                                <x-ap-button size="sm" variant="ghost" :icon-only="true" icon="trash" :label="__('Delete')" form-method="DELETE"
                                                    :form-action="route('server.ports.reserved.destroy', ['range' => $entry->label()])" :confirm="sprintf(__('Delete reservation %s?'), $entry->label())" />
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($canChange)
                        <x-slot:footer>
                            <x-ap-form :action="route('server.ports.reserved.store')">
                                <div class="ap-form-grid">
                                    <x-ap-input name="range" :label="__('Port or range')" :hint="__('e.g. 31000 or 31000-31009')" required mono />
                                    <x-ap-input name="comment" :label="__('Reason')" />
                                </div>
                                <div class="ap-row"><x-ap-button type="submit" icon="lock">{{ __('Reserve') }}</x-ap-button></div>
                            </x-ap-form>
                        </x-slot:footer>
                    @endif
                </x-ap-card>
            </div>
        </x-ap-tab-panel>
    </x-ap-tabs>

    @if ($canChange)
        <x-ap-modal id="allocate-ports" :title="__('Allocate ports')" :description="__('Give an account a range of the server range, or a number of ports as one block.')" icon="plug">
            <x-ap-form :action="route('server.ports.allocate')" id="allocate-ports-form">
                <x-ap-select name="user" :label="__('Account')" :options="array_combine($userNames, $userNames)" required />
                <x-ap-radio-group name="mode" :label="__('Allocate')" :options="['count' => __('A number of ports'), 'range' => __('A port or range')]" value="count" />
                <div class="ap-form-grid">
                    <x-ap-input name="count" type="number" :label="__('Number of ports')" value="10" mono />
                    <x-ap-input name="range" :label="__('Port or range')" :hint="sprintf(__('Inside %s'), $registry->rangeStart.'–'.$registry->rangeEnd)" mono />
                </div>
                <x-ap-switch name="public_ok" :label="__('May be public')" :description="__('The account may open these ports to the internet.')" />
                <x-ap-input name="comment" :label="__('Comment')" />
            </x-ap-form>
            <x-slot:footer>
                <x-ap-button variant="ghost" x-on:click="close()">{{ __('Cancel') }}</x-ap-button>
                <x-ap-button type="submit" form="allocate-ports-form" variant="primary" icon="check">{{ __('Allocate') }}</x-ap-button>
            </x-slot:footer>
        </x-ap-modal>
    @endif
</x-ap-page>
