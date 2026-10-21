{{-- One secret of the secret store (E38 spec §7): metadata, versions and the apps that use it; never the value. --}}
@php
    $name = (string) ($secret['NAME'] ?? '');
    $reference = \App\Services\Keys\UserSecretManager::reference($name);
    $description = (string) ($secret['DESCRIPTION'] ?? '');
    $usedBy = array_values(array_filter((array) ($secret['USED_BY'] ?? []), 'is_string'));
    $versions = array_reverse(array_values(array_filter((array) ($secret['VERSIONS'] ?? []), 'is_array')));
    $current = (string) ($secret['VERSION'] ?? '');
    $fingerprint = (string) ($secret['FINGERPRINT'] ?? '');
    $list = route('secrets.list', ['user' => $user]);
    // node:<domain>, docker:<name> (spec §6.3) and app:<domain>/<name> (E39 W5) as kind, label, icon and link
    $apps = array_map(function (string $app) use ($user): array {
        [$kind, $id] = array_pad(explode(':', $app, 2), 2, '');

        return match ($kind) {
            'node' => [__('Node.js app'), $id, 'hexagon', route('node.apps.show', ['user' => $user, 'domain' => $id])],
            'docker' => [__('Docker app'), $id, 'container', route('docker.apps.show', ['user' => $user, 'name' => $id])],
            'app' => str_contains($id, '/')
                ? [__('App process'), $id, 'terminal', route('app-processes.show', ['user' => $user, 'domain' => explode('/', $id, 2)[0], 'name' => explode('/', $id, 2)[1]])]
                : ['app', $id, 'package', null],
            default => [$kind, $id !== '' ? $id : $app, 'package', null],
        };
    }, $usedBy);
    $restartLabel = trans_choice('Restart the app that uses it|Restart the :count apps that use it', count($usedBy), ['count' => count($usedBy)]);
    $restartHint = __('Running apps get the new value only when they start again.');
