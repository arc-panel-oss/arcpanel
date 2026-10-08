{{-- API access keys of an account (web/list/access-key, E7 batch 2 spec §8.2; columns of spec 2026-10-08-008 U1). --}}
@php
    $columns = [
        ['key' => 'id', 'label' => __('Access Key'), 'sortable' => true],
        ['key' => 'permissions', 'label' => __('Permissions')],
        ['key' => 'created', 'label' => __('Created'), 'sortable' => true],
    ];
@endphp
<x-ap-page :title="__('Access Keys')" :crumbs="[['label' => __('Account')], ['label' => __('Access Keys')]]"
    :description="__('Keys that let scripts and other services call the API for :user.', ['user' => $user])">
    <x-slot:actions>
        @if ($canManage)
            <x-ap-button :href="route('access-keys.create', ['user' => $user])" variant="primary" icon="plus">{{ __('Add Access Key') }}</x-ap-button>
        @endif
    </x-slot:actions>

    <x-ap-owner-switch route="access-keys.index" :owners="$owners" :owner="$user" :label="__('Show access keys')" />

    <x-ap-card :flush="true">
        @if ($keys === [])
            <x-ap-empty icon="key" :title="__('No access keys')">{{ __('Add an access key to use the API.') }}</x-ap-empty>
        @else
            @if ($canManage)
                <x-ap-bulk-bar table="access-keys" :action="route('access-keys.bulk', ['user' => $user])"
                    :actions="['delete' => ['label' => __('Delete'), 'icon' => 'trash', 'danger' => true, 'confirm' => __('Delete %d access keys?')]]" />
            @endif
            <x-ap-table id="access-keys" :columns="$columns" :selectable="$canManage" :search-label="__('Search access keys')" :caption="__('Access Keys')">
                @foreach ($keys as $key)
                    @php
                        $params = ['user' => $user, 'key' => $key->id];
                        $shown = array_slice($key->permissions, 0, 3);
                        $more = array_slice($key->permissions, 3);
                    @endphp
                    <tr data-row data-search="{{ trim($key->id.' '.$key->comment.' '.implode(' ', $key->permissions)) }}">
                        @if ($canManage)
                            <x-ap-row-check :value="$key->id" :label="__('Select :name', ['name' => $key->id])" form="access-keys-bulk" />
                        @endif
                        <td data-col="id" data-label="{{ __('Access Key') }}" data-sort-value="{{ $key->comment !== '' ? $key->comment : $key->id }}">
                            <div class="ap-cell-main ap-key-name">
                                @if ($key->comment !== '')
                                    <a href="{{ route('access-keys.show', $params) }}"><b>{{ $key->comment }}</b></a>
                                    <span class="mono">{{ $key->id }}</span>
                                @else
                                    <a href="{{ route('access-keys.show', $params) }}"><b class="mono">{{ $key->id }}</b></a>
                                @endif
                            </div>
                        </td>
                        <td data-col="permissions" data-label="{{ __('Permissions') }}">
                            @if ($key->permissions === [])
                                -
                            @else
                                <div class="ap-row ap-key-sets-cell">
                                    @foreach ($shown as $permission)
                                        <x-ap-badge :mono="true">{{ $permission }}</x-ap-badge>
                                    @endforeach
                                    @if ($more !== [])
                                        <x-ap-badge :dot="false" title="{{ implode(', ', $more) }}">
                                            <span aria-hidden="true">+{{ count($more) }}</span>
                                            <span class="sr-only">{{ __(':count more: :sets', ['count' => count($more), 'sets' => implode(', ', $more)]) }}</span>
                                        </x-ap-badge>
                                    @endif
                                </div>
                            @endif
                        </td>
                        <td data-col="created" data-label="{{ __('Created') }}" data-sort-value="{{ $key->date }} {{ $key->time }}" class="nowrap">
                            @if ($key->date !== '')
                                <span>{{ $key->date }} <span class="muted">{{ $key->time }}</span></span>
                            @else
                                -
                            @endif
                        </td>
                        <td class="col-actions">
                            @if ($canManage)
                                <x-ap-button variant="ghost" size="sm" :icon-only="true" icon="trash" :label="__('Delete :name', ['name' => $key->id])"
                                    :form-action="route('access-keys.destroy', $params)" form-method="DELETE"
                                    :confirm="sprintf(__('Are you sure you want to delete access key %s?'), $key->id)" :confirm-accept="__('Delete')" :confirm-danger="true" />
                            @endif
                        </td>
                    </tr>
                @endforeach
                <x-slot:footer>
                    <span class="muted">{{ trans_choice(':count access key|:count access keys', count($keys), ['count' => count($keys)]) }}</span>
                </x-slot:footer>
            </x-ap-table>
        @endif
    </x-ap-card>
</x-ap-page>
