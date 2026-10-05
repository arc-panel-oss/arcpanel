{{-- Textarea as a full field. --}}
@props(['name', 'label' => null, 'value' => null, 'id' => null, 'hint' => null, 'bag' => null, 'required' => false, 'mono' => false, 'rows' => 3])
@php
    $id ??= \App\View\FormField::id($name);
    $error = \App\View\FormField::error($errors ?? null, $name, $bag);
    $current = \App\View\FormField::old($name, $value);
    $describedBy = \App\View\FormField::describedBy([$hint ? $id.'-hint' : null, $error ? $id.'-error' : null]);
@endphp
<x-ap-field :label="$label" :for="$id" :hint="$hint" :error="$error" :required="$required">
    <textarea {{ $attributes->merge(['class' => 'ap-textarea'.($mono ? ' ap-input-mono' : '')]) }} id="{{ $id }}" name="{{ $name }}" rows="{{ $rows }}"
        @if ($required) required @endif
        @if ($error) aria-invalid="true" @endif
        @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif>{{ $current }}</textarea>
</x-ap-field>
