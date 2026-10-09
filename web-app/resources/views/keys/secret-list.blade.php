{{-- Secret store of an account (E38 spec §7): names and fingerprints, never values. --}}
@php
    $columns = [
        ['key' => 'name', 'label' => __('Name'), 'sortable' => true],
        ['key' => 'version', 'label' => __('Version'), 'sortable' => true],
        ['key' => 'fingerprint', 'label' => __('Fingerprint'), 'sortable' => false],
        ['key' => 'used', 'label' => __('Used by'), 'sortable' => true],
        ['key' => 'date', 'label' => __('Changed'), 'sortable' => true],
    ];
@endphp
<x-ap-page :title="__('Secrets')" :crumbs="[['label' => __('Account')], ['label' => __('Secrets')]]"
    :description="__('Passwords, tokens and keys of :user, encrypted on the server. Apps use them as :reference in their environment.', ['user' => $user, 'reference' => \App\Services\Keys\UserSecretManager::reference('NAME')])">
    <x-slot:actions>
        @if ($canManage)
            <x-ap-button variant="primary" icon="plus" x-data x-on:click="$dispatch('ap-modal', { id: 'add-secret' })">{{ __('Add Secret') }}</x-ap-button>
        @endif
    </x-slot:actions>

    <x-ap-owner-switch route="secrets.index" :owners="$owners" :owner="$user" :label="__('Show secrets')" />

    <x-ap-card :flush="true">
        @if ($secrets === [])
            <x-ap-empty icon="shield-check" :title="__('No secrets')">{{ __('Add a secret, then reference it from the environment of a Node.js or Docker app.') }}</x-ap-empty>
        @else
            <x-ap-table id="secrets" :columns="$columns" :search-label="__('Search secrets')" :caption="__('Secrets')">
                @foreach ($secrets as $name => $secret)
                    <tr data-row data-search="{{ $name }} {{ $secret['DESCRIPTION'] ?? '' }}">
                        <td data-col="name" data-label="{{ __('Name') }}" data-sort-value="{{ $name }}">
                            <a class="mono" href="{{ route('secrets.show', ['user' => $user, 'name' => $name]) }}">{{ $name }}</a>
                            @if (($secret['DESCRIPTION'] ?? '') !== '')
                                <div class="muted">{{ $secret['DESCRIPTION'] }}</div>
                            @endif
                        </td>
                        <td data-col="version" data-label="{{ __('Version') }}" data-sort-value="{{ (int) ($secret['VERSION'] ?? 0) }}">{{ $secret['VERSION'] ?? '' }}</td>
                        <td data-col="fingerprint" data-label="{{ __('Fingerprint') }}"><span class="mono">{{ $secret['FINGERPRINT'] ?? '' }}</span></td>
                        <td data-col="used" data-label="{{ __('Used by') }}" data-sort-value="{{ (int) ($secret['USED_BY'] ?? 0) }}">
                            {{ trans_choice(':count app|:count apps', (int) ($secret['USED_BY'] ?? 0), ['count' => (int) ($secret['USED_BY'] ?? 0)]) }}
                        </td>
                        <td data-col="date" data-label="{{ __('Changed') }}" data-sort-value="{{ ($secret['DATE'] ?? '').' '.($secret['TIME'] ?? '') }}">{{ $secret['DATE'] ?? '' }} {{ $secret['TIME'] ?? '' }}</td>
                        <td class="col-actions">
                            <x-ap-button variant="ghost" size="sm" :icon-only="true" icon="eye" :label="__('Open :name', ['name' => $name])"
                                :href="route('secrets.show', ['user' => $user, 'name' => $name])" />
                        </td>
                    </tr>
                @endforeach
                <x-slot:footer>
                    <span class="muted">{{ trans_choice(':count secret|:count secrets', count($secrets), ['count' => count($secrets)]) }}</span>
                </x-slot:footer>
            </x-ap-table>
        @endif
    </x-ap-card>

    @if ($canManage)
        <x-ap-drawer id="add-secret" :title="__('Add Secret')" :open="$errors->hasAny(['name', 'value', 'description'])">
            <x-ap-form :action="route('secrets.store', ['user' => $user])" x-data="{ multiline: false }">
                <x-ap-input name="name" :label="__('Name')" :hint="__('A path like /shop/db_password: one to five parts of letters, digits, _ . and -.')" required mono />
                <div x-show="!multiline">
                    <x-ap-password name="value" :label="__('Value')" :meter="false" x-bind:disabled="multiline" required />
                </div>
                <div x-show="multiline" x-cloak>
                    <x-ap-textarea name="value" id="value-multiline" :label="__('Value')" mono rows="8" x-bind:disabled="!multiline" disabled />
                </div>
                <label class="ap-check">
                    <input type="checkbox" x-model="multiline">
                    <span>{{ __('Several lines (a PEM key or a certificate)') }}</span>
                </label>
                <x-ap-input name="description" :label="__('Description')" :hint="__('Optional, for example Shop database.')" />
                <p class="muted">{{ __('The value cannot be shown again after saving. It is encrypted with a key of this account.') }}</p>
                <div class="ap-form-actions">
                    <x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button>
                </div>
            </x-ap-form>
        </x-ap-drawer>
    @endif
</x-ap-page>
