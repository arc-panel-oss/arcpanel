{{--
    Password field with show/hide, strength meter (check-password-strength)
    and an optional generator (apPassword). The value is never echoed back.
--}}
@props(['name', 'label' => null, 'id' => null, 'hint' => null, 'bag' => null, 'required' => false, 'generate' => false, 'meter' => true, 'autocomplete' => 'new-password'])
@php
    $id ??= \App\View\FormField::id($name);
    $error = \App\View\FormField::error($errors ?? null, $name, $bag);
    $describedBy = \App\View\FormField::describedBy([$hint ? $id.'-hint' : null, $error ? $id.'-error' : null, $meter ? $id.'-strength' : null]);
@endphp
<x-ap-field :label="$label" :for="$id" :hint="$hint" :error="$error" :required="$required" x-data="apPassword()">
    @if ($generate)
        <x-slot:labelAside>
            <button type="button" class="ap-link-btn" x-on:click="generate()" x-cloak>{{ __('Generate') }}</button>
        </x-slot:labelAside>
    @endif
    <div class="ap-input-affix">
        <x-ap-icon name="key" />
        <input {{ $attributes->merge(['class' => 'ap-input']) }} x-ref="input" x-on:input="evaluate()" id="{{ $id }}" name="{{ $name }}" type="password"
            autocomplete="{{ $autocomplete }}" spellcheck="false"
            @if ($required) required @endif
            @if ($error) aria-invalid="true" @endif
            @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif>
        <button type="button" class="ap-reveal" x-cloak x-on:click="toggle()" x-bind:aria-pressed="visible.toString()"
            x-bind:aria-label="visible ? @js(__('Hide password')) : @js(__('Show password'))" aria-label="{{ __('Show password') }}">
            <x-ap-icon name="eye" size="sm" />
        </button>
    </div>
    @if ($meter)
        <div class="ap-strength" x-bind:data-score="score" data-score="0" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
        <span class="ap-hint" id="{{ $id }}-strength" aria-live="polite" x-text="hint"></span>
    @endif
</x-ap-field>
