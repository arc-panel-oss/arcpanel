{{-- API access keys of an account (web/list/access-key, E7 batch 2 spec §8.2). --}}
@php
    $columns = [
        ['key' => 'id', 'label' => __('Access Key'), 'sortable' => true],
        ['key' => 'comment', 'label' => __('Comment'), 'sortable' => true],
        ['key' => 'date', 'label' => __('Date'), 'sortable' => true],
        ['key' => 'time', 'label' => __('Time'), 'sortable' => true],
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
                    @php($params = ['user' => $user, 'key' => $key->id])
                    <tr data-row data-search="{{ $key->id }} {{ $key->comment }}">
                        @if ($canManage)
                            <x-ap-row-check :value="$key->id" :label="__('Select :name', ['name' => $key->id])" form="access-keys-bulk" />
                        @endif
                        <td data-col="id" data-label="{{ __('Access Key') }}" data-sort-value="{{ $key->id }}">
                            <a class="mono" href="{{ route('access-keys.show', $params) }}">{{ $key->id }}</a>
                        </td>
                        <td data-col="comment" data-label="{{ __('Comment') }}">{{ $key->comment !== '' ? $key->comment : '-' }}</td>
                        <td data-col="date" data-label="{{ __('Date') }}">{{ $key->date !== '' ? $key->date : '-' }}</td>
                        <td data-col="time" data-label="{{ __('Time') }}">{{ $key->time !== '' ? $key->time : '-' }}</td>
                        <td class="col-actions">
                            @if ($canManage)
                                <x-ap-button variant="ghost" size="sm" :icon-only="true" icon="trash" :label="__('Delete :name', ['name' => $key->id])"
                                    :form-action="route('access-keys.destroy', $params)" form-method="DELETE"
                                    :confirm="sprintf(__('Are you sure you want to delete access key %s?'), $key->id)" />
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
