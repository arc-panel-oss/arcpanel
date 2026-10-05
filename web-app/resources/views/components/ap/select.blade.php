{{-- Select as a full field. options: [value => label]; a value of the current selection that is not in the list is kept. --}}
@props(['name', 'label' => null, 'options' => [], 'value' => null, 'id' => null, 'hint' => null, 'bag' => null, 'required' => false, 'placeholder' => null])
@php
    $id ??= \App\View\FormField::id($name);
    $error = \App\View\FormField::error($errors ?? null, $name, $bag);
    $current = (string) \App\View\FormField::old($name, $value);
    $describedBy = \App\View\FormField::describedBy([$hint ? $id.'-hint' : null, $error ? $id.'-error' : null]);
    if ($current !== '' && ! array_key_exists($current, $options)) {
        $options = [$current => $current] + $options;
    }
@endphp
<x-ap-field :label="$label" :for="$id" :hint="$hint" :error="$error" :required="$required">
    <div class="ap-select-wrap">
        <select {{ $attributes->merge(['class' => 'ap-select']) }} id="{{ $id }}" name="{{ $name }}"
            @if ($required) required @endif
            @if ($error) aria-invalid="true" @endif
            @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif>
            @if ($placeholder !== null)<option value="">{{ $placeholder }}</option>@endif
            @foreach ($options as $optionValue => $optionLabel)
                <option value="{{ $optionValue }}" @selected((string) $optionValue === $current)>{{ $optionLabel }}</option>
            @endforeach
        </select>
        <x-ap-icon name="chevron-down" size="sm" />
    </div>
</x-ap-field>
