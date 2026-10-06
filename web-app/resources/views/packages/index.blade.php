{{-- Packages (web/list/package) with reseller settings, E11 spec §8. --}}
@php
    $columns = array_values(array_filter([
        ['key' => 'name', 'label' => __('Package'), 'sortable' => true],
        ['key' => 'web', 'label' => __('Web Domains'), 'type' => 'number'],
        ['key' => 'mail', 'label' => __('Mail Accounts'), 'type' => 'number', 'optional' => true],
        ['key' => 'db', 'label' => __('Databases'), 'type' => 'number', 'optional' => true],
        ['key' => 'disk', 'label' => __('Disk'), 'type' => 'number'],
        ['key' => 'bandwidth', 'label' => __('Bandwidth'), 'type' => 'number', 'optional' => true],
        $showResellerColumns ? ['key' => 'customers', 'label' => __('Customers'), 'optional' => true] : null,
        $showResellerColumns ? ['key' => 'visible', 'label' => __('For resellers')] : null,
        ['key' => 'owner', 'label' => __('Owner'), 'optional' => true],
        $showResellerColumns ? ['key' => 'users', 'label' => __('Users'), 'sortable' => true, 'type' => 'number'] : null,
    ]));
    $limit = fn (string $value, bool $size = false) => $value === 'unlimited' ? new \Illuminate\Support\HtmlString('<span class="ap-inf" title="'.e(__('Unlimited')).'"><span aria-hidden="true">∞</span><span class="sr-only">'.e(__('Unlimited')).'</span></span>') : ($size ? \App\Services\Dashboard\DashboardService::size((int) $value) : $value);
@endphp
<x-ap-page :title="__('Packages')" :crumbs="[['label' => __('Packages')]]"
    :description="$isReseller ? __('Packages you can give your customers. Your own packages carry your limits at most.') : __('Hosting plans and what resellers may sell.')">
    <x-slot:actions>
        @if ($canCreate)
            <x-ap-button :href="route('packages.create')" variant="primary" icon="plus">{{ __('Add Package') }}</x-ap-button>
        @endif
    </x-slot:actions>

    <x-ap-card :flush="true">
        @if ($records === [])
            <x-ap-empty icon="package" :title="__('No packages yet')" />
        @else
            <x-ap-table id="packages" :columns="$columns" :search-label="__('Search packages')" :caption="__('Packages')">
                @foreach ($records as $name => $record)
                    @php($can = $abilities[$name])
                    <tr data-row data-search="{{ $name }}">
                        <td data-col="name" data-label="{{ __('Package') }}" data-sort-value="{{ $name }}">
                            @if ($can['update'])
                                <a class="mono" href="{{ route('packages.edit', ['package' => $name]) }}">{{ \App\Services\Users\UserService::packageLabel($name, $reseller) }}</a>
                            @else
                                <span class="mono">{{ \App\Services\Users\UserService::packageLabel($name, $reseller) }}</span>
                            @endif
                        </td>
                        <td data-col="web" data-label="{{ __('Web Domains') }}" class="col-num mono">{{ $limit($record->get('WEB_DOMAINS')) }}</td>
                        <td data-col="mail" data-label="{{ __('Mail Accounts') }}" class="col-num mono">{{ $limit($record->get('MAIL_ACCOUNTS')) }}</td>
                        <td data-col="db" data-label="{{ __('Databases') }}" class="col-num mono">{{ $limit($record->get('DATABASES')) }}</td>
                        <td data-col="disk" data-label="{{ __('Disk') }}" class="col-num mono">{{ $limit($record->get('DISK_QUOTA'), true) }}</td>
                        <td data-col="bandwidth" data-label="{{ __('Bandwidth') }}" class="col-num mono">{{ $limit($record->get('BANDWIDTH'), true) }}</td>
                        @if ($showResellerColumns)
                            <td data-col="customers" data-label="{{ __('Customers') }}" class="mono">{{ $record->get('USERS', '0') === '0' ? '—' : $limit($record->get('USERS')) }}</td>
                            <td data-col="visible" data-label="{{ __('For resellers') }}">
                                <x-ap-badge :tone="$record->visibleToResellers() ? 'ok' : null">{{ $record->visibleToResellers() ? __('Yes') : __('No') }}</x-ap-badge>
                            </td>
                        @endif
                        <td data-col="owner" data-label="{{ __('Owner') }}" class="muted">{{ $record->owner() ?: '—' }}</td>
                        @if ($showResellerColumns)
                            <td data-col="users" data-label="{{ __('Users') }}" class="col-num mono" data-sort-value="{{ $record->users }}">{{ $record->users }}</td>
                        @endif
                        <td class="col-actions">
                            @if ($can['update'] || $can['delete'] || $can['copy'])
                                <details class="ap-dropdown ap-row-menu">
                                    <summary class="ap-btn ap-btn-icon ap-btn-ghost ap-btn-sm" aria-label="{{ __('Actions for :package', ['package' => $name]) }}" title="{{ __('Actions') }}"><x-ap-icon name="more" /></summary>
                                    <div class="ap-menu" role="group" aria-label="{{ __('Actions for :package', ['package' => $name]) }}">
                                        @if ($can['update'])
                                            <a class="ap-menu-item" href="{{ route('packages.edit', ['package' => $name]) }}"><x-ap-icon name="edit" />{{ __('Edit') }}</a>
                                        @endif
                                        @if ($can['copy'])
                                            <form method="post" action="{{ route('packages.copy', ['package' => $name]) }}" class="ap-switch-form">
                                                @csrf
                                                <label class="sr-only" for="copy-{{ $loop->index }}">{{ __('New package name') }}</label>
                                                <input class="ap-input" id="copy-{{ $loop->index }}" name="name" required placeholder="{{ __('New package name') }}">
                                                <button type="submit" class="ap-btn ap-btn-sm"><x-ap-icon name="copy" />{{ __('Copy') }}</button>
                                            </form>
                                        @endif
                                        @if ($can['delete'])
                                            <x-ap-button class="ap-menu-item is-danger" variant="danger" :form-action="route('packages.destroy', ['package' => $name])" form-method="DELETE" icon="trash"
                                                :confirm="sprintf(__('Are you sure you want to delete package %s?'), $name)">{{ __('Delete') }}</x-ap-button>
                                        @endif
                                    </div>
                                </details>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-ap-table>
        @endif
    </x-ap-card>
</x-ap-page>
