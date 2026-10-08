{{--
    One API access key (web/list/access-key?key=); right after creation with
    its secret, once (E7 batch 2 spec §8.2). Layout, the copyable bearer token,
    the usage examples and the leave guard: spec 2026-10-08-008 U2, U5–U7.
--}}
@php
    $list = route('access-keys.list', ['user' => $user]);
    $showSecret = $created && $key->secret !== null;
    $token = $showSecret ? $key->id.':'.$key->secret : null;
    $hasV2 = array_filter($key->permissions, fn (string $set) => \App\Services\Keys\AccessKeySets::isV2($set)) !== [];
    $credentials = $showSecret ? [
        'id' => [__('Access Key ID'), $key->id, null],
        'secret' => [__('Secret Access Key'), $key->secret, null],
        'token' => [__('Bearer token'), $token, __('REST API v2 reads it from the Authorization header: Authorization: Bearer <token>.')],
    ] : [];
    $examples = $showSecret && $hasV2 ? [
        'curl' => ['curl', "curl -H 'Authorization: Bearer ".$token."' ".$endpoint.'/api/v2/me'],
        'arc' => [__('arc command line tool'), implode("\n", [
            'export ARCPANEL_ENDPOINT='.$endpoint,
            'export ARCPANEL_ACCESS_KEY_ID='.$key->id,
            "export ARCPANEL_SECRET_KEY='".$key->secret."'",
            'arc whoami',
        ])],
    ] : [];
@endphp
<x-ap-page :title="__('Access Key')" :eyebrow="__('Access Key')" :heading="$key->comment !== '' ? $key->comment : $key->id"
    :crumbs="[['label' => __('Account')], ['label' => __('Access Keys'), 'url' => $list], ['label' => $key->id]]">
    @if ($canManage)
        <x-slot:actions>
            <x-ap-button variant="danger" :form-action="route('access-keys.destroy', ['user' => $user, 'key' => $key->id])" form-method="DELETE" icon="trash"
                :confirm="sprintf(__('Are you sure you want to delete access key %s?'), $key->id)" :confirm-accept="__('Delete')">{{ __('Delete') }}</x-ap-button>
        </x-slot:actions>
    @endif

    @if ($created)
        {{-- A reload must not post the form again --}}
        <div x-data x-init="history.replaceState(null, '', {{ json_encode(route('access-keys.show', ['user' => $user, 'key' => $key->id])) }})"></div>
        <x-ap-alert tone="ok" :title="sprintf(__('Access key %s has been created successfully.'), $key->id)" />
    @endif

    @if ($showSecret)
        <div x-data="apAccessKeySecret()" x-on:copy="onCopy()" x-on:submit.window="onSubmit($event)">
            <x-ap-card :title="__('Save these credentials')" icon="lock">
                <div class="ap-stack ap-key-credentials">
                    <x-ap-alert tone="warn" :title="__('Warning! Last chance to save secret key!')">{{ __('The secret is shown only now. Store it somewhere safe.') }}</x-ap-alert>
                    @foreach ($credentials as $name => [$label, $value, $hint])
                        <div class="ap-field">
                            <label class="ap-label" for="access-key-{{ $name }}">{{ $label }}</label>
                            <div class="ap-input-group">
                                <input class="ap-input ap-input-mono" id="access-key-{{ $name }}" type="text" readonly value="{{ $value }}"
                                    x-on:focus="$el.select()" @if ($hint) aria-describedby="access-key-{{ $name }}-hint" @endif>
                                <button type="button" class="ap-btn" x-on:click="copy(@js($name), $el.previousElementSibling.value)">
                                    <x-ap-icon name="copy" />
                                    <span x-text="copied === @js($name) ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span>
                                    <span class="sr-only">{{ $label }}</span>
                                </button>
                            </div>
                            @if ($hint)
                                <p class="ap-hint" id="access-key-{{ $name }}-hint">{{ $hint }}</p>
                            @endif
                        </div>
                    @endforeach

                    @if ($examples !== [])
                        <div class="ap-stack ap-key-examples">
                            <h3 class="ap-key-heading">{{ __('Try the key') }}</h3>
                            @foreach ($examples as $name => [$label, $code])
                                <div class="ap-key-example">
                                    <div class="ap-key-example-head">
                                        <span>{{ $label }}</span>
                                        <button type="button" class="ap-btn ap-btn-ghost ap-btn-sm"
                                            x-on:click="copy(@js('example-'.$name), $el.closest('.ap-key-example').querySelector('pre').textContent)">
                                            <x-ap-icon name="copy" size="sm" />
                                            <span x-text="copied === @js('example-'.$name) ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span>
                                            <span class="sr-only">{{ $label }}</span>
                                        </button>
                                    </div>
                                    <x-ap-code :label="$label">{{ $code }}</x-ap-code>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    <p class="sr-only" aria-live="polite" x-text="copied ? @js(__('Copied')) : ''"></p>
                </div>
            </x-ap-card>
        </div>
    @endif

    <x-ap-card :title="__('Details')" icon="key">
        <dl class="ap-facts">
            @unless ($showSecret)
                <dt>{{ __('Access Key ID') }}</dt>
                <dd class="mono">{{ $key->id }}</dd>
            @endunless
            <dt>{{ __('Comment') }}</dt>
            <dd>{{ $key->comment !== '' ? $key->comment : '-' }}</dd>
            <dt>{{ __('Created') }}</dt>
            <dd>
                @if ($key->date !== '')
                    {{ $key->date }} <span class="muted">{{ $key->time }}</span>
                @else
                    -
                @endif
            </dd>
            <dt>{{ __('Permissions') }}</dt>
            <dd>
                @if ($key->permissions === [])
                    -
                @else
                    <ul class="ap-set-list">
                        @foreach ($key->permissions as $permission)
                            @php($summary = \App\Services\Keys\AccessKeySets::summary($permission))
                            <li>
                                <span class="mono">{{ $permission }}</span>
                                <x-ap-badge :dot="false" :tone="\App\Services\Keys\AccessKeySets::isV2($permission) ? 'accent' : null">
                                    {{ \App\Services\Keys\AccessKeySets::isV2($permission) ? __('API v2') : __('API v1') }}
                                </x-ap-badge>
                                @if ($summary)
                                    <span class="muted">{{ $summary }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </dd>
        </dl>

        <div class="ap-form-actions">
            <x-ap-button :href="$list" icon="chevron-left">{{ __('Back') }}</x-ap-button>
        </div>
    </x-ap-card>
</x-ap-page>
