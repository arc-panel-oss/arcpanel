{{-- Accounts of a mail domain (web/list/mail?domain=, E7 batch 1 spec §9.4). --}}
@php
    $params = ['user' => $record->user, 'domain' => $record->domain];
    $columns = [
        ['key' => 'name', 'label' => __('Name'), 'sortable' => true],
        ['key' => 'disk', 'label' => __('Disk'), 'sortable' => true, 'type' => 'number'],
        ['key' => 'quota', 'label' => __('Quota'), 'sortable' => true, 'type' => 'number'],
        ['key' => 'aliases', 'label' => __('Aliases'), 'sortable' => true, 'optional' => true],
        ['key' => 'forwarding', 'label' => __('Forwarding'), 'sortable' => true, 'optional' => true],
        ['key' => 'autoreply', 'label' => __('Auto Reply'), 'sortable' => true, 'optional' => true],
        ['key' => 'status', 'label' => __('Status'), 'sortable' => true],
    ];
    $size = fn (int $mb) => \App\Services\Dashboard\DashboardService::size($mb);
@endphp
<x-ap-page :title="__('Mail Accounts')" :crumbs="[['label' => __('Mail'), 'url' => route('mail.index')], ['label' => $record->domain]]">
    <x-slot:head>
        <div class="ap-entity">
            <span class="ap-entity-icon"><x-ap-icon name="mail" /></span>
            <div class="ap-entity-text">
                <span class="eyebrow">{{ __('Owner') }}: {{ $record->user }} · {{ __('Mail Accounts') }}</span>
                <h1 class="mono">{{ $record->domain }}</h1>
                <div class="ap-row">
                    <x-ap-badge :tone="$record->suspended ? 'danger' : 'ok'">{{ $record->suspended ? __('Suspended') : __('Active') }}</x-ap-badge>
                    <x-ap-badge>{{ __('Accounts') }}: {{ count($accounts) }}</x-ap-badge>
                </div>
            </div>
        </div>
    </x-slot:head>
    <x-slot:actions>
        <x-ap-button :href="route('mail.index')" icon="return">{{ __('Back') }}</x-ap-button>
        <x-ap-button :href="route('mail.dns', $params)" icon="dns">{{ __('DNS Records') }}</x-ap-button>
        @if ($can['update'] && ! $record->suspended)
            <x-ap-button :href="route('mail.edit', $params)" icon="edit">{{ __('Edit Mail Domain') }}</x-ap-button>
        @endif
        @if ($can['create'] && ! $record->suspended)
            <x-ap-button :href="route('mail.accounts.create', $params)" variant="primary" icon="plus">{{ __('Add Mail Account') }}</x-ap-button>
        @endif
    </x-slot:actions>

    <x-ap-card :flush="true">
        @if ($canBulk)
            <x-ap-bulk-bar table="mail-accounts" :action="route('mail.accounts.bulk', $params)" :actions="$bulkActions" />
        @endif
        <x-ap-table id="mail-accounts" :columns="$columns" :selectable="$canBulk" :search-label="__('Search mail accounts')" :caption="__('Mail Accounts')">
            @forelse ($accounts as $account)
                @php
                    $accountParams = $params + ['account' => $account->account];
                    $email = $account->email();
                @endphp
                <tr data-row data-search="{{ $email }} {{ implode(' ', $account->aliases) }} {{ implode(' ', $account->forwards) }}" @class(['is-disabled' => $account->suspended])>
                    @if ($canBulk)
                        <x-ap-row-check :value="$account->account" :label="__('Select :name', ['name' => $email])" form="mail-accounts-bulk" />
                    @endif
                    <td data-col="name" data-label="{{ __('Name') }}" data-sort-value="{{ $account->account }}">
                        @if ($can['update'] && ! $account->suspended)
                            <a class="mono" href="{{ route('mail.accounts.edit', $accountParams) }}" title="{{ __('Edit Mail Account') }}: {{ $email }}">{{ $email }}</a>
                        @else
                            <span class="mono">{{ $email }}</span>
                        @endif
                    </td>
                    <td data-col="disk" data-label="{{ __('Disk') }}" class="col-num mono" data-sort-value="{{ $account->diskMb }}">{{ $size($account->diskMb) }}</td>
                    <td data-col="quota" data-label="{{ __('Quota') }}" class="col-num mono" data-sort-value="{{ $account->unlimitedQuota() ? PHP_INT_MAX : (int) $account->quota }}">
                        @if ($account->unlimitedQuota())<x-ap-inf />@else{{ $size((int) $account->quota) }}@endif
                    </td>
                    <td data-col="aliases" data-label="{{ __('Aliases') }}">
                        @forelse ($account->aliases as $alias)
                            <div class="mono">{{ $alias }}@<span class="muted">{{ $record->domain }}</span></div>
                        @empty
                            <span class="muted">{{ __('none') }}</span>
                        @endforelse
                    </td>
                    <td data-col="forwarding" data-label="{{ __('Forwarding') }}">
                        @if ($account->discards())
                            <x-ap-badge tone="warn">{{ __('Discard all mail') }}</x-ap-badge>
                        @else
                            @forelse ($account->forwardAddresses() as $forward)
                                <div class="mono">{{ $forward }}</div>
                            @empty
                                <span class="muted">{{ __('none') }}</span>
                            @endforelse
                        @endif
                    </td>
                    <td data-col="autoreply" data-label="{{ __('Auto Reply') }}" data-sort-value="{{ $account->autoreply ? 'yes' : 'no' }}">
                        <x-ap-badge :tone="$account->autoreply ? 'ok' : null">{{ $account->autoreply ? __('Enabled') : __('Disabled') }}</x-ap-badge>
                    </td>
                    <td data-col="status" data-label="{{ __('Status') }}" data-sort-value="{{ $account->suspended ? 'suspended' : 'active' }}">
                        <x-ap-badge :tone="$account->suspended ? 'danger' : 'ok'">{{ $account->suspended ? __('Suspended') : __('Active') }}</x-ap-badge>
                    </td>
                    <td class="col-actions">
                        <details class="ap-dropdown ap-row-menu">
                            <summary class="ap-btn ap-btn-icon ap-btn-ghost ap-btn-sm" aria-label="{{ __('Actions for :name', ['name' => $email]) }}" title="{{ __('Actions') }}"><x-ap-icon name="more" /></summary>
                            <div class="ap-menu" role="group" aria-label="{{ __('Actions for :name', ['name' => $email]) }}">
                                @if ($webmailUrl && ! $account->suspended)
                                    <a class="ap-menu-item" href="{{ $webmailUrl.'?'.http_build_query(['_user' => $email]) }}" target="_blank" rel="noopener"><x-ap-icon name="external" />{{ __('Open Webmail') }}</a>
                                @endif
                                @if ($signOn($account))
                                    <x-ap-button class="ap-menu-item" :form-action="route('mail.accounts.webmail', $accountParams)" icon="key" formtarget="_blank">{{ __('Open Webmail (signed in)') }}</x-ap-button>
                                @endif
                                @if ($can['update'] && ! $account->suspended)
                                    <a class="ap-menu-item" href="{{ route('mail.accounts.edit', $accountParams) }}"><x-ap-icon name="edit" />{{ __('Edit Mail Account') }}</a>
                                @endif
                                @if ($can['suspend'])
                                    <div class="ap-menu-sep" role="separator"></div>
                                    @if ($account->suspended)
                                        <x-ap-button class="ap-menu-item" :form-action="route('mail.accounts.unsuspend', $accountParams)" icon="play"
                                            :confirm="sprintf(__('Are you sure you want to unsuspend %s?'), $account->account)">{{ __('Unsuspend') }}</x-ap-button>
                                    @else
                                        <x-ap-button class="ap-menu-item" :form-action="route('mail.accounts.suspend', $accountParams)" icon="pause"
                                            :confirm="sprintf(__('Are you sure you want to suspend %s?'), $account->account)">{{ __('Suspend') }}</x-ap-button>
                                    @endif
                                @endif
                                @if ($can['delete'])
                                    <x-ap-button class="ap-menu-item is-danger" variant="danger" :form-action="route('mail.accounts.destroy', $accountParams)" form-method="DELETE" icon="trash"
                                        :confirm="sprintf(__('Are you sure you want to delete %s?'), $account->account)">{{ __('Delete') }}</x-ap-button>
                                @endif
                            </div>
                        </details>
                    </td>
                </tr>
            @empty
                <tr><td colspan="{{ count($columns) + 2 }}" class="muted">{{ __('This domain has no mail accounts.') }}</td></tr>
            @endforelse
        </x-ap-table>
    </x-ap-card>
</x-ap-page>
