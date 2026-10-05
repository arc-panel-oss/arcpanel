{{-- Radio buttons in a fieldset with a legend. options: [value => label]. --}}
@props(['name', 'label', 'options' => [], 'value' => null, 'id' => null, 'hint' => null, 'bag' => null])
@php
    $id ??= \App\View\FormField::id($name);
    $error = \App\View\FormField::error($errors ?? null, $name, $bag);
    $current = (string) \App\View\FormField::old($name, $value);
@endphp
<fieldset {{ $attributes->merge(['class' => 'ap-field ap-fieldset'.($error ? ' is-invalid' : '')]) }}
    @if ($hint || $error) aria-describedby="{{ \App\View\FormField::describedBy([$hint ? $id.'-hint' : null, $error ? $id.'-error' : null]) }}" @endif>
    <legend class="ap-label">{{ $label }}</legend>
    <div class="ap-row ap-radio-row">
        @foreach ($options as $optionValue => $optionLabel)
            <label class="ap-check"><input type="radio" name="{{ $name }}" value="{{ $optionValue }}" @checked((string) $optionValue === $current)><span>{{ $optionLabel }}</span></label>
        @endforeach
    </div>
    @if ($hint)<span class="ap-hint" id="{{ $id }}-hint">{{ $hint }}</span>@endif
    @if ($error)<span class="ap-error" id="{{ $id }}-error"><x-ap-icon name="x-circle" size="sm" />{{ $error }}</span>@endif
</fieldset>
