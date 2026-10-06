{{-- One API access key (web/list/access-key?key=); right after creation with its secret, once (E7 batch 2 spec §8.2). --}}
@php
    $list = route('access-keys.list', ['user' => $user]);
@endphp
<x-ap-page :title="__('Access Key')" :crumbs="[['label' => __('Account')], ['label' => __('Access Keys'), 'url' => $list], ['label' => $key->id]]">
    @if ($created)
        {{-- A reload must not post the form again --}}
        <div x-data x-init="history.replaceState(null, '', {{ json_encode(route('access-keys.show', ['user' => $user, 'key' => $key->id])) }})"></div>
        <x-ap-alert tone="ok" :title="sprintf(__('Access key %s has been created successfully.'), $key->id)" />
        <x-ap-alert tone="warn" :title="__('Warning! Last chance to save secret key!')">{{ __('The secret is shown only now. Store it somewhere safe.') }}</x-ap-alert>
    @endif

    <x-ap-card :title="__('Access Key')" icon="key">
        <dl class="ap-dl">
            <dt>{{ __('Access Key ID') }}</dt>
            <dd class="mono">{{ $key->id }}</dd>
            @if ($created && $key->secret !== null)
                <dt>{{ __('Secret Access Key') }}</dt>
                <dd>
                    <div class="ap-input-group" x-data="{ copied: false }">
                        <input class="ap-input ap-input-mono" id="access-key-secret" type="text" readonly value="{{ $key->secret }}" aria-label="{{ __('Secret Access Key') }}">
                        <button type="button" class="ap-btn" x-on:click="navigator.clipboard.writeText($el.previousElementSibling.value).then(() => { copied = true })">
                            <x-ap-icon name="copy" /><span x-text="copied ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span>
                        </button>
                    </div>
                </dd>
            @endif
            <dt>{{ __('Permissions') }}</dt>
            <dd>
                @if ($key->permissions === [])
                    -
                @else
                    <div class="ap-row">
                        @foreach ($key->permissions as $permission)
                            <x-ap-badge :dot="false">{{ __($permission) }}</x-ap-badge>
                        @endforeach
                    </div>
                @endif
            </dd>
            <dt>{{ __('Comment') }}</dt>
            <dd>{{ $key->comment !== '' ? $key->comment : '-' }}</dd>
        </dl>

        <div class="ap-form-actions">
            <x-ap-button :href="$list" icon="chevron-left">{{ __('Back') }}</x-ap-button>
            @if ($canManage)
                <x-ap-button variant="danger" :form-action="route('access-keys.destroy', ['user' => $user, 'key' => $key->id])" form-method="DELETE" icon="trash"
                    :confirm="sprintf(__('Are you sure you want to delete access key %s?'), $key->id)">{{ __('Delete') }}</x-ap-button>
            @endif
        </div>
    </x-ap-card>
</x-ap-page>
