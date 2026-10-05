{{-- One user or customer (E11 spec §7.2): contact, package, usage, web domains; a reseller also shows its plan and customers. --}}
@php
    $roleLabels = ['admin' => __('Administrator'), 'reseller' => __('Reseller'), 'user' => __('User'), 'dns-cluster' => __('DNS Cluster')];
    $isCustomerView = $principal->actingRole()->value === 'reseller';
    $listTitle = $isCustomerView ? __('Customers') : __('Users');
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
    $size = fn (int|string $mb) => \App\Services\Dashboard\DashboardService::size((int) $mb);
@endphp
<x-ap-page :title="$record->name" :crumbs="[['label' => $listTitle, 'url' => route('users.index')], ['label' => $record->name]]">
    <x-slot:head>
        <div class="ap-entity">
            <span class="ap-entity-icon"><x-ap-icon :name="$isReseller ? 'users' : 'user'" /></span>
            <div class="ap-entity-text">
                <span class="eyebrow">{{ $roleLabels[$record->role()->value] ?? $record->role()->value }}@if ($record->owner !== '' && ! $isCustomerView) · {{ __('Owner') }}: {{ $record->owner }}@endif</span>
                <h1 class="mono">{{ $record->name }}</h1>
                <div class="ap-row">
                    <x-ap-badge :tone="$record->suspended() ? 'danger' : 'ok'">{{ $record->suspended() ? __('Suspended') : __('Active') }}</x-ap-badge>
                    <x-ap-badge>{{ __('Package') }}: {{ $packageLabel }}</x-ap-badge>
                </div>
            </div>
        </div>
    </x-slot:head>
    <x-slot:actions>
        @if ($can['impersonate'])
            <x-ap-button :form-action="route('impersonate.start', ['user' => $record->name])" icon="switch">{{ __('Log in as') }}</x-ap-button>
        @endif
        @if ($can['update'])
            <x-ap-button :href="route('users.edit', ['user' => $record->name])" icon="edit">{{ __('Edit') }}</x-ap-button>
        @endif
        @if ($can['suspend'])
            @if ($record->suspended())
                <x-ap-button :form-action="route('users.unsuspend', ['user' => $record->name])" icon="play">{{ __('Unsuspend') }}</x-ap-button>
            @else
                <x-ap-button :form-action="route('users.suspend', ['user' => $record->name])" icon="pause" :confirm="sprintf(__('Are you sure you want to suspend user %s?'), $record->name)">{{ __('Suspend') }}</x-ap-button>
            @endif
        @endif
        @if ($can['delete'])
            <x-ap-button variant="danger" :form-action="route('users.destroy', ['user' => $record->name])" form-method="DELETE" icon="trash"
                :confirm="sprintf(__('Are you sure you want to delete user %s?'), $record->name)">{{ __('Delete') }}</x-ap-button>
        @endif
    </x-slot:actions>

    <div class="ap-split">
        <x-ap-card :title="__('Contact')" icon="user-circle">
            <dl class="ap-dl">
                <dt>{{ __('Name') }}</dt><dd>{{ $record->fullName() }}</dd>
                <dt>{{ __('Email') }}</dt><dd>{{ $record->email() }}</dd>
                <dt>{{ __('Language') }}</dt><dd>{{ $record->language() }}</dd>
                <dt>{{ __('Name servers') }}</dt><dd class="mono">{{ implode(', ', $record->nameservers()) }}</dd>
                <dt>{{ __('Created') }}</dt><dd>{{ $record->get('DATE') }}</dd>
            </dl>
        </x-ap-card>
        <x-ap-card :title="__('Usage')" icon="chart">
            @include('users.partials.usage', ['record' => $record])
        </x-ap-card>
    </div>

    @if ($isReseller)
        <x-ap-card :title="__('Reseller plan')" icon="package">
            <p class="muted">{{ __('Own usage plus the limits of every customer package, against the reseller package.') }}</p>
            <div class="ap-usage-list">
                @foreach ($allocation as $key => $row)
                    @php
                        $unlimited = $row['limit'] === 'unlimited';
                        $sized = in_array($key, ['DISK_QUOTA', 'BANDWIDTH'], true);
                        $value = $row['allocated'];
                    @endphp
                    <x-ap-usage-bar :label="$keyLabels[$key] ?? $key" :value="$value ?? 0" :limit="$unlimited ? null : (float) $row['limit']" :unlimited="$unlimited"
                        :value-label="$value === null ? __('Unlimited') : ($sized ? $size($value) : $value)"
                        :limit-label="$unlimited ? null : ($sized ? $size($row['limit']) : $row['limit'])" />
                @endforeach
            </div>
        </x-ap-card>

        <x-ap-card :title="__('Customers')" icon="users" :flush="true">
            @if ($customers === [])
                <x-ap-empty icon="users" :title="__('No customers yet')" />
            @else
                <div class="ap-table-wrap">
                    <table class="ap-table ap-table-cards">
                        <thead><tr><th scope="col">{{ __('Username') }}</th><th scope="col">{{ __('Package') }}</th><th scope="col">{{ __('Status') }}</th></tr></thead>
                        <tbody>
                            @foreach ($customers as $customer)
                                <tr>
                                    <td data-label="{{ __('Username') }}"><a class="mono" href="{{ route('users.show', ['user' => $customer->name]) }}">{{ $customer->name }}</a></td>
                                    <td data-label="{{ __('Package') }}">{{ $customer->package() }}</td>
                                    <td data-label="{{ __('Status') }}"><x-ap-badge :tone="$customer->suspended() ? 'danger' : 'ok'">{{ $customer->suspended() ? __('Suspended') : __('Active') }}</x-ap-badge></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ap-card>
    @endif

    <x-ap-card :title="__('Web domains')" icon="globe" :flush="true">
        <x-slot:actions>
            <x-ap-button :href="route('web.domains.index', ['owner' => $record->name])" variant="ghost" size="sm">{{ __('All web domains') }}<x-ap-icon name="chevron-right" /></x-ap-button>
            <x-ap-button :href="route('web.domains.create', ['owner' => $record->name])" size="sm" icon="plus">{{ __('Add Web Domain') }}</x-ap-button>
        </x-slot:actions>
        @if ($domains === [])
            <x-ap-empty icon="globe" :title="__('No web domains yet')" />
        @else
            <div class="ap-table-wrap">
                <table class="ap-table ap-table-cards">
                    <thead><tr><th scope="col">{{ __('Domain') }}</th><th scope="col">{{ __('SSL') }}</th><th scope="col" class="col-num">{{ __('Disk') }}</th></tr></thead>
                    <tbody>
                        @foreach ($domains as $domain)
                            <tr>
                                <td data-label="{{ __('Domain') }}"><a class="mono" href="{{ route('web.domains.edit', ['user' => $record->name, 'domain' => $domain->domain]) }}">{{ $domain->domain }}</a>
                                    @if ($domain->suspended)<x-ap-badge tone="danger">{{ __('Suspended') }}</x-ap-badge>@endif</td>
                                <td data-label="{{ __('SSL') }}">
                                    @if ($domain->ssl)
                                        <x-ap-badge tone="ok">{{ $domain->letsEncrypt ? __('Let’s Encrypt') : __('Enabled') }}</x-ap-badge>
                                    @else
                                        <x-ap-badge>{{ __('Disabled') }}</x-ap-badge>
                                    @endif
                                </td>
                                <td data-label="{{ __('Disk') }}" class="col-num mono">{{ $size($domain->diskMb) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ap-card>
</x-ap-page>
