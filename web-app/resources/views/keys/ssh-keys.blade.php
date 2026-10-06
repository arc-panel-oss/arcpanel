{{-- SSH keys of an account (web/list/key, web/add/key, E7 batch 2 spec §8.3). --}}
@php
    $columns = [
        ['key' => 'id', 'label' => __('SSH ID'), 'sortable' => true],
        ['key' => 'key', 'label' => __('SSH Key'), 'sortable' => false],
    ];
@endphp
<x-ap-page :title="__('SSH Keys')" :crumbs="[['label' => __('Account')], ['label' => __('SSH Keys')]]"
    :description="__('Public keys that may sign in to :user over SSH and SFTP.', ['user' => $user])">
    <x-slot:actions>
        @if ($canManage)
            <x-ap-button variant="primary" icon="plus" x-data x-on:click="$dispatch('ap-modal', { id: 'add-ssh-key' })">{{ __('Add SSH Key') }}</x-ap-button>
        @endif
    </x-slot:actions>

    <x-ap-owner-switch route="ssh-keys.index" :owners="$owners" :owner="$user" :label="__('Show SSH keys')" />

    <x-ap-card :flush="true">
        @if ($keys === [])
            <x-ap-empty icon="lock" :title="__('No SSH keys')">{{ __('Add a public key to sign in without a password.') }}</x-ap-empty>
        @else
            <x-ap-table id="ssh-keys" :columns="$columns" :search-label="__('Search SSH keys')" :caption="__('SSH Keys')">
                @foreach ($keys as $id => $fingerprint)
                    <tr data-row data-search="{{ $id }} {{ $fingerprint }}">
                        <td data-col="id" data-label="{{ __('SSH ID') }}" data-sort-value="{{ $id }}">{{ $id }}</td>
                        <td data-col="key" data-label="{{ __('SSH Key') }}"><span class="mono ap-trunc" title="{{ $fingerprint }}">{{ $fingerprint }}</span></td>
                        <td class="col-actions">
                            @if ($canManage)
                                <x-ap-button variant="ghost" size="sm" :icon-only="true" icon="trash" :label="__('Delete :name', ['name' => $id])"
                                    :form-action="route('ssh-keys.destroy', ['user' => $user, 'id' => $id])" form-method="DELETE"
                                    :confirm="sprintf(__('Are you sure you want to delete SSH key %s?'), $id)" />
                            @endif
                        </td>
                    </tr>
                @endforeach
                <x-slot:footer>
                    <span class="muted">{{ trans_choice(':count SSH key|:count SSH keys', count($keys), ['count' => count($keys)]) }}</span>
                </x-slot:footer>
            </x-ap-table>
        @endif
    </x-ap-card>

    @if ($canManage)
        <x-ap-drawer id="add-ssh-key" :title="__('Add SSH Key')" :open="$errors->has('key') || old('key') !== null">
            <x-ap-form :action="route('ssh-keys.store', ['user' => $user])">
                <x-ap-textarea name="key" :label="__('SSH Key')" :hint="__('The public key, e.g. ssh-ed25519 AAAA… name@host')" required mono rows="6" />
                <div class="ap-form-actions">
                    <x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button>
                </div>
            </x-ap-form>
        </x-ap-drawer>
    @endif
</x-ap-page>
