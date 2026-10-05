{{-- Checkbox with its label. Unchecked boxes post nothing; checked ones post value (default "1"). --}}
@props(['name', 'label', 'checked' => false, 'value' => '1', 'id' => null, 'hint' => null, 'bag' => null])
@php
    $id ??= \App\View\FormField::id($name);
    $error = \App\View\FormField::error($errors ?? null, $name, $bag);
    $isChecked = \App\View\FormField::submitted()
        ? \App\View\FormField::old($name) !== null
        : (bool) $checked;
@endphp
<div class="ap-field ap-field-check{{ $error ? ' is-invalid' : '' }}">
    <label class="ap-check" for="{{ $id }}">
        <input {{ $attributes }} type="checkbox" id="{{ $id }}" name="{{ $name }}" value="{{ $value }}" @checked($isChecked)
            @if ($hint || $error) aria-describedby="{{ \App\View\FormField::describedBy([$hint ? $id.'-hint' : null, $error ? $id.'-error' : null]) }}" @endif>
        <span>{{ $label }}</span>
    </label>
    @if ($hint)<span class="ap-hint" id="{{ $id }}-hint">{{ $hint }}</span>@endif
    @if ($error)<span class="ap-error" id="{{ $id }}-error"><x-ap-icon name="x-circle" size="sm" />{{ $error }}</span>@endif
</div>
