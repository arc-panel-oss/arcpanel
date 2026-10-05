{{--
    Text input as a full field (label, hint, error). prefix renders an addon
    (/home/alice/web/), suffix an addon after it, icon an affix icon.
--}}
@props([
    'name',
    'label' => null,
    'type' => 'text',
    'value' => null,
    'id' => null,
    'hint' => null,
    'bag' => null,
    'required' => false,
    'mono' => false,
    'prefix' => null,
    'suffix' => null,
    'icon' => null,
])
@php
    $id ??= \App\View\FormField::id($name);
    $error = \App\View\FormField::error($errors ?? null, $name, $bag);
    $current = $type === 'password' ? null : \App\View\FormField::old($name, $value);
    $describedBy = \App\View\FormField::describedBy([$hint ? $id.'-hint' : null, $error ? $id.'-error' : null]);
    $classes = 'ap-input'.($mono ? ' ap-input-mono' : '');
@endphp
<x-ap-field :label="$label" :for="$id" :hint="$hint" :error="$error" :required="$required">
    @if ($prefix || $suffix)<div class="ap-input-group">@elseif ($icon)<div class="ap-input-affix">@endif
    @if ($prefix)<span class="ap-addon">{{ $prefix }}</span>@endif
    @if ($icon)<x-ap-icon :name="$icon" />@endif
    <input {{ $attributes->merge(['class' => $classes]) }} id="{{ $id }}" name="{{ $name }}" type="{{ $type }}"
        @if ($current !== null) value="{{ $current }}" @endif
        @if ($required) required @endif
        @if ($error) aria-invalid="true" @endif
        @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif>
    @if ($suffix)<span class="ap-addon">{{ $suffix }}</span>@endif
    @if ($prefix || $suffix || $icon)</div>@endif
</x-ap-field>
