{{-- Users (administrators) and customers (resellers), E11 spec §7.1. --}}
@php
    $title = $isReseller ? __('Customers') : __('Users');
    $columns = array_values(array_filter([
        ['key' => 'user', 'label' => __('Username'), 'sortable' => true],
        ['key' => 'name', 'label' => __('Name'), 'sortable' => true, 'optional' => true],
        // Administrators see every account: the e-mail column waits in the column chooser
        ['key' => 'email', 'label' => __('Email'), 'optional' => true, 'hidden' => $isAdmin],
        $isAdmin ? ['key' => 'role', 'label' => __('Role'), 'sortable' => true] : null,
        $isAdmin ? ['key' => 'owner', 'label' => __('Owner'), 'sortable' => true, 'optional' => true] : null,
        ['key' => 'package', 'label' => __('Package'), 'sortable' => true],
        ['key' => 'web', 'label' => __('Web Domains'), 'sortable' => true, 'type' => 'number', 'optional' => true],
        ['key' => 'disk', 'label' => __('Disk'), 'sortable' => true, 'type' => 'number'],
        ['key' => 'status', 'label' => __('Status'), 'sortable' => true],
    ]));
    $roleLabels = ['admin' => __('Administrator'), 'reseller' => __('Reseller'), 'user' => __('User'), 'dns-cluster' => __('DNS Cluster')];
    $size = fn (int $mb) => \App\Services\Dashboard\DashboardService::size($mb);
    $label = fn (string $package) => $isReseller ? \App\Services\Users\UserService::packageLabel($package, $principal->effectiveUser()) : $package;
    $filters = array_filter(['role' => $roleFilter, 'owner' => $ownerFilter]);
@endphp
<x-ap-page :title="$title" :crumbs="[['label' => $title]]"
    :description="$isReseller ? __('The customers you host, with their plans and usage.') : __('Every account on this server.')">
    <x-slot:actions>
        @if ($canCreate)
            <x-ap-button :href="route('users.create')" variant="primary" icon="user-plus">{{ $isReseller ? __('Add Customer') : __('Add User') }}</x-ap-button>
        @endif
    </x-slot:actions>

    @if ($isAdmin)
        <x-ap-tabs :label="__('Views')" :tabs="['list' => __('List'), 'tree' => __('Owner tree')]" initial="list">
            <x-ap-tab-panel name="list">
                @include('users.partials.table')
            </x-ap-tab-panel>
            <x-ap-tab-panel name="tree">
                <x-ap-card :title="__('Owner tree')" icon="users">
                    <ul class="ap-tree">
                        @foreach ($tree as $node)
                            @php($record = $node['record'])
                            <li>
                                <span class="ap-tree-who">
                                    <x-ap-icon :name="$record->role()->value === 'reseller' ? 'users' : 'user'" />
                                    <a href="{{ $record->name === $self ? route('account.edit') : route('users.show', ['user' => $record->name]) }}"><b>{{ $record->name }}</b></a>
                                    <x-ap-badge :tone="$record->role()->value === 'user' ? null : 'info'">{{ $roleLabels[$record->role()->value] ?? $record->role()->value }}</x-ap-badge>
                                    @if ($record->suspended())<x-ap-badge tone="danger">{{ __('Suspended') }}</x-ap-badge>@endif
                                </span>
                                <span class="muted">{{ $record->package() }}</span>
                            </li>
                            @foreach ($node['children'] as $child)
                                <li class="is-child">
                                    <span class="ap-tree-who">
                                        <x-ap-icon name="user" />
                                        <a href="{{ route('users.show', ['user' => $child->name]) }}">{{ $child->name }}</a>
                                        @if ($child->suspended())<x-ap-badge tone="danger">{{ __('Suspended') }}</x-ap-badge>@endif
                                    </span>
                                    <span class="muted">{{ $child->package() }}</span>
                                </li>
                            @endforeach
                        @endforeach
                    </ul>
                </x-ap-card>
            </x-ap-tab-panel>
        </x-ap-tabs>
    @else
        @include('users.partials.table')
    @endif
</x-ap-page>
