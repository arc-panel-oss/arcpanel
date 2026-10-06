{{-- Reseller dashboard (E11 spec §9): plan, customers, certificates of the tree, then the reseller's own hosting. --}}
@php
    $plan = $widgets['plan'];
    $customers = $widgets['customers'];
    $ssl = $widgets['ssl'];
    $keyLabels = [
        'USERS' => __('Customers'),
        'WEB_DOMAINS' => __('Web Domains'),
        'DNS_DOMAINS' => __('DNS Zones'),
        'MAIL_DOMAINS' => __('Mail Domains'),
        'MAIL_ACCOUNTS' => __('Mail Accounts'),
        'DATABASES' => __('Databases'),
        'CRON_JOBS' => __('Cron Jobs'),
        'DISK_QUOTA' => __('Disk'),
        'BANDWIDTH' => __('Bandwidth'),
    ];
    $size = fn (int|string $mb) => \App\Services\Dashboard\DashboardService::size((float) $mb);
    $sized = ['DISK_QUOTA', 'BANDWIDTH'];
@endphp
<x-ap-page :title="__('Dashboard')" :eyebrow="$plan->ok() ? __('Package').' · '.$plan->data['package'] : null"
    :heading="__('Welcome, :user', ['user' => $user])" :description="__('Your customers, your plan and your own sites.')">
    <x-slot:actions>
        @if ($canCreate)
            <x-ap-button :href="route('users.create')" variant="primary" icon="user-plus">{{ __('Add Customer') }}</x-ap-button>
        @endif
    </x-slot:actions>

    <div class="ap-split">
        <x-ap-card :title="__('Plan')" icon="package">
            @if ($plan->ok())
                <p class="muted">{{ __('Allocated: your usage plus the limits of your customers’ packages. Used: what the whole tree uses today.') }}</p>
                <div class="ap-usage-list">
                    @foreach ($plan->data['rows'] as $key => $row)
                        @php
                            $unlimited = $row['limit'] === 'unlimited';
                            $value = $row['allocated'];
                            $isSized = in_array($key, $sized, true);
                        @endphp
                        <x-ap-usage-bar :label="$keyLabels[$key] ?? $key"
                            :note="$key === 'USERS' ? null : __('Used by the tree: :value', ['value' => $isSized ? $size($row['used']) : $row['used']])"
                            :value="$value ?? 0" :limit="$unlimited ? null : (float) $row['limit']" :unlimited="$unlimited"
                            :value-label="$value === null ? __('Unlimited') : ($isSized ? $size($value) : $value)"
                            :limit-label="$unlimited ? null : ($isSized ? $size($row['limit']) : $row['limit'])" />
                    @endforeach
                </div>
            @else
                <x-ap-alert tone="danger">{{ $plan->error }}</x-ap-alert>
            @endif
        </x-ap-card>

        <div class="ap-stack">
            <x-ap-card :title="__('Customers')" icon="users" :flush="true">
                <x-slot:actions><x-ap-button :href="route('users.index')" variant="ghost" size="sm">{{ __('All customers') }}<x-ap-icon name="chevron-right" /></x-ap-button></x-slot:actions>
                @if (! $customers->ok())
                    <div class="ap-card-body"><x-ap-alert tone="danger">{{ $customers->error }}</x-ap-alert></div>
                @elseif ($customers->data['total'] === 0)
                    <x-ap-empty icon="users" :title="__('No customers yet')">
                        @if ($canCreate)
                            <x-slot:actions><x-ap-button :href="route('users.create')" variant="primary" icon="user-plus">{{ __('Add Customer') }}</x-ap-button></x-slot:actions>
                        @endif
                    </x-ap-empty>
                @else
                    <div class="ap-card-body ap-row">
                        <x-ap-badge>{{ trans_choice(':count customer|:count customers', $customers->data['total'], ['count' => $customers->data['total']]) }}</x-ap-badge>
                        @if ($customers->data['suspended'] > 0)
                            <x-ap-badge tone="danger">{{ $customers->data['suspended'] }} {{ __('suspended') }}</x-ap-badge>
                        @endif
                    </div>
                    <ul class="ap-list">
                        @foreach ($customers->data['recent'] as $row)
                            <li>
                                <div class="ap-list-main"><a href="{{ route('users.show', ['user' => $row['name']]) }}"><b>{{ $row['name'] }}</b></a><span>{{ implode(' · ', array_filter([$row['fullName'], $row['package']], 'filled')) }}</span></div>
                                <span class="subtle mono">{{ $row['date'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ap-card>

            <x-ap-card :title="__('SSL expiring in 14 days')" icon="shield-check" :flush="true">
                @if (! $ssl->ok())
                    <div class="ap-card-body"><x-ap-alert tone="danger">{{ $ssl->error }}</x-ap-alert></div>
                @elseif ($ssl->data === [])
                    <x-ap-empty icon="shield-check" :title="__('No certificate expires soon')" />
                @else
                    <ul class="ap-list">
                        @foreach ($ssl->data as $row)
                            <li>
                                <div class="ap-list-main"><a class="mono" href="{{ route('web.domains.edit', ['user' => $row['user'], 'domain' => $row['domain']]) }}#ssl">{{ $row['domain'] }}</a><span>{{ $row['user'] }}</span></div>
                                <x-ap-badge :tone="$row['days'] <= 3 ? 'danger' : 'warn'">{{ $row['expires'] }}</x-ap-badge>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ap-card>
        </div>
    </div>

    @php($usage = $widgets['usage'])
    <x-ap-card :title="__('My hosting')" icon="chart">
        <x-slot:actions><x-ap-button :href="route('web.domains.index', ['owner' => $user])" variant="ghost" size="sm">{{ __('My web domains') }}<x-ap-icon name="chevron-right" /></x-ap-button></x-slot:actions>
        @if ($usage->ok())
            <div class="ap-usage-list">
                @foreach ($usage->data['items'] as $item)
                    <x-ap-usage-bar :label="$item['label']" :value="$item['value']" :limit="$item['limit'] === 'unlimited' ? null : (float) $item['limit']"
                        :value-label="$item['valueLabel']" :limit-label="$item['limitLabel']" :unlimited="$item['limit'] === 'unlimited'" :href="$item['url'] ?? null" />
                @endforeach
            </div>
        @else
            <x-ap-alert tone="danger">{{ $usage->error }}</x-ap-alert>
        @endif
    </x-ap-card>
</x-ap-page>
