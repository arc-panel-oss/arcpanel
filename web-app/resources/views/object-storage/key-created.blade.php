{{-- A new S3 key with its secret, shown once (E44 spec §7, §11); nothing keeps the secret. --}}
<x-ap-page :title="__('S3 key created')" :crumbs="[['label' => __('Object storage'), 'url' => route('storage.show', ['user' => $user])], ['label' => $name]]">
    <x-ap-alert tone="warn" :title="__('Copy the secret now')">
        {{ __('This is the only time the secret is shown. Store it in your app or password manager; if it is lost, delete the key and add a new one.') }}
    </x-ap-alert>
    @if ($savedAs)
        <x-ap-alert tone="ok">{{ sprintf(__('The secret is also saved in the secret store as %s.'), $savedAs) }}</x-ap-alert>
    @elseif ($saveError)
        <x-ap-alert tone="danger">{{ sprintf(__('The secret could not be saved in the secret store: %s'), $saveError) }}</x-ap-alert>
    @endif

    <x-ap-card :title="$name" icon="key">
        <dl class="ap-dl">
            <dt>{{ __('Access key ID') }}</dt>
            <dd><code class="mono" id="s3-key-id">{{ $key }}</code></dd>
            <dt>{{ __('Secret access key') }}</dt>
            <dd><code class="mono" id="s3-key-secret">{{ $secret }}</code></dd>
            <dt>{{ __('Endpoint') }}</dt>
            <dd class="mono">{{ $account->endpoint !== '' ? $account->endpoint : __('(no endpoint yet)') }}</dd>
            <dt>{{ __('Region') }}</dt>
            <dd class="mono">{{ $account->region }}</dd>
        </dl>
        <div class="ap-form-actions">
            <x-ap-button variant="primary" icon="check" :href="route('storage.show', ['user' => $user])">{{ __('Done') }}</x-ap-button>
        </div>
    </x-ap-card>
</x-ap-page>
