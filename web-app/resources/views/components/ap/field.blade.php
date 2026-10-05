{{--
    Label, control slot, hint and error of one form field. The control gets
    aria-describedby="{id}-hint {id}-error" from the input components.
--}}
@props(['label' => null, 'for' => null, 'hint' => null, 'error' => null, 'required' => false, 'labelAside' => null])
<div {{ $attributes->merge(['class' => 'ap-field'.($error ? ' is-invalid' : '')]) }}>
    @if ($label)
        <label class="ap-label" @if ($for) for="{{ $for }}" @endif>
            <span>{{ $label }}@if ($required)<span class="ap-required" aria-hidden="true"> *</span>@endif</span>
            @if ($labelAside){{ $labelAside }}@endif
        </label>
    @endif
    {{ $slot }}
    @if ($hint)
        <span class="ap-hint" @if ($for) id="{{ $for }}-hint" @endif>{{ $hint }}</span>
    @endif
    @if ($error)
        <span class="ap-error" @if ($for) id="{{ $for }}-error" @endif><x-ap-icon name="x-circle" size="sm" />{{ $error }}</span>
    @endif
</div>
