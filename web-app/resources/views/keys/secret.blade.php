{{-- One secret of the secret store (E38 spec §7): metadata, versions and the apps that use it; never the value. --}}
@php
    $name = (string) ($secret['NAME'] ?? '');
    $reference = \App\Services\Keys\UserSecretManager::reference($name);
    $usedBy = array_values(array_filter((array) ($secret['USED_BY'] ?? []), 'is_string'));
    $versions = array_reverse(array_values(array_filter((array) ($secret['VERSIONS'] ?? []), 'is_array')));
    $current = (string) ($secret['VERSION'] ?? '');
    $list = route('secrets.list', ['user' => $user]);
    $appLink = function (string $app) use ($user): ?string {
        if (str_starts_with($app, 'node:')) {
            return route('node.apps.show', ['user' => $user, 'domain' => substr($app, 5)]);
        }
        if (str_starts_with($app, 'docker:')) {
            return route('docker.apps.show', ['user' => $user, 'name' => substr($app, 7)]);
        }

        return null;
    };
@endphp
<x-ap-page :title="$name" :eyebrow="__('Secret')" :heading="$name"
    :crumbs="[['label' => __('Account')], ['label' => __('Secrets'), 'url' => $list], ['label' => $name]]">
    @if ($canManage)
        <x-slot:actions>
            <x-ap-button variant="primary" icon="edit" x-data x-on:click="$dispatch('ap-modal', { id: 'replace-secret' })">{{ __('Replace value') }}</x-ap-button>
            <x-ap-button variant="danger" icon="trash" :form-action="route('secrets.destroy', array_filter(['user' => $user, 'name' => $name, 'force' => $usedBy === [] ? null : '1']))" form-method="DELETE"
                :confirm="$usedBy === []
                    ? sprintf(__('Are you sure you want to delete secret %s?'), $name)
                    : sprintf(__('Secret %s is used by %s. Those apps will not start until it is added again. Delete it anyway?'), $name, implode(', ', $usedBy))"
                :confirm-accept="__('Delete')">{{ __('Delete') }}</x-ap-button>
        </x-slot:actions>
    @endif

    <x-ap-card>
        <dl class="ap-facts">
            <dt>{{ __('Reference') }}</dt>
            <dd>
                <div class="ap-input-group" x-data="{ copied: false }">
                    <input class="ap-input ap-input-mono" type="text" value="{{ $reference }}" readonly x-ref="value" aria-label="{{ __('Reference') }}">
                    <button type="button" class="ap-btn ap-btn-sm" title="{{ __('Copy') }}"
                        x-on:click="navigator.clipboard?.writeText($refs.value.value).then(() => { copied = true; setTimeout(() => copied = false, 1500) })">
                        <x-ap-icon name="copy" /><span x-text="copied ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span>
                    </button>
                </div>
            </dd>
            <dt>{{ __('Description') }}</dt>
            <dd>
                @if ($canManage)
                    <x-ap-form :action="route('secrets.describe', ['user' => $user])" method="PATCH" class="ap-row">
                        <input type="hidden" name="name" value="{{ $name }}">
                        <x-ap-input name="description" :label="__('Description')" :value="$secret['DESCRIPTION'] ?? ''" />
                        <x-ap-button type="submit" size="sm" icon="save">{{ __('Save') }}</x-ap-button>
                    </x-ap-form>
                @else
                    {{ ($secret['DESCRIPTION'] ?? '') !== '' ? $secret['DESCRIPTION'] : '-' }}
                @endif
            </dd>
            <dt>{{ __('Version') }}</dt>
            <dd>{{ $current }}</dd>
            <dt>{{ __('Fingerprint') }}</dt>
            <dd class="mono">{{ $secret['FINGERPRINT'] ?? '' }}</dd>
            <dt>{{ __('Changed') }}</dt>
            <dd>{{ $secret['DATE'] ?? '' }} {{ $secret['TIME'] ?? '' }}</dd>
            <dt>{{ __('Used by') }}</dt>
            <dd>
                @if ($usedBy === [])
                    <span class="muted">{{ __('No app references this secret yet.') }}</span>
                @else
                    <ul>
                        @foreach ($usedBy as $app)
                            <li>
                                @if ($appLink($app))
                                    <a href="{{ $appLink($app) }}">{{ $app }}</a>
                                @else
                                    {{ $app }}
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </dd>
        </dl>
    </x-ap-card>

    <x-ap-card :title="__('Versions')" :flush="true">
        <x-ap-table id="secret-versions" :columns="[
            ['key' => 'version', 'label' => __('Version'), 'sortable' => false],
            ['key' => 'fingerprint', 'label' => __('Fingerprint'), 'sortable' => false],
            ['key' => 'date', 'label' => __('Date'), 'sortable' => false],
        ]" :caption="__('Versions')">
            @foreach ($versions as $version)
                @php $number = (string) ($version['VERSION'] ?? ''); @endphp
                <tr data-row>
                    <td data-col="version" data-label="{{ __('Version') }}">
                        {{ sprintf(__('Version %s'), $number) }}
                        @if ($number === $current)
                            <x-ap-badge tone="ok">{{ __('Current') }}</x-ap-badge>
                        @endif
                    </td>
                    <td data-col="fingerprint" data-label="{{ __('Fingerprint') }}"><span class="mono">{{ $version['FINGERPRINT'] ?? '' }}</span></td>
                    <td data-col="date" data-label="{{ __('Date') }}">{{ $version['DATE'] ?? '' }} {{ $version['TIME'] ?? '' }}</td>
                    <td class="col-actions">
                        @if ($canManage && $number !== $current)
                            <x-ap-button variant="ghost" size="sm" icon="history" :form-action="route('secrets.rollback', ['user' => $user, 'name' => $name, 'version' => $number])"
                                :confirm="sprintf(__('Roll %s back to version %s? The apps keep the current value until they restart.'), $name, $number)">{{ __('Roll back') }}</x-ap-button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ap-table>
    </x-ap-card>

    @if ($canManage)
        <x-ap-drawer id="replace-secret" :title="__('Replace value')" :open="$errors->hasAny(['value', 'restart'])">
            <x-ap-form :action="route('secrets.value', ['user' => $user])" x-data="{ multiline: false }">
                <input type="hidden" name="name" value="{{ $name }}">
                <div x-show="!multiline">
                    <x-ap-password name="value" :label="__('New value')" :meter="false" x-bind:disabled="multiline" required />
                </div>
                <div x-show="multiline" x-cloak>
                    <x-ap-textarea name="value" id="value-multiline" :label="__('New value')" mono rows="8" x-bind:disabled="!multiline" disabled />
                </div>
                <label class="ap-check">
                    <input type="checkbox" x-model="multiline">
                    <span>{{ __('Several lines (a PEM key or a certificate)') }}</span>
                </label>
                @if ($usedBy !== [])
                    <x-ap-checkbox name="restart" :checked="true"
                        :label="trans_choice('Restart the app that uses it|Restart the :count apps that use it', count($usedBy), ['count' => count($usedBy)])"
                        :hint="__('Running apps get the new value only when they start again.')" />
                @endif
                <p class="muted">{{ __('The current value is kept as the previous version, so you can roll back.') }}</p>
                <div class="ap-form-actions">
                    <x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button>
                </div>
            </x-ap-form>
        </x-ap-drawer>
    @endif
</x-ap-page>
