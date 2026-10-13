{{-- Secret store of an account (E38 spec §7): names and fingerprints, never values. --}}
@php
    $columns = [
        ['key' => 'name', 'label' => __('Name'), 'sortable' => true],
        ['key' => 'used', 'label' => __('Used by'), 'sortable' => true, 'type' => 'number'],
        ['key' => 'version', 'label' => __('Version'), 'sortable' => true, 'type' => 'number'],
        ['key' => 'fingerprint', 'label' => __('Fingerprint'), 'sortable' => false, 'optional' => true],
        ['key' => 'date', 'label' => __('Changed'), 'sortable' => true],
    ];
    $openAdd = $errors->hasAny(['name', 'value', 'description']);
@endphp
<x-ap-page :title="__('Secrets')" :crumbs="[['label' => __('Account')], ['label' => __('Secrets')]]">
    <x-slot:head>
        <div class="ap-page-head-text">
            <h1>{{ __('Secrets') }}</h1>
            <p>
                {{ __('Passwords, tokens and keys of :user, encrypted on the server.', ['user' => $user]) }}
                {{ __('Apps read them through a reference such as') }} <code class="ap-secret-code">{{ \App\Services\Keys\UserSecretManager::reference('/shop/db_password') }}</code>.
            </p>
        </div>
    </x-slot:head>
    <x-slot:actions>
        @if ($canManage)
            <x-ap-button variant="primary" icon="plus" x-data x-on:click="$dispatch('ap-modal', { id: 'add-secret' })">{{ __('Add Secret') }}</x-ap-button>
        @endif
    </x-slot:actions>

    <x-ap-owner-switch route="secrets.index" :owners="$owners" :owner="$user" :label="__('Show secrets')" />

    <x-ap-card :flush="true" x-data="apSecretStore()">
        @if ($secrets === [])
            <x-ap-empty icon="shield-check" :title="__('No secrets')">
                {{ __('Keep database passwords, API tokens and keys here instead of in the environment of your apps.') }}
                @if ($canManage)
                    <x-slot:actions>
                        <x-ap-button variant="primary" icon="plus" x-on:click="$dispatch('ap-modal', { id: 'add-secret' })">{{ __('Add Secret') }}</x-ap-button>
                    </x-slot:actions>
                @endif
            </x-ap-empty>
            <ol class="ap-secret-steps" aria-label="{{ __('How apps use a secret') }}">
                <li>
                    <span class="ap-secret-step-num" aria-hidden="true">1</span>
                    <div><b>{{ __('Add a secret') }}</b><span>{{ __('Give it a path name such as /shop/db_password. The value is encrypted and never shown again.') }}</span></div>
                </li>
                <li>
                    <span class="ap-secret-step-num" aria-hidden="true">2</span>
                    <div><b>{{ __('Reference it') }}</b><span>{{ __('Write :reference in an environment value of a Node.js or Docker app.', ['reference' => \App\Services\Keys\UserSecretManager::reference('/shop/db_password')]) }}</span></div>
                </li>
                <li>
                    <span class="ap-secret-step-num" aria-hidden="true">3</span>
                    <div><b>{{ __('Start the app') }}</b><span>{{ __('The server puts the value in place when the app starts. The panel only ever shows the reference.') }}</span></div>
                </li>
            </ol>
        @else
            <x-ap-table id="secrets" :columns="$columns" :search-label="__('Search secrets')" :caption="__('Secrets')">
                @foreach ($secrets as $name => $secret)
                    @php
                        $name = (string) $name;
                        $show = route('secrets.show', ['user' => $user, 'name' => $name]);
                        $used = (int) ($secret['USED_BY'] ?? 0);
                        $description = (string) ($secret['DESCRIPTION'] ?? '');
                    @endphp
                    <tr data-row data-search="{{ $name }} {{ $description }}">
                        <td data-col="name" data-label="{{ __('Name') }}" data-sort-value="{{ $name }}">
                            <div class="ap-secret-name">
                                <span class="ap-secret-icon" aria-hidden="true"><x-ap-icon name="key" size="sm" /></span>
                                <div>
                                    <a class="mono" href="{{ $show }}">{{ $name }}</a>
                                    @if ($description !== '')
                                        <span class="muted">{{ $description }}</span>
                                    @endif
                                </div>
                            </div>
                        </td>
                        <td data-col="used" data-label="{{ __('Used by') }}" data-sort-value="{{ $used }}">
                            @if ($used > 0)
                                <x-ap-badge tone="ok">{{ trans_choice(':count app|:count apps', $used, ['count' => $used]) }}</x-ap-badge>
                            @else
                                <x-ap-badge>{{ __('Not used') }}</x-ap-badge>
                            @endif
                        </td>
                        <td data-col="version" data-label="{{ __('Version') }}" data-sort-value="{{ (int) ($secret['VERSION'] ?? 0) }}">
                            <span class="num">v{{ $secret['VERSION'] ?? '' }}</span>
                        </td>
                        <td data-col="fingerprint" data-label="{{ __('Fingerprint') }}"><span class="mono subtle">{{ $secret['FINGERPRINT'] ?? '' }}</span></td>
                        <td data-col="date" data-label="{{ __('Changed') }}" data-sort-value="{{ ($secret['DATE'] ?? '').' '.($secret['TIME'] ?? '') }}">
                            <span>{{ $secret['DATE'] ?? '' }} <span class="subtle">{{ $secret['TIME'] ?? '' }}</span></span>
                        </td>
                        <td class="col-actions">
                            <div class="ap-row ap-secret-row-actions">
                                <button type="button" class="ap-btn ap-btn-ghost ap-btn-sm" x-on:click="copy(@js($name), @js(\App\Services\Keys\UserSecretManager::reference($name)))"
                                    title="{{ __('Copy the reference') }}">
                                    <x-ap-icon name="copy" size="sm" />
                                    <span class="ap-secret-copy-text" x-text="copied === @js($name) ? @js(__('Copied')) : @js(__('Reference'))">{{ __('Reference') }}</span>
                                    <span class="sr-only">{{ __('Copy the reference of :name', ['name' => $name]) }}</span>
                                </button>
                                <x-ap-button variant="ghost" size="sm" :icon-only="true" icon="chevron-right" :label="__('Open :name', ['name' => $name])" :href="$show" />
                            </div>
                        </td>
                    </tr>
                @endforeach
                <x-slot:footer>
                    <span class="muted">{{ trans_choice(':count secret|:count secrets', count($secrets), ['count' => count($secrets)]) }}</span>
                </x-slot:footer>
            </x-ap-table>
        @endif
        <p class="sr-only" aria-live="polite" x-text="copied ? @js(__('Copied')) : ''"></p>
    </x-ap-card>

    @if ($canManage)
        @php $nameError = $errors->first('name'); @endphp
        <x-ap-drawer id="add-secret" class="ap-secret-drawer" :title="__('Add Secret')" :description="__('A password, token or key for your apps.')" :open="$openAdd">
            <x-ap-form :action="route('secrets.store', ['user' => $user])" id="add-secret-form" class="ap-stack ap-secret-form"
                x-data="apSecretStore({ name: {{ \Illuminate\Support\Js::from((string) old('name', '')) }} })">
                <div class="ap-field{{ $nameError ? ' is-invalid' : '' }}">
                    <label class="ap-label" for="secret-name"><span>{{ __('Name') }}<span class="ap-required" aria-hidden="true"> *</span></span></label>
                    <input class="ap-input ap-input-mono" id="secret-name" name="name" type="text" required autocomplete="off" spellcheck="false"
                        placeholder="/shop/db_password" maxlength="128" x-model.trim="name" value="{{ old('name') }}"
                        x-bind:aria-invalid="(nameState === 'invalid').toString()" aria-describedby="secret-name-help{{ $nameError ? ' secret-name-error' : '' }}">
                    <div class="ap-secret-preview" id="secret-name-help" x-bind:class="{ 'is-invalid': nameState === 'invalid' }" aria-live="polite">
                        <span x-show="nameState === 'empty'">{{ __('A path of one to five parts: letters, digits, _ . and -.') }}</span>
                        <span x-show="nameState === 'valid'" x-cloak>{{ __('Apps use it as') }} <code class="ap-secret-code" x-text="reference"></code></span>
                        <span x-show="nameState === 'invalid'" x-cloak><x-ap-icon name="alert" size="sm" /> {{ __('Start with / and use one to five parts of letters, digits, _ . and -.') }}</span>
                    </div>
                    @if ($nameError)
                        <span class="ap-error" id="secret-name-error"><x-ap-icon name="x-circle" size="sm" />{{ $nameError }}</span>
                    @endif
                </div>

                @include('keys.partials.secret-value', ['id' => 'add-secret-value', 'label' => __('Value')])

                <x-ap-input name="description" id="add-secret-description" :label="__('Description')" :hint="__('Optional. What the secret is for.')" placeholder="Shop database" maxlength="255" />
            </x-ap-form>
            <x-slot:footer>
                <x-ap-button variant="ghost" x-on:click="close()">{{ __('Cancel') }}</x-ap-button>
                <x-ap-button type="submit" form="add-secret-form" variant="primary" icon="lock">{{ __('Encrypt and save') }}</x-ap-button>
            </x-slot:footer>
        </x-ap-drawer>
    @endif
</x-ap-page>
