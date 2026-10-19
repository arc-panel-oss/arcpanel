{{-- A new S3 key with its secret, shown once (E44 spec §7, §11); nothing keeps the secret. Copy buttons
     and the leave guard follow the access key page (apAccessKeySecret). --}}
@php
    $endpoint = $account->connectEndpoint();
    $credentials = [
        'id' => [__('Access key ID'), $key],
        'secret' => [__('Secret access key'), $secret],
        'endpoint' => [__('Endpoint'), $endpoint],
        'region' => [__('Region'), $account->region],
    ];
    $examples = [
        'rclone' => [__('rclone configuration'), "[arcpanel]\ntype = s3\nprovider = Minio\nendpoint = {$endpoint}\nregion = {$account->region}\naccess_key_id = {$key}\nsecret_access_key = {$secret}\nforce_path_style = true\nno_check_bucket = true"],
        'env' => [__('Environment of an app'), "AWS_ACCESS_KEY_ID={$key}\nAWS_SECRET_ACCESS_KEY={$secret}\nAWS_DEFAULT_REGION={$account->region}\nAWS_ENDPOINT_URL={$endpoint}\nAWS_USE_PATH_STYLE_ENDPOINT=true"],
    ];
@endphp
<x-ap-page :title="__('S3 key created')" :crumbs="[['label' => __('Object storage'), 'url' => route('storage.show', ['user' => $user])], ['label' => $name]]">
    {{-- A reload must not post the form again and create a second key --}}
    <div x-data x-init="history.replaceState(null, '', {{ json_encode(route('storage.show', ['user' => $user])) }})"></div>
    @if ($savedAs)
        <x-ap-alert tone="ok">{{ sprintf(__('The secret is also saved in the secret store as %s.'), $savedAs) }}</x-ap-alert>
    @elseif ($saveError)
        <x-ap-alert tone="danger">{{ sprintf(__('The secret could not be saved in the secret store: %s'), $saveError) }}</x-ap-alert>
    @endif

    <div x-data="apAccessKeySecret()" @if ($savedAs) x-init="saved = true" @endif x-on:copy="onCopy()" x-on:submit.window="onSubmit($event)">
        <x-ap-card :title="sprintf(__('Save the S3 key %s'), $name)" icon="key">
            <div class="ap-stack ap-key-credentials">
                <x-ap-alert tone="warn" :title="__('Copy the secret now')">{{ __('This is the only time the secret is shown. Store it in your app or password manager; if it is lost, delete the key and add a new one.') }}</x-ap-alert>
                @foreach ($credentials as $field => [$label, $value])
                    <div class="ap-field">
                        <label class="ap-label" for="s3-key-{{ $field }}">{{ $label }}</label>
                        <div class="ap-input-group">
                            <input class="ap-input ap-input-mono" id="s3-key-{{ $field }}" type="text" readonly value="{{ $value }}" x-on:focus="$el.select()">
                            <button type="button" class="ap-btn" x-on:click="copy(@js($field), $el.previousElementSibling.value)">
                                <x-ap-icon name="copy" />
                                <span x-text="copied === @js($field) ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span>
                                <span class="sr-only">{{ $label }}</span>
                            </button>
                        </div>
                    </div>
                @endforeach
                @if ($account->endpoint === '')
                    <p class="ap-hint">{{ __('No public endpoint yet: this endpoint works for apps on this server only.') }}</p>
                @endif

                <div class="ap-stack ap-key-examples">
                    <h3 class="ap-key-heading">{{ __('Use the key') }}</h3>
                    @foreach ($examples as $example => [$label, $code])
                        <div class="ap-key-example">
                            <div class="ap-key-example-head">
                                <span>{{ $label }}</span>
                                <button type="button" class="ap-btn ap-btn-ghost ap-btn-sm"
                                    x-on:click="copy(@js('example-'.$example), $el.closest('.ap-key-example').querySelector('pre').textContent)">
                                    <x-ap-icon name="copy" size="sm" />
                                    <span x-text="copied === @js('example-'.$example) ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span>
                                    <span class="sr-only">{{ $label }}</span>
                                </button>
                            </div>
                            <x-ap-code :label="$label">{{ $code }}</x-ap-code>
                        </div>
                    @endforeach
                </div>
                <p class="sr-only" aria-live="polite" x-text="copied ? @js(__('Copied')) : ''"></p>
            </div>
            <x-slot:footer>
                <x-ap-button variant="primary" icon="check" :href="route('storage.show', ['user' => $user])">{{ __('Done') }}</x-ap-button>
            </x-slot:footer>
        </x-ap-card>
    </div>
</x-ap-page>
