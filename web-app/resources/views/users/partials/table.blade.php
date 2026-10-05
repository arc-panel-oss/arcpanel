{{-- The users table of users.index. --}}
<x-ap-card :flush="true">
    @if ($records === [] && $filters === [])
        <x-ap-empty icon="users" :title="$isReseller ? __('No customers yet') : __('No users yet')">
            {{ $isReseller ? __('Create your first customer with a package, a web domain and a database in one step.') : __('Create an account for each customer or project.') }}
            @if ($canCreate)
                <x-slot:actions><x-ap-button :href="route('users.create')" variant="primary" icon="user-plus">{{ $isReseller ? __('Add Customer') : __('Add User') }}</x-ap-button></x-slot:actions>
            @endif
        </x-ap-empty>
    @else
        @if ($canBulk)
            <x-ap-bulk-bar table="users" :action="route('users.bulk')" :actions="$bulkActions" />
        @endif
        <x-ap-table id="users" :columns="$columns" :selectable="$canBulk" :search-label="__('Search users')" :caption="$title">
            @if ($isAdmin)
                <x-slot:toolbar>
                    <form method="get" action="{{ route('users.index') }}" class="ap-owner-filter">
                        <label class="sr-only" for="role-filter">{{ __('Role') }}</label>
                        <div class="ap-select-wrap">
                            <select class="ap-select ap-select-sm" id="role-filter" name="role" onchange="this.form.requestSubmit()">
                                <option value="">{{ __('All roles') }}</option>
                                @foreach (['admin', 'reseller', 'user'] as $value)
                                    <option value="{{ $value }}" @selected($roleFilter === $value)>{{ $roleLabels[$value] }}</option>
                                @endforeach
                            </select>
                            <x-ap-icon name="chevron-down" size="sm" />
                        </div>
                        <label class="sr-only" for="owner-filter">{{ __('Owner') }}</label>
                        <div class="ap-select-wrap">
                            <select class="ap-select ap-select-sm" id="owner-filter" name="owner" onchange="this.form.requestSubmit()">
                                <option value="">{{ __('All owners') }}</option>
                                @foreach ([$rootUser, ...$resellers] as $name)
                                    <option value="{{ $name }}" @selected($ownerFilter === $name)>{{ $name }}</option>
                                @endforeach
                            </select>
                            <x-ap-icon name="chevron-down" size="sm" />
                        </div>
                        <noscript><button type="submit" class="ap-btn ap-btn-sm">{{ __('Filter') }}</button></noscript>
                    </form>
                    @if ($filters !== [])
                        <x-ap-chip :active="true" :remove-href="route('users.index')" :remove-label="__('Clear filters')">{{ implode(' · ', $filters) }}</x-ap-chip>
                    @endif
                </x-slot:toolbar>
            @endif

            @forelse ($records as $name => $record)
                @php($can = $abilities[$name])
                <tr data-row data-search="{{ $name }} {{ $record->fullName() }} {{ $record->email() }}" @class(['is-disabled' => $record->suspended()])>
                    @if ($canBulk)
                        @if ($can['suspend'] || $can['delete'])
                            <x-ap-row-check :value="$name" :label="__('Select :user', ['user' => $name])" form="users-bulk" />
                        @else
                            <td class="col-check"></td>
                        @endif
                    @endif
                    <td data-col="user" data-label="{{ __('Username') }}" data-sort-value="{{ $name }}">
                        @if ($can['view'])
                            <a class="mono" href="{{ route('users.show', ['user' => $name]) }}">{{ $name }}</a>
                        @else
                            <span class="mono">{{ $name }}</span>
                        @endif
                    </td>
                    <td data-col="name" data-label="{{ __('Name') }}">{{ $record->fullName() }}</td>
                    <td data-col="email" data-label="{{ __('Email') }}" class="muted">{{ $record->email() }}</td>
                    @if ($isAdmin)
                        <td data-col="role" data-label="{{ __('Role') }}" data-sort-value="{{ $record->role()->value }}">
                            <x-ap-badge :tone="$record->role()->value === 'user' ? null : 'info'">{{ $roleLabels[$record->role()->value] ?? $record->role()->value }}</x-ap-badge>
                        </td>
                        <td data-col="owner" data-label="{{ __('Owner') }}" class="muted">{{ $record->owner ?: '—' }}</td>
                    @endif
                    <td data-col="package" data-label="{{ __('Package') }}">{{ $label($record->package()) }}</td>
                    <td data-col="web" data-label="{{ __('Web Domains') }}" class="col-num mono" data-sort-value="{{ $record->get('U_WEB_DOMAINS', '0') }}">
                        {{ $record->get('U_WEB_DOMAINS', '0') }} / {{ $record->get('WEB_DOMAINS') === 'unlimited' ? '∞' : $record->get('WEB_DOMAINS') }}
                    </td>
                    <td data-col="disk" data-label="{{ __('Disk') }}" class="col-num mono" data-sort-value="{{ $record->get('U_DISK', '0') }}">{{ $size((int) $record->get('U_DISK', '0')) }}</td>
                    <td data-col="status" data-label="{{ __('Status') }}" data-sort-value="{{ $record->suspended() ? 'suspended' : 'active' }}">
                        <x-ap-badge :tone="$record->suspended() ? 'danger' : 'ok'">{{ $record->suspended() ? __('Suspended') : __('Active') }}</x-ap-badge>
                    </td>
                    <td class="col-actions">
                        @if ($can['view'] || $can['impersonate'])
                            <details class="ap-dropdown ap-row-menu">
                                <summary class="ap-btn ap-btn-icon ap-btn-ghost ap-btn-sm" aria-label="{{ __('Actions for :user', ['user' => $name]) }}" title="{{ __('Actions') }}"><x-ap-icon name="more" /></summary>
                                <div class="ap-menu" role="group" aria-label="{{ __('Actions for :user', ['user' => $name]) }}">
                                    @if ($can['view'])
                                        <a class="ap-menu-item" href="{{ route('users.show', ['user' => $name]) }}"><x-ap-icon name="user" />{{ __('Open') }}</a>
                                    @endif
                                    @if ($can['update'])
                                        <a class="ap-menu-item" href="{{ route('users.edit', ['user' => $name]) }}"><x-ap-icon name="edit" />{{ __('Edit') }}</a>
                                    @endif
                                    @if ($can['impersonate'])
                                        <x-ap-button class="ap-menu-item" :form-action="route('impersonate.start', ['user' => $name])" icon="switch">{{ __('Log in as') }}</x-ap-button>
                                    @endif
                                    @if ($can['suspend'])
                                        <div class="ap-menu-sep" role="separator"></div>
                                        @if ($record->suspended())
                                            <x-ap-button class="ap-menu-item" :form-action="route('users.unsuspend', ['user' => $name])" icon="play">{{ __('Unsuspend') }}</x-ap-button>
                                        @else
                                            <x-ap-button class="ap-menu-item" :form-action="route('users.suspend', ['user' => $name])" icon="pause"
                                                :confirm="sprintf(__('Are you sure you want to suspend user %s?'), $name)">{{ __('Suspend') }}</x-ap-button>
                                        @endif
                                    @endif
                                    @if ($can['delete'])
                                        <x-ap-button class="ap-menu-item is-danger" variant="danger" :form-action="route('users.destroy', ['user' => $name])" form-method="DELETE" icon="trash"
                                            :confirm="sprintf(__('Are you sure you want to delete user %s?'), $name)">{{ __('Delete') }}</x-ap-button>
                                    @endif
                                </div>
                            </details>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="{{ count($columns) + 2 }}" class="muted">{{ __('No users match these filters.') }}</td></tr>
            @endforelse
        </x-ap-table>
    @endif
</x-ap-card>
