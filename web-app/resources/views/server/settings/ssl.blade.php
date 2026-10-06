{{-- Server settings: the certificate of the panel (web/edit/server "SSL", E7 batch 3 spec §8.2). --}}
<x-ap-page :title="__('Server settings')" :crumbs="[['label' => __('Server')], ['label' => __('Server settings')], ['label' => __('SSL')]]">
    @include('server.settings.partials.nav')

    <x-ap-card :title="__('SSL')" icon="lock">
        @if (($ssl['SUBJECT'] ?? '') !== '')
            <dl class="ap-dl">
                <dt>{{ __('Subject') }}</dt><dd class="mono">{{ $ssl['SUBJECT'] }}</dd>
                <dt>{{ __('Aliases') }}</dt><dd class="mono">{{ $ssl['ALIASES'] ?? '' }}</dd>
                <dt>{{ __('Issued By') }}</dt><dd>{{ $ssl['ISSUER'] ?? '' }}</dd>
                <dt>{{ __('Valid From') }}</dt><dd>{{ $ssl['NOT_BEFORE'] ?? '' }}</dd>
                <dt>{{ __('Valid To') }}</dt><dd>{{ $ssl['NOT_AFTER'] ?? '' }}</dd>
                <dt>{{ __('Signature') }}</dt><dd>{{ $ssl['SIGNATURE'] ?? '' }}</dd>
                <dt>{{ __('Key Size') }}</dt><dd>{{ $ssl['PUB_KEY'] ?? '' }}</dd>
            </dl>
        @endif

        <x-ap-form :action="route('server.settings.update.ssl')" id="panel-ssl-form">
            <fieldset class="ap-fieldset-plain" @disabled(! $canEdit)>
                <x-ap-textarea name="certificate" :label="__('SSL Certificate')" :value="$ssl['CRT'] ?? ''" rows="8" mono spellcheck="false" required />
                <x-ap-textarea name="key" :label="__('SSL Private Key')" rows="6" mono spellcheck="false" autocomplete="off" required
                    :hint="__('The current key is not shown. Paste the key that belongs to the certificate.')" />
            </fieldset>
            @if ($canEdit)
                <div class="ap-form-actions">
                    <x-ap-button type="submit" variant="primary" icon="check">{{ __('Save') }}</x-ap-button>
                    @if (Route::has('server.settings.csr'))
                        <x-ap-csr-modal :action="route('server.settings.csr')" :domain="$hostname" form="panel-ssl-form" />
                    @endif
                </div>
            @endif
        </x-ap-form>
    </x-ap-card>
</x-ap-page>
