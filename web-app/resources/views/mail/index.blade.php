{{-- Mail domains list (web/list/mail, E7 batch 1 spec §9.2). --}}
@php
    $columns = array_values(array_filter([
        ['key' => 'name', 'label' => __('Name'), 'sortable' => true],
        $showOwner ? ['key' => 'owner', 'label' => __('Owner'), 'sortable' => true] : null,
        ['key' => 'accounts', 'label' => __('Accounts'), 'sortable' => true, 'type' => 'number'],
        ['key' => 'disk', 'label' => __('Disk'), 'sortable' => true, 'type' => 'number'],
        ['key' => 'antivirus', 'label' => __('Anti-Virus'), 'sortable' => true, 'optional' => true],
        ['key' => 'antispam', 'label' => __('Spam Filter'), 'sortable' => true, 'optional' => true],
        ['key' => 'dkim', 'label' => __('DKIM'), 'sortable' => true, 'optional' => true],
        ['key' => 'ssl', 'label' => __('SSL'), 'sortable' => true],
        ['key' => 'status', 'label' => __('Status'), 'sortable' => true],
    ]));
    $filter = array_filter(['owner' => $owner]);
    $size = fn (int $mb) => \App\Services\Dashboard\DashboardService::size($mb);
    $flag = fn (bool $on) => $on ? __('Enabled') : __('Disabled');
