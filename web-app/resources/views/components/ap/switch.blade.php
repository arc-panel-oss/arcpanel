{{--
    On/off setting: a checkbox with role="switch", a title and a description,
    in the settings-row layout of the prototype.
--}}
@props(['name', 'label', 'description' => null, 'checked' => false, 'value' => '1', 'id' => null, 'bag' => null])
@php
    $id ??= \App\View\FormField::id($name);
    $error = \App\View\FormField::error($errors ?? null, $name, $bag);
    $isChecked = \App\View\FormField::submitted()
        ? \App\View\FormField::old($name) !== null
        : (bool) $checked;
@endphp
<label class="ap-setting{{ $error ? ' is-invalid' : '' }}" for="{{ $id }}">
    <span class="ap-setting-text">
        <b id="{{ $id }}-label">{{ $label }}</b>
        @if ($description)<span id="{{ $id }}-hint">{{ $description }}</span>@endif
        @if ($error)<span class="ap-error" id="{{ $id }}-error"><x-ap-icon name="x-circle" size="sm" />{{ $error }}</span>@endif
    </span>
    <span class="ap-switch">
        {{-- Named by the title only: the whole row is the label, and the description is read once, as the description --}}
        <input {{ $attributes }} type="checkbox" role="switch" id="{{ $id }}" name="{{ $name }}" value="{{ $value }}" aria-labelledby="{{ $id }}-label" @checked($isChecked)
            @if ($description || $error) aria-describedby="{{ \App\View\FormField::describedBy([$description ? $id.'-hint' : null, $error ? $id.'-error' : null]) }}" @endif>
    </span>
</label>