@endphp
<x-ap-page :title="$name" :crumbs="[['label' => __('Account')], ['label' => __('Secrets'), 'url' => $list], ['label' => $name]]">
    <x-slot:head>
        <div class="ap-page-head-text ap-secret-head">
            <span class="eyebrow">{{ __('Secret') }}</span>
            <h1 class="mono">{{ $name }}</h1>
            <div class="ap-row">
                <x-ap-badge tone="accent">{{ sprintf(__('Version %s'), $current) }}</x-ap-badge>
                @if ($usedBy === [])
                    <x-ap-badge>{{ __('Not used') }}</x-ap-badge>
                @else
                    <x-ap-badge tone="ok">{{ trans_choice('Used by :count app|Used by :count apps', count($usedBy), ['count' => count($usedBy)]) }}</x-ap-badge>
                @endif
                <span class="subtle">{{ sprintf(__('Changed %s'), trim(($secret['DATE'] ?? '').' '.($secret['TIME'] ?? ''))) }}</span>
            </div>
        </div>
    </x-slot:head>
    @if ($canManage)
        <x-slot:actions>
            <x-ap-button variant="primary" icon="refresh" x-data x-on:click="$dispatch('ap-modal', { id: 'replace-secret' })">{{ __('Replace value') }}</x-ap-button>
            <x-ap-button variant="danger" icon="trash" :form-action="route('secrets.destroy', array_filter(['user' => $user, 'name' => $name, 'force' => $usedBy === [] ? null : '1']))" form-method="DELETE"
                :confirm-title="sprintf(__('Delete %s?'), $name)"
                :confirm="$usedBy === []
                    ? __('The secret and all its versions are deleted. This cannot be undone.')
                    : sprintf(__('Secret %s is used by %s. Those apps will not start until it is added again. Delete it anyway?'), $name, implode(', ', $usedBy))"
                :confirm-accept="__('Delete')">{{ __('Delete') }}</x-ap-button>
        </x-slot:actions>
    @endif

    <div class="ap-stack" x-data="apSecretStore()">
        <x-ap-card :title="__('Reference')" icon="link">
            <p class="muted ap-secret-lead">{{ __('Paste it into an environment value of a Node.js or Docker app. The server puts the value in place when the app starts; the panel never shows it.') }}</p>
            <div class="ap-input-group ap-secret-ref">
                <input class="ap-input ap-input-mono" id="secret-reference" type="text" value="{{ $reference }}" readonly x-on:focus="$el.select()" aria-label="{{ __('Reference') }}">
                <button type="button" class="ap-btn" x-on:click="copy('reference', @js($reference))">
                    <x-ap-icon name="copy" />
                    <span x-text="copied === 'reference' ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span>
                </button>
            </div>
            <p class="sr-only" aria-live="polite" x-text="copied ? @js(__('Copied')) : ''"></p>
        </x-ap-card>

        <div class="ap-split">
            <x-ap-card :title="__('Details')" icon="info">
                <dl class="ap-facts ap-secret-facts">
                    <dt>{{ __('Description') }}</dt>
                    <dd>
                        <div class="ap-secret-describe">
                            @if ($description !== '')
                                <span>{{ $description }}</span>
                            @else
                                <span class="subtle">{{ __('No description') }}</span>
                            @endif
                            @if ($canManage)
                                <button type="button" class="ap-link-btn" x-on:click="$dispatch('ap-modal', { id: 'describe-secret' })">
                                    {{ $description !== '' ? __('Edit') : __('Add') }}<span class="sr-only"> {{ __('Description') }}</span>
                                </button>
                            @endif
                        </div>
                    </dd>
                    <dt>{{ __('Current version') }}</dt>
                    <dd>{{ sprintf(__('Version %s'), $current) }} <span class="subtle">· {{ trans_choice(':count version kept|:count versions kept', count($versions), ['count' => count($versions)]) }}</span></dd>
                    <dt>{{ __('Fingerprint') }}</dt>
                    <dd>
                        <span class="mono">{{ $fingerprint }}</span>
                        <span class="ap-hint ap-secret-hint">{{ __('Equal values have equal fingerprints, so you can compare values without seeing them.') }}</span>
                    </dd>
                    <dt>{{ __('Changed') }}</dt>
                    <dd>{{ $secret['DATE'] ?? '' }} <span class="subtle">{{ $secret['TIME'] ?? '' }}</span></dd>
                </dl>
            </x-ap-card>

            <x-ap-card :title="__('Used by')" icon="plug" :flush="true">
                @if ($apps === [])
                    <div class="ap-secret-unused">
                        <p>{{ __('No app references this secret yet.') }}</p>
                        <p class="subtle">{{ __('Open the environment of a Node.js or Docker app and insert the reference above.') }}</p>
                    </div>
                @else
                    <ul class="ap-list ap-secret-apps">
                        @foreach ($apps as [$kind, $label, $icon, $href])
                            <li>
                                <span class="ap-list-icon" aria-hidden="true"><x-ap-icon :name="$icon" size="sm" /></span>
                                <div class="ap-list-main">
                                    @if ($href)
                                        <a href="{{ $href }}"><b>{{ $label }}</b></a>
                                    @else
                                        <b>{{ $label }}</b>
                                    @endif
                                    <span>{{ $kind }}</span>
                                </div>
                                @if ($href)
                                    <x-ap-button variant="ghost" size="sm" :icon-only="true" icon="chevron-right" :label="__('Open :name', ['name' => $label])" :href="$href" />
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ap-card>
        </div>

        <x-ap-card :title="__('Versions')" icon="history" :flush="true">
            <x-slot:actions>
                <span class="subtle ap-secret-keep">{{ __('The current version and the four before it are kept.') }}</span>
            </x-slot:actions>
            <x-ap-table id="secret-versions" :searchable="false" :columns="[
                ['key' => 'version', 'label' => __('Version'), 'sortable' => false],
                ['key' => 'fingerprint', 'label' => __('Fingerprint'), 'sortable' => false],
                ['key' => 'date', 'label' => __('Date'), 'sortable' => false],
            ]" :caption="__('Versions')">
                @foreach ($versions as $version)
                    @php $number = (string) ($version['VERSION'] ?? ''); @endphp
                    <tr data-row @class(['is-current' => $number === $current])>
                        <td data-col="version" data-label="{{ __('Version') }}">
                            <span class="ap-row">
                                <span>{{ sprintf(__('Version %s'), $number) }}</span>
                                @if ($number === $current)
                                    <x-ap-badge tone="ok">{{ __('Current') }}</x-ap-badge>
                                @endif
                            </span>
                        </td>
                        <td data-col="fingerprint" data-label="{{ __('Fingerprint') }}">
                            <span class="mono">{{ $version['FINGERPRINT'] ?? '' }}</span>
                            @if ($number !== $current && ($version['FINGERPRINT'] ?? '') === $fingerprint)
                                <span class="subtle">{{ __('same value as current') }}</span>
                            @endif
                        </td>
                        <td data-col="date" data-label="{{ __('Date') }}"><span>{{ $version['DATE'] ?? '' }} <span class="subtle">{{ $version['TIME'] ?? '' }}</span></span></td>
                        <td class="col-actions">
                            @if ($canManage && $number !== $current)
                                <x-ap-button variant="ghost" size="sm" icon="history" x-on:click="$dispatch('ap-modal', { id: 'rollback-{{ $number }}' })">{{ __('Roll back') }}</x-ap-button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-ap-table>
        </x-ap-card>
    </div>

    @if ($canManage)
        <x-ap-drawer id="replace-secret" class="ap-secret-drawer" :title="__('Replace value')" :description="sprintf(__('Saved as version %s. Version %s stays available for a roll back.'), (int) $current + 1, $current)"
            :open="$errors->hasAny(['value', 'restart'])">
            <x-ap-form :action="route('secrets.value', ['user' => $user])" id="replace-secret-form" class="ap-stack ap-secret-form" x-data="apSecretStore()">
                <input type="hidden" name="name" value="{{ $name }}">
                @include('keys.partials.secret-value', ['id' => 'replace-secret-value', 'label' => __('New value')])
                @if ($usedBy !== [])
                    <div class="ap-secret-restart">
                        <x-ap-checkbox name="restart" id="replace-restart" :checked="true" :label="$restartLabel" :hint="$restartHint" />
                        <ul class="ap-secret-restart-apps">
                            @foreach ($apps as [$kind, $label, $icon])
                                <li><x-ap-icon :name="$icon" size="sm" /> <span>{{ $label }}</span></li>
                            @endforeach
                        </ul>
                    </div>
                @else
                    <p class="subtle">{{ __('No app uses this secret, so nothing needs a restart.') }}</p>
                @endif
            </x-ap-form>
            <x-slot:footer>
                <x-ap-button variant="ghost" x-on:click="close()">{{ __('Cancel') }}</x-ap-button>
                <x-ap-button type="submit" form="replace-secret-form" variant="primary" icon="lock">{{ __('Encrypt and save') }}</x-ap-button>
            </x-slot:footer>
        </x-ap-drawer>

        <x-ap-modal id="describe-secret" class="ap-secret-modal" :title="__('Description')" :description="$name" icon="edit" :open="$errors->has('description')">
            <x-ap-form :action="route('secrets.describe', ['user' => $user])" method="PATCH" id="describe-secret-form">
                <input type="hidden" name="name" value="{{ $name }}">
                <x-ap-input name="description" :label="__('Description')" :value="$description" :hint="__('What the secret is for, for example Shop database. Leave it empty to remove it.')" autocomplete="off" />
            </x-ap-form>
            <x-slot:footer>
                <x-ap-button variant="ghost" x-on:click="close()">{{ __('Cancel') }}</x-ap-button>
                <x-ap-button type="submit" form="describe-secret-form" variant="primary" icon="save">{{ __('Save') }}</x-ap-button>
            </x-slot:footer>
        </x-ap-modal>

        @foreach ($versions as $version)
            @php $number = (string) ($version['VERSION'] ?? ''); @endphp
            @continue($number === $current)
            <x-ap-modal :id="'rollback-'.$number" class="ap-secret-modal" :title="sprintf(__('Roll back to version %s?'), $number)" icon="history"
                :description="sprintf(__('The value of version %s becomes version %s. Nothing is deleted, so you can roll forward again.'), $number, (int) $current + 1)"
                :open="$errors->has('version') && old('version') === $number">
                <x-ap-form :action="route('secrets.rollback', ['user' => $user])" :id="'rollback-'.$number.'-form'">
                    <input type="hidden" name="name" value="{{ $name }}">
                    <input type="hidden" name="version" value="{{ $number }}">
                    <dl class="ap-facts ap-secret-facts">
                        <dt>{{ __('Fingerprint') }}</dt>
                        <dd><span class="mono">{{ $version['FINGERPRINT'] ?? '' }}</span></dd>
                        <dt>{{ __('Saved') }}</dt>
                        <dd>{{ $version['DATE'] ?? '' }} <span class="subtle">{{ $version['TIME'] ?? '' }}</span></dd>
                    </dl>
                    @if ($usedBy !== [])
                        <x-ap-checkbox name="restart" :id="'rollback-'.$number.'-restart'" :checked="true" :label="$restartLabel" :hint="$restartHint" />
                    @endif
                </x-ap-form>
                <x-slot:footer>
                    <x-ap-button variant="ghost" x-on:click="close()">{{ __('Cancel') }}</x-ap-button>
                    <x-ap-button type="submit" :form="'rollback-'.$number.'-form'" variant="primary" icon="history">{{ __('Roll back') }}</x-ap-button>
                </x-slot:footer>
            </x-ap-modal>
        @endforeach
    @endif
</x-ap-page>
