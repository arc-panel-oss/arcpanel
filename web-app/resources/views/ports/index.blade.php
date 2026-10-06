{{-- Ports of a user (E12 spec §7.4) or a reseller with its customers (§7.5). --}}
@php
    $abilitiesFor = fn (string $user) => $abilities[$user] ?? ['reserve' => false, 'public' => false, 'publicOk' => false, 'forbidPublic' => false, 'give' => false, 'reclaim' => false, 'delete' => false];
    $own = $abilitiesFor($account->user);
    $unlimited = $account->unlimited();
    $giveable = array_keys(array_filter($customers, fn ($c) => $abilitiesFor($c->user)['give']));
    // Ports the package still allows on top of what is held (topped up on use)
    $room = $unlimited ? PHP_INT_MAX : max(0, (int) $account->limit - ($account->treeTotal ?? $account->allocated));
    $available = $account->free + ($unlimited ? 0 : $room);
@endphp
<x-ap-page :title="__('Ports')" :crumbs="[['label' => __('Ports')]]"
    :description="$isReseller ? __('Your pool of ports and the ports you gave to your customers.') : __('Ports your apps may use. They are private unless you open them to the internet.')">
    <x-slot:actions>
        @if ($canRequest)
            <x-ap-button icon="send" x-data x-on:click="$dispatch('ap-modal', { id: 'request-ports' })">{{ __('Request more ports') }}</x-ap-button>
        @endif
        @if ($own['reserve'])
            <x-ap-button variant="primary" icon="plus" x-data x-on:click="$dispatch('ap-modal', { id: 'reserve-port' })" :disabled="$account->free === 0 && $room === 0">{{ __('Reserve a port') }}</x-ap-button>
        @endif
    </x-slot:actions>

    @unless ($account->firewallOn())
        <x-ap-alert tone="warn" :title="__('The firewall is off')">{{ __('Every port that listens on all addresses is reachable from the internet.') }}</x-ap-alert>
    @endunless

    <div class="ap-split">
        <x-ap-card :title="$isReseller ? __('Your pool') : __('Your ports')" icon="plug">
            <div class="ap-usage-list">
                @if ($isReseller && $account->treeUsed !== null)
                    <x-ap-usage-bar :label="__('In use in your tree')" :value="$account->treeUsed" :limit="$unlimited ? null : (float) $account->limit" :unlimited="$unlimited" />
                @else
                    <x-ap-usage-bar :label="__('Allocated')" :value="$account->allocated" :limit="$unlimited ? null : (float) $account->limit" :unlimited="$unlimited" />
                @endif
            </div>
            <dl class="ap-dl">
                @if ($isReseller)
                    <dt>{{ __('Free in your pool') }}</dt><dd class="mono">{{ $account->free }}</dd>
                    <dt>{{ __('Given to customers') }}</dt><dd class="mono">{{ ($account->treeTotal ?? $account->allocated) - $account->allocated }}</dd>
                @else
                    <dt>{{ __('Free') }}</dt><dd class="mono">{{ $account->free }}</dd>
                @endif
                <dt>{{ __('In use') }}</dt><dd class="mono">{{ $account->assigned }}</dd>
                <dt>{{ __('Public') }}</dt><dd class="mono">{{ $account->public }}</dd>
            </dl>
        </x-ap-card>
        <x-ap-card :title="__('How ports work')" icon="info">
            <p class="muted">{{ __('Apps listen on 127.0.0.1 and your domains reach them through the web server, so nothing is exposed by default.') }}</p>
            <p class="muted">{{ __('Reserve a port for what you run over SSH. Ports marked "may be public" can be opened to the internet; this writes a firewall rule.') }}</p>
        </x-ap-card>
    </div>

    <x-ap-card :title="$isReseller ? __('Your own ports') : __('Ports')" icon="plug" :flush="true">
        @include('ports.partials.entries', ['entries' => $account->entries, 'abilitiesFor' => $abilitiesFor, 'id' => 'my-ports',
            'firewallOn' => $account->firewallOn(), 'empty' => $canRequest ? __('Your plan has no ports yet. Ask your provider for some.') : __('No ports are allocated to this account.')])
    </x-ap-card>

    @if ($isReseller)
        <x-ap-card :title="__('Customers')" icon="users" :flush="true">
            <x-slot:actions>
                @if ($giveable !== [] && ($unlimited || $available > 0))
                    <x-ap-button size="sm" icon="arrow" x-data x-on:click="$dispatch('ap-modal', { id: 'give-ports' })">{{ __('Give ports') }}</x-ap-button>
                @endif
            </x-slot:actions>
            @if ($customers === [])
                <x-ap-empty icon="users" :title="__('No customers yet')" />
            @else
                <div class="ap-stack ap-ports-customers">
                    @foreach ($customers as $customer)
                        <details class="ap-disclosure">
                            <summary class="ap-row">
                                <b class="mono">{{ $customer->user }}</b>
                                <span class="muted">{{ $customer->allocated }} / @if ($customer->unlimited())<x-ap-inf />@else{{ $customer->limit }}@endif {{ __('allocated') }} · {{ $customer->assigned }} {{ __('in use') }} · {{ $customer->public }} {{ __('public') }}</span>
                                @if ($customer->suspended)<x-ap-badge tone="danger">{{ __('Suspended') }}</x-ap-badge>@endif
                            </summary>
                            @include('ports.partials.entries', ['entries' => $customer->entries, 'abilitiesFor' => $abilitiesFor, 'id' => 'ports-'.$customer->user,
                                'caption' => sprintf(__('Ports of %s'), $customer->user), 'firewallOn' => $customer->firewallOn()])
                        </details>
                    @endforeach
                </div>
            @endif
        </x-ap-card>

        @if ($giveable !== [])
            <x-ap-modal id="give-ports" :title="__('Give ports')" :description="$unlimited ? __('Your pool is topped up as needed.') : sprintf(__('%d ports available: free in your pool or still allowed by your plan.'), $available)" icon="arrow">
                @php($giveData = '{ customer: '.json_encode($giveable[0]).', base: '.json_encode(route('ports.give', ['user' => '__USER__'])).' }')
                <x-ap-form :action="route('ports.give', ['user' => $giveable[0]])" id="give-ports-form"
                    x-data="{{ $giveData }}" x-bind:action="base.replace('__USER__', customer)">
                    <div class="ap-field">
                        <label class="ap-label" for="give-customer">{{ __('Customer') }}</label>
                        <div class="ap-select-wrap">
                            <select class="ap-select" id="give-customer" x-model="customer">
                                @foreach ($giveable as $name)<option value="{{ $name }}">{{ $name }}</option>@endforeach
                            </select>
                            <x-ap-icon name="chevron-down" size="sm" />
                        </div>
                    </div>
                    <x-ap-radio-group name="mode" :label="__('Give')" :options="['count' => __('A number of ports'), 'range' => __('A port or range')]" value="count" />
                    <div class="ap-form-grid">
                        <x-ap-input name="count" type="number" :label="__('Number of ports')" value="5" mono />
                        <x-ap-input name="range" :label="__('Port or range')" :hint="__('Free ports of your pool')" mono />
                    </div>
                </x-ap-form>
                <x-slot:footer>
                    <x-ap-button variant="ghost" x-on:click="close()">{{ __('Cancel') }}</x-ap-button>
                    <x-ap-button type="submit" form="give-ports-form" variant="primary" icon="check">{{ __('Give') }}</x-ap-button>
                </x-slot:footer>
            </x-ap-modal>
        @endif
    @endif

    @if ($own['reserve'])
        <x-ap-modal id="reserve-port" :title="__('Reserve a port')" :description="__('Leave the port empty to take the lowest free one.')" icon="plug">
            <x-ap-form :action="route('ports.reserve', ['user' => $account->user])" id="reserve-port-form">
                <div class="ap-form-grid">
                    <x-ap-input name="port" type="number" :label="__('Port')" mono />
                    <x-ap-select name="proto" :label="__('Protocol')" :options="['tcp' => 'TCP', 'udp' => 'UDP']" value="tcp" />
                </div>
                <x-ap-input name="comment" :label="__('What runs on it')" :hint="__('Letters, digits, spaces, dots, dashes')" />
            </x-ap-form>
            <x-slot:footer>
                <x-ap-button variant="ghost" x-on:click="close()">{{ __('Cancel') }}</x-ap-button>
                <x-ap-button type="submit" form="reserve-port-form" variant="primary" icon="check">{{ __('Reserve') }}</x-ap-button>
            </x-slot:footer>
        </x-ap-modal>
    @endif

    @if ($canRequest)
        <x-ap-modal id="request-ports" :title="__('Request more ports')" :description="sprintf(__('%s gets a notification.'), $owner)" icon="send">
            <x-ap-form :action="route('ports.request')" id="request-ports-form">
                <x-ap-input name="count" type="number" :label="__('Number of ports')" value="5" required mono />
                <x-ap-input name="reason" :label="__('What for')" />
            </x-ap-form>
            <x-slot:footer>
                <x-ap-button variant="ghost" x-on:click="close()">{{ __('Cancel') }}</x-ap-button>
                <x-ap-button type="submit" form="request-ports-form" variant="primary" icon="send">{{ __('Send request') }}</x-ap-button>
            </x-slot:footer>
        </x-ap-modal>
    @endif
</x-ap-page>
