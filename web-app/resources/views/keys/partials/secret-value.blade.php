{{--
    Value of a secret (E38 spec §7): one line in a password field with show,
    hide and Generate, or several lines (PEM keys) in a text area. Only the
    active control is enabled, so one "value" is posted. Needs the
    multiline flag of apSecretStore around it. Params: $id, $label.
--}}
@php
    $error = $errors->first('value');
    $help = $id.'-help';
@endphp
<div class="ap-field ap-secret-value{{ $error ? ' is-invalid' : '' }}" x-data="apPassword()">
    <div class="ap-secret-value-head">
        <label class="ap-label" x-bind:for="multiline ? @js($id.'-lines') : @js($id)" for="{{ $id }}">
            <span>{{ $label }}<span class="ap-required" aria-hidden="true"> *</span></span>
        </label>
        <div class="ap-segment ap-secret-mode" role="group" aria-label="{{ __('Value format') }}" x-cloak>
            <button type="button" x-bind:aria-pressed="(!multiline).toString()" x-on:click="multiline = false">{{ __('One line') }}</button>
            <button type="button" x-bind:aria-pressed="multiline.toString()" x-on:click="multiline = true">{{ __('Several lines') }}</button>
        </div>
    </div>
    <div class="ap-input-affix" x-show="!multiline">
        <x-ap-icon name="key" />
        <input class="ap-input ap-input-mono" x-ref="input" id="{{ $id }}" name="value" type="password" autocomplete="off" spellcheck="false" required
            x-bind:disabled="multiline" @if ($error) aria-invalid="true" @endif aria-describedby="{{ $help }}{{ $error ? ' '.$id.'-error' : '' }}">
        <button type="button" class="ap-reveal" x-cloak x-on:click="toggle()" x-bind:aria-pressed="visible.toString()"
            x-bind:aria-label="visible ? @js(__('Hide value')) : @js(__('Show value'))" aria-label="{{ __('Show value') }}">
            <x-ap-icon name="eye" size="sm" />
        </button>
    </div>
    <textarea class="ap-textarea ap-input-mono" id="{{ $id }}-lines" name="value" rows="8" spellcheck="false" x-show="multiline" x-cloak
        x-bind:disabled="!multiline" disabled placeholder="-----BEGIN PRIVATE KEY-----"
        @if ($error) aria-invalid="true" @endif aria-describedby="{{ $help }}{{ $error ? ' '.$id.'-error' : '' }}"></textarea>
    <div class="ap-secret-value-foot">
        <span class="ap-hint" id="{{ $help }}">
            <x-ap-icon name="lock" size="sm" />
            <span x-show="!multiline">{{ __('Encrypted on the server and never shown again.') }}</span>
            <span x-show="multiline" x-cloak>{{ __('For PEM keys and certificates. Apps cannot use these in an environment value.') }}</span>
        </span>
        <button type="button" class="ap-link-btn" x-show="!multiline" x-cloak x-on:click="generate()">{{ __('Generate') }}</button>
    </div>
    @if ($error)
        <span class="ap-error" id="{{ $id }}-error"><x-ap-icon name="x-circle" size="sm" />{{ $error }}</span>
    @endif
</div>