@endphp
<x-ap-page :title="__('Mail')" :crumbs="[['label' => __('Mail')]]"
    :description="$everyUser ? __('Mail domains of every user on this server.') : ($showOwner ? __('Your mail domains and those of your customers.') : __('The domains this server receives mail for.'))">
    <x-slot:actions>
        @if ($canCreate)
            <x-ap-button :href="route('mail.create', $filter)" variant="primary" icon="plus">{{ __('Add Mail Domain') }}</x-ap-button>
        @endif
    </x-slot:actions>

    <x-ap-card :flush="true">
        @if ($total === 0 && $owner === '')
            <x-ap-empty icon="mail" :title="__('No mail domains yet')">{{ __('Add a mail domain to create mailboxes for it.') }}
                @if ($canCreate)
                    <x-slot:actions><x-ap-button :href="route('mail.create')" variant="primary" icon="plus">{{ __('Add Mail Domain') }}</x-ap-button></x-slot:actions>
                @endif
            </x-ap-empty>
        @else
            @if ($canBulk)
                <x-ap-bulk-bar table="mail-domains" :action="route('mail.bulk', $filter)" :actions="$bulkActions" />
            @endif
            <x-ap-table id="mail-domains" :columns="$columns" :selectable="$canBulk" :search-label="__('Search mail domains')" :caption="__('Mail Domains')">
                @if ($showOwner)
                    <x-slot:toolbar>
                        <x-ap-owner-filter route="mail.index" :owners="$owners" :owner="$owner" />
                    </x-slot:toolbar>
                @endif

                @forelse ($records as $record)
                    @php
                        $can = $abilities[$record->user];
                        $params = ['user' => $record->user, 'domain' => $record->domain];
                        $webmailUrl = $webmail && ! $record->suspended ? $record->webmailUrl($webmailAlias) : null;
                    @endphp
                    <tr data-row data-search="{{ $record->domain }} {{ $record->user }}" @class(['is-disabled' => $record->suspended])>
                        @if ($canBulk)
                            <x-ap-row-check :value="$record->user.'/'.$record->domain" :label="__('Select :name', ['name' => $record->domain])" form="mail-domains-bulk" />
                        @endif
                        <td data-col="name" data-label="{{ __('Name') }}" data-sort-value="{{ $record->domain }}">
                            <a class="mono" href="{{ route('mail.accounts.index', $params) }}" title="{{ __('Mail Accounts') }}: {{ $record->domain }}">{{ $record->domain }}</a>
                        </td>
                        @if ($showOwner)
                            <td data-col="owner" data-label="{{ __('Owner') }}" class="muted">{{ $record->user }}</td>
                        @endif
                        <td data-col="accounts" data-label="{{ __('Accounts') }}" data-sort-value="{{ $record->accounts }}">{{ $record->accounts }}</td>
                        <td data-col="disk" data-label="{{ __('Disk') }}" class="col-num mono" data-sort-value="{{ $record->diskMb }}">{{ $size($record->diskMb) }}</td>
                        <td data-col="antivirus" data-label="{{ __('Anti-Virus') }}" data-sort-value="{{ $record->antivirus ? 'yes' : 'no' }}">
                            <x-ap-badge :tone="$record->antivirus ? 'ok' : null">{{ $flag($record->antivirus) }}</x-ap-badge>
                        </td>
                        <td data-col="antispam" data-label="{{ __('Spam Filter') }}" data-sort-value="{{ $record->antispam ? 'yes' : 'no' }}">
                            <x-ap-badge :tone="$record->antispam ? 'ok' : null">{{ $flag($record->antispam) }}</x-ap-badge>
                        </td>
                        <td data-col="dkim" data-label="{{ __('DKIM') }}" data-sort-value="{{ $record->dkim ? 'yes' : 'no' }}">
                            <x-ap-badge :tone="$record->dkim ? 'ok' : null">{{ $flag($record->dkim) }}</x-ap-badge>
                        </td>
                        <td data-col="ssl" data-label="{{ __('SSL') }}" data-sort-value="{{ $record->ssl ? 'yes' : 'no' }}">
                            <x-ap-badge :tone="$record->ssl ? 'ok' : null">{{ $record->ssl ? ($record->letsEncrypt ? __('Let’s Encrypt') : __('Enabled')) : __('Disabled') }}</x-ap-badge>
                        </td>
                        <td data-col="status" data-label="{{ __('Status') }}" data-sort-value="{{ $record->suspended ? 'suspended' : 'active' }}">
                            <x-ap-badge :tone="$record->suspended ? 'danger' : 'ok'">{{ $record->suspended ? __('Suspended') : __('Active') }}</x-ap-badge>
                        </td>
                        <td class="col-actions">
                            <details class="ap-dropdown ap-row-menu">
                                <summary class="ap-btn ap-btn-icon ap-btn-ghost ap-btn-sm" aria-label="{{ __('Actions for :name', ['name' => $record->domain]) }}" title="{{ __('Actions') }}"><x-ap-icon name="more" /></summary>
                                <div class="ap-menu" role="group" aria-label="{{ __('Actions for :name', ['name' => $record->domain]) }}">
                                    <a class="ap-menu-item" href="{{ route('mail.accounts.index', $params) }}"><x-ap-icon name="list" />{{ __('Mail Accounts') }}</a>
                                    @if ($can['create'] && ! $record->suspended)
                                        <a class="ap-menu-item" href="{{ route('mail.accounts.create', $params) }}"><x-ap-icon name="user-plus" />{{ __('Add Mail Account') }}</a>
                                    @endif
                                    @if ($webmailUrl)
                                        <a class="ap-menu-item" href="{{ $webmailUrl }}" target="_blank" rel="noopener"><x-ap-icon name="external" />{{ __('Open Webmail') }}</a>
                                    @endif
                                    <a class="ap-menu-item" href="{{ route('mail.dns', $params) }}"><x-ap-icon name="dns" />{{ __('DNS Records') }}</a>
                                    @if ($can['update'] && ! $record->suspended)
                                        <a class="ap-menu-item" href="{{ route('mail.edit', $params) }}"><x-ap-icon name="edit" />{{ __('Edit Mail Domain') }}</a>
                                    @endif
                                    @if ($can['suspend'])
                                        <div class="ap-menu-sep" role="separator"></div>
                                        @if ($record->suspended)
                                            <x-ap-button class="ap-menu-item" :form-action="route('mail.unsuspend', $params)" icon="play"
                                                :confirm="sprintf(__('Are you sure you want to unsuspend domain %s?'), $record->domain)">{{ __('Unsuspend') }}</x-ap-button>
                                        @else
                                            <x-ap-button class="ap-menu-item" :form-action="route('mail.suspend', $params)" icon="pause"
                                                :confirm="sprintf(__('Are you sure you want to suspend domain %s?'), $record->domain)">{{ __('Suspend') }}</x-ap-button>
                                        @endif
                                    @endif
                                    @if ($can['delete'])
                                        <x-ap-button class="ap-menu-item is-danger" variant="danger" :form-action="route('mail.destroy', $params)" form-method="DELETE" icon="trash"
                                            :confirm="sprintf(__('Are you sure you want to delete domain %s?'), $record->domain)">{{ __('Delete') }}</x-ap-button>
                                    @endif
                                </div>
                            </details>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="{{ count($columns) + 2 }}" class="muted">{{ __('No mail domains for this owner.') }}</td></tr>
                @endforelse

                <x-slot:footer>
                    <x-ap-pager :page="$page" :pages="$pages" :total="$total" :per-page="\App\Http\Controllers\Mail\MailDomainController::PAGE_SIZE"
                        :url="route('mail.index', $filter)" />
                </x-slot:footer>
            </x-ap-table>
        @endif
    </x-ap-card>
</x-ap-page>
