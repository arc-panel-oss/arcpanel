{{--
    "Generate CSR" button and dialog of the SSL forms (web/generate/ssl, E7
    batch 3 spec §12.2). The fields belong to no form (form="…-detached"), so
    they can sit inside the SSL form; apCsr posts them to action and fills the
    certificate and key fields of the form with id `form`.
--}}
@props(['action', 'form', 'domain' => '', 'id' => 'csr'])
@php($detached = $id.'-detached')
<div x-data="apCsr({ url: @js($action), form: @js($form) })" class="ap-inline">
    <x-ap-button icon="key" x-on:click="$dispatch('ap-modal', { id: '{{ $id }}' })">{{ __('Generate CSR') }}</x-ap-button>
    <x-ap-modal :id="$id" :title="__('Generate CSR')" :description="__('A self-signed certificate and a certificate signing request for a certificate authority.')" icon="key">
        <div x-ref="fields" class="ap-stack">
            <div class="ap-form-grid">
                <x-ap-input name="domain" :id="$id.'-domain'" :label="__('Domain')" :value="$domain" mono required :form="$detached" />
                <x-ap-input name="email" :id="$id.'-email'" type="email" :label="__('Email')" required :form="$detached" />
                <x-ap-input name="country" :id="$id.'-country'" :label="__('Country')" value="US" mono maxlength="2" required :form="$detached" :hint="__('2-letter ISO code')" />
                <x-ap-input name="state" :id="$id.'-state'" :label="__('State / Province')" value="California" required :form="$detached" />
                <x-ap-input name="locality" :id="$id.'-locality'" :label="__('City / Locality')" value="San Francisco" required :form="$detached" />
                <x-ap-input name="org" :id="$id.'-org'" :label="__('Organization')" value="MyCompany Inc" required :form="$detached" />
                <x-ap-select name="key_algo" :id="$id.'-key-algo'" :label="__('Key algorithm')" value="ecdsa-256" :form="$detached"
                    :options="['ecdsa-256' => 'ECDSA P-256', 'ecdsa-384' => 'ECDSA P-384', 'rsa' => 'RSA 4096']" />
            </div>
            <x-ap-textarea name="aliases" :id="$id.'-aliases'" :label="__('Aliases')" rows="2" mono :form="$detached" :hint="__('One per line')" />
            <p class="ap-error" x-show="error" x-text="error" role="alert"></p>
            <div class="ap-stack" x-show="csr" x-cloak>
                <p>{{ __('The certificate and its key are in the form now. Save the form to use them.') }}</p>
                <div class="ap-field">
                    <label class="ap-label" for="{{ $id }}-result">{{ __('SSL CSR') }}</label>
                    <textarea class="ap-textarea ap-input-mono" id="{{ $id }}-result" rows="8" readonly x-text="csr" form="{{ $detached }}"></textarea>
                </div>
            </div>
        </div>
        <x-slot:footer>
            <x-ap-button variant="ghost" x-on:click="close()">{{ __('Close') }}</x-ap-button>
            <x-ap-button icon="copy" x-show="csr" x-on:click="copy()"><span x-text="copied ? @js(__('Copied')) : @js(__('Copy CSR'))"></span></x-ap-button>
            <x-ap-button variant="primary" icon="key" x-on:click="generate()" x-bind:disabled="busy">{{ __('Generate') }}</x-ap-button>
        </x-slot:footer>
    </x-ap-modal>
</div>
